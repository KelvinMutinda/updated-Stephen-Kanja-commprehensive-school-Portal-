<?php
// ============================================================
// UploadResults.php — teacher marks entry
// Save on the server as: UploadResults.php
// Teachers only see/enter the grades + subjects they are assigned to
// (see teacher_subject_assignments). Exam types are managed in
// ManageExamTypes.php (admin only).
// ============================================================
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

function uc_report_fatal($msg) {
    file_put_contents(__DIR__ . '/php-error.log', '[' . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    if (!headers_sent()) http_response_code(500);
    echo "<pre style='background:#fee;color:#900;padding:1em;border:2px solid #900;white-space:pre-wrap;'>";
    echo htmlspecialchars($msg);
    echo "</pre>";
}
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        uc_report_fatal(sprintf("FATAL: %s in %s on line %d", $err['message'], $err['file'], $err['line']));
    }
});

ob_start();
mysqli_report(MYSQLI_REPORT_OFF);

try { include 'conn.php'; }
catch (\Throwable $e) { ob_clean(); uc_report_fatal('conn.php threw: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine()); exit; }

if (!isset($conn) || !($conn instanceof mysqli)) {
    ob_clean();
    uc_report_fatal('conn.php did not produce a valid $conn mysqli object.');
    exit;
}

require_once 'auth_helpers.php';
requireLogin();

// Form subject key  =>  exam2 column
$subjectColMap = [
    'MATH'=>'math','ENG'=>'eng','KISW'=>'kisw','SCIE'=>'scie',
    'sst'=>'sst','ca'=>'ca','AGRI'=>'agri','re'=>'re','pretec'=>'pretec',
];
$subjectCols = array_values($subjectColMap);

// [grade => [column, ...]] this user may upload
$ALLOWED = getAllowedMap($conn);

// ============================================================
// ACTION: load student table rows (manual-entry AJAX)
// Pre-fills any marks already saved for the chosen
// term / exam / year so re-entry is simply an edit.
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] === '1' && isset($_POST['grade']) && $_POST['grade'] !== '') {

    ob_clean();
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // Authorisation: this grade must be one the user is registered for
    $gradeNum = (int)$_POST['grade'];
    if (!isset($ALLOWED[$gradeNum]) || count($ALLOWED[$gradeNum]) === 0) {
        http_response_code(403);
        echo "<tr><td colspan='5'><div class='empty-state'><div class='empty-icon'><i class=\"fa-solid fa-lock\"></i></div><p>You are not registered to upload marks for Grade " . $gradeNum . ".</p></div></td></tr>";
        ob_end_flush(); exit;
    }
    $allowedCols = $ALLOWED[$gradeNum];

    $grade    = mysqli_real_escape_string($conn, (string)$gradeNum);
    $termRaw  = trim((string)($_POST['term'] ?? ''));
    $examRaw  = trim((string)($_POST['examType'] ?? ''));
    $yearRaw  = trim((string)($_POST['year'] ?? ''));

    $query = "SELECT id, Assesment, firstName, surname
              FROM Student
              WHERE Grade = '$grade'
              ORDER BY firstName, surname";
    $result = mysqli_query($conn, $query);

    if ($result === false) {
        echo "<tr><td colspan='5'><div class='empty-state'><div class='empty-icon'><i class=\"fa-solid fa-triangle-exclamation\"></i></div><p>Database error loading students: " . htmlspecialchars(mysqli_error($conn)) . "</p></div></td></tr>";
        ob_end_flush(); exit;
    }
    if (mysqli_num_rows($result) === 0) {
        echo "<tr><td colspan='5'><div class='empty-state'><div class='empty-icon'><i class=\"fa-solid fa-user-slash\"></i></div><p>No students found for this grade.</p></div></td></tr>";
        ob_end_flush(); exit;
    }

    $existingMarks = [];
    if ($termRaw !== '' && $examRaw !== '' && $yearRaw !== '') {

        // Strip spaces / underscores / hyphens / dots and lowercase, so
        // "Term 1", "term_1" and "1" can all be recognised as the same term,
        // and "Mid Term" / "mid_term" / "midterm" as the same exam.
        $normPhp = function ($s) { return preg_replace('/[^a-z0-9]/', '', strtolower((string)$s)); };
        $normSql = function ($col) { return "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM($col),' ',''),'_',''),'-',''),'.',''))"; };
        $quoteList = function (array $vals) use ($conn) {
            $out = [];
            foreach (array_unique($vals) as $v) {
                if ($v === '') continue;
                $out[] = "'" . mysqli_real_escape_string($conn, $v) . "'";
            }
            return implode(',', $out);
        };

        // Term candidates
        $termNum = preg_replace('/\D/', '', $termRaw);
        $ordinals = ['1' => 'firstterm', '2' => 'secondterm', '3' => 'thirdterm'];
        $termCands = [$normPhp($termRaw), $termNum, 'term' . $termNum, 't' . $termNum];
        if (isset($ordinals[$termNum])) $termCands[] = $ordinals[$termNum];

        // Exam candidates: the code itself plus its display name (if any)
        $examCands = [$normPhp($examRaw)];
        $examCodeEsc = mysqli_real_escape_string($conn, $examRaw);
        $labelRes = mysqli_query($conn, "SELECT name FROM exam_types WHERE code = '$examCodeEsc' LIMIT 1");
        if ($labelRes && ($labelRow = mysqli_fetch_assoc($labelRes))) {
            $examCands[] = $normPhp($labelRow['name']);
        }

        $termIn = $quoteList($termCands);
        $examIn = $quoteList($examCands);
        $yearEsc = mysqli_real_escape_string($conn, $yearRaw);
        $termExpr = $normSql('term');
        $examExpr = $normSql('exam_type');

        $marksQuery = "SELECT student_id, math, eng, kisw, sst, scie, ca, agri, re, pretec
                        FROM exam2
                        WHERE student_id IN (SELECT id FROM Student WHERE Grade = '$grade')
                          AND $termExpr IN ($termIn)
                          AND $examExpr IN ($examIn)
                          AND TRIM(year) = '$yearEsc'";
        $marksResult = mysqli_query($conn, $marksQuery);

        if ($marksResult === false) {
            $dbErr = mysqli_error($conn);
            error_log('UploadResults marks lookup failed: ' . $dbErr);
            header('X-Marks-Error: ' . substr(preg_replace('/[\r\n]+/', ' ', $dbErr), 0, 200));
        } else {
            // A student can have several (split) rows for the same term/exam/year,
            // so merge them: for each subject, keep the latest non-empty value.
            while ($mrow = mysqli_fetch_assoc($marksResult)) {
                $sid = $mrow['student_id'];
                if (!isset($existingMarks[$sid])) {
                    $existingMarks[$sid] = $mrow;
                    continue;
                }
                foreach ($subjectCols as $c) {
                    if ($mrow[$c] !== null && $mrow[$c] !== '') {
                        $existingMarks[$sid][$c] = $mrow[$c];
                    }
                }
            }
        }
    }

    while ($row = mysqli_fetch_assoc($result)) {
        $studentIdSafe = htmlspecialchars((string)$row['id'], ENT_QUOTES);
        $assesmentSafe = htmlspecialchars((string)$row['Assesment'], ENT_QUOTES);
        $firstNameSafe = htmlspecialchars((string)$row['firstName'], ENT_QUOTES);
        $surnameSafe   = htmlspecialchars((string)$row['surname'], ENT_QUOTES);

        $existing = $existingMarks[$row['id']] ?? null;

        // Only subjects this user is registered for get an input at all
        $reals = '';
        foreach ($subjectColMap as $dataSubj => $col) {
            if (!in_array($col, $allowedCols, true)) continue;
            $val = '';
            if ($existing && $existing[$col] !== null && $existing[$col] !== '') {
                $val = htmlspecialchars((string)$existing[$col], ENT_QUOTES);
            }
            $saved = ($val !== '') ? '1' : '0';
            $reals .= "<input type='hidden' class='real-mark' name='{$dataSubj}[]' data-subj='{$dataSubj}' data-saved='{$saved}' value='{$val}'>";
        }

        echo "<tr>
            <td class='col-select'><input type='checkbox' class='learner-select' checked title='Include this learner in the submission'></td>
            <td class='col-assess'>{$assesmentSafe}<input type='hidden' name='assesment[]' value='{$assesmentSafe}'></td>
            <td class='col-name'>
                <span class='stu-name'>{$firstNameSafe} {$surnameSafe}</span><i class='fa-solid fa-circle-check saved-dot' title='Marks already saved for this term/exam/year'></i>
                <input type='hidden' name='firstName[]' value='{$firstNameSafe}'>
                <input type='hidden' name='surname[]' value='{$surnameSafe}'>
                <input type='hidden' name='studentId[]' value='{$studentIdSafe}'>
            </td>
            <td class='col-mks'><input type='number' class='mks-input' form='_none' inputmode='decimal' min='0' step='1' placeholder='—' autocomplete='off'>{$reals}</td>
            <td class='col-max'>100</td>
        </tr>";
    }

    ob_end_flush(); exit;
}

require_once 'add-exam-type.php';
$EXAM_TYPES = getExamTypes($conn);

// Grades the user may pick (only those with at least one subject)
$allowedGrades = [];
foreach ($ALLOWED as $g => $cols) { if (count($cols) > 0) $allowedGrades[] = (int)$g; }
sort($allowedGrades);
$hasAssignments = count($allowedGrades) > 0;
$isAdminUser = isAdmin();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upload Results — Stephen Kanja School</title>
  <script>
    /* Apply saved theme before first paint (shared with the other dashboards) */
    try { var th = localStorage.getItem('hoi-theme'); if (th === 'dark' || th === 'light') document.documentElement.setAttribute('data-theme', th); } catch (e) {}
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:#f3f5fa; --surface:#ffffff; --surface-2:#f8f9fc; --surface-3:#eef1f7;
      --text:#0f172a; --text-2:#475569; --muted:#8392a5;
      --border:#e4e8f0; --border-2:#eef1f6;
      --navy:#0b1f3a; --gold:#f0c040; --gold-d:#c8992a; --gold-pale:#fdf3d4; --gold-ink:#7a5a10;
      --green:#16a34a; --green-bg:#dcfce7; --red:#dc2626; --red-bg:#fee2e2; --blue:#2563eb; --blue-bg:#dbeafe;
      --shadow-sm:0 1px 2px rgba(15,23,42,.05); --shadow:0 6px 24px rgba(15,23,42,.07);
      --r:14px; --r-lg:18px; --sidebar-w:264px; --topbar-h:64px;
      color-scheme: light;
    }
    [data-theme="dark"] {
      --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
      --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3; --border:#1f2d47; --border-2:#192740;
      --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040;
      --green:#4ade80; --green-bg:rgba(34,197,94,.16); --red:#f87171; --red-bg:rgba(248,113,113,.16); --blue:#7db3f0; --blue-bg:rgba(96,165,250,.16);
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

    .app { display:grid; grid-template-columns:var(--sidebar-w) minmax(0,1fr); min-height:100vh; transition:grid-template-columns .25s; }
    body.nav-collapsed .app { grid-template-columns:0 minmax(0,1fr); }
    body.nav-collapsed .sidebar { visibility:hidden; }

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
    .burger { display:inline-flex; align-items:center; justify-content:center; background:none; border:1px solid var(--border); width:38px; height:38px; border-radius:10px; cursor:pointer; color:var(--text); }
    .burger:hover { background:var(--surface-3); }
    .tb-title { font-weight:800; font-size:1.02rem; letter-spacing:-.2px; line-height:1.2; }
    .tb-title small { display:block; font-weight:500; font-size:.72rem; color:var(--muted); }
    .tb-right { display:flex; align-items:center; gap:8px; }
    .icon-btn { width:38px; height:38px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:15px; transition:.15s; text-decoration:none; }
    .icon-btn:hover { background:var(--surface-3); color:var(--text); }
    .btn-gold { display:inline-flex; align-items:center; gap:8px; background:var(--gold); color:#101826; font-weight:700; font-size:.82rem; padding:0 16px; height:38px; border-radius:10px; text-decoration:none; border:none; cursor:pointer; box-shadow:0 6px 16px rgba(240,192,64,.3); transition:.15s; font-family:inherit; }
    .btn-gold:hover { background:#f5cf5e; transform:translateY(-1px); }

    .content { padding:24px 32px 60px; width:100%; max-width:1480px; margin:0 auto; display:flex; flex-direction:column; gap:20px; }
    .page-eyebrow { font-size:.7rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold-d); }
    .page-title { font-size:clamp(1.4rem,2.4vw,1.8rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; }
    .page-sub { color:var(--muted); font-size:.86rem; margin-top:4px; }

    /* ───────── KPI strip ───────── */
    .kpi-strip { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; }
    .kpi { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:16px 18px; box-shadow:var(--shadow-sm); position:relative; overflow:hidden; transition:transform .2s,box-shadow .2s; animation:fadeUp .45s ease both; }
    .kpi:hover { transform:translateY(-3px); box-shadow:var(--shadow); }
    .kpi::after { content:''; position:absolute; right:-26px; top:-26px; width:88px; height:88px; border-radius:50%; background:var(--accent,var(--gold)); opacity:.12; }
    .kpi .ico { width:36px; height:36px; border-radius:10px; background:color-mix(in srgb,var(--accent,var(--gold)) 16%,transparent); color:var(--accent,var(--gold-d)); display:flex; align-items:center; justify-content:center; font-size:14px; margin-bottom:10px; }
    .kpi-label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .kpi-val { font-size:1.6rem; font-weight:800; line-height:1.15; letter-spacing:-.6px; margin-top:3px; font-variant-numeric:tabular-nums; }
    .kpi-sub { font-size:.72rem; color:var(--muted); margin-top:4px; }
    .kpi.gold{--accent:#d9a521} .kpi.green{--accent:#16a34a} .kpi.blue{--accent:#2563eb} .kpi.red{--accent:#dc2626} .kpi.violet{--accent:#7c3aed}

    /* ───────── Tabs ───────── */
    .tabs { display:inline-flex; gap:4px; padding:4px; background:var(--surface-3); border:1px solid var(--border); border-radius:12px; align-self:flex-start; }
    .tab { border:none; background:transparent; color:var(--text-2); font-family:inherit; font-weight:700; font-size:.82rem; padding:9px 18px; border-radius:9px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:.15s; }
    .tab:hover { color:var(--text); }
    .tab.active { background:var(--surface); color:var(--text); box-shadow:var(--shadow-sm); }
    .tab.active i { color:var(--gold-d); }

    /* ───────── Alerts ───────── */
    .alert-bar { display:flex; align-items:flex-start; gap:10px; padding:12px 16px; border-radius:12px; font-size:.84rem; font-weight:600; background:var(--red-bg); color:var(--red); border:1px solid color-mix(in srgb,var(--red) 30%,transparent); margin-bottom:16px; }
    .alert-bar.ok { background:var(--green-bg); color:var(--green); border-color:color-mix(in srgb,var(--green) 30%,transparent); }
    .alert-bar.warn { background:var(--gold-pale); color:var(--gold-ink); border-color:color-mix(in srgb,var(--gold-d) 35%,transparent); }
    .alert-bar i { margin-top:3px; }

    /* ───────── Filters ───────── */
    .filters { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:18px 20px; box-shadow:var(--shadow-sm); display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:14px; align-items:end; margin-bottom:16px; animation:fadeUp .45s ease both; }
    .field { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .field.span2 { grid-column:span 2; }
    .field label, .picker-title, .excel-label, .sort-title { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .field select, .txt { appearance:none; width:100%; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; padding:10px 14px; font-family:inherit; font-size:.88rem; font-weight:500; color:var(--text); transition:.15s; height:42px; }
    .field select { background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; padding-right:38px; cursor:pointer; }
    .field select:hover, .txt:hover { border-color:rgba(37,99,235,.35); }
    .field select:focus, .txt:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .field select:disabled { opacity:.55; cursor:not-allowed; }
    .field-hint { font-size:.7rem; color:var(--muted); }

    /* ───────── Buttons ───────── */
    .btn { background:var(--navy); color:#fff; border:none; border-radius:10px; padding:0 20px; height:42px; font-family:inherit; font-size:.84rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; white-space:nowrap; }
    [data-theme="dark"] .btn { background:var(--gold); color:#101826; }
    .btn:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 6px 18px rgba(11,31,58,.25); }
    .btn:active:not(:disabled) { transform:scale(.98); }
    .btn:disabled { opacity:.5; cursor:not-allowed; }
    .btn-primary { background:var(--gold); color:#101826; box-shadow:0 8px 20px rgba(240,192,64,.28); }
    [data-theme="dark"] .btn-primary { background:var(--gold); color:#101826; }
    .btn-ghost { background:transparent; color:var(--text-2); border:1.5px solid var(--border); }
    [data-theme="dark"] .btn-ghost { background:transparent; color:var(--text-2); }
    .btn-ghost:hover:not(:disabled) { background:var(--surface-3); box-shadow:none; }

    .chips { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:16px; }
    .chip { font-size:.72rem; font-weight:700; padding:5px 12px; border-radius:99px; background:var(--navy); color:#fff; }
    [data-theme="dark"] .chip { background:var(--surface-3); color:var(--text); }
    .chip.muted { background:var(--surface-3); color:var(--muted); }

    /* ───────── Mode toggle ───────── */
    .mode-toggle { display:inline-flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
    .mode-btn { border:1.5px solid var(--border); background:var(--surface-2); color:var(--text-2); font-family:inherit; font-size:.76rem; font-weight:700; padding:7px 15px; border-radius:99px; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; gap:7px; }
    .mode-btn:hover { border-color:var(--gold); }
    .mode-btn.active { background:var(--navy); color:#fff; border-color:var(--navy); }
    [data-theme="dark"] .mode-btn.active { background:var(--gold); color:#101826; border-color:var(--gold); }

    .status-bar { display:none; align-items:center; gap:10px; padding:11px 16px; border-radius:12px; font-size:.82rem; font-weight:600; background:var(--blue-bg); color:var(--blue); margin-bottom:16px; }
    .status-bar.visible { display:flex; }
    .status-bar.status-error { background:var(--red-bg); color:var(--red); }

    /* ───────── Cards ───────── */
    .entry { display:grid; grid-template-columns:minmax(0,1fr) 320px; gap:18px; align-items:start; }
    .box { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-sm); animation:fadeUp .45s .05s ease both; min-width:0; }
    .box-head { padding:15px 22px; border-bottom:1px solid var(--border-2); display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; }
    .box-title { font-size:.76rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); display:flex; align-items:center; gap:9px; }
    .box-title i { color:var(--gold-d); }
    .badge { font-size:.66rem; font-weight:800; letter-spacing:.9px; text-transform:uppercase; padding:3px 10px; border-radius:99px; background:var(--gold-pale); color:var(--gold-ink); margin-right:auto; }
    .marks-progress { font-size:.76rem; color:var(--muted); }
    .marks-progress strong { color:var(--text); }

    .search-bar { display:flex; align-items:center; gap:12px; padding:12px 22px; border-bottom:1px solid var(--border-2); flex-wrap:wrap; }
    .search-bar.disabled { opacity:.6; }
    .search-wrap { position:relative; flex:1; max-width:340px; min-width:200px; }
    .search-wrap > i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:.8rem; pointer-events:none; }
    .search-wrap input { width:100%; height:36px; padding:0 34px 0 34px; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; font-family:inherit; font-size:.82rem; color:var(--text); }
    .search-wrap input:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    #learnerSearchClear { display:none; position:absolute; right:6px; top:50%; transform:translateY(-50%); width:24px; height:24px; border:none; background:none; color:var(--muted); cursor:pointer; border-radius:6px; }
    #learnerSearchClear.visible { display:block; }
    #learnerSearchClear:hover { background:var(--surface-3); color:var(--text); }
    .search-count { font-size:.72rem; color:var(--muted); }

    /* ───────── Marks tables ───────── */
    .table-scroll { overflow:auto; max-height:560px; -webkit-overflow-scrolling:touch; }
    table.marks { width:100%; border-collapse:collapse; font-size:.83rem; }
    .marks thead th { background:var(--surface-2); color:var(--muted); font-size:.64rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; padding:11px 12px; text-align:left; white-space:nowrap; border-bottom:1px solid var(--border); position:sticky; top:0; z-index:1; }
    .marks tbody tr { border-bottom:1px solid var(--border-2); transition:background .12s; }
    .marks tbody tr:hover td { background:var(--surface-2); }
    .marks tbody tr:last-child { border-bottom:none; }
    .marks td { padding:8px 12px; vertical-align:middle; color:var(--text-2); font-variant-numeric:tabular-nums; }
    .col-select { width:42px; text-align:center !important; }
    .col-assess { width:100px; font-family:ui-monospace,Menlo,monospace; font-size:.76rem; font-weight:600; }
    .col-name { min-width:180px; }
    .stu-name { font-weight:600; color:var(--text); }
    .col-mks { width:130px; }
    .col-max { width:70px; color:var(--muted); }
    .marks input[type="checkbox"], .subject-check input { accent-color:var(--gold-d); width:16px; height:16px; cursor:pointer; }
    .mks-input { width:96px; height:36px; padding:0 10px; background:var(--surface-2); border:1.5px solid var(--border); border-radius:9px; font-family:inherit; font-size:.86rem; font-weight:600; color:var(--text); outline:none; transition:.15s; -moz-appearance:textfield; }
    .mks-input::-webkit-inner-spin-button, .mks-input::-webkit-outer-spin-button { -webkit-appearance:none; }
    .mks-input:hover { border-color:rgba(37,99,235,.35); }
    .mks-input:focus { border-color:var(--gold); background:var(--surface); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .mks-input.invalid { border-color:var(--red); background:var(--red-bg); }
    .saved-dot { display:none; margin-left:8px; color:var(--green); font-size:.78rem; }
    tr.has-saved .saved-dot { display:inline; }
    tr.has-saved td { background:color-mix(in srgb,var(--green-bg) 55%,transparent); }
    tr.learner-deselected td { opacity:.45; }
    tr.row-no-match { display:none; }
    .multi-panel { display:none; }
    .entry.multi .multi-panel { display:block; }
    .entry.multi #singleTable { display:none; }
    .multi-marks .mks-input { width:84px; }
    .multi-picker { padding:16px 22px; border-bottom:1px solid var(--border-2); background:var(--surface-2); }
    .picker-title { margin-bottom:10px; }

    .empty-state { text-align:center; padding:56px 20px; }
    .empty-icon { width:64px; height:64px; border-radius:18px; margin:0 auto 14px; display:flex; align-items:center; justify-content:center; background:var(--gold-pale); color:var(--gold-d); font-size:24px; }
    .empty-state p { font-size:.86rem; color:var(--muted); max-width:420px; margin:4px auto 0; }
    .spinner { display:none; text-align:center; padding:40px; color:var(--muted); font-size:.86rem; }
    .spinner i { color:var(--gold-d); margin-right:8px; }

    .submit-bar { display:none; align-items:center; justify-content:flex-end; gap:10px; padding:14px 22px; border-top:1px solid var(--border-2); background:var(--surface-2); border-radius:0 0 var(--r-lg) var(--r-lg); flex-wrap:wrap; }

    .subject-checkbox-row { display:flex; flex-wrap:wrap; gap:8px; }
    .subject-check { display:inline-flex; align-items:center; gap:8px; padding:7px 13px; border:1.5px solid var(--border); background:var(--surface); border-radius:99px; font-size:.8rem; font-weight:600; color:var(--text-2); cursor:pointer; transition:.15s; }
    .subject-check:hover { border-color:var(--gold); }
    .subject-check.locked { opacity:.4; cursor:not-allowed; }
    .subject-select-actions { display:flex; gap:8px; margin-top:12px; }
    .subject-select-actions .btn { height:34px; font-size:.76rem; padding:0 14px; }

    /* ───────── Performance panel ───────── */
    .perf { padding:20px; position:sticky; top:calc(var(--topbar-h) + 16px); }
    .perf h3 { font-size:.76rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); margin-bottom:14px; }
    .stats { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
    .stats > div { background:var(--surface-2); border:1px solid var(--border-2); border-radius:12px; padding:11px 13px; }
    .stat-label { font-size:.6rem; font-weight:800; letter-spacing:1.1px; text-transform:uppercase; color:var(--muted); }
    .stat-val { font-size:1.3rem; font-weight:800; letter-spacing:-.4px; font-variant-numeric:tabular-nums; }
    .bands-title { margin:18px 0 8px; font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    table.bands { width:100%; border-collapse:collapse; text-align:center; font-size:.78rem; }
    .bands th { font-size:.62rem; font-weight:800; color:var(--muted); padding:5px 0; border-bottom:1px solid var(--border); }
    .bands td { padding:7px 0; font-weight:700; color:var(--text); }
    .sort-opts { margin-top:18px; display:flex; flex-direction:column; gap:7px; padding-top:16px; border-top:1px solid var(--border-2); }
    .sort-opts label { display:flex; align-items:center; gap:8px; font-size:.82rem; color:var(--text-2); cursor:pointer; }
    .sort-opts input { accent-color:var(--gold-d); }

    /* ───────── Excel tab ───────── */
    .sub-box-body { padding:18px 22px; }
    .sub-box-body p { font-size:.84rem; color:var(--text-2); margin-bottom:14px; max-width:820px; }
    .excel-actions { display:flex; gap:28px; padding:18px 22px 22px; border-top:1px solid var(--border-2); background:var(--surface-2); border-radius:0 0 var(--r-lg) var(--r-lg); flex-wrap:wrap; }
    .excel-col { display:flex; flex-direction:column; gap:10px; align-items:flex-start; }
    .excel-col-grow { flex:1; min-width:280px; }
    .format-toggle-row { display:flex; gap:8px; }
    .excel-col form { width:100%; }
    .excel-upload-row { display:flex; gap:10px; flex-wrap:wrap; }
    .file-input-label { flex:1; min-width:220px; display:flex; align-items:center; gap:10px; height:42px; padding:0 14px; border:1.5px dashed var(--border); border-radius:10px; background:var(--surface); color:var(--text-2); font-size:.84rem; cursor:pointer; transition:.15s; overflow:hidden; }
    .file-input-label:hover { border-color:var(--gold); background:var(--gold-pale); }
    .file-input-label i { color:var(--gold-d); }
    .file-input-label span { white-space:nowrap; text-overflow:ellipsis; overflow:hidden; }
    .file-input-label input { display:none; }

    /* ───────── Preview modal ───────── */
    .modal-overlay { display:none; position:fixed; inset:0; z-index:200; background:rgba(3,10,22,.6); backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:20px; }
    .modal-overlay.active { display:flex; }
    .modal { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:0 30px 80px rgba(0,0,0,.4); width:100%; max-width:860px; max-height:88vh; display:flex; flex-direction:column; animation:fadeUp .25s ease both; }
    .modal-head { display:flex; justify-content:space-between; align-items:center; padding:16px 22px; border-bottom:1px solid var(--border-2); font-weight:800; font-size:.95rem; }
    .modal-head i { color:var(--gold-d); margin-right:10px; }
    .modal-x { width:34px; height:34px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); border-radius:9px; cursor:pointer; }
    .modal-x:hover { background:var(--surface-3); }
    .modal-body { padding:18px 22px; overflow:auto; flex:1; }
    .modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 22px; border-top:1px solid var(--border-2); background:var(--surface-2); border-radius:0 0 var(--r-lg) var(--r-lg); }
    .pv-summary { font-size:.86rem; color:var(--text-2); margin-bottom:12px; }
    .pv-note, .pv-empty { padding:11px 14px; border-radius:10px; background:var(--gold-pale); color:var(--gold-ink); font-size:.8rem; font-weight:600; margin-bottom:12px; }
    .pv-note i { margin-right:8px; }
    .pv-empty { text-align:center; padding:28px; }
    table.pv { width:100%; border-collapse:collapse; font-size:.82rem; }
    .pv th { background:var(--surface-2); color:var(--muted); font-size:.64rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; padding:9px 12px; text-align:left; border-bottom:1px solid var(--border); position:sticky; top:0; }
    .pv td { padding:8px 12px; border-bottom:1px solid var(--border-2); color:var(--text-2); font-variant-numeric:tabular-nums; }
    .pv td:first-child { color:var(--text); font-weight:600; }
    .pv tfoot td { font-weight:800; background:var(--surface-2); color:var(--text); }
    .mp-blank { color:var(--muted); }

    .footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (max-width:1100px) { .entry { grid-template-columns:1fr; } .perf { position:static; } }
    @media (max-width:1024px) {
      .app, body.nav-collapsed .app { grid-template-columns:1fr; }
      body.nav-collapsed .sidebar { visibility:visible; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); }
      .sidebar.open { left:0; }
      .topbar { padding:0 18px; } .content { padding:18px 18px 50px; }
    }
    @media (max-width:640px) {
      .btn-gold span { display:none; } .tb-title small { display:none; }
      .filters { grid-template-columns:1fr 1fr; } .field.span2 { grid-column:1/-1; }
      .submit-bar { flex-direction:column; align-items:stretch; } .submit-bar .btn { width:100%; }
      .excel-actions { gap:20px; } .tab { padding:9px 12px; }
    }
    @media (max-width:420px) { .filters { grid-template-columns:1fr; } }

    @media print {
      .sidebar, .topbar, .filters, .submit-bar, .backdrop, .icon-btn, .btn-gold, .tabs, .modal-overlay { display:none !important; }
      .app { display:block; } body { background:#fff; } .content { padding:0; } .table-scroll { max-height:none; overflow:visible; }
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
        <a href="teacherpanel"><i class="fa-solid fa-house"></i> Home</a>
        <a href="Students"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="Progress"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
        <a href="generate_template"><i class="fa-solid fa-chart-column"></i> Get Marks template</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults" class="active" aria-current="page"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="send_results_sms"><i class="fa-solid fa-comment-sms"></i> Send Results SMS</a>
        <a href="track"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
        <a href="LearningMaterials"><i class="fa-solid fa-book"></i> Learning Materials</a>
      </div>
      <div class="nav-section">
        <div class="nav-title">Administration</div>
        <?php if ($isAdminUser): ?>
        <a href="RegisterTeacher.php"><i class="fa-solid fa-chalkboard-user"></i> Register Teachers</a>
        <a href="ManageExamTypes.php"><i class="fa-solid fa-clipboard-list"></i> Add Exam Types</a>
        <?php endif; ?>
        <a href="RegisterStudent"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
        <a href="manage_parent_contacts"><i class="fa-solid fa-address-book"></i> Parent Contacts</a>
      </div>
    </nav>

    <div class="side-user">
      <div class="av"><?= $isAdminUser ? 'AD' : 'TR' ?></div>
      <div><b><?= $isAdminUser ? 'Administrator' : 'Teacher' ?></b><small>Signed in</small></div>
      <a href="logout" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>
  <div class="backdrop" id="backdrop" onclick="closeSidebar()"></div>

  <!-- ═══════ MAIN ═══════ -->
  <div class="main">

    <header class="topbar">
      <div class="tb-left">
        <button class="burger" id="burger" type="button" aria-label="Toggle menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
        <div class="tb-title">Upload Results<small>Stephen Kanja Primary &amp; Junior School</small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="ViewResults" class="btn-gold"><i class="fa-solid fa-chart-pie"></i><span>View Results</span></a>
        <a href="logout" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <div>
        <p class="page-eyebrow">Academics Module</p>
        <h1 class="page-title">Upload Learner Results</h1>
        <p class="page-sub">Pick grade, term, year and exam, then type marks in or import them from a spreadsheet.</p>
      </div>

      <!-- Summary KPIs (live, driven by the marks entered below) -->
      <div class="kpi-strip">
        <div class="kpi blue" style="animation-delay:.03s">
          <div class="ico"><i class="fa-solid fa-user-graduate"></i></div>
          <div class="kpi-label">Learners Loaded</div>
          <div class="kpi-val" id="kpiLearners">—</div>
          <div class="kpi-sub">in selected grade</div>
        </div>
        <div class="kpi gold" style="animation-delay:.06s">
          <div class="ico"><i class="fa-solid fa-pen-to-square"></i></div>
          <div class="kpi-label">Marks Entered</div>
          <div class="kpi-val" id="kpiEntered">0</div>
          <div class="kpi-sub" id="kpiEnteredSub">for the current subject</div>
        </div>
        <div class="kpi green" style="animation-delay:.09s">
          <div class="ico"><i class="fa-solid fa-bullseye"></i></div>
          <div class="kpi-label">Average</div>
          <div class="kpi-val" id="kpiAvg">—</div>
          <div class="kpi-sub" id="kpiLevel">level —</div>
        </div>
        <div class="kpi violet" style="animation-delay:.12s">
          <div class="ico"><i class="fa-solid fa-circle-check"></i></div>
          <div class="kpi-label">Already Saved</div>
          <div class="kpi-val" id="kpiSaved">0</div>
          <div class="kpi-sub">learners with saved marks</div>
        </div>
      </div>

      <div class="tabs" role="tablist">
        <button type="button" class="tab active" id="tabManual" onclick="setUploadMode('manual')">
          <i class="fa-solid fa-pen"></i> Enter Marks
        </button>
        <button type="button" class="tab" id="tabExcel" onclick="setUploadMode('excel')">
          <i class="fa-solid fa-file-excel"></i> Bulk Excel Upload
        </button>
      </div>

      <div>
      <?php if (!$hasAssignments): ?>
      <div class="alert-bar warn">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>You have not been registered for any grade or subject yet, so you can't upload marks. Please ask the administrator to assign your grades and subjects.</span>
      </div>
      <?php endif; ?>

      <!-- ═════════ MANUAL ENTRY ═════════ -->
      <div id="manualEntrySection">
        <form method="POST" action="/upload" id="resultsForm">

          <?php if (isset($_GET['upload_msg'])): ?>
          <div class="alert-bar <?= ($_GET['upload_status'] ?? '') === 'error' ? '' : 'ok' ?>">
            <i class="fa-solid <?= ($_GET['upload_status'] ?? '') === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
            <span><?= htmlspecialchars($_GET['upload_msg']) ?></span>
          </div>
          <?php endif; ?>

          <div class="filters">
            <div class="field">
              <label for="gradeSelect">Grade</label>
              <select id="gradeSelect" name="grade" required>
                <option value="">Select grade</option>
                <?php foreach ($allowedGrades as $g) echo "<option value='$g'>Grade $g</option>"; ?>
              </select>
            </div>

            <div class="field">
              <label for="subjectSelect">Subject</label>
              <select id="subjectSelect" form="_none" disabled>
                <option value="">Select a grade first</option>
              </select>
            </div>

            <div class="field">
              <label for="termSelect">Term</label>
              <select id="termSelect" name="term" required>
                <option value="">Select term</option>
                <option value="1">Term 1</option>
                <option value="2">Term 2</option>
                <option value="3">Term 3</option>
              </select>
            </div>

            <div class="field">
              <label for="yearSelect">Year</label>
              <select id="yearSelect" name="year" required>
                <option value="">Select year</option>
                <?php for ($y = 2026; $y <= 2030; $y++) echo "<option value='$y'>$y</option>"; ?>
              </select>
            </div>

            <div class="field span2">
              <label for="examSelect">Exam name</label>
              <select id="examSelect" name="examType" required>
                <option value="">Select exam</option>
                <?php foreach ($EXAM_TYPES as $code => $et): ?>
                <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($et['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if ($isAdminUser): ?>
              <span class="field-hint"><a href="ManageExamTypes.php" style="color:var(--gold-d);font-weight:600;text-decoration:none;"><i class="fa-solid fa-plus"></i> Add or remove exam types</a></span>
              <?php endif; ?>
            </div>

            <div class="field">
              <label for="outOfInput">Exam out of</label>
              <input type="number" class="txt" id="outOfInput" form="_none" value="100" min="1" step="any" placeholder="Max score e.g. 100">
              <span class="field-hint">Saved out of 100 — other values are converted.</span>
            </div>
          </div>

          <div class="chips" id="ctxChips"><span class="chip muted">Pick a grade, term, year and exam to begin</span></div>

          <div class="mode-toggle" role="tablist" aria-label="Entry mode">
            <button type="button" class="mode-btn active" id="modeSingle"><i class="fa-solid fa-book"></i> One subject</button>
            <button type="button" class="mode-btn" id="modeMulti"><i class="fa-solid fa-layer-group"></i> Multiple subjects</button>
          </div>

          <div class="status-bar" id="statusBar" role="status">
            <i class="fa-solid fa-circle-info" id="statusIcon"></i>
            <span id="statusText"></span>
          </div>

          <div class="entry" id="entryWrap">

            <div class="box">
              <div class="box-head">
                <span class="box-title"><i class="fa-solid fa-table"></i><span id="subjectTitle">Marks</span>&nbsp;marks</span>
                <span class="badge" id="studentCount">0 students</span>
                <span class="marks-progress" id="marksProgress" style="display:none;">
                  <strong id="marksProgressCount">0</strong> of <span id="marksProgressTotal">0</span> marks filled
                </span>
              </div>

              <div class="search-bar disabled" id="learnerSearchBar">
                <div class="search-wrap">
                  <i class="fa-solid fa-magnifying-glass"></i>
                  <input type="text" id="learnerSearch" placeholder="Select a grade first…" autocomplete="off" disabled>
                  <button type="button" id="learnerSearchClear" title="Clear search" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <span class="search-count" id="learnerSearchCount"></span>
              </div>

              <div class="table-scroll">
                <div class="spinner" id="spinner"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading students…</div>
                <table class="marks" id="singleTable">
                  <thead>
                    <tr>
                      <th class="col-select"><input type="checkbox" id="selectAllLearners" checked title="Select / deselect all learners"></th>
                      <th class="col-assess">Adm.</th>
                      <th class="col-name">Student</th>
                      <th class="col-mks">Marks</th>
                      <th class="col-max">Max</th>
                    </tr>
                  </thead>
                  <tbody id="studentTable">
                    <tr><td colspan="5">
                      <div class="empty-state">
                        <div class="empty-icon"><i class="fa-solid fa-arrow-up-from-bracket"></i></div>
                        <p>Select a grade above to load students</p>
                      </div>
                    </td></tr>
                  </tbody>
                </table>
              </div>

              <!-- Multi-subject entry panel -->
              <div class="multi-panel" id="multiPanel">
                <div class="multi-picker">
                  <p class="picker-title">Subjects to enter together</p>
                  <div class="subject-checkbox-row">
                    <label class="subject-check"><input type="checkbox" class="multi-cb" value="MATH" checked> Mathematics</label>
                    <label class="subject-check"><input type="checkbox" class="multi-cb" value="ENG" checked> English</label>
                    <label class="subject-check"><input type="checkbox" class="multi-cb" value="KISW" checked> Kiswahili</label>
                    <label class="subject-check"><input type="checkbox" class="multi-cb" value="SCIE" checked> Science</label>
                    <label class="subject-check"><input type="checkbox" class="multi-cb" value="sst" checked> Social Studies</label>
                    <label class="subject-check"><input type="checkbox" class="multi-cb" value="ca"> C/A</label>
                    <label class="subject-check"><input type="checkbox" class="multi-cb" value="AGRI"> Agriculture</label>
                    <label class="subject-check"><input type="checkbox" class="multi-cb" value="re"> RE</label>
                    <label class="subject-check"><input type="checkbox" class="multi-cb" value="pretec"> Pre-Technical</label>
                  </div>
                  <div class="subject-select-actions">
                    <button type="button" class="btn btn-ghost" id="multiAll"><i class="fa-solid fa-check-double"></i> Select all</button>
                    <button type="button" class="btn btn-ghost" id="multiNone"><i class="fa-solid fa-xmark"></i> Clear all</button>
                  </div>
                </div>
                <div class="table-scroll">
                  <table class="marks multi-marks">
                    <thead id="multiHead"></thead>
                    <tbody id="multiBody"></tbody>
                  </table>
                </div>
              </div>

              <div class="submit-bar" id="submitSection">
                <button type="button" class="btn btn-ghost" onclick="clearTable()">
                  <i class="fa-solid fa-rotate-left"></i> Clear <span class="subj-word">this subject</span>
                </button>
                <button type="button" class="btn btn-primary" onclick="openMarksPreview()">
                  <i class="fa-solid fa-eye"></i> Preview &amp; submit
                </button>
              </div>
            </div>

            <aside class="box perf">
              <h3>Performance — <span id="perfSubject">Marks</span></h3>
              <div class="stats">
                <div><div class="stat-label">Avg. marks</div><div class="stat-val" id="statAvg">—</div></div>
                <div><div class="stat-label">Avg. points</div><div class="stat-val" id="statPts">—</div></div>
                <div><div class="stat-label">Level</div><div class="stat-val" id="statLevel">—</div></div>
                <div><div class="stat-label">Entered</div><div class="stat-val" id="statEntered">0 / 0</div></div>
              </div>
              <p class="bands-title">Levels breakdown</p>
              <table class="bands">
                <thead><tr id="bandHead"></tr></thead>
                <tbody><tr id="bandCounts"></tr></tbody>
              </table>
              <div class="sort-opts">
                <span class="sort-title">Order learners by</span>
                <label><input type="radio" name="sortBy" value="name" checked> Name</label>
                <label><input type="radio" name="sortBy" value="adm"> Admission no.</label>
                <label><input type="radio" name="sortBy" value="perf"> Performance (highest first)</label>
              </div>
            </aside>

          </div>
        </form>

        <div class="modal-overlay" id="marksPreviewOverlay">
          <div class="modal" role="dialog" aria-modal="true" aria-label="Review before submitting">
            <div class="modal-head">
              <span><i class="fa-solid fa-eye"></i>Review before submitting</span>
              <button type="button" class="modal-x" onclick="closeMarksPreview()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body" id="marksPreviewBody"></div>
            <div class="modal-foot">
              <button type="button" class="btn btn-ghost" onclick="closeMarksPreview()"><i class="fa-solid fa-pen"></i> Back to edit</button>
              <button type="button" class="btn btn-primary" id="confirmSubmitBtn" onclick="confirmSubmit()"><i class="fa-solid fa-floppy-disk"></i> Confirm &amp; submit</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ═════════ BULK EXCEL UPLOAD ═════════ -->
      <div id="excelSection" style="display:none;">

        <?php if (isset($_GET['excel_msg'])): ?>
        <div class="alert-bar <?= ($_GET['excel_status'] ?? '') === 'error' ? '' : 'ok' ?>">
          <i class="fa-solid <?= ($_GET['excel_status'] ?? '') === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
          <span><?= htmlspecialchars($_GET['excel_msg']) ?></span>
        </div>
        <?php endif; ?>

        <div class="filters">
          <div class="field">
            <label for="excelGrade">Grade</label>
            <select id="excelGrade" required>
              <option value="">Select grade</option>
              <?php foreach ($allowedGrades as $g) echo "<option value='$g'>Grade $g</option>"; ?>
            </select>
          </div>

          <div class="field">
            <label for="excelTerm">Term</label>
            <select id="excelTerm" required>
              <option value="">Select term</option>
              <option value="1">Term 1</option>
              <option value="2">Term 2</option>
              <option value="3">Term 3</option>
            </select>
          </div>

          <div class="field">
            <label for="excelYear">Year</label>
            <select id="excelYear" required>
              <option value="">Select year</option>
              <?php for ($y = 2026; $y <= 2030; $y++) echo "<option value='$y'>$y</option>"; ?>
            </select>
          </div>

          <div class="field">
            <label for="excelExamType">Exam name</label>
            <select id="excelExamType" required>
              <option value="">Select exam</option>
              <?php foreach ($EXAM_TYPES as $code => $et): ?>
              <option value="<?= htmlspecialchars($code) ?>"><?= htmlspecialchars($et['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($isAdminUser): ?>
            <span class="field-hint"><a href="ManageExamTypes.php" style="color:var(--gold-d);font-weight:600;text-decoration:none;"><i class="fa-solid fa-plus"></i> Add or remove exam types</a></span>
            <?php endif; ?>
          </div>
        </div>

        <div class="box">
          <div class="box-head"><span class="box-title"><i class="fa-solid fa-list-check"></i>Subjects to upload</span></div>

          <div class="sub-box-body">
            <p>
              Pick a grade first — only the subjects you are registered to teach in that grade can be ticked.
              Choose one subject to grade a single paper, or several to grade multiple subjects from the same
              spreadsheet in one pass. Rows are matched back to a learner mainly by <strong>First Name + Surname</strong>
              within the selected grade, so please don't edit those two columns in the template — Assessment No is only
              used to break a tie if two learners in the grade share the same name.
            </p>

            <div class="subject-checkbox-row">
              <label class="subject-check"><input type="checkbox" class="subj-cb" value="math" checked> Mathematics</label>
              <label class="subject-check"><input type="checkbox" class="subj-cb" value="eng"> English</label>
              <label class="subject-check"><input type="checkbox" class="subj-cb" value="kisw"> Kiswahili</label>
              <label class="subject-check"><input type="checkbox" class="subj-cb" value="scie"> Science</label>
              <label class="subject-check"><input type="checkbox" class="subj-cb" value="sst"> Social Studies</label>
              <label class="subject-check"><input type="checkbox" class="subj-cb" value="ca"> C/A</label>
              <label class="subject-check"><input type="checkbox" class="subj-cb" value="agri"> Agriculture</label>
              <label class="subject-check"><input type="checkbox" class="subj-cb" value="re"> RE</label>
              <label class="subject-check"><input type="checkbox" class="subj-cb" value="pretec"> Pre-Technical</label>
            </div>

            <div class="subject-select-actions">
              <button type="button" class="btn btn-ghost" id="selectAllSubjects"><i class="fa-solid fa-check-double"></i> Select all</button>
              <button type="button" class="btn btn-ghost" id="clearAllSubjects"><i class="fa-solid fa-xmark"></i> Clear all</button>
            </div>
          </div>

          <div class="excel-actions">
            <div class="excel-col">
              <p class="excel-label">Step 1 — Get the template</p>
              <div class="format-toggle-row" role="radiogroup" aria-label="Template file format">
                <label class="subject-check"><input type="radio" name="templateFormat" value="csv" checked> CSV</label>
                <label class="subject-check"><input type="radio" name="templateFormat" value="xlsx"> Excel (.xlsx)</label>
              </div>
              <button type="button" class="btn btn-primary" id="downloadTemplateBtn"><i class="fa-solid fa-download"></i> Download template</button>
            </div>

            <div class="excel-col excel-col-grow">
              <p class="excel-label">Step 2 — Upload the completed file</p>
              <form action="https://stephenkanjaportal.infinityfreeapp.com/upload_excel_marks" method="POST" enctype="multipart/form-data" id="excelUploadForm">
                <input type="hidden" name="grade" id="excelGradeHidden">
                <input type="hidden" name="term" id="excelTermHidden">
                <input type="hidden" name="examType" id="excelExamTypeHidden">
                <input type="hidden" name="year" id="excelYearHidden">
                <div class="excel-upload-row">
                  <label class="file-input-label" id="fileInputLabel">
                    <i class="fa-solid fa-file-arrow-up"></i>
                    <span id="fileNameLabel">Choose completed .csv or .xlsx file…</span>
                    <input type="file" name="marksFile" id="marksFileInput" accept=".csv,.xlsx" required>
                  </label>
                  <button type="submit" class="btn btn-primary" id="uploadExcelBtn"><i class="fa-solid fa-cloud-arrow-up"></i> Upload marks</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
      </div>

    </main>

    <div class="footer">&copy; Designed by <strong>Kelvin Mutinda</strong> 2026. All rights reserved.</div>
  </div>
</div>

<script>

  /* ══════════════════ Sidebar + theme ══════════════════ */
  const sidebar  = document.getElementById('sidebar');
  const backdrop = document.getElementById('backdrop');
  const burger   = document.getElementById('burger');
  const isMobile = () => window.matchMedia('(max-width: 1024px)').matches;

  function setMenuIcon(open) {
    burger.innerHTML = '<i class="fa-solid ' + (open ? 'fa-xmark' : 'fa-bars') + '"></i>';
    burger.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  function closeSidebar() {
    sidebar.classList.remove('open');
    backdrop.classList.remove('show');
    document.body.style.overflow = '';
    setMenuIcon(false);
  }
  burger.addEventListener('click', function () {
    if (isMobile()) {
      const open = sidebar.classList.toggle('open');
      backdrop.classList.toggle('show', open);
      document.body.style.overflow = open ? 'hidden' : '';
      setMenuIcon(open);
    } else {
      document.body.classList.toggle('nav-collapsed');
    }
  });
  sidebar.querySelectorAll('nav a').forEach(a => a.addEventListener('click', () => { if (isMobile()) closeSidebar(); }));
  window.addEventListener('resize', () => { if (!isMobile()) closeSidebar(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeSidebar(); closeMarksPreview(); }
  });

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

  /* Context chips + KPI strip (read-only helpers; they never change the marks data) */
  function renderContext() {
    const g = $('#gradeSelect').val(), t = $('#termSelect').val(), y = $('#yearSelect').val();
    const exam = $('#examSelect option:selected').text();
    const has = g || t || y || $('#examSelect').val();
    $('#ctxChips').html(has
      ? '<span class="chip">' + (g ? 'Grade ' + esc(g) : 'Grade —') + '</span>' +
        '<span class="chip">' + (t ? 'Term ' + esc(t) : 'Term —') + '</span>' +
        '<span class="chip">' + (y ? esc(y) : 'Year —') + '</span>' +
        '<span class="chip">' + ($('#examSelect').val() ? esc(exam) : 'Exam —') + '</span>'
      : '<span class="chip muted">Pick a grade, term, year and exam to begin</span>');
  }
  function refreshKpis() {
    const n = $rows().length;
    $('#kpiLearners').text(n ? n : '—');
    $('#kpiEntered').text($('#statEntered').text().split('/')[0].trim());
    $('#kpiEnteredSub').text('of ' + n + ' for ' + ($('#subjectTitle').text() || 'this subject'));
    $('#kpiAvg').text($('#statAvg').text());
    const lv = $('#statLevel').text();
    $('#kpiLevel').text('level ' + lv);
    $('#kpiSaved').text($rows().filter('.has-saved').length);
  }


  /* ══════════════════ Subjects, bands, state ══════════════════ */
  const SUBJECTS = {
    MATH:'Mathematics', ENG:'English', KISW:'Kiswahili', SCIE:'Science',
    sst:'Social Studies', ca:'C/A', AGRI:'Agriculture', re:'RE', pretec:'Pre-Technical'
  };
  const SUBJECT_ORDER = Object.keys(SUBJECTS);

  // form key -> exam2 column
  const COL_OF = <?= json_encode($subjectColMap) ?>;
  // grade -> [exam2 columns] this user is registered for (enforced again on the server)
  const ALLOWED = <?= json_encode((object)array_map('array_values', $ALLOWED)) ?>;

  const BANDS = [
    { code:'EE1', min:90, pts:8 }, { code:'EE2', min:75, pts:7 },
    { code:'ME1', min:58, pts:6 }, { code:'ME2', min:41, pts:5 },
    { code:'AE1', min:31, pts:4 }, { code:'AE2', min:21, pts:3 },
    { code:'BE1', min:11, pts:2 }, { code:'BE2', min:0,  pts:1 }
  ];
  const bandOf = p => BANDS.find(b => p >= b.min) || BANDS[BANDS.length - 1];

  let currentSubject = '';
  let outOf = 100;
  let multiMode = false;
  const esc = s => $('<div>').text(s == null ? '' : s).html();

  /* Subjects the logged-in user may enter for a grade, in display order */
  function allowedFor(grade) {
    const cols = ALLOWED[grade] || [];
    return SUBJECT_ORDER.filter(k => cols.indexOf(COL_OF[k]) !== -1);
  }
  const allowedSubjects = () => allowedFor($('#gradeSelect').val());

  /* Rebuild the single-subject dropdown + multi-subject tick boxes for the chosen grade */
  function rebuildSubjectControls() {
    const ok = allowedSubjects();
    const $sel = $('#subjectSelect');

    if (!ok.length) {
      $sel.html('<option value="">Select a grade first</option>').prop('disabled', true);
      currentSubject = '';
    } else {
      $sel.html(ok.map(k => '<option value="' + k + '">' + esc(SUBJECTS[k]) + '</option>').join('')).prop('disabled', false);
      if (ok.indexOf(currentSubject) === -1) currentSubject = ok[0];
      $sel.val(currentSubject);
    }

    $('.multi-cb').each(function () {
      const allowed = ok.indexOf(this.value) !== -1;
      $(this).closest('label').toggle(allowed);
      if (!allowed) this.checked = false;
    });
    // if nothing visible is ticked (e.g. the teacher only has non-default subjects), tick them all
    if (ok.length && $('.multi-cb:checked').length === 0) {
      $('.multi-cb').each(function () { if (ok.indexOf(this.value) !== -1) this.checked = true; });
    }
  }

  $('#bandHead').html(BANDS.map(function (b, i) {
    const hi = i === 0 ? 100 : BANDS[i - 1].min - 1;
    return '<th title="' + b.min + '–' + hi + '% · ' + b.pts + ' pts">' + b.code + '</th>';
  }).join(''));

  const $rows  = () => $('#studentTable tr').filter(function () { return $(this).find('.mks-input').length > 0; });
  const realEl = ($r, key) => $r.find('.real-mark[data-subj="' + key + '"]');
  const isIncluded = $r => { const $cb = $r.find('.learner-select'); return !$cb.length || $cb.is(':checked'); };

  function toShown(real) {
    if (real === '' || real == null) return '';
    const v = parseFloat(real) * outOf / 100;
    return isNaN(v) ? '' : String(+v.toFixed(1));
  }
  function toReal(shown) {
    const n = parseFloat(shown);
    return isNaN(n) ? '' : String(Math.round(n * 100 / outOf));
  }
  function flagInvalid(el) {
    const v = el.value;
    const bad = v !== '' && (isNaN(+v) || +v < 0 || +v > outOf);
    el.classList.toggle('invalid', bad);
    return bad;
  }
  const emptyRow = (icon, msg) =>
    '<tr><td colspan="5"><div class="empty-state"><div class="empty-icon"><i class="fa-solid ' + icon + '"></i></div><p>' + msg + '</p></div></td></tr>';

  function setStatus(text, isError) {
    $('#statusBar').addClass('visible').toggleClass('status-error', !!isError);
    $('#statusIcon').attr('class', 'fa-solid ' + (isError ? 'fa-triangle-exclamation' : 'fa-circle-info'));
    $('#statusText').text(text);
  }

  function renderSubject() {
    const label = multiMode ? 'Multi-subject' : (SUBJECTS[currentSubject] || 'Marks');
    $('#subjectTitle, #perfSubject').text(label);
    $('.subj-word').text(multiMode ? 'selected subjects' : label);

    $rows().each(function () {
      const $r = $(this), $real = realEl($r, currentSubject);
      $r.find('.mks-input')
        .val(toShown($real.val()))
        .attr({ max: outOf, step: outOf === 100 ? '1' : 'any' })
        .removeClass('invalid');
      const anySaved = multiMode
        ? $r.find('.real-mark[data-saved="1"]').length > 0
        : $real.attr('data-saved') === '1';
      $r.toggleClass('has-saved', anySaved);
    });
    $('#studentTable .col-max').text(outOf);

    applySort();
    updateStats();
    updateMarksProgress();
    renderMulti();

    const n = $rows().length;
    if (n) {
      const saved = $rows().filter('.has-saved').length;
      const filtersSet = $('#termSelect').val() && $('#examSelect').val() && $('#yearSelect').val();
      setStatus(
        n + ' students loaded for Grade ' + $('#gradeSelect').val() + ' — ' +
        (saved > 0
          ? saved + ' already have marks saved for this term/exam/year (highlighted). Review or update and submit.'
          : (!filtersSet
              ? 'select the term, exam and year to load any marks already saved.'
              : (multiMode ? 'tick the subjects you want and enter their marks side by side.' : 'enter ' + label + ' marks and submit.'))),
        false
      );
    }
  }

  function applySort() {
    const mode = $('input[name="sortBy"]:checked').val();
    const rows = $rows().get();
    if (!rows.length) return;
    const val = r => { const v = parseFloat(realEl($(r), currentSubject).val()); return isNaN(v) ? -1 : v; };
    const adm = r => $(r).find('.col-assess').text().trim();
    const byAdm = (a, b) => adm(a).localeCompare(adm(b), undefined, { numeric: true });

    if (mode === 'adm')       rows.sort(byAdm);
    else if (mode === 'perf') rows.sort((a, b) => val(b) - val(a) || byAdm(a, b));
    else                      rows.sort((a, b) => $(a).data('idx') - $(b).data('idx'));

    const $tb = $('#studentTable');
    rows.forEach(r => $tb.append(r));
  }

  function updateStats() {
    let entered = 0, sum = 0, pts = 0;
    const total = $rows().length;
    const counts = {};
    BANDS.forEach(b => counts[b.code] = 0);

    $rows().each(function () {
      const $r = $(this);
      if (!isIncluded($r)) return;
      const v = parseFloat(realEl($r, currentSubject).val());
      if (isNaN(v)) return;
      const b = bandOf(v);
      entered++; sum += v; pts += b.pts; counts[b.code]++;
    });

    if (entered) {
      const avgPts = pts / entered;
      const lvl = BANDS.find(b => b.pts === Math.round(avgPts));
      $('#statAvg').text((sum / entered * outOf / 100).toFixed(2));
      $('#statPts').text(avgPts.toFixed(2));
      $('#statLevel').text(lvl ? lvl.code : '—');
    } else {
      $('#statAvg, #statPts, #statLevel').text('—');
    }
    $('#statEntered').text(entered + ' / ' + total);
    $('#bandCounts').html(BANDS.map(b => '<td>' + counts[b.code] + '</td>').join(''));
  }

  function updateMarksProgress() {
    const $r = $rows().not('.row-no-match');
    let filled = 0, total = $r.length;
    if (multiMode) {
      const subs = selectedMultiSubjects();
      total = $r.length * subs.length;
      $r.each(function () {
        const $row = $(this);
        subs.forEach(k => { if (realEl($row, k).val() !== '') filled++; });
      });
    } else {
      filled = $r.find('.mks-input').filter(function () { return this.value !== ''; }).length;
    }
    $('#marksProgressCount').text(filled);
    $('#marksProgressTotal').text(total);
  }

  // Only the most recent student-table request is allowed to update the page,
  // so a slow response for an earlier (half-filled) selection can never
  // overwrite the saved marks loaded for the final selection.
  let loadSeq = 0;
  let loadXhr = null;

  function loadStudentTable() {
    const grade      = $('#gradeSelect').val();
    const term       = $('#termSelect').val();
    const examType   = $('#examSelect').val();
    const year       = $('#yearSelect').val();
    const statusBar  = $('#statusBar');
    const spinner    = $('#spinner');
    const submitSec  = $('#submitSection');
    const countBadge = $('#studentCount');

    function resetSearch(enabled, placeholder) {
      $('#learnerSearchBar').toggleClass('disabled', !enabled);
      $('#learnerSearch').prop('disabled', !enabled).attr('placeholder', placeholder).val('');
      $('#learnerSearchClear').removeClass('visible');
      $('#learnerSearchCount').text('');
    }

    if (loadXhr) { loadXhr.abort(); loadXhr = null; }
    const seq = ++loadSeq;

    rebuildSubjectControls();

    if (!grade) {
      spinner.hide();
      $('#studentTable').html(emptyRow('fa-arrow-up-from-bracket', 'Select a grade above to load students'));
      countBadge.text('0 students');
      statusBar.removeClass('visible');
      submitSec.hide();
      $('#marksProgress').hide();
      resetSearch(false, 'Select a grade first…');
      updateStats();
      renderMulti();
      return;
    }

    spinner.show();
    $('#studentTable').html('');
    statusBar.removeClass('visible');
    submitSec.hide();

    loadXhr = $.ajax({
      url: 'UploadResults',
      method: 'POST',
      cache: false,
      data: { ajax: 1, grade: grade, term: term, examType: examType, year: year },
      success: function (response, textStatus, xhr) {
        if (seq !== loadSeq) return;
        loadXhr = null;
        spinner.hide();

        if (/<!DOCTYPE/i.test(response) || /<html[\s>]/i.test(response)) {
          $('#studentTable').html(emptyRow('fa-triangle-exclamation', 'Unexpected server response. Please contact the administrator.'));
          countBadge.text('0 students');
          submitSec.hide();
          $('#marksProgress').hide();
          updateStats();
          renderMulti();
          return;
        }

        $('#studentTable').html(response);
        $rows().each(function (i) { $(this).data('idx', i); });

        const count = $rows().length;
        countBadge.text(count + ' student' + (count !== 1 ? 's' : ''));
        submitSec.css('display', count > 0 ? 'flex' : 'none');
        $('#selectAllLearners').prop('checked', true);

        resetSearch(count > 0, count === 0 ? 'No students in this grade' : 'Search name or admission no…');
        $('#marksProgress').toggle(count > 0);

        renderSubject();

        const marksErr = xhr && xhr.getResponseHeader ? xhr.getResponseHeader('X-Marks-Error') : null;
        if (marksErr) {
          setStatus('Students loaded, but saved marks could not be read: ' + marksErr, true);
        }
      },
      error: function (xhr, textStatus) {
        if (textStatus === 'abort' || seq !== loadSeq) return;
        loadXhr = null;
        spinner.hide();
        const denied = xhr && xhr.status === 403;
        $('#studentTable').html(emptyRow(denied ? 'fa-lock' : 'fa-triangle-exclamation',
          denied ? 'You are not registered to upload marks for this grade.' : 'Error loading students. Please try again.'));
        $('#marksProgress').hide();
        updateStats();
        renderMulti();
      }
    });
  }

  $('#gradeSelect, #termSelect, #examSelect, #yearSelect').on('change', loadStudentTable);
  $('#subjectSelect').on('change', function () { currentSubject = this.value; renderSubject(); });
  $('#outOfInput').on('input change', function () {
    const v = parseFloat(this.value);
    if (!(v > 0)) return;
    outOf = v;
    renderSubject();
  });
  $('input[name="sortBy"]').on('change', function () { applySort(); renderMulti(); });

  $('#studentTable').on('input', '.mks-input', function () {
    const $r = $(this).closest('tr');
    realEl($r, currentSubject).val(this.value === '' ? '' : toReal(this.value));
    flagInvalid(this);
    updateStats();
    updateMarksProgress();
  });

  $('#studentTable').on('keydown', '.mks-input', function (e) {
    if (['Enter', 'ArrowDown', 'ArrowUp'].indexOf(e.key) === -1) return;
    e.preventDefault();
    const $vis = $rows().not('.row-no-match').find('.mks-input');
    const i = $vis.index(this);
    const t = $vis.get(e.key === 'ArrowUp' ? i - 1 : i + 1);
    if (t) { t.focus(); t.select(); }
  }).on('focus', '.mks-input', function () { this.select(); });

  function clearTable() {
    const keys = multiMode ? selectedMultiSubjects() : [currentSubject];
    $rows().each(function () {
      const $r = $(this);
      keys.forEach(k => realEl($r, k).val(''));
      $r.find('.mks-input').val('').removeClass('invalid');
    });
    updateStats();
    updateMarksProgress();
    renderMulti();
  }

  $('#selectAllLearners').on('change', function () {
    const checked = $(this).is(':checked');
    $('#studentTable .learner-select').prop('checked', checked)
      .closest('tr').toggleClass('learner-deselected', !checked);
    updateStats();
    renderMulti();
  });
  $('#studentTable').on('change', '.learner-select', function () {
    $(this).closest('tr').toggleClass('learner-deselected', !this.checked);
    const total = $('#studentTable .learner-select').length;
    $('#selectAllLearners').prop('checked', total > 0 && total === $('#studentTable .learner-select:checked').length);
    updateStats();
  });

  $('#resultsForm').on('submit', function () {
    $('#studentTable tr').each(function () {
      const $r = $(this);
      if ($r.find('.learner-select').length && !$r.find('.learner-select').is(':checked')) {
        $r.find('.real-mark').val('');
      }
    });
  });

  function filterLearnerRows() {
    const term = $('#learnerSearch').val().trim().toLowerCase();
    const rows = $rows();
    $('#learnerSearchClear').toggleClass('visible', term.length > 0);

    if (!term) {
      rows.removeClass('row-no-match');
      $('#learnerSearchCount').text('');
      updateMarksProgress();
      renderMulti();
      return;
    }

    let visible = 0;
    rows.each(function () {
      const $r = $(this);
      const hay = [
        $r.find('input[name="assesment[]"]').val(),
        $r.find('input[name="firstName[]"]').val(),
        $r.find('input[name="surname[]"]').val()
      ].join(' ').toLowerCase();
      const match = hay.indexOf(term) !== -1;
      $r.toggleClass('row-no-match', !match);
      if (match) visible++;
    });
    $('#learnerSearchCount').text(visible + ' of ' + rows.length + ' shown');
    updateMarksProgress();
    renderMulti();
  }
  $('#learnerSearch').on('input', filterLearnerRows);
  $('#learnerSearchClear').on('click', function () { $('#learnerSearch').val('').trigger('input').focus(); });

  /* ══════════════════ Multi-subject entry ══════════════════ */
  function selectedMultiSubjects() {
    const ok = allowedSubjects();
    return SUBJECT_ORDER.filter(k => ok.indexOf(k) !== -1 && $('.multi-cb[value="' + k + '"]').is(':checked'));
  }
  const rowByIdx = idx => $rows().filter(function () { return $(this).data('idx') == idx; });

  function renderMulti() {
    if (!multiMode) return;
    const subs = selectedMultiSubjects();
    const rows = $rows().not('.row-no-match').get();

    let head = '<tr><th class="col-select"></th><th class="col-assess">Adm.</th><th class="col-name">Student</th>';
    subs.forEach(k => head += '<th class="col-mks">' + esc(SUBJECTS[k]) + '</th>');
    $('#multiHead').html(head + '</tr>');

    const span = 3 + Math.max(subs.length, 1);
    if (!rows.length) {
      $('#multiBody').html('<tr><td colspan="' + span + '"><div class="empty-state"><div class="empty-icon"><i class="fa-solid fa-user-slash"></i></div><p>' +
        ($('#gradeSelect').val() ? 'No learners to show.' : 'Select a grade above to load students') + '</p></div></td></tr>');
      return;
    }
    if (!subs.length) {
      $('#multiBody').html('<tr><td colspan="' + span + '"><div class="empty-state"><p>Tick at least one subject above.</p></div></td></tr>');
      return;
    }

    let html = '';
    rows.forEach(function (r) {
      const $r = $(r), included = isIncluded($r);
      const name = ($r.find('input[name="firstName[]"]').val() || '') + ' ' + ($r.find('input[name="surname[]"]').val() || '');
      html += '<tr data-idx="' + $r.data('idx') + '" class="' + (included ? '' : 'learner-deselected') + (($r.hasClass('has-saved')) ? ' has-saved' : '') + '">' +
        '<td class="col-select"><input type="checkbox" class="multi-select"' + (included ? ' checked' : '') + '></td>' +
        '<td class="col-assess">' + esc($r.find('input[name="assesment[]"]').val()) + '</td>' +
        '<td class="col-name">' + esc(name) + '</td>';
      subs.forEach(function (k) {
        html += '<td class="col-mks"><input type="number" class="mks-input multi-input" data-subj="' + k + '" min="0" max="' + outOf +
          '" step="' + (outOf === 100 ? '1' : 'any') + '" inputmode="decimal" placeholder="—" autocomplete="off" value="' + esc(toShown(realEl($r, k).val())) + '"></td>';
      });
      html += '</tr>';
    });
    $('#multiBody').html(html);
  }

  function setEntryMode(multi) {
    multiMode = multi;
    $('#entryWrap').toggleClass('multi', multi);
    $('#modeSingle').toggleClass('active', !multi);
    $('#modeMulti').toggleClass('active', multi);
    $('#subjectSelect').closest('.field').toggle(!multi);
    renderSubject();
  }
  $('#modeSingle').on('click', () => setEntryMode(false));
  $('#modeMulti').on('click', () => setEntryMode(true));

  $('.multi-cb').on('change', function () { renderMulti(); updateMarksProgress(); });
  $('#multiAll').on('click', function () {
    const ok = allowedSubjects();
    $('.multi-cb').each(function () { this.checked = ok.indexOf(this.value) !== -1; });
    renderMulti(); updateMarksProgress();
  });
  $('#multiNone').on('click', function () { $('.multi-cb').prop('checked', false); renderMulti(); updateMarksProgress(); });

  $('#multiBody').on('input', '.multi-input', function () {
    const $src = rowByIdx($(this).closest('tr').data('idx'));
    realEl($src, this.dataset.subj).val(this.value === '' ? '' : toReal(this.value));
    flagInvalid(this);
    updateStats();
    updateMarksProgress();
  });

  $('#multiBody').on('change', '.multi-select', function () {
    const $tr = $(this).closest('tr');
    $tr.toggleClass('learner-deselected', !this.checked);
    rowByIdx($tr.data('idx')).find('.learner-select').prop('checked', this.checked).trigger('change');
  });

  $('#multiBody').on('keydown', '.multi-input', function (e) {
    if (['Enter', 'ArrowDown', 'ArrowUp'].indexOf(e.key) === -1) return;
    e.preventDefault();
    const $col = $('#multiBody .multi-input[data-subj="' + this.dataset.subj + '"]');
    const i = $col.index(this);
    const t = $col.get(e.key === 'ArrowUp' ? i - 1 : i + 1);
    if (t) { t.focus(); t.select(); }
  }).on('focus', '.multi-input', function () { this.select(); });

  /* ══════════════════ Preview & submit ══════════════════ */
  function openMarksPreview() {
    const form = document.getElementById('resultsForm');
    if (form.reportValidity && !form.reportValidity()) return;

    const $all = $rows();
    if ($all.length === 0) return;

    const $bad = (multiMode ? $('#multiBody .multi-input') : $all.find('.mks-input'))
      .filter(function () { return flagInvalid(this); });
    if ($bad.length) {
      setStatus($bad.length + ' mark' + (($bad.length !== 1) ? 's are' : ' is') + ' outside 0–' + outOf + '. Fix the highlighted box' + (($bad.length !== 1) ? 'es' : '') + ' first.', true);
      $bad.get(0).scrollIntoView({ block: 'center', behavior: 'smooth' });
      $bad.get(0).focus();
      return;
    }

    const $included = $all.filter(function () { return isIncluded($(this)); });
    const excluded = $all.length - $included.length;
    const $body = $('#marksPreviewBody').empty();
    const $confirm = $('#confirmSubmitBtn');

    let summary = '<strong>' + $included.length + '</strong> learner' + ($included.length !== 1 ? 's' : '') + ' will be submitted';
    if (excluded > 0) summary += ' · ' + excluded + ' unchecked learner' + (excluded !== 1 ? 's' : '') + ' excluded';
    $body.append('<div class="marks-preview-summary pv-summary">' + summary + '.</div>');

    if ($included.length === 0) {
      $body.append('<div class="pv-empty"><i class="fa-solid fa-user-slash" style="font-size:20px;display:block;margin-bottom:8px;"></i>No learners selected — nothing will be submitted.</div>');
      $confirm.prop('disabled', true);
      $('#marksPreviewOverlay').addClass('active');
      return;
    }

    const okSubjects = allowedSubjects();
    const withData = SUBJECT_ORDER.filter(function (key) {
      if (okSubjects.indexOf(key) === -1) return false;
      return $included.toArray().some(function (row) { return realEl($(row), key).val() !== ''; });
    });

    if (withData.length === 0) {
      $body.append('<div class="pv-note"><i class="fa-solid fa-triangle-exclamation"></i>No marks have been entered for any included learner yet.</div>');
      $confirm.prop('disabled', true);
      $('#marksPreviewOverlay').addClass('active');
      return;
    }
    $confirm.prop('disabled', false);

    if (outOf !== 100) {
      $body.append('<div class="pv-note"><i class="fa-solid fa-circle-info"></i>Marks are shown as they will be saved — out of 100 (converted from ' + outOf + ').</div>');
    }

    let html = '<div class="table-scroll"><table class="pv"><thead><tr><th>Learner</th>';
    withData.forEach(function (k) { html += '<th>' + SUBJECTS[k] + '</th>'; });
    html += '</tr></thead><tbody>';

    const sums = {}, counts = {};
    withData.forEach(function (k) { sums[k] = 0; counts[k] = 0; });

    $included.each(function () {
      const $r = $(this);
      const name = ($r.find('input[name="firstName[]"]').val() || '') + ' ' + ($r.find('input[name="surname[]"]').val() || '');
      html += '<tr><td>' + $('<div>').text(name).html() + '</td>';
      withData.forEach(function (k) {
        const v = realEl($r, k).val();
        if (v !== '') { html += '<td>' + $('<div>').text(v).html() + '</td>'; sums[k] += parseFloat(v); counts[k]++; }
        else html += '<td class="mp-blank">—</td>';
      });
      html += '</tr>';
    });

    html += '</tbody><tfoot><tr><td>Mean</td>';
    withData.forEach(function (k) { html += '<td>' + (counts[k] ? (sums[k] / counts[k]).toFixed(1) : '—') + '</td>'; });
    html += '</tr></tfoot></table></div>';

    $body.append(html);
    $('#marksPreviewOverlay').addClass('active');
  }

  function closeMarksPreview() { $('#marksPreviewOverlay').removeClass('active'); }

  function confirmSubmit() {
    closeMarksPreview();
    const form = document.getElementById('resultsForm');
    if (form.requestSubmit) form.requestSubmit();
    else { $(form).trigger('submit'); form.submit(); }
  }

  /* ══════════════════ Upload mode tabs ══════════════════ */
  function setUploadMode(mode) {
    const excel = mode === 'excel';
    document.getElementById('manualEntrySection').style.display = excel ? 'none' : 'block';
    document.getElementById('excelSection').style.display = excel ? 'block' : 'none';
    document.getElementById('tabManual').classList.toggle('active', !excel);
    document.getElementById('tabExcel').classList.toggle('active', excel);
  }

  <?php if (isset($_GET['excel_msg'])): ?>
  document.addEventListener('DOMContentLoaded', () => setUploadMode('excel'));
  <?php endif; ?>

  /* Excel tab: only the subjects registered for the chosen grade can be ticked */
  function applyExcelSubjectLimits() {
    const grade = document.getElementById('excelGrade').value;
    const cols = ALLOWED[grade] || [];
    document.querySelectorAll('.subj-cb').forEach(cb => {
      const ok = cols.indexOf(cb.value) !== -1;
      cb.disabled = !ok;
      if (!ok) cb.checked = false;
      cb.closest('label').classList.toggle('locked', !ok);
    });
    // nothing ticked but some allowed? default to the first allowed subject
    if (cols.length && !document.querySelector('.subj-cb:checked')) {
      const first = Array.from(document.querySelectorAll('.subj-cb')).find(cb => !cb.disabled);
      if (first) first.checked = true;
    }
  }
  document.getElementById('excelGrade').addEventListener('change', applyExcelSubjectLimits);

  document.getElementById('selectAllSubjects').addEventListener('click', () => {
    document.querySelectorAll('.subj-cb').forEach(cb => { if (!cb.disabled) cb.checked = true; });
  });
  document.getElementById('clearAllSubjects').addEventListener('click', () => {
    document.querySelectorAll('.subj-cb').forEach(cb => cb.checked = false);
  });

  function getSelectedSubjects() {
    return Array.from(document.querySelectorAll('.subj-cb:checked')).map(cb => cb.value);
  }

  function getExcelFilters() {
    return {
      grade: document.getElementById('excelGrade').value,
      term: document.getElementById('excelTerm').value,
      examType: document.getElementById('excelExamType').value,
      year: document.getElementById('excelYear').value,
    };
  }

  document.getElementById('downloadTemplateBtn').addEventListener('click', () => {
    const { grade } = getExcelFilters();
    const subjects = getSelectedSubjects();
    const formatEl = document.querySelector('input[name="templateFormat"]:checked');
    const format = formatEl ? formatEl.value : 'csv';

    if (!grade) { alert('Please select a grade first.'); return; }
    if (subjects.length === 0) { alert('Please select at least one subject.'); return; }

    const params = new URLSearchParams();
    params.append('grade', grade);
    params.append('format', format);
    subjects.forEach(s => params.append('subjects[]', s));

    window.location = 'generate_template.php?' + params.toString();
  });

  document.getElementById('excelUploadForm').addEventListener('submit', function (e) {
    e.preventDefault();

    const { grade, term, examType, year } = getExcelFilters();

    if (!grade || !term || !examType || !year) {
      alert('Please select Grade, Term, Exam Type, and Year before uploading.');
      return;
    }

    const fileInput = document.getElementById('marksFileInput');
    if (!fileInput.files.length) {
      alert('Please choose a file to upload.');
      return;
    }

    document.getElementById('excelGradeHidden').value = grade;
    document.getElementById('excelTermHidden').value = term;
    document.getElementById('excelExamTypeHidden').value = examType;
    document.getElementById('excelYearHidden').value = year;

    const formData = new FormData(this);

    const uploadBtn = document.getElementById('uploadExcelBtn');
    const originalBtnHtml = uploadBtn.innerHTML;
    uploadBtn.disabled = true;
    uploadBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Uploading…';

    fetch(this.action, { method: 'POST', body: formData })
      .then(function (response) {
        if (response.url) window.location.href = response.url;
        else window.location.reload();
      })
      .catch(function (err) {
        uploadBtn.disabled = false;
        uploadBtn.innerHTML = originalBtnHtml;
        alert('Upload failed: ' + err.message + '. Please check your connection and try again.');
      });
  });

  document.getElementById('marksFileInput').addEventListener('change', function () {
    document.getElementById('fileNameLabel').textContent =
      this.files.length ? this.files[0].name : 'Choose completed .csv or .xlsx file…';
  });

  /* ══════════════════ KPI / context hooks ══════════════════ */
  const _updateStats = updateStats;
  updateStats = function () { _updateStats(); refreshKpis(); };
  $('#gradeSelect, #termSelect, #examSelect, #yearSelect').on('change', renderContext);

  /* ══════════════════ Init ══════════════════ */
  rebuildSubjectControls();
  applyExcelSubjectLimits();
  updateStats();
</script>

</body>
</html>