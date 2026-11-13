<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\PedidoRepository;
use BibliotecaVendas\Repositories\SeniorSyncRepository;
use BibliotecaVendas\Services\SeniorBridgeClient;
use BibliotecaVendas\Services\SeniorOrderSyncService;
use BibliotecaVendas\Support\Seguranca;

require_once dirname(__DIR__) . '/src/bootstrap.php';
session_start();

$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new DomainException('Método não permitido.');
    Seguranca::validarCsrf($_POST['csrf_token'] ?? null);
    if ((getenv('PAYMENT_MODE') ?: 'simulado_local') !== 'mercadopago_test') {
        throw new DomainException('A sincronização com o Senior só pode ser refeita no sandbox do Mercado Pago.');
    }

    $sessionOrderId = filter_var($_SESSION['payment_order_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$orderId || !$sessionOrderId || $orderId !== $sessionOrderId) {
        throw new DomainException('Pedido inválido para esta sessão.');
    }

    $pdo = Database::connect();
    $orders = new PedidoRepository($pdo);
    $payment = $orders->buscarPagamentoPorPedido($orderId);
    if (!$payment || $payment['provedor'] !== 'mercado_pago' || $payment['status'] !== 'aprovado') {
        throw new DomainException('Somente pedidos aprovados no sandbox podem ser enviados ao Senior X.');
    }

    $service = new SeniorOrderSyncService(
        $orders,
        new SeniorSyncRepository($pdo),
        new SeniorBridgeClient()
    );
    $result = $service->sincronizar($orderId, $payment);
    $_SESSION['senior_sync_order_id'] = $orderId;
    $_SESSION['senior_sync_result'][$orderId] = $result;
} catch (Throwable $error) {
    error_log('Falha na tentativa de sincronização com Senior X: ' . $error->getMessage());
    if ($orderId) {
        $_SESSION['senior_sync_result'][$orderId] = [
            'status' => 'erro',
            'message' => 'Não foi possível sincronizar o pedido. Confira a configuração do Senior X e tente novamente.',
        ];
    }
}

if ($orderId) {
    header('Location: index.php?recibo=' . rawurlencode((string) $orderId) . '#recibo');
    exit;
}

header('Location: index.php#carrinho');
exit;
