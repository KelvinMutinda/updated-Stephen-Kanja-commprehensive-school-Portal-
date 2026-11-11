<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="refresh" content="60"><!-- auto-refresh every 60s -->
  <title>Site Monitor &amp; Settings — Stephen Kanja School</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=JetBrains+Mono:wght@300;400;500&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --gold:      #f0c040;
      --gold-dim:  #c9a030;
      --gold-pale: rgba(240,192,64,0.08);
      --black:     #080808;
      --dark:      #101010;
      --panel:     #141414;
      --card:      #191919;
      --border:    rgba(255,255,255,0.07);
      --border-2:  rgba(255,255,255,0.04);
      --text:      #e8e6e0;
      --text-2:    #888880;
      --text-3:    #444440;
      --mid:       #222;
      --sidebar-w: 260px;

      --green:     #22c55e; --green-d: rgba(34,197,94,0.15);
      --blue:      #3b82f6; --blue-d:  rgba(59,130,246,0.15);
      --red:       #ef4444; --red-d:   rgba(239,68,68,0.15);
      --amber:     #f59e0b; --amber-d: rgba(245,158,11,0.15);
      --teal:      #14b8a6; --teal-d:  rgba(20,184,166,0.15);
      --purple:    #a855f7; --purple-d:rgba(168,85,247,0.15);

      --r: 10px;
      --r-lg: 14px;
      --sh: 0 4px 24px rgba(0,0,0,0.4);
    }

    html { scroll-behavior: smooth; }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--black);
      color: var(--text);
      min-height: 100vh;
      font-size: 14px;
      line-height: 1.6;
    }

    /* scanline texture overlay */
    body::after {
      content: '';
      position: fixed; inset: 0;
      background: repeating-linear-gradient(0deg, transparent, transparent 2px, rgba(0,0,0,0.015) 2px, rgba(0,0,0,0.015) 4px);
      pointer-events: none; z-index: 9999;
    }

    /* ══ OVERLAY ══ */
    .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:200; backdrop-filter:blur(4px); }
    .overlay.active { display:block; }

    /* ══ SIDEBAR ══ */
    .sidebar {
      position:fixed; top:0; left:0;
      width:var(--sidebar-w); height:100vh;
      background:var(--dark);
      border-right:1px solid var(--border);
      display:flex; flex-direction:column;
      z-index:300;
      transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);
    }
    .sidebar::before {
      content:''; position:absolute; top:0; left:0;
      width:2px; height:100%;
      background:linear-gradient(180deg,var(--gold) 0%,transparent 100%);
    }
    .sidebar-brand {
      padding:1.5rem 1.4rem 1.1rem;
      border-bottom:1px solid var(--border);
      display:flex; align-items:center; gap:10px;
    }
    .brand-icon { width:34px; height:34px; background:var(--gold); border-radius:7px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .brand-icon i { color:#000; font-size:15px; }
    .brand-text .bn { font-family:'Bebas Neue',sans-serif; font-size:1.1rem; letter-spacing:0.12em; color:var(--text); display:block; }
    .brand-text .bs { font-size:9px; color:var(--text-3); letter-spacing:0.12em; text-transform:uppercase; }
    .sidebar-close { display:none; position:absolute; top:0.9rem; right:0.9rem; background:var(--mid); border:none; color:var(--text-2); width:26px; height:26px; border-radius:5px; cursor:pointer; align-items:center; justify-content:center; font-size:11px; transition:all 0.2s; }
    .sidebar-close:hover { background:var(--gold); color:#000; }
    .sidebar-nav { flex:1; overflow-y:auto; padding:0.8rem 0; scrollbar-width:thin; scrollbar-color:var(--mid) transparent; }
    .nav-label { font-size:9px; font-weight:700; letter-spacing:0.14em; text-transform:uppercase; color:var(--text-3); padding:0.7rem 1.4rem 0.3rem; }
    .sidebar-nav a { display:flex; align-items:center; gap:10px; padding:0.6rem 1.4rem; color:var(--text-2); text-decoration:none; font-size:12.5px; font-weight:500; border-left:2px solid transparent; transition:all 0.16s; }
    .sidebar-nav a i { width:16px; text-align:center; font-size:11.5px; flex-shrink:0; }
    .sidebar-nav a:hover,.sidebar-nav a.active { color:var(--text); background:rgba(255,255,255,0.03); border-left-color:var(--gold); }
    .sidebar-nav a.active { background:var(--gold-pale); }
    .sidebar-nav a:hover i,.sidebar-nav a.active i { color:var(--gold); }
    .sidebar-footer { padding:0.9rem 1.4rem; border-top:1px solid var(--border); font-size:10px; color:var(--text-3); }

    /* ══ MAIN ══ */
    .main { margin-left:var(--sidebar-w); min-height:100vh; display:flex; flex-direction:column; }

    /* ── Topbar ── */
    .topbar {
      background:var(--dark);
      border-bottom:1px solid var(--border);
      border-bottom-width:2px;
      border-image:linear-gradient(90deg,var(--gold) 0%,transparent 100%) 1;
      height:60px; padding:0 2rem;
      display:flex; align-items:center; justify-content:space-between;
      position:sticky; top:0; z-index:100; flex-shrink:0;
    }
    .topbar-l { display:flex; align-items:center; gap:14px; }
    .menu-btn { display:none; background:var(--mid); border:none; color:var(--text-2); width:30px; height:30px; border-radius:6px; cursor:pointer; align-items:center; justify-content:center; font-size:12px; transition:all 0.2s; flex-shrink:0; }
    .menu-btn:hover { background:var(--gold); color:#000; }
    .topbar-title { font-family:'Bebas Neue',sans-serif; font-size:1.5rem; letter-spacing:0.12em; color:var(--text); }
    .topbar-title em { color:var(--gold); font-style:normal; }
    .topbar-r { display:flex; align-items:center; gap:16px; }
    .live-badge {
      display:flex; align-items:center; gap:7px;
      font-size:10.5px; font-weight:600; letter-spacing:0.06em;
      text-transform:uppercase; color:var(--green);
    }
    .live-dot { width:7px; height:7px; background:var(--green); border-radius:50%; animation:pulse 1.5s ease-in-out infinite; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:0.5;transform:scale(1.3)} }
    .auto-refresh { font-size:10.5px; color:var(--text-3); display:flex; align-items:center; gap:5px; }
    .auto-refresh i { color:var(--gold); font-size:9px; }

    /* ══ CONTENT ══ */
    .content { flex:1; padding:1.8rem 2rem 3rem; }

    /* ── Section head ── */
    .sec { display:flex; align-items:center; gap:12px; margin-bottom:1.2rem; margin-top:2rem; }
    .sec:first-of-type { margin-top:0; }
    .sec h2 { font-family:'Bebas Neue',sans-serif; font-size:1.35rem; letter-spacing:0.09em; color:var(--text); white-space:nowrap; }
    .sec h2 i { color:var(--gold); font-size:0.95rem; margin-right:5px; }
    .sec::after { content:''; flex:1; height:1px; background:var(--border); }
    .sec-badge { font-family:'JetBrains Mono',monospace; font-size:9px; font-weight:500; background:var(--gold-pale); color:var(--gold); border:1px solid rgba(240,192,64,0.2); padding:3px 9px; border-radius:4px; white-space:nowrap; }

    /* ── Grid helpers ── */
    .g2 { display:grid; grid-template-columns:1fr 1fr; gap:1.1rem; }
    .g3 { display:grid; grid-template-columns:repeat(3,1fr); gap:1.1rem; }
    .g4 { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; }
    .g5 { display:grid; grid-template-columns:repeat(5,1fr); gap:1rem; }
    .mb { margin-bottom:1.4rem; }
    .full { grid-column:1/-1; }

    /* ── KPI chips ── */
    .kpi {
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--r-lg);
      padding:1.2rem 1.4rem;
      position:relative; overflow:hidden;
      transition:transform 0.2s, border-color 0.2s;
      animation:fadeUp 0.4s ease both;
    }
    .kpi:hover { transform:translateY(-2px); border-color:rgba(255,255,255,0.12); }
    .kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:2px; }
    .kpi.c-gold::before   { background:var(--gold); }
    .kpi.c-green::before  { background:var(--green); }
    .kpi.c-blue::before   { background:var(--blue); }
    .kpi.c-red::before    { background:var(--red); }
    .kpi.c-amber::before  { background:var(--amber); }
    .kpi.c-teal::before   { background:var(--teal); }
    .kpi.c-purple::before { background:var(--purple); }
    .kpi-label { font-size:9px; font-weight:700; letter-spacing:1.4px; text-transform:uppercase; color:var(--text-3); margin-bottom:7px; }
    .kpi-val { font-family:'JetBrains Mono',monospace; font-size:2.1rem; font-weight:400; color:var(--text); line-height:1; letter-spacing:-0.02em; }
    .kpi-val.sm { font-size:1.5rem; }
    .kpi-sub { font-size:10.5px; color:var(--text-3); margin-top:6px; }
    .kpi-icon { position:absolute; top:1.1rem; right:1.2rem; font-size:1.5rem; }
    .kpi-icon.gold   { color:rgba(240,192,64,0.2); }
    .kpi-icon.green  { color:rgba(34,197,94,0.2); }
    .kpi-icon.blue   { color:rgba(59,130,246,0.2); }
    .kpi-icon.red    { color:rgba(239,68,68,0.2); }
    .kpi-icon.amber  { color:rgba(245,158,11,0.2); }
    .kpi-icon.teal   { color:rgba(20,184,166,0.2); }

    /* ── Cards ── */
    .card {
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--r-lg);
      overflow:hidden;
      box-shadow:var(--sh);
      animation:fadeUp 0.4s ease both;
    }
    .card-head {
      background:var(--panel);
      border-bottom:1px solid var(--border);
      padding:0.8rem 1.3rem;
      display:flex; align-items:center; justify-content:space-between;
      gap:10px;
    }
    .card-head h3 {
      font-family:'JetBrains Mono',monospace;
      font-size:0.78rem; font-weight:500;
      letter-spacing:0.08em; text-transform:uppercase;
      color:var(--gold);
      display:flex; align-items:center; gap:8px;
    }
    .card-head h3 i { font-size:0.7rem; }
    .card-body { padding:1.3rem 1.4rem; }
    .ch { position:relative; }
    .ch-240 { height:240px; }
    .ch-280 { height:280px; }
    .ch-200 { height:200px; }
    .ch-320 { height:320px; }

    /* ── Table ── */
    .tbl-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    table.t {
      width:100%; border-collapse:collapse;
      font-size:12px; min-width:500px;
      font-family:'JetBrains Mono',monospace;
    }
    table.t thead th {
      background:var(--panel); color:var(--text-3);
      font-size:9px; font-weight:500; letter-spacing:1.2px;
      text-transform:uppercase; padding:9px 12px;
      border-bottom:1px solid var(--border);
      text-align:left; white-space:nowrap;
    }
    table.t tbody tr { border-bottom:1px solid var(--border-2); transition:background 0.1s; }
    table.t tbody tr:hover { background:rgba(255,255,255,0.02); }
    table.t tbody tr:last-child { border-bottom:none; }
    table.t td { padding:8px 12px; color:var(--text-2); vertical-align:middle; white-space:nowrap; }
    table.t td.hl { color:var(--text); font-weight:500; }
    table.t td.mono { font-family:'JetBrains Mono',monospace; font-size:11px; }

    /* status pills */
    .pill { display:inline-flex; align-items:center; gap:5px; font-family:'DM Sans',sans-serif; font-size:10px; font-weight:700; letter-spacing:0.4px; padding:2px 8px; border-radius:4px; }
    .pill-green  { background:var(--green-d);  color:var(--green); }
    .pill-red    { background:var(--red-d);    color:var(--red); }
    .pill-amber  { background:var(--amber-d);  color:var(--amber); }
    .pill-blue   { background:var(--blue-d);   color:var(--blue); }
    .pill-teal   { background:var(--teal-d);   color:var(--teal); }
    .pill-purple { background:var(--purple-d); color:var(--purple); }
    .pill-dot { width:5px; height:5px; border-radius:50%; background:currentColor; }

    /* device icon */
    .dev-icon { font-size:13px; }

    /* ── Active sessions bar ── */
    .active-grid { display:flex; flex-direction:column; gap:6px; }
    .active-row {
      background:var(--panel); border:1px solid var(--border-2);
      border-radius:8px; padding:9px 13px;
      display:grid; grid-template-columns:auto 1fr auto;
      align-items:center; gap:10px;
      transition:border-color 0.15s;
    }
    .active-row:hover { border-color:rgba(240,192,64,0.2); }
    .active-dot { width:8px; height:8px; border-radius:50%; background:var(--green); box-shadow:0 0 6px var(--green); animation:pulse 2s ease-in-out infinite; flex-shrink:0; }
    .active-info .page { font-size:12px; color:var(--text); font-weight:500; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:300px; }
    .active-info .meta { font-size:10px; color:var(--text-3); margin-top:1px; }
    .active-time { font-family:'JetBrains Mono',monospace; font-size:10px; color:var(--text-3); white-space:nowrap; }

    /* ── System health ── */
    .sys-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; }
    .sys-item {
      background:var(--panel); border:1px solid var(--border);
      border-radius:var(--r); padding:1rem 1.2rem;
      display:flex; flex-direction:column; gap:4px;
    }
    .sys-label { font-size:9px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:var(--text-3); }
    .sys-val { font-family:'JetBrains Mono',monospace; font-size:1rem; color:var(--text); font-weight:500; margin-top:2px; }
    .sys-val.ok    { color:var(--green); }
    .sys-val.warn  { color:var(--amber); }
    .sys-val.err   { color:var(--red); }
    .sys-sub { font-size:10px; color:var(--text-3); }

    /* ── Page hit bars ── */
    .page-hit-row { display:flex; align-items:center; gap:10px; margin-bottom:9px; }
    .page-hit-name { font-size:11px; color:var(--text-2); width:180px; flex-shrink:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-family:'JetBrains Mono',monospace; }
    .page-hit-track { flex:1; background:var(--panel); border-radius:99px; height:6px; overflow:hidden; }
    .page-hit-bar { height:100%; border-radius:99px; background:var(--gold); transition:width 1s ease; }
    .page-hit-count { font-size:10px; font-weight:600; color:var(--text-3); width:40px; text-align:right; font-family:'JetBrains Mono',monospace; }

    /* ── Pagination ── */
    .pag { display:flex; align-items:center; gap:5px; padding:0.9rem 1.4rem; border-top:1px solid var(--border); }
    .pag-btn { width:28px; height:28px; background:var(--panel); border:1px solid var(--border); border-radius:5px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:600; color:var(--text-2); cursor:pointer; text-decoration:none; transition:all 0.15s; font-family:'JetBrains Mono',monospace; }
    .pag-btn:hover { border-color:var(--gold); color:var(--text); }
    .pag-btn.active { background:var(--gold); color:#000; border-color:var(--gold); }
    .pag-btn.disabled { opacity:0.3; pointer-events:none; }
    .pag-info { margin-left:auto; font-size:10px; color:var(--text-3); font-family:'JetBrains Mono',monospace; }

    /* ── Filter bar ── */
    .filter-row { display:flex; align-items:flex-end; gap:0.9rem; flex-wrap:wrap; padding:1rem 1.4rem; border-bottom:1px solid var(--border); background:var(--panel); }
    .fg { display:flex; flex-direction:column; gap:4px; }
    .fg label { font-size:9px; font-weight:700; letter-spacing:1.2px; text-transform:uppercase; color:var(--text-3); }
    .fg select, .fg input {
      background:var(--card); border:1px solid var(--border);
      border-radius:6px; padding:7px 10px;
      font-family:'JetBrains Mono',monospace; font-size:11.5px;
      color:var(--text); outline:none; appearance:none;
      transition:border-color 0.15s; cursor:pointer;
    }
    .fg select:focus,.fg input:focus { border-color:var(--gold); }
    .fg input { min-width:180px; }
    .btn-filter {
      background:var(--gold); color:#000;
      border:none; border-radius:6px; padding:7px 16px;
      font-family:'DM Sans',sans-serif; font-size:12px; font-weight:700;
      cursor:pointer; display:flex; align-items:center; gap:6px;
      transition:opacity 0.15s; height:34px; white-space:nowrap;
    }
    .btn-filter:hover { opacity:0.85; }
    .btn-clear { background:transparent; color:var(--text-3); border:1px solid var(--border); border-radius:6px; padding:7px 12px; font-family:'DM Sans',sans-serif; font-size:12px; cursor:pointer; height:34px; transition:all 0.15s; }
    .btn-clear:hover { border-color:var(--text-2); color:var(--text); }

    /* ── Danger zone ── */
    .danger-card { border-color:rgba(239,68,68,0.25) !important; }
    .btn-danger { background:transparent; color:var(--red); border:1px solid rgba(239,68,68,0.35); border-radius:6px; padding:8px 16px; font-family:'DM Sans',sans-serif; font-size:12px; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:7px; transition:all 0.15s; }
    .btn-danger:hover { background:var(--red-d); border-color:var(--red); }

    /* ── Footer ── */
    .footer { background:var(--dark); border-top:1px solid var(--border); text-align:center; padding:1.2rem 2rem; font-size:10px; color:var(--text-3); font-family:'JetBrains Mono',monospace; letter-spacing:0.04em; }

    /* ── Animations ── */
    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }

    /* ── Responsive ── */
    @media(max-width:1100px) { .g5{grid-template-columns:repeat(3,1fr)} .g4{grid-template-columns:repeat(2,1fr)} }
    @media(max-width:900px)  {
      .sidebar{transform:translateX(-100%)} .sidebar.open{transform:translateX(0);box-shadow:8px 0 40px rgba(0,0,0,0.7)}
      .sidebar-close{display:flex} .main{margin-left:0} .menu-btn{display:flex}
      .auto-refresh{display:none} .content{padding:1.2rem 1rem 3rem}
      .g2,.g3,.g4,.g5{grid-template-columns:1fr}
    }
    @media(max-width:640px) { .topbar-title{font-size:1.2rem} .filter-row{flex-direction:column;align-items:stretch} }
  </style>
</head>
<body>

<?php
/* ═══════════════════════════════════════
   BOOTSTRAP
═══════════════════════════════════════ */
include_once('conn.php');
include_once('logger.php'); // logs this very visit

/* ─ Create tables if logger hasn't run yet ─ */
mysqli_query($conn,"
CREATE TABLE IF NOT EXISTS site_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, session_id VARCHAR(100), ip_address VARCHAR(60),
    page VARCHAR(500), referrer VARCHAR(500), user_agent VARCHAR(500), method VARCHAR(10),
    status_code INT DEFAULT 200, visit_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    country VARCHAR(80), device_type VARCHAR(30), browser VARCHAR(80), os VARCHAR(80), duration_ms INT DEFAULT 0
)");
mysqli_query($conn,"
CREATE TABLE IF NOT EXISTS login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(200), ip_address VARCHAR(60),
    success TINYINT(1) DEFAULT 0, user_agent VARCHAR(500),
    login_time DATETIME DEFAULT CURRENT_TIMESTAMP, session_id VARCHAR(100), note VARCHAR(300)
)");
mysqli_query($conn,"
CREATE TABLE IF NOT EXISTS active_sessions (
    session_id VARCHAR(100) PRIMARY KEY, ip_address VARCHAR(60), current_page VARCHAR(500),
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    user_agent VARCHAR(500), started_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

/* ─ Handle purge actions ─ */
$msg = '';
if (isset($_POST['purge_logs'])) {
    $days = max(1,(int)($_POST['keep_days']??30));
    mysqli_query($conn,"DELETE FROM site_logs WHERE visit_time < DATE_SUB(NOW(),INTERVAL $days DAY)");
    $affected = mysqli_affected_rows($conn);
    $msg = "✓ Purged $affected old visit records (kept last $days days).";
}
if (isset($_POST['purge_login'])) {
    mysqli_query($conn,"TRUNCATE TABLE login_logs");
    $msg = "✓ Login log cleared.";
}

/* ═══════════════════════════════════════
   ANALYTICS QUERIES
═══════════════════════════════════════ */

/* ─ KPIs ─ */
function sq($conn,$sql){ $r=mysqli_fetch_row(mysqli_query($conn,$sql)); return $r?$r[0]:0; }

$totalVisits    = sq($conn,"SELECT COUNT(*) FROM site_logs");
$todayVisits    = sq($conn,"SELECT COUNT(*) FROM site_logs WHERE DATE(visit_time)=CURDATE()");
$uniqueIPs      = sq($conn,"SELECT COUNT(DISTINCT ip_address) FROM site_logs");
$uniqueToday    = sq($conn,"SELECT COUNT(DISTINCT ip_address) FROM site_logs WHERE DATE(visit_time)=CURDATE()");
$activeSessions = sq($conn,"SELECT COUNT(*) FROM active_sessions WHERE last_seen >= DATE_SUB(NOW(),INTERVAL 10 MINUTE)");
$totalLogins    = sq($conn,"SELECT COUNT(*) FROM login_logs");
$successLogins  = sq($conn,"SELECT COUNT(*) FROM login_logs WHERE success=1");
$failedLogins   = sq($conn,"SELECT COUNT(*) FROM login_logs WHERE success=0");
$todayLogins    = sq($conn,"SELECT COUNT(*) FROM login_logs WHERE DATE(login_time)=CURDATE()");

/* ─ Visits by hour (today) ─ */
$hourData = array_fill(0,24,0);
$hRes = mysqli_query($conn,"SELECT HOUR(visit_time) AS h, COUNT(*) AS c FROM site_logs WHERE DATE(visit_time)=CURDATE() GROUP BY h");
while($r=mysqli_fetch_assoc($hRes)) $hourData[(int)$r['h']]=(int)$r['c'];

/* ─ Visits last 14 days ─ */
$dayLabels=[]; $dayData=[];
for($i=13;$i>=0;$i--){
    $d=date('Y-m-d',strtotime("-$i days"));
    $dayLabels[]=date('d M',strtotime($d));
    $dayData[]=(int)sq($conn,"SELECT COUNT(*) FROM site_logs WHERE DATE(visit_time)='$d'");
}

/* ─ Top pages ─ */
$topPagesRes = mysqli_query($conn,
    "SELECT page, COUNT(*) AS hits FROM site_logs GROUP BY page ORDER BY hits DESC LIMIT 12");
$topPages=[];
while($r=mysqli_fetch_assoc($topPagesRes)) $topPages[]=$r;
$maxHits = $topPages ? (int)$topPages[0]['hits'] : 1;

/* ─ Browser distribution ─ */
$browRes = mysqli_query($conn,"SELECT browser, COUNT(*) AS c FROM site_logs GROUP BY browser ORDER BY c DESC");
$browLabels=[]; $browData=[];
while($r=mysqli_fetch_assoc($browRes)){ $browLabels[]=$r['browser']?:'Unknown'; $browData[]=(int)$r['c']; }

/* ─ Device distribution ─ */
$devRes = mysqli_query($conn,"SELECT device_type, COUNT(*) AS c FROM site_logs GROUP BY device_type ORDER BY c DESC");
$devLabels=[]; $devData=[];
while($r=mysqli_fetch_assoc($devRes)){ $devLabels[]=$r['device_type']?:'Unknown'; $devData[]=(int)$r['c']; }

/* ─ OS distribution ─ */
$osRes = mysqli_query($conn,"SELECT os, COUNT(*) AS c FROM site_logs GROUP BY os ORDER BY c DESC");
$osLabels=[]; $osData=[];
while($r=mysqli_fetch_assoc($osRes)){ $osLabels[]=$r['os']?:'Unknown'; $osData[]=(int)$r['c']; }

/* ─ Login trend last 7 days ─ */
$loginDayLabels=[]; $loginSuccess=[]; $loginFail=[];
for($i=6;$i>=0;$i--){
    $d=date('Y-m-d',strtotime("-$i days"));
    $loginDayLabels[]=date('d M',strtotime($d));
    $loginSuccess[]=(int)sq($conn,"SELECT COUNT(*) FROM login_logs WHERE DATE(login_time)='$d' AND success=1");
    $loginFail[]   =(int)sq($conn,"SELECT COUNT(*) FROM login_logs WHERE DATE(login_time)='$d' AND success=0");
}

/* ─ Active sessions ─ */
$activeRes = mysqli_query($conn,
    "SELECT * FROM active_sessions WHERE last_seen >= DATE_SUB(NOW(),INTERVAL 10 MINUTE) ORDER BY last_seen DESC LIMIT 20");

/* ─ System health ─ */
$dbVersion   = sq($conn,"SELECT VERSION()");
$phpVersion  = phpversion();
$tableStats  = [];
$tables      = ['site_logs','login_logs','active_sessions','Student','Teachers','Fees','exam2'];
foreach($tables as $tb){
    $r=mysqli_fetch_assoc(mysqli_query($conn,"SHOW TABLE STATUS LIKE '$tb'"));
    if($r) $tableStats[$tb]=['rows'=>(int)$r['Rows'],'size'=>round(((int)$r['Data_length']+(int)$r['Index_length'])/1024,1).' KB'];
}

/* ─ Pagination for visit log ─ */
$logPage   = max(1,(int)($_GET['lp']??1));
$logSearch = trim($_GET['ls']??'');
$logIP     = trim($_GET['lip']??'');
$logDate   = trim($_GET['ldate']??'');
$logDevice = trim($_GET['ldev']??'');
$perPage   = 25;
$logWhere  = ['1=1'];
if($logSearch) $logWhere[]="page LIKE '%".mysqli_real_escape_string($conn,$logSearch)."%'";
if($logIP)     $logWhere[]="ip_address LIKE '%".mysqli_real_escape_string($conn,$logIP)."%'";
if($logDate)   $logWhere[]="DATE(visit_time)='".mysqli_real_escape_string($conn,$logDate)."'";
if($logDevice) $logWhere[]="device_type='".mysqli_real_escape_string($conn,$logDevice)."'";
$lWStr = implode(' AND ',$logWhere);
$logTotal = (int)sq($conn,"SELECT COUNT(*) FROM site_logs WHERE $lWStr");
$logPages = max(1,(int)ceil($logTotal/$perPage));
$logOffset= ($logPage-1)*$perPage;
$logRes   = mysqli_query($conn,"SELECT * FROM site_logs WHERE $lWStr ORDER BY visit_time DESC LIMIT $perPage OFFSET $logOffset");

/* ─ Pagination for login log ─ */
$llPage   = max(1,(int)($_GET['llp']??1));
$llSearch = trim($_GET['lls']??'');
$llWhere  = ['1=1'];
if($llSearch) $llWhere[]="(username LIKE '%".mysqli_real_escape_string($conn,$llSearch)."%' OR ip_address LIKE '%".mysqli_real_escape_string($conn,$llSearch)."%')";
$llWStr = implode(' AND ',$llWhere);
$llTotal = (int)sq($conn,"SELECT COUNT(*) FROM login_logs WHERE $llWStr");
$llPages = max(1,(int)ceil($llTotal/$perPage));
$llOffset= ($llPage-1)*$perPage;
$llRes   = mysqli_query($conn,"SELECT * FROM login_logs WHERE $llWStr ORDER BY login_time DESC LIMIT $perPage OFFSET $llOffset");

/* ─ Top IPs ─ */
$topIpRes = mysqli_query($conn,"SELECT ip_address, COUNT(*) AS hits, MAX(visit_time) AS last_seen FROM site_logs GROUP BY ip_address ORDER BY hits DESC LIMIT 10");

/* ─ Referrers ─ */
$refRes = mysqli_query($conn,"SELECT referrer, COUNT(*) AS c FROM site_logs WHERE referrer!='' GROUP BY referrer ORDER BY c DESC LIMIT 8");

function timeAgoS($dt){ $d=time()-strtotime($dt); if($d<60)return $d.'s ago'; if($d<3600)return round($d/60).'m ago'; if($d<86400)return round($d/3600).'h ago'; return date('d M Y',strtotime($dt)); }
function pageName($url){ $p=parse_url($url,PHP_URL_PATH); return basename($p)?:'/'; }
function deviceIcon($d){ return match(strtolower($d??'')){'mobile'=>'📱','tablet'=>'📟',default=>'🖥️'}; }

/**
 * Roughly parses a User-Agent string into a browser + device label.
 * Used by the Login Log table, which only stores the raw user_agent
 * string per attempt (order of checks matters — e.g. Edge/Chrome both
 * contain "Safari" in their UA, so more specific checks go first).
 */
function _parseUA($ua) {
    $ua = $ua ?: '';

    if (stripos($ua, 'Edg/') !== false) {
        $browser = 'Edge';
    } elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) {
        $browser = 'Opera';
    } elseif (stripos($ua, 'Chrome') !== false) {
        $browser = 'Chrome';
    } elseif (stripos($ua, 'Firefox') !== false) {
        $browser = 'Firefox';
    } elseif (stripos($ua, 'Safari') !== false) {
        $browser = 'Safari';
    } else {
        $browser = 'Unknown';
    }

    if (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false) {
        $device = 'Tablet';
    } elseif (stripos($ua, 'Mobile') !== false || stripos($ua, 'iPhone') !== false || stripos($ua, 'Android') !== false) {
        $device = 'Mobile';
    } else {
        $device = 'Desktop';
    }

    return ['browser' => $browser, 'device' => $device];
}
?>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ░░░ SIDEBAR ░░░ -->
<aside class="sidebar" id="sidebar">
  <button class="sidebar-close" onclick="closeSidebar()"><i class="fa-solid fa-xmark"></i></button>
  <div class="sidebar-brand">
    <div class="brand-icon"><i class="fa-solid fa-graduation-cap"></i></div>
    <div class="brand-text">
      <span class="bn">Kanja School</span>
      <span class="bs">Management System</span>
    </div>
  </div>
  <nav class="sidebar-nav">
    <div class="nav-label">Main</div>
    <a href="Hoi.php"><i class="fa-solid fa-house"></i> Home</a>
    <a href="Students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
    <a href="progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
    <div class="nav-label">Administration</div>
    <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
    <a href="RegisterTeacher.php"><i class="fa-solid fa-user-plus"></i> Register Teachers</a>
    <div class="nav-label">Academics</div>
    <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
    <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
    <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
    <a href="learningMaterial.php"><i class="fa-solid fa-book-open"></i> Learning Materials</a>
    <div class="nav-label">Finance</div>
    <a href="Fee.php"><i class="fa-solid fa-coins"></i> Finances</a>
    <div class="nav-label">System</div>
    <a href="settings.php" class="active"><i class="fa-solid fa-shield-halved"></i> Site Monitor</a>
  </nav>
  <div class="sidebar-footer">© 2026 Kelvin Mutinda</div>
</aside>

<!-- ░░░ MAIN ░░░ -->
<div class="main" id="main">

  <header class="topbar">
    <div class="topbar-l">
      <button class="menu-btn" onclick="openSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div class="topbar-title">Site <em>Monitor</em> &amp; Settings</div>
    </div>
    <div class="topbar-r">
      <div class="live-badge"><div class="live-dot"></div> LIVE</div>
      <div class="auto-refresh"><i class="fa-solid fa-rotate"></i> Auto-refresh: 60s</div>
    </div>
  </header>

  <div class="content">

    <?php if($msg): ?>
    <div style="margin-bottom:1.2rem;padding:11px 15px;background:var(--green-d);color:var(--green);border-left:3px solid var(--green);border-radius:7px;font-size:12.5px;font-family:'JetBrains Mono',monospace;animation:fadeUp 0.3s ease">
      <?=htmlspecialchars($msg)?>
    </div>
    <?php endif; ?>

    <!-- ══ KPI STRIP ══ -->
    <div class="sec"><h2><i class="fa-solid fa-gauge-high"></i>Overview</h2><span class="sec-badge">LIVE · <?=date('d M Y H:i')?></span></div>
    <div class="g5 mb">
      <div class="kpi c-gold" style="animation-delay:0.03s">
        <div class="kpi-icon gold"><i class="fa-solid fa-eye"></i></div>
        <div class="kpi-label">Total Page Views</div>
        <div class="kpi-val"><?=number_format($totalVisits)?></div>
        <div class="kpi-sub">All time</div>
      </div>
      <div class="kpi c-green" style="animation-delay:0.07s">
        <div class="kpi-icon green"><i class="fa-solid fa-calendar-day"></i></div>
        <div class="kpi-label">Visits Today</div>
        <div class="kpi-val"><?=number_format($todayVisits)?></div>
        <div class="kpi-sub"><?=$uniqueToday?> unique IPs</div>
      </div>
      <div class="kpi c-teal" style="animation-delay:0.11s">
        <div class="kpi-icon teal"><i class="fa-solid fa-circle-dot"></i></div>
        <div class="kpi-label">Active Now</div>
        <div class="kpi-val"><?=$activeSessions?></div>
        <div class="kpi-sub">sessions in last 10 min</div>
      </div>
      <div class="kpi c-blue" style="animation-delay:0.15s">
        <div class="kpi-icon blue"><i class="fa-solid fa-users"></i></div>
        <div class="kpi-label">Unique Visitors</div>
        <div class="kpi-val"><?=number_format($uniqueIPs)?></div>
        <div class="kpi-sub">distinct IP addresses</div>
      </div>
      <div class="kpi c-<?=$failedLogins>5?'red':'amber'?>" style="animation-delay:0.19s">
        <div class="kpi-icon <?=$failedLogins>5?'red':'amber'?>"><i class="fa-solid fa-key"></i></div>
        <div class="kpi-label">Login Attempts</div>
        <div class="kpi-val"><?=number_format($totalLogins)?></div>
        <div class="kpi-sub"><?=$successLogins?> ok · <?=$failedLogins?> failed</div>
      </div>
    </div>

    <!-- ══ CHARTS ROW 1 ══ -->
    <div class="sec"><h2><i class="fa-solid fa-chart-area"></i>Traffic Analytics</h2></div>
    <div class="g2 mb" style="grid-template-columns:2fr 1fr">
      <div class="card">
        <div class="card-head"><h3><i class="fa-solid fa-calendar-week"></i>Visits — Last 14 Days</h3></div>
        <div class="card-body"><div class="ch ch-240"><canvas id="dayChart"></canvas></div></div>
      </div>
      <div class="card">
        <div class="card-head"><h3><i class="fa-solid fa-clock"></i>Visits by Hour (Today)</h3></div>
        <div class="card-body"><div class="ch ch-240"><canvas id="hourChart"></canvas></div></div>
      </div>
    </div>

    <!-- ══ ACTIVE SESSIONS ══ -->
    <div class="sec"><h2><i class="fa-solid fa-circle-dot"></i>Active Sessions</h2><span class="sec-badge"><?=$activeSessions?> ONLINE</span></div>
    <div class="card mb">
      <div class="card-body">
        <?php if($activeSessions === 0): ?>
        <div style="text-align:center;padding:2rem;color:var(--text-3);font-family:'JetBrains Mono',monospace;font-size:12px">
          <i class="fa-solid fa-satellite-dish" style="font-size:2rem;color:var(--text-3);display:block;margin-bottom:0.8rem"></i>
          No active sessions detected in the last 10 minutes.
        </div>
        <?php else: ?>
        <div class="active-grid">
          <?php
          mysqli_data_seek($activeRes,0);
          while($s=mysqli_fetch_assoc($activeRes)):
            $minsAgo = round((time()-strtotime($s['last_seen']))/60);
            $ua = $s['user_agent']??'';
            $dev = stripos($ua,'Mobile')!==false?'Mobile':(stripos($ua,'Tablet')!==false?'Tablet':'Desktop');
          ?>
          <div class="active-row">
            <div class="active-dot"></div>
            <div class="active-info">
              <div class="page"><?=htmlspecialchars(urldecode($s['current_page']))?></div>
              <div class="meta"><?=deviceIcon($dev)?> <?=htmlspecialchars($s['ip_address'])?> &nbsp;·&nbsp; Started <?=timeAgoS($s['started_at'])?></div>
            </div>
            <div class="active-time"><?=$minsAgo?> min ago</div>
          </div>
          <?php endwhile; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ CHARTS ROW 2 ══ -->
    <div class="sec"><h2><i class="fa-solid fa-chart-pie"></i>Visitor Breakdown</h2></div>
    <div class="g3 mb">
      <div class="card">
        <div class="card-head"><h3><i class="fa-solid fa-globe"></i>Browser Usage</h3></div>
        <div class="card-body"><div class="ch ch-200"><canvas id="browChart"></canvas></div></div>
      </div>
      <div class="card">
        <div class="card-head"><h3><i class="fa-solid fa-mobile-screen"></i>Device Types</h3></div>
        <div class="card-body"><div class="ch ch-200"><canvas id="devChart"></canvas></div></div>
      </div>
      <div class="card">
        <div class="card-head"><h3><i class="fa-brands fa-windows"></i>Operating Systems</h3></div>
        <div class="card-body"><div class="ch ch-200"><canvas id="osChart"></canvas></div></div>
      </div>
    </div>

    <!-- ══ TOP PAGES ══ -->
    <div class="sec"><h2><i class="fa-solid fa-fire"></i>Most Visited Pages</h2></div>
    <div class="card mb">
      <div class="card-body">
        <?php foreach($topPages as $tp):
          $pct = $maxHits?round((int)$tp['hits']/$maxHits*100):0;
          $cleanPage = basename(parse_url($tp['page'],PHP_URL_PATH))?:'/';
          $fullPage  = htmlspecialchars(urldecode($tp['page']));
        ?>
        <div class="page-hit-row" title="<?=$fullPage?>">
          <div class="page-hit-name"><?=htmlspecialchars($cleanPage)?></div>
          <div class="page-hit-track"><div class="page-hit-bar" style="width:<?=$pct?>%"></div></div>
          <div class="page-hit-count"><?=number_format((int)$tp['hits'])?></div>
        </div>
        <?php endforeach; ?>
        <?php if(!$topPages): ?>
        <div style="text-align:center;padding:1.5rem;color:var(--text-3);font-size:12px;font-family:'JetBrains Mono',monospace">No page data yet.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ══ LOGIN ANALYTICS ══ -->
    <div class="sec"><h2><i class="fa-solid fa-lock"></i>Login Monitor</h2></div>
    <div class="g2 mb" style="grid-template-columns:1fr 1fr">
      <div class="card">
        <div class="card-head"><h3><i class="fa-solid fa-chart-column"></i>Login Trend — Last 7 Days</h3></div>
        <div class="card-body"><div class="ch ch-200"><canvas id="loginChart"></canvas></div></div>
      </div>
      <div class="card">
        <div class="card-head">
          <h3><i class="fa-solid fa-list"></i>Login Summary</h3>
        </div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 14px;background:var(--panel);border-radius:8px;border:1px solid var(--border)">
            <span style="font-size:12px;color:var(--text-2)">Total Attempts</span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:1.4rem;color:var(--text)"><?=$totalLogins?></span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 14px;background:var(--green-d);border-radius:8px;border:1px solid rgba(34,197,94,0.15)">
            <span style="font-size:12px;color:var(--green)"><i class="fa-solid fa-circle-check"></i> Successful Logins</span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:1.4rem;color:var(--green)"><?=$successLogins?></span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 14px;background:var(--red-d);border-radius:8px;border:1px solid rgba(239,68,68,0.15)">
            <span style="font-size:12px;color:var(--red)"><i class="fa-solid fa-circle-xmark"></i> Failed Attempts</span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:1.4rem;color:var(--red)"><?=$failedLogins?></span>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:11px 14px;background:var(--panel);border-radius:8px;border:1px solid var(--border)">
            <span style="font-size:12px;color:var(--text-2)">Today</span>
            <span style="font-family:'JetBrains Mono',monospace;font-size:1.4rem;color:var(--amber)"><?=$todayLogins?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- ══ TOP IPs ══ -->
    <div class="sec"><h2><i class="fa-solid fa-network-wired"></i>Top IP Addresses</h2></div>
    <div class="card mb">
      <div class="tbl-scroll">
        <table class="t">
          <thead><tr><th>#</th><th>IP Address</th><th>Page Views</th><th>Last Seen</th></tr></thead>
          <tbody>
            <?php
            $ipRank=0;
            while($ip=mysqli_fetch_assoc($topIpRes)):
              $ipRank++;
            ?>
            <tr>
              <td style="color:var(--text-3)"><?=$ipRank?></td>
              <td class="mono hl"><?=htmlspecialchars($ip['ip_address'])?></td>
              <td><span class="pill pill-blue"><span class="pill-dot"></span><?=number_format((int)$ip['hits'])?></span></td>
              <td class="mono"><?=timeAgoS($ip['last_seen'])?></td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ══ VISIT LOG ══ -->
    <div class="sec"><h2><i class="fa-solid fa-scroll"></i>Full Visit Log</h2><span class="sec-badge"><?=number_format($logTotal)?> RECORDS</span></div>
    <div class="card mb">
      <form method="GET" action="">
        <input type="hidden" name="lp" value="1">
        <div class="filter-row">
          <div class="fg"><label>Search Page</label><input type="text" name="ls" placeholder="/page.php…" value="<?=htmlspecialchars($logSearch)?>"></div>
          <div class="fg"><label>Filter IP</label><input type="text" name="lip" placeholder="192.168…" value="<?=htmlspecialchars($logIP)?>" style="min-width:130px"></div>
          <div class="fg">
            <label>Date</label>
            <input type="date" name="ldate" value="<?=htmlspecialchars($logDate)?>" style="min-width:130px">
          </div>
          <div class="fg">
            <label>Device</label>
            <select name="ldev">
              <option value="">All</option>
              <?php foreach(['Desktop','Mobile','Tablet'] as $d){$s=$logDevice===$d?'selected':'';echo"<option value='$d' $s>$d</option>";}?>
            </select>
          </div>
          <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
          <?php if($logSearch||$logIP||$logDate||$logDevice):?>
          <a href="settings.php" class="btn-clear">Clear</a>
          <?php endif;?>
        </div>
      </form>
      <div class="tbl-scroll">
        <table class="t">
          <thead>
            <tr>
              <th>Time</th><th>IP Address</th><th>Page</th><th>Method</th>
              <th>Browser</th><th>OS</th><th>Device</th><th>Referrer</th>
            </tr>
          </thead>
          <tbody>
            <?php while($log=mysqli_fetch_assoc($logRes)): ?>
            <tr>
              <td class="mono" style="font-size:10.5px"><?=date('d M H:i:s',strtotime($log['visit_time']))?></td>
              <td class="mono hl"><?=htmlspecialchars($log['ip_address'])?></td>
              <td class="mono" style="max-width:220px;overflow:hidden;text-overflow:ellipsis" title="<?=htmlspecialchars($log['page'])?>">
                <?=htmlspecialchars(basename(urldecode($log['page']))?:$log['page'])?>
              </td>
              <td>
                <span class="pill <?=$log['method']==='POST'?'pill-amber':'pill-teal'?>">
                  <?=htmlspecialchars($log['method'])?>
                </span>
              </td>
              <td class="mono" style="font-size:11px"><?=htmlspecialchars($log['browser']?:'—')?></td>
              <td class="mono" style="font-size:11px"><?=htmlspecialchars($log['os']?:'—')?></td>
              <td><?=deviceIcon($log['device_type'])?> <?=htmlspecialchars($log['device_type']?:'—')?></td>
              <td class="mono" style="font-size:10px;max-width:140px;overflow:hidden;text-overflow:ellipsis;color:var(--text-3)" title="<?=htmlspecialchars($log['referrer'])?>">
                <?=htmlspecialchars($log['referrer']?basename(parse_url($log['referrer'],PHP_URL_PATH))?:'—':'—')?>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php if($logTotal===0): ?>
            <tr><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-3)">No records match.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if($logPages>1): ?>
      <div class="pag">
        <?php
        $baseQS = http_build_query(array_filter(['ls'=>$logSearch,'lip'=>$logIP,'ldate'=>$logDate,'ldev'=>$logDevice,'llp'=>$llPage,'lls'=>$llSearch]));
        if($baseQS)$baseQS='&'.$baseQS;
        ?>
        <a href="?lp=<?=max(1,$logPage-1).$baseQS?>" class="pag-btn <?=$logPage<=1?'disabled':''?>"><i class="fa-solid fa-chevron-left"></i></a>
        <?php for($p=max(1,$logPage-2);$p<=min($logPages,$logPage+2);$p++): ?>
        <a href="?lp=<?=$p.$baseQS?>" class="pag-btn <?=$p===$logPage?'active':''?>"><?=$p?></a>
        <?php endfor; ?>
        <a href="?lp=<?=min($logPages,$logPage+1).$baseQS?>" class="pag-btn <?=$logPage>=$logPages?'disabled':''?>"><i class="fa-solid fa-chevron-right"></i></a>
        <span class="pag-info">Page <?=$logPage?> / <?=$logPages?> &nbsp;·&nbsp; <?=number_format($logTotal)?> records</span>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ LOGIN LOG ══ -->
    <div class="sec"><h2><i class="fa-solid fa-key"></i>Login Log</h2><span class="sec-badge"><?=number_format($llTotal)?> RECORDS</span></div>
    <div class="card mb">
      <form method="GET" action="">
        <input type="hidden" name="llp" value="1">
        <?php if($logSearch||$logIP||$logDate||$logDevice) echo "<input type='hidden' name='ls' value='".htmlspecialchars($logSearch)."'><input type='hidden' name='lip' value='".htmlspecialchars($logIP)."'><input type='hidden' name='ldate' value='".htmlspecialchars($logDate)."'><input type='hidden' name='ldev' value='".htmlspecialchars($logDevice)."'>"; ?>
        <div class="filter-row">
          <div class="fg"><label>Search User / IP</label><input type="text" name="lls" placeholder="username or IP…" value="<?=htmlspecialchars($llSearch)?>"></div>
          <button type="submit" class="btn-filter"><i class="fa-solid fa-filter"></i> Filter</button>
          <?php if($llSearch):?><a href="settings.php" class="btn-clear">Clear</a><?php endif;?>
        </div>
      </form>
      <div class="tbl-scroll">
        <table class="t">
          <thead>
            <tr><th>Time</th><th>Username</th><th>IP Address</th><th>Status</th><th>Browser / Device</th><th>Session ID</th><th>Note</th></tr>
          </thead>
          <tbody>
            <?php while($ll=mysqli_fetch_assoc($llRes)):
              $ua=_parseUA($ll['user_agent']??'');
            ?>
            <tr>
              <td class="mono" style="font-size:10.5px"><?=date('d M Y H:i:s',strtotime($ll['login_time']))?></td>
              <td class="hl" style="font-family:'JetBrains Mono',monospace"><?=htmlspecialchars($ll['username']?:'—')?></td>
              <td class="mono"><?=htmlspecialchars($ll['ip_address'])?></td>
              <td>
                <?php if($ll['success']): ?>
                <span class="pill pill-green"><span class="pill-dot"></span>Success</span>
                <?php else: ?>
                <span class="pill pill-red"><span class="pill-dot"></span>Failed</span>
                <?php endif; ?>
              </td>
              <td class="mono" style="font-size:10.5px"><?=htmlspecialchars("{$ua['browser']} / {$ua['device']}")?></td>
              <td class="mono" style="font-size:10px;color:var(--text-3)"><?=htmlspecialchars(substr($ll['session_id']??'—',0,16))?>…</td>
              <td style="font-size:11px;color:var(--text-3)"><?=htmlspecialchars($ll['note']?:'—')?></td>
            </tr>
            <?php endwhile; ?>
            <?php if($llTotal===0): ?>
            <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-3)">No login records yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if($llPages>1): ?>
      <div class="pag">
        <?php
        $bqs2=http_build_query(array_filter(['lls'=>$llSearch,'lp'=>$logPage,'ls'=>$logSearch,'lip'=>$logIP,'ldate'=>$logDate,'ldev'=>$logDevice]));
        if($bqs2)$bqs2='&'.$bqs2;
        ?>
        <a href="?llp=<?=max(1,$llPage-1).$bqs2?>" class="pag-btn <?=$llPage<=1?'disabled':''?>"><i class="fa-solid fa-chevron-left"></i></a>
        <?php for($p=max(1,$llPage-2);$p<=min($llPages,$llPage+2);$p++): ?>
        <a href="?llp=<?=$p.$bqs2?>" class="pag-btn <?=$p===$llPage?'active':''?>"><?=$p?></a>
        <?php endfor; ?>
        <a href="?llp=<?=min($llPages,$llPage+1).$bqs2?>" class="pag-btn <?=$llPage>=$llPages?'disabled':''?>"><i class="fa-solid fa-chevron-right"></i></a>
        <span class="pag-info">Page <?=$llPage?> / <?=$llPages?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- ══ SYSTEM HEALTH ══ -->
    <div class="sec"><h2><i class="fa-solid fa-server"></i>System Health</h2></div>
    <div class="card mb">
      <div class="card-body">
        <div class="sys-grid">
          <div class="sys-item">
            <div class="sys-label">PHP Version</div>
            <div class="sys-val ok"><?=htmlspecialchars($phpVersion)?></div>
          </div>
          <div class="sys-item">
            <div class="sys-label">MySQL Version</div>
            <div class="sys-val ok"><?=htmlspecialchars($dbVersion)?></div>
          </div>
          <div class="sys-item">
            <div class="sys-label">Database</div>
            <div class="sys-val ok">Connected</div>
            <div class="sys-sub">conn.php</div>
          </div>
          <div class="sys-item">
            <div class="sys-label">Server Time</div>
            <div class="sys-val"><?=date('H:i:s')?></div>
            <div class="sys-sub"><?=date('l, d M Y')?></div>
          </div>
          <div class="sys-item">
            <div class="sys-label">Max Upload</div>
            <div class="sys-val"><?=ini_get('upload_max_filesize')?></div>
          </div>
          <div class="sys-item">
            <div class="sys-label">Memory Limit</div>
            <div class="sys-val"><?=ini_get('memory_limit')?></div>
          </div>
          <div class="sys-item">
            <div class="sys-label">Session Status</div>
            <div class="sys-val ok"><?=session_status()===PHP_SESSION_ACTIVE?'Active':'Inactive'?></div>
          </div>
          <div class="sys-item">
            <div class="sys-label">Error Reporting</div>
            <div class="sys-val"><?=error_reporting()===0?'Off':'On'?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Table stats -->
    <div class="sec"><h2><i class="fa-solid fa-database"></i>Database Tables</h2></div>
    <div class="card mb">
      <div class="tbl-scroll">
        <table class="t">
          <thead><tr><th>Table</th><th>Approx Rows</th><th>Size on Disk</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach($tableStats as $name=>$stat): ?>
            <tr>
              <td class="mono hl"><?=htmlspecialchars($name)?></td>
              <td class="mono"><?=number_format($stat['rows'])?></td>
              <td class="mono"><?=$stat['size']?></td>
              <td><span class="pill pill-green"><span class="pill-dot"></span>OK</span></td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$tableStats): ?>
            <tr><td colspan="4" style="text-align:center;padding:1.5rem;color:var(--text-3)">No table data available.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ══ DANGER ZONE ══ -->
    <div class="sec"><h2><i class="fa-solid fa-triangle-exclamation"></i>Maintenance</h2></div>
    <div class="card danger-card mb">
      <div class="card-head"><h3 style="color:var(--red)"><i class="fa-solid fa-trash"></i>Purge Old Data</h3></div>
      <div class="card-body">
        <p style="font-size:12.5px;color:var(--text-2);margin-bottom:1.2rem">Remove old visit logs to keep the database light. Login logs are kept separately.</p>
        <div style="display:flex;gap:1.2rem;flex-wrap:wrap;align-items:flex-end">
          <form method="POST" action="" onsubmit="return confirm('Purge visit logs older than the selected period?')" style="display:flex;gap:10px;align-items:flex-end">
            <div class="fg">
              <label>Keep last N days</label>
              <select name="keep_days" style="background:var(--card);border:1px solid var(--border);border-radius:6px;padding:7px 10px;font-size:12px;color:var(--text);outline:none">
                <option value="7">7 days</option>
                <option value="14">14 days</option>
                <option value="30" selected>30 days</option>
                <option value="60">60 days</option>
                <option value="90">90 days</option>
              </select>
            </div>
            <button type="submit" name="purge_logs" class="btn-danger"><i class="fa-solid fa-broom"></i> Purge Visit Logs</button>
          </form>
          <form method="POST" action="" onsubmit="return confirm('Clear ALL login logs? This cannot be undone.')">
            <button type="submit" name="purge_login" class="btn-danger"><i class="fa-solid fa-key"></i> Clear Login Logs</button>
          </form>
        </div>
      </div>
    </div>

  </div><!-- .content -->

  <div class="footer">
    STEPHEN KANJA SCHOOL &nbsp;·&nbsp; SITE MONITOR v1.0 &nbsp;·&nbsp;
    LAST REFRESHED: <?=date('d M Y H:i:s')?> &nbsp;·&nbsp; © 2026 KELVIN MUTINDA
  </div>
</div>

<!-- ════════ CHARTS ════════ -->
<script>
Chart.defaults.font.family = "'JetBrains Mono', monospace";
Chart.defaults.color       = '#666';

const GOLD='rgba(240,192,64,0.9)', GOLD_BG='rgba(240,192,64,0.1)';
const GREEN='rgba(34,197,94,0.85)', GREEN_BG='rgba(34,197,94,0.1)';
const RED='rgba(239,68,68,0.85)', RED_BG='rgba(239,68,68,0.1)';
const BLUE='rgba(59,130,246,0.85)', TEAL='rgba(20,184,166,0.85)';
const AMBER='rgba(245,158,11,0.85)';
const PURPLE='rgba(168,85,247,0.85)';
const PALETTE=[GOLD,TEAL,GREEN,BLUE,AMBER,RED,PURPLE,'rgba(236,72,153,0.85)'];

const gridLine = 'rgba(255,255,255,0.05)';
const opts = { responsive:true, maintainAspectRatio:false };

/* ─ 14-day visits ─ */
new Chart(document.getElementById('dayChart'),{
  type:'bar',
  data:{
    labels: <?=json_encode($dayLabels)?>,
    datasets:[{
      label:'Page Views',
      data: <?=json_encode($dayData)?>,
      backgroundColor:GOLD_BG,
      borderColor:GOLD,
      borderWidth:1.5, borderRadius:4, borderSkipped:false
    },{
      type:'line', label:'Trend',
      data: <?=json_encode($dayData)?>,
      borderColor:'rgba(240,192,64,0.5)',
      backgroundColor:'transparent',
      borderWidth:2, pointRadius:0, tension:0.45
    }]
  },
  options:{...opts,
    plugins:{legend:{display:false}},
    scales:{
      x:{grid:{color:gridLine},ticks:{font:{size:10}}},
      y:{grid:{color:gridLine},beginAtZero:true}
    }
  }
});

/* ─ Hourly ─ */
new Chart(document.getElementById('hourChart'),{
  type:'bar',
  data:{
    labels:[...Array(24).keys()].map(h=>h+':00'),
    datasets:[{
      data: <?=json_encode(array_values($hourData))?>,
      backgroundColor: <?=json_encode(array_values($hourData))?>.map(v=>v>0?GOLD_BG:'rgba(255,255,255,0.02)'),
      borderColor: <?=json_encode(array_values($hourData))?>.map(v=>v>0?GOLD:'rgba(255,255,255,0.05)'),
      borderWidth:1.5, borderRadius:3, borderSkipped:false
    }]
  },
  options:{...opts,
    plugins:{legend:{display:false}},
    scales:{
      x:{grid:{color:gridLine},ticks:{font:{size:9},maxRotation:0}},
      y:{grid:{color:gridLine},beginAtZero:true}
    }
  }
});

/* ─ Browser doughnut ─ */
new Chart(document.getElementById('browChart'),{
  type:'doughnut',
  data:{
    labels: <?=json_encode($browLabels)?>,
    datasets:[{data:<?=json_encode($browData)?>,backgroundColor:PALETTE,borderWidth:1,borderColor:'#191919',hoverOffset:6}]
  },
  options:{...opts,cutout:'58%',
    plugins:{legend:{position:'bottom',labels:{padding:10,boxWidth:10,font:{size:10}}}}
  }
});

/* ─ Device doughnut ─ */
new Chart(document.getElementById('devChart'),{
  type:'doughnut',
  data:{
    labels: <?=json_encode($devLabels)?>,
    datasets:[{data:<?=json_encode($devData)?>,backgroundColor:[TEAL,GOLD,BLUE,AMBER],borderWidth:1,borderColor:'#191919',hoverOffset:6}]
  },
  options:{...opts,cutout:'58%',
    plugins:{legend:{position:'bottom',labels:{padding:10,boxWidth:10,font:{size:10}}}}
  }
});

/* ─ OS doughnut ─ */
new Chart(document.getElementById('osChart'),{
  type:'doughnut',
  data:{
    labels: <?=json_encode($osLabels)?>,
    datasets:[{data:<?=json_encode($osData)?>,backgroundColor:PALETTE,borderWidth:1,borderColor:'#191919',hoverOffset:6}]
  },
  options:{...opts,cutout:'58%',
    plugins:{legend:{position:'bottom',labels:{padding:10,boxWidth:10,font:{size:10}}}}
  }
});

/* ─ Login trend ─ */
new Chart(document.getElementById('loginChart'),{
  type:'bar',
  data:{
    labels: <?=json_encode($loginDayLabels)?>,
    datasets:[
      {label:'Success', data:<?=json_encode($loginSuccess)?>, backgroundColor:GREEN_BG, borderColor:GREEN, borderWidth:1.5, borderRadius:3, borderSkipped:false},
      {label:'Failed',  data:<?=json_encode($loginFail)?>,   backgroundColor:RED_BG,   borderColor:RED,   borderWidth:1.5, borderRadius:3, borderSkipped:false}
    ]
  },
  options:{...opts,
    plugins:{legend:{position:'bottom',labels:{padding:12,boxWidth:10,font:{size:10}}}},
    scales:{
      x:{grid:{color:gridLine},ticks:{font:{size:10}}},
      y:{grid:{color:gridLine},beginAtZero:true,ticks:{stepSize:1}}
    }
  }
});

/* ── Sidebar ── */
function openSidebar()  { document.getElementById('sidebar').classList.add('open'); document.getElementById('overlay').classList.add('active'); document.body.style.overflow='hidden'; }
function closeSidebar() { document.getElementById('sidebar').classList.remove('open'); document.getElementById('overlay').classList.remove('active'); document.body.style.overflow=''; }
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeSidebar(); });

/* ── Countdown refresh ── */
let secs = 60;
const ri = document.querySelector('.auto-refresh');
setInterval(()=>{ secs--; if(ri)ri.innerHTML=`<i class="fa-solid fa-rotate"></i> Auto-refresh: ${secs}s`; if(secs<=0)location.reload(); },1000);
</script>

</body>
</html>