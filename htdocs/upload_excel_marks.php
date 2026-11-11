<?php
// ============================================================
// upload_excel_marks.php — FIXED VERSION 4
// Properly reads ALL rows and inserts ALL students
// Uses auto-increment ID in exam2
//
// FIX (v2): prepare() failures on any row after the first were
// fatal — calling bind_param() on a `false` result from a bad
// prepare() kills the whole script mid-loop. Both insert and
// update prepares are now guarded, logged, and skipped per-row.
//
// FIX (v3): Grade 1–3 reuse the `scie` column for "Environmental
// Activities". Added explicit header aliases so that column is
// detected instead of silently dropped.
//
// FIX (v4): ASSESSMENT NO WAS NEVER SAVED.
//   Root cause: this script copied Assesment from the Student table,
//   and for these learners Student.Assesment is BLANK — so exam2 got a
//   blank too (student_id was saved fine, which is why matching worked).
//   Now:
//   1. The upload file may contain an "Assessment No" column (many
//      header spellings accepted).
//   2. If the learner's Student.Assesment is blank and the file has a
//      value, it is written back to Student (when it does not clash with
//      another learner in the same grade) and used for exam2.
//   3. When a result row already exists in exam2, its Assesment,
//      firstName and lastName are refreshed too — before, only the
//      marks were updated, so a blank Assesment stayed blank forever.
//   4. The final message tells you how many learners STILL have no
//      Assessment No, so it can't fail silently again.
// ============================================================

// ── Enable error reporting ──
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── Create a debug log file ──
$debugFile = fopen("upload_debug.log", "a");
fwrite($debugFile, "\n========================================\n");
fwrite($debugFile, date('Y-m-d H:i:s') . " - UPLOAD STARTED\n");

// ── Hand-rolled minimal .xlsx reader ──
function xlsx_col_to_index($cellRef) {
    preg_match('/^([A-Z]+)/', $cellRef, $m);
    $letters = isset($m[1]) ? $m[1] : 'A';
    $index = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

function read_xlsx_rows($tmpPath) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive unavailable. Please upload CSV.');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        throw new Exception('Could not open Excel file.');
    }

    $sharedStrings = array();
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ssDom = new SimpleXMLElement($ssXml);
        foreach ($ssDom->si as $si) {
            if (isset($si->t)) {
                $sharedStrings[] = (string)$si->t;
            } else {
                $text = '';
                foreach ($si->r as $run) { $text .= (string)$run->t; }
                $sharedStrings[] = $text;
            }
        }
    }

    $sheetPath = 'xl/worksheets/sheet1.xml';
    if ($zip->locateName($sheetPath) === false) {
        $sheetPath = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetPath = $name;
                break;
            }
        }
    }
    if ($sheetPath === null) {
        $zip->close();
        throw new Exception('No worksheet found.');
    }

    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new Exception('Could not read worksheet.');
    }

    $sheetDom = new SimpleXMLElement($sheetXml);
    $rows = array();

    if (isset($sheetDom->sheetData->row)) {
        foreach ($sheetDom->sheetData->row as $rowXml) {
            $rowCells = array();
            $maxIdx = -1;

            foreach ($rowXml->c as $c) {
                $ref  = (string)$c['r'];
                $idx  = xlsx_col_to_index($ref);
                $type = (string)$c['t'];
                $value = isset($c->v) ? (string)$c->v : '';

                if ($type === 's') {
                    $value = isset($sharedStrings[(int)$value]) ? $sharedStrings[(int)$value] : '';
                } elseif ($type === 'inlineStr') {
                    $value = isset($c->is->t) ? (string)$c->is->t : '';
                }

                $rowCells[$idx] = $value;
                if ($idx > $maxIdx) $maxIdx = $idx;
            }

            $normalized = array();
            for ($i = 0; $i <= $maxIdx; $i++) {
                $normalized[$i] = isset($rowCells[$i]) ? $rowCells[$i] : '';
            }
            $rows[] = $normalized;
        }
    }

    return $rows;
}

/* Tidy an Assessment No read from a spreadsheet cell:
   trims it and turns Excel's "1234.0" back into "1234". */
function clean_assess($v) {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (is_numeric($v) && strpos($v, '.') !== false && (float)$v == floor((float)$v)) {
        $v = (string)(int)(float)$v;
    }
    return substr($v, 0, 30);
}

session_start();
include 'conn.php';

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

function redirect_with_message($msg, $type = 'success') {
    header('Location: /UploadResults?excel_msg=' . urlencode($msg) . '&excel_status=' . $type);
    exit;
}

// ── Canonical subject list ──
$subject_map = array(
    'math' => 'Mathematics',
    'eng' => 'English',
    'kisw' => 'Kiswahili',
    'sst' => 'Social Studies',
    'scie' => 'Science',
    'ca' => 'CA',
    'agri' => 'Agriculture',
    're' => 'RE',
    'pretec' => 'Pre-Technical',
);

$label_to_code = array();
foreach ($subject_map as $code => $label) {
    $label_to_code[strtolower($label)] = $code;
    $label_to_code[strtolower($code)] = $code;
}

// Grade 1–3 reuse the `scie` column for "Environmental Activities".
$label_to_code['environmental activities'] = 'scie';
$label_to_code['environmental science']    = 'scie';

$first_name_header_aliases = array('first name', 'firstname', 'first');
$surname_header_aliases    = array('surname', 'last name', 'lastname', 'second name');

// Header spellings accepted for the Assessment No column (compared after
// lower-casing and trimming). "Assesment" is how the database spells it.
$assess_header_aliases = array(
    'assessment no', 'assesment no', 'assessment number', 'assesment number',
    'assessment', 'assesment', 'assess no', 'assess. no', 'assess no.',
    'assessment no.', 'assesment no.', 'adm', 'adm.', 'adm no', 'adm no.',
    'admission no', 'admission number',
);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_message('No file was uploaded.', 'error');
}

// ── Check for post_max_size exceeded ──
if (empty($_POST) && empty($_FILES) && !empty($_SERVER['CONTENT_LENGTH']) && (int)$_SERVER['CONTENT_LENGTH'] > 0) {
    $postMaxSize = ini_get('post_max_size');
    redirect_with_message("File too large (limit: {$postMaxSize}).", 'error');
}

if (!isset($_FILES['marksFile'])) {
    redirect_with_message('No file uploaded.', 'error');
}

// ── Read + validate context fields ──
$grade    = isset($_POST['grade']) ? (int)$_POST['grade'] : 0;
$termNum  = isset($_POST['term']) ? preg_replace('/[^0-9]/', '', $_POST['term']) : '';
$year     = isset($_POST['year']) ? (int)$_POST['year'] : 0;
$examType = isset($_POST['examType']) ? trim($_POST['examType']) : '';
$allowedExamTypes = array('opener', 'midterm', 'endterm');
$etQuery = @mysqli_query($conn, "SELECT code FROM exam_types");
if ($etQuery) {
    while ($etRow = mysqli_fetch_assoc($etQuery)) {
        $allowedExamTypes[] = $etRow['code'];
    }
}

if (!$grade || $termNum === '' || !$year || !in_array($examType, $allowedExamTypes, true)) {
    redirect_with_message('Grade, term, exam type, and year are required.', 'error');
}
$termLabel = "Term $termNum";

// ── Validate file ──
$file = $_FILES['marksFile'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    redirect_with_message('File upload error: ' . $file['error'], 'error');
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowedExts = array('csv', 'xlsx');
if (!in_array($ext, $allowedExts, true)) {
    redirect_with_message('Please upload .csv or .xlsx file.', 'error');
}

// ── Read the file ──
$headerFields = array();
$dataRows     = array();

if ($ext === 'csv') {
    $content = file_get_contents($file['tmp_name']);
    if ($content === false || trim($content) === '') {
        redirect_with_message('File is empty.', 'error');
    }
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $content = str_replace(array("\r\n", "\r"), "\n", $content);

    $lines = array_values(array_filter(explode("\n", $content), function ($l) {
        return trim($l) !== '';
    }));

    if (count($lines) < 2) {
        redirect_with_message('No data rows.', 'error');
    }

    $firstLine = $lines[0];
    $delimiter = (substr_count($firstLine, ';') > substr_count($firstLine, ',')) ? ';' : ',';

    $headerFields = str_getcsv(array_shift($lines), $delimiter);
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $dataRows[] = str_getcsv($line, $delimiter);
    }
} else {
    try {
        $rows = read_xlsx_rows($file['tmp_name']);
    } catch (Exception $e) {
        redirect_with_message('Excel read error: ' . $e->getMessage(), 'error');
    }

    // Remove empty rows
    $rows = array_values(array_filter($rows, function ($row) {
        foreach ($row as $cell) {
            if (trim((string)$cell) !== '') return true;
        }
        return false;
    }));

    if (count($rows) < 2) {
        redirect_with_message('No data rows.', 'error');
    }

    $headerFields = array_map(function ($v) { return (string)$v; }, array_shift($rows));
    foreach ($rows as $row) {
        $dataRows[] = array_map(function ($v) { return (string)$v; }, $row);
    }
}

fwrite($debugFile, "Total data rows read from file: " . count($dataRows) . "\n");

// ── Locate columns ──
$firstNameColIdx = null;
$surnameColIdx   = null;
$assessColIdx    = null;
$subjectCols     = array();

foreach ($headerFields as $idx => $headerText) {
    // strip a possible BOM / non-breaking spaces so "﻿First Name" still matches
    $h = strtolower(trim(str_replace(array("\xEF\xBB\xBF", "\xC2\xA0"), ' ', (string)$headerText)));
    if ($h === '') continue;

    if (in_array($h, $first_name_header_aliases, true)) {
        $firstNameColIdx = $idx;
    } elseif (in_array($h, $surname_header_aliases, true)) {
        $surnameColIdx = $idx;
    } elseif (in_array($h, $assess_header_aliases, true)) {
        $assessColIdx = $idx;
    } elseif (isset($label_to_code[$h])) {
        $subjectCols[$label_to_code[$h]] = $idx;
    }
}

if ($firstNameColIdx === null || $surnameColIdx === null) {
    redirect_with_message('Could not find "First Name" and "Surname" columns.', 'error');
}
if (empty($subjectCols)) {
    redirect_with_message('No recognized subject columns found.', 'error');
}

fwrite($debugFile, "Subject columns detected: " . implode(', ', array_keys($subjectCols)) . "\n");
fwrite($debugFile, "Assessment No column in file: " . ($assessColIdx === null ? 'NOT FOUND' : 'column index ' . $assessColIdx) . "\n");

// ── Process each row ──
$insertedCount   = 0;
$updatedCount    = 0;
$skippedRows     = 0;
$errorRows       = 0;
$totalStudents   = 0;
$assessFilled    = 0;   // Student.Assesment filled in from the file
$assessConflicts = 0;   // file value clashed with another learner in the grade
$blankAssess     = 0;   // learners still without an Assessment No after this upload

// Get all students in this grade for matching
// Matching is by first name + surname; the real key used for exam2 is Student.id.
$allStudents = array();
$studentQuery = $conn->query("SELECT id, Assesment, firstName, surname FROM Student WHERE Grade = $grade");
while ($row = $studentQuery->fetch_assoc()) {
    $key = strtolower(trim($row['firstName'])) . '|' . strtolower(trim($row['surname']));
    $allStudents[$key] = $row;
}

// Get existing records for this exam to check for updates (keyed by Student.id)
$existingRecords = array();
$existingQuery = $conn->query("
    SELECT id, student_id FROM exam2
    WHERE grade = $grade AND term = '$termLabel' AND year = $year AND exam_type = '$examType'
");
while ($row = $existingQuery->fetch_assoc()) {
    $existingRecords[$row['student_id']] = $row['id'];
}
fwrite($debugFile, "Existing exam2 records found for grade=$grade term='$termLabel' year=$year exam_type=$examType: " . count($existingRecords) . "\n");

// Process each row
foreach ($dataRows as $rowIndex => $fields) {
    $lineNum = $rowIndex + 2; // +1 for header, +1 for 1-based display
    $firstName = trim((string)(isset($fields[$firstNameColIdx]) ? $fields[$firstNameColIdx] : ''));
    $surname   = trim((string)(isset($fields[$surnameColIdx]) ? $fields[$surnameColIdx] : ''));

    if ($firstName === '' || $surname === '') {
        fwrite($debugFile, "Row $lineNum: SKIPPED - empty first name or surname\n");
        $skippedRows++;
        continue;
    }

    $totalStudents++;
    $key = strtolower($firstName) . '|' . strtolower($surname);

    // Find student in the grade
    if (!isset($allStudents[$key])) {
        fwrite($debugFile, "Row $lineNum: SKIPPED - '$firstName $surname' not found in Student table for grade $grade\n");
        $skippedRows++;
        continue;
    }

    $student   = $allStudents[$key];
    $studentId = $student['id'];

    // ── Assessment No: Student record first, otherwise the file ──
    $assesment  = trim((string)$student['Assesment']);
    $fileAssess = ($assessColIdx !== null && isset($fields[$assessColIdx])) ? clean_assess($fields[$assessColIdx]) : '';

    if ($assesment === '' && $fileAssess !== '') {
        // Make sure no OTHER learner in this grade already uses that number
        $clash = false;
        $chk = $conn->prepare("SELECT id FROM Student WHERE Assesment = ? AND Grade = ? AND id <> ? LIMIT 1");
        if ($chk) {
            $sidStr = (string)$studentId;
            $chk->bind_param('sis', $fileAssess, $grade, $sidStr);
            $chk->execute();
            $cr = $chk->get_result();
            $clash = ($cr && $cr->num_rows > 0);
            $chk->close();
        }

        if ($clash) {
            fwrite($debugFile, "Row $lineNum: WARNING - Assessment No '$fileAssess' for '$firstName $surname' is already used by another learner in grade $grade; NOT saved\n");
            $assessConflicts++;
        } else {
            $fill = $conn->prepare("UPDATE Student SET Assesment = ? WHERE id = ? LIMIT 1");
            if ($fill) {
                $sidStr = (string)$studentId;
                $fill->bind_param('ss', $fileAssess, $sidStr);
                if ($fill->execute()) {
                    $assesment = $fileAssess;
                    $allStudents[$key]['Assesment'] = $fileAssess;
                    $assessFilled++;
                    fwrite($debugFile, "Row $lineNum: Assessment No '$fileAssess' saved to Student record of '$firstName $surname'\n");
                } else {
                    fwrite($debugFile, "Row $lineNum: ERROR - could not save Assessment No to Student: " . $fill->error . "\n");
                }
                $fill->close();
            } else {
                fwrite($debugFile, "Row $lineNum: ERROR - prepare() failed for Student Assesment update: " . $conn->error . "\n");
            }
        }
    }

    if ($assesment === '') {
        $blankAssess++;
        fwrite($debugFile, "Row $lineNum: NOTE - '$firstName $surname' has NO Assessment No (blank in Student and not in the file)\n");
    }

    // Build the insert/update data
    $subjectData = array();
    $hasMarks = false;

    foreach ($subjectCols as $code => $idx) {
        $raw = trim((string)(isset($fields[$idx]) ? $fields[$idx] : ''));
        if ($raw !== '' && is_numeric($raw)) {
            $score = (float)$raw;
            $score = max(0, min(100, $score));
            $subjectData[$code] = $score;
            $hasMarks = true;
        }
    }

    if (!$hasMarks) {
        fwrite($debugFile, "Row $lineNum: SKIPPED - '$firstName $surname' matched but no numeric marks found\n");
        continue;
    }

    // Check if record exists for this student
    if (isset($existingRecords[$studentId])) {
        // UPDATE existing record — now also refreshes Assesment / names
        $recordId = $existingRecords[$studentId];
        $updateFields = array();
        $updateValues = array();
        $types = '';

        if ($assesment !== '') {
            $updateFields[] = "`Assesment` = ?";
            $updateValues[] = $assesment;
            $types .= 's';
        }
        $updateFields[] = "`firstName` = ?";
        $updateValues[] = $student['firstName'];
        $types .= 's';
        $updateFields[] = "`lastName` = ?";
        $updateValues[] = $student['surname'];
        $types .= 's';

        foreach ($subjectData as $code => $score) {
            $updateFields[] = "`$code` = ?";
            $updateValues[] = $score;
            $types .= 'd';
        }

        $updateValues[] = $recordId;
        $types .= 'i';
        $updateSQL = "UPDATE exam2 SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $updateStmt = $conn->prepare($updateSQL);

        if ($updateStmt === false) {
            fwrite($debugFile, "Row $lineNum: ERROR - prepare() failed for UPDATE ('$firstName $surname'): " . $conn->error . " | SQL: $updateSQL\n");
            $errorRows++;
        } else {
            $updateStmt->bind_param($types, ...$updateValues);
            if ($updateStmt->execute()) {
                $affected = $updateStmt->affected_rows;
                fwrite($debugFile, "Row $lineNum: UPDATE ran for '$firstName $surname' (record id $recordId, affected_rows=$affected, subjects=" . implode(',', array_keys($subjectData)) . ")\n");
                if ($affected === 0) {
                    fwrite($debugFile, "Row $lineNum: NOTE - UPDATE changed 0 rows for id $recordId (values already identical, or that id no longer exists in exam2).\n");
                }
                $updatedCount++;
            } else {
                fwrite($debugFile, "Row $lineNum: ERROR - UPDATE failed for '$firstName $surname': " . $updateStmt->error . "\n");
                $errorRows++;
            }
            $updateStmt->close();
        }
    } else {
        // INSERT new record (writes student_id so future uploads match reliably)
        $columns = array('student_id', 'Assesment', 'firstName', 'lastName', 'grade', 'term', 'year', 'exam_type');
        $values = array(
            $studentId,
            $assesment,
            $student['firstName'],
            $student['surname'],
            $grade,
            $termLabel,
            $year,
            $examType
        );
        $types = 'isssisis';
        $placeholders = array('?', '?', '?', '?', '?', '?', '?', '?');

        foreach ($subjectData as $code => $score) {
            $columns[] = "`$code`";
            $values[] = $score;
            $types .= 'd';
            $placeholders[] = '?';
        }

        $insertSQL = "INSERT INTO exam2 (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $insertStmt = $conn->prepare($insertSQL);

        if ($insertStmt === false) {
            fwrite($debugFile, "Row $lineNum: ERROR - prepare() failed for INSERT ('$firstName $surname'): " . $conn->error . " | SQL: $insertSQL\n");
            $errorRows++;
        } else {
            $insertStmt->bind_param($types, ...$values);

            if ($insertStmt->execute()) {
                fwrite($debugFile, "Row $lineNum: INSERTED - '$firstName $surname' (student_id $studentId, Assesment '$assesment')\n");
                $insertedCount++;
                // Store the new ID for future updates in this session
                $existingRecords[$studentId] = $conn->insert_id;
            } else {
                fwrite($debugFile, "Row $lineNum: ERROR - INSERT failed for '$firstName $surname': " . $insertStmt->error . "\n");
                $errorRows++;
            }
            $insertStmt->close();
        }
    }
}

mysqli_close($conn);

// ── Build message ──
$parts = array();
$parts[] = "$insertedCount new record(s) created";
$parts[] = "$updatedCount mark(s) updated";
if ($assessFilled > 0)    $parts[] = "$assessFilled Assessment No(s) saved from the file";
if ($assessConflicts > 0) $parts[] = "$assessConflicts Assessment No(s) NOT saved because another learner in the grade already has that number";
if ($blankAssess > 0)     $parts[] = "$blankAssess learner(s) still have NO Assessment No (add an 'Assessment No' column to the file, or edit them on the Students page)";
if ($skippedRows > 0)     $parts[] = "$skippedRows row(s) skipped (student not found or empty name)";
if ($errorRows > 0)       $parts[] = "$errorRows row(s) had errors";

$message = 'Upload complete — ' . implode(', ', $parts) . '.';

// Log the results
fwrite($debugFile, "RESULT: $message\n");
fwrite($debugFile, "Total students processed: $totalStudents\n");
fwrite($debugFile, "Inserted: $insertedCount\n");
fwrite($debugFile, "Updated: $updatedCount\n");
fwrite($debugFile, "Assessment No saved from file: $assessFilled\n");
fwrite($debugFile, "Assessment No conflicts: $assessConflicts\n");
fwrite($debugFile, "Still blank: $blankAssess\n");
fwrite($debugFile, "Skipped: $skippedRows\n");
fwrite($debugFile, "Errors: $errorRows\n");
fclose($debugFile);

redirect_with_message($message, ($errorRows > 0 || $blankAssess > 0 || $assessConflicts > 0) ? 'error' : 'success');