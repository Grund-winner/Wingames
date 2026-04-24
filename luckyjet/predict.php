<?php
/**
 * DVYS AI - Endpoint de Prediction
 * 
 * Recoit ?b=<auth_token> (hash de l'iframe 1Win),
 * authentifie aupres de l'API 100hp.app, recupere l'historique
 * des crashes, et retourne une prediction via notre algorithme.
 */

header('Content-Type: application/json');

// CORS - Autoriser toutes les origines
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Validation
$b = isset($_GET['b']) ? trim($_GET['b']) : null;
if (!$b || strlen($b) < 20) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid b parameter', 'status' => 'error']);
    exit;
}

// ETAPE 1 : Auth 100hp.app
$ch = curl_init('https://crash-gateway-grm-cr.100hp.app/user/auth');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'auth-token: ' . $b,
        'Content-Type: application/json',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_FOLLOWLOCATION => true,
]);
$authResponse = curl_exec($ch);
$authHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$authError = curl_error($ch);
curl_close($ch);

if ($authError) {
    echo json_encode(['error' => 'Auth service unavailable: ' . $authError, 'status' => 'error']);
    exit;
}

$authData = json_decode($authResponse, true);
if (!$authData || !isset($authData['sessionId'])) {
    echo json_encode(['error' => 'Invalid or expired auth token', 'status' => 'error', 'http' => $authHttpCode]);
    exit;
}

$sessionId  = trim($authData['sessionId'] ?? '');
$customerId = trim($authData['customerId'] ?? '');

if (!$sessionId || !$customerId) {
    echo json_encode(['error' => 'Missing session data', 'status' => 'error']);
    exit;
}

// ETAPE 2 : Historique des crashes
function fetchCrashHistory(string $sessionId, string $customerId): ?array
{
    $url = 'https://crash-gateway-grm-cr.100hp.app/history';
    $headers = [
        'accept: application/json, text/plain, */*',
        'origin: https://1play.gamedev-tech.cc',
        'referer: https://1play.gamedev-tech.cc/',
        'customer-id: ' . $customerId,
        'session-id: ' . $sessionId,
        'user-agent: Mozilla/5.0 (iPhone; CPU iPhone OS 18_5 like Mac OS X)',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) return null;

    $data = json_decode($resp, true);
    if (!is_array($data)) return null;

    $values = [];
    foreach ($data as $item) {
        if (isset($item['finalValues']) && is_array($item['finalValues'])) {
            foreach ($item['finalValues'] as $val) {
                if (is_numeric($val) && $val > 0) {
                    $values[] = (float) $val;
                }
            }
        }
    }

    return count($values) > 0 ? $values : null;
}

$history = fetchCrashHistory($sessionId, $customerId);
if (!$history) {
    echo json_encode(['error' => 'Failed to fetch crash history', 'status' => 'error']);
    exit;
}

// ETAPE 3 : Algorithme DVYS
require_once __DIR__ . '/Predictor.php';

$predictor = new CrashPredictor($history, 1.00, 25.00);
$result = $predictor->predict();

// ETAPE 4 : Reponse JSON
echo json_encode([
    'ai_prediction'  => $result['prediction'],
    'confidence'     => $result['confidence'],
    'signals'        => $result['signals'],
    'last_rounds'    => array_slice(array_reverse($history), 0, 15),
    'total_rounds'   => $result['analysis']['rounds_analyzed'],
    'avg'            => $result['analysis']['avg'],
    'std_dev'        => $result['analysis']['std_dev'],
    'direction'      => $result['analysis']['direction'],
    'status'         => 'ok',
], JSON_UNESCAPED_SLASHES);
