<?php
$pageTitle = 'Guarantors';
$activeNav = 'guarantors';
require_once __DIR__ . '/../config/db_connection.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$rows = [];
$res = $conn->query("SELECT id, name, email, phone, identification_number,
                            attachment_url, date_added, status
                     FROM guarantors ORDER BY id DESC");
if ($res) { while ($r = $res->fetch_assoc()) $rows[] = $r; $res->free(); }

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Management</p>
      <h1 class="h3 mb-1">Guarantors</h1>
      <p class="text-muted mb-0">Individuals backing client loans.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-primary btn-sm" href="guarantor-registration.php">
      <i class="bi bi-person-plus" aria-hidden="true"></i> Add Guarantor
    </a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title"><i class="bi bi-people"></i><span>All Guarantors</span></h2>
      <p class="text-muted mb-0"><?= number_format(count($rows)) ?> guarantors on record.</p>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead>
        <tr>
          <th>ID</th><th>Name</th><th>Email</th><th>Phone</th>
          <th>ID No</th><th>Photo</th><th>Date Added</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">
            No guarantors yet. Click <strong>Add Guarantor</strong>.
          </td></tr>
        <?php else: foreach ($rows as $g): ?>
          <tr>
            <td><?= (int) $g['id'] ?></td>
            <td><?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($g['email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($g['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($g['identification_number'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?php if (!empty($g['attachment_url'])): ?>
                <img src="../assets/images/guarantor-attachments/<?= htmlspecialchars(rawurlencode($g['attachment_url']), ENT_QUOTES, 'UTF-8') ?>"
                     class="avatar-img avatar-sm" alt="Photo">
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td><?= $g['date_added'] ? date('M j, Y', strtotime($g['date_added'])) : '—' ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';