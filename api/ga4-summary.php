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

// ---- GA4 Data API: helpers ----

function ga4_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// Firma un JWT con la private key del Service Account y lo cambia por un access
// token OAuth2. Sin librerías: openssl_sign hace la firma RS256 a mano.
function ga4_get_access_token(): ?string {
    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claim = [
        'iss'   => GA4_SA_CLIENT_EMAIL,
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ];

    $segments = [
        ga4_base64url_encode(json_encode($header)),
        ga4_base64url_encode(json_encode($claim)),
    ];
    $signingInput = implode('.', $segments);

    $signature = '';
    $signed = openssl_sign($signingInput, $signature, GA4_SA_PRIVATE_KEY, 'sha256WithRSAEncryption');
    if (!$signed) return null;

    $segments[] = ga4_base64url_encode($signature);
    $jwt = implode('.', $segments);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return null;
    $data = json_decode($body, true);
    return $data['access_token'] ?? null;
}

// Un solo batchRunReports con los 3 reportes que necesita la card, para gastar
// una sola llamada de cuota/red en vez de tres.
function ga4_fetch_batch(string $accessToken): ?array {
    $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . GA4_PROPERTY_ID . ':batchRunReports';

    $body = [
        'requests' => [
            [
                'dateRanges' => [
                    ['startDate' => '7daysAgo', 'endDate' => 'today', 'name' => 'range7'],
                    ['startDate' => '30daysAgo', 'endDate' => 'today', 'name' => 'range30'],
                ],
                // 'dateRange' es una pseudo-dimensión que la Data API agrega sola
                // cuando hay varios dateRanges nombrados - no se pide explícita en
                // 'dimensions', pero igual aparece en dimensionValues[0] de cada fila.
                'metrics'    => [['name' => 'sessions'], ['name' => 'activeUsers']],
            ],
            [
                'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']],
                'dimensions' => [['name' => 'pagePath']],
                'metrics'    => [['name' => 'screenPageViews']],
                'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                // Pedimos margen de más filas: "/" e "/index.html" se fusionan y
                // "/hasu/" se excluye en ga4_parse_batch, así que 5 no alcanzaría
                // siempre para devolver 5 finales.
                'limit'      => 8,
            ],
            [
                'dateRanges' => [['startDate' => '30daysAgo', 'endDate' => 'today']],
                'dimensions' => [['name' => 'eventName']],
                'metrics'    => [['name' => 'eventCount']],
                'dimensionFilter' => [
                    'filter' => [
                        'fieldName'     => 'eventName',
                        'inListFilter'  => ['values' => ['reserva_completada', 'giftcard_comprada', 'bono_comprado']],
                    ],
                ],
            ],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT    => 15,
    ]);
    $respBody = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) return null;
    return json_decode($respBody, true);
}

function ga4_parse_batch(array $batch): array {
    $reports = $batch['reports'] ?? [];
    $result = [
        'sessions7'  => 0,
        'users7'     => 0,
        'sessions30' => 0,
        'users30'    => 0,
        'topPages'   => [],
        'events'     => [
            'reserva_completada' => 0,
            'giftcard_comprada'  => 0,
            'bono_comprado'      => 0,
        ],
    ];

    $r0 = $reports[0] ?? null;
    foreach (($r0['rows'] ?? []) as $row) {
        $rangeName = $row['dimensionValues'][0]['value'] ?? '';
        $sessions  = (int)($row['metricValues'][0]['value'] ?? 0);
        $users     = (int)($row['metricValues'][1]['value'] ?? 0);
        if ($rangeName === 'range7') {
            $result['sessions7'] = $sessions;
            $result['users7']    = $users;
        } elseif ($rangeName === 'range30') {
            $result['sessions30'] = $sessions;
            $result['users30']    = $users;
        }
    }

    // "/" y "/index.html" son la misma home vista de dos formas -> se fusionan.
    // "/hasu/" es ruido y se descarta directamente.
    $r1 = $reports[1] ?? null;
    $pageViews = [];
    foreach (($r1['rows'] ?? []) as $row) {
        $path  = $row['dimensionValues'][0]['value'] ?? '';
        $views = (int)($row['metricValues'][0]['value'] ?? 0);

        if ($path === '/hasu/') continue;
        if ($path === '/index.html') $path = '/';

        $pageViews[$path] = ($pageViews[$path] ?? 0) + $views;
    }
    arsort($pageViews);
    foreach (array_slice($pageViews, 0, 5, true) as $path => $views) {
        $result['topPages'][] = ['path' => $path, 'views' => $views];
    }

    $r2 = $reports[2] ?? null;
    foreach (($r2['rows'] ?? []) as $row) {
        $name  = $row['dimensionValues'][0]['value'] ?? '';
        $count = (int)($row['metricValues'][0]['value'] ?? 0);
        if (array_key_exists($name, $result['events'])) {
            $result['events'][$name] = $count;
        }
    }

    $conversions30 = $result['events']['reserva_completada']
        + $result['events']['giftcard_comprada']
        + $result['events']['bono_comprado'];
    $result['conversions30'] = $conversions30;
    $result['conversionRate30'] = $result['sessions30'] > 0
        ? round(($conversions30 / $result['sessions30']) * 100, 1)
        : 0;

    return $result;
}

// ---- Cache: evita pegarle a la Data API en cada carga del panel ----
$cacheDir  = __DIR__ . '/cache';
$cacheFile = $cacheDir . '/ga4-summary.json';
$cacheTtl  = 1200; // 20 minutos

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0775, true);
}

$cached = null;
if (is_file($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    $cached = $raw ? json_decode($raw, true) : null;
}

$now = time();
if (is_array($cached) && isset($cached['fetched_at']) && ($now - $cached['fetched_at']) < $cacheTtl) {
    $cached['stale'] = false;
    echo json_encode($cached);
    exit;
}

$accessToken = ga4_get_access_token();
$batch = $accessToken ? ga4_fetch_batch($accessToken) : null;

if ($batch === null) {
    // La Data API falló (cuota, token, timeout, lo que sea): mejor devolver el
    // cache viejo marcado como stale que romper la card en el panel.
    if (is_array($cached)) {
        $cached['stale'] = true;
        echo json_encode($cached);
        exit;
    }
    http_response_code(502);
    exit(json_encode(['error' => 'GA4 API error']));
}

$result = ga4_parse_batch($batch);
$result['fetched_at'] = $now;
$result['stale'] = false;

@file_put_contents($cacheFile, json_encode($result));

echo json_encode($result);
