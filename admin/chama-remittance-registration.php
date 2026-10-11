

<?php

require_once __DIR__ . '/../config/db_connection.php';

/* ================================================================
   INSERT one remittance row — no loan update
================================================================ */
function add_chama_remittance(mysqli $conn, int $loanId, int $guarantorId): array
{
    /* Confirm the loan is cleared and the guarantor is a chama */
    $stmt = $conn->prepare(
        "SELECT l.interest_amount
         FROM loans l
         JOIN loan_guarantors lg ON lg.loan_id = l.id
         JOIN guarantors g       ON g.id = lg.guarantor_id
         WHERE l.id = ?
           AND g.id = ?
           AND l.status = 'cleared'
           AND g.type   = 'chama'
           AND NOT EXISTS (
             SELECT 1 FROM chama_remittances cr
             WHERE cr.loan_id = l.id AND cr.guarantor_id = g.id
           )"
    );
    $stmt->bind_param('ii', $loanId, $guarantorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return ['ok' => false, 'message' => 'Already processed or loan not eligible.'];
    }

    $base = (float) $row['interest_amount'];
    $rate = 10.00;
    $amt  = round($base * $rate / 100, 2);

    $stmt = $conn->prepare(
        "INSERT INTO chama_remittances
           (loan_id, guarantor_id, base_amount, rate, remittance_amount, status, paid_date)
         VALUES (?, ?, ?, ?, ?, 'pending', CURDATE())"
    );
    $stmt->bind_param('iiddd', $loanId, $guarantorId, $base, $rate, $amt);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return ['ok' => false, 'message' => 'Insert failed: ' . $err];
    }
    $stmt->close();

    return ['ok' => true, 'amount' => $amt];
}

/* ================================================================
   ACTIONS
================================================================ */
if (isset($_GET['loan_id'], $_GET['guarantor_id'])) {
    $r = add_chama_remittance($conn, (int)$_GET['loan_id'], (int)$_GET['guarantor_id']);

    $_SESSION[$r['ok'] ? 'flash' : 'flash_error'] = $r['ok']
        ? 'Remittance of Ksh ' . number_format($r['amount'], 2) . ' recorded.'
        : $r['message'];

    if (ob_get_level()) ob_end_clean();
    header('Location: chama-remittance.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_all') {
    $res = $conn->query(
        "SELECT l.id AS loan_id, g.id AS guarantor_id
         FROM loans l
         JOIN loan_guarantors lg ON lg.loan_id = l.id
         JOIN guarantors g       ON g.id = lg.guarantor_id
         WHERE l.status = 'cleared' AND g.type = 'chama'
           AND NOT EXISTS (SELECT 1 FROM chama_remittances cr
                           WHERE cr.loan_id = l.id AND cr.guarantor_id = g.id)"
    );

    $n = 0; $total = 0.0;
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $r = add_chama_remittance($conn, (int)$row['loan_id'], (int)$row['guarantor_id']);
            if ($r['ok']) { $n++; $total += $r['amount']; }
        }
        $res->free();
    }

    $_SESSION['flash'] = $n
        ? "Processed $n remittance(s) totalling Ksh " . number_format($total, 2) . "."
        : 'No pending remittances.';

    if (ob_get_level()) ob_end_clean();
    header('Location: chama-remittance.php');
    exit;
}
