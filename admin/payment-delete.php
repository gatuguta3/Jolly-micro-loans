<?php
/* ================================================================
   DELETE PAYMENT
   - POST + CSRF only (never GET)
   - Reverses the payment's effect on the loan by REPLAYING
     all remaining payments from scratch (interest-first, then principal)
   - Removes the payment row + its uploaded proof
   - Flash + redirect back to payments.php
================================================================ */

require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ---------- Guards ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = 'Invalid delete request.';
    header('Location: payments.php'); exit;
}
if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
    $_SESSION['flash'] = 'Security token mismatch. Please try again.';
    header('Location: payments.php'); exit;
}
$paymentId = (int) ($_POST['id'] ?? 0);
if ($paymentId <= 0) {
    $_SESSION['flash'] = 'Invalid payment reference.';
    header('Location: payments.php'); exit;
}

/* ---------- Helpers ---------- */
function derive_loan_status(float $interestBal, float $principalBal, string $dueDate, string $category, string $currentStatus): string {
    $balance = $interestBal + $principalBal;
    if ($balance <= 0.009) return 'cleared';
    if ($currentStatus === 'written_off') return 'written_off';

    $today = new DateTime('today');
    $due   = new DateTime($dueDate);
    if ($today < $due) return 'active';

    $t3 = clone $due; $t6 = clone $due;
    if ($category === 'monthly') { $t3->modify('+3 months'); $t6->modify('+6 months'); }
    else                         { $t3->modify('+3 days');   $t6->modify('+6 days'); }

    if ($today >= $t6) return 'written_off';
    if ($today >= $t3) return 'defaulted';
    return 'overdue';
}

/**
 * Recompute a loan's state by replaying every payment (except excluded IDs)
 * in chronological order and applying interest-first, then principal.
 */
function recompute_loan_state(mysqli $conn, int $loanId, array $excludeIds = []): array {
    $stmt = $conn->prepare(
        "SELECT id, principal_amount, interest_amount, due_date, loan_category, status
         FROM loans WHERE id = ? LIMIT 1"
    );
    if (!$stmt) throw new Exception('Prepare loan fetch failed: ' . $conn->error);
    $stmt->bind_param("i", $loanId);
    $stmt->execute();
    $loan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$loan) throw new Exception("Loan {$loanId} not found.");

    $principalBal = (float) $loan['principal_amount'];
    $interestBal  = (float) $loan['interest_amount'];
    $amountPaid   = 0.0;

    $excludeIds   = array_values(array_map('intval', $excludeIds));
    $placeholders = empty($excludeIds) ? '' : implode(',', array_fill(0, count($excludeIds), '?'));

    $sql = "SELECT id, amount_paid, payment_date FROM payments WHERE loan_id = ?";
    if ($placeholders) $sql .= " AND id NOT IN ($placeholders)";
    $sql .= " ORDER BY payment_date ASC, id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare payments fetch failed: ' . $conn->error);

    if ($placeholders) {
        $types  = 'i' . str_repeat('i', count($excludeIds));
        $params = array_merge([$loanId], $excludeIds);
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param("i", $loanId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $payments = [];
    while ($p = $res->fetch_assoc()) $payments[] = $p;
    $stmt->close();

    foreach ($payments as $p) {
        $amt = (float) $p['amount_paid'];
        $amountPaid += $amt;
        if ($amt >= $interestBal) {
            $amt        -= $interestBal;
            $interestBal = 0.0;
            if ($amt > 0) $principalBal = max(0.0, $principalBal - $amt);
        } else {
            $interestBal -= $amt;
        }
    }

    $interestBal  = round($interestBal, 2);
    $principalBal = round($principalBal, 2);
    $amountPaid   = round($amountPaid, 2);

    $status = derive_loan_status(
        $interestBal, $principalBal,
        $loan['due_date'], $loan['loan_category'], $loan['status']
    );

    return [
        'interest_balance'  => $interestBal,
        'principal_balance' => $principalBal,
        'amount_paid'       => $amountPaid,
        'status'            => $status,
        'loan_id'           => $loanId,
    ];
}

/* ---------- Fetch payment ---------- */
$stmt = $conn->prepare("SELECT id, payment_code, loan_id, upload FROM payments WHERE id = ? LIMIT 1");
if (!$stmt) {
    $_SESSION['flash'] = 'Could not process delete request.';
    header('Location: payments.php'); exit;
}
$stmt->bind_param("i", $paymentId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    $_SESSION['flash'] = 'Payment not found.';
    header('Location: payments.php'); exit;
}

/* ---------- Delete + recompute ---------- */
$uploadDirFs = __DIR__ . '/assets/images/payment-uploads/';
$fileToUnlink = null;

$conn->begin_transaction();
try {
    /* 1) Delete the payment row */
    $del = $conn->prepare("DELETE FROM payments WHERE id = ?");
    if (!$del) throw new Exception('Prepare delete failed: ' . $conn->error);
    $del->bind_param("i", $paymentId);
    if (!$del->execute()) throw new Exception('Delete payment failed: ' . $del->error);
    $del->close();

    /* 2) Recompute the loan from its remaining payments */
    $newState = recompute_loan_state($conn, (int) $payment['loan_id']);

    $upd = $conn->prepare(
        "UPDATE loans
         SET interest_balance  = ?,
             principal_balance = ?,
             amount_paid       = ?,
             status            = ?
         WHERE id = ?"
    );
    if (!$upd) throw new Exception('Prepare update failed: ' . $conn->error);
    $upd->bind_param(
        "dddsi",
        $newState['interest_balance'],
        $newState['principal_balance'],
        $newState['amount_paid'],
        $newState['status'],
        $newState['loan_id']
    );
    if (!$upd->execute()) throw new Exception('Update loan failed: ' . $upd->error);
    $upd->close();

    $conn->commit();

    /* 3) Remove the physical proof file */
    if (!empty($payment['upload'])) {
        $safe = basename((string) $payment['upload']);
        $path = rtrim($uploadDirFs, '/\\') . DIRECTORY_SEPARATOR . $safe;
        if (is_file($path)) @unlink($path);
    }

    $_SESSION['flash'] = "Payment {$payment['payment_code']} deleted. Loan balances were recalculated.";

} catch (Throwable $ex) {
    $conn->rollback();
    $_SESSION['flash'] = 'Could not delete payment: ' . $ex->getMessage();
}

header('Location: payments.php');
exit;