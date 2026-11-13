<?php
declare(strict_types=1);

use BibliotecaVendas\Entities\CarrinhoDeCompras;
use BibliotecaVendas\Entities\Cliente;
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responderJson(405, ['message' => 'Método não permitido.']);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) responderJson(400, ['message' => 'Requisição inválida.']);

try {
    Seguranca::validarCsrf(isset($payload['csrf_token']) ? (string) $payload['csrf_token'] : null);

    $pdo = Database::connect();
    $mode = trim((string) (getenv('PAYMENT_MODE') ?: 'simulado_local'));
    $paymentData = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
    $pixFinalStatus = null;

    if ($mode === 'simulado_local') {
        SimulacaoLocal::exigirAcessoLocal();
        $method = (string) ($paymentData['payment_method_id'] ?? '');
        if (!in_array($method, ['credit_card', 'debit_card', 'pix'], true)) {
            throw new DomainException('Selecione crédito, débito ou Pix para a simulação.');
        }

        $requestedStatus = (string) ($paymentData['simulation_status'] ?? '');
        if (!in_array($requestedStatus, [StatusPagamento::APROVADO->value, StatusPagamento::RECUSADO->value], true)) {
            throw new DomainException('Escolha se a simulação será aprovada ou recusada.');
        }
        $pixFinalStatus = StatusPagamento::from($requestedStatus);
        $initialStatus = $method === 'pix' ? StatusPagamento::PENDENTE : $pixFinalStatus;
        $gateway = new PagamentoSimulado($initialStatus, match ($method) {
            'credit_card' => 'cartao_credito',
            'debit_card' => 'cartao_debito',
            default => 'pix',
        });
    } elseif ($mode === 'mercadopago_test') {
        $gateway = new MercadoPagoGateway();
        $gateway->validarConfiguracao();
        $gateway->validarFormulario($paymentData);
    } else {
        throw new DomainException('Modo de pagamento não reconhecido. Use simulado_local ou mercadopago_test.');
    }

    $columns = $pdo->query(
        "SELECT COLUMN_NAME, COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagamentos'"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!isset($columns['metodo_pagamento'], $columns['status_detalhe'])
        || !str_contains((string) ($columns['status'] ?? ''), "'pendente'")) {
        throw new DomainException('Antes de testar pagamentos, faça backup e execute database/migrate_mercadopago_sandbox.sql uma vez.');
    }

    $customerData = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
    $name = trim((string) ($customerData['name'] ?? ''));
    $identification = preg_replace('/\D+/', '', (string) ($customerData['identification'] ?? '')) ?? '';
    $email = trim((string) ($customerData['email'] ?? ''));
    if (!preg_match('/^\d{11}$/', $identification)) {
        throw new DomainException('Informe um CPF de teste com 11 dígitos.');
    }
    $customer = new Cliente($name, $identification, $email);

    $products = new ProdutoRepository($pdo);
    $cart = new CarrinhoDeCompras();
    foreach (($_SESSION['carrinho'] ?? []) as $id => $quantity) {
        $product = $products->buscarPorId((int) $id);
        if ($product && (int) $quantity > 0 && $product->possuiEstoque((int) $quantity)) {
            $cart->adicionar($product, (int) $quantity);
        }
    }
    if ($cart->estaVazio()) throw new DomainException('Seu carrinho está vazio ou os livros não estão mais disponíveis.');

    if (empty($_SESSION['chave_pedido'])) $_SESSION['chave_pedido'] = bin2hex(random_bytes(32));
    $service = new VendaService(
        $pdo,
        $products,
        new ClienteRepository($pdo),
        new PedidoRepository($pdo),
        $gateway
    );
    $sale = $service->finalizarVenda($customer, $cart, (string) $_SESSION['chave_pedido'], $paymentData);
    $payment = $sale['pagamento'];
    $orderId = (int) $sale['pedidoId'];
    $_SESSION['payment_order_id'] = $orderId;
    $seniorSync = null;
    if ($mode === 'mercadopago_test' && $payment->status === StatusPagamento::APROVADO) {
        $syncService = new SeniorOrderSyncService(
            $orders,
            new SeniorSyncRepository($pdo),
            new SeniorBridgeClient()
        );
        $seniorSync = $syncService->sincronizar($orderId, [
            'provedor' => $payment->provedor,
            'status' => $payment->status->value,
            'identificador_externo' => $payment->identificadorExterno,
            'metodo_pagamento' => $payment->metodoPagamento,
        ]);
        $_SESSION['senior_sync_order_id'] = $orderId;
        $_SESSION['senior_sync_result'][$orderId] = $seniorSync;
    }
    if ($mode === 'simulado_local' && $payment->status === StatusPagamento::PENDENTE && $pixFinalStatus) {
        $_SESSION['simulacao_pix_resultado'][$orderId] = $pixFinalStatus->value;
    }

    if ($payment->status === StatusPagamento::APROVADO) {
        $_SESSION['carrinho'] = [];
        $_SESSION['chave_pedido'] = bin2hex(random_bytes(32));
    } elseif ($payment->status === StatusPagamento::RECUSADO) {
        $_SESSION['chave_pedido'] = bin2hex(random_bytes(32));
    }

    responderJson(200, [
        'order_id' => $orderId,
        'status' => $payment->status->value,
        'message' => $payment->mensagem,
        'payment_method' => $payment->metodoPagamento,
        'qr_code' => $payment->qrCode,
        'qr_code_base64' => $payment->qrCodeBase64,
        'ticket_url' => $payment->ticketUrl,
        'senior_sync' => $seniorSync,
    ]);
} catch (DomainException|InvalidArgumentException $e) {
    responderJson(422, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Falha no pagamento de teste: ' . $e->getMessage());
    responderJson(502, ['message' => 'Não foi possível concluir o pagamento de teste. Confira a internet e as credenciais de teste do Mercado Pago.']);
}
