<?php
$pageTitle = 'Edit Payment';
$activeNav = 'payments';

require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

/* ----------------------------------------------------------------
   LOAD PAYMENT + LOAN
---------------------------------------------------------------- */
$paymentId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($paymentId <= 0) {
    $_SESSION['flash'] = 'Invalid payment reference.';
    header('Location: payments.php'); exit;
}

$stmt = $conn->prepare(
    "SELECT p.id, p.payment_code, p.client_id, p.loan_id, p.amount_paid,
            p.payment_date, p.mode_of_payment, p.reference_number, p.remarks, p.upload,
            l.loan_code, l.client_id AS loan_client_id
     FROM payments p
     INNER JOIN loans l ON l.id = p.loan_id
     WHERE p.id = ? LIMIT 1"
);
$stmt->bind_param("i", $paymentId);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$payment) {
    $_SESSION['flash'] = 'Payment not found.';
    header('Location: payments.php'); exit;
}

/* Client for display */
$clientName = '';
$clientEmail = '';
$s = $conn->prepare("SELECT full_name, email FROM clients WHERE id = ? LIMIT 1");
if ($s) {
    $s->bind_param("i", $payment['client_id']);
    $s->execute();
    $cRow = $s->get_result()->fetch_assoc();
    if ($cRow) { $clientName = (string)$cRow['full_name']; $clientEmail = (string)$cRow['email']; }
    $s->close();
}

/* ----------------------------------------------------------------
   UPLOAD CONFIG
---------------------------------------------------------------- */
$uploadDirFs = __DIR__ . '/../assets/images/payment-uploads/';
$maxBytes    = 5 * 1024 * 1024;
$allowedMime = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/gif'       => 'gif',
    'application/pdf' => 'pdf',
];
if (!is_dir($uploadDirFs)) @mkdir($uploadDirFs, 0775, true);

/* ----------------------------------------------------------------
   HELPERS  (same as payment-delete.php)
---------------------------------------------------------------- */
function normalize_payment_mode(string $mode): string {
    return match ($mode) {
        'mpesa'                      => 'mpesa',
        'bank','bank_transfer'       => 'bank_transfer',
        'cash'                       => 'cash',
        'cheque'                     => 'cheque',
        'card'                       => 'card',
        default                      => 'other',
    };
}

function save_payment_upload(array $file, string $fsDir, array $allowedMime, int $maxBytes): array {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'Upload failed (code ' . $file['error'] . ').'];
    if ($file['size'] > $maxBytes) return [null, 'File exceeds 5 MB: ' . htmlspecialchars($file['name'])];

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
    }
    if (!$mime || !isset($allowedMime[$mime])) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $fallback = ['jpg'=>'jpg','jpeg'=>'jpg','png'=>'png','webp'=>'webp','gif'=>'gif','pdf'=>'pdf'];
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

function derive_loan_status(float $interestBal, float $principalBal, string $dueDate, string $category, string $currentStatus): string {
    $balance = $interestBal + $principalBal;
    if ($balance <= 0.009) return 'cleared';
    if ($currentStatus === 'written_off') return 'written_off';

    $today = new DateTime('today');
    $due   = new DateTime($dueDate);
    if ($today < $due) return 'active';

    $t3 = clone $due; $t6 = clone $due;
    if ($category === 'monthly') { $t3->modify('+3 months'); $t6->modify('+6 months'); }
    else                         { $t3->modify('+3 days');   $t6->modify('+6 days'); }

    if ($today >= $t6) return 'written_off';
    if ($today >= $t3) return 'defaulted';
    return 'overdue';
}

/**
 * Replay all payments for a loan (excluding given IDs), optionally injecting a
 * hypothetical replacement payment into the sequence, and derive the new loan state.
 */
function recompute_loan_state(
    mysqli $conn,
    int $loanId,
    array $excludeIds = [],
    ?array $overridePayment = null
): array {
    $stmt = $conn->prepare(
        "SELECT id, principal_amount, interest_amount, due_date, loan_category, status
         FROM loans WHERE id = ? LIMIT 1"
    );
    if (!$stmt) throw new Exception('Prepare loan fetch failed: ' . $conn->error);
    $stmt->bind_param("i", $loanId);
    $stmt->execute();
    $loan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$loan) throw new Exception("Loan {$loanId} not found.");

    $principalBal = (float) $loan['principal_amount'];
    $interestBal  = (float) $loan['interest_amount'];
    $amountPaid   = 0.0;

    $excludeIds   = array_values(array_map('intval', $excludeIds));
    $placeholders = empty($excludeIds) ? '' : implode(',', array_fill(0, count($excludeIds), '?'));

    $sql = "SELECT id, amount_paid, payment_date FROM payments WHERE loan_id = ?";
    if ($placeholders) $sql .= " AND id NOT IN ($placeholders)";
    $sql .= " ORDER BY payment_date ASC, id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('Prepare payments fetch failed: ' . $conn->error);

    if ($placeholders) {
        $types  = 'i' . str_repeat('i', count($excludeIds));
        $params = array_merge([$loanId], $excludeIds);
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param("i", $loanId);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $payments = [];
    while ($p = $res->fetch_assoc()) $payments[] = $p;
    $stmt->close();

    if ($overridePayment !== null) {
        $payments[] = [
            'id'           => 0,
            'amount_paid'  => $overridePayment['amount_paid'],
            'payment_date' => $overridePayment['payment_date'],
        ];
        usort($payments, function ($a, $b) {
            if ($a['payment_date'] !== $b['payment_date']) return strcmp($a['payment_date'], $b['payment_date']);
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });
    }

    foreach ($payments as $p) {
        $amt = (float) $p['amount_paid'];
        $amountPaid += $amt;
        if ($amt >= $interestBal) {
            $amt        -= $interestBal;
            $interestBal = 0.0;
            if ($amt > 0) $principalBal = max(0.0, $principalBal - $amt);
        } else {
            $interestBal -= $amt;
        }
    }

    $interestBal  = round($interestBal, 2);
    $principalBal = round($principalBal, 2);
    $amountPaid   = round($amountPaid, 2);

    $status = derive_loan_status(
        $interestBal, $principalBal,
        $loan['due_date'], $loan['loan_category'], $loan['status']
    );

    return [
        'interest_balance'  => $interestBal,
        'principal_balance' => $principalBal,
        'amount_paid'       => $amountPaid,
        'status'            => $status,
        'loan_id'           => $loanId,
    ];
}

/* ----------------------------------------------------------------
   INITIAL FORM STATE
---------------------------------------------------------------- */
$errors = [];
$form = [
    'amountPaid'   => (string) $payment['amount_paid'],
    'datePaid'     => (string) $payment['payment_date'],
    'mode'         => (string) $payment['mode_of_payment'],
    'reference'    => (string) ($payment['reference_number'] ?? ''),
    'notes'        => (string) ($payment['remarks'] ?? ''),
    'removeUpload' => false,
];

/* ----------------------------------------------------------------
   HANDLE POST
---------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Security token mismatch. Please refresh and try again.';
    }

    foreach (['amountPaid','datePaid','mode','reference','notes'] as $k) {
        $form[$k] = trim($_POST[$k] ?? $form[$k]);
    }
    $form['removeUpload'] = !empty($_POST['removeUpload']);

    /* ---- Validation ---- */
    $amountPaid = (float) $form['amountPaid'];
    if ($amountPaid <= 0) $errors[] = 'Amount paid must be greater than zero.';

    if ($form['datePaid'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['datePaid'])) {
        $errors[] = 'Date paid is invalid.';
    } elseif ($form['datePaid'] > date('Y-m-d')) {
        $errors[] = 'Date paid cannot be in the future.';
    }

    if ($form['mode'] === '') $errors[] = 'Please choose a payment mode.';
    $mode = normalize_payment_mode($form['mode']);

    /* ---- Optional new proof ---- */
    $newUpload   = null;
    $oldUpload   = (string) ($payment['upload'] ?? '');
    if (empty($errors) && !empty($_FILES['proof']['name'][0])) {
        $one = [
            'name'     => $_FILES['proof']['name'][0],
            'type'     => $_FILES['proof']['type'][0],
            'tmp_name' => $_FILES['proof']['tmp_name'][0],
            'error'    => $_FILES['proof']['error'][0],
            'size'     => $_FILES['proof']['size'][0],
        ];
        if ($one['error'] !== UPLOAD_ERR_NO_FILE) {
            [$fname, $err] = save_payment_upload($one, $uploadDirFs, $allowedMime, $maxBytes);
            if ($err) $errors[] = 'Proof: ' . $err;
            else      $newUpload = $fname;
        }
    }

    /* Decide the effective upload value that should end up in the DB */
    $effectiveUpload = $oldUpload;
    if ($newUpload !== null)         $effectiveUpload = $newUpload;   // replaced
    elseif ($form['removeUpload'])   $effectiveUpload = null;         // removed

    /* ---- Save ---- */
    if (empty($errors)) {
        $reference = $form['reference'] !== '' ? $form['reference'] : null;
        $remarks   = $form['notes']     !== '' ? $form['notes']     : null;

        $loanId = (int) $payment['loan_id'];

        $conn->begin_transaction();
        try {
            /* 1) Update the payment row */
            $upd = $conn->prepare(
                "UPDATE payments
                 SET amount_paid       = ?,
                     payment_date      = ?,
                     mode_of_payment   = ?,
                     reference_number  = ?,
                     remarks           = ?,
                     upload            = ?
                 WHERE id = ?"
            );
            if (!$upd) throw new Exception('Prepare payment update failed: ' . $conn->error);
            $upd->bind_param(
                "dsssssi",
                $amountPaid,
                $form['datePaid'],
                $mode,
                $reference,
                $remarks,
                $effectiveUpload,
                $paymentId
            );
            if (!$upd->execute()) throw new Exception('Update payment failed: ' . $upd->error);
            $upd->close();

            /* 2) Recompute loan state: exclude the OLD payment, then overlay the NEW one */
            $newState = recompute_loan_state(
                $conn,
                $loanId,
                [$paymentId],
                ['amount_paid' => $amountPaid, 'payment_date' => $form['datePaid']]
            );

            $updL = $conn->prepare(
                "UPDATE loans
                 SET interest_balance  = ?,
                     principal_balance = ?,
                     amount_paid       = ?,
                     status            = ?
                 WHERE id = ?"
            );
            if (!$updL) throw new Exception('Prepare loan update failed: ' . $conn->error);
            $updL->bind_param(
                "dddsi",
                $newState['interest_balance'],
                $newState['principal_balance'],
                $newState['amount_paid'],
                $newState['status'],
                $newState['loan_id']
            );
            if (!$updL->execute()) throw new Exception('Update loan failed: ' . $updL->error);
            $updL->close();

            $conn->commit();

            /* 3) If the old upload was replaced or removed, delete the physical file */
            if ($oldUpload !== '' && $oldUpload !== $effectiveUpload) {
                $safe = basename($oldUpload);
                $path = rtrim($uploadDirFs, '/\\') . DIRECTORY_SEPARATOR . $safe;
                if (is_file($path)) @unlink($path);
            }

            $_SESSION['flash'] = "Payment {$payment['payment_code']} updated. Loan balances were recalculated.";
            header('Location: payment-view.php?id=' . $paymentId);
            exit;

        } catch (Throwable $ex) {
            $conn->rollback();
            /* If we saved a new file but the transaction failed, clean it up */
            if ($newUpload !== null) {
                $path = rtrim($uploadDirFs, '/\\') . DIRECTORY_SEPARATOR . $newUpload;
                if (is_file($path)) @unlink($path);
            }
            $errors[] = 'Could not update payment: ' . $ex->getMessage();
        }
    }
}

/* Current proof preview path */
$currentProofUrl = '';
if (!empty($payment['upload'])) {
    $currentProofUrl = '../assets/images/payment-uploads/' . rawurlencode($payment['upload']);
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-pencil-square" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Payments</p>
      <h1 class="h3 mb-1">Edit Payment — <?= htmlspecialchars($payment['payment_code'], ENT_QUOTES, 'UTF-8') ?></h1>
      <p class="text-muted mb-0">Update the payment details. Loan balances are recalculated automatically.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="payment-view.php?id=<?= (int) $paymentId ?>">
      <i class="bi bi-eye" aria-hidden="true"></i> View
    </a>
    <a class="btn btn-outline-secondary btn-sm" href="payments.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Payments
    </a>
  </div>
</div>

<div class="alert alert-warning d-flex align-items-start gap-2">
  <i class="bi bi-exclamation-triangle-fill mt-1" aria-hidden="true"></i>
  <div>
    <strong>Editing amount or date will recalculate the loan.</strong>
    The system replays every payment on this loan in chronological order using
    interest-first, then principal allocation. The loan's balances and status
    are updated from that replay — not by a delta.
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
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="id" value="<?= (int) $paymentId ?>">

      <div class="panel-header">
        <div>
          <h2 class="h5 mb-1 section-title">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
            <span>Payment Information</span>
          </h2>
          <p class="text-muted mb-0">Client and loan are fixed. Everything else can be edited.</p>
        </div>
      </div>

      <div class="row g-3">
        <!-- Client (read-only) -->
        <div class="col-md-6">
          <label class="form-label" for="clientDisplay">Client</label>
          <input class="form-control" id="clientDisplay" type="text" readonly
                 value="<?= htmlspecialchars($clientName . ' (' . ($clientEmail ?: 'no email') . ')', ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <!-- Loan (read-only) -->
        <div class="col-md-6">
          <label class="form-label" for="loanDisplay">Loan</label>
          <input class="form-control" id="loanDisplay" type="text" readonly
                 value="<?= htmlspecialchars($payment['loan_code'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-text">To move this payment to a different loan, delete it and re-record.</div>
        </div>

        <!-- Amount Paid -->
        <div class="col-md-6">
          <label class="form-label" for="amountPaid">Amount Paid (Ksh)</label>
          <input class="form-control" id="amountPaid" name="amountPaid" type="number"
                 min="1" step="0.01" required
                 value="<?= htmlspecialchars($form['amountPaid'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Enter the amount paid.</div>
        </div>

        <!-- Date Paid -->
        <div class="col-md-6">
          <label class="form-label" for="datePaid">Date Paid</label>
          <input class="form-control" id="datePaid" name="datePaid" type="date" required
                 max="<?= date('Y-m-d') ?>"
                 value="<?= htmlspecialchars($form['datePaid'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Date paid is required.</div>
        </div>

        <!-- Mode -->
        <div class="col-md-6">
          <label class="form-label" for="mode">Mode of Payment</label>
          <select class="form-select" id="mode" name="mode" required>
            <option value="">Choose mode</option>
            <option value="mpesa"  <?= $form['mode']==='mpesa'  ? 'selected':'' ?>>Mpesa</option>
            <option value="bank"   <?= $form['mode']==='bank_transfer' ? 'selected':'' ?>>Bank Transfer</option>
            <option value="cash"   <?= $form['mode']==='cash'   ? 'selected':'' ?>>Cash</option>
            <option value="cheque" <?= $form['mode']==='cheque' ? 'selected':'' ?>>Cheque</option>
            <option value="card"   <?= $form['mode']==='card'   ? 'selected':'' ?>>Card</option>
            <option value="other"  <?= $form['mode']==='other'  ? 'selected':'' ?>>Other</option>
          </select>
          <div class="invalid-feedback">Choose a payment mode.</div>
        </div>

        <!-- Reference -->
        <div class="col-md-6">
          <label class="form-label" for="reference">Reference / Transaction No</label>
          <input class="form-control" id="reference" name="reference" type="text"
                 placeholder="e.g., MPESA-XXXX1234"
                 value="<?= htmlspecialchars($form['reference'], ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <!-- Existing proof -->
        <?php if ($currentProofUrl !== ''): ?>
          <div class="col-12">
            <label class="form-label">Current Proof of Payment</label>
            <div class="border rounded p-3 d-flex align-items-center gap-3">
              <?php if (preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $payment['upload'])): ?>
                <img src="<?= htmlspecialchars($currentProofUrl, ENT_QUOTES, 'UTF-8') ?>"
                     class="img-thumbnail" style="max-height: 90px;" alt="Proof of payment">
              <?php else: ?>
                <i class="bi bi-file-earmark-text fs-2 text-muted" aria-hidden="true"></i>
              <?php endif; ?>
              <div class="flex-grow-1">
                <div class="small text-truncate" title="<?= htmlspecialchars($payment['upload'], ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($payment['upload'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="removeUpload" name="removeUpload" value="1"
                         <?= $form['removeUpload'] ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="removeUpload">
                    Remove this file on save
                  </label>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- New proof -->
        <div class="col-12">
          <label class="form-label" for="proof">
            <?= $currentProofUrl !== '' ? 'Replace Proof of Payment' : 'Proof of Payment' ?>
          </label>
          <input class="form-control" id="proof" name="proof[]" type="file"
                 accept="image/*,application/pdf">
          <div class="form-text">Uploading a new file replaces the existing one. Only one file is stored.</div>
          <div id="proofPreview" class="mt-2 d-flex flex-wrap gap-2"></div>
        </div>

        <!-- Notes -->
        <div class="col-12">
          <label class="form-label" for="notes">Notes</label>
          <textarea class="form-control" id="notes" name="notes" rows="3"
                    placeholder="Optional notes about this payment"><?= htmlspecialchars($form['notes'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
      </div>

      <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
        <a class="btn btn-outline-secondary" href="payments.php">Cancel</a>
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
        <div class="activity-item"><span class="activity-dot bg-success"></span><div><p class="mb-1 fw-semibold">Safe to edit</p><p class="text-muted small mb-0">Date, mode, reference, notes, proof file.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-warning"></span><div><p class="mb-1 fw-semibold">Amount paid</p><p class="text-muted small mb-0">Editing the amount triggers a full replay of this loan's payments.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-danger"></span><div><p class="mb-1 fw-semibold">Loan is fixed</p><p class="text-muted small mb-0">To move a payment to a different loan, delete and re-record it.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-primary"></span><div><p class="mb-1 fw-semibold">Recomputed status</p><p class="text-muted small mb-0">The loan's status (active / cleared / overdue) is redetermined from dates and remaining balance.</p></div></div>
      </div>
    </div>
  </div>
</section>

<script>
(() => {
  /* Proof preview */
  const $proof   = document.getElementById('proof');
  const $preview = document.getElementById('proofPreview');
  if ($proof && $preview) {
    $proof.addEventListener('change', () => {
      $preview.innerHTML = '';
      Array.from($proof.files).forEach(f => {
        const url = URL.createObjectURL(f);
        const html = f.type.startsWith('image/')
          ? `<img src="${url}" class="img-thumbnail" style="max-height:100px" alt="Preview">`
          : `<span class="badge text-bg-secondary">${f.name}</span>`;
        $preview.insertAdjacentHTML('beforeend', html);
      });
    });
  }

  /* Bootstrap validation */
  document.querySelectorAll('.needs-validation').forEach(form => {
    form.addEventListener('submit', e => {
      if (!form.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';