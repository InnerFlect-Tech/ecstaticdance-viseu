<?php
require_once __DIR__ . '/auth.php';
start_admin_session();
session_destroy();
header('Location: /admin/login.php');
exit;
