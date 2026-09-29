<?php
$pageTitle = 'Guarantor Details';
$activeNav = 'guarantors';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- LOAN LOGIC (for status badges) ---------- */
if (file_exists(__DIR__ . '/loan-logic.php')) {
    require_once __DIR__ . '/loan-logic.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- VALIDATE ?id ---------- */
$guarantorId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($guarantorId <= 0) {
    $_SESSION['flash'] = 'Invalid guarantor ID.';
    header('Location: guarantors.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH GUARANTOR
---------------------------------------------------------------- */
$stmt = $conn->prepare(
    "SELECT id, name, email, phone, type,
            identification_type, identification_number,
            attachment_url, notes, status, date_added, created_at
     FROM guarantors
     WHERE id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $guarantorId);
$stmt->execute();
$guarantor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$guarantor) {
    $_SESSION['flash'] = 'Guarantor not found.';
    header('Location: guarantors.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH GUARANTEED LOANS (via loan_guarantors join table, if present)
---------------------------------------------------------------- */
$loans = [];
$hasJoin = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
if ($hasJoin && $hasJoin->num_rows > 0) {
    $stmt = $conn->prepare(
        "SELECT
            l.id, l.loan_code, l.loan_category, l.purpose,
            l.principal_amount, l.total_due, l.balance, l.status,
            l.date_issued, l.due_date,
            c.id         AS client_id,
            c.full_name  AS client_name,
            c.email      AS client_email,
            c.photo_url  AS client_photo
         FROM loan_guarantors lg
         INNER JOIN loans   l ON l.id = lg.loan_id
         INNER JOIN clients c ON c.id = l.client_id
         WHERE lg.guarantor_id = ?
         ORDER BY l.id DESC"
    );
    $stmt->bind_param("i", $guarantorId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $loans[] = $r;
    $stmt->close();
}

/* ----------------------------------------------------------------
   SUMMARY TOTALS
---------------------------------------------------------------- */
$totalGuaranteed = 0.0;
$totalBalance    = 0.0;
$activeCount     = 0;
foreach ($loans as $l) {
    $totalGuaranteed += (float) $l['principal_amount'];
    $totalBalance    += (float) $l['balance'];
    if (in_array($l['status'], ['active','overdue'], true)) $activeCount++;
}

/* ----------------------------------------------------------------
   HELPERS
---------------------------------------------------------------- */
if (!function_exists('gv_avatar_svg')) {
    function gv_avatar_svg($name) {
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

if (!function_exists('gv_ksh')) {
    function gv_ksh($n): string {
        return 'Ksh ' . number_format((float) $n, 2);
    }
}

if (!function_exists('gv_fmt_date')) {
    function gv_fmt_date(?string $d): string {
        return $d ? date('M j, Y', strtotime($d)) : '—';
    }
}

if (!function_exists('gv_status_badge')) {
    function gv_status_badge(string $status): array {
        return match ($status) {
            'active'      => ['text-bg-success',   'Active'],
            'overdue'     => ['text-bg-warning',   'Overdue'],
            'defaulted'   => ['text-bg-danger',    'Defaulted'],
            'cleared'     => ['text-bg-primary',   'Cleared'],
            'written_off' => ['text-bg-secondary', 'Written Off'],
            default       => ['text-bg-secondary', ucfirst($status)],
        };
    }
}

/* ---------- Prepare display values ---------- */
$guarantorPhoto = !empty($guarantor['attachment_url'])
    ? '/assets/images/guarantor-attachments/' . rawurlencode($guarantor['attachment_url'])
    : gv_avatar_svg($guarantor['name']);

$idTypeLabel = [
    'id_no'    => 'ID No',
    'passport' => 'Passport',
    'other'    => 'Document',
][$guarantor['identification_type']] ?? 'ID';

$typeLabel = $guarantor['type'] === 'chama' ? 'Chama' : 'Individual';
$isActive  = $guarantor['status'] === 'active';

$guarantorPhotoExt = $guarantor['attachment_url']
    ? strtolower(pathinfo($guarantor['attachment_url'], PATHINFO_EXTENSION))
    : null;
$photoIsImage = $guarantorPhotoExt && in_array($guarantorPhotoExt, ['jpg','jpeg','png','webp','gif'], true);

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-person-lines-fill" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Management</p>
      <h1 class="h3 mb-1">Guarantor Details</h1>
      <p class="text-muted mb-0">View guarantor profile, contact information, and guaranteed loans.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="guarantors.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Guarantors
    </a>
    <a class="btn btn-outline-primary btn-sm" href="guarantor-edit.php?id=<?= (int) $guarantor['id'] ?>">
      <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit Guarantor
    </a>
    <a class="btn btn-primary btn-sm" href="guarantor-registration.php">
      <i class="bi bi-person-plus" aria-hidden="true"></i> Add Guarantor
    </a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<section class="row g-3">
  <!-- Guarantor profile card -->
  <div class="col-12 col-xl-4">
    <div class="panel h-100 text-center profile-card">
      <div class="profile-cover">
        <img src="../assets/images/png/dasher-ui-bootstrap-5.jpg" alt="Guarantor cover">
      </div>
      <div class="profile-hero">
        <?php if ($photoIsImage): ?>
          <img class="avatar-img avatar-xl profile-photo"
               src="<?= htmlspecialchars($guarantorPhoto, ENT_QUOTES, 'UTF-8') ?>"
               alt="<?= htmlspecialchars($guarantor['name'], ENT_QUOTES, 'UTF-8') ?>">
        <?php else: ?>
          <img class="avatar-img avatar-xl profile-photo"
               src="<?= htmlspecialchars(gv_avatar_svg($guarantor['name']), ENT_QUOTES, 'UTF-8') ?>"
               alt="<?= htmlspecialchars($guarantor['name'], ENT_QUOTES, 'UTF-8') ?>">
        <?php endif; ?>

        <h2 class="h5 mb-1"><?= htmlspecialchars($guarantor['name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <p class="text-muted mb-3">Guarantor ID: #<?= (int) $guarantor['id'] ?></p>

        <div class="d-flex justify-content-center gap-2">
            <span class="badge text-bg-info"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></span>
            <span class="badge <?= $isActive ? 'text-bg-success' : 'text-bg-secondary' ?>">
                <?= $isActive ? 'Active' : 'Inactive' ?>
            </span>
        </div>
      </div>

      <div class="info-list mt-4 text-start">
        <div><span>Email</span><strong><?= htmlspecialchars($guarantor['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Phone</span><strong><?= htmlspecialchars($guarantor['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span><?= htmlspecialchars($idTypeLabel, ENT_QUOTES, 'UTF-8') ?></span><strong><?= htmlspecialchars($guarantor['identification_number'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Date Created</span><strong><?= htmlspecialchars(gv_fmt_date($guarantor['date_added'])) ?></strong></div>
      </div>

      <?php if (!empty($guarantor['attachment_url']) && !$photoIsImage): ?>
        <a class="btn btn-outline-secondary btn-sm w-100 mt-3"
           href="<?= htmlspecialchars($guarantorPhoto, ENT_QUOTES, 'UTF-8') ?>"
           target="_blank" rel="noopener">
          <i class="bi bi-paperclip" aria-hidden="true"></i> View Attachment
        </a>
      <?php endif; ?>

      <?php if (!empty($guarantor['notes'])): ?>
        <div class="alert alert-light text-start mt-3 mb-0 small">
          <strong>Notes:</strong><br>
          <?= nl2br(htmlspecialchars($guarantor['notes'], ENT_QUOTES, 'UTF-8')) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Guaranteed loans summary + table -->
  <div class="col-12 col-xl-8">
    <!-- Summary cards -->
    <div class="row g-3 mb-3">
      <div class="col-12 col-md-4">
        <div class="mini-card h-100 d-flex flex-column">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span>Loans Guaranteed</span>
            <i class="bi bi-shield-check text-primary fs-5"></i>
          </div>
          <strong class="fs-3"><?= number_format(count($loans)) ?></strong>
          <small class="text-muted"><?= number_format($activeCount) ?> active</small>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="mini-card h-100 d-flex flex-column">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span>Total Guaranteed</span>
            <i class="bi bi-cash-stack text-success fs-5"></i>
          </div>
          <strong class="fs-3"><?= gv_ksh($totalGuaranteed) ?></strong>
          <small class="text-muted">Combined principal</small>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="mini-card h-100 d-flex flex-column">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span>Outstanding Balance</span>
            <i class="bi bi-exclamation-circle text-warning fs-5"></i>
          </div>
          <strong class="fs-3"><?= gv_ksh($totalBalance) ?></strong>
          <small class="text-muted">Across all guaranteed loans</small>
        </div>
      </div>
    </div>

    <!-- Loans table -->
    <div class="panel">
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-table" aria-hidden="true"></i>
            <span>Guaranteed Loans</span>
          </h2>
          <p class="text-muted mb-0">All loans where this person is a guarantor.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <input type="search"
                 class="form-control form-control-sm"
                 id="loanSearch"
                 placeholder="Search loans..."
                 style="max-width: 200px;">
        </div>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="guaranteedLoansTable">
          <thead class="table-light">
            <tr>
              <th scope="col">Loan ID</th>
              <th scope="col">Borrower</th>
              <th scope="col">Loan Type</th>
              <th scope="col" class="text-end">Amount</th>
              <th scope="col" class="text-end">Balance</th>
              <th scope="col">Status</th>
              <th scope="col" class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>

            <?php if (empty($loans)): ?>
              <tr>
                <td colspan="7" class="text-center text-muted py-4">
                  <?php if ($hasJoin && $hasJoin->num_rows > 0): ?>
                    This guarantor has not backed any loans yet.
                  <?php else: ?>
                    Guaranteed loans will appear here once the <code>loan_guarantors</code> table is created.
                  <?php endif; ?>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($loans as $l): ?>
                <?php
                  [$badgeClass, $badgeLabel] = gv_status_badge($l['status']);

                  $borrowerPhoto = !empty($l['client_photo'])
                      ? '../assets/client-attachments/' . rawurlencode($l['client_photo'])
                      : gv_avatar_svg($l['client_name']);

                  $loanType = $l['purpose']
                      ?: ucwords(str_replace('_', ' ', $l['loan_category'] ?? 'loan')) . ' Loan';
                ?>
                <tr>
                  <td><?= htmlspecialchars($l['loan_code'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <img class="avatar-img avatar-sm"
                           src="<?= htmlspecialchars($borrowerPhoto, ENT_QUOTES, 'UTF-8') ?>"
                           alt="<?= htmlspecialchars($l['client_name'], ENT_QUOTES, 'UTF-8') ?>">
                      <div>
                        <p class="fw-semibold mb-0"><?= htmlspecialchars($l['client_name'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="text-muted small mb-0"><?= htmlspecialchars($l['client_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                      </div>
                    </div>
                  </td>
                  <td><?= htmlspecialchars($loanType, ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="text-end"><?= gv_ksh($l['principal_amount']) ?></td>
                  <td class="text-end"><?= gv_ksh($l['balance']) ?></td>
                  <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span></td>
                  <td class="text-end">
                    <a href="loan-view.php?id=<?= (int) $l['id'] ?>" class="btn btn-sm btn-outline-primary">View Loan</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>

          </tbody>
        </table>
      </div>
    </div>
  </div>
</section>

<script>
(() => {
  /* Client-side search filter for the guaranteed loans table */
  const $search = document.getElementById('loanSearch');
  const $table  = document.getElementById('guaranteedLoansTable');
  if (!$search || !$table) return;

  $search.addEventListener('input', () => {
    const q = $search.value.trim().toLowerCase();
    const rows = $table.querySelectorAll('tbody tr');
    rows.forEach(row => {
      row.style.display = !q || row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';