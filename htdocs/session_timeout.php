<?php
/* ════════════════════════════════════════════════════════════════════
   session_timeout.php — logs people out after a period of inactivity.

   Load it with require_once at the very top of every protected page.
   The easiest way is to load it once from the top of auth_helpers.php:
       require_once __DIR__ . '/session_timeout.php';

   What it does on every request from a logged-in user:
     1. Starts the session with safer cookie settings.
     2. If they have been idle longer than SESSION_IDLE_LIMIT, destroys the
        session and sends them to the login page (or returns JSON 401 for AJAX).
     3. Otherwise refreshes "last activity" and injects a small script that
        warns the user before the logout and logs them out in the browser.
   ════════════════════════════════════════════════════════════════════ */

if (!defined('SESSION_IDLE_LIMIT'))   define('SESSION_IDLE_LIMIT', 30 * 60);      // seconds (30 minutes)
if (!defined('SESSION_LOGIN_URL'))    define('SESSION_LOGIN_URL', '/index.php');
if (!defined('SESSION_ACTIVITY_URL')) define('SESSION_ACTIVITY_URL', '/session_activity.php');

/* True for fetch/AJAX requests, which should get JSON instead of a redirect */
function session_is_ajax() {
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') return true;
    if (isset($_GET['ajax_action'])) return true;
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $ctype  = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    return strpos($ctype, 'application/json') !== false
        || (strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false);
}

/* Wipes the session data and the session cookie */
function session_destroy_completely() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* Called by the login page right after a successful login */
function session_begin_login() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    session_regenerate_id(true);                 // new session id on login (blocks session fixation)
    $_SESSION['last_activity'] = time();
}

function session_expire_now() {
    session_destroy_completely();
    if (session_is_ajax()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'success' => false,
            'expired' => true,
            'error'   => 'Your session expired because of inactivity. Please log in again.',
        ]);
        exit;
    }
    header('Location: ' . SESSION_LOGIN_URL . '?timeout=1');
    exit;
}

/* The browser-side script: idle timer, warning box, keep-alive pings */
function session_timeout_script() {
    $idleMs = (int)(SESSION_IDLE_LIMIT * 1000);
    $warnMs = min(60, (int)floor(SESSION_IDLE_LIMIT / 3)) * 1000;

    $js = <<<'JS'
<script>
(function () {
  if (window.__sktmLoaded) return;
  window.__sktmLoaded = true;

  var IDLE_MS      = __IDLE_MS__;          /* log out after this much inactivity */
  var WARN_MS      = __WARN_MS__;          /* show the warning this long before that */
  var PING_EVERY   = 5 * 60 * 1000;        /* while the user is active, tell the server at most this often */
  var ACTIVITY_URL = __ACTIVITY_URL__;
  var LOGIN_URL    = __LOGIN_URL__;
  var KEY = 'sktm_last_activity', OUT_KEY = 'sktm_logout';

  var lastActivity = Date.now(), lastPing = Date.now(), lastStore = 0;
  var modal = null, countEl = null, leaving = false;

  /* activity is shared between tabs through localStorage */
  function save(t) { try { localStorage.setItem(KEY, String(t)); } catch (e) {} }
  function saved() { try { return Number(localStorage.getItem(KEY)) || 0; } catch (e) { return 0; } }
  function lastSeen() { return Math.max(lastActivity, saved()); }

  function goLogin(timedOut) {
    leaving = true;
    window.location.href = LOGIN_URL + (timedOut ? '?timeout=1' : '');
  }

  function logout(reason) {
    if (leaving) return;
    leaving = true;
    try { localStorage.setItem(OUT_KEY, reason + ':' + Date.now()); } catch (e) {}
    window.location.href = ACTIVITY_URL + '?action=logout&reason=' + reason;
  }

  function ping() {
    lastPing = Date.now();
    return fetch(ACTIVITY_URL + '?action=ping', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { if (r.status === 401) goLogin(true); })
      .catch(function () { /* offline: try again later */ });
  }

  function addStyle() {
    if (document.getElementById('sktm-style')) return;
    var s = document.createElement('style');
    s.id = 'sktm-style';
    s.textContent =
      '.sktm-overlay{position:fixed;top:0;right:0;bottom:0;left:0;z-index:2147483000;background:rgba(10,20,35,.55);display:flex;align-items:center;justify-content:center;padding:16px;font-family:"DM Sans",Arial,sans-serif}' +
      '.sktm-box{background:#fff;border-radius:14px;max-width:380px;width:100%;padding:24px 22px 20px;box-shadow:0 24px 60px rgba(0,0,0,.35);text-align:center;border-top:4px solid #f0c040}' +
      '.sktm-box h3{margin:0 0 8px;font-size:18px;color:#0a2342}' +
      '.sktm-box p{margin:0 0 16px;font-size:14px;line-height:1.5;color:#3a4a5c}' +
      '.sktm-count{font-size:22px;font-weight:700;color:#c62828}' +
      '.sktm-btns{display:flex;gap:10px}' +
      '.sktm-btns button{flex:1;padding:10px 12px;border-radius:9px;font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;border:1px solid transparent}' +
      '.sktm-stay{background:#0f3460;color:#fff}' +
      '.sktm-out{background:#fff;color:#3a4a5c;border-color:#cfd6e0}';
    document.head.appendChild(s);
  }

  function showWarning(msLeft) {
    if (!modal) {
      addStyle();
      modal = document.createElement('div');
      modal.className = 'sktm-overlay';
      modal.setAttribute('role', 'alertdialog');
      modal.setAttribute('aria-modal', 'true');
      modal.innerHTML =
        '<div class="sktm-box"><h3>Still there?</h3>' +
        '<p>You will be logged out in <span class="sktm-count">0:00</span> because of inactivity.</p>' +
        '<div class="sktm-btns"><button type="button" class="sktm-stay">Stay signed in</button>' +
        '<button type="button" class="sktm-out">Log out now</button></div></div>';
      document.body.appendChild(modal);
      countEl = modal.querySelector('.sktm-count');
      modal.querySelector('.sktm-stay').addEventListener('click', stay);
      modal.querySelector('.sktm-out').addEventListener('click', function () { logout('manual'); });
      modal.querySelector('.sktm-stay').focus();
    }
    var s = Math.max(0, Math.ceil(msLeft / 1000));
    countEl.textContent = Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
  }

  function hideWarning() {
    if (modal) { modal.parentNode.removeChild(modal); modal = null; countEl = null; }
  }

  function stay() {
    hideWarning();
    lastActivity = Date.now();
    save(lastActivity);
    ping();
  }

  function onActivity() {
    if (modal) return;                           /* while the warning is open, only its buttons count */
    var now = Date.now();
    lastActivity = now;
    if (now - lastStore > 5000) { lastStore = now; save(now); }
  }

  function tick() {
    if (leaving) return;
    var now  = Date.now();
    var seen = lastSeen();
    var idle = now - seen;

    if (idle >= IDLE_MS) { logout('timeout'); return; }
    if (idle >= IDLE_MS - WARN_MS) { showWarning(IDLE_MS - idle); return; }

    if (modal) hideWarning();                    /* activity in another tab cancelled the warning */
    if (seen > lastPing && now - lastPing >= PING_EVERY) ping();
  }

  ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click', 'wheel'].forEach(function (ev) {
    window.addEventListener(ev, onActivity, { passive: true, capture: true });
  });
  /* logging out in one tab logs out the others */
  window.addEventListener('storage', function (e) {
    if (e.key === OUT_KEY && e.newValue && !leaving) goLogin(e.newValue.indexOf('timeout') === 0);
  });
  /* after sleep or a long time in a background tab, check straight away */
  document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });

  save(lastActivity);
  setInterval(tick, 1000);
})();
</script>
JS;

    return "\n" . str_replace(
        ['__IDLE_MS__', '__WARN_MS__', '__ACTIVITY_URL__', '__LOGIN_URL__'],
        [(string)$idleMs, (string)$warnMs,
         json_encode(SESSION_ACTIVITY_URL, JSON_UNESCAPED_SLASHES),
         json_encode(SESSION_LOGIN_URL, JSON_UNESCAPED_SLASHES)],
        $js
    ) . "\n";
}

/* Adds the script just before </body> of normal HTML pages, so no page needs editing */
function session_timeout_inject() {
    if (session_is_ajax()) return;
    ob_start(function ($html) {
        $pos = strripos($html, '</body>');
        if ($pos === false) return $html;
        foreach (headers_list() as $h) {          // leave PDFs, CSV downloads, etc. alone
            if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) return $html;
        }
        return substr($html, 0, $pos) . session_timeout_script() . substr($html, $pos);
    });
}

function session_timeout_boot() {
    if (session_status() === PHP_SESSION_NONE) {
        // The default PHP cleanup time is 24 minutes, shorter than our 30, so raise it.
        ini_set('session.gc_maxlifetime', (string)(SESSION_IDLE_LIMIT * 4));
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,                 // cookie ends when the browser closes
                'path'     => '/',
                'secure'   => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            session_set_cookie_params(0, '/', '', $secure, true);
        }
        session_start();
    }

    if (empty($_SESSION['loggedin'])) return;     // nobody logged in, nothing to expire

    $now  = time();
    $last = isset($_SESSION['last_activity']) ? (int)$_SESSION['last_activity'] : $now;
    if (($now - $last) > SESSION_IDLE_LIMIT) {
        session_expire_now();                     // redirects or answers JSON, then exits
    }
    $_SESSION['last_activity'] = $now;

    // stop the browser from showing old protected pages after logout (Back button)
    if (!session_is_ajax() && !headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
    session_timeout_inject();
}

session_timeout_boot();