<?php
require_once __DIR__ . '/stripe-config.php';

// Envía un evento a GA4 vía Measurement Protocol (server-side, no depende del navegador
// del cliente ni de que gtag.js haya cargado). Silencioso: nunca debe romper el flujo que
// la llama — quien la use debe envolverla igual en try/catch por si acaso.
function ga4_send_event(string $client_id, string $event_name, array $params): void {
    // Debug temporal: log nativo de PHP (va a error_log de PHP, no al archivo custom).
    $debugFlag = file_exists(__DIR__ . '/.debug_ga4');
    if ($debugFlag) error_log('GA4: función invocada, debug_flag=' . ($debugFlag ? 'true' : 'false'));

    if (!defined('GA4_MEASUREMENT_ID') || !defined('GA4_API_SECRET')) {
        if ($debugFlag) error_log('GA4: return temprano — constantes no definidas');
        return;
    }
    if ($client_id === '') {
        if ($debugFlag) error_log('GA4: return temprano — client_id vacío');
        return;
    }

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
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Debug temporal: solo si existe api/.debug_ga4 (creado a mano). No crear ni borrar
    // ese archivo acá — sin él, el comportamiento sigue 100% silencioso y no hace la
    // llamada extra de abajo. La llamada real de arriba (/mp/collect) no se toca.
    if ($debugFlag) {
        // Debug temporal: ver si la llamada real a /mp/collect está fallando a nivel cURL
        // (ej. SSL/cacert.pem sin configurar en PHP de Windows).
        error_log(sprintf(
            'GA4: curl real a /mp/collect -> status=%s curl_errno=%s curl_error=%s',
            $status,
            $curlErrno,
            $curlError
        ));

        $debugUrl = 'https://www.google-analytics.com/debug/mp/collect'
            . '?measurement_id=' . urlencode(GA4_MEASUREMENT_ID)
            . '&api_secret=' . urlencode(GA4_API_SECRET);

        $dch = curl_init($debugUrl);
        curl_setopt_array($dch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 3,
        ]);
        $debugResponse = curl_exec($dch);
        $debugStatus   = curl_getinfo($dch, CURLINFO_HTTP_CODE);
        curl_close($dch);

        $log = sprintf(
            "[%s] payload=%s | real_status=%s | debug_status=%s debug_response=%s\n",
            date('c'),
            $payload,
            $status,
            $debugStatus,
            $debugResponse
        );
        file_put_contents(__DIR__ . '/ga4-debug.log', $log, FILE_APPEND);
    }
}
