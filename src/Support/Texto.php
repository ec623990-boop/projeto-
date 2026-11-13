<?php
declare(strict_types=1);

namespace BibliotecaVendas\Support;

final class Texto
{
    public static function comprimento(string $texto): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($texto, 'UTF-8');
        $count = preg_match_all('/./us', $texto);
        return $count === false ? strlen($texto) : $count;
    }

    public static function cortar(string $texto, int $inicio, int $limite): string
    {
        if (function_exists('mb_substr')) return mb_substr($texto, $inicio, $limite, 'UTF-8');
        $chars = preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY);
        return $chars === false ? substr($texto, $inicio, $limite) : implode('', array_slice($chars, $inicio, $limite));
    }

    public static function minusculo(string $texto): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($texto, 'UTF-8') : strtolower($texto);
    }
}
