<?php
// ============================================================
// generate_template.php — Stephen Kanja School Management System
//
// Streams a ready-to-fill marks template for a chosen grade and
// subject list: Assessment No / First Name / Surname are
// pre-filled from the Student table, one blank column is added
// per selected subject for the teacher to fill in offline.
//
//   format=csv  (default) — fputcsv(), built into core PHP.
//   format=xlsx           — a hand-rolled .xlsx writer.
//
// WHY THE XLSX PATH WAS FAILING, AND WHAT CHANGED
//   1. It depended on the ZipArchive extension (and a temp file).
//      Many hosts don't have it, or block the temp directory, so
//      Excel downloads failed while CSV (core PHP only) worked.
//      The zip container is now written by hand in plain PHP
//      (build_zip_bytes) — no extension, no temp file.
//   2. Anything printed before the file bytes (a notice, a BOM or
//      a blank line at the end of conn.php) corrupts a binary
//      file. Output buffering now starts BEFORE conn.php is
//      included and is thrown away just before the download is
//      sent, and display_errors is off for this endpoint.
//   3. No Content-Length header is sent any more: if the host
//      compresses the response, a wrong length truncates the file.
//
// NOTE ON MATCHING: upload_excel_marks.php matches each uploaded
// row back to a learner PRIMARILY by First Name + Surname (scoped
// to the grade) and only falls back to Assessment No. Keep all
// three identity columns in this template, unedited.
// ============================================================

// Capture ANY stray output (from conn.php or notices) so it can never
// end up inside the downloaded file.
ob_start();
@ini_set('display_errors', '0');
@ini_set('zlib.output_compression', '0');

include 'conn.php';

// ── Canonical subject list — MUST match the codes used in
// student_dashboard.php and upload_excel_marks.php exactly, since
// the uploader matches these same display labels back to DB columns. ──
$subject_map = [
    'math'   => 'Mathematics',
    'eng'    => 'English',
    'kisw'   => 'Kiswahili',
    'sst'    => 'Social Studies',
    'scie'   => 'Science',
    'ca'     => 'CA',
    'agri'   => 'Agriculture',
    're'     => 'RE',
    'pretec' => 'Pre-Technical',
];

/* Discards everything buffered so far, then sends a plain-text error. */
function template_fail($code, $message) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

$grade = isset($_GET['grade']) ? (int)$_GET['grade'] : 0;
$requestedSubjects = isset($_GET['subjects']) && is_array($_GET['subjects']) ? $_GET['subjects'] : [];
// Whitelist: only accept subject codes we actually know about
$selectedSubjects = array_values(array_filter($requestedSubjects, function ($s) use ($subject_map) {
    return array_key_exists($s, $subject_map);
}));

$format = (isset($_GET['format']) && strtolower($_GET['format']) === 'xlsx') ? 'xlsx' : 'csv';

if ($grade < 1 || $grade > 9) {
    template_fail(400, 'Invalid or missing grade.');
}
if (empty($selectedSubjects)) {
    template_fail(400, 'Please select at least one subject.');
}

// ── Pull the grade's roster ──
$stmt = $conn->prepare(
    "SELECT Assesment, firstName, surname FROM Student WHERE Grade = ? AND role = 'user' ORDER BY surname, firstName"
);
if (!$stmt) {
    template_fail(500, 'Could not read the learner list: ' . $conn->error);
}
$stmt->bind_param('i', $grade);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
mysqli_close($conn);

// ── Shared row data, built once regardless of format ──
$headers = ['Assessment No', 'First Name', 'Surname'];
foreach ($selectedSubjects as $code) {
    $headers[] = $subject_map[$code];
}
$dataRows = [];
foreach ($students as $s) {
    $row = [$s['Assesment'], $s['firstName'], $s['surname']];
    foreach ($selectedSubjects as $code) {
        $row[] = ''; // blank — the teacher fills this in
    }
    $dataRows[] = $row;
}

// ============================================================
// XLSX PATH
// ============================================================
if ($format === 'xlsx') {

    /* Zero-based column index -> Excel letters (0 -> A, 25 -> Z, 26 -> AA ...) */
    function xlsx_index_to_col($zeroBasedIndex) {
        $n = $zeroBasedIndex + 1;
        $letters = '';
        while ($n > 0) {
            $rem = ($n - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $n = intdiv($n - 1, 26);
        }
        return $letters;
    }

    /* Makes a value safe for XML (drops control characters XML forbids). */
    function xlsx_clean($value) {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$value);
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /* Builds a .zip archive in memory from [name => contents]. Pure PHP:
       no ZipArchive extension and no temp file needed. Uses deflate when
       zlib is available, otherwise stores the data uncompressed — both
       are valid for Excel. */
    function build_zip_bytes(array $files) {
        $now = getdate();
        $dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | (int)($now['seconds'] / 2);
        $dosDate = (($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

        $body = '';
        $central = '';
        $count = 0;

        foreach ($files as $name => $data) {
            $crc  = crc32($data);
            $size = strlen($data);
            $method = 0;
            $packed = $data;
            if (function_exists('gzdeflate')) {
                $deflated = gzdeflate($data, 6);
                if ($deflated !== false && strlen($deflated) < $size) {
                    $method = 8;
                    $packed = $deflated;
                }
            }
            $csize  = strlen($packed);
            $offset = strlen($body);
            $nameLen = strlen($name);

            $body .= "PK\x03\x04"
                  . pack('v', 20) . pack('v', 0) . pack('v', $method)
                  . pack('v', $dosTime) . pack('v', $dosDate)
                  . pack('V', $crc) . pack('V', $csize) . pack('V', $size)
                  . pack('v', $nameLen) . pack('v', 0)
                  . $name . $packed;

            $central .= "PK\x01\x02"
                     . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', $method)
                     . pack('v', $dosTime) . pack('v', $dosDate)
                     . pack('V', $crc) . pack('V', $csize) . pack('V', $size)
                     . pack('v', $nameLen) . pack('v', 0) . pack('v', 0)
                     . pack('v', 0) . pack('v', 0) . pack('V', 0)
                     . pack('V', $offset)
                     . $name;
            $count++;
        }

        $end = "PK\x05\x06"
             . pack('v', 0) . pack('v', 0)
             . pack('v', $count) . pack('v', $count)
             . pack('V', strlen($central)) . pack('V', strlen($body))
             . pack('v', 0);

        return $body . $central . $end;
    }

    function build_xlsx_bytes(array $headerRow, array $dataRows) {
        $xmlHead = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

        $contentTypes = $xmlHead .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '</Types>';

        $rootRels = $xmlHead .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>';

        $workbook = $xmlHead .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="Marks" sheetId="1" r:id="rId1"/></sheets>' .
            '</workbook>';

        $workbookRels = $xmlHead .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
            '</Relationships>';

        // Two cell styles: 0 = normal, 1 = bold (used for the header row)
        $styles = $xmlHead .
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="2">' .
            '<font><sz val="11"/><name val="Calibri"/></font>' .
            '<font><b/><sz val="11"/><name val="Calibri"/></font>' .
            '</fonts>' .
            '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>' .
            '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="2">' .
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' .
            '</cellXfs>' .
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
            '</styleSheet>';

        $colCount = count($headerRow);
        $cols = '<cols>' .
            '<col min="1" max="1" width="16" customWidth="1"/>' .
            '<col min="2" max="3" width="18" customWidth="1"/>';
        if ($colCount > 3) {
            $cols .= '<col min="4" max="' . $colCount . '" width="16" customWidth="1"/>';
        }
        $cols .= '</cols>';

        $sheetXml = $xmlHead .
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            $cols . '<sheetData>';

        $allRows = array_merge([$headerRow], $dataRows);
        foreach ($allRows as $rowIdx => $rowValues) {
            $rowNum  = $rowIdx + 1;
            $styleAt = ($rowIdx === 0) ? ' s="1"' : '';
            $sheetXml .= '<row r="' . $rowNum . '">';
            foreach ($rowValues as $colIdx => $value) {
                $cellRef = xlsx_index_to_col($colIdx) . $rowNum;
                $sheetXml .= '<c r="' . $cellRef . '"' . $styleAt . ' t="inlineStr"><is><t xml:space="preserve">' .
                             xlsx_clean($value) . '</t></is></c>';
            }
            $sheetXml .= '</row>';
        }
        $sheetXml .= '</sheetData></worksheet>';

        // [Content_Types].xml must come first in the archive
        return build_zip_bytes([
            '[Content_Types].xml'        => $contentTypes,
            '_rels/.rels'                => $rootRels,
            'xl/workbook.xml'            => $workbook,
            'xl/_rels/workbook.xml.rels' => $workbookRels,
            'xl/styles.xml'              => $styles,
            'xl/worksheets/sheet1.xml'   => $sheetXml,
        ]);
    }

    try {
        $bytes = build_xlsx_bytes($headers, $dataRows);
    } catch (Throwable $e) {
        template_fail(500, 'Could not generate the Excel file: ' . $e->getMessage());
    }

    // Throw away anything that was printed earlier, so the file is clean bytes only
    while (ob_get_level() > 0) { ob_end_clean(); }

    $filename = "Grade{$grade}_Marks_Template.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

// ============================================================
// CSV PATH (default)
// ============================================================
while (ob_get_level() > 0) { ob_end_clean(); }

$filename = "Grade{$grade}_Marks_Template.csv";
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel on Windows detects the encoding correctly
// instead of mangling names with accented characters.
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $headers);
foreach ($dataRows as $row) {
    fputcsv($out, $row);
}
fclose($out);
exit;