<?php
/**
 * Pembaca rahasia (API key, secret) untuk seluruh aplikasi.
 * Urutan: environment variable server → config/secrets.php → $default.
 */

function ddSecret(string $key, string $default = ''): string
{
    static $fileValues = null;

    $fromEnv = getenv($key);
    if ($fromEnv !== false && $fromEnv !== '') {
        return (string)$fromEnv;
    }

    if ($fileValues === null) {
        $path       = __DIR__ . '/secrets.php';
        $loaded     = is_file($path) ? require $path : [];
        $fileValues = is_array($loaded) ? $loaded : [];
    }
    $value = (string)($fileValues[$key] ?? '');
    return $value !== '' ? $value : $default;
}
