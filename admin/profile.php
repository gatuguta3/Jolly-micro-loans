<?php
$pageTitle = 'Profile';
$activeNav = 'dashboard';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SESSION ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   AUTH GUARD
---------------------------------------------------------------- */
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
if ($userId <= 0) {
    $_SESSION['flash'] = 'Please sign in.';
    header('Location: login.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH CURRENT USER (before POST so we can diff)
---------------------------------------------------------------- */
$stmt = $conn->prepare(
    "SELECT id, name, email, phone, role, password_hash, created_at
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    $_SESSION['flash'] = 'Your account no longer exists.';
    header('Location: logout.php');
    exit;
}

/* ----------------------------------------------------------------
   HELPERS
---------------------------------------------------------------- */
if (!function_exists('pf_avatar_svg')) {
    function pf_avatar_svg($name) {
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

if (!function_exists('pf_role_label')) {
    function pf_role_label(string $role): string {
        return match ($role) {
            'admin'        => 'Administrator',
            'loan_officer' => 'Loan Officer',
            'user'         => 'User',
            default        => ucfirst($role),
        };
    }
}

/* ----------------------------------------------------------------
   HANDLE POST
   Only fields whose posted value differs from the DB value are
   included in the UPDATE statement. Unedited fields are untouched.
---------------------------------------------------------------- */
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name            = trim($_POST['name']  ?? '');
    $email           = trim($_POST['email'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword     = $_POST['new_password']     ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    /* ---- Validation ---- */
    if ($name === '')  $errors[] = 'Name is required.';
    if ($email === '') $errors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($phone === '') $errors[] = 'Phone number is required.';

    /* Uniqueness — only check when email/phone actually changed */
    $emailChanged = ($email !== $user['email']);
    $phoneChanged = ($phone !== $user['phone']);

    if (empty($errors) && ($emailChanged || $phoneChanged)) {
        $stmt = $conn->prepare(
            "SELECT id FROM users
             WHERE (email = ? OR phone = ?) AND id <> ?
             LIMIT 1"
        );
        if ($stmt) {
            $stmt->bind_param("ssi", $email, $phone, $userId);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $errors[] = 'That email or phone is already used by another account.';
            }
            $stmt->close();
        }
    }

    /* Password change is optional — if any field is filled, all three are required */
    $passwordChange = ($currentPassword !== '' || $newPassword !== '' || $confirmPassword !== '');
    if ($passwordChange) {
        if ($currentPassword === '') $errors[] = 'Enter your current password to change it.';
        if ($newPassword === '')     $errors[] = 'Enter a new password.';
        elseif (strlen($newPassword) < 6) $errors[] = 'New password must be at least 6 characters.';
        if ($newPassword !== $confirmPassword) $errors[] = 'New password and confirmation do not match.';
    }

    /* ---- Apply update ---- */
    if (empty($errors)) {
        $conn->begin_transaction();
        try {

            /* Verify current password if changing */
            $newHash = null;
            if ($passwordChange) {
                $storedHash = $user['password_hash'] ?? '';

                $currentOk = false;
                if (password_verify($currentPassword, $storedHash)) {
                    $currentOk = true;
                } elseif (preg_match('/^[0-9a-f]{64}$/i', $storedHash)
                          && hash_equals(strtolower($storedHash), hash('sha256', $currentPassword))) {
                    $currentOk = true;
                }

                if (!$currentOk) {
                    throw new Exception('Current password is incorrect.');
                }

                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            /* Build the UPDATE from ONLY the fields that changed */
            $setParts = [];
            $types    = '';
            $params   = [];

            if ($name !== $user['name']) {
                $setParts[] = 'name = ?';
                $types     .= 's';
                $params[]   = $name;
            }
            if ($emailChanged) {
                $setParts[] = 'email = ?';
                $types     .= 's';
                $params[]   = $email;
            }
            if ($phoneChanged) {
                $setParts[] = 'phone = ?';
                $types     .= 's';
                $params[]   = $phone;
            }
            if ($newHash !== null) {
                $setParts[] = 'password_hash = ?';
                $types     .= 's';
                $params[]   = $newHash;
            }

            if (empty($setParts)) {
                /* Nothing to update — just redirect with a neutral message */
                $conn->rollback();
                $_SESSION['flash'] = 'No changes were made.';
                $conn->close();
                header('Location: profile.php');
                exit;
            }

            $types   .= 'i';
            $params[] = $userId;

            $sql = "UPDATE users SET " . implode(', ', $setParts) . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

            $stmt->bind_param($types, ...$params);

            if (!$stmt->execute()) throw new Exception('Update failed: ' . $stmt->error);
            $stmt->close();

            $conn->commit();

            /* Keep session name/email in sync */
            if ($name !== $user['name'])   $_SESSION['user_name']  = $name;
            if ($emailChanged)             $_SESSION['user_email'] = $email;

            /* Build a human message listing what was changed */
            $changedLabels = [];
            if ($name !== $user['name'])   $changedLabels[] = 'name';
            if ($emailChanged)             $changedLabels[] = 'email';
            if ($phoneChanged)             $changedLabels[] = 'phone';
            if ($newHash !== null)         $changedLabels[] = 'password';

            $_SESSION['flash'] = 'Updated: ' . implode(', ', $changedLabels) . '.';
            $conn->close();
            header('Location: profile.php');
            exit;

        } catch (Throwable $ex) {
            $conn->rollback();
            $errors[] = $ex->getMessage();
        }
    }

    /* Repopulate the form with posted values on error */
    $user['name']  = $name;
    $user['email'] = $email;
    $user['phone'] = $phone;
}

/* ---------- Prepare display ---------- */
$avatarUrl  = pf_avatar_svg($user['name']);
$roleLabel  = pf_role_label($user['role']);
$roleClass  = match ($user['role']) {
    'admin'        => 'text-bg-primary',
    'loan_officer' => 'text-bg-warning',
    default        => 'text-bg-secondary',
};
$memberSince = $user['created_at'] ? date('M j, Y', strtotime($user['created_at'])) : '—';

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-person-badge" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Account</p>
      <h1 class="h3 mb-1">Profile</h1>
      <p class="text-muted mb-0">Manage your personal details and contact information.</p>
    </div>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

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
  <!-- Left column: read-only profile card -->
  <div class="col-12 col-xl-4">
    <div class="panel h-100 text-center profile-card">
      <div class="profile-cover">
        <img src="../assets/images/png/dasher-ui-bootstrap-5.jpg" alt="Profile cover">
      </div>
      <img class="avatar-img avatar-xl profile-photo"
           src="<?= htmlspecialchars($avatarUrl, ENT_QUOTES, 'UTF-8') ?>"
           alt="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>">
      <h2 class="h5 mt-3 mb-1"><?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?></h2>
      <p class="text-muted mb-3"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></p>

      <div class="d-flex justify-content-center gap-2">
        <span class="badge <?= $roleClass ?>"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <span class="badge text-bg-success">Active</span>
      </div>

      <div class="info-list mt-4 text-start">
        <div><span>Email</span><strong><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Phone</span><strong><?= htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Member Since</span><strong><?= htmlspecialchars($memberSince) ?></strong></div>
      </div>
    </div>
  </div>

  <!-- Right column: edit form -->
  <div class="col-12 col-xl-8">
    <form class="panel needs-validation" method="post" action="profile.php" novalidate id="profileForm">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-person-gear" aria-hidden="true"></i>
            <span>Profile Settings</span>
          </h2>
          <p class="text-muted mb-0">Only the fields you edit will be saved.</p>
        </div>
        <span class="badge text-bg-warning d-none" id="unsavedBadge">Unsaved changes</span>
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label" for="profileName">Name</label>
          <input class="form-control" id="profileName" name="name" type="text" required
                 value="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>"
                 data-original="<?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Name is required.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="profileEmail">Email</label>
          <input class="form-control" id="profileEmail" name="email" type="email" required
                 value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>"
                 data-original="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Enter a valid email.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="profilePhone">Phone</label>
          <input class="form-control" id="profilePhone" name="phone" type="tel" required
                 value="<?= htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') ?>"
                 data-original="<?= htmlspecialchars($user['phone'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Phone number is required.</div>
        </div>

        <div class="col-md-6">
          <label class="form-label" for="profileRole">Role</label>
          <input class="form-control" id="profileRole" type="text" readonly
                 value="<?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-text">Role can only be changed by an administrator.</div>
        </div>
      </div>

      <!-- Password section -->
      <hr class="my-4">

      <div class="panel-header px-0 mb-3">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-shield-lock" aria-hidden="true"></i>
            <span>Change Password</span>
          </h2>
          <p class="text-muted mb-0">Leave all password fields blank to keep your current password.</p>
        </div>
      </div>

      <div class="row g-3">
        <!-- Current password -->
        <div class="col-12">
          <label class="form-label" for="currentPassword">Current Password</label>
          <div class="input-group">
            <input class="form-control" id="currentPassword" name="current_password"
                   type="password" autocomplete="current-password">
            <button class="btn btn-outline-secondary toggle-password" type="button"
                    data-target="currentPassword" tabindex="-1"
                    aria-label="Show current password">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <!-- New password -->
        <div class="col-md-6">
          <label class="form-label" for="newPassword">New Password</label>
          <div class="input-group">
            <input class="form-control" id="newPassword" name="new_password"
                   type="password" minlength="6" autocomplete="new-password">
            <button class="btn btn-outline-secondary toggle-password" type="button"
                    data-target="newPassword" tabindex="-1"
                    aria-label="Show new password">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
          <div class="form-text">At least 6 characters.</div>
        </div>

        <!-- Confirm password -->
        <div class="col-md-6">
          <label class="form-label" for="confirmPassword">Confirm New Password</label>
          <div class="input-group">
            <input class="form-control" id="confirmPassword" name="confirm_password"
                   type="password" minlength="6" autocomplete="new-password">
            <button class="btn btn-outline-secondary toggle-password" type="button"
                    data-target="confirmPassword" tabindex="-1"
                    aria-label="Show confirmed password">
              <i class="bi bi-eye" aria-hidden="true"></i>
            </button>
          </div>
        </div>
      </div>

      <div class="d-flex justify-content-end mt-4">
        <a class="btn btn-light me-2" href="dashboard.php">Cancel</a>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-check2-circle" aria-hidden="true"></i> Save Profile
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

  /* ---------- Password show / hide toggles ---------- */
  document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', () => {
      const targetId = btn.getAttribute('data-target');
      const input = document.getElementById(targetId);
      if (!input) return;

      const icon = btn.querySelector('i');

      if (input.type === 'password') {
        input.type = 'text';
        if (icon) { icon.classList.remove('bi-eye'); icon.classList.add('bi-eye-slash'); }
        btn.setAttribute('aria-label', 'Hide password');
      } else {
        input.type = 'password';
        if (icon) { icon.classList.remove('bi-eye-slash'); icon.classList.add('bi-eye'); }
        btn.setAttribute('aria-label', 'Show password');
      }
    });
  });

  /* ---------- Client-side: require all three password fields together ---------- */
  const $cur = document.getElementById('currentPassword');
  const $new = document.getElementById('newPassword');
  const $con = document.getElementById('confirmPassword');
  if ($cur && $new && $con) {
    const sync = () => {
      const any = $cur.value || $new.value || $con.value;
      $cur.required = any;
      $new.required = any;
      $con.required = any;
    };
    [$cur, $new, $con].forEach(el => el.addEventListener('input', sync));
    sync();
  }

  /* ---------- "Unsaved changes" indicator ---------- */
  const form = document.getElementById('profileForm');
  const badge = document.getElementById('unsavedBadge');
  if (form && badge) {
    const tracked = form.querySelectorAll('[data-original]');
    const passwordFields = [$cur, $new, $con].filter(Boolean);

    const checkDirty = () => {
      let dirty = false;

      tracked.forEach(el => {
        if (el.value.trim() !== (el.getAttribute('data-original') || '').trim()) {
          dirty = true;
        }
      });

      /* Any password field with content also counts as dirty */
      if (!dirty) {
        dirty = passwordFields.some(el => el.value.length > 0);
      }

      badge.classList.toggle('d-none', !dirty);
    };

    form.addEventListener('input', checkDirty);
    form.addEventListener('change', checkDirty);
    checkDirty();
  }
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';