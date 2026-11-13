<?php
declare(strict_types=1);

namespace BibliotecaVendas\Support;

use RuntimeException;

final class Seguranca
{
    public static function tokenCsrf(): string
    {
        if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return (string) $_SESSION['csrf_token'];
    }

    public static function validarCsrf(?string $token): void
    {
        if (!is_string($token) || !hash_equals(self::tokenCsrf(), $token)) {
            throw new RuntimeException('Sua sessão expirou. Atualize a página e tente novamente.');
        }
    }

    public static function escapar(string|int|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function dinheiro(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
