<?php

     require_once __DIR__ . '/page_guard.php';
include 'conn.php';
require_once 'add-exam-type.php';
$EXAM_TYPES = getExamTypes($conn); 


require_once 'auth_helpers.php';
requireLogin();

/* ════════════════════════════════════════════════════════════════════
   MANAGE LEARNER MARKS — access, CSRF and the delete handler.
   Runs BEFORE any HTML is sent so it can redirect back after deleting.
   (normalizeTerm() is declared further down; top-level functions are
   hoisted by PHP, so it can be called from here.)
   ════════════════════════════════════════════════════════════════════ */
$isAdminUser    = function_exists('isAdmin') ? (bool)isAdmin() : false;
$canDeleteMarks = $isAdminUser;   // ← deleting marks is limited to administrators; change this one line to allow other roles (e.g. class teachers)

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (empty($_SESSION['vr_csrf'])) { $_SESSION['vr_csrf'] = bin2hex(random_bytes(16)); }
$vrCsrf = (string)$_SESSION['vr_csrf'];

$isHoi     = (($_SESSION['account_type'] ?? '') === 'hoi');
$homeLink  = $isHoi ? 'Hoi.php' : 'teacherpanel.php';
$roleLabel = $isHoi ? 'Head of Institution' : ($isAdminUser ? 'Administrator' : 'Teacher Panel');
$thisYear  = (int)date('Y');
$yearFrom  = min(2026, $thisYear);
$yearTo    = max(2030, $thisYear + 2);

/* Results release gate: admin releases results; progress stays visible to everyone. */
require_once 'exam_release_ui.php';
erHandlePost($conn, $EXAM_TYPES, $vrCsrf);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_marks') {
    $pGrade = (string)($_POST['grade'] ?? '');
    $pTerm  = (string)($_POST['term'] ?? '');
    $pYear  = (string)($_POST['year'] ?? '');
    $pExam  = resolveExamCode((string)($_POST['exam_type'] ?? ''), $EXAM_TYPES);
    $pKeys  = (isset($_POST['keys']) && is_array($_POST['keys'])) ? array_slice($_POST['keys'], 0, 500) : [];

    $pValid = $canDeleteMarks
        && hash_equals($vrCsrf, (string)($_POST['csrf'] ?? ''))
        && ctype_digit($pGrade) && ctype_digit($pYear)
        && in_array($pTerm, ['Term 1', 'Term 2', 'Term 3'], true)
        && $pExam !== null && $pExam !== ''
        && count($pKeys) > 0;

    $delStatus = 'error';
    $delRows   = 0;   // exam2 rows removed
    $delProg   = 0;   // rows removed from the Progress Records tables

    if ($pValid) {
        $gE = mysqli_real_escape_string($conn, $pGrade);
        $eE = mysqli_real_escape_string($conn, $pExam);
        $yE = mysqli_real_escape_string($conn, $pYear);
        $targetTerm = normalizeTerm($pTerm);

        // Find the exact stored term strings that the results page treats as this term
        // ("Term 1", "1", "term_1" …) so the delete hits precisely the rows being displayed.
        $termVals = [];
        try {
            $tr = mysqli_query($conn, "SELECT DISTINCT term FROM exam2 WHERE grade='$gE' AND exam_type='$eE' AND year='$yE'");
            while ($tr && ($t = mysqli_fetch_assoc($tr))) {
                if (normalizeTerm($t['term'] ?? '') === $targetTerm) {
                    $termVals[] = "'" . mysqli_real_escape_string($conn, (string)$t['term']) . "'";
                }
            }
        } catch (\Throwable $e) { error_log('[ViewResults] term lookup failed: ' . $e->getMessage()); }

        if ($termVals) {
            $termIn  = implode(',', $termVals);
            $termInt = ctype_digit($targetTerm) ? (int)$targetTerm : 0;
            foreach ($pKeys as $k) {
                $k = (string)$k;
                $sid = '';
                if (strpos($k, 'sid:') === 0) {
                    $sid = substr($k, 4);
                    if ($sid === '') continue;
                    $cond = "student_id='" . mysqli_real_escape_string($conn, $sid) . "'";
                } elseif (strpos($k, 'ass:') === 0) {
                    $ass = substr($k, 4);
                    if ($ass === '') continue;
                    $cond = "Assesment='" . mysqli_real_escape_string($conn, $ass) . "'";
                } else {
                    continue;
                }

                try {
                    if (mysqli_query($conn, "DELETE FROM exam2 WHERE grade='$gE' AND exam_type='$eE' AND year='$yE' AND term IN ($termIn) AND $cond")) {
                        $delRows += max(0, mysqli_affected_rows($conn));
                    }
                } catch (\Throwable $e) { error_log('[ViewResults] delete failed for ' . $k . ': ' . $e->getMessage()); }

                // Keep Progress Records in step: remove the same learner's rows for this exam there too.
                if ($sid !== '' && $termInt > 0) {
                    try {
                        $sE = mysqli_real_escape_string($conn, $sid);
                        if (mysqli_query($conn,
                            "DELETE sc FROM exam_scores sc JOIN exam_sessions es ON es.id = sc.exam_session_id
                             WHERE sc.student_id='$sE' AND es.grade='$gE' AND es.term=$termInt AND es.exam_type='$eE' AND es.year='$yE'")) {
                            $delProg += max(0, mysqli_affected_rows($conn));
                        }
                    } catch (\Throwable $e) { /* Progress tables absent or different — safe to ignore */ }
                }
            }
            $delStatus = 'ok';
            error_log(sprintf('[ViewResults] marks deleted: grade=%s term=%s exam=%s year=%s learners=%d exam2_rows=%d progress_rows=%d',
                $pGrade, $pTerm, $pExam, $pYear, count($pKeys), $delRows, $delProg));
        }
    }

    $backPath = strtok((string)($_SERVER['REQUEST_URI'] ?? 'ViewResults.php'), '?');
    $backUrl  = $backPath . '?' . http_build_query([
        'grade' => $pGrade, 'term' => $pTerm, 'exam_type' => (string)$pExam, 'year' => $pYear,
        'mm' => $delStatus, 'dn' => $delRows, 'dp' => $delProg,
    ]) . '#manage-marks';
    if (!headers_sent()) { header('Location: ' . $backUrl); }
    else { echo "<script>window.location.href=" . json_encode($backUrl) . ";</script>"; }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Results — Stephen Kanja</title>
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
            --border:#e4e8f0; --border-2:#eef1f6; --grid-line:rgba(15,23,42,.06);
            --navy:#0b1f3a; --gold:#f0c040; --gold-d:#c8992a; --gold-pale:#fdf3d4; --gold-ink:#7a5a10;
            --accent-strong:#1f6fb2;

            /* Table styling */
            --th-bg:#10304d; --th-border:rgba(255,255,255,.14); --tgrid:#d5dde7; --zebra:#f6f9fc; --row-hover:#e9f2fb; --tot-line:#8fa6bd;

            --ee:#16a34a; --ee-bg:#dcfce7; --me:#1a4a7a; --me-bg:#dce8f5;
            --ae:#b45309; --ae-bg:#fef3c7; --be:#991b1b; --be-bg:#fee2e2;
            /* 8-level scale: sub-band 1 is the HIGHER one (EE1 = 90+) */
            --ee1:#15803d; --ee1-bg:#bbf7d0; --ee2:#16a34a; --ee2-bg:#dcfce7;
            --me1:#123657; --me1-bg:#cfe0f3; --me2:#1a4a7a; --me2-bg:#dce8f5;
            --ae1:#92400e; --ae1-bg:#fde68a; --ae2:#b45309; --ae2-bg:#fef3c7;
            --be1:#7f1d1d; --be1-bg:#fecaca; --be2:#991b1b; --be2-bg:#fee2e2;
            --blue:#1a4a7a; --blue-pale:#dce8f5; --paper:var(--bg); --cream:var(--surface-2);
            --ink:var(--text); --ink-soft:var(--text-2); --ink-mute:var(--muted);

            --shadow-sm:0 1px 2px rgba(15,23,42,.05); --shadow:0 6px 24px rgba(15,23,42,.07); --shadow-lg:0 24px 64px rgba(15,23,42,.25);
            --r:14px; --r-lg:18px; --sidebar-w:264px; --topbar-h:64px;
            color-scheme: light;
        }
        [data-theme="dark"] {
            --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
            --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3; --border:#1f2d47; --border-2:#192740; --grid-line:rgba(255,255,255,.08);
            --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040; --accent-strong:#5aa9e6;
            --th-bg:#173a5e; --th-border:rgba(255,255,255,.12); --tgrid:#243652; --zebra:#0e1729; --row-hover:#16223b; --tot-line:#4c6b8c;
            --ee:#4ade80; --ee-bg:rgba(34,197,94,.16); --me:#7db3f0; --me-bg:rgba(96,165,250,.16);
            --ae:#fbbf24; --ae-bg:rgba(251,191,36,.15); --be:#f87171; --be-bg:rgba(248,113,113,.16);
            --ee1:#86efac; --ee1-bg:rgba(34,197,94,.30); --ee2:#4ade80; --ee2-bg:rgba(34,197,94,.18);
            --me1:#a9cdf6; --me1-bg:rgba(96,165,250,.30); --me2:#7db3f0; --me2-bg:rgba(96,165,250,.18);
            --ae1:#fcd34d; --ae1-bg:rgba(251,191,36,.30); --ae2:#fbbf24; --ae2-bg:rgba(251,191,36,.16);
            --be1:#fca5a5; --be1-bg:rgba(248,113,113,.30); --be2:#f87171; --be2-bg:rgba(248,113,113,.16);
            --blue:#8ab8ee; --blue-pale:rgba(96,165,250,.16);
            --shadow-sm:0 1px 2px rgba(0,0,0,.4); --shadow:0 6px 24px rgba(0,0,0,.35); --shadow-lg:0 24px 64px rgba(0,0,0,.6);
            color-scheme: dark;
        }

        html { scroll-behavior:smooth; }
        body { font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14.5px; line-height:1.6; -webkit-font-smoothing:antialiased; transition:background .25s,color .25s; }
        ::-webkit-scrollbar { width:8px; height:8px; }
        ::-webkit-scrollbar-thumb { background:rgba(120,135,160,.35); border-radius:99px; }
        :focus-visible { outline:2px solid var(--gold); outline-offset:2px; border-radius:6px; }
        .skip { position:absolute; left:-999px; top:8px; background:var(--gold); color:#000; padding:8px 14px; border-radius:8px; font-weight:700; z-index:600; }
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
        .sidebar-backdrop { display:none; position:fixed; inset:0; background:rgba(3,10,22,.55); backdrop-filter:blur(2px); z-index:99; }
        .sidebar-backdrop.show { display:block; }

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

        .results { padding:24px 32px 70px; width:100%; max-width:1480px; margin:0 auto; }
        .page-eyebrow { font-size:.7rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold-d); }
        .page-title { font-size:clamp(1.4rem,2.4vw,1.8rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; }
        .page-sub { color:var(--muted); font-size:.86rem; margin-top:4px; }
        .page-head { margin-bottom:18px; }

        /* ───────── Filter form ───────── */
        .filter-form { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:18px 20px; box-shadow:var(--shadow-sm); margin-bottom:8px; display:grid; grid-template-columns:repeat(4,minmax(140px,1fr)) auto; gap:14px; align-items:end; }
        .form-group { display:flex; flex-direction:column; gap:6px; min-width:0; }
        .form-group label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
        .form-group select { appearance:none; width:100%; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; padding:0 38px 0 14px; height:42px; font-family:inherit; font-size:.88rem; font-weight:500; color:var(--text); cursor:pointer; transition:.15s;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; }
        .form-group select:hover { border-color:rgba(37,99,235,.35); }
        .form-group select:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
        .btn-view { background:var(--navy); color:#fff; border:none; border-radius:10px; padding:0 24px; height:42px; font-family:inherit; font-size:.84rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; justify-content:center; gap:8px; }
        [data-theme="dark"] .btn-view { background:var(--gold); color:#101826; }
        .btn-view:hover { transform:translateY(-1px); box-shadow:0 8px 20px rgba(11,31,58,.25); }

        /* ───────── Section divider ───────── */
        .section-divider { display:flex; align-items:center; gap:14px; margin:34px 0 16px; flex-wrap:wrap; row-gap:10px; }
        .section-divider h2 { font-size:clamp(1.05rem,2vw,1.25rem); font-weight:800; letter-spacing:-.3px; }
        .section-divider::after { content:''; flex:1; height:1px; background:var(--border); min-width:30px; order:2; }
        .section-divider .export-btns { order:3; }
        .section-badge { font-size:.68rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; background:var(--navy); color:var(--gold); padding:5px 12px; border-radius:99px; white-space:nowrap; order:3; }
        [data-theme="dark"] .section-badge { background:var(--surface-3); }

        /* ───────── Export buttons ───────── */
        .export-btns { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .btn-export { display:inline-flex; align-items:center; gap:8px; background:var(--navy); color:#fff; border:none; border-radius:10px; padding:0 16px; height:38px; font-family:inherit; font-size:.78rem; font-weight:700; cursor:pointer; white-space:nowrap; transition:.15s; }
        [data-theme="dark"] .btn-export { background:var(--surface-3); border:1px solid var(--border); }
        .btn-export:hover { transform:translateY(-1px); box-shadow:0 8px 20px rgba(11,31,58,.28); }
        .btn-export:disabled { opacity:.6; cursor:wait; transform:none; }
        .btn-export-gold { background:var(--gold); color:#101826 !important; box-shadow:0 6px 16px rgba(240,192,64,.3); border:none !important; }
        .btn-export-green { background:#16a34a !important; color:#fff !important; border:none !important; }
        .rc-opt { display:inline-flex; align-items:center; gap:7px; font-size:.78rem; font-weight:600; color:var(--text-2); cursor:pointer; }
        .rc-opt input { width:16px; height:16px; accent-color:var(--gold-d); cursor:pointer; }

        /* ───────── KPI strip ───────── */
        .kpi-strip { display:grid; grid-template-columns:repeat(4,1fr); gap:14px; margin-bottom:22px; }
        .kpi-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:18px 20px 16px; box-shadow:var(--shadow-sm); position:relative; overflow:hidden; transition:transform .2s,box-shadow .2s; animation:fadeUp .45s ease both; --accent:var(--gold); }
        .kpi-card:hover { transform:translateY(-3px); box-shadow:var(--shadow); }
        .kpi-card::after { content:''; position:absolute; right:-26px; top:-26px; width:92px; height:92px; border-radius:50%; background:var(--accent); opacity:.12; }
        .kpi-card::before { content:''; position:absolute; left:0; top:0; bottom:0; width:4px; background:var(--accent); }
        .kpi-card.kpi-total { --accent:#2563eb; } .kpi-card.kpi-ee { --accent:#16a34a; } .kpi-card.kpi-me { --accent:#d9a521; } .kpi-card.kpi-below { --accent:#dc2626; }
        .kpi-label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); margin-bottom:4px; }
        .kpi-val { font-size:2rem; font-weight:800; line-height:1.1; letter-spacing:-.8px; color:var(--text); font-variant-numeric:tabular-nums; }
        .kpi-sub { font-size:.74rem; color:var(--muted); margin-top:5px; }

        /* ───────── Cards: bands, charts, compare, means ───────── */
        .bands-grid, .charts-grid { display:grid; grid-template-columns:1fr 1fr; gap:18px; margin-bottom:22px; }
        .band-card, .chart-card, .compare-card, .means-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:20px 22px; box-shadow:var(--shadow-sm); animation:fadeUp .45s .05s ease both; margin-bottom:0; }
        .means-card, .compare-card { margin-bottom:22px; }
        .chart-card.full-width { grid-column:1/-1; }
        .band-card h3, .chart-card h3, .compare-card h3, .means-card h3 { font-size:.74rem; font-weight:800; letter-spacing:1.1px; text-transform:uppercase; color:var(--text-2); margin-bottom:16px; }
        .band-row { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
        .band-label { font-size:.76rem; font-weight:600; width:140px; flex-shrink:0; color:var(--text-2); }
        .band-bar-wrap { flex:1; background:var(--surface-3); border-radius:99px; height:10px; overflow:hidden; }
        .band-bar { height:100%; border-radius:99px; transition:width 1s cubic-bezier(.4,0,.2,1); }
        .band-count { font-size:.76rem; font-weight:700; width:84px; text-align:right; color:var(--text-2); flex-shrink:0; }
        .band-ee { background:var(--ee); } .band-me { background:var(--me); } .band-ae { background:#d97706; } .band-be { background:var(--be); }
        .chart-wrap { position:relative; height:260px; } .chart-wrap.tall { height:320px; }

        .compare-meta { display:flex; gap:20px; margin-bottom:14px; flex-wrap:wrap; }
        .compare-pill { display:flex; align-items:center; gap:8px; font-size:.8rem; font-weight:600; color:var(--text-2); }
        .compare-pill-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .no-prev { text-align:center; padding:28px; color:var(--muted); font-size:.86rem; background:var(--surface-2); border-radius:12px; line-height:1.7; }

        /* ───────── Tables (grid style) ───────── */
        .means-card { overflow-x:auto; }
        .means-table { width:100%; border-collapse:collapse; font-size:.84rem; min-width:700px; font-variant-numeric:tabular-nums; }
        .means-table th { background:var(--th-bg); color:#fff; padding:11px 12px; text-align:center; vertical-align:middle; font-size:.66rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; border:1px solid var(--th-border); border-bottom:2px solid var(--gold); }
        .means-table td { padding:10px 12px; text-align:center; vertical-align:middle; border:1px solid var(--tgrid); color:var(--text-2); }
        .means-table th.l, .means-table td.l { text-align:left; }
        .means-table tbody tr:nth-child(even) td { background:var(--zebra); }
        .means-table tbody tr:hover td { background:var(--row-hover); }
        .means-table td strong { color:var(--text); }
        .means-table .mean-val { font-weight:700; color:var(--text); }
        .means-table .trend-up { color:var(--ee); font-weight:700; } .means-table .trend-down { color:var(--be); font-weight:700; } .means-table .trend-same { color:var(--muted); }

        .table-scroll { overflow:auto; background:var(--surface); border-radius:var(--r); box-shadow:var(--shadow); border:1px solid var(--tgrid); -webkit-overflow-scrolling:touch; }
        @media (min-width:1025px) { .table-scroll { max-height:78vh; } }
        .results-table { width:100%; min-width:960px; border-collapse:collapse; font-size:.85rem; font-variant-numeric:tabular-nums; }
        .results-table thead th { position:sticky; top:0; z-index:2; background:var(--th-bg); color:#fff; padding:12px 10px; text-align:center; vertical-align:middle; font-size:.66rem; font-weight:700; letter-spacing:.9px; text-transform:uppercase; line-height:1.3; border:1px solid var(--th-border); border-bottom:2px solid var(--gold); }
        .results-table thead th.l { text-align:left; padding-left:14px; }
        .results-table td { padding:9px 10px; text-align:center; vertical-align:middle; color:var(--text-2); white-space:nowrap; border:1px solid var(--tgrid); }
        .results-table td.l { text-align:left; padding-left:14px; }
        .results-table td.l strong { color:var(--text); }
        .results-table th.tot, .results-table td.tot { border-left:2px solid var(--tot-line); }
        .results-table td.tot { font-weight:700; color:var(--text); }
        .results-table tbody tr:nth-child(even) td { background:var(--zebra); }
        .results-table tbody tr:hover td { background:var(--row-hover); }
        .sc { display:inline-block; min-width:26px; text-align:right; font-weight:600; color:var(--text); margin-right:5px; }
        .rank-badge { display:inline-flex; align-items:center; justify-content:center; width:30px; height:30px; border-radius:50%; font-weight:800; font-size:.78rem; background:var(--blue-pale); color:var(--blue); }
        .rank-badge.gold { background:linear-gradient(135deg,#fde68a,#f0c040); color:#6b4a00; }
        .rank-badge.silver { background:linear-gradient(135deg,#e5e7ee,#c7ccd9); color:#3d4560; }
        .rank-badge.bronze { background:linear-gradient(135deg,#f3d9bd,#dfa972); color:#6b3b14; }
        .results-table tbody tr.mean-row td { background:var(--th-bg) !important; color:rgba(255,255,255,.9) !important; border-color:var(--th-border); padding-top:13px; padding-bottom:13px; font-weight:600; }
        .results-table tbody tr.mean-row td.lbl { text-align:right; padding-right:14px; color:#fff !important; font-size:.7rem; letter-spacing:1.5px; text-transform:uppercase; }
        .mean-row .sc { color:#fff; }

        .award { display:inline-block; min-width:34px; text-align:center; font-size:.6rem; font-weight:800; padding:2px 6px; border-radius:5px; letter-spacing:.4px; vertical-align:middle; margin-left:3px; }
        .award.ee1 { background:var(--ee1-bg); color:var(--ee1); } .award.ee2 { background:var(--ee2-bg); color:var(--ee2); }
        .award.me1 { background:var(--me1-bg); color:var(--me1); } .award.me2 { background:var(--me2-bg); color:var(--me2); }
        .award.ae1 { background:var(--ae1-bg); color:var(--ae1); } .award.ae2 { background:var(--ae2-bg); color:var(--ae2); }
        .award.be1 { background:var(--be1-bg); color:var(--be1); } .award.be2 { background:var(--be2-bg); color:var(--be2); }
        .award.ee { background:var(--ee2-bg); color:var(--ee2); } .award.me { background:var(--me2-bg); color:var(--me2); }
        .award.ae { background:var(--ae2-bg); color:var(--ae2); } .award.be { background:var(--be2-bg); color:var(--be2); }
        .mean-row .award.ee2, .mean-row .award.ee1, .mean-row .award.ee { background:rgba(22,163,74,.35); color:#86efac; }
        .mean-row .award.me2, .mean-row .award.me1, .mean-row .award.me { background:rgba(96,165,250,.30); color:#bfdbfe; }
        .mean-row .award.ae2, .mean-row .award.ae1, .mean-row .award.ae { background:rgba(200,153,42,.35); color:#f5d88a; }
        .mean-row .award.be2, .mean-row .award.be1, .mean-row .award.be { background:rgba(153,27,27,.35); color:#fca5a5; }

        .no-results { background:var(--surface); border:1.5px dashed var(--border); border-radius:var(--r-lg); padding:52px 32px; text-align:center; color:var(--muted); font-size:.95rem; line-height:2; margin-top:18px; }
        .no-results::before { content:'📋'; display:block; font-size:2.2rem; margin-bottom:10px; }
        .scroll-hint { display:none; font-size:.74rem; color:var(--muted); text-align:right; margin-bottom:8px; }

        /* ───────── Manage learner marks ───────── */
        .flash { display:flex; align-items:flex-start; gap:12px; padding:14px 18px; border-radius:var(--r); margin:18px 0; font-size:.86rem; font-weight:600; border:1px solid transparent; animation:fadeUp .3s ease both; }
        .flash i { margin-top:3px; }
        .flash small { display:block; font-weight:500; opacity:.85; margin-top:2px; }
        .flash-ok { background:var(--ee-bg); border-color:rgba(34,197,94,.4); color:var(--ee); }
        .flash-err { background:var(--be-bg); border-color:rgba(248,113,113,.45); color:var(--be); }

        .manage-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--shadow-sm); margin-bottom:26px; overflow:hidden; }
        .manage-intro { display:flex; gap:14px; align-items:flex-start; padding:18px 22px; border-bottom:1px solid var(--border-2); background:var(--surface-2); font-size:.82rem; color:var(--text-2); line-height:1.7; }
        .manage-intro > i { color:var(--gold-d); font-size:18px; margin-top:3px; }
        .manage-intro strong { color:var(--text); }
        .manage-intro a { color:var(--accent-strong); font-weight:700; }
        .mg-clean { margin:16px 22px 0; padding:12px 16px; border-radius:10px; background:var(--ee-bg); color:var(--ee); font-size:.82rem; font-weight:600; }
        .mg-toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; padding:16px 22px; }
        .mg-toggle { display:inline-flex; align-items:center; gap:8px; font-size:.8rem; font-weight:600; color:var(--text-2); cursor:pointer; margin-right:auto; }
        .mg-toggle input, .mg-table input[type=checkbox] { width:16px; height:16px; accent-color:#dc2626; cursor:pointer; }
        .mg-btn { display:inline-flex; align-items:center; gap:7px; border:1.5px solid var(--border); background:var(--surface); color:var(--text-2); border-radius:10px; padding:0 14px; height:36px; font-family:inherit; font-size:.78rem; font-weight:700; cursor:pointer; transition:.15s; }
        .mg-btn:hover { background:var(--surface-3); color:var(--text); }
        .mg-btn-danger { background:#dc2626; border-color:#dc2626; color:#fff; }
        .mg-btn-danger:hover { background:#b91c1c; border-color:#b91c1c; color:#fff; }
        .mg-btn:disabled { opacity:.45; cursor:not-allowed; }
        .mg-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; border-top:1px solid var(--border-2); }
        .mg-table { width:100%; min-width:820px; border-collapse:collapse; font-size:.84rem; font-variant-numeric:tabular-nums; }
        .mg-table thead th { background:var(--th-bg); color:#fff; padding:11px 12px; text-align:left; vertical-align:middle; font-size:.64rem; font-weight:700; letter-spacing:1px; text-transform:uppercase; white-space:nowrap; border:1px solid var(--th-border); border-bottom:2px solid var(--gold); }
        .mg-table thead th.c, .mg-table td.c { text-align:center; }
        .mg-table td { padding:10px 12px; border:1px solid var(--tgrid); color:var(--text-2); vertical-align:middle; }
        .mg-table tbody tr:nth-child(even) td { background:var(--zebra); }
        .mg-table tbody tr:hover td { background:var(--row-hover); }
        .mg-table tbody tr.mg-sel td { background:var(--be-bg); }
        .mg-table .mg-name { font-weight:700; color:var(--text); }
        .mg-table .col-chk { width:44px; text-align:center; }
        .mg-flags { display:flex; flex-wrap:wrap; gap:5px; }
        .flag { display:inline-block; font-size:.64rem; font-weight:800; letter-spacing:.3px; padding:3px 8px; border-radius:6px; white-space:nowrap; }
        .flag.danger { background:var(--be-bg); color:var(--be); } .flag.warn { background:var(--ae-bg); color:var(--ae); } .flag.ok { background:var(--ee-bg); color:var(--ee); }
        .mg-foot { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; padding:14px 22px; border-top:1px solid var(--border-2); background:var(--surface-2); font-size:.8rem; color:var(--text-2); }
        .mg-foot strong { color:var(--text); }

        .mg-modal-overlay { display:none; position:fixed; inset:0; z-index:500; background:rgba(3,10,22,.62); backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:18px; }
        .mg-modal-overlay.open { display:flex; }
        .mg-modal { background:var(--surface); border-radius:var(--r-lg); width:100%; max-width:520px; max-height:88vh; display:flex; flex-direction:column; overflow:hidden; box-shadow:var(--shadow-lg); border-top:4px solid #dc2626; border-left:1px solid var(--border); border-right:1px solid var(--border); border-bottom:1px solid var(--border); }
        .mg-modal-head { padding:18px 22px 8px; font-size:1.08rem; font-weight:800; letter-spacing:-.2px; color:var(--text); display:flex; align-items:center; gap:10px; }
        .mg-modal-head i { color:#dc2626; }
        .mg-modal-body { padding:4px 22px 14px; overflow-y:auto; font-size:.84rem; color:var(--text-2); }
        .mg-modal-meta { margin:8px 0 10px; padding:10px 12px; border-radius:10px; background:var(--surface-2); font-size:.78rem; }
        .mg-modal-list { list-style:none; border:1px solid var(--border); border-radius:10px; max-height:190px; overflow-y:auto; }
        .mg-modal-list li { padding:7px 12px; border-bottom:1px solid var(--border-2); font-weight:600; color:var(--text); }
        .mg-modal-list li:last-child { border-bottom:none; }
        .mg-confirm { display:flex; align-items:flex-start; gap:9px; margin-top:14px; font-size:.8rem; font-weight:600; color:var(--be); cursor:pointer; }
        .mg-confirm input { width:16px; height:16px; margin-top:2px; accent-color:#dc2626; }
        .mg-modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 22px; border-top:1px solid var(--border-2); background:var(--surface-2); }

        .footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

        @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
        @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

        /* ───────── Responsive ───────── */
        @media (max-width:1180px) { .filter-form { grid-template-columns:repeat(2,1fr); } .btn-view { grid-column:1/-1; } }
        @media (max-width:1024px) {
            .app { grid-template-columns:1fr; }
            .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); }
            .sidebar.open { left:0; }
            .burger { display:inline-flex; align-items:center; justify-content:center; }
            .topbar { padding:0 18px; } .results { padding:18px 18px 60px; }
        }
        @media (max-width:900px) {
            .kpi-strip { grid-template-columns:repeat(2,1fr); }
            .bands-grid, .charts-grid { grid-template-columns:1fr; }
            .charts-grid .chart-card.full-width { grid-column:1; }
        }
        @media (max-width:700px) {
            .scroll-hint { display:block; }
            .btn-gold span { display:none; } .tb-title small { display:none; }
            .band-label { width:110px; }
            .mg-toolbar { padding:14px 16px; } .mg-toggle { width:100%; margin-right:0; }
            .mg-modal-foot .mg-btn { flex:1; justify-content:center; }
        }
        @media (max-width:520px) { .filter-form { grid-template-columns:1fr; padding:16px; } .kpi-strip { grid-template-columns:1fr; } }

        @media print {
            .sidebar, .topbar, .filter-form, .export-btns, .manage-card, .sidebar-backdrop, .icon-btn, .btn-gold, .mg-modal-overlay, .page-head .page-sub { display:none !important; }
            .app { display:block; } body { background:#fff; } .results { padding:0; }
            .table-scroll { max-height:none; overflow:visible; } .kpi-card, .band-card, .chart-card, .means-card { box-shadow:none; break-inside:avoid; }
        }
    </style>
</head>
<body>
<a href="#main-content" class="skip">Skip to content</a>

<div class="app">

    <!-- ══════════════════ SIDEBAR ══════════════════ -->
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
                <a href="ClassRanking.php"><i class="fa-solid fa-file-arrow-down"></i> Get Marks template</a>
            </div>
            <div class="nav-section">
                <div class="nav-title">Academics</div>
                <a href="ViewResults.php" class="active" aria-current="page"><i class="fa-solid fa-chart-pie"></i> Results</a>
                <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
                <a href="send_results_sms.php"><i class="fa-solid fa-comment-sms"></i> Send Results SMS</a>
                <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
                <a href="LearningMaterials.php"><i class="fa-solid fa-book"></i> Learning Materials</a>
            </div>
            <div class="nav-section">
                <div class="nav-title">Administration</div>
                <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
                <a href="ParentContacts.php"><i class="fa-solid fa-address-book"></i> Parent Contacts</a>
            </div>
        </nav>

        <div class="side-user">
            <div class="av"><?= $isHoi ? 'HOI' : ($isAdminUser ? 'AD' : 'TR') ?></div>
            <div><b><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></b><small>Signed in</small></div>
            <a href="logout.php" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
        </div>
        <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
    </aside>

    <div class="sidebar-backdrop" id="backdrop" onclick="toggleSidebar()"></div>

    <!-- ══════════════════ MAIN ══════════════════ -->
    <div class="main">

        <header class="topbar">
            <div class="tb-left">
                <button class="burger" type="button" onclick="toggleSidebar()" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
                <div class="tb-title">View Results<small>Academic Performance Transcript</small></div>
            </div>
            <div class="tb-right">
                <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
                <a href="UploadResults.php" class="btn-gold"><i class="fa-solid fa-upload"></i><span>Upload Results</span></a>
                <a href="logout.php" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
            </div>
        </header>

        <main id="main-content" class="results">

            <div class="page-head">
                <p class="page-eyebrow">Academics</p>
                <h1 class="page-title">Student Results</h1>
                <p class="page-sub">Stephen Kanja Primary &amp; Junior School — pick a grade, term, exam and year to see the full transcript, analysis and downloads.</p>
            </div>

            <!-- FILTER FORM -->
            <form method="GET" action="" class="filter-form" id="filterForm">
                <div class="form-group">
                    <label for="f-grade">Grade</label>
                    <select name="grade" id="f-grade" required>
                        <option value="">— Select —</option>
                        <?php
                        $selectedGrade = $_GET['grade'] ?? '';
                        for ($g = 1; $g <= 9; $g++) {
                            $sel = ($selectedGrade == $g) ? 'selected' : '';
                            echo "<option value='$g' $sel>Grade $g</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="f-term">Term</label>
                    <select name="term" id="f-term" required>
                        <option value="">— Select —</option>
                        <?php
                        $selectedTerm = $_GET['term'] ?? '';
                        foreach (['Term 1','Term 2','Term 3'] as $t) {
                            $sel = ($selectedTerm === $t) ? 'selected' : '';
                            echo "<option value='$t' $sel>$t</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="f-exam">Exam Type</label>
                    <select name="exam_type" id="f-exam" required>
                        <option value="">— Select —</option>
                        <?php
                        $selectedExam = resolveExamCode($_GET['exam_type'] ?? '', $EXAM_TYPES);
                        foreach ($EXAM_TYPES as $code => $et) {
                            $sel = ($selectedExam === $code) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . "' $sel>"
                               . htmlspecialchars($et['label'], ENT_QUOTES, 'UTF-8') . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="f-year">Year</label>
                    <select name="year" id="f-year" required>
                        <option value="">— Select —</option>
                        <?php
                        $selectedYear = $_GET['year'] ?? '';
                        for ($y = $yearFrom; $y <= $yearTo; $y++) {
                            $sel = ($selectedYear == $y) ? 'selected' : '';
                            echo "<option value='$y' $sel>$y</option>";
                        }
                        ?>
                    </select>
                </div>
                <button type="submit" class="btn-view"><i class="fa-solid fa-magnifying-glass-chart"></i> View Results</button>
            </form>

<?php
include 'subjects_config.php';

/* ── Result of a "delete learner marks" action (set by the redirect after the POST) ── */
if (isset($_GET['mm'])) {
    $flashDn = (int)($_GET['dn'] ?? 0);
    $flashDp = (int)($_GET['dp'] ?? 0);
    if (($_GET['mm'] ?? '') === 'ok') {
        $flashMsg = $flashDn > 0
            ? "Deleted <strong>$flashDn</strong> saved mark record" . ($flashDn === 1 ? '' : 's') . " for this exam."
            : "Nothing was deleted — no saved marks matched the selected learners.";
        $flashSub = $flashDp > 0 ? "Also removed $flashDp matching row" . ($flashDp === 1 ? '' : 's') . " from Progress Records." : '';
        echo "<div class='flash flash-ok'><i class='fa-solid fa-circle-check'></i><div>$flashMsg"
           . ($flashSub !== '' ? "<small>$flashSub</small>" : '') . "</div></div>";
    } else {
        echo "<div class='flash flash-err'><i class='fa-solid fa-triangle-exclamation'></i><div>The marks could not be deleted."
           . "<small>Your session may have expired, you may not have permission to delete marks, or the selection was invalid. Reload the page and try again.</small></div></div>";
    }
}

function awardPill($score, $grade, $cssPrefix = 'award') {
    $b = bandInfoForGrade($score, $grade);
    $tierClass = bandCssClass($b['code']);
    return "<span class='$cssPrefix $tierClass' title=\"{$b['label']}\">{$b['code']}</span>";
}

function normalizeTerm($term) {
    $digits = preg_replace('/[^0-9]/', '', (string)$term);
    return $digits !== '' ? $digits : strtolower(trim((string)$term));
}

function normalizeExamType($examType) {
    return preg_replace('/[^a-z0-9]/', '', strtolower((string)$examType));
}

/* ════════════════════════════════════════════════════════════════════
   PREVIOUS-EXAM LOOKUP
   ════════════════════════════════════════════════════════════════════ */

/* Chronological order of exams inside a term: built-ins first, then any
   custom exam types in the order they appear in the dropdown. */
function examSequence(array $EXAM_TYPES) {
    $seq = [];
    foreach (['opener', 'midterm', 'endterm'] as $c) $seq[] = normalizeExamType($c);
    foreach (array_keys($EXAM_TYPES) as $c) {
        $n = normalizeExamType($c);
        if (!in_array($n, $seq, true)) $seq[] = $n;
    }
    return $seq;
}

/* Finds the most recent exam BEFORE the selected one for this grade:
   1) earlier in the same year (same term first, then earlier terms), else
   2) the same term + exam in the previous year.
   Returns ['rows'=>[...], 'label'=>'...'] or null. */
function findPreviousExam($conn, $grade, $year, $termLabel, $examCode, array $EXAM_TYPES) {
    $seq     = examSequence($EXAM_TYPES);
    $curYear = (int)$year;
    $curTerm = (int)normalizeTerm($termLabel);
    $curExam = normalizeExamType($examCode);

    $rank = function ($y, $t, $e) use ($seq) {
        $ei = array_search($e, $seq, true);
        if ($ei === false) $ei = count($seq);
        return $y * 10000 + $t * 100 + $ei;
    };
    $curKey = $rank($curYear, $curTerm, $curExam);

    $labels = [];
    foreach ($EXAM_TYPES as $code => $et) $labels[normalizeExamType($code)] = $et['label'];

    $yPrev = (string)($curYear - 1);
    $yCur  = (string)$curYear;
    $stmt  = $conn->prepare("SELECT * FROM exam2 WHERE grade = ? AND year IN (?, ?)");
    if (!$stmt) return null;
    $stmt->bind_param('sss', $grade, $yPrev, $yCur);
    $stmt->execute();
    $res = $stmt->get_result();

    $groups = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $t = (int)normalizeTerm($r['term'] ?? '');
        $e = normalizeExamType($r['exam_type'] ?? '');
        $y = (int)($r['year'] ?? 0);
        if ($t === 0 || $e === '' || $y === 0) continue;
        $key = $rank($y, $t, $e);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'year' => $y, 'term' => $t,
                'label' => ($labels[$e] ?? ucfirst((string)($r['exam_type'] ?? ''))) . ", Term $t $y",
                'rows' => [],
            ];
        }
        // one row per student (guards against duplicate rows skewing the means)
        $sid = (string)($r['student_id'] ?? '');
        if ($sid !== '') $groups[$key]['rows'][$sid] = $r; else $groups[$key]['rows'][] = $r;
    }
    $stmt->close();

    // 1) latest earlier exam in the same year
    $best = null;
    foreach ($groups as $key => $g) {
        if ($g['year'] === $curYear && $key < $curKey && ($best === null || $key > $best)) $best = $key;
    }
    // 2) same term + exam in the previous year
    if ($best === null) {
        $fallback = $rank($curYear - 1, $curTerm, $curExam);
        if (isset($groups[$fallback])) $best = $fallback;
    }
    if ($best === null) return null;

    return ['rows' => array_values($groups[$best]['rows']), 'label' => $groups[$best]['label']];
}

function computePreviousStatsFromRows(array $rows, array $subjects) {
    $totals       = array_fill_keys($subjects, 0);
    $subjectBands = array_fill_keys($subjects, ['ee'=>0,'me'=>0,'ae'=>0,'be'=>0]);
    $classBands   = ['ee'=>0,'me'=>0,'ae'=>0,'be'=>0];
    $count        = 0;
    $subjectCount = count($subjects);

    foreach ($rows as $pr) {
        $rowTotal = 0;
        foreach ($subjects as $s) {
            $score = (int)($pr[$s] ?? 0);
            $totals[$s] += $score;
            $rowTotal   += $score;
            $subjectBands[$s][bandInfo($score)['tier']]++;
        }
        $classBands[bandInfo($subjectCount ? $rowTotal / $subjectCount : 0)['tier']]++;
        $count++;
    }
    return [$totals, $subjectBands, $classBands, $count];
}

$gradeRaw   = $_GET['grade']     ?? '';
$termRaw    = $_GET['term']      ?? '';
$yearRaw    = $_GET['year']      ?? '';
$examRaw    = $_GET['exam_type'] ?? '';

$grade      = ctype_digit((string)$gradeRaw) ? (string)$gradeRaw : '';
$termLabel  = in_array($termRaw, ['Term 1', 'Term 2', 'Term 3'], true) ? $termRaw : '';
$year       = ctype_digit((string)$yearRaw) ? (string)$yearRaw : '';
$gradeInt   = (int)$grade;

$exam_type    = resolveExamCode($examRaw, $EXAM_TYPES) ?? '';
$examDisplay  = $exam_type !== '' ? $EXAM_TYPES[$exam_type]['label'] : '';
$examDisplayH = htmlspecialchars($examDisplay, ENT_QUOTES, 'UTF-8');

$subjectMapForGrade = $gradeInt ? getSubjectsForGrade($gradeInt) : [];
$subjects      = array_keys($subjectMapForGrade);
$subjectLabels = array_values($subjectMapForGrade);
$subjectCount  = count($subjects);
$totalOutOf    = $subjectCount * 100;

$erHidden = erGate($conn, $grade, $termLabel, $exam_type, $examDisplay, $year, $subjectMapForGrade, $vrCsrf);

if ($grade && $termLabel && $exam_type && $year && $subjectCount > 0 && !$erHidden) {

    $stmt = $conn->prepare(
        "SELECT DISTINCT * FROM exam2 WHERE grade = ? AND exam_type = ? AND year = ?"
    );
    $stmt->bind_param('sss', $grade, $exam_type, $year);
    $stmt->execute();
    $result = $stmt->get_result();

    $currentRows = [];
    if ($result) {
        $targetTermNorm = normalizeTerm($termLabel);
        while ($row = mysqli_fetch_assoc($result)) {
            if (normalizeTerm($row['term'] ?? '') === $targetTermNorm) {
                $currentRows[] = $row;
            }
        }
    }
    $stmt->close();

    if (count($currentRows) > 0) {

        $subjectTotals = array_fill_keys($subjects, 0);
        $subjectBands  = array_fill_keys($subjects, ['ee'=>0,'me'=>0,'ae'=>0,'be'=>0]);
        $classBands    = ['ee'=>0,'me'=>0,'ae'=>0,'be'=>0];
        $subjectBands8 = array_fill_keys($subjects, array_fill_keys(allBandCodes(), 0));
        $classBands8   = array_fill_keys(allBandCodes(), 0);
        $totalSum      = 0;
        $students      = [];

        foreach ($currentRows as $row) {
            $subs = [];
            foreach ($subjects as $s) $subs[$s] = (int)($row[$s] ?? 0);
            $total = array_sum($subs);
            $row['total']    = $total;
            $row['subjects'] = $subs;
            $students[]      = $row;

            foreach ($subs as $s => $score) {
                $subjectTotals[$s] += $score;
                $b = bandInfo($score);
                $subjectBands[$s][$b['tier']]++;
                $subjectBands8[$s][$b['code']]++;
            }
            $avgPerSubject = $total / $subjectCount;
            $avgBand = bandInfo($avgPerSubject);
            $classBands[$avgBand['tier']]++;
            $classBands8[$avgBand['code']]++;
            $totalSum += $total;
        }

        $studentCount = count($students);
        $classMeanOfTotal = round($totalSum / $studentCount, 1);
        $classMeanPerSubject = round($classMeanOfTotal / $subjectCount, 1);

        usort($students, fn($a,$b) => $b['total'] <=> $a['total']);
        $rank = 1; $prevTotal = null; $repeatCount = 0;
        foreach ($students as $i => $student) {
            if ($student['total'] === $prevTotal) {
                $students[$i]['rank'] = $rank;
                $repeatCount++;
            } else {
                $rank += $repeatCount;
                $students[$i]['rank'] = $rank;
                $prevTotal = $student['total'];
                $repeatCount = 1;
            }
        }

        $subjectMeans = [];
        foreach ($subjects as $s) $subjectMeans[$s] = round($subjectTotals[$s] / $studentCount, 1);

        /* ── Previous exam (most recent earlier exam for this grade) ── */
        $prevMeans = [];
        $prevLabel = '';
        $hasPrev   = false;
        $prevSubjectBands     = array_fill_keys($subjects, ['ee'=>0,'me'=>0,'ae'=>0,'be'=>0]);
        $prevClassBands       = ['ee'=>0,'me'=>0,'ae'=>0,'be'=>0];
        $prevStudentCount     = 0;
        $prevClassMeanOfTotal = 0;

        $prevFound = findPreviousExam($conn, $grade, $year, $termLabel, $exam_type, $EXAM_TYPES);
        if ($prevFound && count($prevFound['rows']) > 0) {
            [$prevTotals, $prevSubjectBands, $prevClassBands, $prevCount] =
                computePreviousStatsFromRows($prevFound['rows'], $subjects);
            if ($prevCount > 0) {
                foreach ($subjects as $s) $prevMeans[$s] = round($prevTotals[$s] / $prevCount, 1);
                $prevStudentCount     = $prevCount;
                $prevClassMeanOfTotal = round(array_sum($prevTotals) / $prevCount, 1);
                $prevLabel            = $prevFound['label'];   // e.g. "End Term, Term 1 2026"
                $hasPrev              = true;
            }
        }

        $prevLabelH = htmlspecialchars($prevLabel, ENT_QUOTES, 'UTF-8');

        $jsSubjectLabels = json_encode($subjectLabels);
        $jsSubjectMeans  = json_encode(array_values($subjectMeans));
        $jsPrevMeans     = $hasPrev ? json_encode(array_values($prevMeans)) : 'null';
        $jsBandsEE       = json_encode(array_map(fn($s) => $subjectBands[$s]['ee'], $subjects));
        $jsBandsME       = json_encode(array_map(fn($s) => $subjectBands[$s]['me'], $subjects));
        $jsBandsAE       = json_encode(array_map(fn($s) => $subjectBands[$s]['ae'], $subjects));
        $jsBandsBE       = json_encode(array_map(fn($s) => $subjectBands[$s]['be'], $subjects));
        $jsClassBands    = json_encode([$classBands['ee'],$classBands['me'],$classBands['ae'],$classBands['be']]);
        $jsStudentCount  = $studentCount;

        $eeCount = $classBands['ee']; $meCount = $classBands['me'];
        $aeCount = $classBands['ae']; $beCount = $classBands['be'];
        $eeP = $studentCount ? round($eeCount/$studentCount*100) : 0;
        $meP = $studentCount ? round($meCount/$studentCount*100) : 0;
        $aeP = $studentCount ? round($aeCount/$studentCount*100) : 0;
        $beP = $studentCount ? round($beCount/$studentCount*100) : 0;

        echo "
        <div class='section-divider'>
            <h2>Grade $grade &mdash; $examDisplayH ($termLabel, $year)</h2>
            <span class='section-badge'>$studentCount Students</span>
        </div>

        <div class='kpi-strip'>
            <div class='kpi-card kpi-total' style='animation-delay:0.04s'>
                <div class='kpi-label'>Total Students</div>
                <div class='kpi-val'>$studentCount</div>
                <div class='kpi-sub'>in this grade &amp; exam</div>
            </div>
            <div class='kpi-card kpi-ee' style='animation-delay:0.08s'>
                <div class='kpi-label'>Exceeding Expectations</div>
                <div class='kpi-val'>$eeCount</div>
                <div class='kpi-sub'>$eeP% of class &mdash; Avg &ge;75/subject</div>
            </div>
            <div class='kpi-card kpi-me' style='animation-delay:0.12s'>
                <div class='kpi-label'>Class Mean Score</div>
                <div class='kpi-val'>$classMeanOfTotal</div>
                <div class='kpi-sub'>out of $totalOutOf &mdash; $classMeanPerSubject avg per subject</div>
            </div>
            <div class='kpi-card kpi-below' style='animation-delay:0.16s'>
                <div class='kpi-label'>Below Expectations</div>
                <div class='kpi-val'>$beCount</div>
                <div class='kpi-sub'>$beP% of class &mdash; Avg &lt;26/subject</div>
            </div>
        </div>
        ";

        echo "
        <div class='section-divider'><h2>Performance Analysis</h2></div>
        <div class='bands-grid'>
            <div class='band-card'>
                <h3>Student Achievement Bands (Overall)</h3>
                <div class='band-row'>
                    <div class='band-label' style='color:var(--ee)'>&#9679; Exceeding (E.E)</div>
                    <div class='band-bar-wrap'><div class='band-bar band-ee' style='width:{$eeP}%'></div></div>
                    <div class='band-count'>$eeCount ($eeP%)</div>
                </div>
                <div class='band-row'>
                    <div class='band-label' style='color:var(--me)'>&#9679; Meeting (M.E)</div>
                    <div class='band-bar-wrap'><div class='band-bar band-me' style='width:{$meP}%'></div></div>
                    <div class='band-count'>$meCount ($meP%)</div>
                </div>
                <div class='band-row'>
                    <div class='band-label' style='color:#d97706'>&#9679; Approaching (A.E)</div>
                    <div class='band-bar-wrap'><div class='band-bar band-ae' style='width:{$aeP}%'></div></div>
                    <div class='band-count'>$aeCount ($aeP%)</div>
                </div>
                <div class='band-row'>
                    <div class='band-label' style='color:var(--be)'>&#9679; Below (B.E)</div>
                    <div class='band-bar-wrap'><div class='band-bar band-be' style='width:{$beP}%'></div></div>
                    <div class='band-count'>$beCount ($beP%)</div>
                </div>
            </div>
            <div class='band-card'>
                <h3>Subject Means vs Benchmark (out of 100)</h3>
        ";

        $barColors = ['ee'=>'var(--ee)','me'=>'var(--me)','ae'=>'#d97706','be'=>'var(--be)'];
        foreach ($subjects as $idx => $s) {
            $mean = $subjectMeans[$s];
            $label = $subjectLabels[$idx];
            $pct  = min(100, round($mean));
            $cls  = bandLabel($mean);
            $col  = $barColors[$cls];
            echo "
                <div class='band-row'>
                    <div class='band-label'>$label</div>
                    <div class='band-bar-wrap'><div class='band-bar' style='background:$col;width:{$pct}%'></div></div>
                    <div class='band-count'>$mean</div>
                </div>
            ";
        }
        echo "</div></div>";

        $breakdownTitle = ($gradeInt >= 1 && $gradeInt <= 6) ? 'Performance Breakdown' : 'Performance Breakdown (8-Level)';
        echo "
        <div class='means-card'>
            <h3>$breakdownTitle</h3>
            <table class='means-table'>
                <thead><tr><th>Level</th><th class='l'>Meaning</th><th>Students (Class Avg)</th><th>% of Class</th></tr></thead>
                <tbody>
        ";
        if ($gradeInt >= 1 && $gradeInt <= 6) {
            $simpleTierMap = ['E.E'=>'ee','M.E'=>'me','A.E'=>'ae','B.E'=>'be'];
            foreach (allBandCodesForGrade($gradeInt) as $code) {
                $tier  = $simpleTierMap[$code];
                $count = $classBands[$tier];
                $pct   = $studentCount ? round($count / $studentCount * 100) : 0;
                $label = bandInfoForGrade(representativeScoreForGrade($code, $gradeInt), $gradeInt)['label'];
                echo "<tr><td><strong>$code</strong></td><td class='l'>$label</td><td>$count</td><td>$pct%</td></tr>";
            }
        } else {
            foreach (allBandCodes() as $code) {
                $count = $classBands8[$code];
                $pct   = $studentCount ? round($count / $studentCount * 100) : 0;
                $label = bandInfo(representativeScoreFor($code))['label'];
                echo "<tr><td><strong>$code</strong></td><td class='l'>$label</td><td>$count</td><td>$pct%</td></tr>";
            }
        }
        echo "</tbody></table></div>";

        echo "
        <div class='charts-grid'>
            <div class='chart-card'>
                <h3>Subject Mean Scores (out of 100)</h3>
                <div class='chart-wrap'><canvas id='subjectMeansChart'></canvas></div>
            </div>
            <div class='chart-card'>
                <h3>Class Achievement Distribution</h3>
                <div class='chart-wrap'><canvas id='classBandsChart'></canvas></div>
            </div>
            <div class='chart-card full-width'>
                <h3>Per-Subject Band Breakdown (All Students)</h3>
                <div class='chart-wrap tall'><canvas id='subjectBandsChart'></canvas></div>
            </div>
        </div>
        ";

        echo "<div class='section-divider'><h2>Comparison with Previous Exam</h2></div>";
        if ($hasPrev) {
            echo "
            <div class='compare-card'>
                <h3>Current ($examDisplayH) vs Previous ($prevLabelH) — Subject Means</h3>
                <div class='compare-meta'>
                    <div class='compare-pill'>
                        <div class='compare-pill-dot' style='background:var(--blue)'></div>
                        Current: $examDisplayH $termLabel $year
                    </div>
                    <div class='compare-pill'>
                        <div class='compare-pill-dot' style='background:rgba(131,146,165,0.6)'></div>
                        Previous: $prevLabelH
                    </div>
                </div>
                <div class='chart-wrap tall'><canvas id='compareChart'></canvas></div>
            </div>
            ";

            echo "
            <div class='means-card'>
                <h3>Subject-by-Subject Comparison Table</h3>
                <table class='means-table'>
                    <thead><tr>
                        <th class='l'>Subject</th><th>Current Mean</th><th>Previous Mean</th><th>Change</th>
                        <th>E.E</th><th>M.E</th><th>A.E</th><th>B.E</th>
                    </tr></thead>
                    <tbody>
            ";
            foreach ($subjects as $idx => $s) {
                $cur  = $subjectMeans[$s];
                $prev = $prevMeans[$s] ?? null;
                $diff = $prev !== null ? round($cur - $prev, 1) : null;
                $trendClass = $diff === null ? 'trend-same' : ($diff > 0 ? 'trend-up' : ($diff < 0 ? 'trend-down' : 'trend-same'));
                $trendStr   = $diff === null ? '—' : ($diff > 0 ? "▲ +$diff" : ($diff < 0 ? "▼ $diff" : "→ 0"));
                $prevStr    = $prev !== null ? $prev : '—';
                $eeC = $subjectBands[$s]['ee']; $meC = $subjectBands[$s]['me'];
                $aeC = $subjectBands[$s]['ae']; $beC = $subjectBands[$s]['be'];
                echo "<tr>
                    <td class='l'><strong>{$subjectLabels[$idx]}</strong></td>
                    <td class='mean-val'><span class='sc'>$cur</span>".awardPill($cur, $gradeInt)."</td>
                    <td>$prevStr</td>
                    <td class='$trendClass'>$trendStr</td>
                    <td>$eeC</td><td>$meC</td><td>$aeC</td><td>$beC</td>
                </tr>";
            }
            echo "</tbody></table></div>";
        } else {
            echo "<div class='compare-card'><div class='no-prev'>📊 No previous exam data found to compare against.<br>
            <small>The comparison uses the most recent earlier exam saved for this grade (e.g. Mid Term compares with Opener, and a new term's Opener compares with the previous term's last exam).</small></div></div>";
        }

        echo "
        <div class='section-divider'>
            <h2>Full Results Table</h2>
            <div class='export-btns'>
                <button type='button' id='downloadAnalysisBtn' class='btn-export'>
                    <svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4'/><polyline points='7 10 12 15 17 10'/><line x1='12' y1='15' x2='12' y2='3'/></svg>
                    Download Analysis (PDF)
                </button>
                <label class='rc-opt'><input type='checkbox' id='rcGraphs' checked> Include trend graphs</label>
                <button type='button' id='downloadReportCardsBtn' class='btn-export btn-export-gold'>
                    <svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z'/><polyline points='14 2 14 8 20 8'/><line x1='16' y1='13' x2='8' y2='13'/><line x1='16' y1='17' x2='8' y2='17'/></svg>
                    Download Report Cards (PDF)
                </button>
                <button type='button' id='downloadMarksExcelBtn' class='btn-export btn-export-green'>
                    <svg width='15' height='15' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='3' width='18' height='18' rx='2'/><line x1='3' y1='9' x2='21' y2='9'/><line x1='3' y1='15' x2='21' y2='15'/><line x1='9' y1='3' x2='9' y2='21'/></svg>
                    Download Marks (Excel)
                </button>
            </div>
        </div>
        <p class='scroll-hint'>← Scroll sideways to see all columns →</p>
        <div class='table-scroll'>
        <table class='results-table'>
            <thead><tr>
                <th>Rank</th><th>Assess. No</th><th class='l'>First Name</th><th class='l'>Surname</th>
        ";
        foreach ($subjectLabels as $label) echo "<th>$label</th>";
        echo "
                <th class='tot'>Total (/$totalOutOf)</th>
            </tr></thead>
            <tbody>
        ";

        foreach ($students as $student) {
            $r  = $student['rank'];
            $bc = $r===1 ? 'gold' : ($r===2 ? 'silver' : ($r===3 ? 'bronze' : ''));
            echo "<tr>
                <td><span class='rank-badge $bc'>$r</span></td>
                <td>{$student['Assesment']}</td>
                <td class='l'><strong>{$student['firstName']}</strong></td>
                <td class='l'>{$student['lastName']}</td>";
            foreach ($student['subjects'] as $sub => $score) echo "<td><span class='sc'>{$score}</span>".awardPill($score, $gradeInt)."</td>";
            echo "<td class='tot'><span class='sc'>{$student['total']}</span>".awardPill($student['total'] / $subjectCount, $gradeInt)."</td></tr>";
        }

        echo "<tr class='mean-row'><td colspan='4' class='lbl'>Class Mean</td>";
        foreach ($subjects as $s) {
            $sm = round($subjectTotals[$s] / $studentCount, 1);
            echo "<td><span class='sc'>$sm</span>".awardPill($sm, $gradeInt)."</td>";
        }
        echo "<td class='tot'><span class='sc'>$classMeanOfTotal</span>".awardPill($classMeanPerSubject, $gradeInt)."</td>";
        echo "</tr></tbody></table></div>";

/* ════════════════════════════════════════════════════════════════════
   MANAGE LEARNER MARKS
   Groups the saved rows by learner, flags the inconsistent ones, and lets
   you delete a learner's saved marks for THIS grade / term / exam / year.
   Flags (red = serious, amber = check):
     • Duplicate rows / conflicting marks  — split or repeated uploads
     • Mark outside 0–100 or non-numeric
     • No marks entered (blank or all zeros)
     • Learner not in the system (deleted / bad student_id)
     • Blank subject(s)                    — amber only, may be legitimate (e.g. CRE vs IRE)
   ════════════════════════════════════════════════════════════════════ */
        if ($canDeleteMarks) {

            /* Which learner ids still exist in the Student table */
            $mgKnown    = [];
            $mgLookupOk = false;
            $mgIds      = [];
            foreach ($currentRows as $mr) {
                $mid = trim((string)($mr['student_id'] ?? ''));
                if ($mid !== '') $mgIds[$mid] = true;
            }
            if ($mgIds) {
                try {
                    $mgIn = "'" . implode("','", array_map(function ($i) use ($conn) {
                        return mysqli_real_escape_string($conn, (string)$i);
                    }, array_keys($mgIds))) . "'";
                    $mgRes = mysqli_query($conn, "SELECT id FROM Student WHERE id IN ($mgIn)");
                    if ($mgRes) {
                        while ($mk = mysqli_fetch_assoc($mgRes)) $mgKnown[(string)$mk['id']] = true;
                        $mgLookupOk = true;
                    }
                } catch (\Throwable $e) { $mgLookupOk = false; }
            }

            /* Group rows per learner */
            $mgGroups = [];
            foreach ($currentRows as $mi => $mr) {
                $mSid = trim((string)($mr['student_id'] ?? ''));
                $mAss = trim((string)($mr['Assesment'] ?? ''));
                $mKey = $mSid !== '' ? 'sid:' . $mSid : ($mAss !== '' ? 'ass:' . $mAss : '');
                $mGk  = $mKey !== '' ? $mKey : 'row:' . $mi;
                if (!isset($mgGroups[$mGk])) {
                    $mgGroups[$mGk] = [
                        'key' => $mKey, 'sid' => $mSid, 'ass' => $mAss,
                        'name' => trim(($mr['firstName'] ?? '') . ' ' . ($mr['lastName'] ?? ($mr['surname'] ?? ''))),
                        'rows' => 0, 'vals' => array_fill_keys($subjects, null),
                        'conflict' => false, 'range' => false,
                    ];
                }
                $mgGroups[$mGk]['rows']++;
                foreach ($subjects as $ms) {
                    $mv = $mr[$ms] ?? null;
                    if ($mv === null || trim((string)$mv) === '') continue;
                    if (!is_numeric($mv)) { $mgGroups[$mGk]['range'] = true; continue; }
                    $mv = (float)$mv;
                    if ($mv < 0 || $mv > 100) $mgGroups[$mGk]['range'] = true;
                    if ($mgGroups[$mGk]['vals'][$ms] !== null && $mgGroups[$mGk]['vals'][$ms] != $mv) $mgGroups[$mGk]['conflict'] = true;
                    $mgGroups[$mGk]['vals'][$ms] = $mv;
                }
            }

            /* Flags */
            foreach ($mgGroups as $mGk => $mg) {
                $filled = count(array_filter($mg['vals'], function ($v) { return $v !== null; }));
                $sum    = array_sum(array_map(function ($v) { return $v ?? 0; }, $mg['vals']));
                $danger = []; $warn = [];
                if ($mg['rows'] > 1)  $danger[] = 'Duplicate rows (' . $mg['rows'] . ')';
                if ($mg['conflict'])  $danger[] = 'Conflicting marks';
                if ($mg['range'])     $danger[] = 'Mark outside 0–100';
                if ($filled === 0 || $sum == 0) $danger[] = 'No marks entered';
                if ($mgLookupOk && $mg['sid'] !== '' && !isset($mgKnown[$mg['sid']])) $danger[] = 'Learner not in system';
                if ($filled > 0 && $filled < $subjectCount) {
                    $blank = $subjectCount - $filled;
                    $warn[] = $blank . ' blank subject' . ($blank === 1 ? '' : 's');
                }
                $mgGroups[$mGk]['filled'] = $filled;
                $mgGroups[$mGk]['total']  = $sum;
                $mgGroups[$mGk]['danger'] = $danger;
                $mgGroups[$mGk]['warn']   = $warn;
            }
            $mgList = array_values($mgGroups);
            usort($mgList, function ($a, $b) {
                $pa = $a['danger'] ? 0 : ($a['warn'] ? 1 : 2);
                $pb = $b['danger'] ? 0 : ($b['warn'] ? 1 : 2);
                return $pa <=> $pb ?: strcasecmp($a['name'], $b['name']);
            });
            $mgFlagged = 0; $mgSerious = 0;
            foreach ($mgList as $mg) {
                if ($mg['danger'] || $mg['warn']) $mgFlagged++;
                if ($mg['danger']) $mgSerious++;
            }
?>

        <div class="section-divider" id="manage-marks">
            <h2>Manage Learner Marks</h2>
            <span class="section-badge"><?= (int)$mgFlagged ?> flagged</span>
        </div>

        <div class="manage-card">
            <div class="manage-intro">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    Tick the learners whose marks are wrong (duplicates, conflicting entries, empty or out-of-range marks) and delete them.
                    <strong>This removes ALL saved marks for the selected learner in
                    Grade <?= htmlspecialchars($grade) ?> · <?= htmlspecialchars($termLabel) ?> · <?= $examDisplayH ?> · <?= htmlspecialchars($year) ?> only</strong>
                    — other exams are not touched. It cannot be undone, but you can re-enter the marks from <a href="UploadResults.php">Upload Results</a>.
                </div>
            </div>

            <?php if ($mgFlagged === 0): ?>
            <div class="mg-clean"><i class="fa-solid fa-circle-check"></i> No inconsistencies found for this exam. You can still select any learner below to remove their marks.</div>
            <?php endif; ?>

            <form method="POST" action="" id="mgForm">
                <input type="hidden" name="action" value="delete_marks">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($vrCsrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="grade" value="<?= htmlspecialchars($grade, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="term" value="<?= htmlspecialchars($termLabel, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="exam_type" value="<?= htmlspecialchars($exam_type, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="year" value="<?= htmlspecialchars($year, ENT_QUOTES, 'UTF-8') ?>">

                <div class="mg-toolbar">
                    <label class="mg-toggle"><input type="checkbox" id="mgOnlyFlagged" <?= $mgFlagged > 0 ? 'checked' : '' ?>> Show only flagged learners</label>
                    <button type="button" class="mg-btn" id="mgSelSerious"><i class="fa-solid fa-wand-magic-sparkles"></i> Select serious issues (<?= (int)$mgSerious ?>)</button>
                    <button type="button" class="mg-btn" id="mgClear"><i class="fa-solid fa-xmark"></i> Clear selection</button>
                </div>

                <div class="mg-wrap">
                    <table class="mg-table">
                        <thead>
                            <tr>
                                <th class="col-chk"><input type="checkbox" id="mgAll" title="Select all shown"></th>
                                <th class="c">#</th><th class="c">Assess. No</th><th>Learner</th><th class="c">Rows</th><th class="c">Subjects</th><th class="c">Total</th><th>Issues</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($mgList as $mn => $mg):
                            $mFlag    = ($mg['danger'] || $mg['warn']) ? '1' : '0';
                            $mSerious = $mg['danger'] ? '1' : '0';
                        ?>
                            <tr data-mg="1" data-flag="<?= $mFlag ?>" data-serious="<?= $mSerious ?>">
                                <td class="col-chk">
                                    <?php if ($mg['key'] !== ''): ?>
                                    <input type="checkbox" class="mg-cb" name="keys[]" value="<?= htmlspecialchars($mg['key'], ENT_QUOTES, 'UTF-8') ?>" data-name="<?= htmlspecialchars($mg['name'] !== '' ? $mg['name'] : $mg['ass'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php else: ?>
                                    <input type="checkbox" disabled title="No learner id or assessment number — cannot be targeted safely">
                                    <?php endif; ?>
                                </td>
                                <td class="c"><?= $mn + 1 ?></td>
                                <td class="c"><?= htmlspecialchars($mg['ass'] !== '' ? $mg['ass'] : '—') ?></td>
                                <td class="mg-name"><?= htmlspecialchars($mg['name'] !== '' ? $mg['name'] : '—') ?></td>
                                <td class="c"><?= (int)$mg['rows'] ?></td>
                                <td class="c"><?= (int)$mg['filled'] ?> / <?= (int)$subjectCount ?></td>
                                <td class="c"><strong><?= htmlspecialchars((string)round($mg['total'], 1)) ?></strong></td>
                                <td>
                                    <div class="mg-flags">
                                        <?php foreach ($mg['danger'] as $fl): ?><span class="flag danger"><?= htmlspecialchars($fl) ?></span><?php endforeach; ?>
                                        <?php foreach ($mg['warn'] as $fl): ?><span class="flag warn"><?= htmlspecialchars($fl) ?></span><?php endforeach; ?>
                                        <?php if (!$mg['danger'] && !$mg['warn']): ?><span class="flag ok">Consistent</span><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mg-foot">
                    <span><strong id="mgSelCount">0</strong> learner(s) selected</span>
                    <button type="button" class="mg-btn mg-btn-danger" id="mgDeleteBtn" disabled><i class="fa-solid fa-trash"></i> Delete selected marks</button>
                </div>
            </form>
        </div>

        <div class="mg-modal-overlay" id="mgModal" role="dialog" aria-modal="true" aria-labelledby="mgModalTitle">
            <div class="mg-modal">
                <div class="mg-modal-head" id="mgModalTitle"><i class="fa-solid fa-trash"></i> Delete these learners' marks?</div>
                <div class="mg-modal-body">
                    <div class="mg-modal-meta">
                        Grade <?= htmlspecialchars($grade) ?> · <?= htmlspecialchars($termLabel) ?> · <?= $examDisplayH ?> · <?= htmlspecialchars($year) ?>
                    </div>
                    <ul class="mg-modal-list" id="mgModalList"></ul>
                    <label class="mg-confirm"><input type="checkbox" id="mgConfirmChk"> I understand this permanently deletes the saved marks listed above for this exam.</label>
                </div>
                <div class="mg-modal-foot">
                    <button type="button" class="mg-btn" id="mgCancel">Cancel</button>
                    <button type="button" class="mg-btn mg-btn-danger" id="mgConfirmBtn" disabled><i class="fa-solid fa-trash"></i> Delete permanently</button>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var form = document.getElementById('mgForm');
            if (!form) { return; }
            var rows    = [].slice.call(form.querySelectorAll('tbody tr[data-mg]'));
            var cbs     = [].slice.call(form.querySelectorAll('.mg-cb'));
            var countEl = document.getElementById('mgSelCount');
            var delBtn  = document.getElementById('mgDeleteBtn');
            var only    = document.getElementById('mgOnlyFlagged');
            var allBox  = document.getElementById('mgAll');
            var modal   = document.getElementById('mgModal');
            var listEl  = document.getElementById('mgModalList');
            var chk     = document.getElementById('mgConfirmChk');
            var okBtn   = document.getElementById('mgConfirmBtn');

            function visible(tr) { return tr.style.display !== 'none'; }
            function checkedBoxes() { return cbs.filter(function (c) { return c.checked; }); }

            function refresh() {
                var n = checkedBoxes().length;
                countEl.textContent = n;
                delBtn.disabled = (n === 0);
                rows.forEach(function (tr) {
                    var c = tr.querySelector('.mg-cb');
                    tr.classList.toggle('mg-sel', !!(c && c.checked));
                });
                var shown = cbs.filter(function (c) { return visible(c.closest('tr')); });
                allBox.checked = shown.length > 0 && shown.every(function (c) { return c.checked; });
            }

            function applyFilter() {
                rows.forEach(function (tr) {
                    var hide = only.checked && tr.getAttribute('data-flag') === '0';
                    tr.style.display = hide ? 'none' : '';
                    if (hide) { var c = tr.querySelector('.mg-cb'); if (c) { c.checked = false; } }   // never delete rows you can't see
                });
                refresh();
            }

            only.addEventListener('change', applyFilter);
            allBox.addEventListener('change', function () {
                cbs.forEach(function (c) { if (visible(c.closest('tr'))) { c.checked = allBox.checked; } });
                refresh();
            });
            cbs.forEach(function (c) { c.addEventListener('change', refresh); });

            document.getElementById('mgClear').addEventListener('click', function () {
                cbs.forEach(function (c) { c.checked = false; });
                refresh();
            });
            document.getElementById('mgSelSerious').addEventListener('click', function () {
                rows.forEach(function (tr) {
                    var c = tr.querySelector('.mg-cb');
                    if (c && tr.getAttribute('data-serious') === '1') {
                        tr.style.display = '';
                        c.checked = true;
                    }
                });
                refresh();
            });

            function openModal() {
                var sel = checkedBoxes();
                if (!sel.length) { return; }
                listEl.innerHTML = '';
                sel.forEach(function (c) {
                    var li = document.createElement('li');
                    li.textContent = c.getAttribute('data-name') || c.value;
                    listEl.appendChild(li);
                });
                chk.checked = false; okBtn.disabled = true;
                modal.classList.add('open');
            }
            function closeModal() { modal.classList.remove('open'); }

            delBtn.addEventListener('click', openModal);
            document.getElementById('mgCancel').addEventListener('click', closeModal);
            modal.addEventListener('click', function (e) { if (e.target === modal) { closeModal(); } });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModal(); } });
            chk.addEventListener('change', function () { okBtn.disabled = !chk.checked; });
            okBtn.addEventListener('click', function () {
                okBtn.disabled = true;
                okBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';
                form.submit();
            });

            applyFilter();
        })();
        </script>

<?php
        } // end if ($canDeleteMarks)

        /* ── Exam history for the report-card trend graphs ──
           Every exam for this grade, this year + last year, up to and including the
           selected one (last $limit exams), with each learner's marks per exam. */
        if (!function_exists('buildExamHistory')) {
            function buildExamHistory($conn, $grade, $year, $termLabel, $examCode, array $EXAM_TYPES, array $subjects, $limit = 6) {
                $seq     = examSequence($EXAM_TYPES);
                $curYear = (int)$year;
                $curTerm = (int)normalizeTerm($termLabel);
                $curExam = normalizeExamType($examCode);
                $rank = function ($y, $t, $e) use ($seq) {
                    $ei = array_search($e, $seq, true);
                    if ($ei === false) $ei = count($seq);
                    return $y * 10000 + $t * 100 + $ei;
                };
                $curKey = $rank($curYear, $curTerm, $curExam);

                $labels = [];
                foreach ($EXAM_TYPES as $code => $et) $labels[normalizeExamType($code)] = $et['label'];

                $yPrev = (string)($curYear - 1);
                $yCur  = (string)$curYear;
                $stmt  = $conn->prepare("SELECT * FROM exam2 WHERE grade = ? AND year IN (?, ?)");
                if (!$stmt) return [];
                $stmt->bind_param('sss', $grade, $yPrev, $yCur);
                $stmt->execute();
                $res = $stmt->get_result();

                $groups = [];
                while ($res && ($r = $res->fetch_assoc())) {
                    $t = (int)normalizeTerm($r['term'] ?? '');
                    $e = normalizeExamType($r['exam_type'] ?? '');
                    $y = (int)($r['year'] ?? 0);
                    if ($t === 0 || $e === '' || $y === 0) continue;
                    $key = $rank($y, $t, $e);
                    if ($key > $curKey) continue;                       // only exams up to the selected one
                    $sid = trim((string)($r['student_id'] ?? ''));
                    if ($sid === '') continue;
                    if (!isset($groups[$key])) {
                        $groups[$key] = [
                            'year' => $y, 'term' => $t,
                            'label' => ($labels[$e] ?? ucfirst((string)($r['exam_type'] ?? ''))),
                            'isCurrent' => ($key === $curKey), 'rows' => [],
                        ];
                    }
                    foreach ($subjects as $s) {                          // keep the highest mark if a learner has duplicate rows
                        $v = $r[$s] ?? null;
                        if ($v === null || $v === '' || !is_numeric($v)) continue;
                        $v = (float)$v;
                        if (!isset($groups[$key]['rows'][$sid][$s]) || $v > $groups[$key]['rows'][$sid][$s]) {
                            $groups[$key]['rows'][$sid][$s] = $v;
                        }
                    }
                }
                $stmt->close();

                ksort($groups);
                $groups = array_slice($groups, -$limit, null, true);

                $n   = max(1, count($subjects));
                $out = [];
                foreach ($groups as $g) {
                    $sum = 0; $cnt = 0;
                    foreach ($g['rows'] as $subs) { $sum += array_sum($subs) / $n; $cnt++; }
                    $out[] = [
                        'label'     => $g['label'] . ', Term ' . $g['term'] . ' ' . $g['year'],
                        'short'     => $g['label'] . ' T' . $g['term'] . ($g['year'] !== $curYear ? ' ' . $g['year'] : ''),
                        'classAvg'  => $cnt ? round($sum / $cnt, 1) : null,
                        'isCurrent' => $g['isCurrent'],
                        'rows'      => $g['rows'],
                    ];
                }
                return $out;
            }
        }

        $examHistory = buildExamHistory($conn, $grade, $year, $termLabel, $exam_type, $EXAM_TYPES, $subjects);
        $historyMeta = [];
        foreach ($examHistory as $hEx) {
            $historyMeta[] = ['label' => $hEx['label'], 'short' => $hEx['short'], 'classAvg' => $hEx['classAvg'], 'isCurrent' => $hEx['isCurrent']];
        }

        $exportStudents = [];
        foreach ($students as $student) {
            $sidH = trim((string)($student['student_id'] ?? ''));
            $hist = [];
            foreach ($examHistory as $hEx) {
                if ($hEx['isCurrent']) {
                    $subsH = array_values($student['subjects']);          // the exact marks shown in the table
                } elseif ($sidH !== '' && isset($hEx['rows'][$sidH])) {
                    $subsH = [];
                    foreach ($subjects as $sj) $subsH[] = $hEx['rows'][$sidH][$sj] ?? null;
                } else {
                    $hist[] = null;                                       // learner has no marks for that exam
                    continue;
                }
                $subsH = array_map(function ($v) { return ($v === null || $v <= 0) ? null : round((float)$v, 1); }, $subsH);
                $totH  = array_sum(array_map(function ($v) { return $v === null ? 0 : $v; }, $subsH));
                $hist[] = ['avg' => round($totH / max(1, $subjectCount), 1), 'subs' => $subsH];
            }
            $exportStudents[] = [
                'rank'      => $student['rank'],
                'assesment' => $student['Assesment'],
                'firstName' => $student['firstName'],
                'lastName'  => $student['lastName'],
                'subjects'  => array_values($student['subjects']),
                'total'     => $student['total'],
                'hist'      => $hist,
            ];
        }
        $jsExportStudents = json_encode($exportStudents);
        $jsExportHistory  = json_encode($historyMeta);
        $jsExportMeta = json_encode([
            'school'        => 'Stephen Kanja Primary & Junior School',
            'grade'         => $grade,
            'term'          => $termLabel,
            'exam'          => $examDisplay,
            'year'          => $year,
            'studentCount'  => $studentCount,
            'subjectCount'  => $subjectCount,
            'totalOutOf'    => $totalOutOf,
            'classMeanTotal'=> $classMeanOfTotal,
            'classMeanSubj' => $classMeanPerSubject,
            'eeCount' => $eeCount, 'eeP' => $eeP,
            'meCount' => $meCount, 'meP' => $meP,
            'aeCount' => $aeCount, 'aeP' => $aeP,
            'beCount' => $beCount, 'beP' => $beP,
            'gradeInt' => $gradeInt,
            'generatedAt' => date('jS F Y, g:i A'),
            'hasPrev' => $hasPrev,
            'prevLabel' => $prevLabel,
            'prevStudentCount' => $prevStudentCount,
            'prevClassMeanTotal' => $prevClassMeanOfTotal,
        ]);

/* ════════════════════════════════════════════════════════════════════
   ANALYSIS PDF DATA + GENERATOR
   Computes everything the PDF needs (adm no., stream, stream position,
   points, previous-exam positions, per-subject band splits) and emits the
   jsPDF generator. The previous exam comes from $prevFound (same exam the
   on-screen comparison uses). No `$` characters live inside the JS, so it
   can't be mangled by PHP string interpolation (it's a nowdoc).
   ════════════════════════════════════════════════════════════════════ */

if (!function_exists('analysisBandPoints')) {
    /* Points per band.
       Grades 1-6 (4-level):  E.E = 4, M.E = 3, A.E = 2, B.E = 1
       Grades 7-9 (8-level):  the "1" sub-band is the HIGHER one (EE1 = 90+ = 8 points). */
    function analysisBandPoints($code) {
        static $scale = ['EE1'=>8,'EE2'=>7,'ME1'=>6,'ME2'=>5,'AE1'=>4,'AE2'=>3,'BE1'=>2,'BE2'=>1,
                         'E.E'=>4,'M.E'=>3,'A.E'=>2,'B.E'=>1];
        return $scale[$code] ?? 0;
    }
    /* Converts an AVERAGE points value (can be decimal, e.g. 3.33) into a level. */
    function analysisLevelFromPoints($avg, $lower = false) {
        if ($lower) {                       // 4-level scale (Grades 1-6)
            if ($avg >= 3.5) return 'E.E';
            if ($avg >= 2.5) return 'M.E';
            if ($avg >= 1.5) return 'A.E';
            return 'B.E';
        }
        if ($avg >= 7.5) return 'EE1';
        if ($avg >= 6.5) return 'EE2';
        if ($avg >= 5.5) return 'ME1';
        if ($avg >= 4.5) return 'ME2';
        if ($avg >= 3.5) return 'AE1';
        if ($avg >= 2.5) return 'AE2';
        if ($avg >= 1.5) return 'BE1';
        return 'BE2';
    }
    /* Competition ranking (ties share a rank), highest first. */
    function analysisRanks(array $items, $key) {
        usort($items, function ($a, $b) use ($key) { return $b[$key] <=> $a[$key]; });
        $rank = 1; $prev = null; $repeat = 0;
        foreach ($items as $i => $it) {
            if ($it[$key] === $prev) { $items[$i]['rank'] = $rank; $repeat++; }
            else { $rank += $repeat; $items[$i]['rank'] = $rank; $prev = $it[$key]; $repeat = 1; }
        }
        return $items;
    }
    /* uid => rank within that item's stream */
    function analysisStreamRanks(array $items) {
        $groups = [];
        foreach ($items as $it) $groups[$it['stream']][] = $it;
        $out = [];
        foreach ($groups as $g) foreach (analysisRanks($g, 'total') as $it) $out[$it['uid']] = $it['rank'];
        return $out;
    }
}

$isLowerW     = ($gradeInt >= 1 && $gradeInt <= 6);
$hasPointsW   = true;   // points now apply to both the 4-level and 8-level scales
$pdfBandCodes = $isLowerW ? ['E.E','M.E','A.E','B.E'] : ['EE1','EE2','ME1','ME2','AE1','AE2','BE1','BE2'];

/* ── Previous-exam rows: exactly the exam used by the on-screen comparison ── */
$prevRowsAn = ($hasPrev && $prevFound) ? $prevFound['rows'] : [];

/* ── Adm No. + Stream from the Student table ──
   ADJUST `admissionNo` / `stream` if your Student columns are named
   differently. Wrapped in try/catch so a wrong name just leaves those
   columns blank instead of breaking the page. */
$idSet = [];
foreach ($students as $st) if (!empty($st['student_id'])) $idSet[(string)$st['student_id']] = true;
foreach ($prevRowsAn as $r) if (!empty($r['student_id'])) $idSet[(string)$r['student_id']] = true;
$metaMap = [];
if ($idSet) {
    try {
        $in = "'" . implode("','", array_map(function ($id) use ($conn) {
            return mysqli_real_escape_string($conn, (string)$id);
        }, array_keys($idSet))) . "'";
        $mres = mysqli_query($conn, "SELECT id, admissionNo, stream FROM Student WHERE id IN ($in)");
        if ($mres) {
            while ($m = mysqli_fetch_assoc($mres)) {
                $metaMap[(string)$m['id']] = [
                    'admNo'  => $m['admissionNo'] ?? '',
                    'stream' => (($m['stream'] ?? '') !== '') ? $m['stream'] : 'A',
                ];
            }
        }
    } catch (\Throwable $e) { /* leave blank */ }
}

/* ── Current students ── */
$curItems = [];
foreach ($students as $i => $st) {
    $sid = (string)($st['student_id'] ?? '');
    $curItems[] = ['uid' => $i, 'stream' => $metaMap[$sid]['stream'] ?? 'A', 'total' => $st['total']];
}
$curStreamRank = analysisStreamRanks($curItems);

$studentsAn = [];
$ptsAvgSum  = 0;
$overallSplits = array_fill_keys($pdfBandCodes, 0);
foreach ($students as $i => $st) {
    $sid  = (string)($st['student_id'] ?? '');
    $subs = []; $pts = 0;
    foreach ($subjects as $code) {
        $sc = (int)$st['subjects'][$code];
        $bc = bandInfoForGrade($sc, $gradeInt)['code'];
        $subs[] = ['s' => $sc, 'b' => $bc];
        $pts += analysisBandPoints($bc);
    }
    $avgPts = $subjectCount ? $pts / $subjectCount : 0;
    $ptsAvgSum += $avgPts;
    $level = bandInfoForGrade($st['total'] / max(1, $subjectCount), $gradeInt)['code'];
    if (isset($overallSplits[$level])) $overallSplits[$level]++;
    $studentsAn[] = [
        'sid'     => $sid,
        'admNo'   => $metaMap[$sid]['admNo'] ?? '',
        'name'    => trim(($st['firstName'] ?? '') . ' ' . ($st['lastName'] ?? '')),
        'ass'     => $st['Assesment'] ?? '',
        'stream'  => $metaMap[$sid]['stream'] ?? 'A',
        'strPos'  => $curStreamRank[$i] ?? $st['rank'],
        'rank'    => $st['rank'],
        'subs'    => $subs,
        'total'   => $st['total'],
        'avg'     => round($st['total'] / max(1, $subjectCount), 1),
        'pts'     => $pts,
        'avgPts'  => round($avgPts, 4),
        'level'   => $level,
    ];
}
$overallAvgPts = $studentCount ? $ptsAvgSum / $studentCount : 0;

/* ── Previous per-student positions + points ── */
$prevBySid = [];
$prevSubjPts = array_fill_keys($subjects, null);
$prevAvgPtsOverall = null;
if ($prevRowsAn) {
    $pItems = [];
    foreach ($prevRowsAn as $i => $r) {
        $sid = (string)($r['student_id'] ?? '');
        $t = 0; $p = 0;
        foreach ($subjects as $code) {
            $sc = (int)($r[$code] ?? 0);
            $t += $sc;
            $p += analysisBandPoints(bandInfoForGrade($sc, $gradeInt)['code']);
        }
        $pItems[] = ['uid' => $i, 'sid' => $sid, 'stream' => $metaMap[$sid]['stream'] ?? 'A',
                     'total' => $t, 'points' => $subjectCount ? $p / $subjectCount : 0];
    }
    $pStreamRank = analysisStreamRanks($pItems);
    $pSum = 0;
    foreach (analysisRanks($pItems, 'total') as $it) {
        $pSum += $it['points'];
        if ($it['sid'] !== '') $prevBySid[$it['sid']] = ['ovr' => $it['rank'], 'str' => $pStreamRank[$it['uid']] ?? $it['rank']];
    }
    $prevAvgPtsOverall = round($pSum / count($pItems), 4);
    foreach ($subjects as $code) {
        $sum = 0;
        foreach ($prevRowsAn as $r) $sum += analysisBandPoints(bandInfoForGrade((int)($r[$code] ?? 0), $gradeInt)['code']);
        $prevSubjPts[$code] = round($sum / count($prevRowsAn), 4);
    }
}
foreach ($studentsAn as $k => $sa) {
    $studentsAn[$k]['prevStr'] = $prevBySid[$sa['sid']]['str'] ?? null;
    $studentsAn[$k]['prevOvr'] = $prevBySid[$sa['sid']]['ovr'] ?? null;
}

/* ── Per-subject distribution ── */
$subjectsAn = [];
foreach ($subjects as $i => $code) {
    $splits = array_fill_keys($pdfBandCodes, 0);
    $ptsSum = 0;
    foreach ($students as $st) {
        $bc = bandInfoForGrade((int)$st['subjects'][$code], $gradeInt)['code'];
        if (isset($splits[$bc])) $splits[$bc]++;
        $ptsSum += analysisBandPoints($bc);
    }
    $avgP     = $studentCount ? round($ptsSum / $studentCount, 4) : 0;
    $prevP    = $prevSubjPts[$code];
    $mean     = $subjectMeans[$code];
    $prevMean = $prevMeans[$code] ?? null;

    /* FIX 1 — Grades 7-9: a subject's points come from its MEAN mark (mean → band → points) */
    if (!$isLowerW) {
        $avgP  = analysisBandPoints(bandInfoForGrade($mean, $gradeInt)['code']);
        $prevP = $prevMean !== null
            ? analysisBandPoints(bandInfoForGrade($prevMean, $gradeInt)['code'])
            : null;
    }

    $level    = $hasPointsW ? analysisLevelFromPoints($avgP, $isLowerW) : bandInfoForGrade((int)round($mean), $gradeInt)['code'];
    $prevLevel = null;
    if ($prevMean !== null) {
        $prevLevel = ($hasPointsW && $prevP !== null)
            ? analysisLevelFromPoints($prevP, $isLowerW)
            : bandInfoForGrade((int)round($prevMean), $gradeInt)['code'];
    }
    $subjectsAn[] = [
        'label' => $subjectLabels[$i], 'mean' => $mean, 'prevMean' => $prevMean,
        'avgPoints' => $avgP, 'prevPoints' => $prevP, 'level' => $level, 'prevLevel' => $prevLevel,
        'splits' => $splits, 'entry' => $studentCount,
        'teacher' => '',   // no teacher-per-subject data on this page; column auto-hides when empty
    ];
}

/* ── Class-level breakdown (same counts as the on-screen table) ── */
$breakdownAn = [];
if ($isLowerW) {
    foreach (['E.E'=>'ee','M.E'=>'me','A.E'=>'ae','B.E'=>'be'] as $code => $tier) {
        $cnt = $classBands[$tier];
        $breakdownAn[] = ['code' => $code,
            'label' => bandInfoForGrade(representativeScoreForGrade($code, $gradeInt), $gradeInt)['label'],
            'count' => $cnt, 'pct' => $studentCount ? round($cnt / $studentCount * 100) : 0];
    }
} else {
    foreach (allBandCodes() as $code) {
        $cnt = $classBands8[$code];
        $breakdownAn[] = ['code' => $code,
            'label' => bandInfo(representativeScoreFor($code))['label'],
            'count' => $cnt, 'pct' => $studentCount ? round($cnt / $studentCount * 100) : 0];
    }
}

$prevPerSubject = ($prevStudentCount > 0 && $subjectCount) ? round($prevClassMeanOfTotal / $subjectCount, 1) : null;

/* FIX 2 — Grades 7-9: overall points = (class mean total ÷ number of subjects, i.e. 9) → band → points */
if (!$isLowerW) {
    $overallAvgPts = analysisBandPoints(bandInfoForGrade($classMeanOfTotal / max(1, $subjectCount), $gradeInt)['code']);
    $prevAvgPtsOverall = $prevPerSubject !== null
        ? analysisBandPoints(bandInfoForGrade($prevPerSubject, $gradeInt)['code'])
        : null;
}

$analysisPayload = [
    'meta' => [
        'school' => 'Stephen Kanja Primary & Junior School',
        'grade' => $grade, 'term' => $termLabel, 'exam' => $examDisplay, 'year' => $year,
        'studentCount' => $studentCount, 'subjectCount' => $subjectCount, 'totalOutOf' => $totalOutOf,
        'classMeanTotal' => $classMeanOfTotal, 'classMeanPerSubject' => $classMeanPerSubject,
        'generatedAt' => date('d.m.Y \a\t H:i:s'),
        'hasPoints' => $hasPointsW, 'bandCodes' => $pdfBandCodes,
    ],
    'kpis' => ['total' => $studentCount, 'ee' => $eeCount, 'eeP' => $eeP, 'be' => $beCount, 'beP' => $beP],
    'breakdown' => $breakdownAn,
    'subjects'  => $subjectsAn,
    'students'  => $studentsAn,
    'overall'   => [
        'splits' => $overallSplits, 'entry' => $studentCount, 'mean' => $classMeanPerSubject,
        'prevMean' => $prevPerSubject,
        'avgPoints' => round($overallAvgPts, 4), 'prevPoints' => $prevAvgPtsOverall,
        'level' => $hasPointsW ? analysisLevelFromPoints($overallAvgPts, $isLowerW)
                               : bandInfoForGrade((int)round($classMeanPerSubject), $gradeInt)['code'],
    ],
    'comparison' => [
        'hasPrev' => $hasPrev, 'label' => $prevLabel, 'prevN' => $prevStudentCount,
        'prevMeanTotal' => $prevClassMeanOfTotal,
        'bandShift' => [
            ['code' => 'E.E', 'label' => 'Exceeding Expectation',   'cur' => $classBands['ee'], 'prev' => $prevClassBands['ee']],
            ['code' => 'M.E', 'label' => 'Meeting Expectation',     'cur' => $classBands['me'], 'prev' => $prevClassBands['me']],
            ['code' => 'A.E', 'label' => 'Approaching Expectation', 'cur' => $classBands['ae'], 'prev' => $prevClassBands['ae']],
            ['code' => 'B.E', 'label' => 'Below Expectation',       'cur' => $classBands['be'], 'prev' => $prevClassBands['be']],
        ],
    ],
];

echo "<script>window.ANALYSIS_DATA = "
   . json_encode($analysisPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR)
   . ";</script>\n";

echo <<<'ANALYSIS_JS'
<script>
(function () {
  var D = window.ANALYSIS_DATA;
  if (!D) { return; }

  /* ── FONT SIZES (pt) — change these to scale the whole PDF ── */
  var FS = {
    school: 18,        // school name at top of every page
    title: 12.5,       // report title
    meta: 9.5,         // CLASS / TERM / YEAR line
    kpiLabel: 8,       // small caps label on KPI cards
    kpiValue: 18,      // big number on KPI cards
    kpiSub: 8,         // line under the KPI number
    heading: 10,       // section headings
    body: 10,          // front page + comparison tables
    bodyHead: 9,       // header row of those tables
    merit: 8,          // merit list marks + names
    meritHead: 7,      // merit list header row
    dist: 9,           // grade distribution table
    distHead: 8,       // grade distribution header row
    notes: 10,         // notes under the merit list
    footer: 9          // page footer
  };

  /* Level colours — sub-band 1 is the HIGHER one (EE1 = 90+). */
  var COLORS = {
    'EE1': { bg: [187,247,208], fg: [21,128,61] },
    'EE2': { bg: [220,252,231], fg: [22,163,74] },
    'ME1': { bg: [207,224,243], fg: [18,54,87] },
    'ME2': { bg: [220,232,245], fg: [26,74,122] },
    'AE1': { bg: [253,230,138], fg: [146,64,14] },
    'AE2': { bg: [254,243,199], fg: [180,83,9] },
    'BE1': { bg: [254,202,202], fg: [127,29,29] },
    'BE2': { bg: [254,226,226], fg: [153,27,27] },
    'E.E': { bg: [220,252,231], fg: [22,163,74] },
    'M.E': { bg: [220,232,245], fg: [26,74,122] },
    'A.E': { bg: [254,243,199], fg: [180,83,9] },
    'B.E': { bg: [254,226,226], fg: [153,27,27] }
  };
  var SHORT = {
    english: 'ENG', kiswahili: 'KIS', mathematics: 'MAT', integratedscience: 'INT/SC',
    creativeartsandsports: 'CAS', socialstudies: 'SST', christianreligiouseducation: 'C.R.E',
    islamicreligiouseducation: 'I.R.E', agriculture: 'AGRI', pretechnicalstudies: 'PRE TECH',
    pretechnical: 'PRE TECH', environmentalactivities: 'ENV', religiouseducation: 'RE'
  };
  function short(label) {
    var k = String(label).toLowerCase().replace(/[^a-z]/g, '');
    return SHORT[k] || String(label).toUpperCase().slice(0, 8);
  }
  function colorFor(text) {
    var s = String(text == null ? '' : text).trim();
    if (!s) { return null; }
    var parts = s.split(/\s+/);
    return COLORS[parts[parts.length - 1]] || null;
  }
  function paint(cell, c) {
    if (!c) { return; }
    cell.styles.fillColor = c.bg;
    cell.styles.textColor = c.fg;
    cell.styles.fontStyle = 'bold';
  }
  function f(n, d) {
    if (n === null || n === undefined || isNaN(n)) { return '-'; }
    return Number(n).toFixed(d);
  }
  function signed(n, d) {
    if (n === null || n === undefined || isNaN(n)) { return '-'; }
    return (n > 0 ? '+' : '') + Number(n).toFixed(d);
  }
  function signColor(cell) {
    var t = cell.text.join('');
    if (t.charAt(0) === '+') { cell.styles.textColor = [22,163,74]; cell.styles.fontStyle = 'bold'; }
    else if (t.length > 1 && t.charAt(0) === '-') { cell.styles.textColor = [153,27,27]; cell.styles.fontStyle = 'bold'; }
  }

  function buildPdf() {
    var doc = new window.jspdf.jsPDF({ unit: 'pt', format: 'a4', orientation: 'landscape' });
    var W = doc.internal.pageSize.getWidth();
    var H = doc.internal.pageSize.getHeight();
    var M = 26;
    var BOTTOM = 40;
    var subj = D.subjects;
    var hp = D.meta.hasPoints;

    function header(title) {
      doc.setFont('helvetica', 'bold'); doc.setFontSize(FS.school); doc.setTextColor(16, 41, 63);
      doc.text(D.meta.school, M, 36);
      doc.setFontSize(FS.title); doc.setTextColor(15, 25, 35);
      doc.text(title, M, 55);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(FS.meta); doc.setTextColor(61, 79, 92);
      doc.text('CLASS: GRADE ' + D.meta.grade + '    TERM: ' + D.meta.term + '    YEAR: ' + D.meta.year +
               '    EXAM: ' + String(D.meta.exam).toUpperCase(), M, 70);
      doc.setDrawColor(240, 192, 64); doc.setLineWidth(1.5); doc.line(M, 78, W - M, 78);
      return 92;
    }
    function kpiCards(y, items) {
      var gap = 10, n = items.length, w = (W - 2 * M - gap * (n - 1)) / n, h = 64;
      items.forEach(function (it, i) {
        var x = M + i * (w + gap);
        doc.setDrawColor(216, 216, 216); doc.setLineWidth(0.6); doc.roundedRect(x, y, w, h, 4, 4, 'S');
        doc.setFillColor(it.accent[0], it.accent[1], it.accent[2]); doc.rect(x, y, w, 3.5, 'F');
        doc.setFont('helvetica', 'bold'); doc.setFontSize(FS.kpiLabel); doc.setTextColor(122, 144, 158);
        doc.text(it.label, x + 10, y + 19);
        doc.setFontSize(FS.kpiValue); doc.setTextColor(15, 25, 35);
        doc.text(it.value, x + 10, y + 42);
        if (it.sub) {
          doc.setFont('helvetica', 'normal'); doc.setFontSize(FS.kpiSub); doc.setTextColor(122, 144, 158);
          doc.text(it.sub, x + 10, y + 56, { maxWidth: w - 20 });
        }
      });
      return y + h + 16;
    }
    function heading(text, x, y) {
      doc.setFont('helvetica', 'bold'); doc.setFontSize(FS.heading); doc.setTextColor(61, 79, 92);
      doc.text(text, x, y);
    }
    var HEAD = { fillColor: [16, 41, 63], textColor: [255, 255, 255], fontStyle: 'bold', halign: 'center', fontSize: FS.bodyHead };
    var BASE = { font: 'helvetica', fontSize: FS.body, cellPadding: 4.5, textColor: [40, 55, 66], lineColor: [216, 216, 216], lineWidth: 0.4 };

    /* ═════ PAGE 1 — CLASS PERFORMANCE SUMMARY ═════ */
    var y = header('REPORT: CLASS PERFORMANCE SUMMARY');
    y = kpiCards(y, [
      { label: 'TOTAL STUDENTS', value: String(D.kpis.total), sub: 'in this grade & exam', accent: [16, 41, 63] },
      { label: 'EXCEEDING EXPECTATIONS', value: D.kpis.ee + ' (' + D.kpis.eeP + '%)', sub: 'Avg >= 75 per subject', accent: [22, 163, 74] },
      { label: 'CLASS MEAN SCORE', value: D.meta.classMeanTotal + ' / ' + D.meta.totalOutOf, sub: D.meta.classMeanPerSubject + ' avg per subject', accent: [26, 74, 122] },
      { label: 'BELOW EXPECTATIONS', value: D.kpis.be + ' (' + D.kpis.beP + '%)', sub: 'Avg < 26 per subject', accent: [153, 27, 27] }
    ]);
    var half = (W - 2 * M - 16) / 2;
    heading('ACHIEVEMENT BANDS (OVERALL)', M, y);
    heading('SUBJECT MEANS (OUT OF 100)', M + half + 16, y);
    doc.autoTable({
      startY: y + 7, margin: { left: M, right: W - M - half, bottom: BOTTOM }, tableWidth: half, theme: 'grid',
      head: [['Level', 'Meaning', 'Students', '%']],
      body: D.breakdown.map(function (b) { return [b.code, b.label, String(b.count), b.pct + '%']; }),
      styles: BASE, headStyles: HEAD,
      columnStyles: { 0: { cellWidth: 46, halign: 'center' }, 2: { halign: 'center' }, 3: { halign: 'center' } },
      didParseCell: function (d) { if (d.section === 'body' && d.column.index === 0) { paint(d.cell, colorFor(d.cell.text.join(' '))); } }
    });
    doc.autoTable({
      startY: y + 7, margin: { left: M + half + 16, right: M, bottom: BOTTOM }, tableWidth: half, theme: 'grid',
      head: [['Subject', 'Mean', 'Level']],
      body: subj.map(function (s) { return [s.label, f(s.mean, 1), s.level]; }),
      styles: BASE, headStyles: HEAD,
      columnStyles: { 1: { halign: 'center' }, 2: { halign: 'center', cellWidth: 56 } },
      didParseCell: function (d) { if (d.section === 'body' && d.column.index === 2) { paint(d.cell, colorFor(d.cell.text.join(' '))); } }
    });

    /* ═════ MERIT LIST ═════ */
    doc.addPage();
    y = header("REPORT: STUDENTS' PERFORMANCE MERIT LIST");
    var idCols = 9, nSub = subj.length;
    var head1 = ['S.No', 'ADM No.', 'Student Name', 'Ass No', 'Stream', 'Str Pos', 'Ovr Pos', 'Prv Str', 'Prv Ovr']
      .concat(subj.map(function (s) { return short(s.label); }))
      .concat(['Sub. Entry', 'Total Marks', 'Avg Marks']);
    if (hp) { head1 = head1.concat(['Total Pts', 'Avg Pts']); }
    head1.push('Level');
    var lastIdx = head1.length - 1;

    var body1 = D.students.map(function (s, i) {
      var r = [String(i + 1), s.admNo || '', s.name, s.ass || '', s.stream || 'A', String(s.strPos), String(s.rank),
               s.prevStr == null ? '-' : String(s.prevStr), s.prevOvr == null ? '-' : String(s.prevOvr)];
      s.subs.forEach(function (x) { r.push(x.s + ' ' + x.b); });
      r.push(String(s.subs.length), String(s.total), f(s.avg, 1));
      if (hp) { r.push(String(s.pts), f(s.avgPts, 2)); }
      r.push(s.level);
      return r;
    });
    var trailing = head1.length - idCols - nSub;
    var grey = [242, 239, 234];
    function sumRow(label, cells) {
      var row = [{ content: label, colSpan: idCols, styles: { halign: 'right', fontStyle: 'bold', fillColor: grey } }];
      cells.forEach(function (c) { row.push({ content: c, styles: { fontStyle: 'bold', fillColor: grey } }); });
      row.push({ content: '', colSpan: trailing, styles: { fillColor: grey } });
      return row;
    }
    body1.push(sumRow('AVERAGE MARKS', subj.map(function (s) { return f(s.mean, 2) + '%'; })));
    if (hp) { body1.push(sumRow('AVERAGE POINTS', subj.map(function (s) { return f(s.avgPoints, 4); }))); }
    body1.push(sumRow('PERFORMANCE LEVEL', subj.map(function (s) { return s.level; })));

    doc.autoTable({
      startY: y, margin: { left: 18, right: 18, top: 30, bottom: BOTTOM }, theme: 'grid',
      head: [head1], body: body1,
      styles: { font: 'helvetica', fontSize: FS.merit, cellPadding: { top: 2.6, bottom: 2.6, left: 1.6, right: 1.6 },
                textColor: [25, 38, 48], halign: 'center', valign: 'middle', lineColor: [200, 200, 200], lineWidth: 0.3 },
      headStyles: { fillColor: [16, 41, 63], textColor: [255, 255, 255], fontStyle: 'bold', halign: 'center', fontSize: FS.meritHead },
      columnStyles: { 0: { cellWidth: 22 }, 1: { cellWidth: 30 }, 2: { cellWidth: 100, halign: 'left', overflow: 'ellipsize', fontStyle: 'bold' },
                      3: { cellWidth: 44 }, 4: { cellWidth: 26 }, 6: { fontStyle: 'bold' } },
      didParseCell: function (d) {
        if (d.section !== 'body') { return; }
        var ci = d.column.index;
        if ((ci >= idCols && ci < idCols + nSub) || ci === lastIdx) { paint(d.cell, colorFor(d.cell.text.join(' '))); }
      }
    });
    var ny = doc.lastAutoTable.finalY + 16;
    if (ny > H - 70) { doc.addPage(); ny = 44; }
    doc.setFont('helvetica', 'normal'); doc.setFontSize(FS.notes); doc.setTextColor(61, 79, 92);
    doc.text('- CLASS AVERAGE MARKS: ' + Number(D.meta.classMeanTotal).toFixed(1), M, ny);
    doc.text('- Student position assigned by using Total marks', M, ny + 14);
    doc.text('- Student performance level calculated using student average marks', M, ny + 28);

    /* ═════ SUBJECT GRADE DISTRIBUTION ═════ */
    doc.addPage();
    y = header('REPORT: SUBJECT GRADE DISTRIBUTION REPORT');
    var O = D.overall;
    var oCur = hp ? O.avgPoints : O.mean, oPrev = hp ? O.prevPoints : O.prevMean;
    var oDev = (oPrev === null || oPrev === undefined) ? null : oCur - oPrev;
    y = kpiCards(y, [
      { label: "GRADED STUDENTS' COUNT", value: String(O.entry), accent: [16, 41, 63] },
      { label: hp ? 'AVERAGE POINTS' : 'AVERAGE MARKS (PER SUBJECT)', value: f(oCur, hp ? 4 : 1), accent: [26, 74, 122] },
      { label: 'LEVEL', value: O.level, accent: [240, 192, 64] },
      { label: 'DEVIATION VS PREVIOUS', value: oDev === null ? '-' : signed(oDev, hp ? 4 : 1), accent: [22, 163, 74] }
    ]);
    var codes = D.meta.bandCodes;
    var hasTeacher = subj.some(function (s) { return s.teacher; });
    var rows3 = [{ label: 'Mean Marks (Overall)', splits: O.splits, entry: O.entry, mean: O.mean, prevMean: O.prevMean,
                   avgPoints: O.avgPoints, prevPoints: O.prevPoints, level: O.level, teacher: '' }].concat(subj);
    var head3 = ['Learning Area'].concat(codes).concat(['Entry', 'Avg Marks']);
    if (hp) { head3.push('Avg Points'); }
    head3.push('Level', hp ? 'Prev Points' : 'Prev Marks', 'DEV');
    if (hasTeacher) { head3.push('Teacher'); }
    var levelIdx = 1 + codes.length + 2 + (hp ? 1 : 0);
    var devIdx = levelIdx + 2;
    var body3 = rows3.map(function (s) {
      var r = [s.label];
      codes.forEach(function (c) { r.push(String(s.splits[c] || 0)); });
      r.push(String(s.entry), f(s.mean, 2));
      if (hp) { r.push(f(s.avgPoints, 4)); }
      r.push(s.level);
      var cur = hp ? s.avgPoints : s.mean, prv = hp ? s.prevPoints : s.prevMean;
      var has = !(prv === null || prv === undefined);
      r.push(has ? f(prv, hp ? 4 : 1) : '-');
      r.push(has ? signed(cur - prv, hp ? 4 : 1) : '-');
      if (hasTeacher) { r.push(s.teacher || ''); }
      return r;
    });
    doc.autoTable({
      startY: y, margin: { left: M, right: M, bottom: BOTTOM }, theme: 'grid',
      head: [head3], body: body3,
      styles: { font: 'helvetica', fontSize: FS.dist, cellPadding: 4, textColor: [25, 38, 48], lineColor: [216, 216, 216], lineWidth: 0.4, valign: 'middle' },
      headStyles: { fillColor: [16, 41, 63], textColor: [255, 255, 255], fontStyle: 'bold', halign: 'center', fontSize: FS.distHead },
      columnStyles: (function () { var c = { 0: { halign: 'left', cellWidth: 128, fontStyle: 'bold' } }; for (var i = 1; i < head3.length; i++) { c[i] = { halign: 'center' }; } return c; })(),
      didParseCell: function (d) {
        if (d.section !== 'body') { return; }
        if (d.row.index === 0) { d.cell.styles.fontStyle = 'bold'; d.cell.styles.fillColor = [242, 239, 234]; }
        if (d.column.index === levelIdx) { paint(d.cell, colorFor(d.cell.text.join(' '))); }
        if (d.column.index === devIdx) { signColor(d.cell); }
      }
    });

    /* ═════ COMPARISON WITH PREVIOUS EXAM ═════ */
    doc.addPage();
    y = header('REPORT: COMPARISON WITH PREVIOUS EXAM');
    var C = D.comparison;
    if (!C.hasPrev) {
      doc.setFont('helvetica', 'normal'); doc.setFontSize(12); doc.setTextColor(122, 144, 158);
      doc.text('No previous exam data found to compare against.', M, y + 20);
    } else {
      var meanDiff = Math.round((D.meta.classMeanTotal - C.prevMeanTotal) * 10) / 10;
      y = kpiCards(y, [
        { label: 'CURRENT CLASS MEAN', value: String(D.meta.classMeanTotal), sub: D.meta.exam + ' ' + D.meta.term + ' ' + D.meta.year, accent: [26, 74, 122] },
        { label: 'PREVIOUS CLASS MEAN', value: String(C.prevMeanTotal), sub: C.label, accent: [16, 41, 63] },
        { label: 'CHANGE IN CLASS MEAN', value: signed(meanDiff, 1), accent: meanDiff >= 0 ? [22, 163, 74] : [153, 27, 27] },
        { label: 'PREVIOUS STUDENT COUNT', value: String(C.prevN), sub: 'current: ' + D.meta.studentCount, accent: [240, 192, 64] }
      ]);
      heading('SUBJECT-BY-SUBJECT COMPARISON', M, y);
      doc.autoTable({
        startY: y + 7, margin: { left: M, right: M, bottom: BOTTOM }, theme: 'grid',
        head: [['Subject', 'Current Mean', 'Previous Mean', 'Change', 'Current Level', 'Previous Level']],
        body: subj.map(function (s) {
          var diff = (s.prevMean === null || s.prevMean === undefined) ? null : Math.round((s.mean - s.prevMean) * 10) / 10;
          return [s.label, f(s.mean, 1), f(s.prevMean, 1), diff === null ? '-' : signed(diff, 1), s.level, s.prevLevel || '-'];
        }),
        styles: Object.assign({}, BASE, { cellPadding: 4 }), headStyles: HEAD,
        columnStyles: { 0: { halign: 'left', cellWidth: 200, fontStyle: 'bold' }, 1: { halign: 'center' }, 2: { halign: 'center' }, 3: { halign: 'center' }, 4: { halign: 'center' }, 5: { halign: 'center' } },
        didParseCell: function (d) {
          if (d.section !== 'body') { return; }
          if (d.column.index === 3) { signColor(d.cell); }
          if (d.column.index === 4 || d.column.index === 5) { paint(d.cell, colorFor(d.cell.text.join(' '))); }
        }
      });
      var by = doc.lastAutoTable.finalY + 22;
      if (by > H - 150) { doc.addPage(); by = 44; }
      heading('ACHIEVEMENT BAND SHIFT (CURRENT VS PREVIOUS)', M, by);
      doc.autoTable({
        startY: by + 7, margin: { left: M, right: M, bottom: BOTTOM }, theme: 'grid',
        head: [['Level', 'Meaning', 'Current Students', 'Previous Students', 'Change']],
        body: C.bandShift.map(function (b) { return [b.code, b.label, String(b.cur), String(b.prev), signed(b.cur - b.prev, 0)]; }),
        styles: Object.assign({}, BASE, { cellPadding: 4 }), headStyles: HEAD,
        columnStyles: { 0: { halign: 'center', cellWidth: 56 }, 2: { halign: 'center' }, 3: { halign: 'center' }, 4: { halign: 'center' } },
        didParseCell: function (d) {
          if (d.section !== 'body') { return; }
          if (d.column.index === 0) { paint(d.cell, colorFor(d.cell.text.join(' '))); }
          if (d.column.index === 4) { signColor(d.cell); }
        }
      });
    }

    /* ═════ FOOTER ON EVERY PAGE ═════ */
    var n = doc.getNumberOfPages();
    for (var p = 1; p <= n; p++) {
      doc.setPage(p);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(FS.footer); doc.setTextColor(122, 144, 158);
      doc.text('Report generated on: ' + D.meta.generatedAt, M, H - 16);
      doc.text('Page ' + p + '/' + n, W - M, H - 16, { align: 'right' });
    }

    doc.save('Grade' + D.meta.grade + '_' + String(D.meta.exam).replace(/\s+/g, '') + '_' +
             String(D.meta.term).replace(/\s+/g, '') + '_' + D.meta.year + '_Analysis.pdf');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('downloadAnalysisBtn');
    if (!btn) { return; }
    btn.addEventListener('click', function () {
      var original = btn.innerHTML;
      btn.disabled = true; btn.innerHTML = 'Preparing...';
      try { buildPdf(); }
      catch (err) { console.error('Analysis PDF failed:', err); alert('Sorry, the analysis PDF could not be generated.'); }
      finally { btn.disabled = false; btn.innerHTML = original; }
    });
  });
})();
</script>
ANALYSIS_JS;
echo "\n";

        echo "
        <script src='https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js'></script>
        <script src='https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js'></script>
        <script src='https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js'></script>
        <script src='https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js'></script>
        <script>
        (function() {
            const subjectLabels = $jsSubjectLabels;
            const subjectMeans  = $jsSubjectMeans;
            const prevMeans     = $jsPrevMeans;
            const bandsEE       = $jsBandsEE;
            const bandsME       = $jsBandsME;
            const bandsAE       = $jsBandsAE;
            const bandsBE       = $jsBandsBE;
            const classBands    = $jsClassBands;
            const n             = $jsStudentCount;

            const shortLabels = subjectLabels.map(l => l.length > 8 ? l.slice(0,4) + '.' : l);

            /* Chart colours follow the light / dark theme */
            const isDarkTheme = document.documentElement.getAttribute('data-theme') === 'dark';
            const gridCol     = isDarkTheme ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';
            const ringCol     = isDarkTheme ? '#111b30' : '#ffffff';

            Chart.defaults.font.family = \"'Plus Jakarta Sans', sans-serif\";
            Chart.defaults.color       = '#7a909e';

            const ctx1 = document.getElementById('subjectMeansChart');
            if (ctx1) {
                new Chart(ctx1, {
                    type: 'bar',
                    data: { labels: shortLabels, datasets: [{
                        label: 'Mean Score', data: subjectMeans,
                        backgroundColor: subjectMeans.map(v =>
                            v >= 75 ? 'rgba(22,163,74,0.8)' :
                            v >= 50 ? 'rgba(26,74,122,0.8)' :
                            v >= 26 ? 'rgba(217,119,6,0.8)' :
                                      'rgba(153,27,27,0.8)'),
                        borderRadius: 6, borderSkipped: false,
                    }]},
                    options: { responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { min: 0, max: 100, grid: { color: gridCol } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }

            const ctx2 = document.getElementById('classBandsChart');
            if (ctx2) {
                new Chart(ctx2, {
                    type: 'doughnut',
                    data: { labels: ['Exceeding (E.E)','Meeting (M.E)','Approaching (A.E)','Below (B.E)'],
                        datasets: [{ data: classBands,
                            backgroundColor: ['rgba(22,163,74,0.85)','rgba(26,74,122,0.85)','rgba(217,119,6,0.85)','rgba(153,27,27,0.85)'],
                            borderWidth: 2, borderColor: ringCol, hoverOffset: 8 }]},
                    options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
                        plugins: { legend: { position: 'bottom', labels: { padding: 16, boxWidth: 12 } } }
                    }
                });
            }

            const ctx3 = document.getElementById('subjectBandsChart');
            if (ctx3) {
                new Chart(ctx3, {
                    type: 'bar',
                    data: { labels: shortLabels, datasets: [
                        { label:'Exceeding (E.E)', data: bandsEE, backgroundColor:'rgba(22,163,74,0.85)' },
                        { label:'Meeting (M.E)',   data: bandsME, backgroundColor:'rgba(26,74,122,0.85)' },
                        { label:'Approaching (A.E)',data: bandsAE,backgroundColor:'rgba(217,119,6,0.85)' },
                        { label:'Below (B.E)',     data: bandsBE, backgroundColor:'rgba(153,27,27,0.85)' }
                    ]},
                    options: { responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position:'bottom', labels:{ padding:16, boxWidth:12 } } },
                        scales: {
                            x: { stacked: true, grid: { display: false } },
                            y: { stacked: true, grid: { color: gridCol }, ticks: { stepSize: 1 } }
                        }
                    }
                });
            }

            const ctx4 = document.getElementById('compareChart');
            if (ctx4 && prevMeans !== null) {
                new Chart(ctx4, {
                    type: 'line',
                    data: { labels: shortLabels, datasets: [
                        { label: 'Current Exam', data: subjectMeans,
                          borderColor: 'rgba(26,111,178,1)', backgroundColor: 'rgba(26,111,178,0.12)',
                          borderWidth: 3, pointRadius: 5, tension: 0.35, fill: true },
                        { label: 'Previous Exam', data: prevMeans,
                          borderColor: 'rgba(131,146,165,0.9)', backgroundColor: 'rgba(131,146,165,0.08)',
                          borderWidth: 2, borderDash: [6,4], pointRadius: 4, tension: 0.35, fill: true }
                    ]},
                    options: { responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position:'bottom', labels:{ padding:16, boxWidth:14 } } },
                        scales: {
                            y: { min: 0, max: 100, grid: { color: gridCol } },
                            x: { grid: { display: false } }
                        }
                    }
                });
            }

            /* ── SHARED EXPORT DATA (used by the report cards + Excel) ── */
            const exportMeta       = $jsExportMeta;
            const exportStudents   = $jsExportStudents;
            const exportHistory    = $jsExportHistory;
            const subjectFullNames = $jsSubjectLabels;
            const isLowerGrade     = exportMeta.gradeInt >= 1 && exportMeta.gradeInt <= 6;

            /* ── REPORT CARDS (PDF) — ONE PAGE PER LEARNER ── */
            /* 8-level scale: sub-band 1 is the HIGHER one (EE1 = 90-100). */
            const RC_BAND_COLORS = {
                'EE1': { bg: [187,247,208], fg: [21,128,61]  },
                'EE2': { bg: [220,252,231], fg: [22,163,74]  },
                'ME1': { bg: [207,224,243], fg: [18,54,87]   },
                'ME2': { bg: [220,232,245], fg: [26,74,122]  },
                'AE1': { bg: [253,230,138], fg: [146,64,14]  },
                'AE2': { bg: [254,243,199], fg: [180,83,9]   },
                'BE1': { bg: [254,202,202], fg: [127,29,29]  },
                'BE2': { bg: [254,226,226], fg: [153,27,27]  },
                'E.E': { bg: [220,252,231], fg: [22,163,74]  },
                'M.E': { bg: [220,232,245], fg: [26,74,122]  },
                'A.E': { bg: [254,243,199], fg: [180,83,9]   },
                'B.E': { bg: [254,226,226], fg: [153,27,27]  },
            };
            function rcBandFor8(s){ return s>=90?'EE1':s>=75?'EE2':s>=63?'ME1':s>=50?'ME2':s>=38?'AE1':s>=26?'AE2':s>=13?'BE1':'BE2'; }
            function rcBandFor4(s){ return s>=75?'E.E':s>=50?'M.E':s>=26?'A.E':'B.E'; }
            function rcBandFor(s){ return isLowerGrade ? rcBandFor4(s) : rcBandFor8(s); }

            /* Points per level (Grades 1-6: E.E 4, M.E 3, A.E 2, B.E 1; Grades 7-9: EE1 8 … BE2 1) */
            const RC_POINTS = { 'EE1':8,'EE2':7,'ME1':6,'ME2':5,'AE1':4,'AE2':3,'BE1':2,'BE2':1,
                                'E.E':4,'M.E':3,'A.E':2,'B.E':1 };
            function rcPts(score){ return RC_POINTS[rcBandFor(score)] || 0; }

            const RC_COMMENTS = {
                'EE1': 'An outstanding performance across the board. Keep up this excellent standard.',
                'EE2': 'A very strong performance this term. Well done — keep up the excellent work.',
                'ME1': 'A good, solid performance. With a bit more focus you can reach the top band.',
                'ME2': 'A fair, satisfactory performance. Continued effort will improve these results.',
                'AE1': 'Performance is approaching expectations. More consistent study time is needed.',
                'AE2': 'Performance is below the expected standard. Extra practice and support are recommended.',
                'BE1': 'Significant support is needed in most areas. Please arrange a meeting with the class teacher.',
                'BE2': 'Performance is well below expectations. Close guidance and remedial support are strongly advised.',
                'E.E': 'An excellent performance this term. Keep up the great work!',
                'M.E': 'A good, solid performance. Keep striving for even better results.',
                'A.E': 'Fair performance — more effort and practice will help improve these results.',
                'B.E': 'Performance needs significant improvement. Extra support at home and school is advised.',
            };
            function commentFor(code){ return RC_COMMENTS[code] || ''; }

            /* ── TREND GRAPHS (vector charts drawn straight into the PDF) ── */
            var TR_SHORT = {
                english: 'ENG', kiswahili: 'KIS', mathematics: 'MAT', integratedscience: 'INT/SC',
                creativeartsandsports: 'CAS', socialstudies: 'SST', christianreligiouseducation: 'C.R.E',
                islamicreligiouseducation: 'I.R.E', agriculture: 'AGRI', pretechnicalstudies: 'PRE TECH',
                pretechnical: 'PRE TECH', environmentalactivities: 'ENV', religiouseducation: 'RE',
                science: 'SCI', creativearts: 'CA'
            };
            function trShort(label) {
                var k = String(label).toLowerCase().replace(/[^a-z]/g, '');
                return TR_SHORT[k] || (String(label).length > 9 ? String(label).slice(0, 8) + '.' : String(label));
            }
            /* Splits an exam label such as 'Mid Term T2' onto two lines (name | term) for tight x-axes */
            function trTwoLine(t) {
                var s = String(t), i = s.lastIndexOf(' T');
                return i > 0 ? s.slice(0, i) + '|' + s.slice(i + 1) : s;
            }
            var TR_NAVY = [16, 41, 63];
            var TR_PREV = [[90, 135, 190], [140, 170, 200], [190, 205, 220]];   // most recent previous exam = darkest
            function trColor(i, n) {
                if (i === n - 1) { return TR_NAVY; }
                var k = n - 2 - i;
                return TR_PREV[Math.min(k, TR_PREV.length - 1)];
            }
            function trAxes(doc, x, y, w, h) {
                doc.setDrawColor(225, 229, 235); doc.setLineWidth(0.5);
                doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(122, 144, 158);
                for (var v = 0; v <= 100; v += 25) {
                    var yy = y + h - (v / 100) * h;
                    doc.line(x, yy, x + w, yy);
                    doc.text(String(v), x - 5, yy + 2.5, { align: 'right' });
                }
            }
            function trLegend(doc, x, y, items) {
                doc.setFont('helvetica', 'normal'); doc.setFontSize(7.5);
                var cx = x;
                items.forEach(function (it) {
                    doc.setFillColor(it.color[0], it.color[1], it.color[2]);
                    doc.rect(cx, y - 6, 9, 9, 'F');
                    doc.setTextColor(61, 79, 92);
                    doc.text(it.name, cx + 13, y + 1.5);
                    cx += 13 + doc.getTextWidth(it.name) + 14;
                });
            }
            function trBarChart(doc, x, y, w, h, cats, series) {
                trAxes(doc, x, y, w, h);
                var nC = cats.length, nS = series.length;
                if (!nC || !nS) { return; }
                var groupW = w / nC, pad = groupW * 0.14, barW = (groupW - 2 * pad) / nS;
                for (var c = 0; c < nC; c++) {
                    for (var s = 0; s < nS; s++) {
                        var v = series[s].values[c];
                        if (v === null || v === undefined) { continue; }
                        var bh = Math.max(0, Math.min(100, v)) / 100 * h;
                        var bx = x + c * groupW + pad + s * barW;
                        doc.setFillColor(series[s].color[0], series[s].color[1], series[s].color[2]);
                        doc.rect(bx, y + h - bh, barW * 0.92, bh, 'F');
                        if (series[s].label) {
                            doc.setFont('helvetica', 'bold'); doc.setFontSize(6.5); doc.setTextColor(15, 25, 35);
                            doc.text(String(Math.round(v)), bx + barW * 0.46, y + h - bh - 2, { align: 'center' });
                        }
                    }
                    doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(61, 79, 92);
                    doc.text(cats[c], x + c * groupW + groupW / 2, y + h + 10, { align: 'center' });
                }
                doc.setDrawColor(150, 160, 170); doc.setLineWidth(0.8); doc.line(x, y + h, x + w, y + h);
            }
            function trLineChart(doc, x, y, w, h, cats, series) {
                trAxes(doc, x, y, w, h);
                var n = cats.length;
                if (!n) { return; }
                function px(i) { return x + w * (i + 0.5) / n; }
                function py(v) { return y + h - (Math.max(0, Math.min(100, v)) / 100) * h; }
                for (var c = 0; c < n; c++) {
                    doc.setFont('helvetica', 'normal'); doc.setFontSize(7); doc.setTextColor(61, 79, 92);
                    var parts = String(cats[c]).split('|');
                    for (var q = 0; q < parts.length; q++) {
                        doc.text(parts[q], px(c), y + h + 10 + q * 8, { align: 'center' });
                    }
                }
                series.forEach(function (se) {
                    doc.setDrawColor(se.color[0], se.color[1], se.color[2]);
                    doc.setFillColor(se.color[0], se.color[1], se.color[2]);
                    doc.setLineWidth(1.6);
                    doc.setLineDashPattern(se.dashed ? [4, 3] : [], 0);
                    var prev = -1;
                    for (var i = 0; i < n; i++) {
                        var v = se.values[i];
                        if (v === null || v === undefined) { prev = -1; continue; }
                        if (prev >= 0) { doc.line(px(prev), py(se.values[prev]), px(i), py(v)); }
                        prev = i;
                    }
                    doc.setLineDashPattern([], 0);
                    for (var j = 0; j < n; j++) {
                        var vv = se.values[j];
                        if (vv === null || vv === undefined) { continue; }
                        doc.circle(px(j), py(vv), 2.6, 'F');
                        if (se.label) {
                            doc.setFont('helvetica', 'bold'); doc.setFontSize(7); doc.setTextColor(15, 25, 35);
                            doc.text(String(Math.round(vv * 10) / 10), px(j), py(vv) - 6, { align: 'center' });
                        }
                    }
                });
            }

            /* Draws BOTH graphs plus the 'how this exam compares' notes inside the report card's single page.
               y0 = top of the block; returns the y just below it. */
            function drawTrendBlock(doc, s, y0) {
                var pw = doc.internal.pageSize.getWidth();
                var L = 36, fullW = pw - 72;
                var H = exportHistory || [], n = H.length, hist = s.hist || [];
                var y = y0;

                doc.setFont('helvetica', 'bold'); doc.setFontSize(9); doc.setTextColor(61, 79, 92);
                doc.text('SUBJECT MARKS - CURRENT EXAM VS PREVIOUS EXAMS', L, y);
                if (!n) {
                    doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(122, 144, 158);
                    doc.text('No exam history is available for this learner yet.', L, y + 16);
                    return y + 34;
                }

                /* 1) Bar graph: subject marks, current exam vs previous exams */
                var first = Math.max(0, n - 4), series = [];
                for (var i = first; i < n; i++) {
                    series.push({
                        name: H[i].short + (i === n - 1 ? ' (current)' : ''),
                        color: trColor(i - first, n - first),
                        label: i === n - 1,
                        values: subjectFullNames.map(function (nm, c) {
                            var e = hist[i];
                            return (e && e.subs[c] !== null && e.subs[c] !== undefined) ? e.subs[c] : null;
                        })
                    });
                }
                trLegend(doc, L, y + 15, series);
                trBarChart(doc, L + 22, y + 26, fullW - 28, 106, subjectFullNames.map(trShort), series);
                y += 26 + 106 + 28;

                /* 2) Line graph (left) + notes (right) */
                var lw = Math.round(fullW * 0.6), rx = L + lw + 14, rw = fullW - lw - 14;
                doc.setFont('helvetica', 'bold'); doc.setFontSize(9); doc.setTextColor(61, 79, 92);
                doc.text('OVERALL AVERAGE ACROSS EXAMS (OUT OF 100)', L, y);
                var lineSeries = [
                    { name: 'This learner', color: TR_NAVY, label: true,
                      values: H.map(function (x, i) { return hist[i] ? hist[i].avg : null; }) },
                    { name: 'Class mean', color: [200, 153, 42], dashed: true,
                      values: H.map(function (x) { return x.classAvg; }) }
                ];
                trLegend(doc, L, y + 15, lineSeries);
                trLineChart(doc, L + 22, y + 28, lw - 30, 80, H.map(function (x) { return trTwoLine(x.short); }), lineSeries);

                var cur = hist[n - 1], prev = null, pi = -1;
                for (var j = n - 2; j >= 0; j--) { if (hist[j]) { prev = hist[j]; pi = j; break; } }
                var lines = [];
                if (cur) { lines.push('Current average: ' + cur.avg + '%   |   Class mean: ' + (H[n - 1].classAvg === null ? '-' : H[n - 1].classAvg + '%')); }
                if (cur && prev) {
                    var d = Math.round((cur.avg - prev.avg) * 10) / 10;
                    lines.push('Change since ' + H[pi].label + ': ' + (d > 0 ? '+' : '') + d + ' points');
                    var bi = -1, bd = 0, wi = -1, wd = 0;
                    for (var c = 0; c < subjectFullNames.length; c++) {
                        var a1 = cur.subs[c], b1 = prev.subs[c];
                        if (a1 === null || a1 === undefined || b1 === null || b1 === undefined) { continue; }
                        var df = Math.round((a1 - b1) * 10) / 10;
                        if (df > bd) { bd = df; bi = c; }
                        if (df < wd) { wd = df; wi = c; }
                    }
                    if (bi >= 0) { lines.push('Biggest improvement: ' + subjectFullNames[bi] + ' (+' + bd + ')'); }
                    if (wi >= 0) { lines.push('Needs attention: ' + subjectFullNames[wi] + ' (' + wd + ')'); }
                } else if (cur) {
                    lines.push('No earlier exam on record to compare with.');
                }
                doc.setFillColor(248, 249, 251); doc.setDrawColor(216, 222, 230); doc.setLineWidth(0.6);
                doc.roundedRect(rx, y - 12, rw, 132, 4, 4, 'FD');
                doc.setFont('helvetica', 'bold'); doc.setFontSize(8.5); doc.setTextColor(61, 79, 92);
                doc.text('HOW THIS EXAM COMPARES', rx + 9, y + 2);
                doc.setFont('helvetica', 'normal'); doc.setFontSize(8.5); doc.setTextColor(40, 55, 66);
                var ty = y + 18;
                for (var k = 0; k < lines.length; k++) {
                    var wrapped = doc.splitTextToSize(lines[k], rw - 18);
                    for (var w2 = 0; w2 < wrapped.length; w2++) {
                        if (ty > y + 112) { break; }
                        doc.text(wrapped[w2], rx + 9, ty);
                        ty += 11;
                    }
                    ty += 4;
                }
                return y + 140;
            }

            function buildReportCards() {
                const { jsPDF } = window.jspdf;
                const doc = new jsPDF({ unit: 'pt', format: 'a4' });
                const pw = doc.internal.pageSize.getWidth();
                const ph = doc.internal.pageSize.getHeight();
                const L = 36, CW = pw - 72;
                const totalStudents = exportStudents.length;
                const subjCount = exportMeta.subjectCount;
                const totalOutOf = exportMeta.totalOutOf;
                const rcGraphsBox = document.getElementById('rcGraphs');
                const rcGraphsOn  = rcGraphsBox ? rcGraphsBox.checked : true;

                const allAvgPts = exportStudents.map(st => st.subjects.reduce((a, sc) => a + rcPts(sc), 0) / subjCount);
                const classAvgPts = allAvgPts.reduce((a, b) => a + b, 0) / (allAvgPts.length || 1);
                const classAvgMarks = exportMeta.classMeanSubj;

                exportStudents.forEach((s, idx) => {
                    if (idx > 0) doc.addPage();

                    /* ── Header band ── */
                    doc.setFillColor(16, 41, 63);
                    doc.rect(0, 0, pw, 64, 'F');
                    doc.setFillColor(240, 192, 64);
                    doc.rect(0, 64, pw, 3, 'F');
                    doc.setTextColor(255, 255, 255);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(14);
                    doc.text(exportMeta.school, L, 25);
                    doc.setTextColor(240, 192, 64);
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(8.5);
                    doc.text('ACADEMIC PERFORMANCE REPORT CARD', L, 40);
                    doc.setTextColor(220, 232, 245);
                    doc.text('Grade ' + exportMeta.grade + '  |  ' + exportMeta.exam + '  |  ' + exportMeta.term + ', ' + exportMeta.year, L, 54);

                    /* ── Learner + position / total badges ── */
                    const ob = rcBandFor(s.total / subjCount);
                    const obCol = RC_BAND_COLORS[ob];
                    const stuTotalPts = s.subjects.reduce((a, sc) => a + rcPts(sc), 0);
                    const stuAvgPts   = stuTotalPts / subjCount;
                    const stuAvgMarks = s.total / subjCount;

                    let y = 82;
                    const nm = (s.firstName + ' ' + s.lastName).trim();
                    let nfs = 14;
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(nfs);
                    while (doc.getTextWidth(nm) > 235 && nfs > 9) { nfs -= 0.5; doc.setFontSize(nfs); }
                    doc.setTextColor(15, 25, 35);
                    doc.text(nm, L, y + 14);
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(9);
                    doc.setTextColor(122, 144, 158);
                    doc.text('Assessment No: ' + s.assesment, L, y + 28);

                    const bw1 = 118, bw2 = 150, bh = 26, bgap = 8;
                    const bx2 = pw - L - bw2, bx1 = bx2 - bgap - bw1;
                    doc.setFillColor(245, 233, 200);
                    doc.roundedRect(bx1, y, bw1, bh, 4, 4, 'F');
                    doc.setTextColor(122, 90, 16);
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(10);
                    doc.text('Position: ' + s.rank + ' of ' + totalStudents, bx1 + bw1 / 2, y + 17, { align: 'center' });
                    doc.setFillColor(obCol.bg[0], obCol.bg[1], obCol.bg[2]);
                    doc.roundedRect(bx2, y, bw2, bh, 4, 4, 'F');
                    doc.setTextColor(obCol.fg[0], obCol.fg[1], obCol.fg[2]);
                    doc.text('Total: ' + s.total + '/' + totalOutOf + '  (' + ob + ')', bx2 + bw2 / 2, y + 17, { align: 'center' });
                    y += 46;

                    /* ── Results as tables: subject marks (left) + summary (right) ── */
                    const lw = 316, gap = 14, rw = CW - lw - gap;
                    const tblStyles = { font: 'helvetica', fontSize: 9, cellPadding: 3.6, textColor: [40, 55, 66], lineColor: [216, 216, 216], lineWidth: 0.4 };
                    const tblHead   = { fillColor: [16, 41, 63], textColor: [255, 255, 255], fontStyle: 'bold', halign: 'center' };

                    doc.autoTable({
                        startY: y,
                        margin: { left: L, right: pw - L - lw },
                        tableWidth: lw,
                        theme: 'grid',
                        head: [['Subject', 'Score (/100)', 'Level', 'Points']],
                        body: subjectFullNames.map((name, i) => [name, String(s.subjects[i]), rcBandFor(s.subjects[i]), String(rcPts(s.subjects[i]))]),
                        styles: tblStyles,
                        headStyles: tblHead,
                        columnStyles: { 0: { halign: 'left', fontStyle: 'bold' }, 1: { halign: 'center', cellWidth: 62 }, 2: { halign: 'center', cellWidth: 48 }, 3: { halign: 'center', cellWidth: 44 } },
                        didParseCell: function (data) {
                            if (data.section === 'body' && data.column.index === 2) {
                                const c = RC_BAND_COLORS[data.cell.raw];
                                if (c) {
                                    data.cell.styles.fillColor = c.bg;
                                    data.cell.styles.textColor = c.fg;
                                    data.cell.styles.fontStyle = 'bold';
                                }
                            }
                        }
                    });
                    const marksBottom = doc.lastAutoTable.finalY;

                    const sumRows = [
                        ['Total score', s.total + ' / ' + totalOutOf],
                        ['Average marks', stuAvgMarks.toFixed(1)],
                        ['Class mean (avg)', String(classAvgMarks)],
                        ['Total points', String(stuTotalPts)],
                        ['Average points', stuAvgPts.toFixed(2)],
                        ['Class avg points', classAvgPts.toFixed(2)],
                        ['Class mean total', exportMeta.classMeanTotal + ' / ' + totalOutOf],
                        ['Position', s.rank + ' of ' + totalStudents]
                    ];
                    doc.autoTable({
                        startY: y,
                        margin: { left: L + lw + gap, right: L },
                        tableWidth: rw,
                        theme: 'grid',
                        head: [[{ content: 'Summary', colSpan: 2, styles: { halign: 'left' } }]],
                        body: sumRows,
                        styles: tblStyles,
                        headStyles: tblHead,
                        columnStyles: { 0: { fontStyle: 'bold', textColor: [61, 79, 92] }, 1: { halign: 'center', fontStyle: 'bold', textColor: [15, 25, 35], cellWidth: 72 } },
                        didParseCell: function (data) {
                            if (data.section === 'body' && data.row.index === sumRows.length - 1) {
                                data.cell.styles.fillColor = [245, 233, 200];
                                data.cell.styles.textColor = [122, 90, 16];
                                data.cell.styles.fontStyle = 'bold';
                            }
                        }
                    });
                    y = Math.max(marksBottom, doc.lastAutoTable.finalY) + 18;

                    /* ── Trend graphs (same page) ── */
                    if (rcGraphsOn) { y = drawTrendBlock(doc, s, y); }

                    /* ── Teacher's comment ── */
                    const boxH = 50;
                    doc.setFillColor(obCol.bg[0], obCol.bg[1], obCol.bg[2]);
                    doc.roundedRect(L, y, CW, boxH, 5, 5, 'F');
                    doc.setDrawColor(obCol.fg[0], obCol.fg[1], obCol.fg[2]);
                    doc.setLineWidth(0.75);
                    doc.roundedRect(L, y, CW, boxH, 5, 5, 'S');
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(9);
                    doc.setTextColor(obCol.fg[0], obCol.fg[1], obCol.fg[2]);
                    doc.text('TEACHER\\'S COMMENT', L + 12, y + 16);
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(9.5);
                    doc.setTextColor(61, 79, 92);
                    doc.text(commentFor(ob), L + 12, y + 32, { maxWidth: CW - 24 });
                    y += boxH + 14;

                    /* ── Key / legend ── */
                    doc.setFont('helvetica', 'italic');
                    doc.setFontSize(7.5);
                    doc.setTextColor(122, 144, 158);
                    const legendText = isLowerGrade
                        ? 'Key: E.E Exceeding Expectation (75-100) = 4 pts | M.E Meeting Expectation (50-74) = 3 pts | A.E Approaching Expectation (26-49) = 2 pts | B.E Below Expectation (0-25) = 1 pt'
                        : 'Key: EE1 90-100 | EE2 75-89 | ME1 63-74 | ME2 50-62 | AE1 38-49 | AE2 26-37 | BE1 13-25 | BE2 0-12  (points 8 down to 1)';
                    doc.text(legendText, L, y, { maxWidth: CW });

                    /* ── Signatures + footer pinned to the bottom ── */
                    const sigY = ph - 64;
                    doc.setDrawColor(200, 200, 200);
                    doc.setLineWidth(0.75);
                    doc.line(L, sigY, 236, sigY);
                    doc.line(pw - 236, sigY, pw - L, sigY);
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(9);
                    doc.setTextColor(61, 79, 92);
                    doc.text('Class Teacher', L, sigY + 14);
                    doc.text('Head of Institution', pw - 236, sigY + 14);
                    doc.setFontSize(8);
                    doc.setTextColor(122, 144, 158);
                    doc.text('Learner ' + (idx + 1) + ' of ' + totalStudents, pw / 2, ph - 20, { align: 'center' });
                });

                doc.save('Grade' + exportMeta.grade + '_' + exportMeta.exam.replace(/\\s+/g, '') + '_' + exportMeta.term.replace(/\\s+/g, '') + '_' + exportMeta.year + '_ReportCards.pdf');
            }

            const reportCardsBtn = document.getElementById('downloadReportCardsBtn');
            if (reportCardsBtn) {
                reportCardsBtn.addEventListener('click', function () {
                    const originalHtml = reportCardsBtn.innerHTML;
                    reportCardsBtn.disabled = true;
                    reportCardsBtn.innerHTML = 'Preparing…';
                    try { buildReportCards(); }
                    catch (err) { console.error('Report card generation failed:', err); alert('Sorry, the report cards could not be generated.'); }
                    finally { reportCardsBtn.disabled = false; reportCardsBtn.innerHTML = originalHtml; }
                });
            }

            /* ── MARKS-ONLY EXCEL EXPORT (no levels / points) ── */
            function buildMarksExcel() {
                const subjCount = exportMeta.subjectCount;
                const r1 = function (v) { return Math.round(v * 10) / 10; };

                const aoa = [];
                aoa.push([exportMeta.school]);
                aoa.push(['Grade ' + exportMeta.grade + '  |  ' + exportMeta.exam + '  |  ' + exportMeta.term + ', ' + exportMeta.year]);
                aoa.push([]);

                const header = ['Rank', 'Assess. No', 'First Name', 'Surname']
                    .concat(subjectFullNames)
                    .concat(['Total (/' + exportMeta.totalOutOf + ')', 'Mean Marks']);
                aoa.push(header);

                const subjectSums = subjectFullNames.map(function () { return 0; });

                exportStudents.forEach(function (s) {
                    const row = [s.rank, s.assesment, s.firstName, s.lastName];
                    s.subjects.forEach(function (sc, i) {
                        row.push(sc);
                        subjectSums[i] += sc;
                    });
                    row.push(s.total);
                    row.push(subjCount ? r1(s.total / subjCount) : 0);
                    aoa.push(row);
                });

                const count = exportStudents.length || 1;
                const meanRow = ['', '', '', 'Class Mean'];
                subjectSums.forEach(function (sum) { meanRow.push(r1(sum / count)); });
                meanRow.push(exportMeta.classMeanTotal);
                meanRow.push(exportMeta.classMeanSubj);
                aoa.push(meanRow);

                const ws = XLSX.utils.aoa_to_sheet(aoa);
                const cols = [{ wch: 7 }, { wch: 13 }, { wch: 16 }, { wch: 16 }];
                subjectFullNames.forEach(function (name) { cols.push({ wch: Math.max(10, String(name).length + 2) }); });
                cols.push({ wch: 14 });
                cols.push({ wch: 12 });
                ws['!cols'] = cols;

                const wb = XLSX.utils.book_new();
                XLSX.utils.book_append_sheet(wb, ws, 'Marks');
                XLSX.writeFile(wb, 'Grade' + exportMeta.grade + '_' + exportMeta.exam.replace(/\\s+/g, '') + '_' + exportMeta.term.replace(/\\s+/g, '') + '_' + exportMeta.year + '_Marks.xlsx');
            }

            const marksExcelBtn = document.getElementById('downloadMarksExcelBtn');
            if (marksExcelBtn) {
                marksExcelBtn.addEventListener('click', function () {
                    const originalHtml = marksExcelBtn.innerHTML;
                    marksExcelBtn.disabled = true;
                    marksExcelBtn.innerHTML = 'Preparing…';
                    try { buildMarksExcel(); }
                    catch (err) { console.error('Excel export failed:', err); alert('Sorry, the Excel file could not be generated.'); }
                    finally { marksExcelBtn.disabled = false; marksExcelBtn.innerHTML = originalHtml; }
                });
            }
        })();
        </script>
        ";

    } else {
        echo "<p class='no-results'>No results found for the selected filters.</p>";
    }

} else {
    echo "<p class='no-results'>Select a grade, term, exam type, and year above to view results.</p>";
}
?>

        </main><!-- .results -->

        <div class="footer">&copy; Designed by <strong>Kelvin Mutinda</strong> 2026. All rights reserved.</div>
    </div><!-- .main -->
</div><!-- .app -->

<script>
/* Sidebar toggle (mobile) */
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('backdrop').classList.toggle('show');
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.getElementById('sidebar').classList.remove('open');
        document.getElementById('backdrop').classList.remove('show');
    }
});

/* Theme (same key as the other dashboards) */
function syncThemeIcon() {
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    document.getElementById('themeBtn').innerHTML = dark ? '<i class="fa-solid fa-sun"></i>' : '<i class="fa-solid fa-moon"></i>';
}
function toggleTheme() {
    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('hoi-theme', next); } catch (e) {}
    syncThemeIcon();
    /* charts pick their colours when the page loads, so refresh once to repaint them */
    if (document.getElementById('subjectMeansChart')) { window.location.reload(); }
}
syncThemeIcon();

/* Filters apply as soon as all four are chosen */
(function () {
    var form = document.getElementById('filterForm');
    if (!form) { return; }
    var selects = [].slice.call(form.querySelectorAll('select'));
    selects.forEach(function (s) {
        s.addEventListener('change', function () {
            if (selects.every(function (x) { return x.value !== ''; })) { form.submit(); }
        });
    });
})();
</script>

</body>
</html>
