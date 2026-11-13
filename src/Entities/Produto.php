<?php
declare(strict_types=1);

namespace BibliotecaVendas\Entities;

use InvalidArgumentException;

final class Produto
{
    public function __construct(
        private int $id,
        private string $codigo,
        private string $nome,
        private int $precoCentavos,
        private int $estoque
    ) {
        if ($id < 1 || trim($codigo) === '' || trim($nome) === '') {
            throw new InvalidArgumentException('Produto inválido.');
        }
        if ($precoCentavos < 1 || $estoque < 0) {
            throw new InvalidArgumentException('Preço ou estoque inválido.');
        }
    }

    public function id(): int { return $this->id; }
    public function codigo(): string { return $this->codigo; }
    public function nome(): string { return $this->nome; }
    public function precoCentavos(): int { return $this->precoCentavos; }
    public function estoque(): int { return $this->estoque; }

    public function possuiEstoque(int $quantidade): bool
    {
        return $quantidade > 0 && $this->estoque >= $quantidade;
    }
}
