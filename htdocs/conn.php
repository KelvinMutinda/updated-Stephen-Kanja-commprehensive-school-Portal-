<?php
mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_connect(
    getenv('DB_HOST') ?: 'sql104.infinityfree.com',
    getenv('DB_USER') ?: 'if0_40469224',
    getenv('DB_PASS') ?: '16tish2005',
    getenv('DB_NAME') ?: 'if0_40469224_crudoperation'
);

if (!$conn) {
    error_log('DB connection failed: ' . mysqli_connect_error());
    http_response_code(500);
    die('<div style="font-family:sans-serif;max-width:480px;margin:4rem auto;padding:1.5rem 1.8rem;border-radius:10px;background:#fff0f0;border-left:4px solid #e24b4a;color:#c0392b">
        <strong>We could not connect to the database.</strong><br>
        Please try again in a moment. If this keeps happening, contact the school office.
    </div>');
}