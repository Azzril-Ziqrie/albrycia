<?php
/**
 * Sinkron & konfirmasi pembayaran Kasera: cek status, verifikasi ke API,
 * tandai lunas, dan kirim invoice.
 */

require_once __DIR__ . '/kasera_orders.php';
require_once __DIR__ . '/order_payment.php';
require_once __DIR__ . '/email_helpers.php';

const KASERA_SYNC_MIN_INTERVAL_SECONDS = 10;

$composerAutoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

/**
 * Cadangan webhook: tanya Kasera status transaksi order ini dan sinkronkan.
 * Dibatasi 1x per 10 detik per order karena endpoint pemanggilnya publik.
 * @return string payment_status order setelah sinkronisasi
 */
function kaseraSyncOrderStatus(PDO $pdo, array $order): string
{
    $current   = strtolower((string)$order['payment_status']);
    $requestId = (string)($order['kasera_payment_request_id'] ?? '');
    if ($current !== 'pending' || $requestId === '' || kaseraSyncIsThrottled((string)$order['order_number'])) {
        return $current;
    }

    try {
        $status = strtolower((string)(kaseraGetTransaction($requestId)['status'] ?? ''));
        if (kaseraIsPaidStatus($status)) {
            kaseraConfirmPaid($pdo, (int)$order['id'], $requestId, 'Payment confirmed via Kasera status check.');
            return 'paid';
        }
        if (in_array($status, KASERA_FAILED_STATUSES, true)) {
            markOrderExpired($pdo, (int)$order['id']);
            return 'expired';
        }
    } catch (Throwable $e) {
        error_log('[kasera] sync failed for order ' . $order['order_number'] . ': ' . $e->getMessage());
    }
    return $current;
}

function kaseraSyncIsThrottled(string $orderNumber): bool
{
    $lock = sys_get_temp_dir() . '/kasera-sync-' . md5($orderNumber);
    $throttled = is_file($lock) && time() - filemtime($lock) < KASERA_SYNC_MIN_INTERVAL_SECONDS;
    if (!$throttled) {
        @touch($lock);
    }
    return $throttled;
}

/**
 * Pastikan klaim "sudah dibayar" benar dengan bertanya langsung ke API Kasera:
 * transaksi itu memang milik order ini, berstatus lunas, dan nominalnya sama dengan tagihan.
 */
function kaseraPaymentMatchesOrder(PDO $pdo, int $orderId, string $requestId): bool
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || $requestId === '' || $requestId !== (string)($order['kasera_payment_request_id'] ?? '')) {
        return false;
    }

    $transaction = kaseraGetTransaction($requestId);
    return kaseraIsPaidStatus((string)($transaction['status'] ?? ''))
        && (int)round((float)($transaction['amount'] ?? 0)) === kaseraAmountIdr($order);
}

/** Tandai lunas (idempotent) dan kirim invoice hanya pada konfirmasi pertama. */
function kaseraConfirmPaid(PDO $pdo, int $orderId, string $reference, string $note): bool
{
    $result = markOrderPaid($pdo, $orderId, 'kasera_pay', $reference, $note);
    if (!$result['already_paid']) {
        kaseraSendInvoiceSafe($pdo, $orderId);
    }
    return !$result['already_paid'];
}

/** Kirim invoice tanpa pernah menggagalkan konfirmasi pembayaran. */
function kaseraSendInvoiceSafe(PDO $pdo, int $orderId): bool
{
    try {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        $sent  = $order && sendPPFInvoiceEmail($pdo, $order);
        if (!$sent) {
            error_log('[kasera] invoice email failed for order id ' . $orderId);
        }
        return (bool)$sent;
    } catch (Throwable $e) {
        error_log('[kasera] invoice email error for order id ' . $orderId . ': ' . $e->getMessage());
        return false;
    }
}
