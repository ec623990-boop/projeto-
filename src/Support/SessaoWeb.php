<?php
declare(strict_types=1);

namespace BibliotecaVendas\Support;

final class SessaoWeb
{
    public static function iniciar(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params([
            'lifetime' => 0, 'path' => '/', 'secure' => $https,
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        session_start();
    }
}
