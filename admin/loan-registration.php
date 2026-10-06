<?php
$pageTitle = 'Create Loan';
$activeNav = 'loans';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SHARED LOAN CONFIG ---------- */
require_once __DIR__ . '/loan-config.php';

/* ---------- SESSION ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   OPTIONAL: preselect client from ?id=X
---------------------------------------------------------------- */
$preselectedClientId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($preselectedClientId > 0) {
    $_SESSION['preselected_client_id'] = $preselectedClientId;
}
$defaultClientId = $_SESSION['preselected_client_id'] ?? 0;

/* ---------- RATE / TERM HELPERS ---------- */
function calc_base_rate(string $category, float $principal, array $categories): ?float {
    if (!isset($categories[$category])) return null;
    $cfg = $categories[$category];

    if ($cfg['rate_type'] === 'flat') {
        return (float) $cfg['rate'];
    }

    foreach ($cfg['tiers'] as $t) {
        if ($principal >= $t['min'] && $principal <= $t['max']) {
            return (float) $t['rate'];
        }
    }
    return null;
}

function calc_effective_rate(string $category, float $principal, int $duration, array $categories): ?float {
    $base = calc_base_rate($category, $principal, $categories);
    if ($base === null) return null;
    return round($base * $duration, 4);
}

function calc_due_date(string $issueDate, string $category, int $duration, array $categories): string {
    $dt  = new DateTime($issueDate);
    $cfg = $categories[$category] ?? ['duration_unit' => 'days'];

    if ($cfg['duration_unit'] === 'months') {
        $dt->modify("+{$duration} months");
    } else {
        $dt->modify("+{$duration} days");
    }
    return $dt->format('Y-m-d');
}

/* ----------------------------------------------------------------
   LOAN LIMIT CHECK
---------------------------------------------------------------- */
function check_loan_limit(mysqli $conn, int $clientId, string $category, array $limits): array {
    if (!isset($limits[$category])) {
        return [true, null, 0, 0];
    }

    $max = (int) $limits[$category];
    if ($max <= 0) {
        return [true, null, 0, 0];
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM loans
         WHERE client_id = ?
           AND loan_category = ?
           AND status NOT IN ('cleared','written_off')"
    );
    if (!$stmt) {
        return [true, null, 0, $max];
    }

    $stmt->bind_param("is", $clientId, $category);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $current = (int) ($row['cnt'] ?? 0);

    if ($current >= $max) {
        $label = ucfirst($category);
        $msg = "This client already holds {$current} open {$label} loan"
             . ($current === 1 ? '' : 's')
             . ". Maximum allowed: {$max}.";
        return [false, $msg, $current, $max];
    }

    return [true, null, $current, $max];
}

/* ----------------------------------------------------------------
   UPLOAD CONFIG
---------------------------------------------------------------- */
$uploadDirFs = __DIR__ . '/assets/images/loan-attachments/';
$maxBytes    = 10 * 1024 * 1024;
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

/* Per-client category counts */
$clientUsage = [];
$res = $conn->query(
    "SELECT client_id, loan_category, COUNT(*) AS cnt
     FROM loans
     WHERE status NOT IN ('cleared','written_off')
     GROUP BY client_id, loan_category"
);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $clientUsage[(int) $r['client_id']][$r['loan_category']] = (int) $r['cnt'];
    }
    $res->free();
}

/* ----------------------------------------------------------------
   INITIAL FORM STATE
---------------------------------------------------------------- */
$errors = [];
$form = [
    'client'        => $defaultClientId > 0 ? (string) $defaultClientId : '',
    'issueDate'     => date('Y-m-d'),
    'loanCategory'  => '',
    'duration'      => '',
    'principal'     => '',
    'guarantor'     => '',
    'notes'         => '',
];

/* ----------------------------------------------------------------
   HANDLE POST
---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($form as $k => $v) {
        $form[$k] = trim($_POST[$k] ?? $v);
    }

    if ((int) $form['client'] > 0) {
        $_SESSION['preselected_client_id'] = (int) $form['client'];
    }

    /* ---- Validation ---- */
    $clientId = (int) $form['client'];
    if ($clientId <= 0) $errors[] = 'Please choose a client.';

    if ($form['issueDate'] === '' ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['issueDate'])) {
        $errors[] = 'Date of issue is invalid.';
    } elseif ($form['issueDate'] > date('Y-m-d')) {
        $errors[] = 'Date of issue cannot be in the future.';
    }

    $categoryValid = $form['loanCategory'] !== '' && isset($loanCategories[$form['loanCategory']]);
    if (!$categoryValid) {
        $errors[] = 'Please choose a valid loan type.';
    }

    $duration = (int) $form['duration'];
    if ($categoryValid) {
        $cfg = $loanCategories[$form['loanCategory']];
        if ($duration < $cfg['duration_min'] || $duration > $cfg['duration_max']) {
            $errors[] = 'Duration must be between '
                      . $cfg['duration_min'] . ' and ' . $cfg['duration_max']
                      . ' ' . strtolower($cfg['duration_label']) . '.';
        }
    }

    $principal = (float) $form['principal'];
    if ($principal < $minPrincipal) {
        $errors[] = 'Principal amount must be at least Ksh ' . number_format($minPrincipal, 0) . '.';
    } elseif ($principal > $maxPrincipal) {
        $errors[] = 'Principal amount cannot exceed Ksh ' . number_format($maxPrincipal, 0) . '.';
    }

    /* ---- Guarantor OR Collateral rule ---- */
    $guarantorId = (int) $form['guarantor'];

    $hasCollateral = !empty($_FILES['collateral']['name'][0])
                  && ($_FILES['collateral']['error'][0] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $hasGuarantor  = $guarantorId > 0;

    if (!$hasGuarantor && !$hasCollateral) {
        $errors[] = 'Please attach at least one collateral file or choose a guarantor.';
    }

    /* ---- Loan limit check ---- */
    if (empty($errors) && $clientId > 0 && $categoryValid) {
        [$allowed, $limitMsg] = check_loan_limit($conn, $clientId, $form['loanCategory'], $loanLimits);
        if (!$allowed) {
            $errors[] = $limitMsg;
        }
    }

    /* ---- Compute base rate ---- */
    $interestRate = null;
    if (empty($errors)) {
        if ($form['loanCategory'] === 'monthly') {
            /* Monthly: bill ONLY month 1 at registration */
            $interestRate = calc_base_rate('monthly', $principal, $loanCategories);
        } else {
            $interestRate = calc_effective_rate($form['loanCategory'], $principal, $duration, $loanCategories);
        }

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

    /* ---- Verify guarantor exists (only when provided) ---- */
    if (empty($errors) && $guarantorId > 0) {
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

    /* ---- Collateral uploads ---- */
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
        $dueDate      = calc_due_date($form['issueDate'], $form['loanCategory'], $duration, $loanCategories);
        $uploadsJson  = !empty($uploadedFiles) ? json_encode($uploadedFiles) : null;
        $purpose      = $categoryCfg['label'];
        $notes        = $form['notes'] !== '' ? $form['notes'] : null;

        /* ---- Month 1 interest (billed at registration) ---- */
        $interestAmount = round($principal * $interestRate / 100, 2);

        /* ---- Checkpoint = issue date + 1 month (month 1 already billed) ---- */
        $accrualStart = new DateTime($form['issueDate']);
        $accrualStart->modify('+1 month');
        $lastAccrualDate = $accrualStart->format('Y-m-d');

        $conn->begin_transaction();
        try {
           $sql = "INSERT INTO loans
        (loan_code, client_id, loan_category, date_issued,
         principal_amount, principal_balance,
         interest_rate, interest_amount, interest_balance,
         amount_paid,
         due_date, duration, last_accrual_date,
         status, collateral_type, uploads, purpose, notes)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, ?, ?, ?, 'active', 'none', ?, ?, ?)";

$stmt = $conn->prepare($sql);
if (!$stmt) throw new Exception('Prepare loan failed: ' . $conn->error);

$stmt->bind_param(
    "sissdddddsissss",
    $loanCode,
    $clientId,
    $form['loanCategory'],
    $form['issueDate'],
    $principal,
    $principal,  /* principal_balance = principal at registration */
    $interestRate,
    $interestAmount,
    $interestAmount,  /* interest_balance = month 1 interest at registration */
    $dueDate,
    $duration,
    $lastAccrualDate,
    $uploadsJson,
    $purpose,
    $notes
);

            if (!$stmt->execute()) {
                throw new Exception('Insert loan failed: ' . $stmt->error);
            }

            $newLoanId = (int) $stmt->insert_id;
            $stmt->close();

            /* Link guarantor (only when one was chosen) */
            if ($guarantorId > 0) {
                $hasJoin = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
                if ($hasJoin && $hasJoin->num_rows > 0) {
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
            }

            $conn->commit();

            unset($_SESSION['preselected_client_id']);

            $_SESSION['flash'] = "Loan {$loanCode} registered successfully "
                              . "({$duration} " . strtolower($categoryCfg['duration_label']) . ").";
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
            <button class="btn btn-outline-secondary" type="button"
                    data-bs-toggle="modal" data-bs-target="#clientQuickModal"
                    title="Add new client">
              <i class="bi bi-person-plus" aria-hidden="true"></i>
            </button>
          </div>
          <div class="invalid-feedback">Please choose a client.</div>
          <div class="form-text" id="clientLimitHint"></div>
        </div>

        <!-- Date of Issue -->
        <div class="col-md-6">
          <label class="form-label" for="issueDate">Date of Issue</label>
          <input class="form-control" id="issueDate" name="issueDate" type="date" required
                 max="<?= date('Y-m-d') ?>"
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

        <!-- Duration -->
        <div class="col-md-6">
          <label class="form-label" for="duration">
            Duration (<span id="durationUnitLabel">—</span>)
          </label>
          <input class="form-control" id="duration" name="duration" type="number"
                 min="1" max="3" step="1" required
                 value="<?= htmlspecialchars($form['duration'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-text" id="durationHint">Select a loan type first.</div>
          <div class="invalid-feedback">Enter a valid duration.</div>
        </div>

        <!-- Principal Amount -->
        <div class="col-md-6">
          <label class="form-label" for="principal">Principal Amount (Ksh)</label>
          <input class="form-control" id="principal" name="principal" type="number"
                 min="5000" max="1000000" step="0.01" required
                 value="<?= htmlspecialchars($form['principal'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-text">Between Ksh 5,000 and Ksh 1,000,000.</div>
          <div class="invalid-feedback">Enter a principal between Ksh 5,000 and Ksh 1,000,000.</div>
        </div>

        <!-- Interest Rate (auto) -->
        <div class="col-md-6">
          <label class="form-label" for="interestRate">Interest Rate (%)</label>
          <input class="form-control" id="interestRate" name="interestRate" type="text" readonly>
          <div class="form-text">For monthly loans, only the first month is billed at registration.</div>
        </div>

        <!-- Total Due (auto) -->
        <div class="col-md-6">
          <label class="form-label" for="totalDue">Total Due (Ksh)</label>
          <input class="form-control" id="totalDue" name="totalDue" type="text" readonly>
          <div class="form-text" id="totalDueHint">Principal + interest, calculated automatically.</div>
        </div>

        <!-- Due Date (auto) -->
        <div class="col-md-6">
          <label class="form-label" for="dueDate">Due Date</label>
          <input class="form-control" id="dueDate" name="dueDate" type="date" readonly>
          <div class="form-text">Based on loan type duration and date of issue.</div>
        </div>

        <!-- Status (auto) -->
        <div class="col-md-6">
          <label class="form-label" for="status">Status</label>
          <input class="form-control" id="status" name="status" type="text" value="Pending" readonly>
          <div class="form-text">Status is assigned automatically.</div>
        </div>

        <!-- Guarantor -->
        <div class="col-md-6">
          <label class="form-label" for="guarantor">
            Guarantor <span class="text-muted small">(optional if collateral provided)</span>
          </label>
          <div class="input-group">
            <select class="form-select" id="guarantor" name="guarantor">
              <option value="">Choose guarantor</option>
              <?php foreach ($guarantors as $g): ?>
                <option value="<?= (int) $g['id'] ?>"
                  <?= ((string) $g['id'] === $form['guarantor']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($g['name'] . ' (' . ($g['identification_number'] ?? '—') . ')', ENT_QUOTES, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-outline-secondary" type="button"
                    data-bs-toggle="modal" data-bs-target="#guarantorQuickModal"
                    title="Add new guarantor">
              <i class="bi bi-person-plus" aria-hidden="true"></i>
            </button>
          </div>
        </div>

        <!-- Collateral Upload -->
        <div class="col-12">
          <label class="form-label" for="collateral">Collateral (Media Upload)</label>
          <input class="form-control" id="collateral" name="collateral[]" type="file"
                 accept="image/*,video/*,application/pdf" multiple>
          <div class="form-text">
            Upload images or videos of the collateral. Accepted: JPG, PNG, GIF, MP4, PDF. Max 10 MB each.<br>
            <strong>Provide collateral or a guarantor — at least one is required.</strong>
          </div>
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
        <div class="activity-item"><span class="activity-dot bg-success"></span><div><p class="mb-1 fw-semibold">Select registered client</p><p class="text-muted small mb-0">Or add a new client with the + button.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-primary"></span><div><p class="mb-1 fw-semibold">Choose loan type &amp; duration</p><p class="text-muted small mb-0">Monthly (1–3 mo), Daily (1–7 d), Jolly Boost (fixed 5 d).</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-warning"></span><div><p class="mb-1 fw-semibold">Assign guarantor or attach collateral</p><p class="text-muted small mb-0">At least one of the two is required.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-info"></span><div><p class="mb-1 fw-semibold">Upload collateral</p><p class="text-muted small mb-0">Attach photos or videos of the pledged asset.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-secondary"></span><div><p class="mb-1 fw-semibold">Review & submit</p><p class="text-muted small mb-0">Verify auto-calculated amounts before saving.</p></div></div>
      </div>
    </div>
  </div>
</section>

<!-- ================================================================
     MODAL: Quick-add CLIENT
================================================================= -->
<div class="modal fade" id="clientQuickModal" tabindex="-1"
     aria-labelledby="clientQuickModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="clientQuickModalLabel">
          <i class="bi bi-person-plus" aria-hidden="true"></i> Add Client
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="clientQuickForm" autocomplete="off">
        <div class="modal-body">
          <div class="alert alert-danger d-none" id="clientQuickErrors"></div>

          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="cqFirstName">First name <span class="text-danger">*</span></label>
              <input class="form-control" id="cqFirstName" name="firstName" type="text" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="cqLastName">Last name <span class="text-danger">*</span></label>
              <input class="form-control" id="cqLastName" name="lastName" type="text" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="cqEmail">Email <span class="text-danger">*</span></label>
              <input class="form-control" id="cqEmail" name="email" type="email" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="cqPhone">Phone <span class="text-danger">*</span></label>
              <input class="form-control" id="cqPhone" name="phone" type="tel" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="cqIdNo">ID Number <span class="text-danger">*</span></label>
              <input class="form-control" id="cqIdNo" name="idNo" type="text" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="cqLocation">Location</label>
              <input class="form-control" id="cqLocation" name="location" type="text"
                     placeholder="Town / County">
            </div>
            <div class="col-12">
              <label class="form-label" for="cqPhoto">Photo</label>
              <input class="form-control" id="cqPhoto" name="photo" type="file"
                     accept="image/jpeg,image/png,image/webp,image/gif">
              <div class="form-text">JPG, PNG, WEBP, GIF. Max 8 MB.</div>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary"
                  data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="cqSubmit">
            <span class="spinner-border spinner-border-sm d-none" role="status"
                  aria-hidden="true" id="cqSpinner"></span>
            <i class="bi bi-check2-circle" aria-hidden="true" id="cqSubmitIcon"></i>
            Save Client
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ================================================================
     MODAL: Quick-add GUARANTOR
================================================================= -->
<div class="modal fade" id="guarantorQuickModal" tabindex="-1"
     aria-labelledby="guarantorQuickModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="guarantorQuickModalLabel">
          <i class="bi bi-person-plus" aria-hidden="true"></i> Add Guarantor
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <form id="guarantorQuickForm" autocomplete="off">
        <div class="modal-body">
          <div class="alert alert-danger d-none" id="guarantorQuickErrors"></div>

          <div class="row g-3">
            <div class="col-12">
              <label class="form-label" for="gqName">Guarantor Name <span class="text-danger">*</span></label>
              <input class="form-control" id="gqName" name="name" type="text" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="gqPhone">Phone <span class="text-danger">*</span></label>
              <input class="form-control" id="gqPhone" name="phone" type="tel" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="gqIdType">ID Type</label>
              <select class="form-select" id="gqIdType" name="idType">
                <option value="id_no">ID No</option>
                <option value="passport">Passport</option>
                <option value="other">Other Document</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="gqIdNo">ID Number <span class="text-danger">*</span></label>
              <input class="form-control" id="gqIdNo" name="idNo" type="text" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="gqType">Type</label>
              <select class="form-select" id="gqType" name="type">
                <option value="individual">Individual</option>
                <option value="chama">Chama</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label" for="gqEmail">Email</label>
              <input class="form-control" id="gqEmail" name="email" type="email">
            </div>
            <div class="col-12">
              <label class="form-label" for="gqPhoto">Photo / ID Scan</label>
              <input class="form-control" id="gqPhoto" name="photo" type="file"
                     accept="image/*,application/pdf">
              <div class="form-text">JPG, PNG, WEBP, GIF or PDF. Max 5 MB.</div>
            </div>
            <div class="col-12">
              <label class="form-label" for="gqNotes">Notes</label>
              <textarea class="form-control" id="gqNotes" name="notes" rows="2"></textarea>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary"
                  data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="gqSubmit">
            <span class="spinner-border spinner-border-sm d-none" role="status"
                  aria-hidden="true" id="gqSpinner"></span>
            <i class="bi bi-check2-circle" aria-hidden="true" id="gqSubmitIcon"></i>
            Save Guarantor
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  /* ================================================================
     CONFIG from PHP
  ================================================================ */
  const LOAN_CATEGORIES = <?= json_encode($loanCategories, JSON_NUMERIC_CHECK) ?>;
  const LOAN_LIMITS     = <?= json_encode($loanLimits, JSON_NUMERIC_CHECK) ?>;
  const CLIENT_USAGE    = <?= json_encode($clientUsage ?: new stdClass(), JSON_NUMERIC_CHECK) ?>;
  const MIN_PRINCIPAL   = <?= (float) $minPrincipal ?>;
  const MAX_PRINCIPAL   = <?= (float) $maxPrincipal ?>;

  const $client        = document.getElementById('client');
  const $issueDate     = document.getElementById('issueDate');
  const $category      = document.getElementById('loanCategory');
  const $duration      = document.getElementById('duration');
  const $durationUnit  = document.getElementById('durationUnitLabel');
  const $durationHint  = document.getElementById('durationHint');
  const $principal     = document.getElementById('principal');
  const $rate          = document.getElementById('interestRate');
  const $total         = document.getElementById('totalDue');
  const $totalHint     = document.getElementById('totalDueHint');
  const $due           = document.getElementById('dueDate');
  const $status        = document.getElementById('status');
  const $hint          = document.getElementById('loanTypeHint');
  const $clientLimit   = document.getElementById('clientLimitHint');
  const $collateral    = document.getElementById('collateral');
  const $preview       = document.getElementById('collateralPreview');
  const $submit        = document.querySelector('form.needs-validation button[type="submit"]');

  function categoryHint(catKey) {
    const cfg = LOAN_CATEGORIES[catKey];
    if (!cfg) return 'Select a loan type to see rate and term rules.';
    if (cfg.rate_type === 'tiered') {
      const tiers = cfg.tiers.map(t =>
        `${(t.min/1000).toFixed(0)}k–${(t.max/1000).toFixed(0)}k → ${t.rate}%`
      ).join(' · ');
      return `Monthly loan. Rate tiers: ${tiers} per month. Duration: 1–3 months.`;
    }
    if (catKey === 'boost') {
      return `Jolly Boost. 0% interest for the fixed 5-day term.`;
    }
    return `Daily loan. Flat ${cfg.rate}% per day. Duration: 1–7 days.`;
  }

  function baseRate(catKey, amount) {
    const cfg = LOAN_CATEGORIES[catKey];
    if (!cfg) return 0;
    if (cfg.rate_type === 'flat') return cfg.rate;
    for (const t of cfg.tiers) {
      if (amount >= t.min && amount <= t.max) return t.rate;
    }
    return 0;
  }

  function usageFor(clientId, catKey) {
    if (!clientId || !catKey) return 0;
    const c = CLIENT_USAGE[clientId];
    if (!c) return 0;
    return c[catKey] || 0;
  }

  function refreshClientLimitHint() {
    const clientId = $client.value;
    if (!clientId) {
      $clientLimit.textContent = '';
      $clientLimit.classList.remove('text-danger', 'text-success');
      return;
    }

    const usage = CLIENT_USAGE[clientId] || {};
    const parts = [];

    for (const catKeyName of ['monthly','daily','boost']) {
      const limit = LOAN_LIMITS[catKeyName] || 0;
      if (limit <= 0) continue;
      const used  = usage[catKeyName] || 0;
      const label = LOAN_CATEGORIES[catKeyName] ? LOAN_CATEGORIES[catKeyName].label.split(' ')[0] : catKeyName;
      const hit   = used >= limit;
      parts.push(`<span class="${hit ? 'text-danger fw-semibold' : 'text-muted'}">${label}: ${used}/${limit}</span>`);
    }

    $clientLimit.innerHTML = parts.length ? 'Open loans — ' + parts.join(' · ') : '';
  }

  let lastDurationCategory = '';

  function refreshDurationField(catKey) {
    const cfg = LOAN_CATEGORIES[catKey];

    if (!cfg) {
      $duration.disabled = false;
      $duration.readOnly = false;
      $duration.removeAttribute('min');
      $duration.removeAttribute('max');
      $durationUnit.textContent = '—';
      $durationHint.textContent = 'Enter any value — it will be validated once you pick a loan type.';
      lastDurationCategory = '';
      return;
    }

    $duration.disabled = false;
    $duration.readOnly = false;
    $duration.min = cfg.duration_min;
    $duration.max = cfg.duration_max;

    if (catKey !== lastDurationCategory) {
      let val = parseInt($duration.value, 10);
      if (isNaN(val) || val < cfg.duration_min || val > cfg.duration_max) {
        $duration.value = cfg.duration_default;
      }
      lastDurationCategory = catKey;
    }

    $durationUnit.textContent = cfg.duration_label;

    if (cfg.duration_min === cfg.duration_max) {
      $durationHint.textContent = `Fixed at ${cfg.duration_min} ${cfg.duration_label.toLowerCase()}.`;
    } else {
      $durationHint.textContent = `Between ${cfg.duration_min} and ${cfg.duration_max} ${cfg.duration_label.toLowerCase()}.`;
    }
  }

  function refresh() {
    const amount = parseFloat($principal.value) || 0;
    const catKey = $category.value;

    $hint.textContent = categoryHint(catKey);
    refreshDurationField(catKey);
    refreshClientLimitHint();

    if ($client.value && catKey) {
      const limit = LOAN_LIMITS[catKey] || 0;
      const used  = usageFor($client.value, catKey);
      const capped = limit > 0 && used >= limit;
      if ($submit) $submit.disabled = capped;
      if (capped) {
        $hint.innerHTML = `<span class="text-danger fw-semibold">${LOAN_CATEGORIES[catKey].label} limit reached for this client (${used}/${limit}).</span>`;
      }
    } else {
      if ($submit) $submit.disabled = false;
    }

    if (!catKey) {
      $rate.value = ''; $total.value = ''; $due.value = '';
      $status.value = 'Pending';
      return;
    }

    const cfg = LOAN_CATEGORIES[catKey];
    const duration = parseInt($duration.value, 10) || 0;

    if (duration < cfg.duration_min || duration > cfg.duration_max ||
        amount < MIN_PRINCIPAL || amount > MAX_PRINCIPAL) {
      $rate.value = ''; $total.value = ''; $due.value = '';
      $status.value = 'Pending';
      return;
    }

    const base = baseRate(catKey, amount);
    let effRate;
    let rateDisplay;

    if (catKey === 'monthly') {
      effRate = base;
      rateDisplay = base.toFixed(2) + ' (per month)';
    } else if (catKey === 'boost' || base === 0) {
      effRate = 0;
      rateDisplay = '0.00 (no interest)';
    } else if (duration > 1) {
      effRate = base * duration;
      rateDisplay = `${base.toFixed(2)} × ${duration} = ${effRate.toFixed(2)}`;
    } else {
      effRate = base;
      rateDisplay = base.toFixed(2);
    }

    const total = amount * (1 + effRate / 100);
    $rate.value  = rateDisplay;
    $total.value = total.toFixed(2);

    if ($totalHint) {
      $totalHint.textContent = (catKey === 'monthly')
        ? 'First month only. Additional months accrue as time passes.'
        : 'Principal + interest, calculated automatically.';
    }

    if ($issueDate.value) {
      const d = new Date($issueDate.value);
      if (cfg.duration_unit === 'months') {
        d.setMonth(d.getMonth() + duration);
      } else {
        d.setDate(d.getDate() + duration);
      }
      $due.value = d.toISOString().slice(0, 10);
    } else {
      $due.value = '';
    }

    $status.value = ($client.value && total > 0) ? 'Active' : 'Pending';
  }

  [$principal, $issueDate, $category, $duration, $client].forEach(el => {
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

  /* Live-clear either/or alert */
  const $guarantorSel    = document.getElementById('guarantor');
  const $collateralInput = document.getElementById('collateral');
  const clearEitherOr = () => {
    const a = document.getElementById('eitherOrAlert');
    if (a) a.remove();
  };
  if ($guarantorSel)    $guarantorSel.addEventListener('change', clearEitherOr);
  if ($collateralInput) $collateralInput.addEventListener('change', clearEitherOr);

  /* Bootstrap validation + either/or rule */
  document.querySelectorAll('.needs-validation').forEach(form => {
    form.addEventListener('submit', e => {
      const guarantorVal    = document.getElementById('guarantor').value;
      const collateralInput = document.getElementById('collateral');
      const hasCollateral   = collateralInput.files && collateralInput.files.length > 0;
      const hasGuarantor    = guarantorVal && guarantorVal !== '';

      if (!hasGuarantor && !hasCollateral) {
        e.preventDefault();
        e.stopPropagation();

        let alert = document.getElementById('eitherOrAlert');
        if (!alert) {
          alert = document.createElement('div');
          alert.id = 'eitherOrAlert';
          alert.className = 'alert alert-danger mt-3';
          alert.innerHTML = '<strong>Missing information:</strong> attach at least one collateral file or choose a guarantor.';
          form.appendChild(alert);
        }
        alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
      } else {
        const existing = document.getElementById('eitherOrAlert');
        if (existing) existing.remove();
      }

      if (!form.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });

  /* ================================================================
     Helpers
  ================================================================ */
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  async function postForm(url, formData) {
    const res = await fetch(url, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });
    try { return await res.json(); }
    catch { throw new Error('Server returned invalid response.'); }
  }

  /* ================================================================
     CLIENT quick-add modal
  ================================================================ */
  (() => {
    const $modal    = document.getElementById('clientQuickModal');
    const $form     = document.getElementById('clientQuickForm');
    const $select   = document.getElementById('client');
    const $errors   = document.getElementById('clientQuickErrors');
    const $submit   = document.getElementById('cqSubmit');
    const $spinner  = document.getElementById('cqSpinner');
    const $submitIc = document.getElementById('cqSubmitIcon');

    if (!$modal || !$form || !$select) return;

    $modal.addEventListener('show.bs.modal', () => {
      $form.reset();
      $form.classList.remove('was-validated');
      $errors.classList.add('d-none');
      $errors.innerHTML = '';
    });

    $form.addEventListener('submit', async (e) => {
      e.preventDefault();

      if (!$form.checkValidity()) {
        $form.classList.add('was-validated');
        return;
      }

      $submit.disabled = true;
      $spinner.classList.remove('d-none');
      $submitIc.classList.add('d-none');
      $errors.classList.add('d-none');

      try {
        const data = await postForm('client-quick-add.php', new FormData($form));

        if (!data.ok) {
          const list = (data.errors || ['Failed to save client.'])
            .map(er => `<div>• ${escapeHtml(er)}</div>`).join('');
          $errors.innerHTML = list;
          $errors.classList.remove('d-none');
          return;
        }

        let opt = $select.querySelector(`option[value="${data.id}"]`);
        if (!opt) {
          opt = document.createElement('option');
          opt.value = data.id;
          opt.textContent = `${data.code} — ${data.name} (${data.email})`;
          $select.appendChild(opt);
        }
        opt.selected = true;

        const bsModal = bootstrap.Modal.getInstance($modal);
        if (bsModal) bsModal.hide();

        $select.dispatchEvent(new Event('change', { bubbles: true }));
        refresh();

      } catch (err) {
        $errors.innerHTML = `<div>• ${escapeHtml(err.message || 'Unexpected error.')}</div>`;
        $errors.classList.remove('d-none');
      } finally {
        $submit.disabled = false;
        $spinner.classList.add('d-none');
        $submitIc.classList.remove('d-none');
      }
    });
  })();

  /* ================================================================
     GUARANTOR quick-add modal
  ================================================================ */
  (() => {
    const $modal    = document.getElementById('guarantorQuickModal');
    const $form     = document.getElementById('guarantorQuickForm');
    const $select   = document.getElementById('guarantor');
    const $errors   = document.getElementById('guarantorQuickErrors');
    const $submit   = document.getElementById('gqSubmit');
    const $spinner  = document.getElementById('gqSpinner');
    const $submitIc = document.getElementById('gqSubmitIcon');

    if (!$modal || !$form || !$select) return;

    $modal.addEventListener('show.bs.modal', () => {
      $form.reset();
      $form.classList.remove('was-validated');
      $errors.classList.add('d-none');
      $errors.innerHTML = '';
    });

    $form.addEventListener('submit', async (e) => {
      e.preventDefault();

      if (!$form.checkValidity()) {
        $form.classList.add('was-validated');
        return;
      }

      $submit.disabled = true;
      $spinner.classList.remove('d-none');
      $submitIc.classList.add('d-none');
      $errors.classList.add('d-none');

      try {
        const data = await postForm('guarantor-quick-add.php', new FormData($form));

        if (!data.ok) {
          const list = (data.errors || ['Failed to save guarantor.'])
            .map(er => `<div>• ${escapeHtml(er)}</div>`).join('');
          $errors.innerHTML = list;
          $errors.classList.remove('d-none');
          return;
        }

        let opt = $select.querySelector(`option[value="${data.id}"]`);
        if (!opt) {
          opt = document.createElement('option');
          opt.value = data.id;
          opt.textContent = `${data.name} (${data.id_no})`;
          $select.appendChild(opt);
        }
        opt.selected = true;

        const bsModal = bootstrap.Modal.getInstance($modal);
        if (bsModal) bsModal.hide();

        $select.dispatchEvent(new Event('change', { bubbles: true }));

      } catch (err) {
        $errors.innerHTML = `<div>• ${escapeHtml(err.message || 'Unexpected error.')}</div>`;
        $errors.classList.remove('d-none');
      } finally {
        $submit.disabled = false;
        $spinner.classList.add('d-none');
        $submitIc.classList.remove('d-none');
      }
    });
  })();
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';