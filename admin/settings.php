<?php
$pageTitle = 'Settings';
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


/* ================================================================
   4) TEAM ACTIVITY (derived from real records)
================================================================ */
$activity = [];

/* Latest 15 loans */
$res = $conn->query(
    "SELECT l.loan_code, l.principal_amount, l.created_at,
            c.full_name AS client_name
     FROM loans l
     INNER JOIN clients c ON c.id = l.client_id
     ORDER BY l.id DESC LIMIT 15"
);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $activity[] = [
            'ts'    => strtotime($r['created_at']),
            'color' => 'bg-primary',
            'title' => 'New loan ' . $r['loan_code'] . ' for ' . $r['client_name'],
            'note'  => 'Ksh ' . number_format((float) $r['principal_amount'], 0)
                     . ' · ' . date('M j, H:i', strtotime($r['created_at'])),
        ];
    }
    $res->free();
}

/* Latest 15 payments */
$res = $conn->query(
    "SELECT p.payment_code, p.amount_paid, p.recorded_at,
            c.full_name AS client_name
     FROM payments p
     INNER JOIN clients c ON c.id = p.client_id
     ORDER BY p.id DESC LIMIT 15"
);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $activity[] = [
            'ts'    => strtotime($r['recorded_at']),
            'color' => 'bg-success',
            'title' => 'Payment ' . $r['payment_code'] . ' from ' . $r['client_name'],
            'note'  => 'Ksh ' . number_format((float) $r['amount_paid'], 0)
                     . ' · ' . date('M j, H:i', strtotime($r['recorded_at'])),
        ];
    }
    $res->free();
}

/* Latest 10 clients */
$res = $conn->query(
    "SELECT client_code, full_name, created_at
     FROM clients ORDER BY id DESC LIMIT 10"
);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $activity[] = [
            'ts'    => strtotime($r['created_at']),
            'color' => 'bg-warning',
            'title' => 'New client ' . $r['full_name'],
            'note'  => $r['client_code'] . ' · ' . date('M j, H:i', strtotime($r['created_at'])),
        ];
    }
    $res->free();
}

/* Sort newest first, trim to 30 */
usort($activity, fn($a, $b) => $b['ts'] <=> $a['ts']);
$activity = array_slice($activity, 0, 30);

/* Drop the sort key */
foreach ($activity as &$a) unset($a['ts']);
unset($a);


?>


          <div class="page-heading">
            <div class="page-heading-copy">
              <span class="page-icon"><i class="bi bi-gear" aria-hidden="true"></i></span>
              <div>
                <p class="eyebrow mb-1">Workspace</p>
                <h1 class="h3 mb-1">Settings</h1>
                <p class="text-muted mb-0">Customize workspace defaults, security options, and notification preferences.</p>
              </div>
            </div>
            
          </div>

          <section class="row g-3">

              
          </section>




<script>
(() => {
  const list = document.getElementById('activityList');
  if (!list) return;

  const items   = list.querySelectorAll('.activity-page-item');
  const prevBtn = document.getElementById('activityPrev');
  const nextBtn = document.getElementById('activityNext');
  const counter = document.getElementById('activityCounter');
  const range   = document.getElementById('activityRange');

  // Nothing to paginate
  if (!items.length || !prevBtn || !nextBtn) return;

  const PER_PAGE   = 5;
  const TOTAL      = items.length;
  const TOTAL_PAGES = Math.ceil(TOTAL / PER_PAGE);
  let page = 1;

  function render() {
    const start = (page - 1) * PER_PAGE;
    const end   = Math.min(start + PER_PAGE, TOTAL);

    items.forEach((el, i) => {
      el.style.display = (i >= start && i < end) ? '' : 'none';
    });

    prevBtn.disabled = page === 1;
    nextBtn.disabled = page === TOTAL_PAGES;

    if (counter) counter.textContent = `Page ${page} of ${TOTAL_PAGES}`;
    if (range)   range.textContent   = `${start + 1}–${end} of ${TOTAL}`;
  }

  prevBtn.addEventListener('click', () => {
    if (page > 1) { page--; render(); }
  });

  nextBtn.addEventListener('click', () => {
    if (page < TOTAL_PAGES) { page++; render(); }
  });

  render();   // first paint
})();
</script>
     






<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';