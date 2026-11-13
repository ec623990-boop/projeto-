<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Contracts\GatewayPagamento;
use BibliotecaVendas\Entities\Pedido;
use BibliotecaVendas\Enums\StatusPagamento;
use BibliotecaVendas\ValueObjects\ResultadoPagamento;
use DomainException;
use RuntimeException;

/** Integração do Payment Brick com a API de pagamentos no ambiente de teste. */
final class MercadoPagoGateway implements GatewayPagamento
{
    private const API_BASE = 'https://api.mercadopago.com';

    public function validarConfiguracao(): void
    {
        if (getenv('PAYMENT_MODE') !== 'mercadopago_test') {
            throw new RuntimeException('O checkout exige PAYMENT_MODE=mercadopago_test.');
        }

        $token = getenv('MP_TEST_ACCESS_TOKEN');
        if ($token === false || trim($token) === '') {
            throw new RuntimeException('Configure MP_TEST_ACCESS_TOKEN com a credencial do ambiente Teste.');
        }
    }

    /** @param array<string, mixed> $dadosPagamento */
    public function validarFormulario(array $dadosPagamento): void
    {
        $methodId = (string) ($dadosPagamento['payment_method_id'] ?? '');
        if ($methodId === '' || !preg_match('/^[a-z0-9_]{2,40}$/i', $methodId)) {
            throw new DomainException('Selecione cartão de crédito, débito ou Pix no formulário do Mercado Pago.');
        }

        if ($methodId === 'pix') return;

        $token = $dadosPagamento['token'] ?? null;
        if (!is_string($token) || trim($token) === '' || strlen($token) > 2048) {
            throw new DomainException('O cartão precisa ser tokenizado pelo formulário seguro do Mercado Pago.');
        }

        $paymentType = (string) ($dadosPagamento['payment_type_id'] ?? '');
        if ($paymentType !== '' && !in_array($paymentType, ['credit_card', 'debit_card'], true)) {
            throw new DomainException('Este checkout aceita somente cartão de crédito, débito ou Pix.');
        }

        $installments = filter_var($dadosPagamento['installments'] ?? 1, FILTER_VALIDATE_INT);
        if ($installments === false || $installments < 1 || $installments > 24) {
            throw new DomainException('A quantidade de parcelas informada é inválida.');
        }
    }

    public function cobrar(Pedido $pedido, string $chaveIdempotencia, array $dadosPagamento = []): ResultadoPagamento
    {
        $this->validarConfiguracao();
        $this->validarFormulario($dadosPagamento);

        $methodId = (string) $dadosPagamento['payment_method_id'];
        $cliente = $pedido->cliente();
        $nome = preg_split('/\s+/u', trim($cliente->nome()), 2) ?: [];
        $payer = [
            'email' => $cliente->email(),
            'first_name' => $nome[0] ?? $cliente->nome(),
        ];
        if (isset($nome[1]) && $nome[1] !== '') $payer['last_name'] = $nome[1];

        $identification = $dadosPagamento['payer']['identification'] ?? null;
        if (!is_array($identification) || empty($identification['type']) || empty($identification['number'])) {
            $digits = preg_replace('/\D+/', '', $cliente->identificacao()) ?? '';
            if (strlen($digits) === 11) $identification = ['type' => 'CPF', 'number' => $digits];
        }
        if (is_array($identification) && !empty($identification['type']) && !empty($identification['number'])) {
            $payer['identification'] = [
                'type' => substr((string) $identification['type'], 0, 20),
                'number' => preg_replace('/\D+/', '', (string) $identification['number']) ?? '',
            ];
        }

        $body = [
            'transaction_amount' => round($pedido->valorTotalCentavos() / 100, 2),
            'description' => 'Compra de livros - pedido #' . $pedido->id(),
            'payment_method_id' => $methodId,
            'external_reference' => (string) $pedido->id(),
            'payer' => $payer,
        ];

        if ($methodId !== 'pix') {
            $body['token'] = (string) $dadosPagamento['token'];
            $body['installments'] = (int) ($dadosPagamento['installments'] ?? 1);
            if (isset($dadosPagamento['issuer_id']) && is_scalar($dadosPagamento['issuer_id'])) {
                $body['issuer_id'] = (string) $dadosPagamento['issuer_id'];
            }
        }

        $response = $this->request('POST', '/v1/payments', $body, $chaveIdempotencia);
        if (empty($response['id'])) throw new RuntimeException('O Mercado Pago não retornou o identificador do pagamento.');

        return $this->resultado($response, $methodId);
    }

    public function consultar(string $paymentId, int $pedidoId): ResultadoPagamento
    {
        $this->validarConfiguracao();
        if (!ctype_digit($paymentId)) throw new DomainException('Identificador de pagamento inválido.');

        $response = $this->request('GET', '/v1/payments/' . rawurlencode($paymentId));
        if ((string) ($response['external_reference'] ?? '') !== (string) $pedidoId) {
            throw new DomainException('O pagamento consultado não corresponde a este pedido.');
        }

        return $this->resultado($response);
    }

    /** @param array<string, mixed> $body
     *  @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array
    {
        $token = trim((string) getenv('MP_TEST_ACCESS_TOKEN'));
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ];
        if ($idempotencyKey !== null) $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;

        $options = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'ignore_errors' => true,
                'timeout' => 20,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ];
        if ($body !== null) {
            $options['http']['content'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $context = stream_context_create($options);
        $raw = @file_get_contents(self::API_BASE . $path, false, $context);
        $statusCode = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $statusCode = (int) $match[1];
        }

        $response = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($response)) {
            throw new RuntimeException('Não foi possível conectar à API de teste do Mercado Pago.');
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            $detail = (string) ($response['message'] ?? $response['error'] ?? 'Erro de comunicação.');
            throw new RuntimeException('Mercado Pago HTTP ' . $statusCode . ': ' . substr($detail, 0, 160));
        }

        return $response;
    }

    /** @param array<string, mixed> $response */
    private function resultado(array $response, ?string $methodFallback = null): ResultadoPagamento
    {
        $providerStatus = strtolower((string) ($response['status'] ?? 'pending'));
        $status = match ($providerStatus) {
            'approved' => StatusPagamento::APROVADO,
            'rejected', 'cancelled', 'refunded', 'charged_back' => StatusPagamento::RECUSADO,
            default => StatusPagamento::PENDENTE,
        };
        $message = match ($status) {
            StatusPagamento::APROVADO => 'Pagamento de teste aprovado pelo Mercado Pago.',
            StatusPagamento::RECUSADO => 'Pagamento recusado pelo ambiente de teste.',
            StatusPagamento::PENDENTE => 'Pagamento pendente. Aguarde a confirmação ou consulte o status novamente.',
        };
        $transaction = $response['point_of_interaction']['transaction_data'] ?? [];

        return new ResultadoPagamento(
            $status,
            (string) ($response['id'] ?? ''),
            $message,
            'mercado_pago',
            (string) ($response['payment_method_id'] ?? $methodFallback ?? ''),
            substr((string) ($response['status_detail'] ?? $providerStatus), 0, 80),
            isset($transaction['qr_code']) ? (string) $transaction['qr_code'] : null,
            isset($transaction['qr_code_base64']) ? (string) $transaction['qr_code_base64'] : null,
            isset($transaction['ticket_url']) ? (string) $transaction['ticket_url'] : null
        );
    }
}
