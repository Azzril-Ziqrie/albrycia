<?php
/**
 * Pembuatan tagihan Kasera untuk sebuah order: nominal, payload, simpan transaksi.
 */

require_once __DIR__ . '/kasera_methods.php';
require_once __DIR__ . '/currency_helper.php';

const KASERA_EXPIRY_MINUTES = 60;

/** Nominal order dalam IDR bulat. Order USD dikonversi dengan kurs toko. */
function kaseraAmountIdr(array $order): int
{
    $total = (float)($order['grand_total'] ?? 0);
    $isUsd = strtoupper((string)($order['currency'] ?? 'IDR')) === 'USD';
    return (int)round($isUsd ? $total * ddUsdToIdrRate() : $total);
}

/** Tambahkan kolom Kasera ke tabel orders kalau belum ada. */
function kaseraEnsureColumns(PDO $pdo): void
{
    $needed = [
        'kasera_payment_request_id' => 'VARCHAR(100) NULL',
        'kasera_checkout_url'       => 'TEXT NULL',
        'kasera_payment_method'     => 'VARCHAR(40) NULL',
        'kasera_payment_data'       => 'MEDIUMTEXT NULL',
        'payment_reference'         => 'VARCHAR(100) NULL',
        'payment_verified_at'       => 'DATETIME NULL',
    ];
    try {
        $existing = array_column($pdo->query('SHOW COLUMNS FROM orders')->fetchAll(PDO::FETCH_ASSOC), 'Field');
        foreach (array_diff_key($needed, array_flip($existing)) as $column => $definition) {
            $pdo->exec("ALTER TABLE orders ADD COLUMN {$column} {$definition}");
        }
    } catch (Throwable $e) {
        error_log('[kasera] ensure columns failed: ' . $e->getMessage());
    }
}

/**
 * Susun payload payment request. $method (mis. 'qris', 'va_bca') menampilkan
 * metode itu langsung; null = pembeli memilih sendiri di halaman Kasera.
 */
function kaseraBuildPayload(array $order, ?string $method = null): array
{
    $isVirtualAccount = $method !== null && str_starts_with($method, 'va_');
    $payload = [
        'amount'       => kaseraAmountIdr($order),
        'description'  => 'Order ' . $order['order_number'],
        'external_id'  => $order['order_number'],
        'merchant_ref' => $order['order_number'],
        'customer'     => [
            'name'  => $isVirtualAccount ? kaseraAsciiName((string)$order['full_name']) : (string)$order['full_name'],
            'email' => (string)$order['email'],
            'phone' => kaseraNormalizePhoneE164((string)$order['phone']),
        ],
        'return_url'         => SITE_BASE_URL . '/index.php?kasera_verify=1&order=' . urlencode($order['order_number']),
        'expires_in_minutes' => KASERA_EXPIRY_MINUTES,
    ];

    if ($method) {
        $payload['payment_methods'] = [$method];
    } else {
        $payload['checkout'] = ['steps' => ['payment_method', 'payment']];
    }
    return $payload;
}

function kaseraAssertAmountAllowed(array $order, string $method): void
{
    if (!isset(kaseraPaymentMethods()[$method])) {
        throw new RuntimeException('Invalid payment method.');
    }
    [$min, $max] = kaseraAmountLimits($method);
    $amount = kaseraAmountIdr($order);
    if ($amount < $min || $amount > $max) {
        throw new RuntimeException('Amount is outside the allowed range for this payment method.');
    }
}

/**
 * Buat payment request untuk order lalu simpan id, checkout_url, dan data QR/VA.
 * Idempotency-Key per order+metode: memilih metode yang sama dua kali memakai
 * tagihan yang sama, pindah metode membuat tagihan baru.
 */
function kaseraEnsureTransactionForOrder(PDO $pdo, array $order, ?string $method = null): array
{
    if ($method) {
        kaseraAssertAmountAllowed($order, $method);
    }
    kaseraEnsureColumns($pdo);

    $idempotencyKey = 'order-' . $order['order_number'] . ($method ? '-' . $method : '');
    if (strtolower((string)$order['payment_status']) === 'expired') {
        $idempotencyKey .= '-retry-' . time(); // tagihan lama sudah mati; minta tagihan baru
    }
    $response = kaseraCreateTransaction(kaseraBuildPayload($order, $method), $idempotencyKey);

    kaseraSaveTransaction($pdo, (int)$order['id'], $response, $method);
    return $response;
}

function kaseraSaveTransaction(PDO $pdo, int $orderId, array $response, ?string $method): void
{
    $paymentData = $method ? json_encode([
        'payment'      => $response['payment'] ?? null,
        'instructions' => $response['instructions'] ?? null,
        'expires_at'   => $response['expires_at'] ?? null,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

    $pdo->prepare(
        'UPDATE orders
         SET kasera_payment_request_id = :request_id,
             kasera_checkout_url       = :checkout_url,
             kasera_payment_method     = :method,
             kasera_payment_data       = :data,
             payment_provider          = :provider,
             payment_status            = IF(payment_status = \'expired\', \'pending\', payment_status),
             updated_at                = NOW()
         WHERE id = :id'
    )->execute([
        'request_id'   => $response['id'] ?? null,
        'checkout_url' => $response['checkout_url'] ?? null,
        'method'       => $method,
        'data'         => $paymentData,
        'provider'     => 'kasera_pay',
        'id'           => $orderId,
    ]);
}

/** Data QR/VA tersimpan untuk ditampilkan; array kosong kalau belum ada. */
function kaseraStoredPaymentData(array $order): array
{
    $data = json_decode((string)($order['kasera_payment_data'] ?? ''), true);
    return is_array($data) ? $data : [];
}
