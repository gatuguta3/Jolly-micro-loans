<?php

require_once __DIR__ . '/../config/db_connection.php';
require_once __DIR__ . '/loan-config.php';




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

    $sql = "SELECT id, date_issued, due_date,
                   principal_amount, principal_balance,
                   interest_rate, interest_balance,
                   last_accrual_date
            FROM loans
            WHERE loan_category = 'monthly'
              AND status IN ('active','overdue')
              AND principal_balance > 0";

    $res = $conn->query($sql);
    if (!$res) return 0;

    $update = $conn->prepare(
        "UPDATE loans SET interest_balance = ?, last_accrual_date = ? WHERE id = ?"
    );
    if (!$update) { $res->free(); return 0; }

    $today   = new DateTime('today');
    $changed = 0;

    while ($loan = $res->fetch_assoc()) {

        $principal_amount  = (float) $loan['principal_amount'];
        $principal_balance = (float) $loan['principal_balance'];
        $interest_balance  = (float) $loan['interest_balance'];

        /* No outstanding principal → nothing to charge */
        if ($principal_balance <= 0) continue;

        /* Tier rate — determined by the ORIGINAL principal,
           not the current balance. Once set, the tier stays fixed. */
        $baseRate = null;
        foreach ($tiers as $t) {
            if ($principal_amount >= $t['min'] && $principal_amount <= $t['max']) {
                $baseRate = (float) $t['rate'];
                break;
            }
        }
        if ($baseRate === null) continue;

        /* Checkpoint — where we last billed from */
        if (!empty($loan['last_accrual_date'])) {
            $checkpoint = new DateTime($loan['last_accrual_date']);
        } else {
            $checkpoint = new DateTime($loan['date_issued']);
            $checkpoint->modify('+1 month');
        }

        /* Freeze — stop accruing at due + 3 months */
        $due    = new DateTime($loan['due_date']);
        $freeze = (clone $due)->modify('+3 months');
        $cap    = $today < $freeze ? $today : $freeze;

        /* Walk month by month */
        $monthsBilled = 0;

        while (true) {
            $next = (clone $checkpoint)->modify('+1 month');
            if ($next > $cap) break;

            /* Interest for one month, on the OUTSTANDING PRINCIPAL */
            $monthlyInterest   = round($principal_balance * $baseRate / 100, 2);
            $interest_balance += $monthlyInterest;

            $checkpoint = $next;
            $monthsBilled++;
        }

        if ($monthsBilled === 0) continue;

        $id      = (int) $loan['id'];
        $lastStr = $checkpoint->format('Y-m-d');

        $update->bind_param("dsi", $interest_balance, $lastStr, $id);
        $update->execute();
        $changed++;
    }

    $update->close();
    $res->free();
    return $changed;
}

/* ---------- RUN MONTHLY ACCRUAL ---------- */
accrue_monthly_interest($conn, $loanCategories);

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


