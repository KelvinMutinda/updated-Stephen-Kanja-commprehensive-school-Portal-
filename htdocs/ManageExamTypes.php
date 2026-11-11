<?php
     require_once __DIR__ . '/page_guard.php';
// Save on the server as: ManageExamTypes.php  (admin only)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php-error.log');
error_reporting(E_ALL);

ob_start();
mysqli_report(MYSQLI_REPORT_OFF);

include 'conn.php';
require_once 'auth_helpers.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    ob_clean();
    http_response_code(500);
    exit('Database connection failed.');
}

// ── Admin gate (JSON for AJAX, plain text otherwise) ──
if (!isAdmin()) {
    ob_clean();
    http_response_code(403);
    if (isset($_GET['ajax_action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Only an administrator can manage exam types.']);
    } else {
        echo 'Access denied: administrators only.';
    }
    exit;
}

// ============================================================
// ACTION: add_exam_type
// ============================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'add_exam_type') {
    ob_clean();
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $name  = isset($input['name']) ? trim((string)$input['name']) : '';

    if ($name === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Exam name is required.']); ob_end_flush(); exit; }
    if (mb_strlen($name) > 60) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Exam name is too long (max 60 characters).']); ob_end_flush(); exit; }

    $code = strtolower(trim($name));
    $code = preg_replace('/[^a-z0-9]+/', '_', $code);
    $code = trim($code, '_');
    if ($code === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Exam name must contain at least one letter or number.']); ob_end_flush(); exit; }

    $reserved = ['opener', 'midterm', 'endterm'];
    if (in_array($code, $reserved, true)) {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>'"'.$name.'" is already a built-in exam type.']);
        ob_end_flush(); exit;
    }

    $nameEsc = mysqli_real_escape_string($conn, $name);
    $codeEsc = mysqli_real_escape_string($conn, $code);

    $check = mysqli_query($conn, "SELECT id, name FROM exam_types WHERE code = '$codeEsc' LIMIT 1");
    if ($check && mysqli_num_rows($check) > 0) {
        $existing = mysqli_fetch_assoc($check);
        echo json_encode(['success'=>true,'code'=>$code,'name'=>$existing['name'],'existing'=>true]);
        ob_end_flush(); exit;
    }

    $insert = mysqli_query($conn, "INSERT INTO exam_types (code, name) VALUES ('$codeEsc', '$nameEsc')");
    if ($insert === false) {
        if (mysqli_errno($conn) === 1062) {
            echo json_encode(['success'=>true,'code'=>$code,'name'=>$name,'existing'=>true]);
            ob_end_flush(); exit;
        }
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'Could not save exam type: ' . mysqli_error($conn)]);
        ob_end_flush(); exit;
    }

    echo json_encode(['success'=>true,'code'=>$code,'name'=>$name,'existing'=>false]);
    ob_end_flush(); exit;
}

// ============================================================
// ACTION: delete_exam_type
// ============================================================
if (isset($_GET['ajax_action']) && $_GET['ajax_action'] === 'delete_exam_type') {
    ob_clean();
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $code  = isset($input['code']) ? trim((string)$input['code']) : '';

    if ($code === '') { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Exam type is required.']); ob_end_flush(); exit; }
    if (in_array($code, ['opener','midterm','endterm'], true)) { http_response_code(409); echo json_encode(['success'=>false,'error'=>'Built-in exam types cannot be deleted.']); ob_end_flush(); exit; }

    $codeEsc = mysqli_real_escape_string($conn, $code);

    $exists = mysqli_query($conn, "SELECT id FROM exam_types WHERE code = '$codeEsc' LIMIT 1");
    if (!$exists || mysqli_num_rows($exists) === 0) { http_response_code(404); echo json_encode(['success'=>false,'error'=>'That exam type no longer exists.']); ob_end_flush(); exit; }

    $use   = mysqli_query($conn, "SELECT COUNT(*) AS c FROM exam2 WHERE exam_type = '$codeEsc'");
    $inUse = $use ? (int)mysqli_fetch_assoc($use)['c'] : 0;
    if ($inUse > 0) {
        http_response_code(409);
        echo json_encode(['success'=>false,'error'=>"Can't delete: $inUse saved mark record(s) use this exam type."]);
        ob_end_flush(); exit;
    }

    $del = mysqli_query($conn, "DELETE FROM exam_types WHERE code = '$codeEsc' LIMIT 1");
    if ($del === false) { http_response_code(500); echo json_encode(['success'=>false,'error'=>'Could not delete exam type: ' . mysqli_error($conn)]); ob_end_flush(); exit; }

    echo json_encode(['success'=>true,'code'=>$code]);
    ob_end_flush(); exit;
}

require_once 'add-exam-type.php';
$EXAM_TYPES = getExamTypes($conn);
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Exam Types — Stephen Kanja School</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:ital,wght@0,700;0,900;1,700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="upload-style.css">
  <style>
    .et-add { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1.4rem; }
    .et-add .field { flex: 1; min-width: 240px; max-width: 420px; }
    table.et { width: 100%; border-collapse: collapse; font-size: 14px; }
    .et th { text-align: left; padding: 10px 12px; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; background: #fafbfc; border-bottom: 2px solid var(--line); }
    .et td { padding: 10px 12px; border-bottom: 1px solid #edf0f4; vertical-align: middle; }
    .et tbody tr:last-child td { border-bottom: none; }
    .tag { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 2px 10px; border-radius: 20px; }
    .tag-built { background: #eef1f5; color: #4b5563; }
    .tag-custom { background: #e7f8f2; color: var(--accent-dk); }
    .btn-del { height: 34px; padding: 0 12px; border-radius: 6px; cursor: pointer; color: #a12020; background: #fff5f5; border: 1px solid #f0c4c4; font: 600 13px 'Plus Jakarta Sans', sans-serif; display: inline-flex; align-items: center; gap: 6px; }
    .btn-del:hover { background: var(--danger); color: #fff; border-color: var(--danger); }
    .muted { color: var(--muted); font-size: 12.5px; }
  </style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<header class="brandbar">
  <div class="brandbar-left">
    <button type="button" class="menu-btn" id="menuBtn" aria-label="Toggle menu" aria-expanded="false"><i class="fa-solid fa-bars"></i></button>
    <div class="brandbar-mark"><i class="fa-solid fa-graduation-cap"></i></div>
    <div class="brandbar-text">
      <div class="brandbar-title">Stephen Kanja <span>School</span></div>
      <div class="brandbar-tag">Aim Higher</div>
    </div>
  </div>
  <div class="brandbar-right">
    <span class="brandbar-user"><i class="fa-solid fa-circle-user"></i><span>Administrator</span></span>
    <a href="logout" class="brandbar-logout"><i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span></a>
  </div>
</header>

<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-inner">
      <p class="nav-label">Main</p>
      <nav>
        <a href="teacherpanel"><i class="fa-solid fa-house"></i> Home</a>
        <a href="Students"><i class="fa-solid fa-user-graduate"></i> Students</a>
        <a href="Progress"><i class="fa-solid fa-chart-line"></i> Progress Records</a>
        <a href="generate_template"><i class="fa-solid fa-chart-column"></i> Get Marks template</a>
      </nav>
      <p class="nav-label">Academics</p>
      <nav>
        <a href="ViewResults"><i class="fa-solid fa-chart-pie"></i> Results</a>
        <a href="UploadResults"><i class="fa-solid fa-upload"></i> Upload Results</a>
        <a href="send_results_sms"><i class="fa-solid fa-comment-sms"></i> Send Results SMS</a>
        <a href="track"><i class="fa-solid fa-bullseye"></i> Track Performance</a>
        <a href="LearningMaterials"><i class="fa-solid fa-book"></i> Learning Materials</a>
      </nav>
      <p class="nav-label">Administration</p>
      <nav>
        <a href="ManageExamTypes.php" class="active"><i class="fa-solid fa-clipboard-list"></i> Exam Types</a>
        <a href="RegisterStudent"><i class="fa-solid fa-user-plus"></i> Register Learners</a>
        <a href="manage_parent_contacts"><i class="fa-solid fa-address-book"></i> Parent Contacts</a>
      </nav>
    </div>
    <div class="sidebar-footer">© 2026 <strong>Kelvin Mutinda</strong></div>
  </aside>

  <main class="main">
    <div class="topbar">
      <div class="topbar-inner">
        <div class="topbar-title">Exam Types<small>Administrators only</small></div>
        <a href="UploadResults" class="topbar-action"><i class="fa-solid fa-upload"></i><span>Upload Results</span></a>
      </div>
    </div>

    <header class="hero">
      <div class="hero-inner">
        <div class="hero-crest"><i class="fa-solid fa-clipboard-list"></i></div>
        <div>
          <h1>Stephen Kanja Primary &amp; Junior School</h1>
          <p>Manage Exam Types</p>
        </div>
      </div>
    </header>
    <div class="hero-rule"></div>

    <div class="content">
      <div class="panel" style="padding-top:1.6rem;">

        <div class="et-add">
          <div class="field">
            <label for="newExamName">Add a new exam type</label>
            <input type="text" class="txt" id="newExamName" maxlength="60" placeholder="e.g. Mock Exam" autocomplete="off">
          </div>
          <button type="button" class="btn btn-primary" id="saveExamTypeBtn" style="height:42px;"><i class="fa-solid fa-plus"></i> Add exam type</button>
        </div>
        <div class="add-exam-msg" id="addExamMsg"></div>

        <div class="box" style="margin-top:1rem;">
          <div class="box-head">
            <span class="box-title"><i class="fa-solid fa-list"></i>Current exam types</span>
            <span class="badge"><?= count($EXAM_TYPES) ?></span>
          </div>
          <div class="table-scroll">
            <table class="et">
              <thead><tr><th>Name</th><th>Code</th><th>Kind</th><th style="width:120px;"></th></tr></thead>
              <tbody>
              <?php foreach ($EXAM_TYPES as $code => $et): ?>
                <tr>
                  <td><?= htmlspecialchars($et['label']) ?></td>
                  <td class="muted"><?= htmlspecialchars($code) ?></td>
                  <td><?= $et['custom'] ? '<span class="tag tag-custom">Custom</span>' : '<span class="tag tag-built">Built-in</span>' ?></td>
                  <td>
                    <?php if ($et['custom']): ?>
                    <button type="button" class="btn-del" data-code="<?= htmlspecialchars($code, ENT_QUOTES) ?>" data-label="<?= htmlspecialchars($et['label'], ENT_QUOTES) ?>"><i class="fa-solid fa-trash"></i> Delete</button>
                    <?php else: ?>
                    <span class="muted">Locked</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>

        <p class="muted" style="margin-top:12px;">An exam type can't be deleted once marks have been saved under it. New exam types appear in every teacher's Upload Results dropdown immediately.</p>
      </div>
    </div>
  </main>
</div>

<footer class="site-footer">&copy; Designed by Kelvin Mutinda 2026. All rights reserved.</footer>

<script>
  /* Sidebar */
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('overlay');
  const menuBtn = document.getElementById('menuBtn');
  const isMobile = () => window.matchMedia('(max-width: 900px)').matches;

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
    }
  });
  window.addEventListener('resize', () => { if (!isMobile()) closeSidebar(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

  /* Exam types */
  const msg = document.getElementById('addExamMsg');
  const input = document.getElementById('newExamName');
  const saveBtn = document.getElementById('saveExamTypeBtn');

  function showMsg(ok, text) {
    msg.className = 'add-exam-msg visible ' + (ok ? 'ok' : 'err');
    msg.textContent = text;
  }

  function saveNewExamType() {
    const name = input.value.trim();
    if (!name) { showMsg(false, 'Please enter an exam name.'); input.focus(); return; }

    saveBtn.disabled = true;
    const original = saveBtn.innerHTML;
    saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    fetch('ManageExamTypes.php?ajax_action=add_exam_type', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: name })
    })
      .then(res => res.json().catch(() => null))
      .then(data => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = original;
        if (!data || !data.success) {
          showMsg(false, (data && data.error) ? data.error : 'Could not save the exam type.');
          return;
        }
        showMsg(true, data.existing ? '"' + data.name + '" already existed.' : '"' + data.name + '" was added.');
        setTimeout(() => window.location.reload(), 700);
      })
      .catch(err => {
        saveBtn.disabled = false;
        saveBtn.innerHTML = original;
        showMsg(false, 'Network error: ' + err.message);
      });
  }

  function deleteExamType(code, label) {
    if (!confirm('Delete the exam type "' + label + '"?\n\nThis cannot be undone, and it is blocked if marks have already been saved under it.')) return;

    fetch('ManageExamTypes.php?ajax_action=delete_exam_type', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code: code })
    })
      .then(res => res.json().catch(() => null))
      .then(data => {
        if (!data || !data.success) {
          showMsg(false, (data && data.error) ? data.error : 'Could not delete the exam type.');
          return;
        }
        window.location.reload();
      })
      .catch(err => showMsg(false, 'Network error: ' + err.message));
  }

  saveBtn.addEventListener('click', saveNewExamType);
  input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); saveNewExamType(); } });
  document.querySelectorAll('.btn-del').forEach(b =>
    b.addEventListener('click', () => deleteExamType(b.dataset.code, b.dataset.label)));
</script>

</body>
</html>