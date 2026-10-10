<?php
$pageTitle = 'Edit Loan';
$activeNav = 'loans';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SHARED LOAN CONFIG ---------- */
require_once __DIR__ . '/loan-config.php';

/* ---------- SESSION + CSRF ---------- */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

/* ----------------------------------------------------------------
   LOAD LOAN
---------------------------------------------------------------- */
$loanId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($loanId <= 0) {
    $_SESSION['flash'] = 'Invalid loan reference.';
    header('Location: loans.php');
    exit;
}

$stmt = $conn->prepare("SELECT * FROM loans WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $loanId);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    $_SESSION['flash'] = 'Loan not found.';
    header('Location: loans.php');
    exit;
}

/* ----------------------------------------------------------------
   RATE / TERM HELPERS  (mirror loan-registration.php)
---------------------------------------------------------------- */
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
   LOAN LIMIT CHECK  (excludes the loan being edited)
---------------------------------------------------------------- */
function check_loan_limit_edit(mysqli $conn, int $clientId, string $category, int $excludeLoanId, array $limits): array {
    if (!isset($limits[$category])) return [true, null, 0, 0];
    $max = (int) $limits[$category];
    if ($max <= 0) return [true, null, 0, 0];

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS cnt
         FROM loans
         WHERE client_id = ?
           AND loan_category = ?
           AND status NOT IN ('cleared','written_off')
           AND id <> ?"
    );
    if (!$stmt) return [true, null, 0, $max];

    $stmt->bind_param("isi", $clientId, $category, $excludeLoanId);
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
   UPLOAD CONFIG  (mirror registration)
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
if (!is_dir($uploadDirFs)) @mkdir($uploadDirFs, 0775, true);

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
        if (!isset($fallback[$ext])) return [null, 'Unsupported file: ' . htmlspecialchars($file['name'])];
        $ext = $fallback[$ext];
    } else {
        $ext = $allowedMime[$mime];
    }
    if (!is_writable($fsDir)) return [null, 'Upload folder is not writable.'];

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

/* Per-client category counts (excluding this loan) */
$clientUsage = [];
$stmt = $conn->prepare(
    "SELECT client_id, loan_category, COUNT(*) AS cnt
     FROM loans
     WHERE status NOT IN ('cleared','written_off')
       AND id <> ?
     GROUP BY client_id, loan_category"
);
if ($stmt) {
    $stmt->bind_param("i", $loanId);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $clientUsage[(int)$row['client_id']][$row['loan_category']] = (int)$row['cnt'];
    }
    $stmt->close();
}

/* Current guarantor (if any) */
$currentGuarantorId = 0;
$hasJoin = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
if ($hasJoin && $hasJoin->num_rows > 0) {
    $hasJoin->free();
    $g = $conn->prepare("SELECT guarantor_id FROM loan_guarantors WHERE loan_id = ? LIMIT 1");
    if ($g) {
        $g->bind_param("i", $loanId);
        $g->execute();
        $gRow = $g->get_result()->fetch_assoc();
        if ($gRow) $currentGuarantorId = (int) $gRow['guarantor_id'];
        $g->close();
    }
}

/* Existing uploads */
$existingUploads = [];
if (!empty($loan['uploads'])) {
    $decoded = json_decode($loan['uploads'], true);
    if (is_array($decoded)) $existingUploads = array_values(array_filter($decoded, 'is_string'));
}

/* Is the loan financially locked? (any payment received) */
$hasPayments = ((float) ($loan['amount_paid'] ?? 0)) > 0.009;

/* ----------------------------------------------------------------
   INITIAL FORM STATE (from existing loan)
---------------------------------------------------------------- */
$errors = [];
$form = [
    'client'       => (string) $loan['client_id'],
    'issueDate'    => $loan['date_issued'],
    'loanCategory' => $loan['loan_category'],
    'duration'     => (string) $loan['duration'],
    'principal'    => (string) $loan['principal_amount'],
    'guarantor'    => $currentGuarantorId > 0 ? (string) $currentGuarantorId : '',
    'notes'        => (string) ($loan['notes'] ?? ''),
];

/* ----------------------------------------------------------------
   HANDLE POST
---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* CSRF */
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token mismatch. Please refresh and try again.';
    }

    /* Collect form fields */
    foreach ($form as $k => $v) {
        $form[$k] = trim($_POST[$k] ?? $v);
    }

    /* Files to remove */
    $removeUploads = $_POST['remove_uploads'] ?? [];
    if (!is_array($removeUploads)) $removeUploads = [];
    $removeUploads = array_values(array_intersect($removeUploads, $existingUploads));

    /* If payments exist, force the financial fields to their originals */
    if ($hasPayments) {
        $form['issueDate']    = $loan['date_issued'];
        $form['loanCategory'] = $loan['loan_category'];
        $form['duration']     = (string) $loan['duration'];
        $form['principal']    = (string) $loan['principal_amount'];
    }

    /* ---- Validation ---- */
    $clientId = (int) $form['client'];
    if ($clientId <= 0) $errors[] = 'Please choose a client.';

    if ($form['issueDate'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['issueDate'])) {
        $errors[] = 'Date of issue is invalid.';
    } elseif ($form['issueDate'] > date('Y-m-d')) {
        $errors[] = 'Date of issue cannot be in the future.';
    }

    $categoryValid = $form['loanCategory'] !== '' && isset($loanCategories[$form['loanCategory']]);
    if (!$categoryValid) $errors[] = 'Please choose a valid loan type.';

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

    /* Guarantor / collateral rule */
    $guarantorId = (int) $form['guarantor'];

    $newCollateralUploaded = !empty($_FILES['collateral']['name'][0])
                          && ($_FILES['collateral']['error'][0] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $keptUploads = array_values(array_diff($existingUploads, $removeUploads));

    $hasGuarantor  = $guarantorId > 0;
    $hasCollateral = $newCollateralUploaded || !empty($keptUploads);

    if (!$hasGuarantor && !$hasCollateral) {
        $errors[] = 'Please attach at least one collateral file or choose a guarantor.';
    }

    /* Loan limit (excluding self) */
    if (empty($errors) && $clientId > 0 && $categoryValid) {
        [$allowed, $limitMsg] = check_loan_limit_edit(
            $conn, $clientId, $form['loanCategory'], $loanId, $loanLimits
        );
        if (!$allowed) $errors[] = $limitMsg;
    }

    /* Verify client / guarantor still exist */
    if (empty($errors)) {
        $s = $conn->prepare("SELECT id FROM clients WHERE id = ? LIMIT 1");
        if ($s) {
            $s->bind_param("i", $clientId);
            $s->execute();
            if (!$s->get_result()->fetch_assoc()) $errors[] = 'Selected client no longer exists.';
            $s->close();
        }
    }
    if (empty($errors) && $guarantorId > 0) {
        $s = $conn->prepare("SELECT id FROM guarantors WHERE id = ? LIMIT 1");
        if ($s) {
            $s->bind_param("i", $guarantorId);
            $s->execute();
            if (!$s->get_result()->fetch_assoc()) $errors[] = 'Selected guarantor no longer exists.';
            $s->close();
        }
    }

    /* Upload new collateral (append to kept) */
    $uploadedFiles = $keptUploads;
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

    /* ---- Determine if financial fields actually changed ---- */
    $financialChanged =
        $loan['loan_category'] !== $form['loanCategory'] ||
        $loan['date_issued']   !== $form['issueDate']     ||
        (int) $loan['duration'] !== $duration             ||
        abs((float) $loan['principal_amount'] - $principal) > 0.0001;

    /* ---- Update ---- */
    if (empty($errors)) {
        $categoryCfg = $loanCategories[$form['loanCategory']];
        $purpose     = $categoryCfg['label'];
        $notes       = $form['notes'] !== '' ? $form['notes'] : null;
        $uploadsJson = !empty($uploadedFiles) ? json_encode(array_values($uploadedFiles)) : null;

        /* Start from existing values */
        $interestRate    = (float) $loan['interest_rate'];
        $interestAmount  = (float) $loan['interest_amount'];
        $interestBalance = (float) $loan['interest_balance'];
        $principalBal    = (float) $loan['principal_balance'];
        $dueDate         = $loan['due_date'];
        $lastAccrualDate = $loan['last_accrual_date'];

        if ($financialChanged && !$hasPayments) {
            /* Recompute everything from scratch, exactly like registration */
            if ($form['loanCategory'] === 'monthly') {
                $interestRate = calc_base_rate('monthly', $principal, $loanCategories);
            } else {
                $interestRate = calc_effective_rate($form['loanCategory'], $principal, $duration, $loanCategories);
            }

            if ($interestRate === null) {
                $errors[] = 'No interest rate matches that principal for the selected loan type.';
            } else {
                $dueDate         = calc_due_date($form['issueDate'], $form['loanCategory'], $duration, $loanCategories);
                $interestAmount  = round($principal * $interestRate / 100, 2);
                $interestBalance = $interestAmount;
                $principalBal    = $principal;

                $accrualStart = new DateTime($form['issueDate']);
                $accrualStart->modify('+1 month');
                $lastAccrualDate = $accrualStart->format('Y-m-d');
            }
        }

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                $sql = "UPDATE loans SET
                            client_id         = ?,
                            loan_category     = ?,
                            date_issued       = ?,
                            principal_amount  = ?,
                            principal_balance = ?,
                            interest_rate     = ?,
                            interest_amount   = ?,
                            interest_balance  = ?,
                            due_date          = ?,
                            duration          = ?,
                            last_accrual_date = ?,
                            uploads           = ?,
                            purpose           = ?,
                            notes             = ?
                        WHERE id = ?";

                $stmt = $conn->prepare($sql);
                if (!$stmt) throw new Exception('Prepare update failed: ' . $conn->error);

                $stmt->bind_param(
                    "issdddddsissssi",
                    $clientId,
                    $form['loanCategory'],
                    $form['issueDate'],
                    $principal,
                    $principalBal,
                    $interestRate,
                    $interestAmount,
                    $interestBalance,
                    $dueDate,
                    $duration,
                    $lastAccrualDate,
                    $uploadsJson,
                    $purpose,
                    $notes,
                    $loanId
                );

                if (!$stmt->execute()) {
                    throw new Exception('Update failed: ' . $stmt->error);
                }
                $stmt->close();

                /* Re-link guarantor */
                $hasJoin = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
                if ($hasJoin && $hasJoin->num_rows > 0) {
                    $hasJoin->free();

                    $del = $conn->prepare("DELETE FROM loan_guarantors WHERE loan_id = ?");
                    if ($del) {
                        $del->bind_param("i", $loanId);
                        $del->execute();
                        $del->close();
                    }

                    if ($guarantorId > 0) {
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
                            $link->bind_param("iid", $loanId, $guarantorId, $guaranteedAmount);
                        } else {
                            $link = $conn->prepare(
                                "INSERT INTO loan_guarantors (loan_id, guarantor_id)
                                 VALUES (?, ?)"
                            );
                            if (!$link) throw new Exception('Prepare guarantor link failed: ' . $conn->error);
                            $link->bind_param("ii", $loanId, $guarantorId);
                        }
                        if (!$link->execute()) throw new Exception('Insert guarantor link failed: ' . $link->error);
                        $link->close();
                    }
                }

                $conn->commit();

                /* Remove unlinked physical files (only after commit) */
                foreach ($removeUploads as $fname) {
                    $safe = basename((string) $fname);
                    if ($safe === '') continue;
                    $path = rtrim($uploadDirFs, '/\\') . DIRECTORY_SEPARATOR . $safe;
                    if (is_file($path)) @unlink($path);
                }

                $_SESSION['flash'] = "Loan {$loan['loan_code']} was updated successfully.";
                header('Location: loans.php');
                exit;

            } catch (Throwable $ex) {
                $conn->rollback();
                $errors[] = 'Could not update loan: ' . $ex->getMessage();
            }
        }
    }
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Loan Management</p>
      <h1 class="h3 mb-1">Edit Loan — <?= htmlspecialchars($loan['loan_code'], ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="text-muted mb-0">Update loan details, client, guarantor, collateral, or notes.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="loan-view.php?id=<?= (int) $loanId ?>">
      <i class="bi bi-eye" aria-hidden="true"></i> View
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="loans.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Loans
    </a>
  </div>
</div>

<?php if ($hasPayments): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="bi bi-lock-fill mt-1" aria-hidden="true"></i>
    <div>
      <strong>Financial fields are locked.</strong>
      This loan already has payments recorded
      (Ksh <?= number_format((float) $loan['amount_paid'], 2) ?>).
      You can still edit the client, guarantor, collateral, and notes —
      but category, issue date, duration, and principal cannot be changed.
    </div>
  </div>
<?php endif; ?>

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
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id" value="<?= (int) $loanId ?>">

      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
            <span>Loan Information</span>
          </h2>
          <p class="text-muted mb-0">Modify the details below and save to apply changes.</p>
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
                 <?= $hasPayments ? 'readonly' : '' ?>
                 value="<?= htmlspecialchars($form['issueDate'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Date of issue is required.</div>
        </div>

        <!-- Loan Type -->
        <div class="col-md-6">
          <label class="form-label" for="loanCategory">Loan Type</label>
          <select class="form-select" id="loanCategory" name="loanCategory" required
                  <?= $hasPayments ? 'disabled' : '' ?>>
            <option value="">Choose loan type</option>
            <option value="monthly" <?= $form['loanCategory']==='monthly' ? 'selected':'' ?>>Monthly Loan</option>
            <option value="daily"   <?= $form['loanCategory']==='daily'   ? 'selected':'' ?>>Daily Loan</option>
            <option value="boost"   <?= $form['loanCategory']==='boost'   ? 'selected':'' ?>>Jolly Boost Loan</option>
          </select>
          <?php if ($hasPayments): ?>
            <input type="hidden" name="loanCategory" value="<?= htmlspecialchars($form['loanCategory'], ENT_QUOTES, 'UTF-8') ?>">
          <?php endif; ?>
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
                 <?= $hasPayments ? 'readonly' : '' ?>
                 value="<?= htmlspecialchars($form['duration'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-text" id="durationHint">Select a loan type first.</div>
          <div class="invalid-feedback">Enter a valid duration.</div>
        </div>

        <!-- Principal -->
        <div class="col-md-6">
          <label class="form-label" for="principal">Principal Amount (Ksh)</label>
          <input class="form-control" id="principal" name="principal" type="number"
                 min="5000" max="1000000" step="0.01" required
                 <?= $hasPayments ? 'readonly' : '' ?>
                 value="<?= htmlspecialchars($form['principal'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-text">Between Ksh 5,000 and Ksh 1,000,000.</div>
          <div class="invalid-feedback">Enter a principal between Ksh 5,000 and Ksh 1,000,000.</div>
        </div>

        <!-- Interest Rate (display only) -->
        <div class="col-md-6">
          <label class="form-label" for="interestRate">Interest Rate (%)</label>
          <input class="form-control" id="interestRate" name="interestRate" type="text" readonly>
          <div class="form-text">Recalculated automatically when loan fields change.</div>
        </div>

        <!-- Total Due (display only) -->
        <div class="col-md-6">
          <label class="form-label" for="totalDue">Total Due (Ksh)</label>
          <input class="form-control" id="totalDue" name="totalDue" type="text" readonly>
          <div class="form-text" id="totalDueHint">Principal + interest.</div>
        </div>

        <!-- Due Date (display only) -->
        <div class="col-md-6">
          <label class="form-label" for="dueDate">Due Date</label>
          <input class="form-control" id="dueDate" name="dueDate" type="date" readonly>
        </div>

        <!-- Guarantor -->
        <div class="col-md-6">
          <label class="form-label" for="guarantor">
            Guarantor <span class="text-muted small">(optional if collateral is attached)</span>
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

        <!-- Existing collateral -->
        <?php if (!empty($existingUploads)): ?>
          <div class="col-12">
            <label class="form-label">Existing Collateral</label>
            <div class="d-flex flex-wrap gap-3">
              <?php foreach ($existingUploads as $f):
                  $url = '../assets/images/loan-attachments/' . rawurlencode($f);
                  $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
              ?>
                <div class="border rounded p-2 text-center" style="width: 140px;">
                  <?php if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true)): ?>
                    <img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                         class="img-fluid mb-2" style="max-height: 80px;" alt="Collateral">
                  <?php elseif (in_array($ext, ['mp4','webm'], true)): ?>
                    <video src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>"
                           class="img-fluid mb-2" style="max-height: 80px;" controls></video>
                  <?php else: ?>
                    <div class="py-3"><i class="bi bi-file-earmark-text fs-3"></i></div>
                  <?php endif; ?>
                  <div class="small text-truncate mb-2" title="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>
                  </div>
                  <div class="form-check d-flex justify-content-center">
                    <input class="form-check-input me-2" type="checkbox"
                           name="remove_uploads[]" value="<?= htmlspecialchars($f, ENT_QUOTES, 'UTF-8') ?>"
                           id="rm_<?= htmlspecialchars(md5($f), ENT_QUOTES, 'UTF-8') ?>">
                    <label class="form-check-label small"
                           for="rm_<?= htmlspecialchars(md5($f), ENT_QUOTES, 'UTF-8') ?>">
                      Remove
                    </label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="form-text mt-2">Tick <strong>Remove</strong> to delete a file when you save.</div>
          </div>
        <?php endif; ?>

        <!-- New collateral upload -->
        <div class="col-12">
          <label class="form-label" for="collateral">Add New Collateral (Media Upload)</label>
          <input class="form-control" id="collateral" name="collateral[]" type="file"
                 accept="image/*,video/*,application/pdf" multiple>
          <div class="form-text">
            JPG, PNG, GIF, WEBP, MP4, WEBM, PDF. Max 10 MB each.
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
          <i class="bi bi-check-circle" aria-hidden="true"></i> Save Changes
        </button>
      </div>
    </form>
  </div>

  <div class="col-12 col-xl-4">
    <div class="panel h-100">
      <h2 class="h5 mb-3 section-title">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>Edit Rules</span>
      </h2>
      <div class="activity-list">
        <div class="activity-item"><span class="activity-dot bg-success"></span><div><p class="mb-1 fw-semibold">Client / guarantor</p><p class="text-muted small mb-0">Always editable, subject to loan-limit rules.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-info"></span><div><p class="mb-1 fw-semibold">Collateral</p><p class="text-muted small mb-0">Add new files or tick <em>Remove</em> on existing ones.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-warning"></span><div><p class="mb-1 fw-semibold">Financial fields</p><p class="text-muted small mb-0">Once a payment is received, these become read-only.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-primary"></span><div><p class="mb-1 fw-semibold">Interest recalculation</p><p class="text-muted small mb-0">Only triggered when financial fields change.</p></div></div>
      </div>
    </div>
  </div>
</section>

<!-- ================================================================
     MODAL: Quick-add CLIENT  (same as registration)
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
              <input class="form-control" id="cqLocation" name="location" type="text">
            </div>
            <div class="col-12">
              <label class="form-label" for="cqPhoto">Photo</label>
              <input class="form-control" id="cqPhoto" name="photo" type="file"
                     accept="image/jpeg,image/png,image/webp,image/gif">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
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
            </div>
            <div class="col-12">
              <label class="form-label" for="gqNotes">Notes</label>
              <textarea class="form-control" id="gqNotes" name="notes" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
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
  const HAS_PAYMENTS    = <?= $hasPayments ? 'true' : 'false' ?>;
  const EXISTING_UPLOAD_COUNT = <?= (int) count($existingUploads) ?>;

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
  const $hint          = document.getElementById('loanTypeHint');
  const $clientLimit   = document.getElementById('clientLimitHint');
  const $collateral    = document.getElementById('collateral');
  const $preview       = document.getElementById('collateralPreview');

  function categoryHint(catKey) {
    const cfg = LOAN_CATEGORIES[catKey];
    if (!cfg) return 'Select a loan type to see rate and term rules.';
    if (cfg.rate_type === 'tiered') {
      const tiers = cfg.tiers.map(t =>
        `${(t.min/1000).toFixed(0)}k–${(t.max/1000).toFixed(0)}k → ${t.rate}%`
      ).join(' · ');
      return `Monthly loan. Rate tiers: ${tiers} per month. Duration: 1–3 months.`;
    }
    if (catKey === 'boost') return `Jolly Boost. 0% interest for the fixed 5-day term.`;
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

  function refreshClientLimitHint() {
    const clientId = $client.value;
    if (!clientId) { $clientLimit.textContent = ''; return; }
    const usage = CLIENT_USAGE[clientId] || {};
    const parts = [];
    for (const k of ['monthly','daily','boost']) {
      const limit = LOAN_LIMITS[k] || 0;
      if (limit <= 0) continue;
      const used  = usage[k] || 0;
      const label = LOAN_CATEGORIES[k] ? LOAN_CATEGORIES[k].label.split(' ')[0] : k;
      const hit   = used >= limit;
      parts.push(`<span class="${hit ? 'text-danger fw-semibold' : 'text-muted'}">${label}: ${used}/${limit}</span>`);
    }
    $clientLimit.innerHTML = parts.length ? 'Open loans — ' + parts.join(' · ') : '';
  }

  function refreshDurationField(catKey) {
    const cfg = LOAN_CATEGORIES[catKey];
    if (!cfg) {
      $durationUnit.textContent = '—';
      $durationHint.textContent = 'Select a loan type first.';
      return;
    }
    if (!HAS_PAYMENTS) {
      $duration.min = cfg.duration_min;
      $duration.max = cfg.duration_max;
    }
    $durationUnit.textContent = cfg.duration_label;
    $durationHint.textContent = (cfg.duration_min === cfg.duration_max)
      ? `Fixed at ${cfg.duration_min} ${cfg.duration_label.toLowerCase()}.`
      : `Between ${cfg.duration_min} and ${cfg.duration_max} ${cfg.duration_label.toLowerCase()}.`;
  }

  function refresh() {
    const amount = parseFloat($principal.value) || 0;
    const catKey = $category.value;

    $hint.textContent = categoryHint(catKey);
    refreshDurationField(catKey);
    refreshClientLimitHint();

    if (!catKey) {
      $rate.value = ''; $total.value = ''; $due.value = '';
      return;
    }

    const cfg = LOAN_CATEGORIES[catKey];
    const duration = parseInt($duration.value, 10) || 0;

    if (duration < cfg.duration_min || duration > cfg.duration_max ||
        amount < MIN_PRINCIPAL || amount > MAX_PRINCIPAL) {
      $rate.value = ''; $total.value = ''; $due.value = '';
      return;
    }

    const base = baseRate(catKey, amount);
    let effRate, rateDisplay;

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
      if (cfg.duration_unit === 'months') d.setMonth(d.getMonth() + duration);
      else d.setDate(d.getDate() + duration);
      $due.value = d.toISOString().slice(0, 10);
    } else {
      $due.value = '';
    }
  }

  [$principal, $issueDate, $category, $duration, $client].forEach(el => {
    if (el) {
      el.addEventListener('input', refresh);
      el.addEventListener('change', refresh);
    }
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

  /* ================================================================
     Helpers
  ================================================================ */
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  async function postForm(url, formData) {
    const res = await fetch(url, { method: 'POST', body: formData, credentials: 'same-origin' });
    try { return await res.json(); }
    catch { throw new Error('Server returned invalid response.'); }
  }

  /* CLIENT quick-add */
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
      if (!$form.checkValidity()) { $form.classList.add('was-validated'); return; }

      $submit.disabled = true;
      $spinner.classList.remove('d-none');
      $submitIc.classList.add('d-none');
      $errors.classList.add('d-none');

      try {
        const data = await postForm('client-quick-add.php', new FormData($form));
        if (!data.ok) {
          $errors.innerHTML = (data.errors || ['Failed to save client.'])
            .map(er => `<div>• ${escapeHtml(er)}</div>`).join('');
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

  /* GUARANTOR quick-add */
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
      if (!$form.checkValidity()) { $form.classList.add('was-validated'); return; }

      $submit.disabled = true;
      $spinner.classList.remove('d-none');
      $submitIc.classList.add('d-none');
      $errors.classList.add('d-none');

      try {
        const data = await postForm('guarantor-quick-add.php', new FormData($form));
        if (!data.ok) {
          $errors.innerHTML = (data.errors || ['Failed to save guarantor.'])
            .map(er => `<div>• ${escapeHtml(er)}</div>`).join('');
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