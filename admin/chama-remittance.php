<?php
$pageTitle = 'Chama remittance';
$activeNav = 'guarantors';

require_once __DIR__ . '/../config/db_connection.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!ob_get_level()) ob_start();

/* ================================================================
   FETCH PENDING
================================================================ */
$pending = [];
$res = $conn->query(
    "SELECT
        l.id                                    AS loan_id,
        l.loan_code,
        c.full_name                             AS client_name,
        g.id                                    AS guarantor_id,
        g.name                                  AS chama_name,
        g.phone                                 AS chama_phone,
        l.interest_amount,
        ROUND(l.interest_amount * 10 / 100, 2)  AS remittance_due,
        l.date_issued,
        l.due_date
     FROM loans l
     JOIN clients c           ON c.id = l.client_id
     JOIN loan_guarantors lg  ON lg.loan_id = l.id
     JOIN guarantors g        ON g.id = lg.guarantor_id
     WHERE l.status = 'cleared'
       AND g.type   = 'chama'
       AND NOT EXISTS (
         SELECT 1 FROM chama_remittances cr
         WHERE cr.loan_id = l.id AND cr.guarantor_id = g.id
       )
     ORDER BY l.date_issued DESC, l.id DESC"
);
if ($res) { while ($p = $res->fetch_assoc()) $pending[] = $p; $res->free(); }

$totalDue = array_sum(array_column($pending, 'remittance_due'));

$flash      = $_SESSION['flash']       ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash'], $_SESSION['flash_error']);

if (!function_exists('ksh')) {
    function ksh(float $n): string { return 'Ksh ' . number_format($n, 2); }
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-cash-coin"></i></span>
    <div>
      <p class="eyebrow mb-1">Overview</p>
      <h1 class="h3 mb-1">Chama remittance</h1>
      <p class="text-muted mb-0">Monitor loan interest remittance to chama guarantors.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-light btn-sm" href="chama-remittance-history.php">
      <i class="bi bi-database"></i> All remittance
    </a>
  </div>
</div>

<?php if ($flash): ?>
  <div class="alert alert-success alert-dismissible fade show mt-2">
    <?= htmlspecialchars($flash) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<?php if ($flashError): ?>
  <div class="alert alert-danger alert-dismissible fade show mt-2">
    <?= htmlspecialchars($flashError) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
<?php endif; ?>

<section class="row g-3 mt-1">
  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Pending Remittances</span>
        <span class="metric-icon"><i class="bi bi-hourglass-split"></i></span>
      </div>
      <div class="metric-value"><?= count($pending) ?></div>
    </article>
  </div>
  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-success">
      <div class="metric-top">
        <span class="metric-label">Total Amount Due</span>
        <span class="metric-icon"><i class="bi bi-cash-stack"></i></span>
      </div>
      <div class="metric-value"><?= ksh($totalDue) ?></div>
    </article>
  </div>
</section>

<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-cash-coin"></i>
        <span>Pending Chama Remittances</span>
      </h2>
      <p class="text-muted mb-0">Cleared loans whose chama guarantors have not been remitted yet.</p>
    </div>
    <div class="heading-actions">
      <div class="d-flex justify-content-center gap-2">
        <input type="search" class="form-control form-control-sm"
               id="loanSearch" placeholder="Search..." style="max-width: 220px;">
      </div>
      <?php if (!empty($pending)): ?>
        <form method="post" action="chama-remittance.php" class="m-0"
              onsubmit="return confirm('Process all <?= count($pending) ?> pending remittance(s)?');">
          <input type="hidden" name="action" value="process_all">
          <button class="btn btn-primary btn-sm" type="submit">
            <i class="bi bi-check2-circle"></i> Process All (<?= count($pending) ?>)
          </button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0" id="clientLoansTable">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Loan</th>
          <th>Client</th>
          <th>Chama</th>
          <th class="text-end">Interest</th>
          <th class="text-end">Remittance (10%)</th>
          <th>Issued</th>
          <th>Due</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pending)): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-4">
              <i class="bi bi-check2-circle fs-4 d-block mb-1"></i>
              No pending remittances. All cleared loans have been processed.
            </td>
          </tr>
        <?php else: foreach ($pending as $i => $row): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <a href="loan-view.php?id=<?= (int)$row['loan_id'] ?>" class="fw-semibold">
                <?= htmlspecialchars($row['loan_code']) ?>
              </a>
            </td>
            <td><?= htmlspecialchars($row['client_name']) ?></td>
            <td>
              <div class="fw-semibold"><?= htmlspecialchars($row['chama_name']) ?></div>
              <?php if (!empty($row['chama_phone'])): ?>
                <small class="text-muted"><?= htmlspecialchars($row['chama_phone']) ?></small>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= ksh((float)$row['interest_amount']) ?></td>
            <td class="text-end fw-semibold text-success"><?= ksh((float)$row['remittance_due']) ?></td>
            <td><?= htmlspecialchars($row['date_issued']) ?></td>
            <td><?= htmlspecialchars($row['due_date']) ?></td>
            <td class="text-end">
              <a href="chama-remittance-registration.php?loan_id=<?= (int)$row['loan_id'] ?>&guarantor_id=<?= (int)$row['guarantor_id'] ?>"
                 class="btn btn-outline-primary btn-sm"
                 onclick="return confirm('Record 10% remittance to this chama?');">
                <i class="bi bi-cash"></i> Process
              </a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
      <?php if (!empty($pending)): ?>
        <tfoot class="table-light">
          <tr>
            <th colspan="5" class="text-end">Total</th>
            <th class="text-end text-success"><?= ksh($totalDue) ?></th>
            <th colspan="3"></th>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
</section>

<script>
(() => {
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