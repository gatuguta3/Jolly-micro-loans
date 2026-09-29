<?php
/**
 * loan-logic.php
 * Central place for every interest, term, and status calculation.
 * Include this from any page that creates, displays, or updates loans.
 *
 * Requires: an active mysqli $conn in scope only when a *status* function
 *           is called (calc_* functions are pure).
 */

/* ================================================================
   CONFIG
================================================================ */

/* --- Monthly: tiered rate --- */
const MONTHLY_TIERS = [
    ['min' => 5000,   'max' => 100000,  'rate' => 20.00],
    ['min' => 100001, 'max' => 500000,  'rate' => 18.00],
    ['min' => 500001, 'max' => 1000000, 'rate' => 15.00],
];
const MONTHLY_TERM_DAYS = 30;

/* --- Daily: flat 25 KSH per 1000 KSH --- */
const DAILY_RATE_PER_1000 = 25.00;   // KSH of interest per KSH 1000
const DAILY_TERM_DAYS     = 30;

/* --- Boost: 0% for first 5 days, 20% after --- */
const BOOST_GRACE_DAYS = 5;
const BOOST_RATE       = 20.00;
const BOOST_TERM_DAYS  = 30;

/* Global bounds */
const LOAN_MIN_PRINCIPAL = 5000.00;
const LOAN_MAX_PRINCIPAL = 1000000.00;

/* ================================================================
   INTEREST CALCULATIONS (pure)
================================================================ */

/**
 * Tier-based rate for monthly loans. Returns null if out of range.
 */
function calc_monthly_rate(float $principal): ?float {
    foreach (MONTHLY_TIERS as $t) {
        if ($principal >= $t['min'] && $principal <= $t['max']) {
            return (float) $t['rate'];
        }
    }
    return null;
}

/**
 * Daily loan rate. Flat 2.5% (25 / 1000 * 100).
 * Returns null below the global minimum.
 */
function calc_daily_rate(float $principal): ?float {
    if ($principal < LOAN_MIN_PRINCIPAL) return null;
    return round(DAILY_RATE_PER_1000 / 1000 * 100, 4);   // 2.5
}

/**
 * Boost rate as a function of days elapsed since issue.
 * Within grace → 0%. After grace → 20%.
 */
function calc_boost_rate(int $daysElapsed): float {
    return $daysElapsed <= BOOST_GRACE_DAYS ? 0.0 : BOOST_RATE;
}

/**
 * Unified calculator.
 * Returns ['rate' => float, 'term_days' => int] or null if out of range.
 * $daysElapsed is only used by boost; pass 0 at registration.
 */
function calc_loan_terms(string $category, float $principal, int $daysElapsed = 0): ?array {
    if ($principal < LOAN_MIN_PRINCIPAL || $principal > LOAN_MAX_PRINCIPAL) {
        return null;
    }

    switch ($category) {
        case 'monthly':
            $rate = calc_monthly_rate($principal);
            if ($rate === null) return null;
            return ['rate' => $rate, 'term_days' => MONTHLY_TERM_DAYS];

        case 'daily':
            $rate = calc_daily_rate($principal);
            if ($rate === null) return null;
            return ['rate' => $rate, 'term_days' => DAILY_TERM_DAYS];

        case 'boost':
            return [
                'rate'      => calc_boost_rate($daysElapsed),
                'term_days' => BOOST_TERM_DAYS,
            ];
    }
    return null;
}

/**
 * Days elapsed between two dates (positive if $to > $from).
 */
function days_between(string $from, string $to): int {
    $a = new DateTime($from);
    $b = new DateTime($to);
    return (int) $a->diff($b)->format('%r%a');
}

/**
 * Interest amount = principal × rate / 100, rounded to 2 decimals.
 */
function calc_interest_amount(float $principal, float $rate): float {
    return round($principal * $rate / 100, 2);
}

/**
 * Total due = principal + interest.
 */
function calc_total_due(float $principal, float $rate): float {
    return round($principal + calc_interest_amount($principal, $rate), 2);
}

/**
 * Due date = issue date + term_days.
 */
function calc_due_date(string $issueDate, int $termDays): string {
    $dt = new DateTime($issueDate);
    $dt->modify("+{$termDays} days");
    return $dt->format('Y-m-d');
}

/* ================================================================
   DISPLAY HELPERS
================================================================ */

function loan_category_label(string $category): string {
    return [
        'monthly' => 'Monthly Loan',
        'daily'   => 'Daily Loan',
        'boost'   => 'Boost Loan',
    ][$category] ?? ucfirst($category);
}

function loan_category_rate_hint(string $category): string {
    switch ($category) {
        case 'monthly': return 'Tiered: 20% / 18% / 15% by amount';
        case 'daily':   return 'Flat 25 KSH per 1,000 KSH';
        case 'boost':   return '0% for 5 days, then 20%';
    }
    return '';
}

/**
 * The amount currently owed on a loan, honouring the boost grace period.
 *
 * - If the loan is not boost → returns the DB-computed balance.
 * - If it's a boost loan still in grace → returns principal - amount_paid (0% interest).
 * - If it's a boost loan past grace → returns the DB-computed balance (20% interest).
 *
 * $loan = an associative array from the loans table (must include loan_category,
 *         grace_days, principal_amount, amount_paid, balance, date_issued).
 */
function effective_balance(array $loan, ?string $asOfDate = null): float {
    if (($loan['loan_category'] ?? '') !== 'boost') {
        return (float) $loan['balance'];
    }

    $asOf     = $asOfDate ?: date('Y-m-d');
    $elapsed  = days_between($loan['date_issued'], $asOf);
    $grace    = (int) ($loan['grace_days'] ?? BOOST_GRACE_DAYS);

    if ($elapsed <= $grace) {
        // Within grace → principal only, ignore stored interest
        return max(0.0, round((float) $loan['principal_amount'] - (float) $loan['amount_paid'], 2));
    }
    return (float) $loan['balance'];
}

/* ================================================================
   STATUS / BALANCE UPDATES (need $conn in scope)
================================================================ */

/**
 * Recompute amount_paid from all payments, then set status.
 * Call after any payment insert / update / delete.
 */
function refresh_loan_status(mysqli $conn, int $loanId): void {
    /* 1) Sum payments */
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount_paid), 0) AS paid FROM payments WHERE loan_id = ?");
    $stmt->bind_param("i", $loanId);
    $stmt->execute();
    $paid = (float) ($stmt->get_result()->fetch_assoc()['paid'] ?? 0);
    $stmt->close();

    /* 2) Load the loan */
    $stmt = $conn->prepare(
        "SELECT loan_category, grace_days, principal_amount, total_due, date_issued, due_date, status
         FROM loans WHERE id = ? LIMIT 1"
    );
    $stmt->bind_param("i", $loanId);
    $stmt->execute();
    $loan = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$loan) return;

    /* 3) Effective balance right now (boost-aware) */
    $loan['amount_paid'] = $paid;
    $loan['balance']     = round((float) $loan['total_due'] - $paid, 2);
    $effectiveBalance    = effective_balance($loan);

    /* 4) Decide new status */
    $newStatus = $loan['status'];
    if ($loan['status'] !== 'written_off') {
        if ($effectiveBalance <= 0.009) {
            $newStatus = 'cleared';
        } elseif (strtotime($loan['due_date']) < strtotime('today')) {
            $newStatus = 'defaulted';
        } else {
            $newStatus = 'active';
        }
    }

    /* 5) Persist */
    $upd = $conn->prepare("UPDATE loans SET amount_paid = ?, status = ? WHERE id = ?");
    $upd->bind_param("dsi", $paid, $newStatus, $loanId);
    $upd->execute();
    $upd->close();
}

/**
 * Flag every active loan past its due date as defaulted.
 * Returns the number of rows changed.
 */
function refresh_all_overdue_loans(mysqli $conn): int {
    $conn->query(
        "UPDATE loans
         SET status = 'defaulted'
         WHERE status = 'active'
           AND due_date < CURRENT_DATE
           AND balance > 0"
    );
    return $conn->affected_rows;
}