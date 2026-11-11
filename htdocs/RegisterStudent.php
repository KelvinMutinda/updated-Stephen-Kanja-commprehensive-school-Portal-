<?php
/*
 * RegisterStudent.php
 * ====================
 * Manual form  -> /reg            (reg.php)
 * Bulk import  -> /bulk_register  (bulk_register.php)
 * Both add new learners and update existing ones (see student_upsert.php).
 * Only FirstName is required.
 */

// Set to true to print PHP errors on this page while debugging a blank screen.
     require_once __DIR__ . '/page_guard.php';
$DEBUG = false;
if ($DEBUG) { ini_set('display_errors', '1'); error_reporting(E_ALL); }

if (session_status() === PHP_SESSION_NONE) { session_start(); }

function h($v) { return htmlspecialchars(is_scalar($v) ? (string)$v : '', ENT_QUOTES, 'UTF-8'); }
function qs($k) { return (isset($_GET[$k]) && is_scalar($_GET[$k])) ? (string)$_GET[$k] : ''; }
function qn($k) { return (int)qs($k); }

$isHoi     = (($_SESSION['account_type'] ?? '') === 'hoi');
$homeLink  = $isHoi ? 'Hoi.php' : 'teacherpanel.php';
$roleLabel = $isHoi ? 'Head of Institution' : 'Teacher Panel';

/* ── Build flash messages: [type, icon, html] ── */
$flash = [];

if (qs('success') !== '') {
    $flash[] = ['success', 'fa-circle-check', 'Student registered successfully. You may add another below.'];
} elseif (qs('learner_updated') !== '') {
    $who = qs('name') !== '' ? qs('name') : 'This learner';
    $flash[] = ['info', 'fa-pen-to-square', '<strong>' . h($who) . '</strong> is already registered — their details were <strong>updated</strong>.'];
} elseif (qs('error') !== '') {
    $flash[] = ['error', 'fa-circle-exclamation',
        qs('msg') !== '' ? 'Could not save this learner — ' . h(qs('msg'))
                         : 'Something went wrong. Please check your details and try again.'];
}

if (qs('bulk_success') !== '') {
    $imp = qn('bulk_success'); $upd = qn('updated'); $skp = qn('skipped');
    $msg = 'Bulk import complete — <strong>' . $imp . '</strong> new learner' . ($imp !== 1 ? 's' : '') . ' registered, '
         . '<strong>' . $upd . '</strong> existing learner' . ($upd !== 1 ? 's' : '') . ' updated';
    if ($skp > 0) {
        $msg .= ', <strong>' . $skp . '</strong> row' . ($skp !== 1 ? 's' : '') . ' skipped (missing first name or unclear match)';
    }
    $flash[] = ['success', 'fa-circle-check', $msg . '.'];
} elseif (qs('bulk_error') !== '') {
    $flash[] = ['error', 'fa-circle-exclamation', 'Bulk import failed — ' . h(qs('bulk_error'))];
}

/* Open the bulk tab straight after an import */
$openBulk = (qs('bulk_success') !== '' || qs('bulk_error') !== '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Student Registration — Kanja School</title>
  <script>
    /* Apply saved theme before first paint (shared with the other dashboards) */
    try { var th = localStorage.getItem('hoi-theme'); if (th === 'dark' || th === 'light') document.documentElement.setAttribute('data-theme', th); } catch (e) {}
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:#f3f5fa; --surface:#ffffff; --surface-2:#f8f9fc; --surface-3:#eef1f7;
      --text:#0f172a; --text-2:#475569; --muted:#8392a5;
      --border:#e4e8f0; --border-2:#eef1f6;
      --navy:#0b1f3a; --gold:#f0c040; --gold-d:#c8992a; --gold-pale:#fdf3d4; --gold-ink:#7a5a10;
      --green:#16a34a; --green-bg:#dcfce7; --green-bd:#86efac; --green-ink:#166534;
      --red:#dc2626; --red-bg:#fee2e2; --red-bd:#fca5a5; --red-ink:#991b1b;
      --blue:#2563eb; --blue-bg:#dbeafe; --blue-bd:#93c5fd; --blue-ink:#1e3a8a;
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
      --blue:#7db3f0; --blue-bg:rgba(96,165,250,.16); --blue-bd:rgba(96,165,250,.4); --blue-ink:#a9cdf6;
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
    .page-sub { color:var(--muted); font-size:.86rem; margin-top:4px; max-width:780px; }

    /* ───────── Flash ───────── */
    .flash { display:flex; align-items:flex-start; gap:12px; border-radius:var(--r); padding:13px 16px; font-size:.86rem; border:1px solid; animation:fadeUp .3s ease both; }
    .flash i.lead { margin-top:2px; flex-shrink:0; font-size:1rem; }
    .flash span { flex:1; }
    .flash .x { background:none; border:none; color:inherit; opacity:.6; cursor:pointer; font-size:14px; padding:2px 4px; }
    .flash .x:hover { opacity:1; }
    .flash.success { background:var(--green-bg); border-color:var(--green-bd); color:var(--green-ink); }
    .flash.error   { background:var(--red-bg);   border-color:var(--red-bd);   color:var(--red-ink); }
    .flash.info    { background:var(--blue-bg);  border-color:var(--blue-bd);  color:var(--blue-ink); }

    /* ───────── Tabs ───────── */
    .tabs { display:inline-flex; gap:4px; padding:5px; background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow-sm); align-self:flex-start; }
    .tab { display:inline-flex; align-items:center; gap:9px; padding:9px 18px; border:none; background:none; border-radius:10px; color:var(--text-2); font-family:inherit; font-weight:700; font-size:.84rem; cursor:pointer; transition:.15s; }
    .tab:hover { background:var(--surface-3); color:var(--text); }
    .tab.on { background:var(--navy); color:#fff; }
    [data-theme="dark"] .tab.on { background:var(--gold); color:#101826; }
    .panel { display:none; }
    .panel.on { display:block; animation:fadeUp .35s ease both; }

    /* ───────── Cards / form ───────── */
    .split { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:18px; align-items:start; }
    .card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-sm); overflow:hidden; }
    .card-head { padding:18px 24px; border-bottom:1px solid var(--border-2); display:flex; align-items:center; gap:14px; }
    .head-icon { width:42px; height:42px; border-radius:12px; background:var(--gold-pale); color:var(--gold-d); display:flex; align-items:center; justify-content:center; font-size:17px; flex-shrink:0; }
    .head-icon.green { background:var(--green-bg); color:var(--green); }
    .card-head h2 { font-size:1.02rem; font-weight:800; letter-spacing:-.2px; line-height:1.2; }
    .card-head p { font-size:.78rem; color:var(--muted); margin-top:1px; }
    .card-body { padding:22px 24px; }

    .section-label { font-size:.66rem; font-weight:800; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); display:flex; align-items:center; gap:12px; margin:26px 0 14px; }
    .section-label:first-child { margin-top:0; }
    .section-label::after { content:''; flex:1; height:1px; background:var(--border); }
    .field-grid { display:grid; gap:16px; margin-bottom:6px; }
    .cols-1 { grid-template-columns:1fr; } .cols-2 { grid-template-columns:1fr 1fr; } .cols-3 { grid-template-columns:1fr 1fr 1fr; }
    .field { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .field label { font-size:.76rem; font-weight:700; color:var(--text-2); display:flex; align-items:center; gap:6px; }
    .req { color:var(--gold-d); font-size:.9rem; line-height:1; }
    .opt-tag { font-size:.62rem; color:var(--muted); background:var(--surface-3); border-radius:6px; padding:1px 7px; font-weight:600; letter-spacing:.3px; }
    .field input, .field select { font-family:inherit; font-size:.9rem; color:var(--text); background:var(--surface-2); border:1.5px solid var(--border); border-radius:11px; padding:0 14px; height:44px; width:100%; outline:none; transition:border-color .15s,box-shadow .15s,background .15s; }
    .field input::placeholder { color:var(--muted); }
    .field input:hover, .field select:hover { border-color:rgba(37,99,235,.35); }
    .field input:focus, .field select:focus { border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); background:var(--surface); }
    .field input.error { border-color:var(--red); box-shadow:0 0 0 3px rgba(220,38,38,.12); }
    .field select { appearance:none; -webkit-appearance:none; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; padding-right:38px; cursor:pointer; }
    .field-hint { font-size:.72rem; color:var(--muted); }
    .field-err { display:none; font-size:.72rem; color:var(--red); font-weight:600; }
    .field input.error ~ .field-err { display:block; }

    .form-footer { padding:16px 24px; background:var(--surface-2); border-top:1px solid var(--border-2); display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .footer-note { font-size:.78rem; color:var(--muted); display:flex; align-items:center; gap:8px; }
    .footer-note i { color:var(--gold-d); }
    .btn { background:var(--navy); color:#fff; border:none; border-radius:11px; padding:0 22px; height:44px; font-family:inherit; font-size:.86rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; justify-content:center; gap:9px; text-decoration:none; white-space:nowrap; }
    [data-theme="dark"] .btn { background:var(--gold); color:#101826; }
    .btn:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 8px 20px rgba(11,31,58,.25); }
    .btn:active:not(:disabled) { transform:scale(.98); }
    .btn:disabled { opacity:.5; cursor:not-allowed; }
    .btn.gold { background:var(--gold); color:#101826; box-shadow:0 8px 20px rgba(240,192,64,.28); }
    .btn.ghost { background:transparent; color:var(--text-2); border:1.5px solid var(--border); }
    [data-theme="dark"] .btn.ghost { background:transparent; color:var(--text-2); }
    .btn.ghost:hover:not(:disabled) { background:var(--surface-3); box-shadow:none; }
    .btn.sm { height:36px; padding:0 14px; font-size:.78rem; border-radius:10px; }

    /* ───────── Live preview + tips ───────── */
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
    .pv-row span:first-child { color:var(--muted); }
    .pv-row span:last-child { font-weight:600; color:var(--text); text-align:right; word-break:break-all; }
    .tips { padding:18px 20px; }
    .tips h4 { font-size:.7rem; font-weight:800; letter-spacing:1.4px; text-transform:uppercase; color:var(--muted); margin-bottom:10px; }
    .tip { display:flex; gap:10px; font-size:.8rem; color:var(--text-2); padding:6px 0; line-height:1.5; }
    .tip i { color:var(--gold-d); margin-top:3px; flex-shrink:0; font-size:.8rem; }

    /* ───────── Bulk import ───────── */
    .steps { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:22px; }
    .step { display:flex; align-items:center; gap:12px; padding:14px 16px; border-radius:14px; background:var(--surface-2); border:1px solid var(--border-2); }
    .step .n { width:30px; height:30px; border-radius:50%; background:var(--navy); color:var(--gold); font-weight:800; font-size:.82rem; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    [data-theme="dark"] .step .n { background:var(--surface-3); }
    .step b { display:block; font-size:.84rem; line-height:1.2; }
    .step small { color:var(--muted); font-size:.72rem; }
    .info-box { background:var(--surface-2); border:1px solid var(--border); border-radius:14px; padding:14px 18px; margin-bottom:20px; display:flex; align-items:flex-start; gap:12px; }
    .info-box i { color:var(--gold-d); margin-top:3px; flex-shrink:0; }
    .info-box b { font-size:.84rem; display:block; margin-bottom:3px; }
    .info-box p { font-size:.8rem; color:var(--muted); line-height:1.65; }
    .info-box p strong, .info-box p em { color:var(--text-2); }

    .drop-zone { border:2px dashed var(--border); border-radius:var(--r-lg); padding:40px 24px; text-align:center; cursor:pointer; transition:border-color .2s,background .2s,transform .2s; position:relative; background:var(--surface-2); }
    .drop-zone:hover, .drop-zone.drag-over { border-color:var(--gold); background:var(--gold-pale); }
    .drop-zone.drag-over { transform:scale(1.01); }
    .drop-zone input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
    .drop-icon { width:58px; height:58px; border-radius:16px; background:var(--navy); display:flex; align-items:center; justify-content:center; margin:0 auto 14px; box-shadow:0 10px 24px rgba(11,31,58,.25); }
    [data-theme="dark"] .drop-icon { background:var(--surface-3); }
    .drop-icon i { color:var(--gold); font-size:22px; }
    .drop-zone h3 { font-size:1rem; font-weight:800; margin-bottom:2px; }
    .drop-zone p { font-size:.82rem; color:var(--muted); }
    .file-types { display:inline-flex; align-items:center; gap:6px; margin-top:12px; font-size:.7rem; font-weight:700; letter-spacing:.5px; color:var(--muted); }
    .file-types span { background:var(--navy); color:var(--gold); border-radius:6px; padding:2px 8px; font-family:ui-monospace,Menlo,monospace; }
    [data-theme="dark"] .file-types span { background:var(--surface-3); }

    .preview-wrap { display:none; flex-direction:column; gap:14px; margin-top:22px; }
    .preview-wrap.visible { display:flex; }
    .preview-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
    .preview-title { font-size:.9rem; font-weight:800; display:flex; align-items:center; gap:10px; }
    .row-count { font-size:.68rem; font-weight:800; background:var(--navy); color:var(--gold); border-radius:99px; padding:3px 11px; letter-spacing:.5px; }
    [data-theme="dark"] .row-count { background:var(--surface-3); }
    .pf { display:flex; gap:6px; flex-wrap:wrap; }
    .pf button { border:1.5px solid var(--border); background:var(--surface-2); color:var(--text-2); font-family:inherit; font-size:.74rem; font-weight:700; padding:5px 13px; border-radius:99px; cursor:pointer; transition:.15s; }
    .pf button:hover { border-color:var(--gold); }
    .pf button.on { background:var(--navy); color:#fff; border-color:var(--navy); }
    [data-theme="dark"] .pf button.on { background:var(--gold); color:#101826; border-color:var(--gold); }
    .pf button b { opacity:.7; margin-left:4px; }

    .validation-summary { display:none; border-radius:12px; padding:11px 16px; font-size:.84rem; border:1px solid; }
    .validation-summary.has-errors { display:flex; align-items:flex-start; gap:10px; background:var(--red-bg); border-color:var(--red-bd); color:var(--red-ink); }
    .validation-summary.all-good { display:flex; align-items:flex-start; gap:10px; background:var(--green-bg); border-color:var(--green-bd); color:var(--green-ink); }

    .preview-table-wrap { border:1px solid var(--border); border-radius:14px; overflow:hidden; }
    .preview-scroll { overflow:auto; max-height:460px; }
    table.preview-table { width:100%; border-collapse:collapse; font-size:.82rem; min-width:720px; }
    .preview-table thead th { background:var(--surface-2); color:var(--muted); font-size:.64rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; padding:11px 14px; border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; position:sticky; top:0; z-index:1; }
    .preview-table td { padding:10px 14px; border-bottom:1px solid var(--border-2); color:var(--text-2); vertical-align:middle; }
    .preview-table tbody tr:last-child td { border-bottom:none; }
    .preview-table tbody tr:hover td { background:var(--surface-2); }
    .preview-table tbody tr.row-error td { background:var(--red-bg); color:var(--red-ink); }
    .preview-table td:nth-child(4) { color:var(--text); font-weight:700; }
    .row-num { color:var(--muted) !important; font-size:.72rem; font-weight:700; text-align:center; width:40px; }
    .empty-filter { text-align:center; color:var(--muted); padding:30px !important; }

    .status-pill { display:inline-flex; align-items:center; gap:5px; font-size:.7rem; font-weight:700; padding:3px 10px; border-radius:99px; white-space:nowrap; }
    .status-pill.ok  { background:var(--green-bg); color:var(--green); }
    .status-pill.upd { background:var(--blue-bg);  color:var(--blue); }
    .status-pill.err { background:var(--red-bg);   color:var(--red); }

    .upload-progress { display:none; flex-direction:column; gap:8px; }
    .upload-progress.visible { display:flex; }
    .progress-label { font-size:.8rem; font-weight:700; color:var(--text-2); display:flex; justify-content:space-between; }
    .progress-bar-wrap { height:8px; background:var(--surface-3); border-radius:99px; overflow:hidden; }
    .progress-bar-fill { height:100%; background:linear-gradient(90deg,var(--gold),#e0a920); border-radius:99px; width:0%; transition:width .3s ease; }
    .bulk-footer { border:1px solid var(--border); border-radius:14px; }

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
    @media (max-width:760px) { .cols-2, .cols-3 { grid-template-columns:1fr; } .steps { grid-template-columns:1fr; } }
    @media (max-width:640px) {
      .btn-gold span { display:none; } .tb-title small { display:none; }
      .card-body { padding:18px; } .card-head { padding:16px 18px; }
      .form-footer { flex-direction:column; align-items:stretch; } .form-footer .btn { width:100%; }
      .tab { padding:9px 13px; }
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
        <a href="<?= h($homeLink) ?>"><i class="fa-solid fa-house"></i> Home</a>
        <a href="Students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="Progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Administration</div>
        <a href="RegisterStudent.php" class="active" aria-current="page"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
       
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
      </div>
    
    </nav>

    <div class="side-user">
      <div class="av"><?= $isHoi ? 'HOI' : 'TR' ?></div>
      <div><b><?= h($roleLabel) ?></b><small>Signed in</small></div>
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
        <div class="tb-title">Student Registration<small>Stephen Kanja Primary &amp; Junior School</small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="Students.php" class="btn-gold"><i class="fa-solid fa-user-graduate"></i><span>View Students</span></a>
        <a href="logout.php" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <div>
        <p class="page-eyebrow">Administration</p>
        <h1 class="page-title">Register New Learner</h1>
        <p class="page-sub">Add one learner with the form, or import many from a spreadsheet. Only First Name is required. If a learner is already registered, their record is updated instead of being added twice.</p>
      </div>

      <?php foreach ($flash as $f): ?>
      <div class="flash <?= h($f[0]) ?>" role="status">
        <i class="fa-solid <?= h($f[1]) ?> lead"></i>
        <span><?= $f[2] ?></span>
        <button type="button" class="x" aria-label="Dismiss" onclick="this.closest('.flash').remove()"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <?php endforeach; ?>

      <div class="tabs" role="tablist" aria-label="Registration method">
        <button type="button" class="tab<?= $openBulk ? '' : ' on' ?>" role="tab" data-tab="manual" aria-selected="<?= $openBulk ? 'false' : 'true' ?>"><i class="fa-solid fa-user-pen"></i> Manual</button>
        <button type="button" class="tab<?= $openBulk ? ' on' : '' ?>" role="tab" data-tab="bulk" aria-selected="<?= $openBulk ? 'true' : 'false' ?>"><i class="fa-solid fa-file-excel"></i> Bulk import</button>
      </div>

      <!-- ══════════ MANUAL ══════════ -->
      <section class="panel<?= $openBulk ? '' : ' on' ?>" id="panel-manual" role="tabpanel">
        <div class="split">

          <div class="card">
            <div class="card-head">
              <div class="head-icon"><i class="fa-solid fa-user-pen"></i></div>
              <div><h2>Manual Registration</h2><p>Register one student at a time</p></div>
            </div>

            <form method="POST" action="/reg" id="regForm" novalidate>
              <div class="card-body">

                <p class="section-label">Identification</p>
                <div class="field-grid cols-2">
                  <div class="field">
                    <label for="upi">UPI Number <span class="opt-tag">Optional</span></label>
                    <input type="text" id="upi" name="UPI" placeholder="e.g. 12345678" autocomplete="off">
                  </div>
                  <div class="field">
                    <label for="assessment">Assessment Number <span class="opt-tag">Optional</span></label>
                    <input type="text" id="assessment" name="Assesment" placeholder="e.g. A0000001" autocomplete="off">
                  </div>
                </div>
                <div class="field-grid cols-1">
                  <div class="field">
                    <label for="birthNo">Birth Certificate No <span class="opt-tag">Optional</span></label>
                    <input type="text" id="birthNo" name="birthNo" placeholder="Enter birth certificate number" autocomplete="off">
                    <span class="field-hint">Leave blank if not yet available</span>
                  </div>
                </div>

                <p class="section-label">Personal Details</p>
                <div class="field-grid cols-3">
                  <div class="field">
                    <label for="firstName"><span class="req">✦</span> First Name</label>
                    <input type="text" id="firstName" name="firstName" placeholder="First name" required autocomplete="off">
                    <span class="field-err">First name is required</span>
                  </div>
                  <div class="field">
                    <label for="middleName">Middle Name <span class="opt-tag">Optional</span></label>
                    <input type="text" id="middleName" name="middleName" placeholder="Middle name" autocomplete="off">
                  </div>
                  <div class="field">
                    <label for="surname">Surname <span class="opt-tag">Optional</span></label>
                    <input type="text" id="surname" name="surname" placeholder="Surname" autocomplete="off">
                  </div>
                </div>
                <div class="field-grid cols-2">
                  <div class="field">
                    <label for="dob">Date of Birth <span class="opt-tag">Optional</span></label>
                    <input type="date" id="dob" name="DOB" max="<?= date('Y-m-d') ?>">
                  </div>
                  <div class="field">
                    <label for="grade">Grade <span class="opt-tag">Optional</span></label>
                    <select id="grade" name="Grade">
                      <option value="">— Select grade —</option>
                      <?php for ($g = 1; $g <= 9; $g++): ?>
                      <option value="<?= $g ?>">Grade <?= $g ?></option>
                      <?php endfor; ?>
                    </select>
                  </div>
                </div>

              </div>

              <div class="form-footer">
                <span class="footer-note">
                  <i class="fa-solid fa-circle-info"></i>
                  Only <span class="req">✦</span>&nbsp;<strong>First Name</strong> is required. Existing learners are updated, not duplicated.
                </span>
                <button type="submit" class="btn gold" id="regBtn">
                  <i class="fa-solid fa-user-plus"></i> Register Student
                </button>
              </div>
            </form>
          </div>

          <!-- Live preview + tips -->
          <aside class="side-stack" aria-label="Learner preview">
            <div class="card pv">
              <div class="pv-av" id="pvInitials">?</div>
              <div class="pv-name ph" id="pvName">New learner</div>
              <div class="pv-meta">
                <span class="pv-chip gold" id="pvGrade">No grade</span>
                <span class="pv-chip" id="pvDob">No date of birth</span>
              </div>
              <div class="pv-rows">
                <div class="pv-row"><span>UPI</span><span id="pvUpi">—</span></div>
                <div class="pv-row"><span>Assessment</span><span id="pvAss">—</span></div>
                <div class="pv-row"><span>Birth cert.</span><span id="pvBirth">—</span></div>
              </div>
            </div>
            <div class="card tips">
              <h4>Good to know</h4>
              <div class="tip"><i class="fa-solid fa-circle-check"></i><span>A learner counts as already registered when their UPI, Assessment or Birth certificate number matches, or when their names match.</span></div>
              <div class="tip"><i class="fa-solid fa-circle-check"></i><span>Blank fields never erase information that is already saved.</span></div>
              <div class="tip"><i class="fa-solid fa-circle-check"></i><span>Registering many learners? Use <strong>Bulk import</strong> above.</span></div>
            </div>
          </aside>

        </div>
      </section>

      <!-- ══════════ BULK ══════════ -->
      <section class="panel<?= $openBulk ? ' on' : '' ?>" id="panel-bulk" role="tabpanel">
        <div class="card">
          <div class="card-head">
            <div class="head-icon green"><i class="fa-solid fa-file-excel"></i></div>
            <div><h2>Bulk Import via Excel</h2><p>Upload an .xlsx or .csv file to register multiple students at once</p></div>
          </div>

          <div class="card-body">

            <div class="steps">
              <div class="step"><span class="n">1</span><div><b>Get the template</b><small>One row per learner</small></div></div>
              <div class="step"><span class="n">2</span><div><b>Upload your file</b><small>.xlsx, .xls or .csv</small></div></div>
              <div class="step"><span class="n">3</span><div><b>Review &amp; import</b><small>New and Update are labelled</small></div></div>
            </div>

            <div class="info-box">
              <i class="fa-solid fa-circle-info"></i>
              <div>
                <b>How bulk import works</b>
                <p>
                  Download the template, fill in the student details (one row per student), then upload it here.
                  The preview labels each row <strong>New</strong> or <strong>Update</strong> before anything is saved.
                  A learner counts as already registered when their UPI, Assessment number or Birth certificate number
                  matches, or when their names match (e.g. <em>John Kamau</em> matches <em>John Kamau Mwangi</em>).
                  Blank cells never erase information already saved. Only <strong>FirstName</strong> is required.
                </p>
              </div>
            </div>

            <div style="margin-bottom:18px;">
              <button type="button" class="btn ghost sm" onclick="downloadTemplate()">
                <i class="fa-solid fa-download" style="color:var(--green)"></i> Download Excel Template
              </button>
            </div>

            <div class="drop-zone" id="dropZone">
              <input type="file" id="excelFile" accept=".xlsx,.xls,.csv" onchange="handleFile(this.files[0])" aria-label="Choose a spreadsheet file">
              <div class="drop-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
              <h3>Drop your file here</h3>
              <p>or click to browse</p>
              <div class="file-types">Accepted: <span>.xlsx</span> <span>.xls</span> <span>.csv</span></div>
            </div>

            <div class="preview-wrap" id="previewWrap">

              <div class="preview-header">
                <span class="preview-title">Preview <span class="row-count" id="rowCount">0 rows</span></span>
                <button type="button" class="btn ghost sm" onclick="clearPreview()"><i class="fa-solid fa-xmark"></i> Clear</button>
              </div>

              <div class="pf" id="previewFilters" role="group" aria-label="Filter preview rows"></div>

              <div class="validation-summary" id="validationSummary"></div>

              <div class="preview-table-wrap">
                <div class="preview-scroll">
                  <table class="preview-table">
                    <thead>
                      <tr>
                        <th class="row-num">#</th>
                        <th>UPI</th>
                        <th>Assessment No</th>
                        <th>First Name</th>
                        <th>Middle Name</th>
                        <th>Surname</th>
                        <th>Date of Birth</th>
                        <th>Grade</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody id="previewBody"></tbody>
                  </table>
                </div>
              </div>

              <div class="upload-progress" id="uploadProgress">
                <div class="progress-label"><span>Importing students…</span><span id="progressPct">0%</span></div>
                <div class="progress-bar-wrap"><div class="progress-bar-fill" id="progressFill"></div></div>
              </div>

              <div class="form-footer bulk-footer">
                <span class="footer-note"><i class="fa-solid fa-circle-info"></i> New learners are added, existing learners are updated</span>
                <button type="button" class="btn gold" id="bulkSubmitBtn" onclick="submitBulk()" disabled>
                  <i class="fa-solid fa-upload"></i> Import All Valid Rows
                </button>
              </div>

            </div>

          </div>
        </div>
      </section>

    </main>

    <div class="footer">&copy; Designed by <strong>Kelvin Mutinda</strong> 2026. All rights reserved.</div>
  </div>
</div>

<script>
/* ───────────── Sidebar ───────────── */
const sidebar  = document.getElementById('sidebar');
const backdrop = document.getElementById('backdrop');
const burger   = document.getElementById('burger');

function openSidebar()  { sidebar.classList.add('open');    backdrop.classList.add('show');    burger.innerHTML = '<i class="fa-solid fa-xmark"></i>'; document.body.style.overflow = 'hidden'; }
function closeSidebar() { sidebar.classList.remove('open'); backdrop.classList.remove('show'); burger.innerHTML = '<i class="fa-solid fa-bars"></i>'; document.body.style.overflow = ''; }
burger.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

/* ───────────── Theme (same key as the other dashboards) ───────────── */
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

/* ───────────── Tabs ───────────── */
function showTab(name) {
  document.querySelectorAll('.tab').forEach(t => { const on = t.dataset.tab === name; t.classList.toggle('on', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
  document.querySelectorAll('.panel').forEach(p => p.classList.toggle('on', p.id === 'panel-' + name));
}
document.querySelectorAll('.tab').forEach(t => t.addEventListener('click', () => showTab(t.dataset.tab)));

/* ───────────── Manual form: validation + live preview ───────────── */
const requiredIds = ['firstName'];
const regForm = document.getElementById('regForm');

regForm.addEventListener('submit', function (e) {
  let valid = true;
  requiredIds.forEach(id => {
    const el = document.getElementById(id);
    if (!el.value.trim()) { el.classList.add('error'); valid = false; }
  });
  if (!valid) {
    e.preventDefault();
    const bad = regForm.querySelector('.error');
    if (bad) bad.focus();
    return;
  }
  const b = document.getElementById('regBtn');
  b.disabled = true;
  b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';
});

requiredIds.forEach(id => {
  const el = document.getElementById(id);
  if (el) el.addEventListener('input', function () { this.classList.remove('error'); });
});

(function livePreview() {
  const $ = id => document.getElementById(id);
  const f = { first: $('firstName'), mid: $('middleName'), sur: $('surname'), dob: $('dob'), grade: $('grade'), upi: $('upi'), ass: $('assessment'), birth: $('birthNo') };
  function update() {
    const full = [f.first.value, f.mid.value, f.sur.value].map(s => s.trim()).filter(Boolean).join(' ');
    const ini  = ((f.first.value.trim()[0] || '') + (f.sur.value.trim()[0] || f.mid.value.trim()[0] || '')).toUpperCase();
    $('pvInitials').textContent = ini || '?';
    $('pvName').textContent = full || 'New learner';
    $('pvName').classList.toggle('ph', !full);
    $('pvGrade').textContent = f.grade.value ? 'Grade ' + f.grade.value : 'No grade';
    $('pvDob').textContent = f.dob.value ? f.dob.value : 'No date of birth';
    $('pvUpi').textContent   = f.upi.value.trim()   || '—';
    $('pvAss').textContent   = f.ass.value.trim()   || '—';
    $('pvBirth').textContent = f.birth.value.trim() || '—';
  }
  Object.values(f).forEach(el => { el.addEventListener('input', update); el.addEventListener('change', update); });
  update();
})();

/* ───────────── Drag & drop ───────────── */
const dropZone = document.getElementById('dropZone');

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
['dragleave', 'drop'].forEach(evt => { dropZone.addEventListener(evt, () => dropZone.classList.remove('drag-over')); });
dropZone.addEventListener('drop', e => {
  e.preventDefault();
  const file = e.dataTransfer.files[0];
  if (file) handleFile(file);
});

/* ───────────── Template download ───────────── */
function downloadTemplate() {
  const headers = [
    ['UPI', 'Assessment', 'FirstName', 'MiddleName', 'Surname', 'DOB', 'Grade', 'BirthCertNo']
  ];
  const ws = XLSX.utils.aoa_to_sheet(headers);
  ws['!cols'] = [14, 16, 14, 14, 14, 14, 8, 16].map(w => ({ wch: w }));

  const wb = XLSX.utils.book_new();
  XLSX.utils.book_append_sheet(wb, ws, 'Students');
  XLSX.writeFile(wb, 'Kanja_Student_Import_Template.xlsx');
}

/* ───────────── Parse Excel / CSV ───────────── */
let parsedRows = [];
let previewFilter = 'all';   // all | new | update | problems

function toPayload(r) {
  return {
    rowNum: r.rowNum, upi: r.upi, assessment: r.assessment,
    firstName: r.firstName, middleName: r.middleName, surname: r.surname,
    dob: r.dob, grade: r.grade, birthCert: r.birthCert
  };
}

function handleFile(file) {
  if (!file) return;

  const ext = file.name.split('.').pop().toLowerCase();
  if (!['xlsx', 'xls', 'csv'].includes(ext)) {
    alert('Please upload a .xlsx, .xls, or .csv file.');
    return;
  }

  const reader = new FileReader();
  reader.onload = function (e) {
    const data = new Uint8Array(e.target.result);
    const wb   = XLSX.read(data, { type: 'array', cellDates: true });
    const ws   = wb.Sheets[wb.SheetNames[0]];
    const rows = XLSX.utils.sheet_to_json(ws, { defval: '' });

    parsedRows = rows.map((row, i) => {
      const get = (...keys) => {
        for (const k of keys) {
          const found = Object.keys(row).find(rk => rk.trim().toLowerCase() === k.toLowerCase());
          if (found !== undefined) return String(row[found]).trim();
        }
        return '';
      };

      const upi        = get('upi');
      const assessment = get('assessment', 'assessmentno', 'assess');
      const firstName  = get('firstname', 'first name', 'first');
      const middleName = get('middlename', 'middle name', 'middle', 'other names');
      const surname    = get('surname', 'last name', 'lastname');
      const dob        = get('dob', 'date of birth', 'dateofbirth');
      const grade      = get('grade');
      const birthCert  = get('birthcertno', 'birthcert', 'birth cert no', 'birth certificate');

      const errors = [];
      if (!firstName) errors.push('First name missing');
      if (grade && (isNaN(grade) || grade < 1 || grade > 9)) errors.push('Grade invalid (1–9)');

      return {
        rowNum: i + 2,
        upi, assessment, firstName, middleName, surname,
        dob: formatDate(dob), grade, birthCert,
        valid: errors.length === 0,
        errors,
        action: null,   // set by checkExisting(): 'insert' | 'update' | 'skip'
        reason: ''
      };
    });

    previewFilter = 'all';
    renderPreview();
    checkExisting();    // ask the server which rows are new and which already exist
  };
  reader.readAsArrayBuffer(file);
}

function formatDate(raw) {
  if (!raw) return '';
  if (raw instanceof Date) {
    return raw.toISOString().split('T')[0];
  }
  const d = new Date(raw);
  if (!isNaN(d.getTime())) return d.toISOString().split('T')[0];
  return raw;
}

/* Dry run: saves nothing, the server only reports insert / update / skip per row */
async function checkExisting() {
  const valid = parsedRows.filter(r => r.valid);
  if (valid.length === 0) return;
  try {
    const response = await fetch('/bulk_register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ students: valid.map(toPayload), dry_run: true })
    });
    const result = await response.json();
    if (result.success && Array.isArray(result.results)) {
      result.results.forEach(res => {
        const row = parsedRows.find(r => r.rowNum === res.rowNum);
        if (row) { row.action = res.action; row.reason = res.reason || ''; }
      });
      renderPreview();
    }
  } catch (err) {
    /* the preview still works without the New / Update labels */
  }
}

/* ───────────── Preview table ───────────── */
function setPreviewFilter(f) { previewFilter = f; renderPreview(); }

function renderPreview() {
  const wrap    = document.getElementById('previewWrap');
  const body    = document.getElementById('previewBody');
  const countEl = document.getElementById('rowCount');
  const summary = document.getElementById('validationSummary');
  const btn     = document.getElementById('bulkSubmitBtn');
  const filters = document.getElementById('previewFilters');

  const importable = parsedRows.filter(r => r.valid && r.action !== 'skip');
  const problems   = parsedRows.filter(r => !r.valid || r.action === 'skip');
  const newRows    = parsedRows.filter(r => r.valid && r.action === 'insert');
  const updRows    = parsedRows.filter(r => r.valid && r.action === 'update');
  const checked    = parsedRows.some(r => r.action);

  countEl.textContent = parsedRows.length + ' row' + (parsedRows.length !== 1 ? 's' : '');

  const split = checked
    ? ` <strong>${newRows.length}</strong> new, <strong>${updRows.length}</strong> existing will be updated.`
    : '';

  if (problems.length > 0) {
    summary.className = 'validation-summary has-errors';
    summary.innerHTML = `<i class="fa-solid fa-triangle-exclamation" style="margin-top:2px;flex-shrink:0;"></i>
      <span><strong>${problems.length}</strong> row${problems.length !== 1 ? 's have' : ' has'} problems and will be skipped.
      ${importable.length} row${importable.length !== 1 ? 's' : ''} will be imported.${split}</span>`;
  } else {
    summary.className = 'validation-summary all-good';
    summary.innerHTML = `<i class="fa-solid fa-circle-check" style="margin-top:2px;flex-shrink:0;"></i>
      <span>All <strong>${importable.length}</strong> rows are ready to import.${split}</span>`;
  }

  /* Filter pills */
  const pills = [['all', 'All', parsedRows.length]];
  if (checked) { pills.push(['new', 'New', newRows.length], ['update', 'Update', updRows.length]); }
  pills.push(['problems', 'Problems', problems.length]);
  filters.innerHTML = pills.map(p =>
    `<button type="button" class="${previewFilter === p[0] ? 'on' : ''}" onclick="setPreviewFilter('${p[0]}')">${p[1]}<b>${p[2]}</b></button>`
  ).join('');

  const visible = parsedRows.filter(r => {
    if (previewFilter === 'new')      return r.valid && r.action === 'insert';
    if (previewFilter === 'update')   return r.valid && r.action === 'update';
    if (previewFilter === 'problems') return !r.valid || r.action === 'skip';
    return true;
  });

  const dash = '<span style="color:var(--muted)">—</span>';

  body.innerHTML = visible.length === 0
    ? '<tr><td colspan="9" class="empty-filter">No rows in this view.</td></tr>'
    : visible.map(r => {
    const bad = !r.valid || r.action === 'skip';
    let pill;
    if (!r.valid) {
      pill = `<span class="status-pill err" title="${esc(r.errors.join(', '))}">
                <i class="fa-solid fa-xmark"></i> ${esc(r.errors[0])}${r.errors.length > 1 ? ' +' + (r.errors.length - 1) : ''}
              </span>`;
    } else if (r.action === 'skip') {
      pill = `<span class="status-pill err" title="${esc(r.reason)}">
                <i class="fa-solid fa-xmark"></i> ${esc(r.reason)}
              </span>`;
    } else if (r.action === 'update') {
      pill = '<span class="status-pill upd"><i class="fa-solid fa-pen"></i> Update</span>';
    } else if (r.action === 'insert') {
      pill = '<span class="status-pill ok"><i class="fa-solid fa-plus"></i> New</span>';
    } else {
      pill = '<span class="status-pill ok"><i class="fa-solid fa-check"></i> OK</span>';
    }

    return `
      <tr class="${bad ? 'row-error' : ''}">
        <td class="row-num">${r.rowNum}</td>
        <td>${esc(r.upi) || dash}</td>
        <td>${esc(r.assessment) || dash}</td>
        <td>${esc(r.firstName)}</td>
        <td>${esc(r.middleName) || dash}</td>
        <td>${esc(r.surname) || dash}</td>
        <td>${esc(r.dob) || dash}</td>
        <td>${esc(r.grade) || dash}</td>
        <td>${pill}</td>
      </tr>`;
  }).join('');

  btn.disabled = importable.length === 0;
  wrap.classList.add('visible');
}

function esc(str) {
  return String(str || '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function clearPreview() {
  parsedRows = [];
  previewFilter = 'all';
  document.getElementById('previewWrap').classList.remove('visible');
  document.getElementById('excelFile').value = '';
  document.getElementById('validationSummary').className = 'validation-summary';
  document.getElementById('uploadProgress').classList.remove('visible');
}

/* ───────────── Bulk submit ───────────── */
async function submitBulk() {
  const validRows = parsedRows.filter(r => r.valid && r.action !== 'skip');
  if (validRows.length === 0) return;

  const btn      = document.getElementById('bulkSubmitBtn');
  const progress = document.getElementById('uploadProgress');
  const fill     = document.getElementById('progressFill');
  const pctLabel = document.getElementById('progressPct');

  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Importing…';
  progress.classList.add('visible');

  let pct = 0;
  const ticker = setInterval(() => {
    pct = Math.min(pct + Math.random() * 12, 88);
    fill.style.width = pct + '%';
    pctLabel.textContent = Math.round(pct) + '%';
  }, 200);

  try {
    const response = await fetch('/bulk_register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ students: validRows.map(toPayload) })
    });

    clearInterval(ticker);
    fill.style.width = '100%';
    pctLabel.textContent = '100%';

    const result = await response.json();

    if (result.success) {
      window.location.href =
        'RegisterStudent.php?bulk_success=' + (result.imported || 0) +
        '&updated=' + (result.updated || 0) +
        '&skipped=' + (result.skipped || 0);
    } else {
      window.location.href =
        'RegisterStudent.php?bulk_error=' + encodeURIComponent(result.message || 'Unknown error');
    }

  } catch (err) {
    clearInterval(ticker);
    progress.classList.remove('visible');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-upload"></i> Import All Valid Rows';
    alert('Network error — please check your connection and try again.');
  }
}

/* ───────────── Auto-hide flash messages ───────────── */
document.querySelectorAll('.flash').forEach(el => {
  setTimeout(() => {
    if (!el.isConnected) return;
    el.style.transition = 'opacity 0.4s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 400);
  }, 7000);
});
</script>

</body>
</html>