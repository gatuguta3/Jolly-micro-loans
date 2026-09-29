<?php
$pageTitle = 'Payments';
$activeNav = 'payments';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   AGGREGATE STATS FOR CARDS
---------------------------------------------------------------- */
$stats = [
    'total_payments'      => 0.0,   // SUM of all payments
    'principal_paid'      => 0.0,   // sum applied to principal (approximation)
    'interest_collected'  => 0.0,   // from paid interest on loans
    'pending_payments'    => 0,     // placeholder: active loans with balance > 0
];

/* Total payments + count */
$res = $conn->query("SELECT COALESCE(SUM(amount_paid),0) AS total FROM payments");
if ($res && $row = $res->fetch_assoc()) $stats['total_payments'] = (float) $row['total'];
if ($res) $res->free();

/* Principal paid vs interest collected
   Approximation: for each loan, payments apply to interest first, then principal.
   We compute how much of the total paid has covered interest so far. */
$res = $conn->query(
    "SELECT
        COALESCE(SUM(p.amount_paid), 0) AS paid_total,
        COALESCE(SUM(
            LEAST(l.interest_amount,
                  (SELECT COALESCE(SUM(p2.amount_paid),0)
                   FROM payments p2 WHERE p2.loan_id = l.id))
        ), 0) AS interest_covered
     FROM loans l
     LEFT JOIN payments p ON p.loan_id = l.id"
);
if ($res && $row = $res->fetch_assoc()) {
    $paidTotal       = (float) $row['paid_total'];
    $interestCovered = (float) $row['interest_covered'];
    $stats['interest_collected'] = $interestCovered;
    $stats['principal_paid']     = max(0, $paidTotal - $interestCovered);
}
if ($res) $res->free();

/* Pending: active loans with a positive balance */
$res = $conn->query(
    "SELECT COUNT(*) AS c FROM loans
     WHERE status = 'active' AND balance > 0"
);
if ($res && $row = $res->fetch_assoc()) $stats['pending_payments'] = (int) $row['c'];
if ($res) $res->free();

/* ----------------------------------------------------------------
   RECENT PAYMENTS
---------------------------------------------------------------- */
$payments = [];
$res = $conn->query(
    "SELECT
        p.id, p.payment_code, p.amount_paid, p.payment_date,
        p.mode_of_payment, p.reference_number, p.remarks,
        c.id         AS client_id,
        c.full_name  AS client_name,
        c.email      AS client_email,
        c.client_code,
        c.photo_url  AS client_photo,
        l.id         AS loan_id,
        l.loan_code
     FROM payments p
     INNER JOIN clients c ON c.id = p.client_id
     INNER JOIN loans   l ON l.id = p.loan_id
     ORDER BY p.id DESC"
);
if ($res) {
    while ($r = $res->fetch_assoc()) $payments[] = $r;
    $res->free();
}

/* ----------------------------------------------------------------
   HELPERS
---------------------------------------------------------------- */
if (!function_exists('pay_avatar_svg')) {
    function pay_avatar_svg($name) {
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

function pay_mode_badge(string $mode): array {
    // returns [css class, label]
    return match ($mode) {
        'mpesa'          => ['text-bg-danger',   'Mpesa'],
        'cash'           => ['text-bg-success',  'Cash'],
        'bank_transfer'  => ['text-bg-primary',  'Bank Transfer'],
        'cheque'         => ['text-bg-warning',  'Cheque'],
        'card'           => ['text-bg-info',     'Card'],
        'other'          => ['text-bg-secondary','Other'],
        default          => ['text-bg-secondary', ucfirst($mode)],
    };
}

function pay_ksh($n): string {
    return 'Ksh ' . number_format((float) $n, 2);
}

function pay_fmt_date(?string $d): string {
    return $d ? date('M j, Y', strtotime($d)) : '—';
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Overview</p>
      <h1 class="h3 mb-1">Payments</h1>
      <p class="text-muted mb-0">Monitor payments, principal collected, interest, and outstanding balances.</p>
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
<section class="row g-3 mt-1" aria-label="Payment metrics">

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Total Payments</span>
        <span class="metric-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= pay_ksh($stats['total_payments']) ?></div>
      <div class="metric-meta">
        <span class="text-success">+12.5%</span>
        <span>from last month</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-success">
      <div class="metric-top">
        <span class="metric-label">Principal Paid</span>
        <span class="metric-icon"><i class="bi bi-bank" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= pay_ksh($stats['principal_paid']) ?></div>
      <div class="metric-meta">
        <span class="text-success">+8.2%</span>
        <span>principal collected</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Interest Collected</span>
        <span class="metric-icon"><i class="bi bi-percent" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= pay_ksh($stats['interest_collected']) ?></div>
      <div class="metric-meta">
        <span class="text-success">+5.1%</span>
        <span>interest earned</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Pending Payments</span>
        <span class="metric-icon"><i class="bi bi-exclamation-circle" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($stats['pending_payments']) ?></div>
      <div class="metric-meta">
        <span class="text-danger"><?= (int) $stats['pending_payments'] ?> awaiting</span>
        <span>need follow-up</span>
      </div>
    </article>
  </div>

</section>

<!-- ============ Payments table ============ -->
<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-cash-stack" aria-hidden="true"></i>
        <span>Recent Payments</span>
      </h2>
      <p class="text-muted mb-0">Latest payment activity across the workspace.</p>
    </div>
    <div class="heading-actions">
      <button class="btn btn-outline-secondary btn-sm" type="button">
        <i class="bi bi-download" aria-hidden="true"></i> Export
      </button>
      <a class="btn btn-primary btn-sm" href="payment-registration.php">
        <i class="bi bi-file-earmark-plus" aria-hidden="true"></i> New Payment
      </a>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th scope="col">Payment Id</th>
          <th scope="col">Client</th>
          <th scope="col">Loan Id</th>
          <th scope="col">Amount Paid</th>
          <th scope="col">Date Paid</th>
          <th scope="col">Mode of payment</th>
          <th scope="col" class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>

        <?php if (empty($payments)): ?>
          <tr>
            <td colspan="7" class="text-center text-muted py-4">
              No payments recorded yet. Click <strong>New Payment</strong> to add one.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($payments as $p): ?>
            <?php
              [$modeClass, $modeLabel] = pay_mode_badge($p['mode_of_payment']);

              $clientPhoto = !empty($p['client_photo'])
                  ? '../assets/client-attachments/' . rawurlencode($p['client_photo'])
                  : pay_avatar_svg($p['client_name']);
            ?>
            <tr>
              <td><?= htmlspecialchars($p['payment_code'], ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img class="avatar-img avatar-sm"
                       src="<?= htmlspecialchars($clientPhoto, ENT_QUOTES, 'UTF-8') ?>"
                       alt="<?= htmlspecialchars($p['client_name'], ENT_QUOTES, 'UTF-8') ?>">
                  <div>
                    <p class="fw-semibold mb-0"><?= htmlspecialchars($p['client_name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-muted small mb-0"><?= htmlspecialchars($p['client_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($p['loan_code'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= pay_ksh($p['amount_paid']) ?></td>
              <td><?= htmlspecialchars(pay_fmt_date($p['payment_date'])) ?></td>
              <td><span class="badge <?= $modeClass ?>"><?= htmlspecialchars($modeLabel) ?></span></td>
              <td class="text-end">
                <a class="btn btn-light btn-sm" href="payment-view.php?id=<?= (int) $p['id'] ?>">View</a>
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