// api/logout.php
<?php
header('Content-Type: application/json');
require __DIR__ . '/../conn.php';
$headers = getallheaders();
if (preg_match('/Bearer\s+(\S+)/', $headers['Authorization'] ?? '', $m)) {
    $stmt = $conn->prepare("DELETE FROM api_sessions WHERE token=?");
    $stmt->bind_param("s", $m[1]);
    $stmt->execute();
}
echo json_encode(['success' => true]);