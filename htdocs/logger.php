<?php
/**
 * Records this page visit into site_logs and upserts the caller's
 * active_sessions row. Included by settings.php (and can be safely
 * included by any other page you want tracked) via include_once.
 *
 * Helper function names are prefixed with _log_ to avoid colliding with
 * helpers defined later in settings.php (sq, timeAgoS, pageName,
 * deviceIcon, _parseUA) — redeclaring any of those would be a fatal error.
 */

if (!isset($conn) || !$conn) {
    include_once __DIR__ . '/conn.php';
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ─ Make sure the tables this file writes to exist, even if this is the
     first page ever hit (settings.php also creates these, but a visitor
     might land on a different page first). ─ */
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS site_logs (
    id INT AUTO_INCREMENT PRIMARY KEY, session_id VARCHAR(100), ip_address VARCHAR(60),
    page VARCHAR(500), referrer VARCHAR(500), user_agent VARCHAR(500), method VARCHAR(10),
    status_code INT DEFAULT 200, visit_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    country VARCHAR(80), device_type VARCHAR(30), browser VARCHAR(80), os VARCHAR(80), duration_ms INT DEFAULT 0
)");
mysqli_query($conn, "
CREATE TABLE IF NOT EXISTS active_sessions (
    session_id VARCHAR(100) PRIMARY KEY, ip_address VARCHAR(60), current_page VARCHAR(500),
    last_seen DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    user_agent VARCHAR(500), started_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

function _log_browser($ua) {
    if (stripos($ua, 'Edg/') !== false) return 'Edge';
    if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) return 'Opera';
    if (stripos($ua, 'Chrome') !== false) return 'Chrome';
    if (stripos($ua, 'Firefox') !== false) return 'Firefox';
    if (stripos($ua, 'Safari') !== false) return 'Safari';
    return 'Unknown';
}

function _log_os($ua) {
    if (stripos($ua, 'Windows') !== false) return 'Windows';
    if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) return 'macOS';
    if (stripos($ua, 'Android') !== false) return 'Android';
    if (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false || stripos($ua, 'iOS') !== false) return 'iOS';
    if (stripos($ua, 'Linux') !== false) return 'Linux';
    return 'Unknown';
}

function _log_device($ua) {
    if (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false) return 'Tablet';
    if (stripos($ua, 'Mobile') !== false || stripos($ua, 'iPhone') !== false || stripos($ua, 'Android') !== false) return 'Mobile';
    return 'Desktop';
}

$ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
if (strpos($ip, ',') !== false) $ip = trim(explode(',', $ip)[0]);

$ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
$page     = $_SERVER['REQUEST_URI'] ?? '';
$referrer = $_SERVER['HTTP_REFERER'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$sid      = session_id() ?: '';

$browser = _log_browser($ua);
$os      = _log_os($ua);
$device  = _log_device($ua);

$stmt = $conn->prepare(
    "INSERT INTO site_logs (session_id, ip_address, page, referrer, user_agent, method, device_type, browser, os)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
if ($stmt) {
    $stmt->bind_param('sssssssss', $sid, $ip, $page, $referrer, $ua, $method, $device, $browser, $os);
    $stmt->execute();
}

$stmt2 = $conn->prepare(
    "INSERT INTO active_sessions (session_id, ip_address, current_page, user_agent)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE current_page = VALUES(current_page), ip_address = VALUES(ip_address),
                              user_agent = VALUES(user_agent), last_seen = CURRENT_TIMESTAMP"
);
if ($stmt2) {
    $stmt2->bind_param('ssss', $sid, $ip, $page, $ua);
    $stmt2->execute();
}