<?php
$pageTitle = 'Loans';
$activeNav = 'loans';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- SHARED LOAN CONFIG ---------- */
require_once __DIR__ . '/loan-config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}




/* ================================================================
   MONTHLY INTEREST ACCRUAL

   Rule: month 1 is billed at registration and lives in interest_amount.
   Every month after that, interest is compounded on the current balance.

   The function checks last_accrual_date:
     • If today >= last_accrual_date + 1 month → one month has passed
     • It then walks month by month up to min(today, due + 3 months),
       compounding interest on the balance at each step
     • Updates interest_amount and advances last_accrual_date
     • No write happens unless a full month has elapsed

   The DB auto-recomputes total_due and balance after each update.

   Only monthly, active/overdue loans with a positive balance are touched.
================================================================ */
function accrue_monthly_interest(mysqli $conn, array $categories): int {
    $tiers = $categories['monthly']['tiers'] ?? [];
    if (empty($tiers)) return 0;

    /* Hours to wait after a monthly billing date before applying interest */
    $graceHours = 72;

    $sql = "SELECT id, date_issued, due_date,
                   principal_amount, principal_balance,
                   interest_rate, interest_amount, interest_balance,
                   last_accrual_date
            FROM loans
            WHERE loan_category = 'monthly'
              AND status IN ('active','overdue')
              AND principal_balance > 0";

    $res = $conn->query($sql);
    if (!$res) return 0;

    $update = $conn->prepare(
        "UPDATE loans
         SET interest_amount  = ?,
             interest_balance = ?,
             last_accrual_date = ?
         WHERE id = ?"
    );
    if (!$update) { $res->free(); return 0; }

    $now     = new DateTime('now');
    $today   = new DateTime('today');
    $changed = 0;

    while ($loan = $res->fetch_assoc()) {

        $principal_amount  = (float) $loan['principal_amount'];
        $principal_balance = (float) $loan['principal_balance'];
        $interest_amount   = (float) $loan['interest_amount'];
        $interest_balance  = (float) $loan['interest_balance'];

        if ($principal_balance <= 0) continue;

        /* Tier rate — based on the ORIGINAL principal */
        $baseRate = null;
        foreach ($tiers as $t) {
            if ($principal_amount >= $t['min'] && $principal_amount <= $t['max']) {
                $baseRate = (float) $t['rate'];
                break;
            }
        }
        if ($baseRate === null) continue;

        /* Checkpoint — either stored, or "issue + 1 month" as fallback */
        if (!empty($loan['last_accrual_date'])) {
            $checkpoint = new DateTime($loan['last_accrual_date'] . ' 00:00:00');
        } else {
            $checkpoint = new DateTime($loan['date_issued'] . ' 00:00:00');
            $checkpoint->modify('+1 month');
        }

        /* Freeze — 3 months past DUE date (not issue date) */
        $due     = new DateTime($loan['due_date'] . ' 00:00:00');
        $freeze  = (clone $due)->modify('+2 months');

        /* Cap the whole accrual at whichever is earlier: today's midnight, or the freeze */
        $cap = $today < $freeze ? clone $today : clone $freeze;

        /* Walk month by month */
        $interestAdded = 0.0;
        $monthsBilled  = 0;

        while (true) {
            /* Billing candidate for this period */
            $billingDate = clone $checkpoint;

            /* Only bill if a full month has elapsed AND the 72h grace has passed */
            $graceExpires = (clone $billingDate)->modify("+{$graceHours} hours");

            if ($billingDate > $cap) break;         // past the freeze
            if ($now < $graceExpires) break;        // still within grace

            /* Apply the month's interest */
            $monthlyInterest   = round($principal_balance * $baseRate / 100, 2);
            $interest_balance += $monthlyInterest;
            $interestAdded    += $monthlyInterest;

            /* Advance checkpoint to the billing date (start of the NEXT month) */
            $checkpoint = (clone $billingDate)->modify('+1 month');
            $monthsBilled++;
        }

        if ($monthsBilled === 0) continue;

        $interest_amount = round($interest_amount + $interestAdded, 2);

        $id      = (int) $loan['id'];
        $lastStr = $checkpoint->format('Y-m-d');

        $update->bind_param("ddsi", $interest_amount, $interest_balance, $lastStr, $id);
        $update->execute();
        $changed++;
    }

    $update->close();
    $res->free();
    return $changed;
}

/* ---------- RUN MONTHLY ACCRUAL ---------- */
accrue_monthly_interest($conn, $loanCategories);

/* ----------------------------------------------------------------
   Loan functions and helpers
---------------------------------------------------------------- */



/* ================================================================
   STATUS ENGINE — active / overdue / defaulted / written-off
================================================================ */

function add_duration_units(DateTime $date, string $category, int $units): DateTime {
    $out = clone $date;

    if ($category === 'monthly') {
        $out->modify('+' . max(0, $units) . ' months');
    } else {
        $out->modify('+' . max(0, $units) . ' days');
    }

    return $out;
}

function compute_default_date(array $loan): DateTime {
    $category = $loan['loan_category'] ?? 'monthly';
    $dueDate  = new DateTime($loan['due_date']);

    /* Defaulted at due_date + 3 */
    return add_duration_units($dueDate, $category, 3);
}

function compute_writeoff_date(array $loan): DateTime {
    $category = $loan['loan_category'] ?? 'monthly';
    $dueDate  = new DateTime($loan['due_date']);

    /* Written off at due_date + 6 */
    return add_duration_units($dueDate, $category, 6);
}

function determine_loan_status(array $loan, DateTime $today): string {
    $current  = $loan['status'];
    $category = $loan['loan_category'] ?? 'monthly';

    /* Terminal states */
    if ($current === 'written_off') return 'written_off';
    if ($current === 'cleared')     return 'cleared';

    /* Cleared wins */
    $balance = (float) ($loan['balance'] ?? 0);
    if ($balance <= 0.009) return 'cleared';

    $dueDate = new DateTime($loan['due_date']);

    /* Active until the due date */
    if ($today < $dueDate) return 'active';

    /* Past due — walk the thresholds */
    $defaultDate  = compute_default_date($loan);     // due + 3
    $writeOffDate = compute_writeoff_date($loan);    // due + 6

    if ($today >= $writeOffDate) return 'written_off';
    if ($today >= $defaultDate)  return 'defaulted';

    return 'overdue';
}

function refresh_loan_statuses(mysqli $conn): int {
    $sql = "SELECT id, loan_category, date_issued, due_date, duration,
                   principal_amount, amount_paid, balance, status
            FROM loans
            WHERE status NOT IN ('written_off','cleared')";

    $res = $conn->query($sql);
    if (!$res) return 0;

    $today   = new DateTime('today');
    $updates = [];

    while ($loan = $res->fetch_assoc()) {
        $newStatus = determine_loan_status($loan, $today);
        if ($newStatus !== $loan['status']) {
            $updates[] = [(int) $loan['id'], $newStatus];
        }
    }
    $res->free();

    if (empty($updates)) return 0;

    $stmt = $conn->prepare("UPDATE loans SET status = ? WHERE id = ?");
    if (!$stmt) return 0;

    foreach ($updates as $u) {
        $stmt->bind_param("si", $u[1], $u[0]);
        $stmt->execute();
    }
    $stmt->close();

    return count($updates);
}

/* ---------- RUN STATUS REFRESH ---------- */
refresh_loan_statuses($conn);



/* ----------------------------------------------------------------
   Chama remitance functions
---------------------------------------------------------------- */




/* ----------------------------------------------------------------
   AGGREGATE STATS FOR CARDS
---------------------------------------------------------------- */
$stats = [
    'total_principal' => 0.0,
    'total_interest'  => 0.0,
    'total_due'       => 0.0,
    'total_loans'     => 0,
    'urgent'          => 0,
];

$res = $conn->query(
    "SELECT
        COALESCE(SUM(principal_amount), 0) AS total_principal,
        COALESCE(SUM(interest_amount), 0)  AS total_interest,
        COALESCE(SUM(total_due), 0)        AS total_due,
        COUNT(*)                           AS total_loans,
        SUM(CASE WHEN status = 'active'
                  AND due_date <= DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY)
                 THEN 1 ELSE 0 END)        AS urgent
     FROM loans"
);
if ($res && $row = $res->fetch_assoc()) {
    $stats['total_principal'] = (float) $row['total_principal'];
    $stats['total_interest']  = (float) $row['total_interest'];
    $stats['total_due']       = (float) $row['total_due'];
    $stats['total_loans']     = (int)   $row['total_loans'];
    $stats['urgent']          = (int)   $row['urgent'];
    $res->free();
}

/* ----------------------------------------------------------------
   LOANS LIST (recent first)
---------------------------------------------------------------- */
$loans = [];
$res = $conn->query(
    "SELECT
        l.id,
        l.loan_code,
        l.loan_category,
        l.date_issued,
        l.principal_amount,
        l.interest_rate,
        l.interest_amount,
        l.total_due,
        l.principal_balance,
        l.interest_balance,
        l.due_date,
        l.status,
        c.id         AS client_id,
        c.full_name  AS client_name,
        c.email      AS client_email,
        c.client_code,
        c.photo_url  AS client_photo
     FROM loans l
     INNER JOIN clients c ON c.id = l.client_id
     ORDER BY l.date_issued DESC"
);
if ($res) {
    while ($r = $res->fetch_assoc()) $loans[] = $r;
    $res->free();
}

/* ---------- HELPERS ---------- */
if (!function_exists('loan_avatar_svg')) {
    function loan_avatar_svg($name) {
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

function loan_status_badge(string $status): array {
    return match ($status) {
        'active'      => ['text-bg-success',   'Active'],
        'overdue'     => ['text-bg-warning',   'Overdue'],
        'defaulted'   => ['text-bg-danger',    'Defaulted'],
        'cleared'     => ['text-bg-primary',   'Cleared'],
        'written_off' => ['text-bg-secondary', 'Written Off'],
        default       => ['text-bg-secondary', ucfirst($status)],
    };
}

function ksh(float $n): string {
    return 'Ksh ' . number_format($n, 2);
}

function category_label(string $c): string {
    return [
        'monthly' => 'Monthly',
        'daily'   => 'Daily',
        'boost'   => 'Jolly Boost',
    ][$c] ?? ucfirst($c);
}

ob_start();
?>

<div class="page-heading">
  <div class="page-heading-copy">
    <span class="page-icon"><i class="bi bi-cash-coin" aria-hidden="true"></i></span>
    <div>
      <p class="eyebrow mb-1">Overview</p>
      <h1 class="h3 mb-1">Loans</h1>
      <p class="text-muted mb-0">Monitor loan performance, balances, and upcoming due dates.</p>
    </div>
  </div>
  <div class="heading-actions">
    
    <a class="btn btn-primary btn-sm" href="chama-remittance.php">
        <i class="bi bi-cash-stack" aria-hidden="true"></i> Chama remmitance
      </a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success"><?= htmlspecialchars($_SESSION['flash'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<!-- ============ Metric cards ============ -->
<section class="row g-3 mt-1" aria-label="Loan metrics">

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-primary">
      <div class="metric-top">
        <span class="metric-label">Total Principal</span>
        <span class="metric-icon"><i class="bi bi-currency-dollar" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= ksh($stats['total_principal']) ?></div>
      <div class="metric-meta">
        <span class="text-muted">across all loans</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-success">
      <div class="metric-top">
        <span class="metric-label">Interest</span>
        <span class="metric-icon"><i class="bi bi-bag-check" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= ksh($stats['total_interest']) ?></div>
      <div class="metric-meta">
        <span class="text-muted">expected total</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-warning">
      <div class="metric-top">
        <span class="metric-label">Total Amount Expected</span>
        <span class="metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= ksh($stats['total_due']) ?></div>
      <div class="metric-meta">
        <span class="text-muted">principal + interest</span>
      </div>
    </article>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <article class="metric-card metric-danger">
      <div class="metric-top">
        <span class="metric-label">Total Loans</span>
        <span class="metric-icon"><i class="bi bi-life-preserver" aria-hidden="true"></i></span>
      </div>
      <div class="metric-value"><?= number_format($stats['total_loans']) ?></div>
      <div class="metric-meta">
        <span class="text-danger"><?= (int) $stats['urgent'] ?> urgent</span>
        <span>need review</span>
      </div>
    </article>
  </div>

</section>

<!-- ============ Loans table ============ -->
<section class="panel mt-3">
  <div class="panel-header">
    <div>
      <h2 class="h5 mb-1 section-title">
        <i class="bi bi-cash-coin" aria-hidden="true"></i>
        <span>Recent Loans</span>
      </h2>
      <p class="text-muted mb-0">Latest loan activity across the workspace.</p>
    </div>
    <div class="heading-actions">
      <div class="d-flex justify-content-center gap-2">
        <input type="search"
               class="form-control form-control-sm"
               id="loanSearch"
               placeholder="Search loans..."
               style="max-width: 220px;">
      </div>
      <select class="form-select form-select-sm" id="statusFilter" style="max-width: 160px;">
        <option value="">All statuses</option>
        <option value="active">Active</option>
        <option value="overdue">Overdue</option>
        <option value="defaulted">Defaulted</option>
        <option value="cleared">Cleared</option>
        <option value="written_off">Written Off</option>
      </select>
      <button class="btn btn-outline-secondary btn-sm" type="button">
        <i class="bi bi-download" aria-hidden="true"></i> Export
      </button>
      <a class="btn btn-primary btn-sm" href="loan-registration.php">
        <i class="bi bi-plus-circle" aria-hidden="true"></i> Add Loan
      </a>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table align-middle mb-0" id="clientLoansTable">
      <thead>
        <tr>
          <th scope="col">Loan Id</th>
          <th scope="col">Client</th>
          <th scope="col">Date Issued</th>
          <th scope="col">Principal Amount</th>
          <th scope="col">Principal balance</th>
          <th scope="col">Interest balance</th>
          <th scope="col">Due date</th>
          <th scope="col">Status</th>
          <th scope="col" class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>

        <?php if (empty($loans)): ?>
          <tr>
            <td colspan="8" class="text-center text-muted py-4">
              No loans yet. Click <strong>Add Loan</strong> to create the first one.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($loans as $l): ?>
            <?php
              [$badgeClass, $badgeLabel] = loan_status_badge($l['status']);

              $clientPhoto = !empty($l['client_photo'])
                  ? '../assets/client-attachments/' . rawurlencode($l['client_photo'])
                  : loan_avatar_svg($l['client_name']);

              $issuedFmt = $l['date_issued'] ? date('M j, Y', strtotime($l['date_issued'])) : '—';
              $dueFmt    = $l['due_date']    ? date('M j, Y', strtotime($l['due_date']))    : '—';
            ?>
            <tr>
              <td>
                <span class="fw-semibold"><a href="loan-view.php?id=<?= (int) $l['id'] ?>" class="text-decoration-none"><?= htmlspecialchars($l['loan_code']) ?></a></span>
                <p class="text-muted small mb-0"><?= htmlspecialchars(category_label($l['loan_category'] ?? 'monthly'), ENT_QUOTES, 'UTF-8') ?></p>
              </td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img class="avatar-img avatar-sm"
                       src="<?= htmlspecialchars($clientPhoto, ENT_QUOTES, 'UTF-8') ?>"
                       alt="<?= htmlspecialchars($l['client_name'], ENT_QUOTES, 'UTF-8') ?>">
                  <div>
                    <p class="fw-semibold mb-0"><?= htmlspecialchars($l['client_name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="text-muted small mb-0"><?= htmlspecialchars($l['client_email'] ?? '—', ENT_QUOTES, 'UTF-8') ?></p>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($issuedFmt) ?></td>
              <td><?= ksh((float) $l['principal_amount']) ?></td>
              <td><?= ksh((float) $l['principal_balance']) ?></td>
              <td><?= ksh((float) $l['interest_balance']) ?></td>
              <td><?= htmlspecialchars($dueFmt) ?></td>
              <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span></td>
              <td class="text-end">
                <a class="btn btn-light btn-sm" href="loan-view.php?id=<?= (int) $l['id'] ?>">View</a>

                <a class="btn btn-outline-primary btn-sm"
                  href="loan-edit.php?id=<?= (int) $l['id'] ?>"
                  title="Edit loan">
                  <i class="bi bi-pencil-square" aria-hidden="true"></i>
                </a>

                <a class="btn btn-outline-secondary btn-sm"
                  href="loan-print.php?id=<?= (int) $l['id'] ?>"
                  target="_blank" rel="noopener"
                  title="Print statement">
                  <i class="bi bi-printer" aria-hidden="true"></i>
                </a>

                <form method="post" action="loan-delete.php" class="d-inline"
                      onsubmit="return confirm('Delete this loan permanently? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete loan">
                    <i class="bi bi-trash" aria-hidden="true"></i>
                  </button>
                </form>
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
  const $search = document.getElementById('loanSearch');
  const $filter = document.getElementById('statusFilter');
  const $table  = document.getElementById('clientLoansTable');
  if (!$search || !$table) return;

  $search.addEventListener('input', () => {
    const q = $search.value.trim().toLowerCase();
    $table.querySelectorAll('tbody tr').forEach(row => {
      row.style.display = !q || row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
  });
  const applyFilters = () => {
    const q      = ($search ? $search.value : '').trim().toLowerCase();
    const status = ($filter ? $filter.value : '').toLowerCase();
    $table.querySelectorAll('tbody tr').forEach(row => {
      const rowStatus = row.getAttribute('data-status') || '';
      row.style.display = (!q || row.textContent.toLowerCase().includes(q)) && (!status || rowStatus === status) ? '' : 'none';
    });
  };
  $filter.addEventListener('change', applyFilters);
  $search.addEventListener('input', applyFilters);
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/main.php';