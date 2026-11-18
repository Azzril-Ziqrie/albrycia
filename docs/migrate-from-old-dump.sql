-- Migrasi dari dump lama (albj3628_albrycia (1).sql) ke skema terbaru.
-- Jalankan SETELAH import dump lama. Aman dijalankan ulang (MariaDB; "IF NOT EXISTS").
-- Kalau server pakai MySQL (bukan MariaDB), hapus "IF NOT EXISTS" pada ADD COLUMN
-- dan jalankan sekali saja.

-- 1) Kolom pembayaran Kasera di tabel orders
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `kasera_payment_request_id` varchar(100) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `kasera_checkout_url`       text         DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `kasera_payment_method`     varchar(40)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `kasera_payment_data`       mediumtext   DEFAULT NULL;

-- 2) Tabel baru untuk teks marquee dkk
CREATE TABLE IF NOT EXISTS `site_options` (
  `opt_key`    varchar(60) NOT NULL,
  `opt_value`  mediumtext  NOT NULL,
  `updated_at` timestamp   NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`opt_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
