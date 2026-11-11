<?php
// api/auth_helper.php
// Include after conn.php in any protected endpoint, then call
// requireAuth($conn) to get ['role' => ..., 'identifier' => ...] for the
// current bearer token (or have it exit with a 401 JSON error).

function requireAuth($conn) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing or malformed Authorization header']);
        exit;
    }

    $token = mysqli_real_escape_string($conn, trim($m[1]));
    $res = mysqli_query($conn, "SELECT role, identifier FROM api_tokens WHERE token='$token' AND expires_at > NOW() LIMIT 1");

    if (!$res || mysqli_num_rows($res) === 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }

    return mysqli_fetch_assoc($res); // ['role' => ..., 'identifier' => ...]
}

function issueToken($conn, $role, $identifier) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
    $role_esc = mysqli_real_escape_string($conn, $role);
    $id_esc = mysqli_real_escape_string($conn, $identifier);
    mysqli_query($conn, "INSERT INTO api_tokens (role, identifier, token, expires_at) VALUES ('$role_esc','$id_esc','$token','$expires')");
    return $token;
}