<?php
// ============================================================
// upload.php — Stephen Kanja School Management System
//
// IMPORTANT: this script must only ever be reached via a POST to
// the extensionless path /upload. The site's .htaccess 301-redirects
// any request whose raw request line contains ".php" back to the
// extensionless URL — and a 301 downgrades a POST to a GET, wiping
// the body. So:
//   - The <form action="..."> that targets this script must be
//     "/upload", never "/upload.php" or a bare relative "upload".
//   - Every Location: header below is extensionless for the same
//     reason (redirecting to a .php URL would just bounce again).
// ============================================================
session_start();
include 'conn.php';

function redirect_with_message(string $path, string $msg, string $status): never {
    header("Location: /$path?upload_msg=" . urlencode($msg) . "&upload_status=$status");
    exit;
}

// ── Method guard ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Reaching here as GET almost always means the .htaccess 301
    // caught a ".php"-suffixed request and stripped the POST body.
    // Log it so it's easy to tell apart from a genuine stray visit.
    error_log('[upload.php] Rejected non-POST request from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    redirect_with_message('UploadResults', 'Your submission was not received (please try again).', 'error');
}

// ── DB connection guard ──
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_errno) {
    error_log('[upload.php] DB connection failed: ' . ($conn->connect_error ?? 'no $conn object'));
    redirect_with_message('UploadResults', 'Could not connect to the database. Please contact the administrator.', 'error');
}

// ── Shared context (one value for the whole table) ──
$grade    = isset($_POST['grade']) ? (string)(int)$_POST['grade'] : '';   // exam2.grade is varchar
$termNum  = isset($_POST['term']) ? preg_replace('/[^0-9]/', '', $_POST['term']) : '';
$examType = isset($_POST['examType']) ? trim($_POST['examType']) : '';
$year     = isset($_POST['year']) ? (string)(int)$_POST['year'] : '';     // exam2.year is varchar

$allowedExamTypes = array('opener', 'midterm', 'endterm');
$etQuery = @mysqli_query($conn, "SELECT code FROM exam_types");
if ($etQuery) {
    while ($etRow = mysqli_fetch_assoc($etQuery)) {
        $allowedExamTypes[] = $etRow['code'];
    }
}
if ($grade === '0' || $grade === '' || $termNum === '' || !in_array($examType, $allowedExamTypes, true) || $year === '0' || $year === '') {
    redirect_with_message('UploadResults', 'Grade, term, exam type, and year are all required.', 'error');
}
$termLabel = "Term $termNum"; // canonical format, matches exam2.term (varchar)

// ── Per-row arrays (as sent by the table) ──
$studentIds  = $_POST['studentId']  ?? [];
$assessments = $_POST['assesment']  ?? [];
$firstNames  = $_POST['firstName']  ?? [];
$lastNames   = $_POST['surname']    ?? [];
$maths       = $_POST['MATH']       ?? [];
$engs        = $_POST['ENG']        ?? [];
$kisws       = $_POST['KISW']       ?? [];
$scies       = $_POST['SCIE']       ?? [];
$ssts        = $_POST['sst']        ?? [];
$cas         = $_POST['ca']         ?? [];
$agris       = $_POST['AGRI']       ?? [];
$res         = $_POST['re']         ?? [];
$pretecs     = $_POST['pretec']     ?? [];

$rowCount = count($studentIds);

if ($rowCount === 0) {
    redirect_with_message('UploadResults', 'No students were loaded for this grade — nothing to submit.', 'error');
}

function markOrNull($raw) {
    $raw = trim((string)($raw ?? ''));
    if ($raw === '' || !is_numeric($raw)) return null;
    $v = (int)round((float)$raw);
    return max(0, min(100, $v)); // clamp to 0–100
}

$updatedCount  = 0;
$insertedCount = 0;
$skippedCount  = 0;
$errorCount    = 0;
$errors        = [];

for ($i = 0; $i < $rowCount; $i++) {
    $studentId = isset($studentIds[$i]) ? (int)$studentIds[$i] : 0;
    $assess    = trim((string)($assessments[$i] ?? ''));
    $fname     = trim((string)($firstNames[$i]  ?? ''));
    $lname     = trim((string)($lastNames[$i]   ?? ''));

    if (!$studentId) {
        $errorCount++;
        $errors[] = "Row " . ($i + 1) . " ($fname $lname): missing student id, skipped.";
        continue;
    }

    $math   = markOrNull($maths[$i]   ?? null);
    $eng    = markOrNull($engs[$i]    ?? null);
    $kisw   = markOrNull($kisws[$i]   ?? null);
    $scie   = markOrNull($scies[$i]   ?? null);
    $sst    = markOrNull($ssts[$i]    ?? null);
    $ca     = markOrNull($cas[$i]     ?? null);
    $agri   = markOrNull($agris[$i]   ?? null);
    $re     = markOrNull($res[$i]     ?? null);
    $pretec = markOrNull($pretecs[$i] ?? null);

    $allBlank = ($math === null && $eng === null && $kisw === null && $sst === null &&
                 $scie === null && $ca === null && $agri === null && $re === null && $pretec === null);

    try {
        $check = $conn->prepare(
            "SELECT id FROM exam2 WHERE student_id = ? AND grade = ? AND term = ? AND year = ? AND exam_type = ? LIMIT 1"
        );
        if ($check === false) {
            throw new mysqli_sql_exception('prepare(SELECT) failed: ' . $conn->error);
        }
        $check->bind_param('issss', $studentId, $grade, $termLabel, $year, $examType);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$existing && $allBlank) {
            $skippedCount++;
            continue;
        }

        if ($existing) {
            $update = $conn->prepare(
                "UPDATE exam2
                 SET math = COALESCE(?, math), eng = COALESCE(?, eng), kisw = COALESCE(?, kisw),
                     sst = COALESCE(?, sst), scie = COALESCE(?, scie), ca = COALESCE(?, ca),
                     agri = COALESCE(?, agri), re = COALESCE(?, re), pretec = COALESCE(?, pretec),
                     Assesment = COALESCE(NULLIF(?, ''), Assesment),
                     firstName = ?, lastName = ?
                 WHERE id = ?"
            );
            if ($update === false) {
                throw new mysqli_sql_exception('prepare(UPDATE) failed: ' . $conn->error);
            }
            $update->bind_param(
                'iiiiiiiiisssi',
                $math, $eng, $kisw, $sst, $scie, $ca, $agri, $re, $pretec,
                $assess, $fname, $lname, $existing['id']
            );
            if ($update->execute()) {
                $updatedCount++;
            } else {
                throw new mysqli_sql_exception('UPDATE execute failed: ' . $update->error);
            }
            $update->close();
        } else {
            $insert = $conn->prepare(
                "INSERT INTO exam2
                    (student_id, Assesment, firstName, lastName, math, eng, kisw, sst, scie, ca, agri, re, pretec, grade, term, exam_type, year)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            if ($insert === false) {
                throw new mysqli_sql_exception('prepare(INSERT) failed: ' . $conn->error);
            }
            $insert->bind_param(
                'isssiiiiiiiiissss',
                $studentId, $assess, $fname, $lname, $math, $eng, $kisw, $sst, $scie, $ca, $agri, $re, $pretec,
                $grade, $termLabel, $examType, $year
            );
            if ($insert->execute()) {
                $insertedCount++;
            } else {
                throw new mysqli_sql_exception('INSERT execute failed: ' . $insert->error);
            }
            $insert->close();
        }
    } catch (Throwable $e) {
        // Log the real error server-side for debugging; keep the
        // user-facing message generic (no raw SQL/error text leaked).
        error_log('[upload.php] Row ' . ($i + 1) . " ($fname $lname, student_id=$studentId): " . $e->getMessage());
        $errorCount++;
        $errors[] = "Row " . ($i + 1) . " ($fname $lname): could not be saved.";
    }
}

$conn->close();

$parts = [];
if ($insertedCount > 0) $parts[] = "$insertedCount new record(s) created";
if ($updatedCount > 0)  $parts[] = "$updatedCount record(s) updated";
if ($skippedCount > 0)  $parts[] = "$skippedCount row(s) skipped (no marks entered)";
if ($errorCount > 0)    $parts[] = "$errorCount row(s) failed to save — please check with the administrator";

if (empty($parts)) {
    $parts[] = 'No changes were made.';
}

$status = $errorCount > 0 ? 'error' : 'success';
redirect_with_message('UploadResults', implode(', ', $parts), $status);