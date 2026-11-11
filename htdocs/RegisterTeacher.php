<?php
// RegisterTeacher.php — admin registers a teacher AND the grades/subjects they may upload marks for.
// The assignment matrix posts as assign[grade][]=subject to regT.php, which must call
// saveTeacherAssignments() after inserting the teacher (see instructions).

// Set to true to print PHP errors on this page while debugging a blank screen.
$DEBUG = false;
if ($DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }
 require_once __DIR__ . '/page_guard.php';
include 'conn.php';
require_once 'auth_helpers.php';
require_once 'teacher_assignments_form.php';

requireLogin();
requireAdmin();

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$isHoi     = (($_SESSION['account_type'] ?? '') === 'hoi');
$homeLink  = $isHoi ? 'Hoi.php' : 'teacherpanel.php';
$roleLabel = $isHoi ? 'Head of Institution' : 'Administrator';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Register Teacher — Kanja School</title>
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
      --shadow-sm:0 1px 2px rgba(0,0,0,.4); --shadow:0 6px 24px rgba(0,0,0,.35);
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
    .tb-title small { display:block; font-weight:500; font-size:.72rem; color:var(--muted); }
    .tb-right { display:flex; align-items:center; gap:8px; }
    .icon-btn { width:38px; height:38px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:15px; transition:.15s; text-decoration:none; }
    .icon-btn:hover { background:var(--surface-3); color:var(--text); }
    .btn-gold { display:inline-flex; align-items:center; gap:8px; background:var(--gold); color:#101826; font-weight:700; font-size:.82rem; padding:0 16px; height:38px; border-radius:10px; text-decoration:none; border:none; cursor:pointer; box-shadow:0 6px 16px rgba(240,192,64,.3); transition:.15s; font-family:inherit; }
    .btn-gold:hover { background:#f5cf5e; transform:translateY(-1px); }

    .content { padding:24px 32px 60px; width:100%; max-width:1280px; margin:0 auto; display:flex; flex-direction:column; gap:20px; }
    .page-eyebrow { font-size:.7rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold-d); }
    .page-title { font-size:clamp(1.4rem,2.4vw,1.8rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; }
    .page-sub { color:var(--muted); font-size:.86rem; margin-top:4px; max-width:760px; }

    /* ───────── Flash ───────── */
    .flash { display:flex; align-items:flex-start; gap:12px; border-radius:var(--r); padding:13px 16px; font-size:.86rem; border:1px solid; animation:fadeUp .3s ease both; }
    .flash i.lead { margin-top:2px; flex-shrink:0; font-size:1rem; }
    .flash span { flex:1; }
    .flash .x { background:none; border:none; color:inherit; opacity:.6; cursor:pointer; font-size:14px; padding:2px 4px; }
    .flash .x:hover { opacity:1; }
    .flash.success { background:var(--green-bg); border-color:var(--green-bd); color:var(--green-ink); }
    .flash.error   { background:var(--red-bg);   border-color:var(--red-bd);   color:var(--red-ink); }
    .flash.warn    { background:var(--amber-bg); border-color:var(--amber-bd); color:var(--amber-ink); }

    /* ───────── Cards / form ───────── */
    .split { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:18px; align-items:start; }
    .card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-sm); overflow:hidden; animation:fadeUp .4s ease both; }
    .card-head { padding:18px 24px; border-bottom:1px solid var(--border-2); display:flex; align-items:center; gap:14px; }
    .head-icon { width:42px; height:42px; border-radius:12px; background:var(--gold-pale); color:var(--gold-d); display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
    .card-head h2 { font-size:1.02rem; font-weight:800; letter-spacing:-.2px; line-height:1.2; }
    .card-head p { font-size:.78rem; color:var(--muted); margin-top:1px; }
    .card-body { padding:22px 24px; }

    .section-label { font-size:.66rem; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); display:flex; align-items:center; gap:12px; margin:28px 0 14px; }
    .section-label:first-child { margin-top:0; }
    .section-label::after { content:''; flex:1; height:1px; background:var(--border); }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .span-full { grid-column:1/-1; }
    .field { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .field label { font-size:.76rem; font-weight:700; color:var(--text-2); display:flex; align-items:center; gap:6px; }
    .req { color:var(--gold-d); font-size:.9rem; line-height:1; }
    .opt-tag { font-size:.62rem; color:var(--muted); background:var(--surface-3); border-radius:6px; padding:1px 7px; font-weight:600; letter-spacing:.3px; }
    .input-wrap { position:relative; }
    .input-wrap > i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:13px; pointer-events:none; }
    .field input[type="text"], .field input[type="email"], .field input[type="tel"], .field select {
      font-family:inherit; font-size:.9rem; color:var(--text); background:var(--surface-2); border:1.5px solid var(--border); border-radius:11px;
      padding:0 14px; height:44px; width:100%; outline:none; transition:border-color .15s,box-shadow .15s,background .15s;
    }
    .input-wrap input { padding-left:38px !important; }
    .field input::placeholder { color:var(--muted); }
    .field input:hover, .field select:hover { border-color:rgba(37,99,235,.35); }
    .field input:focus, .field select:focus { border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); background:var(--surface); }
    .field input.error, .field select.error { border-color:var(--red); box-shadow:0 0 0 3px rgba(220,38,38,.12); }
    .field select { appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; padding-right:38px; cursor:pointer; }
    .field-hint { font-size:.72rem; color:var(--muted); }
    .field-err { display:none; font-size:.72rem; color:var(--red); font-weight:600; }
    .field .error ~ .field-err, .input-wrap .error ~ .field-err { display:block; }
    .field.has-err .field-err { display:block; }

    /* ───────── Teaching assignments (matrix from teacher_assignments_form.php) ───────── */
    .assign-intro { font-size:.8rem; color:var(--muted); margin:-4px 0 12px; line-height:1.6; }
    .ta-wrap { border:1.5px solid var(--border) !important; background:var(--surface-2) !important; border-radius:14px !important; margin:0 !important; padding:4px 6px !important; color:var(--text) !important; }
    .ta-wrap .ta-title, .ta-wrap .ta-hint { display:none !important; }
    .ta-row { border-top:1px solid var(--border-2) !important; padding-top:12px !important; padding-bottom:12px !important; }
    .ta-row:first-of-type { border-top:none !important; }
    .ta-grade { color:var(--text) !important; font-weight:800 !important; cursor:pointer; }
    .ta-sub { color:var(--text-2) !important; cursor:pointer; display:inline-flex; align-items:center; gap:6px; padding:4px 11px; margin:3px 6px 3px 0; border-radius:99px; background:var(--surface); border:1px solid var(--border); font-size:.8rem !important; font-weight:600; transition:.15s; }
    .ta-sub:hover { border-color:var(--gold); color:var(--text) !important; }
    .ta-wrap input[type="checkbox"] { accent-color:var(--gold-d); width:15px; height:15px; cursor:pointer; flex-shrink:0; }

    .form-footer { padding:16px 24px; background:var(--surface-2); border-top:1px solid var(--border-2); display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .footer-note { font-size:.78rem; color:var(--muted); display:flex; align-items:center; gap:8px; }
    .footer-note i { color:var(--gold-d); }
    .btn-actions { display:flex; gap:10px; flex-wrap:wrap; }
    .btn { background:var(--navy); color:#fff; border:none; border-radius:11px; padding:0 22px; height:44px; font-family:inherit; font-size:.86rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; justify-content:center; gap:9px; text-decoration:none; white-space:nowrap; }
    .btn:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 8px 20px rgba(11,31,58,.25); }
    .btn:active:not(:disabled) { transform:scale(.98); }
    .btn:disabled { opacity:.5; cursor:not-allowed; }
    .btn.gold { background:var(--gold); color:#101826; box-shadow:0 8px 20px rgba(240,192,64,.28); }
    .btn.ghost { background:transparent; color:var(--text-2); border:1.5px solid var(--border); }
    .btn.ghost:hover:not(:disabled) { background:var(--surface-3); box-shadow:none; }

    /* ───────── Live preview ───────── */
    .side-stack { display:flex; flex-direction:column; gap:18px; position:sticky; top:calc(var(--topbar-h) + 18px); }
    .pv { padding:22px; text-align:center; }
    .pv-av { width:72px; height:72px; border-radius:20px; margin:0 auto 12px; display:flex; align-items:center; justify-content:center; background:linear-gradient(135deg,var(--navy),#1c4a80); color:var(--gold); font-size:1.5rem; font-weight:800; letter-spacing:1px; }
    .pv-name { font-weight:800; font-size:1.02rem; letter-spacing:-.2px; line-height:1.3; word-break:break-word; }
    .pv-name.ph { color:var(--muted); font-weight:600; }
    .pv-meta { display:flex; justify-content:center; gap:8px; flex-wrap:wrap; margin-top:10px; }
    .pv-chip { font-size:.7rem; font-weight:700; padding:3px 11px; border-radius:99px; background:var(--surface-3); color:var(--text-2); }
    .pv-chip.gold { background:var(--gold-pale); color:var(--gold-ink); }
    .pv-rows { margin-top:16px; border-top:1px dashed var(--border); padding-top:12px; text-align:left; }
    .pv-row { display:flex; justify-content:space-between; gap:10px; font-size:.78rem; padding:4px 0; }
    .pv-row span:first-child { color:var(--muted); flex-shrink:0; }
    .pv-row span:last-child { font-weight:600; color:var(--text); text-align:right; word-break:break-all; }
    .assign-sum { padding:18px 20px; }
    .assign-sum h4, .tips h4 { font-size:.7rem; font-weight:800; letter-spacing:1.4px; text-transform:uppercase; color:var(--muted); margin-bottom:10px; }
    .big { font-size:2rem; font-weight:800; letter-spacing:-1px; line-height:1.1; font-variant-numeric:tabular-nums; }
    .big small { font-size:.8rem; font-weight:600; color:var(--muted); letter-spacing:0; margin-left:4px; }
    .grade-dots { display:flex; flex-wrap:wrap; gap:5px; margin-top:12px; }
    .grade-dots span { font-size:.68rem; font-weight:800; padding:2px 9px; border-radius:99px; background:var(--gold-pale); color:var(--gold-ink); }
    .grade-dots .none { background:none; color:var(--muted); font-weight:500; padding:0; }
    .tips { padding:18px 20px; }
    .tip { display:flex; gap:10px; font-size:.8rem; color:var(--text-2); padding:6px 0; line-height:1.5; }
    .tip i { color:var(--gold-d); margin-top:3px; flex-shrink:0; font-size:.8rem; }

    .footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (max-width:1100px) { .split { grid-template-columns:1fr; } .side-stack { position:static; flex-direction:row; flex-wrap:wrap; } .side-stack > * { flex:1; min-width:260px; } }
    @media (max-width:1024px) {
      .app { grid-template-columns:1fr; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); }
      .sidebar.open { left:0; }
      .burger { display:inline-flex; align-items:center; justify-content:center; }
      .topbar { padding:0 18px; } .content { padding:18px 18px 50px; }
    }
    @media (max-width:760px) { .form-grid { grid-template-columns:1fr; } .span-full { grid-column:1; } }
    @media (max-width:640px) {
      .btn-gold span { display:none; } .tb-title small { display:none; }
      .card-body { padding:18px; } .card-head { padding:16px 18px; }
      .form-footer { flex-direction:column; align-items:stretch; } .btn-actions { flex-direction:column; } .form-footer .btn { width:100%; }
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
        <a href="<?= htmlspecialchars($homeLink, ENT_QUOTES, 'UTF-8') ?>"><i class="fa-solid fa-house"></i> Home</a>
        <a href="Students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="Progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Administration</div>
        <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
       
        <a href="ManageExamTypes.php"><i class="fa-solid fa-clipboard-list"></i> Exam Types</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
      </div>
     
    </nav>

    <div class="side-user">
      <div class="av"><?= $isHoi ? 'HOI' : 'AD' ?></div>
      <div><b><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></b><small>Signed in</small></div>
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
        <div class="tb-title">Teacher Registration<small>Stephen Kanja Primary &amp; Junior School</small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="ManageExamTypes.php" class="btn-gold"><i class="fa-solid fa-clipboard-list"></i><span>Exam Types</span></a>
        <a href="index.php" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <div>
        <p class="page-eyebrow">Administration</p>
        <h1 class="page-title">Register New Teacher</h1>
        <p class="page-sub">Add a staff member and choose exactly which grades and subjects they can upload marks for.</p>
      </div>

      <?php
      /* ── Flash message after redirect ── */
      if (isset($_GET['success'])): ?>
      <div class="flash success" role="status">
        <i class="fa-solid fa-circle-check lead"></i>
        <span>Teacher registered successfully. You can add another below.</span>
        <button type="button" class="x" aria-label="Dismiss" onclick="this.closest('.flash').remove()"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <?php elseif (isset($_GET['assign_error'])): ?>
      <div class="flash warn" role="status">
        <i class="fa-solid fa-triangle-exclamation lead"></i>
        <span>The teacher was saved, but their grades and subjects could not be saved. Please assign them again.</span>
        <button type="button" class="x" aria-label="Dismiss" onclick="this.closest('.flash').remove()"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <?php elseif (isset($_GET['error'])): ?>
      <div class="flash error" role="status">
        <i class="fa-solid fa-circle-exclamation lead"></i>
        <span>Something went wrong. Please check your details and try again.</span>
        <button type="button" class="x" aria-label="Dismiss" onclick="this.closest('.flash').remove()"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <?php endif; ?>

      <div class="split">

        <!-- FORM CARD -->
        <div class="card">
          <div class="card-head">
            <div class="head-icon"><i class="fa-solid fa-chalkboard-user"></i></div>
            <div>
              <h2>Teacher Details</h2>
              <p>Complete all required fields marked with ✦</p>
            </div>
          </div>

          <form method="POST" action="regT.php" id="teacherForm">
            <div class="card-body">

              <!-- Personal info -->
              <p class="section-label">Personal Information</p>
              <div class="form-grid">

                <div class="field span-full">
                  <label for="name"><span class="req">✦</span> Full Name</label>
                  <div class="input-wrap">
                    <i class="fa-solid fa-user"></i>
                    <input type="text" id="name" name="name" placeholder="e.g. Jane Wanjiku Mwangi" required autocomplete="off">
                    <span class="field-err">Full name is required</span>
                  </div>
                </div>

                <div class="field">
                  <label for="email"><span class="req">✦</span> Email Address</label>
                  <div class="input-wrap">
                    <i class="fa-solid fa-envelope"></i>
                    <input type="email" id="email" name="email" placeholder="jane@school.ac.ke" required autocomplete="off">
                    <span class="field-err">Enter a valid email address</span>
                  </div>
                </div>

                <div class="field">
                  <label for="phone"><span class="req">✦</span> Phone Number</label>
                  <div class="input-wrap">
                    <i class="fa-solid fa-phone"></i>
                    <input type="tel" id="phone" name="phone" placeholder="07XX XXX XXX" required autocomplete="off">
                    <span class="field-err">Phone number is required</span>
                  </div>
                </div>

              </div>

              <!-- Professional info -->
              <p class="section-label">Professional Details</p>
              <div class="form-grid">

                <div class="field">
                  <label for="tsc">TSC Number <span class="opt-tag">Optional</span></label>
                  <div class="input-wrap">
                    <i class="fa-solid fa-id-badge"></i>
                    <input type="text" id="tsc" name="tsc" placeholder="e.g. 0123456" autocomplete="off">
                  </div>
                  <span class="field-hint">Teachers Service Commission registration number</span>
                </div>

                <div class="field">
                  <label for="role"><span class="req">✦</span> Role / Designation</label>
                  <select id="role" name="role" required>
                    <option value="" disabled selected>— Select role —</option>
                    <option value="teacher">Teacher</option>
                    <option value="head_teacher">Head Teacher</option>
                    <option value="deputy_head">Deputy Head Teacher</option>
                    <option value="senior_teacher">Senior Teacher</option>
                    <option value="support_staff">Support Staff</option>
                  </select>
                  <span class="field-err">Please choose a role</span>
                </div>

                <div class="field">
                  <label for="subject">Subject of Profession <span class="opt-tag">Optional</span></label>
                  <div class="input-wrap">
                    <i class="fa-solid fa-book-open"></i>
                    <input type="text" id="subject" name="subject" placeholder="e.g. Mathematics" autocomplete="off">
                  </div>
                </div>

                <div class="field">
                  <label for="grade">Class Teacher For <span class="opt-tag">Optional</span></label>
                  <select id="grade" name="grade">
                    <option value="">— Not a class teacher —</option>
                    <?php for ($g = 1; $g <= 9; $g++): ?>
                      <option value="<?= $g ?>">Grade <?= $g ?></option>
                    <?php endfor; ?>
                  </select>
                  <span class="field-hint">Leave blank if this teacher has no assigned class</span>
                </div>

              </div>

              <!-- Teaching assignments -->
              <p class="section-label">Teaching Assignments (Upload Results access)</p>
              <p class="assign-intro">
                Tick the subjects this teacher teaches in each grade. They will only be able to upload marks for
                these grade/subject combinations. Tick a grade's checkbox to select all its subjects.
              </p>
              <?php renderTeacherAssignmentsFields($conn, 0); ?>

            </div><!-- end .card-body -->

            <div class="form-footer">
              <span class="footer-note">
                <i class="fa-solid fa-lock"></i>
                Teacher records are saved securely to the school database.
              </span>
              <div class="btn-actions">
                <button type="reset" class="btn ghost" id="resetBtn"><i class="fa-solid fa-rotate-left"></i> Clear Form</button>
                <button type="submit" class="btn gold" id="saveBtn">
                  <i class="fa-solid fa-user-plus"></i> Register Teacher
                </button>
              </div>
            </div>

          </form>
        </div>

        <!-- Live preview + summary -->
        <aside class="side-stack" aria-label="Teacher preview">
          <div class="card pv">
            <div class="pv-av" id="pvInitials">?</div>
            <div class="pv-name ph" id="pvName">New teacher</div>
            <div class="pv-meta">
              <span class="pv-chip gold" id="pvRole">No role yet</span>
              <span class="pv-chip" id="pvClass">No class</span>
            </div>
            <div class="pv-rows">
              <div class="pv-row"><span>Email</span><span id="pvEmail">—</span></div>
              <div class="pv-row"><span>Phone</span><span id="pvPhone">—</span></div>
              <div class="pv-row"><span>TSC No</span><span id="pvTsc">—</span></div>
              <div class="pv-row"><span>Subject</span><span id="pvSubject">—</span></div>
            </div>
          </div>

          <div class="card assign-sum">
            <h4>Upload access</h4>
            <div class="big"><span id="asCount">0</span><small>grade / subject pairs</small></div>
            <div class="grade-dots" id="asGrades"><span class="none">No grades selected yet</span></div>
          </div>

          <div class="card tips">
            <h4>Good to know</h4>
            <div class="tip"><i class="fa-solid fa-circle-check"></i><span>Teachers can upload marks only for the grades and subjects ticked here.</span></div>
            <div class="tip"><i class="fa-solid fa-circle-check"></i><span>Ticking a grade's checkbox selects all of its subjects at once.</span></div>
          </div>
        </aside>

      </div>

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
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

  /* ── Theme (same key as the other dashboards) ── */
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

  /* ── Validation (required fields) ── */
  const form = document.getElementById('teacherForm');
  const requiredIds = ['name', 'email', 'phone', 'role'];

  function fieldOk(el) {
    if (!el.value.trim()) return false;
    if (el.type === 'email') return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(el.value.trim());
    return true;
  }
  form.addEventListener('submit', function (e) {
    let firstBad = null;
    requiredIds.forEach(id => {
      const el = document.getElementById(id);
      const ok = fieldOk(el);
      el.classList.toggle('error', !ok);
      if (!ok && !firstBad) firstBad = el;
    });
    if (firstBad) { e.preventDefault(); firstBad.focus(); return; }
    const b = document.getElementById('saveBtn');
    b.disabled = true;
    b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
  });
  requiredIds.forEach(id => {
    const el = document.getElementById(id);
    el.addEventListener('input',  () => el.classList.remove('error'));
    el.addEventListener('change', () => el.classList.remove('error'));
  });

  /* ── Live preview ── */
  (function () {
    const $ = id => document.getElementById(id);
    const f = { name: $('name'), email: $('email'), phone: $('phone'), tsc: $('tsc'), role: $('role'), subject: $('subject'), grade: $('grade') };
    function update() {
      const nm = f.name.value.trim();
      const parts = nm.split(/\s+/).filter(Boolean);
      const ini = ((parts[0] || '')[0] || '') + ((parts.length > 1 ? parts[parts.length - 1] : '')[0] || '');
      $('pvInitials').textContent = ini.toUpperCase() || '?';
      $('pvName').textContent = nm || 'New teacher';
      $('pvName').classList.toggle('ph', !nm);
      const roleTxt = f.role.value ? f.role.options[f.role.selectedIndex].text : 'No role yet';
      $('pvRole').textContent = roleTxt;
      $('pvClass').textContent = f.grade.value ? 'Class teacher · Grade ' + f.grade.value : 'No class';
      $('pvEmail').textContent   = f.email.value.trim()   || '—';
      $('pvPhone').textContent   = f.phone.value.trim()   || '—';
      $('pvTsc').textContent     = f.tsc.value.trim()     || '—';
      $('pvSubject').textContent = f.subject.value.trim() || '—';
    }
    Object.values(f).forEach(el => { el.addEventListener('input', update); el.addEventListener('change', update); });
    document.getElementById('resetBtn').addEventListener('click', () => setTimeout(() => { update(); summarise(); }, 0));
    update();
  })();

  /* ── Assignment summary: counts ticked assign[grade][] boxes ── */
  function summarise() {
    const boxes = form.querySelectorAll('input[type="checkbox"][name^="assign["]:checked');
    const grades = new Set();
    boxes.forEach(b => { const m = b.name.match(/^assign\[(\d+)\]/); if (m) grades.add(parseInt(m[1], 10)); });
    document.getElementById('asCount').textContent = boxes.length;
    const wrap = document.getElementById('asGrades');
    const list = [...grades].sort((a, b) => a - b);
    wrap.innerHTML = list.length
      ? list.map(g => '<span>Grade ' + g + '</span>').join('')
      : '<span class="none">No grades selected yet</span>';
  }
  form.addEventListener('change', e => { if (e.target.matches('input[type="checkbox"]')) setTimeout(summarise, 0); });
  summarise();

  /* ── Auto-dismiss flash after 6 s ── */
  document.querySelectorAll('.flash').forEach(el => {
    setTimeout(() => {
      if (!el.isConnected) return;
      el.style.transition = 'opacity .4s'; el.style.opacity = '0';
      setTimeout(() => el.remove(), 400);
    }, 6000);
  });
</script>
</body>
</html>