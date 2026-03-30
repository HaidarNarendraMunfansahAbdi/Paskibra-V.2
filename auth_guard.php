<?php
/**
 * auth_guard.php
 * ============================================================
 * Blok pengaman session — sertakan di AWAL setiap halaman
 * yang memerlukan login.
 *
 * Cara pakai:
 *
 *   // Halaman untuk semua user yang sudah login:
 *   require_once __DIR__ . '/auth_guard.php';
 *   authGuard();
 *
 *   // Halaman khusus admin saja:
 *   require_once __DIR__ . '/auth_guard.php';
 *   authGuard('admin');
 *
 *   // Halaman untuk juri atau admin:
 *   require_once __DIR__ . '/auth_guard.php';
 *   authGuard(['admin', 'juri']);
 *
 * ============================================================
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * authGuard(string|array|null $allowedRoles)
 *
 * Cek apakah user sudah login dan memiliki role yang diizinkan.
 * Jika tidak, redirect ke login.php dengan pesan error.
 *
 * @param string|array|null $allowedRoles  null = semua role boleh masuk
 */
function authGuard(string|array|null $allowedRoles = null): void
{
    // ── 1. Cek sudah login ────────────────────────────────────
    if (empty($_SESSION['id_user'])) {
        $_SESSION['login_error'] = 'Silakan login terlebih dahulu.';
        header('Location: login.php');
        exit;
    }

    // ── 2. Cek timeout sesi (2 jam tidak aktif) ───────────────
    $timeout = 2 * 60 * 60;   // 7200 detik
    if (isset($_SESSION['login_at']) && (time() - $_SESSION['login_at']) > $timeout) {
        session_destroy();
        session_start();
        $_SESSION['login_error'] = 'Sesi Anda telah berakhir. Silakan login kembali.';
        header('Location: login.php');
        exit;
    }
    // Perbarui waktu aktivitas terakhir
    $_SESSION['login_at'] = time();

    // ── 3. Cek role (jika diperlukan) ─────────────────────────
    if ($allowedRoles !== null) {
        $allowed = (array) $allowedRoles;
        if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
            // Tampilkan halaman 403 sederhana — JANGAN redirect ke login
            // agar user yang sudah login tidak bingung
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">'
               . '<title>Akses Ditolak</title>'
               . '<style>body{font-family:sans-serif;background:#0d1b2e;color:#dce8f5;'
               . 'display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;}'
               . 'h1{font-size:3rem;color:#e63946;}p{color:#7a94af;margin:.5rem 0;}'
               . 'a{color:#38bdf8;}</style></head><body>'
               . '<div><h1>403</h1><p>Anda tidak memiliki akses ke halaman ini.</p>'
               . '<p>Role Anda: <strong>' . htmlspecialchars($_SESSION['role'] ?? '—') . '</strong></p>'
               . '<p><a href="javascript:history.back()">← Kembali</a>'
               . ' &nbsp;|&nbsp; <a href="logout.php">Logout</a></p>'
               . '</div></body></html>';
            exit;
        }
    }
}

// ── Helper: dapatkan data user dari session ───────────────────
function currentUser(): array
{
    return [
        'id_user'      => $_SESSION['id_user']      ?? null,
        'username'     => $_SESSION['username']     ?? '',
        'nama_lengkap' => $_SESSION['nama_lengkap'] ?? '',
        'role'         => $_SESSION['role']         ?? '',
        'id_event'     => $_SESSION['id_event']     ?? null,
    ];
}
