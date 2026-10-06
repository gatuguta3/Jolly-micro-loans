<?php
$pageTitle = 'Reports';
$activeNav = 'reports';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ================================================================
   DATE RANGE
   ?preset=this_month|last_month|this_quarter|this_year|custom
   ?from=YYYY-MM-DD&to=YYYY-MM-DD  (used when preset=custom)
================================================================ */
$preset = $_GET['preset'] ?? 'this_month';
$fromRaw = $_GET['from'] ?? '';
$toRaw   = $_GET['to']   ?? '';

$today = new DateTime('today');

switch ($preset) {
    case 'last_month':
        $from = (clone $today)->modify('first day of last month');
        $to   = (clone $today)->modify('last day of last month');
        $label = 'Last Month';
        break;
    case 'this_quarter':
        $q = (int) ceil((int) $today->format('n') / 3);
        $from = new DateTime($today->format('Y') . '-' . (($q - 1) * 3 + 1) . '-01');
        $to   = (clone $from)->modify('+3 months -1 day');
        $label = 'This Quarter';
        break;
    case 'this_year':
        $from = new DateTime($today->format('Y') . '-01-01');
        $to   = new DateTime($today->format('Y') . '-12-31');
        $label = 'This Year';
        break;
    case 'custom':
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromRaw) ? new DateTime($fromRaw) : (clone $today)->modify('first day of this month');
        $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toRaw)   ? new DateTime($toRaw)   : clone $today;
        if ($from > $to) { $tmp = $from; $from = $to; $to = $tmp; }
        $label = 'Custom Range';
        break;
    case 'this_month':
    default:
        $preset = 'this_month';
        $from = (clone $today)->modify('first day of this month');
        $to   = (clone $today)->modify('last day of this month');
        $label = 'This Month';
        break;
}
$fromStr = $from->format('Y-m-d');
$toStr   = $to->format('Y-m-d');

/* ================================================================
   1) PERIOD KPIs
================================================================ */
$period = [
    'loans_count'      => 0,
    'loans_principal'  => 0.0,
    'loans_interest'   => 0.0,
    'collected'        => 0.0,
    'payments_count'   => 0,
    'new_clients'      => 0,
];

/* Loans issued in period */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c,
            COALESCE(SUM(principal_amount), 0) AS p,
            COALESCE(SUM(interest_amount),  0) AS i
     FROM loans
     WHERE date_issued BETWEEN ? AND ?"
);
if ($stmt) {
    $stmt->bind_param("ss", $fromStr, $toStr);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $period['loans_count']     = (int)   $row['c'];
    $period['loans_principal'] = (float) $row['p'];
    $period['loans_interest']  = (float) $row['i'];
}

/* Payments received in period */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c, COALESCE(SUM(amount_paid), 0) AS total
     FROM payments
     WHERE payment_date BETWEEN ? AND ?"
);
if ($stmt) {
    $stmt->bind_param("ss", $fromStr, $toStr);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $period['payments_count'] = (int)   $row['c'];
    $period['collected']      = (float) $row['total'];
}

/* New clients joined in period */
$stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM clients WHERE join_date BETWEEN ? AND ?"
);
if ($stmt) {
    $stmt->bind_param("ss", $fromStr, $toStr);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $period['new_clients'] = (int) $row['c'];
}

/* ================================================================
   2) PORTFOLIO KPIs (current snapshot, no date filter)
================================================================ */
$portfolio = [
    'outstanding'    => 0.0,
    'at_risk'        => 0.0,
    'active_loans'   => 0,
    'overdue_loans'  => 0,
    'defaulted_loans'=> 0,
    'total_loans'    => 0,
    'total_principal'=> 0.0,
];

$res = $conn->query(
    "SELECT
        COUNT(*) AS total_loans,
        COALESCE(SUM(principal_amount), 0) AS total_principal,
        COALESCE(SUM(CASE WHEN status IN ('active','overdue','defaulted') THEN balance ELSE 0 END), 0) AS outstanding,
        COALESCE(SUM(CASE WHEN status IN ('overdue','defaulted')          THEN balance ELSE 0 END), 0) AS at_risk,
        SUM(CASE WHEN status = 'active'    THEN 1 ELSE 0 END) AS active_cnt,
        SUM(CASE WHEN status = 'overdue'   THEN 1 ELSE 0 END) AS overdue_cnt,
        SUM(CASE WHEN status = 'defaulted' THEN 1 ELSE 0 END) AS defaulted_cnt
     FROM loans"
);
if ($res && $row = $res->fetch_assoc()) {
    $portfolio['total_loans']     = (int)   $row['total_loans'];
    $portfolio['total_principal'] = (float) $row['total_principal'];
    $portfolio['outstanding']     = (float) $row['outstanding'];
    $portfolio['at_risk']         = (float) $row['at_risk'];
    $portfolio['active_loans']    = (int)   $row['active_cnt'];
    $portfolio['overdue_loans']   = (int)   $row['overdue_cnt'];
    $portfolio['defaulted_loans'] = (int)   $row['defaulted_cnt'];
    $res->free();
}

/* Collection rate = total paid / total due */
$totalPaidAll = 0.0;
$res = $conn->query("SELECT COALESCE(SUM(amount_paid), 0) AS t FROM payments");
if ($res && $row = $res->fetch_assoc()) $totalPaidAll = (float) $row['t'];
if ($res) $res->free();

$collectionRate = $portfolio['total_principal'] > 0
    ? ($totalPaidAll / ($portfolio['total_principal'] * 1.2)) * 100
    : 0;

/* ================================================================
   3) PORTFOLIO BY CATEGORY (period-scoped issuance)
================================================================ */
$byCategory = [];
$stmt = $conn->prepare(
    "SELECT
        l.loan_category,
        COUNT(*) AS loans_count,
        COALESCE(SUM(l.principal_amount), 0) AS principal,
        COALESCE(SUM(l.interest_amount),  0) AS interest,
        COALESCE(SUM(l.total_due),        0) AS total_due,
        COALESCE(SUM(l.amount_paid),      0) AS collected,
        COALESCE(SUM(CASE WHEN l.status IN ('active','overdue','defaulted') THEN l.balance ELSE 0 END), 0) AS outstanding
     FROM loans l
     WHERE l.date_issued BETWEEN ? AND ?
     GROUP BY l.loan_category
     ORDER BY l.loan_category ASC"
);
if ($stmt) {
    $stmt->bind_param("ss", $fromStr, $toStr);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $byCategory[$row['loan_category']] = $row;
    }
    $stmt->close();
}

/* ================================================================
   4) PORTFOLIO BY STATUS (current snapshot)
================================================================ */
$byStatus = [];
$res = $conn->query(
    "SELECT status,
            COUNT(*) AS loans_count,
            COALESCE(SUM(balance), 0) AS balance
     FROM loans
     GROUP BY status"
);
if ($res) {
    while ($row = $res->fetch_assoc()) $byStatus[$row['status']] = $row;
    $res->free();
}

/* ================================================================
   5) TOP OVERDUE LOANS (current)
================================================================ */
$overdueLoans = [];
$res = $conn->query(
    "SELECT
        l.id, l.loan_code, l.due_date, l.balance,
        l.loan_category,
        c.full_name AS client_name,
        c.client_code,
        DATEDIFF(CURRENT_DATE, l.due_date) AS days_overdue
     FROM loans l
     INNER JOIN clients c ON c.id = l.client_id
     WHERE l.status IN ('overdue','defaulted')
       AND l.balance > 0
     ORDER BY days_overdue DESC, l.balance DESC
     LIMIT 10"
);
if ($res) {
    while ($row = $res->fetch_assoc()) $overdueLoans[] = $row;
    $res->free();
}

/* ================================================================
   6) RECENT PAYMENTS IN PERIOD
================================================================ */
$recentPayments = [];
$stmt = $conn->prepare(
    "SELECT
        p.id, p.payment_code, p.amount_paid, p.payment_date,
        p.mode_of_payment,
        c.full_name AS client_name,
        l.loan_code
     FROM payments p
     INNER JOIN clients c ON c.id = p.client_id
     INNER JOIN loans   l ON l.id = p.loan_id
     WHERE p.payment_date BETWEEN ? AND ?
     ORDER BY p.payment_date DESC, p.id DESC
     LIMIT 15"
);
if ($stmt) {
    $stmt->bind_param("ss", $fromStr, $toStr);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $recentPayments[] = $row;
    $stmt->close();
}

/* ================================================================
   7) TOP CLIENTS BY OUTSTANDING EXPOSURE
================================================================ */
$topClients = [];
$res = $conn->query(
    "SELECT
        c.id, c.client_code, c.full_name,
        COUNT(l.id) AS active_loans,
        COALESCE(SUM(l.balance), 0) AS outstanding
     FROM clients c
     INNER JOIN loans l ON l.client_id = c.id
     WHERE l.status IN ('active','overdue','defaulted')
     GROUP BY c.id, c.client_code, c.full_name
     ORDER BY outstanding DESC
     LIMIT 10"
);
if ($res) {
    while ($row = $res->fetch_assoc()) $topClients[] = $row;
    $res->free();
}

/* ---------- HELPERS ---------- */
function r_ksh($n): string {
    return 'Ksh ' . number_format((float) $n, 2);
}
function r_ksh_short($n): string {
    $n = (float) $n;
    if ($n >= 1_000_000) return 'Ksh ' . number_format($n / 1_000_000, 2) . 'M';
    if ($n >= 1_000)     return 'Ksh ' . number_format($n / 1_000, 1) . 'K';
    return 'Ksh ' . number_format($n, 0);
}
function r_date(?string $d): string {
    return $d ? date('M j, Y', strtotime($d)) : '—';
}
function r_category_label(string $c): string {
    return [
        'monthly' => 'Monthly',
        'daily'   => 'Daily',
        'boost'   => 'Jolly Boost',
    ][$c] ?? ucfirst($c);
}



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


ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Reports</p>
      <h1 class="h3 mb-1">Business Reports</h1>
      <p class="text-muted mb-0">Portfolio health, collections, and risk — as of <?= htmlspecialchars($today->format('M j, Y')) ?>.</p>
    </div>
  </div>
  <div class="heading-actions no-print">
    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print();">
      <i class="bi bi-printer" aria-hidden="true"></i> Print
    </button>
  </div>
</div>

<!-- ============ Date range filter ============ -->
<section class="panel mt-3 no-print">
  <form class="row g-3 align-items-end" method="get" action="reports.php">

    <div class="col-md-3">
      <label class="form-label" for="preset">Period</label>
      <select class="form-select" id="preset" name="preset" onchange="document.getElementById('presetForm').submit();">
        <option value="this_month"   <?= $preset==='this_month'   ? 'selected':'' ?>>This Month</option>
        <option value="last_month"   <?= $preset==='last_month'   ? 'selected':'' ?>>Last Month</option>
        <option value="this_quarter" <?= $preset==='this_quarter' ? 'selected':'' ?>>This Quarter</option>
        <option value="this_year"    <?= $preset==='this_year'    ? 'selected':'' ?>>This Year</option>
        <option value="custom"       <?= $preset==='custom'       ? 'selected':'' ?>>Custom Range</option>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label" for="from">From</label>
      <input class="form-control" id="from" name="from" type="date"
             value="<?= htmlspecialchars($fromStr) ?>">
    </div>

    <div class="col-md-3">
      <label class="form-label" for="to">To</label>
      <input class="form-control" id="to" name="to" type="date"
             value="<?= htmlspecialchars($toStr) ?>">
    </div>

    <div class="col-md-3 d-flex gap-2">
      <input type="hidden" name="preset" value="custom" id="presetForm">
      <button class="btn btn-primary flex-fill" type="submit"
              onclick="document.getElementById('preset').value='custom';">
        <i class="bi bi-funnel" aria-hidden="true"></i> Apply
      </button>
    </div>

  </form>
</section>

<!-- ============ Period KPIs ============ -->
<div class="mt-3">
  <h2 class="h6 mb-2 text-muted text-uppercase" style="letter-spacing:.06em; font-size:11px;">
    Period Activity — <?= htmlspecialchars($label) ?>
    <span class="fw-normal">(<?= $from->format('M j') ?> – <?= $to->format('M j, Y') ?>)</span>
  </h2>
  <section class="row g-3">

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="metric-card metric-primary">
        <div class="metric-top">
          <span class="metric-label">Loans Issued</span>
          <span class="metric-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
        </div>
        <div class="metric-value"><?= number_format($period['loans_count']) ?></div>
        <div class="metric-meta">
          <span class="text-success"><?= r_ksh_short($period['loans_principal']) ?></span>
          <span>disbursed</span>
        </div>
      </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="metric-card metric-success">
        <div class="metric-top">
          <span class="metric-label">Collected</span>
          <span class="metric-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
        </div>
        <div class="metric-value"><?= r_ksh_short($period['collected']) ?></div>
        <div class="metric-meta">
          <span class="text-muted"><?= number_format($period['payments_count']) ?></span>
          <span>payments</span>
        </div>
      </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="metric-card metric-warning">
        <div class="metric-top">
          <span class="metric-label">Interest Booked</span>
          <span class="metric-icon"><i class="bi bi-percent" aria-hidden="true"></i></span>
        </div>
        <div class="metric-value"><?= r_ksh_short($period['loans_interest']) ?></div>
        <div class="metric-meta">
          <span class="text-muted">expected on period loans</span>
        </div>
      </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="metric-card metric-danger">
        <div class="metric-top">
          <span class="metric-label">New Clients</span>
          <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
        </div>
        <div class="metric-value"><?= number_format($period['new_clients']) ?></div>
        <div class="metric-meta">
          <span class="text-muted">joined in period</span>
        </div>
      </article>
    </div>

  </section>
</div>

<!-- ============ Portfolio snapshot ============ -->
<div class="mt-4">
  <h2 class="h6 mb-2 text-muted text-uppercase" style="letter-spacing:.06em; font-size:11px;">
    Portfolio Snapshot — Current
  </h2>
  <section class="row g-3">

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="metric-card metric-primary">
        <div class="metric-top">
          <span class="metric-label">Outstanding Portfolio</span>
          <span class="metric-icon"><i class="bi bi-bank" aria-hidden="true"></i></span>
        </div>
        <div class="metric-value"><?= r_ksh_short($portfolio['outstanding']) ?></div>
        <div class="metric-meta">
          <span class="text-muted"><?= number_format($portfolio['active_loans']) ?></span>
          <span>active loans</span>
        </div>
      </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="metric-card metric-danger">
        <div class="metric-top">
          <span class="metric-label">Portfolio at Risk</span>
          <span class="metric-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
        </div>
        <div class="metric-value"><?= r_ksh_short($portfolio['at_risk']) ?></div>
        <div class="metric-meta">
          <span class="text-danger"><?= number_format($portfolio['overdue_loans'] + $portfolio['defaulted_loans']) ?></span>
          <span>loans late</span>
        </div>
      </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="metric-card metric-success">
        <div class="metric-top">
          <span class="metric-label">Collection Rate</span>
          <span class="metric-icon"><i class="bi bi-graph-up-arrow" aria-hidden="true"></i></span>
        </div>
        <div class="metric-value"><?= number_format($collectionRate, 1) ?>%</div>
        <div class="metric-meta">
          <span class="text-muted"><?= r_ksh_short($totalPaidAll) ?></span>
          <span>collected lifetime</span>
        </div>
      </article>
    </div>

    <div class="col-12 col-sm-6 col-xl-3">
      <article class="metric-card metric-warning">
        <div class="metric-top">
          <span class="metric-label">Principal Disbursed</span>
          <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
        </div>
        <div class="metric-value"><?= r_ksh_short($portfolio['total_principal']) ?></div>
        <div class="metric-meta">
          <span class="text-muted"><?= number_format($portfolio['total_loans']) ?></span>
          <span>loans ever</span>
        </div>
      </article>
    </div>

  </section>
</div>

<!-- ============ Portfolio by Category ============ -->
<section class="panel mt-4">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-pie-chart" aria-hidden="true"></i>
        <span>Portfolio by Category</span>
      </h2>
      <p class="text-muted mb-0">Loans issued between <?= $from->format('M j') ?> and <?= $to->format('M j, Y') ?>.</p>
    </div>
    <button class="btn btn-outline-secondary btn-sm no-print" type="button"
            onclick="exportTable('categoryTable', 'portfolio_by_category');">
      <i class="bi bi-download" aria-hidden="true"></i> CSV
    </button>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0" id="categoryTable">
      <thead>
        <tr>
          <th>Category</th>
          <th class="text-end">Loans</th>
          <th class="text-end">Principal</th>
          <th class="text-end">Interest</th>
          <th class="text-end">Total Due</th>
          <th class="text-end">Collected</th>
          <th class="text-end">Outstanding</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($byCategory)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No loans issued in this period.</td></tr>
        <?php else: foreach ($byCategory as $cat => $row): ?>
          <tr>
            <td><strong><?= htmlspecialchars(r_category_label($cat)) ?></strong></td>
            <td class="text-end"><?= number_format((int) $row['loans_count']) ?></td>
            <td class="text-end"><?= r_ksh($row['principal']) ?></td>
            <td class="text-end"><?= r_ksh($row['interest']) ?></td>
            <td class="text-end"><?= r_ksh($row['total_due']) ?></td>
            <td class="text-end"><?= r_ksh($row['collected']) ?></td>
            <td class="text-end"><strong><?= r_ksh($row['outstanding']) ?></strong></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ============ Portfolio by Status ============ -->
<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-clipboard-data" aria-hidden="true"></i>
        <span>Portfolio by Status</span>
      </h2>
      <p class="text-muted mb-0">Current loan book, across all periods.</p>
    </div>
    <button class="btn btn-outline-secondary btn-sm no-print" type="button"
            onclick="exportTable('statusTable', 'portfolio_by_status');">
      <i class="bi bi-download" aria-hidden="true"></i> CSV
    </button>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0" id="statusTable">
      <thead>
        <tr>
          <th>Status</th>
          <th class="text-end">Loans</th>
          <th class="text-end">Balance</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $statuses = ['active','overdue','defaulted','cleared','written_off'];
        $statusBadge = [
            'active'      => 'text-bg-success',
            'overdue'     => 'text-bg-warning',
            'defaulted'   => 'text-bg-danger',
            'cleared'     => 'text-bg-primary',
            'written_off' => 'text-bg-secondary',
        ];
        foreach ($statuses as $s):
            $row = $byStatus[$s] ?? ['loans_count' => 0, 'balance' => 0];
        ?>
          <tr>
            <td><span class="badge <?= $statusBadge[$s] ?>"><?= ucfirst(str_replace('_', ' ', $s)) ?></span></td>
            <td class="text-end"><?= number_format((int) $row['loans_count']) ?></td>
            <td class="text-end"><?= r_ksh($row['balance']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ============ Two-column: Overdue + Top Clients ============ -->
<section class="row g-3 mt-3">

  <!-- Overdue loans -->
  <div class="col-12 col-xl-7">
    <div class="panel h-100">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <span>Top Overdue Loans</span>
          </h2>
          <p class="text-muted mb-0">Highest days overdue, top 10.</p>
        </div>
        <button class="btn btn-outline-secondary btn-sm no-print" type="button"
                onclick="exportTable('overdueTable', 'top_overdue');">
          <i class="bi bi-download" aria-hidden="true"></i> CSV
        </button>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0" id="overdueTable">
          <thead>
            <tr>
              <th>Loan</th>
              <th>Client</th>
              <th>Due Date</th>
              <th class="text-end">Days Late</th>
              <th class="text-end">Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($overdueLoans)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No overdue loans — book is clean. ✅</td></tr>
            <?php else: foreach ($overdueLoans as $l): ?>
              <tr>
                <td>
                  <a href="loan-view.php?id=<?= (int) $l['id'] ?>" class="fw-semibold text-decoration-none">
                    <?= htmlspecialchars($l['loan_code']) ?>
                  </a>
                  <p class="text-muted small mb-0"><?= htmlspecialchars(r_category_label($l['loan_category'] ?? 'monthly')) ?></p>
                </td>
                <td><?= htmlspecialchars($l['client_name']) ?></td>
                <td><?= r_date($l['due_date']) ?></td>
                <td class="text-end">
                  <span class="badge <?= (int)$l['days_overdue'] > 60 ? 'text-bg-danger' : 'text-bg-warning' ?>">
                    <?= (int) $l['days_overdue'] ?> days
                  </span>
                </td>
                <td class="text-end"><?= r_ksh($l['balance']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Top clients by exposure -->
  <div class="col-12 col-xl-5">
    <div class="panel h-100">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-person-lines-fill" aria-hidden="true"></i>
            <span>Top Clients by Exposure</span>
          </h2>
          <p class="text-muted mb-0">Highest outstanding balance.</p>
        </div>
        <button class="btn btn-outline-secondary btn-sm no-print" type="button"
                onclick="exportTable('topClientsTable', 'top_clients');">
          <i class="bi bi-download" aria-hidden="true"></i> CSV
        </button>
      </div>
      <div class="table-responsive">
        <table class="table align-middle mb-0" id="topClientsTable">
          <thead>
            <tr>
              <th>Client</th>
              <th class="text-end">Loans</th>
              <th class="text-end">Outstanding</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($topClients)): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">No active clients.</td></tr>
            <?php else: foreach ($topClients as $c): ?>
              <tr>
                <td>
                  <a href="client-view.php?id=<?= (int) $c['id'] ?>" class="fw-semibold text-decoration-none">
                    <?= htmlspecialchars($c['full_name']) ?>
                  </a>
                  <p class="text-muted small mb-0"><?= htmlspecialchars($c['client_code']) ?></p>
                </td>
                <td class="text-end"><?= number_format((int) $c['active_loans']) ?></td>
                <td class="text-end"><strong><?= r_ksh($c['outstanding']) ?></strong></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</section>

<!-- ============ Recent Payments ============ -->
<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-cash-stack" aria-hidden="true"></i>
        <span>Recent Payments — <?= htmlspecialchars($label) ?></span>
      </h2>
      <p class="text-muted mb-0">Latest <?= count($recentPayments) ?> payments received in the period.</p>
    </div>
    <button class="btn btn-outline-secondary btn-sm no-print" type="button"
            onclick="exportTable('recentPaymentsTable', 'recent_payments');">
      <i class="bi bi-download" aria-hidden="true"></i> CSV
    </button>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0" id="recentPaymentsTable">
      <thead>
        <tr>
          <th>Payment</th>
          <th>Client</th>
          <th>Loan</th>
          <th>Date</th>
          <th>Mode</th>
          <th class="text-end">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recentPayments)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No payments received in this period.</td></tr>
        <?php else: foreach ($recentPayments as $p): ?>
          <tr>
            <td><a href="payment-view.php?id=<?= (int) $p['id'] ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars($p['payment_code']) ?></a></td>
            <td><?= htmlspecialchars($p['client_name']) ?></td>
            <td><?= htmlspecialchars($p['loan_code']) ?></td>
            <td><?= r_date($p['payment_date']) ?></td>
            <td><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $p['mode_of_payment']))) ?></td>
            <td class="text-end"><?= r_ksh($p['amount_paid']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
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

 
</section>

<script>
  /* Generic CSV export for any <table id="..."> */
  function exportTable(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const rows = [];
    table.querySelectorAll('tr').forEach(tr => {
      const cells = [];
      tr.querySelectorAll('th, td').forEach(td => {
        // Strip out images / badges, keep text
        let txt = td.textContent.replace(/\s+/g, ' ').trim();
        // Escape quotes
        txt = '"' + txt.replace(/"/g, '""') + '"';
        cells.push(txt);
      });
      rows.push(cells.join(','));
    });

    const csv = rows.join('\r\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename + '_' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }
</script>

<style>
  @media print {
    .no-print, .admin-sidebar, .admin-navbar, .admin-footer, .sidebar-backdrop,
    .page-heading .heading-actions { display: none !important; }
    .admin-main { margin: 0 !important; }
    body { background: #fff !important; }
    .panel { box-shadow: none !important; border: 1px solid #e5e7eb !important; }
    .metric-card { box-shadow: none !important; }
  }
</style>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';