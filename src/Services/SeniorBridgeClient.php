<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Entities\Recibo;
use RuntimeException;

final class SeniorBridgeClient
{
    /** @param array<string, mixed> $payment */
    public function sincronizar(Recibo $receipt, array $payment): array
    {
        $url = trim((string) (getenv('SENIOR_BRIDGE_URL') ?: 'http://127.0.0.1:8091/api/orders'));
        $apiKey = trim((string) (getenv('SENIOR_BRIDGE_API_KEY') ?: ''));
        if ($apiKey === '') throw new RuntimeException('Configure SENIOR_BRIDGE_API_KEY no arquivo .env local.');

        $items = [];
        foreach ($receipt->itens as $item) {
            $items[] = [
                'sku' => (string) $item['codigo_produto'],
                'name' => (string) $item['nome_produto'],
                'unitPriceCents' => (int) $item['preco_unitario_centavos'],
                'quantity' => (int) $item['quantidade'],
            ];
        }

        $payload = [
            'orderId' => $receipt->pedidoId,
            'paymentStatus' => (string) $payment['status'],
            'paymentProvider' => (string) $payment['provedor'],
            'paymentId' => (string) $payment['identificador_externo'],
            'paymentMethod' => (string) ($payment['metodo_pagamento'] ?? ''),
            'customerName' => $receipt->clienteNome,
            'customerEmail' => $receipt->email,
            'customerDocument' => preg_replace('/\D+/', '', $receipt->identificacao) ?? '',
            'items' => $items,
            'totalCents' => $receipt->totalCentavos,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\nX-Bridge-Key: {$apiKey}\r\n",
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
                'timeout' => 20,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $statusCode = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $statusCode = (int) $match[1];
        }

        $response = is_string($responseBody) ? json_decode($responseBody, true) : null;
        if ($statusCode < 200 || $statusCode >= 300 || !is_array($response)) {
            $message = is_array($response) ? (string) ($response['message'] ?? '') : '';
            throw new RuntimeException($message !== ''
                ? substr($message, 0, 220)
                : 'Não foi possível confirmar o envio do pedido ao serviço Java do Senior X.');
        }

        return $response;
    }
}
