<?php
header('Content-Type: application/json');
// InfinityFree can't run allow-listed CORS config, so handle it here.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
require __DIR__ . '/../conn.php';
function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

// Changed from json_decode(php://input) to $_POST — InfinityFree silently
// drops raw JSON POST bodies before PHP even runs (connection gets closed
// mid-handshake). Form-encoded POST bodies go through fine, so the mobile
// app now sends application/x-www-form-urlencoded instead.
$identifier = trim($_POST['identifier'] ?? '');
$passcode   = trim($_POST['passcode'] ?? '');

if ($identifier === '' || $passcode === '') {
    respond(['success' => false, 'message' => 'Enter your username and password.'], 400);
}
$authenticated = false;
$account_type = null;
$user_id = null;
$role = null;
$displayName = null;
// ── 1) Try Student (Assessment Number + password) ──
$stmt = $conn->prepare("SELECT * FROM `Student` WHERE Assesment=? AND password=?");
$stmt->bind_param("ss", $identifier, $passcode);
$stmt->execute();
$result = $stmt->get_result();
if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $roleRaw = strtolower(trim($row['role']));
    if ($roleRaw === 'user') {
        $authenticated = true; $account_type = 'student'; $role = 'student';
        $user_id = $row['id']; $displayName = $row['FullName'] ?? $identifier;
    } elseif ($roleRaw === 'admin') {
        $authenticated = true; $account_type = 'student'; $role = 'teacher';
        $user_id = $row['id']; $displayName = $row['FullName'] ?? $identifier;
    } elseif ($roleRaw === 'superadmin') {
        $authenticated = true; $account_type = 'student'; $role = 'hoi';
        $user_id = $row['id']; $displayName = $row['FullName'] ?? $identifier;
    } else {
        respond(['success' => false, 'message' => "Account role not recognized. Contact the school office."], 403);
    }
}
// ── 2) If no Student match, try Teachers (Email + TSC Number) ──
if (!$authenticated) {
    $stmt2 = $conn->prepare("SELECT * FROM `Teachers` WHERE email=? AND tscNo=?");
    $stmt2->bind_param("ss", $identifier, $passcode);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    if ($result2 && $result2->num_rows > 0) {
        $row2 = $result2->fetch_assoc();
        $roleNorm = strtolower(trim($row2['role']));
        $authenticated = true;
        $account_type = 'teacher';
        $user_id = $row2['id'];
        $displayName = $row2['FullName'] ?? $row2['email'];
        $role = (strpos($roleNorm, 'head') !== false) ? 'hoi' : 'teacher';
    }
}
if (!$authenticated) {
    respond(['success' => false, 'message' => 'Invalid credentials.'], 401);
}
// ── Issue token ──
$token = bin2hex(random_bytes(32));
$expires = date('Y-m-d H:i:s', strtotime('+30 days'));
$stmt3 = $conn->prepare(
    "INSERT INTO api_sessions (token, account_type, user_id, role, identifier, expires_at)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt3->bind_param("ssisss", $token, $account_type, $user_id, $role, $identifier, $expires);
$stmt3->execute();
respond([
    'success' => true,
    'token'   => $token,
    'role'    => $role,          // 'student' | 'teacher' | 'hoi' — matches ROLE_ROUTES in the app
    'user'    => [
        'id'   => $user_id,
        'name' => $displayName,
    ],
]);