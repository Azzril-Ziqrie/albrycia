<?php
/**
 * Pengaturan login admin (password tunggal). Yang disimpan hanya HASH password
 * (ADMIN_PASSWORD_HASH di config/secrets.php), bukan password aslinya.
 *
 * Ganti password:
 *   php -r 'echo password_hash("PASSWORD_BARU", PASSWORD_DEFAULT), "\n";'
 * lalu tempel hasilnya sebagai nilai ADMIN_PASSWORD_HASH di config/secrets.php.
 */
require_once __DIR__ . '/env.php';

return [
    'password_hash' => ddSecret('ADMIN_PASSWORD_HASH'),
    'session_ttl'   => 8 * 3600,   // login kedaluwarsa setelah 8 jam tanpa aktivitas
    'max_attempts'  => 5,          // salah password sebelum dikunci
    'lock_seconds'  => 15 * 60,    // lama penguncian per IP
];
