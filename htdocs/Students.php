<?php
/* ════════════════════════════════════════════════════════════════════
   Students.php — list, search, EDIT, REMOVE, GRADUATE and DOWNLOAD learners.
   The connection is loaded first so the AJAX handlers below can answer
   with clean JSON before any HTML is sent.
   ════════════════════════════════════════════════════════════════════ */
mysqli_report(MYSQLI_REPORT_OFF);
include 'page_guard.php';
 require_once __DIR__ . '/page_guard.php';
include 'conn.php';

/* Only signed-in users may use this page. Removing, graduating and reinstating
   learners is limited to administrators; editing a learner is open to any signed-in staff. */
require_once 'auth_helpers.php';
requireLogin();
$isAdminUser = function_exists('isAdmin') ? (bool)isAdmin() : false;
$canManage   = $isAdminUser;   // ← change this one line if other roles should be able to remove / graduate learners

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
$isHoi     = (($_SESSION['account_type'] ?? '') === 'hoi');
$homeLink  = $isHoi ? 'Hoi.php' : 'teacherpanel.php';
$roleLabel = $isHoi ? 'Head of Institution' : ($isAdminUser ? 'Administrator' : 'Teacher Panel');

/* Most learners one bulk action may touch */
const MAX_BULK = 2000;

/* Tables that hold a learner's own records, as [table, column that stores the learner id].
   When a learner is permanently removed, the rows in these tables go with them.
   Add your fee / progress tables here if they store the learner id, for example:
       ['fees', 'student_id'],
   Tables that do not exist are skipped. */
const LEARNER_RELATED_TABLES = [
    ['exam2', 'student_id'],
];

function json_out($code, array $payload) {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}

/* Adds the "alumni" columns to Student the first time the page runs.
   enrolStatus: 'active' (in school) or 'alumni' (finished Grade 9, records kept).
   Returns false if the columns could not be created (the page then shows the SQL to run by hand). */
function ensure_alumni_columns(mysqli $conn) {
    $r = $conn->query("SHOW COLUMNS FROM Student");
    if (!$r) return false;
    $have = [];
    while ($c = $r->fetch_assoc()) $have[strtolower($c['Field'])] = true;
    $r->free();

    $alter = [];
    if (empty($have['enrolstatus'])) $alter[] = "ADD COLUMN enrolStatus ENUM('active','alumni') NOT NULL DEFAULT 'active'";
    if (empty($have['gradyear']))    $alter[] = "ADD COLUMN gradYear SMALLINT NULL DEFAULT NULL";
    if (empty($have['gradat']))      $alter[] = "ADD COLUMN gradAt DATETIME NULL DEFAULT NULL";
    if ($alter && !$conn->query("ALTER TABLE Student " . implode(', ', $alter))) return false;
    return true;
}

function table_exists(mysqli $conn, $table) {
    $q = $conn->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1");
    if (!$q) return false;
    $q->bind_param('s', $table);
    $q->execute();
    $res = $q->get_result();
    $ok = $res && $res->num_rows > 0;
    if ($res) $res->free();
    $q->close();
    return $ok;
}

/* Validates the list of learner ids sent by the browser */
function clean_ids($raw) {
    if (!is_array($raw) || !$raw)
        json_out(400, ['success' => false, 'error' => 'No learners were selected.']);
    $ids = [];
    foreach ($raw as $v) {
        $v = trim((string)$v);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v))
            json_out(400, ['success' => false, 'error' => 'Invalid learner id.']);
        $ids[] = $v;
    }
    $ids = array_values(array_unique($ids));
    if (count($ids) > MAX_BULK)
        json_out(400, ['success' => false, 'error' => 'Too many learners at once (max ' . MAX_BULK . ').']);
    return $ids;
}

/* ════════════════════════════════════════════
   ACTION: update_student  (POST, JSON body)
   ════════════════════════════════════════════ */
function handle_update_student(mysqli $conn, array $in, $hasAlumni) {
    $id         = trim((string)($in['id'] ?? ''));
    $assesment  = trim((string)($in['assesment'] ?? ''));
    $upi        = trim((string)($in['upi'] ?? ''));
    $firstName  = trim((string)($in['firstName'] ?? ''));
    $middleName = trim((string)($in['middleName'] ?? ''));
    $surname    = trim((string)($in['surname'] ?? ''));
    $dob        = trim((string)($in['dob'] ?? ''));
    $birthNo    = trim((string)($in['birthNo'] ?? ''));
    $gradeRaw   = trim((string)($in['grade'] ?? ''));

    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id))
        json_out(400, ['success' => false, 'error' => 'Missing learner id.']);
    if ($firstName === '')  json_out(400, ['success' => false, 'error' => 'First name is required.']);
    if ($surname === '')    json_out(400, ['success' => false, 'error' => 'Surname is required.']);
    if ($assesment === '')  json_out(400, ['success' => false, 'error' => 'Assessment number is required.']);
    if (mb_strlen($firstName) > 60 || mb_strlen($middleName) > 60 || mb_strlen($surname) > 60)
        json_out(400, ['success' => false, 'error' => 'Names can be at most 60 characters.']);
    if (mb_strlen($assesment) > 30 || mb_strlen($upi) > 30)
        json_out(400, ['success' => false, 'error' => 'Assessment No and UPI can be at most 30 characters.']);
    if (mb_strlen($birthNo) > 40)
        json_out(400, ['success' => false, 'error' => 'Birth certificate number is too long (max 40).']);
    if (!ctype_digit($gradeRaw) || (int)$gradeRaw < 1 || (int)$gradeRaw > 9)
        json_out(400, ['success' => false, 'error' => 'Grade must be between 1 and 9.']);
    $grade = (int)$gradeRaw;

    $d = DateTime::createFromFormat('Y-m-d', $dob);
    if (!$d || $d->format('Y-m-d') !== $dob)
        json_out(400, ['success' => false, 'error' => 'Please enter a valid date of birth.']);

    /* Learner must exist (and we need to know if they are alumni) */
    $cols = $hasAlumni ? 'id, enrolStatus' : 'id';
    $chk = $conn->prepare("SELECT $cols FROM Student WHERE id = ? AND role = 'user' LIMIT 1");
    if (!$chk) json_out(500, ['success' => false, 'error' => 'Database error: ' . $conn->error]);
    $chk->bind_param('s', $id);
    $chk->execute();
    $found    = $chk->get_result();
    $existing = $found ? $found->fetch_assoc() : null;
    $chk->close();
    if (!$existing)
        json_out(404, ['success' => false, 'error' => 'That learner no longer exists.']);

    /* Alumni finished Grade 9, so their grade cannot be changed here */
    $status = ($hasAlumni && ($existing['enrolStatus'] ?? '') === 'alumni') ? 'alumni' : 'active';
    if ($status === 'alumni') $grade = 9;

    /* Assessment No must be unique within the grade */
    $dup = $conn->prepare("SELECT id FROM Student WHERE Assesment = ? AND Grade = ? AND id <> ? LIMIT 1");
    if ($dup) {
        $dup->bind_param('sis', $assesment, $grade, $id);
        $dup->execute();
        $r = $dup->get_result();
        $dup->close();
        if ($r && $r->num_rows > 0)
            json_out(409, ['success' => false, 'error' => "Another learner in Grade $grade already has Assessment No \"$assesment\"."]);
    }

    /* UPI must be unique (when given) */
    if ($upi !== '') {
        $dupU = $conn->prepare("SELECT id FROM Student WHERE UPI = ? AND id <> ? LIMIT 1");
        if ($dupU) {
            $dupU->bind_param('ss', $upi, $id);
            $dupU->execute();
            $r = $dupU->get_result();
            $dupU->close();
            if ($r && $r->num_rows > 0)
                json_out(409, ['success' => false, 'error' => "Another learner already has UPI \"$upi\"."]);
        }
    }

    /* Save */
    $upd = $conn->prepare(
        "UPDATE Student
            SET Assesment = ?, UPI = ?, firstName = ?, middleName = ?, surname = ?,
                DOB = ?, birthNo = ?, Grade = ?
          WHERE id = ? AND role = 'user' LIMIT 1"
    );
    if (!$upd) json_out(500, ['success' => false, 'error' => 'Database error: ' . $conn->error]);
    $upd->bind_param('sssssssis', $assesment, $upi, $firstName, $middleName, $surname, $dob, $birthNo, $grade, $id);
    if (!$upd->execute()) {
        $msg = $upd->error;
        $upd->close();
        json_out(500, ['success' => false, 'error' => 'Could not save the learner: ' . $msg]);
    }
    $upd->close();

    /* Keep already-saved exam rows in step: the results pages read the
       learner's name and assessment number from exam2 as well. */
    $warning = '';
    $sync = $conn->prepare("UPDATE exam2 SET Assesment = ?, firstName = ?, lastName = ? WHERE student_id = ?");
    if ($sync) {
        $sync->bind_param('ssss', $assesment, $firstName, $surname, $id);
        if (!$sync->execute()) $warning = 'Saved, but the name could not be refreshed on existing exam records.';
        $sync->close();
    } else {
        $warning = 'Saved, but the name could not be refreshed on existing exam records.';
    }

    json_out(200, [
        'success' => true,
        'warning' => $warning,
        'student' => [
            'id' => $id, 'assesment' => $assesment, 'upi' => $upi,
            'firstName' => $firstName, 'middleName' => $middleName, 'surname' => $surname,
            'dob' => $dob, 'birthNo' => $birthNo, 'grade' => $grade, 'status' => $status,
        ],
    ]);
}

/* ════════════════════════════════════════════
   ACTION: delete_students  (POST, JSON body: { ids: [...] })
   Permanently removes learners AND their rows in LEARNER_RELATED_TABLES.
   Only rows with role = 'user' can be removed, so teachers/admins in the
   same table are never touched.
   ════════════════════════════════════════════ */
function handle_delete_students(mysqli $conn, array $in) {
    $ids = clean_ids($in['ids'] ?? null);

    /* One prepared DELETE per related table that really exists */
    $related = [];
    foreach (LEARNER_RELATED_TABLES as $pair) {
        $table = $pair[0];
        $col   = $pair[1];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $col)) continue;
        if (!table_exists($conn, $table)) continue;
        $st = $conn->prepare("DELETE FROM `$table` WHERE `$col` = ?");
        if (!$st) json_out(500, ['success' => false, 'error' => "Database error on table $table: " . $conn->error]);
        $related[] = $st;
    }

    $find = $conn->prepare("SELECT id FROM Student WHERE id = ? AND role = 'user' LIMIT 1");
    $del  = $conn->prepare("DELETE FROM Student WHERE id = ? AND role = 'user' LIMIT 1");
    if (!$find || !$del) json_out(500, ['success' => false, 'error' => 'Database error: ' . $conn->error]);

    $conn->begin_transaction();      /* all-or-nothing (needs InnoDB tables) */
    $done = [];
    foreach ($ids as $id) {
        $find->bind_param('s', $id);
        $find->execute();
        $res = $find->get_result();
        $exists = $res && $res->num_rows > 0;
        if ($res) $res->free();
        if (!$exists) continue;      /* already gone, or not a learner */

        foreach ($related as $st) {
            $st->bind_param('s', $id);
            if (!$st->execute()) {
                $msg = $st->error;
                $conn->rollback();
                json_out(500, ['success' => false, 'error' => 'Could not remove the learner\'s records: ' . $msg]);
            }
        }
        $del->bind_param('s', $id);
        if (!$del->execute()) {
            $msg = $del->error;
            $conn->rollback();
            json_out(500, ['success' => false, 'error' => 'Could not remove the learner: ' . $msg]);
        }
        if ($del->affected_rows > 0) $done[] = (string)$id;
    }
    $conn->commit();

    json_out(200, [
        'success' => true,
        'count'   => count($done),
        'skipped' => count($ids) - count($done),
        'doneIds' => $done,
    ]);
}

/* ════════════════════════════════════════════
   ACTIONS: graduate_students / reinstate_students
   graduate:  Grade 9 learners become ALUMNI (all records are kept)
   reinstate: alumni go back to being active Grade 9 learners
   ════════════════════════════════════════════ */
function handle_change_status(mysqli $conn, array $in, $hasAlumni, $toAlumni) {
    if (!$hasAlumni)
        json_out(500, ['success' => false, 'error' => 'The alumni feature needs a database update first (see the notice on the page).']);
    $ids = clean_ids($in['ids'] ?? null);

    $year = 0;
    if ($toAlumni) {
        $year = (int)($in['year'] ?? 0);
        if ($year < 2000 || $year > 2100)
            json_out(400, ['success' => false, 'error' => 'Please enter a valid graduation year.']);
        $st = $conn->prepare(
            "UPDATE Student SET enrolStatus = 'alumni', gradYear = ?, gradAt = NOW()
              WHERE id = ? AND role = 'user' AND Grade = 9 AND enrolStatus = 'active' LIMIT 1"
        );
    } else {
        $st = $conn->prepare(
            "UPDATE Student SET enrolStatus = 'active', gradYear = NULL, gradAt = NULL
              WHERE id = ? AND role = 'user' AND enrolStatus = 'alumni' LIMIT 1"
        );
    }
    if (!$st) json_out(500, ['success' => false, 'error' => 'Database error: ' . $conn->error]);

    $conn->begin_transaction();
    $done = [];
    foreach ($ids as $id) {
        if ($toAlumni) $st->bind_param('is', $year, $id);
        else           $st->bind_param('s', $id);
        if (!$st->execute()) {
            $msg = $st->error;
            $conn->rollback();
            json_out(500, ['success' => false, 'error' => 'Could not update the learners: ' . $msg]);
        }
        if ($st->affected_rows > 0) $done[] = (string)$id;
    }
    $conn->commit();

    json_out(200, [
        'success' => true,
        'count'   => count($done),
        'skipped' => count($ids) - count($done),
        'doneIds' => $done,
    ]);
}

$hasAlumni = (isset($conn) && $conn instanceof mysqli) ? ensure_alumni_columns($conn) : false;

/* ════════════════════════════════════════════
   AJAX router
   ════════════════════════════════════════════ */
$ajaxAction = $_GET['ajax_action'] ?? '';
if ($ajaxAction !== '') {
    if (!in_array($ajaxAction, ['update_student', 'delete_students', 'graduate_students', 'reinstate_students'], true)) {
        json_out(404, ['success' => false, 'error' => 'Unknown action.']);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(405, ['success' => false, 'error' => 'Invalid request method.']);
    }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        json_out(500, ['success' => false, 'error' => 'Database connection is not available.']);
    }
    if ($ajaxAction !== 'update_student' && !$canManage) {
        json_out(403, ['success' => false, 'error' => 'Only an administrator can remove, graduate or reinstate learners.']);
    }
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) {
        json_out(400, ['success' => false, 'error' => 'Invalid data received.']);
    }

    switch ($ajaxAction) {
        case 'update_student':     handle_update_student($conn, $in, $hasAlumni);            break;
        case 'delete_students':    handle_delete_students($conn, $in);                       break;
        case 'graduate_students':  handle_change_status($conn, $in, $hasAlumni, true);       break;
        case 'reinstate_students': handle_change_status($conn, $in, $hasAlumni, false);      break;
    }
    exit;
}

/* ════════════════════════════════════════════
   Retrieve learners for the selected grade / alumni
   ════════════════════════════════════════════ */
$gradeParam    = $_GET['grade'] ?? '';
$isAll         = ($gradeParam === 'all');
$isAlumniView  = ($hasAlumni && $gradeParam === 'alumni');
$grade         = null;
if (!$isAll && !$isAlumniView && ctype_digit((string)$gradeParam) && (int)$gradeParam >= 1 && (int)$gradeParam <= 9) {
    $grade = (int)$gradeParam;
}
$hasFilter = $isAll || $isAlumniView || $grade !== null;

$rows = [];
$loadError = '';
if ($hasFilter && isset($conn) && $conn instanceof mysqli) {
    $cols = "id, Assesment, UPI, firstName, middleName, surname, DOB, birthNo, Grade"
          . ($hasAlumni ? ", enrolStatus, gradYear" : "");
    $activeOnly = $hasAlumni ? " AND enrolStatus = 'active'" : "";

    if ($isAlumniView) {
        $stmt = $conn->prepare(
            "SELECT $cols FROM Student WHERE role = 'user' AND enrolStatus = 'alumni'
              ORDER BY gradYear DESC, firstName, surname"
        );
    } elseif ($isAll) {
        $stmt = $conn->prepare(
            "SELECT $cols FROM Student WHERE role = 'user'$activeOnly ORDER BY Grade, firstName, surname"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT $cols FROM Student WHERE Grade = ? AND role = 'user'$activeOnly ORDER BY firstName, surname"
        );
        if ($stmt) $stmt->bind_param('i', $grade);
    }
    if ($stmt && $stmt->execute()) {
        $res  = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
    } else {
        $loadError = $conn->error ?: 'Could not load learners.';
    }
}
$total = count($rows);
$pageTitle = $isAlumniView ? 'Alumni' : ($isAll ? 'All Grades' : ($grade !== null ? "Grade $grade" : ''));
$bannerSuffix = $isAlumniView ? 'Learners Who Completed Grade 9' : 'Enrolled Learners';
$filterKey = $isAlumniView ? 'alumni' : ($isAll ? 'all' : ($grade === null ? '' : (string)$grade));

/* "Promote Learners" button: if a grade 1-8 is being viewed, open the
   promotion page already set to that grade. */
$promoteHref = 'PromoteLearners.php' . (($grade !== null && $grade < 9) ? '?grade=' . $grade : '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Students — Kanja School</title>
  <script>
    /* Apply saved theme before first paint (shared with the other dashboards) */
    try { var th = localStorage.getItem('hoi-theme'); if (th === 'dark' || th === 'light') document.documentElement.setAttribute('data-theme', th); } catch (e) {}
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:#f3f5fa; --surface:#ffffff; --surface-2:#f8f9fc; --surface-3:#eef1f7;
      --text:#0f172a; --text-2:#475569; --muted:#8392a5;
      --border:#e4e8f0; --border-2:#eef1f6;
      --navy:#0b1f3a; --gold:#f0c040; --gold-d:#c8992a; --gold-pale:#fdf3d4; --gold-ink:#7a5a10;
      --green:#16a34a; --green-bg:#dcfce7; --green-d:#15803d;
      --red:#dc2626; --red-bg:#fee2e2; --red-bd:#fca5a5; --red-ink:#991b1b;
      --amber-bg:#fef9c3; --amber-bd:#fde047; --amber-ink:#854d0e;
      --shadow-sm:0 1px 2px rgba(15,23,42,.05); --shadow:0 6px 24px rgba(15,23,42,.07); --shadow-lg:0 24px 64px rgba(15,23,42,.28);
      --r:14px; --r-lg:18px; --sidebar-w:264px; --topbar-h:64px;
      color-scheme: light;
    }
    [data-theme="dark"] {
      --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
      --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3; --border:#1f2d47; --border-2:#192740;
      --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040;
      --green:#4ade80; --green-bg:rgba(34,197,94,.16); --green-d:#86efac;
      --red:#f87171; --red-bg:rgba(248,113,113,.14); --red-bd:rgba(248,113,113,.4); --red-ink:#fca5a5;
      --amber-bg:rgba(251,191,36,.14); --amber-bd:rgba(251,191,36,.4); --amber-ink:#fcd34d;
      --shadow-sm:0 1px 2px rgba(0,0,0,.4); --shadow:0 6px 24px rgba(0,0,0,.35); --shadow-lg:0 24px 64px rgba(0,0,0,.6);
      color-scheme: dark;
    }

    html { scroll-behavior: smooth; }
    body { font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14.5px; line-height:1.6; -webkit-font-smoothing:antialiased; transition:background .25s,color .25s; }
    ::-webkit-scrollbar { width:8px; height:8px; }
    ::-webkit-scrollbar-thumb { background:rgba(120,135,160,.35); border-radius:99px; }
    :focus-visible { outline:2px solid var(--gold); outline-offset:2px; border-radius:6px; }
    .skip { position:absolute; left:-999px; top:8px; background:var(--gold); color:#000; padding:8px 14px; border-radius:8px; font-weight:700; z-index:700; }
    .skip:focus { left:12px; }
    .is-hidden { display:none !important; }

    .app { display:grid; grid-template-columns:var(--sidebar-w) minmax(0,1fr); min-height:100vh; }

    /* ───────── Sidebar ───────── */
    .sidebar { background:linear-gradient(180deg,var(--navy) 0%,#07152a 100%); color:#b9c4d6; display:flex; flex-direction:column; position:sticky; top:0; height:100vh; overflow-y:auto; z-index:100; }
    .sidebar::-webkit-scrollbar-thumb { background:rgba(255,255,255,.12); }
    .brand { display:flex; align-items:center; gap:12px; padding:20px 22px 18px; border-bottom:1px solid rgba(255,255,255,.07); }
    .brand-mark { width:42px; height:42px; border-radius:12px; background:linear-gradient(135deg,var(--gold),#e0a920); display:flex; align-items:center; justify-content:center; color:#0b1f3a; font-size:19px; flex-shrink:0; box-shadow:0 6px 18px rgba(240,192,64,.35); }
    .brand-name { font-weight:800; font-size:.92rem; color:#fff; line-height:1.15; letter-spacing:.2px; }
    .brand-name span { color:var(--gold); }
    .brand-tag { font-size:.68rem; color:#7f8fa8; letter-spacing:1.4px; text-transform:uppercase; margin-top:2px; }
    .nav { flex:1; padding:14px 12px 20px; }
    .nav-section { margin-bottom:14px; }
    .nav-title { padding:8px 12px 6px; font-size:.62rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase; color:#5f7192; }
    .nav a { display:flex; align-items:center; gap:12px; padding:9px 12px; margin:1px 0; color:#b9c4d6; text-decoration:none; font-size:.84rem; font-weight:500; border-radius:10px; transition:.15s; }
    .nav a i { width:18px; text-align:center; font-size:14px; color:#7587a6; transition:.15s; }
    .nav a:hover { background:rgba(255,255,255,.06); color:#fff; }
    .nav a:hover i { color:var(--gold); }
    .nav a.active { background:rgba(240,192,64,.13); color:var(--gold); font-weight:700; box-shadow:inset 3px 0 0 var(--gold); }
    .nav a.active i { color:var(--gold); }
    .side-user { margin:0 12px 12px; padding:12px; border-radius:12px; background:rgba(255,255,255,.05); border:1px solid rgba(255,255,255,.07); display:flex; align-items:center; gap:10px; }
    .side-user .av { width:36px; height:36px; border-radius:10px; background:var(--gold); color:#0b1f3a; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:.8rem; }
    .side-user b { display:block; color:#fff; font-size:.8rem; line-height:1.2; }
    .side-user small { color:#7f8fa8; font-size:.68rem; }
    .side-user a { margin-left:auto; color:#9fb0cb; width:32px; height:32px; display:flex; align-items:center; justify-content:center; border-radius:8px; text-decoration:none; }
    .side-user a:hover { background:rgba(255,255,255,.08); color:#fff; }
    .side-foot { padding:14px 22px 18px; border-top:1px solid rgba(255,255,255,.07); font-size:.7rem; color:#5f7192; }
    .side-foot strong { color:#aebcd2; font-weight:600; }
    .backdrop { display:none; position:fixed; inset:0; background:rgba(3,10,22,.55); backdrop-filter:blur(2px); z-index:99; }
    .backdrop.show { display:block; }

    /* ───────── Main / topbar ───────── */
    .main { min-width:0; display:flex; flex-direction:column; }
    .topbar { position:sticky; top:0; z-index:60; height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; gap:12px; padding:0 32px; background:color-mix(in srgb,var(--surface) 86%,transparent); backdrop-filter:saturate(1.4) blur(12px); border-bottom:1px solid var(--border); }
    .tb-left { display:flex; align-items:center; gap:12px; min-width:0; }
    .burger { display:none; background:none; border:1px solid var(--border); width:38px; height:38px; border-radius:10px; cursor:pointer; color:var(--text); align-items:center; justify-content:center; }
    .burger:hover { background:var(--surface-3); }
    .tb-title { font-weight:800; font-size:1.02rem; letter-spacing:-.2px; line-height:1.2; }
    .tb-title small { display:block; font-weight:500; font-size:.72rem; color:var(--muted); }
    .tb-right { display:flex; align-items:center; gap:8px; }
    .icon-btn { width:38px; height:38px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:15px; transition:.15s; text-decoration:none; }
    .icon-btn:hover { background:var(--surface-3); color:var(--text); }

    .content { padding:24px 32px 60px; width:100%; max-width:1480px; margin:0 auto; display:flex; flex-direction:column; gap:20px; }
    .page-head { display:flex; align-items:flex-end; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .page-eyebrow { font-size:.7rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold-d); }
    .page-title { font-size:clamp(1.4rem,2.4vw,1.8rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; }
    .page-sub { color:var(--muted); font-size:.86rem; margin-top:4px; }
    .title-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }

    /* ───────── Buttons ───────── */
    .btn-gold, .btn-outline { display:inline-flex; align-items:center; gap:8px; font-family:inherit; font-size:.84rem; font-weight:700; border-radius:10px; padding:0 18px; height:42px; text-decoration:none; cursor:pointer; white-space:nowrap; transition:.15s; border:1.5px solid var(--gold); }
    .btn-gold { background:var(--gold); color:#101826; box-shadow:0 6px 16px rgba(240,192,64,.3); }
    .btn-gold:hover { background:#f5cf5e; transform:translateY(-1px); }
    .btn-outline { background:var(--surface); color:var(--text); }
    .btn-outline i { color:var(--gold-d); }
    .btn-outline:hover { background:var(--gold-pale); transform:translateY(-1px); }

    .status-notice { background:var(--amber-bg); border:1px solid var(--amber-bd); border-left:4px solid var(--gold); border-radius:12px; padding:14px 16px; font-size:.84rem; color:var(--amber-ink); line-height:1.6; }
    .status-notice pre { margin-top:10px; padding:12px 14px; background:var(--surface); border:1px solid var(--border); border-radius:10px; font-family:ui-monospace,Menlo,monospace; font-size:.76rem; white-space:pre-wrap; color:var(--text); }

    /* ───────── Filter ───────── */
    .filter-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:18px 20px; box-shadow:var(--shadow-sm); display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; animation:fadeUp .45s ease both; }
    .field-group { display:flex; flex-direction:column; gap:6px; flex:1; min-width:160px; }
    .field-label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .field-label .req { color:var(--red); }
    .field-select, .field-input { font-family:inherit; font-size:.9rem; font-weight:500; color:var(--text); background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; padding:0 14px; height:42px; outline:none; transition:border-color .15s,box-shadow .15s,background .15s; width:100%; }
    .field-select { appearance:none; -webkit-appearance:none; padding-right:38px; cursor:pointer; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; }
    .field-select:disabled { opacity:.6; cursor:not-allowed; }
    .field-select:hover:not(:disabled), .field-input:hover { border-color:rgba(37,99,235,.35); }
    .field-select:focus, .field-input:focus { border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); background-color:var(--surface); }
    .field-input.invalid { border-color:var(--red); background:var(--red-bg); }
    .btn-filter { font-family:inherit; font-size:.84rem; font-weight:700; color:#fff; background:var(--navy); border:none; border-radius:10px; padding:0 22px; height:42px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; white-space:nowrap; transition:.15s; }
    [data-theme="dark"] .btn-filter { background:var(--gold); color:#101826; }
    .btn-filter:hover { transform:translateY(-1px); box-shadow:0 8px 20px rgba(11,31,58,.25); }

    /* ───────── Results ───────── */
    .results-section { display:flex; flex-direction:column; gap:14px; }
    .results-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; }
    .results-title { font-size:1.05rem; font-weight:800; letter-spacing:-.2px; display:flex; align-items:center; gap:10px; }
    .count-badge { font-size:.68rem; font-weight:800; letter-spacing:.8px; text-transform:uppercase; background:var(--gold-pale); color:var(--gold-ink); border-radius:99px; padding:4px 12px; }
    .results-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .btn-download, .btn-graduate, .btn-remove-all { display:inline-flex; align-items:center; gap:8px; font-family:inherit; font-size:.8rem; font-weight:700; border-radius:10px; padding:0 16px; height:40px; cursor:pointer; white-space:nowrap; transition:.15s; }
    .btn-download { color:#101826; background:var(--gold); border:none; box-shadow:0 6px 16px rgba(240,192,64,.28); }
    .btn-download:hover:not(:disabled) { background:#f5cf5e; transform:translateY(-1px); }
    .btn-graduate { color:#fff; background:var(--green); border:1px solid var(--green); }
    [data-theme="dark"] .btn-graduate { color:#05200f; }
    .btn-graduate:hover:not(:disabled) { background:var(--green-d); border-color:var(--green-d); transform:translateY(-1px); }
    .btn-remove-all { color:var(--red); background:var(--surface); border:1.5px solid var(--red-bd); }
    .btn-remove-all:hover:not(:disabled) { background:var(--red); border-color:var(--red); color:#fff; }
    .btn-download:disabled, .btn-graduate:disabled, .btn-remove-all:disabled { opacity:.5; cursor:not-allowed; box-shadow:none; transform:none; }

    .table-wrap { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); overflow:hidden; box-shadow:var(--shadow-sm); animation:fadeUp .45s .05s ease both; }
    .learner-search-bar { display:flex; align-items:center; gap:12px; padding:12px 20px; background:var(--surface-2); border-bottom:1px solid var(--border-2); flex-wrap:wrap; }
    .learner-search-wrap { flex:1; min-width:220px; max-width:420px; position:relative; display:flex; align-items:center; }
    .learner-search-wrap > i.fa-magnifying-glass { position:absolute; left:14px; font-size:13px; color:var(--muted); pointer-events:none; }
    .learner-search-wrap input { width:100%; height:40px; padding:0 36px 0 38px; font-family:inherit; font-size:.88rem; color:var(--text); background:var(--surface); border:1.5px solid var(--border); border-radius:10px; outline:none; transition:.15s; }
    .learner-search-wrap input:focus { border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .learner-search-wrap input::placeholder { color:var(--muted); }
    #learnerSearchClear { position:absolute; right:8px; width:24px; height:24px; border:none; background:transparent; color:var(--muted); cursor:pointer; border-radius:50%; display:none; align-items:center; justify-content:center; font-size:12px; }
    #learnerSearchClear.visible { display:flex; }
    #learnerSearchClear:hover { background:var(--surface-3); color:var(--text); }
    .learner-search-count { font-size:.76rem; color:var(--muted); white-space:nowrap; font-weight:600; }

    .table-scroll { overflow:auto; max-height:70vh; -webkit-overflow-scrolling:touch; }
    table.results-table { width:100%; border-collapse:collapse; font-size:.86rem; min-width:980px; }
    table.results-table thead tr.banner-row th { background:var(--navy); color:#fff; font-size:.78rem; font-weight:800; letter-spacing:1.6px; text-transform:uppercase; text-align:center; padding:12px; border-bottom:2px solid var(--gold); }
    [data-theme="dark"] table.results-table thead tr.banner-row th { background:#173a5e; }
    table.results-table thead tr.col-row th { background:var(--surface-2); padding:11px 16px; text-align:left; font-size:.64rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--muted); white-space:nowrap; border-bottom:1px solid var(--border); position:sticky; top:0; z-index:2; }
    table.results-table thead tr.col-row th:first-child { width:48px; text-align:center; }
    table.results-table thead tr.col-row th.th-act { text-align:center; min-width:210px; }
    table.results-table td { padding:12px 16px; color:var(--text-2); border-bottom:1px solid var(--border-2); vertical-align:middle; font-variant-numeric:tabular-nums; }
    table.results-table td:first-child { text-align:center; color:var(--muted); font-size:.76rem; font-weight:700; }
    table.results-table tbody tr:hover td { background:var(--surface-2); }
    table.results-table tbody tr:last-child td { border-bottom:none; }
    table.results-table tbody tr.row-no-match { display:none !important; }
    table.results-table tbody tr.row-highlight td:first-child { color:var(--gold-d); }
    table.results-table tbody tr.row-saved td { animation:savedFlash 1.6s ease; }
    @keyframes savedFlash { 0% { background:var(--green-bg); } 100% { background:transparent; } }
    .mono { font-family:ui-monospace,Menlo,monospace; font-size:.78rem; color:var(--text-2); background:var(--surface-3); padding:2px 8px; border-radius:6px; }
    .name-cell strong { font-weight:700; color:var(--text); }
    .name-with-avatar { display:flex; align-items:center; gap:11px; }
    .avatar { width:34px; height:34px; border-radius:10px; background:var(--navy); display:inline-flex; align-items:center; justify-content:center; font-size:.72rem; font-weight:800; color:var(--gold); flex-shrink:0; letter-spacing:.04em; }
    [data-theme="dark"] .avatar { background:var(--surface-3); }
    .grade-chip { font-size:.72rem; font-weight:800; padding:3px 11px; border-radius:99px; background:var(--surface-3); color:var(--text-2); white-space:nowrap; }

    .td-act { text-align:center; white-space:nowrap; }
    .btn-edit, .btn-remove, .btn-reinstate { display:inline-flex; align-items:center; gap:6px; font-family:inherit; font-size:.76rem; font-weight:700; color:var(--text); background:var(--surface); border:1.5px solid var(--border); border-radius:9px; padding:0 12px; height:34px; cursor:pointer; transition:.15s; }
    .td-act .btn-edit, .td-act .btn-remove, .td-act .btn-reinstate { margin:0 2px; }
    .btn-edit i { color:var(--gold-d); font-size:12px; }
    .btn-edit:hover { background:var(--gold); border-color:var(--gold); color:#101826; }
    .btn-edit:hover i { color:#101826; }
    .btn-remove { color:var(--red); border-color:var(--red-bd); }
    .btn-remove i { color:var(--red); font-size:12px; }
    .btn-remove:hover { background:var(--red); border-color:var(--red); color:#fff; }
    .btn-remove:hover i { color:#fff; }
    .btn-reinstate { color:var(--green); border-color:color-mix(in srgb,var(--green) 45%,transparent); }
    .btn-reinstate i { color:var(--green); font-size:12px; }
    .btn-reinstate:hover { background:var(--green); border-color:var(--green); color:#fff; }
    .btn-reinstate:hover i { color:#fff; }

    .empty-state { padding:52px 24px; text-align:center; }
    .empty-state i { font-size:2rem; margin-bottom:12px; color:var(--gold-d); display:block; opacity:.8; }
    .empty-state p { font-size:1rem; font-weight:700; color:var(--text); margin-bottom:4px; }
    .empty-state small { font-size:.84rem; color:var(--muted); }

    .footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    /* ───────── Modals ───────── */
    .modal-overlay { display:none; position:fixed; inset:0; z-index:500; background:rgba(3,10,22,.6); backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:18px; }
    .modal-overlay.active { display:flex; }
    #confirmOverlay { z-index:550; }
    .modal { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); width:100%; max-width:640px; max-height:92vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:var(--shadow-lg); animation:fadeUp .25s ease both; }
    .modal.modal-sm { max-width:500px; }
    .modal-head { display:flex; align-items:center; justify-content:space-between; padding:16px 22px; border-bottom:1px solid var(--border-2); background:var(--gold-pale); font-weight:800; font-size:1rem; letter-spacing:-.2px; }
    .modal-head i { color:var(--gold-d); margin-right:10px; }
    .modal-head.danger { background:var(--red-bg); }
    .modal-head.danger i { color:var(--red); }
    .modal-x { width:34px; height:34px; border:none; border-radius:9px; background:transparent; color:var(--text-2); font-size:15px; cursor:pointer; display:flex; align-items:center; justify-content:center; }
    .modal-x:hover { background:rgba(120,135,160,.18); color:var(--text); }
    .modal-x i { margin:0; color:inherit; }
    .modal-body { padding:20px 22px; overflow-y:auto; }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .form-grid .full { grid-column:1 / -1; }
    .field-hint { font-size:.76rem; color:var(--muted); line-height:1.5; }
    .confirm-text { font-size:.92rem; line-height:1.6; color:var(--text); }
    .confirm-note { font-size:.84rem; line-height:1.6; color:var(--text-2); margin-top:9px; }
    .confirm-tip { font-size:.8rem; line-height:1.55; color:var(--gold-ink); background:var(--gold-pale); border:1px solid rgba(240,192,64,.4); border-radius:10px; padding:9px 12px; margin-top:12px; }
    .confirm-extra { margin-top:16px; }
    .form-msg { display:none; margin-top:14px; padding:10px 14px; border-radius:10px; font-size:.84rem; }
    .form-msg.visible { display:block; }
    .form-msg.err { background:var(--red-bg); border:1px solid var(--red-bd); color:var(--red-ink); }
    .modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 22px; border-top:1px solid var(--border-2); background:var(--surface-2); }
    .btn { font-family:inherit; font-size:.84rem; font-weight:700; padding:0 20px; height:42px; border-radius:10px; border:1.5px solid transparent; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:.15s; }
    .btn-ghost { background:transparent; color:var(--text-2); border-color:var(--border); }
    .btn-ghost:hover { background:var(--surface-3); }
    .btn-save { background:var(--gold); color:#101826; }
    .btn-save:hover:not(:disabled) { background:#f5cf5e; }
    .btn-danger { background:var(--red); color:#fff; }
    .btn-danger:hover:not(:disabled) { background:#b91c1c; }
    .btn:disabled { opacity:.55; cursor:not-allowed; }

    .toast { position:fixed; left:50%; bottom:26px; transform:translate(-50%,20px); background:var(--surface); color:var(--text); border:1px solid var(--border); border-left:4px solid var(--green); box-shadow:0 14px 34px rgba(0,0,0,.25); border-radius:12px; padding:12px 20px; font-size:.86rem; font-weight:600; opacity:0; pointer-events:none; transition:opacity .25s,transform .25s; z-index:650; max-width:90vw; }
    .toast.show { opacity:1; transform:translate(-50%,0); }
    .toast.warn { border-left-color:#d97706; }
    .toast i { color:var(--green); margin-right:9px; }
    .toast.warn i { color:#d97706; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (max-width:1024px) {
      .app { grid-template-columns:1fr; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); }
      .sidebar.open { left:0; }
      .burger { display:inline-flex; }
      .topbar { padding:0 18px; } .content { padding:18px 18px 50px; }
    }
    /* Table becomes a list of cards on phones */
    @media (max-width:760px) {
      .table-scroll { max-height:none; }
      table.results-table { min-width:0; }
      table.results-table thead tr.col-row { display:none; }
      table.results-table thead tr.banner-row, table.results-table thead tr.banner-row th { display:block; width:100%; }
      table.results-table, table.results-table tbody { display:block; width:100%; }
      table.results-table tbody tr[data-id] { display:block; margin:12px; border:1px solid var(--border); border-radius:12px; padding:6px 14px; background:var(--surface); }
      table.results-table tbody tr[data-id]:hover td { background:transparent; }
      table.results-table tbody tr[data-id] td { display:flex; justify-content:space-between; align-items:center; gap:14px; text-align:right; border:none; padding:7px 0; width:100%; }
      table.results-table tbody tr[data-id] td::before { content:attr(data-label); font-size:.62rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); text-align:left; flex-shrink:0; }
      table.results-table tbody tr[data-id] td:first-child { display:none; }
      table.results-table tbody tr[data-id] td.c-name { padding-bottom:10px; border-bottom:1px dashed var(--border); margin-bottom:4px; }
      table.results-table tbody tr[data-id] td.c-name::before { display:none; }
      table.results-table tbody tr[data-id] td.td-act { justify-content:flex-end; flex-wrap:wrap; border-top:1px dashed var(--border); margin-top:6px; padding-top:12px; }
      table.results-table tbody tr[data-id] td.td-act::before { display:none; }
      #emptyRow, #emptyRow td { display:block; width:100%; }
    }
    @media (max-width:640px) {
      .tb-title small { display:none; }
      .title-actions { width:100%; } .title-actions a { flex:1; justify-content:center; }
      .filter-card { padding:16px; } .btn-filter { width:100%; justify-content:center; }
      .learner-search-bar { padding:12px 14px; } .learner-search-count { width:100%; }
      .results-actions { width:100%; } .results-actions button { flex:1; justify-content:center; }
      .form-grid { grid-template-columns:1fr; }
      .modal-overlay { padding:0; align-items:flex-end; }
      .modal { max-width:100%; max-height:94vh; border-radius:18px 18px 0 0; }
      .modal-foot .btn { flex:1; justify-content:center; }
    }
    @media print {
      .sidebar, .topbar, .filter-card, .results-actions, .learner-search-bar, .td-act, .th-act, .title-actions, .backdrop { display:none !important; }
      .app { display:block; } body { background:#fff; } .content { padding:0; } .table-scroll { max-height:none; overflow:visible; }
    }
  </style>
</head>
<body>
<a href="#main-content" class="skip">Skip to content</a>

<div class="app">

  <!-- ════════════ SIDEBAR ════════════ -->
  <aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="brand">
      <div class="brand-mark"><i class="fa-solid fa-graduation-cap"></i></div>
      <div>
        <div class="brand-name">Stephen Kanja <span>School</span></div>
        <div class="brand-tag">Aim Higher</div>
      </div>
    </div>

    <nav class="nav">
      <div class="nav-section">
        <div class="nav-title">Main</div>
        <a href="<?= htmlspecialchars($homeLink, ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-house"></i> Home</a>
        <a href="Students.php" class="active" aria-current="page"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="Progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Administration</div>
      
        <a href="PromoteLearners.php"><i class="fa-solid fa-arrow-up-right-dots"></i> Promote Learners</a>
        <a href="RegisterStudent.php"><i class="fa-solid fa-chalkboard-user"></i> Register Learners</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
      </div>
    
    </nav>

    <div class="side-user">
      <div class="av"><?= $isHoi ? 'HOI' : ($isAdminUser ? 'AD' : 'TR') ?></div>
      <div><b><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></b><small>Signed in</small></div>
      <a href="logout.php" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>
  <div class="backdrop" id="overlay" onclick="closeSidebar()"></div>

  <!-- ════════════ MAIN ════════════ -->
  <div class="main">

    <header class="topbar">
      <div class="tb-left">
        <button class="burger" id="hamburger" type="button" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
        <div class="tb-title">Students<small>Enrollment Portal</small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="logout.php" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <div class="page-head">
        <div>
          <p class="page-eyebrow">Enrollment Portal</p>
          <h1 class="page-title">Student Learners</h1>
          <p class="page-sub">Search, edit, promote, graduate and download your learners.</p>
        </div>
        <div class="title-actions">
          <a href="<?= htmlspecialchars($promoteHref, ENT_QUOTES, 'UTF-8') ?>" class="btn-gold" title="Move learners up to the next class">
            <i class="fa-solid fa-arrow-up-right-dots"></i> Promote Learners
          </a>
          <a href="RegisterStudent.php" class="btn-outline">
            <i class="fa-solid fa-user-plus"></i> Register New Learner
          </a>
        </div>
      </div>

      <?php if (!$hasAlumni): ?>
      <div class="status-notice">
        <strong>Alumni records are switched off.</strong> The page could not add the columns it needs to the
        <code>Student</code> table. Run this SQL once (phpMyAdmin or your database console), then reload:
        <pre>ALTER TABLE Student
  ADD COLUMN enrolStatus ENUM('active','alumni') NOT NULL DEFAULT 'active',
  ADD COLUMN gradYear SMALLINT NULL DEFAULT NULL,
  ADD COLUMN gradAt DATETIME NULL DEFAULT NULL;</pre>
      </div>
      <?php endif; ?>

      <!-- Filter card -->
      <div class="filter-card">
        <form method="GET" action="" style="display:contents;" id="gradeForm">
          <div class="field-group">
            <label class="field-label" for="grade">Filter by Grade</label>
            <select class="field-select" name="grade" id="grade" required>
              <option value="" disabled <?= !$hasFilter ? 'selected' : '' ?>>— Select a grade —</option>
              <option value="all" <?= $isAll ? 'selected' : '' ?>>All grades</option>
              <?php for ($g = 1; $g <= 9; $g++): ?>
                <option value="<?= $g ?>" <?= ($grade === $g) ? 'selected' : '' ?>>Grade <?= $g ?></option>
              <?php endfor; ?>
              <?php if ($hasAlumni): ?>
                <option value="alumni" <?= $isAlumniView ? 'selected' : '' ?>>Alumni (completed Grade 9)</option>
              <?php endif; ?>
            </select>
          </div>
          <button class="btn-filter" type="submit">
            <i class="fa-solid fa-magnifying-glass"></i> View Learners
          </button>
        </form>
      </div>

      <?php if ($hasFilter): ?>
      <div class="results-section">
        <div class="results-header">
          <p class="results-title">
            <?= htmlspecialchars($pageTitle) ?> Learners
            <span class="count-badge" id="countBadge"><?= $total ?> student<?= $total === 1 ? '' : 's' ?></span>
          </p>
          <div class="results-actions">
            <button type="button" class="btn-download js-needs-rows" id="downloadListBtn" <?= $total === 0 ? 'disabled' : '' ?>>
              <i class="fa-solid fa-file-arrow-down"></i> Download Learners List
            </button>

            <?php if ($canManage && $hasAlumni && $grade === 9): ?>
            <button type="button" class="btn-graduate js-needs-rows" id="graduateAllBtn" <?= $total === 0 ? 'disabled' : '' ?>
                    title="Mark these learners as having completed school. Their records are kept.">
              <i class="fa-solid fa-user-graduate"></i> Graduate Grade 9
            </button>
            <?php endif; ?>

            <?php if ($canManage && $isAlumniView): ?>
            <button type="button" class="btn-graduate js-needs-rows" id="reinstateAllBtn" <?= $total === 0 ? 'disabled' : '' ?>
                    title="Return these alumni to active Grade 9">
              <i class="fa-solid fa-rotate-left"></i> Reinstate all
            </button>
            <?php endif; ?>

            <?php if ($canManage): ?>
            <button type="button" class="btn-remove-all js-needs-rows" id="removeAllBtn" <?= $total === 0 ? 'disabled' : '' ?>
                    title="Permanently delete the learners shown below">
              <i class="fa-solid fa-trash-can"></i> Remove all
            </button>
            <?php endif; ?>
          </div>
        </div>

        <div class="table-wrap">

          <div class="learner-search-bar" id="learnerSearchBar">
            <div class="learner-search-wrap">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" id="learnerSearch" placeholder="Search by name, assessment no, or UPI…" autocomplete="off" aria-label="Search learners">
              <button type="button" id="learnerSearchClear" title="Clear search" aria-label="Clear search">
                <i class="fa-solid fa-xmark"></i>
              </button>
            </div>
            <span class="learner-search-count" id="learnerSearchCount"></span>
          </div>

          <div class="table-scroll">
            <table class="results-table">
              <thead>
                <tr class="banner-row">
                  <th colspan="8"><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($bannerSuffix) ?></th>
                </tr>
                <tr class="col-row">
                  <th>#</th>
                  <th>Assessment No</th>
                  <th>UPI No</th>
                  <th>Full Name</th>
                  <th><?= $isAlumniView ? 'Graduated' : 'Grade' ?></th>
                  <th>Date of Birth</th>
                  <th>Birth Cert No</th>
                  <th class="th-act">Action</th>
                </tr>
              </thead>
              <tbody id="studentTableBody">
                <?php if ($loadError !== ''): ?>
                <tr id="emptyRow"><td colspan="8">
                  <div class="empty-state">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <p>Could not load learners</p>
                    <small><?= htmlspecialchars($loadError) ?></small>
                  </div>
                </td></tr>
                <?php elseif ($total === 0): ?>
                <tr id="emptyRow"><td colspan="8">
                  <div class="empty-state">
                    <i class="fa-solid fa-user-slash"></i>
                    <p>No learners found for <?= htmlspecialchars($pageTitle) ?></p>
                    <small><?= $isAlumniView ? 'Learners appear here after you graduate Grade 9.' : 'Try a different grade or register a new learner.' ?></small>
                  </div>
                </td></tr>
                <?php else: ?>
                <?php foreach ($rows as $i => $row):
                  $initials = strtoupper(mb_substr((string)$row['firstName'], 0, 1) . mb_substr((string)$row['surname'], 0, 1));
                  $fullName = trim($row['firstName'] . ' ' . $row['middleName'] . ' ' . $row['surname']);
                  $fullName = preg_replace('/\s+/', ' ', $fullName);
                  $isAlumniRow = $hasAlumni && (($row['enrolStatus'] ?? 'active') === 'alumni');
                  $gradYear    = (int)($row['gradYear'] ?? 0);
                  $gradeLabel  = $isAlumniRow ? 'Class of ' . ($gradYear ?: '-') : 'Grade ' . (int)$row['Grade'];
                ?>
                <tr
                  data-id="<?= htmlspecialchars((string)$row['id'], ENT_QUOTES) ?>"
                  data-assesment="<?= htmlspecialchars((string)$row['Assesment'], ENT_QUOTES) ?>"
                  data-upi="<?= htmlspecialchars((string)$row['UPI'], ENT_QUOTES) ?>"
                  data-first="<?= htmlspecialchars((string)$row['firstName'], ENT_QUOTES) ?>"
                  data-middle="<?= htmlspecialchars((string)$row['middleName'], ENT_QUOTES) ?>"
                  data-surname="<?= htmlspecialchars((string)$row['surname'], ENT_QUOTES) ?>"
                  data-dob="<?= htmlspecialchars((string)$row['DOB'], ENT_QUOTES) ?>"
                  data-birthno="<?= htmlspecialchars((string)$row['birthNo'], ENT_QUOTES) ?>"
                  data-grade="<?= (int)$row['Grade'] ?>"
                  data-status="<?= $isAlumniRow ? 'alumni' : 'active' ?>"
                  data-gradyear="<?= $gradYear ?: '' ?>"
                  data-search="<?= htmlspecialchars(strtolower($fullName . ' ' . $row['Assesment'] . ' ' . $row['UPI']), ENT_QUOTES) ?>">
                  <td class="c-num"><?= $i + 1 ?></td>
                  <td class="c-assess" data-label="Assessment No"><span class="mono"><?= htmlspecialchars((string)$row['Assesment']) ?></span></td>
                  <td class="c-upi" data-label="UPI No"><span class="mono"><?= htmlspecialchars((string)$row['UPI']) ?></span></td>
                  <td class="c-name" data-label="Learner">
                    <div class="name-with-avatar">
                      <div class="avatar"><?= htmlspecialchars($initials) ?></div>
                      <div class="name-cell"><strong><?= htmlspecialchars($fullName) ?></strong></div>
                    </div>
                  </td>
                  <td class="c-grade" data-label="<?= $isAlumniView ? 'Graduated' : 'Grade' ?>"><span class="grade-chip"><?= htmlspecialchars($gradeLabel) ?></span></td>
                  <td class="c-dob" data-label="Date of Birth"><?= htmlspecialchars((string)$row['DOB']) ?></td>
                  <td class="c-birth" data-label="Birth Cert No"><span class="mono"><?= htmlspecialchars((string)($row['birthNo'] ?: '—')) ?></span></td>
                  <td class="td-act">
                    <button type="button" class="btn-edit" title="Edit this learner">
                      <i class="fa-solid fa-pen-to-square"></i> Edit
                    </button>
                    <?php if ($canManage && $isAlumniRow): ?>
                    <button type="button" class="btn-reinstate" title="Return to active Grade 9">
                      <i class="fa-solid fa-rotate-left"></i> Reinstate
                    </button>
                    <?php endif; ?>
                    <?php if ($canManage): ?>
                    <button type="button" class="btn-remove" title="Permanently remove this learner">
                      <i class="fa-solid fa-trash-can"></i> Remove
                    </button>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <?php else: ?>
      <div class="table-wrap">
        <div class="empty-state">
          <i class="fa-solid fa-filter"></i>
          <p>Select a grade above to view learners</p>
          <small>Choose a grade (or "All grades") from the dropdown and click "View Learners".</small>
        </div>
      </div>
      <?php endif; ?>

    </main>

    <div class="footer">&copy; Designed by <strong>Kelvin Mutinda</strong> 2026. All rights reserved.</div>
  </div>
</div>

<!-- ════════════ EDIT LEARNER MODAL ════════════ -->
<div class="modal-overlay" id="editOverlay">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="editTitle">
    <div class="modal-head">
      <span id="editTitle"><i class="fa-solid fa-pen-to-square"></i>Edit learner</span>
      <button type="button" class="modal-x" id="editClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form id="editForm" novalidate>
      <div class="modal-body">
        <input type="hidden" id="fId">
        <div class="form-grid">
          <div class="field-group">
            <label class="field-label" for="fFirst">First name <span class="req">*</span></label>
            <input class="field-input" id="fFirst" maxlength="60" autocomplete="off">
          </div>
          <div class="field-group">
            <label class="field-label" for="fMiddle">Middle name</label>
            <input class="field-input" id="fMiddle" maxlength="60" autocomplete="off">
          </div>
          <div class="field-group">
            <label class="field-label" for="fSurname">Surname <span class="req">*</span></label>
            <input class="field-input" id="fSurname" maxlength="60" autocomplete="off">
          </div>
          <div class="field-group">
            <label class="field-label" for="fAssess">Assessment No <span class="req">*</span></label>
            <input class="field-input" id="fAssess" maxlength="30" autocomplete="off">
          </div>
          <div class="field-group">
            <label class="field-label" for="fUpi">UPI No</label>
            <input class="field-input" id="fUpi" maxlength="30" autocomplete="off">
          </div>
          <div class="field-group">
            <label class="field-label" for="fBirth">Birth certificate No</label>
            <input class="field-input" id="fBirth" maxlength="40" autocomplete="off">
          </div>
          <div class="field-group">
            <label class="field-label" for="fDob">Date of birth <span class="req">*</span></label>
            <input class="field-input" type="date" id="fDob">
          </div>
          <div class="field-group">
            <label class="field-label" for="fGrade">Grade <span class="req">*</span></label>
            <select class="field-select" id="fGrade">
              <?php for ($g = 1; $g <= 9; $g++): ?>
                <option value="<?= $g ?>">Grade <?= $g ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <p class="field-hint full">
            Changing the grade moves the learner to that grade. Marks already saved for earlier exams stay in their original grade.
          </p>
          <p class="field-hint full is-hidden" id="alumniHint">
            This learner has completed Grade 9, so the grade is locked. Use Reinstate first if they need to go back into a class.
          </p>
        </div>
        <div class="form-msg err" id="formMsg"></div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost" id="editCancel">Cancel</button>
        <button type="submit" class="btn btn-save" id="editSave"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ════════════ CONFIRM MODAL (remove / graduate / reinstate) ════════════ -->
<div class="modal-overlay" id="confirmOverlay">
  <div class="modal modal-sm" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitleText">
    <div class="modal-head" id="confirmHead">
      <span><i class="fa-solid fa-triangle-exclamation" id="confirmIcon"></i><span id="confirmTitleText"></span></span>
      <button type="button" class="modal-x" id="confirmClose" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div id="confirmBody"></div>

      <div class="field-group confirm-extra is-hidden" id="confirmYearWrap">
        <label class="field-label" for="confirmYear">Graduation year</label>
        <input class="field-input" type="number" id="confirmYear" min="2000" max="2100">
      </div>

      <div class="field-group confirm-extra is-hidden" id="confirmTypeWrap">
        <label class="field-label" for="confirmType">Type <strong id="confirmTypeWord"></strong> to confirm</label>
        <input class="field-input" id="confirmType" autocomplete="off">
      </div>

      <div class="form-msg err" id="confirmMsg"></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost" id="confirmCancel">Cancel</button>
      <button type="button" class="btn btn-danger" id="confirmGo">Confirm</button>
    </div>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
<script>
  /* ══════════ Sidebar ══════════ */
  const hamburger = document.getElementById('hamburger');
  const sidebar   = document.getElementById('sidebar');
  const overlay   = document.getElementById('overlay');

  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('show');
    hamburger.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    hamburger.innerHTML = '<i class="fa-solid fa-bars"></i>';
    document.body.style.overflow = '';
  }
  hamburger.addEventListener('click', () => {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
  });

  /* ══════════ Theme (same key as the other dashboards) ══════════ */
  function syncThemeIcon() {
    const dark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.getElementById('themeBtn').innerHTML = dark ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
  }
  function toggleTheme() {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('hoi-theme', next); } catch (e) {}
    syncThemeIcon();
  }
  syncThemeIcon();

  /* Grade filter applies as soon as a grade is chosen */
  document.getElementById('grade').addEventListener('change', function () {
    if (this.value) document.getElementById('gradeForm').submit();
  });

  /* ══════════ Page settings from PHP ══════════ */
  const FILTER       = <?= json_encode($filterKey) ?>;      /* '', 'all', 'alumni' or '1'..'9' */
  const PAGE_TITLE   = <?= json_encode($pageTitle) ?>;
  const VIEW_ALUMNI  = FILTER === 'alumni';
  const CAN_GRADUATE = <?= json_encode($hasAlumni) ?>;

  const byId = id => document.getElementById(id);
  const esc  = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ══════════ Learner search ══════════ */
  const learnerSearchInput = document.getElementById('learnerSearch');
  const learnerSearchClear = document.getElementById('learnerSearchClear');
  const learnerSearchCount = document.getElementById('learnerSearchCount');
  const studentTableBody   = document.getElementById('studentTableBody');

  const dataRows    = () => studentTableBody ? Array.from(studentTableBody.querySelectorAll('tr[data-id]')) : [];
  const visibleRows = () => dataRows().filter(r => !r.classList.contains('row-no-match'));
  const rowName     = tr => [tr.dataset.first, tr.dataset.middle, tr.dataset.surname].filter(Boolean).join(' ').replace(/\s+/g, ' ');
  const gradeLabel  = tr => tr.dataset.status === 'alumni' ? 'Class of ' + (tr.dataset.gradyear || '-') : 'Grade ' + tr.dataset.grade;

  function filterLearnerRows() {
    if (!studentTableBody) return;
    const term = learnerSearchInput.value.trim().toLowerCase();
    const rows = dataRows();

    learnerSearchClear.classList.toggle('visible', term.length > 0);

    if (!term) {
      rows.forEach(r => r.classList.remove('row-no-match', 'row-highlight'));
      learnerSearchCount.textContent = '';
      return;
    }
    let visible = 0;
    rows.forEach(row => {
      const match = (row.getAttribute('data-search') || '').includes(term);
      row.classList.toggle('row-no-match', !match);
      row.classList.toggle('row-highlight', match);
      if (match) visible++;
    });
    learnerSearchCount.textContent = visible + ' of ' + rows.length + ' shown';
  }

  if (learnerSearchInput) {
    learnerSearchInput.addEventListener('input', filterLearnerRows);
    learnerSearchClear.addEventListener('click', () => {
      learnerSearchInput.value = '';
      filterLearnerRows();
      learnerSearchInput.focus();
    });
  }

  /* ══════════ Shared: toast + server calls ══════════ */
  const editOverlay = document.getElementById('editOverlay');
  const editForm    = document.getElementById('editForm');
  const formMsg     = document.getElementById('formMsg');
  const saveBtn     = document.getElementById('editSave');
  const toastEl     = document.getElementById('toast');
  const F = {
    id:     document.getElementById('fId'),
    first:  document.getElementById('fFirst'),
    middle: document.getElementById('fMiddle'),
    surname:document.getElementById('fSurname'),
    assess: document.getElementById('fAssess'),
    upi:    document.getElementById('fUpi'),
    birth:  document.getElementById('fBirth'),
    dob:    document.getElementById('fDob'),
    grade:  document.getElementById('fGrade')
  };
  let editingRow = null;

  function showToast(text, warn) {
    toastEl.className = 'toast show' + (warn ? ' warn' : '');
    toastEl.innerHTML = '';
    const i = document.createElement('i');
    i.className = 'fa-solid ' + (warn ? 'fa-triangle-exclamation' : 'fa-circle-check');
    toastEl.appendChild(i);
    toastEl.appendChild(document.createTextNode(text));
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => toastEl.classList.remove('show'), 3200);
  }

  /* Sends JSON to one of the PHP actions. Resolves with the reply, or throws an Error with a readable message. */
  async function postAction(action, payload) {
    let res;
    try {
      res = await fetch(location.pathname + '?ajax_action=' + action, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
    } catch (err) {
      throw new Error('Network error: ' + err.message);
    }
    let data = null;
    try { data = await res.json(); } catch (_) {}
    if (!data || !data.success) {
      throw new Error((data && data.error) ? data.error : 'The server could not do that (HTTP ' + res.status + '). Please try again.');
    }
    return data;
  }

  /* ══════════ Table bookkeeping ══════════ */
  function renumber() {
    dataRows().forEach((r, i) => { r.querySelector('.c-num').textContent = i + 1; });
  }
  function updateCount() {
    const n = dataRows().length;
    const badge = document.getElementById('countBadge');
    if (badge) badge.textContent = n + ' student' + (n === 1 ? '' : 's');
    document.querySelectorAll('.js-needs-rows').forEach(b => { b.disabled = (n === 0); });
    if (n === 0 && studentTableBody && !document.getElementById('emptyRow')) {
      studentTableBody.innerHTML =
        '<tr id="emptyRow"><td colspan="8"><div class="empty-state"><i class="fa-solid fa-user-slash"></i>' +
        '<p>No learners left in this list</p><small>Choose another grade above or register a new learner.</small></div></td></tr>';
    }
  }
  function removeRowsById(ids) {
    const gone = new Set((ids || []).map(String));
    dataRows().forEach(r => { if (gone.has(String(r.dataset.id))) r.remove(); });
    renumber();
    updateCount();
    filterLearnerRows();
  }

  /* ══════════ Edit learner ══════════ */
  function setMsg(text) {
    formMsg.textContent = text || '';
    formMsg.classList.toggle('visible', !!text);
  }

  function openEdit(tr) {
    editingRow = tr;
    setMsg('');
    Object.values(F).forEach(el => el.classList && el.classList.remove('invalid'));
    F.id.value      = tr.dataset.id;
    F.first.value   = tr.dataset.first || '';
    F.middle.value  = tr.dataset.middle || '';
    F.surname.value = tr.dataset.surname || '';
    F.assess.value  = tr.dataset.assesment || '';
    F.upi.value     = tr.dataset.upi || '';
    F.birth.value   = tr.dataset.birthno || '';
    F.dob.value     = /^\d{4}-\d{2}-\d{2}$/.test(tr.dataset.dob || '') ? tr.dataset.dob : '';
    F.grade.value   = tr.dataset.grade || '1';

    const isAlumnus = tr.dataset.status === 'alumni';
    F.grade.disabled = isAlumnus;                       /* alumni stay in Grade 9 */
    byId('alumniHint').classList.toggle('is-hidden', !isAlumnus);

    editOverlay.classList.add('active');
    setTimeout(() => F.first.focus(), 50);
  }

  function closeEdit() {
    editOverlay.classList.remove('active');
    editingRow = null;
  }

  document.getElementById('editClose').addEventListener('click', closeEdit);
  document.getElementById('editCancel').addEventListener('click', closeEdit);
  editOverlay.addEventListener('mousedown', e => { if (e.target === editOverlay) closeEdit(); });

  function applyToRow(tr, s) {
    tr.dataset.assesment = s.assesment;
    tr.dataset.upi       = s.upi;
    tr.dataset.first     = s.firstName;
    tr.dataset.middle    = s.middleName;
    tr.dataset.surname   = s.surname;
    tr.dataset.dob       = s.dob;
    tr.dataset.birthno   = s.birthNo;
    tr.dataset.grade     = s.grade;

    const full = [s.firstName, s.middleName, s.surname].filter(Boolean).join(' ').replace(/\s+/g, ' ');
    tr.dataset.search = (full + ' ' + s.assesment + ' ' + s.upi).toLowerCase();

    tr.querySelector('.c-assess .mono').textContent = s.assesment;
    tr.querySelector('.c-upi .mono').textContent    = s.upi;
    tr.querySelector('.c-name strong').textContent  = full;
    tr.querySelector('.c-name .avatar').textContent = ((s.firstName[0] || '') + (s.surname[0] || '')).toUpperCase();
    tr.querySelector('.c-grade .grade-chip').textContent = gradeLabel(tr);
    tr.querySelector('.c-dob').textContent   = s.dob;
    tr.querySelector('.c-birth .mono').textContent = s.birthNo || '—';
  }

  editForm.addEventListener('submit', async e => {
    e.preventDefault();
    if (!editingRow) return;
    setMsg('');

    const payload = {
      id: F.id.value,
      firstName:  F.first.value.trim(),
      middleName: F.middle.value.trim(),
      surname:    F.surname.value.trim(),
      assesment:  F.assess.value.trim(),
      upi:        F.upi.value.trim(),
      birthNo:    F.birth.value.trim(),
      dob:        F.dob.value,
      grade:      F.grade.value
    };

    ['first', 'surname', 'assess', 'dob'].forEach(k => F[k].classList.remove('invalid'));
    const missing = [];
    if (!payload.firstName) { F.first.classList.add('invalid');   missing.push('first name'); }
    if (!payload.surname)   { F.surname.classList.add('invalid'); missing.push('surname'); }
    if (!payload.assesment) { F.assess.classList.add('invalid');  missing.push('assessment number'); }
    if (!payload.dob)       { F.dob.classList.add('invalid');     missing.push('date of birth'); }
    if (missing.length) { setMsg('Please fill in: ' + missing.join(', ') + '.'); return; }

    saveBtn.disabled = true;
    const original = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    try {
      const data = await postAction('update_student', payload);

      const s = data.student;
      const row = editingRow;
      /* The learner only leaves this list if we are viewing one numbered grade and they moved to another */
      const movedOut = /^\d+$/.test(FILTER) && String(s.grade) !== String(FILTER);

      if (movedOut) {
        row.remove();
        renumber();
        updateCount();
        filterLearnerRows();
        closeEdit();
        showToast('Saved — moved to Grade ' + s.grade, !!data.warning);
        return;
      }

      applyToRow(row, s);
      filterLearnerRows();
      row.classList.remove('row-saved');
      void row.offsetWidth;
      row.classList.add('row-saved');
      closeEdit();
      showToast(data.warning ? data.warning : 'Learner saved successfully', !!data.warning);
    } catch (err) {
      setMsg(err.message);
    } finally {
      saveBtn.disabled = false;
      saveBtn.innerHTML = original;
    }
  });

  /* ══════════ Confirm modal (remove / graduate / reinstate) ══════════ */
  const cf = {
    overlay:  byId('confirmOverlay'),
    head:     byId('confirmHead'),
    icon:     byId('confirmIcon'),
    title:    byId('confirmTitleText'),
    body:     byId('confirmBody'),
    yearWrap: byId('confirmYearWrap'),
    year:     byId('confirmYear'),
    typeWrap: byId('confirmTypeWrap'),
    typeWord: byId('confirmTypeWord'),
    type:     byId('confirmType'),
    msg:      byId('confirmMsg'),
    go:       byId('confirmGo'),
    cancel:   byId('confirmCancel'),
    close:    byId('confirmClose')
  };
  let cfCurrent = null;

  function setCfMsg(text) {
    cf.msg.textContent = text || '';
    cf.msg.classList.toggle('visible', !!text);
  }

  /* opts: { title, icon, danger, html, goLabel, goIcon, typeWord, year, run(extra) } */
  function openConfirm(opts) {
    cfCurrent = opts;
    cf.title.textContent = opts.title;
    cf.icon.className = 'fa-solid ' + (opts.icon || 'fa-triangle-exclamation');
    cf.head.classList.toggle('danger', !!opts.danger);
    cf.body.innerHTML = opts.html;
    cf.yearWrap.classList.toggle('is-hidden', !opts.year);
    if (opts.year) cf.year.value = new Date().getFullYear();
    cf.typeWrap.classList.toggle('is-hidden', !opts.typeWord);
    cf.typeWord.textContent = opts.typeWord || '';
    cf.type.value = '';
    setCfMsg('');
    cf.go.className = 'btn ' + (opts.danger ? 'btn-danger' : 'btn-save');
    cf.go.innerHTML = '<i class="fa-solid ' + (opts.goIcon || 'fa-check') + '"></i> ' + esc(opts.goLabel || 'Confirm');
    cf.go.disabled = false;
    cf.overlay.classList.add('active');
    setTimeout(() => (opts.typeWord ? cf.type : cf.go).focus(), 50);
  }
  function closeConfirm() {
    cf.overlay.classList.remove('active');
    cfCurrent = null;
  }

  cf.go.addEventListener('click', async () => {
    if (!cfCurrent) return;
    setCfMsg('');

    if (cfCurrent.typeWord && cf.type.value.trim().toUpperCase() !== cfCurrent.typeWord) {
      setCfMsg('Type ' + cfCurrent.typeWord + ' exactly to confirm.');
      cf.type.focus();
      return;
    }
    const extra = {};
    if (cfCurrent.year) {
      const y = parseInt(cf.year.value, 10);
      if (!(y >= 2000 && y <= 2100)) { setCfMsg('Enter a valid year, for example ' + new Date().getFullYear() + '.'); return; }
      extra.year = y;
    }

    const label = cf.go.innerHTML;
    cf.go.disabled = true;
    cf.go.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Working…';
    try {
      await cfCurrent.run(extra);
      closeConfirm();
    } catch (err) {
      setCfMsg(err.message);
      cf.go.disabled = false;
      cf.go.innerHTML = label;
    }
  });
  cf.type.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); cf.go.click(); } });
  cf.cancel.addEventListener('click', closeConfirm);
  cf.close.addEventListener('click', closeConfirm);
  cf.overlay.addEventListener('mousedown', e => { if (e.target === cf.overlay) closeConfirm(); });

  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeConfirm(); closeEdit(); closeSidebar(); }
  });

  const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');

  /* ── Remove one learner (permanent) ── */
  function askRemoveOne(tr) {
    const tip = (CAN_GRADUATE && FILTER === '9')
      ? '<p class="confirm-tip">Has this learner finished Grade 9? Use <strong>Graduate Grade 9</strong> instead and their records are kept.</p>'
      : '';
    openConfirm({
      title: 'Remove learner', icon: 'fa-trash-can', danger: true,
      goLabel: 'Delete learner', goIcon: 'fa-trash-can',
      html: '<p class="confirm-text">Permanently delete <strong>' + esc(rowName(tr)) + '</strong> (Assessment No <span class="mono">' + esc(tr.dataset.assesment) + '</span>)?</p>' +
            '<p class="confirm-note">Their saved exam marks are deleted too. This cannot be undone.</p>' + tip,
      run: async () => {
        const d = await postAction('delete_students', { ids: [tr.dataset.id] });
        removeRowsById(d.doneIds);
        showToast(d.count ? 'Learner deleted' : 'That learner was already removed', !d.count);
      }
    });
  }

  /* ── Remove all learners currently shown (permanent) ── */
  function askRemoveAll() {
    const rows = visibleRows();
    if (!rows.length) { showToast('There are no learners to remove.', true); return; }
    const term = learnerSearchInput ? learnerSearchInput.value.trim() : '';
    const scope = term
      ? plural(rows.length, 'learner') + ' matching "' + esc(term) + '" in ' + esc(PAGE_TITLE)
      : 'all ' + plural(rows.length, 'learner') + ' in ' + esc(PAGE_TITLE);
    const tip = (CAN_GRADUATE && FILTER === '9')
      ? '<p class="confirm-tip">If these learners have finished Grade 9, cancel and use <strong>Graduate Grade 9</strong> so their records are kept.</p>'
      : '<p class="confirm-tip">Tip: use <strong>Download Learners List</strong> first if you want a copy.</p>';
    openConfirm({
      title: 'Remove all learners', icon: 'fa-trash-can', danger: true, typeWord: 'DELETE',
      goLabel: 'Delete ' + plural(rows.length, 'learner'), goIcon: 'fa-trash-can',
      html: '<p class="confirm-text">You are about to permanently delete <strong>' + scope + '</strong>.</p>' +
            '<p class="confirm-note">Their saved exam marks are deleted too. This cannot be undone.</p>' + tip,
      run: async () => {
        const d = await postAction('delete_students', { ids: rows.map(r => r.dataset.id) });
        removeRowsById(d.doneIds);
        showToast(plural(d.count, 'learner') + ' deleted');
      }
    });
  }

  /* ── Graduate Grade 9 (records are kept) ── */
  function askGraduate() {
    const rows = visibleRows();
    if (!rows.length) { showToast('There are no learners to graduate.', true); return; }
    openConfirm({
      title: 'Graduate Grade 9', icon: 'fa-user-graduate', year: true,
      goLabel: 'Graduate ' + plural(rows.length, 'learner'), goIcon: 'fa-user-graduate',
      html: '<p class="confirm-text">Mark <strong>' + plural(rows.length, 'Grade 9 learner') + '</strong> as having completed school?</p>' +
            '<p class="confirm-note">They move to the <strong>Alumni</strong> list. Their details, marks and fees are all kept, and you can reinstate them later.</p>',
      run: async extra => {
        const d = await postAction('graduate_students', { ids: rows.map(r => r.dataset.id), year: extra.year });
        removeRowsById(d.doneIds);
        showToast(plural(d.count, 'learner') + ' moved to Alumni', !d.count);
      }
    });
  }

  /* ── Reinstate alumni (one or all shown) ── */
  function askReinstate(rows, label) {
    if (!rows.length) { showToast('There are no learners to reinstate.', true); return; }
    openConfirm({
      title: 'Reinstate learners', icon: 'fa-rotate-left',
      goLabel: 'Reinstate', goIcon: 'fa-rotate-left',
      html: '<p class="confirm-text">Return <strong>' + esc(label) + '</strong> to active Grade 9?</p>' +
            '<p class="confirm-note">They will appear in the Grade 9 list again.</p>',
      run: async () => {
        const d = await postAction('reinstate_students', { ids: rows.map(r => r.dataset.id) });
        removeRowsById(d.doneIds);
        showToast(plural(d.count, 'learner') + ' returned to Grade 9', !d.count);
      }
    });
  }

  /* ── Wire the buttons ── */
  if (studentTableBody) {
    studentTableBody.addEventListener('click', e => {
      const tr = e.target.closest('tr[data-id]');
      if (!tr) return;
      if (e.target.closest('.btn-edit'))      openEdit(tr);
      else if (e.target.closest('.btn-remove'))    askRemoveOne(tr);
      else if (e.target.closest('.btn-reinstate')) askReinstate([tr], rowName(tr));
    });
  }
  const removeAllBtn = byId('removeAllBtn');
  if (removeAllBtn) removeAllBtn.addEventListener('click', askRemoveAll);
  const graduateAllBtn = byId('graduateAllBtn');
  if (graduateAllBtn) graduateAllBtn.addEventListener('click', askGraduate);
  const reinstateAllBtn = byId('reinstateAllBtn');
  if (reinstateAllBtn) reinstateAllBtn.addEventListener('click', () => {
    const rows = visibleRows();
    askReinstate(rows, plural(rows.length, 'alumnus').replace('alumnuss', 'alumni').replace(/^1 alumnus$/, '1 alumnus'));
  });

  /* ══════════ Download learners list (PDF) ══════════
     Exports the learners currently shown — so if a search is active,
     only the matching learners are included. */
  const downloadBtn = document.getElementById('downloadListBtn');

  function pdfText(s) {
    /* jsPDF's built-in font can't draw the em dash used for blanks */
    const t = String(s == null ? '' : s).trim();
    return (t === '—' || t === '') ? '-' : t.replace(/[\u2013\u2014]/g, '-');
  }

  function downloadLearnersPdf() {
    const rows = visibleRows();
    if (!rows.length) { showToast('There are no learners to download.', true); return; }
    if (!window.jspdf || !window.jspdf.jsPDF) { showToast('The PDF library did not load. Check your connection and try again.', true); return; }

    const doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4', orientation: 'landscape' });
    const W = doc.internal.pageSize.getWidth();
    const H = doc.internal.pageSize.getHeight();
    const M = 36;

    const term = learnerSearchInput ? learnerSearchInput.value.trim() : '';
    const now = new Date();
    const stamp = now.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }) +
                  ', ' + now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });

    /* Header */
    doc.setFont('helvetica', 'bold'); doc.setFontSize(16); doc.setTextColor(26, 26, 24);
    doc.text('Stephen Kanja Primary & Junior School', M, 40);
    doc.setFontSize(11); doc.setTextColor(184, 144, 26);
    doc.text('LEARNERS LIST - ' + PAGE_TITLE.toUpperCase(), M, 58);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(95, 94, 90);
    let meta = 'Total learners: ' + rows.length + '    Generated: ' + stamp;
    if (term) { meta += '    Search filter: "' + term + '"'; }
    doc.text(meta, M, 72);
    doc.setDrawColor(240, 192, 64); doc.setLineWidth(2); doc.line(M, 80, W - M, 80);

    const body = rows.map((tr, i) => {
      const d = tr.dataset;
      const full = [d.first, d.middle, d.surname].filter(Boolean).join(' ').replace(/\s+/g, ' ');
      return [String(i + 1), pdfText(d.assesment), pdfText(d.upi), pdfText(full), gradeLabel(tr), pdfText(d.dob), pdfText(d.birthno)];
    });

    doc.autoTable({
      startY: 90,
      margin: { left: M, right: M, top: 36, bottom: 40 },
      head: [['#', 'Assessment No', 'UPI No', 'Full Name', VIEW_ALUMNI ? 'Graduated' : 'Grade', 'Date of Birth', 'Birth Cert No']],
      body: body,
      theme: 'grid',
      styles: { font: 'helvetica', fontSize: 9, cellPadding: 5, textColor: [26, 26, 24], lineColor: [225, 225, 220], lineWidth: 0.4 },
      headStyles: { fillColor: [15, 25, 35], textColor: [255, 255, 255], fontStyle: 'bold', halign: 'left' },
      alternateRowStyles: { fillColor: [251, 249, 240] },
      columnStyles: {
        0: { cellWidth: 30, halign: 'center' },
        1: { cellWidth: 100 },
        2: { cellWidth: 100 },
        3: { cellWidth: 'auto' },
        4: { cellWidth: 70, halign: 'center' },
        5: { cellWidth: 85 },
        6: { cellWidth: 110 }
      }
    });

    /* Footer on every page */
    const n = doc.getNumberOfPages();
    for (let p = 1; p <= n; p++) {
      doc.setPage(p);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(138, 137, 131);
      doc.text('Stephen Kanja Primary & Junior School - Learners List', M, H - 16);
      doc.text('Page ' + p + '/' + n, W - M, H - 16, { align: 'right' });
    }

    const slug = PAGE_TITLE.replace(/\s+/g, '');
    const date = now.toISOString().slice(0, 10);
    doc.save('Learners_' + slug + '_' + date + '.pdf');
  }

  if (downloadBtn) {
    downloadBtn.addEventListener('click', () => {
      const original = downloadBtn.innerHTML;
      downloadBtn.disabled = true;
      downloadBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Preparing…';
      try { downloadLearnersPdf(); }
      catch (err) { console.error('Learners list PDF failed:', err); showToast('Sorry, the learners list could not be generated.', true); }
      finally { downloadBtn.disabled = false; downloadBtn.innerHTML = original; }
    });
  }
</script>

</body>
</html>