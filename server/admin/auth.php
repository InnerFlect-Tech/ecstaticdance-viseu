<?php
/* ============================================================
   auth.php — Admin session helpers
   ============================================================ */

require_once __DIR__ . '/../api/helpers.php';

/** Cookie só «secure» em HTTPS (local HTTP em Vite + PHP funciona; prod atrás de proxy usa X-Forwarded-Proto). */
function admin_session_cookie_secure(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $fwd = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    return is_string($fwd) && strtolower($fwd) === 'https';
}

function start_admin_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/admin',
            'secure'   => admin_session_cookie_secure(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }
}

function is_admin_logged_in(): bool {
    start_admin_session();
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function require_admin_session(): void {
    if (!is_admin_logged_in()) {
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            header('Location: /admin/login.php');
            exit;
        }
        json_err('Não autorizado.', 401);
    }
}
