<?php
/* session_activity.php — tiny endpoint used by the inactivity script.
     ?action=ping                        user is active: refresh the session (JSON reply)
     ?action=logout&reason=timeout|manual   destroy the session and go to the login page */
require_once __DIR__ . '/session_timeout.php';   // runs the expiry check and refreshes last_activity

$action = $_GET['action'] ?? 'ping';

if ($action === 'logout') {
    $timedOut = (($_GET['reason'] ?? '') === 'timeout');
    session_destroy_completely();
    header('Location: ' . SESSION_LOGIN_URL . ($timedOut ? '?timeout=1' : ''));
    exit;
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (empty($_SESSION['loggedin'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'expired' => true]);
    exit;
}

echo json_encode(['ok' => true, 'idleLimit' => SESSION_IDLE_LIMIT]);