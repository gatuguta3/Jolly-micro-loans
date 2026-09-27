<?php
$pageTitle = 'Add Client';
$activeNav = 'clients';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SESSION ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   UPLOAD CONFIG
   assets/ is NOT inside admin/, so we go one level up
---------------------------------------------------------------- */
$uploadDirFs  = __DIR__ . '/../assets/images/client-attachments/';
$maxBytes     = 8 * 1024 * 1024;   // 8 MB
$allowedMime  = [
    'image/jpeg'      => 'jpeg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/gif'       => 'gif',
    'video/mp4'       => 'mp4',
];

if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0775, true);
}

/* ----------------------------------------------------------------
   HELPERS
---------------------------------------------------------------- */
function gen_client_code(mysqli $conn): string {
    $res  = $conn->query("SELECT MAX(id) AS max_id FROM clients");
    $row  = $res ? $res->fetch_assoc() : null;
    $next = ((int)($row['max_id'] ?? 0)) + 1;
    return 'JML-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function save_upload(array $file, string $fsDir, array $allowedMime, int $maxBytes): array {
    // returns [filename|null, error|null]
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed (code ' . $file['error'] . ').'];
    }
    if ($file['size'] > $maxBytes) {
        return [null, 'File exceeds 8 MB: ' . htmlspecialchars($file['name'])];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($allowedMime[$mime])) {
        return [null, 'Unsupported file type: ' . htmlspecialchars($mime) . ' (' . htmlspecialchars($file['name']) . ')'];
    }

    $ext  = $allowedMime[$mime];
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = rtrim($fsDir, '/\\') . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [null, 'Could not save uploaded file: ' . htmlspecialchars($file['name'])];
    }
    return [$name, null];
}

/* ----------------------------------------------------------------
   HANDLE POST
---------------------------------------------------------------- */
$errors = [];
$form = [
    'firstName' => '',
    'lastName'  => '',
    'email'     => '',
    'phone'     => '',
    'idNo'      => '',
    'dob'       => '',
    'notes'     => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($form as $k => $v) {
        $form[$k] = trim($_POST[$k] ?? '');
    }

    /* ---- Validation ---- */
    if ($form['firstName'] === '') $errors[] = 'First name is required.';
    if ($form['lastName']  === '') $errors[] = 'Last name is required.';
    if ($form['email']     === '') $errors[] = 'Email is required.';
    elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($form['phone']     === '') $errors[] = 'Phone is required.';
    if ($form['idNo']      === '') $errors[] = 'ID number is required.';
    if ($form['dob']       === '') $errors[] = 'Date of birth is required.';
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['dob'])) $errors[] = 'Date of birth is invalid.';

    /* ---- Duplicate checks ---- */
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM clients WHERE phone = ? OR id_number = ? LIMIT 1");
        $stmt->bind_param("ss", $form['phone'], $form['idNo']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'A client with that phone or ID number already exists.';
        }
        $stmt->close();
    }

    /* ---- Handle uploads ---- */
    $allFiles = [];
    if (empty($errors) && !empty($_FILES['media']['name'][0])) {
        $count = count($_FILES['media']['name']);
        for ($i = 0; $i < $count; $i++) {
            $one = [
                'name'     => $_FILES['media']['name'][$i],
                'type'     => $_FILES['media']['type'][$i],
                'tmp_name' => $_FILES['media']['tmp_name'][$i],
                'error'    => $_FILES['media']['error'][$i],
                'size'     => $_FILES['media']['size'][$i],
            ];
            if ($one['error'] === UPLOAD_ERR_NO_FILE) continue;
            [$fname, $err] = save_upload($one, $uploadDirFs, $allowedMime, $maxBytes);
            if ($err) $errors[] = $err;
            else      $allFiles[] = ['name' => $fname, 'type' => $one['type']];
        }
    }

    /* ---- Insert ---- */
    if (empty($errors)) {
        $clientCode = gen_client_code($conn);
        $fullName   = $form['firstName'] . ' ' . $form['lastName'];

        // First image → photo_url ; all files → uploads JSON
        $photoFile = null;
        foreach ($allFiles as $f) {
            if (str_starts_with($f['type'], 'image/')) { $photoFile = $f['name']; break; }
        }
        $uploadsJson = !empty($allFiles)
            ? json_encode(array_column($allFiles, 'name'))
            : null;

        $createdBy = $_SESSION['user_id'] ?? null;
        $email     = $form['email'];
        $notes     = $form['notes'] !== '' ? $form['notes'] : null;

        $sql = "INSERT INTO clients
                (client_code, full_name, email, phone, id_number,
                 date_of_birth, photo_url, uploads, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $errors[] = 'Database error: ' . $conn->error;
        } else {
            // types: code,fname,email,phone,idno,dob,photo,uploads,notes,created_by
            $stmt->bind_param(
                "sssssssssi",
                $clientCode,
                $fullName,
                $email,
                $form['phone'],
                $form['idNo'],
                $form['dob'],
                $photoFile,
                $uploadsJson,
                $notes,
                $createdBy
            );

            if ($stmt->execute()) {
                $_SESSION['flash'] = "Client {$fullName} registered as {$clientCode}.";
                $stmt->close();
                $conn->close();
                header("Location: clients.php");
                exit;
            } else {
                $errors[] = 'Could not save client: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-person-plus" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Management</p>
      <h1 class="h3 mb-1">Add Client</h1>
      <p class="text-muted mb-0">Create a new client and access them on your loan dashboard.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="clients.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to clients
    </a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger" role="alert">
    <strong>Please fix the following:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<section class="row g-3">
  <!-- Left column: form -->
  <div class="col-12 col-xl-8">
    <form class="panel needs-validation" method="post" enctype="multipart/form-data" novalidate>
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-person-plus" aria-hidden="true"></i>
            <span>Client Information</span>
          </h2>
          <p class="text-muted mb-0">Create a client account with validated fields.</p>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="firstName">First name</label>
          <input class="form-control" id="firstName" name="firstName" type="text" required
                 value="<?= htmlspecialchars($form['firstName'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">First name is required.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="lastName">Last name</label>
          <input class="form-control" id="lastName" name="lastName" type="text" required
                 value="<?= htmlspecialchars($form['lastName'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Last name is required.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="email">Email</label>
          <input class="form-control" id="email" name="email" type="email" required
                 value="<?= htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Enter a valid email.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="phone">Phone</label>
          <input class="form-control" id="phone" name="phone" type="tel" required
                 value="<?= htmlspecialchars($form['phone'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Phone number is required.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="idNo">ID No</label>
          <input class="form-control" id="idNo" name="idNo" type="text" required
                 value="<?= htmlspecialchars($form['idNo'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">ID number is required.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="dob">Date of birth</label>
          <input class="form-control" id="dob" name="dob" type="date" required
                 value="<?= htmlspecialchars($form['dob'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Date of birth is required.</div>
        </div>

        <div class="col-12">
          <label class="form-label" for="media">Media upload</label>
          <input class="form-control" id="media" name="media[]" type="file" accept="image/*,video/*" multiple>
          <div class="form-text">Upload profile image or video. Accepted: JPG, PNG, GIF, MP4. Max 8 MB each.</div>
          <div id="mediaPreview" class="mt-2 d-flex flex-wrap"></div>
        </div>

        <div class="col-12">
          <label class="form-label" for="notes">Notes</label>
          <textarea class="form-control" id="notes" name="notes" rows="4" placeholder="Optional onboarding notes"><?= htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      </div>

      <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="clients.php">Cancel</a>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-person-check" aria-hidden="true"></i> Create Client
        </button>
      </div>
    </form>

    <script>
      (() => {
        document.querySelectorAll('.needs-validation').forEach(form => {
          form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
              event.preventDefault();
              event.stopPropagation();
            }
            form.classList.add('was-validated');
          }, false);
        });

        const mediaInput = document.getElementById('media');
        const preview = document.getElementById('mediaPreview');

        if (mediaInput && preview) {
          mediaInput.addEventListener('change', () => {
            preview.innerHTML = '';
            Array.from(mediaInput.files).forEach(file => {
              const url = URL.createObjectURL(file);
              const html = file.type.startsWith('image/')
                ? `<img src="${url}" class="img-thumbnail me-2 mb-2" style="max-height:120px" alt="Preview">`
                : `<video src="${url}" class="img-thumbnail me-2 mb-2" style="max-height:120px" controls></video>`;
              preview.insertAdjacentHTML('beforeend', html);
            });
          });
        }
      })();
    </script>
  </div>

  <!-- Right column: Client checklist -->
  <div class="col-12 col-xl-4">
    <div class="panel h-100">
      <h2 class="h5 mb-3 section-title">
        <i class="bi bi-list-check" aria-hidden="true"></i>
        <span>Client Checklist</span>
      </h2>
      <div class="activity-list">
        <div class="activity-item">
          <span class="activity-dot bg-primary"></span>
          <div>
            <p class="mb-1 fw-semibold">Verify personal details</p>
            <p class="text-muted small mb-0">Confirm first name, last name, and date of birth.</p>
          </div>
        </div>
        <div class="activity-item">
          <span class="activity-dot bg-success"></span>
          <div>
            <p class="mb-1 fw-semibold">Confirm contact information</p>
            <p class="text-muted small mb-0">Ensure email and phone number are correct and reachable.</p>
          </div>
        </div>
        <div class="activity-item">
          <span class="activity-dot bg-warning"></span>
          <div>
            <p class="mb-1 fw-semibold">Validate ID number</p>
            <p class="text-muted small mb-0">Check the ID number against official documents.</p>
          </div>
        </div>
        <div class="activity-item">
          <span class="activity-dot bg-info"></span>
          <div>
            <p class="mb-1 fw-semibold">Upload profile photo</p>
            <p class="text-muted small mb-0">A clear photo helps identify the client.</p>
          </div>
        </div>
        <div class="activity-item">
          <span class="activity-dot bg-secondary"></span>
          <div>
            <p class="mb-1 fw-semibold">Save client record</p>
            <p class="text-muted small mb-0">The client will appear in the clients list and loan dashboard.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';