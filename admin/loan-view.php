<?php
$pageTitle = 'Loan Details';
$activeNav = 'loans';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- VALIDATE ?id ---------- */
$loanId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($loanId <= 0) {
    $_SESSION['flash'] = 'Invalid loan ID.';
    header('Location: loans.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH LOAN + CLIENT
---------------------------------------------------------------- */
$stmt = $conn->prepare(
    "SELECT
        l.id, l.loan_code, l.date_issued, l.principal_amount,
        l.interest_rate, l.interest_amount, l.total_due,
        l.amount_paid, l.balance, l.due_date, l.status,
        l.collateral_type, l.collateral_description,
        l.uploads, l.purpose, l.notes,
        l.created_at,
        c.id           AS client_id,
        c.client_code,
        c.full_name    AS client_name,
        c.email        AS client_email,
        c.phone        AS client_phone,
        c.id_number    AS client_id_number,
        c.date_of_birth,
        c.gender,
        c.location,
        c.photo_url    AS client_photo,
        c.join_date
     FROM loans l
     INNER JOIN clients c ON c.id = l.client_id
     WHERE l.id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $loanId);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    $_SESSION['flash'] = 'Loan not found.';
    header('Location: loans.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH GUARANTOR (defensive — table may not exist yet)
---------------------------------------------------------------- */
$guarantors = [];
$tableCheck = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $stmt = $conn->prepare(
        "SELECT g.id, g.name, g.email, g.phone,
                g.identification_type, g.identification_number,
                g.attachment_url
         FROM loan_guarantors lg
         INNER JOIN guarantors g ON g.id = lg.guarantor_id
         WHERE lg.loan_id = ?"
    );
    $stmt->bind_param("i", $loanId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $guarantors[] = $r;
    $stmt->close();
}

/* ----------------------------------------------------------------
   COLLATERAL / ATTACHMENTS
---------------------------------------------------------------- */
$attachments = [];
if (!empty($loan['uploads'])) {
    $decoded = json_decode($loan['uploads'], true);
    if (is_array($decoded)) $attachments = $decoded;
}

/* ----------------------------------------------------------------
   HELPERS
---------------------------------------------------------------- */
if (!function_exists('lv_avatar_svg')) {
    function lv_avatar_svg($name) {
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

function lv_status_badge(string $status): array {
    return match ($status) {
        'active'      => ['text-bg-success',   'Active'],
        'defaulted'   => ['text-bg-danger',    'Defaulted'],
        'cleared'     => ['text-bg-primary',   'Cleared'],
        'written_off' => ['text-bg-secondary', 'Written Off'],
        default       => ['text-bg-secondary', ucfirst($status)],
    };
}

function lv_ksh($n): string {
    return 'Ksh ' . number_format((float) $n, 2);
}

function lv_fmt_date(?string $d): string {
    return $d ? date('M j, Y', strtotime($d)) : '—';
}

/* ---------- Prepare display values ---------- */
[$badgeClass, $badgeLabel] = lv_status_badge($loan['status']);

$clientPhoto = !empty($loan['client_photo'])
    ? '../assets/client-attachments/' . rawurlencode($loan['client_photo'])
    : lv_avatar_svg($loan['client_name']);

$loanPurpose = $loan['purpose'] ?: ucwords(str_replace('_', ' ', 'personal')) . ' Loan';

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Loan Management</p>
      <h1 class="h3 mb-1">Loan Details</h1>
      <p class="text-muted mb-0">View loan terms, client information, guarantor, collateral, and payment history.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="loans.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back
    </a>
    <a class="btn btn-primary btn-sm" href="loan-registration.php">
      <i class="bi bi-plus-circle" aria-hidden="true"></i> Add Loan
    </a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<section class="row g-3">
  <!-- Left column: Client profile -->
  <div class="col-12 col-xl-4">
    <div class="panel h-100 text-center profile-card">
      <div class="profile-cover">
        <img src="../assets/images/png/dasher-ui-bootstrap-5.jpg" alt="Client cover">
      </div>
      <div class="profile-hero">
        <img class="avatar-img avatar-xl profile-photo"
             src="<?= htmlspecialchars($clientPhoto, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($loan['client_name'], ENT_QUOTES, 'UTF-8') ?>">
        <h2 class="h5 mb-1"><?= htmlspecialchars($loan['client_name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="text-muted mb-3">Loan Client</p>
        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span>
      </div>
      <div class="info-list mt-4 text-start">
        <div><span>Client Code</span><strong><?= htmlspecialchars($loan['client_code'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Email</span><strong><?= htmlspecialchars($loan['client_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Phone</span><strong><?= htmlspecialchars($loan['client_phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>ID No</span><strong><?= htmlspecialchars($loan['client_id_number'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Date of Birth</span><strong><?= htmlspecialchars(lv_fmt_date($loan['date_of_birth'])) ?></strong></div>
        <div><span>Gender</span><strong><?= $loan['gender'] ? htmlspecialchars(ucfirst($loan['gender'])) : '—' ?></strong></div>
        <div><span>Location</span><strong><?= htmlspecialchars($loan['location'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Member Since</span><strong><?= htmlspecialchars(lv_fmt_date($loan['join_date'])) ?></strong></div>
      </div>
      <a class="btn btn-outline-secondary btn-sm w-100 mt-3"
         href="client-view.php?id=<?= (int) $loan['client_id'] ?>">
        <i class="bi bi-person-lines-fill" aria-hidden="true"></i> View Full Profile
      </a>
    </div>
  </div>

  <!-- Right column: Loan details + Payments table -->
  <div class="col-12 col-xl-8">
    <!-- Loan Details Panel -->
    <div class="panel mb-3">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-cash-coin" aria-hidden="true"></i>
            <span>Loan Details</span>
          </h2>
          <p class="text-muted mb-0">Loan ID: <?= htmlspecialchars($loan['loan_code'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <a class="btn btn-primary btn-sm" href="loan-edit.php?id=<?= (int) $loan['id'] ?>">Edit Loan</a>
      </div>

      <div class="row g-3">
        <div class="col-md-6">
          <div class="mini-card">
            <span>Loan Type</span>
            <strong><?= htmlspecialchars($loan['purpose'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Principal Amount</span>
            <strong><?= lv_ksh($loan['principal_amount']) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Interest Rate</span>
            <strong><?= number_format((float) $loan['interest_rate'], 2) ?>%</strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Interest Amount</span>
            <strong><?= lv_ksh($loan['interest_amount']) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Total Due</span>
            <strong><?= lv_ksh($loan['total_due']) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Amount Paid</span>
            <strong><?= lv_ksh($loan['amount_paid']) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Balance</span>
            <strong><?= lv_ksh($loan['balance']) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Date of Issue</span>
            <strong><?= htmlspecialchars(lv_fmt_date($loan['date_issued'])) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Due Date</span>
            <strong><?= htmlspecialchars(lv_fmt_date($loan['due_date'])) ?></strong>
          </div>
        </div>
        <div class="col-md-6">
          <div class="mini-card">
            <span>Status</span>
            <strong><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span></strong>
          </div>
        </div>

        <!-- Guarantor -->
        <div class="col-md-6">
          <div class="mini-card">
            <span>Guarantor</span>
            <?php if (empty($guarantors)): ?>
              <strong class="text-muted">Not assigned</strong>
            <?php else: ?>
              <?php foreach ($guarantors as $g): ?>
                <strong>
                  <?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>
                  <?php if (!empty($g['identification_number'])): ?>
                    <span class="text-muted small">
                      (<?= htmlspecialchars($g['identification_number'], ENT_QUOTES, 'UTF-8') ?>)
                    </span>
                  <?php endif; ?>
                </strong>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Collateral -->
        <div class="col-12">
          <div class="mini-card">
            <span>Collateral</span>
            <?php if (empty($attachments)): ?>
              <p class="text-muted small mb-0 mt-2">No collateral attached.</p>
            <?php else: ?>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <?php foreach ($attachments as $file): ?>
                  <?php
                    $url = '../assets/loan-attachments/' . rawurlencode($file);
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $isImage = in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
                    $isVideo = in_array($ext, ['mp4','webm','mov'], true);
                  ?>
                  <?php if ($isImage): ?>
                    <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                      <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                           class="img-thumbnail" style="max-height:100px" alt="Collateral">
                    </a>
                  <?php elseif ($isVideo): ?>
                    <video src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                           class="img-thumbnail" style="max-height:100px" controls></video>
                  <?php else: ?>
                    <a class="btn btn-light btn-sm"
                       href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                       target="_blank" rel="noopener">
                      <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                      <?= htmlspecialchars($file, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Notes -->
        <div class="col-12">
          <div class="mini-card">
            <span>Notes</span>
            <p class="mb-0 mt-1">
              <?= !empty($loan['notes'])
                  ? nl2br(htmlspecialchars($loan['notes'], ENT_QUOTES, 'UTF-8'))
                  : '<span class="text-muted small">No notes.</span>' ?>
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Payments Panel (header only — no payments table yet) -->
    <div class="panel">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <span>Payments</span>
          </h2>
          <p class="text-muted mb-0">Payment history for this loan.</p>
        </div>
        <button class="btn btn-primary btn-sm" type="button" disabled>
          <i class="bi bi-plus-circle" aria-hidden="true"></i> Record Payment
        </button>
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
            <tr>
              <td colspan="7" class="text-center text-muted py-4">
                No payments recorded for this loan yet.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';