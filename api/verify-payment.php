<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://hasumasajes.com');

require_once __DIR__ . '/stripe-config.php';

$session_id = $_GET['session_id'] ?? '';

if (!$session_id || !preg_match('/^cs_[a-zA-Z0-9_]+$/', $session_id)) {
    http_response_code(400);
    exit(json_encode(['paid' => false, 'error' => 'Invalid session ID']));
}

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($session_id));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code(400);
    exit(json_encode(['paid' => false, 'error' => 'Session not found']));
}

$session = json_decode($body, true);
echo json_encode(['paid' => ($session['payment_status'] ?? '') === 'paid']);
