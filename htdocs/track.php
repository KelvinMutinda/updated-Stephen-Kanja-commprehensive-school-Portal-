<?php
// ============================================================
// track.php — Performance Tracker (individual / grade / school)
// ============================================================
     require_once __DIR__ . '/page_guard.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include('conn.php');
require_once 'add-exam-type.php';

$isHoi     = (($_SESSION['account_type'] ?? '') === 'hoi');
$homeLink  = $isHoi ? 'Hoi.php' : 'teacherpanel.php';
$roleLabel = $isHoi ? 'Head of Institution' : 'Teacher Panel';
$roleInit  = $isHoi ? 'HI' : 'TR';

/* Exam types: the same list Upload Results / View Results use (built-in + custom) */
$EXAM_TYPES = getExamTypes($conn); // code => ['label' => ..., 'custom' => bool]

$subjects     = ['math','eng','kisw','sst','scie','ca','agri','re','pretec'];
$subjectNames = ['Mathematics','English','Kiswahili','Social Studies','Science','Creative Arts','Agriculture','RE','Pre-Tech'];
$short        = ['Maths','Eng','Kisw','SST','Sci','CA','Agri','RE','PreTec'];

function bandClass($v)  { return $v>74?'aw-ee':($v>49?'aw-me':($v>25?'aw-ae':'aw-be')); }
function bandKey($v)    { return $v>74?'ee':($v>49?'me':($v>25?'ae':'be')); }
function bandLabel($v)  { return $v>74?'E.E':($v>49?'M.E':($v>25?'A.E':'B.E')); }
function bandColor($v)  { return $v>74?'#059669':($v>49?'#2563eb':($v>25?'#d97706':'#dc2626')); }

/* Display label for any stored exam_type (built-in or custom) */
function examDisp($e) {
    global $EXAM_TYPES;
    if (isset($EXAM_TYPES[$e])) return $EXAM_TYPES[$e]['label'];
    $c = resolveExamCode((string)$e, $EXAM_TYPES);
    if ($c !== null && isset($EXAM_TYPES[$c])) return $EXAM_TYPES[$c]['label'];
    return ucfirst((string)$e);
}

/* Chronological position of an exam inside a term: built-ins first, then custom
   types in the order they appear in the Upload Results dropdown. */
function normExamKey($e) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$e)); }
function examSeqIndex($e) {
    global $EXAM_TYPES;
    static $seq = null;
    if ($seq === null) {
        $seq = ['opener', 'midterm', 'endterm'];
        foreach (array_keys($EXAM_TYPES) as $c) {
            $n = normExamKey($c);
            if (!in_array($n, $seq, true)) $seq[] = $n;
        }
    }
    $i = array_search(normExamKey($e), $seq, true);
    return $i === false ? count($seq) : $i;
}
/* Comparator: year, then term, then exam order */
function cmpExamChrono($a, $b) {
    if ((int)$a['year'] !== (int)$b['year']) return (int)$a['year'] <=> (int)$b['year'];
    if ((int)$a['term'] !== (int)$b['term']) return (int)$a['term'] <=> (int)$b['term'];
    return examSeqIndex($a['exam_type']) <=> examSeqIndex($b['exam_type']);
}

/* vs-average helper: formats a signed percentage-point delta with the right CSS class */
function vsAvgFmt($diff) {
    if ($diff === null) return ['cls'=>'vs-flat', 'txt'=>'—'];
    if ($diff > 0)  return ['cls'=>'vs-pos',  'txt'=>'+'.number_format($diff,1).'%'];
    if ($diff < 0)  return ['cls'=>'vs-neg',  'txt'=>number_format($diff,1).'%'];
    return ['cls'=>'vs-flat', 'txt'=>'0%'];
}

/* ── School-wide stats (for the top strip) ── */
$schoolStats = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT COUNT(DISTINCT CONCAT(grade,term,exam_type,year)) AS exams,
            COUNT(*) AS records,
            AVG((math+eng+kisw+sst+scie+ca+agri+re+pretec)/9) AS school_mean
     FROM exam2"));
$totalStudents = (int)(mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM Student"))['c']??0);

/* ── Grade performance (for school tab) ── */
$gradePerf = [];
$gpRes = mysqli_query($conn,
    "SELECT grade, term, exam_type, year,
            AVG((math+eng+kisw+sst+scie+ca+agri+re+pretec)/9) AS mean,
            COUNT(*) AS cnt
     FROM exam2 GROUP BY grade,term,exam_type,year");
while ($r = mysqli_fetch_assoc($gpRes)) $gradePerf[] = $r;
/* sort by grade, then real exam chronology (so custom exams count as "latest" correctly) */
usort($gradePerf, function($a, $b) {
    if ((int)$a['grade'] !== (int)$b['grade']) return (int)$a['grade'] <=> (int)$b['grade'];
    return cmpExamChrono($a, $b);
});

/* ── Latest mean per grade ── */
$latestGrade = [];
foreach ($gradePerf as $p) $latestGrade[$p['grade']] = $p;

/* ── Overall band distribution ── */
$bandAll = ['ee'=>0,'me'=>0,'ae'=>0,'be'=>0];
$bRes = mysqli_query($conn,"SELECT (math+eng+kisw+sst+scie+ca+agri+re+pretec)/9 AS m FROM exam2");
while ($b = mysqli_fetch_assoc($bRes)) {
    $s=(float)$b['m'];
    if($s>74)$bandAll['ee']++;elseif($s>49)$bandAll['me']++;elseif($s>25)$bandAll['ae']++;else$bandAll['be']++;
}
$bandTotal = array_sum($bandAll);

/* ── Subject school means (the "vs School Avg" benchmark on the Grade tab) ── */
$schoolSubjMeans = [];
foreach ($subjects as $s) {
    $r = mysqli_fetch_assoc(mysqli_query($conn,"SELECT AVG($s) AS m FROM exam2"));
    $schoolSubjMeans[$s] = round((float)($r['m']??0),1);
}

/* ── Grade rankings (latest exam per grade) ── */
$gradeRankData = [];
for ($g=1;$g<=9;$g++) {
    $m = isset($latestGrade[$g]) ? round((float)$latestGrade[$g]['mean'],1) : 0;
    $gradeRankData[] = ['grade'=>$g,'mean'=>$m];
}
usort($gradeRankData, fn($a,$b)=>$b['mean']<=>$a['mean']);

/* ── Individual learner search ── */
$learner      = null;
$learnerExams = [];
$searchError  = '';
$searchAssess = trim($_GET['assess'] ?? '');
$searchName   = trim($_GET['sname']  ?? '');
$searchGrade  = trim($_GET['sgrade'] ?? '');
$latestExam = null; $overallMean = 0; $bestRank = 0; $delta = 0;

/* per-subject "vs Grade Avg" benchmark for the learner's most recent exam session */
$gradeSubjAvgForLatest = [];

if ($searchAssess || $searchName) {
    $where = [];
    if ($searchAssess) $where[] = "Assesment = '".mysqli_real_escape_string($conn,$searchAssess)."'";
    if ($searchName)   $where[] = "(firstName LIKE '%".mysqli_real_escape_string($conn,$searchName)."%' OR surname LIKE '%".mysqli_real_escape_string($conn,$searchName)."%')";
    if ($searchGrade)  $where[] = "Grade = '".mysqli_real_escape_string($conn,$searchGrade)."'";
    $wStr = implode(' AND ',$where);
    $learner = mysqli_fetch_assoc(mysqli_query($conn,"SELECT * FROM Student WHERE $wStr LIMIT 1"));
    if (!$learner) $searchError = 'No learner found matching your search.';

    if ($learner) {
        $assess = mysqli_real_escape_string($conn, $learner['Assesment']);
        // Student column is "Grade" — read it case-safely
        $grade  = mysqli_real_escape_string($conn, (string)($learner['Grade'] ?? $learner['grade'] ?? ''));
        /* all exams for this learner (built-in and custom exam types) */
        $eRes = mysqli_query($conn,
            "SELECT * FROM exam2 WHERE Assesment='$assess' ORDER BY year,term");
        while ($e = mysqli_fetch_assoc($eRes)) {
            $e['total'] = array_sum(array_map(fn($s)=>(int)($e[$s]??0),$subjects));
            $e['mean']  = round($e['total']/count($subjects),1);
            $learnerExams[] = $e;
        }
        /* class rank per exam */
        foreach ($learnerExams as &$exam) {
            $t   = (int)$exam['term'];
            $et  = mysqli_real_escape_string($conn, $exam['exam_type']);
            $yr  = (int)$exam['year'];
            $tot = $exam['total'];
            $rRes = mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*)+1 AS rnk FROM exam2
                 WHERE grade='$grade' AND term='$t' AND exam_type='$et' AND year='$yr'
                 AND (math+eng+kisw+sst+scie+ca+agri+re+pretec) > $tot"));
            $exam['rank'] = (int)($rRes['rnk']??1);
            $classSize = (int)(mysqli_fetch_assoc(mysqli_query($conn,
                "SELECT COUNT(*) AS c FROM exam2
                 WHERE grade='$grade' AND term='$t' AND exam_type='$et' AND year='$yr'"))['c']??1);
            $exam['class_size'] = $classSize;
        }
        unset($exam);

        if ($learnerExams) {
            // chronological order for charts (custom exams slot in after the built-ins)
            usort($learnerExams, 'cmpExamChrono');
            $latestExam  = end($learnerExams);
            reset($learnerExams);
            $overallMean = round(array_sum(array_map(fn($e)=>$e['mean'],$learnerExams))/count($learnerExams),1);
            $bestRank    = min(array_column($learnerExams,'rank'));
            $delta       = round($latestExam['mean'] - $learnerExams[0]['mean'], 1);

            /* grade-wide subject averages for the same exam session as the learner's LATEST exam */
            $lt  = (int)$latestExam['term'];
            $let = mysqli_real_escape_string($conn, $latestExam['exam_type']);
            $ly  = (int)$latestExam['year'];
            foreach ($subjects as $s) {
                $r = mysqli_fetch_assoc(mysqli_query($conn,
                    "SELECT AVG($s) AS m FROM exam2 WHERE grade='$grade' AND term='$lt' AND exam_type='$let' AND year='$ly'"));
                $gradeSubjAvgForLatest[$s] = round((float)($r['m']??0),1);
            }
        }
    }
}

/* ── Grade-level filter ── */
$gGrade   = (int)($_GET['ggrade'] ?? 1);
$gTerm    = $_GET['gterm'] ?? '';
$gExamRaw = $_GET['gexam'] ?? '';
$gExam    = $gExamRaw !== '' ? (resolveExamCode($gExamRaw, $EXAM_TYPES) ?? $gExamRaw) : '';
$gYear    = $_GET['gyear'] ?? '';

$gradeStudents    = [];
$gradeSubjMeans   = [];
$gradeBands       = ['ee'=>0,'me'=>0,'ae'=>0,'be'=>0];
$gradeTopStudents = [];
$gradeExamLabel   = '';

if ($gGrade && $gTerm && $gExam && $gYear) {
    $gt  = (int)$gTerm; $gy = (int)$gYear;
    $gSql = "SELECT * FROM exam2 WHERE grade='$gGrade' AND term='$gt' AND exam_type='".mysqli_real_escape_string($conn,$gExam)."' AND year='$gy'";
    $gRes = mysqli_query($conn, $gSql);
    while ($r = mysqli_fetch_assoc($gRes)) {
        $r['total'] = array_sum(array_map(fn($s)=>(int)($r[$s]??0),$subjects));
        $r['mean']  = round($r['total']/count($subjects),1);
        $gradeStudents[] = $r;
    }
    if ($gradeStudents) {
        usort($gradeStudents, fn($a,$b)=>$b['total']<=>$a['total']);
        $cnt = count($gradeStudents);
        foreach ($subjects as $s) {
            $gradeSubjMeans[$s] = round(array_sum(array_map(fn($r)=>(float)($r[$s]??0),$gradeStudents))/$cnt,1);
        }
        foreach ($gradeStudents as $gs) {
            $m = $gs['mean'];
            if($m>74)$gradeBands['ee']++;elseif($m>49)$gradeBands['me']++;elseif($m>25)$gradeBands['ae']++;else$gradeBands['be']++;
        }
        $gradeTopStudents = array_slice($gradeStudents,0,10);
        $gradeExamLabel = "Grade $gGrade · Term $gTerm · ".examDisp($gExam)." $gYear";
    }
}

/* distinct years in exam2 */
$yearsRes = mysqli_query($conn,"SELECT DISTINCT year FROM exam2 ORDER BY year DESC");
$examYears = [];
while ($y=mysqli_fetch_assoc($yearsRes)) $examYears[]=$y['year'];

/* school timeline: average per year-term-exam */
$schoolTimeline = [];
$stRes = mysqli_query($conn,
    "SELECT year,term,exam_type, AVG((math+eng+kisw+sst+scie+ca+agri+re+pretec)/9) AS m, COUNT(*) AS cnt
     FROM exam2 GROUP BY year,term,exam_type");
while ($r=mysqli_fetch_assoc($stRes)) {
    $r['label'] = "T{$r['term']} ".examDisp($r['exam_type'])." {$r['year']}";
    $schoolTimeline[] = $r;
}
usort($schoolTimeline, 'cmpExamChrono');

/* Which tab is open */
$tab = $_GET['tab'] ?? 'individual';
if (!in_array($tab, ['individual','grade','school'], true)) $tab = 'individual';
$schoolMeanTop = round((float)($schoolStats['school_mean']??0),1);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Track Performance — Stephen Kanja School</title>
  <script>
    /* Apply saved theme before first paint (shared with the other dashboards) */
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
      --border:#e4e8f0; --border-2:#eef1f6;
      --navy:#0b1f3a; --gold:#f0c040; --gold-d:#c8992a; --gold-pale:#fdf3d4; --gold-ink:#7a5a10;
      --ee:#059669; --ee-bg:#d1fae5;
      --me:#2563eb; --me-bg:#dbeafe;
      --ae:#d97706; --ae-bg:#fef3c7;
      --be:#dc2626; --be-bg:#fee2e2;
      --silver:#94a3b8; --bronze:#b87333;
      --shadow-sm:0 1px 2px rgba(15,23,42,.05); --shadow:0 6px 24px rgba(15,23,42,.07);
      --r:14px; --r-lg:18px; --sidebar-w:264px; --topbar-h:64px;
      color-scheme: light;
    }
    [data-theme="dark"] {
      --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
      --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3; --border:#1f2d47; --border-2:#192740;
      --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040;
      --ee:#4ade80; --ee-bg:rgba(34,197,94,.16);
      --me:#7db3f0; --me-bg:rgba(96,165,250,.16);
      --ae:#fbbf24; --ae-bg:rgba(251,191,36,.14);
      --be:#f87171; --be-bg:rgba(248,113,113,.14);
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

    .content { padding:24px 32px 60px; width:100%; max-width:1480px; margin:0 auto; display:flex; flex-direction:column; gap:18px; }
    .page-eyebrow { font-size:.7rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold-d); }
    .page-title { font-size:clamp(1.4rem,2.4vw,1.8rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; }
    .page-sub { color:var(--muted); font-size:.86rem; margin-top:4px; max-width:720px; }

    /* ───────── Tabs ───────── */
    .tabs { display:inline-flex; gap:4px; padding:5px; background:var(--surface); border:1px solid var(--border); border-radius:14px; box-shadow:var(--shadow-sm); align-self:flex-start; max-width:100%; overflow-x:auto; }
    .tab { display:inline-flex; align-items:center; gap:9px; padding:9px 18px; border:none; background:none; border-radius:10px; color:var(--text-2); font-family:inherit; font-weight:700; font-size:.84rem; cursor:pointer; transition:.15s; white-space:nowrap; }
    .tab:hover { background:var(--surface-3); color:var(--text); }
    .tab.active { background:var(--navy); color:#fff; }
    [data-theme="dark"] .tab.active { background:var(--gold); color:#101826; }
    .tab-pane { display:none; flex-direction:column; gap:18px; animation:fadeUp .35s ease both; }
    .tab-pane.active { display:flex; }

    /* ───────── Generic box ───────── */
    .box { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-sm); overflow:hidden; }
    .box-head { padding:15px 22px; border-bottom:1px solid var(--border-2); display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .box-title { font-size:.76rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); display:flex; align-items:center; gap:9px; margin-right:auto; }
    .box-title i { color:var(--gold-d); }
    .box-body { padding:20px 22px; }
    .badge { font-size:.66rem; font-weight:800; letter-spacing:.9px; text-transform:uppercase; padding:3px 11px; border-radius:99px; background:var(--gold-pale); color:var(--gold-ink); }
    .sec-title { font-size:.76rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); display:flex; align-items:center; gap:9px; margin-top:6px; }
    .sec-title i { color:var(--gold-d); }

    .g2 { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .span-all { grid-column:1/-1; }
    .ch { position:relative; }
    .ch-220 { height:220px; } .ch-260 { height:260px; } .ch-300 { height:300px; }

    /* ───────── Form fields ───────── */
    .form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:16px; align-items:end; }
    .field { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .field label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .field select, .field input[type="text"] { appearance:none; width:100%; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; padding:0 14px; font-family:inherit; font-size:.88rem; font-weight:500; color:var(--text); transition:.15s; height:42px; }
    .field select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; padding-right:38px; cursor:pointer; }
    .field select:hover, .field input[type="text"]:hover { border-color:rgba(37,99,235,.35); }
    .field select:focus, .field input[type="text"]:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .btn { background:var(--gold); color:#101826; border:none; border-radius:10px; padding:0 22px; height:42px; font-family:inherit; font-size:.84rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 8px 20px rgba(240,192,64,.28); white-space:nowrap; }
    .btn:hover { background:#f5cf5e; transform:translateY(-1px); }
    .btn:active { transform:scale(.98); }
    .alert-bar { display:flex; align-items:flex-start; gap:12px; border-radius:var(--r); padding:13px 16px; font-size:.86rem; border:1px solid color-mix(in srgb,var(--be) 40%,transparent); background:var(--be-bg); color:var(--be); margin-top:16px; }
    .alert-bar i { margin-top:3px; }

    /* ───────── KPI cards ───────── */
    .kpi-row { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; }
    .kpi-row.three { grid-template-columns:repeat(3,minmax(0,1fr)); }
    .kpi { --accent:var(--navy); background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-sm); padding:16px 18px 15px; position:relative; overflow:hidden; transition:transform .2s, box-shadow .2s; animation:fadeUp .4s ease both; }
    .kpi:hover { transform:translateY(-2px); box-shadow:var(--shadow); }
    .kpi::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:var(--accent); }
    .kpi.k-gold { --accent:var(--gold); } .kpi.k-ee { --accent:var(--ee); } .kpi.k-me { --accent:var(--me); } .kpi.k-ae { --accent:var(--ae); } .kpi.k-be { --accent:var(--be); }
    [data-theme="dark"] .kpi { --accent:var(--gold); }
    [data-theme="dark"] .kpi.k-ee { --accent:var(--ee); } [data-theme="dark"] .kpi.k-me { --accent:var(--me); } [data-theme="dark"] .kpi.k-ae { --accent:var(--ae); } [data-theme="dark"] .kpi.k-be { --accent:var(--be); }
    .kpi-ic { position:absolute; top:14px; right:14px; width:34px; height:34px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:14px; background:var(--gold-pale); color:var(--gold-d); }
    .kpi-lbl { font-size:.6rem; font-weight:800; letter-spacing:1.2px; text-transform:uppercase; color:var(--muted); margin-bottom:4px; }
    .kpi-val { font-size:1.9rem; font-weight:800; letter-spacing:-.8px; line-height:1.15; font-variant-numeric:tabular-nums; }
    .kpi-val small { font-size:.9rem; font-weight:600; color:var(--muted); letter-spacing:0; }
    .kpi-sub { font-size:.76rem; color:var(--muted); margin-top:3px; }

    /* ───────── Band badges ───────── */
    .aw { display:inline-block; font-size:.68rem; font-weight:800; padding:2px 9px; border-radius:99px; letter-spacing:.3px; vertical-align:middle; font-variant-numeric:tabular-nums; }
    .aw-ee { background:var(--ee-bg); color:var(--ee); } .aw-me { background:var(--me-bg); color:var(--me); }
    .aw-ae { background:var(--ae-bg); color:var(--ae); } .aw-be { background:var(--be-bg); color:var(--be); }

    /* ───────── Profile ───────── */
    .profile { background:linear-gradient(135deg,var(--navy) 0%,#10294d 100%); border-radius:var(--r-lg); padding:24px 28px; display:flex; align-items:center; gap:22px; flex-wrap:wrap; position:relative; overflow:hidden; box-shadow:var(--shadow); color:#fff; }
    .profile::before { content:''; position:absolute; inset:0; background:radial-gradient(ellipse 55% 90% at 4% 50%, rgba(240,192,64,.16) 0%, transparent 60%); pointer-events:none; }
    .avatar { width:68px; height:68px; border-radius:18px; background:linear-gradient(135deg,var(--gold),#e0a920); display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:1.5rem; font-weight:800; color:#0b1f3a; position:relative; box-shadow:0 0 0 4px rgba(240,192,64,.22); }
    .p-info { position:relative; flex:1; min-width:200px; }
    .p-name { font-size:1.5rem; font-weight:800; letter-spacing:-.4px; line-height:1.2; }
    .p-detail { font-size:.8rem; color:#9fb0cb; margin-top:6px; display:flex; gap:18px; flex-wrap:wrap; }
    .p-detail span { display:inline-flex; align-items:center; gap:7px; }
    .p-detail i { color:var(--gold); font-size:.72rem; }
    .p-badges { position:relative; display:flex; gap:10px; flex-wrap:wrap; }
    .pb { display:flex; align-items:center; gap:12px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1); border-radius:12px; padding:9px 16px; }
    .pb-lbl { font-size:.58rem; font-weight:800; letter-spacing:1.2px; text-transform:uppercase; color:#8ea0bd; }
    .pb-val { font-size:1.4rem; font-weight:800; color:var(--gold); line-height:1.1; letter-spacing:-.4px; }

    /* ───────── Rank history ───────── */
    .rank-history { display:flex; gap:10px; overflow-x:auto; padding:2px 2px 8px; }
    .rh-item { flex-shrink:0; min-width:108px; background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:12px 16px; text-align:center; box-shadow:var(--shadow-sm); transition:box-shadow .2s, transform .2s; }
    .rh-item:hover { box-shadow:var(--shadow); transform:translateY(-2px); }
    .rh-exam { font-size:.6rem; font-weight:800; letter-spacing:.8px; text-transform:uppercase; color:var(--muted); line-height:1.4; white-space:nowrap; }
    .rh-rank { font-size:1.6rem; font-weight:800; letter-spacing:-.6px; line-height:1.3; }
    .rh-total { font-size:.7rem; color:var(--muted); }

    /* ───────── Tables ───────── */
    .tbl-scroll { overflow:auto; -webkit-overflow-scrolling:touch; }
    table.t { width:100%; border-collapse:collapse; font-size:.84rem; min-width:620px; }
    table.t thead th { background:var(--surface-2); color:var(--muted); font-size:.64rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; padding:11px 14px; text-align:left; white-space:nowrap; border-bottom:1px solid var(--border); }
    table.t td { padding:10px 14px; border-bottom:1px solid var(--border-2); color:var(--text-2); vertical-align:middle; font-variant-numeric:tabular-nums; }
    table.t tbody tr { transition:background .12s; }
    table.t tbody tr:hover td { background:var(--surface-2); }
    table.t tbody tr:last-child td { border-bottom:none; }
    table.t .strong { font-weight:700; color:var(--text); }
    table.t .rank { font-weight:800; font-size:1rem; color:var(--muted); width:54px; }
    .rank-gold { color:var(--gold-d) !important; } .rank-silver { color:var(--silver) !important; } .rank-bronze { color:var(--bronze) !important; }
    table.t .subj { font-weight:700; color:var(--text); white-space:nowrap; }
    table.t .score { font-size:1.05rem; font-weight:800; color:var(--text); }
    .muted-sm { font-size:.74rem; color:var(--muted); }
    .prog-track { width:150px; background:var(--surface-3); border-radius:99px; height:8px; overflow:hidden; }
    .prog-fill { height:100%; border-radius:99px; transition:width 1s cubic-bezier(.4,0,.2,1); }
    .vs-pos { color:var(--ee); font-weight:800; white-space:nowrap; } .vs-neg { color:var(--be); font-weight:800; white-space:nowrap; } .vs-flat { color:var(--muted); font-weight:600; }

    /* ───────── Band bars ───────── */
    .band-row { display:flex; align-items:center; gap:12px; margin-bottom:13px; }
    .band-row:last-child { margin-bottom:0; }
    .band-label { font-size:.78rem; font-weight:700; width:210px; flex-shrink:0; }
    .band-track { flex:1; background:var(--surface-3); border-radius:99px; height:9px; overflow:hidden; }
    .band-fill { height:100%; border-radius:99px; transition:width 1.2s cubic-bezier(.4,0,.2,1); }
    .bf-ee { background:var(--ee); } .bf-me { background:var(--me); } .bf-ae { background:var(--ae); } .bf-be { background:var(--be); }
    .band-count { font-size:.76rem; font-weight:700; width:78px; text-align:right; color:var(--text-2); flex-shrink:0; font-variant-numeric:tabular-nums; }

    /* ───────── Empty state ───────── */
    .empty-state { text-align:center; padding:52px 20px; }
    .empty-icon { width:64px; height:64px; border-radius:18px; margin:0 auto 14px; display:flex; align-items:center; justify-content:center; background:var(--gold-pale); color:var(--gold-d); font-size:24px; }
    .empty-state h3 { font-size:1rem; font-weight:800; margin-bottom:4px; }
    .empty-state p { font-size:.86rem; color:var(--muted); max-width:420px; margin:0 auto; }

    .site-footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (max-width:1180px) { .kpi-row { grid-template-columns:repeat(2,minmax(0,1fr)); } }
    @media (max-width:1024px) {
      .app, body.nav-collapsed .app { grid-template-columns:1fr; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); visibility:visible; transform:none; }
      body.nav-collapsed .sidebar { visibility:visible; transform:none; }
      .sidebar.open { left:0; }
      .topbar { padding:0 18px; } .content { padding:18px 18px 50px; }
      .g2 { grid-template-columns:1fr; }
    }
    @media (max-width:640px) {
      .btn-gold span { display:none; } .tb-title small { display:none; }
      .kpi-row, .kpi-row.three { grid-template-columns:1fr 1fr; }
      .kpi-val { font-size:1.55rem; }
      .profile { padding:20px; } .band-label { width:100%; } .band-row { flex-wrap:wrap; gap:6px; }
      .band-track { flex:1 1 60%; }
      .btn { width:100%; }
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
        <a href="Progress.php"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="track.php" class="active" aria-current="page"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
        <a href="LearningMaterials.php"><i class="fa-solid fa-book"></i> Learning Materials</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Communication</div>
        <a href="manage_parent_contacts.php"><i class="fa-solid fa-address-book"></i> Parent Contacts</a>
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
      <a href="index.php" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>

  <!-- ══════════ Main ══════════ -->
  <div class="main">

    <header class="topbar">
      <div class="tb-left">
        <button type="button" class="menu-btn" id="menuBtn" aria-label="Toggle menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
        <div class="tb-title">Track Performance<small>Learners, grades and school-wide trends</small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="ViewResults.php" class="btn-gold"><i class="fa-solid fa-chart-pie"></i><span>View Results</span></a>
        <a href="index.php" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <div>
        <p class="page-eyebrow">Analytics</p>
        <h1 class="page-title">Performance Tracker</h1>
        <p class="page-sub">Drill into individual learners, grade cohorts and school-wide academic trends.</p>
      </div>

      <!-- School-wide strip -->
      <div class="kpi-row three">
        <div class="kpi k-gold">
          <div class="kpi-ic"><i class="fa-solid fa-user-graduate"></i></div>
          <div class="kpi-lbl">Learners</div>
          <div class="kpi-val"><?= $totalStudents ?></div>
        </div>
        <div class="kpi k-gold" style="animation-delay:.04s">
          <div class="kpi-ic"><i class="fa-solid fa-file-lines"></i></div>
          <div class="kpi-lbl">Exam sessions</div>
          <div class="kpi-val"><?= (int)($schoolStats['exams']??0) ?></div>
        </div>
        <div class="kpi k-<?= bandKey($schoolMeanTop) ?>" style="animation-delay:.08s">
          <div class="kpi-ic"><i class="fa-solid fa-gauge-high"></i></div>
          <div class="kpi-lbl">School mean</div>
          <div class="kpi-val"><?= $schoolMeanTop ?><small>/100</small></div>
        </div>
      </div>

      <!-- Tabs -->
      <div class="tabs" role="tablist" aria-label="Report view">
        <button type="button" class="tab <?= $tab==='individual'?'active':'' ?>" data-tab="individual" onclick="switchTab('individual',this)"><i class="fa-solid fa-user"></i> Individual Learner</button>
        <button type="button" class="tab <?= $tab==='grade'?'active':'' ?>" data-tab="grade" onclick="switchTab('grade',this)"><i class="fa-solid fa-users"></i> Grade Performance</button>
        <button type="button" class="tab <?= $tab==='school'?'active':'' ?>" data-tab="school" onclick="switchTab('school',this)"><i class="fa-solid fa-school"></i> School Overview</button>
      </div>

      <!-- ═════════ TAB 1: INDIVIDUAL ═════════ -->
      <div class="tab-pane <?= $tab==='individual'?'active':'' ?>" id="tab-individual">

        <div class="box">
          <div class="box-head"><span class="box-title"><i class="fa-solid fa-magnifying-glass"></i>Search learner</span></div>
          <div class="box-body">
            <form method="GET" action="">
              <input type="hidden" name="tab" value="individual">
              <div class="form-grid">
                <div class="field">
                  <label for="assess">Assessment number</label>
                  <input type="text" id="assess" name="assess" placeholder="e.g. SKS/2024/001" value="<?= htmlspecialchars($searchAssess) ?>">
                </div>
                <div class="field">
                  <label for="sname">Learner name</label>
                  <input type="text" id="sname" name="sname" placeholder="First or last name…" value="<?= htmlspecialchars($searchName) ?>">
                </div>
                <div class="field">
                  <label for="sgrade">Grade (optional)</label>
                  <select id="sgrade" name="sgrade">
                    <option value="">All grades</option>
                    <?php for($g=1;$g<=9;$g++){ $sel=$searchGrade==$g?'selected':''; echo "<option value='$g' $sel>Grade $g</option>"; } ?>
                  </select>
                </div>
                <div class="field">
                  <button type="submit" class="btn"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
                </div>
              </div>
            </form>
            <?php if ($searchError): ?>
            <div class="alert-bar"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($searchError) ?></span></div>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($learner && $learnerExams): ?>

        <div class="profile">
          <div class="avatar"><?= strtoupper(substr($learner['firstName'],0,1).substr($learner['surname'],0,1)) ?></div>
          <div class="p-info">
            <div class="p-name"><?= htmlspecialchars($learner['firstName'].' '.$learner['surname']) ?></div>
            <div class="p-detail">
              <span><i class="fa-solid fa-id-badge"></i><?= htmlspecialchars($learner['Assesment']) ?></span>
              <span><i class="fa-solid fa-door-open"></i>Grade <?= htmlspecialchars((string)($learner['Grade'] ?? '')) ?></span>
              <?php if (!empty($learner['gender'])): ?>
              <span><i class="fa-solid fa-venus-mars"></i><?= htmlspecialchars($learner['gender']) ?></span>
              <?php endif; ?>
              <span><i class="fa-solid fa-file-lines"></i><?= count($learnerExams) ?> exams recorded</span>
            </div>
          </div>
          <div class="p-badges">
            <div class="pb">
              <div><div class="pb-lbl">Overall avg</div><div class="pb-val"><?= $overallMean ?></div></div>
              <span class="aw <?= bandClass($overallMean) ?>"><?= bandLabel($overallMean) ?></span>
            </div>
            <div class="pb">
              <div><div class="pb-lbl">Best rank</div><div class="pb-val">#<?= $bestRank ?></div></div>
            </div>
          </div>
        </div>

        <?php $latMean = $latestExam['mean']; ?>
        <div class="kpi-row">
          <div class="kpi">
            <div class="kpi-ic"><i class="fa-solid fa-list-ol"></i></div>
            <div class="kpi-lbl">Latest rank</div>
            <div class="kpi-val">#<?= $latestExam['rank'] ?></div>
            <div class="kpi-sub">of <?= $latestExam['class_size'] ?> in class</div>
          </div>
          <div class="kpi k-<?= bandKey($latMean) ?>" style="animation-delay:.04s">
            <div class="kpi-lbl">Latest mean</div>
            <div class="kpi-val"><?= $latMean ?><small>/100</small></div>
            <div class="kpi-sub"><span class="aw <?= bandClass($latMean) ?>"><?= bandLabel($latMean) ?></span></div>
          </div>
          <div class="kpi k-gold" style="animation-delay:.08s">
            <div class="kpi-lbl">Latest total</div>
            <div class="kpi-val"><?= $latestExam['total'] ?><small>/900</small></div>
            <div class="kpi-sub">across 9 subjects</div>
          </div>
          <div class="kpi k-<?= $delta>=0?'ee':'be' ?>" style="animation-delay:.12s">
            <div class="kpi-lbl">Improvement</div>
            <div class="kpi-val" style="color:<?= $delta>=0?'var(--ee)':'var(--be)' ?>"><?= $delta>=0?'+':'' ?><?= $delta ?></div>
            <div class="kpi-sub">since first recorded exam</div>
          </div>
        </div>

        <div class="g2">
          <div class="box">
            <div class="box-head"><span class="box-title"><i class="fa-solid fa-chart-line"></i>Score trend across exams</span></div>
            <div class="box-body"><div class="ch ch-260"><canvas id="learnerTrendChart"></canvas></div></div>
          </div>
          <div class="box">
            <div class="box-head"><span class="box-title"><i class="fa-solid fa-chart-column"></i>Latest exam — subject breakdown</span></div>
            <div class="box-body"><div class="ch ch-260"><canvas id="learnerSubjectChart"></canvas></div></div>
          </div>
        </div>

        <div class="sec-title"><i class="fa-solid fa-trophy"></i>Class rank history</div>
        <div class="rank-history">
          <?php foreach ($learnerExams as $ex): ?>
          <div class="rh-item">
            <div class="rh-exam">T<?= $ex['term'] ?> <?= htmlspecialchars(examDisp($ex['exam_type'])) ?><br><?= $ex['year'] ?></div>
            <div class="rh-rank" style="color:<?= $ex['rank']===1?'var(--gold-d)':($ex['rank']<=3?'var(--ee)':'var(--text)') ?>">#<?= $ex['rank'] ?></div>
            <div class="rh-total"><?= $ex['total'] ?>/900</div>
          </div>
          <?php endforeach; ?>
        </div>

        <div class="box">
          <div class="box-head">
            <span class="box-title"><i class="fa-solid fa-book"></i>Subject scores</span>
            <span class="badge">T<?= $latestExam['term'] ?> <?= htmlspecialchars(examDisp($latestExam['exam_type'])) ?> <?= $latestExam['year'] ?></span>
          </div>
          <div class="tbl-scroll">
            <table class="t">
              <thead><tr><th>Subject</th><th>Score</th><th>Out of</th><th>%</th><th>Band</th><th>Progress</th><th>vs grade avg</th></tr></thead>
              <tbody>
                <?php foreach ($subjects as $idx => $s):
                    $score = (int)($latestExam[$s] ?? 0);
                    $gAvg  = $gradeSubjAvgForLatest[$s] ?? null;
                    $vs    = vsAvgFmt($gAvg !== null ? round($score - $gAvg, 1) : null);
                ?>
                <tr>
                  <td class="subj"><?= $subjectNames[$idx] ?></td>
                  <td class="score"><?= $score ?></td>
                  <td class="muted-sm">100</td>
                  <td><?= $score ?>%</td>
                  <td><span class="aw <?= bandClass($score) ?>"><?= bandLabel($score) ?></span></td>
                  <td><div class="prog-track"><div class="prog-fill" style="width:<?= min(100,$score) ?>%;background:<?= bandColor($score) ?>"></div></div></td>
                  <td class="<?= $vs['cls'] ?>"><?= $vs['txt'] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="box">
          <div class="box-head"><span class="box-title"><i class="fa-solid fa-table-list"></i>Full exam history</span></div>
          <div class="tbl-scroll">
            <table class="t">
              <thead>
                <tr>
                  <th>Exam</th><th>Year</th>
                  <?php foreach($short as $sh) echo "<th>$sh</th>"; ?>
                  <th>Total</th><th>Mean</th><th>Rank</th><th>Band</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($learnerExams as $ex): $exMean=$ex['mean']; ?>
                <tr>
                  <td class="strong">T<?= $ex['term'] ?> <?= htmlspecialchars(examDisp($ex['exam_type'])) ?></td>
                  <td><?= $ex['year'] ?></td>
                  <?php foreach($subjects as $s): $sv=(int)($ex[$s]??0); ?>
                  <td><span class="aw <?= bandClass($sv) ?>"><?= $sv ?></span></td>
                  <?php endforeach; ?>
                  <td class="strong"><?= $ex['total'] ?></td>
                  <td><?= $exMean ?></td>
                  <td class="strong <?= $ex['rank']===1?'rank-gold':($ex['rank']===2?'rank-silver':($ex['rank']===3?'rank-bronze':'')) ?>">#<?= $ex['rank'] ?>/<?= $ex['class_size'] ?></td>
                  <td><span class="aw <?= bandClass($exMean) ?>"><?= bandLabel($exMean) ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php elseif (!$searchAssess && !$searchName): ?>
        <div class="box">
          <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-user-magnifying-glass"></i></div>
            <h3>Search for a learner</h3>
            <p>Enter an assessment number or learner name above to view their full performance profile.</p>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ═════════ TAB 2: GRADE ═════════ -->
      <div class="tab-pane <?= $tab==='grade'?'active':'' ?>" id="tab-grade">

        <div class="box">
          <div class="box-head"><span class="box-title"><i class="fa-solid fa-sliders"></i>Select grade &amp; exam</span></div>
          <div class="box-body">
            <form method="GET" action="">
              <input type="hidden" name="tab" value="grade">
              <div class="form-grid">
                <div class="field">
                  <label for="ggrade">Grade</label>
                  <select id="ggrade" name="ggrade">
                    <?php for($g=1;$g<=9;$g++){ $sel=$gGrade==$g?'selected':''; echo "<option value='$g' $sel>Grade $g</option>"; } ?>
                  </select>
                </div>
                <div class="field">
                  <label for="gterm">Term</label>
                  <select id="gterm" name="gterm">
                    <option value="">Select term</option>
                    <?php foreach([1,2,3] as $t){ $sel=$gTerm==$t?'selected':''; echo "<option value='$t' $sel>Term $t</option>"; } ?>
                  </select>
                </div>
                <div class="field">
                  <label for="gexam">Exam name</label>
                  <select id="gexam" name="gexam">
                    <option value="">Select exam</option>
                    <?php foreach($EXAM_TYPES as $code=>$et){
                        $sel = ($gExam===$code) ? 'selected' : '';
                        echo "<option value='".htmlspecialchars($code,ENT_QUOTES,'UTF-8')."' $sel>".htmlspecialchars($et['label'],ENT_QUOTES,'UTF-8')."</option>";
                    } ?>
                  </select>
                </div>
                <div class="field">
                  <label for="gyear">Year</label>
                  <select id="gyear" name="gyear">
                    <option value="">Select year</option>
                    <?php foreach($examYears as $y){ $sel=$gYear==$y?'selected':''; echo "<option value='$y' $sel>$y</option>"; } ?>
                  </select>
                </div>
                <div class="field">
                  <button type="submit" class="btn"><i class="fa-solid fa-filter"></i> Load</button>
                </div>
              </div>
            </form>
          </div>
        </div>

        <?php if ($gradeStudents):
          $gcnt   = count($gradeStudents);
          $gMean  = round(array_sum(array_map(fn($s)=>$s['mean'],$gradeStudents))/$gcnt,1);
          $gBandTotal = array_sum($gradeBands);
        ?>

        <div class="kpi-row">
          <div class="kpi">
            <div class="kpi-ic"><i class="fa-solid fa-users"></i></div>
            <div class="kpi-lbl">Students tested</div>
            <div class="kpi-val"><?= $gcnt ?></div>
            <div class="kpi-sub"><?= htmlspecialchars($gradeExamLabel) ?></div>
          </div>
          <div class="kpi k-<?= bandKey($gMean) ?>" style="animation-delay:.04s">
            <div class="kpi-lbl">Class mean</div>
            <div class="kpi-val"><?= $gMean ?><small>/100</small></div>
            <div class="kpi-sub"><span class="aw <?= bandClass($gMean) ?>"><?= bandLabel($gMean) ?></span></div>
          </div>
          <div class="kpi k-ee" style="animation-delay:.08s">
            <div class="kpi-lbl">Exceeding (E.E)</div>
            <div class="kpi-val"><?= $gradeBands['ee'] ?></div>
            <div class="kpi-sub"><?= $gBandTotal?round($gradeBands['ee']/$gBandTotal*100):0 ?>% of class</div>
          </div>
          <div class="kpi k-be" style="animation-delay:.12s">
            <div class="kpi-lbl">Below (B.E)</div>
            <div class="kpi-val"><?= $gradeBands['be'] ?></div>
            <div class="kpi-sub"><?= $gBandTotal?round($gradeBands['be']/$gBandTotal*100):0 ?>% need support</div>
          </div>
        </div>

        <div class="box">
          <div class="box-head">
            <span class="box-title"><i class="fa-solid fa-book"></i>Subject scores</span>
            <span class="badge"><?= htmlspecialchars($gradeExamLabel) ?></span>
          </div>
          <div class="tbl-scroll">
            <table class="t">
              <thead><tr><th>Subject</th><th>Class mean</th><th>Out of</th><th>%</th><th>Band</th><th>Progress</th><th>vs school avg</th></tr></thead>
              <tbody>
                <?php foreach ($subjects as $idx => $s):
                    $gm   = $gradeSubjMeans[$s] ?? 0;
                    $sAvg = $schoolSubjMeans[$s] ?? null;
                    $vs   = vsAvgFmt($sAvg !== null ? round($gm - $sAvg, 1) : null);
                ?>
                <tr>
                  <td class="subj"><?= $subjectNames[$idx] ?></td>
                  <td class="score"><?= $gm ?></td>
                  <td class="muted-sm">100</td>
                  <td><?= $gm ?>%</td>
                  <td><span class="aw <?= bandClass($gm) ?>"><?= bandLabel($gm) ?></span></td>
                  <td><div class="prog-track"><div class="prog-fill" style="width:<?= min(100,$gm) ?>%;background:<?= bandColor($gm) ?>"></div></div></td>
                  <td class="<?= $vs['cls'] ?>"><?= $vs['txt'] ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="g2">
          <div class="box">
            <div class="box-head"><span class="box-title"><i class="fa-solid fa-layer-group"></i>Achievement band distribution</span></div>
            <div class="box-body">
              <?php
              $bands = [
                ['Exceeding Expectations (E.E)',  $gradeBands['ee'], 'bf-ee', 'var(--ee)'],
                ['Meeting Expectations (M.E)',    $gradeBands['me'], 'bf-me', 'var(--me)'],
                ['Approaching Expectations (A.E)',$gradeBands['ae'], 'bf-ae', 'var(--ae)'],
                ['Below Expectations (B.E)',      $gradeBands['be'], 'bf-be', 'var(--be)'],
              ];
              foreach ($bands as $b): $pct = $gBandTotal ? round($b[1]/$gBandTotal*100) : 0; ?>
              <div class="band-row">
                <div class="band-label" style="color:<?= $b[3] ?>"><?= $b[0] ?></div>
                <div class="band-track"><div class="band-fill <?= $b[2] ?>" style="width:<?= $pct ?>%"></div></div>
                <div class="band-count"><?= $b[1] ?> (<?= $pct ?>%)</div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="box">
            <div class="box-head"><span class="box-title"><i class="fa-solid fa-chart-pie"></i>Band breakdown</span></div>
            <div class="box-body"><div class="ch ch-260"><canvas id="gradeDoughnut"></canvas></div></div>
          </div>
        </div>

        <div class="box">
          <div class="box-head"><span class="box-title"><i class="fa-solid fa-ranking-star"></i>Top 10 students this exam</span></div>
          <div class="tbl-scroll">
            <table class="t">
              <thead><tr><th>#</th><th>Assessment</th><th>Name</th><th>Total</th><th>Mean</th><th>Band</th></tr></thead>
              <tbody>
                <?php foreach($gradeTopStudents as $idx=>$st):
                  $rc = $idx===0?'rank-gold':($idx===1?'rank-silver':($idx===2?'rank-bronze':''));
                ?>
                <tr>
                  <td class="rank <?= $rc ?>"><?= $idx+1 ?></td>
                  <td class="muted-sm"><?= htmlspecialchars($st['Assesment']??'') ?></td>
                  <td class="strong"><?= htmlspecialchars(($st['firstName']??'').' '.($st['lastName']??$st['surname']??'')) ?></td>
                  <td><?= $st['total'] ?></td>
                  <td><?= $st['mean'] ?></td>
                  <td><span class="aw <?= bandClass($st['mean']) ?>"><?= bandLabel($st['mean']) ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <?php else: ?>
        <div class="box">
          <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-users"></i></div>
            <h3>Select a grade &amp; exam</h3>
            <p>Choose a grade, term, exam name and year above to load grade-level analytics.</p>
          </div>
        </div>
        <?php endif; ?>
      </div>

      <!-- ═════════ TAB 3: SCHOOL ═════════ -->
      <div class="tab-pane <?= $tab==='school'?'active':'' ?>" id="tab-school">

        <?php
          $sTot  = $bandTotal;
          $sMean = $schoolMeanTop;
          $eeP   = $sTot?round($bandAll['ee']/$sTot*100):0;
          $beP   = $sTot?round($bandAll['be']/$sTot*100):0;
        ?>
        <div class="kpi-row">
          <div class="kpi">
            <div class="kpi-ic"><i class="fa-solid fa-school"></i></div>
            <div class="kpi-lbl">Total students</div>
            <div class="kpi-val"><?= $totalStudents ?></div>
            <div class="kpi-sub">9 grades enrolled</div>
          </div>
          <div class="kpi k-<?= bandKey($sMean) ?>" style="animation-delay:.04s">
            <div class="kpi-lbl">School mean</div>
            <div class="kpi-val"><?= $sMean ?><small>/100</small></div>
            <div class="kpi-sub"><span class="aw <?= bandClass($sMean) ?>"><?= bandLabel($sMean) ?></span> overall</div>
          </div>
          <div class="kpi k-ee" style="animation-delay:.08s">
            <div class="kpi-ic" style="background:var(--ee-bg);color:var(--ee)"><i class="fa-solid fa-star"></i></div>
            <div class="kpi-lbl">Exceeding (E.E)</div>
            <div class="kpi-val"><?= $bandAll['ee'] ?></div>
            <div class="kpi-sub"><?= $eeP ?>% of all records</div>
          </div>
          <div class="kpi k-be" style="animation-delay:.12s">
            <div class="kpi-lbl">Below (B.E)</div>
            <div class="kpi-val"><?= $bandAll['be'] ?></div>
            <div class="kpi-sub"><?= $beP ?>% need intervention</div>
          </div>
        </div>

        <div class="box">
          <div class="box-head"><span class="box-title"><i class="fa-solid fa-chart-line"></i>School mean trend — all exam sessions</span></div>
          <div class="box-body"><div class="ch ch-300"><canvas id="schoolTimelineChart"></canvas></div></div>
        </div>

        <div class="g2">
          <div class="box">
            <div class="box-head"><span class="box-title"><i class="fa-solid fa-chart-column"></i>Latest mean score by grade</span></div>
            <div class="box-body"><div class="ch ch-300"><canvas id="schoolGradeChart"></canvas></div></div>
          </div>
          <div class="box">
            <div class="box-head"><span class="box-title"><i class="fa-solid fa-chart-pie"></i>School-wide achievement bands</span></div>
            <div class="box-body">
              <div class="ch ch-220"><canvas id="schoolBandDoughnut"></canvas></div>
              <div style="margin-top:18px">
                <?php
                $schoolBands = [
                  ['Exceeding (E.E)',   $bandAll['ee'], 'bf-ee', 'var(--ee)'],
                  ['Meeting (M.E)',     $bandAll['me'], 'bf-me', 'var(--me)'],
                  ['Approaching (A.E)', $bandAll['ae'], 'bf-ae', 'var(--ae)'],
                  ['Below (B.E)',       $bandAll['be'], 'bf-be', 'var(--be)'],
                ];
                foreach($schoolBands as $b): $pct = $sTot?round($b[1]/$sTot*100):0; ?>
                <div class="band-row">
                  <div class="band-label" style="color:<?= $b[3] ?>;width:140px"><?= $b[0] ?></div>
                  <div class="band-track"><div class="band-fill <?= $b[2] ?>" style="width:<?= $pct ?>%"></div></div>
                  <div class="band-count"><?= $b[1] ?> (<?= $pct ?>%)</div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>

        <div class="box">
          <div class="box-head"><span class="box-title"><i class="fa-solid fa-chart-bar"></i>School-wide subject means</span></div>
          <div class="box-body"><div class="ch ch-300"><canvas id="schoolSubjChart"></canvas></div></div>
        </div>

        <div class="box">
          <div class="box-head"><span class="box-title"><i class="fa-solid fa-ranking-star"></i>Grade performance rankings (latest)</span></div>
          <div class="tbl-scroll">
            <table class="t">
              <thead><tr><th>Rank</th><th>Grade</th><th>Mean /100</th><th>Band</th><th>Students tested</th><th>Last exam</th></tr></thead>
              <tbody>
                <?php foreach($gradeRankData as $idx=>$gr):
                  $rc = $idx===0?'rank-gold':($idx===1?'rank-silver':($idx===2?'rank-bronze':''));
                  $latG = $latestGrade[$gr['grade']] ?? null;
                  $cnt  = $latG['cnt'] ?? '—';
                  $lastExam = $latG ? "T{$latG['term']} ".examDisp($latG['exam_type'])." {$latG['year']}" : '—';
                ?>
                <tr>
                  <td class="rank <?= $rc ?>"><?= $idx+1 ?></td>
                  <td class="strong">Grade <?= $gr['grade'] ?></td>
                  <td><?= $gr['mean'] ?></td>
                  <td><span class="aw <?= bandClass($gr['mean']) ?>"><?= bandLabel($gr['mean']) ?></span></td>
                  <td><?= $cnt ?></td>
                  <td class="muted-sm"><?= htmlspecialchars($lastExam) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="box">
          <div class="box-head"><span class="box-title"><i class="fa-solid fa-calendar-days"></i>All exam sessions summary</span></div>
          <div class="tbl-scroll">
            <table class="t">
              <thead><tr><th>Exam session</th><th>Year</th><th>Students</th><th>School mean</th><th>Band</th></tr></thead>
              <tbody>
                <?php foreach(array_reverse($schoolTimeline) as $stl): $sm = round((float)$stl['m'],1); ?>
                <tr>
                  <td class="strong"><?= htmlspecialchars($stl['label']) ?></td>
                  <td><?= $stl['year'] ?></td>
                  <td><?= $stl['cnt'] ?></td>
                  <td><?= $sm ?></td>
                  <td><span class="aw <?= bandClass($sm) ?>"><?= bandLabel($sm) ?></span></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div><!-- /tab-school -->

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
    applyChartTheme();
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
      setTimeout(() => { Object.values(Chart.instances).forEach(c => c.resize()); }, 300);
    }
  });
  sidebar.querySelectorAll('nav a').forEach(a => a.addEventListener('click', () => { if (isMobile()) closeSidebar(); }));
  window.addEventListener('resize', () => { if (!isMobile()) closeSidebar(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

  /* ══════════════════ Tabs ══════════════════ */
  function switchTab(id, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + id).classList.add('active');
    btn.classList.add('active');
    const url = new URL(window.location);
    url.searchParams.set('tab', id);
    window.history.replaceState(null, '', url);
    Object.values(Chart.instances).forEach(c => c.resize());
  }

  /* ══════════════════ Charts (theme-aware) ══════════════════ */
  const cssVar = n => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
  function applyChartTheme() {
    Chart.defaults.color = cssVar('--muted');
    Chart.defaults.borderColor = cssVar('--border-2');
    Object.values(Chart.instances).forEach(function (c) {
      if (c.config.type === 'doughnut') c.data.datasets.forEach(ds => ds.borderColor = cssVar('--surface'));
      c.update();
    });
  }
  Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
  Chart.defaults.color = cssVar('--muted');
  Chart.defaults.borderColor = cssVar('--border-2');

  const GOLD = '#f0c040';
  const bandRGBA = v => v > 74 ? 'rgba(5,150,105,0.82)' : v > 49 ? 'rgba(37,99,235,0.82)' : v > 25 ? 'rgba(217,119,6,0.82)' : 'rgba(220,38,38,0.82)';
  const BAND_COLORS = ['rgba(5,150,105,0.82)', 'rgba(37,99,235,0.82)', 'rgba(217,119,6,0.82)', 'rgba(220,38,38,0.82)'];
  const legendOpts = { position: 'bottom', labels: { padding: 14, boxWidth: 12, usePointStyle: true } };

  /* ════ INDIVIDUAL TAB ════ */
  <?php if ($learner && $learnerExams): ?>
  (function () {
    const examLabels = <?= json_encode(array_map(fn($e)=>"T{$e['term']} ".examDisp($e['exam_type'])." {$e['year']}",$learnerExams)) ?>;
    const means      = <?= json_encode(array_map(fn($e)=>$e['mean'],$learnerExams)) ?>;
    const ranks      = <?= json_encode(array_map(fn($e)=>$e['rank'],$learnerExams)) ?>;

    new Chart(document.getElementById('learnerTrendChart'), {
      type: 'line',
      data: {
        labels: examLabels,
        datasets: [
          { label: 'Mean /100', data: means, borderColor: GOLD, backgroundColor: 'rgba(240,192,64,0.10)', borderWidth: 3, pointRadius: 5, pointHoverRadius: 8, tension: 0.38, fill: true, yAxisID: 'y' },
          { label: 'Class rank', data: ranks, borderColor: 'rgba(37,99,235,0.75)', backgroundColor: 'transparent', borderWidth: 2, borderDash: [5, 4], pointRadius: 4, tension: 0.3, yAxisID: 'y2' }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: legendOpts },
        scales: {
          y:  { position: 'left', min: 0, max: 100, title: { display: true, text: 'Mean /100' } },
          y2: { position: 'right', reverse: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Rank' } }
        }
      }
    });

    const latest = <?= json_encode(array_map(fn($s)=>(int)($latestExam[$s]??0),$subjects)) ?>;
    new Chart(document.getElementById('learnerSubjectChart'), {
      type: 'bar',
      data: { labels: <?= json_encode($short) ?>, datasets: [{ label: 'Score', data: latest, backgroundColor: latest.map(bandRGBA), borderRadius: 6, borderSkipped: false }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { min: 0, max: 100 } } }
    });
  })();
  <?php endif; ?>

  /* ════ GRADE TAB ════ */
  <?php if ($gradeStudents): ?>
  (function () {
    new Chart(document.getElementById('gradeDoughnut'), {
      type: 'doughnut',
      data: {
        labels: ['Exceeding (E.E)', 'Meeting (M.E)', 'Approaching (A.E)', 'Below (B.E)'],
        datasets: [{ data: <?= json_encode(array_values($gradeBands)) ?>, backgroundColor: BAND_COLORS, borderWidth: 2, borderColor: cssVar('--surface'), hoverOffset: 8 }]
      },
      options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: legendOpts } }
    });
  })();
  <?php endif; ?>

  /* ════ SCHOOL TAB ════ */
  (function () {
    const tlEl = document.getElementById('schoolTimelineChart');
    if (tlEl) new Chart(tlEl, {
      type: 'line',
      data: {
        labels: <?= json_encode(array_column($schoolTimeline,'label')) ?>,
        datasets: [{ label: 'School mean /100', data: <?= json_encode(array_map(fn($r)=>round((float)$r['m'],1),$schoolTimeline)) ?>, borderColor: GOLD, backgroundColor: 'rgba(240,192,64,0.10)', borderWidth: 3, pointRadius: 5, pointHoverRadius: 8, tension: 0.38, fill: true }]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { maxRotation: 40, minRotation: 25 } }, y: { min: 0, max: 100 } } }
    });

    const gradeMeans = <?= json_encode(array_column($gradeRankData,'mean')) ?>;
    const gEl = document.getElementById('schoolGradeChart');
    if (gEl) new Chart(gEl, {
      type: 'bar',
      data: { labels: <?= json_encode(array_map(fn($g)=>"Grade {$g['grade']}",$gradeRankData)) ?>, datasets: [{ label: 'Mean /100', data: gradeMeans, backgroundColor: gradeMeans.map(bandRGBA), borderRadius: 6, borderSkipped: false }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { min: 0, max: 100 } } }
    });

    const bEl = document.getElementById('schoolBandDoughnut');
    if (bEl) new Chart(bEl, {
      type: 'doughnut',
      data: { labels: ['E.E', 'M.E', 'A.E', 'B.E'], datasets: [{ data: <?= json_encode(array_values($bandAll)) ?>, backgroundColor: BAND_COLORS, borderWidth: 2, borderColor: cssVar('--surface'), hoverOffset: 6 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '62%', plugins: { legend: legendOpts } }
    });

    const subjMeans = <?= json_encode(array_values($schoolSubjMeans)) ?>;
    const sEl = document.getElementById('schoolSubjChart');
    if (sEl) new Chart(sEl, {
      type: 'bar',
      data: { labels: <?= json_encode($short) ?>, datasets: [{ label: 'School mean /100', data: subjMeans, backgroundColor: subjMeans.map(bandRGBA), borderRadius: 6, borderSkipped: false }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { min: 0, max: 100, title: { display: true, text: 'Average score /100' } } } }
    });
  })();
</script>

</body>
</html>