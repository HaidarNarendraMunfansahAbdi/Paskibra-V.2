<?php
/**
 * proses_login.php — Handler POST dari form login
 */
declare(strict_types=1);
session_start();

// ── Hanya POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

// ── Validasi CSRF token ───────────────────────────────────────
$csrfPost    = $_POST['csrf']        ?? '';
$csrfSession = $_SESSION['csrf']     ?? '';
if (!$csrfPost || !hash_equals($csrfSession, $csrfPost)) {
    $_SESSION['login_error'] = 'Permintaan tidak valid. Silakan coba lagi.';
    header('Location: login.php');
    exit;
}
unset($_SESSION['csrf']);   // pakai sekali, regenerasi di login.php

// ── Sanitasi input ────────────────────────────────────────────
$username = trim($_POST['username'] ?? '');
$password = $_POST['password']       ?? '';

if ($username === '' || $password === '') {
    $_SESSION['login_error'] = 'Username dan password wajib diisi.';
    header('Location: login.php');
    exit;
}

// Batasi panjang untuk cegah serangan bcrypt DoS (> 72 karakter)
if (strlen($password) > 72) {
    $_SESSION['login_error'] = 'Password terlalu panjang.';
    header('Location: login.php');
    exit;
}

// ── Koneksi DB ────────────────────────────────────────────────
require_once __DIR__ . '/koneksi.php';   // → $pdo

// ── Ambil user dari DB ────────────────────────────────────────
$stmt = $pdo->prepare(
    "SELECT id_user, username, password, nama_lengkap, role, id_event, aktif
     FROM tabel_users
     WHERE username = :u
     LIMIT 1"
);
$stmt->execute([':u' => $username]);
$user = $stmt->fetch();

// ── Verifikasi ────────────────────────────────────────────────
// Jika user tidak ditemukan: password_verify tetap dipanggil
// dengan dummy hash agar response time konsisten (cegah timing attack)
$dummyHash = '$2y$10$dummyhashfortimingequalityXXXXXXXXXXXXXXXXXXXXXX';
$hashToCheck = $user ? $user['password'] : $dummyHash;

if (!$user || !password_verify($password, $hashToCheck)) {
    $_SESSION['login_error'] = 'Username atau password salah.';
    header('Location: login.php');
    exit;
}

if (!(bool)$user['aktif']) {
    $_SESSION['login_error'] = 'Akun Anda dinonaktifkan. Hubungi administrator.';
    header('Location: login.php');
    exit;
}

// ── Regenerasi session ID (cegah session fixation) ───────────
session_regenerate_id(true);

// ── Simpan data ke session ────────────────────────────────────
$_SESSION['id_user']      = (int)    $user['id_user'];
$_SESSION['username']     =          $user['username'];
$_SESSION['nama_lengkap'] =          $user['nama_lengkap'];
$_SESSION['role']         =          $user['role'];
$_SESSION['id_event']     = $user['id_event'] ? (int)$user['id_event'] : null;
$_SESSION['login_at']     = time();

// ── Perbarui last_login ───────────────────────────────────────
$pdo->prepare("UPDATE tabel_users SET last_login = NOW() WHERE id_user = :id")
    ->execute([':id' => $user['id_user']]);

// ── Redirect sesuai role ──────────────────────────────────────
$redirect = match($user['role']) {
    'admin' => 'dashboard_klasemen.php',
    'juri'  => 'rekap.php',
    default => 'rekap.php',
};

header("Location: {$redirect}");
exit;
