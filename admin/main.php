<?php
/* Single admin layout.
   Expects: $pageTitle, $activeNav, $content
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

$userName  = $_SESSION['user_name']  ?? 'User';
$userRole  = $_SESSION['user_role']  ?? '';
$userId    = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$pageTitle = $pageTitle ?? 'Dashboard';
$activeNav = $activeNav ?? '';
$content   = $content   ?? '';

if (!function_exists('nav_active')) {
    function nav_active($key, $activeNav) {
        return $key === $activeNav ? 'active' : '';
    }
}

/* ================================================================
   NOTIFICATIONS — unread loans due within 3 days (current user only)
================================================================ */
$notifications = [];

if (isset($conn) && $conn instanceof mysqli && $userId > 0) {

    $sql = "SELECT
                l.id,
                l.loan_code,
                l.due_date,
                l.balance,
                l.purpose,
                c.full_name  AS client_name,
                DATEDIFF(l.due_date, CURRENT_DATE) AS days_left
            FROM loans l
            INNER JOIN clients c ON c.id = l.client_id
            LEFT JOIN notification_reads nr
                   ON nr.loan_id = l.id AND nr.user_id = ?
            WHERE l.status = 'active'
              AND l.balance > 0
              AND l.due_date BETWEEN CURRENT_DATE
                                 AND DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY)
              AND nr.loan_id IS NULL
            ORDER BY l.due_date ASC, l.id ASC
            LIMIT 15";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $notifications[] = $r;
        $stmt->close();
    }
}

$notificationCount = count($notifications);

function notif_due_label(int $days): string {
    return match (true) {
        $days <= 0  => 'Due today',
        $days === 1 => 'Due tomorrow',
        default     => 'Due in ' . $days . ' days',
    };
}

function notif_ksh($n): string {
    return 'Ksh ' . number_format((float) $n, 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="description" content="Jolly Micro Loans admin dashboard">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | Jolly Micro Loans</title>

  <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="../assets/vendors/bootstrap-icons/bootstrap-icons.css">
  <link rel="stylesheet" href="../assets/css/style.css">
</head>

<body>
  <div class="admin-shell">
    <div class="sidebar-backdrop" data-sidebar-close></div>

    <aside class="admin-sidebar" id="adminSidebar" aria-label="Main navigation">
      <div class="sidebar-header">
        <a class="brand-mark" href="dashboard.php" aria-label="Jolly Micro Loans dashboard">
          <img src="../assets/images/icon.png"
            alt="Jolly Ventures"
            class="auth-brand-logo"
            width="26" height="26">
          <span class="brand-copy">
            <span class="brand-title">Jolly Micro Loans</span>
            <span class="brand-subtitle">Admin</span>
          </span>
        </a>
      </div>

      <nav class="sidebar-nav">
  <a class="nav-link <?= nav_active('dashboard', $activeNav) ?>" href="dashboard.php">
    <span class="nav-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
    <span class="nav-text">Dashboard</span>
  </a>
  <a class="nav-link <?= nav_active('clients', $activeNav) ?>" href="clients.php">
    <span class="nav-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></span>
    <span class="nav-text">Clients</span>
  </a>
  <a class="nav-link <?= nav_active('guarantors', $activeNav) ?>" href="guarantors.php">
    <span class="nav-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
    <span class="nav-text">Guarantors</span>
  </a>
  <a class="nav-link <?= nav_active('loans', $activeNav) ?>" href="loans.php">
    <span class="nav-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
    <span class="nav-text">Loans</span>
  </a>
  <a class="nav-link <?= nav_active('payments', $activeNav) ?>" href="payments.php">
    <span class="nav-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
    <span class="nav-text">Payments</span>
  </a>
  <a class="nav-link <?= nav_active('reports', $activeNav) ?>" href="reports.php">
    <span class="nav-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span>
    <span class="nav-text">Reports</span>
  </a>
</nav>


      <div class="sidebar-footer">
        <span class="status-dot"></span>
        <span class="sidebar-footer-text">System running smoothly</span>
      </div>
    </aside>

    <div class="admin-main">
      <nav class="navbar admin-navbar navbar-expand bg-white">
        <div class="container-fluid px-3 px-lg-4">
          <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-controls="adminSidebar" aria-expanded="true" aria-label="Toggle sidebar">
            <span></span><span></span><span></span>
          </button>

          <form class="d-none d-md-flex ms-3 flex-grow-1" role="search">
            <input class="form-control search-input" type="search" placeholder="Search users, orders, reports" aria-label="Search">
          </form>

          <div class="navbar-actions ms-auto">
            <button class="icon-button theme-toggle" type="button" data-theme-toggle aria-label="Switch color theme" title="Switch color theme">
              <i class="bi bi-moon-stars" data-theme-icon aria-hidden="true"></i>
            </button>

            <!-- ============ Notifications ============ -->
            <div class="dropdown">
              <button class="icon-button" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Notifications">
                <?php if ($notificationCount > 0): ?>
                  <span class="notification-dot"></span>
                <?php endif; ?>
                <i class="bi bi-bell" aria-hidden="true"></i>
              </button>

              <div class="dropdown-menu dropdown-menu-end notification-menu">
                <div class="dropdown-header fw-bold text-body d-flex justify-content-between align-items-center">
                  <span>Notifications</span>
                  <?php if ($notificationCount > 0): ?>
                    <span class="badge text-bg-warning"><?= $notificationCount ?></span>
                  <?php endif; ?>
                </div>

                <?php if (empty($notifications)): ?>
                  <div class="dropdown-item text-muted small py-3 text-center">
                    <i class="bi bi-check2-circle fs-4 d-block mb-1 text-success"></i>
                    No new notifications.
                  </div>
                <?php else: ?>
                  <?php foreach ($notifications as $n): ?>
                    <?php
                      $days = (int) $n['days_left'];
                      $label = notif_due_label($days);
                      $labelColor = match (true) {
                          $days <= 0  => 'text-danger',
                          $days === 1 => 'text-warning',
                          default     => 'text-muted',
                      };
                    ?>
                    <a class="dropdown-item"
                       href="notification-read.php?loan_id=<?= (int) $n['id'] ?>&amp;redirect=<?= urlencode('loan-view.php?id=' . (int) $n['id']) ?>">
                      <span class="notification-title">
                        <?= htmlspecialchars($n['client_name'], ENT_QUOTES, 'UTF-8') ?>
                        · <?= htmlspecialchars($n['loan_code'], ENT_QUOTES, 'UTF-8') ?>
                      </span>
                      <span class="notification-time <?= $labelColor ?>">
                        <?= htmlspecialchars($label) ?>
                        · <?= htmlspecialchars(notif_ksh($n['balance'])) ?> balance
                      </span>
                    </a>
                  <?php endforeach; ?>

                  <div class="dropdown-divider"></div>
                  <a class="dropdown-item text-center small fw-semibold text-primary" href="loans.php?filter=due_soon">
                    View all due loans
                  </a>
                <?php endif; ?>
              </div>
            </div>

            <div class="dropdown">
              <button class="profile-button dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                
                <span class="profile-name d-none d-sm-inline"><?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?></span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="profile.php">Profile</a></li>
                <li><a class="dropdown-item" href="settings.php">Account settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
              </ul>
            </div>
          </div>
        </div>
      </nav>

      <main class="dashboard-content">
        <div class="container-fluid px-3 px-lg-4 py-4">
          <?= $content ?>
        </div>
      </main>

      <footer class="admin-footer">
        <div class="container-fluid px-3 px-lg-4">
          <span>Copyright <?= date('Y') ?> Jolly Micro Loans.</span>
        </div>
      </footer>
    </div>
  </div>

  <script src="../assets/js/bootstrap.bundle.min.js"></script>
  <script src="../assets/js/main.js"></script>
</body>
</html>