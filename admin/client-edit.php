<?php
$pageTitle = 'Edit Client';
$activeNav = 'clients';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SESSION ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   VALIDATE ?id
---------------------------------------------------------------- */
$clientId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($clientId <= 0) {
    $_SESSION['flash'] = 'Invalid client ID.';
    header('Location: clients.php');
    exit;
}

/* ----------------------------------------------------------------
   UPLOAD CONFIG
---------------------------------------------------------------- */
$uploadDirFs = __DIR__ . '/../assets/client-attachments/';
$maxBytes    = 8 * 1024 * 1024;
$allowedMime = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
    'video/mp4'  => 'mp4',
];
if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0775, true);
}

/* ---------- HELPERS ---------- */
function save_client_upload(array $file, string $fsDir, array $allowedMime, int $maxBytes): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'Upload failed (code ' . $file['error'] . ').'];
    if ($file['size'] > $maxBytes) return [null, 'File exceeds 8 MB: ' . htmlspecialchars($file['name'])];

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
    }
    if (!$mime || !isset($allowedMime[$mime])) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fallback = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif','mp4'=>'mp4'];
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
   FETCH CLIENT
---------------------------------------------------------------- */
$stmt = $conn->prepare(
    "SELECT id, client_code, full_name, email, phone, id_number,
            date_of_birth, gender, location, id_document_url, photo_url,
            uploads, status, notes, join_date, created_at
     FROM clients
     WHERE id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $clientId);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    $_SESSION['flash'] = 'Client not found.';
    header('Location: clients.php');
    exit;
}

/* ----------------------------------------------------------------
   PARSE EXISTING UPLOADS
---------------------------------------------------------------- */
$existingAttachments = [];
if (!empty($client['uploads'])) {
    $decoded = json_decode($client['uploads'], true);
    if (is_array($decoded)) $existingAttachments = $decoded;
}

/* ----------------------------------------------------------------
   INITIAL FORM STATE
---------------------------------------------------------------- */
$errors = [];
$form = [
    'full_name'      => $client['full_name'],
    'email'          => $client['email'] ?? '',
    'phone'          => $client['phone'],
    'id_number'      => $client['id_number'],
    'date_of_birth'  => $client['date_of_birth'] ?? '',
    'gender'         => $client['gender'] ?? '',
    'location'       => $client['location'] ?? '',
    'status'         => $client['status'],
    'notes'          => $client['notes'] ?? '',
];

/* Track which existing attachments the user wants to keep */
$keepAttachments = $existingAttachments;

/* ----------------------------------------------------------------
   HANDLE POST
---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($form as $k => $v) {
        $form[$k] = trim($_POST[$k] ?? '');
    }

    /* Attachments the user chose to keep (checkboxes) */
    $keepAttachments = [];
    if (!empty($_POST['keep_attachments']) && is_array($_POST['keep_attachments'])) {
        foreach ($_POST['keep_attachments'] as $f) {
            $f = trim($f);
            if ($f !== '' && in_array($f, $existingAttachments, true)) {
                $keepAttachments[] = $f;
            }
        }
    }

    /* ---- Validation ---- */
    if ($form['full_name'] === '') $errors[] = 'Full name is required.';
    if ($form['phone']     === '') $errors[] = 'Phone is required.';
    if ($form['id_number'] === '') $errors[] = 'ID number is required.';
    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email.';
    }
    if ($form['date_of_birth'] !== '' &&
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['date_of_birth'])) {
        $errors[] = 'Date of birth is invalid.';
    }
    if ($form['gender'] !== '' &&
        !in_array($form['gender'], ['male','female','other'], true)) {
        $form['gender'] = '';
    }
    if (!in_array($form['status'], ['active','inactive','blacklisted'], true)) {
        $form['status'] = 'active';
    }

    /* ---- Duplicate check (email / phone / id_number, excluding this row) ---- */
    if (empty($errors)) {
        $stmt = $conn->prepare(
            "SELECT id FROM clients
             WHERE id <> ? AND (phone = ? OR id_number = ?)
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("iss", $clientId, $form['phone'], $form['id_number']);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $errors[] = 'Another client already uses that phone or ID number.';
            }
            $stmt->close();
        }
    }

    /* ---- Handle new attachments ---- */
    $newFiles = [];
    if (empty($errors) && !empty($_FILES['new_media']['name'][0])) {
        $count = count($_FILES['new_media']['name']);
        for ($i = 0; $i < $count; $i++) {
            $one = [
                'name'     => $_FILES['new_media']['name'][$i],
                'type'     => $_FILES['new_media']['type'][$i],
                'tmp_name' => $_FILES['new_media']['tmp_name'][$i],
                'error'    => $_FILES['new_media']['error'][$i],
                'size'     => $_FILES['new_media']['size'][$i],
            ];
            if ($one['error'] === UPLOAD_ERR_NO_FILE) continue;
            [$fname, $err] = save_client_upload($one, $uploadDirFs, $allowedMime, $maxBytes);
            if ($err) $errors[] = $err;
            else      $newFiles[] = ['name' => $fname, 'type' => $one['type']];
        }
    }

    /* ---- Handle profile photo replacement ---- */
    $newPhotoUrl = $client['photo_url'];  // keep current unless replaced

    if (empty($errors) && !empty($_FILES['new_photo']['name'])) {
        [$fname, $err] = save_client_upload(
            $_FILES['new_photo'], $uploadDirFs, $allowedMime, $maxBytes
        );
        if ($err) $errors[] = 'Photo: ' . $err;
        else      $newPhotoUrl = $fname;
    }

    /* ---- If a new photo was uploaded, make sure it's in the uploads list too ---- */
    if ($newPhotoUrl && $newPhotoUrl !== ($client['photo_url'] ?? '')) {
        if (!in_array($newPhotoUrl, $keepAttachments, true)) {
            $keepAttachments[] = $newPhotoUrl;
        }
    }

    /* ---- Final uploads array = kept existing + new files ---- */
    $allUploads = $keepAttachments;
    foreach ($newFiles as $nf) {
        if (!in_array($nf['name'], $allUploads, true)) {
            $allUploads[] = $nf['name'];
        }
    }

    $uploadsJson = !empty($allUploads) ? json_encode($allUploads) : null;

    /* ---- Update ---- */
    if (empty($errors)) {
        $emailDb = $form['email']    !== '' ? $form['email']    : null;
        $dobDb   = $form['date_of_birth'] !== '' ? $form['date_of_birth'] : null;
        $gender  = $form['gender']   !== '' ? $form['gender']   : null;
        $locDb   = $form['location'] !== '' ? $form['location'] : null;
        $notesDb = $form['notes']    !== '' ? $form['notes']    : null;

        $sql = "UPDATE clients SET
                    full_name     = ?,
                    email         = ?,
                    phone         = ?,
                    id_number     = ?,
                    date_of_birth = ?,
                    gender        = ?,
                    location      = ?,
                    status        = ?,
                    notes         = ?,
                    photo_url     = ?,
                    uploads       = ?
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            $errors[] = 'Database error: ' . $conn->error;
        } else {
            /* types: s s s s s s s s s s s i
               full_name, email, phone, id_number, dob, gender, location,
               status, notes, photo_url, uploads, id */
            $stmt->bind_param(
                "sssssssssssi",
                $form['full_name'],
                $emailDb,
                $form['phone'],
                $form['id_number'],
                $dobDb,
                $gender,
                $locDb,
                $form['status'],
                $notesDb,
                $newPhotoUrl,
                $uploadsJson,
                $clientId
            );

            if ($stmt->execute()) {
                $stmt->close();
                $_SESSION['flash'] = "Client {$form['full_name']} updated successfully.";
                $conn->close();
                header("Location: client-view.php?id=" . $clientId);
                exit;
            } else {
                $errors[] = 'Could not update client: ' . $stmt->error;
                $stmt->close();
            }
        }
    }
}

/* ---------- DISPLAY HELPERS ---------- */
if (!function_exists('ce_avatar_svg')) {
    function ce_avatar_svg($name) {
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

$currentPhotoUrl = !empty($client['photo_url'])
    ? '../assets/client-attachments/' . rawurlencode($client['photo_url'])
    : ce_avatar_svg($client['full_name']);

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Client Management</p>
      <h1 class="h3 mb-1">Edit Client</h1>
      <p class="text-muted mb-0">Update profile details, status, and attachments for <?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?>.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="client-view.php?id=<?= (int) $client['id'] ?>">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Client
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="clients.php">
      <i class="bi bi-people" aria-hidden="true"></i> All Clients
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
             alt="Client photo">
        <h2 class="h5 mb-1" id="previewName"><?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <span class="badge text-bg-secondary"><?= htmlspecialchars($client['client_code'], ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <div class="info-list mt-4 text-start">
        <div><span>Joined</span><strong><?= htmlspecialchars($client['join_date'] ? date('M j, Y', strtotime($client['join_date'])) : '—') ?></strong></div>
        <div><span>Status</span><strong id="previewStatus"><?= htmlspecialchars(ucfirst($client['status'])) ?></strong></div>
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
            <span>Client Information</span>
          </h2>
          <p class="text-muted mb-0">Edit fields and click <strong>Save Changes</strong> when done.</p>
        </div>
      </div>

      <div class="row g-3">
        <!-- Full name -->
        <div class="col-md-6">
          <label class="form-label" for="full_name">Full name <span class="text-danger">*</span></label>
          <input class="form-control" id="full_name" name="full_name" type="text" required
                 value="<?= htmlspecialchars($form['full_name'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Full name is required.</div>
        </div>

        <!-- Client code (read-only) -->
        <div class="col-md-6">
          <label class="form-label" for="client_code_display">Client Code</label>
          <input class="form-control" id="client_code_display" type="text"
                 value="<?= htmlspecialchars($client['client_code'], ENT_QUOTES, 'UTF-8') ?>" readonly>
          <div class="form-text">Auto-generated — cannot be changed.</div>
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
          <label class="form-label" for="phone">Phone <span class="text-danger">*</span></label>
          <input class="form-control" id="phone" name="phone" type="tel" required
                 value="<?= htmlspecialchars($form['phone'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Phone number is required.</div>
        </div>

        <!-- ID Number -->
        <div class="col-md-6">
          <label class="form-label" for="id_number">ID Number <span class="text-danger">*</span></label>
          <input class="form-control" id="id_number" name="id_number" type="text" required
                 value="<?= htmlspecialchars($form['id_number'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">ID number is required.</div>
        </div>

        <!-- Date of birth -->
        <div class="col-md-6">
          <label class="form-label" for="date_of_birth">Date of birth</label>
          <input class="form-control" id="date_of_birth" name="date_of_birth" type="date"
                 value="<?= htmlspecialchars($form['date_of_birth'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-text">Leave blank if not provided.</div>
        </div>

        <!-- Gender -->
        <div class="col-md-6">
          <label class="form-label" for="gender">Gender</label>
          <select class="form-select" id="gender" name="gender">
            <option value="">— Not specified —</option>
            <option value="male"   <?= $form['gender']==='male'   ? 'selected':'' ?>>Male</option>
            <option value="female" <?= $form['gender']==='female' ? 'selected':'' ?>>Female</option>
            <option value="other"  <?= $form['gender']==='other'  ? 'selected':'' ?>>Other</option>
          </select>
        </div>

        <!-- Location -->
        <div class="col-md-6">
          <label class="form-label" for="location">Location</label>
          <input class="form-control" id="location" name="location" type="text"
                 placeholder="Town / County"
                 value="<?= htmlspecialchars($form['location'], ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <!-- Status -->
        <div class="col-md-6">
          <label class="form-label" for="status">Status</label>
          <select class="form-select" id="status" name="status">
            <option value="active"      <?= $form['status']==='active'      ? 'selected':'' ?>>Active</option>
            <option value="inactive"    <?= $form['status']==='inactive'    ? 'selected':'' ?>>Inactive</option>
            <option value="blacklisted" <?= $form['status']==='blacklisted' ? 'selected':'' ?>>Blacklisted</option>
          </select>
          <div class="form-text">Blacklisted clients should not be given new loans.</div>
        </div>

        <!-- Replace profile photo -->
        <div class="col-12">
          <label class="form-label" for="new_photo">Replace Profile Photo</label>
          <input class="form-control" id="new_photo" name="new_photo" type="file"
                 accept="image/jpeg,image/png,image/webp,image/gif">
          <div class="form-text">Leave empty to keep the current photo.</div>
        </div>

        <!-- Existing attachments -->
        <?php if (!empty($existingAttachments)): ?>
          <div class="col-12">
            <label class="form-label d-block">Existing Attachments</label>
            <div class="form-text mb-2">
              Uncheck an item to remove it. Any unchecked files are <strong>not</strong> deleted from disk — they're just detached from this client.
            </div>
            <div class="row g-2">
              <?php foreach ($existingAttachments as $file): ?>
                <?php
                  $url = '../assets/client-attachments/' . rawurlencode($file);
                  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                  $isImage = in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
                  $isVideo = in_array($ext, ['mp4','webm','mov'], true);
                ?>
                <div class="col-6 col-md-4 col-lg-3">
                  <label class="border rounded p-2 d-block h-100" style="cursor:pointer;">
                    <input type="checkbox"
                           class="form-check-input me-2"
                           name="keep_attachments[]"
                           value="<?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>"
                           checked>
                    <?php if ($isImage): ?>
                      <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                           class="img-thumbnail w-100 mt-1"
                           style="max-height:110px; object-fit:cover;" alt="Attachment">
                    <?php elseif ($isVideo): ?>
                      <video src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                             class="img-thumbnail w-100 mt-1"
                             style="max-height:110px" controls></video>
                    <?php else: ?>
                      <span class="badge text-bg-secondary mt-1">
                        <?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>
                      </span>
                    <?php endif; ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- New attachments -->
        <div class="col-12">
          <label class="form-label" for="new_media">Add New Attachments</label>
          <input class="form-control" id="new_media" name="new_media[]" type="file"
                 accept="image/*,video/*" multiple>
          <div class="form-text">Upload additional images or videos. Accepted: JPG, PNG, GIF, MP4. Max 8 MB each.</div>
          <div id="newMediaPreview" class="mt-2 d-flex flex-wrap gap-2"></div>
        </div>

        <!-- Notes -->
        <div class="col-12">
          <label class="form-label" for="notes">Notes</label>
          <textarea class="form-control" id="notes" name="notes" rows="4"
                    placeholder="Optional onboarding notes"><?= htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      </div>

      <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="client-view.php?id=<?= (int) $client['id'] ?>">Cancel</a>
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
  const $name = document.getElementById('full_name');
  const $previewName = document.getElementById('previewName');
  if ($name && $previewName) {
    $name.addEventListener('input', () => {
      $previewName.textContent = $name.value.trim() || 'Client name';
    });
  }

  /* Live preview — photo */
  const $photo = document.getElementById('new_photo');
  const $previewAvatar = document.getElementById('previewAvatar');
  if ($photo && $previewAvatar) {
    $photo.addEventListener('change', () => {
      const f = $photo.files[0];
      if (!f) return;
      $previewAvatar.src = URL.createObjectURL(f);
    });
  }

  /* Live preview — status */
  const $status = document.getElementById('status');
  const $previewStatus = document.getElementById('previewStatus');
  if ($status && $previewStatus) {
    $status.addEventListener('change', () => {
      const v = $status.value;
      $previewStatus.textContent = v.charAt(0).toUpperCase() + v.slice(1);
    });
  }

  /* New media preview */
  const $media   = document.getElementById('new_media');
  const $preview = document.getElementById('newMediaPreview');
  if ($media && $preview) {
    $media.addEventListener('change', () => {
      $preview.innerHTML = '';
      Array.from($media.files).forEach(file => {
        const url = URL.createObjectURL(file);
        const html = file.type.startsWith('image/')
          ? `<img src="${url}" class="img-thumbnail" style="max-height:100px" alt="Preview">`
          : `<video src="${url}" class="img-thumbnail" style="max-height:100px" controls></video>`;
        $preview.insertAdjacentHTML('beforeend', html);
      });
    });
  }
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';