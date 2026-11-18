<?php
/**
 * Klien HTTP Kasera Pay + verifikasi signature webhook.
 * Docs: https://pay.kasera.id/docs
 */

require_once __DIR__ . '/../config/kasera.php';

class KaseraApiException extends Exception
{
    public array $errorData = [];
    public int $httpStatus = 0;
}

function kaseraApiRequest(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
{
    [$rawResponse, $httpStatus] = kaseraSendHttp($method, $path, $body, $idempotencyKey);
    return kaseraDecodeResponse($rawResponse, $httpStatus);
}

function kaseraSendHttp(string $method, string $path, ?array $body, ?string $idempotencyKey): array
{
    $headers = ['Authorization: Bearer ' . KASERA_API_KEY, 'Content-Type: application/json'];
    if ($idempotencyKey) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init(rtrim(KASERA_API_BASE_URL, '/') . '/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $raw    = curl_exec($ch);
    $error  = curl_errno($ch) ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error !== null) {
        throw new KaseraApiException('Kasera Pay connection error: ' . $error);
    }
    return [(string)$raw, $status];
}

function kaseraDecodeResponse(string $raw, int $httpStatus): array
{
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw kaseraException('Kasera Pay returned an invalid response (HTTP ' . $httpStatus . ').', $httpStatus);
    }
    if ($httpStatus >= 400) {
        $message = $decoded['message'] ?? ($decoded['error']['message'] ?? 'Kasera Pay request failed.');
        throw kaseraException('Kasera Pay error (' . $httpStatus . '): ' . $message, $httpStatus, $decoded);
    }
    return $decoded;
}

function kaseraException(string $message, int $httpStatus, array $errorData = []): KaseraApiException
{
    $e = new KaseraApiException($message);
    $e->httpStatus = $httpStatus;
    $e->errorData  = $errorData;
    return $e;
}

/** POST /v1/transactions */
function kaseraCreateTransaction(array $payload, string $idempotencyKey): array
{
    return kaseraApiRequest('POST', '/transactions', $payload, $idempotencyKey);
}

/** GET /v1/transactions/{id} */
function kaseraGetTransaction(string $paymentRequestId): array
{
    return kaseraApiRequest('GET', '/transactions/' . rawurlencode($paymentRequestId));
}

/** GET /v1/payment_methods → daftar kode metode yang aktif di akun. */
function kaseraFetchEnabledMethodCodes(): array
{
    $response = kaseraApiRequest('GET', '/payment_methods');
    $codes    = array_map(fn($method) => $method['code'] ?? null, $response['data'] ?? []);
    return array_values(array_filter($codes));
}

/**
 * Verifikasi header Kasera-Signature-V1: "t=<unix>,v1=<hex>[,v1=<hex>]".
 * v1 = HMAC-SHA256(secret, "<t>.<rawBody>"). Menolak selisih waktu > toleransi
 * dan menerima kalau salah satu v1 cocok (masa tenggang rotasi secret).
 */
function kaseraVerifyWebhookSignatureV1(string $rawBody, string $header, string $secret, int $toleranceSeconds = 300): bool
{
    [$timestamp, $signatures] = kaseraParseSignatureHeader($header);
    if ($timestamp === null || !$signatures) {
        return false;
    }
    if (abs(time() - $timestamp) > $toleranceSeconds) {
        return false;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}

function kaseraParseSignatureHeader(string $header): array
{
    $timestamp  = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $part = trim($part);
        if (str_starts_with($part, 't=')) {
            $timestamp = (int)substr($part, 2);
        } elseif (str_starts_with($part, 'v1=')) {
            $signatures[] = substr($part, 3);
        }
    }
    return [$timestamp, $signatures];
}

/** Header lama (deprecated di sisi Kasera): HMAC-SHA256 polos atas raw body. */
function kaseraVerifyWebhookSignatureLegacy(string $rawBody, string $header, string $secret): bool
{
    return hash_equals(hash_hmac('sha256', $rawBody, $secret), trim($header));
}

/** Normalisasi nomor telepon Indonesia ke E.164 (+62...). */
function kaseraNormalizePhoneE164(string $phone, string $dialCode = '62'): string
{
    $digits = preg_replace('/[^\d+]/', '', trim($phone)) ?? '';
    if ($digits === '')                       return '';
    if ($digits[0] === '+')                   return $digits;
    if (str_starts_with($digits, '0'))        return '+' . $dialCode . substr($digits, 1);
    if (str_starts_with($digits, $dialCode))  return '+' . $digits;
    return '+' . $dialCode . $digits;
}

/** customer.name VA: maks 30 karakter ASCII, hanya [A-Za-z0-9 .,'-]. */
function kaseraAsciiName(string $name): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
    $ascii = preg_replace("/[^A-Za-z0-9 .,'-]/", '', (string)$ascii) ?? '';
    $ascii = trim(substr(trim($ascii), 0, 30));
    return $ascii !== '' ? $ascii : 'Customer';
}
