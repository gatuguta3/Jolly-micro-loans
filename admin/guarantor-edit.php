<?php
$pageTitle = 'Edit Guarantor';
$activeNav = 'guarantors';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SESSION ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   VALIDATE ?id
---------------------------------------------------------------- */
$guarantorId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($guarantorId <= 0) {
    $_SESSION['flash'] = 'Invalid guarantor ID.';
    header('Location: guarantors.php');
    exit;
}

/* ----------------------------------------------------------------
   UPLOAD CONFIG
---------------------------------------------------------------- */
$uploadDirFs = __DIR__ . '/../assets/images/guarantor-attachments/';
$maxBytes    = 5 * 1024 * 1024;   // 5 MB
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

/* ---------- HELPERS ---------- */
function save_guarantor_edit_upload(array $file, string $fsDir, array $allowedMime, int $maxBytes): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'Upload failed (code ' . $file['error'] . ').'];
    if ($file['size'] > $maxBytes) return [null, 'File exceeds 5 MB: ' . htmlspecialchars($file['name'])];

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
    }
    if (!$mime || !isset($allowedMime[$mime])) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fallback = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif','pdf'=>'pdf'];
        if (!isset($fallback[$ext])) {
            return [null, 'Unsupported file: ' . htmlspecialchars($file['name'])];
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
        return [null, 'Could not save: ' . htmlspecialchars($file['name'])];
    }
    return [$name, null];
}

/* ----------------------------------------------------------------
   FETCH GUARANTOR
---------------------------------------------------------------- */
$stmt = $conn->prepare(
    "SELECT id, name, email, phone, type,
            identification_type, identification_number,
            attachment_url, notes, status, date_added, created_at
     FROM guarantors
     WHERE id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $guarantorId);
$stmt->execute();
$guarantor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$guarantor) {
    $_SESSION['flash'] = 'Guarantor not found.';
    header('Location: guarantors.php');
    exit;
}

/* ----------------------------------------------------------------
   INITIAL FORM STATE
---------------------------------------------------------------- */
$errors = [];
$form = [
    'name'                  => $guarantor['name'],
    'email'                 => $guarantor['email'] ?? '',
    'phone'                 => $guarantor['phone'] ?? '',
    'type'                  => $guarantor['type'],
    'identification_type'   => $guarantor['identification_type'],
    'identification_number' => $guarantor['identification_number'] ?? '',
    'date_added'            => $guarantor['date_added'] ?? date('Y-m-d'),
    'status'                => $guarantor['status'],
    'notes'                 => $guarantor['notes'] ?? '',
];

$validTypes   = ['individual', 'chama'];
$validIdTypes = ['id_no', 'passport', 'other'];
$validStatus  = ['active', 'inactive'];

/* ----------------------------------------------------------------
   HANDLE POST
---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($form as $k => $v) {
        $form[$k] = trim($_POST[$k] ?? '');
    }

    /* ---- Validation ---- */
    if ($form['name'] === '')  $errors[] = 'Guarantor name is required.';
    if ($form['phone'] === '') $errors[] = 'Phone number is required.';
    if ($form['identification_number'] === '') $errors[] = 'ID number is required.';

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email.';
    }
    if (!in_array($form['type'], $validTypes, true)) {
        $form['type'] = 'individual';
    }
    if (!in_array($form['identification_type'], $validIdTypes, true)) {
        $form['identification_type'] = 'id_no';
    }
    if (!in_array($form['status'], $validStatus, true)) {
        $form['status'] = 'active';
    }
    if ($form['date_added'] === '' ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['date_added'])) {
        $errors[] = 'Date created is invalid.';
    }

    /* ---- Duplicate ID number check (excludes self) ---- */
    if (empty($errors) && $form['identification_number'] !== '') {
        $stmt = $conn->prepare(
            "SELECT id FROM guarantors
             WHERE identification_number = ? AND id <> ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("si", $form['identification_number'], $guarantorId);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $errors[] = 'Another guarantor already uses that ID number.';
            }
            $stmt->close();
        }
    }

    /* ---- Handle new attachment ---- */
    $newAttachment = $guarantor['attachment_url'];   // default: keep current

    if (empty($errors) && !empty($_FILES['new_attachment']['name'])) {
        [$fname, $err] = save_guarantor_edit_upload(
            $_FILES['new_attachment'], $uploadDirFs, $allowedMime, $maxBytes
        );
        if ($err) $errors[] = 'Attachment: ' . $err;
        else      $newAttachment = $fname;
    }

    /* ---- Optionally remove the existing attachment ---- */
    $removeAttachment = !empty($_POST['remove_attachment']) && $_POST['remove_attachment'] === '1';
    if ($removeAttachment && empty($_FILES['new_attachment']['name'])) {
        $newAttachment = null;
    }

    /* ---- Update ---- */
    if (empty($errors)) {
        $emailDb = $form['email'] !== '' ? $form['email'] : null;
        $notesDb = $form['notes'] !== '' ? $form['notes'] : null;

        $sql = "UPDATE guarantors SET
                    name                  = ?,
                    email                 = ?,
                    phone                 = ?,
                    type                  = ?,
                    identification_type   = ?,
                    identification_number = ?,
                    attachment_url        = ?,
                    notes                 = ?,
                    date_added            = ?,
                    status                = ?
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $errors[] = 'Database error: ' . $conn->error;
        } else {
            /* types: s s s s s s s s s s i */
            $stmt->bind_param(
                "ssssssssssi",
                $form['name'],
                $emailDb,
                $form['phone'],
                $form['type'],
                $form['identification_type'],
                $form['identification_number'],
                $newAttachment,
                $notesDb,
                $form['date_added'],
                $form['status'],
                $guarantorId
            );

            if ($stmt->execute()) {
                $stmt->close();
                $_SESSION['flash'] = "Guarantor {$form['name']} updated successfully.";
                $conn->close();
                header("Location: guarantor-view.php?id=" . $guarantorId);
                exit;
            } else {
                $errors[] = 'Could not update guarantor: ' . $stmt->error;
                $stmt->close();
            }
        }
    }
}

/* ---------- DISPLAY HELPERS ---------- */
if (!function_exists('ge_avatar_svg')) {
    function ge_avatar_svg($name) {
        $initial = strtoupper(substr(trim($name), 0, 1)) ?: '?';
        return 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="96" height="96">'
          . '<rect width="100%" height="100%" fill="#e9ecef"/>'
          . '<text x="50%" y="55%" font-size="40" font-family="Arial" '
          . 'fill="#6c757d" text-anchor="middle" dominant-baseline="middle">'
          . $initial . '</text></svg>'
        );
    }
}

$currentPhotoUrl = !empty($guarantor['attachment_url'])
    ? '../assets/images/guarantor-attachments/' . rawurlencode($guarantor['attachment_url'])
    : ge_avatar_svg($guarantor['name']);

$currentExt = $guarantor['attachment_url']
    ? strtolower(pathinfo($guarantor['attachment_url'], PATHINFO_EXTENSION))
    : null;
$currentIsImage = $currentExt && in_array($currentExt, ['jpg','jpeg','png','webp','gif'], true);
$currentIsPdf   = $currentExt === 'pdf';

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Management</p>
      <h1 class="h3 mb-1">Edit Guarantor</h1>
      <p class="text-muted mb-0">Update details, attachment, and status for <?= htmlspecialchars($guarantor['name'], ENT_QUOTES, 'UTF-8') ?>.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="guarantor-view.php?id=<?= (int) $guarantor['id'] ?>">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Guarantor
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="guarantors.php">
      <i class="bi bi-people" aria-hidden="true"></i> All Guarantors
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
  <!-- Left: live preview card -->
  <div class="col-12 col-xl-4">
    <div class="panel h-100 text-center profile-card">
      <div class="profile-cover">
        <img src="../assets/images/png/dasher-ui-bootstrap-5.jpg" alt="Preview cover">
      </div>
      <div class="profile-hero">
        <img class="avatar-img avatar-xl profile-photo" id="previewAvatar"
             src="<?= htmlspecialchars($currentPhotoUrl, ENT_QUOTES, 'UTF-8') ?>"
             alt="Guarantor photo">
        <h2 class="h5 mb-1" id="previewName"><?= htmlspecialchars($guarantor['name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="text-muted mb-3">Guarantor ID: #<?= (int) $guarantor['id'] ?></p>

        <div class="d-flex justify-content-center gap-2">
          <span class="badge text-bg-info" id="previewType">
            <?= htmlspecialchars($guarantor['type'] === 'chama' ? 'Chama' : 'Individual', ENT_QUOTES, 'UTF-8') ?>
          </span>
          <span class="badge <?= $guarantor['status'] === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>"
                id="previewStatus">
            <?= htmlspecialchars($guarantor['status'] === 'active' ? 'Active' : 'Inactive', ENT_QUOTES, 'UTF-8') ?>
          </span>
        </div>
      </div>

      <div class="info-list mt-4 text-start">
        <div><span>Email</span><strong id="previewEmail"><?= htmlspecialchars($form['email'] ?: '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Phone</span><strong id="previewPhone"><?= htmlspecialchars($form['phone'] ?: '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>ID</span><strong id="previewId"><?= htmlspecialchars($form['identification_number'] ?: '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Date Created</span><strong><?= htmlspecialchars($guarantor['date_added'] ? date('M j, Y', strtotime($guarantor['date_added'])) : '—') ?></strong></div>
      </div>

      <div class="alert alert-light text-start mt-3 mb-0 small">
        <strong>Tip:</strong> Click <em>Save Changes</em> at the bottom of the form to apply your edits.
      </div>
    </div>
  </div>

  <!-- Right: edit form -->
  <div class="col-12 col-xl-8">
    <form class="panel needs-validation" method="post" enctype="multipart/form-data" novalidate>
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-person-gear" aria-hidden="true"></i>
            <span>Guarantor Information</span>
          </h2>
          <p class="text-muted mb-0">Edit fields and click <strong>Save Changes</strong> when done.</p>
        </div>
      </div>

      <div class="row g-3">
        <!-- Name -->
        <div class="col-12">
          <label class="form-label" for="name">Guarantor Name <span class="text-danger">*</span></label>
          <input class="form-control" id="name" name="name" type="text" required
                 value="<?= htmlspecialchars($form['name'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Name is required.</div>
        </div>

        <!-- Phone -->
        <div class="col-md-6">
          <label class="form-label" for="phone">Phone <span class="text-danger">*</span></label>
          <input class="form-control" id="phone" name="phone" type="tel" required
                 value="<?= htmlspecialchars($form['phone'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Phone is required.</div>
        </div>

        <!-- Type -->
        <div class="col-md-6">
          <label class="form-label" for="type">Type</label>
          <select class="form-select" id="type" name="type">
            <option value="individual" <?= $form['type']==='individual' ? 'selected':'' ?>>Individual</option>
            <option value="chama"      <?= $form['type']==='chama'      ? 'selected':'' ?>>Chama</option>
          </select>
        </div>

        <!-- Identification type -->
        <div class="col-md-6">
          <label class="form-label" for="identification_type">Identification Type</label>
          <select class="form-select" id="identification_type" name="identification_type">
            <option value="id_no"    <?= $form['identification_type']==='id_no'    ? 'selected':'' ?>>ID No</option>
            <option value="passport" <?= $form['identification_type']==='passport' ? 'selected':'' ?>>Passport</option>
            <option value="other"    <?= $form['identification_type']==='other'    ? 'selected':'' ?>>Other Document</option>
          </select>
        </div>

        <!-- ID Number -->
        <div class="col-md-6">
          <label class="form-label" for="identification_number">ID Number <span class="text-danger">*</span></label>
          <input class="form-control" id="identification_number" name="identification_number"
                 type="text" required
                 value="<?= htmlspecialchars($form['identification_number'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">ID number is required.</div>
        </div>

        <!-- Email -->
        <div class="col-md-6">
          <label class="form-label" for="email">Email</label>
          <input class="form-control" id="email" name="email" type="email"
                 value="<?= htmlspecialchars($form['email'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Enter a valid email.</div>
        </div>

        <!-- Date created -->
        <div class="col-md-6">
          <label class="form-label" for="date_added">Date Created</label>
          <input class="form-control" id="date_added" name="date_added" type="date"
                 value="<?= htmlspecialchars($form['date_added'], ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <!-- Status -->
        <div class="col-md-6">
          <label class="form-label" for="status">Status</label>
          <select class="form-select" id="status" name="status">
            <option value="active"   <?= $form['status']==='active'   ? 'selected':'' ?>>Active</option>
            <option value="inactive" <?= $form['status']==='inactive' ? 'selected':'' ?>>Inactive</option>
          </select>
          <div class="form-text">Inactive guarantors shouldn't be attached to new loans.</div>
        </div>

        <!-- Current attachment preview + replacement -->
        <div class="col-12">
          <label class="form-label d-block">Attachment</label>

          <?php if (!empty($guarantor['attachment_url'])): ?>
            <div class="d-flex flex-wrap align-items-center gap-3 mb-2">
              <?php if ($currentIsImage): ?>
                <img src="<?= htmlspecialchars($currentPhotoUrl, ENT_QUOTES, 'UTF-8') ?>"
                     class="img-thumbnail" style="max-height:100px" alt="Current attachment">
              <?php elseif ($currentIsPdf): ?>
                <a class="btn btn-light btn-sm"
                   href="<?= htmlspecialchars($currentPhotoUrl, ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" rel="noopener">
                  <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                  View current PDF
                </a>
              <?php else: ?>
                <a class="btn btn-light btn-sm"
                   href="<?= htmlspecialchars($currentPhotoUrl, ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" rel="noopener">
                  <i class="bi bi-paperclip" aria-hidden="true"></i>
                  View current attachment
                </a>
              <?php endif; ?>

              <div class="form-check">
                <input class="form-check-input" type="checkbox"
                       id="remove_attachment" name="remove_attachment" value="1">
                <label class="form-check-label" for="remove_attachment">
                  Remove this attachment
                </label>
              </div>
            </div>
          <?php else: ?>
            <p class="text-muted small mb-2">No attachment on file.</p>
          <?php endif; ?>

          <input class="form-control" id="new_attachment" name="new_attachment" type="file"
                 accept="image/*,application/pdf">
          <div class="form-text">
            Upload a new file to replace the existing one.
            Accepted: JPG, PNG, WEBP, GIF, PDF. Max 5 MB.
          </div>
        </div>

        <!-- Notes -->
        <div class="col-12">
          <label class="form-label" for="notes">Notes</label>
          <textarea class="form-control" id="notes" name="notes" rows="4"
                    placeholder="Optional notes about this guarantor"><?= htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      </div>

      <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="guarantor-view.php?id=<?= (int) $guarantor['id'] ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-check2-circle" aria-hidden="true"></i> Save Changes
        </button>
      </div>
    </form>
  </div>
</section>

<script>
(() => {
  /* Bootstrap validation */
  document.querySelectorAll('.needs-validation').forEach(form => {
    form.addEventListener('submit', e => {
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });

  /* Live preview — name */
  const $name = document.getElementById('name');
  const $previewName = document.getElementById('previewName');
  if ($name && $previewName) {
    $name.addEventListener('input', () => {
      $previewName.textContent = $name.value.trim() || 'Guarantor name';
    });
  }

  /* Live preview — phone */
  const $phone = document.getElementById('phone');
  const $previewPhone = document.getElementById('previewPhone');
  if ($phone && $previewPhone) {
    $phone.addEventListener('input', () => {
      $previewPhone.textContent = $phone.value.trim() || '—';
    });
  }

  /* Live preview — email */
  const $email = document.getElementById('email');
  const $previewEmail = document.getElementById('previewEmail');
  if ($email && $previewEmail) {
    $email.addEventListener('input', () => {
      $previewEmail.textContent = $email.value.trim() || '—';
    });
  }

  /* Live preview — ID */
  const $idNo = document.getElementById('identification_number');
  const $previewId = document.getElementById('previewId');
  if ($idNo && $previewId) {
    $idNo.addEventListener('input', () => {
      $previewId.textContent = $idNo.value.trim() || '—';
    });
  }

  /* Live preview — type */
  const $type = document.getElementById('type');
  const $previewType = document.getElementById('previewType');
  if ($type && $previewType) {
    $type.addEventListener('change', () => {
      $previewType.textContent = $type.value === 'chama' ? 'Chama' : 'Individual';
    });
  }

  /* Live preview — status */
  const $status = document.getElementById('status');
  const $previewStatus = document.getElementById('previewStatus');
  if ($status && $previewStatus) {
    $status.addEventListener('change', () => {
      const v = $status.value;
      $previewStatus.textContent = v.charAt(0).toUpperCase() + v.slice(1);
      $previewStatus.classList.remove('text-bg-success', 'text-bg-secondary');
      $previewStatus.classList.add(v === 'active' ? 'text-bg-success' : 'text-bg-secondary');
    });
  }

  /* Live preview — new attachment */
  const $newAttach = document.getElementById('new_attachment');
  const $previewAvatar = document.getElementById('previewAvatar');
  if ($newAttach && $previewAvatar) {
    $newAttach.addEventListener('change', () => {
      const f = $newAttach.files[0];
      if (!f) return;
      if (f.type.startsWith('image/')) {
        $previewAvatar.src = URL.createObjectURL(f);
      }
    });
  }

  /* If user types a new attachment name, auto-uncheck "remove" so we don't drop both */
  if ($newAttach) {
    $newAttach.addEventListener('change', () => {
      const remove = document.getElementById('remove_attachment');
      if (remove && $newAttach.files.length > 0) remove.checked = false;
    });
  }
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';