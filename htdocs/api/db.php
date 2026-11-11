<?php
mysqli_report(MYSQLI_REPORT_OFF);
$conn = mysqli_connect('sql104.infinityfree.com', 'if0_40469224', '16tish2005', 'if0_40469224_crudoperation');
if (!$conn) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['status' => 'error', 'message' => 'DB connection failed']));
}