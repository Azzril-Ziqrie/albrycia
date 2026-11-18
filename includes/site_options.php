<?php
/**
 * Pengaturan situs berbasis kunci-nilai (tabel site_options, dibuat otomatis saat pertama kali disimpan).
 * Saat ini dipakai untuk teks bergerak (marquee) di beranda.
 */

const MARQUEE_OPTION_KEY = 'marquee';
const MARQUEE_MAX_WORDS = 12;
const MARQUEE_MAX_WORD_LENGTH = 60;
const MARQUEE_MIN_SECONDS = 10;
const MARQUEE_MAX_SECONDS = 120;
const MARQUEE_SEPARATORS = ['✦', '•', '◆', '—', '/', '+', '·'];

function marqueeDefaults(): array
{
    return [
        'enabled'   => true,
        'words'     => ['Chrome State Of Mind', 'Albrycia', 'Fragrance', 'Limited Releases'],
        'separator' => '✦',
        'seconds'   => 34,   // waktu satu putaran penuh: makin besar makin pelan
    ];
}

function siteOptionsEnsureTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS site_options (
            opt_key    VARCHAR(60) NOT NULL PRIMARY KEY,
            opt_value  MEDIUMTEXT NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/** Nilai tersimpan (di-decode dari JSON) atau null bila belum ada / tabel belum dibuat. */
function siteOptionGet(PDO $pdo, string $key): ?array
{
    try {
        $stmt = $pdo->prepare('SELECT opt_value FROM site_options WHERE opt_key = :k LIMIT 1');
        $stmt->execute(['k' => $key]);
        $decoded = json_decode((string)$stmt->fetchColumn(), true);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        return null;   // tabel belum ada → pakai bawaan
    }
}

function siteOptionSet(PDO $pdo, string $key, array $value): void
{
    siteOptionsEnsureTable($pdo);
    $pdo->prepare(
        'INSERT INTO site_options (opt_key, opt_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE opt_value = VALUES(opt_value)'
    )->execute(['k' => $key, 'v' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

/** Pengaturan marquee yang berlaku: bawaan digabung dengan yang tersimpan, selalu dalam bentuk aman. */
function marqueeOptions(PDO $pdo): array
{
    return marqueeNormalize((siteOptionGet($pdo, MARQUEE_OPTION_KEY) ?? []) + marqueeDefaults());
}

/** @return list<string> kata bersih dari teks satu-kata-per-baris (atau array) */
function marqueeParseWords($raw): array
{
    $lines = is_array($raw) ? $raw : preg_split('/\R/u', (string)$raw);
    $words = [];
    foreach ($lines as $line) {
        $word = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$line)) ?? '');
        if ($word !== '') {
            $words[] = mb_substr($word, 0, MARQUEE_MAX_WORD_LENGTH);
        }
    }
    return array_slice($words, 0, MARQUEE_MAX_WORDS);
}

function marqueeNormalize(array $input): array
{
    $defaults  = marqueeDefaults();
    $words     = marqueeParseWords($input['words'] ?? []);
    $separator = (string)($input['separator'] ?? $defaults['separator']);
    $seconds   = (int)($input['seconds'] ?? $defaults['seconds']);

    return [
        'enabled'   => !empty($input['enabled']),
        'words'     => $words ?: $defaults['words'],
        'separator' => in_array($separator, MARQUEE_SEPARATORS, true) ? $separator : $defaults['separator'],
        'seconds'   => max(MARQUEE_MIN_SECONDS, min(MARQUEE_MAX_SECONDS, $seconds)),
    ];
}
