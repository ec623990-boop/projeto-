<?php
declare(strict_types=1);

use BibliotecaVendas\Services\GoogleBooksClient;
use BibliotecaVendas\Support\Autenticacao;
use BibliotecaVendas\Support\SessaoWeb;

require_once dirname(__DIR__) . '/src/bootstrap.php';
SessaoWeb::iniciar();
Autenticacao::exigirEquipe();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo '{"message":"Método não permitido."}'; exit; }
try {
    $book = (new GoogleBooksClient())->buscarPorIsbn((string) ($_GET['isbn'] ?? ''));
    if (!$book) { http_response_code(404); echo json_encode(['message' => 'ISBN não encontrado no catálogo.']); exit; }
    echo json_encode($book, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (DomainException $e) {
    http_response_code(422); echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Falha na consulta Google Books: ' . $e->getMessage());
    http_response_code(502); echo '{"message":"Não foi possível consultar o catálogo externo."}';
}
