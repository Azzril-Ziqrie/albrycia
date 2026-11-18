<?php
/**
 * Fungsi login admin: sesi terpisah dari toko, CSRF, dan pembatasan percobaan per IP.
 * File ini hanya berisi fungsi dan tidak menampilkan apa pun.
 */

function adminConfig(): array
{
    static $config = null;
    return $config ??= require __DIR__ . '/../config/admin_auth.php';
}

function adminIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** Sesi khusus admin: nama cookie terpisah, HttpOnly, SameSite=Lax. */
function adminStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('ALBADMINSESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => adminIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Sidik jari hash saat ini: kalau password diganti, semua sesi lama otomatis tidak berlaku. */
function adminPasswordVersion(): string
{
    return substr(hash('sha256', (string)adminConfig()['password_hash']), 0, 24);
}

function adminIsConfigured(): bool
{
    return (string)adminConfig()['password_hash'] !== '';
}

function adminVerifyPassword(string $plain): bool
{
    return adminIsConfigured() && password_verify($plain, (string)adminConfig()['password_hash']);
}

function adminIsLoggedIn(): bool
{
    adminStartSession();
    if (empty($_SESSION['admin_ok'])) {
        return false;
    }
    $isExpired = time() - (int)($_SESSION['admin_last'] ?? 0) > (int)adminConfig()['session_ttl'];
    $isStale   = !hash_equals((string)($_SESSION['admin_pw_ver'] ?? ''), adminPasswordVersion());
    if ($isExpired || $isStale) {
        adminLogout();
        adminStartSession();
        return false;
    }
    $_SESSION['admin_last'] = time();
    return true;
}

function adminLoginOk(): void
{
    adminStartSession();
    session_regenerate_id(true);
    $_SESSION['admin_ok']     = true;
    $_SESSION['admin_last']   = time();
    $_SESSION['admin_pw_ver'] = adminPasswordVersion();
    $_SESSION['admin']        = ['id' => 1, 'via' => 'password']; // dibaca approve/reject-payment.php
}

function adminLogout(): void
{
    adminStartSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function adminCsrfToken(): string
{
    adminStartSession();
    return $_SESSION['login_csrf'] ??= bin2hex(random_bytes(16));
}

/* ── pembatasan percobaan login (per IP, disimpan di folder temp) ── */

function adminAttemptFile(): string
{
    return sys_get_temp_dir() . '/albrycia-admin-lock2-' . md5($_SERVER['REMOTE_ADDR'] ?? 'x') . '.json';
}

function adminAttemptState(): array
{
    $file  = adminAttemptFile();
    $state = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($state) ? $state + ['count' => 0, 'locked_until' => 0] : ['count' => 0, 'locked_until' => 0];
}

/** Sisa detik penguncian (0 = tidak terkunci). */
function adminLockedSeconds(): int
{
    return max(0, (int)adminAttemptState()['locked_until'] - time());
}

function adminRegisterFailure(): void
{
    $state = adminAttemptState();
    $state['count']++;
    if ($state['count'] >= (int)adminConfig()['max_attempts']) {
        $state['locked_until'] = time() + (int)adminConfig()['lock_seconds'];
        $state['count'] = 0;
    }
    @file_put_contents(adminAttemptFile(), json_encode($state));
}

function adminClearFailures(): void
{
    @unlink(adminAttemptFile());
}

/** Hanya izinkan redirect ke file .php di folder admin yang sama (cegah open redirect). */
function adminSafeNext(?string $next): string
{
    $next = (string)$next;
    return preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $next) ? $next : 'dashboard.php';
}
