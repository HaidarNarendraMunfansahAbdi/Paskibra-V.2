<?php
/**
 * api_auth.php
 * ============================================================
 * Guard khusus untuk endpoint API (fetch/AJAX).
 *
 * TIDAK boleh ada redirect atau output HTML di sini.
 * Jika session tidak valid → kembalikan JSON 401/403 lalu exit.
 *
 * Cara pakai (di baris paling atas endpoint API):
 *
 *   require_once __DIR__ . '/api_auth.php';
 *   api_guard();               // semua role yang sudah login
 *   api_guard('admin');        // hanya admin
 *   api_guard(['admin','juri']);// admin atau juri
 * ============================================================
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Jangan kirim cookie baru — baca sesi yang sudah ada saja
    session_start();
}

/**
 * api_guard(string|array|null $roles)
 *
 * Cek session. Jika tidak valid, output JSON dan exit.
 * TIDAK pernah redirect ke HTML.
 */
function api_guard(string|array|null $roles = null): void
{
    // ── 1. Belum login ────────────────────────────────────────
    if (empty($_SESSION['id_user'])) {
        http_response_code(401);
        echo json_encode([
            'status'  => 'error',
            'code'    => 401,
            'message' => 'Unauthorized. Silakan login terlebih dahulu.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── 2. Session timeout (2 jam) ────────────────────────────
    $timeout = 2 * 60 * 60;
    if (isset($_SESSION['login_at']) && (time() - $_SESSION['login_at']) > $timeout) {
        session_destroy();
        http_response_code(401);
        echo json_encode([
            'status'  => 'error',
            'code'    => 401,
            'message' => 'Sesi telah berakhir. Silakan login kembali.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $_SESSION['login_at'] = time();   // refresh timer

    // ── 3. Cek role (opsional) ────────────────────────────────
    if ($roles !== null) {
        $allowed = (array) $roles;
        if (!in_array($_SESSION['role'] ?? '', $allowed, true)) {
            http_response_code(403);
            echo json_encode([
                'status'  => 'error',
                'code'    => 403,
                'message' => 'Forbidden. Role Anda tidak diizinkan mengakses endpoint ini.',
                'role'    => $_SESSION['role'] ?? null,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

/**
 * api_user() — shortcut ambil data user dari session
 */
function api_user(): array
{
    return [
        'id_user'      => (int) ($_SESSION['id_user']      ?? 0),
        'username'     =>       ($_SESSION['username']     ?? ''),
        'nama_lengkap' =>       ($_SESSION['nama_lengkap'] ?? ''),
        'role'         =>       ($_SESSION['role']         ?? ''),
        'id_event'     =>       ($_SESSION['id_event']     ?? null),
    ];
}
