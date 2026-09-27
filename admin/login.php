<?php
session_start();

/* ---------- DB CONNECTION (from /config) ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- GENERATE CSRF TOKEN ---------- */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ---------- SIMPLE RATE LIMIT (per session) ---------- */
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_attempt']   = time();
}
if (time() - $_SESSION['last_attempt'] > 900) { // 15 min reset
    $_SESSION['login_attempts'] = 0;
}

$errors = [];
$email  = "";

/* ---------- HANDLE LOGIN ---------- *//* ---------- HANDLE LOGIN ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF
    if (!isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $errors[] = "Invalid session. Please refresh and try again.";
    }

    // Rate limit
    if ($_SESSION['login_attempts'] >= 5) {
        $errors[] = "Too many failed attempts. Please wait 15 minutes.";
    }

    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email.";
    }
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters.";
    }

    if (empty($errors)) {
        $stmt = $conn->prepare(
            "SELECT id, name, email, password_hash, role 
             FROM users 
             WHERE email = ? 
             LIMIT 1"
        );
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {

            $storedHash = $row['password_hash'];
            $loginOk    = false;
            $needsUpgrade = false;

            /*
             * Detect hash type:
             *  - bcrypt  → starts with $2y$ / $2a$ / $2b$
             *  - argon2  → starts with $argon2
             *  - SHA-256 → 64 hex chars (what your INSERT created)
             */
            if (str_starts_with($storedHash, '$2y$') ||
                str_starts_with($storedHash, '$2a$') ||
                str_starts_with($storedHash, '$2b$') ||
                str_starts_with($storedHash, '$argon2')) {

                // Modern hash — normal path
                if (password_verify($password, $storedHash)) {
                    $loginOk = true;
                    if (password_needs_rehash($storedHash, PASSWORD_DEFAULT)) {
                        $needsUpgrade = true;
                    }
                }
            }
            elseif (preg_match('/^[0-9a-f]{64}$/i', $storedHash)) {
                // Legacy SHA-256 hex digest (your current insert)
                // NOTE: SHA2(x, 256) in MySQL returns lowercase hex of SHA-256
                $computed = hash('sha256', $password);
                if (hash_equals(strtolower($storedHash), $computed)) {
                    $loginOk = true;
                    $needsUpgrade = true;   // migrate to bcrypt now
                }
            }

            if ($loginOk) {

                // Migrate legacy SHA-256 → bcrypt on first successful login
                if ($needsUpgrade) {
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $upd = $conn->prepare("UPDATE users SET password_hash=? WHERE id=?");
                    $upd->bind_param("si", $newHash, $row['id']);
                    $upd->execute();
                    $upd->close();
                }

                session_regenerate_id(true);

                $_SESSION['user_id']    = $row['id'];
                $_SESSION['user_name']  = $row['name'];
                $_SESSION['user_email'] = $row['email'];
                $_SESSION['user_role']  = $row['role'];
                $_SESSION['logged_in']  = true;
                $_SESSION['login_time'] = time();
                $_SESSION['login_attempts'] = 0;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                if (!empty($_POST['remember'])) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, [
                        'expires'  => time() + 60 * 60 * 24 * 30,
                        'path'     => '/',
                        'secure'   => !empty($_SERVER['HTTPS']),
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }

                $stmt->close();
                $conn->close();

                header("Location: main.php");
                exit;
            } else {
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt'] = time();
                $errors[] = "Invalid email or password.";
            }
        } else {
            $_SESSION['login_attempts']++;
            $_SESSION['last_attempt'] = time();
            $errors[] = "Invalid email or password.";
        }

        if (isset($stmt)) $stmt->close();
    }
}

/* ---------- FLASH MESSAGE ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="adminHMD authentication page">
  <title>Login | Jolly Micro loans</title>

  <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="../assets/vendors/bootstrap-icons/bootstrap-icons.css">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body class="auth-body">
  <button class="icon-button theme-toggle auth-theme-toggle" type="button" data-theme-toggle aria-label="Switch color theme" title="Switch color theme">
    <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
  </button>
  <main class="auth-page">
    <section class="auth-card">
      <a class="auth-brand" href="index.html">
        <span class="brand-icon"><i class="bi bi-grid-1x2-fill" aria-hidden="true"></i></span>
        <span><strong>Jolly Micro Loans</strong><small>Sign in to your admin workspace.</small></span>
      </a>
      <div class="auth-visual">
        <img src="../assets/images/png/dasher-ui-bootstrap-5.jpg" alt="adminHMD dashboard interface">
      </div>

      <form class="needs-validation" method="POST" action="login.php" novalidate>
        <input type="hidden" name="csrf_token"
               value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="mb-4">
          <p class="eyebrow mb-1">Secure Access</p>
          <h1 class="h3 mb-1">Login</h1>
          <p class="text-muted mb-0">Sign in to your admin workspace.</p>
        </div>

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $e): ?>
              <div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($flash): ?>
          <div class="alert alert-success" role="alert">
            <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <div class="mb-3">
          <label class="form-label" for="loginEmail">Email address</label>
          <input class="form-control" id="loginEmail" name="email" type="email"
                 value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" required>
          <div class="invalid-feedback">Enter a valid email.</div>
        </div>

        <div class="mb-3">
          <div class="d-flex justify-content-between">
            <label class="form-label" for="loginPassword">Password</label>
            <a class="small fw-semibold" href="forgot-password.html">Forgot?</a>
          </div>
          <input class="form-control" id="loginPassword" name="password" type="password" minlength="6" required>
          <div class="invalid-feedback">Password must be at least 6 characters.</div>
        </div>

        <div class="form-check mb-4">
          <input class="form-check-input" type="checkbox" id="rememberMe" name="remember" value="1">
          <label class="form-check-label" for="rememberMe">Remember me</label>
        </div>

        <button class="btn btn-primary w-100" type="submit">
          <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Sign In
        </button>
      </form>
    </section>
  </main>

  <script src="../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../assets/js/main.js"></script>
</body>
</html>