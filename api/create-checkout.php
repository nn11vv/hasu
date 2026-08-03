<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://hasumasajes.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['error' => 'Method not allowed'])); }

require_once __DIR__ . '/stripe-config.php';

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$service     = substr(strip_tags($input['service']     ?? ''), 0, 200);
$price_cents = intval($input['price_cents'] ?? 0);
$recipient   = substr(strip_tags($input['recipient']   ?? ''), 0, 200);
$sender      = substr(strip_tags($input['sender']      ?? ''), 0, 200);
$message     = substr(strip_tags($input['message']     ?? ''), 0, 500);

if (!$service || $price_cents < 100 || $price_cents > 200000 || !$recipient) {
    http_response_code(400);
    exit(json_encode(['error' => 'Datos inválidos']));
}

$service_name = trim(explode('·', $service)[0] ?? $service);
$description  = 'Para: ' . $recipient . ($sender ? ' · De: ' . $sender : '');

$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'payment_method_types[]'                               => 'card',
        'line_items[0][price_data][currency]'                  => 'eur',
        'line_items[0][price_data][unit_amount]'               => $price_cents,
        'line_items[0][price_data][product_data][name]'        => 'Gift Card: ' . $service_name,
        'line_items[0][price_data][product_data][description]' => $description,
        'line_items[0][quantity]'                              => 1,
        'mode'                                                 => 'payment',
        'success_url'                                          => SITE_URL . '/?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'                                           => SITE_URL . '/#regalo',
        'metadata[service]'                                    => $service,
        'metadata[recipient]'                                  => $recipient,
        'metadata[sender]'                                     => $sender,
    ])
]);

$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    $session = json_decode($body, true);
    echo json_encode(['url' => $session['url']]);
} else {
    http_response_code(500);
    $err = json_decode($body, true)['error']['message'] ?? 'Unknown error';
    echo json_encode(['error' => $err]);
}
