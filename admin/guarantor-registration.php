<?php
$pageTitle = 'Add Guarantor';
$activeNav = 'guarantors';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SESSION ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   UPLOAD CONFIG
   assets/ is one level up from admin/
---------------------------------------------------------------- */
$uploadDirFs = __DIR__ . '/assets/images/guarantor-attachments/';
$maxBytes    = 5 * 1024 * 1024;   // 5 MB
$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'application/pdf' => 'pdf',   // many IDs come as scans
];
if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0775, true);
}

/* ---------- HELPERS ---------- */
function save_guarantor_photo(array $file, string $fsDir, array $allowedMime, int $maxBytes): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];  // no upload
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'Upload failed (code ' . $file['error'] . ').'];
    }
    if ($file['size'] > $maxBytes) {
        return [null, 'File exceeds 5 MB.'];
    }

    // Detect MIME with fileinfo, fall back to extension if unavailable
    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
    }
    if (!$mime || !isset($allowedMime[$mime])) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fallback = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif','pdf'=>'pdf'];
        if (!isset($fallback[$ext])) {
            return [null, 'Unsupported file type.'];
        }
        $ext = $fallback[$ext];
    } else {
        $ext = $allowedMime[$mime];
    }

    if (!is_writable($fsDir)) {
        return [null, 'Upload folder is not writable.'];
    }

    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = rtrim($fsDir, '/\\') . DIRECTORY_SEPARATOR . $name;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [null, 'Could not save photo.'];
    }
    return [$name, null];
}

/* ----------------------------------------------------------------
   HANDLE POST
---------------------------------------------------------------- */
$errors = [];
$form = [
    'dateCreated'   => date('Y-m-d'),
    'guarantorName' => '',
    'type'          => 'individual',    // 'individual' | 'chama'
    'idType'        => 'id_no',         // 'id_no' | 'passport' | 'other'
    'email'         => '',
    'idNo'          => '',
    'phone'         => '',
    'notes'         => '',
];

$validTypes    = ['individual', 'chama'];
$validIdTypes  = ['id_no', 'passport', 'other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($form as $k => $v) {
        $form[$k] = trim($_POST[$k] ?? $v);
    }

    /* ---- Validation ---- */
    if ($form['guarantorName'] === '') {
        $errors[] = 'Guarantor name is required.';
    }
   
    if ($form['idNo'] === '') {
        $errors[] = 'ID number is required.';
    }
    if ($form['phone'] === '') {
        $errors[] = 'Phone number is required.';
    }
    if ($form['dateCreated'] === '' ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['dateCreated'])) {
        $errors[] = 'Date created is invalid.';
    }
    if (!in_array($form['type'], $validTypes, true)) {
        $form['type'] = 'individual';
    }
    if (!in_array($form['idType'], $validIdTypes, true)) {
        $form['idType'] = 'id_no';
    }

    /* ---- Duplicate ID check ---- */
    if (empty($errors)) {
        $stmt = $conn->prepare(
            "SELECT id FROM guarantors WHERE identification_number = ? LIMIT 1"
        );
        $stmt->bind_param("s", $form['idNo']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            $errors[] = 'A guarantor with that ID number already exists.';
        }
        $stmt->close();
    }

    /* ---- Handle photo upload ---- */
    $photoFile = null;
    if (empty($errors) && !empty($_FILES['guarantorPhoto']['name'])) {
        [$fname, $err] = save_guarantor_photo(
            $_FILES['guarantorPhoto'], $uploadDirFs, $allowedMime, $maxBytes
        );
        if ($err) $errors[] = $err;
        else      $photoFile = $fname;
    }

    /* ---- Insert (no `created_by` in this table) ---- */
    if (empty($errors)) {
        $email = $form['email'] !== '' ? $form['email'] : null;
        $notes = $form['notes'] !== '' ? $form['notes'] : null;

        $sql = "INSERT INTO guarantors
                (name, email, phone,
                 type, identification_type, identification_number,
                 attachment_url, notes, date_added)
                VALUES (?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $errors[] = 'Database error: ' . $conn->error;
        } else {
            // name, email, phone, type, idType, idNo, photo, notes, dateCreated
            $stmt->bind_param(
                "sssssssss",
                $form['guarantorName'],
                $email,
                $form['phone'],
                $form['type'],
                $form['idType'],
                $form['idNo'],
                $photoFile,
                $notes,
                $form['dateCreated']
            );

            if ($stmt->execute()) {
                $_SESSION['flash'] = "Guarantor {$form['guarantorName']} registered successfully.";
                $stmt->close();
                $conn->close();
                header("Location: guarantors.php");
                exit;
            } else {
                $errors[] = 'Could not save guarantor: ' . $stmt->error;
                $stmt->close();
            }
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
      <h1 class="h3 mb-1">Add Guarantor</h1>
      <p class="text-muted mb-0">Create a guarantor record with verified identity and contact details.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="guarantors.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Guarantors
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
  <div class="col-12 col-xl-8">
    <form class="panel needs-validation" method="post" enctype="multipart/form-data" novalidate>
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-person-plus" aria-hidden="true"></i>
            <span>Guarantor Information</span>
          </h2>
          <p class="text-muted mb-0">Add the guarantor’s personal and contact details.</p>
        </div>
      </div>

      <div class="row g-3">
        <!-- Guarantor ID (informational) -->
        <div class="col-md-6">
          <label class="form-label" for="guarantorId">Guarantor ID</label>
          <input class="form-control" id="guarantorId" name="guarantorId" type="text"
                 value="#NEW" readonly>
          <div class="form-text">Auto-generated by the system.</div>
        </div>

        <!-- Date Created -->
        <div class="col-md-6">
          <label class="form-label" for="dateCreated">Date Created</label>
          <input class="form-control" id="dateCreated" name="dateCreated" type="date" required
                 value="<?= htmlspecialchars($form['dateCreated'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Date created is required.</div>
        </div>

        <!-- Guarantor Name -->
        <div class="col-md-6">
          <label class="form-label" for="guarantorName">Guarantor Name</label>
          <input class="form-control" id="guarantorName" name="guarantorName" type="text" required
                 value="<?= htmlspecialchars($form['guarantorName'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Guarantor name is required.</div>
        </div>

        <!-- Type -->
        <div class="col-md-6">
          <label class="form-label" for="type">Guarantor Type</label>
          <select class="form-select" id="type" name="type" required>
            <option value="individual" <?= $form['type']==='individual' ? 'selected':'' ?>>Individual</option>
            <option value="chama"      <?= $form['type']==='chama'      ? 'selected':'' ?>>Chama</option>
          </select>
        </div>

        <!-- Email -->
        <div class="col-md-6">
          <label class="form-label" for="email">Email</label>
          <input class="form-control" id="email" name="email" type="email"
                 value="<?= htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Enter a valid email.</div>
        </div>

        <!-- Phone -->
        <div class="col-md-6">
          <label class="form-label" for="phone">Phone Number</label>
          <input class="form-control" id="phone" name="phone" type="tel" required
                 value="<?= htmlspecialchars($form['phone'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Phone number is required.</div>
        </div>

        <!-- Identification type -->
        <div class="col-md-6">
          <label class="form-label" for="idType">Identification Type</label>
          <select class="form-select" id="idType" name="idType" required>
            <option value="id_no"    <?= $form['idType']==='id_no'    ? 'selected':'' ?>>ID No</option>
            <option value="passport" <?= $form['idType']==='passport' ? 'selected':'' ?>>Passport</option>
            <option value="other"    <?= $form['idType']==='other'    ? 'selected':'' ?>>Other Document</option>
          </select>
        </div>

        <!-- ID Number -->
        <div class="col-md-6">
          <label class="form-label" for="idNo">ID Number</label>
          <input class="form-control" id="idNo" name="idNo" type="text" required
                 value="<?= htmlspecialchars($form['idNo'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">ID number is required.</div>
        </div>

        <!-- Photo / attachment -->
        <div class="col-12">
          <label class="form-label" for="guarantorPhoto">Guarantor Photo / ID Scan</label>
          <input class="form-control" id="guarantorPhoto" name="guarantorPhoto" type="file"
                 accept="image/*,application/pdf">
          <div class="form-text">JPG, PNG, WEBP, GIF or PDF. Max 5 MB.</div>
          <div id="photoPreview" class="mt-2"></div>
        </div>

        <!-- Notes -->
        <div class="col-12">
          <label class="form-label" for="notes">Notes</label>
          <textarea class="form-control" id="notes" name="notes" rows="4"
                    placeholder="Optional notes about the guarantor"><?= htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      </div>

      <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="guarantors.php">Cancel</a>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-person-check" aria-hidden="true"></i> Create Guarantor
        </button>
      </div>
    </form>
  </div>

  <div class="col-12 col-xl-4">
    <div class="panel h-100">
      <h2 class="h5 mb-3 section-title">
        <i class="bi bi-list-check" aria-hidden="true"></i>
        <span>Guarantor Checklist</span>
      </h2>
      <div class="activity-list">
        <div class="activity-item"><span class="activity-dot bg-primary"></span><div><p class="mb-1 fw-semibold">Verify ID number</p><p class="text-muted small mb-0">Confirm the ID matches the guarantor’s documents.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-success"></span><div><p class="mb-1 fw-semibold">Confirm phone number</p><p class="text-muted small mb-0">Ensure the phone number is active and reachable.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-warning"></span><div><p class="mb-1 fw-semibold">Attach photo</p><p class="text-muted small mb-0">A clear photo helps identify the guarantor.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-secondary"></span><div><p class="mb-1 fw-semibold">Save record</p><p class="text-muted small mb-0">The guarantor will appear in the guarantors table.</p></div></div>
      </div>
    </div>
  </div>
</section>

<script>
(() => {
  // Bootstrap validation
  document.querySelectorAll('.needs-validation').forEach(form => {
    form.addEventListener('submit', e => {
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });

  // Photo preview
  const input   = document.getElementById('guarantorPhoto');
  const preview = document.getElementById('photoPreview');
  if (input && preview) {
    input.addEventListener('change', () => {
      preview.innerHTML = '';
      const file = input.files[0];
      if (!file) return;
      const url = URL.createObjectURL(file);
      const html = file.type.startsWith('image/')
        ? `<img src="${url}" class="img-thumbnail" style="max-height:140px" alt="Preview">`
        : `<span class="badge text-bg-secondary">${file.name}</span>`;
      preview.insertAdjacentHTML('beforeend', html);
    });
  }
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';