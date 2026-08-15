<?php
require_once __DIR__ . '/ga4-helpers.php';

$allowed_origins = ['https://hasumasajes.com', 'http://localhost:8000'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// Allowlist de eventos — evita que este endpoint se use para inyectar cualquier
// nombre/param arbitrario a la propiedad de GA4.
$allowed_events = ['reserva_completada', 'bono_comprado'];

$input     = json_decode(file_get_contents('php://input'), true) ?? [];
$clientId  = trim($input['client_id'] ?? '');
$eventName = trim($input['event'] ?? '');
$value     = $input['value'] ?? null;
$currency  = trim($input['currency'] ?? 'EUR');

if (!$clientId || !in_array($eventName, $allowed_events, true)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'Datos inválidos']));
}

if (file_exists(__DIR__ . '/.debug_ga4')) {
    error_log('GA4: ga4-tracking.php clientId recibido="' . $clientId . '"');
}

try {
    ga4_send_event($clientId, $eventName, [
        'value' => is_numeric($value) ? (float)$value : 0,
        'currency' => $currency,
    ]);
} catch (Throwable $e) {
    // Silencioso a propósito: el tracking nunca debe afectar la respuesta al cliente.
}

echo json_encode(['success' => true]);
