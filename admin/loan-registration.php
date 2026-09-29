<?php
$pageTitle = 'Create Loan';
$activeNav = 'loans';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SESSION ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   LOAN CATEGORIES
   Monthly:  30-day term, tiered rate 20% / 18% / 15%
   Daily:    1-day term,  flat 2.5% interest
   Boost:    5-day term,  0% interest (Jolly Boost)

   Status transitions (based on 3× term validity):
     Monthly → overdue after 30 days, defaulted after 90 days
     Daily   → overdue after 1 day,  defaulted after 3 days
     Boost   → overdue after 5 days, defaulted after 15 days
---------------------------------------------------------------- */
$loanCategories = [
    'monthly' => [
        'label'         => 'Monthly Loan',
        'term_days'     => 30,
        'rate_type'     => 'tiered',
        'tiers'         => [
            ['min' => 5000,   'max' => 100000,  'rate' => 20.00],
            ['min' => 100001, 'max' => 500000,  'rate' => 18.00],
            ['min' => 500001, 'max' => 1000000, 'rate' => 15.00],
        ],
        'default_mult'  => 3,   // 90 days to default
    ],
    'daily' => [
        'label'         => 'Daily Loan',
        'term_days'     => 1,
        'rate_type'     => 'flat',
        'rate'          => 2.50,
        'default_mult'  => 3,   // 3 days to default
    ],
    'boost' => [
        'label'         => 'Jolly Boost Loan',
        'term_days'     => 5,
        'rate_type'     => 'flat',
        'rate'          => 0.00,
        'default_mult'  => 3,   // 15 days to default
    ],
];

$minPrincipal = 5000.00;
$maxPrincipal = 1000000.00;

/* ---------- RATE / TERM HELPERS ---------- */
function calc_category_rate(string $category, float $principal, array $categories): ?float {
    if (!isset($categories[$category])) return null;
    $cfg = $categories[$category];

    if ($cfg['rate_type'] === 'flat') {
        return (float) $cfg['rate'];
    }

    // tiered
    foreach ($cfg['tiers'] as $t) {
        if ($principal >= $t['min'] && $principal <= $t['max']) {
            return (float) $t['rate'];
        }
    }
    return null;
}

function calc_due_date(string $issueDate, int $termDays): string {
    $dt = new DateTime($issueDate);
    $dt->modify("+{$termDays} days");
    return $dt->format('Y-m-d');
}

/* ----------------------------------------------------------------
   UPLOAD CONFIG — assets/ is one level up from admin/
---------------------------------------------------------------- */
$uploadDirFs = __DIR__ . '/assets/images/loan-attachments/';
$maxBytes    = 10 * 1024 * 1024;   // 10 MB
$allowedMime = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/gif'       => 'gif',
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'application/pdf' => 'pdf',
];
if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0775, true);
}

/* ---------- HELPERS ---------- */
function gen_loan_code(mysqli $conn): string {
    $res  = $conn->query("SELECT MAX(id) AS max_id FROM loans");
    $row  = $res ? $res->fetch_assoc() : null;
    $next = ((int)($row['max_id'] ?? 0)) + 1;
    return 'LN-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
}

function save_loan_upload(array $file, string $fsDir, array $allowedMime, int $maxBytes): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'Upload failed (code ' . $file['error'] . ').'];
    if ($file['size'] > $maxBytes) return [null, 'File exceeds 10 MB: ' . htmlspecialchars($file['name'])];

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
    }
    if (!$mime || !isset($allowedMime[$mime])) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fallback = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif','mp4'=>'mp4','webm'=>'webm','pdf'=>'pdf'];
        if (!isset($fallback[$ext])) {
            return [null, 'Unsupported file: ' . htmlspecialchars($file['name'])];
        }
        $ext = $fallback[$ext];
    } else {
        $ext = $allowedMime[$mime];
    }

    if (!is_writable($fsDir)) {
        return [null, 'Upload folder is not writable.'];
    }

    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = rtrim($fsDir, '/\\') . DIRECTORY_SEPARATOR . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [null, 'Could not save: ' . htmlspecialchars($file['name'])];
    }
    return [$name, null];
}

/* ----------------------------------------------------------------
   FETCH DROPDOWN DATA
---------------------------------------------------------------- */
$clients = [];
$res = $conn->query("SELECT id, client_code, full_name, email FROM clients ORDER BY full_name ASC");
if ($res) { while ($r = $res->fetch_assoc()) $clients[] = $r; $res->free(); }

$guarantors = [];
$res = $conn->query("SELECT id, name, identification_number FROM guarantors ORDER BY name ASC");
if ($res) { while ($r = $res->fetch_assoc()) $guarantors[] = $r; $res->free(); }

/* ----------------------------------------------------------------
   HANDLE POST
---------------------------------------------------------------- */
$errors = [];
$form = [
    'client'        => '',
    'issueDate'     => date('Y-m-d'),
    'loanCategory'  => '',
    'principal'     => '',
    'guarantor'     => '',
    'notes'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($form as $k => $v) {
        $form[$k] = trim($_POST[$k] ?? $v);
    }

    /* ---- Validation ---- */
    $clientId = (int) $form['client'];
    if ($clientId <= 0) $errors[] = 'Please choose a client.';

    if ($form['issueDate'] === '' ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['issueDate'])) {
        $errors[] = 'Date of issue is invalid.';
    }

    if ($form['loanCategory'] === '' || !isset($loanCategories[$form['loanCategory']])) {
        $errors[] = 'Please choose a valid loan type.';
    }

    $principal = (float) $form['principal'];
    if ($principal < $minPrincipal) {
        $errors[] = 'Principal amount must be at least Ksh ' . number_format($minPrincipal, 0) . '.';
    } elseif ($principal > $maxPrincipal) {
        $errors[] = 'Principal amount cannot exceed Ksh ' . number_format($maxPrincipal, 0) . '.';
    }

    $guarantorId = (int) $form['guarantor'];
    if ($guarantorId <= 0) $errors[] = 'Please choose a guarantor.';

    /* ---- Confirm a rate exists for this category + principal ---- */
    $interestRate = null;
    if (empty($errors)) {
        $interestRate = calc_category_rate($form['loanCategory'], $principal, $loanCategories);
        if ($interestRate === null) {
            $errors[] = 'No interest rate matches that principal for the selected loan type.';
        }
    }

    /* ---- Verify client exists ---- */
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM clients WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $clientId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                $errors[] = 'Selected client no longer exists.';
            }
            $stmt->close();
        }
    }

    /* ---- Verify guarantor exists ---- */
    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM guarantors WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $guarantorId);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                $errors[] = 'Selected guarantor no longer exists.';
            }
            $stmt->close();
        }
    }

    /* ---- Handle collateral uploads ---- */
    $uploadedFiles = [];
    if (empty($errors) && !empty($_FILES['collateral']['name'][0])) {
        $count = count($_FILES['collateral']['name']);
        for ($i = 0; $i < $count; $i++) {
            $one = [
                'name'     => $_FILES['collateral']['name'][$i],
                'type'     => $_FILES['collateral']['type'][$i],
                'tmp_name' => $_FILES['collateral']['tmp_name'][$i],
                'error'    => $_FILES['collateral']['error'][$i],
                'size'     => $_FILES['collateral']['size'][$i],
            ];
            if ($one['error'] === UPLOAD_ERR_NO_FILE) continue;
            [$fname, $err] = save_loan_upload($one, $uploadDirFs, $allowedMime, $maxBytes);
            if ($err) $errors[] = 'Collateral: ' . $err;
            else      $uploadedFiles[] = $fname;
        }
    }

    /* ---- Insert ---- */
    if (empty($errors)) {
        $loanCode     = gen_loan_code($conn);
        $categoryCfg  = $loanCategories[$form['loanCategory']];
        $termDays     = (int) $categoryCfg['term_days'];
        $dueDate      = calc_due_date($form['issueDate'], $termDays);
        $uploadsJson  = !empty($uploadedFiles) ? json_encode($uploadedFiles) : null;
        $purpose      = $categoryCfg['label'];   // "Monthly Loan" / "Daily Loan" / "Jolly Boost Loan"
        $notes        = $form['notes'] !== '' ? $form['notes'] : null;

        $conn->begin_transaction();
        try {
            /* 1) Insert the loan */
            $sql = "INSERT INTO loans
                    (loan_code, client_id, date_issued,
                     principal_amount, interest_rate, amount_paid,
                     due_date, status, collateral_type,
                     uploads, purpose, notes)
                    VALUES (?, ?, ?, ?, ?, 0.00, ?, 'active', 'none', ?, ?, ?)";

            $stmt = $conn->prepare($sql);
            if (!$stmt) throw new Exception('Prepare loan failed: ' . $conn->error);

            $stmt->bind_param(
                "sisddssss",
                $loanCode,
                $clientId,
                $form['issueDate'],
                $principal,
                $interestRate,
                $dueDate,
                $uploadsJson,
                $purpose,
                $notes
            );

            if (!$stmt->execute()) {
                throw new Exception('Insert loan failed: ' . $stmt->error);
            }

            $newLoanId = (int) $stmt->insert_id;
            $stmt->close();

            /* 2) Link the guarantor (if the join table exists) */
            $hasJoin = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
            if ($hasJoin && $hasJoin->num_rows > 0) {

                // Adapt to either schema — with or without `guaranteed_amount`
                $hasAmountCol = false;
                $col = $conn->query("SHOW COLUMNS FROM loan_guarantors LIKE 'guaranteed_amount'");
                if ($col && $col->num_rows > 0) $hasAmountCol = true;

                if ($hasAmountCol) {
                    $link = $conn->prepare(
                        "INSERT INTO loan_guarantors (loan_id, guarantor_id, guaranteed_amount)
                         VALUES (?, ?, ?)"
                    );
                    if (!$link) throw new Exception('Prepare guarantor link failed: ' . $conn->error);
                    $guaranteedAmount = $principal;
                    $link->bind_param("iid", $newLoanId, $guarantorId, $guaranteedAmount);
                } else {
                    $link = $conn->prepare(
                        "INSERT INTO loan_guarantors (loan_id, guarantor_id)
                         VALUES (?, ?)"
                    );
                    if (!$link) throw new Exception('Prepare guarantor link failed: ' . $conn->error);
                    $link->bind_param("ii", $newLoanId, $guarantorId);
                }

                if (!$link->execute()) {
                    throw new Exception('Insert guarantor link failed: ' . $link->error);
                }
                $link->close();
            }

            $conn->commit();

            $_SESSION['flash'] = "Loan {$loanCode} registered successfully.";
            $conn->close();
            header("Location: loans.php");
            exit;

        } catch (Throwable $ex) {
            $conn->rollback();
            $errors[] = 'Could not save loan: ' . $ex->getMessage();
        }
    }
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Loan Management</p>
      <h1 class="h3 mb-1">Create New Loan</h1>
      <p class="text-muted mb-0">Register a new loan with client, guarantor, collateral, and repayment details.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="loans.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Loans
    </a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger" role="alert">
    <strong>Please fix the following:</strong>
    <ul class="mb-0 mt-2">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<section class="row g-3">
  <div class="col-12 col-xl-8">
    <form class="panel needs-validation" method="post" enctype="multipart/form-data" novalidate>
      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-cash-coin" aria-hidden="true"></i>
            <span>Loan Information</span>
          </h2>
          <p class="text-muted mb-0">Fill in the loan terms, client, guarantor, and collateral details.</p>
        </div>
      </div>

      <div class="row g-3">
        <!-- Client -->
        <div class="col-md-6">
          <label class="form-label" for="client">Client</label>
          <div class="input-group">
            <select class="form-select" id="client" name="client" required>
              <option value="">Choose registered client</option>
              <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>"
                  <?= ((string) $c['id'] === $form['client']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($c['client_code'] . ' — ' . $c['full_name'] . ' (' . ($c['email'] ?? 'no email') . ')', ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <a class="btn btn-outline-secondary" href="client-registration.php" title="Add new client">
              <i class="bi bi-person-plus" aria-hidden="true"></i>
            </a>
          </div>
          <div class="invalid-feedback">Please choose a client.</div>
        </div>

        <!-- Date of Issue -->
        <div class="col-md-6">
          <label class="form-label" for="issueDate">Date of Issue</label>
          <input class="form-control" id="issueDate" name="issueDate" type="date" required
                 value="<?= htmlspecialchars($form['issueDate'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Date of issue is required.</div>
        </div>

        <!-- Loan Type -->
        <div class="col-md-6">
          <label class="form-label" for="loanCategory">Loan Type</label>
          <select class="form-select" id="loanCategory" name="loanCategory" required>
            <option value="">Choose loan type</option>
            <option value="monthly" <?= $form['loanCategory']==='monthly' ? 'selected':'' ?>>Monthly Loan</option>
            <option value="daily"   <?= $form['loanCategory']==='daily'   ? 'selected':'' ?>>Daily Loan</option>
            <option value="boost"   <?= $form['loanCategory']==='boost'   ? 'selected':'' ?>>Jolly Boost Loan</option>
          </select>
          <div class="form-text" id="loanTypeHint">Select a loan type to see rate and term rules.</div>
          <div class="invalid-feedback">Choose a loan type.</div>
        </div>

        <!-- Principal Amount -->
        <div class="col-md-6">
          <label class="form-label" for="principal">Principal Amount (Ksh)</label>
          <input class="form-control" id="principal" name="principal" type="number"
                 min="5000" max="1000000" step="0.01" required
                 value="<?= htmlspecialchars($form['principal'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-text">
            Between Ksh 5,000 and Ksh 1,000,000.
          </div>
          <div class="invalid-feedback">Enter a principal between Ksh 5,000 and Ksh 1,000,000.</div>
        </div>

        <!-- Interest Rate (auto) -->
        <div class="col-md-6">
          <label class="form-label" for="interestRate">Interest Rate (%)</label>
          <input class="form-control" id="interestRate" name="interestRate" type="text" readonly>
          <div class="form-text">Automatically assigned based on the loan type.</div>
        </div>

        <!-- Total Due (auto) -->
        <div class="col-md-6">
          <label class="form-label" for="totalDue">Total Due (Ksh)</label>
          <input class="form-control" id="totalDue" name="totalDue" type="text" readonly>
          <div class="form-text">Principal + interest, calculated automatically.</div>
        </div>

        <!-- Due Date (auto) -->
        <div class="col-md-6">
          <label class="form-label" for="dueDate">Due Date</label>
          <input class="form-control" id="dueDate" name="dueDate" type="date" readonly>
          <div class="form-text">Based on loan type term and date of issue.</div>
        </div>

        <!-- Status (auto) -->
        <div class="col-md-6">
          <label class="form-label" for="status">Status</label>
          <input class="form-control" id="status" name="status" type="text" value="Pending" readonly>
          <div class="form-text">Status is assigned automatically.</div>
        </div>

        <!-- Guarantor -->
        <div class="col-md-6">
          <label class="form-label" for="guarantor">Guarantor</label>
          <div class="input-group">
            <select class="form-select" id="guarantor" name="guarantor" required>
              <option value="">Choose guarantor</option>
              <?php foreach ($guarantors as $g): ?>
                <option value="<?= (int) $g['id'] ?>"
                  <?= ((string) $g['id'] === $form['guarantor']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($g['name'] . ' (' . ($g['identification_number'] ?? '—') . ')', ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <a class="btn btn-outline-secondary" href="guarantor-registration.php" title="Add new guarantor">
              <i class="bi bi-person-plus" aria-hidden="true"></i>
            </a>
          </div>
          <div class="invalid-feedback">Please choose a guarantor.</div>
        </div>

        <!-- Collateral Upload -->
        <div class="col-12">
          <label class="form-label" for="collateral">Collateral (Media Upload)</label>
          <input class="form-control" id="collateral" name="collateral[]" type="file"
                 accept="image/*,video/*,application/pdf" multiple>
          <div class="form-text">Upload images or videos of the collateral. Accepted: JPG, PNG, GIF, MP4, PDF. Max 10 MB each.</div>
          <div id="collateralPreview" class="mt-2 d-flex flex-wrap gap-2"></div>
        </div>

        <!-- Notes -->
        <div class="col-12">
          <label class="form-label" for="notes">Additional Notes</label>
          <textarea class="form-control" id="notes" name="notes" rows="3"
                    placeholder="Any additional loan details or special conditions"><?= htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      </div>

      <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="loans.php">Cancel</a>
        <button class="btn btn-primary" type="submit">
          <i class="bi bi-check-circle" aria-hidden="true"></i> Create Loan
        </button>
      </div>
    </form>
  </div>

  <div class="col-12 col-xl-4">
    <div class="panel h-100">
      <h2 class="h5 mb-3 section-title">
        <i class="bi bi-list-check" aria-hidden="true"></i>
        <span>Loan Setup Checklist</span>
      </h2>
      <div class="activity-list">
        <div class="activity-item"><span class="activity-dot bg-success"></span><div><p class="mb-1 fw-semibold">Select registered client</p><p class="text-muted small mb-0">Only existing clients can be linked to a loan.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-primary"></span><div><p class="mb-1 fw-semibold">Choose loan type</p><p class="text-muted small mb-0">Monthly (tiered), Daily (2.5%), or Jolly Boost (0%).</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-warning"></span><div><p class="mb-1 fw-semibold">Assign guarantor</p><p class="text-muted small mb-0">Pick an existing guarantor or create a new one.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-info"></span><div><p class="mb-1 fw-semibold">Upload collateral</p><p class="text-muted small mb-0">Attach photos or videos of the pledged asset.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-secondary"></span><div><p class="mb-1 fw-semibold">Review & submit</p><p class="text-muted small mb-0">Verify auto-calculated amounts before saving.</p></div></div>
      </div>
    </div>
  </div>
</section>

<script>
(() => {
  /* Config from PHP — single source of truth */
  const LOAN_CATEGORIES = <?= json_encode($loanCategories, JSON_NUMERIC_CHECK) ?>;
  const MIN_PRINCIPAL   = <?= (float) $minPrincipal ?>;
  const MAX_PRINCIPAL   = <?= (float) $maxPrincipal ?>;

  const $client     = document.getElementById('client');
  const $issueDate  = document.getElementById('issueDate');
  const $category   = document.getElementById('loanCategory');
  const $principal  = document.getElementById('principal');
  const $rate       = document.getElementById('interestRate');
  const $total      = document.getElementById('totalDue');
  const $due        = document.getElementById('dueDate');
  const $status     = document.getElementById('status');
  const $hint       = document.getElementById('loanTypeHint');
  const $collateral = document.getElementById('collateral');
  const $preview    = document.getElementById('collateralPreview');

  function fmtKsh(n) {
    return 'Ksh ' + Number(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function categoryHint(catKey) {
    const cfg = LOAN_CATEGORIES[catKey];
    if (!cfg) return 'Select a loan type to see rate and term rules.';
    if (cfg.rate_type === 'tiered') {
      const tiers = cfg.tiers.map(t =>
        `${(t.min/1000).toFixed(0)}k–${(t.max/1000).toFixed(0)}k → ${t.rate}%`
      ).join(' · ');
      return `Term: ${cfg.term_days} days. Rate tiers: ${tiers}`;
    }
    return `Term: ${cfg.term_days} day${cfg.term_days === 1 ? '' : 's'}. Flat rate: ${cfg.rate}%.`;
  }

  function calcRate(catKey, amount) {
    const cfg = LOAN_CATEGORIES[catKey];
    if (!cfg) return 0;

    if (cfg.rate_type === 'flat') return cfg.rate;

    for (const t of cfg.tiers) {
      if (amount >= t.min && amount <= t.max) return t.rate;
    }
    return 0;
  }

  function refresh() {
    const amount   = parseFloat($principal.value) || 0;
    const catKey   = $category.value;

    // Hint under the dropdown
    $hint.textContent = categoryHint(catKey);

    if (!catKey || amount < MIN_PRINCIPAL || amount > MAX_PRINCIPAL) {
      $rate.value   = '';
      $total.value  = '';
      $due.value    = '';
      $status.value = 'Pending';
      return;
    }

    const cfg  = LOAN_CATEGORIES[catKey];
    const rate = calcRate(catKey, amount);
    const total = amount * (1 + rate / 100);

    // Rate field
    if (cfg.rate_type === 'flat' && rate === 0) {
      $rate.value = '0.00 (no interest)';
    } else {
      $rate.value = rate.toFixed(2);
    }

    $total.value = total.toFixed(2);

    // Due date
    if ($issueDate.value && cfg.term_days > 0) {
      const d = new Date($issueDate.value);
      d.setDate(d.getDate() + cfg.term_days);
      $due.value = d.toISOString().slice(0, 10);
    } else {
      $due.value = '';
    }

    $status.value = ($client.value && rate >= 0) ? 'Active' : 'Pending';
  }

  [$principal, $issueDate, $category, $client].forEach(el => {
    if (el) el.addEventListener('input', refresh);
    if (el) el.addEventListener('change', refresh);
  });
  refresh();

  /* Collateral preview */
  if ($collateral && $preview) {
    $collateral.addEventListener('change', () => {
      $preview.innerHTML = '';
      Array.from($collateral.files).forEach(f => {
        const url = URL.createObjectURL(f);
        const html = f.type.startsWith('image/')
          ? `<img src="${url}" class="img-thumbnail" style="max-height:100px" alt="Preview">`
          : f.type.startsWith('video/')
            ? `<video src="${url}" class="img-thumbnail" style="max-height:100px" controls></video>`
            : `<span class="badge text-bg-secondary">${f.name}</span>`;
        $preview.insertAdjacentHTML('beforeend', html);
      });
    });
  }

  /* Bootstrap validation */
  document.querySelectorAll('.needs-validation').forEach(form => {
    form.addEventListener('submit', e => {
      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';