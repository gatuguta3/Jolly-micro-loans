<?php
$pageTitle = 'Chama Remittance History';
$activeNav = 'guarantors';

require_once __DIR__ . '/../config/db_connection.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| Fetch all chama remittances (history)
|--------------------------------------------------------------------------
*/
$history = [];
$res = $conn->query("
    SELECT
      cr.id,
      cr.loan_id,
      l.loan_code,
      c.full_name     AS client_name,
      c.email         AS client_email,
      g.id            AS guarantor_id,
      g.name          AS chama_name,
      g.phone         AS chama_phone,
      cr.base_amount,
      cr.rate,
      cr.remittance_amount,
      cr.status,
      cr.due_date,
      cr.paid_date,
      cr.reference_number,
      cr.remarks,
      cr.created_at
    FROM chama_remittances cr
    JOIN loans l      ON l.id = cr.loan_id
    JOIN clients c    ON c.id = l.client_id
    JOIN guarantors g ON g.id = cr.guarantor_id
    ORDER BY cr.created_at DESC
");

if ($res) {
    while ($h = $res->fetch_assoc()) $history[] = $h;
    $res->free();
}

/*
|--------------------------------------------------------------------------
| Summary stats
|--------------------------------------------------------------------------
*/
$totalRemittance = 0.0;
$totalPaid       = 0.0;
$totalPending    = 0.0;
$countPaid       = 0;
$countPending    = 0;
$countCancelled  = 0;

foreach ($history as $r) {
    $amt = (float) $r['remittance_amount'];
    $totalRemittance += $amt;

    switch ($r['status']) {
        case 'paid':
            $totalPaid += $amt;
            $countPaid++;
            break;
        case 'pending':
            $totalPending += $amt;
            $countPending++;
            break;
        case 'cancelled':
            $countCancelled++;
            break;
    }
}

ob_start();
?>

<!-- ============ Page heading ============ -->
<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Overview</p>
      <h1 class="h3 mb-1">Chama Remittance History</h1>
      <p class="text-muted mb-0">All remittances recorded from cleared loans.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-light btn-sm" href="chama-remittance.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back
    </a>
    <button class="btn btn-outline-secondary btn-sm" type="button">
      <i class="bi bi-download" aria-hidden="true"></i> Export
    </button>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success alert-dismissible fade show mt-2">
    <i class="bi bi-check-circle me-1"></i>
    <?= htmlspecialchars($_SESSION['flash']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert alert-danger alert-dismissible fade show mt-2">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <?= htmlspecialchars($_SESSION['flash_error']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  </div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

<!-- ============ Metric cards ============ -->
<section class="row g-3 mt-1" aria-label="Remittance metrics">

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Total Records</span>
        <span class="metric-icon"><i class="bi bi-list-check" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format(count($history)) ?></div>
      <div class="metric-meta">
        <span>all remittances</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-success">
      <div class="metric-top">
        <span class="metric-label">Total Paid</span>
        <span class="metric-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">KES <?= number_format($totalPaid, 2) ?></div>
      <div class="metric-meta">
        <span><?= number_format($countPaid) ?> settled</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Total Pending</span>
        <span class="metric-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">KES <?= number_format($totalPending, 2) ?></div>
      <div class="metric-meta">
        <span><?= number_format($countPending) ?> awaiting payment</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Grand Total</span>
        <span class="metric-icon"><i class="bi bi-cash-stack" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value">KES <?= number_format($totalRemittance, 2) ?></div>
      <div class="metric-meta">
        <span><?= number_format($countCancelled) ?> cancelled</span>
      </div>
    </article>
  </div>

</section>

<!-- ============ History table ============ -->
<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-clock-history" aria-hidden="true"></i>
        <span>Chama Remittance History</span>
      </h2>
      <p class="text-muted mb-0">Every remittance recorded — pending, paid, or cancelled.</p>
    </div>
    <div class="heading-actions">
      <div class="d-flex justify-content-center gap-2">
        <input type="search"
               class="form-control form-control-sm"
               id="historySearch"
               placeholder="Search history..."
               style="max-width: 220px;">
      </div>
      <select class="form-select form-select-sm" id="statusFilter" style="max-width: 160px;">
        <option value="">All statuses</option>
        <option value="pending">Pending</option>
        <option value="paid">Paid</option>
        <option value="cancelled">Cancelled</option>
      </select>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0" id="historyTable">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Loan</th>
          <th>Client</th>
          <th>Chama</th>
          <th class="text-end">Base</th>
          <th class="text-end">Rate</th>
          <th class="text-end">Remittance</th>
          <th>Status</th>
          <th>Due</th>
          <th>Paid</th>
          <th>Reference</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($history)): ?>
          <tr>
            <td colspan="12" class="text-center text-muted py-4">
              <i class="bi bi-inbox fs-4 d-block mb-1"></i>
              No remittances recorded yet.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($history as $i => $row): ?>
            <?php
              switch ($row['status']) {
                  case 'paid':
                      $badge = 'bg-success-subtle text-success-emphasis';
                      break;
                  case 'cancelled':
                      $badge = 'bg-danger-subtle text-danger-emphasis';
                      break;
                  default:
                      $badge = 'bg-warning-subtle text-warning-emphasis';
              }
            ?>
            <tr data-status="<?= htmlspecialchars($row['status']) ?>">
              <td><?= $i + 1 ?></td>
              <td>
                <a href="loan-view.php?id=<?= (int)$row['loan_id'] ?>" class="fw-semibold">
                  <?= htmlspecialchars($row['loan_code']) ?>
                </a>
              </td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($row['client_name']) ?></div>
                <?php if (!empty($row['client_email'])): ?>
                  <small class="text-muted"><?= htmlspecialchars($row['client_email']) ?></small>
                <?php endif; ?>
              </td>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($row['chama_name']) ?></div>
                <?php if (!empty($row['chama_phone'])): ?>
                  <small class="text-muted"><?= htmlspecialchars($row['chama_phone']) ?></small>
                <?php endif; ?>
              </td>
              <td class="text-end">KES <?= number_format((float)$row['base_amount'], 2) ?></td>
              <td class="text-end"><?= number_format((float)$row['rate'], 2) ?>%</td>
              <td class="text-end fw-semibold text-success">
                KES <?= number_format((float)$row['remittance_amount'], 2) ?>
              </td>
              <td><span class="badge <?= $badge ?>"><?= htmlspecialchars(ucfirst($row['status'])) ?></span></td>
              <td><?= htmlspecialchars($row['due_date'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['paid_date'] ?? '—') ?></td>
              <td><?= htmlspecialchars($row['reference_number'] ?? '—') ?></td>
              <td class="text-end">
                <?php if ($row['status'] === 'pending'): ?>
                  <form method="post" action="chama-remittance-action.php"
                        class="d-flex gap-1 justify-content-end align-items-center m-0"
                        onsubmit="return confirm('Confirm this remittance has been paid? The loan\'s interest will be reduced by the remittance amount.');">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <input type="text"
                          name="reference_number"
                          class="form-control form-control-sm"
                          placeholder="Ref (optional)"
                          style="max-width: 130px;">
                    <button type="submit" class="btn btn-outline-success btn-sm" title="Confirm payment">
                      <i class="bi bi-check2-circle" aria-hidden="true"></i> Mark paid
                    </button>
                  </form>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <?php if (!empty($history)): ?>
        <tfoot class="table-light">
          <tr>
            <th colspan="6" class="text-end">Grand Total</th>
            <th class="text-end text-success">KES <?= number_format($totalRemittance, 2) ?></th>
            <th colspan="5"></th>
          </tr>
        </tfoot>
      <?php endif; ?>
    </table>
  </div>
</section>

<script>
(() => {
  const $search = document.getElementById('historySearch');
  const $filter = document.getElementById('statusFilter');
  const $table  = document.getElementById('historyTable');
  if (!$table) return;

  const applyFilters = () => {
    const q      = ($search ? $search.value : '').trim().toLowerCase();
    const status = ($filter ? $filter.value : '').toLowerCase();

    $table.querySelectorAll('tbody tr').forEach(row => {
      const rowStatus = (row.dataset.status || '').toLowerCase();
      const haystack  = row.textContent.toLowerCase();

      const matchesText   = !q      || haystack.indexOf(q) !== -1;
      const matchesStatus = !status || rowStatus === status;

      row.style.display = (matchesText && matchesStatus) ? '' : 'none';
    });
  };

  if ($search) $search.addEventListener('input', applyChanges);
  if ($filter) $filter.addEventListener('change', applyChanges);

  function applyChanges() { applyFilters(); }
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';