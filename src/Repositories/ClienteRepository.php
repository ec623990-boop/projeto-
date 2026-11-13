<?php
declare(strict_types=1);

namespace BibliotecaVendas\Repositories;

use BibliotecaVendas\Entities\Cliente;
use DomainException;
use PDO;

final class ClienteRepository
{
    public function __construct(private PDO $pdo) {}

    public function encontrarOuCriar(Cliente $cliente): int
    {
        $stmt = $this->pdo->prepare('SELECT id, identificacao FROM clientes WHERE email = ? FOR UPDATE');
        $stmt->execute([$cliente->email()]);
        $row = $stmt->fetch();

        if ($row) {
            if ($row['identificacao'] !== $cliente->identificacao()) {
                throw new DomainException('Este e-mail já está associado a outra identificação.');
            }
            return (int) $row['id'];
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO clientes (nome, identificacao, email) VALUES (?, ?, ?)'
        );
        $insert->execute([$cliente->nome(), $cliente->identificacao(), $cliente->email()]);
        return (int) $this->pdo->lastInsertId();
    }
}
