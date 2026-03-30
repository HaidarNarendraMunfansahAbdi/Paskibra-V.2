<?php
/**
 * logout.php — Hancurkan session & kembali ke login.php
 */
declare(strict_types=1);
session_start();

// Hapus semua data session
$_SESSION = [];

// Hapus cookie session dari browser
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 3600,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;
