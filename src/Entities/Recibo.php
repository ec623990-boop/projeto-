<?php
declare(strict_types=1);

namespace BibliotecaVendas\Entities;

use BibliotecaVendas\Enums\StatusPedido;

final class Recibo
{
    /** @param array<int, array<string, mixed>> $itens */
    public function __construct(
        public readonly int $pedidoId,
        public readonly string $clienteNome,
        public readonly string $identificacao,
        public readonly string $email,
        public readonly array $itens,
        public readonly int $totalCentavos,
        public readonly StatusPedido $status,
        public readonly ?string $mensagemPagamento,
        public readonly ?string $identificadorPagamento
    ) {}

    public static function aPartirDaConsulta(array $dados): self
    {
        return new self(
            (int) $dados['id'],
            (string) $dados['cliente_nome'],
            (string) $dados['identificacao'],
            (string) $dados['email'],
            $dados['itens'] ?? [],
            (int) $dados['valor_total_centavos'],
            StatusPedido::from((string) $dados['status']),
            isset($dados['mensagem']) ? (string) $dados['mensagem'] : null,
            isset($dados['identificador_externo']) ? (string) $dados['identificador_externo'] : null
        );
    }
}
