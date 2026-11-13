<?php
declare(strict_types=1);

namespace BibliotecaVendas\Infrastructure;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        self::loadEnv();
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'biblioteca_vendas';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS');
        $pass = $pass === false ? '' : $pass;

        return new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    private static function loadEnv(): void
    {
        $path = dirname(__DIR__, 2) . '/.env';
        if (!is_file($path)) return;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value), "\"'");
            if ($key !== '' && getenv($key) === false) putenv($key . '=' . $value);
        }
    }
}
