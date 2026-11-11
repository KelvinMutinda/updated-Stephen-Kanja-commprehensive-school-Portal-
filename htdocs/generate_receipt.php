<?php
/*
 * generate_receipt.php
 * =====================
 * Builds a Word (.docx) document with fee payment receipts, three
 * receipts per A4 page, and streams it as a download.
 *
 * NO EXTERNAL LIBRARY REQUIRED. A .docx file is just a ZIP archive
 * of XML parts — this builds that ZIP directly with PHP's built-in
 * ZipArchive class, so it works on hosts (like InfinityFree) where
 * Composer/vendor packages aren't available.
 *
 * CHANGE (fee balances): each receipt now also prints how much the
 * student still owes for that term (Expected fees from FeeStructure
 * minus everything paid to date for that grade/term/year — not just
 * this one payment), or confirms fees are cleared.
 *
 * GET param: ids — comma-separated Fees.id values, e.g.
 *            generate_receipt.php?ids=14,15,16
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
include 'conn.php';

$schoolName  = 'STEPHEN KANJA PRIMARY AND JUNIOR SCHOOL';
$schoolMotto = 'Aim Higher';

/* ── Resolve which Fees rows to print ──
   Either an explicit ids=1,2,3 list (used right after a payment, or
   from ticked checkboxes on ViewFees.php), or a filter combination
   (grade / term / year / search) so receipts can be pulled for a
   single learner, a whole grade, or a term/year period. */
$ids = [];
if (!empty($_GET['ids'])) {
    $ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
}

if (empty($ids) && (isset($_GET['grade']) || isset($_GET['term']) || isset($_GET['year']) || isset($_GET['search']))) {
    $f_grade  = isset($_GET['grade'])  ? mysqli_real_escape_string($conn, $_GET['grade'])  : '';
    $f_term   = isset($_GET['term'])   ? mysqli_real_escape_string($conn, $_GET['term'])   : '';
    $f_year   = isset($_GET['year'])   ? mysqli_real_escape_string($conn, $_GET['year'])   : '';
    $f_search = isset($_GET['search']) ? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';

    $where = [];
    if ($f_grade  !== '') $where[] = "Grade = '$f_grade'";
    if ($f_term   !== '') $where[] = "Term = '$f_term'";
    if ($f_year   !== '') $where[] = "Year = '$f_year'";
    if ($f_search !== '') $where[] = "(firstName LIKE '%$f_search%' OR surname LIKE '%$f_search%' OR Assesment LIKE '%$f_search%' OR StudentID LIKE '%$f_search%')";

    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $idsRes = mysqli_query($conn, "SELECT id FROM Fees $whereSql ORDER BY id");
        if ($idsRes) {
            while ($r = mysqli_fetch_assoc($idsRes)) $ids[] = (int)$r['id'];
        }
    }
}

if (empty($ids)) {
    http_response_code(400);
    die('No payment records specified. Use generate_receipt.php?ids=1,2,3 or ?grade=..&term=..&year=..&search=..');
}

$idList = implode(',', $ids);
$sql = "SELECT id, StudentID, Assesment, firstName, surname, Fee, AssesmentFee, Activity, Other AS OtherFee, Grade, Term, Year, payment_date
        FROM Fees
        WHERE id IN ($idList)
        ORDER BY id";
$result = mysqli_query($conn, $sql);

if (!$result || mysqli_num_rows($result) === 0) {
    http_response_code(404);
    die('No matching payment records found.');
}

/* ══════════════════════════════════════════════════════════
   Balance helpers (mirrors fetch_students.php)
   ══════════════════════════════════════════════════════════ */

/** Expected fee totals for a grade/term/year, or null if no FeeStructure row covers it. */
function getExpectedFeesForReceipt($conn, $grade, $term, $year) {
    $grade_e = (int)$grade; $term_e = (int)$term; $year_e = (int)$year;
    $sql = "SELECT ExpectedFee, ExpectedAssesmentFee, ExpectedActivity, ExpectedOther
            FROM FeeStructure
            WHERE FIND_IN_SET($grade_e, Grade) AND Term = $term_e AND Year = $year_e
            LIMIT 1";
    $res = mysqli_query($conn, $sql);
    if (!$res || mysqli_num_rows($res) === 0) return null;
    $row = mysqli_fetch_assoc($res);
    $fee = (float)$row['ExpectedFee']; $ass = (float)$row['ExpectedAssesmentFee'];
    $act = (float)$row['ExpectedActivity']; $other = (float)$row['ExpectedOther'];
    return ['total' => $fee + $ass + $act + $other];
}

/** Everything paid to date (across ALL Fees rows, not just this receipt) for one student's grade/term/year. */
function getPaidToDateForReceipt($conn, $studentId, $grade, $term, $year) {
    $studentId_e = (int)$studentId; $grade_e = (int)$grade; $term_e = (int)$term; $year_e = (int)$year;
    $sql = "SELECT COALESCE(SUM(Fee),0)+COALESCE(SUM(AssesmentFee),0)+COALESCE(SUM(Activity),0)+COALESCE(SUM(Other),0) AS total
            FROM Fees
            WHERE StudentID = $studentId_e AND Grade = $grade_e AND Term = $term_e AND Year = $year_e";
    $res = mysqli_query($conn, $sql);
    if (!$res) return 0.0;
    $row = mysqli_fetch_assoc($res);
    return (float)$row['total'];
}

$receipts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $total = (float)$row['Fee'] + (float)$row['AssesmentFee'] + (float)$row['Activity'] + (float)$row['OtherFee'];

    $balanceKnown = false;
    $balance = 0.0;
    // Only meaningful when the payment carries a real StudentID (legacy
    // rows saved before that column existed won't have one).
    if (!empty($row['StudentID'])) {
        $expected = getExpectedFeesForReceipt($conn, $row['Grade'], $row['Term'], $row['Year']);
        if ($expected !== null) {
            $paidToDate = getPaidToDateForReceipt($conn, $row['StudentID'], $row['Grade'], $row['Term'], $row['Year']);
            $balance = $expected['total'] - $paidToDate;
            $balanceKnown = true;
        }
    }

    $receipts[] = [
        'receiptNo'    => 'RCT-' . str_pad($row['id'], 6, '0', STR_PAD_LEFT),
        'studentId'    => ($row['StudentID'] !== null && $row['StudentID'] !== '') ? $row['StudentID'] : $row['Assesment'],
        'name'         => trim($row['firstName'] . ' ' . $row['surname']),
        'grade'        => $row['Grade'],
        'term'         => $row['Term'],
        'year'         => $row['Year'],
        'date'         => date('d M Y', strtotime($row['payment_date'])),
        'fee'          => (float)$row['Fee'],
        'assessment'   => (float)$row['AssesmentFee'],
        'activity'     => (float)$row['Activity'],
        'other'        => (float)$row['OtherFee'],
        'total'        => $total,
        'balanceKnown' => $balanceKnown,
        'balance'      => $balance,
    ];
}

/* ══════════════════════════════════════════════════════════
   Minimal WordprocessingML helpers
   ══════════════════════════════════════════════════════════ */

function wx($s) {
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

/** A single text run with optional bold/italic/color/size(pt)/caps */
function w_run($text, $opts = []) {
    $rPr = '';
    if (!empty($opts['bold']))   $rPr .= '<w:b/>';
    if (!empty($opts['italic'])) $rPr .= '<w:i/>';
    if (!empty($opts['caps']))   $rPr .= '<w:caps/>';
    if (!empty($opts['color']))  $rPr .= '<w:color w:val="' . $opts['color'] . '"/>';
    if (!empty($opts['size'])) {
        $half = (int)($opts['size'] * 2);
        $rPr .= '<w:sz w:val="' . $half . '"/><w:szCs w:val="' . $half . '"/>';
    }
    $rPrXml = $rPr ? "<w:rPr>{$rPr}</w:rPr>" : '';
    return "<w:r>{$rPrXml}<w:t xml:space=\"preserve\">" . wx($text) . "</w:t></w:r>";
}

/** A paragraph containing one run, with optional alignment + spacing (twips) */
function w_para($text, $opts = []) {
    $pPrParts = '';
    if (!empty($opts['align'])) $pPrParts .= '<w:jc w:val="' . $opts['align'] . '"/>';
    if (isset($opts['spaceBefore']) || isset($opts['spaceAfter'])) {
        $before = $opts['spaceBefore'] ?? 0;
        $after  = $opts['spaceAfter']  ?? 0;
        $pPrParts .= '<w:spacing w:before="' . $before . '" w:after="' . $after . '"/>';
    }
    $pPr = $pPrParts ? "<w:pPr>{$pPrParts}</w:pPr>" : '';
    return "<w:p>{$pPr}" . w_run($text, $opts) . "</w:p>";
}

/** A table cell: content is raw XML (usually one or more w_para calls) */
function w_cell($contentXml, $widthTwips, $opts = []) {
    $tcPr = '<w:tcW w:w="' . $widthTwips . '" w:type="dxa"/>';
    if (!empty($opts['span']))  $tcPr .= '<w:gridSpan w:val="' . $opts['span'] . '"/>';
    if (!empty($opts['bg']))    $tcPr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $opts['bg'] . '"/>';
    if (!empty($opts['valign'])) $tcPr .= '<w:vAlign w:val="' . $opts['valign'] . '"/>';
    if (!empty($opts['borders'])) {
        // custom per-cell borders, e.g. a dashed stamp box
        $b = $opts['borders'];
        $tcPr .= '<w:tcBorders>'
            . '<w:top w:val="' . $b . '" w:sz="8" w:color="999999"/>'
            . '<w:left w:val="' . $b . '" w:sz="8" w:color="999999"/>'
            . '<w:bottom w:val="' . $b . '" w:sz="8" w:color="999999"/>'
            . '<w:right w:val="' . $b . '" w:sz="8" w:color="999999"/>'
            . '</w:tcBorders>';
    }
    return "<w:tc><w:tcPr>{$tcPr}</w:tcPr>{$contentXml}</w:tc>";
}

/** A table row from an array of already-built cell XML strings */
function w_row($cellsXml) {
    return '<w:tr>' . implode('', $cellsXml) . '</w:tr>';
}

/* ══════════════════════════════════════════════════════════
   Build one receipt block (a single bordered table)
   ══════════════════════════════════════════════════════════ */

function buildReceiptTable($r, $schoolName, $schoolMotto) {
    // 4 columns, widths in twips, summing to ~9000 (usable width on A4 w/ narrow margins)
    $c1 = 2200; $c2 = 2600; $c3 = 2000; $c4 = 2200;
    $fullWidth = $c1 + $c2 + $c3 + $c4;

    $rows = [];

    // Title
    $rows[] = w_row([
        w_cell(w_para($schoolName, ['bold' => true, 'size' => 13, 'align' => 'center']), $fullWidth, ['span' => 4]),
    ]);
    $rows[] = w_row([
        w_cell(w_para($schoolMotto, ['italic' => true, 'size' => 8, 'color' => 'C9A030', 'align' => 'center']), $fullWidth, ['span' => 4]),
    ]);
    $rows[] = w_row([
        w_cell(w_para('OFFICIAL FEE PAYMENT RECEIPT', ['bold' => true, 'size' => 10, 'color' => 'C9A030', 'align' => 'center', 'spaceBefore' => 40, 'spaceAfter' => 40]), $fullWidth, ['span' => 4]),
    ]);

    $label = ['bold' => true, 'size' => 9, 'color' => '5F5E5A'];
    $value = ['size' => 9, 'color' => '1A1A18'];

    $rows[] = w_row([
        w_cell(w_para('Receipt No.', $label), $c1),
        w_cell(w_para($r['receiptNo'], $value), $c2),
        w_cell(w_para('Date', $label), $c3),
        w_cell(w_para($r['date'], $value), $c4),
    ]);
    $rows[] = w_row([
        w_cell(w_para('Student ID', $label), $c1),
        w_cell(w_para((string)$r['studentId'], $value), $c2),
        w_cell(w_para('Grade', $label), $c3),
        w_cell(w_para('Grade ' . $r['grade'], $value), $c4),
    ]);
    $rows[] = w_row([
        w_cell(w_para('Student Name', $label), $c1),
        w_cell(w_para($r['name'], $value), $c2),
        w_cell(w_para('Term / Year', $label), $c3),
        w_cell(w_para('Term ' . $r['term'] . ' / ' . $r['year'], $value), $c4),
    ]);

    // Fee breakdown header
    $head = ['bold' => true, 'size' => 8, 'caps' => true, 'color' => 'FFFFFF', 'align' => 'center'];
    $rows[] = w_row([
        w_cell(w_para('School Fees',    $head), $c1, ['bg' => '111111']),
        w_cell(w_para('Assessment Fee', $head), $c2, ['bg' => '111111']),
        w_cell(w_para('Activity Fees',  $head), $c3, ['bg' => '111111']),
        w_cell(w_para('Other Fees',     $head), $c4, ['bg' => '111111']),
    ]);
    $amt = ['size' => 9, 'color' => '1A1A18', 'align' => 'center'];
    $rows[] = w_row([
        w_cell(w_para('KES ' . number_format($r['fee'], 2), $amt), $c1),
        w_cell(w_para('KES ' . number_format($r['assessment'], 2), $amt), $c2),
        w_cell(w_para('KES ' . number_format($r['activity'], 2), $amt), $c3),
        w_cell(w_para('KES ' . number_format($r['other'], 2), $amt), $c4),
    ]);

    // Total
    $rows[] = w_row([
        w_cell(w_para('TOTAL PAID: KES ' . number_format($r['total'], 2),
            ['bold' => true, 'size' => 11, 'color' => '16A34A', 'align' => 'right', 'spaceBefore' => 60, 'spaceAfter' => 20]),
            $fullWidth, ['span' => 4]),
    ]);

    // Balance line — what's still owed for this term (or that it's cleared)
    if (!empty($r['balanceKnown'])) {
        if ($r['balance'] > 0.004) {
            $balanceText  = 'BALANCE OUTSTANDING (this term): KES ' . number_format($r['balance'], 2);
            $balanceColor = 'DC2626';
        } elseif ($r['balance'] < -0.004) {
            $balanceText  = 'OVERPAID (this term): KES ' . number_format(abs($r['balance']), 2);
            $balanceColor = '16A34A';
        } else {
            $balanceText  = 'FEES CLEARED FOR THIS TERM';
            $balanceColor = '16A34A';
        }
        $rows[] = w_row([
            w_cell(w_para($balanceText,
                ['bold' => true, 'size' => 9, 'color' => $balanceColor, 'align' => 'right', 'spaceBefore' => 0, 'spaceAfter' => 60]),
                $fullWidth, ['span' => 4]),
        ]);
    }

    // Signature + stamp box
    $sigContent = w_para('_______________________________', $value)
                . w_para('Bursar / Cashier Signature', ['size' => 7, 'color' => '888780']);
    $stampContent = w_para('OFFICIAL', ['size' => 7, 'color' => 'AAAAAA', 'align' => 'center'])
                  . w_para('SCHOOL STAMP', ['size' => 7, 'color' => 'AAAAAA', 'align' => 'center']);

    $rows[] = w_row([
        w_cell($sigContent, $c1 + $c2, ['span' => 2, 'valign' => 'bottom']),
        w_cell($stampContent, $c3 + $c4, ['span' => 2, 'valign' => 'center', 'borders' => 'dashed']),
    ]);

    $tblBorders = '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="8" w:color="111111"/>'
        . '<w:left w:val="single" w:sz="8" w:color="111111"/>'
        . '<w:bottom w:val="single" w:sz="8" w:color="111111"/>'
        . '<w:right w:val="single" w:sz="8" w:color="111111"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="DDDDDD"/>'
        . '<w:insideV w:val="single" w:sz="4" w:color="DDDDDD"/>'
        . '</w:tblBorders>';

    $tblGrid = '<w:tblGrid>'
        . '<w:gridCol w:w="' . $c1 . '"/>'
        . '<w:gridCol w:w="' . $c2 . '"/>'
        . '<w:gridCol w:w="' . $c3 . '"/>'
        . '<w:gridCol w:w="' . $c4 . '"/>'
        . '</w:tblGrid>';

    $tblPr = '<w:tblPr><w:tblW w:w="' . $fullWidth . '" w:type="dxa"/>' . $tblBorders . '<w:tblCellMar>'
        . '<w:top w:w="60" w:type="dxa"/><w:bottom w:w="60" w:type="dxa"/>'
        . '<w:left w:w="90" w:type="dxa"/><w:right w:w="90" w:type="dxa"/>'
        . '</w:tblCellMar></w:tblPr>';

    return '<w:tbl>' . $tblPr . $tblGrid . implode('', $rows) . '</w:tbl>';
}

/* ══════════════════════════════════════════════════════════
   Assemble the document body: 3 receipts per page
   ══════════════════════════════════════════════════════════ */

$bodyXml = '';
$count = 0;
foreach ($receipts as $r) {
    if ($count > 0 && $count % 3 === 0) {
        $bodyXml .= '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    } else {
        // spacer + dotted cut line between stacked receipts on the same page
        $bodyXml .= w_para(str_repeat('·  ', 55), ['size' => 6, 'color' => 'CCCCCC', 'align' => 'center', 'spaceBefore' => 80, 'spaceAfter' => 80]);
    }
    $bodyXml .= buildReceiptTable($r, $schoolName, $schoolMotto);
    $count++;
}

// A4 page, narrow margins (twips): 11906 x 16838, margins 500
$sectPr = '<w:sectPr>'
    . '<w:pgSz w:w="11906" w:h="16838"/>'
    . '<w:pgMar w:top="400" w:right="500" w:bottom="400" w:left="500" w:header="0" w:footer="0" w:gutter="0"/>'
    . '</w:sectPr>';

$documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
    . '<w:body>' . $bodyXml . $sectPr . '</w:body>'
    . '</w:document>';

/* ══════════════════════════════════════════════════════════
   The other required OOXML parts (fixed boilerplate)
   ══════════════════════════════════════════════════════════ */

$contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
    . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
    . '</Types>';

$relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
    . '</Relationships>';

$docRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';

$coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
    . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
    . '<dc:title>Fee Receipts</dc:title>'
    . '<dc:creator>Stephen Kanja School Portal</dc:creator>'
    . '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>'
    . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:modified>'
    . '</cp:coreProperties>';

$appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
    . '<Application>Stephen Kanja School Portal</Application>'
    . '</Properties>';

/* ══════════════════════════════════════════════════════════
   Zip it up and stream as .docx
   ══════════════════════════════════════════════════════════ */

$tmpFile = tempnam(sys_get_temp_dir(), 'receipt') . '.docx';
$zip = new ZipArchive();
if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die('Could not create the receipt document.');
}
$zip->addFromString('[Content_Types].xml', $contentTypesXml);
$zip->addFromString('_rels/.rels', $relsXml);
$zip->addFromString('word/document.xml', $documentXml);
$zip->addFromString('word/_rels/document.xml.rels', $docRelsXml);
$zip->addFromString('docProps/core.xml', $coreXml);
$zip->addFromString('docProps/app.xml', $appXml);
$zip->close();

$filename = 'Fee_Receipts_' . date('Ymd_His') . '.docx';
header('Content-Description: File Transfer');
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . filesize($tmpFile));
header('Pragma: public');
header('Expires: 0');
header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

readfile($tmpFile);
unlink($tmpFile);
exit;