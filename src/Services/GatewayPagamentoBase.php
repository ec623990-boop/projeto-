<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Contracts\GatewayPagamento;

abstract class GatewayPagamentoBase implements GatewayPagamento
{
    protected function referencia(string $chaveIdempotencia): string
    {
        return 'SIM-' . strtoupper(substr(hash('sha256', $chaveIdempotencia), 0, 24));
    }
}
