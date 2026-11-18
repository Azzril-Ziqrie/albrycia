<?php
/**
 * Akses invoice PDF yang aman. File di invoices/ tidak boleh diunduh langsung (lihat invoices/.htaccess);
 * unduhan hanya lewat invoice-download.php dengan login admin ATAU tautan bertanda tangan (HMAC)
 * yang kita buat sendiri untuk email pelanggan.
 */

require_once __DIR__ . '/../config/env.php';

function invoiceSignature(string $orderNumber): string
{
    $secret = ddSecret('INVOICE_LINK_SECRET');
    if ($secret === '') {
        throw new RuntimeException('INVOICE_LINK_SECRET belum diatur di config/secrets.php');
    }
    return hash_hmac('sha256', 'invoice:' . $orderNumber, $secret);
}

function invoiceSignatureIsValid(string $orderNumber, string $signature): bool
{
    try {
        return $orderNumber !== '' && hash_equals(invoiceSignature($orderNumber), $signature);
    } catch (RuntimeException $e) {
        return false;
    }
}

/** Tautan unduhan untuk pelanggan (mis. di email). $baseUrl contoh: https://albrycia.com */
function invoiceDownloadUrl(string $baseUrl, string $orderNumber): string
{
    return rtrim($baseUrl, '/') . '/invoice-download.php?order=' . rawurlencode($orderNumber)
        . '&sig=' . invoiceSignature($orderNumber);
}
