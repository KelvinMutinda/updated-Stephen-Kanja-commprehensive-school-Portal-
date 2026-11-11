<?php
header('Content-Type: application/json');
require __DIR__ . '/../auth_check.php';
$session = require_auth();
echo json_encode([
    'success' => true,
    'role'    => $session['role'],
    'user'    => [
        'id'   => $session['user_id'],
        'name' => $session['identifier'], // swap for a real display name lookup if you store one
    ],
]);