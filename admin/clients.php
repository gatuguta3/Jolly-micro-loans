<?php
$pageTitle = 'Clients';
$activeNav = 'clients';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- FETCH CLIENTS ---------- */
$clients = [];
$sql = "SELECT id, client_code, full_name, email, phone, id_number, join_date
        FROM clients
        ORDER BY id DESC";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $clients[] = $row;
    }
    $result->free();
}

/* ---------- HELPER: initials avatar fallback ---------- */
if (!function_exists('client_avatar')) {
    function client_avatar($name) {
        $initial = strtoupper(substr(trim($name), 0, 1));
        return 'data:image/svg+xml;utf8,' . rawurlencode(
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48">'
          . '<rect width="100%" height="100%" fill="#e9ecef"/>'
          . '<text x="50%" y="55%" font-size="20" font-family="Arial" '
          . 'fill="#6c757d" text-anchor="middle" dominant-baseline="middle">'
          . $initial . '</text></svg>'
        );
    }
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-speedometer2" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Overview</p>
      <h1 class="h3 mb-1">Clients</h1>
      <p class="text-muted mb-0">Monitor clients, borrowing rate and client reports.</p>
    </div>
  </div>
  <div class="heading-actions">
    <button class="btn btn-outline-secondary btn-sm" type="button"><i class="bi bi-download"></i> Export</button>
    <button class="btn btn-primary btn-sm" type="button"><i class="bi bi-file-earmark-plus"></i> Create Report</button>
  </div>
</div>

<section class="row g-3 mt-1" aria-label="Clients metrics">
  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Clients</span>
        <span class="metric-icon"><i class="bi bi-people"></i></span>
      </div>
      <div class="metric-value"><?= number_format(count($clients)) ?></div>
      <div class="metric-meta"><span class="text-success">+5.1%</span><span>active users</span></div>
    </article>
  </div>
</section>

<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title"><i class="bi bi-people" aria-hidden="true"></i><span>Recent Users</span></h2>
      <p class="text-muted mb-0">Latest account activity across the workspace.</p>
    </div>
    <div class="heading-actions">
      <div class="d-flex justify-content-center gap-2">
            <input type="search"
                  class="form-control form-control-sm"
                  id="clientSearch"
                  placeholder="Search client..."
                  style="max-width: 220px;">
          
          </div>
      <button class="btn btn-outline-secondary btn-sm" type="button"><i class="bi bi-download" aria-hidden="true"></i> Export</button>
      <a class="btn btn-primary btn-sm" href="client-registration.php"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Client</a>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0" id="clientTable">
      <thead>
        <tr>
          <th scope="col">Client Id</th>
          <th scope="col">Client Name</th>
          <th scope="col">Id no</th>
          <th scope="col">Phone number</th>
          <th scope="col">Joined</th>
          <th scope="col" class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>

        <?php if (empty($clients)): ?>
          <tr>
            <td colspan="6" class="text-center text-muted py-4">
              No clients yet. Click <strong>Add Client</strong> to get started.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($clients as $c): ?>
            <tr>
              <td><a href="client-view.php?id=<?= (int) $c['id'] ?>" class="fw-semibold text-decoration-none"><?= htmlspecialchars($c['client_code']) ?></a></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img class="avatar-img avatar-sm"
                       src="<?= htmlspecialchars(client_avatar($c['full_name']), ENT_QUOTES, 'UTF-8') ?>"
                       alt="<?= htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8') ?>">
                  <div>
                    <p class="fw-semibold mb-0"><?= htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-muted small mb-0"><?= htmlspecialchars($c['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($c['id_number'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= htmlspecialchars($c['phone'], ENT_QUOTES, 'UTF-8') ?></td>
              <td><?= date('M j, Y', strtotime($c['join_date'])) ?></td>
              <td class="text-end">
                <a class="btn btn-light btn-sm"
                   href="client-view.php?id=<?= (int) $c['id'] ?>">View</a>
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
  const $search = document.getElementById('clientSearch');
  const $table  = document.getElementById('clientTable');
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