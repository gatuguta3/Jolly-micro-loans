<?php
/**
 * client-quick-add.php
 * Accepts POST from the client quick-add modal.
 * Returns JSON: { ok: true, id, code, name, email } or { ok: false, errors: [] }
 */

require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

/* ---------- AUTH GUARD ---------- */
if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'errors' => ['Not authenticated.']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'errors' => ['Method not allowed.']]);
    exit;
}

/* ---------- UPLOAD CONFIG ---------- */
$uploadDirFs = __DIR__ . '/assets/images/client-attachments/';
$maxBytes    = 8 * 1024 * 1024;
$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];
if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0775, true);
}

/* ---------- INPUT ---------- */
$firstName = trim($_POST['firstName'] ?? '');
$lastName  = trim($_POST['lastName']  ?? '');
$email     = trim($_POST['email']     ?? '');
$phone     = trim($_POST['phone']     ?? '');
$idNo      = trim($_POST['idNo']      ?? '');
$location  = trim($_POST['location']  ?? '');

$errors = [];

if ($firstName === '') $errors[] = 'First name is required.';
if ($lastName  === '') $errors[] = 'Last name is required.';
if ($email     === '') $errors[] = 'Email is required.';
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is invalid.';
if ($phone === '') $errors[] = 'Phone number is required.';
if ($idNo  === '') $errors[] = 'ID number is required.';

/* ---------- Duplicate check ---------- */
if (empty($errors)) {
    $stmt = $conn->prepare(
        "SELECT id FROM clients WHERE phone = ? OR id_number = ? LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param("ss", $phone, $idNo);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'A client with that phone or ID number already exists.';
        }
        $stmt->close();
    }
}

/* ---------- Optional photo ---------- */
$photoFile = null;
$allFiles  = [];
if (empty($errors) && !empty($_FILES['photo']['name'])) {
    $file = $_FILES['photo'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        if ($file['size'] > $maxBytes) {
            $errors[] = 'Photo exceeds 8 MB.';
        } else {
            $mime = null;
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($file['tmp_name']);
            }
            if (!$mime || !isset($allowedMime[$mime])) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $fallback = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif'];
                if (!isset($fallback[$ext])) {
                    $errors[] = 'Unsupported file type.';
                } else {
                    $ext = $fallback[$ext];
                }
            } else {
                $ext = $allowedMime[$mime];
            }
            if (empty($errors)) {
                $fname = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest  = rtrim($uploadDirFs, '/\\') . DIRECTORY_SEPARATOR . $fname;
                if (move_uploaded_file($file['tmp_name'], $dest)) {
                    $photoFile = $fname;
                    $allFiles[] = $fname;
                } else {
                    $errors[] = 'Could not save uploaded file.';
                }
            }
        }
    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Upload failed (code ' . $file['error'] . ').';
    }
}

/* ---------- Insert ---------- */
if (empty($errors)) {
    /* Auto-generate client code */
    $res  = $conn->query("SELECT MAX(id) AS max_id FROM clients");
    $row  = $res ? $res->fetch_assoc() : null;
    $next = ((int)($row['max_id'] ?? 0)) + 1;
    $clientCode = 'JML-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);

    $fullName    = $firstName . ' ' . $lastName;
    $locationDb  = $location !== '' ? $location : null;
    $uploadsJson = !empty($allFiles) ? json_encode($allFiles) : null;
    $createdBy   = $_SESSION['user_id'] ?? null;

    $sql = "INSERT INTO clients
            (client_code, full_name, email, phone, id_number,
             location, photo_url, uploads, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['ok' => false, 'errors' => ['DB error: ' . $conn->error]]);
        exit;
    }

    $stmt->bind_param(
        "ssssssssi",
        $clientCode, $fullName, $email, $phone, $idNo,
        $locationDb, $photoFile, $uploadsJson, $createdBy
    );

    if ($stmt->execute()) {
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        $conn->close();
        echo json_encode([
            'ok'    => true,
            'id'    => $newId,
            'code'  => $clientCode,
            'name'  => $fullName,
            'email' => $email,
        ]);
        exit;
    } else {
        $err = $stmt->error;
        $stmt->close();
        echo json_encode(['ok' => false, 'errors' => ['Insert failed: ' . $err]]);
        exit;
    }
}

$conn->close();
echo json_encode(['ok' => false, 'errors' => $errors]);