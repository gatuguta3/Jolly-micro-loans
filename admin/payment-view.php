<?php
$pageTitle = 'Payment Details';
$activeNav = 'payments';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- VALIDATE ?id ---------- */
$paymentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($paymentId <= 0) {
    $_SESSION['flash'] = 'Invalid payment ID.';
    header('Location: payments.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH PAYMENT + CLIENT + LOAN
---------------------------------------------------------------- */
$stmt = $conn->prepare(
    "SELECT
        p.id, p.payment_code, p.amount_paid, p.payment_date,
        p.mode_of_payment, p.reference_number, p.remarks,
        p.upload, p.recorded_at, p.updated_at,
        c.id           AS client_id,
        c.client_code,
        c.full_name    AS client_name,
        c.email        AS client_email,
        c.phone        AS client_phone,
        c.id_number    AS client_id_number,
        c.photo_url    AS client_photo,
        c.location     AS client_location,
        l.id           AS loan_id,
        l.loan_code,
        l.purpose      AS loan_purpose,
        l.principal_amount,
        l.total_due,
        l.amount_paid  AS loan_amount_paid,
        l.balance      AS loan_balance,
        l.status       AS loan_status,
        l.due_date     AS loan_due_date
     FROM payments p
     INNER JOIN clients c ON c.id = p.client_id
     INNER JOIN loans   l ON l.id = p.loan_id
     WHERE p.id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $paymentId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    $_SESSION['flash'] = 'Payment not found.';
    header('Location: payments.php');
    exit;
}

/* ----------------------------------------------------------------
   COMPUTE BALANCE BEFORE / AFTER THIS PAYMENT
   (ordered by payment id within the same loan)
---------------------------------------------------------------- */
$balBefore = (float) $payment['total_due'];
$balAfter  = (float) $payment['total_due'];

$stmt = $conn->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN id <  ? THEN amount_paid ELSE 0 END), 0) AS paid_before,
        COALESCE(SUM(CASE WHEN id <= ? THEN amount_paid ELSE 0 END), 0) AS paid_upto
     FROM payments
     WHERE loan_id = ?"
);
$stmt->bind_param("iii", $paymentId, $paymentId, $payment['loan_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$balBefore = max(0, (float) $payment['total_due'] - (float) $row['paid_before']);
$balAfter  = max(0, (float) $payment['total_due'] - (float) $row['paid_upto']);

/* ----------------------------------------------------------------
   HELPERS
---------------------------------------------------------------- */
if (!function_exists('pv_avatar_svg')) {
    function pv_avatar_svg($name) {
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

function pv_mode_badge(string $mode): array {
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

function pv_loan_badge(string $status): array {
    return match ($status) {
        'active'      => ['text-bg-success',   'Active'],
        'defaulted'   => ['text-bg-danger',    'Defaulted'],
        'cleared'     => ['text-bg-primary',   'Cleared'],
        'written_off' => ['text-bg-secondary', 'Written Off'],
        default       => ['text-bg-secondary', ucfirst($status)],
    };
}

function pv_ksh($n): string {
    return 'Ksh ' . number_format((float) $n, 2);
}

function pv_fmt_date(?string $d): string {
    return $d ? date('M j, Y', strtotime($d)) : '—';
}

function pv_fmt_datetime(?string $d): string {
    return $d ? date('M j, Y · H:i', strtotime($d)) : '—';
}

/* ---------- Prepare display values ---------- */
[$modeClass, $modeLabel]   = pv_mode_badge($payment['mode_of_payment']);
[$loanClass, $loanLabel]   = pv_loan_badge($payment['loan_status']);

$clientPhoto = !empty($payment['client_photo'])
    ? '../assets/client-attachments/' . rawurlencode($payment['client_photo'])
    : pv_avatar_svg($payment['client_name']);

$proofUrl = !empty($payment['upload'])
    ? '../assets/images/payment-uploads/' . rawurlencode($payment['upload'])
    : null;
$proofExt = $proofUrl ? strtolower(pathinfo($payment['upload'], PATHINFO_EXTENSION)) : null;
$proofIsImage = $proofExt && in_array($proofExt, ['jpg','jpeg','png','webp','gif'], true);
$proofIsPdf   = $proofExt === 'pdf';

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Payments</p>
      <h1 class="h3 mb-1">Payment Details</h1>
      <p class="text-muted mb-0">View payment details, linked loan, and proof of payment.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="payments.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Payments
    </a>
     <a class="btn btn-outline-primary btn-sm"
     href="payment-edit.php?id=<?= (int) $p['id'] ?>"
     title="Edit payment">
    <i class="bi bi-pencil-square" aria-hidden="true"></i>
  </a>

  <form method="post" action="payment-delete.php" class="d-inline"
        onsubmit="return confirm('Delete this payment permanently? The loan balances will be recalculated.');">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete payment">
      <i class="bi bi-trash" aria-hidden="true"></i>
    </button>
  </form>
    <a class="btn btn-outline-secondary btn-sm "
         href="loan-view.php?id=<?= (int) $payment['loan_id'] ?>">
        <i class="bi bi-cash-coin" aria-hidden="true"></i> Back to Loan
      </a>
    <a class="btn btn-primary btn-sm" href="payment-registration.php">
      <i class="bi bi-plus-circle" aria-hidden="true"></i> New Payment
    </a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<section class="row g-3">
  <!-- Left column: Client + Loan summary -->
  <div class="col-12 col-xl-4">
    <div class="panel h-100 text-center profile-card">
      <div class="profile-cover">
        <img src="../assets/images/png/dasher-ui-bootstrap-5.jpg" alt="Client cover">
      </div>
      <div class="profile-hero">
        <img class="avatar-img avatar-xl profile-photo"
             src="<?= htmlspecialchars($clientPhoto, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($payment['client_name'], ENT_QUOTES, 'UTF-8') ?>">
        <h2 class="h5 mb-1"><?= htmlspecialchars($payment['client_name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="text-muted mb-3">Loan Client</p>
        <span class="badge text-bg-success">Payment Confirmed</span>
      </div>

      <div class="info-list mt-4 text-start">
        <div><span>Client Code</span><strong><?= htmlspecialchars($payment['client_code'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Email</span><strong><?= htmlspecialchars($payment['client_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Phone</span><strong><?= htmlspecialchars($payment['client_phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>ID No</span><strong><?= htmlspecialchars($payment['client_id_number'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Loan ID</span><strong><?= htmlspecialchars($payment['loan_code'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Loan Type</span><strong><?= htmlspecialchars($payment['loan_purpose'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Loan Status</span><strong><span class="badge <?= $loanClass ?>"><?= htmlspecialchars($loanLabel) ?></span></strong></div>
        <div><span>Loan Balance</span><strong><?= pv_ksh($payment['loan_balance']) ?></strong></div>
      </div>

      <a class="btn btn-outline-secondary btn-sm w-100 mt-3"
         href="loan-view.php?id=<?= (int) $payment['loan_id'] ?>">
        <i class="bi bi-cash-coin" aria-hidden="true"></i> View Loan
      </a>
    </div>
  </div>

  <!-- Right column: Payment details + proof -->
  <div class="col-12 col-xl-8">
    <div class="panel mb-3">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            <span>Payment Information</span>
          </h2>
          <p class="text-muted mb-0">Payment ID: <?= htmlspecialchars($payment['payment_code'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a class="btn btn-primary btn-sm" href="payment-edit.php?id=<?= (int) $payment['id'] ?>">Edit Payment</a>
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <div class="mini-card">
            <span>Payment ID</span>
            <strong><?= htmlspecialchars($payment['payment_code'], ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Amount Paid</span>
            <strong><?= pv_ksh($payment['amount_paid']) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Date Paid</span>
            <strong><?= htmlspecialchars(pv_fmt_date($payment['payment_date'])) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Mode of Payment</span>
            <strong><span class="badge <?= $modeClass ?>"><?= htmlspecialchars($modeLabel) ?></span></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Reference No</span>
            <strong><?= htmlspecialchars($payment['reference_number'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Recorded At</span>
            <strong><?= htmlspecialchars(pv_fmt_datetime($payment['recorded_at'])) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Loan Balance Before</span>
            <strong><?= pv_ksh($balBefore) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Loan Balance After</span>
            <strong><?= pv_ksh($balAfter) ?></strong>
          </div>
        </div>
        <div class="col-12">
          <div class="mini-card">
            <span>Notes</span>
            <p class="mb-0 mt-1">
              <?= !empty($payment['remarks'])
                  ? nl2br(htmlspecialchars($payment['remarks'], ENT_QUOTES, 'UTF-8'))
                  : '<span class="text-muted small">No notes.</span>' ?>
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Proof of payment panel -->
    <div class="panel">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
            <span>Proof of Payment</span>
          </h2>
          <p class="text-muted mb-0">Uploaded receipt or transaction screenshot.</p>
        </div>
      </div>

      <?php if (!$proofUrl): ?>
        <p class="text-muted small mb-0">No proof of payment was attached.</p>
      <?php else: ?>
        <div class="d-flex flex-wrap gap-3">
          <?php if ($proofIsImage): ?>
            <a href="<?= htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
              <img src="<?= htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') ?>"
                   class="img-thumbnail" style="max-height:160px" alt="Proof of payment">
            </a>
          <?php elseif ($proofIsPdf): ?>
            <a class="btn btn-light"
               href="<?= htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') ?>"
               target="_blank" rel="noopener">
              <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
              View PDF Receipt
            </a>
          <?php else: ?>
            <a class="btn btn-light"
               href="<?= htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') ?>"
               target="_blank" rel="noopener">
              <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
              Download Attachment
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';