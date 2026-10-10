<?php
$pageTitle = 'Guarantors';
$activeNav = 'guarantors';

require_once __DIR__ . '/../config/db_connection.php';
if (session_status() === PHP_SESSION_NONE) session_start();

/* ---------- FETCH GUARANTORS ---------- */
$rows = [];
$res = $conn->query(
    "SELECT id, name, email, phone, type,
            identification_type, identification_number,
            attachment_url, date_added, status
     FROM guarantors
     ORDER BY id DESC"
);
if ($res) {
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $res->free();
}

/* ---------- AGGREGATE STATS ---------- */
$totalGuarantors = count($rows);
$individualCount = 0;
$chamaCount      = 0;

foreach ($rows as $r) {
    if (($r['type'] ?? '') === 'chama') $chamaCount++;
    else                                $individualCount++;
}

/* Linked loans + total guaranteed (defensive about schema variations) */
$linkedGuarantors = 0;
$totalGuaranteed  = 0.0;

$hasJoin = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
if ($hasJoin && $hasJoin->num_rows > 0) {

    /* Does the join table have a guaranteed_amount column? */
    $hasAmountCol = false;
    $colCheck = $conn->query("SHOW COLUMNS FROM loan_guarantors LIKE 'guaranteed_amount'");
    if ($colCheck && $colCheck->num_rows > 0) $hasAmountCol = true;

    if ($hasAmountCol) {
        $res = $conn->query(
            "SELECT COUNT(DISTINCT guarantor_id) AS c,
                    COALESCE(SUM(guaranteed_amount), 0) AS total
             FROM loan_guarantors"
        );
    } else {
        $res = $conn->query(
            "SELECT COUNT(DISTINCT guarantor_id) AS c, 0 AS total
             FROM loan_guarantors"
        );
    }

    if ($res && $row = $res->fetch_assoc()) {
        $linkedGuarantors = (int) $row['c'];
        $totalGuaranteed  = (float) $row['total'];
    }
    if ($res) $res->free();
}

/* Active guarantors */
$activeCount = 0;
foreach ($rows as $r) {
    if (($r['status'] ?? 'active') === 'active') $activeCount++;
}

/* ---------- HELPERS ---------- */
if (!function_exists('g_list_avatar_svg')) {
    function g_list_avatar_svg($name) {
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

if (!function_exists('g_list_ksh')) {
    function g_list_ksh($n): string {
        return 'Ksh ' . number_format((float) $n, 0);
    }
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Management</p>
      <h1 class="h3 mb-1">Guarantors</h1>
      <p class="text-muted mb-0">Individuals and chamas backing client loans.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-primary btn-sm" href="guarantor-registration.php">
      <i class="bi bi-person-plus" aria-hidden="true"></i> Add Guarantor
    </a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- ============ Metric cards ============ -->
<section class="row g-3 mt-1" aria-label="Guarantor metrics">

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Total Guarantors</span>
        <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($totalGuarantors) ?></div>
      <div class="metric-meta">
        <span class="text-success"><?= number_format($activeCount) ?> active</span>
        <span>on record</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-success">
      <div class="metric-top">
        <span class="metric-label">Individuals</span>
        <span class="metric-icon"><i class="bi bi-person" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($individualCount) ?></div>
      <div class="metric-meta">
        <span class="text-muted"><?= number_format($chamaCount) ?></span>
        <span>chama<?= $chamaCount === 1 ? '' : 's' ?></span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Backing Loans</span>
        <span class="metric-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($linkedGuarantors) ?></div>
      <div class="metric-meta">
        <span class="text-muted"><?= number_format(max(0, $totalGuarantors - $linkedGuarantors)) ?></span>
        <span>not linked yet</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Total Guaranteed</span>
        <span class="metric-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= g_list_ksh($totalGuaranteed) ?></div>
      <div class="metric-meta">
        <span class="text-muted">across all loans</span>
      </div>
    </article>
  </div>

</section>

<!-- ============ Guarantors table ============ -->
<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-people" aria-hidden="true"></i>
        <span>All Guarantors</span>
      </h2>
      <p class="text-muted mb-0"><?= number_format($totalGuarantors) ?> guarantors on record.</p>
    </div>
    <div class="heading-actions">
      <div class="d-flex justify-content-center gap-2">
            <input type="search"
                  class="form-control form-control-sm"
                  id="guarantorSearch"
                  placeholder="Search guarantor..."
                  style="max-width: 220px;">
          
          </div>
      <button class="btn btn-outline-secondary btn-sm" type="button"><i class="bi bi-download" aria-hidden="true"></i> Export</button>
      <a class="btn btn-primary btn-sm" href="guarantor-registration.php"><i class="bi bi-person-plus" aria-hidden="true"></i> Add guarantor</a>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0" id="guarantorTable">
      <thead>
        <tr>
          <th scope="col">ID</th>
          <th scope="col">Name</th>
          <th scope="col">Email</th>
          <th scope="col">Phone</th>
          <th scope="col">ID No</th>
          <th scope="col">Photo</th>
          <th scope="col">Date Added</th>
          <th scope="col" class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>

        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">
              No guarantors yet. Click <strong>Add Guarantor</strong>.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $g): ?>
            <?php
              $photoUrl = null;
              $photoIsImage = false;
              if (!empty($g['attachment_url'])) {
                  $photoUrl = '../assets/images/guarantor-attachments/' . rawurlencode($g['attachment_url']);
                  $ext = strtolower(pathinfo($g['attachment_url'], PATHINFO_EXTENSION));
                  $photoIsImage = in_array($ext, ['jpg','jpeg','png','webp','gif'], true);
              }
            ?>
            <tr>
              <td><a href="guarantor-view.php?id=<?= (int) $g['id'] ?>" class="fw-semibold text-decoration-none"><?= (int) $g['id'] ?></a></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if ($photoIsImage): ?>
                    <img class="avatar-img avatar-sm"
                         src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>">
                  <?php else: ?>
                    <img class="avatar-img avatar-sm"
                         src="<?= htmlspecialchars(g_list_avatar_svg($g['name']), ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>">
                  <?php endif; ?>
                  <div>
                    <p class="fw-semibold mb-0"><?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-muted small mb-0">
                      <?= $g['type'] === 'chama' ? 'Chama' : 'Individual' ?>
                    </p>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($g['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($g['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($g['identification_number'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <?php if (!$photoUrl): ?>
                  <span class="text-muted small">—</span>
                <?php elseif ($photoIsImage): ?>
                  <img src="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>"
                       class="avatar-img avatar-sm" alt="Photo">
                <?php else: ?>
                  <a class="btn btn-light btn-sm"
                     href="<?= htmlspecialchars($photoUrl, ENT_QUOTES, 'UTF-8') ?>"
                     target="_blank" rel="noopener"
                     title="Attachment">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                  </a>
                <?php endif; ?>
              </td>
              <td><?= $g['date_added'] ? date('M j, Y', strtotime($g['date_added'])) : '—' ?></td>
              <td class="text-end">
                <a class="btn btn-light btn-sm"
                   href="guarantor-view.php?id=<?= (int) $g['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>

      </tbody>
    </table>
  </div>
</section>

<script>
(() => {
  /* Client-side search filter for the loans table */
  const $search = document.getElementById('guarantorSearch');
  const $table  = document.getElementById('guarantorTable');
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