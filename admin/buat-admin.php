<?php
/**
 * Membuat akun admin di tabel `users` (untuk login toko). HANYA bisa dijalankan dari terminal:
 *   php admin/buat-admin.php email@contoh.com "password-yang-kuat" "Nama Lengkap"
 * Password tidak pernah disimpan di kode maupun ditampilkan.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

[, $email, $password, $fullName] = $argv + [null, null, null, 'albrycia'];
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string)$password) < 12) {
    fwrite(STDERR, "Pemakaian: php admin/buat-admin.php email password(min 12 karakter) [nama]\n");
    exit(1);
}

$pdo->prepare(
    "INSERT INTO users (full_name, email, password, role, is_active) VALUES (:name, :email, :password, 'admin', 1)"
)->execute(['name' => $fullName, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT)]);

echo "Akun admin dibuat untuk {$email}\n";
