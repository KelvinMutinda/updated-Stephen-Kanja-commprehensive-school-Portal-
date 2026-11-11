<?php
// ============================================================
// at_config.php — Africa's Talking credentials
// Fill these in before using send_results_sms.php.
// Get your username + API key from https://account.africastalking.com
// ============================================================

// Your Africa's Talking application username.
// Use 'sandbox' while testing with the sandbox app.
define('AT_USERNAME', 'your_username_here');

// Your Africa's Talking API key (Settings > API Key in the dashboard).
define('AT_API_KEY', 'your_api_key_here');

// Optional: an approved Sender ID / shortcode. Leave blank ('') to use
// the default Africa's Talking sandbox/shared number.
define('AT_SENDER_ID', '');

// true while testing against the sandbox app (no real SMS sent, free,
// numbers must be registered as test recipients in the AT dashboard).
// Set to false once you're using a live/production AT app with real credit.
define('AT_SANDBOX', true);