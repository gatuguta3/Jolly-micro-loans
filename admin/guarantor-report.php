<?php
$pageTitle = 'Guarantor Report';
$activeNav = 'guarantors';

/* ---------- DB CONNECTION ---------- */
require_once __DIR__ . '/../config/db_connection.php';

/* ---------- FPDF ----------
   Adjust the path to your FPDF install.
------------------------------------------------------------ */
$fpdfPath = __DIR__ . '/../assets/fpdf/fpdf.php';
if (!file_exists($fpdfPath)) {
    http_response_code(500);
    exit('FPDF library not found. Check the path in guarantor-report.php');
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
$guarantorId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($guarantorId <= 0) {
    header('Location: guarantors.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH GUARANTOR
---------------------------------------------------------------- */
$stmt = $conn->prepare(
    "SELECT id, name, email, phone, type,
            identification_type, identification_number,
            attachment_url, notes, status, date_added, created_at
     FROM guarantors
     WHERE id = ?
     LIMIT 1"
);
$stmt->bind_param("i", $guarantorId);
$stmt->execute();
$guarantor = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$guarantor) {
    header('Location: guarantors.php');
    exit;
}

/* ----------------------------------------------------------------
   FETCH GUARANTEED LOANS
---------------------------------------------------------------- */
$loans = [];
$hasJoin = $conn->query("SHOW TABLES LIKE 'loan_guarantors'");
if ($hasJoin && $hasJoin->num_rows > 0) {
    $stmt = $conn->prepare(
        "SELECT
            l.id, l.loan_code, l.loan_category, l.purpose,
            l.principal_amount, l.interest_amount, l.total_due,
            l.amount_paid, l.balance, l.status,
            l.date_issued, l.due_date, l.duration,
            c.id         AS client_id,
            c.client_code,
            c.full_name  AS client_name,
            c.email      AS client_email,
            c.phone      AS client_phone
         FROM loan_guarantors lg
         INNER JOIN loans   l ON l.id = lg.loan_id
         INNER JOIN clients c ON c.id = l.client_id
         WHERE lg.guarantor_id = ?
         ORDER BY l.id DESC"
    );
    $stmt->bind_param("i", $guarantorId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $loans[] = $r;
    $stmt->close();
}

/* ----------------------------------------------------------------
   AGGREGATES
---------------------------------------------------------------- */
$totalGuaranteed = 0.0;
$totalBalance    = 0.0;
$totalPaid       = 0.0;

$countActive    = 0;
$countOverdue   = 0;
$countDefaulted = 0;
$countCleared   = 0;

foreach ($loans as $l) {
    $totalGuaranteed += (float) $l['principal_amount'];
    $totalBalance    += (float) $l['balance'];
    $totalPaid       += (float) $l['amount_paid'];

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
function gr_ksh($n): string {
    return 'Ksh ' . number_format((float) $n, 2);
}

function gr_date(?string $d): string {
    return $d ? date('M j, Y', strtotime($d)) : '-';
}

function gr_status_label(string $status): string {
    return [
        'active'      => 'ACTIVE',
        'overdue'     => 'OVERDUE',
        'defaulted'   => 'DEFAULTED',
        'cleared'     => 'CLEARED',
        'written_off' => 'WRITTEN OFF',
    ][$status] ?? strtoupper($status);
}

function gr_category_label(string $category): string {
    return [
        'monthly' => 'Monthly Loan',
        'daily'   => 'Daily Loan',
        'boost'   => 'Jolly Boost Loan',
    ][$category] ?? ucfirst($category);
}

function gr_id_type_label(string $t): string {
    return [
        'id_no'    => 'ID No',
        'passport' => 'Passport',
        'other'    => 'Other Document',
    ][$t] ?? 'ID';
}

function gr_safe(?string $s): string {
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

$typeLabel     = $guarantor['type'] === 'chama' ? 'Chama' : 'Individual';
$statusLabel   = $guarantor['status'] === 'active' ? 'ACTIVE' : 'INACTIVE';

/* ----------------------------------------------------------------
   PDF CLASS — footer on every page
---------------------------------------------------------------- */
class GuarantorReportPDF extends FPDF
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

$pdf = new GuarantorReportPDF('P', 'mm', 'A4');
$pdf->SetAutoPageBreak(true, 22);
$pdf->AliasNbPages();
$pdf->footerLeft  = 'Jolly Micro Loans  -  Confidential  -  Guarantor Report';
$pdf->footerRight = gr_safe($guarantor['name']) . '  -  #' . (int) $guarantor['id'];

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
$pdf->Cell(80, 4, 'Guarantor Report', 0, 1);

/* Guarantor title (right) */
$pdf->SetTextColor(17, 24, 39);
$pdf->SetFont('Arial', 'B', 13);
$pdf->SetXY(105, 15);
$pdf->Cell(90, 6, gr_safe($guarantor['name']), 0, 1, 'R');

$pdf->SetFont('Arial', '', 10);
$pdf->SetX(105);
$pdf->Cell(90, 5, 'Guarantor ID: #' . (int) $guarantor['id'], 0, 1, 'R');

$pdf->SetFont('Arial', '', 8);
$pdf->SetTextColor(107, 114, 128);
$pdf->SetX(105);
$pdf->Cell(90, 4, 'Generated: ' . gr_safe($reportDate), 0, 1, 'R');
$pdf->SetX(105);
$pdf->Cell(90, 4, 'By: ' . gr_safe($generatedBy), 0, 1, 'R');

/* Thick line */
$pdf->SetDrawColor(17, 24, 39);
$pdf->SetLineWidth(0.5);
$pdf->Line(15, 33, 195, 33);
$pdf->SetLineWidth(0.2);

$pdf->SetY(36);

/* ================================================================
   STATUS PILL
================================================================ */
$statusColors = [
    'active'   => [209, 250, 229, 6, 95, 70],
    'inactive' => [229, 231, 235, 55, 65, 81],
];
[$bgR, $bgG, $bgB, $txR, $txG, $txB] = $statusColors[$guarantor['status']] ?? $statusColors['inactive'];

$pdf->SetFont('Arial', 'B', 8);
$statusWidth = $pdf->GetStringWidth($statusLabel) + 6;

$pdf->SetFillColor($bgR, $bgG, $bgB);
$pdf->SetTextColor($txR, $txG, $txB);
$pdf->SetXY(15, 36);
$pdf->Cell($statusWidth, 5, $statusLabel, 0, 0, 'C', true);

/* Type pill next to it */
$pdf->SetFont('Arial', 'B', 8);
$typeWidth = $pdf->GetStringWidth(strtoupper($typeLabel)) + 6;
$pdf->SetFillColor(224, 242, 254);
$pdf->SetTextColor(30, 64, 175);
$pdf->SetXY(15 + $statusWidth + 2, 36);
$pdf->Cell($typeWidth, 5, strtoupper($typeLabel), 0, 0, 'C', true);

$pdf->SetY(44);

/* ================================================================
   SECTION: Guarantor Information
================================================================ */

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(107, 114, 128);
$pdf->SetX(15);
$pdf->Cell(180, 5, 'GUARANTOR INFORMATION', 0, 1);

$pdf->SetDrawColor(229, 231, 235);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(2);

$pdf->SetTextColor(17, 24, 39);

/* Two-column layout */
$infoRows = [
    ['Full Name',    $guarantor['name']],
    ['Email',        $guarantor['email'] ?? '-'],
    ['Phone',        $guarantor['phone'] ?? '-'],
    ['ID Number',    $guarantor['identification_number'] ?? '-'],
    ['ID Type',      gr_id_type_label($guarantor['identification_type'])],
    ['Type',         $typeLabel],
    ['Status',       ucfirst($guarantor['status'])],
    ['Date Created', gr_date($guarantor['date_added'])],
];

$rowY = $pdf->GetY();
foreach ($infoRows as $i => $r) {
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
    $pdf->Cell(60, 5, gr_safe($r[1]), 0, 0);
}

$pdf->SetY($rowY + 8);

/* Notes if present */
if (!empty($guarantor['notes'])) {
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetX(15);
    $pdf->Cell(30, 5, 'Notes:', 0, 0);

    $pdf->SetFont('Arial', '', 8);
    $pdf->SetTextColor(17, 24, 39);
    $pdf->SetX(45);
    $pdf->MultiCell(150, 4.5, gr_safe($guarantor['notes']), 0, 'L');
}

$pdf->SetY($pdf->GetY() + 4);

/* ================================================================
   SECTION: Portfolio Summary
================================================================ */

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(107, 114, 128);
$pdf->SetX(15);
$pdf->Cell(180, 5, 'GUARANTEE PORTFOLIO SUMMARY', 0, 1);

$pdf->SetDrawColor(229, 231, 235);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(3);

/* Row 1: money totals */
$cardsRow1 = [
    ['LOANS GUARANTEED', (string) count($loans)],
    ['TOTAL GUARANTEED', gr_ksh($totalGuaranteed)],
    ['TOTAL PAID',       gr_ksh($totalPaid)],
    ['OUTSTANDING BAL.', gr_ksh($totalBalance)],
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
   SECTION: Guaranteed Loans
================================================================ */

$pdf->SetFont('Arial', 'B', 9);
$pdf->SetTextColor(107, 114, 128);
$pdf->SetX(15);
$pdf->Cell(180, 5, 'GUARANTEED LOANS', 0, 1);

$pdf->SetDrawColor(229, 231, 235);
$pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
$pdf->Ln(3);

if (empty($loans)) {
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->SetX(15);
    $pdf->Cell(180, 6, 'No loans are currently guaranteed by this person.', 0, 1);
} else {

    /* Status colour map */
    $statusMap = [
        'active'      => [209, 250, 229, 6, 95, 70],
        'overdue'     => [254, 243, 199, 146, 64, 14],
        'defaulted'   => [254, 226, 226, 153, 27, 27],
        'cleared'     => [219, 234, 254, 30, 64, 175],
        'written_off' => [229, 231, 235, 55, 65, 81],
    ];

    foreach ($loans as $loan) {

        /* ---- Estimate height and page break if needed ---- */
        $estimatedHeight = 42;   // loan header + meta grid
        $pageBottom = 297 - 22;
        if ($pdf->GetY() + $estimatedHeight > $pageBottom - 20) {
            $pdf->AddPage();
        }

        $blockStartY = $pdf->GetY();

        /* ---- Loan header row ---- */
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->SetX(15);
        $pdf->Cell(120, 6, gr_safe($loan['loan_code']), 0, 0);

        /* Status pill */
        $statusText = gr_status_label($loan['status']);
        [$sbgR, $sbgG, $sbgB, $stxR, $stxG, $stxB] = $statusMap[$loan['status']] ?? $statusMap['active'];

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
        $pdf->Cell(180, 4, gr_safe($loan['purpose'] ?: gr_category_label($loan['loan_category'] ?? 'loan')), 0, 1);

        /* Borrower + issued date row */
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetX(15);
        $pdf->Cell(30, 5, 'Borrower:', 0, 0);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(80, 5, gr_safe($loan['client_name']), 0, 0);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(25, 5, 'Issued:', 0, 0);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(45, 5, gr_date($loan['date_issued']), 0, 1);

        /* Due date + borrower contact */
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->SetX(15);
        $pdf->Cell(30, 5, 'Due Date:', 0, 0);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(80, 5, gr_date($loan['due_date']), 0, 0);

        $pdf->SetFont('Arial', '', 8);
        $pdf->SetTextColor(107, 114, 128);
        $pdf->Cell(25, 5, 'Phone:', 0, 0);
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(17, 24, 39);
        $pdf->Cell(45, 5, gr_safe($loan['client_phone'] ?? '-'), 0, 1);

        $pdf->Ln(1);

        /* Separator */
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(2);

        /* ---- Meta grid: 4 columns x 2 rows ---- */
        $metaRows = [
            ['Principal',       gr_ksh($loan['principal_amount'])],
            ['Interest',        gr_ksh($loan['interest_amount'])],
            ['Total Due',       gr_ksh($loan['total_due'])],
            ['Amount Paid',     gr_ksh($loan['amount_paid'])],
            ['Balance',         gr_ksh($loan['balance'])],
            ['Interest Rate',   number_format((float) $loan['interest_rate'], 2) . '%'],
            ['Duration',        (int) $loan['duration']],
            ['Client Code',     $loan['client_code']],
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
            $pdf->Cell(40, 4, gr_safe($m[1]), 0, 0);
        }

        $pdf->SetY($metaRowY + 10);

        /* ---- Draw outer border ---- */
        $blockEndY = $pdf->GetY();
        $pdf->SetDrawColor(229, 231, 235);
        $pdf->Rect(12, $blockStartY - 2, 186, $blockEndY - $blockStartY + 2);

        /* Space before next loan */
        $pdf->SetY($blockEndY + 4);
    }
}

/* ================================================================
   OUTPUT
================================================================ */

$conn->close();

$safeName = preg_replace('/[^A-Za-z0-9\-]/', '', $guarantor['name'] ?: 'Guarantor');
$filename = 'Guarantor-Report-' . $safeName . '-' . (int) $guarantor['id'] . '.pdf';
$pdf->Output('I', $filename);
exit;