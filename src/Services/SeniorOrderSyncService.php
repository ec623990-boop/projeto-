<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Enums\StatusPagamento;
use BibliotecaVendas\Repositories\PedidoRepository;
use BibliotecaVendas\Repositories\SeniorSyncRepository;
use Throwable;

final class SeniorOrderSyncService
{
    public function __construct(
        private PedidoRepository $orders,
        private SeniorSyncRepository $syncRecords,
        private SeniorBridgeClient $bridge
    ) {}

    /** @param array<string, mixed> $payment */
    public function sincronizar(int $pedidoId, array $payment): array
    {
        if ((getenv('PAYMENT_MODE') ?: 'simulado_local') !== 'mercadopago_test'
            || ($payment['provedor'] ?? '') !== 'mercado_pago'
            || ($payment['status'] ?? '') !== StatusPagamento::APROVADO->value) {
            return ['status' => 'ignorado', 'message' => 'A integração Senior recebe apenas pedidos pagos no sandbox do Mercado Pago.'];
        }

        if (!filter_var(getenv('SENIOR_SYNC_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL)) {
            return ['status' => 'desativado', 'message' => 'A sincronização com o Senior X está desativada.'];
        }

        if (!$this->syncRecords->tabelaDisponivel()) {
            return ['status' => 'erro', 'message' => 'Faça backup e aplique database/migrate_senior_erp.sql antes de ativar a sincronização.'];
        }

        $current = $this->syncRecords->buscar($pedidoId);
        if (($current['status'] ?? '') === 'sincronizado') {
            return [
                'status' => 'sincronizado',
                'senior_order_id' => $current['senior_order_id'],
                'message' => 'Pedido já sincronizado com o Senior X.',
            ];
        }

        $receipt = $this->orders->buscarRecibo($pedidoId);
        if (!$receipt) return ['status' => 'erro', 'message' => 'Pedido local não encontrado para sincronização.'];

        $externalId = 'lv-' . $pedidoId;
        try {
            $this->syncRecords->registrarPendente($pedidoId, $externalId);
            $result = $this->bridge->sincronizar($receipt, $payment);
            $seniorOrderId = (string) ($result['seniorOrderId'] ?? '');
            $message = (string) ($result['message'] ?? 'Pedido sincronizado com o Senior X.');
            if ($seniorOrderId === '') throw new \RuntimeException('O serviço Java não retornou o identificador do pedido do Senior X.');
            $this->syncRecords->marcarSincronizado($pedidoId, $seniorOrderId, $message);
            return ['status' => 'sincronizado', 'senior_order_id' => $seniorOrderId, 'message' => $message];
        } catch (Throwable $error) {
            $message = substr($error->getMessage(), 0, 220);
            try {
                $this->syncRecords->marcarErro($pedidoId, $message);
            } catch (Throwable $storageError) {
                error_log('Não foi possível registrar a falha de sincronização Senior.');
            }
            error_log('Falha na integração de pedido com Senior X: ' . $message);
            return ['status' => 'erro', 'message' => $message];
        }
    }
}
