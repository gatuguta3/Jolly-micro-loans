<?php
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- HELPERS ---------- */
function d_ksh($n): string {
    return 'Ksh ' . number_format((float) $n, 0);
}

function d_pct($n, $decimals = 1): string {
    return number_format((float) $n, $decimals) . '%';
}

/* ================================================================
   1) TOP METRIC CARDS
================================================================ */
$top = [
    'total_principal'   => 0.0,
    'total_interest'    => 0.0,
    'total_collected'   => 0.0,
    'outstanding'       => 0.0,
];

/* Principal issued + interest expected (all loans) */
$res = $conn->query(
    "SELECT
        COALESCE(SUM(principal_amount), 0) AS principal,
        COALESCE(SUM(interest_amount),  0) AS interest,
        COALESCE(SUM(total_due),        0) AS total_due
     FROM loans"
);
if ($res && $r = $res->fetch_assoc()) {
    $top['total_principal'] = (float) $r['principal'];
    $top['total_interest']  = (float) $r['interest'];
    $topTotalDue            = (float) $r['total_due'];
    $res->free();
}

/* Amount collected (all payments) */
$res = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS total FROM payments");
if ($res && $r = $res->fetch_assoc()) {
    $top['total_collected'] = (float) $r['total'];
    $res->free();
}

/* Outstanding balance (active + defaulted loans) */
$res = $conn->query(
    "SELECT COALESCE(SUM(balance), 0) AS total
     FROM loans WHERE status IN ('active','overdue','defaulted')"
);
if ($res && $r = $res->fetch_assoc()) {
    $top['outstanding'] = (float) $r['total'];
    $res->free();
}

/* Month-over-month deltas for principal + collected (this month vs last) */
function monthly_sum(mysqli $conn, string $table, string $column, string $dateCol): array {
    $res = $conn->query(
        "SELECT
            COALESCE(SUM(CASE WHEN DATE_FORMAT({$dateCol}, '%Y-%m') = DATE_FORMAT(CURRENT_DATE, '%Y-%m')
                              THEN {$column} ELSE 0 END), 0) AS this_month,
            COALESCE(SUM(CASE WHEN DATE_FORMAT({$dateCol}, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 1 MONTH), '%Y-%m')
                              THEN {$column} ELSE 0 END), 0) AS last_month
         FROM {$table}"
    );
    $out = ['this' => 0.0, 'last' => 0.0, 'delta' => 0.0];
    if ($res && $r = $res->fetch_assoc()) {
        $out['this'] = (float) $r['this_month'];
        $out['last'] = (float) $r['last_month'];
        $out['delta'] = $out['last'] > 0
            ? (($out['this'] - $out['last']) / $out['last']) * 100
            : ($out['this'] > 0 ? 100 : 0);
    }
    return $out;
}

$deltaPrincipal  = monthly_sum($conn, 'loans',    'principal_amount', 'date_issued');
$deltaInterest   = monthly_sum($conn, 'loans',    'interest_amount',  'date_issued');
$deltaCollected  = monthly_sum($conn, 'payments', 'amount_paid',      'payment_date');

/* ================================================================
   2) LOANS METRICS
================================================================ */
$loans = [
    'active'          => 0,
    'cleared'         => 0,
    'overdue'         => 0,
    'defaulted'       => 0,
    'due_today'       => 0,
    'total_due_value' => 0.0,
];

$res = $conn->query(
    "SELECT
        SUM(CASE WHEN status = 'active'    THEN 1 ELSE 0 END) AS active_cnt,
        SUM(CASE WHEN status = 'cleared'   THEN 1 ELSE 0 END) AS cleared_cnt,
        SUM(CASE WHEN status = 'defaulted' THEN 1 ELSE 0 END) AS defaulted_cnt,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) AS overdue_cnt,
        SUM(CASE WHEN status = 'active' AND due_date = CURRENT_DATE THEN 1 ELSE 0 END) AS due_today_cnt,
        COALESCE(SUM(CASE WHEN status IN ('active','defaulted') THEN balance ELSE 0 END), 0) AS balance_due
     FROM loans"
);
if ($res && $r = $res->fetch_assoc()) {
    $loans['active']          = (int) $r['active_cnt'];
    $loans['cleared']         = (int) $r['cleared_cnt'];
    $loans['defaulted']       = (int) $r['defaulted_cnt'];
    $loans['overdue']         = (int) $r['overdue_cnt'];
    $loans['due_today']       = (int) $r['due_today_cnt'];
    $loans['total_due_value'] = (float) $r['balance_due'];
    $res->free();
}

/* Collection rate = total paid / total due */
$collectionRate = $topTotalDue > 0
    ? ($top['total_collected'] / $topTotalDue) * 100
    : 0;

/* Loan counts for performance donut */
$totalLoans = $loans['active'] + $loans['cleared'] + $loans['defaulted'];
$collectedPct = $totalLoans > 0 ? ($loans['cleared'] / $totalLoans) * 100 : 0;
$defaultedPct = $totalLoans > 0 ? ($loans['defaulted'] / $totalLoans) * 100 : 0;

/* ================================================================
   3) MONTHLY CHART DATA (last 6 months)
================================================================ */
function monthly_series(mysqli $conn, string $table, string $column, string $dateCol): array {
    $rows = [];
    $res = $conn->query(
        "SELECT
            DATE_FORMAT({$dateCol}, '%Y-%m') AS ym,
            DATE_FORMAT({$dateCol}, '%b')    AS label,
            COALESCE(SUM({$column}), 0)      AS total
         FROM {$table}
         WHERE {$dateCol} >= DATE_SUB(CURRENT_DATE, INTERVAL 6 MONTH)
         GROUP BY ym, label
         ORDER BY ym ASC"
    );
    if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; $res->free(); }
    return $rows;
}

$principalSeries = monthly_series($conn, 'loans',    'principal_amount', 'date_issued');
$interestSeries  = monthly_series($conn, 'loans',    'interest_amount',  'date_issued');

/* Helper: render bars normalized to the tallest value (min 8% so tiny bars are visible) */
function render_bars(array $series): string {
    if (empty($series)) {
        return '<div class="text-muted small text-center py-4">No data available.</div>';
    }
    $max = 0;
    foreach ($series as $s) $max = max($max, (float) $s['total']);
    if ($max <= 0) {
        return '<div class="text-muted small text-center py-4">No data available.</div>';
    }

    $html = '';
    foreach ($series as $s) {
        $pct = max(8, round(((float) $s['total'] / $max) * 100));
        $label = htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars(
            $s['label'] . ': Ksh ' . number_format((float) $s['total'], 0),
            ENT_QUOTES, 'UTF-8'
        );
        $html .= '<div class="chart-column bar-' . $pct . '" title="' . $title . '">'
               . '<span></span><small>' . $label . '</small></div>';
    }
    return $html;
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

ob_start();
?>

<style>
  /* ---------- Hide/show dashboard values ---------- */
  .dashboard-root.values-hidden .metric-value,
  .dashboard-root.values-hidden .metric-meta,
  .dashboard-root.values-hidden .donut-chart > span,
  .dashboard-root.values-hidden .legend-list strong {
    filter: blur(7px);
    user-select: none;
    pointer-events: none;
    transition: filter .15s ease;
  }

  /* Slight visual hint that the value is intentionally hidden */
  .dashboard-root.values-hidden .metric-value::selection,
  .dashboard-root.values-hidden .metric-meta::selection {
    background: transparent;
  }
</style>

<!-- Wrapper so the toggle only affects this page -->
<div class="dashboard-root values-hidden" id="dashboardRoot">

<!-- ============ Page heading ============ -->
<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Overview</p>
      <h1 class="h3 mb-1">Dashboard</h1>
      <p class="text-muted mb-0">Monitor performance, loans, collections, and clients from one clean workspace.</p>
    </div>
  </div>
  <div class="heading-actions">
    <button class="btn btn-outline-secondary btn-sm" type="button" id="toggleValuesBtn"
            aria-pressed="true" title="Toggle card values">
      <i class="bi bi-eye" aria-hidden="true"></i>
      <span class="d-none d-sm-inline">Show values</span>
    </button>
    <button class="btn btn-outline-secondary btn-sm" type="button">
      <i class="bi bi-download" aria-hidden="true"></i> Export
    </button>
    <button class="btn btn-primary btn-sm" type="button">
      <i class="bi bi-file-earmark-plus" aria-hidden="true"></i> Create Report
    </button>
  </div>
</div>

<!-- ============ Top metric cards ============ -->
<section class="row g-3 mt-1" aria-label="Dashboard metrics">

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Total Principal issued</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= d_ksh($top['total_principal']) ?></div>
      <div class="metric-meta  h1">
        <span class=" <?= $deltaPrincipal['delta'] >= 0 ? 'text-success' : 'text-danger' ?>">
          <?= ($deltaPrincipal['delta'] >= 0 ? '+' : '') . d_pct($deltaPrincipal['delta']) ?>
        </span>
        <span>from last month</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-success">
      <div class="metric-top">
        <span class="metric-label">Total Interest expected</span>
        <span class="metric-icon"><i class="bi bi-bag-check" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= d_ksh($top['total_interest']) ?></div>
      <div class="metric-meta">
        <span class="<?= $deltaInterest['delta'] >= 0 ? 'text-success' : 'text-danger' ?>">
          <?= ($deltaInterest['delta'] >= 0 ? '+' : '') . d_pct($deltaInterest['delta']) ?>
        </span>
        <span>new interest</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Total Amount collected</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= d_ksh($top['total_collected']) ?></div>
      <div class="metric-meta">
        <span class="<?= $deltaCollected['delta'] >= 0 ? 'text-success' : 'text-danger' ?>">
          <?= ($deltaCollected['delta'] >= 0 ? '+' : '') . d_pct($deltaCollected['delta']) ?>
        </span>
        <span>from last month</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Outstanding balance</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= d_ksh($top['outstanding']) ?></div>
      <div class="metric-meta">
        <span class="text-muted"><?= number_format($loans['active']) ?></span>
        <span>active loans</span>
      </div>
    </article>
  </div>

</section>

<!-- ============ Loans metrics ============ -->
<section class="row g-3 mt-1" aria-label="Loans metrics">

  <div class="col-12">
    <h1 class="h3 mb-1">Loans</h1>
    <p class="text-muted mb-0">Live counts and balances across your portfolio.</p>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Collection rate</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= d_pct($collectionRate) ?></div>
      <div class="metric-meta">
        <span class="text-success"><?= d_ksh($top['total_collected']) ?></span>
        <span>Collected</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Active loans</span>
        <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($loans['active']) ?></div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Cleared loans</span>
        <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($loans['cleared']) ?></div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Overdue loans</span>
        <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($loans['overdue']) ?></div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Defaulted loans</span>
        <span class="metric-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($loans['defaulted']) ?></div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Loans due today</span>
        <span class="metric-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($loans['due_today']) ?></div>
      <div class="metric-meta">
        <span class="text-danger"><?= number_format($loans['overdue']) ?> overdue</span>
        <span>Check them out</span>
      </div>
    </article>
  </div>

</section>

<!-- ============ Charts / Team activity / Performance ============ -->
<section class="row g-3 mt-1">

  <div class="col-12 col-xl-8">
    <div class="panel">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
            <span>Principal issued</span>
          </h2>
          <p class="text-muted mb-0">Monthly report of issued principal amount.</p>
        </div>
        <a class="btn btn-light btn-sm" href="charts.php">Print all</a>
      </div>

      <div class="chart-bars" aria-label="Principal issued chart">
        <?= render_bars($principalSeries) ?>
      </div>
    </div>
  </div>

 <div class="col-12 col-xl-4">
  <div class="panel h-100 d-flex flex-column">

    <div class="panel-header">
      <div>
        <h2 class="h5 mb-1 section-title">
          <i class="bi bi-activity" aria-hidden="true"></i>
          <span>Team Activity</span>
        </h2>
        <p class="text-muted mb-0">Recent operational updates.</p>
      </div>
      <?php if (count($activity) > 5): ?>
        <span class="badge text-bg-light" id="activityCounter">Page 1 of 1</span>
      <?php endif; ?>
    </div>

    <?php if (empty($activity)): ?>
      <p class="text-muted small mb-0">No activity yet.</p>
    <?php else: ?>
      <div class="activity-list flex-grow-1" id="activityList">
        <?php foreach ($activity as $i => $a): ?>
          <div class="activity-item activity-page-item"
               data-index="<?= (int) $i ?>"
               <?= $i >= 5 ? 'style="display:none"' : '' ?>>
            <span class="activity-dot <?= htmlspecialchars($a['color'], ENT_QUOTES, 'UTF-8') ?>"></span>
            <div>
              <p class="mb-1 fw-semibold"><?= htmlspecialchars($a['title'], ENT_QUOTES, 'UTF-8') ?></p>
              <p class="text-muted small mb-0"><?= htmlspecialchars($a['note'], ENT_QUOTES, 'UTF-8') ?></p>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($activity) > 5): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
          <button class="btn btn-outline-secondary btn-sm" type="button" id="activityPrev" disabled>
            <i class="bi bi-chevron-left" aria-hidden="true"></i> Prev
          </button>
          <small class="text-muted" id="activityRange"></small>
          <button class="btn btn-outline-secondary btn-sm" type="button" id="activityNext">
            Next <i class="bi bi-chevron-right" aria-hidden="true"></i>
          </button>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</div>

  <div class="col-12 col-xl-8">
    <div class="panel">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-graph-up-arrow" aria-hidden="true"></i>
            <span>Interest earned</span>
          </h2>
          <p class="text-muted mb-0">Monthly report of total interest earned.</p>
        </div>
        <a class="btn btn-light btn-sm" href="charts.php">Print all</a>
      </div>

      <div class="chart-bars" aria-label="Interest earned chart">
        <?= render_bars($interestSeries) ?>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-4">
    <div class="panel h-100">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-pie-chart" aria-hidden="true"></i>
            <span>Performance</span>
          </h2>
          <p class="text-muted mb-0">Cleared vs defaulted loans.</p>
        </div>
      </div>

      <div class="donut-chart mx-auto">
        <span><?= number_format($collectedPct, 0) ?>%</span>
      </div>

      <div class="legend-list mt-2">
        <div>
          <span class="legend-dot bg-warning"></span>Defaulted Loans
          <strong><?= d_pct($defaultedPct, 0) ?></strong>
        </div>
        <div>
          <span class="legend-dot bg-success"></span>Cleared loans
          <strong><?= d_pct($collectedPct, 0) ?></strong>
        </div>
      </div>
    </div>
  </div>

</section>

</div><!-- /.dashboard-root -->

<script>
(() => {
  /* ================================================================
     Activity pagination (unchanged)
  ================================================================ */
  (() => {
    const list = document.getElementById('activityList');
    if (!list) return;

    const items   = list.querySelectorAll('.activity-page-item');
    const prevBtn = document.getElementById('activityPrev');
    const nextBtn = document.getElementById('activityNext');
    const counter = document.getElementById('activityCounter');
    const range   = document.getElementById('activityRange');

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

    prevBtn.addEventListener('click', () => { if (page > 1) { page--; render(); } });
    nextBtn.addEventListener('click', () => { if (page < TOTAL_PAGES) { page++; render(); } });

    render();
  })();

  /* ================================================================
     Toggle: hide / show card values
     Persisted in localStorage. Default: hidden.
  ================================================================ */
  (() => {
    const root = document.getElementById('dashboardRoot');
    const btn  = document.getElementById('toggleValuesBtn');
    if (!root || !btn) return;

    const STORAGE_KEY = 'jml_dashboard_values_hidden';
    const iconEl  = btn.querySelector('i');
    const labelEl = btn.querySelector('span');

    function apply(hidden) {
      root.classList.toggle('values-hidden', hidden);
      btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
      btn.setAttribute('title', hidden ? 'Show values' : 'Hide values');

      if (iconEl) {
        iconEl.classList.toggle('bi-eye',        hidden);
        iconEl.classList.toggle('bi-eye-slash', !hidden);
      }
      if (labelEl) {
        labelEl.textContent = hidden ? 'Show values' : 'Hide values';
      }
    }

    /* Default state: hidden. Override from localStorage if the user set a preference. */
    let hidden = true;
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      if (stored !== null) hidden = (stored === '1');
    } catch (e) { /* localStorage blocked — fall back to default */ }

    apply(hidden);

    btn.addEventListener('click', () => {
      hidden = !hidden;
      apply(hidden);
      try { localStorage.setItem(STORAGE_KEY, hidden ? '1' : '0'); } catch (e) {}
    });
  })();
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';