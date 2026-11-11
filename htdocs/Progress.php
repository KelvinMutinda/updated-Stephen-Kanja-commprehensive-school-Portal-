<?php
     require_once __DIR__ . '/page_guard.php';
include 'conn.php';
require_once 'add-exam-type.php';
include_once 'subjects_config.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!defined('FULL_SCHOOL_NAME')) define('FULL_SCHOOL_NAME', 'Stephen Kanja Primary & Junior School');

$isHoi     = (($_SESSION['account_type'] ?? '') === 'hoi');
$homeLink  = $isHoi ? 'Hoi.php' : 'teacherpanel.php';
$roleLabel = $isHoi ? 'Head of Institution' : 'Teacher Panel';

/* Map of subject code (as stored in exam_scores.subject) => display name */
$subjectColumns = [
  'math'   => 'Mathematics',
  'eng'    => 'English',
  'kisw'   => 'Kiswahili',
  'sst'    => 'S.S.T',
  'scie'   => 'Science',
  'ca'     => 'C.A',
  'agri'   => 'Agriculture',
  're'     => 'R.E',
  'pretec' => 'Pre-Technical',
];

/* ══════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════ */
function pr_e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pr_norm($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s)); }

/* Level code for a score. Uses the same grading scales as View Results (4-level G1–6, 8-level G7–9). */
function pr_level($score, $grade) {
  $grade = (int)$grade;
  if ($grade >= 1 && $grade <= 9 && function_exists('bandInfoForGrade')) {
    $b = bandInfoForGrade((float)$score, $grade);
    if (is_array($b) && !empty($b['code'])) return $b['code'];
  }
  if ($score >= 75) return 'E.E';
  if ($score >= 50) return 'M.E';
  if ($score >= 25) return 'A.E';
  return 'B.E';
}
function pr_cls($code) { return strtolower(str_replace('.', '', (string)$code)); }
function pr_tier($score) { return $score >= 75 ? 'ee' : ($score >= 50 ? 'me' : ($score >= 25 ? 'ae' : 'be')); }

/* ── Exam types: same list Upload Results / View Results use (built-in + custom) ── */
$EXAM_TYPES = getExamTypes($conn); // code => ['label' => ..., 'custom' => bool]
$examTypeLabels = [];
$examIsCustom   = [];
foreach ($EXAM_TYPES as $code => $et) {
  $examTypeLabels[$code] = $et['label'];
  $examIsCustom[$code]   = !empty($et['custom']);
}
if (!$examTypeLabels) {
  $examTypeLabels = ['opener' => 'Opener', 'midterm' => 'Mid-Term', 'endterm' => 'End Term'];
}

/* Group the exam_type values actually stored in saved records under their exam type, so custom
   exams are found even when the stored text differs from the type's code. Values that match
   no known type still get an entry so their records are never hidden. */
$rawByCode = [];   // code => [stored raw values]
$examExtra = [];   // codes that exist only in saved records
$exRes = mysqli_query($conn, "SELECT DISTINCT exam_type FROM exam_sessions");
while ($exRes && ($er = mysqli_fetch_assoc($exRes))) {
  $raw = trim((string)$er['exam_type']);
  if ($raw === '') continue;
  $c = resolveExamCode($raw, $EXAM_TYPES) ?? $raw;
  $rawByCode[$c][] = $raw;
  if (!isset($examTypeLabels[$c])) {
    $examTypeLabels[$c] = ucfirst($raw);
    $examIsCustom[$c]   = true;
    $examExtra[$c]      = true;
  }
}

/* Chronological order inside a term: built-ins first, then custom exams in list order */
function pr_exam_seq($raw, array $examTypeLabels, array $EXAM_TYPES) {
  static $seq = null;
  if ($seq === null) {
    $seq = ['opener', 'midterm', 'endterm'];
    foreach (array_keys($examTypeLabels) as $c) {
      $n = pr_norm($c);
      if (!in_array($n, $seq, true)) $seq[] = $n;
    }
  }
  $c = resolveExamCode((string)$raw, $EXAM_TYPES) ?? (string)$raw;
  $i = array_search(pr_norm($c), $seq, true);
  return $i === false ? count($seq) : $i;
}
/* Display label for a stored exam_type value */
function pr_exam_label($raw, array $labels, array $EXAM_TYPES) {
  if (isset($labels[$raw])) return $labels[$raw];
  $resolved = resolveExamCode((string)$raw, $EXAM_TYPES);
  if ($resolved !== null && isset($labels[$resolved])) return $labels[$resolved];
  return ucfirst((string)$raw);
}

/* ── Filters ── */
$gradeFilter   = (isset($_GET['grade'])   && ctype_digit((string)$_GET['grade'])   && (int)$_GET['grade'] >= 1 && (int)$_GET['grade'] <= 9) ? (int)$_GET['grade'] : null;
$termFilter    = (isset($_GET['term'])    && in_array((string)$_GET['term'], ['1', '2', '3'], true)) ? (int)$_GET['term'] : null;
$yearFilter    = (isset($_GET['year'])    && ctype_digit((string)$_GET['year']) && (int)$_GET['year'] > 0) ? (int)$_GET['year'] : null;
$subjectFilter = (!empty($_GET['subject']) && isset($subjectColumns[$_GET['subject']])) ? $_GET['subject'] : null;
$studentFilter = (isset($_GET['student_id']) && ctype_digit((string)$_GET['student_id']) && (int)$_GET['student_id'] > 0) ? (int)$_GET['student_id'] : null;

$examTypeFilter = null;
$examRaw = trim((string)($_GET['exam_type'] ?? ''));
if ($examRaw !== '') {
  if (isset($examTypeLabels[$examRaw])) {
    $examTypeFilter = $examRaw;
  } else {
    $r = resolveExamCode($examRaw, $EXAM_TYPES);
    if ($r !== null && isset($examTypeLabels[$r])) $examTypeFilter = $r;
  }
}

/* Records are only loaded once at least one filter is chosen */
$hasFilter = ($gradeFilter || $termFilter || $examTypeFilter || $yearFilter || $subjectFilter || $studentFilter);

$dbRows = [];
if ($hasFilter) {
  $where = []; $params = []; $types = '';
  if ($gradeFilter)   { $where[] = 'es.grade = ?';   $params[] = $gradeFilter;   $types .= 'i'; }
  if ($termFilter)    { $where[] = 'es.term = ?';    $params[] = $termFilter;    $types .= 'i'; }
  if ($yearFilter)    { $where[] = 'es.year = ?';    $params[] = $yearFilter;    $types .= 'i'; }
  if ($subjectFilter) { $where[] = 'sc.subject = ?'; $params[] = $subjectFilter; $types .= 's'; }
  if ($studentFilter) { $where[] = 's.id = ?';       $params[] = $studentFilter; $types .= 'i'; }
  if ($examTypeFilter) {
    $raws = array_values(array_unique(array_merge($rawByCode[$examTypeFilter] ?? [], [$examTypeFilter])));
    $where[] = 'es.exam_type IN (' . implode(',', array_fill(0, count($raws), '?')) . ')';
    foreach ($raws as $rw) { $params[] = $rw; $types .= 's'; }
  }

  $sql = "SELECT s.id AS student_id, s.Assesment, s.firstName, s.surname,
                 es.grade, es.term, es.exam_type, es.year,
                 sc.subject, sc.score
          FROM exam_scores sc
          JOIN exam_sessions es ON es.id = sc.exam_session_id
          JOIN Student s ON s.id = sc.student_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY s.firstName, s.surname, es.year DESC, es.term DESC, sc.subject";

  $stmt = $conn->prepare($sql);
  if ($stmt) {
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $dbRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  }
}

/* ── Build display rows: one row per subject entry ── */
$rows = [];
$distinctStudentIds = [];
foreach ($dbRows as $r) {
  $distinctStudentIds[$r['student_id']] = true;
  $score = (int)$r['score'];
  $level = pr_level($score, $r['grade'] ?? 0);
  $rows[] = [
    'admNo'      => $r['Assesment'] ?? '—',
    'firstName'  => $r['firstName'] ?? '',
    'lastName'   => $r['surname'] ?? '',
    'classGrade' => $r['grade'] ?? '—',
    'term'       => $r['term'] ?? '—',
    'examType'   => pr_exam_label($r['exam_type'], $examTypeLabels, $EXAM_TYPES),
    'examSeq'    => pr_exam_seq($r['exam_type'], $examTypeLabels, $EXAM_TYPES),
    'year'       => $r['year'] ?? '—',
    'subject'    => $subjectColumns[$r['subject']] ?? $r['subject'],
    'score'      => $score,
    'letter'     => $level,
    'tier'       => pr_tier($score),
  ];
}
/* Learner → newest year/term first → exams in chronological order → subject */
usort($rows, function ($a, $b) {
  $n = strcasecmp($a['firstName'] . ' ' . $a['lastName'], $b['firstName'] . ' ' . $b['lastName']);
  if ($n !== 0) return $n;
  if ($a['year'] !== $b['year'])   return (int)$b['year'] <=> (int)$a['year'];
  if ($a['term'] !== $b['term'])   return (int)$b['term'] <=> (int)$a['term'];
  if ($a['examSeq'] !== $b['examSeq']) return $b['examSeq'] <=> $a['examSeq'];
  return strcasecmp($a['subject'], $b['subject']);
});
$count = count($rows);

/* ── Mean mark — overall, per-subject, per-tier ── */
$overallMean = 0.0;
$subjectMeans = [];
$tierCounts = ['ee' => 0, 'me' => 0, 'ae' => 0, 'be' => 0];
$passingCount = 0; $failingCount = 0;
if ($count > 0) {
  $scoreSum = 0;
  foreach ($rows as $row) {
    $scoreSum += $row['score'];
    $label = $row['subject'];
    if (!isset($subjectMeans[$label])) $subjectMeans[$label] = ['sum' => 0, 'count' => 0];
    $subjectMeans[$label]['sum'] += $row['score'];
    $subjectMeans[$label]['count']++;
    $tierCounts[$row['tier']]++;
    if ($row['score'] >= 50) $passingCount++; else $failingCount++;
  }
  $overallMean = $scoreSum / $count;
  foreach ($subjectMeans as $label => &$m) { $m['mean'] = $m['sum'] / $m['count']; }
  unset($m);
  ksort($subjectMeans);
}
$totalStudents = count($distinctStudentIds);
$passRate = $count ? round($passingCount / $count * 100) : 0;

/* ── Filter summary text (chips + PDF header) ── */
$filterParts = [];
$filterParts[] = $gradeFilter ? "Grade $gradeFilter" : 'All Grades';
$filterParts[] = $termFilter ? "Term $termFilter" : 'All Terms';
$filterParts[] = $examTypeFilter ? $examTypeLabels[$examTypeFilter] : 'All Exams';
$filterParts[] = $yearFilter ? (string)$yearFilter : 'All Years';
$filterParts[] = $subjectFilter ? $subjectColumns[$subjectFilter] : 'All Subjects';
if ($studentFilter) {
  $learnerLookup = mysqli_query($conn, "SELECT firstName, surname FROM Student WHERE id = " . (int)$studentFilter);
  if ($learnerLookup && ($lrow = mysqli_fetch_assoc($learnerLookup))) {
    $filterParts[] = trim($lrow['firstName'] . ' ' . $lrow['surname']);
  }
}

/* ── Learner list + years for the dropdowns ── */
$learnerOptions = [];
$learnerRes = mysqli_query($conn, "SELECT id, firstName, surname, Grade FROM Student ORDER BY firstName, surname");
if ($learnerRes) { while ($row = mysqli_fetch_assoc($learnerRes)) $learnerOptions[] = $row; }

$yearOptions = [];
$yearRes = mysqli_query($conn, "SELECT DISTINCT year FROM exam_sessions ORDER BY year DESC");
if ($yearRes) { while ($row = mysqli_fetch_assoc($yearRes)) $yearOptions[] = (int)$row['year']; }

/* ── Data handed to the browser for PDF generation ── */
$subjectMeanList = [];
foreach ($subjectMeans as $label => $m) $subjectMeanList[] = [$label, round($m['mean'], 2), $m['count']];
$pdfData = [
  'school'       => FULL_SCHOOL_NAME,
  'filters'      => $filterParts,
  'generated'    => date('d M Y, H:i'),
  'count'        => $count,
  'overall'      => round($overallMean, 2),
  'rows'         => $rows,
  'subjectMeans' => $subjectMeanList,
];
$builtIn  = array_filter($examTypeLabels, fn($c) => empty($examIsCustom[$c]), ARRAY_FILTER_USE_KEY);
$customEx = array_filter($examTypeLabels, fn($c) => !empty($examIsCustom[$c]) && empty($examExtra[$c]), ARRAY_FILTER_USE_KEY);
$extraEx  = array_filter($examTypeLabels, fn($c) => !empty($examExtra[$c]), ARRAY_FILTER_USE_KEY);
?><!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Progress Records — Kanja School</title>
  <script>
    /* Apply saved theme before first paint (shared with the other dashboards) */
    try { var th = localStorage.getItem('hoi-theme'); if (th === 'dark' || th === 'light') document.documentElement.setAttribute('data-theme', th); } catch (e) {}
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:#f3f5fa; --surface:#ffffff; --surface-2:#f8f9fc; --surface-3:#eef1f7;
      --text:#0f172a; --text-2:#475569; --muted:#8392a5;
      --border:#e4e8f0; --border-2:#eef1f6;
      --navy:#0b1f3a; --gold:#f0c040; --gold-d:#c8992a; --gold-pale:#fdf3d4; --gold-ink:#7a5a10;
      --ee:#16a34a; --ee-bg:#dcfce7; --me:#2563eb; --me-bg:#dbeafe;
      --ae:#b45309; --ae-bg:#fef3c7; --be:#dc2626; --be-bg:#fee2e2;
      --ee2:#15803d; --ee2-bg:#bbf7d0; --me2:#123657; --me2-bg:#cfe0f3;
      --ae2:#92400e; --ae2-bg:#fde68a; --be2:#7f1d1d; --be2-bg:#fecaca;
      --shadow-sm:0 1px 2px rgba(15,23,42,.05); --shadow:0 6px 24px rgba(15,23,42,.07);
      --r:14px; --r-lg:18px; --sidebar-w:264px; --topbar-h:64px;
      color-scheme: light;
    }
    [data-theme="dark"] {
      --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
      --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3; --border:#1f2d47; --border-2:#192740;
      --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040;
      --ee:#4ade80; --ee-bg:rgba(34,197,94,.16); --me:#7db3f0; --me-bg:rgba(96,165,250,.16);
      --ae:#fbbf24; --ae-bg:rgba(251,191,36,.15); --be:#f87171; --be-bg:rgba(248,113,113,.16);
      --ee2:#86efac; --ee2-bg:rgba(34,197,94,.26); --me2:#a9cdf6; --me2-bg:rgba(96,165,250,.26);
      --ae2:#fcd34d; --ae2-bg:rgba(251,191,36,.26); --be2:#fca5a5; --be2-bg:rgba(248,113,113,.26);
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

    .content { padding:24px 32px 60px; width:100%; max-width:1480px; margin:0 auto; display:flex; flex-direction:column; gap:20px; }

    .page-head { display:flex; align-items:flex-end; justify-content:space-between; gap:14px; flex-wrap:wrap; }
    .page-eyebrow { font-size:.7rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold-d); }
    .page-title { font-size:clamp(1.4rem,2.4vw,1.8rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; }
    .page-sub { color:var(--muted); font-size:.84rem; margin-top:2px; }

    /* ───────── Filters ───────── */
    .filter-bar { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:18px 20px; box-shadow:var(--shadow-sm); display:grid; grid-template-columns:repeat(3,minmax(140px,1fr)); gap:14px; align-items:end; animation:fadeUp .45s ease both; }
    .fg { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .fg label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .fg select, .search { appearance:none; width:100%; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; padding:10px 14px; font-family:inherit; font-size:.88rem; font-weight:500; color:var(--text); transition:.15s; height:42px; }
    .fg select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; padding-right:38px; cursor:pointer; text-overflow:ellipsis; }
    .fg select:hover, .search:hover { border-color:rgba(37,99,235,.35); }
    .fg select:focus, .search:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .filter-actions { grid-column:1/-1; display:flex; gap:8px; justify-content:flex-end; flex-wrap:wrap; }
    .btn { background:var(--navy); color:#fff; border:none; border-radius:10px; padding:0 20px; height:42px; font-family:inherit; font-size:.84rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; gap:8px; text-decoration:none; white-space:nowrap; }
    [data-theme="dark"] .btn { background:var(--gold); color:#101826; }
    .btn:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(11,31,58,.25); }
    .btn.ghost { background:transparent; color:var(--text-2); border:1.5px solid var(--border); }
    .btn.ghost:hover { background:var(--surface-3); box-shadow:none; }
    .btn.pdf { background:var(--gold); color:#101826; box-shadow:0 6px 16px rgba(240,192,64,.3); }
    .btn.sm { height:36px; padding:0 14px; font-size:.78rem; }

    .chips { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
    .chip { font-size:.72rem; font-weight:700; padding:5px 12px; border-radius:99px; background:var(--navy); color:#fff; }
    [data-theme="dark"] .chip { background:var(--surface-3); color:var(--text); }
    .chip.school { background:var(--gold-pale); color:var(--gold-ink); }

    /* ───────── KPI ───────── */
    .kpi-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:14px; }
    .kpi { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:16px 18px; box-shadow:var(--shadow-sm); position:relative; overflow:hidden; transition:transform .2s,box-shadow .2s; animation:fadeUp .45s ease both; }
    .kpi:hover { transform:translateY(-3px); box-shadow:var(--shadow); }
    .kpi::after { content:''; position:absolute; right:-26px; top:-26px; width:88px; height:88px; border-radius:50%; background:var(--accent,var(--gold)); opacity:.12; }
    .kpi .ico { width:36px; height:36px; border-radius:10px; background:color-mix(in srgb,var(--accent,var(--gold)) 16%,transparent); color:var(--accent,var(--gold-d)); display:flex; align-items:center; justify-content:center; font-size:14px; margin-bottom:10px; }
    .kpi-label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .kpi-val { font-size:1.8rem; font-weight:800; line-height:1.1; letter-spacing:-.8px; margin-top:3px; font-variant-numeric:tabular-nums; }
    .kpi-sub { font-size:.72rem; color:var(--muted); margin-top:4px; }
    .kpi.gold{--accent:#d9a521} .kpi.green{--accent:#16a34a} .kpi.blue{--accent:#2563eb} .kpi.red{--accent:#dc2626} .kpi.violet{--accent:#7c3aed}

    /* ───────── Cards ───────── */
    .card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--shadow-sm); overflow:hidden; animation:fadeUp .45s .05s ease both; }
    .card-head { padding:15px 22px; border-bottom:1px solid var(--border-2); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .card-head h3 { font-size:.76rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); display:flex; align-items:center; gap:9px; }
    .card-head h3 i { color:var(--gold-d); }
    .card-body { padding:20px 22px; }
    .grid-2 { display:grid; grid-template-columns:1.3fr 1fr; gap:18px; }
    .tag { font-size:.66rem; font-weight:800; letter-spacing:.9px; text-transform:uppercase; padding:3px 10px; border-radius:99px; background:var(--gold-pale); color:var(--gold-ink); }

    .sbar { display:flex; align-items:center; gap:12px; margin-bottom:11px; }
    .sbar:last-child { margin-bottom:0; }
    .sbar-name { width:118px; flex-shrink:0; font-size:.78rem; font-weight:600; color:var(--text-2); }
    .sbar-track { flex:1; height:10px; border-radius:99px; background:var(--surface-3); overflow:hidden; }
    .sbar-track i { display:block; height:100%; border-radius:99px; transition:width .8s cubic-bezier(.4,0,.2,1); }
    .sbar-val { width:70px; text-align:right; font-size:.78rem; font-weight:700; font-variant-numeric:tabular-nums; }
    .sbar-val small { color:var(--muted); font-weight:500; }
    .t-ee{background:var(--ee)} .t-me{background:var(--me)} .t-ae{background:#d97706} .t-be{background:var(--be)}

    .dist { display:flex; height:14px; border-radius:99px; overflow:hidden; background:var(--surface-3); margin-bottom:16px; }
    .dist span { display:block; height:100%; }
    .dist-row { display:flex; align-items:center; gap:10px; padding:7px 0; font-size:.82rem; }
    .dist-row + .dist-row { border-top:1px solid var(--border-2); }
    .dist-row .dot { width:10px; height:10px; border-radius:3px; flex-shrink:0; }
    .dist-row .nm { color:var(--text-2); font-weight:500; }
    .dist-row .ct { margin-left:auto; font-weight:700; font-variant-numeric:tabular-nums; }
    .dist-row .ct small { color:var(--muted); font-weight:500; }

    /* ───────── Table ───────── */
    .table-tools { display:flex; align-items:center; gap:10px; padding:12px 22px; border-bottom:1px solid var(--border-2); flex-wrap:wrap; }
    .table-tools .search { max-width:300px; height:36px; font-size:.82rem; }
    .table-tools .hint { margin-left:auto; font-size:.72rem; color:var(--muted); }
    .table-scroll { overflow:auto; max-height:640px; -webkit-overflow-scrolling:touch; }
    table.data-table { width:100%; border-collapse:collapse; font-size:.83rem; min-width:900px; }
    table.data-table thead th { background:var(--surface-2); color:var(--muted); padding:11px 14px; text-align:left; font-size:.64rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; white-space:nowrap; border-bottom:1px solid var(--border); position:sticky; top:0; z-index:1; }
    table.data-table th[data-sort] { cursor:pointer; user-select:none; }
    table.data-table th[data-sort]:hover { color:var(--text); }
    table.data-table th[data-sort]::after { content:'\f0dc'; font-family:'Font Awesome 6 Free'; font-weight:900; margin-left:6px; opacity:.35; font-size:.62rem; }
    table.data-table th.asc::after { content:'\f0de'; opacity:1; color:var(--gold-d); }
    table.data-table th.desc::after { content:'\f0dd'; opacity:1; color:var(--gold-d); }
    table.data-table td { padding:10px 14px; border-bottom:1px solid var(--border-2); color:var(--text-2); vertical-align:middle; font-variant-numeric:tabular-nums; }
    table.data-table tbody tr:hover td { background:var(--surface-2); }
    table.data-table tbody tr:last-child td { border-bottom:none; }
    table.data-table td strong { color:var(--text); }
    table.data-table tfoot td { background:var(--gold-pale); font-weight:800; color:var(--text); border-top:2px solid var(--gold); position:sticky; bottom:0; }
    .mono { font-family:ui-monospace,Menlo,monospace; font-size:.76rem; background:var(--surface-3); padding:2px 8px; border-radius:6px; color:var(--text-2); }
    .avatar { width:30px; height:30px; border-radius:9px; background:var(--navy); display:inline-flex; align-items:center; justify-content:center; font-size:.68rem; font-weight:800; color:var(--gold); flex-shrink:0; }
    [data-theme="dark"] .avatar { background:var(--surface-3); }
    .name-with-avatar { display:flex; align-items:center; gap:10px; }
    .exam-chip { display:inline-block; font-size:.72rem; font-weight:700; padding:2px 9px; border-radius:99px; background:var(--surface-3); color:var(--text-2); white-space:nowrap; }
    .exam-chip.custom { background:var(--gold-pale); color:var(--gold-ink); }

    .meter { display:flex; align-items:center; gap:8px; }
    .meter .track { flex:1; height:6px; background:var(--surface-3); border-radius:99px; overflow:hidden; min-width:60px; }
    .meter .track i { display:block; height:100%; border-radius:99px; }
    .meter b { min-width:30px; text-align:right; color:var(--text); font-size:.82rem; }

    .lvl { display:inline-block; font-size:.66rem; font-weight:800; padding:2px 9px; border-radius:6px; letter-spacing:.4px; vertical-align:middle; }
    .lvl.ee,.lvl.ee1 { background:var(--ee-bg); color:var(--ee); }
    .lvl.ee2 { background:var(--ee2-bg); color:var(--ee2); }
    .lvl.me,.lvl.me1 { background:var(--me-bg); color:var(--me); }
    .lvl.me2 { background:var(--me2-bg); color:var(--me2); }
    .lvl.ae,.lvl.ae1 { background:var(--ae-bg); color:var(--ae); }
    .lvl.ae2 { background:var(--ae2-bg); color:var(--ae2); }
    .lvl.be,.lvl.be1 { background:var(--be-bg); color:var(--be); }
    .lvl.be2 { background:var(--be2-bg); color:var(--be2); }

    /* ───────── Empty / prompt state ───────── */
    .prompt { text-align:center; padding:54px 24px 46px; }
    .prompt .ico { width:68px; height:68px; border-radius:20px; margin:0 auto 16px; display:flex; align-items:center; justify-content:center; background:var(--gold-pale); color:var(--gold-d); font-size:26px; }
    .prompt h3 { font-size:1.1rem; font-weight:800; letter-spacing:-.2px; }
    .prompt p { color:var(--muted); font-size:.86rem; max-width:460px; margin:6px auto 0; }
    .quick { margin-top:22px; display:flex; flex-wrap:wrap; gap:8px; justify-content:center; }
    .quick-label { width:100%; font-size:.64rem; font-weight:800; letter-spacing:1.4px; text-transform:uppercase; color:var(--muted); margin-bottom:2px; }
    .quick a { text-decoration:none; padding:8px 16px; border-radius:10px; border:1.5px solid var(--border); background:var(--surface-2); color:var(--text-2); font-weight:700; font-size:.8rem; transition:.15s; }
    .quick a:hover { border-color:var(--gold); color:var(--text); transform:translateY(-1px); }

    .footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (min-width:1280px) { .filter-bar { grid-template-columns:repeat(6,minmax(130px,1fr)); } .filter-actions { grid-column:1/-1; } }
    @media (max-width:1180px) { .grid-2 { grid-template-columns:1fr; } }
    @media (max-width:1024px) {
      .app { grid-template-columns:1fr; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); }
      .sidebar.open { left:0; }
      .burger { display:inline-flex; align-items:center; justify-content:center; }
      .topbar { padding:0 18px; } .content { padding:18px 18px 50px; }
    }
    @media (max-width:640px) {
      .btn-gold span { display:none; }
      .filter-bar { grid-template-columns:1fr 1fr; padding:16px; }
      .tb-title small { display:none; }
      .sbar-name { width:92px; }
    }
    @media (max-width:420px) { .filter-bar { grid-template-columns:1fr; } }

    @media print {
      .sidebar, .topbar, .filter-bar, .table-tools, .backdrop, .filter-actions, .btn, .icon-btn, .btn-gold { display:none !important; }
      .app { display:block; } body { background:#fff; } .content { padding:0; } .card, .kpi { box-shadow:none; break-inside:avoid; } .table-scroll { max-height:none; overflow:visible; }
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
        <a href="<?= pr_e($homeLink) ?>"><i class="fa-solid fa-house"></i> Home</a>
        <a href="Students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="Progress.php" class="active" aria-current="page"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Administration</div>
        <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
       
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="download_template.php"><i class="fa-solid fa-file-excel"></i> Marks Template</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
      </div>
   
    </nav>

    <div class="side-user">
      <div class="av"><?= $isHoi ? 'HOI' : 'TR' ?></div>
      <div><b><?= pr_e($roleLabel) ?></b><small>Signed in</small></div>
      <a href="index.php" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>
  <div class="backdrop" id="backdrop" onclick="closeSidebar()"></div>

  <!-- ═══════ MAIN ═══════ -->
  <div class="main">

    <header class="topbar">
      <div class="tb-left">
        <button class="burger" id="burger" type="button" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
        <div class="tb-title">Progress Records<small><?= pr_e(FULL_SCHOOL_NAME) ?></small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="UploadResults.php" class="btn-gold"><i class="fa-solid fa-upload"></i><span>Upload Results</span></a>
        <a href="index.php" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <div class="page-head">
        <div>
          <p class="page-eyebrow">Academic Tracking</p>
          <h1 class="page-title">Progress Records</h1>
          <p class="page-sub">Choose a class, term, exam, year, subject or learner to load records.</p>
        </div>
      </div>

      <!-- Filters -->
      <form method="GET" action="" class="filter-bar" id="filterForm">
        <div class="fg">
          <label for="grade">Class / Grade</label>
          <select name="grade" id="grade">
            <option value="">All Grades</option>
            <?php for ($g = 1; $g <= 9; $g++): ?>
              <option value="<?= $g ?>" <?= $gradeFilter === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="fg">
          <label for="term">Term</label>
          <select name="term" id="term">
            <option value="">All Terms</option>
            <?php foreach ([1, 2, 3] as $tt): ?>
              <option value="<?= $tt ?>" <?= $termFilter === $tt ? 'selected' : '' ?>>Term <?= $tt ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label for="exam_type">Exam</label>
          <select name="exam_type" id="exam_type">
            <option value="">All Exams</option>
            <?php if ($builtIn): ?>
            <optgroup label="Standard exams">
              <?php foreach ($builtIn as $code => $label): ?>
                <option value="<?= pr_e($code) ?>" <?= $examTypeFilter === $code ? 'selected' : '' ?>><?= pr_e($label) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if ($customEx): ?>
            <optgroup label="Custom exams">
              <?php foreach ($customEx as $code => $label): ?>
                <option value="<?= pr_e($code) ?>" <?= $examTypeFilter === $code ? 'selected' : '' ?>><?= pr_e($label) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
            <?php if ($extraEx): ?>
            <optgroup label="Found in saved records">
              <?php foreach ($extraEx as $code => $label): ?>
                <option value="<?= pr_e($code) ?>" <?= $examTypeFilter === $code ? 'selected' : '' ?>><?= pr_e($label) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
        </div>
        <div class="fg">
          <label for="year">Year</label>
          <select name="year" id="year">
            <option value="">All Years</option>
            <?php foreach ($yearOptions as $y): ?>
              <option value="<?= $y ?>" <?= $yearFilter === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label for="subject">Subject</label>
          <select name="subject" id="subject">
            <option value="">All Subjects</option>
            <?php foreach ($subjectColumns as $code => $label): ?>
              <option value="<?= pr_e($code) ?>" <?= $subjectFilter === $code ? 'selected' : '' ?>><?= pr_e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label for="student_id">Learner</label>
          <select name="student_id" id="student_id">
            <option value="">All Learners</option>
            <?php foreach ($learnerOptions as $opt): ?>
              <option value="<?= (int)$opt['id'] ?>" <?= $studentFilter === (int)$opt['id'] ? 'selected' : '' ?>>
                <?= pr_e(trim($opt['firstName'] . ' ' . $opt['surname'])) ?><?= $opt['Grade'] ? ' (Grade ' . (int)$opt['Grade'] . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-actions">
          <?php if ($hasFilter): ?><a class="btn ghost" href="Progress.php"><i class="fa-solid fa-rotate-left"></i> Clear</a><?php endif; ?>
          <button class="btn" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Load records</button>
        </div>
      </form>

      <?php if (!$hasFilter): ?>
      <!-- Nothing selected yet: no records are loaded -->
      <div class="card">
        <div class="prompt">
          <div class="ico"><i class="fa-solid fa-filter"></i></div>
          <h3>Select a filter to view progress records</h3>
          <p>No records are shown by default. Pick at least one option above, such as a class, exam or learner, and the matching records will load.</p>
          <div class="quick">
            <span class="quick-label">Jump to a class</span>
            <?php for ($g = 1; $g <= 9; $g++): ?>
              <a href="?grade=<?= $g ?>">Grade <?= $g ?></a>
            <?php endfor; ?>
          </div>
        </div>
      </div>

      <?php else: ?>

      <div class="chips">
        <span class="chip school"><i class="fa-solid fa-graduation-cap"></i> <?= pr_e(FULL_SCHOOL_NAME) ?></span>
        <?php foreach ($filterParts as $part): ?><span class="chip"><?= pr_e($part) ?></span><?php endforeach; ?>
      </div>

      <?php if ($count === 0): ?>
      <div class="card">
        <div class="prompt">
          <div class="ico"><i class="fa-regular fa-folder-open"></i></div>
          <h3>No records found</h3>
          <p>Nothing matches these filters. Try removing one, or upload results first.</p>
          <div class="quick"><a href="Progress.php">Clear filters</a><a href="UploadResults.php">Upload results</a></div>
        </div>
      </div>

      <?php else: ?>

      <div class="kpi-strip">
        <div class="kpi gold" style="animation-delay:.03s">
          <div class="ico"><i class="fa-solid fa-users"></i></div>
          <div class="kpi-label">Distinct Learners</div>
          <div class="kpi-val"><?= number_format($totalStudents) ?></div>
        </div>
        <div class="kpi blue" style="animation-delay:.06s">
          <div class="ico"><i class="fa-solid fa-list-check"></i></div>
          <div class="kpi-label">Records Logged</div>
          <div class="kpi-val"><?= number_format($count) ?></div>
        </div>
        <div class="kpi violet" style="animation-delay:.09s">
          <div class="ico"><i class="fa-solid fa-calculator"></i></div>
          <div class="kpi-label">Mean Mark</div>
          <div class="kpi-val"><?= number_format($overallMean, 1) ?></div>
          <div class="kpi-sub">out of 100</div>
        </div>
        <div class="kpi green" style="animation-delay:.12s">
          <div class="ico"><i class="fa-solid fa-circle-check"></i></div>
          <div class="kpi-label">Passing (&ge;50)</div>
          <div class="kpi-val"><?= number_format($passingCount) ?></div>
          <div class="kpi-sub"><?= $passRate ?>% of records</div>
        </div>
        <div class="kpi red" style="animation-delay:.15s">
          <div class="ico"><i class="fa-solid fa-life-ring"></i></div>
          <div class="kpi-label">At Risk (&lt;50)</div>
          <div class="kpi-val"><?= number_format($failingCount) ?></div>
          <div class="kpi-sub"><?= 100 - $passRate ?>% of records</div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-head"><h3><i class="fa-solid fa-book"></i> Subject Means</h3><span class="tag"><?= count($subjectMeans) ?> subjects</span></div>
          <div class="card-body">
            <?php foreach ($subjectMeans as $sName => $sm): $mv = round($sm['mean'], 1); ?>
            <div class="sbar">
              <div class="sbar-name"><?= pr_e($sName) ?></div>
              <div class="sbar-track"><i class="t-<?= pr_tier($mv) ?>" style="width:<?= min(100, max(0, $mv)) ?>%"></i></div>
              <div class="sbar-val"><?= number_format($mv, 1) ?> <small>(<?= $sm['count'] ?>)</small></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><h3><i class="fa-solid fa-layer-group"></i> Performance Levels</h3></div>
          <div class="card-body">
            <div class="dist">
              <?php foreach ($tierCounts as $tk => $tv): if (!$tv) continue; ?>
                <span class="t-<?= $tk ?>" style="width:<?= round($tv / $count * 100, 1) ?>%"></span>
              <?php endforeach; ?>
            </div>
            <?php
              $tierNames = ['ee' => 'Exceeding (75–100)', 'me' => 'Meeting (50–74)', 'ae' => 'Approaching (25–49)', 'be' => 'Below (0–24)'];
              foreach ($tierNames as $tk => $tn): $tv = $tierCounts[$tk]; ?>
              <div class="dist-row">
                <span class="dot t-<?= $tk ?>"></span><span class="nm"><?= pr_e($tn) ?></span>
                <span class="ct"><?= $tv ?> <small>(<?= round($tv / $count * 100) ?>%)</small></span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h3><i class="fa-solid fa-table-list"></i> Progress Entries</h3>
          <div style="display:flex;gap:10px;align-items:center">
            <span class="tag"><?= number_format($count) ?> records</span>
            <button type="button" class="btn pdf sm" onclick="downloadPDF()"><i class="fa-solid fa-file-pdf"></i> Download PDF</button>
          </div>
        </div>
        <div class="table-tools">
          <input type="search" class="search" id="rowSearch" placeholder="Search learner, subject, exam, adm no…" aria-label="Search records">
          <span class="hint">Click a column heading to sort</span>
        </div>
        <div class="table-scroll">
          <table class="data-table" id="recTbl">
            <thead>
              <tr>
                <th data-sort="num">#</th>
                <th data-sort="text">Learner</th>
                <th data-sort="text">Adm No.</th>
                <th data-sort="num">Class</th>
                <th data-sort="num">Term</th>
                <th data-sort="text">Exam</th>
                <th data-sort="num">Year</th>
                <th data-sort="text">Subject</th>
                <th data-sort="num">Score</th>
                <th data-sort="text">Level</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $i => $row):
                $fullName = trim($row['firstName'] . ' ' . $row['lastName']);
                $initials = strtoupper((function_exists('mb_substr') ? mb_substr($row['firstName'] ?: '?', 0, 1) . mb_substr($row['lastName'] ?: '?', 0, 1) : substr($row['firstName'] ?: '?', 0, 1) . substr($row['lastName'] ?: '?', 0, 1)));
                $pct = min(100, max(0, $row['score']));
              ?>
              <tr class="lrow" data-s="<?= pr_e(strtolower($fullName . ' ' . $row['admNo'] . ' ' . $row['subject'] . ' ' . $row['examType'] . ' grade ' . $row['classGrade'] . ' term ' . $row['term'] . ' ' . $row['year'])) ?>">
                <td data-v="<?= $i + 1 ?>" style="color:var(--muted);font-size:.74rem"><?= $i + 1 ?></td>
                <td><div class="name-with-avatar"><div class="avatar"><?= pr_e($initials) ?></div><strong><?= pr_e($fullName) ?></strong></div></td>
                <td><span class="mono"><?= pr_e($row['admNo']) ?></span></td>
                <td data-v="<?= (int)$row['classGrade'] ?>">Grade <?= pr_e($row['classGrade']) ?></td>
                <td data-v="<?= (int)$row['term'] ?>">Term <?= pr_e($row['term']) ?></td>
                <td><span class="exam-chip"><?= pr_e($row['examType']) ?></span></td>
                <td data-v="<?= (int)$row['year'] ?>"><?= pr_e($row['year']) ?></td>
                <td><?= pr_e($row['subject']) ?></td>
                <td data-v="<?= $row['score'] ?>">
                  <div class="meter"><div class="track"><i class="t-<?= $row['tier'] ?>" style="width:<?= $pct ?>%"></i></div><b><?= $row['score'] ?></b></div>
                </td>
                <td><span class="lvl <?= pr_e(pr_cls($row['letter'])) ?>"><?= pr_e($row['letter']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="8" style="text-align:right">Mean Mark</td>
                <td colspan="2"><?= number_format($overallMean, 2) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <?php endif; /* count > 0 */ ?>
      <?php endif; /* hasFilter */ ?>

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

  /* ── Filters apply as soon as a dropdown changes ── */
  document.querySelectorAll('#filterForm select').forEach(s => s.addEventListener('change', () => document.getElementById('filterForm').submit()));

  /* ── Table search + sort ── */
  (function () {
    const tbl = document.getElementById('recTbl');
    if (!tbl) return;
    const inp = document.getElementById('rowSearch');
    inp.addEventListener('input', () => {
      const q = inp.value.trim().toLowerCase();
      tbl.querySelectorAll('tbody tr.lrow').forEach(tr => { tr.style.display = (!q || tr.dataset.s.includes(q)) ? '' : 'none'; });
    });
    tbl.querySelectorAll('thead th[data-sort]').forEach(th => {
      th.addEventListener('click', () => {
        const idx = th.cellIndex, num = th.dataset.sort === 'num';
        const dir = th.classList.contains('asc') ? -1 : 1;
        tbl.querySelectorAll('thead th').forEach(x => x.classList.remove('asc', 'desc'));
        th.classList.add(dir === 1 ? 'asc' : 'desc');
        const body = tbl.tBodies[0];
        const rows = [...body.querySelectorAll('tr.lrow')];
        rows.sort((a, b) => {
          const av = a.cells[idx].dataset.v ?? a.cells[idx].textContent.trim();
          const bv = b.cells[idx].dataset.v ?? b.cells[idx].textContent.trim();
          return (num ? (parseFloat(av) - parseFloat(bv)) : av.localeCompare(bv, undefined, { numeric: true })) * dir;
        });
        rows.forEach(r => body.appendChild(r));
      });
    });
  })();

  /* ── PDF download of the currently filtered records ── */
  const PDF_DATA = <?= json_encode($pdfData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

  function downloadPDF() {
    if (!window.jspdf) { alert('PDF library failed to load. Check your internet connection and try again.'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    const pageW = doc.internal.pageSize.getWidth();
    const pageH = doc.internal.pageSize.getHeight();
    const GOLD = [240, 192, 64], NAVY = [11, 31, 58];

    // Header band
    doc.setFillColor(...NAVY);
    doc.rect(0, 0, pageW, 78, 'F');
    doc.setFillColor(...GOLD);
    doc.rect(0, 78, pageW, 3, 'F');
    doc.setTextColor(...GOLD);
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    doc.text(PDF_DATA.school, pageW / 2, 34, { align: 'center' });
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(11);
    doc.text('Student Progress Records', pageW / 2, 54, { align: 'center' });
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    doc.text('Aim Higher', pageW / 2, 68, { align: 'center' });

    // Filters + meta
    doc.setTextColor(60, 60, 60);
    doc.setFontSize(9);
    doc.text('Filters: ' + PDF_DATA.filters.join('  |  '), 40, 102, { maxWidth: pageW - 80 });
    doc.text('Generated: ' + PDF_DATA.generated + '    Records: ' + PDF_DATA.count, 40, 118);

    // Records table
    doc.autoTable({
      startY: 130,
      margin: { left: 40, right: 40, bottom: 40 },
      head: [['#', 'Adm No.', 'Learner', 'Class', 'Term', 'Exam', 'Year', 'Subject', 'Score', 'Level']],
      body: PDF_DATA.rows.map((r, i) => [
        i + 1, r.admNo, (r.firstName + ' ' + r.lastName).trim(),
        'Grade ' + r.classGrade, 'Term ' + r.term, r.examType, r.year,
        r.subject, r.score, r.letter
      ]),
      theme: 'striped',
      styles: { fontSize: 8, cellPadding: 4 },
      headStyles: { fillColor: NAVY, textColor: GOLD, fontStyle: 'bold' },
      alternateRowStyles: { fillColor: [248, 248, 246] },
      columnStyles: { 0: { halign: 'center', cellWidth: 24 }, 8: { halign: 'center' }, 9: { halign: 'center' } },
      didParseCell: (d) => {
        if (d.section === 'body' && d.column.index === 8) {
          const s = Number(d.cell.raw);
          d.cell.styles.fontStyle = 'bold';
          d.cell.styles.textColor = s >= 75 ? [22,163,74] : s >= 50 ? [37,99,235] : s >= 25 ? [217,119,6] : [220,38,38];
        }
      }
    });

    // Mean-mark summary
    let y = doc.lastAutoTable.finalY + 20;
    if (y > pageH - 150) { doc.addPage(); y = 50; }
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(11);
    doc.setTextColor(...NAVY);
    doc.text('Summary', 40, y);
    doc.autoTable({
      startY: y + 8,
      margin: { left: 40, right: 40, bottom: 40 },
      head: [['Subject', 'Mean Mark', 'Entries']],
      body: PDF_DATA.subjectMeans.map(s => [s[0], Number(s[1]).toFixed(2), s[2]]),
      foot: [['OVERALL MEAN MARK', Number(PDF_DATA.overall).toFixed(2), PDF_DATA.count]],
      theme: 'grid',
      styles: { fontSize: 9, cellPadding: 5 },
      headStyles: { fillColor: NAVY, textColor: GOLD },
      footStyles: { fillColor: [253, 246, 227], textColor: NAVY, fontStyle: 'bold' }
    });

    // Page numbers
    const pages = doc.internal.getNumberOfPages();
    for (let p = 1; p <= pages; p++) {
      doc.setPage(p);
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(8);
      doc.setTextColor(120, 120, 120);
      doc.text('Page ' + p + ' of ' + pages, pageW - 40, pageH - 20, { align: 'right' });
      doc.text(PDF_DATA.school, 40, pageH - 20);
    }

    const d = new Date();
    const stamp = d.getFullYear() + String(d.getMonth() + 1).padStart(2, '0') + String(d.getDate()).padStart(2, '0');
    doc.save('progress_records_' + stamp + '.pdf');
  }
</script>
</body>
</html>