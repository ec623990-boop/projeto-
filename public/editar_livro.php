<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\GestaoRepository;
use BibliotecaVendas\Support\Autenticacao;
use BibliotecaVendas\Support\Seguranca;
use BibliotecaVendas\Support\SessaoWeb;

require_once dirname(__DIR__) . '/src/bootstrap.php';
SessaoWeb::iniciar();
Autenticacao::exigirEquipe();
header('Cache-Control: no-store');
$repository = new GestaoRepository(Database::connect());
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$livro = $id ? $repository->produto((int) $id) : null;
if (!$livro) { http_response_code(404); exit('Livro não encontrado.'); }
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Seguranca::validarCsrf($_POST['csrf_token'] ?? null);
        $repository->atualizarProduto((int) $id, $_POST);
        header('Location: admin.php?atualizado=1'); exit;
    } catch (DomainException|InvalidArgumentException $e) {
        $erro = $e->getMessage();
        $livro = array_merge($livro, $_POST);
    } catch (Throwable $e) {
        error_log('Falha ao editar livro: ' . $e->getMessage());
        $erro = 'Não foi possível atualizar. Verifique se o código ou ISBN já estão cadastrados.';
        $livro = array_merge($livro, $_POST);
    }
}
$csrf = Seguranca::tokenCsrf();
function valorCampo(array $livro, string $campo): string { return htmlspecialchars((string) ($livro[$campo] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$preco = number_format(((int) $livro['preco_centavos']) / 100, 2, ',', '.');
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Editar livro</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="container admin-login"><section class="painel"><p class="eyebrow">Acervo</p><h1>Editar livro</h1><?php if ($erro !== ''): ?><div class="mensagem mensagem--erro"><?= Seguranca::escapar($erro) ?></div><?php endif; ?><form method="post" class="campos admin-form"><input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>"><input type="hidden" name="id" value="<?= (int) $livro['id'] ?>">
<label>ISBN<input name="isbn" maxlength="20" value="<?= valorCampo($livro,'isbn') ?>"></label><label>Código<input name="codigo" maxlength="40" required value="<?= valorCampo($livro,'codigo') ?>"></label><label>Título<input name="nome" maxlength="160" required value="<?= valorCampo($livro,'nome') ?>"></label><label>Autor<input name="autor" maxlength="180" value="<?= valorCampo($livro,'autor') ?>"></label><label>Editora<input name="editora" maxlength="140" value="<?= valorCampo($livro,'editora') ?>"></label><label>Categoria<input name="categoria" maxlength="100" value="<?= valorCampo($livro,'categoria') ?>"></label><label>Ano<input name="ano_publicacao" type="number" min="1000" max="<?= (int) date('Y') + 2 ?>" value="<?= valorCampo($livro,'ano_publicacao') ?>"></label><label>Preço (R$)<input name="preco" inputmode="decimal" value="<?= Seguranca::escapar((string) ($_POST['preco'] ?? $preco)) ?>" required></label><label>Estoque mínimo<input name="estoque_minimo" type="number" min="0" value="<?= valorCampo($livro,'estoque_minimo') ?>" required></label><label>Localização<input name="localizacao" maxlength="80" value="<?= valorCampo($livro,'localizacao') ?>"></label><label class="campo-largo">Imagem (URL)<input name="imagem_url" type="url" maxlength="500" value="<?= valorCampo($livro,'imagem_url') ?>"></label><label class="campo-largo">Descrição<textarea name="descricao" maxlength="10000" rows="4"><?= valorCampo($livro,'descricao') ?></textarea></label><div class="campo-largo"><button class="botao" type="submit">Salvar alterações</button> <a class="botao botao--claro" href="admin.php">Voltar</a></div></form><p class="muted">O estoque deve ser ajustado pela movimentação de entrada/saída no painel para manter o histórico.</p></section></main></body></html>
