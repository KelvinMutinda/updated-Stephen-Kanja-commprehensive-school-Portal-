<?php
// api/student/dashboard_stats.php
// GET, Authorization: Bearer <token>
// Optional query params: ?term=Term%201&year=2026 (default: Term 1, current year)
// Returns the StudentStats shape fetchStudentStats() expects.
//
// Note: the student is identified from the token, not from a query param —
// even though the RN stub calls this as `?id=${admissionId}`, that id is
// dropped here on purpose so one student can never read another's stats by
// changing the query string. The token IS the identity.

header('Content-Type: application/json');
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../auth_check.php';

$session = require_auth(); // ['role', 'user_id', 'identifier', ...]
if ($session['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Only students have dashboard stats']);
    exit;
}
$assess = mysqli_real_escape_string($conn, $session['identifier']);

$stu_res = mysqli_query($conn, "SELECT Grade FROM Student WHERE Assesment='$assess' LIMIT 1");
$student = $stu_res ? mysqli_fetch_assoc($stu_res) : null;
if (!$student) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Student not found']);
    exit;
}
$grade = $student['Grade'];

// Same wide-table subject map as student_dashboard.php
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
$default_max = 100;

$sel_term = isset($_GET['term']) ? $_GET['term'] : 'Term 1';
$sel_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Same term-spelling fix as student_dashboard.php (exam2.term is stored
// inconsistently as "Term 1" / "term 1" / "1").
$term_num = preg_replace('/[^0-9]/', '', $sel_term);
if ($term_num === '') { $term_num = '1'; }
$term_variants = array_unique(["Term $term_num", "term $term_num", $term_num]);
$term_variants_esc = array_map(function ($t) use ($conn) {
    return "'" . mysqli_real_escape_string($conn, $t) . "'";
}, $term_variants);
$term_in = implode(',', $term_variants_esc);

$subj_cols_max = implode(', ', array_map(function ($c) {
    return "MAX($c) AS $c";
}, array_keys($subject_map)));

// ── My average score for the selected term/year ────────────────────────
$my_exam_res = mysqli_query($conn,
    "SELECT $subj_cols_max FROM exam2
     WHERE Assesment='$assess' AND term IN ($term_in) AND year=$sel_year
     GROUP BY Assesment
     LIMIT 1"
);
$total_score = 0; $total_max = 0; $subjects_count = 0;
if ($my_exam_res && ($row = mysqli_fetch_assoc($my_exam_res))) {
    foreach ($subject_map as $col => $label) {
        $score = isset($row[$col]) ? (float)$row[$col] : 0;
        if ($score <= 0) continue; // 0 == not taken, same convention as student_dashboard.php
        $total_score += $score;
        $total_max   += $default_max;
        $subjects_count++;
    }
}
$averageScore = $total_max > 0 ? round(($total_score / $total_max) * 100, 1) : null;

// ── Attendance for the current calendar month ───────────────────────────
$att_month_start = date('Y-m-01');
$att_month_end   = date('Y-m-t');
$att = ['Present' => 0, 'Absent' => 0, 'Late' => 0];
$att_check = mysqli_query($conn, "SHOW TABLES LIKE 'attendance'");
if ($att_check && mysqli_num_rows($att_check) > 0) {
    $a_res = mysqli_query($conn,
        "SELECT status, COUNT(*) as cnt FROM attendance
         WHERE Assesment='$assess' AND attendance_date BETWEEN '$att_month_start' AND '$att_month_end'
         GROUP BY status"
    );
    if ($a_res) while ($a = mysqli_fetch_assoc($a_res)) $att[$a['status']] = (int)$a['cnt'];
}
$att_total = array_sum($att);
$attendanceRate = $att_total > 0 ? round(($att['Present'] / $att_total) * 100) : null;

// ── Fees: sum of Fee + AssesmentFee + Activity + other across all records ──
$fees_res = mysqli_query($conn, "SELECT Fee, AssesmentFee, Activity, other FROM Fees WHERE Assesment='$assess'");
$totalPaid = 0; $paymentRecords = 0;
if ($fees_res) {
    while ($f = mysqli_fetch_assoc($fees_res)) {
        $totalPaid += (float)$f['Fee'] + (float)$f['AssesmentFee'] + (float)$f['Activity'] + (float)$f['other'];
        $paymentRecords++;
    }
}

echo json_encode([
    'success' => true,
    'stats'   => [
        'averageScore'    => $averageScore,
        'subjectsCount'   => $subjects_count,
        'currentTerm'     => "$sel_term $sel_year",
        'attendanceRate'  => $attendanceRate,
        'totalPaid'       => $totalPaid,
        'paymentRecords'  => $paymentRecords,
    ],
]);

mysqli_close($conn);