<?php
// exam_release.php — result release gate + marks-entry progress helpers.
// Include AFTER conn.php. The table is created automatically on first use.
//
//   examReleaseStatus()   is this grade/term/exam/year released?
//   isExamReleased()      true/false shortcut — use this in StudentDashboard,
//                         send_results_sms, Progress, etc. before showing results
//   setExamRelease()      release / withdraw (call only after an isAdmin() check)
//   examEntryProgress()   how many learners have marks entered, per subject
//   examSubjectTeachers() who is assigned to a grade/subject (for "who is late")

function releaseEnsureTable(mysqli $conn): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS exam_release (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            grade       TINYINT      NOT NULL,
            term        TINYINT      NOT NULL,
            exam_type   VARCHAR(60)  NOT NULL,
            year        SMALLINT     NOT NULL,
            released    TINYINT(1)   NOT NULL DEFAULT 0,
            released_by VARCHAR(150) NULL,
            released_at DATETIME     NULL,
            UNIQUE KEY uq_release (grade, term, exam_type, year)
        )");
    } catch (\Throwable $e) { error_log('[exam_release] create table failed: ' . $e->getMessage()); }
}

/** "Term 2", "2", "term_2" -> 2 */
function releaseTermNum($term): int {
    return (int)preg_replace('/\D/', '', (string)$term);
}

function examReleaseStatus(mysqli $conn, $grade, $term, string $exam, $year): array {
    releaseEnsureTable($conn);
    $out = ['released' => false, 'by' => '', 'at' => ''];
    $g = (int)$grade; $t = releaseTermNum($term); $y = (int)$year;
    try {
        $stmt = $conn->prepare("SELECT released, released_by, released_at FROM exam_release
                                WHERE grade = ? AND term = ? AND exam_type = ? AND year = ? LIMIT 1");
        if (!$stmt) return $out;
        $stmt->bind_param('iisi', $g, $t, $exam, $y);
        $stmt->execute();
        $stmt->bind_result($rel, $by, $at);
        if ($stmt->fetch()) {
            $out = ['released' => (bool)$rel, 'by' => (string)$by, 'at' => (string)$at];
        }
        $stmt->close();
    } catch (\Throwable $e) { error_log('[exam_release] status failed: ' . $e->getMessage()); }
    return $out;
}

function isExamReleased(mysqli $conn, $grade, $term, string $exam, $year): bool {
    return examReleaseStatus($conn, $grade, $term, $exam, $year)['released'];
}

function setExamRelease(mysqli $conn, $grade, $term, string $exam, $year, bool $released, string $by): bool {
    releaseEnsureTable($conn);
    $g = (int)$grade; $t = releaseTermNum($term); $y = (int)$year; $r = $released ? 1 : 0;
    try {
        $stmt = $conn->prepare("INSERT INTO exam_release (grade, term, exam_type, year, released, released_by, released_at)
                                VALUES (?, ?, ?, ?, ?, ?, NOW())
                                ON DUPLICATE KEY UPDATE released = VALUES(released),
                                                        released_by = VALUES(released_by),
                                                        released_at = NOW()");
        if (!$stmt) return false;
        $stmt->bind_param('iisiis', $g, $t, $exam, $y, $r, $by);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    } catch (\Throwable $e) {
        error_log('[exam_release] set failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Entry progress for one grade + term + exam + year.
 * $cols = exam2 subject columns taught in that grade (e.g. ['math','eng',...]).
 * A mark counts as "entered" when it is numeric and greater than 0
 * (blank and 0 are treated as not entered).
 * Returns: students, by_subject[col] = learners with a mark, filled, expected, pct, complete.
 */
function examEntryProgress(mysqli $conn, $grade, $term, string $exam, $year, array $cols): array {
    $res = ['students' => 0, 'by_subject' => [], 'filled' => 0, 'expected' => 0, 'pct' => 0, 'complete' => false];
    foreach ($cols as $c) $res['by_subject'][$c] = 0;

    $g = (string)(int)$grade;
    $y = (string)(int)$year;
    $targetTerm = releaseTermNum($term);

    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM Student WHERE Grade = ?");
        if ($stmt) {
            $stmt->bind_param('s', $g);
            $stmt->execute();
            $stmt->bind_result($n);
            $stmt->fetch();
            $res['students'] = (int)$n;
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT * FROM exam2 WHERE grade = ? AND exam_type = ? AND year = ?");
        if ($stmt) {
            $stmt->bind_param('sss', $g, $exam, $y);
            $stmt->execute();
            $rs = $stmt->get_result();
            $seen = [];   // learner key => [col => true]
            while ($rs && ($row = $rs->fetch_assoc())) {
                if (releaseTermNum($row['term'] ?? '') !== $targetTerm) continue;
                $sid = trim((string)($row['student_id'] ?? ''));
                $key = $sid !== '' ? 'sid:' . $sid : 'ass:' . trim((string)($row['Assesment'] ?? ''));
                if ($key === 'ass:') continue;
                foreach ($cols as $c) {
                    $v = $row[$c] ?? null;
                    if ($v !== null && $v !== '' && is_numeric($v) && (float)$v > 0) {
                        $seen[$key][$c] = true;
                    }
                }
            }
            $stmt->close();
            foreach ($seen as $perLearner) {
                foreach ($perLearner as $c => $_) {
                    if (isset($res['by_subject'][$c])) $res['by_subject'][$c]++;
                }
            }
        }
    } catch (\Throwable $e) { error_log('[exam_release] progress failed: ' . $e->getMessage()); }

    $n = $res['students'];
    $allDone = $n > 0 && count($cols) > 0;
    foreach ($res['by_subject'] as $c => $cnt) {
        if ($cnt > $n) { $res['by_subject'][$c] = $cnt = $n; }   // ignore rows for learners no longer in the grade
        $res['filled'] += $cnt;
        if ($cnt < $n) $allDone = false;
    }
    $res['expected'] = $n * count($cols);
    $res['pct']      = $res['expected'] > 0 ? (int)floor($res['filled'] / $res['expected'] * 100) : 0;
    $res['complete'] = $allDone;
    return $res;
}

/** Names of teachers assigned to this grade + subject column. Returns [] if unknown. */
function examSubjectTeachers(mysqli $conn, $grade, string $col): array {
    $names = [];
    $g = (int)$grade;
    try {
        $stmt = $conn->prepare("SELECT t.name FROM teacher_subject_assignments a
                                JOIN Teachers t ON t.id = a.teacher_id
                                WHERE a.grade = ? AND a.subject = ?");
        if (!$stmt) return $names;
        $stmt->bind_param('is', $g, $col);
        $stmt->execute();
        $stmt->bind_result($nm);
        while ($stmt->fetch()) { if ($nm !== null && $nm !== '') $names[] = (string)$nm; }
        $stmt->close();
    } catch (\Throwable $e) { /* Teachers.name column may differ — ignore */ }
    return $names;
}