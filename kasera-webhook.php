<?php
/**
 * Endpoint webhook Kasera Pay. Daftarkan URL ini di dashboard Kasera
 * (Developer > Webhooks), contoh: https://albrycia.com/kasera-webhook.php
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/kasera_sync.php';

const WEBHOOK_LOG = __DIR__ . '/webhook_debug.log';
const PAID_EVENTS = ['payment.paid', 'payment.success', 'transaction.paid', 'transaction.completed', 'paid'];
const EXPIRED_EVENTS = ['payment.expired', 'transaction.expired', 'expired'];

function respond(int $status, string $body): void
{
    http_response_code($status);
    exit($body);
}

function logWebhook(string $message): void
{
    file_put_contents(WEBHOOK_LOG, date('[Y-m-d H:i:s] ') . $message . PHP_EOL, FILE_APPEND);
}

function headerValue(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$serverKey])) {
        return (string)$_SERVER[$serverKey];
    }
    foreach (function_exists('getallheaders') ? getallheaders() : [] as $key => $value) {
        if (strcasecmp($key, $name) === 0) {
            return (string)$value;
        }
    }
    return '';
}

function hasValidSignature(string $rawBody): bool
{
    $v1 = headerValue('Kasera-Signature-V1');
    if ($v1 !== '') {
        return kaseraVerifyWebhookSignatureV1($rawBody, $v1, KASERA_WEBHOOK_SECRET);
    }
    $legacy = headerValue('Kasera-Signature');
    return $legacy !== '' && kaseraVerifyWebhookSignatureLegacy($rawBody, $legacy, KASERA_WEBHOOK_SECRET);
}

function findOrderId(PDO $pdo, string $orderNumber): int
{
    $stmt = $pdo->prepare('SELECT id FROM orders WHERE order_number = :n LIMIT 1');
    $stmt->execute(['n' => $orderNumber]);
    return (int)$stmt->fetchColumn();
}

$rawBody = (string)file_get_contents('php://input');
if ($rawBody === '') {
    respond(400, 'Empty Payload');
}
if (!hasValidSignature($rawBody)) {
    logWebhook('Signature invalid');
    respond(400, 'Invalid Signature Verification');
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    respond(400, 'Invalid JSON');
}

$event       = $payload['event'] ?? $payload['type'] ?? '';
$data        = $payload['data'] ?? $payload;
$orderNumber = (string)($data['external_id'] ?? $data['merchant_ref'] ?? '');

// Event tes dari dashboard Kasera tidak punya order sungguhan.
if ($orderNumber === '' || str_contains(strtolower($orderNumber), 'test')) {
    respond(200, json_encode(['status' => 'success', 'message' => 'Test event received']));
}

try {
    $status    = strtolower((string)($data['status'] ?? ''));
    $isPaid    = in_array($event, PAID_EVENTS, true) || kaseraIsPaidStatus($status);
    $isExpired = in_array($event, EXPIRED_EVENTS, true) || $status === 'expired';
    $orderId   = ($isPaid || $isExpired) ? findOrderId(db(), $orderNumber) : 0;

    if ($orderId > 0 && $isPaid) {
        // Payload Kasera: data.payment_request_id (data.id dipertahankan sebagai cadangan format lama)
        $requestId = (string)($data['payment_request_id'] ?? $data['id'] ?? '');
        if (!kaseraPaymentMatchesOrder(db(), $orderId, $requestId)) {
            logWebhook('Paid event rejected, API check failed for order ' . $orderNumber);
            respond(400, 'Payment verification failed');
        }
        kaseraConfirmPaid(db(), $orderId, $requestId, 'Payment confirmed via Kasera Webhook');
    } elseif ($orderId > 0) {
        markOrderExpired(db(), $orderId);
    }
    respond(200, json_encode(['status' => 'success', 'message' => 'Webhook processed']));
} catch (Throwable $e) {
    logWebhook('Error: ' . $e->getMessage());
    respond(500, 'Internal Server Error');
}
