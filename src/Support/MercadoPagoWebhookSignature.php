<?php
declare(strict_types=1);

namespace BibliotecaVendas\Support;

final class MercadoPagoWebhookSignature
{
    public static function validar(string $secret, string $signature, string $requestId, string $dataId): bool
    {
        if ($secret === '' || $signature === '' || $requestId === '' || $dataId === '') return false;
        $parts = [];
        foreach (explode(',', $signature) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) === 2) $parts[trim($pair[0])] = trim($pair[1]);
        }
        if (!isset($parts['ts'], $parts['v1']) || !ctype_digit($parts['ts'])) return false;
        $manifest = 'id:' . strtolower($dataId) . ';request-id:' . $requestId . ';ts:' . $parts['ts'] . ';';
        return hash_equals(hash_hmac('sha256', $manifest, $secret), strtolower($parts['v1']));
    }
}
