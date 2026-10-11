<?php
require_once __DIR__ . '/../config/db_connection.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!ob_get_level()) ob_start();

/* ================================================================
   Confirm a chama remittance as paid
   - Flips status → 'paid', sets paid_date + reference_number
   - Subtracts remittance_amount from loans.interest_amount
   - Transactional: both changes commit or roll back together
   - Idempotent: only pending rows are processed
================================================================ */
function confirm_chama_remittance(mysqli $conn, int $id, ?string $reference): array
{
    $conn->begin_transaction();

    try {
        /* 1. Fetch the pending row + its loan */
        $stmt = $conn->prepare(
            "SELECT loan_id, remittance_amount
             FROM chama_remittances
             WHERE id = ? AND status = 'pending'
             FOR UPDATE"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $conn->rollback();
            return ['ok' => false, 'message' => 'Remittance not found or already processed.'];
        }

        $loanId  = (int)   $row['loan_id'];
        $amount  = (float) $row['remittance_amount'];

        /* 2. Mark it paid */
        $stmt = $conn->prepare(
            "UPDATE chama_remittances
                SET status           = 'paid',
                    paid_date        = CURDATE(),
                    reference_number = COALESCE(?, reference_number)
              WHERE id = ?
                AND status = 'pending'"
        );
        $stmt->bind_param('si', $reference, $id);
        if (!$stmt->execute()) {
            $err = $stmt->error; $stmt->close();
            $conn->rollback();
            return ['ok' => false, 'message' => 'Update remittance failed: ' . $err];
        }
        $stmt->close();

        /* 3. Subtract from loans.interest_amount */
        $stmt = $conn->prepare(
            "UPDATE loans
                SET interest_amount = interest_amount - ?
              WHERE id = ?"
        );
        $stmt->bind_param('di', $amount, $loanId);
        if (!$stmt->execute()) {
            $err = $stmt->error; $stmt->close();
            $conn->rollback();
            return ['ok' => false, 'message' => 'Update loan failed: ' . $err];
        }
        $stmt->close();

        $conn->commit();
        return ['ok' => true, 'amount' => $amount];

    } catch (Throwable $e) {
        $conn->rollback();
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

/* ================================================================
   HANDLE POST
================================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: chama-remittance-history.php');
    exit;
}

$id        = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$reference = trim($_POST['reference_number'] ?? '');
$reference = $reference !== '' ? $reference : null;

if (!$id) {
    $_SESSION['flash_error'] = 'Invalid request.';
    if (ob_get_level()) ob_end_clean();
    header('Location: chama-remittance-history.php');
    exit;
}

$result = confirm_chama_remittance($conn, $id, $reference);

if ($result['ok']) {
    $_SESSION['flash'] = 'Payment confirmed. KES '
        . number_format($result['amount'], 2)
        . ' deducted from the loan\'s interest.';
} else {
    $_SESSION['flash_error'] = $result['message'];
}

if (ob_get_level()) ob_end_clean();
header('Location: chama-remittance-history.php');
exit;