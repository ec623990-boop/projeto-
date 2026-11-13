<?php
declare(strict_types=1);

namespace BibliotecaVendas\Entities;

use BibliotecaVendas\Enums\StatusPedido;
use DomainException;

final class Pedido
{
    /** @param array<int, ItemPedido> $itens */
    public function __construct(
        private int $id,
        private Cliente $cliente,
        private array $itens,
        private int $valorTotalCentavos,
        private StatusPedido $status,
        private string $chaveIdempotencia
    ) {
        if ($id < 1 || $itens === [] || $valorTotalCentavos < 1) throw new DomainException('Pedido inválido.');
    }

    public function id(): int { return $this->id; }
    public function cliente(): Cliente { return $this->cliente; }
    /** @return array<int, ItemPedido> */
    public function itens(): array { return $this->itens; }
    public function valorTotalCentavos(): int { return $this->valorTotalCentavos; }
    public function status(): StatusPedido { return $this->status; }
    public function chaveIdempotencia(): string { return $this->chaveIdempotencia; }

    public function marcarComoPago(): void
    {
        if ($this->status !== StatusPedido::PENDENTE) throw new DomainException('Somente pedido pendente pode ser pago.');
        $this->status = StatusPedido::PAGO;
    }

    public function cancelar(): void
    {
        if ($this->status !== StatusPedido::PENDENTE) throw new DomainException('Somente pedido pendente pode ser cancelado.');
        $this->status = StatusPedido::CANCELADO;
    }
}
