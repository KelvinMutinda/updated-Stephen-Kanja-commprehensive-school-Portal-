<?php
// ============================================================
// africa_talking_sms.php
// Minimal Africa's Talking SMS sender (plain cURL, no SDK/Composer —
// consistent with this project's hand-rolled dependency approach).
// ============================================================

require_once __DIR__ . '/at_config.php';

/**
 * Normalize a Kenyan phone number to the +2547XXXXXXXX / +2541XXXXXXXX
 * format Africa's Talking expects. Returns null if it doesn't look
 * like a valid number.
 */
function normalize_kenyan_phone($raw) {
    if ($raw === null) return null;
    $digits = preg_replace('/[^0-9+]/', '', trim((string)$raw));

    if ($digits === '') return null;

    // Already in +254... form
    if (strpos($digits, '+254') === 0 && strlen($digits) === 13) {
        return $digits;
    }
    // 254... form (no plus)
    if (strpos($digits, '254') === 0 && strlen($digits) === 12) {
        return '+' . $digits;
    }
    // Local 07... / 01... form
    if (strpos($digits, '0') === 0 && strlen($digits) === 10) {
        return '+254' . substr($digits, 1);
    }
    // Bare 7XXXXXXXX / 1XXXXXXXX (9 digits, no leading 0)
    if (strlen($digits) === 9 && ($digits[0] === '7' || $digits[0] === '1')) {
        return '+254' . $digits;
    }

    return null; // doesn't match a recognizable Kenyan mobile format
}

/**
 * Send a single SMS via Africa's Talking.
 * Returns ['success' => bool, 'response' => string (raw/decoded info)]
 */
function send_africas_talking_sms($toPhone, $message) {
    $url = AT_SANDBOX
        ? 'https://api.sandbox.africastalking.com/version1/messaging'
        : 'https://api.africastalking.com/version1/messaging';

    $postFields = array(
        'username' => AT_USERNAME,
        'to'       => $toPhone,
        'message'  => $message,
    );
    if (AT_SENDER_ID !== '') {
        $postFields['from'] = AT_SENDER_ID;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
        'apiKey: ' . AT_API_KEY,
    ));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        return array('success' => false, 'response' => 'cURL error: ' . $curlError);
    }

    $decoded = json_decode($responseBody, true);
    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['SMSMessageData']['Recipients'][0]['status'])) {
        $status = $decoded['SMSMessageData']['Recipients'][0]['status'];
        // Africa's Talking reports per-recipient status even on a 2xx HTTP response
        if (strtolower($status) === 'success') {
            return array('success' => true, 'response' => $responseBody);
        }
        return array('success' => false, 'response' => $responseBody);
    }

    return array('success' => false, 'response' => 'HTTP ' . $httpCode . ': ' . $responseBody);
}