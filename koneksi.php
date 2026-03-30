<?php
/**
 * koneksi.php
 * ============================================================
 * Konfigurasi koneksi database PDO untuk db_paskibra_saas
 * ============================================================
 * Cara pakai di file lain:
 *   require_once __DIR__ . '/koneksi.php';
 *   // $pdo sudah tersedia sebagai objek PDO
 * ============================================================
 */

// ── Konfigurasi Database ─────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_PORT',    '3306');
define('DB_NAME',    'db_paskibra_saas');
define('DB_USER',    'root');          // Ganti sesuai user MySQL Anda
define('DB_PASS',    '');              // Ganti sesuai password MySQL Anda
define('DB_CHARSET', 'utf8mb4');

// ── DSN ──────────────────────────────────────────────────────
$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
);

// ── Opsi PDO ─────────────────────────────────────────────────
$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'"
];

// ── Inisialisasi Koneksi ─────────────────────────────────────
try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $e) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'code'    => 503,
        'message' => 'Koneksi database gagal. Silakan coba beberapa saat lagi.'
        // 'debug' => $e->getMessage() // Aktifkan hanya saat development
    ]);
    exit;
}
