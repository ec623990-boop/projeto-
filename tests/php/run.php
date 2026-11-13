<?php
declare(strict_types=1);

use BibliotecaVendas\Support\Isbn;
use BibliotecaVendas\Support\MercadoPagoWebhookSignature;
use BibliotecaVendas\Support\Texto;
use BibliotecaVendas\Services\CatalogoLivrosClient;

require_once dirname(__DIR__, 2) . '/src/bootstrap.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException($message);
};

$assert(Isbn::valido('978-0-306-40615-7'), 'ISBN-13 válido deve ser aceito.');
$assert(Isbn::valido('0-306-40615-2'), 'ISBN-10 válido deve ser aceito.');
$assert(!Isbn::valido('978-0-306-40615-8'), 'ISBN com dígito verificador incorreto deve ser rejeitado.');
$assert(!Isbn::valido('12345'), 'ISBN incompleto deve ser rejeitado.');
$assert(Texto::comprimento('ação') === 4, 'Contagem de caracteres UTF-8 deve ser correta sem mbstring.');
$assert(Texto::cortar('ação', 0, 2) === 'aç', 'Corte de texto UTF-8 não deve quebrar caracteres.');

$booksClient = new CatalogoLivrosClient();
foreach (['', 'x'] as $termoInvalido) {
    try {
        $booksClient->buscarPorTermo($termoInvalido);
        $assert(false, 'Busca bibliográfica curta deve ser rejeitada sem consultar o serviço.');
    } catch (DomainException) {
        $assert(true, 'Busca bibliográfica curta é rejeitada.');
    }
}

$secret = 'segredo-de-teste';
$requestId = 'req-123';
$dataId = '123456';
$timestamp = '1700000000';
$manifest = 'id:' . $dataId . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
$signature = 'ts=' . $timestamp . ',v1=' . hash_hmac('sha256', $manifest, $secret);
$assert(MercadoPagoWebhookSignature::validar($secret, $signature, $requestId, $dataId), 'Assinatura correta deve ser aceita.');
$assert(!MercadoPagoWebhookSignature::validar($secret, $signature, 'request-alterado', $dataId), 'Request ID alterado deve invalidar assinatura.');
$assert(!MercadoPagoWebhookSignature::validar('', $signature, $requestId, $dataId), 'Segredo ausente deve invalidar assinatura.');

fwrite(STDOUT, 'PHP unit checks passed: ' . $checks . PHP_EOL);
