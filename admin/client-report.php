<?php
$pageTitle = 'Client Report';
$activeNav = 'clients';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- FPDF ----------
   Adjust the path to your FPDF install.
------------------------------------------------------------ */
$fpdfPath = __DIR__ . '/../assets/fpdf/fpdf.php';
if (!file_exists($fpdfPath)) {
    http_response_code(500);
    exit('FPDF library not found. Check the path in client-report.php');
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
$clientId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($clientId <= 0) {
    header('Location: clients.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH CLIENT
---------------------------------------------------------------- */
$stmt = $conn->prepare(
    "SELECT id, client_code, full_name, email, phone, id_number,
            date_of_birth, gender, location, status, notes,
            join_date, created_at
     FROM clients
     WHERE id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $clientId);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    header('Location: clients.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH LOANS
---------------------------------------------------------------- */
$loans = [];
$stmt = $conn->prepare(
    "SELECT id, loan_code, loan_category, date_issued, duration,
            principal_amount, principal_balance,
            interest_rate, interest_amount, interest_balance,
            total_due, balance, due_date, status, purpose, notes, created_at
     FROM loans
     WHERE client_id = ?
     ORDER BY date_issued ASC, id ASC"
);
$stmt->bind_param("i", $clientId);
$stmt->execute();
$res = $stmt->get_result();
while ($r = $res->fetch_assoc()) $loans[] = $r;
$stmt->close();

/* ----------------------------------------------------------------
   FETCH PAYMENTS GROUPED BY LOAN
---------------------------------------------------------------- */
$paymentsByLoan = [];
if (!empty($loans)) {
    $loanIds      = array_map(fn($l) => (int) $l['id'], $loans);
    $placeholders = implode(',', array_fill(0, count($loanIds), '?'));

    $sql = "SELECT id, payment_code, loan_id, amount_paid,
                   payment_date, mode_of_payment, reference_number
            FROM payments
            WHERE loan_id IN ($placeholders)
            ORDER BY payment_date ASC, id ASC";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $types = str_repeat('i', count($loanIds));
        $stmt->bind_param($types, ...$loanIds);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $paymentsByLoan[(int) $r['loan_id']][] = $r;
        }
        $stmt->close();
    }
}

/* ----------------------------------------------------------------
   AGGREGATES
---------------------------------------------------------------- */
$totalPrincipal = 0.0;
$totalInterest  = 0.0;
$totalDue       = 0.0;
$totalPaid      = 0.0;
$totalBalance   = 0.0;

$countActive    = 0;
$countOverdue   = 0;
$countDefaulted = 0;
$countCleared   = 0;

foreach ($loans as $l) {
    $totalPrincipal += (float) $l['principal_amount'];
    $totalInterest  += (float) $l['interest_amount'];
    $totalDue       += (float) $l['total_due'];
    $totalBalance   += (float) $l['balance'];

    /* Amount paid = what's been knocked off principal + interest */
    $paid = ((float) $l['principal_amount'] - (float) $l['principal_balance'])
          + ((float) $l['interest_amount']  - (float) $l['interest_balance']);
    $totalPaid += $paid;

    switch ($l['status']) {
        case 'active':    $countActive++;    break;
        case 'overdue':   $countOverdue++;   break;
        case 'defaulted': $countDefaulted++; break;
        case 'cleared':   $countCleared++;   break;
    }
}

/* ----------------------------------------------------------------
   HELPERS — ASCII safe for FPDF
---------------------------------------------------------------- */
function cr_ksh($n): string {
    return 'Ksh ' . number_format((float) $n, 2);
}

function cr_date(?string $d): string {
    return $d ? date('M j, Y', strtotime($d)) : '-';
}

function cr_status_label(string $status): string {
    return [
        'active'      => 'ACTIVE',
        'overdue'     => 'OVERDUE',
        'defaulted'   => 'DEFAULTED',
        'cleared'     => 'CLEARED',
        'written_off' => 'WRITTEN OFF',
    ][$status] ?? strtoupper($status);
}

function cr_mode_label(string $mode): string {
    return [
        'cash'          => 'Cash',
        'mpesa'         => 'M-Pesa',
        'bank_transfer' => 'Bank Transfer',
        'cheque'        => 'Cheque',
        'card'          => 'Card',
        'other'         => 'Other',
    ][$mode] ?? ucfirst($mode);
}

function cr_category_label(string $category): string {
    return [
        'monthly' => 'Monthly Loan',
        'daily'   => 'Daily Loan',
        'boost'   => 'Jolly Boost Loan',
    ][$category] ?? ucfirst($category);
}

function cr_safe(?string $s): string {
    $s = (string) $s;
    $s = str_replace(
        ["\xE2\x80\x94", "\xE2\x80\x93", "\xE2\x80\xA6", "\xC2\xB7", "\xE2\x80\xA2"],
        ['-', '-', '...', '-', '-'],
        $s
    );
    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', '?', $s);
}

$reportDate  = date('M j, Y H:i');
$generatedBy = $_SESSION['user_name'] ?? 'System';

/* ----------------------------------------------------------------
   PDF CLASS — footer on every page
---------------------------------------------------------------- */
class ClientReportPDF extends FPDF
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
        $this->Cell(60, 4, $this->footerRight . '  |  Page ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}

$pdf = new ClientReportPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 22);
$pdf->AliasNbPages();
$pdf->footerLeft  = 'Jolly Micro Loans  -  Confidential  -  Client Report';
$pdf->footerRight = cr_safe($client['full_name']) . '  -  ' . cr_safe($client['client_code']);

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

/* Brand text */
$pdf->SetTextColor(17, 24, 39);
$pdf->SetFont('Arial', 'B', 13);
$pdf->SetXY(32, 16);
$pdf->Cell(80, 5, 'Jolly Micro Loans', 0, 1);

$pdf->SetTextColor(107, 114, 128);
$pdf->SetFont('Arial', '', 9);
$pdf->SetX(32);
$pdf->Cell(80, 4, 'Client Loan & Payment Report', 0, 1);

/* Client title (right) */
$pdf->SetTextColor(17, 24, 39);
$pdf->SetFont('Arial', 'B', 13);
$pdf->SetXY(105, 15);
$pdf->Cell(90, 6, cr_safe($client['full_name']), 0, 1, 'R');

$pdf->SetFont('Arial', '', 10);
$pdf->SetX(105);
$pdf->Cell(90, 5, 'Report ID: ' . cr_safe($client['client_code']), 0, 1, 'R');

$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(107, 114, 128);
$pdf->SetX(105);
$pdf->Cell(90, 4, 'Generated: ' . cr_safe($reportDate), 0, 1, 'R');
$pdf->SetX(105);
$pdf->Cell(90, 4, 'By: ' . cr_safe($generatedBy), 0, 1, 'R');

/* Thick line */
$pdf->SetDrawColor(17, 24, 39);
$pdf->SetLineWidth(0.5);
$pdf->Line(15, 33, 195, 33);
$pdf->SetLineWidth(0.2);

$pdf->SetY(38);

/* ================================================================
   SECTION: Client Information
================================================================ */

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(107, 114, 128);
$pdf->SetX(15);
$pdf->Cell(180, 5, 'CLIENT INFORMATION', 0, 1);

$pdf->SetDrawColor(229, 231, 235);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(2);

$pdf->SetTextColor(17, 24, 39);

/* Two-column layout: 4 rows each */
$clientRows = [
    ['Full Name',   $client['full_name']],
    ['Email',       $client['email'] ?? '-'],
    ['Phone',       $client['phone']],
    ['ID Number',   $client['id_number']],
    ['Gender',      $client['gender'] ? ucfirst($client['gender']) : '-'],
    ['Location',    $client['location'] ?? '-'],
    ['Status',      ucfirst($client['status'])],
    ['Joined',      cr_date($client['join_date'])],
];

$rowY = $pdf->GetY();
foreach ($clientRows as $i => $r) {
    $col = $i % 2;
    $x   = $col === 0 ? 15 : 108;

    if ($col === 0 && $i > 0) {
        $rowY += 6;
    }

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetXY($x, $rowY);
    $pdf->Cell(28, 5, $r[0], 0, 0);

    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetX($x + 28);
    $pdf->Cell(60, 5, cr_safe($r[1]), 0, 0);
}

$pdf->SetY($rowY + 8);

/* Optional notes */
if (!empty($client['notes'])) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetX(15);
    $pdf->Cell(30, 5, 'Notes:', 0, 0);

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetX(45);
    $pdf->MultiCell(150, 4.5, cr_safe($client['notes']), 0, 'L');
}

$pdf->SetY($pdf->GetY() + 4);

/* ================================================================
   SECTION: Portfolio Summary
================================================================ */

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(107, 114, 128);
$pdf->SetX(15);
$pdf->Cell(180, 5, 'PORTFOLIO SUMMARY', 0, 1);

$pdf->SetDrawColor(229, 231, 235);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(3);

/* Row 1: money cards */
$cardsRow1 = [
    ['TOTAL LOANS',      (string) count($loans)],
    ['TOTAL PRINCIPAL',  cr_ksh($totalPrincipal)],
    ['TOTAL PAID',       cr_ksh($totalPaid)],
    
];
$cardW   = 43;
$cardGap = 2;
$cardY   = $pdf->GetY();

foreach ($cardsRow1 as $i => $c) {
    $x = 15 + $i * ($cardW + $cardGap);

    $pdf->SetFillColor(249, 250, 251);
    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Rect($x, $cardY, $cardW, 16, 'DF');

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetXY($x + 3, $cardY + 2);
    $pdf->Cell($cardW - 6, 4, $c[0], 0, 1);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetXY($x + 3, $cardY + 8);
    $pdf->Cell($cardW - 6, 6, $c[1], 0, 1);
}

$pdf->SetY($cardY + 20);

/* Row 2: status counts */
$cardsRow2 = [
    ['ACTIVE',    $countActive],
    ['OVERDUE',   $countOverdue],
    ['DEFAULTED', $countDefaulted],
    ['CLEARED',   $countCleared],
];
$cardY2 = $pdf->GetY();

foreach ($cardsRow2 as $i => $c) {
    $x = 15 + $i * ($cardW + $cardGap);

    $pdf->SetFillColor(249, 250, 251);
    $pdf->SetDrawColor(229, 231, 235);
    $pdf->Rect($x, $cardY2, $cardW, 14, 'DF');

    $pdf->SetFont('Arial', 'B', 7);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetXY($x + 3, $cardY2 + 2);
    $pdf->Cell($cardW - 6, 4, $c[0], 0, 1);

    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetXY($x + 3, $cardY2 + 7);
    $pdf->Cell($cardW - 6, 5, (string) $c[1], 0, 1);
}

$pdf->SetY($cardY2 + 20);

/* ================================================================
   SECTION: Loans & Payment History
================================================================ */

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(107, 114, 128);
$pdf->SetX(15);
$pdf->Cell(180, 5, 'LOANS & PAYMENT HISTORY', 0, 1);

$pdf->SetDrawColor(229, 231, 235);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(3);

if (empty($loans)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetX(15);
    $pdf->Cell(180, 6, 'No loans recorded for this client.', 0, 1);
} else {

    /* Status colour map */
    $statusColors = [
        'active'      => [209, 250, 229, 6, 95, 70],
        'overdue'     => [254, 243, 199, 146, 64, 14],
        'defaulted'   => [254, 226, 226, 153, 27, 27],
        'cleared'     => [219, 234, 254, 30, 64, 175],
        'written_off' => [229, 231, 235, 55, 65, 81],
    ];

    foreach ($loans as $loan) {
        $loanPayments = $paymentsByLoan[(int) $loan['id']] ?? [];

        /* Derive what's been repaid on this loan */
        $loanPrincipalPaid = round((float) $loan['principal_amount'] - (float) $loan['principal_balance'], 2);
        $loanInterestPaid  = round((float) $loan['interest_amount']  - (float) $loan['interest_balance'],  2);
        $loanTotalPaid     = round($loanPrincipalPaid + $loanInterestPaid, 2);

        /* ---- Estimate height of this loan block ----
           If it won't fit on the current page, start a new page. */
        $estimatedHeight = 67;                // header + 3-row meta grid
        if (!empty($loanPayments)) {
            $estimatedHeight += 10 + count($loanPayments) * 6 + 8;
        }
        $pageBottom = 297 - 22;               // A4 height minus margin
        if ($pdf->GetY() + $estimatedHeight > $pageBottom - 20) {
            $pdf->AddPage();
        }

        /* Box around the block */
        $blockStartY = $pdf->GetY();

        /* ---- Loan header ---- */
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->SetX(15);
        $pdf->Cell(120, 6, cr_safe($loan['loan_code']), 0, 0);

        /* Status pill on the right */
        $statusText = cr_status_label($loan['status']);
        [$sbgR, $sbgG, $sbgB, $stxR, $stxG, $stxB] = $statusColors[$loan['status']] ?? [229, 231, 235, 55, 65, 81];

        $pdf->SetFont('Arial', 'B', 7);
        $pillW = $pdf->GetStringWidth($statusText) + 6;
        $pillX = 195 - $pillW;
        $pillY = $pdf->GetY() + 1;

        $pdf->SetFillColor($sbgR, $sbgG, $sbgB);
        $pdf->SetTextColor($stxR, $stxG, $stxB);
        $pdf->SetXY($pillX, $pillY);
        $pdf->Cell($pillW, 5, $statusText, 0, 0, 'C', true);

        /* Loan type subtitle */
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetXY(15, $pdf->GetY() + 5.5);
        $pdf->Cell(180, 4, cr_safe($loan['purpose'] ?: cr_category_label($loan['loan_category'] ?? 'loan')), 0, 1);

        /* Separator */
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(2);

        /* ---- Loan meta grid (4 columns × 3 rows) ---- */
        $metaRows = [
            ['Date Issued',       cr_date($loan['date_issued'])],
            ['Due Date',          cr_date($loan['due_date'])],
            ['Principal',         cr_ksh($loan['principal_amount'])],
            ['Interest Rate',     number_format((float) $loan['interest_rate'], 2) . '%'],

            ['Interest Billed',   cr_ksh($loan['interest_amount'])],
            ['Total Due',         cr_ksh($loan['total_due'])],
            ['Total Paid',        cr_ksh($loanTotalPaid)],
            ['Balance',           cr_ksh($loan['balance'])],

            ['Principal Balance', cr_ksh($loan['principal_balance'])],
            ['Interest Balance',  cr_ksh($loan['interest_balance'])],
            ['Principal Repaid',  cr_ksh($loanPrincipalPaid)],
            ['Interest Repaid',   cr_ksh($loanInterestPaid)],
        ];

        $metaRowY = $pdf->GetY();
        foreach ($metaRows as $i => $m) {
            $col = $i % 4;
            $x   = 15 + $col * 45;

            if ($col === 0 && $i > 0) {
                $metaRowY += 9;
            }

            $pdf->SetFont('Arial', 'B', 6.5);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->SetXY($x, $metaRowY);
            $pdf->Cell(40, 4, strtoupper($m[0]), 0, 0);

            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(17, 24, 39);
            $pdf->SetXY($x, $metaRowY + 4);
            $pdf->Cell(40, 4, $m[1], 0, 0);
        }

        $pdf->SetY($metaRowY + 10);

        /* ---- Payments ---- */
        if (empty($loanPayments)) {
            $pdf->SetFont('Arial', 'I', 8);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->SetX(15);
            $pdf->Cell(180, 5, 'No payments recorded for this loan.', 0, 1);
        } else {
            /* Table header */
            $pdf->SetFillColor(17, 24, 39);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetFont('Arial', 'B', 7);
            $pdf->SetX(15);
            $pdf->Cell(40, 6, 'Payment ID', 0, 0, 'L', true);
            $pdf->Cell(35, 6, 'Date Paid',  0, 0, 'L', true);
            $pdf->Cell(40, 6, 'Mode',       0, 0, 'L', true);
            $pdf->Cell(30, 6, 'Reference',  0, 0, 'L', true);
            $pdf->Cell(35, 6, 'Amount',     0, 1, 'R', true);

            /* Rows */
            $pdf->SetTextColor(17, 24, 39);
            $pdf->SetFont('Arial', '', 7.5);
            $idx = 0;
            $subtotal = 0.0;
            foreach ($loanPayments as $p) {
                $fill = ($idx % 2 === 1);
                if ($fill) $pdf->SetFillColor(249, 250, 251);

                $pdf->SetX(15);
                $pdf->Cell(40, 5, cr_safe($p['payment_code']),                   0, 0, 'L', $fill);
                $pdf->Cell(35, 5, cr_date($p['payment_date']),                   0, 0, 'L', $fill);
                $pdf->Cell(40, 5, cr_safe(cr_mode_label($p['mode_of_payment'])), 0, 0, 'L', $fill);
                $pdf->Cell(30, 5, cr_safe($p['reference_number'] ?? '-'),        0, 0, 'L', $fill);
                $pdf->Cell(35, 5, cr_ksh($p['amount_paid']),                     0, 1, 'R', $fill);

                $subtotal += (float) $p['amount_paid'];
                $idx++;
            }

            /* Subtotal */
            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(107, 114, 128);
            $pdf->SetX(15);
            $pdf->Cell(145, 5, 'Subtotal:', 0, 0, 'R');
            $pdf->SetTextColor(17, 24, 39);
            $pdf->Cell(35, 5, cr_ksh($subtotal), 0, 1, 'R');
        }

        /* ---- Draw the outer border around the block ---- */
        $blockEndY = $pdf->GetY() + 2;
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->Rect(12, $blockStartY - 2, 186, $blockEndY - $blockStartY + 4);

        /* Space before next loan */
        $pdf->SetY($blockEndY + 4);
    }
}

/* ================================================================
   OUTPUT
================================================================ */

$conn->close();

$filename = 'Client-Report-' . preg_replace('/[^A-Za-z0-9\-]/', '', $client['client_code']) . '.pdf';
$pdf->Output('I', $filename);
exit;