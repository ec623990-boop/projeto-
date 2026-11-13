<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Entities\Pedido;
use BibliotecaVendas\Enums\StatusPagamento;
use BibliotecaVendas\ValueObjects\ResultadoPagamento;

final class PagamentoSimulado extends GatewayPagamentoBase
{
    public function __construct(
        private StatusPagamento $resultado,
        private string $metodoPagamento = 'cartao_credito'
    ) {}

    public function cobrar(Pedido $pedido, string $chaveIdempotencia, array $dadosPagamento = []): ResultadoPagamento
    {
        $pixPendente = $this->metodoPagamento === 'pix' && $this->resultado === StatusPagamento::PENDENTE;
        $mensagem = $pixPendente
            ? 'Pix de demonstração pendente. O código é fictício e não pode ser pago.'
            : ($this->resultado === StatusPagamento::APROVADO
                ? 'Pagamento local de demonstração aprovado. Nenhuma cobrança foi realizada.'
                : 'Pagamento local de demonstração recusado. Nenhuma cobrança foi realizada.');
        $referencia = $this->referencia($chaveIdempotencia);
        $codigoPix = $pixPendente
            ? 'PIX-TESTE-LOCAL-NAO-PAGAVEL-' . $pedido->id() . '-' . substr($referencia, -8)
            : null;

        return new ResultadoPagamento(
            $this->resultado,
            $referencia,
            $mensagem,
            'simulador_local',
            $this->metodoPagamento,
            'simulado_' . $this->resultado->value,
            $codigoPix
        );
    }

    public function confirmarPix(StatusPagamento $resultado, string $chaveIdempotencia): ResultadoPagamento
    {
        if (!in_array($resultado, [StatusPagamento::APROVADO, StatusPagamento::RECUSADO], true)) {
            throw new \InvalidArgumentException('A confirmação simulada do Pix deve aprovar ou recusar o pagamento.');
        }

        $mensagem = $resultado === StatusPagamento::APROVADO
            ? 'Pix de demonstração confirmado como aprovado. Nenhuma cobrança foi realizada.'
            : 'Pix de demonstração confirmado como recusado. Nenhuma cobrança foi realizada.';

        return new ResultadoPagamento(
            $resultado,
            $this->referencia($chaveIdempotencia),
            $mensagem,
            'simulador_local',
            'pix',
            'simulado_' . $resultado->value
        );
    }
}
