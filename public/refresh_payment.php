<?php
declare(strict_types=1);

use BibliotecaVendas\Enums\StatusPagamento;
use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\ClienteRepository;
use BibliotecaVendas\Repositories\PedidoRepository;
use BibliotecaVendas\Repositories\ProdutoRepository;
use BibliotecaVendas\Services\MercadoPagoGateway;
use BibliotecaVendas\Services\PagamentoSimulado;
use BibliotecaVendas\Services\SeniorBridgeClient;
use BibliotecaVendas\Services\SeniorOrderSyncService;
use BibliotecaVendas\Services\VendaService;
use BibliotecaVendas\Support\SimulacaoLocal;
use BibliotecaVendas\Support\Seguranca;
use BibliotecaVendas\Repositories\SeniorSyncRepository;
use BibliotecaVendas\ValueObjects\ResultadoPagamento;

require_once dirname(__DIR__) . '/src/bootstrap.php';
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderJson(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responderJson(405, ['message' => 'Método não permitido.']);
$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) responderJson(400, ['message' => 'Requisição inválida.']);

try {
    Seguranca::validarCsrf(isset($payload['csrf_token']) ? (string) $payload['csrf_token'] : null);
    $orderId = filter_var($_SESSION['payment_order_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$orderId) throw new DomainException('Não há pedido de teste para consultar nesta sessão.');

    $pdo = Database::connect();
    $orders = new PedidoRepository($pdo);
    $paymentRow = $orders->buscarPagamentoPorPedido((int) $orderId);
    if (!$paymentRow) throw new DomainException('Ainda não há pagamento para consultar.');

    $mode = trim((string) (getenv('PAYMENT_MODE') ?: 'simulado_local'));
    if ($paymentRow['provedor'] === 'simulador_local') {
        if ($mode !== 'simulado_local') throw new DomainException('Este pedido pertence à simulação local, que está desativada.');
        SimulacaoLocal::exigirAcessoLocal();

        $paymentStatus = StatusPagamento::from((string) $paymentRow['status']);
        if ($paymentStatus === StatusPagamento::PENDENTE) {
            $finalStatus = StatusPagamento::tryFrom((string) ($_SESSION['simulacao_pix_resultado'][(int) $orderId] ?? ''));
            if (!in_array($finalStatus, [StatusPagamento::APROVADO, StatusPagamento::RECUSADO], true)) {
                throw new DomainException('Não há resultado escolhido para confirmar este Pix de teste.');
            }

            $gateway = new PagamentoSimulado($finalStatus, 'pix');
            $payment = $gateway->confirmarPix($finalStatus, (string) $paymentRow['chave_idempotencia']);
            $service = new VendaService(
                $pdo,
                new ProdutoRepository($pdo),
                new ClienteRepository($pdo),
                $orders,
                $gateway
            );
            $service->atualizarPagamento((int) $orderId, $payment, (string) $paymentRow['chave_idempotencia']);
        } else {
            $payment = new ResultadoPagamento(
                $paymentStatus,
                (string) $paymentRow['identificador_externo'],
                'Resultado local de teste já confirmado.',
                'simulador_local',
                (string) $paymentRow['metodo_pagamento'],
                (string) $paymentRow['status_detalhe']
            );
        }
        unset($_SESSION['simulacao_pix_resultado'][(int) $orderId]);
    } elseif ($paymentRow['provedor'] === 'mercado_pago' && $mode === 'mercadopago_test') {
        $gateway = new MercadoPagoGateway();
        $payment = $gateway->consultar((string) $paymentRow['identificador_externo'], (int) $orderId, (int) $paymentRow['valor_pedido']);
        $service = new VendaService(
            $pdo,
            new ProdutoRepository($pdo),
            new ClienteRepository($pdo),
            $orders,
            $gateway
        );
        $service->atualizarPagamento((int) $orderId, $payment, (string) $paymentRow['chave_idempotencia']);
    } else {
        throw new DomainException('O modo de pagamento não corresponde ao pedido desta sessão.');
    }

    $seniorSync = null;
    if ($mode === 'mercadopago_test' && $payment->status === StatusPagamento::APROVADO) {
        $freshPaymentRow = $orders->buscarPagamentoPorPedido((int) $orderId);
        if ($freshPaymentRow) {
            $syncService = new SeniorOrderSyncService(
                $orders,
                new SeniorSyncRepository($pdo),
                new SeniorBridgeClient()
            );
            $seniorSync = $syncService->sincronizar((int) $orderId, $freshPaymentRow);
            $_SESSION['senior_sync_order_id'] = (int) $orderId;
            $_SESSION['senior_sync_result'][(int) $orderId] = $seniorSync;
        }
    }

    if ($payment->status === StatusPagamento::APROVADO) {
        $_SESSION['carrinho'] = [];
        $_SESSION['chave_pedido'] = bin2hex(random_bytes(32));
    } elseif ($payment->status === StatusPagamento::RECUSADO) {
        $_SESSION['chave_pedido'] = bin2hex(random_bytes(32));
    }

    responderJson(200, [
        'order_id' => (int) $orderId,
        'status' => $payment->status->value,
        'message' => $payment->mensagem,
        'payment_method' => $payment->metodoPagamento,
        'qr_code' => $payment->qrCode,
        'qr_code_base64' => $payment->qrCodeBase64,
        'ticket_url' => $payment->ticketUrl,
        'senior_sync' => $seniorSync,
    ]);
} catch (DomainException $e) {
    responderJson(422, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Falha na consulta do pagamento de teste: ' . $e->getMessage());
    responderJson(502, ['message' => 'Não foi possível consultar o pagamento de teste. Tente novamente.']);
}
