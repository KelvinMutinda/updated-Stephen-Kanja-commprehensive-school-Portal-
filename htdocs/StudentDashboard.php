<?php

 require_once __DIR__ . '/page_guard.php';
$DEBUG = false;
ini_set('display_errors', $DEBUG ? '1' : '0');
error_reporting(E_ALL);

session_start();
include 'conn.php';
require_once 'add-exam-type.php';   // getExamTypes() + resolveExamCode() — same as ViewResults.php
require_once 'exam_release.php';     // isExamReleased() — results show only after the admin releases them
include 'subjects_config.php';      // getSubjectsForGrade() + bandInfoForGrade() + bandCssClass()

date_default_timezone_set('Africa/Nairobi');

// ── Require login ──────────────────────────────────────────
if (empty($_SESSION['loggedin']) || empty($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$assessRaw = (string)$_SESSION['username'];
$assess    = mysqli_real_escape_string($conn, $assessRaw);

// ── Student info ───────────────────────────────────────────
$stu_res = mysqli_query($conn, "SELECT * FROM Student WHERE Assesment='$assess' LIMIT 1");
$student = $stu_res ? mysqli_fetch_assoc($stu_res) : null;
if (!$student) {
    session_unset();
    session_destroy();
    header('Location: index.php');
    exit;
}

$grade    = $student['Grade'];
$gradeInt = (int)$grade;
$name     = htmlspecialchars($student['firstName'] . ' ' . $student['surname']);
$sid      = trim((string)($student['id'] ?? ''));

$section = isset($_GET['section']) ? $_GET['section'] : 'home';

/* ════════════════════════════════════════════════════════════════════
   HELPERS
   ════════════════════════════════════════════════════════════════════ */
function sdTerm($t) { $d = preg_replace('/[^0-9]/', '', (string)$t); return $d !== '' ? (int)$d : 0; }
function sdExam($e) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$e)); }
function sdFmt($n)  { $n = (float)$n; return floor($n) == $n ? (string)(int)$n : number_format($n, 1, '.', ''); }
function sdOrd($n)  { $n = (int)$n; $s = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : [1=>'st',2=>'nd',3=>'rd'][$n % 10] ?? 'th'; return $n . $s; }
function sdTier($cls) { return substr((string)$cls, 0, 2); }   // ee1 / ee2 / ee → ee, etc.

function sdShort($label) {
    static $map = [
        'english'=>'ENG','kiswahili'=>'KIS','mathematics'=>'MAT','integratedscience'=>'INT/SC',
        'creativeartsandsports'=>'CAS','socialstudies'=>'SST','christianreligiouseducation'=>'C.R.E',
        'islamicreligiouseducation'=>'I.R.E','agriculture'=>'AGRI','pretechnicalstudies'=>'PRE TECH',
        'pretechnical'=>'PRE TECH','environmentalactivities'=>'ENV','religiouseducation'=>'RE',
        'science'=>'SCI','creativearts'=>'CA',
    ];
    $k = preg_replace('/[^a-z]/', '', strtolower((string)$label));
    return $map[$k] ?? strtoupper(substr((string)$label, 0, 8));
}

/* Keeps the highest numeric value per subject, so a 0/blank in one duplicate row
   never hides a real mark sitting in a sibling row for the same learner/exam. */
function sdMerge(array &$bucket, array $r, array $codes) {
    foreach ($codes as $c) {
        $v = $r[$c] ?? null;
        if ($v === null || $v === '' || !is_numeric($v)) continue;
        $v = (float)$v;
        if (!isset($bucket[$c]) || $v > $bucket[$c]) $bucket[$c] = $v;
    }
}

/* ── Exam types (same source as the teacher's ViewResults page) ── */
$EXAM_TYPES = getExamTypes($conn);   // code => ['label'=>..., 'custom'=>bool]
$examLabels = [];
foreach ($EXAM_TYPES as $code => $et) $examLabels[sdExam($code)] = $et['label'];

/* Chronological order of exams inside a term: built-ins first, then custom ones */
$examSeq = [];
foreach (['opener','midterm','endterm'] as $c) $examSeq[] = sdExam($c);
foreach (array_keys($EXAM_TYPES) as $c) { $n = sdExam($c); if (!in_array($n, $examSeq, true)) $examSeq[] = $n; }

/* ── Which exam2 rows are mine (match on assessment number OR learner id) ── */
$myCond = "Assesment='$assess'";
if ($sid !== '') $myCond = "(Assesment='$assess' OR student_id='" . mysqli_real_escape_string($conn, $sid) . "')";

/* ── Years that have results for me ── */
$years = [];
$yr = mysqli_query($conn, "SELECT DISTINCT year FROM exam2 WHERE $myCond ORDER BY year DESC");
if ($yr) while ($y = mysqli_fetch_assoc($yr)) { if (ctype_digit((string)$y['year'])) $years[] = (int)$y['year']; }
if (!$years) $years[] = (int)date('Y');
rsort($years);

$sel_year = (isset($_GET['year']) && ctype_digit((string)$_GET['year'])) ? (int)$_GET['year'] : $years[0];
if (!in_array($sel_year, $years, true)) { $years[] = $sel_year; rsort($years); }
$sel_term = in_array($_GET['term'] ?? '', ['Term 1','Term 2','Term 3'], true) ? $_GET['term'] : '';
$sel_exam = resolveExamCode((string)($_GET['exam_type'] ?? ''), $EXAM_TYPES) ?? '';
$sel_term_n = $sel_term !== '' ? sdTerm($sel_term) : 0;
$sel_exam_n = $sel_exam !== '' ? sdExam($sel_exam) : '';
$yE = mysqli_real_escape_string($conn, (string)$sel_year);

/* ── My marks, grouped by term + exam + grade ── */
$myGroups   = [];
$gradesSeen = [];
$mr = mysqli_query($conn, "SELECT * FROM exam2 WHERE $myCond AND year='$yE'");
if ($mr) while ($r = mysqli_fetch_assoc($mr)) {
    $t = sdTerm($r['term'] ?? '');
    $e = sdExam($r['exam_type'] ?? '');
    $g = trim((string)($r['grade'] ?? ''));
    if ($t === 0 || $e === '' || !ctype_digit($g)) continue;
    $k = "$t|$e|$g";
    if (!isset($myGroups[$k])) $myGroups[$k] = ['raw' => (string)($r['exam_type'] ?? ''), 'subs' => []];
    sdMerge($myGroups[$k]['subs'], $r, array_keys(getSubjectsForGrade((int)$g)));
    $gradesSeen[$g] = true;
}

/* ── Whole class (same grade/year) → positions + subject stats per exam ── */
$classGroups = [];
if ($gradesSeen) {
    $gIn = implode(',', array_map(function ($g) use ($conn) { return "'" . mysqli_real_escape_string($conn, (string)$g) . "'"; }, array_keys($gradesSeen)));
    $cr = mysqli_query($conn, "SELECT * FROM exam2 WHERE grade IN ($gIn) AND year='$yE'");
    if ($cr) while ($r = mysqli_fetch_assoc($cr)) {
        $t = sdTerm($r['term'] ?? '');
        $e = sdExam($r['exam_type'] ?? '');
        $g = trim((string)($r['grade'] ?? ''));
        if ($t === 0 || $e === '' || !ctype_digit($g)) continue;
        $rowSid = trim((string)($r['student_id'] ?? ''));
        $rowAss = trim((string)($r['Assesment'] ?? ''));
        $sk = $rowSid !== '' ? 's:' . $rowSid : ($rowAss !== '' ? 'a:' . $rowAss : '');
        if ($sk === '') continue;
        $k = "$t|$e|$g";
        if (!isset($classGroups[$k])) $classGroups[$k] = ['students' => []];
        if (!isset($classGroups[$k]['students'][$sk])) $classGroups[$k]['students'][$sk] = ['subs' => [], 'me' => false];
        sdMerge($classGroups[$k]['students'][$sk]['subs'], $r, array_keys(getSubjectsForGrade((int)$g)));
        if (($sid !== '' && $rowSid === $sid) || $rowAss === $assessRaw) $classGroups[$k]['students'][$sk]['me'] = true;
    }
}
$classStats = [];
foreach ($classGroups as $k => $cg) {
    $parts = explode('|', $k);
    $codes = array_keys(getSubjectsForGrade((int)$parts[2]));
    $list  = [];
    foreach ($cg['students'] as $st) $list[] = ['total' => array_sum($st['subs']), 'me' => $st['me']];
    usort($list, function ($a, $b) { return $b['total'] <=> $a['total']; });
    $rank = 1; $prev = null; $rep = 0; $myRank = 0;
    foreach ($list as $i => $it) {
        if ($it['total'] === $prev) { $rep++; } else { $rank += $rep; $prev = $it['total']; $rep = 1; }
        if ($it['me'] && $myRank === 0) $myRank = $rank;
    }
    $subj = [];
    foreach ($codes as $c) {
        $vals = [];
        foreach ($cg['students'] as $st) { if (isset($st['subs'][$c]) && $st['subs'][$c] > 0) $vals[] = $st['subs'][$c]; }
        if ($vals) $subj[$c] = ['avg' => round(array_sum($vals) / count($vals), 1), 'top' => max($vals), 'low' => min($vals), 'n' => count($vals)];
    }
    $classStats[$k] = ['count' => count($list), 'rank' => $myRank, 'subj' => $subj];
}

/* ── Build one display block per exam (term + exam) ── */
$allBlocks = [];
$hiddenUnreleased = 0;   // exams with marks that the admin has not released yet
foreach ($myGroups as $k => $g) {
    [$t, $e, $gr] = explode('|', $k);
    $grInt = (int)$gr;
    /* Learners only see an exam once the admin has released it (release needs all subjects entered) */
    $relCode = resolveExamCode((string)$g['raw'], $EXAM_TYPES) ?? (string)$g['raw'];
    if (!isExamReleased($conn, $gr, 'Term ' . $t, $relCode, $sel_year)) { $hiddenUnreleased++; continue; }
    $map   = getSubjectsForGrade($grInt);
    $rows = []; $total = 0;
    foreach ($map as $code => $label) {
        $sc = $g['subs'][$code] ?? null;
        if ($sc === null || $sc <= 0) continue;           // blank / 0 = not entered
        $b = bandInfoForGrade($sc, $grInt);
        $rows[] = ['unit' => sdShort($label), 'label' => $label, 'score' => $sc, 'code' => $b['code'], 'cls' => bandCssClass($b['code']), 'blabel' => $b['label'],
                   'class_avg' => $classStats[$k]['subj'][$code]['avg'] ?? null,
                   'class_top' => $classStats[$k]['subj'][$code]['top'] ?? null,
                   'class_low' => $classStats[$k]['subj'][$code]['low'] ?? null,
                   'class_n'   => $classStats[$k]['subj'][$code]['n']   ?? 0,
                   'col' => $code];
        $total += $sc;
    }
    if (!$rows) continue;
    $nSub = max(1, count($map));
    $avg  = $total / $nSub;                               // same rule as the teacher's ViewResults page
    $lb   = bandInfoForGrade($avg, $grInt);
    $allBlocks[] = [
        'key' => $k, 'term' => (int)$t, 'exam' => $e, 'grade' => $gr,
        'exam_label' => $examLabels[$e] ?? ucfirst($g['raw']),
        'rows' => $rows, 'total' => $total, 'out_of' => $nSub * 100, 'avg' => round($avg, 1),
        'level' => $lb['code'], 'level_cls' => bandCssClass($lb['code']),
        'rank' => $classStats[$k]['rank'] ?? 0, 'class_count' => $classStats[$k]['count'] ?? 0,
    ];
}
usort($allBlocks, function ($a, $b) use ($examSeq) {
    if ($a['term'] !== $b['term']) return $a['term'] <=> $b['term'];
    $ia = array_search($a['exam'], $examSeq, true); if ($ia === false) $ia = 99;
    $ib = array_search($b['exam'], $examSeq, true); if ($ib === false) $ib = 99;
    return $ia <=> $ib;
});

/* Blocks that match the Term / Exam filters */
$viewBlocks = array_values(array_filter($allBlocks, function ($b) use ($sel_term_n, $sel_exam_n) {
    return ($sel_term_n === 0 || $b['term'] === $sel_term_n) && ($sel_exam_n === '' || $b['exam'] === $sel_exam_n);
}));
$latest   = $allBlocks ? end($allBlocks) : null;       // most recent exam this year (used on Home)
$lastView = $viewBlocks ? end($viewBlocks) : null;     // last exam shown (used for the summary cards + Grade Performance)

$my_avg = $latest ? $latest['avg'] : 0;
$my_rank = $latest ? $latest['rank'] : 0;
$total_students_in_grade = $latest ? $latest['class_count'] : 0;

// ── Attendance summary ────────────────────────────────────
$att_month_start = date('Y-m-01');
$att_month_end   = date('Y-m-t');
$att = ['Present'=>0,'Absent'=>0,'Late'=>0];
$att_res = mysqli_query($conn, "SHOW TABLES LIKE 'attendance'");
if ($att_res && mysqli_num_rows($att_res) > 0) {
    $a_res = mysqli_query($conn,
        "SELECT status, COUNT(*) as cnt FROM attendance
         WHERE Assesment='$assess' AND attendance_date BETWEEN '$att_month_start' AND '$att_month_end'
         GROUP BY status"
    );
    if ($a_res) while ($a = mysqli_fetch_assoc($a_res)) $att[$a['status']] = (int)$a['cnt'];
}
$att_total = array_sum($att);
$att_pct   = $att_total > 0 ? round(($att['Present'] / $att_total) * 100) : null;

// ── Fees (amounts PAID per record) ────────────────────────
$fees_res = mysqli_query($conn,
    "SELECT Fee, AssesmentFee, Activity, other, Grade, Term, Year, payment_date
     FROM Fees WHERE Assesment='$assess' ORDER BY Year DESC, Term DESC, payment_date DESC"
);
$fees_list = [];
$total_paid = 0;
if ($fees_res) {
    while ($f = mysqli_fetch_assoc($fees_res)) {
        $row_total = (float)$f['Fee'] + (float)$f['AssesmentFee'] + (float)$f['Activity'] + (float)$f['other'];
        $f['row_total'] = $row_total;
        $fees_list[] = $f;
        $total_paid += $row_total;
    }
}
$grade_fees_res = mysqli_query($conn,
    "SELECT Term, Year, AVG(Fee+AssesmentFee+Activity+other) as avg_paid, COUNT(*) as record_count
     FROM Fees WHERE Grade='" . mysqli_real_escape_string($conn, (string)$grade) . "' GROUP BY Term, Year ORDER BY Year DESC, Term DESC LIMIT 10"
);
$grade_fees = [];
if ($grade_fees_res) while ($gf = mysqli_fetch_assoc($grade_fees_res)) $grade_fees[] = $gf;

// ── Notices ───────────────────────────────────────────────
$notices = [];
$all_notices = [];
$nfilter = isset($_GET['ntype']) ? $_GET['ntype'] : '';
$not_check = mysqli_query($conn, "SHOW TABLES LIKE 'notices'");
if ($not_check && mysqli_num_rows($not_check) > 0) {
    $notices_res = mysqli_query($conn, "SELECT * FROM notices ORDER BY created_at DESC LIMIT 5");
    if ($notices_res) while ($n = mysqli_fetch_assoc($notices_res)) $notices[] = $n;
    $nwhere = '';
    if (in_array($nfilter, ['info','urgent','event'], true)) {
        $nfilter_esc = mysqli_real_escape_string($conn, $nfilter);
        $nwhere = "WHERE type='$nfilter_esc'";
    }
    $all_notices_res = mysqli_query($conn, "SELECT * FROM notices $nwhere ORDER BY created_at DESC");
    if ($all_notices_res) while ($n = mysqli_fetch_assoc($all_notices_res)) $all_notices[] = $n;
}

// ── Timetable ─────────────────────────────────────────────
$days    = ['Monday','Tuesday','Wednesday','Thursday','Friday'];
$periods = ['7:30 - 8:30','8:30 - 9:30','9:30 - 10:00','10:00 - 11:00','11:00 - 12:00','12:00 - 1:00','1:00 - 2:00'];
$tt_grid = [];
$tt_check = mysqli_query($conn, "SHOW TABLES LIKE 'timetable'");
if ($tt_check && mysqli_num_rows($tt_check) > 0 && !empty($grade)) {
    $grade_esc = mysqli_real_escape_string($conn, $grade);
    $tt_res = mysqli_query($conn, "SELECT * FROM timetable WHERE grade='Grade $grade_esc' OR grade='$grade_esc'");
    if ($tt_res) while ($tt = mysqli_fetch_assoc($tt_res)) $tt_grid[$tt['day']][$tt['period']] = $tt;
}

$subject_colors = [
    'Mathematics'=>['#dbeafe','#1e40af'],'English'=>['#dcfce7','#166534'],
    'Kiswahili'=>['#fde8e8','#b91c1c'],'Science'=>['#fef9c3','#854d0e'],
    'Social Studies'=>['#ede9fe','#6d28d9'],'CRE'=>['#fce7f3','#9d174d'],
    'Art & Craft'=>['#ffedd5','#9a3412'],'PE'=>['#cffafe','#155e75'],
    'Agriculture'=>['#d1fae5','#065f46'],'RE'=>['#fce7f3','#9d174d'],
    'Pre-Technical'=>['#e0e7ff','#3730a3'],'CA'=>['#f3f4f6','#374151'],
    'Break'=>['#f3f4f6','#4b5563'],'Lunch Break'=>['#f3f4f6','#4b5563'],
];

$url_base = '?';
mysqli_close($conn);

/* ── Presentation helpers ── */
$hour      = (int)date('G');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$initials  = strtoupper(substr($student['firstName'], 0, 1) . substr($student['surname'], 0, 1));
$pageTitles = [
    'home' => 'My Dashboard', 'results' => 'My Results', 'grade_perf' => 'Grade Performance',
    'attendance' => 'Attendance', 'fees' => 'Fees', 'notices' => 'Notices', 'timetable' => 'Timetable',
];
$pageTitle = $pageTitles[$section] ?? 'My Dashboard';

/* Exam-by-exam averages for the Home trend chart */
$trendLabels = []; $trendValues = [];
foreach ($allBlocks as $tb) { $trendLabels[] = 'T' . $tb['term'] . ' ' . $tb['exam_label']; $trendValues[] = $tb['avg']; }

/* Small renderer: the filter bar shared by My Results + Grade Performance */
function sdFilterForm($sectionName, $years, $sel_year, $sel_term, $sel_exam_n, $EXAM_TYPES, $allLabel) {
    ob_start(); ?>
  <form method="GET" class="filter-form" id="sdFilter">
    <input type="hidden" name="section" value="<?= htmlspecialchars($sectionName) ?>">
    <div class="filter-field">
      <label>Year</label>
      <select name="year">
        <?php foreach ($years as $y): ?><option <?= (int)$y === (int)$sel_year ? 'selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="filter-field">
      <label>Term</label>
      <select name="term">
        <option value=""><?= $allLabel ?> Terms</option>
        <?php foreach (['Term 1','Term 2','Term 3'] as $t): ?><option <?= $t === $sel_term ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="filter-field">
      <label>Exam</label>
      <select name="exam_type">
        <option value=""><?= $allLabel ?> Exams</option>
        <?php foreach ($EXAM_TYPES as $code => $et): ?>
          <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= sdExam($code) === $sel_exam_n ? 'selected' : '' ?>><?= htmlspecialchars($et['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn-filter"><i class="fa-solid fa-rotate"></i> Load</button>
  </form>
<?php return ob_get_clean(); }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $name ?> — Student Portal · Stephen Kanja School</title>
<script>
  /* Apply saved theme before first paint (shared with the staff dashboards) */
  try { var th = localStorage.getItem('hoi-theme'); if (th === 'dark' || th === 'light') document.documentElement.setAttribute('data-theme', th); } catch (e) {}
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
  :root{
    --bg:#f3f5fa;--surface:#ffffff;--surface-2:#f8f9fc;--surface-3:#eef1f7;
    --text:#0f172a;--text-2:#475569;--muted:#8392a5;
    --border:#e4e8f0;--border-2:#eef1f6;--grid-line:rgba(15,23,42,.07);
    --navy:#0b1f3a;--gold:#f0c040;--gold-d:#c8992a;--gold-pale:#fdf3d4;--gold-ink:#7a5a10;
    --green:#16a34a;--green-bg:#dcfce7;--red:#dc2626;--red-bg:#fee2e2;--blue:#2563eb;--blue-bg:#dbeafe;--amber:#d97706;--amber-bg:#fef3c7;--purple:#7c3aed;
    --ee1:#15803d;--ee1-bg:#bbf7d0;--ee2:#16a34a;--ee2-bg:#dcfce7;
    --me1:#123657;--me1-bg:#cfe0f3;--me2:#1a4a7a;--me2-bg:#dce8f5;
    --ae1:#92400e;--ae1-bg:#fde68a;--ae2:#b45309;--ae2-bg:#fef3c7;
    --be1:#7f1d1d;--be1-bg:#fecaca;--be2:#991b1b;--be2-bg:#fee2e2;
    --shadow-sm:0 1px 2px rgba(15,23,42,.05);--shadow:0 6px 24px rgba(15,23,42,.07);--shadow-lg:0 18px 44px rgba(15,23,42,.14);
    --r:14px;--r-lg:18px;--sidebar-w:264px;--topbar-h:64px;
    color-scheme:light;
  }
  [data-theme="dark"]{
    --bg:#0a1120;--surface:#111b30;--surface-2:#0e1729;--surface-3:#16223b;
    --text:#e7ecf5;--text-2:#aebad0;--muted:#7b8aa3;--border:#1f2d47;--border-2:#192740;--grid-line:rgba(255,255,255,.08);
    --gold-pale:rgba(240,192,64,.14);--gold-ink:#f0c040;
    --green:#4ade80;--green-bg:rgba(34,197,94,.16);--red:#f87171;--red-bg:rgba(248,113,113,.16);--blue:#7db3f0;--blue-bg:rgba(96,165,250,.16);--amber:#fbbf24;--amber-bg:rgba(251,191,36,.15);--purple:#a78bfa;
    --ee1:#86efac;--ee1-bg:rgba(34,197,94,.30);--ee2:#4ade80;--ee2-bg:rgba(34,197,94,.18);
    --me1:#a9cdf6;--me1-bg:rgba(96,165,250,.30);--me2:#7db3f0;--me2-bg:rgba(96,165,250,.18);
    --ae1:#fcd34d;--ae1-bg:rgba(251,191,36,.30);--ae2:#fbbf24;--ae2-bg:rgba(251,191,36,.16);
    --be1:#fca5a5;--be1-bg:rgba(248,113,113,.30);--be2:#f87171;--be2-bg:rgba(248,113,113,.16);
    --shadow-sm:0 1px 2px rgba(0,0,0,.4);--shadow:0 6px 24px rgba(0,0,0,.35);--shadow-lg:0 18px 44px rgba(0,0,0,.5);
    color-scheme:dark;
  }
  html{scroll-behavior:smooth}
  body{font-family:'Plus Jakarta Sans',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;font-size:14.5px;line-height:1.6;-webkit-font-smoothing:antialiased;transition:background .25s,color .25s}
  ::-webkit-scrollbar{width:8px;height:8px}::-webkit-scrollbar-thumb{background:rgba(120,135,160,.35);border-radius:99px}
  :focus-visible{outline:2px solid var(--gold);outline-offset:2px;border-radius:6px}
  .skip{position:absolute;left:-999px;top:8px;background:var(--gold);color:#000;padding:8px 14px;border-radius:8px;font-weight:700;z-index:400}
  .skip:focus{left:12px}

  .app{display:grid;grid-template-columns:var(--sidebar-w) minmax(0,1fr);min-height:100vh}

  /* ───────── Sidebar ───────── */
  .sidebar{background:linear-gradient(180deg,var(--navy) 0%,#07152a 100%);color:#b9c4d6;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto;z-index:100}
  .sidebar::-webkit-scrollbar-thumb{background:rgba(255,255,255,.12)}
  .brand{display:flex;align-items:center;gap:12px;padding:20px 22px 18px;border-bottom:1px solid rgba(255,255,255,.07)}
  .brand-mark{width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg,var(--gold),#e0a920);display:flex;align-items:center;justify-content:center;color:#0b1f3a;font-size:19px;flex-shrink:0;box-shadow:0 6px 18px rgba(240,192,64,.35)}
  .brand-name{font-weight:800;font-size:.92rem;color:#fff;line-height:1.15;letter-spacing:.2px}
  .brand-name span{color:var(--gold)}
  .brand-tag{font-size:.68rem;color:#7f8fa8;letter-spacing:1.4px;text-transform:uppercase;margin-top:2px}
  .me-card{margin:16px 14px 4px;padding:14px;border-radius:14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;gap:12px}
  .me-av{width:44px;height:44px;border-radius:12px;background:var(--gold);color:#0b1f3a;font-weight:800;font-size:.95rem;display:flex;align-items:center;justify-content:center;flex-shrink:0}
  .me-card b{display:block;color:#fff;font-size:.86rem;line-height:1.25;word-break:break-word}
  .me-card small{color:#8ea0bb;font-size:.7rem;display:block;margin-top:1px}
  .nav{flex:1;padding:12px 12px 20px}
  .nav-title{padding:10px 12px 6px;font-size:.62rem;font-weight:800;letter-spacing:1.8px;text-transform:uppercase;color:#5f7192}
  .nav a{display:flex;align-items:center;gap:12px;padding:10px 12px;margin:1px 0;color:#b9c4d6;text-decoration:none;font-size:.85rem;font-weight:500;border-radius:10px;transition:.15s}
  .nav a i{width:18px;text-align:center;font-size:14px;color:#7587a6;transition:.15s}
  .nav a:hover{background:rgba(255,255,255,.06);color:#fff}
  .nav a:hover i{color:var(--gold)}
  .nav a.active{background:rgba(240,192,64,.13);color:var(--gold);font-weight:700;box-shadow:inset 3px 0 0 var(--gold)}
  .nav a.active i{color:var(--gold)}
  .side-out{margin:0 12px 12px}
  .side-out a{display:flex;align-items:center;justify-content:center;gap:9px;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.14);color:#cfd9e8;text-decoration:none;font-size:.82rem;font-weight:600;transition:.15s}
  .side-out a:hover{background:rgba(255,255,255,.08);color:#fff}
  .side-foot{padding:14px 22px 18px;border-top:1px solid rgba(255,255,255,.07);font-size:.7rem;color:#5f7192}
  .side-foot strong{color:#aebcd2;font-weight:600}
  .backdrop{display:none;position:fixed;inset:0;background:rgba(3,10,22,.55);backdrop-filter:blur(2px);z-index:99}
  .backdrop.show{display:block}

  /* ───────── Main / topbar ───────── */
  .main{min-width:0;display:flex;flex-direction:column}
  .topbar{position:sticky;top:0;z-index:60;height:var(--topbar-h);display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 32px;background:color-mix(in srgb,var(--surface) 86%,transparent);backdrop-filter:saturate(1.4) blur(12px);border-bottom:1px solid var(--border)}
  .tb-left{display:flex;align-items:center;gap:12px;min-width:0}
  .burger{display:none;background:none;border:1px solid var(--border);width:38px;height:38px;border-radius:10px;cursor:pointer;color:var(--text);align-items:center;justify-content:center}
  .burger:hover{background:var(--surface-3)}
  .tb-title{font-weight:800;font-size:1.02rem;letter-spacing:-.2px;line-height:1.2}
  .tb-title small{display:block;font-weight:500;font-size:.72rem;color:var(--muted)}
  .tb-right{display:flex;align-items:center;gap:8px}
  .icon-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text-2);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;font-size:15px;transition:.15s;text-decoration:none}
  .icon-btn:hover{background:var(--surface-3);color:var(--text)}

  .content{padding:24px 32px 60px;width:100%;max-width:1280px;margin:0 auto;display:flex;flex-direction:column;gap:20px}
  .page-eyebrow{font-size:.7rem;font-weight:800;letter-spacing:1.8px;text-transform:uppercase;color:var(--gold-d)}
  .page-title{font-size:clamp(1.4rem,2.4vw,1.8rem);font-weight:800;letter-spacing:-.5px;line-height:1.2}
  .page-sub{color:var(--muted);font-size:.86rem;margin-top:4px;max-width:720px}

  /* ───────── Hero (home) ───────── */
  .hero{padding:28px 30px;border-radius:var(--r-lg);color:#fff;position:relative;overflow:hidden;background:linear-gradient(120deg,#0b1f3a 0%,#173a66 60%,#1c4a80 100%);box-shadow:var(--shadow);animation:fadeUp .45s ease both}
  .hero::before{content:'';position:absolute;inset:0;background:radial-gradient(520px 260px at 6% 0%,rgba(240,192,64,.28),transparent 70%),radial-gradient(420px 260px at 100% 100%,rgba(34,197,94,.22),transparent 70%);pointer-events:none}
  .hero-inner{position:relative;display:flex;align-items:center;justify-content:space-between;gap:22px;flex-wrap:wrap}
  .hero-eyebrow{font-size:.72rem;letter-spacing:2px;text-transform:uppercase;color:rgba(255,255,255,.6);font-weight:700}
  .hero h1{font-size:clamp(1.35rem,2.6vw,2rem);font-weight:800;letter-spacing:-.5px;line-height:1.2;margin-top:4px}
  .hero h1 em{font-style:normal;color:var(--gold)}
  .hero p{color:rgba(255,255,255,.72);font-size:.86rem;margin-top:6px}
  .hero-meta{display:flex;gap:10px;flex-wrap:wrap}
  .hero-pill{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.16);backdrop-filter:blur(4px);border-radius:14px;padding:11px 18px;min-width:108px}
  .hero-pill small{display:block;font-size:.62rem;letter-spacing:1.3px;text-transform:uppercase;color:rgba(255,255,255,.62);font-weight:700}
  .hero-pill b{display:block;font-size:1.4rem;font-weight:800;line-height:1.25;font-variant-numeric:tabular-nums}

  /* ───────── KPI ───────── */
  .kpi-strip{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px}
  .kpi{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:16px 18px;box-shadow:var(--shadow-sm);position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s;animation:fadeUp .45s ease both;--accent:var(--gold)}
  .kpi:hover{transform:translateY(-3px);box-shadow:var(--shadow)}
  .kpi::after{content:'';position:absolute;right:-26px;top:-26px;width:88px;height:88px;border-radius:50%;background:var(--accent);opacity:.12}
  .kpi .ico{width:36px;height:36px;border-radius:10px;background:color-mix(in srgb,var(--accent) 16%,transparent);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:14px;margin-bottom:10px}
  .kpi-label{font-size:.64rem;font-weight:800;letter-spacing:1.3px;text-transform:uppercase;color:var(--muted)}
  .kpi-val{font-size:1.75rem;font-weight:800;line-height:1.15;letter-spacing:-.7px;margin-top:3px;font-variant-numeric:tabular-nums}
  .kpi-val.sm{font-size:1.35rem}
  .kpi-sub{font-size:.72rem;color:var(--muted);margin-top:4px}

  /* ───────── Cards ───────── */
  .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow-sm);overflow:hidden;animation:fadeUp .45s .05s ease both}
  .card-head{padding:15px 22px;border-bottom:1px solid var(--border-2);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
  .card-head h3{font-size:.76rem;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--text-2);display:flex;align-items:center;gap:9px}
  .card-head h3 i{color:var(--gold-d)}
  .card-body{padding:20px 22px}
  .grid-2{display:grid;grid-template-columns:1.4fr 1fr;gap:18px}
  .grid-2 .card{margin:0}
  .tag{font-size:.66rem;font-weight:800;letter-spacing:.9px;text-transform:uppercase;padding:3px 10px;border-radius:99px;background:var(--gold-pale);color:var(--gold-ink)}
  .section-label{font-size:.7rem;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:12px}
  .section-label::after{content:'';flex:1;height:1px;background:var(--border)}

  /* ───────── Quick access ───────── */
  .nav-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));gap:14px}
  .nav-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:16px 18px;display:flex;align-items:center;gap:14px;text-decoration:none;color:var(--text);position:relative;transition:transform .2s,box-shadow .2s,border-color .2s;box-shadow:var(--shadow-sm)}
  .nav-card:hover{transform:translateY(-3px);box-shadow:var(--shadow);border-color:color-mix(in srgb,var(--accent) 60%,transparent)}
  .nav-card .ni{width:44px;height:44px;border-radius:12px;background:color-mix(in srgb,var(--accent) 15%,transparent);color:var(--accent);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;transition:.2s}
  .nav-card:hover .ni{background:var(--accent);color:#fff}
  .nav-card b{display:block;font-size:.92rem;font-weight:700;line-height:1.25}
  .nav-card span.d{display:block;font-size:.76rem;color:var(--muted);line-height:1.4;margin-top:2px}
  .nav-card .arr{margin-left:auto;font-size:12px;color:var(--muted);opacity:0;transform:translateX(-6px);transition:.2s}
  .nav-card:hover .arr{opacity:1;transform:none;color:var(--accent)}
  .a-res{--accent:#16a34a}.a-grade{--accent:#7c3aed}.a-att{--accent:#0891b2}.a-fee{--accent:#d9a521}.a-not{--accent:#dc2626}.a-tt{--accent:#2563eb}

  /* ───────── Filters ───────── */
  .filter-form{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);padding:16px 20px;box-shadow:var(--shadow-sm);display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;animation:fadeUp .45s ease both}
  .filter-field{display:flex;flex-direction:column;gap:6px;flex:1;min-width:140px}
  .filter-field label{font-size:.64rem;font-weight:800;letter-spacing:1.3px;text-transform:uppercase;color:var(--muted)}
  .filter-field select{appearance:none;width:100%;background:var(--surface-2);border:1.5px solid var(--border);border-radius:10px;padding:0 38px 0 14px;height:42px;font-family:inherit;font-size:.88rem;font-weight:500;color:var(--text);cursor:pointer;transition:.15s;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center}
  .filter-field select:hover{border-color:rgba(37,99,235,.35)}
  .filter-field select:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px rgba(240,192,64,.22)}
  .btn-filter{background:var(--navy);color:#fff;border:none;border-radius:10px;padding:0 22px;height:42px;font-family:inherit;font-size:.84rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:.15s}
  [data-theme="dark"] .btn-filter{background:var(--gold);color:#101826}
  .btn-filter:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(11,31,58,.25)}

  .note{display:flex;align-items:flex-start;gap:10px;border-radius:12px;padding:12px 16px;font-size:.84rem;line-height:1.55;border:1px solid}
  .note i{margin-top:3px;flex-shrink:0}
  .note.info{background:var(--blue-bg);border-color:color-mix(in srgb,var(--blue) 35%,transparent)}
  .note.info i{color:var(--blue)}
  .note.gold{background:var(--gold-pale);border-color:rgba(240,192,64,.45);color:var(--gold-ink)}
  .note.gold i{color:var(--gold-d)}

  /* ───────── Download strip ───────── */
  .dl-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow-sm);padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
  .dl-card h3{font-size:.95rem;font-weight:800;display:flex;align-items:center;gap:9px}
  .dl-card h3 i{color:var(--gold-d)}
  .dl-card p{font-size:.78rem;color:var(--muted);margin-top:2px;max-width:560px}
  .dl-btns{display:flex;gap:9px;flex-wrap:wrap}
  .btn-dl{display:inline-flex;align-items:center;gap:8px;border:none;border-radius:10px;padding:0 18px;height:40px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer;color:#fff;background:var(--navy);transition:.15s}
  [data-theme="dark"] .btn-dl{background:var(--surface-3);border:1px solid var(--border)}
  .btn-dl:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(11,31,58,.28)}
  .btn-dl.green{background:#16a34a !important;border:none !important}
  .btn-dl:disabled{opacity:.6;cursor:wait;transform:none}

  /* ───────── Exam result cards ───────── */
  .exam-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);box-shadow:var(--shadow-sm);overflow:hidden;animation:fadeUp .45s ease both}
  .exam-head{padding:18px 22px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;background:linear-gradient(120deg,var(--navy),#173a66);color:#fff}
  .exam-head h3{font-size:1.02rem;font-weight:800;letter-spacing:-.2px;line-height:1.25}
  .exam-head small{display:block;font-size:.72rem;color:rgba(255,255,255,.65);font-weight:500;margin-top:2px;letter-spacing:.4px}
  .exam-chips{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .chip{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:10px;padding:6px 12px;font-size:.72rem;font-weight:700;color:#fff;display:flex;flex-direction:column;line-height:1.25;min-width:72px}
  .chip small{font-size:.58rem;letter-spacing:1.2px;text-transform:uppercase;color:rgba(255,255,255,.62);margin:0 0 1px;font-weight:700}
  .dl-mini{background:var(--gold);border:none;color:#101826;border-radius:10px;padding:0 14px;height:36px;font-size:.76rem;font-weight:800;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px;transition:.15s}
  .dl-mini:hover{background:#f5cf5e;transform:translateY(-1px)}
  .res-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
  table.res-table{width:100%;min-width:620px;border-collapse:collapse;font-size:.86rem;font-variant-numeric:tabular-nums}
  .res-table thead th{background:var(--surface-2);color:var(--muted);font-size:.64rem;font-weight:800;letter-spacing:1px;text-transform:uppercase;padding:11px 16px;text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
  .res-table th.num,.res-table td.num{text-align:center}
  .res-table td{padding:13px 16px;border-bottom:1px solid var(--border-2);color:var(--text-2);vertical-align:middle}
  .res-table tbody tr:hover td{background:var(--surface-2)}
  .res-table td.unit{font-weight:700;color:var(--muted);font-size:.74rem;letter-spacing:.5px;width:88px}
  .res-table td.sub{font-weight:600;color:var(--text)}
  .mk{display:flex;align-items:center;gap:12px;min-width:150px}
  .mk b{min-width:34px;text-align:right;color:var(--text);font-size:.92rem}
  .mbar{flex:1;height:7px;border-radius:99px;background:var(--surface-3);overflow:hidden}
  .mbar i{display:block;height:100%;border-radius:99px}
  .t-ee{background:var(--green)}.t-me{background:var(--blue)}.t-ae{background:var(--amber)}.t-be{background:var(--red)}
  .delta{font-size:.72rem;font-weight:700;margin-left:6px}
  .delta.up{color:var(--green)}.delta.down{color:var(--red)}.delta.same{color:var(--muted)}
  .res-table tfoot td{background:var(--surface-2);font-weight:700;color:var(--text);border-top:2px solid var(--border);padding:12px 16px}
  .res-table tfoot td.r{text-align:right;font-size:.7rem;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted)}
  .lvl{display:inline-block;min-width:38px;text-align:center;font-size:.7rem;font-weight:800;padding:3px 10px;border-radius:7px;letter-spacing:.3px}
  .lvl.ee1{background:var(--ee1-bg);color:var(--ee1)}.lvl.ee2{background:var(--ee2-bg);color:var(--ee2)}
  .lvl.me1{background:var(--me1-bg);color:var(--me1)}.lvl.me2{background:var(--me2-bg);color:var(--me2)}
  .lvl.ae1{background:var(--ae1-bg);color:var(--ae1)}.lvl.ae2{background:var(--ae2-bg);color:var(--ae2)}
  .lvl.be1{background:var(--be1-bg);color:var(--be1)}.lvl.be2{background:var(--be2-bg);color:var(--be2)}
  .lvl.ee{background:var(--ee2-bg);color:var(--ee2)}.lvl.me{background:var(--me2-bg);color:var(--me2)}
  .lvl.ae{background:var(--ae2-bg);color:var(--ae2)}.lvl.be{background:var(--be2-bg);color:var(--be2)}

  /* ───────── Comparison bars ───────── */
  .compare-row{display:flex;align-items:center;gap:16px;padding:13px 0;border-bottom:1px solid var(--border-2);flex-wrap:wrap}
  .compare-row:last-of-type{border-bottom:none}
  .compare-subject{font-size:.88rem;font-weight:700;min-width:150px}
  .compare-bars{flex:1;min-width:220px;display:flex;flex-direction:column;gap:6px}
  .compare-bar-row{display:flex;align-items:center;gap:9px}
  .compare-bar-lbl{font-size:.66rem;font-weight:700;color:var(--muted);width:30px;text-align:right;text-transform:uppercase;letter-spacing:.4px}
  .compare-bar-wrap{flex:1;height:8px;background:var(--surface-3);border-radius:99px;overflow:hidden}
  .compare-bar-fill{height:100%;border-radius:99px}
  .compare-score{font-size:.8rem;font-weight:800;min-width:38px;text-align:right}
  .legend{display:flex;gap:18px;flex-wrap:wrap;font-size:.74rem;color:var(--muted);margin-top:14px}
  .legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:middle}

  table.data{width:100%;border-collapse:collapse;font-size:.84rem;font-variant-numeric:tabular-nums;min-width:560px}
  table.data th{background:var(--surface-2);color:var(--muted);font-size:.64rem;font-weight:800;letter-spacing:1px;text-transform:uppercase;padding:11px 16px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap}
  table.data td{padding:12px 16px;border-bottom:1px solid var(--border-2);color:var(--text-2);vertical-align:middle}
  table.data tbody tr:hover td{background:var(--surface-2)}
  table.data tbody tr:last-child td{border-bottom:none}
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}

  /* ───────── Attendance / bars ───────── */
  .bar-wrap{height:10px;background:var(--surface-3);border-radius:99px;overflow:hidden}
  .bar-fill{height:100%;border-radius:99px;transition:width .6s ease}

  /* ───────── Notices ───────── */
  .notice-item{border:1px solid var(--border);border-left:4px solid var(--muted);padding:13px 16px;background:var(--surface-2);border-radius:0 12px 12px 0;margin-bottom:10px}
  .notice-item:last-child{margin-bottom:0}
  .notice-item.urgent{border-left-color:var(--red)}.notice-item.info{border-left-color:var(--blue)}.notice-item.event{border-left-color:var(--green)}
  .notice-title{font-size:.9rem;font-weight:700;margin:3px 0 2px}
  .notice-meta{font-size:.72rem;color:var(--muted)}
  .nb-badge-s{display:inline-block;padding:2px 9px;border-radius:99px;font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.8px}
  .badge-urgent{background:var(--red-bg);color:var(--red)}.badge-info{background:var(--blue-bg);color:var(--blue)}.badge-event{background:var(--green-bg);color:var(--green)}
  .tabs{display:flex;gap:6px;flex-wrap:wrap}
  .stab{padding:8px 16px;border-radius:99px;border:1.5px solid var(--border);background:var(--surface);color:var(--text-2);font-size:.8rem;font-weight:700;text-decoration:none;transition:.15s;display:inline-flex;align-items:center;gap:7px}
  .stab:hover{border-color:var(--gold);color:var(--text)}
  .stab.active{background:var(--navy);color:#fff;border-color:var(--navy)}
  [data-theme="dark"] .stab.active{background:var(--gold);color:#101826;border-color:var(--gold)}

  /* ───────── Fees ───────── */
  .balance-banner{border-radius:var(--r-lg);padding:20px 24px;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;background:linear-gradient(120deg,#0b1f3a,#173a66);box-shadow:var(--shadow);position:relative;overflow:hidden}
  .balance-banner::before{content:'';position:absolute;inset:0;background:radial-gradient(420px 220px at 0% 0%,rgba(240,192,64,.25),transparent 70%);pointer-events:none}
  .balance-banner>*{position:relative}
  .balance-label{font-size:.66rem;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:1.5px;font-weight:700}
  .balance-val{font-size:2rem;font-weight:800;color:var(--gold);letter-spacing:-.8px;line-height:1.2}
  .balance-pill{background:var(--gold);color:#101826;padding:8px 16px;border-radius:10px;font-size:.8rem;font-weight:800;display:inline-flex;align-items:center;gap:8px}
  .fee-row{display:flex;align-items:center;justify-content:space-between;padding:14px 0;border-bottom:1px solid var(--border-2);flex-wrap:wrap;gap:10px}
  .fee-row:last-child{border-bottom:none}
  .fee-label{font-size:.92rem;font-weight:700}
  .fee-meta{font-size:.72rem;color:var(--muted)}
  .fee-amounts{display:flex;gap:22px;align-items:center;flex-wrap:wrap}
  .fee-col{text-align:right}
  .fee-col .amt{font-size:.86rem;font-weight:700;font-variant-numeric:tabular-nums}
  .fee-col .lbl{font-size:.6rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-weight:700}

  /* ───────── Timetable ───────── */
  table.tt{width:100%;border-collapse:collapse;font-size:.78rem;min-width:640px}
  table.tt th{background:var(--navy);color:#fff;padding:11px 8px;text-align:center;font-size:.66rem;font-weight:800;letter-spacing:1px;text-transform:uppercase;white-space:nowrap;border:1px solid rgba(255,255,255,.1)}
  [data-theme="dark"] table.tt th{background:#173a5e}
  table.tt td{border:1px solid var(--border-2);padding:10px 6px;text-align:center;vertical-align:middle}
  table.tt tbody tr:nth-child(even) td{background:var(--surface-2)}
  .tt-period{font-weight:700;font-size:.72rem;color:var(--text-2);white-space:nowrap;text-align:left !important;padding-left:12px !important}
  .subj-pill{display:inline-block;padding:4px 11px;border-radius:99px;font-size:.7rem;font-weight:700}
  .tt-teacher{font-size:.66rem;color:var(--muted);margin-top:2px}
  .tt-empty{color:var(--muted);opacity:.5}

  .empty{text-align:center;color:var(--muted);padding:44px 20px;font-size:.9rem;line-height:1.7}
  .empty i{font-size:1.9rem;color:var(--gold-d);display:block;margin-bottom:10px}

  .footer{text-align:center;padding:1.3rem 2rem;border-top:1px solid var(--border);font-size:.74rem;color:var(--muted);background:var(--surface);margin-top:auto}

  @keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
  @media (prefers-reduced-motion:reduce){*,*::before,*::after{animation:none !important;transition:none !important;scroll-behavior:auto !important}}

  /* ───────── Responsive ───────── */
  @media (max-width:1100px){.grid-2{grid-template-columns:1fr}}
  @media (max-width:1024px){
    .app{grid-template-columns:1fr}
    .sidebar{position:fixed;left:-290px;width:272px;transition:left .25s ease;box-shadow:0 0 50px rgba(0,0,0,.55)}
    .sidebar.open{left:0}
    .burger{display:inline-flex}
    .topbar{padding:0 18px}.content{padding:18px 18px 50px}
  }
  @media (max-width:640px){
    .tb-title small{display:none}
    .hero{padding:22px 18px}.hero-pill{min-width:0;flex:1;padding:10px 12px}.hero-pill b{font-size:1.15rem}
    .exam-head{padding:16px 18px}
    .res-table td,.res-table thead th{padding:11px 12px}
    .filter-field{min-width:calc(50% - 7px)}.btn-filter{width:100%;justify-content:center}
    .nav-cards{grid-template-columns:1fr}
  }
  @media print{
    .sidebar,.topbar,.filter-form,.dl-card,.dl-mini,.backdrop,.icon-btn{display:none !important}
    .app{display:block}body{background:#fff}.content{padding:0}.exam-card,.card{box-shadow:none;break-inside:avoid}
  }
</style>
</head>
<body>
<a href="#main-content" class="skip">Skip to content</a>

<div class="app">

<!-- ═══ SIDEBAR ═══ -->
<aside class="sidebar" id="sidebar" aria-label="Student navigation">
  <div class="brand">
    <div class="brand-mark"><i class="fa-solid fa-graduation-cap"></i></div>
    <div>
      <div class="brand-name">Stephen Kanja <span>School</span></div>
      <div class="brand-tag">Student Portal</div>
    </div>
  </div>

  <div class="me-card">
    <div class="me-av"><?= htmlspecialchars($initials) ?></div>
    <div>
      <b><?= $name ?></b>
      <small>Grade <?= htmlspecialchars($grade) ?> &middot; <?= htmlspecialchars($assessRaw) ?></small>
    </div>
  </div>

  <nav class="nav">
    <div class="nav-title">My Portal</div>
    <a href="<?= $url_base ?>section=home"       class="<?= $section==='home'       ? 'active':'' ?>"><i class="fa-solid fa-house"></i> Home</a>
    <a href="<?= $url_base ?>section=results"    class="<?= $section==='results'    ? 'active':'' ?>"><i class="fa-solid fa-chart-pie"></i> My Results</a>
    <a href="<?= $url_base ?>section=grade_perf" class="<?= $section==='grade_perf' ? 'active':'' ?>"><i class="fa-solid fa-users"></i> Grade Performance</a>
    <a href="<?= $url_base ?>section=attendance" class="<?= $section==='attendance' ? 'active':'' ?>"><i class="fa-solid fa-clipboard-check"></i> Attendance</a>
    <a href="<?= $url_base ?>section=fees"       class="<?= $section==='fees'       ? 'active':'' ?>"><i class="fa-solid fa-coins"></i> Fees</a>
    <a href="<?= $url_base ?>section=notices"    class="<?= $section==='notices'    ? 'active':'' ?>"><i class="fa-solid fa-bullhorn"></i> Notices</a>
    <a href="<?= $url_base ?>section=timetable"  class="<?= $section==='timetable'  ? 'active':'' ?>"><i class="fa-solid fa-calendar-days"></i> Timetable</a>
  </nav>

  <div class="side-out"><a href="index.php?logout=1" onclick="return confirmLogout()"><i class="fa-solid fa-right-from-bracket"></i> Log out</a></div>
  <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
</aside>
<div class="backdrop" id="backdrop" onclick="closeSidebar()"></div>

<!-- ═══ MAIN ═══ -->
<div class="main">

  <header class="topbar">
    <div class="tb-left">
      <button class="burger" id="hamburger" type="button" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
      <div class="tb-title"><?= htmlspecialchars($pageTitle) ?><small>Grade <?= htmlspecialchars($grade) ?> &middot; <?= htmlspecialchars($assessRaw) ?></small></div>
    </div>
    <div class="tb-right">
      <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
      <a href="index.php?logout=1" class="icon-btn" onclick="return confirmLogout()" aria-label="Log out" title="Log out"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
  </header>

  <main id="main-content" class="content">

<?php if ($section === 'home'): ?>
  <!-- ══ HOME ══ -->
  <section class="hero" aria-label="Welcome">
    <div class="hero-inner">
      <div>
        <div class="hero-eyebrow">Student Dashboard</div>
        <h1><?= htmlspecialchars($greeting) ?>, <em><?= htmlspecialchars($student['firstName']) ?></em></h1>
        <p>Grade <?= htmlspecialchars($grade) ?> &middot; ID <?= htmlspecialchars($assessRaw) ?> &middot; Stephen Kanja Primary &amp; Junior School</p>
      </div>
      <div class="hero-meta">
        <div class="hero-pill"><small>Latest Avg</small><b><?= $latest ? sdFmt($my_avg) . '%' : '—' ?></b></div>
        <div class="hero-pill"><small>Attendance</small><b><?= $att_pct !== null ? $att_pct.'%' : '—' ?></b></div>
        <div class="hero-pill"><small>Total Paid</small><b>KES <?= number_format($total_paid) ?></b></div>
      </div>
    </div>
  </section>

  <?php if ($hiddenUnreleased > 0): ?>
  <div class="note gold"><i class="fa-solid fa-lock"></i><span>Some of your results haven't been released yet. They'll appear here automatically once the school releases them.</span></div>
  <?php endif; ?>

  <div class="kpi-strip">
    <div class="kpi" style="--accent:#16a34a;animation-delay:.03s">
      <div class="ico"><i class="fa-solid fa-chart-line"></i></div>
      <div class="kpi-label">Latest Average</div>
      <div class="kpi-val"><?= $latest ? sdFmt($my_avg) . '%' : '—' ?></div>
      <div class="kpi-sub"><?= $latest ? htmlspecialchars($latest['exam_label']) . ' · Term ' . $latest['term'] . ' ' . $sel_year : ($hiddenUnreleased > 0 ? 'Results not released yet' : 'No results yet') ?></div>
    </div>
    <?php if ($my_rank > 0): ?>
    <div class="kpi" style="--accent:#7c3aed;animation-delay:.06s">
      <div class="ico"><i class="fa-solid fa-ranking-star"></i></div>
      <div class="kpi-label">Class Position</div>
      <div class="kpi-val"><?= sdOrd($my_rank) ?></div>
      <div class="kpi-sub">Out of <?= $total_students_in_grade ?> students</div>
    </div>
    <?php endif; ?>
    <div class="kpi" style="--accent:#2563eb;animation-delay:.09s">
      <div class="ico"><i class="fa-solid fa-clipboard-check"></i></div>
      <div class="kpi-label">Attendance</div>
      <div class="kpi-val"><?= $att_pct !== null ? $att_pct.'%' : '—' ?></div>
      <div class="kpi-sub"><?= $att_pct !== null ? $att['Present'].' present · '.$att['Absent'].' absent' : 'No records yet' ?></div>
    </div>
    <div class="kpi" style="--accent:#d9a521;animation-delay:.12s">
      <div class="ico"><i class="fa-solid fa-coins"></i></div>
      <div class="kpi-label">Total Paid</div>
      <div class="kpi-val sm">KES <?= number_format($total_paid) ?></div>
      <div class="kpi-sub"><?= count($fees_list) ?> payment record<?= count($fees_list)===1?'':'s' ?></div>
    </div>
  </div>

  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h3><i class="fa-solid fa-chart-line"></i> <?= count($trendValues) >= 2 ? 'My Progress This Year' : 'Latest Result Snapshot' ?></h3><?php if ($latest): ?><span class="tag"><?= (int)$sel_year ?></span><?php endif; ?></div>
      <div class="card-body">
        <?php if (count($trendValues) >= 2): ?>
          <div style="position:relative;height:250px"><canvas id="trendChart"></canvas></div>
        <?php elseif ($latest): ?>
          <?php foreach ($latest['rows'] as $r): $tier = sdTier($r['cls']); ?>
          <div class="compare-row" style="padding:10px 0">
            <div class="compare-subject" style="min-width:120px"><?= htmlspecialchars($r['label']) ?></div>
            <div class="mk" style="flex:1"><div class="mbar"><i class="t-<?= $tier ?>" style="width:<?= min(100, max(0, (float)$r['score'])) ?>%"></i></div><b><?= sdFmt($r['score']) ?></b></div>
            <span class="lvl <?= $r['cls'] ?>"><?= htmlspecialchars($r['code']) ?></span>
          </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="empty"><i class="fa-solid fa-chart-simple"></i>Your results will appear here once an exam has been released.</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h3><i class="fa-solid fa-bell"></i> Latest Notices</h3><a href="<?= $url_base ?>section=notices" style="font-size:.76rem;font-weight:700;color:var(--gold-d);text-decoration:none">View all →</a></div>
      <div class="card-body">
        <?php if (!empty($notices)): foreach (array_slice($notices, 0, 3) as $n): ?>
          <div class="notice-item <?= htmlspecialchars($n['type']) ?>">
            <span class="nb-badge-s badge-<?= htmlspecialchars($n['type']) ?>"><?= ucfirst(htmlspecialchars($n['type'])) ?></span>
            <div class="notice-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="notice-meta"><?= htmlspecialchars($n['posted_by']) ?> · <?= date('d M Y', strtotime($n['created_at'])) ?></div>
          </div>
        <?php endforeach; else: ?>
          <div class="empty" style="padding:30px 10px"><i class="fa-solid fa-inbox"></i>No notices right now.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="section-label">Quick Access</div>
  <div class="nav-cards">
    <a href="<?= $url_base ?>section=results" class="nav-card a-res"><div class="ni"><i class="fa-solid fa-chart-pie"></i></div><div><b>My Results</b><span class="d">Marks &amp; grades per exam and term</span></div><i class="fa-solid fa-arrow-right arr"></i></a>
    <a href="<?= $url_base ?>section=grade_perf" class="nav-card a-grade"><div class="ni"><i class="fa-solid fa-users"></i></div><div><b>Grade Performance</b><span class="d">See how your grade is doing</span></div><i class="fa-solid fa-arrow-right arr"></i></a>
    <a href="<?= $url_base ?>section=attendance" class="nav-card a-att"><div class="ni"><i class="fa-solid fa-clipboard-check"></i></div><div><b>Attendance</b><span class="d">Monthly attendance record</span></div><i class="fa-solid fa-arrow-right arr"></i></a>
    <a href="<?= $url_base ?>section=fees" class="nav-card a-fee"><div class="ni"><i class="fa-solid fa-coins"></i></div><div><b>Fees</b><span class="d">Payment history &amp; records</span></div><i class="fa-solid fa-arrow-right arr"></i></a>
    <a href="<?= $url_base ?>section=notices" class="nav-card a-not"><div class="ni"><i class="fa-solid fa-bullhorn"></i></div><div><b>Notices</b><span class="d">School announcements</span></div><i class="fa-solid fa-arrow-right arr"></i></a>
    <a href="<?= $url_base ?>section=timetable" class="nav-card a-tt"><div class="ni"><i class="fa-solid fa-calendar-days"></i></div><div><b>Timetable</b><span class="d">Weekly class schedule</span></div><i class="fa-solid fa-arrow-right arr"></i></a>
  </div>

  <?php if (count($trendValues) >= 2): ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
  <script>
  (function () {
    var el = document.getElementById('trendChart'); if (!el || !window.Chart) { return; }
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
    Chart.defaults.color = '#7a909e';
    new Chart(el, {
      type: 'line',
      data: { labels: <?= json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
        datasets: [{ label: 'My average (%)', data: <?= json_encode($trendValues) ?>,
          borderColor: '#1f6fb2', backgroundColor: 'rgba(31,111,178,0.14)', borderWidth: 3, pointRadius: 5,
          pointBackgroundColor: '#f0c040', pointBorderColor: '#1f6fb2', tension: 0.35, fill: true }] },
      options: { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return ' ' + c.parsed.y + '%'; } } } },
        scales: { y: { min: 0, max: 100, grid: { color: dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)' }, ticks: { callback: function (v) { return v + '%'; } } },
                  x: { grid: { display: false } } } }
    });
  })();
  </script>
  <?php endif; ?>


<?php elseif ($section === 'results'): ?>
  <!-- ══ MY RESULTS (per exam, per term) ══ -->
  <div>
    <p class="page-eyebrow">Academics</p>
    <h1 class="page-title">My Results</h1>
    <p class="page-sub">Marks and grades for every exam you have sat, grouped by term.</p>
  </div>

  <?= sdFilterForm('results', $years, $sel_year, $sel_term, $sel_exam_n, $EXAM_TYPES, 'All') ?>

  <?php if ($lastView): ?>
  <div class="kpi-strip">
    <div class="kpi" style="--accent:#16a34a"><div class="ico"><i class="fa-solid fa-percent"></i></div><div class="kpi-label">Average</div><div class="kpi-val"><?= sdFmt($lastView['avg']) ?>%</div><div class="kpi-sub"><?= htmlspecialchars($lastView['exam_label']) ?> · Term <?= $lastView['term'] ?></div></div>
    <div class="kpi" style="--accent:#2563eb"><div class="ico"><i class="fa-solid fa-layer-group"></i></div><div class="kpi-label">Overall Level</div><div class="kpi-val"><span class="lvl <?= $lastView['level_cls'] ?>" style="font-size:1rem;padding:5px 14px"><?= htmlspecialchars($lastView['level']) ?></span></div></div>
    <?php if ($lastView['rank'] > 0): ?>
    <div class="kpi" style="--accent:#7c3aed"><div class="ico"><i class="fa-solid fa-ranking-star"></i></div><div class="kpi-label">Class Position</div><div class="kpi-val"><?= $lastView['rank'] ?>/<?= $lastView['class_count'] ?></div></div>
    <?php endif; ?>
    <div class="kpi" style="--accent:#d9a521"><div class="ico"><i class="fa-solid fa-file-lines"></i></div><div class="kpi-label">Exam<?= count($viewBlocks)===1?'':'s' ?> Shown</div><div class="kpi-val"><?= count($viewBlocks) ?></div></div>
  </div>
  <?php endif; ?>

<?php if (!empty($viewBlocks)):
    $dlBlocks = [];
    foreach ($viewBlocks as $b) {
        $dlBlocks[] = [
            'term' => $b['term'], 'exam' => $b['exam_label'], 'grade' => $b['grade'],
            'rows' => array_map(function ($r) { return ['unit' => $r['unit'], 'label' => $r['label'], 'score' => $r['score'], 'code' => $r['code'], 'avg' => $r['class_avg']]; }, $b['rows']),
            'total' => $b['total'], 'outOf' => $b['out_of'], 'avg' => $b['avg'], 'level' => $b['level'],
            'rank' => $b['rank'], 'n' => $b['class_count'],
        ];
    }
    $dlMeta = ['school' => 'Stephen Kanja Primary & Junior School', 'name' => trim($student['firstName'] . ' ' . $student['surname']),
               'assess' => $assessRaw, 'year' => $sel_year, 'generated' => date('jS F Y, g:i A')];
    $dlFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR;
?>
  <div class="dl-card">
    <div>
      <h3><i class="fa-solid fa-download"></i> Download Results</h3>
      <p>Saves the <?= count($viewBlocks) ?> exam<?= count($viewBlocks)===1?'':'s' ?> currently shown (use the filters above to choose). Each exam also has its own PDF button.</p>
    </div>
    <div class="dl-btns">
      <button type="button" class="btn-dl" id="dlPdfAll"><i class="fa-solid fa-file-pdf"></i> Download PDF</button>
      <button type="button" class="btn-dl green" id="dlXlsAll"><i class="fa-solid fa-file-excel"></i> Download Excel</button>
    </div>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
  <script>
  (function () {
    var B = <?= json_encode($dlBlocks, $dlFlags) ?>;
    var M = <?= json_encode($dlMeta, $dlFlags) ?>;
    var NAVY = [11, 31, 58], ACC = [240, 192, 64];
    var COL = {
      'EE1': { bg: [187,247,208], fg: [21,128,61] },  'EE2': { bg: [220,252,231], fg: [22,163,74] },
      'ME1': { bg: [207,224,243], fg: [18,54,87] },   'ME2': { bg: [220,232,245], fg: [26,74,122] },
      'AE1': { bg: [253,230,138], fg: [146,64,14] },  'AE2': { bg: [254,243,199], fg: [180,83,9] },
      'BE1': { bg: [254,202,202], fg: [127,29,29] },  'BE2': { bg: [254,226,226], fg: [153,27,27] },
      'E.E': { bg: [220,252,231], fg: [22,163,74] },  'M.E': { bg: [220,232,245], fg: [26,74,122] },
      'A.E': { bg: [254,243,199], fg: [180,83,9] },   'B.E': { bg: [254,226,226], fg: [153,27,27] }
    };
    function fmt(n) { n = Number(n); return Math.floor(n) === n ? String(n) : n.toFixed(1); }
    function clean(t) { return String(t).replace(/[^A-Za-z0-9]+/g, '_'); }

    function examPage(doc, b, first) {
      if (!first) { doc.addPage(); }
      var W = doc.internal.pageSize.getWidth(), H = doc.internal.pageSize.getHeight();
      doc.setFillColor(NAVY[0], NAVY[1], NAVY[2]); doc.rect(0, 0, W, 74, 'F');
      doc.setFillColor(ACC[0], ACC[1], ACC[2]);    doc.rect(0, 74, W, 3, 'F');
      doc.setTextColor(255, 255, 255); doc.setFont('helvetica', 'bold'); doc.setFontSize(15);
      doc.text(M.school, 40, 30);
      doc.setTextColor(ACC[0], ACC[1], ACC[2]); doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5);
      doc.text('ACADEMIC RESULTS SLIP', 40, 47);
      doc.setTextColor(220, 232, 245); doc.setFontSize(9);
      doc.text('Grade ' + b.grade + '  |  ' + b.exam + '  |  Term ' + b.term + ', ' + M.year, 40, 62);

      var y = 108;
      doc.setTextColor(15, 25, 35); doc.setFont('helvetica', 'bold'); doc.setFontSize(15);
      doc.text(M.name, 40, y);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5); doc.setTextColor(122, 144, 158);
      doc.text('Assessment No: ' + M.assess, 40, y + 16);

      var body = b.rows.map(function (r) { return [r.unit, r.label, fmt(r.score), r.code, r.avg === null ? '-' : fmt(r.avg)]; });
      doc.autoTable({
        startY: y + 34, margin: { left: 40, right: 40 },
        head: [['Unit', 'Subject', 'Marks (/100)', 'Grade', 'Class Avg']], body: body, theme: 'grid',
        styles: { font: 'helvetica', fontSize: 10, cellPadding: 6, textColor: [61, 79, 92] },
        headStyles: { fillColor: NAVY, textColor: [255, 255, 255], fontStyle: 'bold', halign: 'left' },
        columnStyles: { 0: { cellWidth: 70 }, 1: { cellWidth: 170 }, 2: { halign: 'center' }, 3: { halign: 'center' }, 4: { halign: 'center' } },
        didParseCell: function (d) {
          if (d.section === 'body' && d.column.index === 3) {
            var c = COL[d.cell.raw];
            if (c) { d.cell.styles.fillColor = c.bg; d.cell.styles.textColor = c.fg; d.cell.styles.fontStyle = 'bold'; }
          }
        }
      });
      var fy = doc.lastAutoTable.finalY + 24;
      doc.setFont('helvetica', 'bold'); doc.setFontSize(10.5); doc.setTextColor(15, 25, 35);
      doc.text('Total Marks: ' + fmt(b.total) + ' / ' + b.outOf, 40, fy);
      doc.text('Average: ' + fmt(b.avg) + '%    Level: ' + b.level, 40, fy + 16);
      if (b.rank > 0) { doc.text('Position in class: ' + b.rank + ' of ' + b.n, 40, fy + 32); }

      doc.setFont('helvetica', 'normal'); doc.setFontSize(8); doc.setTextColor(122, 144, 158);
      doc.text('Generated on ' + M.generated, 40, H - 24);
      doc.text('Stephen Kanja School - Student Portal', W - 40, H - 24, { align: 'right' });
    }

    function pdf(list, fname) {
      var doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4' });
      list.forEach(function (b, i) { examPage(doc, b, i === 0); });
      doc.save(fname);
    }

    function excel(list, fname) {
      var aoa = [[M.school], ['Learner: ' + M.name + '  |  Assessment No: ' + M.assess + '  |  Year ' + M.year], [],
                 ['Term', 'Exam', 'Unit', 'Subject', 'Marks', 'Grade', 'Class Avg']];
      list.forEach(function (b) {
        b.rows.forEach(function (r) { aoa.push(['Term ' + b.term, b.exam, r.unit, r.label, r.score, r.code, r.avg === null ? '' : r.avg]); });
        aoa.push(['Term ' + b.term, b.exam, '', 'TOTAL (/' + b.outOf + ')', b.total, b.level, '']);
        aoa.push(['', '', '', 'Average', b.avg, '', '']);
        if (b.rank > 0) { aoa.push(['', '', '', 'Position', b.rank + ' of ' + b.n, '', '']); }
        aoa.push([]);
      });
      var ws = XLSX.utils.aoa_to_sheet(aoa);
      ws['!cols'] = [{ wch: 9 }, { wch: 14 }, { wch: 10 }, { wch: 26 }, { wch: 12 }, { wch: 8 }, { wch: 10 }];
      var wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, 'Results');
      XLSX.writeFile(wb, fname);
    }

    function guard(btn, fn) {
      var html = btn.innerHTML; btn.disabled = true; btn.innerHTML = 'Preparing...';
      try { fn(); } catch (e) { console.error(e); alert('Sorry, the file could not be generated.'); }
      finally { btn.disabled = false; btn.innerHTML = html; }
    }
    var base = 'Results_' + clean(M.assess) + '_' + M.year;
    document.getElementById('dlPdfAll').addEventListener('click', function () { var t = this; guard(t, function () { pdf(B, base + '.pdf'); }); });
    document.getElementById('dlXlsAll').addEventListener('click', function () { var t = this; guard(t, function () { excel(B, base + '.xlsx'); }); });
    [].slice.call(document.querySelectorAll('.dl-mini')).forEach(function (btn) {
      btn.addEventListener('click', function () {
        var b = B[parseInt(btn.getAttribute('data-dl'), 10)];
        if (b) { pdf([b], base + '_Term' + b.term + '_' + clean(b.exam) + '.pdf'); }
      });
    });
  })();
  </script>
<?php endif; ?>

<?php if (!empty($viewBlocks)): ?>
  <?php foreach ($viewBlocks as $bi => $b): ?>
  <article class="exam-card">
    <div class="exam-head">
      <div>
        <h3>Term <?= $b['term'] ?> &middot; <?= htmlspecialchars($b['exam_label']) ?> &middot; <?= (int)$sel_year ?></h3>
        <small>GRADE <?= htmlspecialchars($b['grade']) ?></small>
      </div>
      <div class="exam-chips">
        <div class="chip"><small>Average</small><?= sdFmt($b['avg']) ?>%</div>
        <div class="chip"><small>Level</small><?= htmlspecialchars($b['level']) ?></div>
        <?php if ($b['rank'] > 0): ?><div class="chip"><small>Position</small><?= $b['rank'] ?> of <?= $b['class_count'] ?></div><?php endif; ?>
        <button type="button" class="dl-mini" data-dl="<?= $bi ?>" title="Download this exam (PDF)"><i class="fa-solid fa-download"></i> PDF</button>
      </div>
    </div>
    <div class="res-wrap">
      <table class="res-table">
        <thead>
          <tr><th>Unit</th><th>Subject</th><th>Marks</th><th class="num">Grade</th><th class="num">Class Avg</th></tr>
        </thead>
        <tbody>
        <?php foreach ($b['rows'] as $r):
            $tier = sdTier($r['cls']);
            $ca   = $r['class_avg'];
            $diff = $ca !== null ? round((float)$r['score'] - (float)$ca, 1) : null;
        ?>
          <tr>
            <td class="unit"><?= htmlspecialchars($r['unit']) ?></td>
            <td class="sub"><?= htmlspecialchars($r['label']) ?></td>
            <td><div class="mk"><b><?= sdFmt($r['score']) ?></b><div class="mbar"><i class="t-<?= $tier ?>" style="width:<?= min(100, max(0, (float)$r['score'])) ?>%"></i></div></div></td>
            <td class="num"><span class="lvl <?= $r['cls'] ?>" title="<?= htmlspecialchars($r['blabel']) ?>"><?= htmlspecialchars($r['code']) ?></span></td>
            <td class="num"><?= $ca !== null ? sdFmt($ca) : '—' ?><?php if ($diff !== null): ?><span class="delta <?= $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'same') ?>"><?= $diff > 0 ? '▲ +' . sdFmt($diff) : ($diff < 0 ? '▼ ' . sdFmt($diff) : '—') ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><td></td><td class="r">Total</td><td><?= sdFmt($b['total']) ?> / <?= $b['out_of'] ?></td><td class="num"><span class="lvl <?= $b['level_cls'] ?>"><?= htmlspecialchars($b['level']) ?></span></td><td class="num"><?= sdFmt($b['avg']) ?>% avg</td></tr>
        </tfoot>
      </table>
    </div>
  </article>
  <?php endforeach; ?>
<?php else: ?>
  <div class="card"><div class="empty"><i class="fa-solid <?= $hiddenUnreleased > 0 ? 'fa-lock' : 'fa-inbox' ?>"></i><?= $hiddenUnreleased > 0 ? 'Your results have not been released yet. They will appear here once the school releases them.<br>' : '' ?>No results found for <?= $sel_term !== '' ? htmlspecialchars($sel_term) : 'any term' ?><?= $sel_exam_n !== '' ? ' · ' . htmlspecialchars($EXAM_TYPES[$sel_exam]['label'] ?? '') : '' ?> in <?= (int)$sel_year ?>.</div></div>
<?php endif; ?>


<?php elseif ($section === 'grade_perf'): ?>
  <!-- ══ GRADE PERFORMANCE (for one exam) ══ -->
  <div>
    <p class="page-eyebrow">Academics</p>
    <h1 class="page-title">Grade <?= htmlspecialchars($grade) ?> Performance</h1>
    <p class="page-sub">Pick a term and exam to compare yourself with the class. Leave both on “Latest” for your most recent exam.</p>
  </div>

  <?= sdFilterForm('grade_perf', $years, $sel_year, $sel_term, $sel_exam_n, $EXAM_TYPES, 'Latest') ?>

  <?php if ($lastView): ?>
  <div class="note info"><i class="fa-solid fa-circle-info"></i><span>Showing: Term <?= $lastView['term'] ?> &middot; <?= htmlspecialchars($lastView['exam_label']) ?> &middot; <?= (int)$sel_year ?></span></div>

  <div class="kpi-strip">
    <div class="kpi" style="--accent:#16a34a"><div class="ico"><i class="fa-solid fa-percent"></i></div><div class="kpi-label">My Average</div><div class="kpi-val"><?= sdFmt($lastView['avg']) ?>%</div></div>
    <div class="kpi" style="--accent:#7c3aed"><div class="ico"><i class="fa-solid fa-ranking-star"></i></div><div class="kpi-label">My Position</div><div class="kpi-val"><?= $lastView['rank'] > 0 ? $lastView['rank'] : '—' ?></div></div>
    <div class="kpi" style="--accent:#2563eb"><div class="ico"><i class="fa-solid fa-users"></i></div><div class="kpi-label">Students Assessed</div><div class="kpi-val"><?= $lastView['class_count'] ?></div></div>
  </div>

  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-chart-bar"></i> Subject Comparison — Me vs Grade</h3></div>
    <div class="card-body">
      <?php foreach ($lastView['rows'] as $r):
        $my = (float)$r['score']; $ga = (float)($r['class_avg'] ?? 0); $gt = (float)($r['class_top'] ?? 0);
      ?>
      <div class="compare-row">
        <div class="compare-subject"><?= htmlspecialchars($r['label']) ?></div>
        <div class="compare-bars">
          <div class="compare-bar-row"><span class="compare-bar-lbl">Me</span>
            <div class="compare-bar-wrap"><div class="compare-bar-fill" style="width:<?= min(100,$my) ?>%;background:#f0c040"></div></div>
            <span class="compare-score" style="color:var(--gold-d)"><?= sdFmt($my) ?></span></div>
          <div class="compare-bar-row"><span class="compare-bar-lbl">Avg</span>
            <div class="compare-bar-wrap"><div class="compare-bar-fill" style="width:<?= min(100,$ga) ?>%;background:#2563eb"></div></div>
            <span class="compare-score" style="color:var(--blue)"><?= sdFmt($ga) ?></span></div>
          <div class="compare-bar-row"><span class="compare-bar-lbl">Top</span>
            <div class="compare-bar-wrap"><div class="compare-bar-fill" style="width:<?= min(100,$gt) ?>%;background:#16a34a"></div></div>
            <span class="compare-score" style="color:var(--green)"><?= sdFmt($gt) ?></span></div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="legend">
        <span><i style="background:#f0c040"></i>Me</span>
        <span><i style="background:#2563eb"></i>Grade Average</span>
        <span><i style="background:#16a34a"></i>Top Score</span>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-table"></i> Grade Subject Summary</h3></div>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th>Subject</th><th>Grade Avg</th><th>Top Score</th><th>Lowest</th><th>Students</th><th>My Score</th></tr></thead>
        <tbody>
          <?php foreach ($lastView['rows'] as $r): ?>
          <tr>
            <td style="font-weight:700;color:var(--text)"><?= htmlspecialchars($r['label']) ?></td>
            <td><?= sdFmt($r['class_avg'] ?? 0) ?></td>
            <td style="color:var(--green);font-weight:700"><?= sdFmt($r['class_top'] ?? 0) ?></td>
            <td style="color:var(--red)"><?= sdFmt($r['class_low'] ?? 0) ?></td>
            <td><?= (int)$r['class_n'] ?></td>
            <td style="font-weight:700;color:var(--text)"><?= sdFmt($r['score']) ?> <span class="lvl <?= $r['cls'] ?>"><?= htmlspecialchars($r['code']) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php else: ?>
    <div class="card"><div class="empty"><i class="fa-solid fa-chart-bar"></i>No grade performance data yet for the selected term/exam in <?= (int)$sel_year ?>.</div></div>
  <?php endif; ?>


<?php elseif ($section === 'attendance'): ?>
  <!-- ══ ATTENDANCE ══ -->
  <div>
    <p class="page-eyebrow">My Record</p>
    <h1 class="page-title">My Attendance</h1>
    <p class="page-sub">Your attendance for <?= date('F Y') ?>.</p>
  </div>

  <?php if ($att_total === 0): ?>
    <div class="note info"><i class="fa-solid fa-circle-info"></i><span>No attendance records have been entered yet. Once your class teacher starts logging attendance, it will appear here automatically.</span></div>
  <?php endif; ?>

  <div class="kpi-strip">
    <div class="kpi" style="--accent:#16a34a"><div class="ico"><i class="fa-solid fa-circle-check"></i></div><div class="kpi-label">Present</div><div class="kpi-val"><?= $att['Present'] ?></div></div>
    <div class="kpi" style="--accent:#dc2626"><div class="ico"><i class="fa-solid fa-circle-xmark"></i></div><div class="kpi-label">Absent</div><div class="kpi-val"><?= $att['Absent'] ?></div></div>
    <div class="kpi" style="--accent:#d97706"><div class="ico"><i class="fa-solid fa-clock"></i></div><div class="kpi-label">Late</div><div class="kpi-val"><?= $att['Late'] ?></div></div>
    <div class="kpi" style="--accent:#2563eb"><div class="ico"><i class="fa-solid fa-percent"></i></div><div class="kpi-label">This Month</div><div class="kpi-val"><?= $att_pct !== null ? $att_pct.'%' : '—' ?></div></div>
  </div>

  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-calendar-check"></i> Attendance Record — <?= date('F Y') ?></h3></div>
    <div class="card-body">
      <?php if ($att_pct !== null): ?>
      <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:.84rem">
          <span style="font-weight:600">Attendance rate</span>
          <span style="font-weight:800;color:<?= $att_pct>=80?'var(--green)':($att_pct>=60?'var(--amber)':'var(--red)') ?>"><?= $att_pct ?>%</span>
        </div>
        <div class="bar-wrap">
          <div class="bar-fill" style="width:<?= $att_pct ?>%;background:<?= $att_pct>=80?'#16a34a':($att_pct>=60?'#d97706':'#dc2626') ?>"></div>
        </div>
        <?php if ($att_pct < 80): ?>
          <p style="font-size:.8rem;color:var(--red);margin-top:10px"><i class="fa-solid fa-triangle-exclamation"></i> Attendance below 80% — please discuss with your class teacher.</p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($att_total > 0): ?>
        <p style="font-size:.82rem;color:var(--muted)">Daily attendance log will appear here once more records are added.</p>
      <?php else: ?>
        <div class="empty" style="padding:24px 0"><i class="fa-solid fa-inbox"></i>No attendance records found.</div>
      <?php endif; ?>
    </div>
  </div>


<?php elseif ($section === 'fees'): ?>
  <!-- ══ FEES ══ -->
  <div>
    <p class="page-eyebrow">My Record</p>
    <h1 class="page-title">Fees</h1>
  </div>

  <div class="note info">
    <i class="fa-solid fa-circle-info"></i>
    <span>This page shows payments on record. There is currently no "amount due" figure stored in the system, so an outstanding balance cannot be calculated yet — talk to the school office if you'd like that added.</span>
  </div>

  <div class="balance-banner">
    <div>
      <div class="balance-label">Total Paid (All Records)</div>
      <div class="balance-val">KES <?= number_format($total_paid, 2) ?></div>
      <div style="font-size:.76rem;color:rgba(255,255,255,.65)"><?= count($fees_list) ?> payment record<?= count($fees_list)===1?'':'s' ?> on file</div>
    </div>
    <div class="balance-pill"><i class="fa-solid fa-receipt"></i> Payment History</div>
  </div>

  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-user"></i> My Payment Records</h3></div>
    <div class="card-body">
      <?php if (!empty($fees_list)): ?>
        <?php foreach ($fees_list as $f): ?>
        <div class="fee-row">
          <div>
            <div class="fee-label"><?= htmlspecialchars($f['Term']) ?> <?= htmlspecialchars($f['Year']) ?></div>
            <div class="fee-meta"><?= $f['payment_date'] && $f['payment_date'] !== '0000-00-00 00:00:00' ? 'Recorded: '.date('d M Y', strtotime($f['payment_date'])) : 'No date on file' ?></div>
          </div>
          <div class="fee-amounts">
            <div class="fee-col"><div class="amt">KES <?= number_format($f['Fee'],2) ?></div><div class="lbl">Fee</div></div>
            <div class="fee-col"><div class="amt">KES <?= number_format($f['AssesmentFee'],2) ?></div><div class="lbl">Assessment</div></div>
            <div class="fee-col"><div class="amt">KES <?= number_format($f['Activity'],2) ?></div><div class="lbl">Activity</div></div>
            <div class="fee-col"><div class="amt">KES <?= number_format($f['other'],2) ?></div><div class="lbl">Other</div></div>
            <div class="fee-col"><div class="amt" style="color:var(--green)">KES <?= number_format($f['row_total'],2) ?></div><div class="lbl">Total</div></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty"><i class="fa-solid fa-receipt"></i>No payment records found.</div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-users"></i> Average Payments — Grade <?= htmlspecialchars($grade) ?></h3></div>
    <div class="card-body">
      <?php if (!empty($grade_fees)): ?>
        <?php foreach ($grade_fees as $gf): ?>
        <div class="fee-row">
          <div>
            <div class="fee-label"><?= htmlspecialchars($gf['Term']) ?> <?= htmlspecialchars($gf['Year']) ?></div>
            <div class="fee-meta"><?= $gf['record_count'] ?> record<?= $gf['record_count']==1?'':'s' ?></div>
          </div>
          <div class="fee-col">
            <div class="amt">KES <?= number_format($gf['avg_paid'],2) ?></div>
            <div class="lbl">Avg Per Student</div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty"><i class="fa-solid fa-receipt"></i>No fee records found for Grade <?= htmlspecialchars($grade) ?>.</div>
      <?php endif; ?>
    </div>
  </div>


<?php elseif ($section === 'notices'): ?>
  <!-- ══ NOTICES ══ -->
  <div>
    <p class="page-eyebrow">School Life</p>
    <h1 class="page-title">School Notices</h1>
  </div>

  <div class="tabs">
    <a href="<?= $url_base ?>section=notices" class="stab <?= $nfilter===''?'active':'' ?>">All</a>
    <a href="<?= $url_base ?>section=notices&ntype=urgent" class="stab <?= $nfilter==='urgent'?'active':'' ?>"><i class="fa-solid fa-circle-exclamation"></i> Urgent</a>
    <a href="<?= $url_base ?>section=notices&ntype=info"   class="stab <?= $nfilter==='info'?'active':'' ?>"><i class="fa-solid fa-circle-info"></i> Info</a>
    <a href="<?= $url_base ?>section=notices&ntype=event"  class="stab <?= $nfilter==='event'?'active':'' ?>"><i class="fa-solid fa-calendar-check"></i> Events</a>
  </div>

  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-list"></i> Notices Board</h3></div>
    <div class="card-body">
      <?php if (!empty($all_notices)): ?>
        <?php foreach ($all_notices as $n): ?>
          <div class="notice-item <?= htmlspecialchars($n['type']) ?>">
            <span class="nb-badge-s badge-<?= htmlspecialchars($n['type']) ?>"><?= ucfirst(htmlspecialchars($n['type'])) ?></span>
            <div class="notice-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="notice-meta">
              <i class="fa-solid fa-user" style="font-size:9px"></i> <?= htmlspecialchars($n['posted_by']) ?>
              &middot; <?= date('d M Y, g:i A', strtotime($n['created_at'])) ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty"><i class="fa-solid fa-inbox"></i>No notices found.</div>
      <?php endif; ?>
    </div>
  </div>


<?php elseif ($section === 'timetable'): ?>
  <!-- ══ TIMETABLE ══ -->
  <div>
    <p class="page-eyebrow">School Life</p>
    <h1 class="page-title">My Timetable — Grade <?= htmlspecialchars($grade) ?></h1>
  </div>

  <div class="card">
    <div class="card-head"><h3><i class="fa-solid fa-calendar-days"></i> Weekly Schedule</h3></div>
    <div class="table-wrap">
      <?php if (!empty($tt_grid)): ?>
      <table class="tt">
        <thead>
          <tr>
            <th style="text-align:left;padding-left:12px">Time</th>
            <?php foreach ($days as $d): ?><th><?= $d ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($periods as $period): ?>
          <tr>
            <td class="tt-period"><?= htmlspecialchars($period) ?></td>
            <?php foreach ($days as $day): ?>
            <td>
              <?php if (isset($tt_grid[$day][$period])):
                $entry = $tt_grid[$day][$period];
                $subj  = $entry['subject'];
                $colors = $subject_colors[$subj] ?? ['#f3f4f6','#374151'];
              ?>
                <span class="subj-pill" style="background:<?= $colors[0] ?>;color:<?= $colors[1] ?>"><?= htmlspecialchars($subj) ?></span>
                <?php if (!empty($entry['teacher_name'])): ?><div class="tt-teacher"><?= htmlspecialchars($entry['teacher_name']) ?></div><?php endif; ?>
              <?php else: ?>
                <span class="tt-empty">—</span>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
        <div class="empty"><i class="fa-solid fa-calendar-xmark"></i>No timetable set for Grade <?= htmlspecialchars($grade) ?> yet.</div>
      <?php endif; ?>
    </div>
  </div>

<?php endif; ?>

  </main>

  <div class="footer">&copy; Designed by <strong>Kelvin Mutinda</strong> 2026. All rights reserved.</div>
</div>
</div>

<script>
/* Sidebar */
var hamburger = document.getElementById('hamburger');
var sidebar   = document.getElementById('sidebar');
var backdrop  = document.getElementById('backdrop');
function openSidebar(){ sidebar.classList.add('open'); backdrop.classList.add('show'); hamburger.innerHTML = '<i class="fa-solid fa-xmark"></i>'; document.body.style.overflow = 'hidden'; }
function closeSidebar(){ sidebar.classList.remove('open'); backdrop.classList.remove('show'); hamburger.innerHTML = '<i class="fa-solid fa-bars"></i>'; document.body.style.overflow = ''; }
hamburger.addEventListener('click', function () { sidebar.classList.contains('open') ? closeSidebar() : openSidebar(); });
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSidebar(); });
function confirmLogout(){ return confirm('Are you sure you want to log out?'); }

/* Theme (same key as the staff dashboards) */
function syncThemeIcon(){
  var dark = document.documentElement.getAttribute('data-theme') === 'dark';
  document.getElementById('themeBtn').innerHTML = dark ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
}
function toggleTheme(){
  var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  try { localStorage.setItem('hoi-theme', next); } catch (e) {}
  syncThemeIcon();
  if (document.getElementById('trendChart')) { window.location.reload(); }   /* repaint the chart in the new colours */
}
syncThemeIcon();

/* Filters apply as soon as a dropdown changes */
(function(){
  var f = document.getElementById('sdFilter');
  if (!f) { return; }
  [].slice.call(f.querySelectorAll('select')).forEach(function (s) { s.addEventListener('change', function () { f.submit(); }); });
})();
</script>
</body>
</html>