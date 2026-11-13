<?php
declare(strict_types=1);

use BibliotecaVendas\Services\CatalogoLivrosClient;
use BibliotecaVendas\Support\Autenticacao;
use BibliotecaVendas\Support\SessaoWeb;

require_once dirname(__DIR__) . '/src/bootstrap.php';
SessaoWeb::iniciar();
Autenticacao::exigirEquipe();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); echo '{"message":"Método não permitido."}'; exit; }
$agora = microtime(true);
$ultimaBusca = (float) ($_SESSION['ultima_busca_catalogo_livros'] ?? 0);
if ($ultimaBusca > 0 && $agora - $ultimaBusca < 1) {
    http_response_code(429);
    echo json_encode(['message' => 'Aguarde um segundo antes de fazer outra busca.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$_SESSION['ultima_busca_catalogo_livros'] = $agora;
try {
    $client = new CatalogoLivrosClient();
    $isbn = trim((string) ($_GET['isbn'] ?? ''));
    $termo = trim((string) ($_GET['q'] ?? ''));
    if ($isbn !== '') {
        $book = $client->buscarPorIsbn($isbn);
        if (!$book) { http_response_code(404); echo json_encode(['message' => 'ISBN não encontrado no catálogo.']); exit; }
        echo json_encode($book, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $books = $client->buscarPorTermo($termo);
    echo json_encode(['items' => $books, 'totalItems' => count($books)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (DomainException $e) {
    http_response_code(422); echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Falha na consulta do catálogo bibliográfico: ' . $e->getMessage());
    http_response_code(502); echo '{"message":"Não foi possível consultar o catálogo externo."}';
}
