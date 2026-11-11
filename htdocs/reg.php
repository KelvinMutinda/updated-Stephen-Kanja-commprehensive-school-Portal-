<?php
session_start();
include 'conn.php';
require_once __DIR__ . '/student_upsert.php';

mysqli_report(MYSQLI_REPORT_OFF); // don't let a failed query throw and skip our error handling

$row = [
    'upi'        => trim($_POST['UPI'] ?? ''),
    'assessment' => trim($_POST['Assesment'] ?? ''),
    'firstName'  => trim($_POST['firstName'] ?? ''),
    'middleName' => trim($_POST['middleName'] ?? ''),
    'surname'    => trim($_POST['surname'] ?? ''),
    'birthCert'  => trim($_POST['birthNo'] ?? ''),
    'dob'        => trim($_POST['DOB'] ?? ''),
    'grade'      => trim($_POST['Grade'] ?? ''),
];

$result = su_process($conn, $row, false, su_grade_allows_null($conn));
$conn->close();

if ($result['action'] === 'insert') {
    $_SESSION['firstName'] = $row['firstName'];
    header('Location: RegisterStudent.php?success=1');
} elseif ($result['action'] === 'update') {
    header('Location: RegisterStudent.php?learner_updated=1&name=' . urlencode($result['name']));
} else {
    header('Location: RegisterStudent.php?error=1&msg=' . urlencode($result['reason'] ?? ''));
}
exit;