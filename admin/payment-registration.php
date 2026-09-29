<?php
$pageTitle = 'Record Payment';
$activeNav = 'payments';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ----------------------------------------------------------------
   UPLOAD CONFIG
---------------------------------------------------------------- */
$uploadDirFs = __DIR__ . '/assets/images/payment-attachments/';
$maxBytes    = 5 * 1024 * 1024;   // 5 MB
$allowedMime = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'image/gif'       => 'gif',
    'application/pdf' => 'pdf',
];
if (!is_dir($uploadDirFs)) {
    @mkdir($uploadDirFs, 0775, true);
}

/* ---------- HELPERS ---------- */
function gen_payment_code(mysqli $conn): string {
    $res  = $conn->query("SELECT MAX(id) AS max_id FROM payments");
    $row  = $res ? $res->fetch_assoc() : null;
    $next = ((int)($row['max_id'] ?? 0)) + 1;
    return 'PY-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
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

/* Map form value → DB enum */
function normalize_payment_mode(string $mode): string {
    return match ($mode) {
        'mpesa'         => 'mpesa',
        'bank'          => 'bank_transfer',
        'bank_transfer' => 'bank_transfer',
        'cash'          => 'cash',
        'cheque'        => 'cheque',
        'card'          => 'card',
        default         => 'other',
    };
}

/* ----------------------------------------------------------------
   FETCH DROPDOWN DATA
---------------------------------------------------------------- */
$clients = [];
$res = $conn->query("SELECT id, client_code, full_name, email FROM clients ORDER BY full_name ASC");
if ($res) { while ($r = $res->fetch_assoc()) $clients[] = $r; $res->free(); }

$loans = [];
$res = $conn->query(
    "SELECT l.id, l.loan_code, l.client_id,
            l.principal_amount, l.total_due, l.balance,
            l.status, l.purpose
     FROM loans l
     WHERE l.status IN ('active','defaulted')
     ORDER BY l.id DESC"
);
if ($res) { while ($r = $res->fetch_assoc()) $loans[] = $r; $res->free(); }

/* ----------------------------------------------------------------
   HANDLE POST
---------------------------------------------------------------- */
$errors = [];
$form = [
    'client'     => '',
    'loanId'     => '',
    'amountPaid' => '',
    'datePaid'   => date('Y-m-d'),
    'mode'       => '',
    'reference'  => '',
    'notes'      => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    foreach ($form as $k => $v) {
        $form[$k] = trim($_POST[$k] ?? $v);
    }

    /* ---- Validation ---- */
    $clientId = (int) $form['client'];
    if ($clientId <= 0) $errors[] = 'Please choose a client.';

    $loanId = (int) $form['loanId'];
    if ($loanId <= 0) $errors[] = 'Please choose a loan.';

    $amountPaid = (float) $form['amountPaid'];
    if ($amountPaid <= 0) $errors[] = 'Amount paid must be greater than zero.';

    if ($form['datePaid'] === '' ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['datePaid'])) {
        $errors[] = 'Date paid is invalid.';
    }

    if ($form['mode'] === '') $errors[] = 'Please choose a payment mode.';
    $mode = normalize_payment_mode($form['mode']);

    /* ---- Verify client ↔ loan + remaining balance ---- */
    $loan = null;
    if (empty($errors)) {
        $stmt = $conn->prepare(
            "SELECT id, client_id, loan_code, total_due, amount_paid, balance, status
             FROM loans WHERE id = ? LIMIT 1"
        );
        $stmt->bind_param("i", $loanId);
        $stmt->execute();
        $loan = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$loan) {
            $errors[] = 'Selected loan no longer exists.';
        } elseif ((int) $loan['client_id'] !== $clientId) {
            $errors[] = 'The selected loan does not belong to the selected client.';
        } elseif (in_array($loan['status'], ['cleared','written_off'], true)) {
            $errors[] = 'This loan is already ' . $loan['status'] . ' and cannot receive payments.';
        } elseif ($amountPaid > (float) $loan['balance'] + 0.001) {
            $errors[] = 'Amount exceeds the outstanding balance of Ksh '
                      . number_format((float) $loan['balance'], 2) . '.';
        }
    }

    /* ---- Handle proof upload (first file only — matches `upload` column) ---- */
    $uploadedFile = null;
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
            else      $uploadedFile = $fname;
        }
    }

    /* ---- Insert payment + update loan (transaction) ---- */
    if (empty($errors)) {
        $paymentCode = gen_payment_code($conn);
        $reference   = $form['reference'] !== '' ? $form['reference'] : null;
        $remarks     = $form['notes']     !== '' ? $form['notes']     : null;

        $conn->begin_transaction();
        try {
            /* 1) Insert payment */
            $stmt = $conn->prepare(
                "INSERT INTO payments
                 (payment_code, client_id, loan_id, amount_paid, payment_date,
                  mode_of_payment, reference_number, remarks, upload)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) throw new Exception('Prepare failed: ' . $conn->error);

            $stmt->bind_param(
                "siidsssss",
                $paymentCode,
                $clientId,
                $loanId,
                $amountPaid,
                $form['datePaid'],
                $mode,
                $reference,
                $remarks,
                $uploadedFile
            );
            if (!$stmt->execute()) throw new Exception('Insert payment failed: ' . $stmt->error);
            $stmt->close();

            /* 2) Recompute loan.amount_paid from all payments + auto-clear */
            $upd = $conn->prepare(
                "UPDATE loans l
                 SET l.amount_paid = (
                     SELECT COALESCE(SUM(p.amount_paid), 0)
                     FROM payments p WHERE p.loan_id = l.id
                 ),
                 l.status = CASE
                     WHEN (SELECT COALESCE(SUM(p.amount_paid), 0)
                           FROM payments p WHERE p.loan_id = l.id) >= l.total_due
                         THEN 'cleared'
                     ELSE l.status
                 END
                 WHERE l.id = ?"
            );
            if (!$upd) throw new Exception('Prepare update failed: ' . $conn->error);
            $upd->bind_param("i", $loanId);
            if (!$upd->execute()) throw new Exception('Update loan failed: ' . $upd->error);
            $upd->close();

            $conn->commit();

            $_SESSION['flash'] = "Payment {$paymentCode} of Ksh "
                              . number_format($amountPaid, 2)
                              . " recorded for {$loan['loan_code']}.";

            $conn->close();
            header("Location: payments.php");
            exit;

        } catch (Throwable $ex) {
            $conn->rollback();
            $errors[] = 'Transaction failed: ' . $ex->getMessage();
        }
    }
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Payments</p>
      <h1 class="h3 mb-1">Record Payment</h1>
      <p class="text-muted mb-0">Record a new payment against an existing loan.</p>
    </div>
  </div>
  <div class="heading-actions">
    <a class="btn btn-outline-secondary btn-sm" href="payments.php">
      <i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Payments
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
            <span>Payment Information</span>
          </h2>
          <p class="text-muted mb-0">Select the loan, enter the amount, and attach proof of payment.</p>
        </div>
      </div>

      <div class="row g-3">
        <!-- Client -->
        <div class="col-md-6">
          <label class="form-label" for="client">Client</label>
          <select class="form-select" id="client" name="client" required>
            <option value="">Choose registered client</option>
            <?php foreach ($clients as $c): ?>
              <option value="<?= (int) $c['id'] ?>"
                <?= ((string) $c['id'] === $form['client']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($c['full_name'] . ' (' . ($c['email'] ?? 'no email') . ')', ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Please choose a client.</div>
        </div>

        <!-- Loan -->
        <div class="col-md-6">
          <label class="form-label" for="loanId">Loan</label>
          <select class="form-select" id="loanId" name="loanId" required disabled>
            <option value="">— Select a client first —</option>
          </select>
          <div class="invalid-feedback">Please choose a loan.</div>
          <div class="form-text" id="loanHint">Loans will appear after you pick a client.</div>
        </div>

        <!-- Amount Paid -->
        <div class="col-md-6">
          <label class="form-label" for="amountPaid">Amount Paid (Ksh)</label>
          <input class="form-control" id="amountPaid" name="amountPaid" type="number"
                 min="1" step="0.01" required
                 value="<?= htmlspecialchars($form['amountPaid'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Enter the amount paid.</div>
          <div class="form-text" id="balanceHint"></div>
        </div>

        <!-- Date Paid -->
        <div class="col-md-6">
          <label class="form-label" for="datePaid">Date Paid</label>
          <input class="form-control" id="datePaid" name="datePaid" type="date" required
                 value="<?= htmlspecialchars($form['datePaid'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="invalid-feedback">Date paid is required.</div>
        </div>

        <!-- Mode -->
        <div class="col-md-6">
          <label class="form-label" for="mode">Mode of Payment</label>
          <select class="form-select" id="mode" name="mode" required>
            <option value="">Choose mode</option>
            <option value="mpesa"  <?= $form['mode']==='mpesa'  ? 'selected':'' ?>>Mpesa</option>
            <option value="bank"   <?= $form['mode']==='bank'   ? 'selected':'' ?>>Bank Transfer</option>
            <option value="cash"   <?= $form['mode']==='cash'   ? 'selected':'' ?>>Cash</option>
            <option value="cheque" <?= $form['mode']==='cheque' ? 'selected':'' ?>>Cheque</option>
            <option value="card"   <?= $form['mode']==='card'   ? 'selected':'' ?>>Card</option>
          </select>
          <div class="invalid-feedback">Choose a payment mode.</div>
        </div>

        <!-- Reference -->
        <div class="col-md-6">
          <label class="form-label" for="reference">Reference / Transaction No</label>
          <input class="form-control" id="reference" name="reference" type="text"
                 placeholder="e.g., MPESA-XXXX1234"
                 value="<?= htmlspecialchars($form['reference'], ENT_QUOTES, 'UTF-8') ?>">
          <div class="form-text">Optional. Useful for reconciliation.</div>
        </div>

        <!-- Proof -->
        <div class="col-12">
          <label class="form-label" for="proof">Proof of Payment (Media Upload)</label>
          <input class="form-control" id="proof" name="proof[]" type="file"
                 accept="image/*,application/pdf" multiple>
          <div class="form-text">Upload screenshots or receipts. Accepted: JPG, PNG, PDF. Only the first file is stored.</div>
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
          <i class="bi bi-check-circle" aria-hidden="true"></i> Record Payment
        </button>
      </div>
    </form>
  </div>

  <div class="col-12 col-xl-4">
    <div class="panel h-100">
      <h2 class="h5 mb-3 section-title">
        <i class="bi bi-list-check" aria-hidden="true"></i>
        <span>Payment Checklist</span>
      </h2>
      <div class="activity-list">
        <div class="activity-item"><span class="activity-dot bg-success"></span><div><p class="mb-1 fw-semibold">Select client</p><p class="text-muted small mb-0">Only registered clients appear here.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-primary"></span><div><p class="mb-1 fw-semibold">Link to a loan</p><p class="text-muted small mb-0">Payments must be linked to a specific loan.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-warning"></span><div><p class="mb-1 fw-semibold">Enter amount</p><p class="text-muted small mb-0">Confirm the amount matches the receipt.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-info"></span><div><p class="mb-1 fw-semibold">Attach proof</p><p class="text-muted small mb-0">Upload Mpesa message or bank slip.</p></div></div>
        <div class="activity-item"><span class="activity-dot bg-secondary"></span><div><p class="mb-1 fw-semibold">Submit</p><p class="text-muted small mb-0">The loan balance updates automatically.</p></div></div>
      </div>
    </div>
  </div>
</section>

<script>
(() => {
  const LOANS = <?= json_encode($loans, JSON_NUMERIC_CHECK) ?>;

  const $client = document.getElementById('client');
  const $loan   = document.getElementById('loanId');
  const $amount = document.getElementById('amountPaid');
  const $hint   = document.getElementById('loanHint');
  const $bal    = document.getElementById('balanceHint');

  const preselectedLoan = "<?= htmlspecialchars($form['loanId'], ENT_QUOTES, 'UTF-8') ?>";

  function fmtKsh(n) {
    return 'Ksh ' + Number(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
  }

  function refreshLoans() {
    const clientId = $client.value;
    $loan.innerHTML = '';

    if (!clientId) {
      $loan.disabled = true;
      $loan.innerHTML = '<option value="">— Select a client first —</option>';
      $hint.textContent = 'Loans will appear after you pick a client.';
      $bal.textContent = '';
      return;
    }

    const matches = LOANS.filter(l => String(l.client_id) === String(clientId));

    if (matches.length === 0) {
      $loan.disabled = true;
      $loan.innerHTML = '<option value="">No active loans for this client</option>';
      $hint.textContent = 'This client has no active loans to pay.';
      $bal.textContent = '';
      return;
    }

    $loan.disabled = false;
    $loan.innerHTML = '<option value="">Choose loan</option>';
    matches.forEach(l => {
      const opt = document.createElement('option');
      opt.value = l.id;
      opt.textContent = l.loan_code
        + ' — ' + (l.purpose || 'Loan')
        + ' (Balance: ' + fmtKsh(l.balance) + ')';
      opt.dataset.balance = l.balance;
      if (String(l.id) === String(preselectedLoan)) opt.selected = true;
      $loan.appendChild(opt);
    });

    $hint.textContent = matches.length + ' active loan(s) found.';
    updateBalanceHint();
  }

  function updateBalanceHint() {
    const opt = $loan.options[$loan.selectedIndex];
    if (!opt || !opt.dataset.balance) {
      $bal.textContent = '';
      $amount.removeAttribute('max');
      return;
    }
    const bal = parseFloat(opt.dataset.balance);
    $bal.textContent = 'Outstanding balance: ' + fmtKsh(bal);
    $amount.max = bal;
  }

  $client.addEventListener('change', refreshLoans);
  $loan.addEventListener('change', updateBalanceHint);
  refreshLoans();

  /* Proof preview */
  const $proof   = document.getElementById('proof');
  const $preview = document.getElementById('proofPreview');
  if ($proof && $preview) {
    $proof.addEventListener('change', () => {
      $preview.innerHTML = '';
      Array.from($proof.files).forEach((f, idx) => {
        const url = URL.createObjectURL(f);
        const label = idx === 0 ? ' <span class="badge text-bg-primary">Stored</span>' : '';
        const html = f.type.startsWith('image/')
          ? `<img src="${url}" class="img-thumbnail" style="max-height:100px" alt="Preview">${label}`
          : `<span class="badge text-bg-secondary">${f.name}</span>${label}`;
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