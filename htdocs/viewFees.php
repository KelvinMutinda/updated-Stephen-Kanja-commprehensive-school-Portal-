<?php
     require_once __DIR__ . '/page_guard.php';
include 'conn.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// ── Filters from GET ─────────────────────────────────────────
$filter_grade = isset($_GET['grade']) ? mysqli_real_escape_string($conn, $_GET['grade']) : '';
$filter_term  = isset($_GET['term'])  ? mysqli_real_escape_string($conn, $_GET['term'])  : '';
$filter_year  = isset($_GET['year'])  ? mysqli_real_escape_string($conn, $_GET['year'])  : '';
$search       = isset($_GET['search'])? mysqli_real_escape_string($conn, trim($_GET['search'])) : '';

// ── Build WHERE clause ────────────────────────────────────────
$where_parts = [];
if ($filter_grade !== '') $where_parts[] = "Grade = '$filter_grade'";
if ($filter_term  !== '') $where_parts[] = "Term = '$filter_term'";
if ($filter_year  !== '') $where_parts[] = "Year = '$filter_year'";
if ($search       !== '') $where_parts[] = "(firstName LIKE '%$search%' OR surname LIKE '%$search%' OR Assesment LIKE '%$search%' OR StudentID LIKE '%$search%')";
$where_sql = count($where_parts) > 0 ? 'WHERE ' . implode(' AND ', $where_parts) : '';

// ── Main records ──────────────────────────────────────────────
$records = mysqli_query($conn,
    "SELECT *, (Fee + AssesmentFee + Activity + Other) AS TotalPaid
     FROM Fees $where_sql
     ORDER BY payment_date DESC, Grade+0 ASC, surname ASC"
);

if (!$records) {
    die("Query error: " . mysqli_error($conn));
}

// ── Summary stats ─────────────────────────────────────────────
$stats_res = mysqli_query($conn,
    "SELECT
        COUNT(*)                              AS total_records,
        COUNT(DISTINCT Assesment)             AS total_students,
        SUM(Fee)                              AS total_fee,
        SUM(AssesmentFee)                     AS total_assess,
        SUM(Activity)                         AS total_activity,
        SUM(Other)                            AS total_other,
        SUM(Fee + AssesmentFee + Activity + Other) AS grand_total
     FROM Fees $where_sql"
);
$stats = mysqli_fetch_assoc($stats_res);

// ── Distinct values for filters ───────────────────────────────
$grades_res = mysqli_query($conn, "SELECT DISTINCT Grade FROM Fees ORDER BY Grade+0");
$terms_res  = mysqli_query($conn, "SELECT DISTINCT Term  FROM Fees ORDER BY Term+0");
$years_res  = mysqli_query($conn, "SELECT DISTINCT Year  FROM Fees ORDER BY Year DESC");

$all_grades = [];
$all_terms  = [];
$all_years  = [];
while ($r = mysqli_fetch_assoc($grades_res)) $all_grades[] = $r['Grade'];
while ($r = mysqli_fetch_assoc($terms_res))  $all_terms[]  = $r['Term'];
while ($r = mysqli_fetch_assoc($years_res))  $all_years[]  = $r['Year'];

// ── URL query string for the currently applied filters, reused by
//    the "Download Filtered Receipts" button so it can pull the same
//    record set from generate_receipt.php (by learner, by grade, or
//    by term/year period). ──
$filterQuery = http_build_query(array_filter([
    'grade'  => $filter_grade,
    'term'   => $filter_term,
    'year'   => $filter_year,
    'search' => $search,
]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Fee Payments — Stephen Kanja School</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --gold:           #f0c040;
      --gold-dim:       #c9a030;
      --gold-pale:      rgba(240,192,64,0.10);
      --black:          #111111;
      --dark:           #1a1a1a;
      --mid:            #2a2a2a;
      --bg:             #f4f4f2;
      --bg-card:        #ffffff;
      --bg-input:       #f8f8f6;
      --text-primary:   #1a1a18;
      --text-secondary: #5f5e5a;
      --text-tertiary:  #888780;
      --border:         rgba(0,0,0,0.09);
      --radius-md:      8px;
      --radius-lg:      12px;
      --sidebar-w:      260px;
    }

    html { scroll-behavior: smooth; }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text-primary);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* ══ OVERLAY ══ */
    .overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:200; backdrop-filter:blur(2px); }
    .overlay.active { display:block; }

    /* ══ HEADER ══ */
    .site-header {
      position: sticky; top: 0; z-index: 300;
      background: var(--black); border-bottom: 3px solid var(--gold);
      display: flex; align-items: center; gap: 1rem;
      padding: 0 1.6rem; height: 62px; flex-shrink: 0;
    }
    .hamburger {
      display: none; background: var(--mid); border: none; cursor: pointer;
      color: #fff; font-size: 14px; width: 34px; height: 34px; border-radius: 7px;
      align-items: center; justify-content: center; flex-shrink: 0; transition: background .2s;
    }
    .hamburger:hover { background: var(--gold); color: var(--black); }
    .school-logo {
      width: 36px; height: 36px; border-radius: 8px; background: var(--gold);
      display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .school-logo i { color: var(--black); font-size: 16px; }
    .school-name-wrap { flex: 1; min-width: 0; }
    .school-name {
      font-family: 'Bebas Neue', sans-serif; font-size: 1.4rem;
      letter-spacing: .12em; color: #fff; white-space: nowrap; overflow: hidden;
      text-overflow: ellipsis; line-height: 1;
    }
    .school-name span { color: var(--gold); }
    .school-motto { font-size: 10px; color: #666; font-weight: 500; letter-spacing: .1em; text-transform: uppercase; margin-top: 2px; }
    .header-tag { font-size: 11px; color: #555; letter-spacing: .05em; display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .header-tag i { color: var(--gold); font-size: 10px; }

    /* ══ LAYOUT ══ */
    .layout { display: flex; flex: 1; }

    /* ══ SIDEBAR ══ */
    .sidebar {
      width: var(--sidebar-w); background: var(--black);
      border-right: 1px solid var(--mid); display: flex; flex-direction: column;
      flex-shrink: 0; transition: transform .3s cubic-bezier(.4,0,.2,1);
      position: relative; overflow: hidden;
    }
    .sidebar::before {
      content:''; position:absolute; top:0; left:0; width:3px; height:100%;
      background: linear-gradient(to bottom, var(--gold) 0%, transparent 100%);
    }
    .sidebar-inner { flex:1; overflow-y:auto; padding:1.2rem 0; scrollbar-width:thin; scrollbar-color:var(--mid) transparent; }
    .nav-label { font-size:10px; font-weight:600; letter-spacing:.12em; text-transform:uppercase; color:#444; padding:.8rem 1.4rem .4rem; }
    .sidebar nav { display:flex; flex-direction:column; gap:2px; padding:0 .75rem; }
    .sidebar nav a {
      display:flex; align-items:center; gap:11px; padding:9px 13px;
      border-radius:var(--radius-md); font-size:13.5px; font-weight:400; color:#aaa;
      text-decoration:none; border-left:3px solid transparent; transition:all .18s;
    }
    .sidebar nav a i { width:17px; text-align:center; font-size:13px; color:#555; flex-shrink:0; transition:color .15s; }
    .sidebar nav a:hover { background:rgba(255,255,255,.04); color:#fff; border-left-color:var(--gold); }
    .sidebar nav a:hover i { color:var(--gold); }
    .sidebar nav a.active { background:rgba(240,192,64,.08); color:#fff; font-weight:500; border-left-color:var(--gold); }
    .sidebar nav a.active i { color:var(--gold); }
    .sidebar-footer { padding:1rem 1.4rem; border-top:1px solid var(--mid); font-size:11px; color:#444; letter-spacing:.04em; }

    /* ══ MAIN ══ */
    .main { flex:1; min-width:0; padding:2rem; display:flex; flex-direction:column; gap:1.5rem; }

    /* ── Page title ── */
    .page-eyebrow { font-size:11px; font-weight:600; letter-spacing:.1em; text-transform:uppercase; color:var(--gold-dim); margin-bottom:4px; }
    .page-title { font-family:'DM Sans',sans-serif; font-size:24px; font-weight:400; color:var(--text-primary); line-height:1.2; }

    /* ── Card ── */
    .card {
      background:var(--bg-card); border:1px solid var(--border);
      border-radius:var(--radius-lg); overflow:hidden;
      animation: fadeUp .4s ease both;
    }
    .card-head {
      background:var(--black); border-bottom:2px solid var(--gold);
      padding:.8rem 1.4rem; display:flex; align-items:center; justify-content:space-between; gap:8px;
      flex-wrap: wrap;
    }
    .card-head-label { font-family:'Bebas Neue',sans-serif; font-size:1rem; letter-spacing:.1em; color:#fff; display:flex; align-items:center; gap:8px; }
    .card-head-label i { color:var(--gold); }
    .card-body { padding:1.4rem 1.6rem; }

    /* ── Summary stat cards ── */
    .stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
    .stat-card {
      background:var(--bg-card); border:1px solid var(--border);
      border-radius:var(--radius-lg); padding:1rem 1.2rem;
      display:flex; flex-direction:column; gap:4px;
      animation:fadeUp .4s ease both;
    }
    .stat-card:nth-child(1) { animation-delay:.05s; }
    .stat-card:nth-child(2) { animation-delay:.10s; }
    .stat-card:nth-child(3) { animation-delay:.15s; }
    .stat-card:nth-child(4) { animation-delay:.20s; }
    .stat-label { font-size:11px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:var(--text-tertiary); }
    .stat-value { font-size:22px; font-weight:600; color:var(--text-primary); }
    .stat-sub   { font-size:11px; color:var(--text-tertiary); }
    .stat-card.accent-gold .stat-value { color:var(--gold-dim); }
    .stat-card.accent-green .stat-value { color:#16a34a; }
    .stat-card.accent-blue  .stat-value { color:#2563eb; }

    /* ── Filter bar ── */
    .filter-grid {
      display:grid; grid-template-columns:repeat(4,1fr) auto auto;
      gap:.9rem 1rem; align-items:end;
    }
    .filter-field { display:flex; flex-direction:column; gap:5px; }
    .filter-field label { font-size:11px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; color:var(--text-secondary); }
    .filter-field select,
    .filter-field input[type=text] {
      width:100%; padding:9px 12px;
      font-family:'DM Sans',sans-serif; font-size:13px; color:var(--text-primary);
      background:var(--bg-input);
      border:1px solid rgba(0,0,0,.1); border-radius:var(--radius-md);
      outline:none; appearance:none; -webkit-appearance:none;
      transition:border-color .15s, box-shadow .15s;
    }
    .filter-field select {
      background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%23888780' stroke-width='1.5' stroke-linecap='round' fill='none'/%3E%3C/svg%3E");
      background-repeat:no-repeat; background-position:right 10px center; padding-right:32px;
    }
    .filter-field select:focus,
    .filter-field input[type=text]:focus { border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.12); }

    .btn-filter {
      font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600;
      color:var(--black); background:var(--gold); border:none;
      border-radius:var(--radius-md); padding:10px 20px;
      cursor:pointer; display:flex; align-items:center; gap:7px;
      transition:background .15s, transform .1s; white-space:nowrap; align-self:flex-end;
    }
    .btn-filter:hover { background:var(--gold-dim); }
    .btn-filter:active { transform:scale(.98); }

    .btn-reset {
      font-family:'DM Sans',sans-serif; font-size:13px; font-weight:500;
      color:var(--text-secondary); background:var(--bg-card);
      border:1px solid var(--border); border-radius:var(--radius-md);
      padding:10px 16px; cursor:pointer; display:flex; align-items:center; gap:7px;
      transition:all .15s; white-space:nowrap; text-decoration:none; align-self:flex-end;
    }
    .btn-reset:hover { border-color:rgba(0,0,0,.2); color:var(--text-primary); }

    /* ── Badge ── */
    .badge {
      display:inline-block; padding:2px 10px; border-radius:20px;
      font-size:10px; font-weight:700; letter-spacing:.05em; text-transform:uppercase;
    }
    .badge-gold  { background:var(--gold);    color:var(--black); }
    .badge-green { background:#dcfce7; color:#166534; }
    .badge-blue  { background:#dbeafe; color:#1e40af; }

    /* ── Active filter pills ── */
    .active-filters { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
    .active-filter-label { font-size:11px; font-weight:600; letter-spacing:.07em; text-transform:uppercase; color:var(--text-tertiary); }
    .filter-pill {
      display:inline-flex; align-items:center; gap:5px;
      padding:3px 10px 3px 10px; border-radius:20px;
      background:var(--gold-pale); border:1px solid rgba(240,192,64,.3);
      font-size:12px; font-weight:500; color:var(--text-secondary);
    }
    .filter-pill i { font-size:9px; color:var(--gold-dim); }

    /* ── Table ── */
    .table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    table.fees-table { width:100%; border-collapse:collapse; font-size:12.5px; min-width:960px; }

    .fees-table thead th {
      background:#fafafa; color:var(--text-tertiary);
      font-size:10.5px; font-weight:600; letter-spacing:.08em; text-transform:uppercase;
      padding:10px 12px; border-bottom:1px solid rgba(0,0,0,.07);
      text-align:left; white-space:nowrap; cursor:default;
    }
    .fees-table thead th.sortable { cursor:pointer; user-select:none; }
    .fees-table thead th.sortable:hover { color:var(--text-primary); }
    .fees-table thead th .sort-icon { margin-left:4px; font-size:9px; opacity:.5; }
    .fees-table thead th.check-col, .fees-table tbody td.check-col { width:32px; padding-left:14px; padding-right:0; }

    .fees-table tbody tr { border-bottom:1px solid rgba(0,0,0,.05); transition:background .12s; }
    .fees-table tbody tr:hover { background:var(--bg-input); }
    .fees-table tbody tr:last-child { border-bottom:none; }
    .fees-table td { padding:9px 12px; vertical-align:middle; }
    .fees-table tbody tr.row-selected { background: var(--gold-pale); }

    input.row-check, input#selectAll {
      width:15px; height:15px; cursor:pointer; accent-color: var(--gold);
    }

    /* Grade cell pill */
    .grade-pill {
      display:inline-block; padding:3px 10px; border-radius:20px;
      background:var(--black); color:var(--gold);
      font-size:11px; font-weight:700; letter-spacing:.04em; white-space:nowrap;
    }
    /* Term cell */
    .term-pill {
      display:inline-block; padding:3px 9px; border-radius:20px;
      background:#f0f0ee; color:var(--text-secondary);
      font-size:11px; font-weight:600; white-space:nowrap;
    }
    /* Amount cells */
    .amount { font-family:monospace; font-size:12px; color:var(--text-primary); }
    .amount-total { font-family:monospace; font-size:12.5px; font-weight:700; color:#16a34a; }
    /* Date cell */
    .date-cell { font-size:11px; color:var(--text-tertiary); white-space:nowrap; }

    /* Row number */
    .row-num { font-size:11px; color:var(--text-tertiary); font-family:monospace; }
    /* Assessment No */
    .assess-no { font-family:monospace; font-size:11px; color:var(--text-tertiary); }

    /* Grade group header row */
    .grade-group-row td {
      background:var(--black); color:var(--gold);
      font-family:'Bebas Neue',sans-serif; font-size:.9rem; letter-spacing:.1em;
      padding:7px 12px; border-bottom:2px solid var(--gold);
    }

    /* ── Totals footer ── */
    .totals-row td {
      background:#fafafa; border-top:2px solid var(--gold);
      font-weight:700; font-size:12.5px; padding:10px 12px;
    }
    .totals-row .amount-total { font-size:13px; }

    /* ── Empty state ── */
    .empty-state { text-align:center; padding:3.5rem 2rem; }
    .empty-icon { width:56px; height:56px; background:var(--black); border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; }
    .empty-icon i { color:var(--gold); font-size:22px; }
    .empty-state p { font-size:14px; color:var(--text-tertiary); }

    /* ── Toolbar buttons (print / receipts) ── */
    .btn-print, .btn-receipt {
      font-family:'DM Sans',sans-serif; font-size:12px; font-weight:500;
      border-radius:var(--radius-md);
      padding:7px 14px; cursor:pointer; display:flex; align-items:center; gap:6px;
      transition:all .15s; text-decoration:none; white-space:nowrap;
    }
    .btn-print {
      color:var(--text-secondary); background:var(--bg-card); border:1px solid var(--border);
    }
    .btn-print:hover { border-color:rgba(0,0,0,.2); color:var(--text-primary); }

    .btn-receipt {
      color:var(--black); background:var(--gold); border:1px solid var(--gold); font-weight:600;
    }
    .btn-receipt:hover { background:var(--gold-dim); border-color:var(--gold-dim); }
    .btn-receipt.disabled {
      pointer-events:none; opacity:.45; background:var(--bg-card); color:var(--text-tertiary); border-color:var(--border);
    }

    .toolbar-actions { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }

    /* ── Footer ── */
    .site-footer {
      background:var(--black); border-top:2px solid var(--gold);
      padding:1rem 1.5rem; text-align:center; font-size:11px; color:#444; letter-spacing:.04em;
    }

    /* ══ ANIMATIONS ══ */
    @keyframes fadeUp {
      from { opacity:0; transform:translateY(14px); }
      to   { opacity:1; transform:translateY(0); }
    }

    /* ══ RESPONSIVE ══ */
    @media (max-width:900px) {
      .sidebar { position:fixed; top:62px; left:0; bottom:0; z-index:250; transform:translateX(-100%); box-shadow:6px 0 30px rgba(0,0,0,.4); }
      .sidebar.open { transform:translateX(0); }
      .hamburger { display:flex; }
      .header-tag { display:none; }
      .main { padding:1.25rem 1rem 3rem; }
      .stats-grid { grid-template-columns:repeat(2,1fr); }
      .filter-grid { grid-template-columns:repeat(2,1fr); }
    }
    @media (max-width:520px) {
      .stats-grid { grid-template-columns:1fr 1fr; }
      .filter-grid { grid-template-columns:1fr; }
      .btn-filter, .btn-reset { width:100%; justify-content:center; }
      .toolbar-actions { width:100%; }
      .btn-print, .btn-receipt { flex:1; justify-content:center; }
    }

    /* ══ PRINT ══ */
    @media print {
      .site-header, .sidebar, .site-footer,
      .filter-section, .btn-print, .btn-receipt, .check-col, .hamburger { display:none !important; }
      .layout { display:block; }
      .main { padding:0; }
      body { background:#fff; }
      .card { border:1px solid #ccc; }
      .grade-group-row td { background:#111 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }
  </style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- ════════ HEADER ════════ -->
<header class="site-header">
  <button class="hamburger" id="hamburger" aria-label="Open menu">
    <i class="fa-solid fa-bars"></i>
  </button>
  <div class="school-logo"><i class="fa-solid fa-graduation-cap"></i></div>
  <div class="school-name-wrap">
    <div class="school-name">Stephen Kanja <span>School</span></div>
    <div class="school-motto">Aim Higher</div>
  </div>
  <div class="header-tag"><i class="fa-solid fa-circle-dot"></i> Fee Payments</div>
</header>

<div class="layout">

  <!-- ════════ SIDEBAR ════════ -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
      <p class="nav-label">Main</p>
      <nav>
        <a href="teacherpanel.php"><i class="fa-solid fa-house"></i> Home</a>
        <a href="Students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
      </nav>
      <p class="nav-label">Academics</p>
      <nav>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
        <a href="LearningMaterials.php"><i class="fa-solid fa-book"></i> Learning Materials</a>
      </nav>
      <p class="nav-label">Administration</p>
      <nav>
        <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
        <a href="RegisterTeacher.php"><i class="fa-solid fa-user-plus"></i> Register Teachers</a>
      </nav>
      <p class="nav-label">Finance</p>
      <nav>
        <a href="Fee.php"><i class="fa-solid fa-coins"></i> Record Fees</a>
        <a href="ViewFees.php" class="active"><i class="fa-solid fa-receipt"></i> View Payments</a>
      </nav>
    </div>
    <div class="sidebar-footer">© 2026 Kelvin Mutinda</div>
  </aside>

  <!-- ════════ MAIN ════════ -->
  <main class="main">

    <!-- Page title -->
    <div>
      <p class="page-eyebrow">Finance</p>
      <h1 class="page-title">Fee Payments</h1>
      <p style="font-size:13px;color:var(--text-tertiary);margin-top:4px;">
        Browse, filter and review all fee payment records. Filter by learner, grade or term/year, then
        download Word receipts for exactly what's shown.
      </p>
    </div>

    <!-- ── Summary Stats ── -->
    <div class="stats-grid">
      <div class="stat-card accent-green" style="animation-delay:.05s">
        <span class="stat-label">Grand Total Collected</span>
        <span class="stat-value">KES <?= number_format($stats['grand_total'] ?? 0, 0) ?></span>
        <span class="stat-sub"><?= number_format($stats['total_records'] ?? 0) ?> payment records</span>
      </div>
      <div class="stat-card" style="animation-delay:.10s">
        <span class="stat-label">School Fee</span>
        <span class="stat-value">KES <?= number_format($stats['total_fee'] ?? 0, 0) ?></span>
        <span class="stat-sub">Tuition collected</span>
      </div>
      <div class="stat-card" style="animation-delay:.15s">
        <span class="stat-label">Assessment Fee</span>
        <span class="stat-value">KES <?= number_format($stats['total_assess'] ?? 0, 0) ?></span>
        <span class="stat-sub">Exams &amp; activity</span>
      </div>
      <div class="stat-card accent-blue" style="animation-delay:.20s">
        <span class="stat-label">Unique Students</span>
        <span class="stat-value"><?= number_format($stats['total_students'] ?? 0) ?></span>
        <span class="stat-sub">Have paid this period</span>
      </div>
    </div>

    <!-- ── Filter Card ── -->
    <div class="card filter-section" style="animation-delay:.08s">
      <div class="card-head">
        <span class="card-head-label"><i class="fa-solid fa-sliders"></i> Filter Payments</span>
        <?php if ($filter_grade || $filter_term || $filter_year || $search): ?>
          <a href="ViewFees.php" class="btn-reset" style="padding:5px 12px;font-size:12px;">
            <i class="fa-solid fa-rotate-left"></i> Clear all
          </a>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <form method="GET" action="ViewFees.php">
          <div class="filter-grid">

            <div class="filter-field">
              <label for="gradeF">Grade</label>
              <select id="gradeF" name="grade">
                <option value="">— All Grades —</option>
                <?php foreach ($all_grades as $g): ?>
                  <option value="<?= htmlspecialchars($g) ?>" <?= $filter_grade == $g ? 'selected' : '' ?>>
                    Grade <?= htmlspecialchars($g) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-field">
              <label for="termF">Term</label>
              <select id="termF" name="term">
                <option value="">— All Terms —</option>
                <?php foreach ($all_terms as $t): ?>
                  <option value="<?= htmlspecialchars($t) ?>" <?= $filter_term == $t ? 'selected' : '' ?>>
                    Term <?= htmlspecialchars($t) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-field">
              <label for="yearF">Year</label>
              <select id="yearF" name="year">
                <option value="">— All Years —</option>
                <?php foreach ($all_years as $y): ?>
                  <option value="<?= htmlspecialchars($y) ?>" <?= $filter_year == $y ? 'selected' : '' ?>>
                    <?= htmlspecialchars($y) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="filter-field">
              <label for="searchF">Search Learner</label>
              <input type="text" id="searchF" name="search"
                     placeholder="Name, Student ID or assess. no."
                     value="<?= htmlspecialchars($search) ?>">
            </div>

            <button type="submit" class="btn-filter">
              <i class="fa-solid fa-magnifying-glass"></i> Filter
            </button>

            <a href="ViewFees.php" class="btn-reset">
              <i class="fa-solid fa-rotate-left"></i> Reset
            </a>

          </div>
        </form>

        <!-- Active filter pills -->
        <?php if ($filter_grade || $filter_term || $filter_year || $search): ?>
          <div class="active-filters" style="margin-top:1rem;">
            <span class="active-filter-label">Active:</span>
            <?php if ($filter_grade): ?>
              <span class="filter-pill"><i class="fa-solid fa-layer-group"></i> Grade <?= htmlspecialchars($filter_grade) ?></span>
            <?php endif; ?>
            <?php if ($filter_term): ?>
              <span class="filter-pill"><i class="fa-solid fa-calendar-half"></i> Term <?= htmlspecialchars($filter_term) ?></span>
            <?php endif; ?>
            <?php if ($filter_year): ?>
              <span class="filter-pill"><i class="fa-solid fa-calendar"></i> <?= htmlspecialchars($filter_year) ?></span>
            <?php endif; ?>
            <?php if ($search): ?>
              <span class="filter-pill"><i class="fa-solid fa-magnifying-glass"></i> "<?= htmlspecialchars($search) ?>"</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Payments Table ── -->
    <div class="card" style="animation-delay:.15s">
      <div class="card-head">
        <span class="card-head-label">
          <i class="fa-solid fa-table"></i> Payment Records
        </span>
        <div class="toolbar-actions">
          <span class="badge badge-gold"><?= mysqli_num_rows($records) ?> records</span>

          <!-- Download receipts for every record matching the current filters:
               a learner (via Search), a grade, or a term/year period. -->
          <a class="btn-receipt <?= mysqli_num_rows($records) === 0 ? 'disabled' : '' ?>"
             href="generate_receipt.php?<?= $filterQuery ?>"
             title="Download a Word receipt for every row currently shown">
            <i class="fa-solid fa-file-word"></i> Download Filtered Receipts
          </a>

          <!-- Download receipts for only the checked rows -->
          <a class="btn-receipt disabled" id="downloadSelectedBtn" href="#"
             title="Tick rows below, then download just those receipts">
            <i class="fa-solid fa-check-double"></i> Download Selected <span id="selectedCount"></span>
          </a>

          <button class="btn-print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Print
          </button>
        </div>
      </div>

      <div class="table-scroll">
        <table class="fees-table" id="feesTable">
          <thead>
            <tr>
              <th class="check-col"><input type="checkbox" id="selectAll" title="Select all"></th>
              <th>#</th>
              <th>Student ID</th>
              <th>First Name</th>
              <th>Surname</th>
              <th>Grade</th>
              <th>Term</th>
              <th>Year</th>
              <th>School Fee</th>
              <th>Assess. Fee</th>
              <th>Activity</th>
              <th>Other</th>
              <th>Total Paid</th>
              <th>Date Paid</th>
            </tr>
          </thead>
          <tbody>
            <?php
            if ($records && mysqli_num_rows($records) > 0):
              $row_num      = 1;

              // Buffer all rows so we can do grade subtotals
              $all_rows = [];
              while ($row = mysqli_fetch_assoc($records)) $all_rows[] = $row;

              // Group by grade
              $grouped = [];
              foreach ($all_rows as $row) {
                  $grouped[$row['Grade']][] = $row;
              }

              foreach ($grouped as $grade => $rows):
                $grade_fee      = array_sum(array_column($rows, 'Fee'));
                $grade_assess   = array_sum(array_column($rows, 'AssesmentFee'));
                $grade_activity = array_sum(array_column($rows, 'Activity'));
                $grade_other    = array_sum(array_column($rows, 'Other'));
                $grade_total    = array_sum(array_column($rows, 'TotalPaid'));
            ?>
              <!-- Grade group header -->
              <tr class="grade-group-row">
                <td colspan="14">
                  <i class="fa-solid fa-layer-group" style="margin-right:8px;color:var(--gold);"></i>
                  Grade <?= htmlspecialchars($grade) ?> — <?= count($rows) ?> record<?= count($rows) !== 1 ? 's' : '' ?>
                </td>
              </tr>

              <?php foreach ($rows as $row):
                $displayId = ($row['StudentID'] !== null && $row['StudentID'] !== '') ? $row['StudentID'] : $row['Assesment'];
              ?>
              <tr>
                <td class="check-col">
                  <input type="checkbox" class="row-check" value="<?= (int)$row['id'] ?>">
                </td>
                <td class="row-num"><?= $row_num++ ?></td>
                <td class="assess-no"><?= htmlspecialchars($displayId) ?></td>
                <td><?= htmlspecialchars($row['firstName']) ?></td>
                <td style="font-weight:500;"><?= htmlspecialchars($row['surname']) ?></td>
                <td><span class="grade-pill">G<?= htmlspecialchars($row['Grade']) ?></span></td>
                <td><span class="term-pill">T<?= htmlspecialchars($row['Term']) ?></span></td>
                <td style="font-size:12px;color:var(--text-tertiary);"><?= htmlspecialchars($row['Year']) ?></td>
                <td class="amount">KES <?= number_format($row['Fee'], 0) ?></td>
                <td class="amount">KES <?= number_format($row['AssesmentFee'], 0) ?></td>
                <td class="amount">KES <?= number_format($row['Activity'], 0) ?></td>
                <td class="amount">KES <?= number_format($row['Other'], 0) ?></td>
                <td class="amount-total">KES <?= number_format($row['TotalPaid'], 0) ?></td>
                <td class="date-cell">
                  <?php if (!empty($row['payment_date'])): ?>
                    <i class="fa-regular fa-calendar" style="font-size:10px;margin-right:3px;"></i>
                    <?= date('d M Y', strtotime($row['payment_date'])) ?>
                    <br><span style="font-size:10px;"><?= date('g:i A', strtotime($row['payment_date'])) ?></span>
                  <?php else: ?>
                    <span style="color:#ccc;">—</span>
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>

              <!-- Grade subtotal row -->
              <tr class="totals-row">
                <td colspan="8" style="font-size:12px;color:var(--text-secondary);">
                  <i class="fa-solid fa-sigma" style="margin-right:5px;color:var(--gold-dim);"></i>
                  Grade <?= htmlspecialchars($grade) ?> Subtotal
                </td>
                <td class="amount-total">KES <?= number_format($grade_fee, 0) ?></td>
                <td class="amount-total">KES <?= number_format($grade_assess, 0) ?></td>
                <td class="amount-total">KES <?= number_format($grade_activity, 0) ?></td>
                <td class="amount-total">KES <?= number_format($grade_other, 0) ?></td>
                <td class="amount-total" style="color:var(--gold-dim);">KES <?= number_format($grade_total, 0) ?></td>
                <td></td>
              </tr>

            <?php endforeach; ?>

            <?php else: ?>
              <tr>
                <td colspan="14">
                  <div class="empty-state">
                    <div class="empty-icon"><i class="fa-solid fa-file-invoice"></i></div>
                    <p>No payment records found<?= ($filter_grade || $filter_term || $filter_year || $search) ? ' for the selected filters' : '' ?>.</p>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          </tbody>

          <!-- Grand total footer -->
          <?php if ($records && isset($all_rows) && count($all_rows) > 0): ?>
          <tfoot>
            <tr class="totals-row">
              <td colspan="8" style="font-size:13px;letter-spacing:.04em;">
                <i class="fa-solid fa-sigma" style="margin-right:5px;color:var(--gold-dim);"></i>
                GRAND TOTAL
              </td>
              <td class="amount-total">KES <?= number_format(array_sum(array_column($all_rows,'Fee')), 0) ?></td>
              <td class="amount-total">KES <?= number_format(array_sum(array_column($all_rows,'AssesmentFee')), 0) ?></td>
              <td class="amount-total">KES <?= number_format(array_sum(array_column($all_rows,'Activity')), 0) ?></td>
              <td class="amount-total">KES <?= number_format(array_sum(array_column($all_rows,'Other')), 0) ?></td>
              <td class="amount-total" style="font-size:14px;color:#15803d;">
                KES <?= number_format(array_sum(array_column($all_rows,'TotalPaid')), 0) ?>
              </td>
              <td></td>
            </tr>
          </tfoot>
          <?php endif; ?>

        </table>
      </div>
    </div>

  </main>
</div>

<!-- ════════ FOOTER ════════ -->
<footer class="site-footer">
  &copy; Designed by Kelvin Mutinda 2026. All rights reserved.
</footer>

<script>
  /* ── Sidebar ── */
  const hamburger = document.getElementById('hamburger');
  const sidebar   = document.getElementById('sidebar');
  const overlay   = document.getElementById('overlay');

  function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('active');
    hamburger.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('active');
    hamburger.innerHTML = '<i class="fa-solid fa-bars"></i>';
    document.body.style.overflow = '';
  }
  hamburger.addEventListener('click', () =>
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar()
  );
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

  /* ── Row selection → "Download Selected Receipts" ── */
  const selectAll        = document.getElementById('selectAll');
  const rowChecks         = () => Array.from(document.querySelectorAll('.row-check'));
  const downloadSelected  = document.getElementById('downloadSelectedBtn');
  const selectedCountSpan = document.getElementById('selectedCount');

  function refreshSelection() {
    const checked = rowChecks().filter(cb => cb.checked);
    const ids = checked.map(cb => cb.value);

    checked.forEach(cb => cb.closest('tr').classList.add('row-selected'));
    rowChecks().filter(cb => !cb.checked).forEach(cb => cb.closest('tr').classList.remove('row-selected'));

    if (ids.length > 0) {
      downloadSelected.href = 'generate_receipt.php?ids=' + ids.join(',');
      downloadSelected.classList.remove('disabled');
      selectedCountSpan.textContent = '(' + ids.length + ')';
    } else {
      downloadSelected.href = '#';
      downloadSelected.classList.add('disabled');
      selectedCountSpan.textContent = '';
    }

    if (selectAll) {
      const all = rowChecks();
      selectAll.checked = all.length > 0 && all.every(cb => cb.checked);
      selectAll.indeterminate = ids.length > 0 && !selectAll.checked;
    }
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      rowChecks().forEach(cb => { cb.checked = selectAll.checked; });
      refreshSelection();
    });
  }

  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('row-check')) {
      refreshSelection();
    }
  });
</script>

</body>
</html>
<?php mysqli_close($conn); ?>