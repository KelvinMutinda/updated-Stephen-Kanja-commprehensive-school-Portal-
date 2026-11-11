<?php
/* ════════════════════════════════════════════════════════════════════
   PromoteLearners.php — move learners up to the next class.

   How it works
   • Pick the grade the learners are in now (Grade 1 – 8). The page lists them
     with a tick box each — everyone is ticked; untick anyone who is repeating.
   • "Promote" changes Student.Grade from N to N+1 for the ticked learners only.
   • Marks already saved in exam2 are NOT touched — they stay under the grade
     they were sat in (same rule as the Edit Learner box on Students.php).
   • A learner is skipped (and reported) if their Assessment No is already
     used by a different learner in the target grade.
   • Every promotion is written to `promotion_log` (created automatically) so
     a batch can be undone from the "Recent promotions" list.
   • Grade 9 is the last grade, so Grade 9 learners cannot be promoted here.

   Tip: promote from the HIGHEST grade down (8→9 first, then 7→8 …) so each
   class has room to receive the one below it.
   ════════════════════════════════════════════════════════════════════ */
mysqli_report(MYSQLI_REPORT_OFF);
include 'conn.php';

$canPromote = true;   // ← replace with your own role check (e.g. only HOI / class teachers)

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (empty($_SESSION['promo_csrf'])) { $_SESSION['promo_csrf'] = bin2hex(random_bytes(16)); }
$csrf = (string)$_SESSION['promo_csrf'];

function json_out($code, array $payload) {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload);
    exit;
}
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Does another learner in $grade already use this Assessment No? */
function assess_conflict(mysqli $conn, $assess, $grade, $exceptId) {
    $assess = (string)$assess;
    if ($assess === '') return false;
    $q = $conn->prepare("SELECT id FROM Student WHERE Assesment = ? AND Grade = ? AND id <> ? LIMIT 1");
    if (!$q) return false;
    $q->bind_param('sis', $assess, $grade, $exceptId);
    $q->execute();
    $r = $q->get_result();
    $has = ($r && $r->num_rows > 0);
    $q->close();
    return $has;
}

/* Promotion history (so a batch can be undone). If the table cannot be created
   the promotion itself still works — only the log / undo is switched off. */
$logOk = false;
if (isset($conn) && $conn instanceof mysqli) {
    $logOk = (bool)$conn->query(
        "CREATE TABLE IF NOT EXISTS promotion_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id VARCHAR(32) NOT NULL,
            student_id VARCHAR(64) NOT NULL,
            from_grade TINYINT NOT NULL,
            to_grade TINYINT NOT NULL,
            promoted_at DATETIME NOT NULL,
            undone TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_batch (batch_id),
            INDEX idx_student (student_id)
        ) DEFAULT CHARSET=utf8mb4"
    );
}

/* ════════════════════════════════════════════
   ACTION: promote  (POST, JSON body)
   ════════════════════════════════════════════ */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'promote') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(405, ['success' => false, 'error' => 'Invalid request method.']);
    if (!$canPromote)                         json_out(403, ['success' => false, 'error' => 'You are not allowed to promote learners.']);
    if (!isset($conn) || !($conn instanceof mysqli)) json_out(500, ['success' => false, 'error' => 'Database connection is not available.']);

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) json_out(400, ['success' => false, 'error' => 'Invalid data received.']);
    if (!hash_equals($csrf, (string)($in['csrf'] ?? '')))
        json_out(403, ['success' => false, 'error' => 'Your session expired. Reload the page and try again.']);

    $fromRaw = (string)($in['fromGrade'] ?? '');
    if (!ctype_digit($fromRaw) || (int)$fromRaw < 1 || (int)$fromRaw > 8)
        json_out(400, ['success' => false, 'error' => 'Choose a grade between 1 and 8 to promote from.']);
    $from = (int)$fromRaw;
    $to   = $from + 1;

    $ids = [];
    foreach ((array)($in['ids'] ?? []) as $v) {
        $v = trim((string)$v);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $v)) json_out(400, ['success' => false, 'error' => 'One of the selected learners is invalid.']);
        $ids[$v] = true;
    }
    $ids = array_keys($ids);
    if (count($ids) === 0)    json_out(400, ['success' => false, 'error' => 'Select at least one learner to promote.']);
    if (count($ids) > 1000)   json_out(400, ['success' => false, 'error' => 'Too many learners in one batch (max 1000).']);

    /* Only learners who are still in the "from" grade can be promoted */
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $sel = $conn->prepare("SELECT id, Assesment, firstName, surname FROM Student WHERE role = 'user' AND Grade = ? AND id IN ($ph)");
    if (!$sel) json_out(500, ['success' => false, 'error' => 'Database error: ' . $conn->error]);
    $params = array_merge([$from], $ids);
    $sel->bind_param('i' . str_repeat('s', count($ids)), ...$params);
    $sel->execute();
    $res   = $sel->get_result();
    $found = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $sel->close();

    $notInGrade = count($ids) - count($found);
    $skipped = [];
    $moved   = 0;
    $batch   = bin2hex(random_bytes(8));

    $conn->begin_transaction();
    $upd = $conn->prepare("UPDATE Student SET Grade = ? WHERE id = ? AND Grade = ? AND role = 'user' LIMIT 1");
    if (!$upd) { $conn->rollback(); json_out(500, ['success' => false, 'error' => 'Database error: ' . $conn->error]); }
    $log = $logOk ? $conn->prepare("INSERT INTO promotion_log (batch_id, student_id, from_grade, to_grade, promoted_at) VALUES (?, ?, ?, ?, NOW())") : null;

    foreach ($found as $L) {
        $sid  = (string)$L['id'];
        $name = trim(preg_replace('/\s+/', ' ', $L['firstName'] . ' ' . $L['surname']));

        if (assess_conflict($conn, $L['Assesment'], $to, $sid)) {
            $skipped[] = ['name' => $name, 'reason' => 'Assessment No "' . $L['Assesment'] . '" is already used in Grade ' . $to];
            continue;
        }

        $upd->bind_param('isi', $to, $sid, $from);
        if (!$upd->execute()) {
            $err = $upd->error;
            $upd->close();
            $conn->rollback();
            json_out(500, ['success' => false, 'error' => 'Could not promote the learners: ' . $err . ' Nothing was changed.']);
        }
        if ($upd->affected_rows === 1) {
            $moved++;
            if ($log) { $log->bind_param('ssii', $batch, $sid, $from, $to); $log->execute(); }
        }
    }
    $upd->close();
    if ($log) $log->close();
    $conn->commit();

    json_out(200, [
        'success'    => true,
        'moved'      => $moved,
        'from'       => $from,
        'to'         => $to,
        'skipped'    => $skipped,
        'notInGrade' => $notInGrade,
        'batch'      => ($logOk && $moved > 0) ? $batch : null,
    ]);
}

/* ════════════════════════════════════════════
   ACTION: undo  (POST, JSON body) — reverse one batch
   ════════════════════════════════════════════ */
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'undo') {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(405, ['success' => false, 'error' => 'Invalid request method.']);
    if (!$canPromote)                         json_out(403, ['success' => false, 'error' => 'You are not allowed to do this.']);
    if (!$logOk)                              json_out(500, ['success' => false, 'error' => 'The promotion history is not available, so this cannot be undone.']);

    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) json_out(400, ['success' => false, 'error' => 'Invalid data received.']);
    if (!hash_equals($csrf, (string)($in['csrf'] ?? '')))
        json_out(403, ['success' => false, 'error' => 'Your session expired. Reload the page and try again.']);

    $batch = (string)($in['batch'] ?? '');
    if (!preg_match('/^[a-f0-9]{16}$/', $batch)) json_out(400, ['success' => false, 'error' => 'Invalid batch.']);

    $q = $conn->prepare(
        "SELECT l.id AS log_id, l.student_id, l.from_grade, l.to_grade, s.Assesment, s.firstName, s.surname
           FROM promotion_log l JOIN Student s ON s.id = l.student_id
          WHERE l.batch_id = ? AND l.undone = 0"
    );
    if (!$q) json_out(500, ['success' => false, 'error' => 'Database error: ' . $conn->error]);
    $q->bind_param('s', $batch);
    $q->execute();
    $res  = $q->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $q->close();
    if (!$rows) json_out(404, ['success' => false, 'error' => 'Nothing left to undo for that promotion.']);

    $restored = 0; $skipped = [];
    $conn->begin_transaction();
    $back = $conn->prepare("UPDATE Student SET Grade = ? WHERE id = ? AND Grade = ? AND role = 'user' LIMIT 1");
    $mark = $conn->prepare("UPDATE promotion_log SET undone = 1 WHERE id = ?");
    if (!$back || !$mark) { $conn->rollback(); json_out(500, ['success' => false, 'error' => 'Database error: ' . $conn->error]); }

    foreach ($rows as $R) {
        $sid  = (string)$R['student_id'];
        $from = (int)$R['from_grade'];
        $to   = (int)$R['to_grade'];
        $name = trim(preg_replace('/\s+/', ' ', $R['firstName'] . ' ' . $R['surname']));

        if (assess_conflict($conn, $R['Assesment'], $from, $sid)) {
            $skipped[] = ['name' => $name, 'reason' => 'Assessment No "' . $R['Assesment'] . '" is now used in Grade ' . $from];
            continue;
        }
        $back->bind_param('isi', $from, $sid, $to);
        $back->execute();
        if ($back->affected_rows === 1) {
            $restored++;
            $lid = (int)$R['log_id'];
            $mark->bind_param('i', $lid);
            $mark->execute();
        } else {
            $skipped[] = ['name' => $name, 'reason' => 'No longer in Grade ' . $to . ' (already moved again)'];
        }
    }
    $back->close(); $mark->close();
    $conn->commit();

    json_out(200, ['success' => true, 'restored' => $restored, 'skipped' => $skipped]);
}

/* ════════════════════════════════════════════
   PAGE DATA
   ════════════════════════════════════════════ */
$gradeParam = $_GET['grade'] ?? '';
$from = (ctype_digit((string)$gradeParam) && (int)$gradeParam >= 1 && (int)$gradeParam <= 9) ? (int)$gradeParam : null;
$isLast = ($from === 9);
$to = ($from !== null && !$isLast) ? $from + 1 : null;

$rows = [];
$loadError = '';
$targetCount = 0;
if ($to !== null && isset($conn) && $conn instanceof mysqli) {
    $stmt = $conn->prepare(
        "SELECT id, Assesment, UPI, firstName, middleName, surname, Grade
           FROM Student WHERE Grade = ? AND role = 'user' ORDER BY firstName, surname"
    );
    if ($stmt) {
        $stmt->bind_param('i', $from);
        if ($stmt->execute()) {
            $res  = $stmt->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        } else { $loadError = $conn->error ?: 'Could not load learners.'; }
        $stmt->close();
    } else { $loadError = $conn->error ?: 'Could not load learners.'; }

    $tc = $conn->prepare("SELECT COUNT(*) AS c FROM Student WHERE Grade = ? AND role = 'user'");
    if ($tc) {
        $tc->bind_param('i', $to);
        $tc->execute();
        $r = $tc->get_result();
        $targetCount = $r ? (int)($r->fetch_assoc()['c'] ?? 0) : 0;
        $tc->close();
    }
}
$total = count($rows);

/* Recent promotions (for undo) */
$recent = [];
if ($logOk) {
    $rs = $conn->query(
        "SELECT batch_id, MIN(promoted_at) AS ts, from_grade, to_grade, COUNT(*) AS total,
                SUM(CASE WHEN undone = 0 THEN 1 ELSE 0 END) AS active
           FROM promotion_log
          GROUP BY batch_id, from_grade, to_grade
          ORDER BY ts DESC LIMIT 6"
    );
    if ($rs) { while ($r = $rs->fetch_assoc()) $recent[] = $r; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Promote Learners — Kanja School</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --gold:        #f0c040;
      --gold-dim:    #b8901a;
      --gold-soft:   #fdf6dd;
      --gold-line:   #f1dc93;
      --ink:         #1a1a18;
      --teal:        #1D9E75;
      --teal-dark:   #0F6E56;
      --teal-light:  #E1F5EE;
      --danger:      #c62828;
      --bg:          #fbfbf9;
      --bg-card:     #ffffff;
      --bg-input:    #f8f8f6;
      --text-primary:   #1a1a18;
      --text-secondary: #5f5e5a;
      --text-tertiary:  #8a8983;
      --border:         rgba(0,0,0,0.09);
      --radius-md:  8px;
      --radius-lg:  12px;
      --sidebar-w:  260px;
      --header-h:   62px;

      --side-text:      #b8b8b8;
      --side-text-hi:   #ffffff;
      --side-muted:     #6a6a6a;
      --side-active-bg: rgba(240,192,64,0.10);
      --side-hover-bg:  rgba(255,255,255,0.05);
      --side-divider:   rgba(255,255,255,0.06);
    }

    html { scroll-behavior: smooth; }
    body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text-primary); min-height: 100vh; display: flex; flex-direction: column; }

    .overlay { display: none; position: fixed; inset: 0; background: rgba(20,20,20,0.35); z-index: 200; backdrop-filter: blur(2px); }
    .overlay.active { display: block; }

    /* ══════════ HEADER ══════════ */
    .site-header {
      position: sticky; top: 0; z-index: 300;
      background: #fff; border-bottom: 3px solid var(--gold);
      box-shadow: 0 2px 10px rgba(0,0,0,0.04);
      display: flex; align-items: center; gap: 1rem;
      padding: 0 1.6rem; height: var(--header-h); flex-shrink: 0;
    }
    .hamburger {
      display: none; background: #f3f3ef; border: 1px solid var(--border); cursor: pointer; color: var(--ink);
      font-size: 14px; width: 36px; height: 36px; border-radius: 8px; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .hamburger:hover { background: var(--gold); }
    .school-logo { width: 36px; height: 36px; border-radius: 8px; background: var(--gold); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .school-logo i { color: var(--ink); font-size: 16px; }
    .school-name-wrap { flex: 1; min-width: 0; }
    .school-name { font-family: 'Bebas Neue', sans-serif; font-size: 1.4rem; letter-spacing: 0.12em; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1; }
    .school-name span { color: var(--gold-dim); }
    .school-motto { font-size: 10px; color: var(--text-tertiary); font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; margin-top: 2px; }
    .header-tag { font-size: 11px; color: var(--text-tertiary); letter-spacing: 0.05em; display: flex; align-items: center; gap: 6px; }
    .header-tag i { color: var(--gold-dim); font-size: 10px; }

    /* ══════════ LAYOUT + SIDEBAR ══════════ */
    .layout { display: flex; flex: 1; }
    .sidebar {
      width: var(--sidebar-w); background: linear-gradient(180deg, #000 0%, #0a0a0a 100%);
      border-right: 1px solid var(--side-divider); display: flex; flex-direction: column; flex-shrink: 0;
      transition: transform 0.3s cubic-bezier(0.4,0,0.2,1); position: relative; overflow: hidden;
    }
    .sidebar::before { content: ''; position: absolute; top: 0; left: 0; width: 3px; height: 100%; background: linear-gradient(to bottom, var(--gold) 0%, transparent 100%); }
    .sidebar-inner { flex: 1; overflow-y: auto; padding: 1.2rem 0; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.15) transparent; }
    .nav-label { font-size: 10px; font-weight: 600; letter-spacing: 0.12em; text-transform: uppercase; color: var(--side-muted); padding: 0.8rem 1.4rem 0.4rem; }
    .sidebar nav { display: flex; flex-direction: column; gap: 2px; padding: 0 0.75rem; }
    .sidebar nav a {
      display: flex; align-items: center; gap: 11px; padding: 9px 13px; border-radius: var(--radius-md);
      font-size: 13.5px; font-weight: 400; color: var(--side-text); text-decoration: none;
      border-left: 3px solid transparent; transition: all 0.18s;
    }
    .sidebar nav a i { width: 17px; text-align: center; font-size: 13px; color: var(--side-text); transition: color 0.15s; flex-shrink: 0; }
    .sidebar nav a:hover { background: var(--side-hover-bg); color: var(--side-text-hi); border-left-color: var(--gold); }
    .sidebar nav a:hover i { color: var(--gold); }
    .sidebar nav a.active { background: var(--side-active-bg); color: var(--gold); font-weight: 600; border-left-color: var(--gold); }
    .sidebar nav a.active i { color: var(--gold); }
    .sidebar-footer { padding: 1rem 1.4rem; border-top: 1px solid var(--side-divider); font-size: 11px; color: var(--side-muted); letter-spacing: 0.04em; }

    /* ══════════ MAIN ══════════ */
    .main { flex: 1; min-width: 0; padding: 2rem; display: flex; flex-direction: column; gap: 1.5rem; }

    .page-title-bar { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
    .page-eyebrow { font-size: 11px; font-weight: 600; letter-spacing: 0.1em; text-transform: uppercase; color: var(--teal); margin-bottom: 4px; }
    .page-title { font-family: 'DM Serif Display', serif; font-size: 24px; font-weight: 400; line-height: 1.2; }

    .btn-back {
      display: inline-flex; align-items: center; gap: 8px; background: #fff; color: var(--ink);
      font-family: 'DM Sans', sans-serif; font-size: 13.5px; font-weight: 500; border-radius: var(--radius-md);
      padding: 9px 18px; text-decoration: none; white-space: nowrap; border: 2px solid var(--gold);
      transition: background 0.15s;
    }
    .btn-back:hover { background: var(--gold); }
    .btn-back i { color: var(--gold-dim); }
    .btn-back:hover i { color: var(--ink); }

    /* Info card */
    .info-card {
      display: flex; gap: 14px; align-items: flex-start;
      background: var(--gold-soft); border: 1px solid var(--gold-line); border-radius: var(--radius-lg);
      padding: 1rem 1.3rem; font-size: 13.5px; color: #5a4608; line-height: 1.55;
    }
    .info-card > i { color: var(--gold-dim); font-size: 18px; margin-top: 2px; }
    .info-card ul { margin: 6px 0 0 18px; }
    .info-card li { margin-bottom: 2px; }
    .info-card strong { color: var(--ink); }

    /* Filter card */
    .filter-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); padding: 1.25rem 1.5rem; display: flex; align-items: flex-end; gap: 1rem; flex-wrap: wrap; }
    .field-group { display: flex; flex-direction: column; gap: 5px; flex: 1; min-width: 180px; }
    .field-label { font-size: 12px; font-weight: 500; color: var(--text-secondary); }
    .field-select {
      font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--text-primary);
      background: var(--bg-input); border: 1px solid rgba(0,0,0,0.12); border-radius: var(--radius-md);
      padding: 9px 32px 9px 13px; outline: none; width: 100%; appearance: none; -webkit-appearance: none; cursor: pointer;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8' fill='none'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23888780' stroke-width='1.5' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat; background-position: right 12px center;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .field-select:focus { border-color: var(--teal); background-color: #fff; box-shadow: 0 0 0 3px rgba(29,158,117,0.10); }
    .btn-filter {
      font-family: 'DM Sans', sans-serif; font-size: 13.5px; font-weight: 600; color: var(--ink); background: var(--gold);
      border: none; border-radius: var(--radius-md); padding: 9px 20px; cursor: pointer; display: flex; align-items: center;
      gap: 7px; white-space: nowrap; height: 40px; transition: background 0.15s;
    }
    .btn-filter:hover { background: #e2b22f; }

    .route-pill { display: inline-flex; align-items: center; gap: 10px; font-weight: 600; }
    .grade-chip { font-size: 11.5px; font-weight: 600; padding: 2px 10px; border-radius: 100px; background: #f1f1ed; color: var(--text-secondary); white-space: nowrap; }
    .grade-chip.to { background: var(--teal-light); color: var(--teal-dark); }
    .route-pill i { color: var(--gold-dim); font-size: 12px; }

    /* Notice (grade 9 / empty) */
    .notice { background: #fff; border: 1px solid var(--border); border-left: 4px solid var(--gold); border-radius: var(--radius-lg); padding: 1.1rem 1.3rem; font-size: 14px; color: var(--text-secondary); line-height: 1.6; }
    .notice strong { color: var(--ink); }

    /* Results */
    .results-section { display: flex; flex-direction: column; gap: 1rem; }
    .results-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.75rem; }
    .results-title { font-size: 14px; font-weight: 500; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .count-badge { font-size: 11px; font-weight: 600; background: var(--gold-soft); color: #7a5c0a; border: 1px solid var(--gold-line); border-radius: 100px; padding: 2px 10px; letter-spacing: 0.04em; }
    .target-note { font-size: 12px; color: var(--text-tertiary); }

    .btn-promote {
      display: inline-flex; align-items: center; gap: 8px; font-family: 'DM Sans', sans-serif; font-size: 13.5px; font-weight: 700;
      color: #fff; background: var(--teal); border: none; border-radius: var(--radius-md); padding: 10px 18px; cursor: pointer;
      white-space: nowrap; box-shadow: 0 4px 14px rgba(29,158,117,0.28); transition: background 0.15s, transform 0.1s;
    }
    .btn-promote:hover:not(:disabled) { background: var(--teal-dark); }
    .btn-promote:active:not(:disabled) { transform: scale(0.98); }
    .btn-promote:disabled { opacity: 0.45; cursor: not-allowed; box-shadow: none; }

    .table-wrap { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
    .toolbar { display: flex; align-items: center; gap: 10px; padding: 0.85rem 1.4rem; background: #fafaf8; border-bottom: 1px solid var(--border); flex-wrap: wrap; }
    .search-wrap { flex: 1; min-width: 220px; position: relative; display: flex; align-items: center; }
    .search-wrap > i.fa-magnifying-glass { position: absolute; left: 13px; font-size: 12px; color: var(--text-tertiary); pointer-events: none; }
    .search-wrap input {
      width: 100%; padding: 9px 36px 9px 34px; font-family: 'DM Sans', sans-serif; font-size: 13.5px; color: var(--text-primary);
      background: #fff; border: 1px solid rgba(0,0,0,0.12); border-radius: var(--radius-md); outline: none;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .search-wrap input:focus { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(240,192,64,0.15); }
    #searchClear { position: absolute; right: 8px; width: 22px; height: 22px; border: none; background: transparent; color: var(--text-tertiary); cursor: pointer; border-radius: 50%; display: none; align-items: center; justify-content: center; font-size: 11px; }
    #searchClear.visible { display: flex; }
    .tool-btn {
      font-family: 'DM Sans', sans-serif; font-size: 12.5px; font-weight: 600; color: var(--text-secondary); background: #fff;
      border: 1px solid rgba(0,0,0,0.15); border-radius: 7px; padding: 7px 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
    }
    .tool-btn:hover { border-color: rgba(0,0,0,0.35); color: var(--ink); }
    .sel-count { font-size: 12.5px; color: var(--text-secondary); margin-left: auto; white-space: nowrap; }
    .sel-count strong { color: var(--ink); }

    .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    table.results-table { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 640px; }
    table.results-table thead tr.banner-row th {
      background: var(--gold-soft); color: #6b5410; font-family: 'Bebas Neue', sans-serif; font-size: 1.1rem; letter-spacing: 0.14em;
      text-align: center; padding: 0.85rem; border-bottom: 2px solid var(--gold);
    }
    table.results-table thead tr.col-row th {
      background: #fafaf8; padding: 10px 16px; text-align: left; font-size: 11px; font-weight: 600; letter-spacing: 0.07em;
      text-transform: uppercase; color: var(--text-tertiary); white-space: nowrap; border-bottom: 1px solid rgba(0,0,0,0.07);
    }
    table.results-table thead tr.col-row th.th-c { text-align: center; width: 48px; }
    table.results-table td { padding: 11px 16px; border-bottom: 1px solid rgba(0,0,0,0.05); vertical-align: middle; }
    table.results-table td.c { text-align: center; color: var(--text-tertiary); font-size: 12px; font-weight: 500; }
    table.results-table tbody tr:nth-child(even) { background: rgba(0,0,0,0.012); }
    table.results-table tbody tr:last-child td { border-bottom: none; }
    table.results-table tbody tr:hover { background: #fbf8ea; }
    table.results-table tbody tr.row-no-match { display: none; }
    table.results-table tbody tr.stay td { opacity: 0.5; }
    .pick, #pickAll { width: 16px; height: 16px; accent-color: var(--teal); cursor: pointer; }
    .mono { font-family: 'DM Mono', 'Courier New', monospace; font-size: 12.5px; color: var(--text-secondary); }
    .avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--gold-soft); border: 1px solid var(--gold-line); display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: #7a5c0a; flex-shrink: 0; }
    .name-with-avatar { display: flex; align-items: center; gap: 9px; }
    .name-with-avatar strong { font-weight: 500; }
    .stay-tag { font-size: 11.5px; font-weight: 600; color: var(--text-tertiary); }

    .empty-state { padding: 3rem 1.5rem; text-align: center; }
    .empty-state i { font-size: 32px; margin-bottom: 0.75rem; color: var(--text-tertiary); opacity: 0.4; }
    .empty-state p { font-size: 14px; color: var(--text-secondary); margin-bottom: 4px; }
    .empty-state small { font-size: 12px; color: var(--text-tertiary); }

    /* Recent promotions */
    .recent-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-lg); overflow: hidden; }
    .recent-head { padding: 0.9rem 1.4rem; font-weight: 600; font-size: 14px; border-bottom: 1px solid var(--border); background: #fafaf8; display: flex; align-items: center; gap: 8px; }
    .recent-head i { color: var(--gold-dim); }
    .recent-row { display: flex; align-items: center; gap: 14px; padding: 0.85rem 1.4rem; border-bottom: 1px solid rgba(0,0,0,0.05); flex-wrap: wrap; }
    .recent-row:last-child { border-bottom: none; }
    .recent-main { flex: 1; min-width: 200px; font-size: 13.5px; }
    .recent-main small { display: block; font-size: 12px; color: var(--text-tertiary); margin-top: 2px; }
    .btn-undo { font-family: 'DM Sans', sans-serif; font-size: 12.5px; font-weight: 600; color: #9b1c1c; background: #fff5f5; border: 1px solid #f0c4c4; border-radius: 7px; padding: 6px 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
    .btn-undo:hover:not(:disabled) { background: var(--danger); color: #fff; border-color: var(--danger); }
    .btn-undo:disabled { opacity: 0.5; cursor: not-allowed; }
    .undone-tag { font-size: 12px; color: var(--text-tertiary); font-weight: 600; }

    .site-footer { background: #fff; border-top: 2px solid var(--gold); padding: 1rem 1.5rem; text-align: center; font-size: 11px; color: #a3a29c; letter-spacing: 0.04em; }

    /* Modal */
    .modal-overlay { display: none; position: fixed; inset: 0; z-index: 500; background: rgba(20,20,20,0.40); backdrop-filter: blur(2px); align-items: center; justify-content: center; padding: 1.2rem; }
    .modal-overlay.active { display: flex; }
    .modal { background: #fff; border-radius: 14px; width: 100%; max-width: 560px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 24px 60px rgba(0,0,0,0.25); animation: fadeUp 0.25s ease both; }
    .modal-head { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.3rem; border-bottom: 3px solid var(--gold); background: var(--gold-soft); font-weight: 600; font-size: 15px; }
    .modal-head i { color: var(--gold-dim); margin-right: 8px; }
    .modal-x { width: 30px; height: 30px; border: none; border-radius: 7px; background: transparent; color: var(--text-secondary); font-size: 15px; cursor: pointer; display: flex; align-items: center; justify-content: center; }
    .modal-x:hover { background: rgba(0,0,0,0.06); color: var(--ink); }
    .modal-body { padding: 1.2rem 1.3rem; overflow-y: auto; font-size: 13.5px; color: var(--text-secondary); line-height: 1.55; }
    .modal-body strong { color: var(--ink); }
    .modal-list { list-style: none; margin: 12px 0; border: 1px solid var(--border); border-radius: 10px; max-height: 210px; overflow-y: auto; }
    .modal-list li { padding: 7px 12px; border-bottom: 1px solid rgba(0,0,0,0.05); color: var(--ink); font-weight: 500; }
    .modal-list li:last-child { border-bottom: none; }
    .modal-list li small { color: var(--text-tertiary); font-weight: 400; margin-left: 6px; }
    .confirm-row { display: flex; align-items: flex-start; gap: 9px; margin-top: 12px; font-weight: 600; color: var(--ink); cursor: pointer; }
    .confirm-row input { width: 16px; height: 16px; margin-top: 2px; accent-color: var(--teal); }
    .result-ok  { display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 10px; background: #ecfaf5; border: 1px solid #a7e3cf; color: #0f6b4f; font-weight: 600; margin-bottom: 10px; }
    .result-warn{ padding: 12px 14px; border-radius: 10px; background: #fffbea; border: 1px solid #f0d98a; color: #7a5c00; margin-top: 10px; }
    .result-err { padding: 12px 14px; border-radius: 10px; background: #fff5f5; border: 1px solid #e9a4a4; color: #9b1c1c; font-weight: 600; }
    .result-warn ul { margin: 6px 0 0 18px; }
    .modal-foot { display: flex; justify-content: flex-end; gap: 10px; padding: 0.95rem 1.3rem; border-top: 1px solid var(--border); background: #fafaf8; flex-wrap: wrap; }
    .btn { font-family: 'DM Sans', sans-serif; font-size: 13.5px; font-weight: 600; padding: 9px 20px; border-radius: var(--radius-md); border: 1px solid transparent; cursor: pointer; display: inline-flex; align-items: center; gap: 7px; }
    .btn-ghost { background: #fff; color: var(--text-secondary); border-color: rgba(0,0,0,0.15); }
    .btn-ghost:hover { border-color: rgba(0,0,0,0.3); }
    .btn-go { background: var(--teal); color: #fff; }
    .btn-go:hover:not(:disabled) { background: var(--teal-dark); }
    .btn:disabled { opacity: 0.5; cursor: not-allowed; }

    .toast {
      position: fixed; left: 50%; bottom: 26px; transform: translate(-50%, 20px); background: #fff; color: var(--ink);
      border: 1px solid var(--gold-line); border-left: 4px solid var(--teal); box-shadow: 0 10px 30px rgba(0,0,0,0.15);
      border-radius: 10px; padding: 11px 18px; font-size: 13.5px; font-weight: 500; opacity: 0; pointer-events: none;
      transition: opacity 0.25s, transform 0.25s; z-index: 600; max-width: 90vw;
    }
    .toast.show { opacity: 1; transform: translate(-50%, 0); }
    .toast.warn { border-left-color: #d97706; }
    .toast i { color: var(--teal); margin-right: 8px; }
    .toast.warn i { color: #d97706; }

    @keyframes fadeUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }
    .filter-card, .table-wrap, .recent-card, .info-card { animation: fadeUp 0.4s 0.05s ease both; }

    @media (max-width: 900px) {
      .sidebar { position: fixed; top: var(--header-h); left: 0; bottom: 0; z-index: 250; transform: translateX(-100%); box-shadow: 6px 0 30px rgba(0,0,0,0.35); }
      .sidebar.open { transform: translateX(0); }
      .hamburger { display: flex; }
      .header-tag { display: none; }
      .main { padding: 1.25rem 1rem 3rem; }
      .page-title { font-size: 20px; }
      .filter-card { padding: 1rem; }
      .btn-filter { width: 100%; justify-content: center; }
    }
    @media (max-width: 560px) {
      .page-title-bar { flex-direction: column; align-items: flex-start; }
      .school-name { font-size: 1.1rem; }
      .toolbar { padding: 0.7rem 0.9rem; }
      .sel-count { width: 100%; margin-left: 0; }
      .btn-promote { width: 100%; justify-content: center; }
      .modal-overlay { padding: 0; align-items: flex-end; }
      .modal { max-width: 100%; max-height: 94vh; border-radius: 14px 14px 0 0; }
      .modal-foot .btn { flex: 1; justify-content: center; }
    }
  </style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ════════════ HEADER ════════════ -->
<header class="site-header">
  <button class="hamburger" id="hamburger" aria-label="Open menu">
    <i class="fa-solid fa-bars"></i>
  </button>
  <div class="school-logo"><i class="fa-solid fa-graduation-cap"></i></div>
  <div class="school-name-wrap">
    <div class="school-name">Stephen Kanja <span>School</span></div>
    <div class="school-motto">Aim Higher</div>
  </div>
  <div class="header-tag"><i class="fa-solid fa-circle-dot"></i> Student Portal</div>
</header>

<!-- ════════════ LAYOUT ════════════ -->
<div class="layout">

  <aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
      <p class="nav-label">Main</p>
      <nav>
        <a href="teacherpanel.php"><i class="fa-solid fa-house"></i> Home</a>
        <a href="Students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="Progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
      </nav>

      <p class="nav-label">Administration</p>
      <nav>
        <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
        <a href="PromoteLearners.php" class="active"><i class="fa-solid fa-arrow-up-right-dots"></i> Promote Learners</a>
        <a href="RegisterTeacher.php"><i class="fa-solid fa-user-plus"></i> Register Teachers</a>
      </nav>

      <p class="nav-label">Academics</p>
      <nav>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
      </nav>

      <p class="nav-label">Finance</p>
      <nav>
        <a href="Fee.php"><i class="fa-solid fa-coins"></i> Finances</a>
      </nav>
    </div>
    <div class="sidebar-footer">© 2026 Kelvin Mutinda</div>
  </aside>

  <main class="main">

    <div class="page-title-bar">
      <div>
        <p class="page-eyebrow">Enrollment Portal</p>
        <h1 class="page-title">Promote Learners</h1>
      </div>
      <a href="Students.php<?= $from !== null ? '?grade=' . (int)$from : '' ?>" class="btn-back">
        <i class="fa-solid fa-arrow-left"></i> Back to Learners
      </a>
    </div>

    <div class="info-card">
      <i class="fa-solid fa-circle-info"></i>
      <div>
        <strong>Move learners up to the next class.</strong>
        <ul>
          <li>Choose the grade the learners are in <em>now</em>. Everyone is ticked — <strong>untick any learner who is repeating</strong> so they stay put.</li>
          <li>Marks already saved for earlier exams stay in the grade they were sat in.</li>
          <li>Promote from the <strong>highest grade down</strong> (8 → 9 first, then 7 → 8 …) so each class has room.</li>
          <li>Grade 9 is the last grade, so Grade 9 learners are not promoted here.</li>
        </ul>
      </div>
    </div>

    <?php if (!$canPromote): ?>
    <div class="notice"><strong>Access restricted.</strong> You do not have permission to promote learners.</div>
    <?php else: ?>

    <!-- Filter card -->
    <div class="filter-card">
      <form method="GET" action="" style="display:contents;">
        <div class="field-group">
          <label class="field-label" for="grade">Promote learners currently in</label>
          <select class="field-select" name="grade" id="grade" required>
            <option value="" disabled <?= $from === null ? 'selected' : '' ?>>— Select a grade —</option>
            <?php for ($g = 1; $g <= 8; $g++): ?>
              <option value="<?= $g ?>" <?= ($from === $g) ? 'selected' : '' ?>>Grade <?= $g ?> → Grade <?= $g + 1 ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <button class="btn-filter" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Load Learners</button>
      </form>
    </div>

    <?php if ($isLast): ?>
    <div class="notice">
      <strong>Grade 9 is the last grade.</strong> There is no next class to move these learners into, so they are not promoted here.
      Choose a grade from 1 to 8 above.
    </div>

    <?php elseif ($from !== null): ?>
    <div class="results-section">
      <div class="results-header">
        <p class="results-title">
          <span class="route-pill">
            <span class="grade-chip">Grade <?= (int)$from ?></span>
            <i class="fa-solid fa-arrow-right"></i>
            <span class="grade-chip to">Grade <?= (int)$to ?></span>
          </span>
          <span class="count-badge" id="countBadge"><?= $total ?> learner<?= $total === 1 ? '' : 's' ?></span>
          <span class="target-note">Grade <?= (int)$to ?> currently has <?= (int)$targetCount ?> learner<?= $targetCount === 1 ? '' : 's' ?></span>
        </p>
        <button type="button" class="btn-promote" id="promoteBtn" disabled>
          <i class="fa-solid fa-arrow-up-right-dots"></i>
          <span id="promoteBtnLabel">Promote to Grade <?= (int)$to ?></span>
        </button>
      </div>

      <div class="table-wrap">
        <div class="toolbar">
          <div class="search-wrap">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" id="search" placeholder="Search by name or assessment no…" autocomplete="off">
            <button type="button" id="searchClear" title="Clear search" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <button type="button" class="tool-btn" id="selAll"><i class="fa-solid fa-check-double"></i> Select all shown</button>
          <button type="button" class="tool-btn" id="selNone"><i class="fa-solid fa-xmark"></i> Clear all</button>
          <span class="sel-count"><strong id="selCount">0</strong> of <?= $total ?> ticked to promote</span>
        </div>

        <div class="table-scroll">
          <table class="results-table">
            <thead>
              <tr class="banner-row"><th colspan="6">Grade <?= (int)$from ?> — Learners to Promote</th></tr>
              <tr class="col-row">
                <th class="th-c"><input type="checkbox" id="pickAll" title="Tick / untick all shown"></th>
                <th class="th-c">#</th>
                <th>Assessment No</th>
                <th>Full Name</th>
                <th>Now</th>
                <th>Will become</th>
              </tr>
            </thead>
            <tbody id="tbody">
              <?php if ($loadError !== ''): ?>
              <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i><p>Could not load learners</p><small><?= h($loadError) ?></small></div></td></tr>
              <?php elseif ($total === 0): ?>
              <tr><td colspan="6"><div class="empty-state"><i class="fa-solid fa-user-slash"></i><p>No learners in Grade <?= (int)$from ?></p><small>Pick another grade above.</small></div></td></tr>
              <?php else: ?>
              <?php foreach ($rows as $i => $row):
                $full = preg_replace('/\s+/', ' ', trim($row['firstName'] . ' ' . $row['middleName'] . ' ' . $row['surname']));
                $initials = strtoupper(mb_substr((string)$row['firstName'], 0, 1) . mb_substr((string)$row['surname'], 0, 1));
              ?>
              <tr data-id="<?= h($row['id']) ?>" data-name="<?= h($full) ?>" data-assess="<?= h($row['Assesment']) ?>"
                  data-search="<?= h(strtolower($full . ' ' . $row['Assesment'])) ?>">
                <td class="c"><input type="checkbox" class="pick" checked aria-label="Promote <?= h($full) ?>"></td>
                <td class="c num"><?= $i + 1 ?></td>
                <td><span class="mono"><?= h($row['Assesment']) ?></span></td>
                <td><div class="name-with-avatar"><div class="avatar"><?= h($initials) ?></div><strong><?= h($full) ?></strong></div></td>
                <td><span class="grade-chip">Grade <?= (int)$from ?></span></td>
                <td class="will"><span class="grade-chip to">Grade <?= (int)$to ?></span></td>
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
        <p>Select a grade above to see the learners</p>
        <small>You will be able to untick anyone who is repeating before promoting.</small>
      </div>
    </div>
    <?php endif; ?>

    <!-- Recent promotions -->
    <?php if ($logOk): ?>
    <div class="recent-card">
      <div class="recent-head"><i class="fa-solid fa-clock-rotate-left"></i> Recent promotions</div>
      <?php if (!$recent): ?>
        <div class="recent-row"><div class="recent-main" style="color:var(--text-tertiary)">No promotions have been made yet.</div></div>
      <?php else: foreach ($recent as $b):
        $active = (int)$b['active'];
        $when = date('d M Y, H:i', strtotime((string)$b['ts']));
      ?>
        <div class="recent-row">
          <div class="recent-main">
            <strong>Grade <?= (int)$b['from_grade'] ?> → Grade <?= (int)$b['to_grade'] ?></strong> · <?= (int)$b['total'] ?> learner<?= (int)$b['total'] === 1 ? '' : 's' ?>
            <small><?= h($when) ?><?= $active < (int)$b['total'] ? ' · ' . ((int)$b['total'] - $active) . ' undone' : '' ?></small>
          </div>
          <?php if ($active > 0): ?>
            <button type="button" class="btn-undo" data-batch="<?= h($b['batch_id']) ?>" data-label="Grade <?= (int)$b['from_grade'] ?> → Grade <?= (int)$b['to_grade'] ?>" data-n="<?= $active ?>">
              <i class="fa-solid fa-rotate-left"></i> Undo<?= $active > 1 ? ' (' . $active . ')' : '' ?>
            </button>
          <?php else: ?>
            <span class="undone-tag"><i class="fa-solid fa-check"></i> Undone</span>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
    <?php endif; ?>

    <?php endif; /* canPromote */ ?>

  </main>
</div>

<footer class="site-footer">&copy; Designed by Kelvin Mutinda 2026. All rights reserved.</footer>

<!-- ════════════ CONFIRM / RESULT MODAL ════════════ -->
<div class="modal-overlay" id="modal">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-head">
      <span id="modalTitle"><i class="fa-solid fa-arrow-up-right-dots"></i>Promote learners?</span>
      <button type="button" class="modal-x" id="modalX" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-foot" id="modalFoot"></div>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
  /* ══════════ Sidebar ══════════ */
  const hamburger = document.getElementById('hamburger');
  const sidebar   = document.getElementById('sidebar');
  const overlay   = document.getElementById('overlay');
  function openSidebar()  { sidebar.classList.add('open'); overlay.classList.add('active'); hamburger.innerHTML = '<i class="fa-solid fa-xmark"></i>'; document.body.style.overflow = 'hidden'; }
  function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('active'); hamburger.innerHTML = '<i class="fa-solid fa-bars"></i>'; document.body.style.overflow = ''; }
  hamburger.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());

  /* ══════════ State ══════════ */
  const CSRF = <?= json_encode($csrf) ?>;
  const FROM = <?= json_encode($from) ?>;
  const TO   = <?= json_encode($to) ?>;
  const IS_LAST = <?= json_encode($isLast) ?>;

  const tbody      = document.getElementById('tbody');
  const promoteBtn = document.getElementById('promoteBtn');
  const selCountEl = document.getElementById('selCount');
  const pickAll    = document.getElementById('pickAll');
  const searchEl   = document.getElementById('search');
  const clearEl    = document.getElementById('searchClear');
  const toastEl    = document.getElementById('toast');
  const modal      = document.getElementById('modal');
  const modalBody  = document.getElementById('modalBody');
  const modalFoot  = document.getElementById('modalFoot');
  const modalTitle = document.getElementById('modalTitle');

  const dataRows = () => tbody ? Array.from(tbody.querySelectorAll('tr[data-id]')) : [];
  const shown    = () => dataRows().filter(r => !r.classList.contains('row-no-match'));
  const ticked   = () => dataRows().filter(r => r.querySelector('.pick').checked);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  function showToast(text, warn) {
    toastEl.className = 'toast show' + (warn ? ' warn' : '');
    toastEl.innerHTML = '<i class="fa-solid ' + (warn ? 'fa-triangle-exclamation' : 'fa-circle-check') + '"></i>' + esc(text);
    clearTimeout(showToast._t);
    showToast._t = setTimeout(() => toastEl.classList.remove('show'), 3400);
  }

  function refresh() {
    const rows = dataRows();
    const on = ticked().length;
    if (selCountEl) selCountEl.textContent = on;
    if (promoteBtn) {
      promoteBtn.disabled = (on === 0);
      document.getElementById('promoteBtnLabel').textContent =
        on === 0 ? 'Promote to Grade ' + TO : 'Promote ' + on + ' learner' + (on === 1 ? '' : 's') + ' to Grade ' + TO;
    }
    rows.forEach(r => {
      const will = r.querySelector('.will');
      const isOn = r.querySelector('.pick').checked;
      r.classList.toggle('stay', !isOn);
      will.innerHTML = isOn
        ? '<span class="grade-chip to">Grade ' + TO + '</span>'
        : '<span class="stay-tag">Stays in Grade ' + FROM + '</span>';
    });
    const vis = shown();
    if (pickAll) pickAll.checked = vis.length > 0 && vis.every(r => r.querySelector('.pick').checked);
  }

  if (tbody) {
    tbody.addEventListener('change', e => { if (e.target.classList.contains('pick')) refresh(); });

    pickAll.addEventListener('change', () => { shown().forEach(r => r.querySelector('.pick').checked = pickAll.checked); refresh(); });
    document.getElementById('selAll').addEventListener('click',  () => { shown().forEach(r => r.querySelector('.pick').checked = true); refresh(); });
    document.getElementById('selNone').addEventListener('click', () => { dataRows().forEach(r => r.querySelector('.pick').checked = false); refresh(); });

    searchEl.addEventListener('input', () => {
      const term = searchEl.value.trim().toLowerCase();
      clearEl.classList.toggle('visible', term.length > 0);
      dataRows().forEach(r => r.classList.toggle('row-no-match', term !== '' && !(r.dataset.search || '').includes(term)));
      let n = 1; shown().forEach(r => { r.querySelector('.num').textContent = n++; });
      refresh();
    });
    clearEl.addEventListener('click', () => { searchEl.value = ''; searchEl.dispatchEvent(new Event('input')); searchEl.focus(); });
    refresh();
  }

  /* ══════════ Modal helpers ══════════ */
  function openModal(title, bodyHtml, footHtml) {
    modalTitle.innerHTML = title;
    modalBody.innerHTML = bodyHtml;
    modalFoot.innerHTML = footHtml;
    modal.classList.add('active');
  }
  function closeModal() { modal.classList.remove('active'); }
  document.getElementById('modalX').addEventListener('click', closeModal);
  modal.addEventListener('mousedown', e => { if (e.target === modal) closeModal(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeModal(); closeSidebar(); } });

  async function postJson(action, payload) {
    const res = await fetch(location.pathname + '?ajax_action=' + action, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ csrf: CSRF }, payload))
    });
    let data = null;
    try { data = await res.json(); } catch (_) {}
    return data;
  }

  /* ══════════ Promote: confirm → run → result ══════════ */
  if (promoteBtn) {
    promoteBtn.addEventListener('click', () => {
      const rows = ticked();
      if (!rows.length) return;
      const staying = dataRows().length - rows.length;

      const list = rows.map(r => '<li>' + esc(r.dataset.name) + '<small>' + esc(r.dataset.assess) + '</small></li>').join('');
      const body =
        '<p>You are about to move <strong>' + rows.length + ' learner' + (rows.length === 1 ? '' : 's') + '</strong> from ' +
        '<strong>Grade ' + FROM + '</strong> to <strong>Grade ' + TO + '</strong>.' +
        (staying > 0 ? ' <strong>' + staying + '</strong> unticked learner' + (staying === 1 ? '' : 's') + ' will stay in Grade ' + FROM + '.' : '') + '</p>' +
        '<ul class="modal-list">' + list + '</ul>' +
        '<p>Saved marks stay with the grade they were sat in. You can undo this from "Recent promotions" afterwards.</p>' +
        '<label class="confirm-row"><input type="checkbox" id="confirmChk"> I have checked the list and want to promote these learners.</label>';
      const foot =
        '<button type="button" class="btn btn-ghost" id="cancelBtn">Cancel</button>' +
        '<button type="button" class="btn btn-go" id="goBtn" disabled><i class="fa-solid fa-arrow-up-right-dots"></i> Promote now</button>';
      openModal('<i class="fa-solid fa-arrow-up-right-dots"></i>Promote to Grade ' + TO + '?', body, foot);

      const chk = document.getElementById('confirmChk');
      const go  = document.getElementById('goBtn');
      chk.addEventListener('change', () => { go.disabled = !chk.checked; });
      document.getElementById('cancelBtn').addEventListener('click', closeModal);

      go.addEventListener('click', async () => {
        go.disabled = true;
        go.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Promoting…';
        let data = null;
        try { data = await postJson('promote', { fromGrade: FROM, ids: rows.map(r => r.dataset.id) }); }
        catch (err) { data = { success: false, error: 'Network error: ' + err.message }; }

        let html = '', title = '<i class="fa-solid fa-circle-check"></i>Promotion complete';
        if (!data || !data.success) {
          title = '<i class="fa-solid fa-triangle-exclamation"></i>Could not promote';
          html = '<div class="result-err">' + esc((data && data.error) ? data.error : 'Something went wrong. Nothing was changed.') + '</div>';
        } else {
          html = '<div class="result-ok"><i class="fa-solid fa-circle-check"></i><span>' + data.moved + ' learner' + (data.moved === 1 ? '' : 's') +
                 ' moved from Grade ' + data.from + ' to Grade ' + data.to + '.</span></div>';
          if (data.skipped && data.skipped.length) {
            html += '<div class="result-warn"><strong>' + data.skipped.length + ' learner' + (data.skipped.length === 1 ? ' was' : 's were') +
                    ' not moved:</strong><ul>' + data.skipped.map(s => '<li>' + esc(s.name) + ' — ' + esc(s.reason) + '</li>').join('') +
                    '</ul><p style="margin-top:6px">Fix the Assessment No on the Students page (Edit), then promote them again.</p></div>';
          }
          if (data.notInGrade > 0) {
            html += '<div class="result-warn">' + data.notInGrade + ' selected learner' + (data.notInGrade === 1 ? ' was' : 's were') + ' no longer in Grade ' + data.from + ' and ' + (data.notInGrade === 1 ? 'was' : 'were') + ' skipped.</div>';
          }
        }
        modalTitle.innerHTML = title;
        modalBody.innerHTML = html;
        modalFoot.innerHTML = '<button type="button" class="btn btn-go" id="doneBtn"><i class="fa-solid fa-check"></i> Done</button>';
        document.getElementById('doneBtn').addEventListener('click', () => { location.href = location.pathname + '?grade=' + FROM; });
        modal.onmousedown = null;
      });
    });
  }

  /* ══════════ Undo a promotion batch ══════════ */
  document.querySelectorAll('.btn-undo').forEach(btn => {
    btn.addEventListener('click', async () => {
      const label = btn.dataset.label, n = btn.dataset.n;
      if (!confirm('Undo the promotion ' + label + '?\n\n' + n + ' learner(s) will be moved back to their previous grade (only those still in the new grade).')) return;

      btn.disabled = true;
      const original = btn.innerHTML;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Undoing…';
      let data = null;
      try { data = await postJson('undo', { batch: btn.dataset.batch }); }
      catch (err) { data = { success: false, error: 'Network error: ' + err.message }; }

      if (!data || !data.success) {
        btn.disabled = false; btn.innerHTML = original;
        showToast((data && data.error) ? data.error : 'Could not undo that promotion.', true);
        return;
      }
      if (data.skipped && data.skipped.length) {
        openModal('<i class="fa-solid fa-rotate-left"></i>Undo finished',
          '<div class="result-ok"><i class="fa-solid fa-circle-check"></i><span>' + data.restored + ' learner' + (data.restored === 1 ? '' : 's') + ' moved back.</span></div>' +
          '<div class="result-warn"><strong>' + data.skipped.length + ' not moved back:</strong><ul>' +
          data.skipped.map(s => '<li>' + esc(s.name) + ' — ' + esc(s.reason) + '</li>').join('') + '</ul></div>',
          '<button type="button" class="btn btn-go" id="doneBtn"><i class="fa-solid fa-check"></i> Done</button>');
        document.getElementById('doneBtn').addEventListener('click', () => location.reload());
      } else {
        showToast(data.restored + ' learner' + (data.restored === 1 ? '' : 's') + ' moved back');
        setTimeout(() => location.reload(), 900);
      }
    });
  });
</script>

</body>
</html>