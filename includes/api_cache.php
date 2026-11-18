<?php
/**
 * Cache file + rate limit ringan untuk endpoint yang memanggil API berkuota (RajaOngkir).
 * Cache hit tidak memakai kuota dan tidak dihitung rate limit.
 */

const API_CACHE_DIR = 'albrycia-api-cache';

function apiCacheDir(): string
{
    $dir = sys_get_temp_dir() . '/' . API_CACHE_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function apiCachePath(string $key): string
{
    return apiCacheDir() . '/' . hash('sha256', $key) . '.json';
}

/** Isi cache bila masih segar (atau, dengan $allowStale, selama ada). Null bila tidak ada. */
function apiCacheGet(string $key, int $ttlSeconds, bool $allowStale = false): ?string
{
    $path = apiCachePath($key);
    if (!is_file($path)) {
        return null;
    }
    $fresh = time() - (int)filemtime($path) < $ttlSeconds;
    if (!$fresh && !$allowStale) {
        return null;
    }
    $content = @file_get_contents($path);
    return $content === false ? null : $content;
}

function apiCacheSet(string $key, string $content): void
{
    $path = apiCachePath($key);
    $tmp  = $path . '.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $content, LOCK_EX) !== false) {
        @rename($tmp, $path); // atomik: tidak ada pembaca yang melihat file setengah jadi
    }
}

/**
 * Batasi jumlah panggilan KE API per IP dalam satu jendela waktu.
 * Lewat batas → balas 429 lalu berhenti.
 */
function apiRateLimit(string $bucket, int $maxCalls, int $windowSeconds): void
{
    $ip   = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $path = apiCacheDir() . '/rl-' . hash('sha256', $bucket . '|' . $ip);
    $fh   = @fopen($path, 'c+');
    if (!$fh) {
        return; // jangan blokir pembeli kalau penyimpanan bermasalah
    }
    flock($fh, LOCK_EX);
    $state = json_decode((string)stream_get_contents($fh), true);
    $now   = time();
    if (!is_array($state) || $now - (int)($state['start'] ?? 0) >= $windowSeconds) {
        $state = ['start' => $now, 'count' => 0];
    }
    $state['count']++;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($state));
    flock($fh, LOCK_UN);
    fclose($fh);

    if ($state['count'] > $maxCalls) {
        http_response_code(429);
        header('Retry-After: ' . max(1, $windowSeconds - ($now - $state['start'])));
        echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again shortly.']);
        exit;
    }
}
