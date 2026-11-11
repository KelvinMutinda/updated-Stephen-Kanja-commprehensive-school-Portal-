<?php
/*
 * fetch_students.php
 * ==================
 * Called via XHR from Fee.php when grade + term + year are all selected.
 * Returns HTML <tr> rows for each student in that grade, now enriched
 * with the term's Expected fees (from FeeStructure), what they've
 * already Paid this term (from Fees), and any outstanding balance
 * carried over from other terms.
 *
 * CHANGE (fee balances): rows now require term + year in addition to
 * grade, since the balance figures are meaningless without knowing
 * which term/year they're being measured against.
 *
 * GET params: grade (1–9), term (1–3), year (e.g. 2026)
 *
 * Place this file in the SAME folder as Fee.php and conn.php.
 */

error_reporting(0);
ini_set('display_errors', '0');
include 'conn.php';

/* ── Validate input ── */
if (!isset($_GET['grade'], $_GET['term'], $_GET['year'])
    || !is_numeric($_GET['grade']) || !is_numeric($_GET['term']) || !is_numeric($_GET['year'])) {
    http_response_code(400);
    echo '<tr><td colspan="11" style="text-align:center;padding:2rem;color:#888;">
            Select grade, term and year to load students and balances.
          </td></tr>';
    exit;
}
$grade = intval($_GET['grade']);
$term  = intval($_GET['term']);
$year  = intval($_GET['year']);

if ($grade < 1 || $grade > 9) {
    http_response_code(400);
    echo '<tr><td colspan="11" style="text-align:center;padding:2rem;color:#888;">
            Grade must be between 1 and 9.
          </td></tr>';
    exit;
}

/* ══════════════════════════════════════════════════════════
   Balance helpers
   ══════════════════════════════════════════════════════════ */

/** Expected fee totals for a grade/term/year, or null if no FeeStructure row covers it. */
function getExpectedFees($conn, $grade, $term, $year) {
    $stmt = $conn->prepare(
        "SELECT ExpectedFee, ExpectedAssesmentFee, ExpectedActivity, ExpectedOther
         FROM FeeStructure
         WHERE FIND_IN_SET(?, Grade) AND Term = ? AND Year = ?
         LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param('iii', $grade, $term, $year);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $fee   = (float)$row['ExpectedFee'];
    $ass   = (float)$row['ExpectedAssesmentFee'];
    $act   = (float)$row['ExpectedActivity'];
    $other = (float)$row['ExpectedOther'];
    return [
        'fee' => $fee, 'assessment' => $ass, 'activity' => $act, 'other' => $other,
        'total' => $fee + $ass + $act + $other,
    ];
}

/** What a student has already paid for one exact grade/term/year. */
function getPaidTotal($conn, $studentId, $grade, $term, $year) {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(Fee),0) AS fee, COALESCE(SUM(AssesmentFee),0) AS assessment,
                COALESCE(SUM(Activity),0) AS activity, COALESCE(SUM(Other),0) AS other
         FROM Fees
         WHERE StudentID = ? AND Grade = ? AND Term = ? AND Year = ?"
    );
    $stmt->bind_param('iiii', $studentId, $grade, $term, $year);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $fee = (float)$row['fee']; $ass = (float)$row['assessment'];
    $act = (float)$row['activity']; $other = (float)$row['other'];
    return ['fee' => $fee, 'assessment' => $ass, 'activity' => $act, 'other' => $other,
            'total' => $fee + $ass + $act + $other];
}

/**
 * Sum of unpaid balances from every OTHER grade/term/year the student
 * has a Fees record for. Only counts combos where a FeeStructure row
 * exists (so we're never guessing at an unknown expected amount) and
 * where Paid < Expected.
 */
function getPriorOutstanding($conn, $studentId, $excludeGrade, $excludeTerm, $excludeYear) {
    $stmt = $conn->prepare(
        "SELECT Grade, Term, Year,
                SUM(Fee) AS paidFee, SUM(AssesmentFee) AS paidAssess,
                SUM(Activity) AS paidActivity, SUM(Other) AS paidOther
         FROM Fees
         WHERE StudentID = ? AND NOT (Grade = ? AND Term = ? AND Year = ?)
         GROUP BY Grade, Term, Year"
    );
    $stmt->bind_param('iiii', $studentId, $excludeGrade, $excludeTerm, $excludeYear);
    $stmt->execute();
    $res = $stmt->get_result();

    $outstanding = 0.0;
    while ($row = $res->fetch_assoc()) {
        $paidTotal = (float)$row['paidFee'] + (float)$row['paidAssess']
                   + (float)$row['paidActivity'] + (float)$row['paidOther'];
        $expected = getExpectedFees($conn, (int)$row['Grade'], (int)$row['Term'], (int)$row['Year']);
        if ($expected === null) continue; // no fee structure for that term — can't price it, so skip
        $diff = $expected['total'] - $paidTotal;
        if ($diff > 0) $outstanding += $diff;
    }
    $stmt->close();
    return $outstanding;
}

/* ── Fee structure for the selected grade/term/year (same for every row below) ── */
$expected = getExpectedFees($conn, $grade, $term, $year);

/* ── Query students ── */
$stmt = $conn->prepare(
    "SELECT id, Assesment, firstName, surname
     FROM Student
     WHERE Grade = ? AND role = 'user'
     ORDER BY surname, firstName"
);
if (!$stmt) {
    http_response_code(500);
    echo '<tr><td colspan="11" style="text-align:center;padding:2rem;color:#c00;">
            Database error: ' . htmlspecialchars($conn->error) . '
          </td></tr>';
    exit;
}
$stmt->bind_param('i', $grade);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo '<tr><td colspan="11">
            <div style="text-align:center;padding:3rem 2rem;">
              <div style="width:56px;height:56px;background:#111;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <i class="fa-solid fa-user-slash" style="color:#f0c040;font-size:20px;"></i>
              </div>
              <p style="font-size:14px;color:#888;">No students found in Grade ' . $grade . '.</p>
            </div>
          </td></tr>';
    $stmt->close();
    $conn->close();
    exit;
}

/* ── Output rows ── */
while ($row = $result->fetch_assoc()) {
    $studentId = (int)$row['id'];
    $assess    = htmlspecialchars($row['Assesment'] ?? '');
    $firstName = htmlspecialchars($row['firstName'] ?? '');
    $surname   = htmlspecialchars($row['surname']   ?? '');

    $paid = getPaidTotal($conn, $studentId, $grade, $term, $year);
    $priorOutstanding = getPriorOutstanding($conn, $studentId, $grade, $term, $year);

    if ($expected === null) {
        $expectedCell  = "<span style='color:#888780;'>Not set</span>";
        $dataExpected  = '';
    } else {
        $expectedCell  = 'KES ' . number_format($expected['total'], 2);
        $dataExpected  = $expected['total'];
    }
    $paidCell = 'KES ' . number_format($paid['total'], 2);

    $priorBadge = $priorOutstanding > 0
        ? "<span class='prior-balance-badge'>Prev bal: KES " . number_format($priorOutstanding, 2) . "</span>"
        : '';

    echo "<tr data-expected='{$dataExpected}' data-paid='{$paid['total']}'>
  <td><span class='cell-assess'>{$studentId}</span>{$priorBadge}</td>
  <td><span class='cell-name'>{$firstName}</span></td>
  <td><span class='cell-name'>{$surname}</span></td>
  <td class='cell-expected'>{$expectedCell}</td>
  <td class='cell-paid'>{$paidCell}</td>
  <td><input type='number' name='Fee[]'           placeholder='0' min='0' step='any'></td>
  <td><input type='number' name='AssessmentFee[]' placeholder='0' min='0' step='any'></td>
  <td><input type='number' name='Activity[]'      placeholder='0' min='0' step='any'></td>
  <td><input type='number' name='Other[]'         placeholder='0' min='0' step='any'></td>
  <input type='hidden' name='firstName[]'  value='{$firstName}'>
  <input type='hidden' name='surname[]'    value='{$surname}'>
  <input type='hidden' name='studentId[]'  value='{$studentId}'>
  <input type='hidden' name='assessment[]' value='{$assess}'>
</tr>";
}
$stmt->close();
$conn->close();
exit;