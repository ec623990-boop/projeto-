<?php
declare(strict_types=1);

use BibliotecaVendas\Services\CatalogoLivrosClient;
use BibliotecaVendas\Support\SessaoWeb;

require_once dirname(__DIR__) . '/src/bootstrap.php';
SessaoWeb::iniciar();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo '{"message":"Método não permitido."}';
    exit;
}

$agora = time();
$ultimaBusca = (int) ($_SESSION['ultima_busca_livro_internet'] ?? 0);
if ($ultimaBusca > 0 && $agora - $ultimaBusca < 1) {
    http_response_code(429);
    echo json_encode(['message' => 'Aguarde um segundo antes de fazer outra busca.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$_SESSION['ultima_busca_livro_internet'] = $agora;

try {
    $termo = trim((string) ($_GET['q'] ?? ''));
    $livros = (new CatalogoLivrosClient())->buscarPorTermo($termo, 8);

    $cache = $_SESSION['livros_busca_internet'] ?? [];
    if (!is_array($cache)) $cache = [];
    $cache = array_filter(
        $cache,
        static fn (mixed $item): bool => is_array($item) && (int) ($item['expira_em'] ?? 0) > $agora
    );

    foreach ($livros as &$livro) {
        $token = bin2hex(random_bytes(16));
        $cache[$token] = ['livro' => $livro, 'expira_em' => $agora + 1800];
        $livro['token'] = $token;
    }
    unset($livro);

    $_SESSION['livros_busca_internet'] = array_slice($cache, -80, null, true);
    echo json_encode(['items' => $livros, 'totalItems' => count($livros)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (DomainException $e) {
    http_response_code(422);
    echo json_encode(['message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Falha na busca pública de livros: ' . $e->getMessage());
    http_response_code(502);
    echo '{"message":"Não foi possível consultar o catálogo de livros agora."}';
}
