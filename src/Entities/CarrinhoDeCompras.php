<?php
declare(strict_types=1);

namespace BibliotecaVendas\Entities;

use DomainException;

final class CarrinhoDeCompras
{
    /** @var array<int, ItemPedido> */
    private array $itens = [];

    public function adicionar(Produto $produto, int $quantidade): void
    {
        $atual = $this->itens[$produto->id()] ?? null;
        $total = ($atual?->quantidade() ?? 0) + $quantidade;
        if (!$produto->possuiEstoque($total)) {
            throw new DomainException('A quantidade solicitada excede o estoque disponível.');
        }
        $this->itens[$produto->id()] = new ItemPedido($produto, $total);
    }

    public function remover(int $produtoId): void { unset($this->itens[$produtoId]); }

    /** @return array<int, ItemPedido> */
    public function itens(): array { return array_values($this->itens); }

    public function estaVazio(): bool { return $this->itens === []; }

    public function totalCentavos(): int
    {
        return array_sum(array_map(
            static fn (ItemPedido $item): int => $item->subtotalCentavos(),
            $this->itens
        ));
    }
}
