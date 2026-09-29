<?php
$pageTitle = 'Loans';
$activeNav = 'loans';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   AGGREGATE STATS FOR CARDS
---------------------------------------------------------------- */
$stats = [
    'total_principal' => 0.0,
    'total_interest'  => 0.0,
    'total_due'       => 0.0,
    'total_loans'     => 0,
    'urgent'          => 0,   // due within 3 days and not cleared
];

$res = $conn->query(
    "SELECT
        COALESCE(SUM(principal_amount), 0) AS total_principal,
        COALESCE(SUM(interest_amount), 0)  AS total_interest,
        COALESCE(SUM(total_due), 0)        AS total_due,
        COUNT(*)                           AS total_loans,
        SUM(CASE WHEN status = 'active'
                  AND due_date <= DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY)
                 THEN 1 ELSE 0 END)        AS urgent
     FROM loans"
);
if ($res && $row = $res->fetch_assoc()) {
    $stats['total_principal'] = (float) $row['total_principal'];
    $stats['total_interest']  = (float) $row['total_interest'];
    $stats['total_due']       = (float) $row['total_due'];
    $stats['total_loans']     = (int)   $row['total_loans'];
    $stats['urgent']          = (int)   $row['urgent'];
    $res->free();
}

/* ----------------------------------------------------------------
   LOANS LIST (recent first)
---------------------------------------------------------------- */
$loans = [];
$res = $conn->query(
    "SELECT
        l.id,
        l.loan_code,
        l.date_issued,
        l.principal_amount,
        l.interest_rate,
        l.total_due,
        l.balance,
        l.due_date,
        l.status,
        c.id         AS client_id,
        c.full_name  AS client_name,
        c.email      AS client_email,
        c.client_code,
        c.photo_url  AS client_photo
     FROM loans l
     INNER JOIN clients c ON c.id = l.client_id
     ORDER BY l.id DESC"
);
if ($res) {
    while ($r = $res->fetch_assoc()) $loans[] = $r;
    $res->free();
}

/* ---------- HELPERS ---------- */
if (!function_exists('loan_avatar_svg')) {
    function loan_avatar_svg($name) {
        $initial = strtoupper(substr(trim($name), 0, 1)) ?: '?';
        return 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48">'
          . '<rect width="100%" height="100%" fill="#e9ecef"/>'
          . '<text x="50%" y="55%" font-size="20" font-family="Arial" '
          . 'fill="#6c757d" text-anchor="middle" dominant-baseline="middle">'
          . $initial . '</text></svg>'
        );
    }
}

function loan_status_badge(string $status): array {
    // returns [css-class, label]
    return match ($status) {
        'active'      => ['text-bg-success', 'Active'],
        'defaulted'   => ['text-bg-danger',  'Defaulted'],
        'cleared'     => ['text-bg-primary', 'Cleared'],
        'written_off' => ['text-bg-secondary', 'Written Off'],
        default       => ['text-bg-secondary', ucfirst($status)],
    };
}

function ksh(float $n): string {
    return 'Ksh ' . number_format($n, 2);
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Overview</p>
      <h1 class="h3 mb-1">Loans</h1>
      <p class="text-muted mb-0">Monitor loan performance, balances, and upcoming due dates.</p>
    </div>
  </div>
  <div class="heading-actions">
    <button class="btn btn-outline-secondary btn-sm" type="button">
      <i class="bi bi-download" aria-hidden="true"></i> Export
    </button>
    <button class="btn btn-primary btn-sm" type="button">
      <i class="bi bi-file-earmark-plus" aria-hidden="true"></i> Create Report
    </button>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- ============ Metric cards ============ -->
<section class="row g-3 mt-1" aria-label="Loan metrics">

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Total Principal</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= ksh($stats['total_principal']) ?></div>
      <div class="metric-meta">
        <span class="text-success">+12.5%</span>
        <span>from last month</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-success">
      <div class="metric-top">
        <span class="metric-label">Interest</span>
        <span class="metric-icon"><i class="bi bi-bag-check" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= ksh($stats['total_interest']) ?></div>
      <div class="metric-meta">
        <span class="text-success">+8.2%</span>
        <span>new interest</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Total Due Amount</span>
        <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= ksh($stats['total_due']) ?></div>
      <div class="metric-meta">
        <span class="text-success">+5.1%</span>
        <span>amount increase</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Total Loans</span>
        <span class="metric-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($stats['total_loans']) ?></div>
      <div class="metric-meta">
        <span class="text-danger"><?= (int) $stats['urgent'] ?> urgent</span>
        <span>need review</span>
      </div>
    </article>
  </div>

</section>

<!-- ============ Loans table ============ -->
<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-cash-coin" aria-hidden="true"></i>
        <span>Recent Loans</span>
      </h2>
      <p class="text-muted mb-0">Latest loan activity across the workspace.</p>
    </div>
    <div class="heading-actions">
      <button class="btn btn-outline-secondary btn-sm" type="button">
        <i class="bi bi-download" aria-hidden="true"></i> Export
      </button>
      <a class="btn btn-primary btn-sm" href="loan-registration.php">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Add Loan
      </a>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th scope="col">Loan Id</th>
          <th scope="col">Client</th>
          <th scope="col">Date Issued</th>
          <th scope="col">Principal Amount</th>
          <th scope="col">Due date</th>
          <th scope="col">Status</th>
          <th scope="col" class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>

        <?php if (empty($loans)): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-4">
              No loans yet. Click <strong>Add Loan</strong> to create the first one.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($loans as $l): ?>
            <?php
              [$badgeClass, $badgeLabel] = loan_status_badge($l['status']);

              $clientPhoto = !empty($l['client_photo'])
                  ? '../assets/client-attachments/' . rawurlencode($l['client_photo'])
                  : loan_avatar_svg($l['client_name']);

              $issuedFmt = $l['date_issued'] ? date('M j, Y', strtotime($l['date_issued'])) : '—';
              $dueFmt    = $l['due_date']    ? date('M j, Y', strtotime($l['due_date']))    : '—';
            ?>
            <tr>
              <td><?= htmlspecialchars($l['loan_code'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img class="avatar-img avatar-sm"
                       src="<?= htmlspecialchars($clientPhoto, ENT_QUOTES, 'UTF-8') ?>"
                       alt="<?= htmlspecialchars($l['client_name'], ENT_QUOTES, 'UTF-8') ?>">
                  <div>
                    <p class="fw-semibold mb-0"><?= htmlspecialchars($l['client_name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-muted small mb-0"><?= htmlspecialchars($l['client_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($issuedFmt) ?></td>
              <td><?= ksh((float) $l['principal_amount']) ?></td>
              <td><?= htmlspecialchars($dueFmt) ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span></td>
              <td class="text-end">
                <a class="btn btn-light btn-sm" href="loan-view.php?id=<?= (int) $l['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>

      </tbody>
    </table>
  </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';