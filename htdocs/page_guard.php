<?php
// ============================================================
// page_guard.php — one include that protects a page.
//
// USAGE (first lines of every protected page, before any output):
//
//   <?php
//   $GUARD_ALLOW = 'staff';                 // who may open this page (see presets below)
//   require_once __DIR__ . '/page_guard.php';
//   include 'conn.php';
//   ...
//
// PRESETS for $GUARD_ALLOW (a string, or an array of account types):
//   'any'      learner, teacher, admin, hoi   (default if you set nothing)
//   'staff'    teacher, admin, hoi
//   'admin'    admin, hoi
//   'hoi'      hoi only
//   'learner'  learner only
//   ['teacher','hoi']   any custom list of account_type values
//
// DO NOT include this file in index.php (the login page) or any public page,
// otherwise visitors will be redirected to the login page forever.
// ============================================================

// Opening this file directly in the browser → nothing to show
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    http_response_code(403);
    exit('Forbidden');
}

// Same inactivity-logout + safer session cookie that index.php and Hoi.php use.
// It must load BEFORE the session starts.
require_once __DIR__ . '/session_timeout.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* ─────────────── Helpers ─────────────── */

/** Web path of the folder this file lives in ('' when the site is at the domain root). */
function guard_web_base() {
    $docRoot  = rtrim(str_replace('\\', '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $guardDir = str_replace('\\', '/', __DIR__);
    if ($docRoot !== '' && strpos($guardDir, $docRoot) === 0) {
        return substr($guardDir, strlen($docRoot));
    }
    return null; // unknown → caller falls back to relative URLs
}

function guard_url($file) {
    $base = guard_web_base();
    return $base === null ? $file : $base . '/' . $file;
}

/** Redirect that still works if some output was already sent. */
function guard_redirect($url) {
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        echo "<meta http-equiv='refresh' content='0;url={$safe}'>";
        echo "<script>window.location.replace(" . json_encode($url) . ");</script>";
    }
    exit;
}

/** Requests made by JavaScript (fetch/XHR) should get an error code, not a login page. */
function guard_is_ajax() {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') return true;
    if (isset($_POST['ajax'])) return true;
    if (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) return true;
    $mode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';
    return ($mode !== '' && $mode !== 'navigate');
}

function guard_deny_ajax($code, $message) {
    http_response_code($code);
    $wantsJson = !empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['error' => $message]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit;
}

/** Where each kind of account belongs. */
function guard_home_for($accountType) {
    switch ($accountType) {
        case 'hoi':     return guard_url('Hoi.php');
        case 'learner': return guard_url('StudentDashboard.php');
        default:        return guard_url('teacherpanel.php'); // teacher, admin
    }
}

function guard_resolve_allowed($allow) {
    $presets = [
        'any'     => ['learner', 'teacher', 'admin', 'hoi'],
        'staff'   => ['teacher', 'admin', 'hoi'],
        'admin'   => ['admin', 'hoi'],
        'hoi'     => ['hoi'],
        'learner' => ['learner'],
    ];
    if (is_array($allow)) {
        return array_map(function ($v) { return strtolower(trim((string)$v)); }, $allow);
    }
    $key = strtolower(trim((string)$allow));
    return isset($presets[$key]) ? $presets[$key] : [$key];
}

/* ─────────────── The check ─────────────── */

function guard_enforce($allow) {

    // Never let the browser (or Back button after logout) show a cached private page
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
    }

    // 1) Must be logged in
    $loggedIn = !empty($_SESSION['loggedin'])
             && !empty($_SESSION['user_id'])
             && !empty($_SESSION['account_type']);

    if (!$loggedIn) {
        if (guard_is_ajax()) guard_deny_ajax(401, 'Your session has expired. Please log in again.');
        guard_redirect(guard_url('index.php'));
    }

    // 2) Must be the right kind of account for this page
    $type    = strtolower((string)$_SESSION['account_type']);
    $allowed = guard_resolve_allowed($allow);

    if (!in_array($type, $allowed, true)) {
        if (guard_is_ajax()) guard_deny_ajax(403, 'You do not have permission to do that.');
        guard_redirect(guard_home_for($type)); // send them to their own dashboard
    }

    // 3) Rotate the session id every 30 minutes
    if (!isset($_SESSION['last_regenerated'])) {
        $_SESSION['last_regenerated'] = time();
    } elseif (time() - $_SESSION['last_regenerated'] > 1800 && !headers_sent()) {
        session_regenerate_id(true);
        $_SESSION['last_regenerated'] = time();
    }
}

guard_enforce(isset($GUARD_ALLOW) ? $GUARD_ALLOW : 'any');