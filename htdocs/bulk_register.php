<?php
/*
 * bulk_register.php
 * =================
 * POST JSON: { "students": [ {rowNum, upi, assessment, firstName, middleName,
 *                             surname, dob, grade, birthCert}, ... ],
 *              "dry_run": true|false }
 *
 * Each row is inserted (new learner) or updated (learner already exists).
 * dry_run = true saves nothing and only reports what WOULD happen per row;
 * the page uses it to label rows New / Update in the preview.
 *
 * Returns: { success, imported (new), updated, skipped, errors[], results[] }
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { ob_end_clean(); http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body || !isset($body['students']) || !is_array($body['students'])) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request body. Expected JSON with "students" array.']);
    exit;
}

$students = $body['students'];
$dryRun   = !empty($body['dry_run']);

if (count($students) === 0) {
    ob_end_clean();
    echo json_encode(['success' => true, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'results' => []]);
    exit;
}

include 'conn.php'; // must define $conn as a mysqli object
require_once __DIR__ . '/student_upsert.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!isset($conn) || $conn->connect_error) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . ($conn->connect_error ?? 'conn.php did not set $conn')
    ]);
    exit;
}

$gradeAllowsNull = su_grade_allows_null($conn);

$imported = 0; $updated = 0; $skipped = 0;
$errors = []; $results = [];

foreach ($students as $idx => $s) {
    $rowNum = isset($s['rowNum']) ? (int)$s['rowNum'] : $idx + 2;
    $res = su_process($conn, (array)$s, $dryRun, $gradeAllowsNull);
    $res['rowNum'] = $rowNum;

    if ($res['action'] === 'insert')      $imported++;
    elseif ($res['action'] === 'update')  $updated++;
    else { $skipped++; $errors[] = "Row $rowNum: " . ($res['reason'] ?? 'Skipped'); }

    $results[] = $res;
}

$conn->close();
ob_end_clean();

echo json_encode([
    'success'  => true,
    'dry_run'  => $dryRun,
    'imported' => $imported,
    'updated'  => $updated,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'results'  => $results
]);
exit;