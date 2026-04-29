<?php
/* ============================================================
   auth.php — Admin session helpers
   ============================================================ */

require_once __DIR__ . '/../api/helpers.php';

function start_admin_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/admin',
            'secure'   => true,
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
