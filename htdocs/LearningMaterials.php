<?php
/* ════════════════════════════════════════════════════════════════════
   LearningMaterials.php — upload, browse, download and manage teaching resources.
   All PHP (database, upload, download, delete) runs BEFORE any HTML is sent,
   so the file download can send its headers properly.
   ════════════════════════════════════════════════════════════════════ */
include 'conn.php';
 require_once __DIR__ . '/page_guard.php';
/* Only signed-in users may use this page. Deleting a material is limited to
   administrators; any signed-in staff can upload and download. */
require_once 'auth_helpers.php';
requireLogin();
$isAdminUser = function_exists('isAdmin') ? (bool)isAdmin() : false;
$canDelete   = $isAdminUser;   // ← change this one line if other roles (e.g. teachers) should also be able to delete materials

if (session_status() === PHP_SESSION_NONE) { @session_start(); }
if (empty($_SESSION['lm_csrf'])) { $_SESSION['lm_csrf'] = bin2hex(random_bytes(16)); }
$csrf = (string)$_SESSION['lm_csrf'];

$isHoi     = (($_SESSION['account_type'] ?? '') === 'hoi');
$homeLink  = $isHoi ? 'Hoi.php' : 'teacherpanel.php';
$roleLabel = $isHoi ? 'Head of Institution' : ($isAdminUser ? 'Administrator' : 'Teacher Panel');
$self      = basename($_SERVER['PHP_SELF']);   // so links keep working whatever this file is named

/* ── Table ── */
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS learning_materials (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(255)   NOT NULL,
    description   TEXT,
    subject       VARCHAR(100)   NOT NULL,
    grade         VARCHAR(20)    NOT NULL,
    term          VARCHAR(20)    NOT NULL,
    material_type VARCHAR(50)    NOT NULL,
    file_name     VARCHAR(255)   NOT NULL,
    file_original VARCHAR(255)   NOT NULL,
    file_size     INT            DEFAULT 0,
    file_ext      VARCHAR(20),
    uploaded_by   VARCHAR(150)   NOT NULL,
    downloads     INT            DEFAULT 0,
    uploaded_at   DATETIME       DEFAULT CURRENT_TIMESTAMP,
    tags          VARCHAR(255)
)
");

/* ── Upload directory ── */
$uploadDir = 'uploads/materials/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

/* ── Lists used by both the upload form and the filters ── */
$SUBJECTS = ['Mathematics','English','Kiswahili','Science & Technology','Social Studies','Creative Arts','Agriculture','Religious Education','Pre-Technical Studies','Physical Education','Life Skills','General'];
$TYPES    = ['Notes','Past Paper','Revision Exercise','Scheme of Work','Lesson Plan','Textbook Chapter','Assignment','Reference Material','Video Lesson','Other'];

/* ── Messages ── */
$flash = '';
$flashType = '';
$okMessages = ['uploaded' => 'Material uploaded successfully!', 'deleted' => 'Material deleted successfully.'];
if (isset($_GET['ok']) && isset($okMessages[$_GET['ok']])) { $flash = $okMessages[$_GET['ok']]; $flashType = 'success'; }
$old = [];   // keeps what was typed if an upload fails

/* ══════════════════════════════
   DOWNLOAD (must run before any output)
══════════════════════════════ */
if (isset($_GET['dl']) && ctype_digit((string)$_GET['dl'])) {
    $dlId = (int)$_GET['dl'];
    $dlRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT file_name, file_original FROM learning_materials WHERE id=$dlId"));
    $dlPath = $dlRow ? $uploadDir . basename($dlRow['file_name']) : '';
    if ($dlRow && is_file($dlPath)) {
        mysqli_query($conn, "UPDATE learning_materials SET downloads=downloads+1 WHERE id=$dlId");
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $dlRow['file_original']) . '"');
        header('Content-Length: ' . filesize($dlPath));
        header('X-Content-Type-Options: nosniff');
        readfile($dlPath);
        exit;
    }
    $flash = 'That file could not be found on the server.';
    $flashType = 'error';
}

/* ══════════════════════════════
   DELETE (POST + CSRF, administrators only)
══════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $delId = ctype_digit((string)($_POST['id'] ?? '')) ? (int)$_POST['id'] : 0;
    if (!$canDelete) {
        $flash = 'Only an administrator can delete materials.'; $flashType = 'error';
    } elseif (!hash_equals($csrf, (string)($_POST['csrf'] ?? '')) || $delId === 0) {
        $flash = 'Your session expired. Please reload the page and try again.'; $flashType = 'error';
    } else {
        $fr = mysqli_fetch_assoc(mysqli_query($conn, "SELECT file_name FROM learning_materials WHERE id=$delId"));
        if ($fr) {
            $fpath = $uploadDir . basename($fr['file_name']);
            if (is_file($fpath)) @unlink($fpath);
            mysqli_query($conn, "DELETE FROM learning_materials WHERE id=$delId");
        }
        header('Location: ' . $self . '?ok=deleted');
        exit;
    }
}

/* ══════════════════════════════
   UPLOAD
══════════════════════════════ */
$postTooBig = ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0);
if ($postTooBig) {
    $flash = 'That upload is larger than the server allows. Try a smaller file (max 50 MB).';
    $flashType = 'error';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['material_file'])) {
    $title       = trim($_POST['title']       ?? '');
    $description = trim($_POST['description'] ?? '');
    $subject     = trim($_POST['subject']     ?? '');
    $grade       = trim($_POST['grade']       ?? '');
    $term        = trim($_POST['term']        ?? '');
    $matType     = trim($_POST['material_type'] ?? '');
    $uploadedBy  = trim($_POST['uploaded_by'] ?? '');
    $tags        = trim($_POST['tags']        ?? '');
    $file        = $_FILES['material_file'];
    $old = compact('title', 'description', 'subject', 'grade', 'term', 'uploadedBy', 'tags') + ['material_type' => $matType];

    $allowedExt = ['pdf','doc','docx','ppt','pptx','xls','xlsx','jpg','jpeg','png','gif','mp4','mkv','avi','mov','txt','zip','rar'];
    $maxSize    = 50 * 1024 * 1024; // 50 MB
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'The file is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE  => 'The file is too large.',
        UPLOAD_ERR_PARTIAL    => 'The file was only partly uploaded. Please try again.',
        UPLOAD_ERR_NO_FILE    => 'Please choose a file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the file.',
    ];

    $errors = [];
    if (empty($title))      $errors[] = 'Title is required.';
    if (empty($subject))    $errors[] = 'Subject is required.';
    if (empty($grade))      $errors[] = 'Grade is required.';
    if (empty($term))       $errors[] = 'Term is required.';
    if (empty($uploadedBy)) $errors[] = 'Uploaded By is required.';
    if ($file['error'] !== UPLOAD_ERR_OK) $errors[] = $uploadErrors[$file['error']] ?? 'File upload error. Please try again.';
    if (empty($errors)) {
        $origName = $file['name'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) $errors[] = "File type .$ext is not allowed.";
        if ($file['size'] > $maxSize)           $errors[] = 'File exceeds 50 MB limit.';
        if (empty($errors)) {
            $safeName = uniqid('mat_', true) . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
                $title       = mysqli_real_escape_string($conn, $title);
                $description = mysqli_real_escape_string($conn, $description);
                $subject     = mysqli_real_escape_string($conn, $subject);
                $grade       = mysqli_real_escape_string($conn, $grade);
                $term        = mysqli_real_escape_string($conn, $term);
                $matType     = mysqli_real_escape_string($conn, $matType);
                $uploadedBy  = mysqli_real_escape_string($conn, $uploadedBy);
                $tags        = mysqli_real_escape_string($conn, $tags);
                $origName_db = mysqli_real_escape_string($conn, $origName);
                $size        = (int)$file['size'];
                $ok = mysqli_query($conn, "INSERT INTO learning_materials
                    (title,description,subject,grade,term,material_type,file_name,file_original,file_size,file_ext,uploaded_by,tags)
                    VALUES
                    ('$title','$description','$subject','$grade','$term','$matType','$safeName','$origName_db',$size,'$ext','$uploadedBy','$tags')");
                if ($ok) {
                    header('Location: ' . $self . '?ok=uploaded');   // avoids a duplicate upload on refresh
                    exit;
                }
                @unlink($uploadDir . $safeName);
                $errors[] = 'The file was saved but the database could not record it.';
            } else {
                $errors[] = 'Could not save file. Check server permissions.';
            }
        }
    }
    if (!empty($errors)) {
        $flash     = implode(' ', $errors);
        $flashType = 'error';
    }
}

/* ══════════════════════════════
   FETCH / FILTER
══════════════════════════════ */
$fGrade   = trim($_GET['fgrade']   ?? '');
$fSubject = trim($_GET['fsubject'] ?? '');
$fTerm    = trim($_GET['fterm']    ?? '');
$fType    = trim($_GET['ftype']    ?? '');
$fSearch  = trim($_GET['search']   ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 12;
$offset   = ($page - 1) * $perPage;

$where = ['1=1'];
if ($fGrade)   $where[] = "grade = '".mysqli_real_escape_string($conn,$fGrade)."'";
if ($fSubject) $where[] = "subject LIKE '%".mysqli_real_escape_string($conn,$fSubject)."%'";
if ($fTerm)    $where[] = "term = '".mysqli_real_escape_string($conn,$fTerm)."'";
if ($fType)    $where[] = "material_type = '".mysqli_real_escape_string($conn,$fType)."'";
if ($fSearch)  $where[] = "(title LIKE '%".mysqli_real_escape_string($conn,$fSearch)."%' OR description LIKE '%".mysqli_real_escape_string($conn,$fSearch)."%' OR uploaded_by LIKE '%".mysqli_real_escape_string($conn,$fSearch)."%' OR tags LIKE '%".mysqli_real_escape_string($conn,$fSearch)."%')";
$whereStr = implode(' AND ', $where);
$hasFilters = ($fGrade || $fSubject || $fTerm || $fType || $fSearch);

$totalRes   = mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM learning_materials WHERE $whereStr"));
$totalMats  = (int)$totalRes['c'];
$totalPages = (int)ceil($totalMats / $perPage);

$matsRes = mysqli_query($conn,"SELECT * FROM learning_materials WHERE $whereStr ORDER BY uploaded_at DESC LIMIT $perPage OFFSET $offset");

/* Stats */
$allTotal     = (int)(mysqli_fetch_assoc(mysqli_query($conn,"SELECT COUNT(*) AS c FROM learning_materials"))['c']);
$allDownloads = (int)(mysqli_fetch_assoc(mysqli_query($conn,"SELECT SUM(downloads) AS c FROM learning_materials"))['c'] ?? 0);
$recentRes    = mysqli_query($conn,"SELECT * FROM learning_materials ORDER BY uploaded_at DESC LIMIT 6");

/* Subject counts */
$subjectCountsRes = mysqli_query($conn,"SELECT subject, COUNT(*) AS cnt FROM learning_materials GROUP BY subject ORDER BY cnt DESC LIMIT 10");
$subjectCounts = [];
while ($sc = mysqli_fetch_assoc($subjectCountsRes)) $subjectCounts[] = $sc;

/* Helpers */
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fileIcon($ext) {
    $ext = strtolower((string)$ext);
    if ($ext === 'pdf')                              return ['fa-file-pdf','pdf'];
    if (in_array($ext,['doc','docx','txt']))         return ['fa-file-word','doc'];
    if (in_array($ext,['xls','xlsx']))               return ['fa-file-excel','xls'];
    if (in_array($ext,['ppt','pptx']))               return ['fa-file-powerpoint','ppt'];
    if (in_array($ext,['jpg','jpeg','png','gif']))   return ['fa-file-image','image'];
    if (in_array($ext,['mp4','avi','mkv','mov']))    return ['fa-file-video','video'];
    if (in_array($ext,['zip','rar']))                return ['fa-file-zipper','zip'];
    return ['fa-file','other'];
}
function formatBytes($b) {
    if ($b >= 1048576) return round($b/1048576,1).' MB';
    if ($b >= 1024) return round($b/1024,1).' KB';
    return $b.' B';
}
function timeAgo($dt) {
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'just now';
    if ($diff < 3600) return round($diff/60).'m ago';
    if ($diff < 86400) return round($diff/3600).'h ago';
    if ($diff < 604800) return round($diff/86400).'d ago';
    return date('d M Y', strtotime($dt));
}
function selOpt($old, $key, $val) { return (($old[$key] ?? '') === $val) ? 'selected' : ''; }

/* Open the upload panel when there is nothing in the library yet, or an upload failed */
$openUpload = ($allTotal === 0) || ($flashType === 'error' && !empty($old));
$qsFilters  = http_build_query(array_filter(['search'=>$fSearch,'fgrade'=>$fGrade,'fsubject'=>$fSubject,'fterm'=>$fTerm,'ftype'=>$fType]));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Learning Materials — Stephen Kanja School</title>
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
      --green:#16a34a; --green-bg:#dcfce7; --red:#dc2626; --red-bg:#fee2e2; --red-bd:#fca5a5;
      --pdf:#dc2626; --pdf-bg:#fee2e2; --doc:#2563eb; --doc-bg:#dbeafe; --xls:#16a34a; --xls-bg:#dcfce7;
      --ppt:#d97706; --ppt-bg:#fef3c7; --image:#0d9488; --image-bg:#ccfbf1; --video:#7c3aed; --video-bg:#ede9fe;
      --zip:#b45309; --zip-bg:#ffedd5; --other:#64748b; --other-bg:#eef1f7;
      --shadow-sm:0 1px 2px rgba(15,23,42,.05); --shadow:0 6px 24px rgba(15,23,42,.07); --shadow-lg:0 22px 56px rgba(15,23,42,.18);
      --r:14px; --r-lg:18px; --sidebar-w:264px; --topbar-h:64px;
      color-scheme: light;
    }
    [data-theme="dark"] {
      --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
      --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3; --border:#1f2d47; --border-2:#192740;
      --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040;
      --green:#4ade80; --green-bg:rgba(34,197,94,.16); --red:#f87171; --red-bg:rgba(248,113,113,.14); --red-bd:rgba(248,113,113,.4);
      --pdf:#f87171; --pdf-bg:rgba(248,113,113,.16); --doc:#7db3f0; --doc-bg:rgba(96,165,250,.16); --xls:#4ade80; --xls-bg:rgba(34,197,94,.16);
      --ppt:#fbbf24; --ppt-bg:rgba(251,191,36,.16); --image:#2dd4bf; --image-bg:rgba(45,212,191,.16); --video:#a78bfa; --video-bg:rgba(167,139,250,.18);
      --zip:#fb923c; --zip-bg:rgba(251,146,60,.16); --other:#94a3b8; --other-bg:rgba(148,163,184,.16);
      --shadow-sm:0 1px 2px rgba(0,0,0,.4); --shadow:0 6px 24px rgba(0,0,0,.35); --shadow-lg:0 22px 56px rgba(0,0,0,.55);
      color-scheme: dark;
    }

    html { scroll-behavior:smooth; }
    body { font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14.5px; line-height:1.6; -webkit-font-smoothing:antialiased; transition:background .25s,color .25s; }
    ::-webkit-scrollbar { width:8px; height:8px; }
    ::-webkit-scrollbar-thumb { background:rgba(120,135,160,.35); border-radius:99px; }
    :focus-visible { outline:2px solid var(--gold); outline-offset:2px; border-radius:6px; }
    .skip { position:absolute; left:-999px; top:8px; background:var(--gold); color:#000; padding:8px 14px; border-radius:8px; font-weight:700; z-index:700; }
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
    .burger { display:none; background:none; border:1px solid var(--border); width:38px; height:38px; border-radius:10px; cursor:pointer; color:var(--text); align-items:center; justify-content:center; }
    .burger:hover { background:var(--surface-3); }
    .tb-title { font-weight:800; font-size:1.02rem; letter-spacing:-.2px; line-height:1.2; }
    .tb-title small { display:block; font-weight:500; font-size:.72rem; color:var(--muted); }
    .tb-right { display:flex; align-items:center; gap:8px; }
    .icon-btn { width:38px; height:38px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:15px; transition:.15s; text-decoration:none; }
    .icon-btn:hover { background:var(--surface-3); color:var(--text); }

    .content { padding:24px 32px 60px; width:100%; max-width:1480px; margin:0 auto; display:flex; flex-direction:column; gap:20px; }

    /* ───────── Hero ───────── */
    .hero { padding:28px 30px; border-radius:var(--r-lg); color:#fff; position:relative; overflow:hidden; background:linear-gradient(120deg,#0b1f3a 0%,#173a66 60%,#1c4a80 100%); box-shadow:var(--shadow); animation:fadeUp .45s ease both; }
    .hero::before { content:''; position:absolute; inset:0; background:radial-gradient(520px 260px at 6% 0%,rgba(240,192,64,.28),transparent 70%), radial-gradient(420px 260px at 100% 100%,rgba(34,197,94,.22),transparent 70%); pointer-events:none; }
    .hero-inner { position:relative; display:flex; align-items:center; justify-content:space-between; gap:22px; flex-wrap:wrap; }
    .hero-eyebrow { font-size:.72rem; letter-spacing:2px; text-transform:uppercase; color:rgba(255,255,255,.6); font-weight:700; }
    .hero h1 { font-size:clamp(1.35rem,2.6vw,2rem); font-weight:800; letter-spacing:-.5px; line-height:1.2; margin-top:4px; }
    .hero h1 em { font-style:normal; color:var(--gold); }
    .hero p { color:rgba(255,255,255,.72); font-size:.88rem; margin-top:6px; max-width:520px; }
    .hero-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:18px; }
    .hero-btn { display:inline-flex; align-items:center; gap:8px; height:40px; padding:0 18px; border-radius:11px; font-weight:700; font-size:.82rem; text-decoration:none; border:none; cursor:pointer; font-family:inherit; transition:.15s; }
    .hero-btn.primary { background:var(--gold); color:#101826; box-shadow:0 8px 20px rgba(240,192,64,.32); }
    .hero-btn.primary:hover { background:#f5cf5e; transform:translateY(-1px); }
    .hero-btn.ghost { background:rgba(255,255,255,.1); color:#fff; border:1px solid rgba(255,255,255,.2); }
    .hero-btn.ghost:hover { background:rgba(255,255,255,.18); }
    .hero-meta { display:flex; gap:10px; flex-wrap:wrap; }
    .hero-pill { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.16); backdrop-filter:blur(4px); border-radius:14px; padding:12px 18px; min-width:118px; }
    .hero-pill small { display:block; font-size:.62rem; letter-spacing:1.3px; text-transform:uppercase; color:rgba(255,255,255,.62); font-weight:700; }
    .hero-pill b { display:block; font-size:1.6rem; font-weight:800; line-height:1.2; font-variant-numeric:tabular-nums; }

    /* ───────── Flash ───────── */
    .flash { display:flex; align-items:flex-start; gap:12px; border-radius:var(--r); padding:13px 16px; font-size:.86rem; border:1px solid; animation:fadeUp .3s ease both; }
    .flash i.lead { margin-top:3px; }
    .flash span { flex:1; }
    .flash .x { background:none; border:none; color:inherit; opacity:.6; cursor:pointer; font-size:14px; padding:2px 4px; }
    .flash .x:hover { opacity:1; }
    .flash.success { background:var(--green-bg); border-color:color-mix(in srgb,var(--green) 40%,transparent); color:var(--green); }
    .flash.error { background:var(--red-bg); border-color:var(--red-bd); color:var(--red); }

    /* ───────── Cards ───────── */
    .card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--shadow-sm); overflow:hidden; animation:fadeUp .45s .05s ease both; }
    .card-head { padding:16px 24px; border-bottom:1px solid var(--border-2); display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .card-head h3 { font-size:.78rem; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:var(--text-2); display:flex; align-items:center; gap:9px; }
    .card-head h3 i { color:var(--gold-d); }
    .card-body { padding:22px 24px; }
    .section-label { font-size:.7rem; font-weight:800; letter-spacing:1.6px; text-transform:uppercase; color:var(--muted); display:flex; align-items:center; gap:12px; }
    .section-label::after { content:''; flex:1; height:1px; background:var(--border); }
    .badge { font-size:.66rem; font-weight:800; letter-spacing:.9px; text-transform:uppercase; padding:4px 12px; border-radius:99px; background:var(--gold-pale); color:var(--gold-ink); white-space:nowrap; }

    /* ───────── Upload panel ───────── */
    .upload-panel { display:none; }
    .upload-panel.open { display:block; }
    .dropzone { border:2px dashed var(--border); border-radius:var(--r); padding:34px 20px; text-align:center; cursor:pointer; transition:.2s; background:var(--surface-2); position:relative; }
    .dropzone:hover, .dropzone.dragover { border-color:var(--gold); background:var(--gold-pale); }
    .dropzone input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }
    .dz-icon { width:58px; height:58px; background:var(--navy); border-radius:16px; display:flex; align-items:center; justify-content:center; margin:0 auto 12px; box-shadow:0 10px 24px rgba(11,31,58,.25); }
    [data-theme="dark"] .dz-icon { background:var(--surface-3); }
    .dz-icon i { color:var(--gold); font-size:22px; }
    .dz-title { font-weight:800; font-size:1rem; }
    .dz-sub { font-size:.8rem; color:var(--muted); margin-top:2px; }
    .dz-sub strong { color:var(--gold-d); }
    .dz-selected { display:none; align-items:center; gap:10px; background:var(--gold-pale); border:1px solid rgba(240,192,64,.5); border-radius:12px; padding:11px 15px; font-size:.84rem; font-weight:600; margin-top:12px; }
    .dz-selected.show { display:flex; }
    .dz-selected i { color:var(--gold-d); }
    .form-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-top:20px; }
    .form-group { display:flex; flex-direction:column; gap:6px; min-width:0; }
    .span2 { grid-column:span 2; } .span3 { grid-column:span 3; }
    .form-group label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }
    .form-group label .req { color:var(--red); }
    .form-group label small { font-weight:500; text-transform:none; letter-spacing:0; }
    .form-control { padding:0 14px; height:42px; background:var(--surface-2); border:1.5px solid var(--border); border-radius:10px; font-family:inherit; font-size:.9rem; font-weight:500; color:var(--text); outline:none; transition:.15s; width:100%; }
    select.form-control { appearance:none; -webkit-appearance:none; padding-right:38px; cursor:pointer; background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%238392a5' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; }
    textarea.form-control { height:auto; padding:11px 14px; resize:vertical; min-height:90px; line-height:1.6; }
    .form-control:hover { border-color:rgba(37,99,235,.35); }
    .form-control:focus { border-color:var(--gold); box-shadow:0 0 0 3px rgba(240,192,64,.22); background:var(--surface); }
    .form-actions { display:flex; align-items:center; gap:12px; margin-top:20px; padding-top:20px; border-top:1px solid var(--border-2); flex-wrap:wrap; }
    .btn { background:var(--navy); color:#fff; border:none; border-radius:10px; padding:0 22px; height:42px; font-family:inherit; font-size:.84rem; font-weight:700; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; white-space:nowrap; }
    [data-theme="dark"] .btn { background:var(--gold); color:#101826; }
    .btn:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 8px 20px rgba(11,31,58,.25); }
    .btn:disabled { opacity:.6; cursor:not-allowed; }
    .btn.gold { background:var(--gold); color:#101826; box-shadow:0 8px 20px rgba(240,192,64,.28); }
    .btn.ghost { background:transparent; color:var(--text-2); border:1.5px solid var(--border); }
    [data-theme="dark"] .btn.ghost { background:transparent; color:var(--text-2); }
    .btn.ghost:hover:not(:disabled) { background:var(--surface-3); box-shadow:none; }
    .btn.danger { background:var(--red); color:#fff; }
    .upload-hint { margin-left:auto; font-size:.76rem; color:var(--muted); display:flex; align-items:center; gap:7px; }
    .upload-hint i { color:var(--gold-d); }

    /* ───────── Recent strip + subjects ───────── */
    .recent-strip { display:flex; gap:12px; overflow-x:auto; padding-bottom:6px; }
    .recent-item { background:var(--surface); border:1px solid var(--border); border-radius:var(--r); padding:13px 16px; min-width:250px; flex-shrink:0; display:flex; align-items:center; gap:12px; transition:.2s; box-shadow:var(--shadow-sm); }
    .recent-item:hover { box-shadow:var(--shadow); transform:translateY(-2px); }
    .recent-name { font-size:.84rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:170px; }
    .recent-meta { font-size:.7rem; color:var(--muted); margin-top:1px; }
    .subject-stats { display:flex; flex-wrap:wrap; gap:10px; }
    .subj-chip { background:var(--surface); border:1.5px solid var(--border); border-radius:99px; padding:7px 8px 7px 16px; display:inline-flex; align-items:center; gap:10px; text-decoration:none; color:var(--text); font-size:.8rem; font-weight:700; transition:.15s; box-shadow:var(--shadow-sm); }
    .subj-chip:hover { border-color:var(--gold); transform:translateY(-1px); }
    .subj-chip.on { background:var(--navy); border-color:var(--navy); color:#fff; }
    [data-theme="dark"] .subj-chip.on { background:var(--gold); border-color:var(--gold); color:#101826; }
    .subj-chip b { background:var(--gold-pale); color:var(--gold-ink); font-size:.7rem; min-width:24px; height:24px; border-radius:99px; display:inline-flex; align-items:center; justify-content:center; padding:0 7px; }

    /* ───────── Filters ───────── */
    .filter-bar { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:16px 20px; display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; box-shadow:var(--shadow-sm); }
    .filter-group { display:flex; flex-direction:column; gap:6px; min-width:140px; flex:1; }
    .filter-group.grow { flex:2; min-width:220px; }
    .filter-group label { font-size:.64rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); }

    /* ───────── Material cards ───────── */
    .materials-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:16px; }
    .material-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; transition:transform .2s,box-shadow .2s,border-color .2s; animation:fadeUp .45s ease both; display:flex; flex-direction:column; box-shadow:var(--shadow-sm); --c:var(--other); --c-bg:var(--other-bg); }
    .material-card:hover { transform:translateY(-4px); box-shadow:var(--shadow-lg); border-color:color-mix(in srgb,var(--c) 45%,transparent); }
    .material-card::before { content:''; height:4px; background:var(--c); display:block; }
    .t-pdf{--c:var(--pdf);--c-bg:var(--pdf-bg)} .t-doc{--c:var(--doc);--c-bg:var(--doc-bg)} .t-xls{--c:var(--xls);--c-bg:var(--xls-bg)} .t-ppt{--c:var(--ppt);--c-bg:var(--ppt-bg)}
    .t-image{--c:var(--image);--c-bg:var(--image-bg)} .t-video{--c:var(--video);--c-bg:var(--video-bg)} .t-zip{--c:var(--zip);--c-bg:var(--zip-bg)} .t-other{--c:var(--other);--c-bg:var(--other-bg)}
    .file-icon-wrap { width:46px; height:46px; border-radius:12px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:19px; background:var(--c-bg); color:var(--c); }
    .card-top { padding:18px 20px 12px; display:flex; align-items:flex-start; gap:14px; flex:1; }
    .card-meta { flex:1; min-width:0; }
    .card-title { font-size:.95rem; font-weight:800; letter-spacing:-.2px; line-height:1.35; margin-bottom:5px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .card-desc { font-size:.8rem; color:var(--text-2); line-height:1.5; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; margin-bottom:10px; }
    .card-tags { display:flex; flex-wrap:wrap; gap:5px; }
    .tag { display:inline-block; font-size:.66rem; font-weight:800; padding:3px 9px; border-radius:99px; letter-spacing:.3px; background:var(--surface-3); color:var(--text-2); }
    .tag-subject { background:var(--gold-pale); color:var(--gold-ink); }
    .tag-term { background:var(--doc-bg); color:var(--doc); }
    .tag-type { background:var(--video-bg); color:var(--video); }
    .card-footer { padding:11px 20px; border-top:1px solid var(--border-2); background:var(--surface-2); display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .card-uploader { display:flex; align-items:center; gap:8px; font-size:.74rem; color:var(--muted); min-width:0; }
    .uploader-avatar { width:24px; height:24px; background:var(--navy); border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    [data-theme="dark"] .uploader-avatar { background:var(--surface-3); }
    .uploader-avatar i { color:var(--gold); font-size:10px; }
    .uploader-name { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .card-stats { display:flex; align-items:center; gap:10px; flex-shrink:0; font-size:.7rem; color:var(--muted); }
    .dl-count { display:inline-flex; align-items:center; gap:5px; font-weight:700; }
    .dl-count i { color:var(--gold-d); font-size:10px; }
    .card-date { padding:6px 20px 0; font-size:.68rem; color:var(--muted); }
    .card-actions { padding:12px 20px 16px; display:flex; gap:8px; }
    .btn-download { flex:1; background:var(--navy); color:#fff; border:none; border-radius:10px; height:38px; font-family:inherit; font-size:.78rem; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:7px; transition:.15s; text-decoration:none; }
    [data-theme="dark"] .btn-download { background:var(--gold); color:#101826; }
    .btn-download:hover { transform:translateY(-1px); box-shadow:0 8px 18px rgba(11,31,58,.25); }
    .btn-preview, .btn-delete { background:transparent; color:var(--text-2); border:1.5px solid var(--border); border-radius:10px; height:38px; padding:0 13px; font-family:inherit; font-size:.78rem; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:7px; transition:.15s; text-decoration:none; }
    .btn-preview:hover { border-color:var(--gold); color:var(--text); }
    .btn-delete { padding:0 12px; color:var(--muted); }
    .btn-delete:hover { background:var(--red-bg); border-color:var(--red); color:var(--red); }

    .empty-state { text-align:center; padding:56px 20px; background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); }
    .empty-icon { width:68px; height:68px; background:var(--gold-pale); color:var(--gold-d); border-radius:18px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px; font-size:26px; }
    .empty-state h3 { font-size:1.05rem; font-weight:800; margin-bottom:4px; }
    .empty-state p { font-size:.86rem; color:var(--muted); }

    .pagination { display:flex; align-items:center; gap:6px; justify-content:center; flex-wrap:wrap; }
    .pag-btn { min-width:38px; height:38px; padding:0 10px; background:var(--surface); border:1.5px solid var(--border); border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; color:var(--text-2); text-decoration:none; transition:.15s; }
    .pag-btn:hover { border-color:var(--gold); color:var(--text); }
    .pag-btn.active { background:var(--navy); color:#fff; border-color:var(--navy); }
    [data-theme="dark"] .pag-btn.active { background:var(--gold); color:#101826; border-color:var(--gold); }
    .pag-btn.disabled { opacity:.4; pointer-events:none; }

    /* ───────── Delete modal + footer ───────── */
    .modal-overlay { display:none; position:fixed; inset:0; z-index:500; background:rgba(3,10,22,.6); backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:18px; }
    .modal-overlay.active { display:flex; }
    .modal { background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); width:100%; max-width:460px; box-shadow:var(--shadow-lg); overflow:hidden; animation:fadeUp .25s ease both; }
    .modal-head { padding:16px 22px; background:var(--red-bg); font-weight:800; font-size:1rem; display:flex; align-items:center; gap:10px; }
    .modal-head i { color:var(--red); }
    .modal-body { padding:20px 22px; font-size:.9rem; color:var(--text-2); line-height:1.65; }
    .modal-body strong { color:var(--text); }
    .modal-foot { display:flex; justify-content:flex-end; gap:10px; padding:14px 22px; border-top:1px solid var(--border-2); background:var(--surface-2); }
    .footer { text-align:center; padding:1.3rem 2rem; border-top:1px solid var(--border); font-size:.74rem; color:var(--muted); background:var(--surface); margin-top:auto; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @keyframes spin { to { transform:rotate(360deg); } }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; scroll-behavior:auto !important; } }

    /* ───────── Responsive ───────── */
    @media (max-width:1100px) { .form-grid { grid-template-columns:repeat(2,1fr); } .span3 { grid-column:span 2; } }
    @media (max-width:1024px) {
      .app { grid-template-columns:1fr; }
      .sidebar { position:fixed; left:-290px; width:272px; transition:left .25s ease; box-shadow:0 0 50px rgba(0,0,0,.55); }
      .sidebar.open { left:0; }
      .burger { display:inline-flex; }
      .topbar { padding:0 18px; } .content { padding:18px 18px 50px; }
    }
    @media (max-width:640px) {
      .tb-title small { display:none; }
      .hero { padding:22px 18px; } .hero-pill { min-width:0; flex:1; padding:10px 12px; } .hero-pill b { font-size:1.25rem; }
      .form-grid { grid-template-columns:1fr; } .span2, .span3 { grid-column:span 1; }
      .card-body { padding:18px 16px; }
      .filter-group, .filter-group.grow { min-width:calc(50% - 7px); } .filter-group.grow { min-width:100%; }
      .materials-grid { grid-template-columns:1fr; }
      .form-actions { flex-direction:column; align-items:stretch; } .upload-hint { margin-left:0; }
      .modal-foot .btn { flex:1; }
    }
    @media print { .sidebar, .topbar, .upload-panel, .filter-bar, .card-actions, .hero-actions, .backdrop, .pagination { display:none !important; } .app { display:block; } body { background:#fff; } }
  </style>
</head>
<body>
<a href="#main-content" class="skip">Skip to content</a>

<div class="app">

  <!-- ░░░ SIDEBAR ░░░ -->
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
        <a href="RegisterStudent.php"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
      
      </div>
      <div class="nav-section">
        <div class="nav-title">Academics</div>
        <a href="ViewResults.php"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults.php"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="track.php"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
        <a href="<?= h($self) ?>" class="active" aria-current="page"><i class="fa-solid fa-book-open"></i> Learning Materials</a>
      </div>
      <div class="nav-section">

      </div>
    </nav>

    <div class="side-user">
      <div class="av"><?= $isHoi ? 'HOI' : ($isAdminUser ? 'AD' : 'TR') ?></div>
      <div><b><?= h($roleLabel) ?></b><small>Signed in</small></div>
      <a href="login.php" title="Logout" aria-label="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
    </div>
    <div class="side-foot">&copy; 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>
  <div class="backdrop" id="overlay" onclick="closeSidebar()"></div>

  <!-- ░░░ MAIN ░░░ -->
  <div class="main">

    <header class="topbar">
      <div class="tb-left">
        <button class="burger" id="menuBtn" type="button" aria-label="Open menu"><i class="fa-solid fa-bars"></i></button>
        <div class="tb-title">Learning Materials<small>Resource library</small></div>
      </div>
      <div class="tb-right">
        <button class="icon-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>
        <a href="logout.php" class="icon-btn" aria-label="Logout" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
      </div>
    </header>

    <main id="main-content" class="content">

      <!-- Hero -->
      <section class="hero" aria-label="Learning materials">
        <div class="hero-inner">
          <div>
            <div class="hero-eyebrow">Academic Resources</div>
            <h1>Learning <em>Materials</em> Library</h1>
            <p>Upload, organise and access teaching and learning resources across all grades and subjects.</p>
            <div class="hero-actions">
              <button type="button" class="hero-btn primary" id="toggleUpload" aria-expanded="<?= $openUpload ? 'true' : 'false' ?>" aria-controls="uploadPanel"><i class="fa-solid fa-cloud-arrow-up"></i> <span id="toggleUploadText"><?= $openUpload ? 'Hide upload form' : 'Upload material' ?></span></button>
              <a href="#library" class="hero-btn ghost"><i class="fa-solid fa-book-open-reader"></i> Browse library</a>
            </div>
          </div>
          <div class="hero-meta">
            <div class="hero-pill"><small>Materials</small><b><?= number_format($allTotal) ?></b></div>
            <div class="hero-pill"><small>Subjects</small><b><?= count($subjectCounts) ?></b></div>
            <div class="hero-pill"><small>Downloads</small><b><?= number_format($allDownloads) ?></b></div>
          </div>
        </div>
      </section>

      <?php if ($flash): ?>
      <div class="flash <?= h($flashType) ?>" role="status">
        <i class="fa-solid <?= $flashType==='success'?'fa-circle-check':'fa-circle-exclamation' ?> lead"></i>
        <span><?= h($flash) ?></span>
        <button type="button" class="x" aria-label="Dismiss" onclick="this.closest('.flash').remove()"><i class="fa-solid fa-xmark"></i></button>
      </div>
      <?php endif; ?>

      <!-- ════ UPLOAD ════ -->
      <section class="upload-panel<?= $openUpload ? ' open' : '' ?>" id="uploadPanel">
        <div class="card">
          <div class="card-head"><h3><i class="fa-solid fa-cloud-arrow-up"></i> Add a learning resource</h3></div>
          <div class="card-body">
            <form method="POST" enctype="multipart/form-data" id="uploadForm">

              <div class="dropzone" id="dropzone">
                <input type="file" name="material_file" id="fileInput" required aria-label="Choose a file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.mp4,.mkv,.avi,.mov,.txt,.zip,.rar">
                <div class="dz-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                <div class="dz-title">Drag &amp; drop your file here</div>
                <div class="dz-sub">or <strong>click to browse</strong> &nbsp;·&nbsp; PDF, DOC, PPT, Excel, images, video, ZIP &nbsp;·&nbsp; max 50 MB</div>
              </div>
              <div class="dz-selected" id="dzSelected">
                <i class="fa-solid fa-file-circle-check"></i>
                <span id="dzFileName">No file selected</span>
                <span id="dzFileSize" style="margin-left:auto;color:var(--muted);font-size:.74rem"></span>
              </div>

              <div class="form-grid">
                <div class="form-group span2">
                  <label for="f-title">Title <span class="req">*</span></label>
                  <input type="text" name="title" id="f-title" class="form-control" placeholder="e.g. Grade 5 Mathematics — Fractions Notes" value="<?= h($old['title'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label for="f-by">Uploaded by <span class="req">*</span></label>
                  <input type="text" name="uploaded_by" id="f-by" class="form-control" placeholder="Teacher / staff name" value="<?= h($old['uploadedBy'] ?? '') ?>" required>
                </div>

                <div class="form-group">
                  <label for="f-subject">Subject <span class="req">*</span></label>
                  <select name="subject" id="f-subject" class="form-control" required>
                    <option value="">— Select subject —</option>
                    <?php foreach ($SUBJECTS as $s): ?><option value="<?= h($s) ?>" <?= selOpt($old, 'subject', $s) ?>><?= h($s) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label for="f-grade">Grade <span class="req">*</span></label>
                  <select name="grade" id="f-grade" class="form-control" required>
                    <option value="">— Select grade —</option>
                    <?php for ($g=1;$g<=9;$g++): ?><option value="Grade <?= $g ?>" <?= selOpt($old, 'grade', "Grade $g") ?>>Grade <?= $g ?></option><?php endfor; ?>
                    <option value="All Grades" <?= selOpt($old, 'grade', 'All Grades') ?>>All Grades</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="f-term">Term <span class="req">*</span></label>
                  <select name="term" id="f-term" class="form-control" required>
                    <option value="">— Select term —</option>
                    <?php foreach (['Term 1','Term 2','Term 3','All Terms'] as $t): ?><option value="<?= h($t) ?>" <?= selOpt($old, 'term', $t) ?>><?= h($t) ?></option><?php endforeach; ?>
                  </select>
                </div>

                <div class="form-group">
                  <label for="f-type">Material type</label>
                  <select name="material_type" id="f-type" class="form-control">
                    <option value="">— Select type —</option>
                    <?php foreach ($TYPES as $tp): ?><option value="<?= h($tp) ?>" <?= selOpt($old, 'material_type', $tp) ?>><?= h($tp) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group span2">
                  <label for="f-tags">Tags <small>(comma-separated, optional)</small></label>
                  <input type="text" name="tags" id="f-tags" class="form-control" placeholder="e.g. fractions, multiplication, term1, revision" value="<?= h($old['tags'] ?? '') ?>">
                </div>

                <div class="form-group span3">
                  <label for="f-desc">Description</label>
                  <textarea name="description" id="f-desc" class="form-control" placeholder="Brief description of what this material covers…"><?= h($old['description'] ?? '') ?></textarea>
                </div>
              </div>

              <div class="form-actions">
                <button type="submit" class="btn gold" id="submitBtn"><i class="fa-solid fa-cloud-arrow-up"></i> Upload material</button>
                <button type="reset" class="btn ghost" onclick="resetForm()"><i class="fa-solid fa-rotate-left"></i> Reset</button>
                <span class="upload-hint"><i class="fa-solid fa-shield-halved"></i> Files are stored securely on the school server.</span>
              </div>
            </form>
          </div>
        </div>
      </section>

      <!-- ════ RECENTLY ADDED ════ -->
      <?php if ($allTotal > 0 && !$hasFilters): ?>
      <div class="section-label">Recently added</div>
      <div class="recent-strip">
        <?php mysqli_data_seek($recentRes, 0); while ($r = mysqli_fetch_assoc($recentRes)): [$ico, $tcls] = fileIcon($r['file_ext']); ?>
        <div class="recent-item t-<?= $tcls ?>">
          <div class="file-icon-wrap"><i class="fa-solid <?= $ico ?>"></i></div>
          <div>
            <div class="recent-name" title="<?= h($r['title']) ?>"><?= h($r['title']) ?></div>
            <div class="recent-meta"><?= h($r['subject']) ?> · <?= h($r['grade']) ?> · <?= timeAgo($r['uploaded_at']) ?></div>
          </div>
        </div>
        <?php endwhile; ?>
      </div>
      <?php endif; ?>

      <!-- ════ SUBJECTS ════ -->
      <?php if (!empty($subjectCounts)): ?>
      <div class="section-label">Browse by subject</div>
      <div class="subject-stats">
        <?php foreach ($subjectCounts as $sc): $on = ($fSubject === $sc['subject']); ?>
        <a class="subj-chip<?= $on ? ' on' : '' ?>" href="<?= $on ? h($self) . '#library' : '?fsubject=' . urlencode($sc['subject']) . '#library' ?>" title="<?= $on ? 'Clear subject filter' : 'Show ' . h($sc['subject']) ?>">
          <?= h($sc['subject']) ?> <b><?= (int)$sc['cnt'] ?></b>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- ════ LIBRARY ════ -->
      <div class="section-label" id="library">Materials library <span class="badge"><?= $totalMats ?> found</span></div>

      <form method="GET" action="#library" id="filterForm">
        <div class="filter-bar">
          <div class="filter-group grow">
            <label for="s-search">Search</label>
            <input type="search" id="s-search" name="search" class="form-control" placeholder="Title, tag, uploader…" value="<?= h($fSearch) ?>">
          </div>
          <div class="filter-group">
            <label for="s-grade">Grade</label>
            <select name="fgrade" id="s-grade" class="form-control">
              <option value="">All grades</option>
              <?php for ($g=1;$g<=9;$g++): ?><option value="Grade <?= $g ?>" <?= $fGrade==="Grade $g"?'selected':'' ?>>Grade <?= $g ?></option><?php endfor; ?>
              <option value="All Grades" <?= $fGrade==='All Grades'?'selected':'' ?>>All Grades</option>
            </select>
          </div>
          <div class="filter-group">
            <label for="s-subject">Subject</label>
            <select name="fsubject" id="s-subject" class="form-control">
              <option value="">All subjects</option>
              <?php foreach ($SUBJECTS as $s): ?><option value="<?= h($s) ?>" <?= $fSubject===$s?'selected':'' ?>><?= h($s) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="filter-group">
            <label for="s-term">Term</label>
            <select name="fterm" id="s-term" class="form-control">
              <option value="">All terms</option>
              <?php foreach (['Term 1','Term 2','Term 3','All Terms'] as $t): ?><option value="<?= h($t) ?>" <?= $fTerm===$t?'selected':'' ?>><?= h($t) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="filter-group">
            <label for="s-type">Type</label>
            <select name="ftype" id="s-type" class="form-control">
              <option value="">All types</option>
              <?php foreach ($TYPES as $tp): ?><option value="<?= h($tp) ?>" <?= $fType===$tp?'selected':'' ?>><?= h($tp) ?></option><?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
          <?php if ($hasFilters): ?><a href="<?= h($self) ?>#library" class="btn ghost"><i class="fa-solid fa-xmark"></i> Clear</a><?php endif; ?>
        </div>
      </form>

      <?php if ($totalMats === 0): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fa-solid fa-book-open"></i></div>
        <h3>No materials found</h3>
        <p><?= $hasFilters ? 'Try adjusting your filters.' : 'Be the first to upload a learning material.' ?></p>
      </div>
      <?php else: ?>
      <div class="materials-grid">
        <?php
        $cardIdx = 0;
        while ($mat = mysqli_fetch_assoc($matsRes)):
            [$ico, $tcls] = fileIcon($mat['file_ext']);
            $cardIdx++;
            $sizeStr = $mat['file_size'] ? formatBytes($mat['file_size']) : '';
            $tagsArr = $mat['tags'] ? array_filter(array_map('trim', explode(',', $mat['tags']))) : [];
            $previewable = in_array(strtolower((string)$mat['file_ext']), ['pdf','jpg','jpeg','png','gif'], true);
        ?>
        <article class="material-card t-<?= $tcls ?>" style="animation-delay:<?= min($cardIdx*0.05,0.6) ?>s">
          <div class="card-top">
            <div class="file-icon-wrap"><i class="fa-solid <?= $ico ?>"></i></div>
            <div class="card-meta">
              <div class="card-title" title="<?= h($mat['title']) ?>"><?= h($mat['title']) ?></div>
              <?php if ($mat['description']): ?><div class="card-desc"><?= h($mat['description']) ?></div><?php endif; ?>
              <div class="card-tags">
                <span class="tag"><?= h($mat['grade']) ?></span>
                <span class="tag tag-subject"><?= h($mat['subject']) ?></span>
                <span class="tag tag-term"><?= h($mat['term']) ?></span>
                <?php if ($mat['material_type']): ?><span class="tag tag-type"><?= h($mat['material_type']) ?></span><?php endif; ?>
                <?php foreach (array_slice($tagsArr, 0, 2) as $tg): ?><span class="tag"><?= h($tg) ?></span><?php endforeach; ?>
              </div>
            </div>
          </div>

          <div class="card-footer">
            <div class="card-uploader">
              <div class="uploader-avatar"><i class="fa-solid fa-user"></i></div>
              <span class="uploader-name"><?= h($mat['uploaded_by']) ?></span>
            </div>
            <div class="card-stats">
              <?php if ($sizeStr): ?><span><?= h($sizeStr) ?></span><?php endif; ?>
              <span class="dl-count"><i class="fa-solid fa-download"></i><?= (int)$mat['downloads'] ?></span>
            </div>
          </div>
          <div class="card-date"><?= date('d M Y, g:ia', strtotime($mat['uploaded_at'])) ?></div>

          <div class="card-actions">
            <a href="<?= h($self) ?>?dl=<?= (int)$mat['id'] ?>" class="btn-download" title="Download <?= h($mat['file_original']) ?>"><i class="fa-solid fa-download"></i> Download</a>
            <?php if ($previewable): ?>
            <a href="<?= h($uploadDir . $mat['file_name']) ?>" target="_blank" rel="noopener" class="btn-preview"><i class="fa-solid fa-eye"></i> Preview</a>
            <?php endif; ?>
            <?php if ($canDelete): ?>
            <button type="button" class="btn-delete" title="Delete" aria-label="Delete <?= h($mat['title']) ?>" data-id="<?= (int)$mat['id'] ?>" data-title="<?= h($mat['title']) ?>"><i class="fa-solid fa-trash"></i></button>
            <?php endif; ?>
          </div>
        </article>
        <?php endwhile; ?>
      </div>

      <?php if ($totalPages > 1): ?>
      <nav class="pagination" aria-label="Pages">
        <?php $qs = $qsFilters ? '&' . $qsFilters : ''; ?>
        <a href="?page=<?= max(1,$page-1) . $qs ?>#library" class="pag-btn <?= $page<=1?'disabled':'' ?>" aria-label="Previous page"><i class="fa-solid fa-chevron-left"></i></a>
        <?php for ($p=1;$p<=$totalPages;$p++): ?>
        <a href="?page=<?= $p . $qs ?>#library" class="pag-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a href="?page=<?= min($totalPages,$page+1) . $qs ?>#library" class="pag-btn <?= $page>=$totalPages?'disabled':'' ?>" aria-label="Next page"><i class="fa-solid fa-chevron-right"></i></a>
      </nav>
      <?php endif; ?>
      <?php endif; ?>

    </main>

    <div class="footer">&copy; Designed by <strong>Kelvin Mutinda</strong> 2026 &nbsp;·&nbsp; Stephen Kanja School Learning Materials Library</div>
  </div>
</div>

<!-- ════ DELETE CONFIRM ════ -->
<?php if ($canDelete): ?>
<div class="modal-overlay" id="delOverlay">
  <div class="modal" role="alertdialog" aria-modal="true" aria-labelledby="delTitle">
    <div class="modal-head" id="delTitle"><i class="fa-solid fa-trash"></i> Delete this material?</div>
    <form method="POST" action="">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="id" id="delId" value="">
      <div class="modal-body">Delete <strong id="delName"></strong>? The file is removed from the server and this cannot be undone.</div>
      <div class="modal-foot">
        <button type="button" class="btn ghost" id="delCancel">Cancel</button>
        <button type="submit" class="btn danger"><i class="fa-solid fa-trash"></i> Delete</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
  /* ── Sidebar ── */
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  const menuBtn = document.getElementById('menuBtn');
  function openSidebar()  { sidebar.classList.add('open');    overlay.classList.add('show');    menuBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>'; document.body.style.overflow = 'hidden'; }
  function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('show'); menuBtn.innerHTML = '<i class="fa-solid fa-bars"></i>'; document.body.style.overflow = ''; }
  menuBtn.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeSidebar(); closeDelete(); } });

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

  /* ── Show / hide the upload form ── */
  const uploadPanel = document.getElementById('uploadPanel');
  const toggleBtn   = document.getElementById('toggleUpload');
  const toggleText  = document.getElementById('toggleUploadText');
  toggleBtn.addEventListener('click', () => {
    const open = uploadPanel.classList.toggle('open');
    toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggleText.textContent = open ? 'Hide upload form' : 'Upload material';
    if (open) uploadPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  /* ── Drop zone ── */
  const dropzone   = document.getElementById('dropzone');
  const fileInput  = document.getElementById('fileInput');
  const dzSelected = document.getElementById('dzSelected');
  const dzFileName = document.getElementById('dzFileName');
  const dzFileSize = document.getElementById('dzFileSize');

  function formatBytes(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b >= 1024) return (b / 1024).toFixed(1) + ' KB';
    return b + ' B';
  }
  function showFile(f) {
    if (!f) return;
    dzFileName.textContent = f.name;
    dzFileSize.textContent = formatBytes(f.size);
    dzSelected.classList.add('show');
  }
  fileInput.addEventListener('change', () => showFile(fileInput.files[0]));
  dropzone.addEventListener('dragover', e => { e.preventDefault(); dropzone.classList.add('dragover'); });
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
  dropzone.addEventListener('drop', e => {
    e.preventDefault(); dropzone.classList.remove('dragover');
    if (e.dataTransfer.files.length) { fileInput.files = e.dataTransfer.files; showFile(e.dataTransfer.files[0]); }
  });
  function resetForm() {
    dzSelected.classList.remove('show');
    dzFileName.textContent = 'No file selected';
    dzFileSize.textContent = '';
    dropzone.classList.remove('dragover');
  }

  /* ── Upload button loading state ── */
  document.getElementById('uploadForm').addEventListener('submit', function (e) {
    const btn = document.getElementById('submitBtn');
    if (!fileInput.files.length) { e.preventDefault(); alert('Please select a file to upload.'); return; }
    if (fileInput.files[0].size > 50 * 1024 * 1024) { e.preventDefault(); alert('That file is over the 50 MB limit.'); return; }
    btn.innerHTML = '<i class="fa-solid fa-circle-notch" style="animation:spin .8s linear infinite"></i> Uploading…';
    btn.disabled = true;
  });

  /* ── Filters apply as soon as a dropdown changes ── */
  document.querySelectorAll('#filterForm select').forEach(s => s.addEventListener('change', () => document.getElementById('filterForm').submit()));

  /* ── Delete confirmation modal (administrators) ── */
  const delOverlay = document.getElementById('delOverlay');
  function closeDelete() { if (delOverlay) delOverlay.classList.remove('active'); }
  if (delOverlay) {
    document.querySelectorAll('.btn-delete').forEach(b => b.addEventListener('click', () => {
      document.getElementById('delId').value = b.dataset.id;
      document.getElementById('delName').textContent = '"' + b.dataset.title + '"';
      delOverlay.classList.add('active');
    }));
    document.getElementById('delCancel').addEventListener('click', closeDelete);
    delOverlay.addEventListener('mousedown', e => { if (e.target === delOverlay) closeDelete(); });
  }
</script>

</body>
</html>