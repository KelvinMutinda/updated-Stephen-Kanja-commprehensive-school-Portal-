<?php
// ============================================================
// manage_parent_contacts.php
// Lets HOI/admin set/edit each student's parent name + phone
// number, grade by grade. Required before send_results_sms.php
// can actually deliver anything.
// ============================================================
 require_once __DIR__ . '/page_guard.php';
session_start();
include 'conn.php';

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

function normalize_phone_for_storage($raw) {
    $digits = preg_replace('/[^0-9+]/', '', trim((string)$raw));
    if (preg_match('/^(\+254|254|0)?[71]\d{8}$/', $digits)) {
        return $digits;
    }
    return null;
}

$grade = isset($_GET['grade']) ? (int)$_GET['grade'] : 0;
$saveMessage = '';
$saveStatus = 'success';

// ── Handle save ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedGrade = isset($_POST['grade']) ? (int)$_POST['grade'] : 0;
    $names  = isset($_POST['parentName']) ? $_POST['parentName'] : array();
    $phones = isset($_POST['parentPhone']) ? $_POST['parentPhone'] : array();

    $updated = 0;
    $skipped = 0;

    foreach ($phones as $studentId => $rawPhone) {
        $studentId = (int)$studentId;
        $rawPhone  = trim((string)$rawPhone);
        $rawName   = trim((string)(isset($names[$studentId]) ? $names[$studentId] : ''));

        if ($rawPhone === '') {
            // Allow intentionally clearing a phone number
            $stmt = $conn->prepare("UPDATE Student SET parentName = ?, parentPhone = NULL WHERE id = ?");
            $stmt->bind_param('si', $rawName, $studentId);
            $stmt->execute();
            $stmt->close();
            continue;
        }

        $normalized = normalize_phone_for_storage($rawPhone);
        if ($normalized === null) {
            $skipped++;
            continue;
        }

        $stmt = $conn->prepare("UPDATE Student SET parentName = ?, parentPhone = ? WHERE id = ?");
        $stmt->bind_param('ssi', $rawName, $normalized, $studentId);
        $stmt->execute();
        $stmt->close();
        $updated++;
    }

    $saveMessage = "Saved contacts for $updated student(s).";
    if ($skipped > 0) {
        $saveMessage .= " $skipped number(s) didn't look like valid phone numbers and were left unchanged.";
        $saveStatus = 'warning';
    }

    $grade = $postedGrade;
}

// ── Fetch students for the selected grade ──
$students = array();
if ($grade > 0) {
    $stmt = $conn->prepare("SELECT id, firstName, surname, parentName, parentPhone FROM Student WHERE Grade = ? ORDER BY firstName, surname");
    $stmt->bind_param('i', $grade);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $students[] = $row;
    }
    $stmt->close();
}
mysqli_close($conn);

$totalStudents = count($students);
$missingCount  = 0;
foreach ($students as $s) {
    if (empty($s['parentPhone'])) $missingCount++;
}
$readyCount  = $totalStudents - $missingCount;
$percentDone = $totalStudents > 0 ? (int)round($readyCount / $totalStudents * 100) : 0;

$isHoi     = (($_SESSION['account_type'] ?? '') === 'hoi');
$homeLink  = $isHoi ? 'Hoi.php' : 'teacherpanel.php';
$roleLabel = $isHoi ? 'Head of Institution' : 'Teacher Panel';
$roleInit  = $isHoi ? 'HI' : 'TR';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Parent Contacts — Stephen Kanja School</title>
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

    /* ───────── Alerts ───────── */
    .alert-bar { display:flex; align-items:flex-start; gap:12px; border-radius:var(--r); padding:13px 16px; font-size:.86rem; border:1px solid var(--green-bd); background:var(--green-bg); color:var(--green-ink); animation:fadeUp .3s ease both; }
    .alert-bar i { margin-top:3px; flex-shrink:0; }
    .alert-bar.warn { background:var(--amber-bg); border-color:var(--amber-bd); color:var(--amber-ink); }

    /* ───────── Filters ───────── */
    .filters { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:18px 20px; box-shadow:var(--shadow-sm); display:grid; grid-template-columns:minmax(200px,280px); gap:16px; animation:fadeUp .45s ease both; }
    .field { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .field label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .field select { appearance:none; width:100%; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; padding:0 38px 0 14px; font-family:inherit; font-size:.88rem; font-weight:500; color:var(--text); transition:.15s; height:42px; cursor:pointer;
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; }
    .field select:hover { border-color:rgba(37,99,235,.35); }
    .field select:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }

    /* ───────── Stat cards ───────── */
    .stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; animation:fadeUp .45s .05s ease both; }
    .stat { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-sm); padding:16px 18px; display:flex; align-items:center; gap:14px; }
    .stat-ic { width:44px; height:44px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; background:var(--gold-pale); color:var(--gold-d); }
    .stat.ok .stat-ic { background:var(--green-bg); color:var(--green); }
    .stat.bad .stat-ic { background:var(--red-bg); color:var(--red); }
    .stat-label { font-size:.6rem; font-weight:800; letter-spacing:1.2px; text-transform:uppercase; color:var(--muted); }
    .stat-val { font-size:1.45rem; font-weight:800; letter-spacing:-.5px; line-height:1.2; font-variant-numeric:tabular-nums; }
    .progress { height:6px; border-radius:99px; background:var(--surface-3); overflow:hidden; margin-top:6px; width:120px; }
    .progress > div { height:100%; background:var(--green); border-radius:99px; transition:width .3s; }

    /* ───────── Box / table ───────── */
    .box { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-sm); overflow:hidden; animation:fadeUp .45s .1s ease both; }
    .box-head { padding:15px 22px; border-bottom:1px solid var(--border-2); display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .box-title { font-size:.76rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); display:flex; align-items:center; gap:9px; margin-right:auto; }
    .box-title i { color:var(--gold-d); }
    .badge { font-size:.66rem; font-weight:800; letter-spacing:.9px; text-transform:uppercase; padding:3px 11px; border-radius:99px; background:var(--gold-pale); color:var(--gold-ink); }

    .search-bar { display:flex; align-items:center; gap:12px; padding:12px 22px; border-bottom:1px solid var(--border-2); flex-wrap:wrap; }
    .search-wrap { position:relative; flex:1; max-width:380px; min-width:200px; }
    .search-wrap > i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:13px; pointer-events:none; }
    .search-wrap input { width:100%; height:38px; padding:0 34px 0 36px; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; font-family:inherit; font-size:.84rem; font-weight:500; color:var(--text); transition:.15s; }
    .search-wrap input:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    #searchClear { display:none; position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; color:var(--muted); cursor:pointer; width:26px; height:26px; border-radius:8px; }
    #searchClear:hover { background:var(--surface-3); color:var(--text); }
    #searchClear.visible { display:block; }
    .search-count { font-size:.76rem; color:var(--muted); font-weight:600; }
    .chip { display:inline-flex; align-items:center; gap:8px; padding:7px 14px; border:1.5px solid var(--border); border-radius:99px; background:var(--surface-2); color:var(--text-2); font-size:.8rem; font-weight:600; cursor:pointer; transition:.15s; user-select:none; }
    .chip:hover { border-color:var(--gold); color:var(--text); }
    .chip input { accent-color:var(--gold-d); width:15px; height:15px; cursor:pointer; }
    .chip:has(input:checked) { border-color:var(--gold); background:var(--gold-pale); color:var(--gold-ink); }

    .table-scroll { overflow:auto; max-height:640px; -webkit-overflow-scrolling:touch; }
    table.contacts { width:100%; border-collapse:collapse; font-size:.84rem; min-width:640px; }
    table.contacts thead th { background:var(--surface-2); color:var(--muted); font-size:.64rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; padding:11px 14px; text-align:left; white-space:nowrap; border-bottom:1px solid var(--border); position:sticky; top:0; z-index:2; }
    table.contacts td { padding:9px 14px; border-bottom:1px solid var(--border-2); color:var(--text-2); vertical-align:top; }
    table.contacts tbody tr { transition:background .12s; }
    table.contacts tbody tr:hover td { background:var(--surface-2); }
    table.contacts tbody tr:last-child td { border-bottom:none; }
    table.contacts tbody tr.missing > td:first-child { box-shadow:inset 3px 0 0 var(--red); }
    table.contacts tbody tr.has-phone > td:first-child { box-shadow:inset 3px 0 0 var(--green); }
    tr.row-hidden { display:none; }
    .col-num { width:48px; color:var(--muted) !important; font-variant-numeric:tabular-nums; }
    .col-name { width:26%; }
    .stu-name { color:var(--text); font-weight:600; display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding-top:8px; }
    .pill { display:inline-flex; align-items:center; gap:5px; font-size:.6rem; font-weight:800; letter-spacing:.6px; text-transform:uppercase; padding:2px 9px; border-radius:99px; }
    .pill.no { background:var(--red-bg); color:var(--red-ink); }
    .pill.yes { background:var(--green-bg); color:var(--green-ink); }

    .inp { width:100%; height:38px; padding:0 12px; background:var(--surface-2); border:1.5px solid var(--border); border-radius:9px; font-family:inherit; font-size:.86rem; font-weight:500; color:var(--text); outline:none; transition:border-color .15s,box-shadow .15s,background .15s; }
    .inp:hover { border-color:rgba(37,99,235,.35); }
    .inp:focus { border-color:var(--gold); background:var(--surface); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .inp.invalid { border-color:var(--red); background:var(--red-bg); box-shadow:0 0 0 3px rgba(220,38,38,.12); }
    .inp.valid { border-color:var(--green-bd); }
    .hint { font-size:.7rem; color:var(--muted); margin-top:4px; min-height:1.1em; }
    .hint.err { color:var(--red); font-weight:600; }

    .empty-state { text-align:center; padding:52px 20px; }
    .empty-icon { width:64px; height:64px; border-radius:18px; margin:0 auto 14px; display:flex; align-items:center; justify-content:center; background:var(--gold-pale); color:var(--gold-d); font-size:24px; }
    .empty-state p { font-size:.86rem; color:var(--muted); max-width:420px; margin:0 auto; }

    .submit-bar { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:14px 22px; border-top:1px solid var(--border-2); background:var(--surface-2); flex-wrap:wrap; }
    .submit-bar .note { font-size:.78rem; color:var(--muted); }
    .btn { background:var(--gold); color:#101826; border:none; border-radius:10px; padding:0 22px; height:42px; font-family:inherit; font-size:.84rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 8px 20px rgba(240,192,64,.28); }
    .btn:hover { background:#f5cf5e; transform:translateY(-1px); }
    .btn:active { transform:scale(.98); }

    .site-footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (max-width:1024px) {
      .app, body.nav-collapsed .app { grid-template-columns:1fr; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); visibility:visible; transform:none; }
      body.nav-collapsed .sidebar { visibility:visible; transform:none; }
      .sidebar.open { left:0; }
      .topbar { padding:0 18px; } .content { padding:18px 18px 50px; }
    }
    @media (max-width:760px) { .stats { grid-template-columns:1fr; } }
    @media (max-width:640px) {
      .btn-gold span { display:none; } .tb-title small { display:none; }
      .filters { padding:16px; grid-template-columns:1fr; }
      .submit-bar { flex-direction:column-reverse; align-items:stretch; } .submit-bar .btn { width:100%; }
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
        <a href="manage_parent_contacts.php" class="active" aria-current="page"><i class="fa-solid fa-address-book"></i> Parent Contacts</a>
        <a href="send_results_sms.php"><i class="fa-solid fa-comment-sms"></i> Send Results SMS</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Administration</div>
        <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
      </div>
    </nav>

    <div class="side-user">
      <div class="av"><?= $roleInit ?></div>
      <div><b><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></b><small>Signed in</small></div>
      <a href="logout" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>

  <!-- ══════════ Main ══════════ -->
  <div class="main">

    <header class="topbar">
      <div class="tb-left">
        <button type="button" class="menu-btn" id="menuBtn" aria-label="Toggle menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
        <div class="tb-title">Parent Contacts<small>Manage guardian names and phone numbers</small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="send_results_sms.php" class="btn-gold"><i class="fa-solid fa-comment-sms"></i><span>Send Results SMS</span></a>
        <a href="logout" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <div>
        <p class="page-eyebrow">Communication</p>
        <h1 class="page-title">Manage Parent Contacts</h1>
        <p class="page-sub">Add or update each learner's parent/guardian name and phone number, grade by grade. A valid phone number is required before results can be sent by SMS.</p>
      </div>

      <?php if ($saveMessage): ?>
      <div class="alert-bar <?= $saveStatus === 'warning' ? 'warn' : '' ?>" role="status">
        <i class="fa-solid <?= $saveStatus === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
        <span><?= htmlspecialchars($saveMessage) ?></span>
      </div>
      <?php endif; ?>

      <!-- Grade filter -->
      <form method="get" id="gradeForm" class="filters">
        <div class="field">
          <label for="gradeSelect">Grade</label>
          <select id="gradeSelect" name="grade" onchange="this.form.submit()">
            <option value="0">Select grade</option>
            <?php for ($g = 1; $g <= 9; $g++): ?>
              <option value="<?= $g ?>" <?= $g === $grade ? 'selected' : '' ?>>Grade <?= $g ?></option>
            <?php endfor; ?>
          </select>
        </div>
      </form>

      <?php if ($grade > 0 && $totalStudents > 0): ?>
      <!-- Summary -->
      <div class="stats">
        <div class="stat">
          <div class="stat-ic"><i class="fa-solid fa-users"></i></div>
          <div>
            <div class="stat-label">Learners in Grade <?= $grade ?></div>
            <div class="stat-val"><?= $totalStudents ?></div>
          </div>
        </div>
        <div class="stat ok">
          <div class="stat-ic"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="stat-label">Ready for SMS</div>
            <div class="stat-val"><?= $readyCount ?></div>
            <div class="progress" aria-label="<?= $percentDone ?>% complete"><div style="width:<?= $percentDone ?>%"></div></div>
          </div>
        </div>
        <div class="stat <?= $missingCount > 0 ? 'bad' : 'ok' ?>">
          <div class="stat-ic"><i class="fa-solid <?= $missingCount > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i></div>
          <div>
            <div class="stat-label">Missing phone number</div>
            <div class="stat-val"><?= $missingCount ?></div>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($grade > 0): ?>
        <?php if ($totalStudents === 0): ?>
          <div class="box">
            <div class="empty-state">
              <div class="empty-icon"><i class="fa-solid fa-user-slash"></i></div>
              <p>No students found for Grade <?= $grade ?>.</p>
            </div>
          </div>
        <?php else: ?>
        <form method="post" id="contactsForm">
          <input type="hidden" name="grade" value="<?= $grade ?>">

          <div class="box">
            <div class="box-head">
              <span class="box-title"><i class="fa-solid fa-address-book"></i>Grade <?= $grade ?> contacts</span>
              <span class="badge" id="rowCount"><?= $totalStudents ?> student<?= $totalStudents === 1 ? '' : 's' ?></span>
            </div>

            <div class="search-bar">
              <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="contactSearch" placeholder="Search learner or parent name…" autocomplete="off">
                <button type="button" id="searchClear" title="Clear search" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
              </div>
              <label class="chip"><input type="checkbox" id="missingOnly"> Show missing only</label>
              <span class="search-count" id="searchCount"></span>
            </div>

            <div class="table-scroll">
              <table class="contacts">
                <thead>
                  <tr>
                    <th class="col-num">#</th>
                    <th class="col-name">Student</th>
                    <th>Parent / Guardian name</th>
                    <th>Parent phone</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($students as $i => $s): ?>
                    <?php $isMissing = empty($s['parentPhone']); ?>
                    <tr class="<?= $isMissing ? 'missing' : 'has-phone' ?>"
                        data-name="<?= htmlspecialchars(strtolower($s['firstName'] . ' ' . $s['surname'] . ' ' . ($s['parentName'] ?? ''))) ?>">
                      <td class="col-num"><?= $i + 1 ?></td>
                      <td>
                        <span class="stu-name">
                          <?= htmlspecialchars($s['firstName'] . ' ' . $s['surname']) ?>
                          <?php if ($isMissing): ?>
                            <span class="pill no row-pill"><i class="fa-solid fa-phone-slash"></i> No phone</span>
                          <?php else: ?>
                            <span class="pill yes row-pill"><i class="fa-solid fa-check"></i> Ready</span>
                          <?php endif; ?>
                        </span>
                      </td>
                      <td>
                        <input type="text" class="inp name-inp" name="parentName[<?= (int)$s['id'] ?>]"
                               value="<?= htmlspecialchars($s['parentName'] !== null ? $s['parentName'] : '') ?>"
                               placeholder="Parent/guardian full name" autocomplete="off">
                      </td>
                      <td>
                        <input type="text" class="inp phone-inp" name="parentPhone[<?= (int)$s['id'] ?>]"
                               value="<?= htmlspecialchars($s['parentPhone'] !== null ? $s['parentPhone'] : '') ?>"
                               placeholder="07XX XXX XXX" inputmode="tel" autocomplete="off">
                        <div class="hint">07XX XXX XXX or +2547XXXXXXXX</div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>

            <div class="submit-bar">
              <span class="note"><i class="fa-regular fa-lightbulb"></i> Leave a phone blank to clear it. Invalid numbers are skipped on save.</span>
              <button type="submit" class="btn"><i class="fa-solid fa-floppy-disk"></i> Save contacts</button>
            </div>
          </div>
        </form>
        <?php endif; ?>
      <?php else: ?>
        <div class="box">
          <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-arrow-up-from-bracket"></i></div>
            <p>Select a grade above to manage parent contacts.</p>
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

  /* ══════════════════ Search + "missing only" filter ══════════════════ */
  const searchInput = document.getElementById('contactSearch');
  const missingOnly = document.getElementById('missingOnly');
  const clearBtn    = document.getElementById('searchClear');

  function applyFilters() {
    if (!searchInput) return;
    const term = searchInput.value.trim().toLowerCase();
    const onlyMissing = missingOnly.checked;
    const rows = document.querySelectorAll('table.contacts tbody tr');
    let visible = 0;
    rows.forEach(row => {
      const phone = row.querySelector('.phone-inp').value.trim();
      const liveName = (row.dataset.name || '') + ' ' + row.querySelector('.name-inp').value.toLowerCase();
      const match = (!term || liveName.includes(term)) && (!onlyMissing || phone === '');
      row.classList.toggle('row-hidden', !match);
      if (match) visible++;
    });
    clearBtn.classList.toggle('visible', term.length > 0);
    document.getElementById('searchCount').textContent =
      (term || onlyMissing) ? (visible + ' of ' + rows.length + ' shown') : '';
  }
  if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
    missingOnly.addEventListener('change', applyFilters);
    clearBtn.addEventListener('click', () => { searchInput.value = ''; applyFilters(); searchInput.focus(); });
  }

  /* ══════════════════ Live phone validation ══════════════════ */
  const PHONE_RE = /^(\+254|254|0)?[71]\d{8}$/;

  function validatePhone(input) {
    const raw = input.value.trim();
    const digits = raw.replace(/[^0-9+]/g, '');
    const hint = input.parentElement.querySelector('.hint');
    const row  = input.closest('tr');
    const pill = row.querySelector('.row-pill');

    input.classList.remove('invalid', 'valid');
    hint.classList.remove('err');

    if (raw === '') {
      hint.textContent = '07XX XXX XXX or +2547XXXXXXXX';
      row.classList.add('missing'); row.classList.remove('has-phone');
      pill.className = 'pill no row-pill';
      pill.innerHTML = '<i class="fa-solid fa-phone-slash"></i> No phone';
      return;
    }
    if (PHONE_RE.test(digits)) {
      input.classList.add('valid');
      hint.textContent = 'Looks good';
      row.classList.remove('missing'); row.classList.add('has-phone');
      pill.className = 'pill yes row-pill';
      pill.innerHTML = '<i class="fa-solid fa-check"></i> Ready';
    } else {
      input.classList.add('invalid');
      hint.textContent = 'Not a valid Kenyan number — will be skipped on save';
      hint.classList.add('err');
      row.classList.add('missing'); row.classList.remove('has-phone');
      pill.className = 'pill no row-pill';
      pill.innerHTML = '<i class="fa-solid fa-phone-slash"></i> Invalid';
    }
  }

  document.querySelectorAll('.phone-inp').forEach(inp => {
    inp.addEventListener('input', () => { validatePhone(inp); if (missingOnly && missingOnly.checked) { /* keep row visible while typing */ } });
    inp.addEventListener('blur', applyFilters);
  });
  document.querySelectorAll('.name-inp').forEach(inp => inp.addEventListener('blur', applyFilters));

  /* Enter / arrow keys move down the same column instead of submitting */
  document.querySelectorAll('.inp').forEach(inp => {
    inp.addEventListener('keydown', function (e) {
      if (['Enter', 'ArrowDown', 'ArrowUp'].indexOf(e.key) === -1) return;
      e.preventDefault();
      const cls = this.classList.contains('phone-inp') ? '.phone-inp' : '.name-inp';
      const all = Array.from(document.querySelectorAll('table.contacts tbody tr:not(.row-hidden) ' + cls));
      const i = all.indexOf(this);
      const t = all[e.key === 'ArrowUp' ? i - 1 : i + 1];
      if (t) { t.focus(); t.select(); }
    });
  });
</script>

</body>
</html>