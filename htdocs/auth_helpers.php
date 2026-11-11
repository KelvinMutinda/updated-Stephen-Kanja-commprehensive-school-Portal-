<?php
// Shared role + assignment helpers. Include AFTER conn.php.
// Save on the server as: auth_helpers.php
if (session_status() === PHP_SESSION_NONE) session_start();

const ALL_GRADES = [1,2,3,4,5,6,7,8,9];
const SUBJECT_COLS = [
    'math'   => 'Mathematics',
    'eng'    => 'English',
    'kisw'   => 'Kiswahili',
    'scie'   => 'Science',
    'sst'    => 'Social Studies',
    'ca'     => 'C/A',
    'agri'   => 'Agriculture',
    're'     => 'RE',
    'pretec' => 'Pre-Technical',
];

/* ── Canonical roles used everywhere ──
   learner  → Student dashboard only
   teacher  → teacherpanel, upload only their assignments
   admin/hoi→ full access (HOI is stored as 'admin' role, account_type 'hoi') */

function currentRole(): string {
    return strtolower(trim((string)($_SESSION['role'] ?? '')));
}

function isAdmin(): bool {
    return in_array(currentRole(), ['admin', 'hoi'], true);
}

function isTeacher(): bool {
    return currentRole() === 'teacher';
}

function isLearner(): bool {
    return currentRole() === 'learner';
}

function currentTeacherId(): int {
    return (int)($_SESSION['teacher_id'] ?? $_SESSION['user_id'] ?? 0);
}

function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function requireLogin(): void {
    if (empty($_SESSION['role']) && currentUserId() === 0) {
        header('Location: index.php');
        exit;
    }
}

function requireAdmin(): void {
    if (!isAdmin()) {
        http_response_code(403);
        exit('Access denied: administrators only.');
    }
}

function requireTeacher(): void {
    if (!isTeacher() && !isAdmin()) {
        http_response_code(403);
        exit('Access denied: staff only.');
    }
}

function requireLearner(): void {
    if (!isLearner()) {
        http_response_code(403);
        exit('Access denied: learners only.');
    }
}

/** [grade(int) => [subjectCol, ...]] for one teacher. */
function getTeacherAssignments(mysqli $conn, int $teacherId): array {
    $map = [];
    $stmt = $conn->prepare("SELECT grade, subject FROM teacher_subject_assignments WHERE teacher_id = ?");
    if (!$stmt) return $map;
    $stmt->bind_param('i', $teacherId);
    $stmt->execute();
    $stmt->bind_result($g, $s);
    while ($stmt->fetch()) {
        $map[(int)$g][] = $s;
    }
    $stmt->close();
    ksort($map);
    return $map;
}

/** What the current user may upload: admins/HOI everything, teachers only their assignments. */
function getAllowedMap(mysqli $conn): array {
    if (isAdmin()) {
        $all = [];
        foreach (ALL_GRADES as $g) $all[$g] = array_keys(SUBJECT_COLS);
        return $all;
    }
    if (isTeacher()) {
        return getTeacherAssignments($conn, currentTeacherId());
    }
    return [];   // learners can't upload
}

/** Use inside /upload, upload_excel_marks and generate_template for every grade + subject being written. */
function canUpload(mysqli $conn, $grade, string $subjectCol): bool {
    if (!isAdmin() && !isTeacher()) return false;
    $map = getAllowedMap($conn);
    return isset($map[(int)$grade])
        && in_array(strtolower($subjectCol), $map[(int)$grade], true);
}

/** Replace a teacher's assignments. $posted = ['3' => ['math','eng'], '4' => ['sst']]. */
function saveTeacherAssignments(mysqli $conn, int $teacherId, array $posted): bool {
    $conn->begin_transaction();
    try {
        $del = $conn->prepare("DELETE FROM teacher_subject_assignments WHERE teacher_id = ?");
        $del->bind_param('i', $teacherId);
        $del->execute();
        $del->close();

        $ins = $conn->prepare("INSERT IGNORE INTO teacher_subject_assignments (teacher_id, grade, subject) VALUES (?, ?, ?)");
        foreach ($posted as $grade => $subjects) {
            $g = (int)$grade;
            if (!in_array($g, ALL_GRADES, true) || !is_array($subjects)) continue;
            foreach ($subjects as $subj) {
                $subj = strtolower((string)$subj);
                if (!isset(SUBJECT_COLS[$subj])) continue;
                $ins->bind_param('iis', $teacherId, $g, $subj);
                $ins->execute();
            }
        }
        $ins->close();
        $conn->commit();
        return true;
    } catch (\Throwable $e) {
        $conn->rollback();
        error_log('saveTeacherAssignments failed: ' . $e->getMessage());
        return false;
    }
}