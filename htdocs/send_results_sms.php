<?php
// ============================================================
// send_results_sms.php — preview and send exam results to parents by SMS
// ============================================================

session_start();
 require_once __DIR__ . '/page_guard.php';
include 'conn.php';
require_once 'africa_talking_sms.php';
require_once 'add-exam-type.php';

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

$isHoi     = (($_SESSION['account_type'] ?? '') === 'hoi');
$homeLink  = $isHoi ? 'Hoi.php' : 'teacherpanel.php';
$roleLabel = $isHoi ? 'Head of Institution' : 'Teacher Panel';
$roleInit  = $isHoi ? 'HI' : 'TR';

// Exam types: same list Upload Results uses (built-in + custom); fall back to the three defaults
$EXAM_TYPES = function_exists('getExamTypes') ? getExamTypes($conn) : array();
if (empty($EXAM_TYPES)) {
    $EXAM_TYPES = array(
        'opener'  => array('label' => 'Opener'),
        'midterm' => array('label' => 'Midterm'),
        'endterm' => array('label' => 'Endterm'),
    );
}

// Keep in sync with the subject columns used by upload_excel_marks.php
$subject_map = array(
    'math' => 'Mathematics', 'eng' => 'English', 'kisw' => 'Kiswahili',
    'sst' => 'Social Studies', 'scie' => 'Science', 'ca' => 'CA',
    'agri' => 'Agriculture', 're' => 'RE', 'pretec' => 'Pre-Technical',
);

$action   = isset($_POST['action']) ? $_POST['action'] : '';
$grade    = isset($_POST['grade']) ? (int)$_POST['grade'] : 0;
$termNum  = isset($_POST['term']) ? preg_replace('/[^0-9]/', '', $_POST['term']) : '';
$year     = isset($_POST['year']) ? (int)$_POST['year'] : 0;
$examType = isset($_POST['examType']) ? trim($_POST['examType']) : '';
$termLabel = $termNum !== '' ? "Term $termNum" : '';

$defaultTemplate = "Dear Parent/Guardian, {student_name}'s {exam_type} results for {term} {year}, {grade_label}: {subjects}. Total: {total} Average: {average}. - Stephen Kanja School";
$messageTemplate = (isset($_POST['messageTemplate']) && trim($_POST['messageTemplate']) !== '')
    ? $_POST['messageTemplate']
    : $defaultTemplate;

$previewRows = array();
$sendResults = array('sent' => 0, 'failed' => 0, 'skipped_no_phone' => 0, 'details' => array());

function build_subject_summary($row, $subject_map) {
    $parts = array();
    $scores = array();
    foreach ($subject_map as $code => $label) {
        if (isset($row[$code]) && $row[$code] !== null && $row[$code] !== '') {
            $parts[] = $label . ': ' . $row[$code];
            $scores[] = (float)$row[$code];
        }
    }
    $total = array_sum($scores);
    $average = count($scores) > 0 ? round($total / count($scores), 1) : 0;
    return array('summary' => implode(', ', $parts), 'total' => $total, 'average' => $average);
}

function fill_template($template, $tokens) {
    $msg = $template;
    foreach ($tokens as $key => $val) {
        $msg = str_replace('{' . $key . '}', $val, $msg);
    }
    return $msg;
}

// ── Fetch matching exam2 + Student rows (used for both preview and send) ──
if ($grade && $termLabel !== '' && $year && $examType && ($action === 'preview' || $action === 'send')) {
    $stmt = $conn->prepare("
        SELECT e.id AS exam_id, s.id AS student_id, s.firstName, s.surname, s.parentName, s.parentPhone,
               e.math, e.eng, e.kisw, e.sst, e.scie, e.ca, e.agri, e.re, e.pretec
        FROM exam2 e
        JOIN Student s ON s.id = e.student_id
        WHERE e.grade = ? AND e.term = ? AND e.year = ? AND e.exam_type = ?
        ORDER BY s.firstName, s.surname
    ");
    $stmt->bind_param('isis', $grade, $termLabel, $year, $examType);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $previewRows[] = $row;
    }
    $stmt->close();
}

// ── Actually send ──
if ($action === 'send' && !empty($previewRows)) {
    $selectedIds = isset($_POST['send_student_ids']) ? array_map('intval', $_POST['send_student_ids']) : array();

    foreach ($previewRows as $row) {
        $studentId = (int)$row['student_id'];
        if (!in_array($studentId, $selectedIds, true)) continue;

        $phone = normalize_kenyan_phone($row['parentPhone']);
        $name  = trim($row['firstName'] . ' ' . $row['surname']);

        if (!$phone) {
            $sendResults['skipped_no_phone']++;
            $sendResults['details'][] = array('name' => $name, 'phone' => null, 'status' => 'skipped', 'note' => 'No valid parent phone on file');
            continue;
        }

        $summary = build_subject_summary($row, $subject_map);
        $message = fill_template($messageTemplate, array(
            'student_name' => $name,
            'exam_type'    => ucfirst($examType),
            'term'         => $termLabel,
            'year'         => $year,
            'grade_label'  => "Grade $grade",
            'subjects'     => $summary['summary'],
            'total'        => $summary['total'],
            'average'      => $summary['average'],
        ));

        $result = send_africas_talking_sms($phone, $message);
        $status = $result['success'] ? 'sent' : 'failed';

        $logStmt = $conn->prepare("INSERT INTO sms_log (student_id, phone, grade, term, year, exam_type, message, status, provider_response) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $logStmt->bind_param('isisssss', $studentId, $phone, $grade, $termLabel, $year, $examType, $message, $status, $result['response']);
        $logStmt->execute();
        $logStmt->close();

        if ($result['success']) {
            $sendResults['sent']++;
            $sendResults['details'][] = array('name' => $name, 'phone' => $phone, 'status' => 'sent', 'note' => '');
        } else {
            $sendResults['failed']++;
            $sendResults['details'][] = array('name' => $name, 'phone' => $phone, 'status' => 'failed', 'note' => $result['response']);
        }
    }
}

mysqli_close($conn);

$showResults = ($action === 'preview' || $action === 'send');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Send Results SMS — Stephen Kanja School</title>
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
      --green:#16a34a; --green-bg:#dcfce7; --green-bd:#86efac; --green-ink:#166534;
      --red:#dc2626; --red-bg:#fee2e2; --red-bd:#fca5a5; --red-ink:#991b1b;
      --amber-bg:#fef9c3; --amber-bd:#fde047; --amber-ink:#854d0e;
      --blue:#2563eb; --blue-bg:#dbeafe;
      --shadow-sm:0 1px 2px rgba(15,23,42,.05); --shadow:0 6px 24px rgba(15,23,42,.07);
      --r:14px; --r-lg:18px; --sidebar-w:264px; --topbar-h:64px;
      color-scheme: light;
    }
    [data-theme="dark"] {
      --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
      --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3; --border:#1f2d47; --border-2:#192740;
      --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040;
      --green:#4ade80; --green-bg:rgba(34,197,94,.16); --green-bd:rgba(34,197,94,.4); --green-ink:#86efac;
      --red:#f87171; --red-bg:rgba(248,113,113,.14); --red-bd:rgba(248,113,113,.4); --red-ink:#fca5a5;
      --amber-bg:rgba(251,191,36,.14); --amber-bd:rgba(251,191,36,.4); --amber-ink:#fcd34d;
      --blue:#7db3f0; --blue-bg:rgba(96,165,250,.16);
      --shadow-sm:0 1px 2px rgba(0,0,0,.4); --shadow:0 6px 24px rgba(0,0,0,.35);
      color-scheme: dark;
    }

    html { scroll-behavior:smooth; }
    body { font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14.5px; line-height:1.6; -webkit-font-smoothing:antialiased; transition:background .25s,color .25s; }
    ::-webkit-scrollbar { width:8px; height:8px; }
    ::-webkit-scrollbar-thumb { background:rgba(120,135,160,.35); border-radius:99px; }
    :focus-visible { outline:2px solid var(--gold); outline-offset:2px; border-radius:6px; }
    .skip { position:absolute; left:-999px; top:8px; background:var(--gold); color:#000; padding:8px 14px; border-radius:8px; font-weight:700; z-index:400; }
    .skip:focus { left:12px; }

    .app { display:grid; grid-template-columns:var(--sidebar-w) minmax(0,1fr); min-height:100vh; transition:grid-template-columns .25s ease; }
    body.nav-collapsed .app { grid-template-columns:0 minmax(0,1fr); }
    body.nav-collapsed .sidebar { visibility:hidden; transform:translateX(-100%); }

    /* ───────── Sidebar ───────── */
    .sidebar { background:linear-gradient(180deg,var(--navy) 0%,#07152a 100%); color:#b9c4d6; display:flex; flex-direction:column; position:sticky; top:0; height:100vh; overflow-y:auto; z-index:100; transition:transform .25s ease; }
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
    .overlay { display:none; position:fixed; inset:0; background:rgba(3,10,22,.55); backdrop-filter:blur(2px); z-index:99; }
    .overlay.active { display:block; }

    /* ───────── Main / topbar ───────── */
    .main { min-width:0; display:flex; flex-direction:column; }
    .topbar { position:sticky; top:0; z-index:60; height:var(--topbar-h); display:flex; align-items:center; justify-content:space-between; gap:12px; padding:0 32px; background:color-mix(in srgb,var(--surface) 86%,transparent); backdrop-filter:saturate(1.4) blur(12px); border-bottom:1px solid var(--border); }
    .tb-left { display:flex; align-items:center; gap:12px; min-width:0; }
    .menu-btn { background:none; border:1px solid var(--border); width:38px; height:38px; border-radius:10px; cursor:pointer; color:var(--text); display:inline-flex; align-items:center; justify-content:center; transition:.15s; }
    .menu-btn:hover { background:var(--surface-3); }
    .tb-title { font-weight:800; font-size:1.02rem; letter-spacing:-.2px; line-height:1.2; }
    .tb-title small { display:block; font-weight:500; font-size:.72rem; color:var(--muted); }
    .tb-right { display:flex; align-items:center; gap:8px; }
    .icon-btn { width:38px; height:38px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:15px; transition:.15s; text-decoration:none; }
    .icon-btn:hover { background:var(--surface-3); color:var(--text); }
    .btn-gold { display:inline-flex; align-items:center; gap:8px; background:var(--gold); color:#101826; font-weight:700; font-size:.82rem; padding:0 16px; height:38px; border-radius:10px; text-decoration:none; border:none; cursor:pointer; box-shadow:0 6px 16px rgba(240,192,64,.3); transition:.15s; font-family:inherit; }
    .btn-gold:hover { background:#f5cf5e; transform:translateY(-1px); }

    .content { padding:24px 32px 60px; width:100%; max-width:1280px; margin:0 auto; display:flex; flex-direction:column; gap:18px; }
    .page-eyebrow { font-size:.7rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold-d); }
    .page-title { font-size:clamp(1.4rem,2.4vw,1.8rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; }
    .page-sub { color:var(--muted); font-size:.86rem; margin-top:4px; max-width:720px; }

    /* ───────── Box ───────── */
    .box { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-sm); overflow:hidden; animation:fadeUp .45s ease both; }
    .box-head { padding:15px 22px; border-bottom:1px solid var(--border-2); display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .box-title { font-size:.76rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); display:flex; align-items:center; gap:9px; margin-right:auto; }
    .box-title i { color:var(--gold-d); }
    .box-body { padding:20px 22px; }
    .badge { font-size:.66rem; font-weight:800; letter-spacing:.9px; text-transform:uppercase; padding:3px 11px; border-radius:99px; background:var(--gold-pale); color:var(--gold-ink); }

    /* ───────── Filters ───────── */
    .form-grid { display:grid; grid-template-columns:repeat(4,minmax(150px,1fr)) auto; gap:16px; align-items:end; }
    .field { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .field label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .field select, .field input[type="number"] { appearance:none; width:100%; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; padding:0 14px; font-family:inherit; font-size:.88rem; font-weight:500; color:var(--text); transition:.15s; height:42px; }
    .field select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; padding-right:38px; cursor:pointer; }
    .field select:hover, .field input[type="number"]:hover { border-color:rgba(37,99,235,.35); }
    .field select:focus, .field input[type="number"]:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .btn { background:var(--gold); color:#101826; border:none; border-radius:10px; padding:0 22px; height:42px; font-family:inherit; font-size:.84rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 8px 20px rgba(240,192,64,.28); white-space:nowrap; }
    .btn:hover:not(:disabled) { background:#f5cf5e; transform:translateY(-1px); }
    .btn:active:not(:disabled) { transform:scale(.98); }
    .btn:disabled { opacity:.55; cursor:not-allowed; box-shadow:none; }

    /* ───────── Send summary ───────── */
    .stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; padding:18px 22px; }
    .stat { border-radius:var(--r); padding:14px 18px; border:1px solid var(--border); background:var(--surface-2); }
    .stat .num { font-size:1.9rem; font-weight:800; letter-spacing:-.8px; line-height:1.15; font-variant-numeric:tabular-nums; }
    .stat .lbl { font-size:.62rem; font-weight:800; letter-spacing:1.2px; text-transform:uppercase; color:var(--muted); margin-top:2px; }
    .stat.sent   { background:var(--green-bg); border-color:var(--green-bd); } .stat.sent .num { color:var(--green); } .stat.sent .lbl { color:var(--green-ink); }
    .stat.failed { background:var(--red-bg); border-color:var(--red-bd); } .stat.failed .num { color:var(--red); } .stat.failed .lbl { color:var(--red-ink); }
    .log { border-top:1px solid var(--border-2); max-height:280px; overflow-y:auto; }
    .log-row { display:flex; align-items:baseline; gap:10px; padding:9px 22px; font-size:.82rem; border-bottom:1px solid var(--border-2); color:var(--text-2); flex-wrap:wrap; }
    .log-row:last-child { border-bottom:none; }
    .log-row i { width:16px; flex-shrink:0; }
    .log-row.sent i { color:var(--green); } .log-row.failed i { color:var(--red); } .log-row.skipped i { color:var(--muted); }
    .log-row .name { font-weight:700; color:var(--text); }
    .log-row .note { color:var(--muted); }

    /* ───────── Template ───────── */
    .tpl-grid { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:22px; padding:20px 22px; align-items:start; }
    .tpl-label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); margin-bottom:8px; display:block; }
    .token-row { display:flex; flex-wrap:wrap; gap:7px; margin-bottom:12px; }
    .token { font-family:ui-monospace,Menlo,monospace; font-size:.74rem; font-weight:700; color:var(--gold-ink); background:var(--gold-pale); border:1.5px solid transparent; border-radius:99px; padding:4px 12px; cursor:pointer; transition:.15s; }
    .token:hover { border-color:var(--gold); background:var(--gold); color:#101826; }
    textarea#messageTemplate { width:100%; min-height:130px; padding:12px 14px; font-family:inherit; font-size:.88rem; color:var(--text); background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; outline:none; resize:vertical; line-height:1.55; transition:.15s; }
    textarea#messageTemplate:focus { border-color:var(--gold); background:var(--surface); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .char-counter { display:flex; justify-content:space-between; align-items:center; margin-top:8px; font-size:.76rem; color:var(--muted); font-weight:600; }
    .char-counter.over { color:var(--amber-ink); }
    .char-note { font-size:.72rem; color:var(--muted); margin-top:2px; }

    .phone-mock { background:var(--surface-2); border:1px solid var(--border); border-radius:var(--r-lg); padding:14px; position:sticky; top:calc(var(--topbar-h) + 18px); }
    .phone-top { display:flex; align-items:center; gap:10px; font-size:.78rem; font-weight:700; color:var(--text); padding-bottom:10px; border-bottom:1px solid var(--border-2); margin-bottom:12px; }
    .phone-top .dot { width:30px; height:30px; border-radius:50%; background:var(--gold); color:#101826; display:flex; align-items:center; justify-content:center; font-size:.7rem; }
    .phone-top small { display:block; font-weight:500; color:var(--muted); font-size:.68rem; }
    .bubble { background:var(--navy); color:#fff; padding:11px 14px; border-radius:16px 16px 16px 4px; font-size:.82rem; line-height:1.5; word-wrap:break-word; white-space:pre-wrap; max-height:260px; overflow:auto; }
    [data-theme="dark"] .bubble { background:#1b2c4b; }
    .bubble.empty { background:var(--surface-3); color:var(--muted); font-style:italic; }
    .bubble-meta { font-size:.7rem; color:var(--muted); margin-top:8px; }

    /* ───────── Recipients ───────── */
    .toolbar { display:flex; align-items:center; gap:12px; padding:12px 22px; border-bottom:1px solid var(--border-2); flex-wrap:wrap; }
    .check { display:inline-flex; align-items:center; gap:9px; font-size:.82rem; font-weight:600; color:var(--text-2); cursor:pointer; }
    .check input, table.rec input[type="checkbox"] { accent-color:var(--gold-d); width:16px; height:16px; cursor:pointer; flex-shrink:0; }
    .search-wrap { position:relative; flex:1; max-width:340px; min-width:180px; margin-left:auto; }
    .search-wrap > i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:13px; pointer-events:none; }
    .search-wrap input { width:100%; height:38px; padding:0 14px 0 36px; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; font-family:inherit; font-size:.84rem; font-weight:500; color:var(--text); transition:.15s; }
    .search-wrap input:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .sel-count { font-size:.76rem; color:var(--muted); font-weight:600; }

    .table-scroll { overflow:auto; max-height:560px; -webkit-overflow-scrolling:touch; }
    table.rec { width:100%; border-collapse:collapse; font-size:.84rem; min-width:700px; }
    table.rec thead th { background:var(--surface-2); color:var(--muted); font-size:.64rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; padding:11px 14px; text-align:left; white-space:nowrap; border-bottom:1px solid var(--border); position:sticky; top:0; z-index:2; }
    table.rec td { padding:10px 14px; border-bottom:1px solid var(--border-2); color:var(--text-2); vertical-align:top; }
    table.rec tbody tr { transition:background .12s; }
    table.rec tbody tr:hover td { background:var(--surface-2); }
    table.rec tbody tr:last-child td { border-bottom:none; }
    table.rec tr.ready > td:first-child { box-shadow:inset 3px 0 0 var(--green); }
    table.rec tr.missing > td:first-child { box-shadow:inset 3px 0 0 var(--red); }
    table.rec tr.missing td { opacity:.7; }
    table.rec tr.row-hidden { display:none; }
    table.rec input[type="checkbox"]:disabled { cursor:not-allowed; opacity:.4; }
    .col-chk { width:44px; text-align:center !important; }
    .stu { color:var(--text); font-weight:700; }
    .sub { font-size:.74rem; color:var(--muted); }
    .phone { font-family:ui-monospace,Menlo,monospace; font-size:.8rem; color:var(--text); }
    .pill-no { display:inline-flex; align-items:center; gap:5px; font-size:.6rem; font-weight:800; letter-spacing:.6px; text-transform:uppercase; padding:2px 9px; border-radius:99px; background:var(--red-bg); color:var(--red-ink); }
    .marks-line { color:var(--text-2); }
    .totals { margin-top:3px; font-size:.74rem; font-weight:700; color:var(--text); }

    .submit-bar { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 22px; border-top:1px solid var(--border-2); background:var(--surface-2); flex-wrap:wrap; }
    .submit-bar .note { font-size:.78rem; color:var(--muted); }

    .empty-state { text-align:center; padding:52px 20px; }
    .empty-icon { width:64px; height:64px; border-radius:18px; margin:0 auto 14px; display:flex; align-items:center; justify-content:center; background:var(--gold-pale); color:var(--gold-d); font-size:24px; }
    .empty-state p { font-size:.86rem; color:var(--muted); max-width:440px; margin:0 auto; }

    .site-footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (max-width:1180px) { .tpl-grid { grid-template-columns:1fr; } .phone-mock { position:static; } .form-grid { grid-template-columns:repeat(2,minmax(150px,1fr)); } }
    @media (max-width:1024px) {
      .app, body.nav-collapsed .app { grid-template-columns:1fr; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); visibility:visible; transform:none; }
      body.nav-collapsed .sidebar { visibility:visible; transform:none; }
      .sidebar.open { left:0; }
      .topbar { padding:0 18px; } .content { padding:18px 18px 50px; }
    }
    @media (max-width:640px) {
      .btn-gold span { display:none; } .tb-title small { display:none; }
      .form-grid { grid-template-columns:1fr; }
      .stats { grid-template-columns:1fr; padding:16px; }
      .submit-bar { flex-direction:column-reverse; align-items:stretch; } .submit-bar .btn, .form-grid .btn { width:100%; }
      .search-wrap { max-width:none; flex-basis:100%; margin-left:0; }
    }
  </style>
</head>
<body>
<a href="#main-content" class="skip">Skip to content</a>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<div class="app">

  <!-- ══════════ Sidebar ══════════ -->
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
        <a href="Students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
        <a href="LearningMaterials.php"><i class="fa-solid fa-book"></i> Learning Materials</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Communication</div>
        <a href="manage_parent_contacts.php"><i class="fa-solid fa-address-book"></i> Parent Contacts</a>
        <a href="send_results_sms.php" class="active" aria-current="page"><i class="fa-solid fa-comment-sms"></i> Send Results SMS</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Administration</div>
        <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
      </div>
    </nav>

    <div class="side-user">
      <div class="av"><?= $roleInit ?></div>
      <div><b><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></b><small>Signed in</small></div>
      <a href="index.phpt" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>

  <!-- ══════════ Main ══════════ -->
  <div class="main">

    <header class="topbar">
      <div class="tb-left">
        <button type="button" class="menu-btn" id="menuBtn" aria-label="Toggle menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
        <div class="tb-title">Send Results SMS<small>Message parents their learner's results</small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="manage_parent_contacts.php" class="btn-gold"><i class="fa-solid fa-address-book"></i><span>Parent Contacts</span></a>
        <a href="index.php" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <div>
        <p class="page-eyebrow">Communication</p>
        <h1 class="page-title">Send Results via SMS</h1>
        <p class="page-sub">Preview which parents will receive results for a grade, term and exam, customise the message, then send. Every attempt is logged for reference.</p>
      </div>

      <?php if ($action === 'send'): ?>
      <!-- Send summary -->
      <div class="box">
        <div class="box-head"><span class="box-title"><i class="fa-solid fa-paper-plane"></i>Send summary</span></div>
        <div class="stats">
          <div class="stat sent"><div class="num"><?= $sendResults['sent'] ?></div><div class="lbl">Sent</div></div>
          <div class="stat failed"><div class="num"><?= $sendResults['failed'] ?></div><div class="lbl">Failed</div></div>
          <div class="stat"><div class="num"><?= $sendResults['skipped_no_phone'] ?></div><div class="lbl">Skipped — no phone</div></div>
        </div>
        <?php if (!empty($sendResults['details'])): ?>
        <div class="log">
          <?php foreach ($sendResults['details'] as $d): ?>
          <div class="log-row <?= htmlspecialchars($d['status']) ?>">
            <i class="fa-solid <?= $d['status'] === 'sent' ? 'fa-circle-check' : ($d['status'] === 'failed' ? 'fa-circle-xmark' : 'fa-circle-minus') ?>"></i>
            <span class="name"><?= htmlspecialchars($d['name']) ?></span>
            <?php if ($d['phone']): ?><span>(<?= htmlspecialchars($d['phone']) ?>)</span><?php endif; ?>
            <?php if ($d['note']): ?><span class="note">— <?= htmlspecialchars($d['note']) ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Filters -->
      <div class="box">
        <div class="box-head"><span class="box-title"><i class="fa-solid fa-sliders"></i>Select results</span></div>
        <div class="box-body">
          <form method="post">
            <input type="hidden" name="action" value="preview">
            <div class="form-grid">
              <div class="field">
                <label for="gradeSelect">Grade</label>
                <select id="gradeSelect" name="grade">
                  <option value="0">Select grade</option>
                  <?php for ($g = 1; $g <= 9; $g++): ?>
                    <option value="<?= $g ?>" <?= $g === $grade ? 'selected' : '' ?>>Grade <?= $g ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="field">
                <label for="termSelect">Term</label>
                <select id="termSelect" name="term">
                  <option value="">Select term</option>
                  <?php for ($t = 1; $t <= 3; $t++): ?>
                    <option value="<?= $t ?>" <?= ((string)$t === $termNum) ? 'selected' : '' ?>>Term <?= $t ?></option>
                  <?php endfor; ?>
                </select>
              </div>
              <div class="field">
                <label for="examSelect">Exam name</label>
                <select id="examSelect" name="examType">
                  <?php foreach ($EXAM_TYPES as $code => $et): ?>
                    <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= $code === $examType ? 'selected' : '' ?>><?= htmlspecialchars(isset($et['label']) ? $et['label'] : ucfirst($code), ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="field">
                <label for="yearInput">Year</label>
                <input type="number" id="yearInput" name="year" value="<?= $year ?: date('Y') ?>">
              </div>
              <button type="submit" class="btn"><i class="fa-solid fa-eye"></i> Preview</button>
            </div>
          </form>
        </div>
      </div>

      <?php if ($showResults): ?>
        <?php if (empty($previewRows)): ?>
          <div class="box">
            <div class="empty-state">
              <div class="empty-icon"><i class="fa-solid fa-file-circle-question"></i></div>
              <p>No exam records found for that grade, term, year and exam. Make sure results have been uploaded first.</p>
            </div>
          </div>
        <?php else: ?>
          <?php
            $phoneReadyCount = 0;
            foreach ($previewRows as $row) {
                if (normalize_kenyan_phone($row['parentPhone'])) $phoneReadyCount++;
            }
          ?>
          <form method="post" id="sendForm">
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="grade" value="<?= $grade ?>">
            <input type="hidden" name="term" value="<?= htmlspecialchars($termNum) ?>">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="examType" value="<?= htmlspecialchars($examType) ?>">

            <div style="display:flex;flex-direction:column;gap:18px;">

              <!-- Message template -->
              <div class="box">
                <div class="box-head"><span class="box-title"><i class="fa-solid fa-message"></i>Message template</span></div>
                <div class="tpl-grid"
                     id="tplCtx"
                     data-exam="<?= htmlspecialchars(ucfirst($examType), ENT_QUOTES) ?>"
                     data-term="<?= htmlspecialchars($termLabel, ENT_QUOTES) ?>"
                     data-year="<?= $year ?>"
                     data-grade="Grade <?= $grade ?>">
                  <div>
                    <span class="tpl-label">Click a token to insert it at your cursor</span>
                    <div class="token-row">
                      <?php foreach (array('student_name','exam_type','term','year','grade_label','subjects','total','average') as $tok): ?>
                        <button type="button" class="token" onclick="insertToken('{<?= $tok ?>}')">{<?= $tok ?>}</button>
                      <?php endforeach; ?>
                    </div>
                    <textarea name="messageTemplate" id="messageTemplate"><?= htmlspecialchars($messageTemplate) ?></textarea>
                    <div class="char-counter" id="charCounter">
                      <span><span id="charCount">0</span> characters in template</span>
                      <span id="segmentCount">1 SMS segment</span>
                    </div>
                    <p class="char-note">Tokens like {subjects} expand at send time — the real length varies per parent.</p>
                  </div>

                  <div class="phone-mock" aria-live="polite">
                    <div class="phone-top">
                      <div class="dot"><i class="fa-solid fa-user"></i></div>
                      <div><span id="pvTo">Preview</span><small id="pvPhone">First selected recipient</small></div>
                    </div>
                    <div class="bubble empty" id="pvBubble">Select a recipient to see a preview.</div>
                    <div class="bubble-meta" id="pvMeta"></div>
                  </div>
                </div>
              </div>

              <!-- Recipients -->
              <div class="box">
                <div class="box-head">
                  <span class="box-title"><i class="fa-solid fa-users"></i>Recipients</span>
                  <span class="badge"><?= $phoneReadyCount ?> of <?= count($previewRows) ?> have a phone on file</span>
                </div>

                <div class="toolbar">
                  <label class="check">
                    <input type="checkbox" id="selectAll" checked>
                    Select all eligible
                  </label>
                  <span class="sel-count" id="selectCount"></span>
                  <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="recSearch" placeholder="Search learner or parent…" autocomplete="off">
                  </div>
                </div>

                <div class="table-scroll">
                  <table class="rec">
                    <thead>
                      <tr>
                        <th class="col-chk"></th>
                        <th>Student</th>
                        <th>Parent</th>
                        <th>Marks</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($previewRows as $row): ?>
                        <?php
                          $summary = build_subject_summary($row, $subject_map);
                          $phoneOk = normalize_kenyan_phone($row['parentPhone']);
                          $fullName = trim($row['firstName'] . ' ' . $row['surname']);
                          $parentName = trim((string)$row['parentName']);
                        ?>
                        <tr class="<?= $phoneOk ? 'ready' : 'missing' ?>"
                            data-search="<?= htmlspecialchars(strtolower($fullName . ' ' . $parentName), ENT_QUOTES) ?>"
                            data-name="<?= htmlspecialchars($fullName, ENT_QUOTES) ?>"
                            data-phone="<?= htmlspecialchars((string)$phoneOk, ENT_QUOTES) ?>"
                            data-subjects="<?= htmlspecialchars($summary['summary'], ENT_QUOTES) ?>"
                            data-total="<?= htmlspecialchars((string)$summary['total'], ENT_QUOTES) ?>"
                            data-average="<?= htmlspecialchars((string)$summary['average'], ENT_QUOTES) ?>">
                          <td class="col-chk">
                            <input class="rowcheck" type="checkbox" name="send_student_ids[]" value="<?= (int)$row['student_id'] ?>" <?= $phoneOk ? 'checked' : 'disabled' ?>>
                          </td>
                          <td><span class="stu"><?= htmlspecialchars($fullName) ?></span></td>
                          <td>
                            <?php if ($phoneOk): ?>
                              <div class="phone"><?= htmlspecialchars($phoneOk) ?></div>
                              <?php if ($parentName !== ''): ?><div class="sub"><?= htmlspecialchars($parentName) ?></div><?php endif; ?>
                            <?php else: ?>
                              <span class="pill-no"><i class="fa-solid fa-phone-slash"></i> No phone</span>
                              <?php if ($parentName !== ''): ?><div class="sub"><?= htmlspecialchars($parentName) ?></div><?php endif; ?>
                            <?php endif; ?>
                          </td>
                          <td>
                            <div class="marks-line"><?= $summary['summary'] !== '' ? htmlspecialchars($summary['summary']) : '—' ?></div>
                            <div class="totals">Total: <?= $summary['total'] ?> · Avg: <?= $summary['average'] ?></div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <div class="submit-bar">
                  <span class="note"><i class="fa-solid fa-circle-info"></i> Learners without a phone number are skipped. Add numbers under <a href="manage_parent_contacts.php" style="color:var(--gold-ink);font-weight:700;">Parent Contacts</a>.</span>
                  <button type="submit" class="btn" id="sendBtn"><i class="fa-solid fa-paper-plane"></i> Send SMS to selected parents</button>
                </div>
              </div>

            </div>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <div class="box">
          <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-comment-sms"></i></div>
            <p>Choose a grade, term, exam and year above, then click Preview to see who will receive results.</p>
          </div>
        </div>
      <?php endif; ?>

    </main>

    <div class="site-footer">&copy; Designed by <strong>Kelvin Mutinda</strong> 2026. All rights reserved.</div>
  </div>
</div>

<script>
  /* ══════════════════ Theme (same key as the other dashboards) ══════════════════ */
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

  /* ══════════════════ Sidebar ══════════════════ */
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  const menuBtn = document.getElementById('menuBtn');
  const isMobile = () => window.matchMedia('(max-width: 1024px)').matches;

  function setMenuIcon(open) {
    menuBtn.innerHTML = '<i class="fa-solid ' + (open ? 'fa-xmark' : 'fa-bars') + '"></i>';
    menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    document.body.style.overflow = '';
    setMenuIcon(false);
  }
  menuBtn.addEventListener('click', function () {
    if (isMobile()) {
      const open = sidebar.classList.toggle('open');
      overlay.classList.toggle('active', open);
      document.body.style.overflow = open ? 'hidden' : '';
      setMenuIcon(open);
    } else {
      document.body.classList.toggle('nav-collapsed');
    }
  });
  sidebar.querySelectorAll('nav a').forEach(a => a.addEventListener('click', () => { if (isMobile()) closeSidebar(); }));
  window.addEventListener('resize', () => { if (!isMobile()) closeSidebar(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

  /* ══════════════════ Template: tokens, counter, live preview ══════════════════ */
  const tplEl = document.getElementById('messageTemplate');

  function insertToken(token) {
    if (!tplEl) return;
    const start = tplEl.selectionStart, end = tplEl.selectionEnd;
    tplEl.value = tplEl.value.substring(0, start) + token + tplEl.value.substring(end);
    tplEl.focus();
    const pos = start + token.length;
    tplEl.setSelectionRange(pos, pos);
    onTemplateChange();
  }

  /* GSM-7: 160 chars per single segment, 153 per segment once concatenated. */
  function updateCharCounter() {
    const len = tplEl.value.length;
    const segments = len <= 160 ? 1 : Math.ceil(len / 153);
    document.getElementById('charCount').textContent = len;
    document.getElementById('segmentCount').textContent = segments + ' SMS segment' + (segments !== 1 ? 's' : '');
    document.getElementById('charCounter').classList.toggle('over', segments > 1);
  }

  function expandTemplate(tpl, row) {
    const ctx = document.getElementById('tplCtx').dataset;
    const map = {
      student_name: row.dataset.name,
      exam_type:    ctx.exam,
      term:         ctx.term,
      year:         ctx.year,
      grade_label:  ctx.grade,
      subjects:     row.dataset.subjects,
      total:        row.dataset.total,
      average:      row.dataset.average
    };
    return tpl.replace(/\{(\w+)\}/g, (m, k) => (k in map ? map[k] : m));
  }

  function updatePreview() {
    const bubble = document.getElementById('pvBubble');
    const first = document.querySelector('table.rec tbody tr:not(.row-hidden) .rowcheck:checked')
               || document.querySelector('table.rec .rowcheck:checked');
    if (!first) {
      bubble.className = 'bubble empty';
      bubble.textContent = 'Select a recipient to see a preview.';
      document.getElementById('pvTo').textContent = 'Preview';
      document.getElementById('pvPhone').textContent = 'No recipient selected';
      document.getElementById('pvMeta').textContent = '';
      return;
    }
    const row = first.closest('tr');
    const msg = expandTemplate(tplEl.value, row);
    bubble.className = 'bubble';
    bubble.textContent = msg;
    document.getElementById('pvTo').textContent = row.dataset.name;
    document.getElementById('pvPhone').textContent = row.dataset.phone;
    const segs = msg.length <= 160 ? 1 : Math.ceil(msg.length / 153);
    document.getElementById('pvMeta').textContent = msg.length + ' characters · ' + segs + ' SMS segment' + (segs !== 1 ? 's' : '') + ' for this parent';
  }

  function onTemplateChange() { updateCharCounter(); updatePreview(); }

  /* ══════════════════ Recipients: select, search, confirm ══════════════════ */
  function updateSelectCount() {
    const eligible = document.querySelectorAll('.rowcheck:not([disabled])').length;
    const checked  = document.querySelectorAll('.rowcheck:checked').length;
    document.getElementById('selectCount').textContent = checked + ' of ' + eligible + ' eligible selected';
    const btn = document.getElementById('sendBtn');
    if (btn) btn.disabled = checked === 0;
    const all = document.getElementById('selectAll');
    if (all) all.checked = eligible > 0 && checked === eligible;
    updatePreview();
  }

  if (tplEl) {
    tplEl.addEventListener('input', onTemplateChange);

    document.getElementById('selectAll').addEventListener('change', function () {
      document.querySelectorAll('.rowcheck').forEach(c => { if (!c.disabled) c.checked = this.checked; });
      updateSelectCount();
    });
    document.querySelectorAll('.rowcheck').forEach(c => c.addEventListener('change', updateSelectCount));

    document.getElementById('recSearch').addEventListener('input', function () {
      const term = this.value.trim().toLowerCase();
      document.querySelectorAll('table.rec tbody tr').forEach(r => {
        r.classList.toggle('row-hidden', term !== '' && r.dataset.search.indexOf(term) === -1);
      });
      updatePreview();
    });

    /* Confirm before sending (SMS cost money) and block double submits */
    document.getElementById('sendForm').addEventListener('submit', function (e) {
      const n = document.querySelectorAll('.rowcheck:checked').length;
      if (n === 0) { e.preventDefault(); return; }
      if (!confirm('Send results SMS to ' + n + ' parent' + (n !== 1 ? 's' : '') + '? This cannot be undone.')) {
        e.preventDefault();
        return;
      }
      const btn = document.getElementById('sendBtn');
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending…';
    });

    onTemplateChange();
    updateSelectCount();
  }
</script>

</body>
</html>