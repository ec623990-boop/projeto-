<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Services\OpenLibraryClient;
use BibliotecaVendas\Support\Isbn;
use BibliotecaVendas\Support\Seguranca;
use BibliotecaVendas\Support\SimulacaoLocal;
use BibliotecaVendas\Support\SessaoWeb;
use BibliotecaVendas\Support\Texto;

require_once dirname(__DIR__) . '/src/bootstrap.php';
SessaoWeb::iniciar();
header('Cache-Control: no-store');

function voltarAoCatalogo(string $titulo): never
{
    $destino = 'index.php?q=' . rawurlencode($titulo) . '#catalogo';
    header('Location: ' . $destino);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Método não permitido.');
}

$token = trim((string) ($_POST['livro_token'] ?? ''));
$tituloParaRetorno = '';
try {
    SimulacaoLocal::exigirAcessoLocal();
    if ((string) (getenv('PAYMENT_MODE') ?: 'simulado_local') !== 'simulado_local') {
        throw new DomainException('A inclusão de estoque fictício funciona somente no modo de simulação local.');
    }
    Seguranca::validarCsrf($_POST['csrf_token'] ?? null);
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new DomainException('Resultado da busca inválido. Faça a pesquisa novamente.');

    $registro = $_SESSION['livros_busca_internet'][$token] ?? null;
    if (!is_array($registro) || (int) ($registro['expira_em'] ?? 0) < time() || !is_array($registro['livro'] ?? null)) {
        throw new DomainException('Este resultado expirou. Faça a pesquisa novamente.');
    }
    $livro = $registro['livro'];
    $titulo = trim((string) ($livro['nome'] ?? ''));
    $tituloParaRetorno = Texto::cortar($titulo, 0, 160);
    if ($tituloParaRetorno === '') throw new DomainException('A fonte não retornou um título válido.');

    $precoTexto = str_replace(',', '.', trim((string) ($_POST['preco'] ?? '')));
    if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $precoTexto)) {
        throw new DomainException('Informe um preço fictício válido, como 29,90.');
    }
    $precoCentavos = (int) round((float) $precoTexto * 100);
    $estoque = filter_var($_POST['estoque'] ?? null, FILTER_VALIDATE_INT);
    if ($precoCentavos < 1 || $precoCentavos > 99999999 || $estoque === false || $estoque < 1 || $estoque > 1000) {
        throw new DomainException('Use um preço maior que zero e estoque entre 1 e 1.000 unidades.');
    }

    $imagem = trim((string) ($livro['imagem_url'] ?? ''));
    if ($imagem !== '') {
        $imagemPartes = parse_url($imagem);
        if (!filter_var($imagem, FILTER_VALIDATE_URL)
            || strtolower((string) ($imagemPartes['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($imagemPartes['host'] ?? '')) !== 'covers.openlibrary.org') {
            throw new DomainException('A capa retornada pela fonte não passou na validação.');
        }
    }
    $descricao = (new OpenLibraryClient())->descricaoDaObra((string) ($livro['openlibrary_id'] ?? ''));
    if ($descricao === '') $descricao = trim((string) ($livro['descricao'] ?? ''));
    if ($descricao === '') $descricao = 'Sinopse não informada pela fonte bibliográfica.';
    $isbn = Isbn::normalizar((string) ($livro['isbn'] ?? ''));
    if (!Isbn::valido($isbn)) $isbn = '';

    $pdo = Database::connect();
    $colunas = $pdo->query('SHOW COLUMNS FROM produtos')->fetchAll(PDO::FETCH_COLUMN);
    $colunas = array_fill_keys($colunas, true);
    foreach (['codigo', 'nome', 'preco_centavos', 'estoque', 'ativo', 'descricao', 'imagem_url'] as $obrigatoria) {
        if (!isset($colunas[$obrigatoria])) {
            throw new DomainException('Seu banco precisa dos campos de capa e sinopse. Faça backup e execute php scripts/migrar_gestao_catalogo.php no terminal.');
        }
    }
    if ($pdo->query("SHOW TABLES LIKE 'movimentacoes_estoque'")->fetchColumn() === false) {
        throw new DomainException('O histórico de estoque ainda não existe. Faça backup e execute php scripts/migrar_gestao_catalogo.php no terminal.');
    }

    if ($isbn !== '' && isset($colunas['isbn'])) {
        $existente = $pdo->prepare('SELECT id FROM produtos WHERE isbn = ? LIMIT 1');
        $existente->execute([$isbn]);
        if ($existente->fetchColumn()) {
            $_SESSION['aviso_livro_demo'] = 'Este ISBN já está cadastrado no estoque. Busque o título no catálogo para adicioná-lo ao carrinho.';
            unset($_SESSION['livros_busca_internet'][$token]);
            voltarAoCatalogo($tituloParaRetorno);
        }
    }

    $codigo = 'DEMO-' . strtoupper(substr($token, 0, 12));
    $valores = [
        'codigo' => $codigo,
        'nome' => $tituloParaRetorno,
        'isbn' => $isbn !== '' ? $isbn : null,
        'autor' => Texto::cortar(trim((string) ($livro['autor'] ?? '')), 0, 180) ?: null,
        'editora' => Texto::cortar(trim((string) ($livro['editora'] ?? '')), 0, 140) ?: null,
        'categoria' => Texto::cortar(trim((string) ($livro['categoria'] ?? '')), 0, 100) ?: null,
        'ano_publicacao' => isset($livro['ano_publicacao']) ? (int) $livro['ano_publicacao'] : null,
        'descricao' => Texto::cortar($descricao, 0, 10000),
        'imagem_url' => $imagem !== '' ? $imagem : null,
        'preco_centavos' => $precoCentavos,
        'custo_centavos' => null,
        'estoque' => $estoque,
        'estoque_minimo' => 1,
        'localizacao' => 'Demonstração local',
        'ativo' => 1,
    ];
    $valores = array_filter($valores, static fn (string $nome): bool => isset($colunas[$nome]), ARRAY_FILTER_USE_KEY);
    $nomes = array_keys($valores);
    $marcadores = implode(',', array_fill(0, count($nomes), '?'));
    $campos = implode(',', $nomes);

    $pdo->beginTransaction();
    try {
        $inserir = $pdo->prepare('INSERT INTO produtos (' . $campos . ') VALUES (' . $marcadores . ')');
        $inserir->execute(array_values($valores));
        $produtoId = (int) $pdo->lastInsertId();

        $movimento = $pdo->prepare(
            "INSERT INTO movimentacoes_estoque (produto_id, tipo, quantidade, observacao)
             VALUES (?, 'entrada', ?, 'Estoque fictício de demonstração local')"
        );
        $movimento->execute([$produtoId, $estoque]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    unset($_SESSION['livros_busca_internet'][$token]);
    $_SESSION['aviso_livro_demo'] = 'Livro adicionado ao estoque fictício com ' . $estoque . ' unidade(s) a ' .
        'R$ ' . number_format($precoCentavos / 100, 2, ',', '.') . '. Agora você pode adicioná-lo ao carrinho e simular a compra.';
    voltarAoCatalogo($tituloParaRetorno);
} catch (DomainException|InvalidArgumentException $e) {
    $_SESSION['erro_livro_demo'] = $e->getMessage();
    voltarAoCatalogo($tituloParaRetorno);
} catch (Throwable $e) {
    error_log('Falha ao incluir livro de demonstração: ' . $e->getMessage());
    $_SESSION['erro_livro_demo'] = 'Não foi possível incluir o livro. Confira se o banco está atualizado com php scripts/migrar_gestao_catalogo.php.';
    voltarAoCatalogo($tituloParaRetorno);
}
