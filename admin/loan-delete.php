<?php
/* ================================================================
   DELETE LOAN
   - POST + CSRF only (never via GET)
   - Removes physical collateral files
   - Removes loan_guarantors links
   - Deletes the loan row (inside a transaction)
   - Flash + redirect back to loans.php
================================================================ */

require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Ensure a CSRF token exists (loans.php page creates it too) */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ---------- Guard: POST only ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = 'Invalid delete request.';
    header('Location: loans.php');
    exit;
}

/* ---------- Guard: CSRF ---------- */
$postedToken = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
    $_SESSION['flash'] = 'Security token mismatch. Please try again.';
    header('Location: loans.php');
    exit;
}

/* ---------- Guard: loan id ---------- */
$loanId = (int) ($_POST['id'] ?? 0);
if ($loanId <= 0) {
    $_SESSION['flash'] = 'Invalid loan reference.';
    header('Location: loans.php');
    exit;
}

/* ---------- Fetch loan (need loan_code + uploads) ---------- */
$stmt = $conn->prepare("SELECT id, loan_code, uploads FROM loans WHERE id = ? LIMIT 1");
if (!$stmt) {
    $_SESSION['flash'] = 'Could not process delete request.';
    header('Location: loans.php');
    exit;
}
$stmt->bind_param("i", $loanId);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    $_SESSION['flash'] = 'Loan not found.';
    header('Location: loans.php');
    exit;
}

/* ---------- Collect physical file paths (delete later, after DB success) ---------- */
$uploadDirFs = __DIR__ . '/assets/images/loan-attachments/';
$filesToUnlink = [];

if (!empty($loan['uploads'])) {
    $decoded = json_decode($loan['uploads'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $fname) {
            $safe = basename((string) $fname);          // prevent traversal
            if ($safe === '') continue;
            $path = rtrim($uploadDirFs, '/\\') . DIRECTORY_SEPARATOR . $safe;
            if (is_file($path)) $filesToUnlink[] = $path;
        }
    }
}

/* ---------- DB delete inside a transaction ---------- */
$conn->begin_transaction();

try {
    /* 1. loan_guarantors links (if the table exists) */
    $hasJoin = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
    if ($hasJoin && $hasJoin->num_rows > 0) {
        $hasJoin->free();
        $del = $conn->prepare("DELETE FROM loan_guarantors WHERE loan_id = ?");
        if (!$del) throw new Exception('Prepare loan_guarantors delete failed: ' . $conn->error);
        $del->bind_param("i", $loanId);
        if (!$del->execute()) throw new Exception('Delete loan_guarantors failed: ' . $del->error);
        $del->close();
    }

    /*
       If you also have a payments table and want hard-delete semantics,
       uncomment the block below. Otherwise leave commented so payment
       history is preserved (and the FK will block the loan delete).

    $hasPay = $conn->query("SHOW TABLES LIKE 'payments'");
    if ($hasPay && $hasPay->num_rows > 0) {
        $hasPay->free();
        $del = $conn->prepare("DELETE FROM payments WHERE loan_id = ?");
        if (!$del) throw new Exception('Prepare payments delete failed: ' . $conn->error);
        $del->bind_param("i", $loanId);
        if (!$del->execute()) throw new Exception('Delete payments failed: ' . $del->error);
        $del->close();
    }
    */

    /* 2. The loan row itself */
    $del = $conn->prepare("DELETE FROM loans WHERE id = ?");
    if (!$del) throw new Exception('Prepare loan delete failed: ' . $conn->error);
    $del->bind_param("i", $loanId);
    if (!$del->execute()) throw new Exception('Delete loan failed: ' . $del->error);
    $del->close();

    $conn->commit();

    /* ---------- Now unlink physical files (safe: DB already committed) ---------- */
    foreach ($filesToUnlink as $path) {
        @unlink($path);
    }

    $_SESSION['flash'] = "Loan {$loan['loan_code']} was deleted successfully.";

} catch (Throwable $ex) {
    $conn->rollback();
    $_SESSION['flash'] = 'Could not delete loan: ' . $ex->getMessage();
}

header('Location: loans.php');
exit;