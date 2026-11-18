<?php
/**
 * Penghubung payment.php ↔ Kasera: handler pilih metode, endpoint status,
 * dan data yang dibutuhkan tampilan.
 */

require_once __DIR__ . '/kasera_sync.php';

const KASERA_OTHER_METHODS = 'checkout';

/** Cetak status order sebagai JSON lalu berhenti. */
function respondWithOrderStatus(PDO $pdo, array $order, bool $syncWithKasera): void
{
    header('Content-Type: application/json; charset=utf-8');
    $status = $syncWithKasera
        ? kaseraSyncOrderStatus($pdo, $order)
        : strtolower((string)$order['payment_status']);
    echo json_encode(['status' => $status]);
    exit;
}

/**
 * Mulai pembayaran dengan metode terpilih. Sukses → redirect (tidak kembali).
 * @return string pesan error untuk ditampilkan
 */
function startKaseraPayment(PDO $pdo, array $order, string $method): string
{
    $isOtherMethods = $method === KASERA_OTHER_METHODS;
    if (!$isOtherMethods && !isset(kaseraPaymentMethods()[$method])) {
        return 'Invalid payment method.';
    }

    try {
        $response = kaseraEnsureTransactionForOrder($pdo, $order, $isOtherMethods ? null : $method);
        $destination = $isOtherMethods
            ? (string)($response['checkout_url'] ?? '')
            : 'payment.php?order=' . urlencode((string)$order['order_number']);
        if ($destination === '') {
            return 'Could not start this payment. Please try again.';
        }
        header('Location: ' . $destination);
        exit;
    } catch (Throwable $e) {
        error_log('[kasera] ' . $method . ' failed for order ' . $order['order_number'] . ': ' . $e->getMessage());
        return $e instanceof RuntimeException
            ? $e->getMessage()
            : 'This payment method is not available right now. Please choose another method.';
    }
}

/**
 * Data tampilan: apakah QR/VA aktif ditampilkan, plus isinya.
 * @return array{show: bool, method: string, label: string, data: array, payment: array, amountIdr: int, expiresAt: string}
 */
function loadKaseraView(array $order, bool $isPaid): array
{
    $method  = (string)($order['kasera_payment_method'] ?? '');
    $data    = kaseraStoredPaymentData($order);
    $methods = kaseraPaymentMethods();

    $show = !$isPaid
        && strtolower((string)$order['payment_status']) === 'pending'
        && isset($methods[$method])
        && !empty($data['payment'])
        && !isset($_GET['change']);

    return [
        'show'      => $show,
        'method'    => $method,
        'label'     => $methods[$method] ?? '',
        'data'      => $data,
        'payment'   => (array)($data['payment'] ?? []),
        'amountIdr' => kaseraAmountIdr($order),
        'expiresAt' => (string)($data['expires_at'] ?? ''),
    ];
}

/** Logo bank dari assets/banks/<kode>.(svg|png|webp); fallback teks. */
function bankLogoHtml(string $code, string $label): string
{
    foreach (['svg', 'png', 'webp'] as $ext) {
        if (is_file(__DIR__ . '/../assets/banks/' . $code . '.' . $ext)) {
            return '<img src="assets/banks/' . htmlspecialchars($code . '.' . $ext) . '" alt="' . htmlspecialchars($label) . '" loading="lazy" class="bank-logo">';
        }
    }
    return '<span>' . htmlspecialchars(trim(str_replace('Virtual Account', '', $label))) . '</span>';
}

/**
 * Pembeli kembali dari halaman Kasera (?kasera_verify=1&order=...).
 * Order lunas (oleh webhook atau cek status) → redirect ke popup sukses.
 * Memakai payment_request_id dari database, bukan dari URL.
 */
function handleKaseraReturn(PDO $pdo): void
{
    if (($_GET['kasera_verify'] ?? '') !== '1') {
        return;
    }
    $orderNumber = trim((string)($_GET['order'] ?? ''));
    if ($orderNumber === '') {
        return;
    }

    $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_number = :n LIMIT 1');
    $stmt->execute(['n' => $orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || kaseraSyncOrderStatus($pdo, $order) !== 'paid') {
        return;
    }

    header('Location: index.php?payment_uploaded=1&invoice_email=sent&order=' . urlencode($orderNumber));
    exit;
}
