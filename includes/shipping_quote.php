<?php
/**
 * Kuota ongkir bertanda tangan. Endpoint api/calculate-*-shipping.php menandatangani
 * setiap layanan yang dikembalikan; checkout.php hanya menerima ongkir yang tokennya valid,
 * sehingga pembeli tidak bisa mengubah angka ongkir dari browser.
 */

require_once __DIR__ . '/../config/env.php';

const SHIPPING_QUOTE_TTL_SECONDS = 3600;

function shippingQuoteKey(): string
{
    $base = ddSecret('INVOICE_LINK_SECRET');
    if ($base === '') {
        throw new RuntimeException('INVOICE_LINK_SECRET belum diatur di config/secrets.php');
    }
    return hash_hmac('sha256', 'shipping-quote', $base); // pemisahan domain dari tanda tangan invoice
}

function shippingQuoteMac(string $mode, string $destination, string $courier, string $service, int $costIdr, int $expires): string
{
    return hash_hmac('sha256', implode('|', [$mode, $destination, $courier, $service, $costIdr, $expires]), shippingQuoteKey());
}

/** Token "<expires>.<mac>" untuk satu layanan pengiriman. */
function shippingQuoteToken(string $mode, string $destination, string $courier, string $service, int $costIdr): string
{
    $expires = time() + SHIPPING_QUOTE_TTL_SECONDS;
    return $expires . '.' . shippingQuoteMac($mode, $destination, $courier, $service, $costIdr, $expires);
}

/** Ongkir (IDR) bila token valid dan belum kedaluwarsa; null bila tidak. */
function shippingQuoteVerify(string $mode, string $destination, string $courier, string $service, int $costIdr, string $token): ?int
{
    if (!preg_match('/^(\d{1,12})\.([a-f0-9]{64})$/', $token, $m)) {
        return null;
    }
    $expires = (int)$m[1];
    if ($expires < time() || $costIdr < 0) {
        return null;
    }
    try {
        $expected = shippingQuoteMac($mode, $destination, $courier, $service, $costIdr, $expires);
    } catch (RuntimeException $e) {
        return null;
    }
    return hash_equals($expected, $m[2]) ? $costIdr : null;
}
