<?php
     require_once __DIR__ . '/page_guard.php';
include 'logger.php';
require_once 'auth_helpers.php';   // role helpers (starts the session if logger.php hasn't)
include 'conn.php';

$isAdminUser = isAdmin();

$students = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM Student WHERE role='user'"))['c'] ?? 0);
$teachers = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM Teachers"))['c'] ?? 0);
$classes  = 9;

date_default_timezone_set('Africa/Nairobi');
$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$todayStr = date('l, j F Y');
$roleLabel = $isAdminUser ? 'Administrator' : 'Teacher';

/* Quick-action groups: [href, icon, title, description, adminOnly] */
$groups = [
  'Academics' => [
    ['UploadResults.php',        'fa-file-circle-check',   'Upload Results',      'Submit exam scores',            false],
    ['ViewResults.php',          'fa-chart-pie',           'Results',             'View exam results',             false],
    ['ViewResults.php',          'fa-book-open-reader',    'Academic Journey',    'Full academic history',         false],
    ['send_results_sms.php',     'fa-comment-sms',         'Send Results SMS',    'Text results to parents',       false],
    ['download_template.php',    'fa-file-arrow-down',     'Get Marks Template',  'Download the marks sheet',      false],
    ['track.php',                'fa-bullseye',            'Track Learners',      'Monitor performance',           false],
    ['LearningMaterials.php',    'fa-book',                'Learning Materials',  'Resources & documents',         false],
  ],
  'Learners' => [
    ['Students.php',               'fa-user-graduate', 'Students',         'Browse enrolled learners',     false],
    ['Progress.php',               'fa-chart-line',    'Progress Records', 'Track student progress',       false],
    ['RegisterStudent.php',        'fa-user-plus',     'Register Learner', 'Enroll a new student',         false],
    ['manage_parent_contacts.php', 'fa-address-book',  'Parent Contacts',  'Set guardian phone numbers',   false],
  ],
  'Administration' => [
    ['RegisterTeacher.php',  'fa-chalkboard-user', 'Register Teacher', 'Add staff and assign grades & subjects', true],
    ['ManageExamTypes.php',  'fa-clipboard-list',  'Add Exam Types',   'Create or remove exam names',            true],
  ],
];
function tp_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $isAdminUser ? 'Admin' : 'Teacher' ?> Panel — Kanja School</title>
  <script>
    /* Apply saved theme before first paint (shared with the HOI dashboard) */
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
      --shadow-sm:0 1px 2px rgba(15,23,42,.05);
      --shadow:0 6px 24px rgba(15,23,42,.07);
      --shadow-lg:0 18px 50px rgba(15,23,42,.14);
      --r:14px; --r-lg:18px;
      --sidebar-w:264px; --topbar-h:64px;
      color-scheme: light;
    }
    [data-theme="dark"] {
      --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
      --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3;
      --border:#1f2d47; --border-2:#192740;
      --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040;
      --shadow-sm:0 1px 2px rgba(0,0,0,.4); --shadow:0 6px 24px rgba(0,0,0,.35); --shadow-lg:0 18px 50px rgba(0,0,0,.5);
      color-scheme: dark;
    }

    html { scroll-behavior:smooth; }
    body { font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14.5px; line-height:1.6; -webkit-font-smoothing:antialiased; transition:background .25s,color .25s; }
    ::-webkit-scrollbar { width:8px; height:8px; }
    ::-webkit-scrollbar-thumb { background:rgba(120,135,160,.35); border-radius:99px; }
    :focus-visible { outline:2px solid var(--gold); outline-offset:2px; border-radius:6px; }
    .skip { position:absolute; left:-999px; top:8px; background:var(--gold); color:#000; padding:8px 14px; border-radius:8px; font-weight:700; z-index:300; }
    .skip:focus { left:12px; }

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
    .burger { display:none; background:none; border:1px solid var(--border); width:38px; height:38px; border-radius:10px; cursor:pointer; color:var(--text); }
    .burger:hover { background:var(--surface-3); }
    .tb-title { font-weight:800; font-size:1.02rem; letter-spacing:-.2px; line-height:1.2; }
    .tb-title small { display:block; font-weight:500; font-size:.72rem; color:var(--muted); letter-spacing:.2px; }
    .tb-right { display:flex; align-items:center; gap:8px; }
    .icon-btn { width:38px; height:38px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:15px; transition:.15s; text-decoration:none; }
    .icon-btn:hover { background:var(--surface-3); color:var(--text); }
    .btn-gold { display:inline-flex; align-items:center; gap:8px; background:var(--gold); color:#101826; font-weight:700; font-size:.82rem; padding:0 16px; height:38px; border-radius:10px; text-decoration:none; border:none; cursor:pointer; box-shadow:0 6px 16px rgba(240,192,64,.3); transition:.15s; font-family:inherit; }
    .btn-gold:hover { background:#f5cf5e; transform:translateY(-1px); }

    .content { padding:24px 32px 60px; width:100%; max-width:1400px; margin:0 auto; display:flex; flex-direction:column; gap:26px; }

    /* ───────── Hero ───────── */
    .hero { padding:30px 32px; border-radius:var(--r-lg); color:#fff; position:relative; overflow:hidden; background:linear-gradient(120deg,#0b1f3a 0%,#173a66 60%,#1c4a80 100%); box-shadow:var(--shadow); animation:fadeUp .45s ease both; }
    .hero::before { content:''; position:absolute; inset:0; background:radial-gradient(520px 260px at 6% 0%,rgba(240,192,64,.28),transparent 70%), radial-gradient(420px 260px at 100% 100%,rgba(34,197,94,.22),transparent 70%); pointer-events:none; }
    .hero-inner { position:relative; display:flex; align-items:center; justify-content:space-between; gap:24px; flex-wrap:wrap; }
    .hero-eyebrow { font-size:.72rem; letter-spacing:2px; text-transform:uppercase; color:rgba(255,255,255,.6); font-weight:700; }
    .hero h1 { font-size:clamp(1.35rem,2.6vw,2rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; margin-top:4px; }
    .hero h1 em { font-style:normal; color:var(--gold); }
    .hero p { color:rgba(255,255,255,.72); font-size:.88rem; margin-top:6px; max-width:520px; }
    .hero-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; }
    .hero-btn { display:inline-flex; align-items:center; gap:8px; height:40px; padding:0 18px; border-radius:11px; font-weight:700; font-size:.82rem; text-decoration:none; transition:.15s; }
    .hero-btn.primary { background:var(--gold); color:#101826; box-shadow:0 8px 20px rgba(240,192,64,.32); }
    .hero-btn.primary:hover { background:#f5cf5e; transform:translateY(-1px); }
    .hero-btn.ghost { background:rgba(255,255,255,.1); color:#fff; border:1px solid rgba(255,255,255,.2); }
    .hero-btn.ghost:hover { background:rgba(255,255,255,.18); }
    .hero-meta { display:flex; gap:10px; flex-wrap:wrap; }
    .hero-pill { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.16); backdrop-filter:blur(4px); border-radius:14px; padding:12px 18px; min-width:118px; }
    .hero-pill small { display:flex; align-items:center; gap:7px; font-size:.64rem; letter-spacing:1.3px; text-transform:uppercase; color:rgba(255,255,255,.62); font-weight:700; }
    .hero-pill small i { color:var(--gold); font-size:.78rem; }
    .hero-pill b { display:block; font-size:1.6rem; font-weight:800; line-height:1.2; margin-top:2px; font-variant-numeric:tabular-nums; }

    /* ───────── Toolbar (search) ───────── */
    .toolbar { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .toolbar h2 { font-size:1.2rem; font-weight:800; letter-spacing:-.3px; display:flex; align-items:center; gap:10px; }
    .toolbar h2 i { width:32px; height:32px; border-radius:10px; background:var(--gold-pale); color:var(--gold-d); display:inline-flex; align-items:center; justify-content:center; font-size:14px; }
    .searchbox { position:relative; width:min(360px,100%); }
    .searchbox i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:13px; pointer-events:none; }
    .searchbox input { width:100%; height:42px; border-radius:12px; border:1.5px solid var(--border); background:var(--surface); color:var(--text); font-family:inherit; font-size:.88rem; padding:0 52px 0 38px; transition:.15s; }
    .searchbox input:hover { border-color:rgba(37,99,235,.35); }
    .searchbox input:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .searchbox kbd { position:absolute; right:12px; top:50%; transform:translateY(-50%); font-family:inherit; font-size:.68rem; font-weight:700; color:var(--muted); background:var(--surface-3); border:1px solid var(--border); border-radius:6px; padding:1px 7px; }

    /* ───────── Groups & cards ───────── */
    .group { animation:fadeUp .45s ease both; }
    .group-head { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
    .group-head h3 { font-size:.72rem; font-weight:800; letter-spacing:1.6px; text-transform:uppercase; color:var(--muted); white-space:nowrap; }
    .group-head::after { content:''; flex:1; height:1px; background:var(--border); }
    .group-count { order:3; font-size:.66rem; font-weight:800; background:var(--surface-3); color:var(--text-2); border-radius:99px; padding:2px 10px; }

    .cards-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:14px; }
    .nav-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:16px 18px; display:flex; align-items:center; gap:14px; text-decoration:none; color:var(--text); position:relative; transition:transform .2s,box-shadow .2s,border-color .2s; animation:fadeUp .45s ease both; }
    .nav-card:hover { transform:translateY(-3px); box-shadow:var(--shadow); border-color:rgba(240,192,64,.7); }
    .card-icon { width:44px; height:44px; border-radius:12px; background:color-mix(in srgb,var(--accent) 15%,transparent); color:var(--accent); display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; transition:.2s; }
    .nav-card:hover .card-icon { background:var(--accent); color:#fff; }
    .card-body { min-width:0; }
    .card-title { display:block; font-size:.92rem; font-weight:700; line-height:1.25; }
    .card-desc { display:block; font-size:.76rem; color:var(--muted); line-height:1.4; margin-top:2px; }
    .card-arrow { margin-left:auto; font-size:12px; color:var(--muted); opacity:0; transform:translateX(-6px); transition:.2s; }
    .nav-card:hover .card-arrow { opacity:1; transform:translateX(0); color:var(--gold-d); }
    .acc-academics { --accent:#2563eb; } .acc-learners { --accent:#16a34a; } .acc-administration { --accent:#d9a521; }
    .empty { display:none; text-align:center; color:var(--muted); padding:40px 10px; border:1.5px dashed var(--border); border-radius:var(--r); }
    .empty i { font-size:1.5rem; display:block; margin-bottom:8px; }
    .empty.show { display:block; }

    .footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (max-width:1024px) {
      .app { grid-template-columns:1fr; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); }
      .sidebar.open { left:0; }
      .burger { display:inline-flex; align-items:center; justify-content:center; }
      .topbar { padding:0 18px; } .content { padding:18px 18px 50px; }
    }
    @media (max-width:640px) {
      .btn-gold span { display:none; }
      .hero { padding:22px 18px; } .hero-pill { min-width:0; flex:1; padding:10px 12px; } .hero-pill b { font-size:1.3rem; }
      .tb-title small { display:none; }
      .cards-grid { grid-template-columns:1fr; }
      .searchbox { width:100%; } .searchbox kbd { display:none; }
    }
  </style>
</head>
<body>
<a href="#main-content" class="skip">Skip to content</a>

<div class="app">

  <!-- ═══════ SIDEBAR ═══════ -->
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
        <a href="teacherpanel.php" class="active" aria-current="page"><i class="fa-solid fa-house"></i> Home</a>
        <a href="Students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="Progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
        <a href="download_template.php"><i class="fa-solid fa-file-arrow-down"></i> Get Marks Template</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="send_results_sms.php"><i class="fa-solid fa-comment-sms"></i> Send Results SMS</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
        <a href="LearningMaterials.php"><i class="fa-solid fa-book"></i> Learning Materials</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Administration</div>
        <?php if ($isAdminUser): ?>
        <a href="RegisterTeacher.php"><i class="fa-solid fa-chalkboard-user"></i> Register Teachers</a>
        <a href="ManageExamTypes.php"><i class="fa-solid fa-clipboard-list"></i> Add Exam Types</a>
        <?php endif; ?>
        <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
        <a href="manage_parent_contacts.php"><i class="fa-solid fa-address-book"></i> Parent Contacts</a>
      </div>
    </nav>

    <div class="side-user">
      <div class="av"><?= $isAdminUser ? 'AD' : 'TR' ?></div>
      <div><b><?= tp_e($roleLabel) ?></b><small><?= $isAdminUser ? 'Full access' : 'Teacher panel' ?></small></div>
      <a href="logout.php" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>
  <div class="backdrop" id="backdrop" onclick="closeSidebar()"></div>

  <!-- ═══════ MAIN ═══════ -->
  <div class="main">

    <header class="topbar">
      <div class="tb-left">
        <button class="burger" id="burger" type="button" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
        <div class="tb-title"><?= $isAdminUser ? 'Admin' : 'Teacher' ?> Dashboard<small><?= tp_e($todayStr) ?></small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="UploadResults.php" class="btn-gold"><i class="fa-solid fa-upload"></i><span>Upload Results</span></a>
        <a href="logout.php" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <section class="hero" aria-label="Welcome">
        <div class="hero-inner">
          <div>
            <div class="hero-eyebrow"><?= $isAdminUser ? 'Admin Dashboard' : 'Teacher Dashboard' ?></div>
            <h1><?= tp_e($greeting) ?> <em>✦</em></h1>
            <p>Stephen Kanja Primary &amp; Junior School — Academic Portal. Jump straight to what you need below.</p>
            <div class="hero-actions">
              <a href="UploadResults.php" class="hero-btn primary"><i class="fa-solid fa-file-circle-check"></i> Upload Results</a>
              <a href="ViewResults.php" class="hero-btn ghost"><i class="fa-solid fa-chart-pie"></i> View Results</a>
            </div>
          </div>
          <div class="hero-meta">
            <div class="hero-pill"><small><i class="fa-solid fa-user-graduate"></i> Learners</small><b><?= number_format($students) ?></b></div>
            <div class="hero-pill"><small><i class="fa-solid fa-chalkboard-user"></i> Teachers</small><b><?= number_format($teachers) ?></b></div>
            <div class="hero-pill"><small><i class="fa-solid fa-door-open"></i> Classes</small><b><?= $classes ?></b></div>
          </div>
        </div>
      </section>

      <div class="toolbar">
        <h2><i class="fa-solid fa-bolt"></i> Quick Navigation</h2>
        <div class="searchbox">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="search" id="cardSearch" placeholder="Search tools…" autocomplete="off" aria-label="Search tools">
          <kbd>/</kbd>
        </div>
      </div>

      <?php foreach ($groups as $gName => $items):
          $visible = array_filter($items, fn($it) => !$it[4] || $isAdminUser);
          if (!$visible) continue;
          $acc = 'acc-' . strtolower($gName);
          $delay = 0;
      ?>
      <section class="group" data-group>
        <div class="group-head"><h3><?= tp_e($gName) ?></h3><span class="group-count"><?= count($visible) ?></span></div>
        <div class="cards-grid">
          <?php foreach ($visible as $it): $delay += 0.04; ?>
          <a href="<?= tp_e($it[0]) ?>" class="nav-card <?= $acc ?>" style="animation-delay:<?= number_format($delay, 2) ?>s" data-s="<?= tp_e(strtolower($it[2] . ' ' . $it[3])) ?>">
            <div class="card-icon"><i class="fa-solid <?= tp_e($it[1]) ?>"></i></div>
            <div class="card-body">
              <span class="card-title"><?= tp_e($it[2]) ?></span>
              <span class="card-desc"><?= tp_e($it[3]) ?></span>
            </div>
            <i class="fa-solid fa-arrow-right card-arrow"></i>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>

      <div class="empty" id="emptyMsg"><i class="fa-regular fa-face-frown"></i>No tools match your search.</div>

    </main>

    <div class="footer">&copy; Designed by <strong>Kelvin Mutinda</strong> 2026. All rights reserved.</div>
  </div>
</div>

<script>
  /* ── Sidebar ── */
  const sidebar  = document.getElementById('sidebar');
  const backdrop = document.getElementById('backdrop');
  const burger   = document.getElementById('burger');
  function openSidebar()  { sidebar.classList.add('open');    backdrop.classList.add('show');    burger.innerHTML = '<i class="fa-solid fa-xmark"></i>'; document.body.style.overflow = 'hidden'; }
  function closeSidebar() { sidebar.classList.remove('open'); backdrop.classList.remove('show'); burger.innerHTML = '<i class="fa-solid fa-bars"></i>'; document.body.style.overflow = ''; }
  burger.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());

  /* ── Theme (same key as the HOI dashboard) ── */
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

  /* ── Tool search ("/" to focus, Esc to clear) ── */
  const search = document.getElementById('cardSearch');
  const cards  = [...document.querySelectorAll('.nav-card')];
  const groups = [...document.querySelectorAll('[data-group]')];
  const empty  = document.getElementById('emptyMsg');
  search.addEventListener('input', () => {
    const q = search.value.trim().toLowerCase();
    let any = false;
    cards.forEach(c => { const hit = !q || c.dataset.s.includes(q); c.style.display = hit ? '' : 'none'; if (hit) any = true; });
    groups.forEach(g => { g.style.display = [...g.querySelectorAll('.nav-card')].some(c => c.style.display !== 'none') ? '' : 'none'; });
    empty.classList.toggle('show', !any);
  });
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeSidebar(); if (document.activeElement === search) { search.value = ''; search.dispatchEvent(new Event('input')); search.blur(); } }
    if (e.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) { e.preventDefault(); search.focus(); }
  });
</script>
</body>
</html>