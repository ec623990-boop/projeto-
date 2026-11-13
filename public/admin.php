<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\GestaoRepository;
use BibliotecaVendas\Repositories\UsuarioRepository;
use BibliotecaVendas\Support\Autenticacao;
use BibliotecaVendas\Support\Seguranca;
use BibliotecaVendas\Support\SessaoWeb;
use BibliotecaVendas\Support\Texto;

require_once dirname(__DIR__) . '/src/bootstrap.php';
SessaoWeb::iniciar();
Autenticacao::exigirEquipe();
header('Cache-Control: no-store');
$pdo = Database::connect();
$gestao = new GestaoRepository($pdo);
$usuarios = new UsuarioRepository($pdo);
$admin = ($_SESSION['equipe_perfil'] ?? '') === 'admin';
$erro = '';
$sucesso = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Seguranca::validarCsrf($_POST['csrf_token'] ?? null);
        $acao = (string) ($_POST['acao'] ?? '');
        if ($acao === 'produto') {
            $gestao->criarProduto($_POST, (int) $_SESSION['equipe_id']);
            $sucesso = 'Livro cadastrado e estoque inicial registrado.';
        } elseif ($acao === 'movimentacao') {
            $gestao->movimentar((int) ($_POST['produto_id'] ?? 0), (int) $_SESSION['equipe_id'], (string) ($_POST['tipo'] ?? ''), (int) ($_POST['quantidade'] ?? 0), (string) ($_POST['observacao'] ?? ''));
            $sucesso = 'Movimentação de estoque registrada.';
        } elseif ($acao === 'situacao') {
            $gestao->alterarAtivo((int) ($_POST['produto_id'] ?? 0));
            $sucesso = 'Situação do livro atualizada.';
        } elseif ($acao === 'usuario' && $admin) {
            $nome = trim((string) ($_POST['nome'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $senha = (string) ($_POST['senha'] ?? '');
            if ($nome === '' || Texto::comprimento($nome) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($senha) < 12) {
                throw new InvalidArgumentException('Informe nome e e-mail válidos e senha com pelo menos 12 caracteres.');
            }
            $usuarios->criar($nome, $email, $senha, (string) ($_POST['perfil'] ?? 'equipe'));
            $sucesso = 'Conta da equipe criada.';
        } else {
            throw new DomainException('Ação não permitida para este perfil.');
        }
    } catch (DomainException|InvalidArgumentException $e) {
        $erro = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Falha na área administrativa: ' . $e->getMessage());
        $erro = 'Não foi possível salvar. Verifique dados duplicados e a conexão do banco.';
    }
}
try {
    $resumo = $gestao->resumo();
    $listaProdutos = $gestao->produtos();
    $baixoEstoque = $gestao->baixoEstoque();
    $pedidos = $gestao->pedidosRecentes();
    $movimentos = $gestao->movimentacoesRecentes();
    $listaEquipe = $admin ? $usuarios->equipe() : [];
} catch (Throwable $e) {
    error_log('Falha ao carregar painel: ' . $e->getMessage());
    http_response_code(500);
    exit('Não foi possível carregar o painel. Confira se database/migrate_gestao_catalogo.sql foi executado.');
}
$csrf = Seguranca::tokenCsrf();
function moedaPainel(int $centavos): string { return 'R$ ' . number_format($centavos / 100, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gestão da livraria</title><link rel="stylesheet" href="assets/app.css"></head>
<body><header class="topo"><div class="container topo__conteudo"><div><p class="eyebrow">Painel de gestão</p><h1>Olá, <?= Seguranca::escapar((string) $_SESSION['equipe_nome']) ?></h1></div><nav class="admin-links"><a class="botao botao--claro" href="index.php">Ver catálogo</a><a class="botao botao--claro" href="exportar_vendas.php">Exportar vendas CSV</a><a class="botao botao--claro" href="admin_logout.php">Sair</a></nav></div></header>
<main class="container admin-main">
<?php if ($erro !== ''): ?><div class="mensagem mensagem--erro"><?= Seguranca::escapar($erro) ?></div><?php endif; ?>
<?php if ($sucesso !== ''): ?><div class="mensagem"><?= Seguranca::escapar($sucesso) ?></div><?php endif; ?>
<section class="metricas">
<?php foreach ([['Livros ativos',$resumo['produtos']],['Unidades em estoque',$resumo['estoque']],['Abaixo do mínimo',$resumo['baixo_estoque']],['Vendas no mês',$resumo['vendas_mes']],['Receita no mês',moedaPainel($resumo['receita_mes'])],['Clientes',$resumo['clientes']]] as [$titulo,$valor]): ?>
<article class="metrica"><span><?= Seguranca::escapar((string) $titulo) ?></span><strong><?= Seguranca::escapar((string) $valor) ?></strong></article>
<?php endforeach; ?>
</section>
<section class="admin-grid">
<article class="painel"><div class="secao-titulo"><div><p class="eyebrow">Acervo</p><h2>Novo livro</h2></div></div>
<form method="post" class="campos admin-form"><input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>"><input type="hidden" name="acao" value="produto">
<label>ISBN <span class="linha-busca"><input id="isbn-busca" name="isbn" maxlength="20" placeholder="ISBN-13"><button id="buscar-isbn" class="botao botao--pequeno" type="button">Buscar dados</button></span></label>
<label>Código<input name="codigo" maxlength="40" required></label><label>Título<input id="livro-nome" name="nome" maxlength="160" required></label>
<label>Autor<input id="livro-autor" name="autor" maxlength="180"></label><label>Editora<input id="livro-editora" name="editora" maxlength="140"></label><label>Categoria<input id="livro-categoria" name="categoria" maxlength="100"></label>
<label>Ano<input id="livro-ano" name="ano_publicacao" type="number" min="1000" max="<?= (int) date('Y') + 2 ?>"></label><label>Preço (R$)<input name="preco" inputmode="decimal" placeholder="29,90" required></label>
<label>Estoque inicial<input name="estoque_inicial" type="number" min="0" value="0" required></label><label>Estoque mínimo<input name="estoque_minimo" type="number" min="0" value="2" required></label><label>Localização<input name="localizacao" maxlength="80" placeholder="Estante A2"></label>
<label class="campo-largo">Imagem (URL)<input id="livro-imagem" name="imagem_url" type="url" maxlength="500"></label><label class="campo-largo">Descrição<textarea id="livro-descricao" name="descricao" maxlength="10000" rows="3"></textarea></label>
<div class="campo-largo"><button class="botao" type="submit">Cadastrar livro</button></div></form></article>
<article class="painel"><div class="secao-titulo"><div><p class="eyebrow">Estoque</p><h2>Registrar movimentação</h2></div></div>
<form method="post" class="campos"><input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>"><input type="hidden" name="acao" value="movimentacao"><label>Livro<select name="produto_id" required><option value="">Selecione</option><?php foreach ($listaProdutos as $produto): if (!(bool) $produto['ativo']) continue; ?><option value="<?= (int) $produto['id'] ?>"><?= Seguranca::escapar($produto['codigo'] . ' — ' . $produto['nome']) ?> (<?= (int) $produto['estoque'] ?>)</option><?php endforeach; ?></select></label><label>Tipo<select name="tipo"><option value="entrada">Entrada</option><option value="saida">Saída / perda</option></select></label><label>Quantidade<input name="quantidade" type="number" min="1" max="100000" required></label><label>Observação<input name="observacao" maxlength="255" placeholder="Compra, ajuste, avaria…"></label><div class="campo-largo"><button class="botao" type="submit">Registrar</button></div></form>
<h3>Alerta de estoque baixo</h3><?php if ($baixoEstoque === []): ?><p class="muted">Nenhum livro abaixo do mínimo.</p><?php else: ?><ul class="lista-alertas"><?php foreach ($baixoEstoque as $livro): ?><li><strong><?= Seguranca::escapar($livro['nome']) ?></strong><span><?= (int) $livro['estoque'] ?> / mínimo <?= (int) $livro['estoque_minimo'] ?></span></li><?php endforeach; ?></ul><?php endif; ?></article>
</section>
<section class="painel"><div class="secao-titulo"><div><p class="eyebrow">Catálogo e estoque</p><h2>Livros cadastrados</h2></div></div><div class="tabela-wrap"><table><thead><tr><th>Código</th><th>Título / ISBN</th><th>Autor / categoria</th><th>Preço</th><th>Estoque</th><th>Situação</th><th></th></tr></thead><tbody>
<?php foreach ($listaProdutos as $produto): ?><tr><td><?= Seguranca::escapar($produto['codigo']) ?></td><td><?= Seguranca::escapar($produto['nome']) ?><br><span class="muted"><?= Seguranca::escapar((string) ($produto['isbn'] ?? '—')) ?></span></td><td><?= Seguranca::escapar((string) ($produto['autor'] ?? '—')) ?><br><span class="muted"><?= Seguranca::escapar((string) ($produto['categoria'] ?? '—')) ?></span></td><td><?= moedaPainel((int) $produto['preco_centavos']) ?></td><td><?= (int) $produto['estoque'] ?> <span class="muted">(mín. <?= (int) $produto['estoque_minimo'] ?>)</span></td><td><?= (bool) $produto['ativo'] ? 'Ativo' : 'Inativo' ?></td><td><a href="editar_livro.php?id=<?= (int) $produto['id'] ?>">Editar</a><form method="post"><input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>"><input type="hidden" name="acao" value="situacao"><input type="hidden" name="produto_id" value="<?= (int) $produto['id'] ?>"><button class="link-perigo" type="submit"><?= (bool) $produto['ativo'] ? 'Desativar' : 'Ativar' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="admin-grid"><article class="painel"><div class="secao-titulo"><div><p class="eyebrow">Pedidos</p><h2>Vendas recentes</h2></div></div><div class="tabela-wrap"><table><thead><tr><th>Pedido</th><th>Cliente</th><th>Data</th><th>Status</th><th>Total</th></tr></thead><tbody><?php foreach ($pedidos as $pedido): ?><tr><td>#<?= (int) $pedido['id'] ?></td><td><?= Seguranca::escapar($pedido['cliente']) ?></td><td><?= Seguranca::escapar($pedido['criado_em']) ?></td><td><?= Seguranca::escapar($pedido['status']) ?></td><td><?= moedaPainel((int) $pedido['valor_total_centavos']) ?></td></tr><?php endforeach; ?></tbody></table></div></article>
<article class="painel"><div class="secao-titulo"><div><p class="eyebrow">Auditoria</p><h2>Movimentações recentes</h2></div></div><div class="tabela-wrap"><table><thead><tr><th>Data</th><th>Livro</th><th>Tipo</th><th>Qtd.</th><th>Equipe</th></tr></thead><tbody><?php foreach ($movimentos as $movimento): ?><tr><td><?= Seguranca::escapar($movimento['criado_em']) ?></td><td><?= Seguranca::escapar($movimento['nome']) ?></td><td><?= Seguranca::escapar(str_replace('_', ' ', $movimento['tipo'])) ?></td><td><?= (int) $movimento['quantidade'] ?></td><td><?= Seguranca::escapar((string) ($movimento['equipe'] ?? 'Sistema')) ?></td></tr><?php endforeach; ?></tbody></table></div></article></section>
<?php if ($admin): ?><section class="admin-grid"><article class="painel"><p class="eyebrow">Permissões</p><h2>Criar conta da equipe</h2><p class="muted">Administradores podem criar outros administradores. Dê esse perfil somente a pessoas autorizadas.</p><form method="post" class="campos"><input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>"><input type="hidden" name="acao" value="usuario"><label>Nome<input name="nome" maxlength="160" required></label><label>E-mail<input type="email" name="email" maxlength="190" required></label><label>Senha inicial<input type="password" name="senha" minlength="12" autocomplete="new-password" required></label><label>Perfil<select name="perfil"><option value="equipe">Equipe</option><option value="admin">Administrador</option></select></label><div><button class="botao" type="submit">Criar conta</button></div></form></article><article class="painel"><p class="eyebrow">Contas da equipe</p><h2>Acessos cadastrados</h2><div class="tabela-wrap"><table><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Ativo</th><th>Último acesso</th></tr></thead><tbody><?php foreach ($listaEquipe as $membro): ?><tr><td><?= Seguranca::escapar($membro['nome']) ?></td><td><?= Seguranca::escapar($membro['email']) ?></td><td><?= Seguranca::escapar($membro['perfil']) ?></td><td><?= (bool) $membro['ativo'] ? 'Sim' : 'Não' ?></td><td><?= Seguranca::escapar((string) ($membro['ultimo_login_em'] ?? '—')) ?></td></tr><?php endforeach; ?></tbody></table></div></article></section><?php endif; ?>
</main><script src="assets/admin.js" defer></script></body></html>
