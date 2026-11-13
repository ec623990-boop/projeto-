<?php
declare(strict_types=1);

namespace BibliotecaVendas\Support;

final class Isbn
{
    public static function normalizar(string $isbn): string
    {
        return strtoupper((string) preg_replace('/[^0-9X]/i', '', trim($isbn)));
    }

    public static function valido(string $isbn): bool
    {
        $isbn = self::normalizar($isbn);
        if (strlen($isbn) === 13 && ctype_digit($isbn)) {
            $sum = 0;
            for ($i = 0; $i < 12; $i++) $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
            return (10 - ($sum % 10)) % 10 === (int) $isbn[12];
        }
        if (strlen($isbn) === 10 && preg_match('/^[0-9]{9}[0-9X]$/', $isbn)) {
            $sum = 0;
            for ($i = 0; $i < 10; $i++) $sum += (int) ($isbn[$i] === 'X' ? 10 : $isbn[$i]) * (10 - $i);
            return $sum % 11 === 0;
        }
        return false;
    }
}
