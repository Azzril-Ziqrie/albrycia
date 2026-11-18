<?php
/**
 * Katalog metode bayar Kasera: daftar metode, metode yang aktif di akun,
 * batas nominal, dan arti status transaksi.
 */

require_once __DIR__ . '/kasera_client.php';

const KASERA_METHODS_CACHE_SECONDS = 600;
const KASERA_FAILED_STATUSES = ['expired', 'failed', 'cancelled', 'canceled', 'rejected'];
const KASERA_PAID_STATUSES = ['succeeded', 'paid', 'success', 'settlement', 'completed'];

/** Metode langsung yang ditawarkan (kode → label). Hapus baris untuk menyembunyikan. */
function kaseraPaymentMethods(): array
{
    return [
        'qris'       => 'QRIS',
        'va_bca'     => 'BCA Virtual Account',
        'va_bri'     => 'BRI Virtual Account',
        'va_bni'     => 'BNI Virtual Account',
        'va_mandiri' => 'Mandiri Virtual Account',
        'va_permata' => 'Permata Virtual Account',
        'va_cimb'    => 'CIMB Niaga Virtual Account',
        'va_danamon' => 'Danamon Virtual Account',
        'va_maybank' => 'Maybank Virtual Account',
    ];
}

/** Metode yang benar-benar aktif di akun Kasera (cache 10 menit); gagal → daftar statis. */
function kaseraEnabledMethods(): array
{
    $all   = kaseraPaymentMethods();
    $codes = kaseraEnabledMethodCodes();
    return $codes ? array_intersect_key($all, array_flip($codes)) : $all;
}

function kaseraEnabledMethodCodes(): array
{
    $cacheFile = sys_get_temp_dir() . '/kasera-methods-' . md5(KASERA_API_BASE_URL . KASERA_API_KEY) . '.json';
    if (is_file($cacheFile) && time() - filemtime($cacheFile) < KASERA_METHODS_CACHE_SECONDS) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        return is_array($cached) ? $cached : [];
    }

    try {
        $codes = kaseraFetchEnabledMethodCodes();
        if ($codes) {
            @file_put_contents($cacheFile, json_encode($codes));
        }
        return $codes;
    } catch (Throwable $e) {
        error_log('[kasera] could not load payment methods: ' . $e->getMessage());
        return [];
    }
}

/** Batas nominal (IDR) per metode: [min, max]. */
function kaseraAmountLimits(string $method): array
{
    return $method === 'qris' ? [1000, 10000000] : [10000, 10000000];
}

function kaseraIsPaidStatus(string $status): bool
{
    return in_array(strtolower($status), KASERA_PAID_STATUSES, true);
}
