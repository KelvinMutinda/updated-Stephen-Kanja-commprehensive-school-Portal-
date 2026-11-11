<?php
// index.php — login page. Session block MUST be first, before any HTML.
include 'conn.php';
require_once 'auth_helpers.php';          // loads constants + role helpers
require_once __DIR__ . '/session_timeout.php';   // inactivity logout + safer session cookie
if (session_status() === PHP_SESSION_NONE) session_start();

$error  = "";
$notice = "";

// Shown after the system logged someone out for being idle
if (isset($_GET['timeout'])) {
    $notice = "You were logged out after " . (int)(SESSION_IDLE_LIMIT / 60)
            . " minutes of inactivity. Please sign in again.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $identifier = trim($_POST['AssesmentNumber'] ?? '');
    $passcode   = trim($_POST['password'] ?? '');

    $authenticated = false;

    // ── 1) Try the Student table (Assessment Number + password) ──
    $sql = "SELECT * FROM `Student` WHERE Assesment=? AND password=?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $identifier, $passcode);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $authenticated = true;
        $row = $result->fetch_assoc();

        session_begin_login();            // new session id + start the inactivity clock

        $_SESSION['loggedin']     = true;
        $_SESSION['username']     = $identifier;
        $_SESSION['user_id']      = (int)$row['id'];

        // Normalise legacy role strings from the Student table:
        //   'user'       → learner
        //   'admin'      → admin (staff kept historically in this table)
        //   'superadmin' → admin, account_type 'hoi'
        $rawRole = strtolower(trim((string)$row['role']));

        if ($rawRole === 'user') {
            $_SESSION['role']         = 'learner';
            $_SESSION['account_type'] = 'learner';
            header("Location: StudentDashboard.php");
            exit;

        } elseif ($rawRole === 'admin') {
            $_SESSION['role']         = 'admin';
            $_SESSION['account_type'] = 'admin';
            header("Location: teacherpanel.php");
            exit;

        } elseif ($rawRole === 'superadmin') {
            $_SESSION['role']         = 'admin';       // HOI counts as admin to helpers
            $_SESSION['account_type'] = 'hoi';
            $_SESSION['teacher_id']   = (int)$row['id'];
            header("Location: Hoi.php");
            exit;

        } else {
            // role not recognised: do not leave them logged in
            session_destroy_completely();
            session_start();
            $error = "Your account role ('" . htmlspecialchars($row['role'])
                   . "') isn't recognized. Please contact the school office.";
        }
    }

    // ── 2) If no learner matched, try the Teachers table (Email + TSC Number) ──
    if (!$authenticated) {
        $sql2 = "SELECT * FROM `Teachers` WHERE email=? AND tscNo=?";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param("ss", $identifier, $passcode);
        $stmt2->execute();
        $result2 = $stmt2->get_result();

        if ($result2 && $result2->num_rows > 0) {
            $authenticated = true;
            $row2 = $result2->fetch_assoc();

            session_begin_login();        // new session id + start the inactivity clock

            $_SESSION['loggedin']     = true;
            $_SESSION['username']     = $row2['email'];
            $_SESSION['teacher_id']   = (int)$row2['id'];
            $_SESSION['user_id']      = (int)$row2['id'];

            // Teachers.role is free-typed ("teacher", "Normal",
            // "Head of Instituion" — typo already in the data). Match
            // loosely: anything containing "head" → HOI/admin,
            // everything else → regular teacher.
            $roleNorm = strtolower(trim((string)$row2['role']));

            if (strpos($roleNorm, 'head') !== false) {
                $_SESSION['role']         = 'admin';   // HOI uses admin powers
                $_SESSION['account_type'] = 'hoi';
                header("Location: Hoi.php");
                exit;
            } else {
                $_SESSION['role']         = 'teacher';
                $_SESSION['account_type'] = 'teacher';
                header("Location: teacherpanel.php");
                exit;
            }
        }
    }

    if (!$authenticated) {
        $error = "Invalid credentials. Enter valid details.";
    }
}

// Keep the user's choice and typed identifier after a failed attempt (never the password)
$loginMode = (($_POST['loginMode'] ?? '') === 'staff') ? 'staff' : 'learner';
$prefill   = isset($identifier) ? $identifier : '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in — Stephen Kanja School Portal</title>
  <script>
    /* Apply saved theme before first paint (shared with the dashboards) */
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
      --border:#e4e8f0;
      --navy:#0b1f3a; --gold:#f0c040; --gold-d:#c8992a; --gold-pale:#fdf3d4; --gold-ink:#7a5a10;
      --red:#dc2626; --red-bg:#fee2e2; --red-bd:#fca5a5; --red-ink:#991b1b;
      --amber-bg:#fef9c3; --amber-bd:#fde047; --amber-ink:#854d0e;
      --shadow:0 6px 24px rgba(15,23,42,.07); --shadow-lg:0 24px 64px rgba(15,23,42,.14);
      color-scheme: light;
    }
    [data-theme="dark"] {
      --bg:#0a1120; --surface:#111b30; --surface-2:#0e1729; --surface-3:#16223b;
      --text:#e7ecf5; --text-2:#aebad0; --muted:#7b8aa3; --border:#1f2d47;
      --gold-pale:rgba(240,192,64,.14); --gold-ink:#f0c040;
      --red:#f87171; --red-bg:rgba(248,113,113,.14); --red-bd:rgba(248,113,113,.4); --red-ink:#fca5a5;
      --amber-bg:rgba(251,191,36,.14); --amber-bd:rgba(251,191,36,.4); --amber-ink:#fcd34d;
      --shadow:0 6px 24px rgba(0,0,0,.35); --shadow-lg:0 24px 64px rgba(0,0,0,.5);
      color-scheme: dark;
    }

    html { scroll-behavior:smooth; }
    body { font-family:'Plus Jakarta Sans',system-ui,sans-serif; background:var(--bg); color:var(--text); min-height:100vh; font-size:14.5px; line-height:1.6; -webkit-font-smoothing:antialiased; transition:background .25s,color .25s; }
    :focus-visible { outline:2px solid var(--gold); outline-offset:2px; border-radius:6px; }

    .split { display:grid; grid-template-columns:minmax(0,1.05fr) minmax(0,1fr); min-height:100vh; }

    /* ───────── Brand panel ───────── */
    .brand-panel { position:relative; overflow:hidden; background:linear-gradient(155deg,#0b1f3a 0%,#0d2a52 55%,#07152a 100%); color:#fff; padding:48px 56px; display:flex; flex-direction:column; justify-content:space-between; }
    .brand-panel::before { content:''; position:absolute; inset:0; background-image:radial-gradient(circle, rgba(255,255,255,.07) 1px, transparent 1px); background-size:28px 28px; pointer-events:none; }
    .brand-panel::after { content:''; position:absolute; width:520px; height:520px; border-radius:50%; right:-180px; bottom:-200px; background:radial-gradient(circle, rgba(240,192,64,.22) 0%, transparent 65%); pointer-events:none; }
    .ring { position:absolute; border-radius:50%; border:1px solid rgba(255,255,255,.07); pointer-events:none; }
    .ring.r1 { width:420px; height:420px; top:-140px; right:-110px; }
    .ring.r2 { width:240px; height:240px; bottom:80px; left:-90px; }
    .brand-top, .brand-mid, .brand-bot { position:relative; z-index:1; }
    .brand-row { display:flex; align-items:center; gap:14px; }
    .brand-mark { width:48px; height:48px; border-radius:14px; background:linear-gradient(135deg,var(--gold),#e0a920); display:flex; align-items:center; justify-content:center; color:#0b1f3a; font-size:21px; flex-shrink:0; box-shadow:0 8px 22px rgba(240,192,64,.35); overflow:hidden; }
    .brand-mark img { width:100%; height:100%; object-fit:cover; }
    .brand-name { font-weight:800; font-size:1.02rem; line-height:1.2; letter-spacing:.2px; }
    .brand-name span { color:var(--gold); }
    .brand-tag { font-size:.68rem; color:#8ea0bd; letter-spacing:1.6px; text-transform:uppercase; margin-top:2px; }
    .hero-eyebrow { font-size:.7rem; font-weight:800; letter-spacing:2px; text-transform:uppercase; color:var(--gold); margin-bottom:14px; }
    .hero-title { font-size:clamp(2rem,3.6vw,3rem); font-weight:800; letter-spacing:-1.2px; line-height:1.1; max-width:460px; }
    .hero-title em { font-style:normal; color:var(--gold); }
    .hero-sub { color:#9fb0cb; font-size:.98rem; margin-top:16px; max-width:430px; line-height:1.7; }
    .points { list-style:none; margin-top:30px; display:flex; flex-direction:column; gap:14px; }
    .points li { display:flex; align-items:center; gap:14px; font-size:.9rem; color:#c9d4e6; font-weight:500; }
    .points .pi { width:36px; height:36px; border-radius:10px; background:rgba(240,192,64,.13); color:var(--gold); display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; }
    .brand-bot { font-size:.74rem; color:#6f83a3; }

    /* ───────── Form panel ───────── */
    .form-panel { position:relative; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:48px 32px; }
    .theme-btn { position:absolute; top:20px; right:20px; width:40px; height:40px; border-radius:10px; border:1px solid var(--border); background:var(--surface); color:var(--text-2); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:15px; transition:.15s; }
    .theme-btn:hover { background:var(--surface-3); color:var(--text); }

    .form-wrap { width:100%; max-width:410px; animation:fadeUp .5s ease both; }
    .mobile-brand { display:none; align-items:center; gap:12px; margin-bottom:28px; }
    .mobile-brand .brand-name { color:var(--text); }
    .eyebrow { font-size:.7rem; font-weight:800; letter-spacing:1.8px; text-transform:uppercase; color:var(--gold-d); }
    .form-title { font-size:1.75rem; font-weight:800; letter-spacing:-.7px; line-height:1.2; margin-top:2px; }
    .form-sub { color:var(--muted); font-size:.88rem; margin-top:6px; }

    .seg { display:grid; grid-template-columns:1fr 1fr; gap:4px; padding:5px; background:var(--surface); border:1px solid var(--border); border-radius:14px; margin:26px 0 22px; box-shadow:var(--shadow); }
    .seg button { display:inline-flex; align-items:center; justify-content:center; gap:9px; padding:10px 12px; border:none; background:none; border-radius:10px; color:var(--text-2); font-family:inherit; font-weight:700; font-size:.84rem; cursor:pointer; transition:.15s; }
    .seg button:hover { background:var(--surface-3); color:var(--text); }
    .seg button.active { background:var(--navy); color:#fff; }
    [data-theme="dark"] .seg button.active { background:var(--gold); color:#101826; }

    .alert { display:flex; align-items:flex-start; gap:11px; border-radius:12px; padding:12px 14px; font-size:.84rem; line-height:1.5; margin-bottom:18px; border:1px solid; animation:fadeUp .3s ease both; }
    .alert i { margin-top:3px; flex-shrink:0; }
    .alert.err { background:var(--red-bg); border-color:var(--red-bd); color:var(--red-ink); }
    .alert.info { background:var(--amber-bg); border-color:var(--amber-bd); color:var(--amber-ink); }

    .field { margin-bottom:18px; }
    .field label { display:block; font-size:.66rem; font-weight:800; letter-spacing:1.3px; text-transform:uppercase; color:var(--muted); margin-bottom:7px; }
    .input-wrap { position:relative; }
    .input-wrap > i.lead { position:absolute; left:15px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:14px; pointer-events:none; transition:.15s; }
    .input-wrap input { width:100%; height:48px; padding:0 14px 0 42px; background:var(--surface-2); border:1.5px solid var(--border); border-radius:12px; font-family:inherit; font-size:.92rem; font-weight:500; color:var(--text); transition:.15s; }
    .input-wrap input.has-toggle { padding-right:48px; }
    .input-wrap input::placeholder { color:var(--muted); font-weight:400; }
    .input-wrap input:hover { border-color:rgba(37,99,235,.35); }
    .input-wrap input:focus { outline:none; border-color:var(--gold); background:var(--surface); box-shadow:0 0 0 3px rgba(240,192,64,.22); }
    .input-wrap input:focus ~ i.lead, .input-wrap:focus-within > i.lead { color:var(--gold-d); }
    .eye { position:absolute; right:6px; top:50%; transform:translateY(-50%); width:36px; height:36px; border:none; background:none; border-radius:9px; color:var(--muted); cursor:pointer; font-size:14px; transition:.15s; }
    .eye:hover { background:var(--surface-3); color:var(--text); }
    .hint { font-size:.74rem; color:var(--muted); margin-top:6px; min-height:1.2em; }
    .hint.warn { color:var(--amber-ink); font-weight:600; }

    .btn { width:100%; height:50px; margin-top:6px; background:var(--gold); color:#101826; border:none; border-radius:12px; font-family:inherit; font-size:.92rem; font-weight:800; letter-spacing:.2px; cursor:pointer; transition:.15s; display:inline-flex; align-items:center; justify-content:center; gap:10px; box-shadow:0 10px 24px rgba(240,192,64,.32); }
    .btn:hover:not(:disabled) { background:#f5cf5e; transform:translateY(-1px); }
    .btn:active:not(:disabled) { transform:scale(.99); }
    .btn:disabled { opacity:.7; cursor:progress; }

    .help { margin-top:22px; padding:14px 16px; border-radius:12px; background:var(--surface); border:1px solid var(--border); display:flex; gap:12px; align-items:flex-start; font-size:.8rem; color:var(--text-2); }
    .help i { color:var(--gold-d); margin-top:3px; }
    .help b { color:var(--text); }

    .form-foot { margin-top:30px; text-align:center; font-size:.72rem; color:var(--muted); line-height:1.7; }
    .form-foot strong { color:var(--text-2); font-weight:600; }

    @keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
    @media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation:none !important; transition:none !important; } }

    @media (max-width:960px) {
      .split { grid-template-columns:1fr; }
      .brand-panel { display:none; }
      .mobile-brand { display:flex; }
      .form-panel { justify-content:flex-start; padding:76px 20px 36px; min-height:100vh; }
    }
  </style>
</head>
<body>

<div class="split">

  <!-- ══════════ Brand panel ══════════ -->
  <section class="brand-panel" aria-hidden="true">
    <div class="ring r1"></div><div class="ring r2"></div>

    <div class="brand-top">
      <div class="brand-row">
        <div class="brand-mark"><i class="fa-solid fa-graduation-cap"></i></div>
        <div>
          <div class="brand-name">Stephen Kanja <span>School</span></div>
          <div class="brand-tag">Primary &amp; Junior School</div>
        </div>
      </div>
    </div>

    <div class="brand-mid">
      <div class="hero-eyebrow">School Portal</div>
      <h2 class="hero-title">Aim <em>Higher</em>, together.</h2>
      <p class="hero-sub">One secure place for learners, teachers and school leadership to follow results, progress and learning.</p>
      <ul class="points">
        <li><span class="pi"><i class="fa-solid fa-chart-line"></i></span> Results and progress, always up to date</li>
        <li><span class="pi"><i class="fa-solid fa-book-open"></i></span> Learning materials in one place</li>
        <li><span class="pi"><i class="fa-solid fa-shield-halved"></i></span> Role-based, secure access</li>
      </ul>
    </div>

    <div class="brand-bot">&copy; <?= date('Y') ?> Stephen Kanja School. All rights reserved.</div>
  </section>

  <!-- ══════════ Form panel ══════════ -->
  <main class="form-panel" id="main-content">
    <button class="theme-btn" id="themeBtn" type="button" onclick="toggleTheme()" aria-label="Toggle dark mode" title="Toggle dark mode"><i class="fa-solid fa-moon"></i></button>

    <div class="form-wrap">

      <div class="mobile-brand">
        <div class="brand-mark"><i class="fa-solid fa-graduation-cap"></i></div>
        <div>
          <div class="brand-name">Stephen Kanja <span style="color:var(--gold-d)">School</span></div>
          <div class="brand-tag">Aim Higher</div>
        </div>
      </div>

      <p class="eyebrow">School Portal</p>
      <h1 class="form-title">Welcome back</h1>
      <p class="form-sub">Sign in to continue to your dashboard.</p>

      <div class="seg" role="group" aria-label="Account type">
        <button type="button" id="toggleStudent" class="active" aria-pressed="true" onclick="setLoginMode('learner')"><i class="fa-solid fa-user-graduate"></i> Learner</button>
        <button type="button" id="toggleStaff" aria-pressed="false" onclick="setLoginMode('staff')"><i class="fa-solid fa-chalkboard-user"></i> Teacher / Staff</button>
      </div>

      <?php if (!empty($notice) && empty($error)): ?>
      <div class="alert info" role="status"><i class="fa-solid fa-clock"></i><span><?= htmlspecialchars($notice) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($error)): ?>
      <div class="alert err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><span><?= htmlspecialchars($error) ?></span></div>
      <?php endif; ?>

      <form action="" method="post" id="loginForm" autocomplete="on">
        <input type="hidden" name="loginMode" id="loginMode" value="<?= $loginMode ?>">

        <div class="field">
          <label for="assessment" id="identifierLabel">Assessment Number</label>
          <div class="input-wrap">
            <i class="fa-solid fa-id-card lead" id="identifierIcon"></i>
            <input type="text" id="assessment" name="AssesmentNumber" placeholder="Enter your assessment number" value="<?= htmlspecialchars($prefill, ENT_QUOTES, 'UTF-8') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required>
          </div>
          <p class="hint" id="identifierHint">Learners: your Assessment Number.</p>
        </div>

        <div class="field">
          <label for="password" id="passwordLabel">Password</label>
          <div class="input-wrap">
            <i class="fa-solid fa-lock lead"></i>
            <input type="password" id="password" name="password" class="has-toggle" placeholder="Enter your password" autocomplete="current-password" required>
            <button type="button" class="eye" id="eyeBtn" aria-label="Show password" title="Show password"><i class="fa-solid fa-eye"></i></button>
          </div>
          <p class="hint" id="passwordHint">Learners: your account password.</p>
        </div>

        <button type="submit" class="btn" id="submitBtn"><span id="submitText">Sign in</span> <i class="fa-solid fa-arrow-right"></i></button>
      </form>

      <div class="help">
        <i class="fa-solid fa-circle-question"></i>
        <div><b>Can't sign in?</b> Check your details and try again, or contact the school office for help.</div>
      </div>

      <div class="form-foot">
        &copy; <?= date('Y') ?> Stephen Kanja School &nbsp;·&nbsp; Designed by <strong>Kelvin Mutinda</strong>
      </div>
    </div>
  </main>
</div>

<script>
  /* ══════════════════ Theme (same key as the dashboards) ══════════════════ */
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

  /* ══════════════════ Learner / staff labels (cosmetic only) ══════════════════
     The server tries Student first, then Teachers, whichever tab is chosen. */
  function setLoginMode(mode) {
    const staff = mode === 'staff';
    document.getElementById('toggleStudent').classList.toggle('active', !staff);
    document.getElementById('toggleStaff').classList.toggle('active', staff);
    document.getElementById('toggleStudent').setAttribute('aria-pressed', String(!staff));
    document.getElementById('toggleStaff').setAttribute('aria-pressed', String(staff));
    document.getElementById('loginMode').value = staff ? 'staff' : 'learner';

    const idInput = document.getElementById('assessment');
    document.getElementById('identifierLabel').textContent = staff ? 'Email Address' : 'Assessment Number';
    idInput.placeholder = staff ? 'Enter your email address' : 'Enter your assessment number';
    idInput.setAttribute('inputmode', staff ? 'email' : 'text');
    document.getElementById('identifierIcon').className = 'fa-solid ' + (staff ? 'fa-envelope' : 'fa-id-card') + ' lead';
    document.getElementById('identifierHint').textContent = staff
      ? 'Teachers / HOI: the email on file with the school.'
      : 'Learners: your Assessment Number.';

    document.getElementById('passwordLabel').textContent = staff ? 'Password' : 'Password';
    document.getElementById('password').placeholder = staff ? 'Enter your Password' : 'Enter your password';
    document.getElementById('passwordHint').textContent = staff
      ? 'Teachers / HOI: your Password.'
      : 'Learners: your account password.';
  }
  setLoginMode('<?= $loginMode ?>');

  /* ══════════════════ Show / hide password ══════════════════ */
  const pw = document.getElementById('password');
  const eyeBtn = document.getElementById('eyeBtn');
  eyeBtn.addEventListener('click', function () {
    const show = pw.type === 'password';
    pw.type = show ? 'text' : 'password';
    eyeBtn.innerHTML = '<i class="fa-solid ' + (show ? 'fa-eye-slash' : 'fa-eye') + '"></i>';
    eyeBtn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    eyeBtn.title = show ? 'Hide password' : 'Show password';
    pw.focus();
  });

  /* ══════════════════ Caps Lock warning ══════════════════ */
  function capsCheck(e) {
    const hint = document.getElementById('passwordHint');
    const on = e.getModifierState && e.getModifierState('CapsLock');
    if (on) { hint.textContent = 'Caps Lock is on.'; hint.classList.add('warn'); }
    else {
      hint.classList.remove('warn');
      setLoginMode(document.getElementById('loginMode').value);
    }
  }
  pw.addEventListener('keyup', capsCheck);
  pw.addEventListener('blur', () => { document.getElementById('passwordHint').classList.remove('warn'); setLoginMode(document.getElementById('loginMode').value); });

  /* ══════════════════ Submit state (prevents double submits) ══════════════════ */
  const form = document.getElementById('loginForm');
  const btn  = document.getElementById('submitBtn');
  form.addEventListener('submit', function () {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Signing in…</span>';
  });
  /* Restore the button if the user comes back with the browser's Back button */
  window.addEventListener('pageshow', function () {
    btn.disabled = false;
    btn.innerHTML = '<span id="submitText">Sign in</span> <i class="fa-solid fa-arrow-right"></i>';
  });

  /* Focus the password when the identifier was kept after a failed attempt */
  (function () {
    const id = document.getElementById('assessment');
    (id.value ? pw : id).focus();
  })();
</script>

</body>
</html>