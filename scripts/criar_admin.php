<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\UsuarioRepository;
use BibliotecaVendas\Support\Texto;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/bootstrap.php';

function perguntar(string $texto): string
{
    fwrite(STDOUT, $texto);
    return trim((string) fgets(STDIN));
}

try {
    $pdo = Database::connect();
    $count = (int) $pdo->query("SELECT COUNT(*) FROM usuarios_equipe WHERE perfil = 'admin'")->fetchColumn();
    if ($count > 0) throw new RuntimeException('Já existe um administrador. Crie contas adicionais pela área administrativa.');
    $nome = perguntar('Nome do administrador: ');
    $email = strtolower(perguntar('E-mail: '));
    if ($nome === '' || Texto::comprimento($nome) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Nome ou e-mail inválido.');
    }
    fwrite(STDOUT, 'Senha (mínimo de 12 caracteres): ');
    $senha = rtrim((string) fgets(STDIN), "\r\n");
    if (strlen($senha) < 12) throw new InvalidArgumentException('A senha precisa ter pelo menos 12 caracteres.');
    (new UsuarioRepository($pdo))->criar($nome, $email, $senha, 'admin');
    fwrite(STDOUT, "Administrador criado. Remova ou proteja este script depois da configuração inicial.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Falha: ' . $e->getMessage() . "\n");
    exit(1);
}
