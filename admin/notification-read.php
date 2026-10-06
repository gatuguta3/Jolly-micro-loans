<?php
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

/* ---------- Validate loan id ---------- */
$loanId = isset($_GET['loan_id']) ? (int) $_GET['loan_id'] : 0;
if ($loanId <= 0) {
    header('Location: dashboard.php');
    exit;
}

/* ---------- Mark as read (idempotent) ---------- */
$stmt = $conn->prepare(
    "INSERT IGNORE INTO notification_reads (user_id, loan_id) VALUES (?, ?)"
);
if ($stmt) {
    $stmt->bind_param("ii", $userId, $loanId);
    $stmt->execute();
    $stmt->close();
}

/* ---------- Build safe redirect target ---------- */
$redirect = $_GET['redirect'] ?? '';
// Only allow internal paths (no scheme, no host, no protocol-relative)
if ($redirect === '' ||
    preg_match('#^[a-z]+://#i', $redirect) ||
    str_starts_with($redirect, '//')) {
    $redirect = 'loan-view.php?id=' . $loanId;
}

$conn->close();
header('Location: ' . $redirect);
exit;