<?php
declare(strict_types=1);

namespace BibliotecaVendas\Repositories;

use PDO;
use BibliotecaVendas\Support\Texto;

final class UsuarioRepository
{
    public function __construct(private PDO $pdo) {}

    public function porEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, nome, email, senha_hash, perfil, ativo, tentativas_login, bloqueado_ate FROM usuarios_equipe WHERE email = ? LIMIT 1');
        $stmt->execute([Texto::minusculo(trim($email))]);
        return $stmt->fetch() ?: null;
    }

    public function registrarFalha(int $id, int $tentativas): void
    {
        $bloqueado = $tentativas >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $stmt = $this->pdo->prepare('UPDATE usuarios_equipe SET tentativas_login = ?, bloqueado_ate = ? WHERE id = ?');
        $stmt->execute([$bloqueado ? 0 : $tentativas, $bloqueado, $id]);
    }

    public function registrarSucesso(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios_equipe SET tentativas_login = 0, bloqueado_ate = NULL, ultimo_login_em = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$id]);
    }

    public function atualizarHash(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios_equipe SET senha_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $id]);
    }

    public function criar(string $nome, string $email, string $senha, string $perfil = 'equipe'): void
    {
        if (!in_array($perfil, ['admin', 'equipe'], true)) throw new \InvalidArgumentException('Perfil inválido.');
        $stmt = $this->pdo->prepare('INSERT INTO usuarios_equipe (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)');
        $stmt->execute([trim($nome), Texto::minusculo(trim($email)), password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    }

    public function equipe(): array
    {
        return $this->pdo->query('SELECT id, nome, email, perfil, ativo, ultimo_login_em FROM usuarios_equipe ORDER BY nome')->fetchAll();
    }
}
