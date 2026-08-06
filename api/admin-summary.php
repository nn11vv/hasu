<?php
require_once __DIR__ . '/stripe-config.php';

$allowed_origins = ['https://hasumasajes.com', 'http://localhost:8000'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ---- Auth: verificar Firebase ID token ----
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/', $authHeader, $m)) {
    http_response_code(401);
    exit(json_encode(['error' => 'Missing token']));
}
$idToken = $m[1];

$ch = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . FIREBASE_WEB_API_KEY);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode(['idToken' => $idToken]),
]);
$authBody = curl_exec($ch);
$authCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$authData = json_decode($authBody, true);
if ($authCode !== 200 || empty($authData['users'][0])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Invalid token']));
}

// ---- Stripe: últimos pagos ----
function stripe_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($body, true)];
}

[$recentCode, $recentData] = stripe_get('https://api.stripe.com/v1/payment_intents?limit=10');
if ($recentCode !== 200) {
    http_response_code(502);
    exit(json_encode(['error' => 'Stripe error']));
}

$recent = [];
foreach (($recentData['data'] ?? []) as $pi) {
    if (($pi['status'] ?? '') !== 'succeeded') continue;
    $recent[] = [
        'amount'      => $pi['amount'] / 100,
        'currency'    => $pi['currency'],
        'created'     => $pi['created'],
        'description' => $pi['description'] ?? '',
    ];
}

// ---- Stripe: resumen del mes actual ----
$monthStart = mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'));

// NOTA: limit=100 sin paginar. Si el volumen mensual de pagos supera 100, habría
// que paginar con starting_after hasta agotar has_more.
[$monthCode, $monthData] = stripe_get(
    'https://api.stripe.com/v1/payment_intents?created[gte]=' . $monthStart . '&limit=100'
);
if ($monthCode !== 200) {
    http_response_code(502);
    exit(json_encode(['error' => 'Stripe error']));
}

$monthlyTotal = 0;
$monthlyCount = 0;
foreach (($monthData['data'] ?? []) as $pi) {
    if (($pi['status'] ?? '') !== 'succeeded') continue;
    $monthlyTotal += $pi['amount'] / 100;
    $monthlyCount++;
}

echo json_encode([
    'recent' => $recent,
    'monthly' => [
        'total' => $monthlyTotal,
        'count' => $monthlyCount,
        'month' => date('Y-m'),
    ],
]);
