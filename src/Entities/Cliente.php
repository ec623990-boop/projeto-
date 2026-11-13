<?php
declare(strict_types=1);

namespace BibliotecaVendas\Entities;

use InvalidArgumentException;

final class Cliente
{
    public function __construct(
        private string $nome,
        private string $identificacao,
        private string $email,
        private ?int $id = null
    ) {
        if (trim($nome) === '' || trim($identificacao) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe nome, identificação e um e-mail válido.');
        }
    }

    public function id(): ?int { return $this->id; }
    public function nome(): string { return $this->nome; }
    public function identificacao(): string { return $this->identificacao; }
    public function email(): string { return $this->email; }
}
