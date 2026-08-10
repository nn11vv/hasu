<?php
require_once __DIR__ . '/stripe-config.php';

// Envía un evento a GA4 vía Measurement Protocol (server-side, no depende del navegador
// del cliente ni de que gtag.js haya cargado). Silencioso: nunca debe romper el flujo que
// la llama — quien la use debe envolverla igual en try/catch por si acaso.
function ga4_send_event(string $client_id, string $event_name, array $params): void {
    if (!defined('GA4_MEASUREMENT_ID') || !defined('GA4_API_SECRET')) return;
    if ($client_id === '') return;

    $url = 'https://www.google-analytics.com/mp/collect'
        . '?measurement_id=' . urlencode(GA4_MEASUREMENT_ID)
        . '&api_secret=' . urlencode(GA4_API_SECRET);

    $payload = json_encode([
        'client_id' => $client_id,
        'events' => [[
            'name' => $event_name,
            'params' => $params,
        ]],
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 3,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
