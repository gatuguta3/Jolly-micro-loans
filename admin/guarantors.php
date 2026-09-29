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

<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-people" aria-hidden="true"></i>
        <span>All Guarantors</span>
      </h2>
      <p class="text-muted mb-0"><?= number_format(count($rows)) ?> guarantors on record.</p>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0">
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
              <td><?= (int) $g['id'] ?></td>
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

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';