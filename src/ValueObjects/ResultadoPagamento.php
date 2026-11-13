<?php
declare(strict_types=1);

namespace BibliotecaVendas\ValueObjects;

use BibliotecaVendas\Enums\StatusPagamento;

final class ResultadoPagamento
{
    public function __construct(
        public readonly StatusPagamento $status,
        public readonly string $identificadorExterno,
        public readonly string $mensagem,
        public readonly string $provedor = 'simulador',
        public readonly ?string $metodoPagamento = null,
        public readonly ?string $statusDetalhe = null,
        public readonly ?string $qrCode = null,
        public readonly ?string $qrCodeBase64 = null,
        public readonly ?string $ticketUrl = null
    ) {}
}
