<?php

// Hoi.php — Head of Institution dashboard
include 'conn.php';
require_once 'auth_helpers.php';
require_once __DIR__ . '/session_timeout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ── Access control ── */
if (
    empty($_SESSION['loggedin']) ||
    empty($_SESSION['role']) ||
    !in_array($_SESSION['role'], ['admin'], true) ||
    (($_SESSION['account_type'] ?? '') !== 'hoi')
) {
    // Not HOI → kick to login
    header('Location: index.php');
    exit;
}

/* Regenerate session ID periodically */
if (!isset($_SESSION['last_regenerated']) || time() - $_SESSION['last_regenerated'] > 1800) {
    session_regenerate_id(true);
    $_SESSION['last_regenerated'] = time();
}

require_once 'add-exam-type.php';
include 'subjects_config.php';

$EXAM_TYPES = getExamTypes($conn); // code => ['label' => ..., 'custom' => bool]

/* ══════════════════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════════════════ */
function hoi_term($t) {
    $d = preg_replace('/[^0-9]/', '', (string)$t);
    return $d !== '' ? (int)$d : 0;
}
function hoi_norm($s) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s));
}
function hoi_exam_label($raw, array $EXAM_TYPES) {
    if (isset($EXAM_TYPES[$raw])) return $EXAM_TYPES[$raw]['label'];
    $c = resolveExamCode((string)$raw, $EXAM_TYPES);
    if ($c !== null && isset($EXAM_TYPES[$c])) return $EXAM_TYPES[$c]['label'];
    return ucfirst((string)$raw);
}
/* Chronological order inside a term: built-ins first, then custom exams in dropdown order */
function hoi_exam_seq($raw, array $EXAM_TYPES) {
    static $seq = null;
    if ($seq === null) {
        $seq = ['opener', 'midterm', 'endterm'];
        foreach (array_keys($EXAM_TYPES) as $c) {
            $n = hoi_norm($c);
            if (!in_array($n, $seq, true)) $seq[] = $n;
        }
    }
    $i = array_search(hoi_norm($raw), $seq, true);
    return $i === false ? count($seq) : $i;
}
function hoi_lvl_class($code) {
    return strtolower(str_replace('.', '', (string)$code));
}
function hoi_tier_color($mean) {
    return $mean > 74 ? '#16a34a' : ($mean > 49 ? '#1a4a7a' : ($mean > 25 ? '#d97706' : '#991b1b'));
}
function hoi_tier_class($mean) {
    return $mean > 74 ? 'ee' : ($mean > 49 ? 'me' : ($mean > 25 ? 'ae' : 'be'));
}
function hoi_e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function termFinance($conn, $term) {
    $term = (int)$term;
    return mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT COALESCE(SUM(Fee),0) AS fee,
                COALESCE(SUM(Assesment),0) AS assess,
                COALESCE(SUM(Activity),0) AS activity,
                COALESCE(SUM(Other),0) AS other
         FROM Fees WHERE Term=$term"));
}
function hoi_term_card($term, $data, $label, $maxTotal) {
    $total = $data['fee'] + $data['assess'] + $data['activity'] + $data['other'];
    $share = $maxTotal > 0 ? round($total / $maxTotal * 100) : 0;
    $parts = [
        ['School Fee', $data['fee'], 'p1'],
        ['Assessment', $data['assess'], 'p2'],
        ['Activity',   $data['activity'], 'p3'],
        ['Other',      $data['other'], 'p4'],
    ];
    $h  = "<div class='finance-card'>";
    $h .= "<div class='finance-card-head'><span class='fc-title'>" . hoi_e($label) . "</span><span class='term-badge'>Term $term</span></div>";
    $h .= "<div class='fc-total'><small>Total collected</small><strong>KES " . number_format($total) . "</strong></div>";
    $h .= "<div class='stack' title='Share of all terms'>";
    foreach ($parts as $p) {
        $w = $total > 0 ? round($p[1] / $total * 100, 1) : 0;
        $h .= "<span class='{$p[2]}' style='width:{$w}%'></span>";
    }
    $h .= "</div>";
    foreach ($parts as $p) {
        $h .= "<div class='fin-row'><span class='lbl'><i class='dot {$p[2]}'></i>{$p[0]}</span><span class='val'>" . number_format((float)$p[1], 2) . "</span></div>";
    }
    $h .= "<div class='fin-row total-row'><span class='lbl'>{$share}% of year's revenue</span><span class='val'>KES " . number_format($total, 2) . "</span></div>";
    $h .= "</div>";
    return $h;
}

date_default_timezone_set('Africa/Nairobi');
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$todayStr = date('l, j F Y');

$fGrade = (isset($_GET['grade']) && ctype_digit((string)$_GET['grade']) && (int)$_GET['grade'] >= 1 && (int)$_GET['grade'] <= 9) ? (int)$_GET['grade'] : 0;
$fTerm  = (isset($_GET['term']) && in_array((string)$_GET['term'], ['1', '2', '3'], true)) ? (int)$_GET['term'] : 0;
$fYear  = (isset($_GET['year']) && ctype_digit((string)$_GET['year'])) ? (int)$_GET['year'] : 0;
$fExam  = '';
if (isset($_GET['exam_type']) && $_GET['exam_type'] !== '') {
    $fExam = resolveExamCode((string)$_GET['exam_type'], $EXAM_TYPES) ?? '';
}
$hasFilter  = ($fGrade || $fTerm || $fYear || $fExam !== '');
$detailMode = ($fGrade && $fTerm && $fYear && $fExam !== '');

/* ══════════════════════════════════════════════════════════════
   KPIs + FINANCE
══════════════════════════════════════════════════════════════ */
$totalStudents = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM Student"))['c'] ?? 0);
$totalTeachers = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM Teachers"))['c'] ?? 0);

$t1 = termFinance($conn, 1);
$t2 = termFinance($conn, 2);
$t3 = termFinance($conn, 3);
$termTotals = [];
foreach ([$t1, $t2, $t3] as $tf) {
    $termTotals[] = (float)($tf['fee'] + $tf['assess'] + $tf['activity'] + $tf['other']);
}
$grandTotal = array_sum($termTotals);
$maxTermTotal = max($termTotals);
$sumFee    = (float)($t1['fee'] + $t2['fee'] + $t3['fee']);
$sumAssess = (float)($t1['assess'] + $t2['assess'] + $t3['assess']);
$sumAct    = (float)($t1['activity'] + $t2['activity'] + $t3['activity']);
$sumOther  = (float)($t1['other'] + $t2['other'] + $t3['other']);

$gradeFeeRes = mysqli_query($conn,
    "SELECT Grade,
            COALESCE(SUM(Fee),0) AS fee, COALESCE(SUM(Assesment),0) AS assess,
            COALESCE(SUM(Activity),0) AS activity, COALESCE(SUM(Other),0) AS other,
            COUNT(*) AS records
     FROM Fees GROUP BY Grade ORDER BY Grade+0");
$gradeData = [];
while ($row = mysqli_fetch_assoc($gradeFeeRes)) $gradeData[] = $row;

$gradeStudentRes = mysqli_query($conn, "SELECT Grade, COUNT(*) AS cnt FROM Student GROUP BY Grade ORDER BY Grade+0");
$gradeStudents = [];
while ($r = mysqli_fetch_assoc($gradeStudentRes)) $gradeStudents[$r['Grade']] = (int)$r['cnt'];

$gradeFeePaidRes = mysqli_query($conn,
    "SELECT Grade, COUNT(DISTINCT Assesment) AS paid_students,
            COALESCE(SUM(Fee+Assesment+Activity+Other),0) AS total_paid
     FROM Fees GROUP BY Grade ORDER BY Grade+0");
$gradeFeePaid = [];
while ($r = mysqli_fetch_assoc($gradeFeePaidRes)) $gradeFeePaid[$r['Grade']] = $r;

$chartGrades = []; $chartFees = []; $chartAssess = []; $chartAct = []; $chartOther = [];
foreach ($gradeData as $gd) {
    $chartGrades[] = "G{$gd['Grade']}";
    $chartFees[]   = (float)$gd['fee'];
    $chartAssess[] = (float)$gd['assess'];
    $chartAct[]    = (float)$gd['activity'];
    $chartOther[]  = (float)$gd['other'];
}
$covData = []; $debtData = []; $enrolData = [];
$enrolledTotal = 0; $paidTotal = 0;
for ($g = 1; $g <= 9; $g++) {
    $enrolled = $gradeStudents[$g] ?? 0;
    $paid     = $gradeFeePaid[$g]['paid_students'] ?? 0;
    $covData[]   = $enrolled > 0 ? round($paid / $enrolled * 100) : 0;
    $debtData[]  = max(0, $enrolled - $paid);
    $enrolData[] = $enrolled;
    $enrolledTotal += $enrolled;
    $paidTotal     += min($enrolled, (int)$paid);
}
$overallCoverage = $enrolledTotal > 0 ? round($paidTotal / $enrolledTotal * 100) : 0;
$ratio = $totalTeachers > 0 ? round($totalStudents / $totalTeachers, 1) : 0;

/* ══════════════════════════════════════════════════════════════
   RESULTS: same subjects / bands / exam types as View Results
══════════════════════════════════════════════════════════════ */
$sessions     = [];   // key => session stats
$seen         = [];
$subjCache    = [];
$detailRows   = [];   // students for the Results Explorer detail panel
$allYears     = [];

$resAll = mysqli_query($conn, "SELECT * FROM exam2");
while ($resAll && ($r = mysqli_fetch_assoc($resAll))) {
    $g = (int)($r['grade'] ?? 0);
    if ($g < 1 || $g > 9) continue;
    $t = hoi_term($r['term'] ?? '');
    $y = (int)($r['year'] ?? 0);
    $e = (string)($r['exam_type'] ?? '');
    if ($t === 0 || $y === 0 || $e === '') continue;
    $ec = resolveExamCode($e, $EXAM_TYPES) ?? $e;

    $sid = (string)($r['student_id'] ?? ($r['Assesment'] ?? ''));
    $key = "$g|$t|$ec|$y";
    if ($sid !== '') {
        $dk = $key . '|' . $sid;
        if (isset($seen[$dk])) continue;
        $seen[$dk] = true;
    }

    if (!isset($subjCache[$g])) $subjCache[$g] = getSubjectsForGrade($g); // code => label
    $subCodes = array_keys($subjCache[$g]);
    $sc = count($subCodes);
    if ($sc === 0) continue;

    if (!isset($sessions[$key])) {
        $codesInit = ($g <= 6) ? allBandCodesForGrade($g) : allBandCodes();
        $sessions[$key] = [
            'grade' => $g, 'term' => $t, 'exam' => $ec, 'year' => $y,
            'n' => 0, 'total' => 0, 'sc' => $sc,
            'subj'  => array_fill_keys($subCodes, 0),
            'codes' => array_fill_keys($codesInit, 0),
            'tiers' => ['ee' => 0, 'me' => 0, 'ae' => 0, 'be' => 0],
        ];
    }
    $allYears[$y] = true;

    $total = 0;
    foreach ($subCodes as $s) {
        $v = (int)($r[$s] ?? 0);
        $sessions[$key]['subj'][$s] += $v;
        $total += $v;
    }
    $avg = $total / $sc;
    $sessions[$key]['n']++;
    $sessions[$key]['total'] += $total;
    $tier = bandInfo($avg)['tier'];
    $code = bandInfoForGrade($avg, $g)['code'];
    $sessions[$key]['tiers'][$tier]++;
    if (isset($sessions[$key]['codes'][$code])) $sessions[$key]['codes'][$code]++;

    if ($detailMode && $g === $fGrade && $t === $fTerm && $y === $fYear && $ec === $fExam) {
        $detailRows[] = [
            'name'  => trim(($r['firstName'] ?? '') . ' ' . ($r['lastName'] ?? ($r['surname'] ?? ''))),
            'ass'   => $r['Assesment'] ?? '',
            'total' => $total,
            'avg'   => round($avg, 1),
            'g'     => $g,
        ];
    }
}
krsort($allYears);
$yearOptions = array_keys($allYears);

/* Finish per-session stats + sort chronologically */
foreach ($sessions as $k => &$s) {
    $s['mean']      = $s['n'] ? round($s['total'] / $s['n'] / $s['sc'], 1) : 0;
    $s['total_avg'] = $s['n'] ? round($s['total'] / $s['n'], 1) : 0;
    $s['label']     = "T{$s['term']} " . hoi_exam_label($s['exam'], $EXAM_TYPES) . " {$s['year']}";
    $s['level']     = bandInfoForGrade($s['mean'], $s['grade'])['code'];
}
unset($s);
uasort($sessions, function ($a, $b) use ($EXAM_TYPES) {
    if ($a['year'] !== $b['year']) return $a['year'] <=> $b['year'];
    if ($a['term'] !== $b['term']) return $a['term'] <=> $b['term'];
    $ea = hoi_exam_seq($a['exam'], $EXAM_TYPES); $eb = hoi_exam_seq($b['exam'], $EXAM_TYPES);
    if ($ea !== $eb) return $ea <=> $eb;
    return $a['grade'] <=> $b['grade'];
});

/* School-wide latest mean (overview KPI, ignores explorer filters) */
$overallLatest = [];
foreach ($sessions as $s) $overallLatest[$s['grade']] = $s;
$schoolMean = count($overallLatest)
    ? round(array_sum(array_column($overallLatest, 'mean')) / count($overallLatest), 1)
    : 0;

/* Apply Results Explorer filters */
$viewSessions = [];
foreach ($sessions as $k => $s) {
    if ($fGrade && $s['grade'] !== $fGrade) continue;
    if ($fTerm  && $s['term']  !== $fTerm)  continue;
    if ($fYear  && $s['year']  !== $fYear)  continue;
    if ($fExam !== '' && $s['exam'] !== $fExam) continue;
    $viewSessions[$k] = $s;
}

/* Latest matching exam per grade, labels, band totals */
$latestGradePerf = [];
$bandCounts = ['ee' => 0, 'me' => 0, 'ae' => 0, 'be' => 0];
$allLabels = [];
$perfIdx = [];
$perfSess = [];
foreach ($viewSessions as $s) {
    $latestGradePerf[$s['grade']] = $s;
    foreach ($s['tiers'] as $tk => $tv) $bandCounts[$tk] += $tv;
    if (!in_array($s['label'], $allLabels, true)) $allLabels[] = $s['label'];
    $perfIdx[$s['grade'] . '|' . $s['label']] = $s['mean'];
    $perfSess[$s['grade'] . '|' . $s['label']] = $s;
}
$gradePerfMeans = [];
for ($g = 1; $g <= 9; $g++) $gradePerfMeans[] = isset($latestGradePerf[$g]) ? $latestGradePerf[$g]['mean'] : 0;

$gradeColors = ['#0f1923', '#16a34a', '#d97706', '#991b1b', '#1a4a7a', '#7c3aed', '#c8992a', '#0d9488', '#db2777'];
$gradeDatasets = [];
for ($g = 1; $g <= 9; $g++) {
    $pts = [];
    foreach ($allLabels as $lbl) $pts[] = $perfIdx["$g|$lbl"] ?? null;
    if (array_filter($pts, fn($v) => $v !== null)) {
        $gradeDatasets[] = [
            'label' => "Grade $g", 'data' => $pts, 'borderColor' => $gradeColors[$g - 1],
            'backgroundColor' => 'transparent', 'borderWidth' => 2, 'pointRadius' => 3,
            'tension' => 0.35, 'spanGaps' => true,
        ];
    }
}
$sessionCountAll = count($sessions);

/* Detail panel data */
$detail = null;
if ($detailMode) {
    foreach ($viewSessions as $s) { $detail = $s; break; }
    if ($detail && $detailRows) {
        usort($detailRows, fn($a, $b) => $b['total'] <=> $a['total']);
        $rank = 0; $prevT = null;
        foreach ($detailRows as $i => &$dr) {
            if ($dr['total'] !== $prevT) { $rank = $i + 1; $prevT = $dr['total']; }
            $dr['rank'] = $rank;
        }
        unset($dr);
    }
}

$teachersRes  = mysqli_query($conn, "SELECT * FROM Teachers");
$avatarColors = ['#0f1923', '#16a34a', '#c8992a', '#1a4a7a', '#7c3aed', '#991b1b', '#0d9488'];
$examLabelFilter = $fExam !== '' ? hoi_exam_label($fExam, $EXAM_TYPES) : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Head of Institution — Dashboard · Stephen Kanja</title>
  <script>
    /* Apply saved theme before first paint to avoid a flash */
    try { var th = localStorage.getItem('hoi-theme'); if (th === 'dark' || th === 'light') document.documentElement.setAttribute('data-theme', th); } catch (e) {}
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:#f3f5fa; --surface:#ffffff; --surface-2:#f8f9fc; --surface-3:#eef1f7;
      --text:#0f172a; --text-2:#475569; --muted:#8392a5;
      --border:#e4e8f0; --border-2:#eef1f6; --grid:rgba(15,23,42,.06);
      --navy:#0b1f3a; --navy-2:#102a4c; --gold:#f0c040; --gold-d:#c8992a; --gold-pale:#fdf3d4; --gold-ink:#7a5a10;
      --blue:#1a4a7a; --blue-pale:#dce8f5;
      --ee:#16a34a; --ee-bg:#dcfce7; --me:#1a4a7a; --me-bg:#dce8f5;
      --ae:#b45309; --ae-bg:#fef3c7; --be:#991b1b; --be-bg:#fee2e2;
      --ee2:#15803d; --ee2-bg:#bbf7d0; --me2:#123657; --me2-bg:#cfe0f3;
      --ae2:#92400e; --ae2-bg:#fde68a; --be2:#7f1d1d; --be2-bg:#fecaca;
      --p1:#0b1f3a; --p2:#f0c040; --p3:#2563eb; --p4:#16a34a;
      --shadow-sm:0 1px 2px rgba(15,23,42,.05);
      --shadow:0 6px 24px rgba(15,23,42,.07);
      --shadow-lg:0 18px 50px rgba(15,23,42,.14);
      --r-sm:8px; --r:14px; --r-lg:18px;
      --sidebar-w:264px; --topbar-h:64px;
      color-scheme: light;
    }
    [data-theme="dark"] {
      --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
      --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3;
      --border:#1f2d47; --border-2:#192740; --grid:rgba(255,255,255,.07);
      --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040; --blue-pale:rgba(96,165,250,.14); --blue:#8ab8ee;
      --ee:#4ade80; --ee-bg:rgba(34,197,94,.16); --me:#7db3f0; --me-bg:rgba(96,165,250,.16);
      --ae:#fbbf24; --ae-bg:rgba(251,191,36,.15); --be:#f87171; --be-bg:rgba(248,113,113,.16);
      --ee2:#86efac; --ee2-bg:rgba(34,197,94,.26); --me2:#a9cdf6; --me2-bg:rgba(96,165,250,.26);
      --ae2:#fcd34d; --ae2-bg:rgba(251,191,36,.26); --be2:#fca5a5; --be2-bg:rgba(248,113,113,.26);
      --p1:#5b7fb5;
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
    .icon-btn { width:38px; height:38px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:15px; transition:.15s; }
    .icon-btn:hover { background:var(--surface-3); color:var(--text); }
    .btn-gold { display:inline-flex; align-items:center; gap:8px; background:var(--gold); color:#101826; font-weight:700; font-size:.82rem; padding:0 16px; height:38px; border-radius:10px; text-decoration:none; border:none; cursor:pointer; box-shadow:0 6px 16px rgba(240,192,64,.3); transition:.15s; font-family:inherit; }
    .btn-gold:hover { background:#f5cf5e; transform:translateY(-1px); }

    /* ───────── Hero ───────── */
    .hero { margin:24px 32px 0; padding:28px 30px; border-radius:var(--r-lg); color:#fff; position:relative; overflow:hidden; background:linear-gradient(120deg,#0b1f3a 0%,#173a66 60%,#1c4a80 100%); box-shadow:var(--shadow); }
    .hero::before { content:''; position:absolute; inset:0; background:radial-gradient(520px 260px at 6% 0%,rgba(240,192,64,.28),transparent 70%), radial-gradient(420px 260px at 100% 100%,rgba(34,197,94,.22),transparent 70%); pointer-events:none; }
    .hero-inner { position:relative; display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; }
    .hero-eyebrow { font-size:.72rem; letter-spacing:2px; text-transform:uppercase; color:rgba(255,255,255,.6); font-weight:700; }
    .hero h1 { font-size:clamp(1.3rem,2.4vw,1.85rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; margin-top:4px; }
    .hero h1 em { font-style:normal; color:var(--gold); }
    .hero p { color:rgba(255,255,255,.72); font-size:.86rem; margin-top:6px; }
    .hero-meta { display:flex; gap:10px; flex-wrap:wrap; }
    .hero-pill { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.16); backdrop-filter:blur(4px); border-radius:12px; padding:10px 16px; min-width:120px; }
    .hero-pill small { display:block; font-size:.64rem; letter-spacing:1.4px; text-transform:uppercase; color:rgba(255,255,255,.6); font-weight:700; }
    .hero-pill b { font-size:1.15rem; font-weight:800; }

    /* ───────── Jump nav ───────── */
    .jump { position:sticky; top:var(--topbar-h); z-index:50; margin:18px 32px 0; display:flex; gap:6px; padding:6px; background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow-sm); overflow-x:auto; }
    .jump a { display:inline-flex; align-items:center; gap:8px; padding:8px 16px; border-radius:10px; color:var(--text-2); font-weight:600; font-size:.82rem; text-decoration:none; white-space:nowrap; transition:.15s; }
    .jump a:hover { background:var(--surface-3); color:var(--text); }
    .jump a.on { background:var(--navy); color:#fff; }
    [data-theme="dark"] .jump a.on { background:var(--gold); color:#101826; }

    .content { padding:6px 32px 60px; width:100%; max-width:1480px; margin:0 auto; }
    section[id] { scroll-margin-top:calc(var(--topbar-h) + 76px); }

    .section-head { display:flex; align-items:flex-end; justify-content:space-between; gap:12px; margin:34px 0 16px; flex-wrap:wrap; }
    .section-head h2 { font-size:1.2rem; font-weight:800; letter-spacing:-.3px; display:flex; align-items:center; gap:10px; }
    .section-head h2 i { width:32px; height:32px; border-radius:10px; background:var(--gold-pale); color:var(--gold-d); display:inline-flex; align-items:center; justify-content:center; font-size:14px; }
    .section-head p { color:var(--muted); font-size:.8rem; margin-top:2px; }
    .badge { font-size:.68rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; background:var(--navy); color:var(--gold); padding:5px 12px; border-radius:99px; white-space:nowrap; }
    [data-theme="dark"] .badge { background:var(--surface-3); }

    /* ───────── KPI ───────── */
    .kpi-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; margin-bottom:6px; }
    .kpi { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:18px; box-shadow:var(--shadow-sm); position:relative; overflow:hidden; transition:transform .2s,box-shadow .2s; animation:fadeUp .45s ease both; }
    .kpi:hover { transform:translateY(-3px); box-shadow:var(--shadow); }
    .kpi::after { content:''; position:absolute; right:-28px; top:-28px; width:96px; height:96px; border-radius:50%; background:var(--accent,var(--gold)); opacity:.12; }
    .kpi .ico { width:38px; height:38px; border-radius:11px; background:color-mix(in srgb,var(--accent,var(--gold)) 16%,transparent); color:var(--accent,var(--gold-d)); display:flex; align-items:center; justify-content:center; font-size:15px; margin-bottom:12px; }
    .kpi-label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .kpi-val { font-size:1.9rem; font-weight:800; line-height:1.1; letter-spacing:-.8px; margin-top:4px; font-variant-numeric:tabular-nums; }
    .kpi-val.sm { font-size:1.35rem; }
    .kpi-val small { font-size:.9rem; font-weight:600; color:var(--muted); letter-spacing:0; }
    .kpi-sub { font-size:.74rem; color:var(--muted); margin-top:6px; }
    .kpi.gold{--accent:#d9a521} .kpi.green{--accent:#16a34a} .kpi.blue{--accent:#2563eb} .kpi.red{--accent:#dc2626} .kpi.violet{--accent:#7c3aed} .kpi.teal{--accent:#0d9488}

    /* ───────── Cards ───────── */
    .card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--shadow-sm); margin-bottom:18px; overflow:hidden; animation:fadeUp .45s .05s ease both; }
    .card-head { padding:15px 22px; border-bottom:1px solid var(--border-2); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .card-head h3 { font-size:.76rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); display:flex; align-items:center; gap:9px; }
    .card-head h3 i { color:var(--gold-d); }
    .card-body { padding:20px 22px; }
    .chart-wrap { position:relative; height:280px; }
    .chart-wrap.tall { height:340px; }
    .chart-wrap.short { height:240px; }
    .grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:18px; }
    .grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; margin-bottom:18px; }
    .grid-2 .card, .grid-3 .card { margin-bottom:0; }
    .grid-2-1 { display:grid; grid-template-columns:1.6fr 1fr; gap:18px; margin-bottom:18px; }
    .grid-2-1 .card { margin-bottom:0; }

    .tag { font-size:.66rem; font-weight:800; letter-spacing:.9px; text-transform:uppercase; padding:3px 10px; border-radius:99px; background:var(--gold-pale); color:var(--gold-ink); }
    .tag.live { background:var(--ee-bg); color:var(--ee); }
    .tag.live::before { content:''; display:inline-block; width:6px; height:6px; border-radius:50%; background:currentColor; margin-right:6px; animation:pulse 1.6s infinite; vertical-align:middle; }

    /* ───────── Filters ───────── */
    .filter-bar { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:18px 20px; box-shadow:var(--shadow-sm); margin-bottom:16px; display:grid; grid-template-columns:repeat(4,minmax(130px,1fr)) auto; gap:14px; align-items:end; }
    .fg { display:flex; flex-direction:column; gap:6px; }
    .fg label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .fg select, .search { appearance:none; width:100%; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; padding:10px 14px; font-family:inherit; font-size:.88rem; font-weight:500; color:var(--text); transition:.15s; height:42px; }
    .fg select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; padding-right:38px; cursor:pointer; }
    .fg select:hover, .search:hover { border-color:rgba(37,99,235,.35); }
    .fg select:focus, .search:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .btn-row { display:flex; gap:8px; }
    .btn { background:var(--navy); color:#fff; border:none; border-radius:10px; padding:0 20px; height:42px; font-family:inherit; font-size:.84rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; gap:8px; text-decoration:none; white-space:nowrap; }
    [data-theme="dark"] .btn { background:var(--gold); color:#101826; }
    .btn:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(11,31,58,.25); }
    .btn.ghost { background:transparent; color:var(--text-2); border:1.5px solid var(--border); }
    [data-theme="dark"] .btn.ghost { background:transparent; color:var(--text-2); }
    .btn.ghost:hover { background:var(--surface-3); box-shadow:none; }
    .btn.sm { height:34px; padding:0 14px; font-size:.78rem; border-radius:9px; }
    .chips { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; align-items:center; }
    .chip { font-size:.72rem; font-weight:700; padding:5px 12px; border-radius:99px; background:var(--navy); color:#fff; }
    [data-theme="dark"] .chip { background:var(--surface-3); color:var(--text); }
    .chip.gold { background:var(--gold-pale); color:var(--gold-ink); }

    /* ───────── Tables ───────── */
    .table-tools { display:flex; align-items:center; gap:10px; padding:12px 22px; border-bottom:1px solid var(--border-2); flex-wrap:wrap; }
    .table-tools .search { max-width:280px; height:36px; font-size:.82rem; }
    .table-tools .hint { margin-left:auto; font-size:.72rem; color:var(--muted); }
    .table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    table.data-table { width:100%; border-collapse:collapse; font-size:.83rem; min-width:620px; }
    table.data-table thead th { background:var(--surface-2); color:var(--muted); padding:11px 14px; text-align:left; font-size:.64rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; white-space:nowrap; border-bottom:1px solid var(--border); position:sticky; top:0; }
    table.data-table th[data-sort] { cursor:pointer; user-select:none; }
    table.data-table th[data-sort]:hover { color:var(--text); }
    table.data-table th[data-sort]::after { content:'\f0dc'; font-family:'Font Awesome 6 Free'; font-weight:900; margin-left:6px; opacity:.35; font-size:.62rem; }
    table.data-table th.asc::after { content:'\f0de'; opacity:1; color:var(--gold-d); }
    table.data-table th.desc::after { content:'\f0dd'; opacity:1; color:var(--gold-d); }
    table.data-table td { padding:11px 14px; border-bottom:1px solid var(--border-2); color:var(--text-2); vertical-align:middle; font-variant-numeric:tabular-nums; }
    table.data-table tbody tr { transition:background .12s; }
    table.data-table tbody tr:hover td { background:var(--surface-2); }
    table.data-table tbody tr:last-child td { border-bottom:none; }
    table.data-table td strong { color:var(--text); }
    table.data-table tfoot td { background:var(--surface-2); font-weight:800; color:var(--text); border-top:2px solid var(--border); }
    .num { font-weight:700; color:var(--text); }
    .empty-row { text-align:center; color:var(--muted); padding:36px !important; }
    .rowlink { color:var(--blue); font-weight:700; text-decoration:none; font-size:.76rem; white-space:nowrap; }
    .rowlink:hover { text-decoration:underline; }
    .hidden-row { display:none; }

    .rank-badge { display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; font-weight:800; font-size:.74rem; background:var(--blue-pale); color:var(--blue); }
    .rank-badge.r1 { background:linear-gradient(135deg,#fde68a,#f0c040); color:#6b4a00; }
    .rank-badge.r2 { background:linear-gradient(135deg,#e5e7ee,#c7ccd9); color:#3d4560; }
    .rank-badge.r3 { background:linear-gradient(135deg,#f3d9bd,#dfa972); color:#6b3b14; }

    .lvl { display:inline-block; font-size:.62rem; font-weight:800; padding:2px 8px; border-radius:6px; letter-spacing:.4px; vertical-align:middle; }
    .lvl.ee,.lvl.ee1 { background:var(--ee-bg); color:var(--ee); }
    .lvl.ee2 { background:var(--ee2-bg); color:var(--ee2); }
    .lvl.me,.lvl.me1 { background:var(--me-bg); color:var(--me); }
    .lvl.me2 { background:var(--me2-bg); color:var(--me2); }
    .lvl.ae,.lvl.ae1 { background:var(--ae-bg); color:var(--ae); }
    .lvl.ae2 { background:var(--ae2-bg); color:var(--ae2); }
    .lvl.be,.lvl.be1 { background:var(--be-bg); color:var(--be); }
    .lvl.be2 { background:var(--be2-bg); color:var(--be2); }

    .pill { display:inline-flex; align-items:center; gap:6px; font-size:.7rem; font-weight:700; padding:3px 10px; border-radius:99px; }
    .pill::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
    .pill.success { background:var(--ee-bg); color:var(--ee); }
    .pill.warn { background:var(--ae-bg); color:var(--ae); }
    .pill.danger { background:var(--be-bg); color:var(--be); }

    .meter { display:inline-block; width:84px; height:7px; border-radius:99px; background:var(--surface-3); vertical-align:middle; margin-left:8px; overflow:hidden; }
    .meter i { display:block; height:100%; border-radius:99px; }
    .meter .g { background:var(--ee); } .meter .a { background:#d97706; } .meter .r { background:var(--be); }
    .meter .ee{background:var(--ee)} .meter .me{background:#2563eb} .meter .ae{background:#d97706} .meter .be{background:#dc2626}

    /* ───────── Heatmap ───────── */
    .heat { border-collapse:separate; border-spacing:4px; font-size:.78rem; min-width:100%; }
    .heat th { font-size:.64rem; font-weight:800; letter-spacing:.6px; color:var(--muted); text-transform:uppercase; padding:6px 8px; white-space:nowrap; text-align:center; }
    .heat th.rowh { text-align:left; position:sticky; left:0; background:var(--surface); z-index:2; color:var(--text-2); font-size:.76rem; letter-spacing:0; text-transform:none; font-weight:700; padding-right:14px; }
    .heat td { text-align:center; padding:0; }
    .heat td a, .heat td span { display:block; min-width:78px; padding:9px 6px; border-radius:9px; font-weight:800; font-variant-numeric:tabular-nums; text-decoration:none; transition:transform .12s,box-shadow .12s; }
    .heat td a:hover { transform:scale(1.07); box-shadow:var(--shadow); }
    .heat .t-ee { background:var(--ee-bg); color:var(--ee); }
    .heat .t-me { background:var(--me-bg); color:var(--me); }
    .heat .t-ae { background:var(--ae-bg); color:var(--ae); }
    .heat .t-be { background:var(--be-bg); color:var(--be); }
    .heat .t-na { background:var(--surface-2); color:var(--muted); font-weight:500; }
    .legend { display:flex; gap:14px; flex-wrap:wrap; font-size:.72rem; color:var(--muted); }
    .legend i { display:inline-block; width:10px; height:10px; border-radius:3px; margin-right:6px; vertical-align:middle; }

    /* ───────── Detail panel ───────── */
    .detail-banner { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; padding:16px 22px; border-radius:var(--r); background:linear-gradient(120deg,var(--gold-pale),transparent); border:1px solid var(--border); margin-bottom:14px; }
    .detail-banner h3 { font-size:1.02rem; font-weight:800; letter-spacing:-.2px; }
    .detail-banner small { color:var(--muted); font-weight:500; }
    .band-row { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
    .band-label { font-size:.76rem; font-weight:600; width:190px; flex-shrink:0; color:var(--text-2); }
    .band-bar-wrap { flex:1; background:var(--surface-3); border-radius:99px; height:10px; overflow:hidden; }
    .band-bar { height:100%; border-radius:99px; transition:width 1s cubic-bezier(.4,0,.2,1); }
    .band-count { font-size:.76rem; font-weight:700; width:84px; text-align:right; color:var(--text-2); flex-shrink:0; }
    .podium { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; padding:20px 22px 6px; }
    .pod { border:1px solid var(--border); border-radius:var(--r); padding:16px; text-align:center; background:var(--surface-2); position:relative; }
    .pod.p1 { background:linear-gradient(180deg,var(--gold-pale),var(--surface-2)); border-color:rgba(240,192,64,.5); }
    .pod .medal { width:40px; height:40px; border-radius:50%; margin:0 auto 8px; display:flex; align-items:center; justify-content:center; font-weight:800; }
    .pod.p1 .medal { background:linear-gradient(135deg,#fde68a,#f0c040); color:#6b4a00; }
    .pod.p2 .medal { background:linear-gradient(135deg,#e5e7ee,#c7ccd9); color:#3d4560; }
    .pod.p3 .medal { background:linear-gradient(135deg,#f3d9bd,#dfa972); color:#6b3b14; }
    .pod b { display:block; color:var(--text); font-size:.9rem; line-height:1.3; }
    .pod small { color:var(--muted); font-size:.72rem; }
    .pod .score { margin-top:8px; font-size:1.3rem; font-weight:800; color:var(--text); }
    .pod .score small { font-size:.72rem; font-weight:600; }

    .scale-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .scale-note { font-size:.74rem; color:var(--muted); margin-top:12px; }
    details.fold > summary { list-style:none; cursor:pointer; }
    details.fold > summary::-webkit-details-marker { display:none; }
    details.fold > summary .chev { transition:transform .2s; color:var(--muted); }
    details.fold[open] > summary .chev { transform:rotate(180deg); }

    /* ───────── Finance ───────── */
    .finance-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:14px; margin-bottom:18px; }
    .finance-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:20px 22px; box-shadow:var(--shadow-sm); transition:.2s; animation:fadeUp .45s ease both; }
    .finance-card:hover { transform:translateY(-3px); box-shadow:var(--shadow); }
    .finance-card-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .fc-title { font-weight:800; font-size:.95rem; }
    .term-badge { font-size:.62rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; padding:3px 10px; border-radius:99px; background:var(--navy); color:var(--gold); }
    [data-theme="dark"] .term-badge { background:var(--surface-3); }
    .fc-total small { display:block; font-size:.66rem; letter-spacing:1.2px; text-transform:uppercase; font-weight:700; color:var(--muted); }
    .fc-total strong { font-size:1.55rem; font-weight:800; letter-spacing:-.6px; font-variant-numeric:tabular-nums; }
    .stack { display:flex; height:8px; border-radius:99px; overflow:hidden; background:var(--surface-3); margin:12px 0 10px; }
    .stack span { display:block; height:100%; }
    .p1{background:var(--p1)} .p2{background:var(--p2)} .p3{background:var(--p3)} .p4{background:var(--p4)}
    .dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:8px; }
    .fin-row { display:flex; justify-content:space-between; padding:6px 0; font-size:.82rem; }
    .fin-row .lbl { color:var(--muted); font-weight:500; display:flex; align-items:center; }
    .fin-row .val { font-weight:600; font-variant-numeric:tabular-nums; color:var(--text); }
    .fin-row.total-row { border-top:1px dashed var(--border); margin-top:6px; padding-top:10px; }
    .fin-row.total-row .lbl { font-size:.72rem; font-weight:700; }
    .fin-row.total-row .val { color:var(--ee); font-weight:800; }

    /* ───────── Staff ───────── */
    .avatar { display:inline-flex; align-items:center; justify-content:center; width:34px; height:34px; border-radius:10px; font-size:.78rem; font-weight:800; margin-right:10px; flex-shrink:0; }
    .staff-name { display:flex; align-items:center; }
    code.tsc { font-family:ui-monospace,Menlo,monospace; font-size:.74rem; background:var(--surface-3); padding:2px 8px; border-radius:6px; color:var(--text-2); }

    .footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (max-width:1180px) { .grid-3 { grid-template-columns:1fr 1fr; } .grid-2-1 { grid-template-columns:1fr; } .filter-bar { grid-template-columns:repeat(2,1fr); } .btn-row { grid-column:1/-1; } }
    @media (max-width:1024px) {
      .app { grid-template-columns:1fr; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); }
      .sidebar.open { left:0; }
      .burger { display:inline-flex; align-items:center; justify-content:center; }
      .topbar { padding:0 18px; } .hero { margin:18px 18px 0; } .jump { margin:14px 18px 0; } .content { padding:6px 18px 50px; }
    }
    @media (max-width:900px) { .grid-2, .scale-grid, .grid-3 { grid-template-columns:1fr; } .podium { grid-template-columns:1fr; } .band-label { width:130px; } }
    @media (max-width:640px) { .btn-gold span, .btn.hide-sm span { display:none; } .hero { padding:22px 18px; } .hero-pill { min-width:0; flex:1; } .filter-bar { padding:16px; } .tb-title small { display:none; } }
    @media (max-width:420px) { .filter-bar { grid-template-columns:1fr; } }

    @media print {
      .sidebar, .topbar, .jump, .filter-bar, .btn-row, .backdrop, .table-tools, .icon-btn, .btn-gold { display:none !important; }
      .app { display:block; } .hero { margin:0 0 12px; color:#000; background:#fff; box-shadow:none; border:1px solid #ccc; } .hero p, .hero-eyebrow, .hero-pill small { color:#333; }
      .card, .kpi, .finance-card { box-shadow:none; break-inside:avoid; } body { background:#fff; } .content { padding:0; }
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
        <div class="nav-title">Overview</div>
        <a href="Hoi.php" class="active" aria-current="page"><i class="fa-solid fa-house-chimney"></i> Dashboard</a>
        <a href="Students.php"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="Progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Administration</div>
        <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
        <a href="attendance.php"><i class="fa-solid fa-calendar-check"></i> Attendance</a>
        <a href="RegisterTeacher.php"><i class="fa-solid fa-chalkboard-user"></i> Register Teachers</a>
        <a href="notice.php"><i class="fa-solid fa-bell"></i> Notices</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="Timetable.php"><i class="fa-solid fa-table-cells"></i> Timetable</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="LearningMaterials.php"><i class="fa-solid fa-book-open"></i> Learning Materials</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
        <a href="settings.php"><i class="fa-solid fa-gear"></i> Settings</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Finance</div>
        <a href="Fee.php"><i class="fa-solid fa-coins"></i> Finances (Pay)</a>
        <a href="viewFees.php"><i class="fa-solid fa-receipt"></i> Track Fees</a>
        <a href="receipt.php"><i class="fa-solid fa-file-invoice"></i> Generate Receipts</a>
      </div>
    </nav>

    <div class="side-user">
      <div class="av">HOI</div>
      <div><b>Head of Institution</b><small>Administrator</small></div>
      <a href="index.php" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>
  <div class="backdrop" id="backdrop" onclick="toggleSidebar()"></div>

  <!-- ═══════ MAIN ═══════ -->
  <div class="main">

    <header class="topbar">
      <div class="tb-left">
        <button class="burger" onclick="toggleSidebar()" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
        <div class="tb-title">Head of Institution Dashboard<small><?= hoi_e($todayStr) ?></small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <button class="icon-btn" type="button" onclick="window.print()" aria-label="Print dashboard" title="Print"><i class="fa-solid fa-print"></i></button>
        <a href="UploadResults.php" class="btn-gold"><i class="fa-solid fa-upload"></i><span>Upload Results</span></a>
      </div>
    </header>

    <main id="main-content">

      <section class="hero" aria-label="Welcome">
        <div class="hero-inner">
          <div>
            <div class="hero-eyebrow">Institutional Performance Overview</div>
            <h1><?= hoi_e($greeting) ?>, <em>Head of Institution</em></h1>
            <p>Stephen Kanja Primary &amp; Junior School · school-wide academics, grading and finances at a glance.</p>
          </div>
          <div class="hero-meta">
            <div class="hero-pill"><small>Learners</small><b><?= number_format($totalStudents) ?></b></div>
            <div class="hero-pill"><small>School Mean</small><b><?= $schoolMean ?><span style="font-size:.7rem;font-weight:600;opacity:.7"> /100</span></b></div>
            <div class="hero-pill"><small>Fee Coverage</small><b><?= $overallCoverage ?>%</b></div>
          </div>
        </div>
      </section>

      <nav class="jump" id="jump" aria-label="Dashboard sections">
        <a href="#sec-overview" class="on"><i class="fa-solid fa-gauge-high"></i> Overview</a>
        <a href="#results"><i class="fa-solid fa-chart-column"></i> Results</a>
        <a href="#finance"><i class="fa-solid fa-coins"></i> Finances</a>
        <a href="#staff"><i class="fa-solid fa-chalkboard-user"></i> Staff</a>
      </nav>

      <div class="content">

        <!-- ══════════ 1. OVERVIEW ══════════ -->
        <section id="sec-overview">
          <div class="section-head">
            <div><h2><i class="fa-solid fa-gauge-high"></i> School Overview</h2><p>Key numbers across the whole institution</p></div>
          </div>
          <div class="kpi-strip">
            <div class="kpi gold" style="animation-delay:.03s">
              <div class="ico"><i class="fa-solid fa-user-graduate"></i></div>
              <div class="kpi-label">Total Learners</div>
              <div class="kpi-val"><?= number_format($totalStudents) ?></div>
              <div class="kpi-sub">Active enrolled learners</div>
            </div>
            <div class="kpi blue" style="animation-delay:.06s">
              <div class="ico"><i class="fa-solid fa-chalkboard-user"></i></div>
              <div class="kpi-label">Teaching Staff</div>
              <div class="kpi-val"><?= number_format($totalTeachers) ?></div>
              <div class="kpi-sub"><?= $ratio ? "1 teacher per $ratio learners" : 'Registered teachers' ?></div>
            </div>
            <div class="kpi violet" style="animation-delay:.09s">
              <div class="ico"><i class="fa-solid fa-door-open"></i></div>
              <div class="kpi-label">Classes</div>
              <div class="kpi-val">9</div>
              <div class="kpi-sub">Grades 1 through 9</div>
            </div>
            <div class="kpi teal" style="animation-delay:.12s">
              <div class="ico"><i class="fa-solid fa-file-circle-check"></i></div>
              <div class="kpi-label">Exam Sessions</div>
              <div class="kpi-val"><?= number_format($sessionCountAll) ?></div>
              <div class="kpi-sub">All grades, terms &amp; years</div>
            </div>
            <div class="kpi green" style="animation-delay:.15s">
              <div class="ico"><i class="fa-solid fa-bullseye"></i></div>
              <div class="kpi-label">School Mean (Latest)</div>
              <div class="kpi-val" style="color:<?= hoi_tier_color($schoolMean) ?>"><?= $schoolMean ?><small> /100</small></div>
              <div class="kpi-sub">Average of each grade's latest exam</div>
            </div>
            <div class="kpi gold" style="animation-delay:.18s">
              <div class="ico"><i class="fa-solid fa-coins"></i></div>
              <div class="kpi-label">Total Revenue</div>
              <div class="kpi-val sm">KES <?= number_format($grandTotal) ?></div>
              <div class="kpi-sub">All terms combined</div>
            </div>
          </div>

          <div class="grid-3" style="margin-top:16px">
            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-people-roof"></i> Enrolment by Grade</h3></div>
              <div class="card-body"><div class="chart-wrap short"><canvas id="enrolChart"></canvas></div></div>
            </div>
            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-calendar-days"></i> Revenue by Term</h3></div>
              <div class="card-body"><div class="chart-wrap short"><canvas id="termRevChart"></canvas></div></div>
            </div>
            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-chart-pie"></i> Revenue Composition</h3></div>
              <div class="card-body"><div class="chart-wrap short"><canvas id="revMixChart"></canvas></div></div>
            </div>
          </div>
        </section>

        <!-- ══════════ 2. RESULTS EXPLORER ══════════ -->
        <section id="results">
          <div class="section-head">
            <div><h2><i class="fa-solid fa-chart-column"></i> Academic Results Explorer</h2><p>Filter by grade, term, exam and year. Pick all four to open a full class breakdown.</p></div>
            <span class="badge"><?= count($viewSessions) ?> exam sessions</span>
          </div>

          <form method="GET" action="#results" class="filter-bar" id="filterForm">
            <div class="fg">
              <label for="f-grade">Grade</label>
              <select name="grade" id="f-grade">
                <option value="">All Grades</option>
                <?php for ($g = 1; $g <= 9; $g++): ?>
                  <option value="<?= $g ?>" <?= $fGrade === $g ? 'selected' : '' ?>>Grade <?= $g ?></option>
                <?php endfor; ?>
              </select>
            </div>
            <div class="fg">
              <label for="f-term">Term</label>
              <select name="term" id="f-term">
                <option value="">All Terms</option>
                <?php foreach ([1, 2, 3] as $tt): ?>
                  <option value="<?= $tt ?>" <?= $fTerm === $tt ? 'selected' : '' ?>>Term <?= $tt ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fg">
              <label for="f-exam">Exam Type</label>
              <select name="exam_type" id="f-exam">
                <option value="">All Exams</option>
                <?php foreach ($EXAM_TYPES as $code => $et): ?>
                  <option value="<?= hoi_e($code) ?>" <?= $fExam === $code ? 'selected' : '' ?>><?= hoi_e($et['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fg">
              <label for="f-year">Year</label>
              <select name="year" id="f-year">
                <option value="">All Years</option>
                <?php foreach ($yearOptions as $y): ?>
                  <option value="<?= (int)$y ?>" <?= $fYear === (int)$y ? 'selected' : '' ?>><?= (int)$y ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="btn-row">
              <button type="submit" class="btn"><i class="fa-solid fa-filter"></i> Apply</button>
              <?php if ($hasFilter): ?><a href="Hoi.php#results" class="btn ghost"><i class="fa-solid fa-rotate-left"></i> Reset</a><?php endif; ?>
            </div>
          </form>

          <?php if ($hasFilter): ?>
          <div class="chips">
            <span class="chip"><?= $fGrade ? "Grade $fGrade" : 'All Grades' ?></span>
            <span class="chip"><?= $fTerm ? "Term $fTerm" : 'All Terms' ?></span>
            <span class="chip"><?= $examLabelFilter !== '' ? hoi_e($examLabelFilter) : 'All Exams' ?></span>
            <span class="chip"><?= $fYear ? $fYear : 'All Years' ?></span>
            <?php if (!$detailMode): ?><span class="chip gold"><i class="fa-regular fa-lightbulb"></i> Select all four filters for a class breakdown</span><?php endif; ?>
          </div>
          <?php endif; ?>

          <?php if ($detailMode && $detail && $detailRows): ?>
            <?php
              $dN = $detail['n']; $dSc = $detail['sc']; $dG = $detail['grade'];
              $dSubjLabels = array_values($subjCache[$dG]);
              $dSubjCodes  = array_keys($subjCache[$dG]);
              $dSubjMeans  = [];
              foreach ($dSubjCodes as $sc) $dSubjMeans[] = round($detail['subj'][$sc] / $dN, 1);
              $dCodes = ($dG <= 6) ? allBandCodesForGrade($dG) : allBandCodes();
              $dTopCount = $detail['tiers']['ee']; $dBelowCount = $detail['tiers']['be'];
              $dMeetCount = $detail['tiers']['ee'] + $detail['tiers']['me'];
              $dTopP = $dN ? round($dTopCount / $dN * 100) : 0;
              $dBelowP = $dN ? round($dBelowCount / $dN * 100) : 0;
              $dMeetP = $dN ? round($dMeetCount / $dN * 100) : 0;
              $dCodeColors = ['ee'=>'#16a34a','ee1'=>'#16a34a','ee2'=>'#15803d','me'=>'#1a4a7a','me1'=>'#1a4a7a','me2'=>'#123657','ae'=>'#d97706','ae1'=>'#b45309','ae2'=>'#92400e','be'=>'#991b1b','be1'=>'#991b1b','be2'=>'#7f1d1d'];
              $podium = array_slice($detailRows, 0, 3);
            ?>
            <div class="detail-banner">
              <div>
                <h3><?= hoi_e($detail['label']) ?> · Grade <?= $dG ?></h3>
                <small><?= $dG <= 6 ? '4-level scale (Grades 1–6)' : '8-level scale (Grades 7–9)' ?> · <?= $dSc ?> subjects assessed</small>
              </div>
              <span class="lvl <?= hoi_lvl_class($detail['level']) ?>" style="font-size:.9rem;padding:6px 16px">Class level: <?= hoi_e($detail['level']) ?></span>
            </div>

            <div class="kpi-strip">
              <div class="kpi" style="animation-delay:.03s">
                <div class="ico"><i class="fa-solid fa-users"></i></div>
                <div class="kpi-label">Learners Assessed</div>
                <div class="kpi-val"><?= $dN ?></div>
                <div class="kpi-sub"><?= hoi_e($detail['label']) ?></div>
              </div>
              <div class="kpi blue" style="animation-delay:.06s">
                <div class="ico"><i class="fa-solid fa-calculator"></i></div>
                <div class="kpi-label">Class Mean</div>
                <div class="kpi-val"><?= $detail['total_avg'] ?></div>
                <div class="kpi-sub">out of <?= $dSc * 100 ?> · <?= $detail['mean'] ?> avg per subject</div>
              </div>
              <div class="kpi green" style="animation-delay:.09s">
                <div class="ico"><i class="fa-solid fa-circle-check"></i></div>
                <div class="kpi-label">Meeting or Above</div>
                <div class="kpi-val"><?= $dMeetCount ?></div>
                <div class="kpi-sub"><?= $dMeetP ?>% of class</div>
              </div>
              <div class="kpi gold" style="animation-delay:.12s">
                <div class="ico"><i class="fa-solid fa-star"></i></div>
                <div class="kpi-label">Exceeding Expectations</div>
                <div class="kpi-val"><?= $dTopCount ?></div>
                <div class="kpi-sub"><?= $dTopP ?>% of class</div>
              </div>
              <div class="kpi red" style="animation-delay:.15s">
                <div class="ico"><i class="fa-solid fa-life-ring"></i></div>
                <div class="kpi-label">Below Expectations</div>
                <div class="kpi-val"><?= $dBelowCount ?></div>
                <div class="kpi-sub"><?= $dBelowP ?>% need support</div>
              </div>
            </div>

            <div class="grid-2" style="margin-top:16px">
              <div class="card">
                <div class="card-head"><h3><i class="fa-solid fa-layer-group"></i> Performance Levels</h3></div>
                <div class="card-body">
                  <?php foreach ($dCodes as $code):
                      $cnt = $detail['codes'][$code] ?? 0;
                      $pct = $dN ? round($cnt / $dN * 100) : 0;
                      $col = $dCodeColors[hoi_lvl_class($code)] ?? '#1a4a7a';
                      $lab = ($dG <= 6)
                          ? bandInfoForGrade(representativeScoreForGrade($code, $dG), $dG)['label']
                          : bandInfo(representativeScoreFor($code))['label'];
                  ?>
                  <div class="band-row">
                    <div class="band-label"><span class="lvl <?= hoi_lvl_class($code) ?>"><?= hoi_e($code) ?></span>&nbsp; <?= hoi_e($lab) ?></div>
                    <div class="band-bar-wrap"><div class="band-bar" style="background:<?= $col ?>;width:<?= $pct ?>%"></div></div>
                    <div class="band-count"><?= $cnt ?> (<?= $pct ?>%)</div>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="card">
                <div class="card-head"><h3><i class="fa-solid fa-book"></i> Subject Means (out of 100)</h3></div>
                <div class="card-body"><div class="chart-wrap"><canvas id="detailSubjChart"></canvas></div></div>
              </div>
            </div>

            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-ranking-star"></i> Class Ranking — <?= hoi_e($detail['label']) ?></h3><span class="tag">Grade <?= $dG ?> · <?= $dN ?> learners</span></div>
              <div class="podium">
                <?php foreach ($podium as $i => $pd): ?>
                <div class="pod p<?= $i + 1 ?>">
                  <div class="medal"><?= $pd['rank'] ?></div>
                  <b><?= hoi_e($pd['name']) ?></b>
                  <small>Assess. No <?= hoi_e($pd['ass']) ?></small>
                  <div class="score"><?= $pd['total'] ?> <small>/ <?= $dSc * 100 ?> · mean <?= $pd['avg'] ?></small></div>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="table-tools">
                <input type="search" class="search" placeholder="Search learner or assessment no…" data-filter="learnersTbl" aria-label="Search learners">
                <button type="button" class="btn ghost sm" id="toggleLearners" data-open="0"><i class="fa-solid fa-list"></i> Show all <?= $dN ?></button>
                <span class="hint">Click a column heading to sort</span>
              </div>
              <div class="table-scroll">
                <table class="data-table" id="learnersTbl">
                  <thead><tr><th data-sort="num">Rank</th><th data-sort="text">Assess. No</th><th data-sort="text">Learner</th><th data-sort="num">Total (/<?= $dSc * 100 ?>)</th><th data-sort="num">Mean</th><th data-sort="text">Level</th></tr></thead>
                  <tbody>
                    <?php foreach ($detailRows as $ri => $dr):
                        $rc = $dr['rank'] === 1 ? 'r1' : ($dr['rank'] === 2 ? 'r2' : ($dr['rank'] === 3 ? 'r3' : ''));
                        $lv = bandInfoForGrade($dr['avg'], $dG)['code'];
                        $tc = hoi_tier_class($dr['avg']);
                    ?>
                    <tr class="lrow<?= $ri >= 10 ? ' hidden-row extra' : '' ?>" data-s="<?= hoi_e(strtolower($dr['name'] . ' ' . $dr['ass'])) ?>">
                      <td data-v="<?= $dr['rank'] ?>"><span class="rank-badge <?= $rc ?>"><?= $dr['rank'] ?></span></td>
                      <td><?= hoi_e($dr['ass']) ?></td>
                      <td><strong><?= hoi_e($dr['name']) ?></strong></td>
                      <td class="num" data-v="<?= $dr['total'] ?>"><?= $dr['total'] ?></td>
                      <td data-v="<?= $dr['avg'] ?>"><?= $dr['avg'] ?><span class="meter"><i class="<?= $tc ?>" style="width:<?= min(100, max(0, $dr['avg'])) ?>%"></i></span></td>
                      <td><span class="lvl <?= hoi_lvl_class($lv) ?>"><?= hoi_e($lv) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php elseif ($detailMode): ?>
            <div class="card"><div class="card-body" style="text-align:center;color:var(--muted)"><i class="fa-regular fa-folder-open" style="font-size:1.6rem;display:block;margin-bottom:8px"></i>No results found for the selected grade, term, exam and year.</div></div>
          <?php endif; ?>

          <?php if ($allLabels): ?>
          <div class="card">
            <div class="card-head">
              <h3><i class="fa-solid fa-table-cells-large"></i> Exam Performance Matrix — Mean /100 by Grade</h3>
              <div class="legend">
                <span><i style="background:var(--ee)"></i>75+ E.E</span>
                <span><i style="background:var(--me)"></i>50–74 M.E</span>
                <span><i style="background:var(--ae)"></i>26–49 A.E</span>
                <span><i style="background:var(--be)"></i>0–25 B.E</span>
              </div>
            </div>
            <div class="card-body table-scroll" style="padding-top:12px">
              <table class="heat">
                <thead>
                  <tr><th class="rowh">Grade</th><?php foreach ($allLabels as $lbl): ?><th><?= hoi_e($lbl) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                  <?php for ($g = 1; $g <= 9; $g++):
                      $hasAny = false;
                      foreach ($allLabels as $lbl) if (isset($perfIdx["$g|$lbl"])) { $hasAny = true; break; }
                      if (!$hasAny) continue;
                  ?>
                  <tr>
                    <th class="rowh">Grade <?= $g ?></th>
                    <?php foreach ($allLabels as $lbl):
                        $k = "$g|$lbl";
                        if (!isset($perfIdx[$k])) { echo "<td><span class='t-na'>—</span></td>"; continue; }
                        $ps = $perfSess[$k];
                        $href = '?grade=' . $g . '&term=' . $ps['term'] . '&exam_type=' . urlencode($ps['exam']) . '&year=' . $ps['year'] . '#results';
                    ?>
                    <td><a class="t-<?= hoi_tier_class($perfIdx[$k]) ?>" href="<?= hoi_e($href) ?>" title="Open class breakdown — <?= hoi_e($lbl) ?> · Grade <?= $g ?> (<?= $ps['n'] ?> learners)"><?= $perfIdx[$k] ?></a></td>
                    <?php endforeach; ?>
                  </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
              <p class="scale-note">Click any cell to open that exam's full class breakdown.</p>
            </div>
          </div>
          <?php endif; ?>

          <div class="grid-2-1">
            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-chart-column"></i> <?= $hasFilter ? 'Latest Matching Exam' : 'Latest Exam' ?> — Average Subject Score by Grade</h3><span class="tag live">Live</span></div>
              <div class="card-body"><div class="chart-wrap"><canvas id="gradePerfChart"></canvas></div></div>
            </div>
            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-chart-pie"></i> Achievement Bands<?= $hasFilter ? ' (Selection)' : ' (All Exams)' ?></h3></div>
              <div class="card-body"><div class="chart-wrap"><canvas id="bandChart"></canvas></div></div>
            </div>
          </div>

          <?php if (count($viewSessions) > 0): ?>
          <div class="card">
            <div class="card-head"><h3><i class="fa-solid fa-timeline"></i> Grade Performance Timeline</h3></div>
            <div class="card-body"><div class="chart-wrap tall"><canvas id="timelineChart"></canvas></div></div>
          </div>
          <?php endif; ?>

          <div class="grid-2-1">
            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-table-list"></i> All Exam Results Summary</h3><span class="tag"><?= count($viewSessions) ?> sessions</span></div>
              <div class="table-tools">
                <input type="search" class="search" placeholder="Search grade, term, exam, year…" data-filter="sessionsTbl" aria-label="Search exam sessions">
                <span class="hint">Newest first · click headings to sort</span>
              </div>
              <div class="table-scroll" style="max-height:520px">
                <table class="data-table" id="sessionsTbl">
                  <thead><tr><th data-sort="num">Grade</th><th data-sort="num">Term</th><th data-sort="text">Exam</th><th data-sort="num">Year</th><th data-sort="num">Learners</th><th data-sort="num">Avg Total</th><th data-sort="num">Avg /100</th><th data-sort="text">Level</th><th></th></tr></thead>
                  <tbody>
                    <?php if (!$viewSessions): ?>
                      <tr><td colspan="9" class="empty-row">No exam results match the selected filters.</td></tr>
                    <?php else: foreach (array_reverse($viewSessions) as $s):
                        $href = '?grade=' . $s['grade'] . '&term=' . $s['term'] . '&exam_type=' . urlencode($s['exam']) . '&year=' . $s['year'] . '#results';
                        $examLbl = hoi_exam_label($s['exam'], $EXAM_TYPES);
                    ?>
                      <tr class="lrow" data-s="<?= hoi_e(strtolower("grade {$s['grade']} term {$s['term']} $examLbl {$s['year']}")) ?>">
                        <td data-v="<?= $s['grade'] ?>"><strong>Grade <?= $s['grade'] ?></strong></td>
                        <td data-v="<?= $s['term'] ?>">Term <?= $s['term'] ?></td>
                        <td><?= hoi_e($examLbl) ?></td>
                        <td data-v="<?= $s['year'] ?>"><?= $s['year'] ?></td>
                        <td data-v="<?= $s['n'] ?>"><?= $s['n'] ?></td>
                        <td class="num" data-v="<?= $s['total_avg'] ?>"><?= $s['total_avg'] ?> <span style="color:var(--muted);font-weight:500">/ <?= $s['sc'] * 100 ?></span></td>
                        <td class="num" data-v="<?= $s['mean'] ?>"><?= $s['mean'] ?><span class="meter"><i class="<?= hoi_tier_class($s['mean']) ?>" style="width:<?= min(100, max(0, $s['mean'])) ?>%"></i></span></td>
                        <td><span class="lvl <?= hoi_lvl_class($s['level']) ?>"><?= hoi_e($s['level']) ?></span></td>
                        <td><a class="rowlink" href="<?= hoi_e($href) ?>">View <i class="fa-solid fa-arrow-right"></i></a></td>
                      </tr>
                    <?php endforeach; endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-list-ol"></i> Latest Exam per Grade</h3></div>
              <div class="table-scroll">
                <table class="data-table" style="min-width:unset">
                  <thead><tr><th>Grade</th><th>Exam</th><th>Mean</th><th>Level</th></tr></thead>
                  <tbody>
                    <?php for ($g = 1; $g <= 9; $g++):
                        if (!isset($latestGradePerf[$g])) { echo "<tr><td><strong>Grade $g</strong></td><td colspan='3' style='color:var(--muted)'>No results</td></tr>"; continue; }
                        $lp = $latestGradePerf[$g];
                    ?>
                    <tr>
                      <td><strong>Grade <?= $g ?></strong></td>
                      <td style="font-size:.74rem"><?= hoi_e($lp['label']) ?></td>
                      <td class="num"><?= $lp['mean'] ?></td>
                      <td><span class="lvl <?= hoi_lvl_class($lp['level']) ?>"><?= hoi_e($lp['level']) ?></span></td>
                    </tr>
                    <?php endfor; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Grading scale reference (collapsible) -->
          <details class="card fold">
            <summary class="card-head">
              <h3><i class="fa-solid fa-scale-balanced"></i> Grading Scale Reference</h3>
              <i class="fa-solid fa-chevron-down chev"></i>
            </summary>
            <div class="card-body">
              <div class="scale-grid">
                <div>
                  <div class="kpi-label" style="margin-bottom:10px">Grades 1–6 · 4-Level Scale</div>
                  <div class="table-scroll"><table class="data-table" style="min-width:unset">
                    <thead><tr><th>Level</th><th>Meaning</th><th>Marks</th><th>Pts</th></tr></thead>
                    <tbody>
                      <tr><td><span class="lvl ee">E.E</span></td><td>Exceeding Expectation</td><td>75 – 100</td><td>4</td></tr>
                      <tr><td><span class="lvl me">M.E</span></td><td>Meeting Expectation</td><td>50 – 74</td><td>3</td></tr>
                      <tr><td><span class="lvl ae">A.E</span></td><td>Approaching Expectation</td><td>26 – 49</td><td>2</td></tr>
                      <tr><td><span class="lvl be">B.E</span></td><td>Below Expectation</td><td>0 – 25</td><td>1</td></tr>
                    </tbody>
                  </table></div>
                </div>
                <div>
                  <div class="kpi-label" style="margin-bottom:10px">Grades 7–9 · 8-Level Scale</div>
                  <div class="table-scroll"><table class="data-table" style="min-width:unset">
                    <thead><tr><th>Level</th><th>Marks</th><th>Pts</th><th>Level</th><th>Marks</th><th>Pts</th></tr></thead>
                    <tbody>
                      <tr><td><span class="lvl ee2">EE2</span></td><td>90 – 100</td><td>8</td><td><span class="lvl ae2">AE2</span></td><td>38 – 49</td><td>4</td></tr>
                      <tr><td><span class="lvl ee1">EE1</span></td><td>75 – 89</td><td>7</td><td><span class="lvl ae1">AE1</span></td><td>26 – 37</td><td>3</td></tr>
                      <tr><td><span class="lvl me2">ME2</span></td><td>63 – 74</td><td>6</td><td><span class="lvl be2">BE2</span></td><td>13 – 25</td><td>2</td></tr>
                      <tr><td><span class="lvl me1">ME1</span></td><td>50 – 62</td><td>5</td><td><span class="lvl be1">BE1</span></td><td>0 – 12</td><td>1</td></tr>
                    </tbody>
                  </table></div>
                </div>
              </div>
              <p class="scale-note">Learner levels are based on average marks per subject; the same scales drive View Results, report cards and analysis PDFs.</p>
            </div>
          </details>
        </section>

        <!-- ══════════ 3. FINANCES ══════════ -->
        <section id="finance">
          <div class="section-head">
            <div><h2><i class="fa-solid fa-coins"></i> Finances</h2><p>Term summaries, collection per grade and fee-record coverage</p></div>
            <span class="badge">KES <?= number_format($grandTotal) ?> collected</span>
          </div>

          <div class="finance-grid">
            <?= hoi_term_card(1, $t1, 'Term 1 Totals', $maxTermTotal) ?>
            <?= hoi_term_card(2, $t2, 'Term 2 Totals', $maxTermTotal) ?>
            <?= hoi_term_card(3, $t3, 'Term 3 Totals', $maxTermTotal) ?>
          </div>

          <div class="card">
            <div class="card-head">
              <h3><i class="fa-solid fa-chart-simple"></i> Fee Collection by Grade — All Categories</h3>
              <span class="tag live">Live Data</span>
            </div>
            <div class="card-body"><div class="chart-wrap tall"><canvas id="gradeFeesChart"></canvas></div></div>
          </div>

          <div class="card">
            <div class="card-head"><h3><i class="fa-solid fa-table"></i> Fee Collection Per Grade &amp; Debt Analysis</h3><span class="tag">Overall coverage <?= $overallCoverage ?>%</span></div>
            <div class="table-scroll">
              <table class="data-table">
                <thead>
                  <tr><th>Grade</th><th>Enrolled</th><th>With Records</th><th>School Fee</th><th>Assessment</th><th>Activity</th><th>Other</th><th>Grade Total</th><th>Coverage</th><th>Debt Status</th></tr>
                </thead>
                <tbody>
                <?php
                $grandFee = 0; $grandAssess = 0; $grandAct = 0; $grandOther = 0; $grandGTotal = 0;
                for ($g = 1; $g <= 9; $g++) {
                    $enrolled = $gradeStudents[$g] ?? 0;
                    $paid     = $gradeFeePaid[$g]['paid_students'] ?? 0;
                    $gTotal = 0; $fee = $assess = $act = $other = 0;
                    foreach ($gradeData as $gd) {
                        if ((int)$gd['Grade'] === $g) {
                            $fee = $gd['fee']; $assess = $gd['assess']; $act = $gd['activity']; $other = $gd['other'];
                            $gTotal = $fee + $assess + $act + $other;
                        }
                    }
                    $grandFee += $fee; $grandAssess += $assess; $grandAct += $act; $grandOther += $other; $grandGTotal += $gTotal;
                    $coverage = $enrolled > 0 ? round($paid / $enrolled * 100) : 0;
                    $missing  = max(0, $enrolled - $paid);
                    if ($coverage >= 90)      { $pillClass = 'success'; $pillLabel = 'Low Risk';  $barClass = 'g'; }
                    elseif ($coverage >= 60)  { $pillClass = 'warn';    $pillLabel = 'Moderate';  $barClass = 'a'; }
                    else                      { $pillClass = 'danger';  $pillLabel = 'High Risk'; $barClass = 'r'; }
                    $barW = min(100, $coverage);
                    echo "<tr>
                      <td><strong>Grade $g</strong></td>
                      <td>$enrolled</td><td>$paid</td>
                      <td>" . number_format((float)$fee) . "</td>
                      <td>" . number_format((float)$assess) . "</td>
                      <td>" . number_format((float)$act) . "</td>
                      <td>" . number_format((float)$other) . "</td>
                      <td class='num'>" . number_format($gTotal) . "</td>
                      <td><span class='num'>$coverage%</span><span class='meter'><i class='$barClass' style='width:{$barW}%'></i></span><br><small style='color:var(--muted);font-size:.68rem'>$missing unpaid</small></td>
                      <td><span class='pill $pillClass'>$pillLabel</span></td>
                    </tr>";
                }
                ?>
                </tbody>
                <tfoot>
                  <tr>
                    <td colspan="3">Totals</td>
                    <td><?= number_format($grandFee) ?></td>
                    <td><?= number_format($grandAssess) ?></td>
                    <td><?= number_format($grandAct) ?></td>
                    <td><?= number_format($grandOther) ?></td>
                    <td><?= number_format($grandGTotal) ?></td>
                    <td colspan="2"></td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>

          <div class="grid-2">
            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-percent"></i> Fee Coverage % by Grade</h3></div>
              <div class="card-body"><div class="chart-wrap"><canvas id="coverageChart"></canvas></div></div>
            </div>
            <div class="card">
              <div class="card-head"><h3><i class="fa-solid fa-user-clock"></i> Students Without Fee Records</h3></div>
              <div class="card-body"><div class="chart-wrap"><canvas id="debtChart"></canvas></div></div>
            </div>
          </div>
        </section>

        <!-- ══════════ 4. STAFF ══════════ -->
        <section id="staff">
          <div class="section-head">
            <div><h2><i class="fa-solid fa-chalkboard-user"></i> Teaching Staff</h2><p>Registered teachers, roles and subjects</p></div>
            <span class="badge"><?= $totalTeachers ?> teachers</span>
          </div>
          <div class="card">
            <div class="table-tools">
              <input type="search" class="search" placeholder="Search name, subject, role, TSC no…" data-filter="staffTbl" aria-label="Search staff">
              <span class="hint">Click a column heading to sort</span>
            </div>
            <div class="table-scroll">
              <table class="data-table" id="staffTbl">
                <thead><tr><th data-sort="text">Name</th><th data-sort="text">Email</th><th>Phone</th><th data-sort="text">TSC No</th><th data-sort="text">Role</th><th data-sort="text">Class Teacher</th><th data-sort="text">Subject</th></tr></thead>
                <tbody>
                  <?php $ai = 0; while ($teachersRes && ($row = mysqli_fetch_assoc($teachersRes))):
                      $nm = (string)($row['name'] ?? '');
                      $initials = strtoupper(function_exists('mb_substr') ? mb_substr($nm, 0, 1) : substr($nm, 0, 1));
                      $col = $avatarColors[$ai % count($avatarColors)]; $ai++;
                  ?>
                  <tr class="lrow" data-s="<?= hoi_e(strtolower($nm . ' ' . ($row['subject'] ?? '') . ' ' . ($row['role'] ?? '') . ' ' . ($row['TscNo'] ?? '') . ' ' . ($row['email'] ?? ''))) ?>">
                    <td><div class="staff-name"><span class="avatar" style="background:<?= $col ?>22;color:<?= $col ?>"><?= hoi_e($initials) ?></span><strong><?= hoi_e($nm) ?></strong></div></td>
                    <td><?= hoi_e($row['email'] ?? '') ?></td>
                    <td><?= hoi_e($row['phoneNo'] ?? '') ?></td>
                    <td><code class="tsc"><?= hoi_e($row['TscNo'] ?? '') ?></code></td>
                    <td><?= hoi_e($row['role'] ?? '') ?></td>
                    <td><?= hoi_e($row['classTeacher'] ?? '') ?></td>
                    <td><span class="pill success"><?= hoi_e($row['subject'] ?? '') ?></span></td>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          </div>
        </section>

      </div><!-- .content -->
    </main>

    <div class="footer">Designed by <strong>Kelvin Mutinda</strong> · Stephen Kanja School Management System · 2026</div>
  </div>
</div>

<script>
/* ── Sidebar ── */
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('backdrop').classList.toggle('show');
}
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('backdrop').classList.remove('show');
  }
});

/* ── Theme ── */
function syncThemeIcon() {
  const dark = document.documentElement.getAttribute('data-theme') === 'dark';
  const b = document.getElementById('themeBtn');
  if (b) b.innerHTML = dark ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
}
function toggleTheme() {
  const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  try { localStorage.setItem('hoi-theme', next); } catch (e) {}
  syncThemeIcon();
  if (window.applyChartTheme) window.applyChartTheme();
}
syncThemeIcon();

/* ── Filters: auto-apply on change ── */
document.querySelectorAll('#filterForm select').forEach(s => s.addEventListener('change', () => document.getElementById('filterForm').submit()));

/* ── Jump nav scroll-spy ── */
(function () {
  const links = [...document.querySelectorAll('#jump a')];
  const map = new Map(links.map(a => [a.getAttribute('href').slice(1), a]));
  const io = new IntersectionObserver(entries => {
    entries.forEach(en => {
      if (en.isIntersecting) { links.forEach(l => l.classList.remove('on')); const a = map.get(en.target.id); if (a) a.classList.add('on'); }
    });
  }, { rootMargin: '-30% 0px -60% 0px' });
  map.forEach((a, id) => { const el = document.getElementById(id); if (el) io.observe(el); });
})();

/* ── Table search + sort + learners expander ── */
(function () {
  document.querySelectorAll('input[data-filter]').forEach(inp => {
    const tbl = document.getElementById(inp.dataset.filter);
    if (!tbl) return;
    inp.addEventListener('input', () => {
      const q = inp.value.trim().toLowerCase();
      tbl.querySelectorAll('tbody tr.lrow').forEach(tr => {
        const hit = !q || (tr.dataset.s || tr.textContent.toLowerCase()).includes(q);
        if (q) { tr.style.display = hit ? '' : 'none'; tr.classList.remove('hidden-row'); }
        else { tr.style.display = ''; if (tr.classList.contains('extra') && tbl.dataset.expanded !== '1') tr.classList.add('hidden-row'); }
      });
    });
  });

  document.querySelectorAll('table.data-table').forEach(tbl => {
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
  });

  const tg = document.getElementById('toggleLearners');
  if (tg) {
    const tbl = document.getElementById('learnersTbl');
    const total = tbl.querySelectorAll('tbody tr.lrow').length;
    if (total <= 10) tg.style.display = 'none';
    tg.addEventListener('click', () => {
      const open = tbl.dataset.expanded === '1';
      tbl.dataset.expanded = open ? '0' : '1';
      tbl.querySelectorAll('tr.extra').forEach(r => r.classList.toggle('hidden-row', open));
      tg.innerHTML = open ? '<i class="fa-solid fa-list"></i> Show all ' + total : '<i class="fa-solid fa-angles-up"></i> Show top 10 only';
    });
  }
})();

/* ── Charts ── */
(function () {
  const css = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
  Chart.defaults.font.size   = 12;

  const C = { ee:'#16a34a', me:'#2563eb', ae:'#d97706', be:'#dc2626', ink:'#0b1f3a', gold:'#f0c040' };
  const tip = { backgroundColor:'#0b1f3a', titleColor:'#fff', bodyColor:'#cbd5e1', padding:11, cornerRadius:10, borderColor:'rgba(240,192,64,0.45)', borderWidth:1, displayColors:true, boxPadding:4 };
  const colorFor = v => v > 74 ? C.ee : v > 49 ? C.me : v > 25 ? C.ae : C.be;
  const G = ['G1','G2','G3','G4','G5','G6','G7','G8','G9'];
  const noGrid = { grid:{ display:false } };
  const legend = { position:'bottom', labels:{ usePointStyle:true, pointStyle:'circle', padding:16 } };

  window.applyChartTheme = function () {
    Chart.defaults.color = css('--muted');
    Chart.defaults.borderColor = css('--grid');
    Object.values(Chart.instances).forEach(ch => {
      if (ch.config.type === 'doughnut') ch.data.datasets.forEach(d => d.borderColor = css('--surface'));
      if (ch.options.scales) Object.values(ch.options.scales).forEach(sc => { if (sc.grid && sc.grid.display !== false) sc.grid.color = css('--grid'); });
      ch.update('none');
    });
  };
  Chart.defaults.color = css('--muted');
  Chart.defaults.borderColor = css('--grid');
  const surface = css('--surface');

  const kes = v => 'KES ' + Number(v).toLocaleString();

  /* Overview: enrolment, revenue by term, revenue mix */
  new Chart(document.getElementById('enrolChart'), {
    type:'bar',
    data:{ labels:G, datasets:[{ label:'Learners', data:<?= json_encode($enrolData) ?>, backgroundColor:C.me, borderRadius:6, maxBarThickness:28 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{ legend:{display:false}, tooltip:tip },
      scales:{ x:noGrid, y:{ beginAtZero:true, ticks:{ precision:0 } } } }
  });
  new Chart(document.getElementById('termRevChart'), {
    type:'bar',
    data:{ labels:['Term 1','Term 2','Term 3'], datasets:[{ label:'Revenue', data:<?= json_encode($termTotals, JSON_NUMERIC_CHECK) ?>, backgroundColor:[C.ink,C.gold,C.ee], borderRadius:8, maxBarThickness:56 }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false}, tooltip:Object.assign({}, tip, { callbacks:{ label:c => ' ' + kes(c.raw) } }) },
      scales:{ x:noGrid, y:{ beginAtZero:true, ticks:{ callback:v => v >= 1000 ? (v/1000) + 'k' : v } } } }
  });
  new Chart(document.getElementById('revMixChart'), {
    type:'doughnut',
    data:{ labels:['School Fee','Assessment','Activity','Other'], datasets:[{ data:<?= json_encode([$sumFee, $sumAssess, $sumAct, $sumOther], JSON_NUMERIC_CHECK) ?>, backgroundColor:[C.ink,C.gold,C.me,C.ee], borderWidth:3, borderColor:surface, hoverOffset:8 }] },
    options:{ responsive:true, maintainAspectRatio:false, cutout:'64%',
      plugins:{ legend:legend, tooltip:Object.assign({}, tip, { callbacks:{ label:c => ' ' + c.label + ': ' + kes(c.raw) } }) } }
  });

  /* Fees by grade */
  new Chart(document.getElementById('gradeFeesChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($chartGrades) ?>,
      datasets: [
        { label:'School Fee', data:<?= json_encode($chartFees, JSON_NUMERIC_CHECK) ?>,   backgroundColor:C.ink, borderRadius:4 },
        { label:'Assessment', data:<?= json_encode($chartAssess, JSON_NUMERIC_CHECK) ?>, backgroundColor:C.gold, borderRadius:4 },
        { label:'Activity',   data:<?= json_encode($chartAct, JSON_NUMERIC_CHECK) ?>,    backgroundColor:C.me, borderRadius:4 },
        { label:'Other',      data:<?= json_encode($chartOther, JSON_NUMERIC_CHECK) ?>,  backgroundColor:C.ee, borderRadius:4 }
      ]
    },
    options: {
      responsive:true, maintainAspectRatio:false, interaction:{ mode:'index', intersect:false },
      plugins: { legend:legend, tooltip:Object.assign({}, tip, { callbacks:{ label:c => ' ' + c.dataset.label + ': ' + kes(c.raw) } }) },
      scales: { x:noGrid, y:{ ticks:{ callback:v => 'KES ' + v.toLocaleString() } } }
    }
  });

  /* Coverage */
  const cov = <?= json_encode($covData) ?>;
  new Chart(document.getElementById('coverageChart'), {
    type:'bar',
    data:{ labels:G, datasets:[{ label:'Coverage %', data:cov, backgroundColor:cov.map(v => v >= 90 ? C.ee : v >= 60 ? C.ae : C.be), borderRadius:6, maxBarThickness:34 }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false}, tooltip:Object.assign({}, tip, { callbacks:{ label:c => ' ' + c.raw + '% coverage' } }) },
      scales:{ x:noGrid, y:{ min:0, max:100, ticks:{ callback:v => v + '%' } } } }
  });

  /* Debt */
  const debt = <?= json_encode($debtData) ?>;
  new Chart(document.getElementById('debtChart'), {
    type:'bar',
    data:{ labels:G, datasets:[{ label:'Unpaid students', data:debt, backgroundColor:debt.map(v => v === 0 ? C.ee : v < 5 ? C.ae : C.be), borderRadius:6, maxBarThickness:34 }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false}, tooltip:tip },
      scales:{ x:noGrid, y:{ ticks:{ stepSize:1, precision:0 } } } }
  });

  /* Latest exam avg by grade */
  const gp = <?= json_encode($gradePerfMeans, JSON_NUMERIC_CHECK) ?>;
  new Chart(document.getElementById('gradePerfChart'), {
    type:'bar',
    data:{ labels:G, datasets:[{ label:'Avg /100', data:gp, backgroundColor:gp.map(colorFor), borderRadius:6, maxBarThickness:40 }] },
    options:{ responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false}, tooltip:Object.assign({}, tip, { callbacks:{ label:c => ' ' + c.raw + '/100' } }) },
      scales:{ x:noGrid, y:{ min:0, max:100, ticks:{ callback:v => v + '/100' } } } }
  });

  /* Band doughnut */
  new Chart(document.getElementById('bandChart'), {
    type:'doughnut',
    data:{ labels:['Exceeding (E.E)','Meeting (M.E)','Approaching (A.E)','Below (B.E)'],
      datasets:[{ data:<?= json_encode(array_values($bandCounts)) ?>, backgroundColor:[C.ee,C.me,C.ae,C.be], borderWidth:3, borderColor:surface, hoverOffset:8 }] },
    options:{ responsive:true, maintainAspectRatio:false, cutout:'64%', plugins:{ legend:legend, tooltip:tip } }
  });

  <?php if (count($viewSessions) > 0): ?>
  /* Timeline */
  new Chart(document.getElementById('timelineChart'), {
    type:'line',
    data:{ labels:<?= json_encode($allLabels) ?>, datasets:<?= json_encode($gradeDatasets) ?> },
    options:{ responsive:true, maintainAspectRatio:false, interaction:{ mode:'index', intersect:false },
      plugins:{ legend:legend, tooltip:tip },
      scales:{ x:{ ticks:{ maxRotation:40, minRotation:25 } }, y:{ min:0, max:100, ticks:{ callback:v => v + '/100' } } } }
  });
  <?php endif; ?>

  <?php if ($detailMode && $detail && $detailRows): ?>
  /* Selected exam: subject means (horizontal, full labels) */
  const ds = <?= json_encode($dSubjMeans, JSON_NUMERIC_CHECK) ?>;
  const dl = <?= json_encode($dSubjLabels) ?>;
  new Chart(document.getElementById('detailSubjChart'), {
    type:'bar',
    data:{ labels:dl, datasets:[{ label:'Mean', data:ds, backgroundColor:ds.map(colorFor), borderRadius:6, maxBarThickness:22 }] },
    options:{ indexAxis:'y', responsive:true, maintainAspectRatio:false,
      plugins:{ legend:{display:false}, tooltip:Object.assign({}, tip, { callbacks:{ label:c => ' ' + c.raw + '/100' } }) },
      scales:{ x:{ min:0, max:100 }, y:noGrid } }
  });
  <?php endif; ?>
})();
</script>

</body>
</html>