<?php
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

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

$uploadDirFs = __DIR__ . '/assets/images/guarantor-attachments/';
$maxBytes    = 5 * 1024 * 1024;
$allowedMime = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/gif'       => 'gif',
    'application/pdf' => 'pdf',
];
if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0775, true);
}

$name   = trim($_POST['name']   ?? '');
$phone  = trim($_POST['phone']  ?? '');
$email  = trim($_POST['email']  ?? '');
$idNo   = trim($_POST['idNo']   ?? '');
$idType = trim($_POST['idType'] ?? 'id_no');
$notes  = trim($_POST['notes']  ?? '');
$type   = trim($_POST['type']   ?? 'individual');

$errors = [];

if ($name  === '') $errors[] = 'Guarantor name is required.';
if ($phone === '') $errors[] = 'Phone number is required.';
if ($idNo  === '') $errors[] = 'ID number is required.';
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email is invalid.';
}
if (!in_array($idType, ['id_no','passport','other'], true)) $idType = 'id_no';
if (!in_array($type, ['individual','chama'], true))         $type = 'individual';

if (empty($errors)) {
    $stmt = $conn->prepare(
        "SELECT id FROM guarantors WHERE identification_number = ? LIMIT 1"
    );
    if ($stmt) {
        $stmt->bind_param("s", $idNo);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'A guarantor with that ID number already exists.';
        }
        $stmt->close();
    }
}

$photoFile = null;
if (empty($errors) && !empty($_FILES['photo']['name'])) {
    $file = $_FILES['photo'];
    if ($file['error'] === UPLOAD_ERR_OK) {
        if ($file['size'] > $maxBytes) {
            $errors[] = 'Photo exceeds 5 MB.';
        } else {
            $mime = null;
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($file['tmp_name']);
            }
            if (!$mime || !isset($allowedMime[$mime])) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                $fallback = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif','pdf'=>'pdf'];
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
                } else {
                    $errors[] = 'Could not save uploaded file.';
                }
            }
        }
    } elseif ($file['error'] !== UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Upload failed (code ' . $file['error'] . ').';
    }
}

if (empty($errors)) {
    $emailDb = $email !== '' ? $email : null;
    $notesDb = $notes !== '' ? $notes : null;
    $today   = date('Y-m-d');

    $sql = "INSERT INTO guarantors
            (name, email, phone, type,
             identification_type, identification_number,
             attachment_url, notes, date_added)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['ok' => false, 'errors' => ['DB error: ' . $conn->error]]);
        exit;
    }

    $stmt->bind_param(
        "sssssssss",
        $name, $emailDb, $phone, $type,
        $idType, $idNo,
        $photoFile, $notesDb, $today
    );

    if ($stmt->execute()) {
        $newId = (int) $stmt->insert_id;
        $stmt->close();
        $conn->close();
        echo json_encode([
            'ok'    => true,
            'id'    => $newId,
            'name'  => $name,
            'id_no' => $idNo,
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