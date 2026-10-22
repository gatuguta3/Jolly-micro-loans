<?php
$pageTitle = 'Loan Statement';
$activeNav = 'loans';

require_once __DIR__ . '/../config/db_connection.php';

/* ---------- FPDF ----------
   Adjust this path to wherever you installed FPDF.
   Common locations:
     __DIR__ . '/../assets/fpdf/fpdf.php'
     __DIR__ . '/../assets/fpdf181/fpdf.php'
     __DIR__ . '/../assets/FPDF/fpdf.php'
------------------------------------------------------------ */
$fpdfPath = __DIR__ . '/../assets/fpdf/fpdf.php';
if (!file_exists($fpdfPath)) {
    http_response_code(500);
    exit('FPDF library not found. Check the path in loan-print.php');
}
require_once $fpdfPath;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ---------- AUTH GUARD ---------- */
if (empty($_SESSION['logged_in'])) {
    header('Location: login.php');
    exit;
}

/* ---------- VALIDATE ?id ---------- */
$loanId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($loanId <= 0) {
    header('Location: loans.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH LOAN + CLIENT
---------------------------------------------------------------- */
$stmt = $conn->prepare(
    "SELECT
        l.id, l.loan_code, l.loan_category, l.date_issued, l.duration,
        l.principal_amount, l.principal_balance,
        l.interest_rate, l.interest_amount, l.interest_balance,
        l.total_due, l.balance, l.due_date, l.status,
        l.purpose, l.notes,
        c.id         AS client_id,
        c.client_code,
        c.full_name  AS client_name,
        c.email      AS client_email,
        c.phone      AS client_phone,
        c.id_number  AS client_id_number,
        c.location,
        c.date_of_birth,
        c.gender,
        c.join_date
     FROM loans l
     INNER JOIN clients c ON c.id = l.client_id
     WHERE l.id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $loanId);
$stmt->execute();
$loan = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$loan) {
    header('Location: loans.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH GUARANTORS (via join table if it exists)
---------------------------------------------------------------- */
$guarantors = [];
$hasJoin = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
if ($hasJoin && $hasJoin->num_rows > 0) {
    $stmt = $conn->prepare(
        "SELECT g.id, g.name, g.email, g.phone,
                g.identification_type, g.identification_number
         FROM loan_guarantors lg
         INNER JOIN guarantors g ON g.id = lg.guarantor_id
         WHERE lg.loan_id = ?"
    );
    if ($stmt) {
        $stmt->bind_param("i", $loanId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $guarantors[] = $r;
        $stmt->close();
    }
}

/* ----------------------------------------------------------------
   FETCH PAYMENTS
---------------------------------------------------------------- */
$payments = [];
$stmt = $conn->prepare(
    "SELECT id, payment_code, amount_paid, payment_date,
            mode_of_payment, reference_number, remarks
     FROM payments
     WHERE loan_id = ?
     ORDER BY payment_date ASC, id ASC"
);
if ($stmt) {
    $stmt->bind_param("i", $loanId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $payments[] = $r;
    $stmt->close();
}

/* ----------------------------------------------------------------
   DERIVED VALUES

   With the new schema, "amount paid" is what has been knocked off
   the original principal + interest:
       principal repaid = principal_amount  - principal_balance
       interest  repaid = interest_amount   - interest_balance
       total repaid     = principal repaid + interest repaid
---------------------------------------------------------------- */
$principalPaid = round((float) $loan['principal_amount'] - (float) $loan['principal_balance'], 2);
$interestPaid  = round((float) $loan['interest_amount']  - (float) $loan['interest_balance'],  2);
$totalPaid     = round($principalPaid + $interestPaid, 2);

/* ----------------------------------------------------------------
   HELPERS (ASCII-safe — FPDF uses ISO-8859-1)
---------------------------------------------------------------- */
function lp_ksh($n): string {
    return 'Ksh ' . number_format((float) $n, 2);
}

function lp_date(?string $d): string {
    return $d ? date('M j, Y', strtotime($d)) : '-';
}

function lp_status_label(string $status): string {
    return [
        'active'      => 'ACTIVE',
        'overdue'     => 'OVERDUE',
        'defaulted'   => 'DEFAULTED',
        'cleared'     => 'CLEARED',
        'written_off' => 'WRITTEN OFF',
    ][$status] ?? strtoupper($status);
}

function lp_mode_label(string $mode): string {
    return [
        'cash'          => 'Cash',
        'mpesa'         => 'M-Pesa',
        'bank_transfer' => 'Bank Transfer',
        'cheque'        => 'Cheque',
        'card'          => 'Card',
        'other'         => 'Other',
    ][$mode] ?? ucfirst($mode);
}

function lp_category_label(string $category): string {
    return [
        'monthly' => 'Monthly Loan',
        'daily'   => 'Daily Loan',
        'boost'   => 'Jolly Boost Loan',
    ][$category] ?? ucfirst($category);
}

/* FPDF has no native "clean" text function — strip anything outside Latin-1 */
function lp_safe(?string $s): string {
    $s = (string) $s;
    $s = str_replace(
        ["\xE2\x80\x94", "\xE2\x80\x93", "\xE2\x80\xA6", "\xC2\xB7", "\xE2\x80\xA2"],
        ['-', '-', '...', '-', '-'],
        $s
    );
    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', '?', $s);
}

/* ---------- PREPARE DISPLAY VALUES ---------- */
$reportDate    = date('M j, Y H:i');
$generatedBy   = $_SESSION['user_name'] ?? 'System';
$categoryLabel = $loan['purpose'] ?: lp_category_label($loan['loan_category'] ?? 'monthly');
$durationUnit  = (($loan['loan_category'] ?? 'monthly') === 'monthly') ? 'month' : 'day';
$durationLabel = (int) $loan['duration'] . ' ' . $durationUnit . ((int) $loan['duration'] === 1 ? '' : 's');

/* Count payments */
$payCount = count($payments);
$paySum   = 0.0;
foreach ($payments as $p) $paySum += (float) $p['amount_paid'];

/* ----------------------------------------------------------------
   BUILD THE PDF
---------------------------------------------------------------- */

class LoanStatementPDF extends FPDF
{
    public $footerLeft  = '';
    public $footerRight = '';

    function Footer()
    {
        $this->SetY(-15);

        $this->SetDrawColor(229, 231, 235);
        $this->SetLineWidth(0.2);
        $this->Line(15, $this->GetY(), 195, $this->GetY());

        $this->SetY(-12);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(107, 114, 128);

        $this->SetX(15);
        $this->Cell(120, 4, $this->footerLeft, 0, 0, 'L');

        $this->SetX(135);
        $this->Cell(60, 4, $this->footerRight, 0, 0, 'R');
    }
}

$pdf = new LoanStatementPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 22);
$pdf->AliasNbPages();
$pdf->footerLeft  = 'Jolly Micro Loans  -  Confidential  -  This statement is computer-generated.';
$pdf->footerRight = lp_safe($loan['client_name']) . '  -  ' . lp_safe($loan['loan_code']);

$pdf->AddPage();

/* ================================================================
   HEADER
================================================================ */

/* Brand mark (blue square with J) */
$pdf->SetFillColor(29, 78, 216);
$pdf->Rect(15, 15, 14, 14, 'F');
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 16);
$pdf->SetXY(15, 15.5);
$pdf->Cell(14, 13, 'J', 0, 0, 'C');

/* Brand text (left) */
$pdf->SetTextColor(17, 24, 39);
$pdf->SetFont('Arial', 'B', 13);
$pdf->SetXY(32, 16);
$pdf->Cell(80, 5, 'Jolly Micro Loans', 0, 1);

$pdf->SetTextColor(107, 114, 128);
$pdf->SetFont('Arial', '', 9);
$pdf->SetX(32);
$pdf->Cell(80, 4, 'Loan Statement', 0, 1);

/* Client title block (right) */
$pdf->SetTextColor(17, 24, 39);
$pdf->SetFont('Arial', 'B', 13);
$pdf->SetXY(105, 15);
$pdf->Cell(90, 6, lp_safe($loan['client_name']), 0, 1, 'R');

$pdf->SetFont('Arial', '', 10);
$pdf->SetX(105);
$pdf->Cell(90, 5, 'Loan ID: ' . lp_safe($loan['loan_code']), 0, 1, 'R');

$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(107, 114, 128);
$pdf->SetX(105);
$pdf->Cell(90, 4, 'Generated: ' . lp_safe($reportDate), 0, 1, 'R');
$pdf->SetX(105);
$pdf->Cell(90, 4, 'By: ' . lp_safe($generatedBy), 0, 1, 'R');

/* Thick line under header */
$pdf->SetDrawColor(17, 24, 39);
$pdf->SetLineWidth(0.5);
$pdf->Line(15, 33, 195, 33);
$pdf->SetLineWidth(0.2);

$pdf->SetY(36);

/* ================================================================
   STATUS PILL
================================================================ */
$statusColorMap = [
    'active'      => [219, 234, 254, 30, 64, 175],
    'overdue'     => [254, 243, 199, 146, 64, 14],
    'defaulted'   => [254, 226, 226, 153, 27, 27],
    'cleared'     => [209, 250, 229, 6, 95, 70],
    'written_off' => [229, 231, 235, 55, 65, 81],
];
[$bgR, $bgG, $bgB, $txR, $txG, $txB] = $statusColorMap[$loan['status']] ?? [229, 231, 235, 55, 65, 81];
$statusText = lp_status_label($loan['status']);

$pdf->SetFont('Arial', 'B', 8);
$statusWidth = $pdf->GetStringWidth($statusText) + 6;

$pdf->SetFillColor($bgR, $bgG, $bgB);
$pdf->SetTextColor($txR, $txG, $txB);
$pdf->SetXY(15, 36);
$pdf->Cell($statusWidth, 5, $statusText, 0, 0, 'C', true);

$pdf->SetY(44);

/* ================================================================
   TWO-COLUMN: CLIENT INFO | LOAN TERMS
================================================================ */

$colLeftX  = 15;
$colRightX = 108;
$colWidth  = 87;

function section_title(FPDF $pdf, float $x, float $y, float $w, string $title) {
    $pdf->SetXY($x, $y);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell($w, 4, strtoupper($title), 0, 1);
    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Line($x, $y + 4, $x + $w, $y + 4);
}

function info_row(FPDF $pdf, float $x, float $y, float $w, string $label, string $value) {
    $pdf->SetXY($x, $y);
    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(30, 5, $label, 0, 0);

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetX($x + 30);
    $pdf->Cell($w - 30, 5, $value, 0, 0);
}

/* --- Left: Client --- */
$rowY = 44;
section_title($pdf, $colLeftX, $rowY, $colWidth, 'Client Information');
$rowY += 7;

$clientRows = [
    ['Client Code',  $loan['client_code']],
    ['Full Name',    $loan['client_name']],
    ['Email',        $loan['client_email'] ?? '-'],
    ['Phone',        $loan['client_phone'] ?? '-'],
    ['ID Number',    $loan['client_id_number'] ?? '-'],
    ['Location',     $loan['location'] ?? '-'],
];
foreach ($clientRows as $r) {
    info_row($pdf, $colLeftX, $rowY, $colWidth, $r[0], lp_safe($r[1]));
    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Line($colLeftX, $rowY + 5, $colLeftX + $colWidth, $rowY + 5);
    $rowY += 5.5;
}

/* --- Right: Loan Terms --- */
$rowY = 44;
section_title($pdf, $colRightX, $rowY, $colWidth, 'Loan Terms');
$rowY += 7;

$termRows = [
    ['Loan Type',     $categoryLabel],
    ['Duration',      $durationLabel],
    ['Date Issued',   lp_date($loan['date_issued'])],
    ['Due Date',      lp_date($loan['due_date'])],
    ['Interest Rate', number_format((float) $loan['interest_rate'], 2) . '%'],
    ['Purpose',       $loan['purpose'] ?? '-'],
];
foreach ($termRows as $r) {
    info_row($pdf, $colRightX, $rowY, $colWidth, $r[0], lp_safe($r[1]));
    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Line($colRightX, $rowY + 5, $colRightX + $colWidth, $rowY + 5);
    $rowY += 5.5;
}

$pdf->SetY(84);

/* ================================================================
   FINANCIAL SUMMARY
================================================================ */

section_title($pdf, 15, $pdf->GetY(), 180, 'Financial Summary');
$pdf->Ln(6);

/* Section sub-heading helper */
$subheading = function (FPDF $pdf, string $text) {
    $pdf->SetX(15);
    $pdf->SetFont('Arial', 'I', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(180, 6, $text, 0, 1);
};

/* Summary rows: [label, value, highlight, is_subheading] */
$summaryRows = [
    ['Billed', null, false, true],

    ['Principal Amount',      lp_ksh($loan['principal_amount']),  false, false],
    ['Interest Billed',       lp_ksh($loan['interest_amount']),   false, false],
    ['Loan value',             lp_ksh($loan['total_due']),         false, false],

    ['Paid', null, false, true],

    ['Principal Repaid',      lp_ksh($principalPaid),             false, false],
    ['Interest Repaid',       lp_ksh($interestPaid),              false, false],
    ['Total Amount Paid',     lp_ksh($totalPaid),                 false, false],

    ['Outstanding', null, false, true],

    ['Principal Balance',     lp_ksh($loan['principal_balance']), false, false],
    ['Interest Balance',      lp_ksh($loan['interest_balance']),  false, false],
    
];

foreach ($summaryRows as $sr) {
    [$label, $value, $highlight, $isSubheading] = $sr;

    if ($isSubheading) {
        $subheading($pdf, $label);
        continue;
    }

    $y = $pdf->GetY();

    if ($highlight) {
        $pdf->SetFillColor(240, 249, 255);
        $pdf->Rect(15, $y, 180, 8, 'F');
    }

    $pdf->SetXY(15, $y);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->Cell(90, 8, $label, 0, 0, 'L');

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetX(105);
    $pdf->Cell(90, 8, $value, 0, 0, 'R');

    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Line(15, $y + 8, 195, $y + 8);

    $pdf->SetY($y + 8);
}

$pdf->SetY($pdf->GetY() + 4);

/* ================================================================
   GUARANTORS (if any)
================================================================ */

if (!empty($guarantors)) {
    section_title($pdf, 15, $pdf->GetY(), 180, 'Guarantor' . (count($guarantors) > 1 ? 's' : ''));
    $pdf->Ln(6);

    $pdf->SetFillColor(17, 24, 39);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetX(15);
    $pdf->Cell(60, 7, 'Name',       0, 0, 'L', true);
   
    $pdf->Cell(40, 7, 'Phone',      0, 0, 'L', true);
    $pdf->Cell(45, 7, 'Email',      0, 1, 'L', true);

    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetFont('Arial', '', 8);
    $rowIdx = 0;
    foreach ($guarantors as $g) {
        $fill = ($rowIdx % 2 === 1);
        if ($fill) $pdf->SetFillColor(249, 250, 251);
        $pdf->SetX(15);
        $pdf->Cell(60, 6, lp_safe(substr($g['name'], 0, 40)), 0, 0, 'L', $fill);
        
        $pdf->Cell(40, 6, lp_safe($g['phone'] ?? '-'), 0, 0, 'L', $fill);
        $pdf->Cell(45, 6, lp_safe(substr($g['email'] ?? '-', 0, 40)), 0, 1, 'L', $fill);
        $rowIdx++;
    }

    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->SetY($pdf->GetY() + 4);
}

/* ================================================================
   PAYMENT HISTORY
================================================================ */

section_title($pdf, 15, $pdf->GetY(), 180, 'Payment History');
$pdf->Ln(6);

if ($payCount === 0) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetX(15);
    $pdf->Cell(180, 6, 'No payments recorded for this loan.', 0, 1);
} else {

    /* Summary strip */
    $cardW = 58;
    $gap   = 3;
    $cards = [
        ['Payments',          (string) $payCount],
        ['Total Paid',        lp_ksh($paySum)],
        
    ];

    $startX = 15;
    $cardY  = $pdf->GetY();

    foreach ($cards as $i => $c) {
        $x = $startX + $i * ($cardW + $gap);

        $pdf->SetFillColor(249, 250, 251);
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->Rect($x, $cardY, $cardW, 14, 'DF');

        $pdf->SetFont('Arial', 'B', 7);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetXY($x + 3, $cardY + 2);
        $pdf->Cell($cardW - 6, 4, strtoupper($c[0]), 0, 1);

        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->SetXY($x + 3, $cardY + 7);
        $pdf->Cell($cardW - 6, 5, $c[1], 0, 1);
    }

    $pdf->SetY($cardY + 18);

    /* Payments table header */
    $pdf->SetFillColor(17, 24, 39);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetX(15);
    $pdf->Cell(10, 7, '#',           0, 0, 'L', true);
    $pdf->Cell(35, 7, 'Payment ID',  0, 0, 'L', true);
    $pdf->Cell(30, 7, 'Date',        0, 0, 'L', true);
    $pdf->Cell(35, 7, 'Mode',        0, 0, 'L', true);
    $pdf->Cell(35, 7, 'Reference',   0, 0, 'L', true);
    $pdf->Cell(35, 7, 'Amount',      0, 1, 'R', true);

    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetFont('Arial', '', 8);
    $i = 1;
    foreach ($payments as $p) {
        $fill = ($i % 2 === 0);
        if ($fill) $pdf->SetFillColor(249, 250, 251);

        $pdf->SetX(15);
        $pdf->Cell(10, 6, (string) $i,                                      0, 0, 'L', $fill);
        $pdf->Cell(35, 6, lp_safe($p['payment_code']),                      0, 0, 'L', $fill);
        $pdf->Cell(30, 6, lp_date($p['payment_date']),                      0, 0, 'L', $fill);
        $pdf->Cell(35, 6, lp_safe(lp_mode_label($p['mode_of_payment'])),    0, 0, 'L', $fill);
        $pdf->Cell(35, 6, lp_safe($p['reference_number'] ?? '-'),           0, 0, 'L', $fill);
        $pdf->Cell(35, 6, lp_ksh($p['amount_paid']),                        0, 1, 'R', $fill);
        $i++;
    }

    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
}

/* ================================================================
   NOTES
================================================================ */
if (!empty($loan['notes'])) {
    $pdf->SetY($pdf->GetY() + 4);
    section_title($pdf, 15, $pdf->GetY(), 180, 'Notes');
    $pdf->Ln(6);

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetX(15);
    $pdf->MultiCell(180, 5, lp_safe($loan['notes']), 0, 'L');
}

/* ================================================================
   OUTPUT
================================================================ */

$conn->close();

/* 'I' = inline (opens in browser), 'D' = force download */
$filename = 'Loan-Statement-' . preg_replace('/[^A-Za-z0-9\-]/', '', $loan['loan_code']) . '.pdf';
$pdf->Output('I', $filename);
exit;