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
        private int $estoque,
        private ?string $isbn = null,
        private ?string $autor = null,
        private ?string $editora = null,
        private ?string $categoria = null,
        private ?int $anoPublicacao = null,
        private ?string $descricao = null,
        private ?string $imagemUrl = null,
        private int $estoqueMinimo = 2,
        private ?string $localizacao = null
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
    public function isbn(): ?string { return $this->isbn; }
    public function autor(): ?string { return $this->autor; }
    public function editora(): ?string { return $this->editora; }
    public function categoria(): ?string { return $this->categoria; }
    public function anoPublicacao(): ?int { return $this->anoPublicacao; }
    public function descricao(): ?string { return $this->descricao; }
    public function imagemUrl(): ?string { return $this->imagemUrl; }
    public function estoqueMinimo(): int { return $this->estoqueMinimo; }
    public function localizacao(): ?string { return $this->localizacao; }

    public function possuiEstoque(int $quantidade): bool
    {
        return $quantidade > 0 && $this->estoque >= $quantidade;
    }
}
