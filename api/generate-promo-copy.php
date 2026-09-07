<?php
require_once __DIR__ . '/stripe-config.php';

// Modelo barato a propósito: mejorar una línea de marketing no necesita más, y este
// endpoint se paga por llamada.
define('CLAUDE_MODEL', 'claude-haiku-4-5-20251001');
// La respuesta esperada es UNA línea corta. El tope está para acotar el gasto si el
// modelo se va de tema, no porque haga falta tanto.
define('CLAUDE_MAX_TOKENS', 300);
// Tope de entrada. Cecilia escribe títulos de promo, no párrafos: sin esto, un pegado
// accidental de texto largo se convierte en una llamada cara.
define('PROMO_TEXT_MAX_LEN', 500);

$allowed_origins = ['https://hasumasajes.com', 'http://localhost:8000'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// ---- Auth: verificar Firebase ID token ----
// Mismo bloque que admin-summary.php. Acá no es solo privacidad: cada llamada que pasa
// de este punto cuesta dinero, así que el endpoint no puede quedar abierto.
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/', $authHeader, $m)) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'error' => 'Missing token']));
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
    exit(json_encode(['success' => false, 'error' => 'Invalid token']));
}

// ---- Entrada ----
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$text  = trim($input['text'] ?? '');

if ($text === '') {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'Falta el texto de la promoción']));
}
if (mb_strlen($text) > PROMO_TEXT_MAX_LEN) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'El texto es demasiado largo (máx. ' . PROMO_TEXT_MAX_LEN . ' caracteres)']));
}

// La constante vive en stripe-config.php, que NO está en git: cada entorno tiene su
// copia. Sin este guard, un deploy donde falte la clave sale como un 500 de PHP sin
// explicación en vez de un mensaje que diga qué configurar.
if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '') {
    http_response_code(500);
    exit(json_encode(['success' => false, 'error' => 'Falta configurar ANTHROPIC_API_KEY en el servidor']));
}

// ---- Claude: mejorar el texto ----
$system = <<<TXT
Sos redactor de textos breves para Hasu Masajes, un centro de masajes y bienestar en Mutxamel, Alicante.

Recibís el texto crudo de una promoción y devolvés una versión mejorada.

Reglas:
- Tono cálido y profesional, cercano pero no informal de más.
- Agregá 1 o 2 emojis relevantes solo si suman; si no quedan bien, ninguno.
- Una sola línea, lo más corta posible (idealmente menos de 100 caracteres).
- No inventes precios, fechas, duraciones, descuentos ni datos que no estén en el texto original.
- Respondé únicamente con el texto mejorado, sin comillas, sin explicaciones y sin alternativas.
TXT;

$payload = json_encode([
    'model'      => CLAUDE_MODEL,
    'max_tokens' => CLAUDE_MAX_TOKENS,
    'system'     => $system,
    'messages'   => [
        ['role' => 'user', 'content' => $text],
    ],
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 30,
]);
$body     = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

// Falla de red / DNS / SSL: no hay respuesta HTTP que interpretar.
if ($body === false) {
    http_response_code(502);
    exit(json_encode(['success' => false, 'error' => 'No se pudo contactar con la API de Claude: ' . $curlErr]));
}

$data = json_decode($body, true);

if ($status !== 200) {
    // La API devuelve {"type":"error","error":{"type":"...","message":"..."}}. Se pasa el
    // mensaje tal cual para que el panel muestre algo accionable (rate_limit_error,
    // authentication_error, etc.) en vez de un "error" genérico.
    $apiType = $data['error']['type'] ?? '';
    $apiMsg  = $data['error']['message'] ?? 'Error desconocido de la API';
    $friendly = [
        'rate_limit_error'     => 'Se alcanzó el límite de uso de la API. Probá de nuevo en un minuto.',
        'authentication_error' => 'La clave de la API de Claude no es válida.',
        'overloaded_error'     => 'La API de Claude está saturada. Probá de nuevo en unos segundos.',
    ];
    http_response_code(502);
    exit(json_encode([
        'success' => false,
        'error'   => $friendly[$apiType] ?? $apiMsg,
    ], JSON_UNESCAPED_UNICODE));
}

// content es un array de bloques; solo interesan los de tipo "text".
$copy = '';
foreach (($data['content'] ?? []) as $block) {
    if (($block['type'] ?? '') === 'text') {
        $copy .= $block['text'];
    }
}
$copy = trim($copy);

if ($copy === '') {
    http_response_code(502);
    exit(json_encode(['success' => false, 'error' => 'La API no devolvió texto']));
}

echo json_encode(['success' => true, 'copy' => $copy], JSON_UNESCAPED_UNICODE);
