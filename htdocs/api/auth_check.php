<?php
require __DIR__ . '/../conn.php';

function require_auth(array $allowed_roles = []): array {
    global $conn;

    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!preg_match('/Bearer\s+(\S+)/', $authHeader, $m)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Missing token']);
        exit;
    }

    $token = $m[1];
    $stmt = $conn->prepare("SELECT * FROM api_sessions WHERE token=? AND expires_at > NOW()");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
        exit;
    }

    $session = $result->fetch_assoc();

    if ($allowed_roles && !in_array($session['role'], $allowed_roles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized for this resource']);
        exit;
    }

    return $session; // ['user_id', 'role', 'account_type', ...]
}