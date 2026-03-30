<?php
/**
 * setup_users.php
 * ============================================================
 * Jalankan SEKALI untuk membuat tabel & insert user awal.
 * Hapus atau rename file ini setelah selesai!
 *   mv setup_users.php setup_users.php.bak
 * ============================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/koneksi.php';

// ════════════════════════════════════════════════════════════════
//  LANGKAH 1 — Deteksi nama kolom password di tabel yang mungkin
//               sudah ada. Ini HARUS dilakukan SEBELUM CREATE TABLE
//               agar tidak hardcode 'password'.
// ════════════════════════════════════════════════════════════════

// Override manual — isi jika deteksi otomatis masih salah:
//   $passwordCol = 'password_hash';
$passwordCol = null;

// Cek apakah tabel sudah ada
$tableExists = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'tabel_users'"
)->fetchColumn() > 0;

if ($tableExists) {
    // Tabel sudah ada → baca nama kolom asli dari DB
    $colRows  = $pdo->query("SHOW COLUMNS FROM `tabel_users`")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_column($colRows, 'Field');

    // Kandidat nama kolom password — urutan prioritas
    foreach (['password','password_hash','pwd','pass',
              'kata_sandi','passwd','hash_password','user_password'] as $c) {
        if (in_array($c, $colNames, true)) {
            $passwordCol = $c;
            break;
        }
    }

    if (!$passwordCol) {
        // Tidak ada kandidat cocok → tampilkan semua kolom + panduan
        echo "<pre style='background:#2b0d0d;color:#ffaaaa;padding:1.2rem;font-family:monospace;border-radius:8px;'>\n";
        echo "❌ Kolom password TIDAK ditemukan secara otomatis.\n\n";
        echo "Kolom yang tersedia di tabel_users:\n";
        foreach ($colNames as $n) { echo "  - {$n}\n"; }
        echo "\n";
        echo "Solusi: edit baris ini di setup_users.php (sekitar baris 24):\n";
        echo "  \$passwordCol = null;\n";
        echo "Ubah menjadi (sesuaikan dengan nama kolom di atas):\n";
        echo "  \$passwordCol = 'NAMA_KOLOM_ANDA';\n";
        echo "</pre>";
        exit;
    }
} else {
    // Tabel belum ada → kita yang buat, pakai nama 'password'
    $passwordCol = 'password';
}

// ════════════════════════════════════════════════════════════════
//  LANGKAH 2 — Buat tabel jika belum ada
//               (gunakan $passwordCol yang sudah diketahui)
// ════════════════════════════════════════════════════════════════
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `tabel_users` (
      `id_user`        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
      `username`       VARCHAR(60)   NOT NULL,
      `{$passwordCol}` VARCHAR(255)  NOT NULL COMMENT 'BCrypt — jangan simpan plaintext',
      `nama_lengkap`   VARCHAR(255)  NOT NULL,
      `role`           ENUM('admin','juri') NOT NULL DEFAULT 'juri',
      `id_event`       INT UNSIGNED      NULL DEFAULT NULL,
      `aktif`          TINYINT(1)    NOT NULL DEFAULT 1,
      `last_login`     DATETIME          NULL DEFAULT NULL,
      `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_user`),
      UNIQUE KEY `uq_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

$users = [
    [
        'username'     => 'admin',
        'password'     => 'admin123',   // ← ganti password ini
        'nama_lengkap' => 'Administrator',
        'role'         => 'admin',
        'id_event'     => null,
    ],
    [
        'username'     => 'juri1',
        'password'     => 'juri123',    // ← ganti password ini
        'nama_lengkap' => 'Juri Satu',
        'role'         => 'juri',
        'id_event'     => 1,
    ],
    [
        'username'     => 'juri2',
        'password'     => 'juri456',    // ← ganti password ini
        'nama_lengkap' => 'Juri Dua',
        'role'         => 'juri',
        'id_event'     => 1,
    ],
];

// ════════════════════════════════════════════════════════════════
//  LANGKAH 3 — INSERT user awal menggunakan $passwordCol
//               yang sudah dideteksi di Langkah 1
// ════════════════════════════════════════════════════════════════
echo "<pre style='font-family:monospace;background:#0d1b2e;color:#dce8f5;padding:1.2rem;border-radius:8px;'>\n";
echo "=== setup_users.php ===\n";
echo "Kolom password digunakan: <strong style='color:#38bdf8'>{$passwordCol}</strong>\n\n";

$stmt = $pdo->prepare(
    "INSERT INTO tabel_users (username, `{$passwordCol}`, nama_lengkap, role, id_event)
     VALUES (:u, :p, :n, :r, :e)
     ON DUPLICATE KEY UPDATE
       `{$passwordCol}` = VALUES(`{$passwordCol}`),
       nama_lengkap     = VALUES(nama_lengkap),
       role             = VALUES(role)"
);

foreach ($users as $u) {
    $hash = password_hash($u['password'], PASSWORD_BCRYPT);
    $stmt->execute([
        ':u' => $u['username'],
        ':p' => $hash,
        ':n' => $u['nama_lengkap'],
        ':r' => $u['role'],
        ':e' => $u['id_event'],
    ]);
    // Tampilkan hash untuk verifikasi — JANGAN log di produksi
    echo '  ✓ ' . str_pad($u['role'], 6) . '  username: ' . str_pad($u['username'], 10) . '  hash: ' . substr($hash, 0, 30) . "...\n";
}

echo "\nSelesai! Segera rename/hapus file ini:\n";
echo "  mv setup_users.php setup_users.php.bak\n";
echo "</pre>";
