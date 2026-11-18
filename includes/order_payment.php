<?php
declare(strict_types=1);

/**
 * Tandai order LUNAS secara atomik dan idempotent: kurangi stok, set status
 * paid/processing, dan catat di order_status_logs.
 *
 * Beda dengan admin/approve-payment.php: kalau stok tidak cukup, order TETAP
 * lunas (uang sudah masuk) dan peringatannya dicatat di log untuk ditangani admin.
 *
 * @return array{already_paid: bool, warnings: string[]}
 */
function markOrderPaid(PDO $pdo, int $orderId, string $provider, ?string $reference, string $logNote): array
{
    $pdo->beginTransaction();
    try {
        $order = lockOrder($pdo, $orderId);
        if (strtolower((string)$order['payment_status']) === 'paid') {
            $pdo->rollBack();
            return ['already_paid' => true, 'warnings' => []];
        }

        $warnings = deductStockForOrder($pdo, $orderId);
        setOrderPaid($pdo, $orderId, $provider, $reference);
        logPaymentPaid($pdo, $orderId, $logNote, $warnings);

        $pdo->commit();
        return ['already_paid' => false, 'warnings' => $warnings];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function lockOrder(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1 FOR UPDATE');
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Order not found.');
    }
    return $order;
}

/** @return string[] peringatan untuk item yang stoknya tidak cukup */
function deductStockForOrder(PDO $pdo, int $orderId): array
{
    $items = $pdo->prepare('SELECT * FROM order_items WHERE order_id = :oid ORDER BY id ASC');
    $items->execute(['oid' => $orderId]);

    $deduct = $pdo->prepare(
        'UPDATE product_sizes SET stock = stock - :qty_update, updated_at = NOW()
         WHERE id = :psid AND is_active = 1 AND stock >= :qty_check'
    );

    $warnings = [];
    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $quantity = (int)$item['quantity'];
        $deduct->execute(['qty_update' => $quantity, 'qty_check' => $quantity, 'psid' => (int)$item['product_size_id']]);
        if ($deduct->rowCount() === 0) {
            $warnings[] = 'Stock not enough for ' . $item['product_name'] . ' size ' . ($item['size_name'] ?? '-') . ' (not deducted)';
        }
    }
    return $warnings;
}

function setOrderPaid(PDO $pdo, int $orderId, string $provider, ?string $reference): void
{
    $pdo->prepare(
        "UPDATE orders
         SET payment_status = 'paid', fulfillment_status = 'processing',
             payment_provider = :provider, payment_reference = :reference,
             payment_verified_at = NOW(), updated_at = NOW()
         WHERE id = :id"
    )->execute(['provider' => $provider, 'reference' => $reference, 'id' => $orderId]);
}

function logPaymentPaid(PDO $pdo, int $orderId, string $note, array $warnings): void
{
    $fullNote = $note . ($warnings ? ' | WARNING: ' . implode('; ', $warnings) : '');
    $pdo->prepare(
        'INSERT INTO order_status_logs (order_id, status_type, status_value, notes)
         VALUES (:oid, :type, :value, :notes)'
    )->execute(['oid' => $orderId, 'type' => 'payment', 'value' => 'paid', 'notes' => $fullNote]);
}

/** Tandai order yang masih pending sebagai kedaluwarsa (tidak menyentuh order yang sudah lunas). */
function markOrderExpired(PDO $pdo, int $orderId): void
{
    $pdo->prepare("UPDATE orders SET payment_status = 'expired', updated_at = NOW() WHERE id = :id AND payment_status = 'pending'")
        ->execute(['id' => $orderId]);
}
