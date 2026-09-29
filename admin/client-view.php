<?php
$pageTitle = 'Client Details';
$activeNav = 'clients';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SESSION ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- VALIDATE ?id ---------- */
$clientId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($clientId <= 0) {
    $_SESSION['flash'] = 'Invalid client ID.';
    header('Location: clients.php');
    exit;
}

/* ---------- FETCH CLIENT ---------- */
$stmt = $conn->prepare(
    "SELECT id, client_code, full_name, email, phone, id_number,
            date_of_birth, gender, location, id_document_url, photo_url,
            uploads, status, notes, join_date, created_at
     FROM clients
     WHERE id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $clientId);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    $_SESSION['flash'] = 'Client not found.';
    header('Location: clients.php');
    exit;
}

/* ---------- FETCH CLIENT LOANS ---------- */
$loans = [];
$stmt = $conn->prepare(
    "SELECT id, loan_code, loan_category, date_issued, principal_amount,
            interest_rate, interest_amount, total_due, amount_paid,
            balance, due_date, status, purpose
     FROM loans
     WHERE client_id = ?
     ORDER BY id DESC"
);
$stmt->bind_param("i", $clientId);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $loans[] = $r;
$stmt->close();

/* ---------- AGGREGATE LOAN STATS ---------- */
$activeCount          = 0;
$activeOutstanding    = 0.0;
$defaultedCount       = 0;
$defaultedOutstanding = 0.0;
$clearedCount         = 0;
$clearedRepaid        = 0.0;

foreach ($loans as $l) {
    $bal  = (float) $l['balance'];
    $paid = (float) $l['amount_paid'];
    switch ($l['status']) {
        case 'active':
        case 'overdue':
            $activeCount++;
            $activeOutstanding += $bal;
            break;
        case 'defaulted':
            $defaultedCount++;
            $defaultedOutstanding += $bal;
            break;
        case 'cleared':
            $clearedCount++;
            $clearedRepaid += $paid;
            break;
    }
}

/* ---------- HELPERS ---------- */
if (!function_exists('client_avatar_svg')) {
    function client_avatar_svg($name) {
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

if (!function_exists('client_loan_badge')) {
    function client_loan_badge(string $status): array {
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

if (!function_exists('client_ksh')) {
    function client_ksh($n): string {
        return 'Ksh ' . number_format((float) $n, 2);
    }
}

/* ---------- PREPARE DISPLAY VALUES ---------- */
$photoUrl = !empty($client['photo_url'])
    ? '../assets/client-attachments/' . rawurlencode($client['photo_url'])
    : client_avatar_svg($client['full_name']);

$joinDate = $client['join_date'] ? date('M j, Y', strtotime($client['join_date'])) : '—';
$dobDate  = $client['date_of_birth'] ? date('M j, Y', strtotime($client['date_of_birth'])) : '—';

/* Attachments (JSON array of filenames) */
$attachments = [];
if (!empty($client['uploads'])) {
    $decoded = json_decode($client['uploads'], true);
    if (is_array($decoded)) $attachments = $decoded;
}

/* Status badge styling */
$statusMap = [
    'active'      => 'text-bg-success',
    'inactive'    => 'text-bg-secondary',
    'blacklisted' => 'text-bg-danger',
];
$statusClass = $statusMap[$client['status']] ?? 'text-bg-secondary';
$statusLabel = ucfirst($client['status']);

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-person-lines-fill" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Client Management</p>
      <h1 class="h3 mb-1">Client Details</h1>
      <p class="text-muted mb-0">Inspect account status, profile data, loan history, and recent activity.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="clients.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Clients
    </a>
    <a class="btn btn-outline-primary btn-sm" href="client-edit.php?id=<?= (int) $client['id'] ?>">
      <i class="bi bi-pencil-square" aria-hidden="true"></i> Edit Client
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
  <!-- Client profile -->
  <div class="col-12 col-xl-4">
    <div class="panel h-100 text-center profile-card">
      <div class="profile-cover">
        <img src="../assets/images/png/dasher-ui-bootstrap-5.jpg" alt="User workspace preview">
      </div>
      <div class="profile-hero">
        <img class="avatar-img avatar-xl profile-photo"
             src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>"
             alt="<?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?>">
        <h2 class="h5 mb-1"><?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?></h2>
        <span class="badge <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
      </div>

      <div class="info-list mt-4 text-start">
        <div><span>Client Code</span><strong><?= htmlspecialchars($client['client_code'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Email</span><strong><?= htmlspecialchars($client['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Phone</span><strong><?= htmlspecialchars($client['phone'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>ID No</span><strong><?= htmlspecialchars($client['id_number'], ENT_QUOTES, 'UTF-8') ?></strong></div>
        <div><span>Date of Birth</span><strong><?= htmlspecialchars($dobDate) ?></strong></div>
        <div><span>Gender</span><strong><?= $client['gender'] ? htmlspecialchars(ucfirst($client['gender'])) : '—' ?></strong></div>
        <div><span>Date of Joining</span><strong><?= htmlspecialchars($joinDate) ?></strong></div>
        <div><span>Location</span><strong><?= htmlspecialchars($client['location'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong></div>
      </div>

      <?php if (!empty($client['notes'])): ?>
        <div class="alert alert-light text-start mt-3 mb-0 small">
          <strong>Notes:</strong><br>
          <?= nl2br(htmlspecialchars($client['notes'], ENT_QUOTES, 'UTF-8')) ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Loan summary + account overview -->
  <div class="col-12 col-xl-8">
    <div class="row g-3">

      <!-- Active loans -->
      <div class="col-12 col-md-4">
        <div class="mini-card h-100 d-flex flex-column">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span>Active Loans</span>
            <i class="bi bi-cash-coin text-success fs-5"></i>
          </div>
          <strong class="fs-3"><?= number_format($activeCount) ?></strong>
          <small class="text-muted"><?= client_ksh($activeOutstanding) ?> outstanding</small>
        </div>
      </div>

      <!-- Defaulted loans -->
      <div class="col-12 col-md-4">
        <div class="mini-card h-100 d-flex flex-column">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span>Defaulted Loans</span>
            <i class="bi bi-exclamation-triangle text-danger fs-5"></i>
          </div>
          <strong class="fs-3"><?= number_format($defaultedCount) ?></strong>
          <small class="text-muted"><?= client_ksh($defaultedOutstanding) ?> overdue</small>
        </div>
      </div>

      <!-- Cleared loans -->
      <div class="col-12 col-md-4">
        <div class="mini-card h-100 d-flex flex-column">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span>Cleared Loans</span>
            <i class="bi bi-check-circle text-primary fs-5"></i>
          </div>
          <strong class="fs-3"><?= number_format($clearedCount) ?></strong>
          <small class="text-muted"><?= client_ksh($clearedRepaid) ?> repaid</small>
        </div>
      </div>

      <!-- Attachments (real, from uploads JSON) -->
      <div class="col-12">
        <div class="mini-card">
          <span>Attachments</span>
          <?php if (empty($attachments)): ?>
            <p class="text-muted small mb-0 mt-2">No attachments uploaded for this client.</p>
          <?php else: ?>
            <div class="d-flex flex-wrap gap-2 mt-2">
              <?php foreach ($attachments as $file): ?>
                <?php
                  $url = '../assets/client-attachments/' . rawurlencode($file);
                  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                  $isImage = in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
                  $isVideo = in_array($ext, ['mp4','webm','mov'], true);
                ?>
                <?php if ($isImage): ?>
                  <a href="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                    <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                         class="img-thumbnail" style="max-height:100px"
                         alt="Attachment">
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

      <!-- ID document preview (single) -->
      <?php if (!empty($client['id_document_url'])): ?>
        <?php
          $idDocUrl = '../assets/client-attachments/' . rawurlencode($client['id_document_url']);
          $idDocExt = strtolower(pathinfo($client['id_document_url'], PATHINFO_EXTENSION));
        ?>
        <div class="col-12">
          <div class="mini-card">
            <span>ID Document</span>
            <div class="mt-2">
              <?php if (in_array($idDocExt, ['jpg','jpeg','png','webp','gif'], true)): ?>
                <a href="<?= htmlspecialchars($idDocUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                  <img src="<?= htmlspecialchars($idDocUrl, ENT_QUOTES, 'UTF-8') ?>"
                       class="img-thumbnail" style="max-height:140px" alt="ID Document">
                </a>
              <?php else: ?>
                <a class="btn btn-light btn-sm"
                   href="<?= htmlspecialchars($idDocUrl, ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" rel="noopener">
                  <i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i>
                  View ID Document
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="col-12 panel mt-3">

        <div class="panel-header ">
          <div>
            <h2 class="h5 mb-1 section-title">
              <i class="bi bi-table" aria-hidden="true"></i>
              <span>All Loans</span>
            </h2>
            <p class="text-muted mb-0">Complete loan history for this client.</p>
          </div>
          <div class="d-flex justify-content-center gap-2">
            <input type="search"
                  class="form-control form-control-sm"
                  id="loanSearch"
                  placeholder="Search loans..."
                  style="max-width: 220px;">
            <button class="btn btn-outline-secondary btn-sm" type="button">
              <i class="bi bi-download" aria-hidden="true"></i> Export
            </button>
          </div>
        </div>

        <div class="table-responsive ">
          <table class="table align-middle mb-0" id="clientLoansTable">
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
                    No loans recorded for this client yet.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($loans as $l): ?>
                  <?php
                    [$badgeClass, $badgeLabel] = client_loan_badge($l['status']);

                    $issuedFmt = $l['date_issued'] ? date('M j, Y', strtotime($l['date_issued'])) : '—';
                    $dueFmt    = $l['due_date']    ? date('M j, Y', strtotime($l['due_date']))    : '—';

                    $loanType = $l['purpose']
                        ?: ucwords(str_replace('_', ' ', $l['loan_category'] ?? 'loan')) . ' Loan';
                  ?>
                  <tr>
                    <td>
                      <span class="fw-semibold"><?= htmlspecialchars($l['loan_code'], ENT_QUOTES, 'UTF-8') ?></span>
                      <p class="text-muted small mb-0"><?= htmlspecialchars($loanType, ENT_QUOTES, 'UTF-8') ?></p>
                    </td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <img class="avatar-img avatar-sm"
                            src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>"
                            alt="<?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?>">
                        <div>
                          <p class="fw-semibold mb-0"><?= htmlspecialchars($client['full_name'], ENT_QUOTES, 'UTF-8') ?></p>
                          <p class="text-muted small mb-0"><?= htmlspecialchars($client['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                      </div>
                    </td>
                    <td><?= htmlspecialchars($issuedFmt) ?></td>
                    <td><?= client_ksh($l['principal_amount']) ?></td>
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


      </div>
    </div>
  </div>
</section>



<script>
(() => {
  /* Client-side search filter for the loans table */
  const $search = document.getElementById('loanSearch');
  const $table  = document.getElementById('clientLoansTable');
  if (!$search || !$table) return;

  $search.addEventListener('input', () => {
    const q = $search.value.trim().toLowerCase();
    $table.querySelectorAll('tbody tr').forEach(row => {
      row.style.display = !q || row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';