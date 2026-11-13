<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\ClienteRepository;
use BibliotecaVendas\Repositories\PedidoRepository;
use BibliotecaVendas\Repositories\ProdutoRepository;
use BibliotecaVendas\Repositories\SeniorSyncRepository;
use BibliotecaVendas\Services\MercadoPagoGateway;
use BibliotecaVendas\Services\SeniorBridgeClient;
use BibliotecaVendas\Services\SeniorOrderSyncService;
use BibliotecaVendas\Services\VendaService;
use BibliotecaVendas\Support\MercadoPagoWebhookSignature;

require_once dirname(__DIR__) . '/src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function webhookResposta(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') webhookResposta(405, ['message' => 'Método não permitido.']);
if ((getenv('PAYMENT_MODE') ?: 'simulado_local') !== 'mercadopago_test') webhookResposta(503, ['message' => 'Webhook de teste desativado.']);

$headers = function_exists('getallheaders') ? getallheaders() : [];
$headers = array_change_key_case(is_array($headers) ? $headers : [], CASE_LOWER);
$signature = (string) ($headers['x-signature'] ?? '');
$requestId = (string) ($headers['x-request-id'] ?? '');
$body = json_decode(file_get_contents('php://input') ?: '', true);
$body = is_array($body) ? $body : [];
$queryDataId = '';
if (preg_match('/(?:^|&)data\.id=([^&]*)/', (string) ($_SERVER['QUERY_STRING'] ?? ''), $queryMatch)) {
    $queryDataId = rawurldecode($queryMatch[1]);
}
$dataId = (string) ($_GET['data.id'] ?? $_GET['id'] ?? ($queryDataId !== '' ? $queryDataId : ($body['data']['id'] ?? '')));
$secret = trim((string) (getenv('MP_TEST_WEBHOOK_SECRET') ?: ''));
if (!MercadoPagoWebhookSignature::validar($secret, $signature, $requestId, $dataId)) {
    webhookResposta(401, ['message' => 'Assinatura do webhook inválida.']);
}
if (($body['type'] ?? '') !== 'payment' && !str_starts_with((string) ($body['action'] ?? ''), 'payment.')) {
    webhookResposta(200, ['received' => true, 'ignored' => true]);
}

try {
    $pdo = Database::connect();
    $orders = new PedidoRepository($pdo);
    $paymentRow = $orders->buscarResumoPorPagamentoExterno($dataId);
    if (!$paymentRow) webhookResposta(200, ['received' => true, 'ignored' => true]);
    $orderId = (int) $paymentRow['pedido_id'];
    $gateway = new MercadoPagoGateway();
    $result = $gateway->consultar($dataId, $orderId, (int) $paymentRow['valor_total_centavos']);
    $service = new VendaService($pdo, new ProdutoRepository($pdo), new ClienteRepository($pdo), $orders, $gateway);
    $service->atualizarPagamento($orderId, $result, (string) $paymentRow['chave_idempotencia']);
    if ($result->status->value === 'aprovado') {
        $freshPayment = $orders->buscarPagamentoPorPedido($orderId);
        if ($freshPayment) {
            $sync = new SeniorOrderSyncService($orders, new SeniorSyncRepository($pdo), new SeniorBridgeClient());
            $sync->sincronizar($orderId, $freshPayment);
        }
    }
    webhookResposta(200, ['received' => true, 'order_id' => $orderId, 'status' => $result->status->value]);
} catch (Throwable $e) {
    error_log('Falha no webhook Mercado Pago: ' . $e->getMessage());
    webhookResposta(500, ['message' => 'Não foi possível processar a notificação.']);
}
