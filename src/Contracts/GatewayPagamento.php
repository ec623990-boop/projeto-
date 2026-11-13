<?php
declare(strict_types=1);

namespace BibliotecaVendas\Contracts;

use BibliotecaVendas\Entities\Pedido;
use BibliotecaVendas\ValueObjects\ResultadoPagamento;

interface GatewayPagamento
{
    /** @param array<string, mixed> $dadosPagamento */
    public function cobrar(Pedido $pedido, string $chaveIdempotencia, array $dadosPagamento = []): ResultadoPagamento;
}
