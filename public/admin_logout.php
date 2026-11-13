<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';
\BibliotecaVendas\Support\SessaoWeb::iniciar();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: admin_login.php');
exit;
