<?php
/**
 * Konfigurasi Kasera Pay (https://pay.kasera.id/app/login → Developer).
 * Key dibaca dari environment / config/secrets.php (lihat config/env.php).
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/site.php';

defineIfMissing('KASERA_API_BASE_URL', 'https://pay.kasera.id/v1');
defineIfMissing('KASERA_API_KEY', ddSecret('KASERA_API_KEY'));
defineIfMissing('KASERA_WEBHOOK_SECRET', ddSecret('KASERA_WEBHOOK_SECRET'));
defineIfMissing('USD_TO_IDR_RATE', defined('USD_RATE_FROM_IDR') ? (float)USD_RATE_FROM_IDR : 16000.0);

function defineIfMissing(string $name, $value): void
{
    if (!defined($name)) {
        define($name, $value);
    }
}
