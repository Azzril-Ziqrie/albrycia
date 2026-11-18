<?php
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT);
    exit;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line'],
        ], JSON_PRETTY_PRINT);
        exit;
    }
});

$config = require __DIR__ . '/../config/rajaongkir.php';
require_once __DIR__ . '/../includes/api_cache.php';

$search = trim((string) ($_GET['search'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 10);
$offset = (int) ($_GET['offset'] ?? 0);

if ($limit <= 0) {
    $limit = 10;
}

if (mb_strlen($search) < 3) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Search must be at least 3 characters.',
    ], JSON_PRETTY_PRINT);
    exit;
}

$url = rtrim((string) $config['base_url'], '/') .
    '/destination/domestic-destination?search=' . urlencode($search) .
    '&limit=' . $limit .
    '&offset=' . $offset;

$cacheKey = 'dest-dom|' . mb_strtolower($search) . '|' . $limit . '|' . $offset;
$cached   = apiCacheGet($cacheKey, 2592000);
if ($cached !== null) {
    $response = $cached; $httpCode = 200; $curlError = '';
} else {
    apiRateLimit('dest', 120, 3600);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'key: ' . $config['api_key'],
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $apiOk = $response !== false && $httpCode >= 200 && $httpCode < 300 && is_array(json_decode((string)$response, true));
    if ($apiOk) {
        apiCacheSet($cacheKey, (string)$response);
    } elseif (($stale = apiCacheGet($cacheKey, 2592000, true)) !== null) {
        // API gagal / kuota habis: pakai data lama daripada pembeli tidak bisa checkout
        $response = $stale; $httpCode = 200; $curlError = '';
    }
}

if ($response === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to connect to RajaOngkir.',
        'curl_error' => $curlError,
    ], JSON_PRETTY_PRINT);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON response from RajaOngkir.',
        'raw_text' => $response,
    ], JSON_PRETTY_PRINT);
    exit;
}

/*
  Tujuan file ini:
  - menjaga raw response tetap ada
  - frontend checkout.php bisa parsing result.raw.data
*/
echo json_encode([
    'success' => $httpCode >= 200 && $httpCode < 300,
    'http_code' => $httpCode,
    'message' => $data['meta']['message'] ?? ($data['message'] ?? 'Response received'),
    'raw' => $data,
], JSON_PRETTY_PRINT);
