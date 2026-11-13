<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Repositories\UsuarioRepository;
use DomainException;

final class LoginService
{
    public function __construct(private UsuarioRepository $usuarios) {}

    public function autenticar(string $email, string $senha): array
    {
        $usuario = $this->usuarios->porEmail($email);
        if (!$usuario || !(bool) $usuario['ativo']) throw new DomainException('E-mail ou senha inválidos.');
        if (!empty($usuario['bloqueado_ate']) && strtotime((string) $usuario['bloqueado_ate']) > time()) {
            throw new DomainException('Acesso temporariamente bloqueado. Tente novamente em 15 minutos.');
        }
        if (!password_verify($senha, (string) $usuario['senha_hash'])) {
            $this->usuarios->registrarFalha((int) $usuario['id'], (int) $usuario['tentativas_login'] + 1);
            throw new DomainException('E-mail ou senha inválidos.');
        }
        $this->usuarios->registrarSucesso((int) $usuario['id']);
        if (password_needs_rehash((string) $usuario['senha_hash'], PASSWORD_DEFAULT)) {
            $this->usuarios->atualizarHash((int) $usuario['id'], password_hash($senha, PASSWORD_DEFAULT));
        }
        return ['id' => (int) $usuario['id'], 'nome' => (string) $usuario['nome'], 'perfil' => (string) $usuario['perfil']];
    }
}
