<?php
declare(strict_types=1);

use BibliotecaVendas\Entities\CarrinhoDeCompras;
use BibliotecaVendas\Enums\StatusPedido;
use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\PedidoRepository;
use BibliotecaVendas\Repositories\ProdutoRepository;
use BibliotecaVendas\Repositories\SeniorSyncRepository;
use BibliotecaVendas\Support\SimulacaoLocal;
use BibliotecaVendas\Support\Seguranca;

require_once dirname(__DIR__) . '/src/bootstrap.php';
session_start();

if (empty($_SESSION['chave_pedido'])) $_SESSION['chave_pedido'] = bin2hex(random_bytes(32));

$erro = '';
$pdo = null;
$produtos = [];
$carrinho = new CarrinhoDeCompras();
$pedidoRecibo = null;
$paymentMode = 'simulado_local';
$publicKey = '';
$paymentSchemaReady = false;
$seniorSyncEnabled = false;
$seniorSync = null;
$persistirCarrinho = static function (CarrinhoDeCompras $cart): void {
    $state = [];
    foreach ($cart->itens() as $item) $state[$item->produto()->id()] = $item->quantidade();
    $_SESSION['carrinho'] = $state;
};

try {
    $pdo = Database::connect();
    $paymentMode = trim((string) (getenv('PAYMENT_MODE') ?: 'simulado_local'));
    $publicKey = getenv('MP_TEST_PUBLIC_KEY') ?: '';
    $seniorSyncEnabled = filter_var(getenv('SENIOR_SYNC_ENABLED') ?: 'false', FILTER_VALIDATE_BOOL);
    $produtoRepo = new ProdutoRepository($pdo);
    $pedidoRepo = new PedidoRepository($pdo);

    $paymentColumns = $pdo->query(
        "SELECT COLUMN_NAME, COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pagamentos'"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $paymentSchemaReady = isset($paymentColumns['metodo_pagamento'], $paymentColumns['status_detalhe'])
        && str_contains((string) ($paymentColumns['status'] ?? ''), "'pendente'");

    foreach (($_SESSION['carrinho'] ?? []) as $id => $quantidade) {
        $produto = $produtoRepo->buscarPorId((int) $id);
        if ($produto && (int) $quantidade > 0 && $produto->possuiEstoque((int) $quantidade)) {
            $carrinho->adicionar($produto, (int) $quantidade);
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Seguranca::validarCsrf($_POST['csrf_token'] ?? null);
        $acao = (string) ($_POST['acao'] ?? '');

        if ($acao === 'adicionar') {
            $produto = $produtoRepo->buscarPorId((int) ($_POST['produto_id'] ?? 0));
            if (!$produto) throw new DomainException('Produto não encontrado.');
            $carrinho->adicionar($produto, max(1, (int) ($_POST['quantidade'] ?? 1)));
            $persistirCarrinho($carrinho);
            header('Location: index.php#carrinho');
            exit;
        }

        if ($acao === 'remover') {
            $carrinho->remover((int) ($_POST['produto_id'] ?? 0));
            $persistirCarrinho($carrinho);
            header('Location: index.php#carrinho');
            exit;
        }

        throw new DomainException('Operação não reconhecida.');
    }

    $produtos = $produtoRepo->listarAtivos();
    $idRecibo = filter_input(INPUT_GET, 'recibo', FILTER_VALIDATE_INT);
    if ($idRecibo) {
        $pedidoRecibo = $pedidoRepo->buscarRecibo($idRecibo);
        if ($pedidoRecibo && $paymentMode === 'mercadopago_test') {
            $seniorSync = $_SESSION['senior_sync_result'][$idRecibo] ?? null;
            $syncRepository = new SeniorSyncRepository($pdo);
            if ($syncRepository->tabelaDisponivel()) {
                $seniorSync = $syncRepository->buscar($idRecibo) ?? $seniorSync;
            }
        }
    }
} catch (DomainException|InvalidArgumentException|RuntimeException $e) {
    $erro = $e->getMessage();
} catch (Throwable $e) {
    error_log((string) $e);
    $erro = 'Não foi possível concluir. Confira a conexão com o banco e tente novamente.';
}

$csrf = Seguranca::tokenCsrf();
$itensCarrinho = $carrinho->itens();
if ($pdo instanceof PDO) $persistirCarrinho($carrinho);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Biblioteca Vendas</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topo">
    <div class="container topo__conteudo">
        <div><p class="eyebrow">Projeto de orientação a objetos</p><h1>Livraria da Biblioteca</h1></div>
        <a class="botao botao--claro" href="#carrinho">Carrinho (<?= count($itensCarrinho) ?>)</a>
    </div>
</header>
<main class="container">
    <?php if ($paymentMode === 'mercadopago_test'): ?>
        <section class="aviso"><strong>Sandbox do Mercado Pago:</strong> pagamentos enviados ao ambiente de teste do provedor. Use somente credenciais e cartões de teste.</section>
    <?php elseif ($paymentMode === 'simulado_local' && SimulacaoLocal::requisicaoLocal()): ?>
        <section class="aviso"><strong>Simulação local:</strong> pagamentos fictícios registrados somente neste projeto; nenhuma chamada é feita ao Mercado Pago.</section>
    <?php else: ?>
        <section class="aviso"><strong>Checkout indisponível:</strong> configure um modo de teste válido para continuar.</section>
    <?php endif; ?>
    <?php if ($erro !== ''): ?><div class="mensagem mensagem--erro"><?= Seguranca::escapar($erro) ?></div><?php endif; ?>

    <?php if ($pedidoRecibo): ?>
        <?php $statusLabel = match ($pedidoRecibo->status) {
            StatusPedido::PAGO => 'Pagamento aprovado',
            StatusPedido::CANCELADO => 'Pagamento recusado',
            default => 'Aguardando pagamento',
        }; ?>
        <section class="painel" id="recibo">
            <div class="secao-titulo">
                <div><p class="eyebrow">Recibo</p><h2>Pedido #<?= $pedidoRecibo->pedidoId ?></h2></div>
                <span class="status status--<?= Seguranca::escapar($pedidoRecibo->status->value) ?>"><?= Seguranca::escapar($statusLabel) ?></span>
            </div>
            <p><strong>Cliente:</strong> <?= Seguranca::escapar($pedidoRecibo->clienteNome) ?></p>
            <p><strong>Identificação:</strong> <?= Seguranca::escapar($pedidoRecibo->identificacao) ?></p>
            <p><strong>E-mail:</strong> <?= Seguranca::escapar($pedidoRecibo->email) ?></p>
            <div class="tabela-wrap">
                <table>
                    <thead><tr><th>Produto</th><th>Qtd.</th><th>Unitário</th><th>Subtotal</th></tr></thead>
                    <tbody>
                    <?php foreach ($pedidoRecibo->itens as $item): ?>
                        <tr>
                            <td><?= Seguranca::escapar($item['nome_produto']) ?></td>
                            <td><?= (int) $item['quantidade'] ?></td>
                            <td><?= Seguranca::dinheiro((int) $item['preco_unitario_centavos']) ?></td>
                            <td><?= Seguranca::dinheiro((int) $item['preco_unitario_centavos'] * (int) $item['quantidade']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="total"><span>Total</span><strong><?= Seguranca::dinheiro($pedidoRecibo->totalCentavos) ?></strong></div>
            <?php if ($pedidoRecibo->mensagemPagamento): ?>
                <p class="muted"><?= Seguranca::escapar($pedidoRecibo->mensagemPagamento) ?> · Ref. <?= Seguranca::escapar($pedidoRecibo->identificadorPagamento) ?></p>
            <?php endif; ?>
            <?php if ($paymentMode === 'mercadopago_test' && $pedidoRecibo->status === StatusPedido::PAGO): ?>
                <?php if (($seniorSync['status'] ?? '') === 'sincronizado'): ?>
                    <div class="mensagem"><strong>Senior X:</strong> pedido sincronizado · código <?= Seguranca::escapar((string) ($seniorSync['senior_order_id'] ?? '')) ?></div>
                <?php elseif (!$seniorSyncEnabled): ?>
                    <div class="aviso"><strong>Senior X:</strong> conexão desligada. O pagamento de teste foi aprovado, mas ainda não foi enviado ao ERP.</div>
                <?php else: ?>
                    <div class="mensagem mensagem--erro"><strong>Senior X:</strong> <?= Seguranca::escapar((string) ($seniorSync['ultima_mensagem'] ?? $seniorSync['message'] ?? 'Sincronização pendente.')) ?></div>
                    <form method="post" action="retry_senior_sync.php" class="checkout__acao">
                        <input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>">
                        <input type="hidden" name="order_id" value="<?= $pedidoRecibo->pedidoId ?>">
                        <button class="botao botao--claro" type="submit">Tentar sincronizar com Senior X</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <a class="botao" href="index.php">Voltar à loja</a>
        </section>
    <?php endif; ?>

    <section class="secao">
        <div class="secao-titulo"><div><p class="eyebrow">Catálogo</p><h2>Livros disponíveis</h2></div><p class="muted">Preços consultados no servidor.</p></div>
        <?php if ($produtos === []): ?>
            <div class="painel"><p>Nenhum produto disponível. Importe database/schema.sql para carregar os livros de demonstração.</p></div>
        <?php else: ?>
            <div class="grade-produtos">
                <?php foreach ($produtos as $produto): ?>
                    <article class="produto">
                        <div class="produto__capa">📚</div>
                        <div><p class="codigo"><?= Seguranca::escapar($produto->codigo()) ?></p><h3><?= Seguranca::escapar($produto->nome()) ?></h3>
                            <p class="preco"><?= Seguranca::dinheiro($produto->precoCentavos()) ?></p>
                            <p class="muted">Estoque: <?= $produto->estoque() ?></p>
                        </div>
                        <form method="post" class="form-adicionar">
                            <input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>">
                            <input type="hidden" name="acao" value="adicionar">
                            <input type="hidden" name="produto_id" value="<?= $produto->id() ?>">
                            <label class="sr-only" for="qtd-<?= $produto->id() ?>">Quantidade</label>
                            <input id="qtd-<?= $produto->id() ?>" type="number" name="quantidade" min="1" max="<?= $produto->estoque() ?>" value="1" required>
                            <button class="botao" type="submit">Adicionar</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="secao painel" id="carrinho">
        <div class="secao-titulo"><div><p class="eyebrow">Composição de itens</p><h2>Carrinho de compras</h2></div><span class="muted"><?= count($itensCarrinho) ?> linha(s)</span></div>
        <?php if ($carrinho->estaVazio()): ?>
            <p class="muted">Seu carrinho está vazio.</p>
        <?php else: ?>
            <div class="tabela-wrap">
                <table>
                    <thead><tr><th>Produto</th><th>Qtd.</th><th>Preço</th><th>Subtotal</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($itensCarrinho as $item): ?>
                        <tr>
                            <td><?= Seguranca::escapar($item->produto()->nome()) ?></td>
                            <td><?= $item->quantidade() ?></td>
                            <td><?= Seguranca::dinheiro($item->produto()->precoCentavos()) ?></td>
                            <td><?= Seguranca::dinheiro($item->subtotalCentavos()) ?></td>
                            <td><form method="post">
                                <input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>">
                                <input type="hidden" name="acao" value="remover">
                                <input type="hidden" name="produto_id" value="<?= $item->produto()->id() ?>">
                                <button class="link-perigo" type="submit">Remover</button>
                            </form></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="total"><span>Total do pedido</span><strong><?= Seguranca::dinheiro($carrinho->totalCentavos()) ?></strong></div>
            <section class="checkout" aria-labelledby="checkoutTitle">
                <h3 id="checkoutTitle">Pagamento de teste</h3>
                <?php if ($paymentMode === 'simulado_local' && SimulacaoLocal::requisicaoLocal()): ?>
                    <?php if (!$paymentSchemaReady): ?>
                        <div class="mensagem mensagem--erro">
                            Faça backup do banco e execute <code>database/migrate_mercadopago_sandbox.sql</code> uma única vez. O simulador usa as colunas de pagamento dessa migração.
                        </div>
                    <?php else: ?>
                        <div class="mensagem">
                            <strong>Simulação local:</strong> não chama o Mercado Pago, não cobra e não gera um Pix válido. Os dados de cartão ficam somente neste navegador e não são enviados nem salvos.
                        </div>
                        <form id="localSimulationForm" class="campos checkout__cliente">
                            <label>Nome de teste<input name="name" maxlength="160" value="Cliente de Teste" required></label>
                            <label>CPF fictício<input name="identification" inputmode="numeric" maxlength="11" pattern="\d{11}" value="00000000000" required></label>
                            <label>E-mail de teste<input name="email" type="email" maxlength="190" value="teste@example.invalid" required></label>
                            <label>Forma de pagamento
                                <select id="simulationMethod" name="payment_method_id" required>
                                    <option value="credit_card">Cartão de crédito (simulado)</option>
                                    <option value="debit_card">Cartão de débito (simulado)</option>
                                    <option value="pix">Pix (simulado)</option>
                                </select>
                            </label>
                            <fieldset id="simulationCardPanel" class="checkout__cartao">
                                <legend>Dados do cartão de teste</legend>
                                <div class="campos">
                                    <label>Cartão de teste do Mercado Pago
                                        <select id="simulationCardPreset">
                                            <option value="">Escolha um cartão de teste</option>
                                            <option value="mastercard">Mastercard — crédito</option>
                                            <option value="visa">Visa — crédito</option>
                                            <option value="amex">American Express — crédito</option>
                                            <option value="elo">Elo — débito</option>
                                        </select>
                                    </label>
                                    <label>Número do cartão<input id="simulationCardNumber" inputmode="numeric" autocomplete="off" maxlength="23" placeholder="Número de teste" required></label>
                                    <label>Validade (MM/AA)<input id="simulationCardExpiry" inputmode="numeric" autocomplete="off" maxlength="5" placeholder="11/30" required></label>
                                    <label>Código de segurança<input id="simulationCardCvv" inputmode="numeric" autocomplete="off" maxlength="4" placeholder="123" required></label>
                                </div>
                                <p class="muted">Selecione um cartão para preencher os dados oficiais de teste. Neste modo local eles servem apenas para validar o formulário; o resultado vem do seletor abaixo. <a href="https://www.mercadopago.com.br/developers/pt/docs/checkout-bricks/integration-test/test-cards?scope=prod" target="_blank" rel="noopener noreferrer">Ver cartões de teste oficiais</a>.</p>
                            </fieldset>
                            <label>Resultado simulado
                                <select name="simulation_status" required>
                                    <option value="aprovado">Aprovado</option>
                                    <option value="recusado">Recusado</option>
                                </select>
                            </label>
                            <div class="checkout__acao"><button class="botao" type="submit">Simular pagamento</button></div>
                        </form>
                        <div id="simulationFeedback" class="mensagem" role="status" aria-live="polite" hidden></div>
                        <section id="simulationResult" class="painel pagamento-resultado" aria-live="polite" hidden>
                            <h4 id="simulationStatus"></h4>
                            <p id="simulationMessage" class="muted"></p>
                            <label id="simulationPixLabel" class="campo-pagamento" hidden>Código Pix fictício — não pagável
                                <textarea id="simulationPixCode" rows="3" readonly></textarea>
                            </label>
                            <button id="confirmSimulationPix" class="botao botao--claro" type="button" hidden>Simular confirmação do Pix</button>
                            <a id="simulationReceipt" class="botao" href="#" hidden>Ver pedido de teste</a>
                            <button id="simulationRetry" class="botao botao--claro" type="button" hidden>Simular outra tentativa</button>
                        </section>
                        <script>
                        (() => {
                            const form = document.getElementById('localSimulationForm');
                            const feedback = document.getElementById('simulationFeedback');
                            const result = document.getElementById('simulationResult');
                            const methodSelect = document.getElementById('simulationMethod');
                            const cardPanel = document.getElementById('simulationCardPanel');
                            const cardPreset = document.getElementById('simulationCardPreset');
                            const cardNumber = document.getElementById('simulationCardNumber');
                            const cardExpiry = document.getElementById('simulationCardExpiry');
                            const cardCvv = document.getElementById('simulationCardCvv');
                            const csrfToken = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                            let currentOrderId = null;
                            const testCards = {
                                mastercard: { number: '5480832801033311', expiry: '11/30', cvv: '123', method: 'credit_card' },
                                visa: { number: '4235647728025682', expiry: '11/30', cvv: '123', method: 'credit_card' },
                                amex: { number: '375365153556885', expiry: '11/30', cvv: '1234', method: 'credit_card' },
                                elo: { number: '5067766783888311', expiry: '11/30', cvv: '123', method: 'debit_card' },
                            };

                            function sincronizarCamposCartao() {
                                const pix = methodSelect.value === 'pix';
                                cardPanel.hidden = pix;
                                [cardPreset, cardNumber, cardExpiry, cardCvv].forEach((field) => {
                                    field.disabled = pix;
                                    field.required = !pix;
                                });
                                if (!pix && cardPreset.value && testCards[cardPreset.value]?.method !== methodSelect.value) {
                                    cardPreset.value = '';
                                    cardNumber.value = '';
                                    cardExpiry.value = '';
                                    cardCvv.value = '';
                                }
                            }

                            function preencherCartaoDeTeste() {
                                const card = testCards[cardPreset.value];
                                if (!card) return;
                                if (card.method !== methodSelect.value) {
                                    methodSelect.value = card.method;
                                    sincronizarCamposCartao();
                                }
                                cardNumber.value = card.number.replace(/(\d{4})(?=\d)/g, '$1 ');
                                cardExpiry.value = card.expiry;
                                cardCvv.value = card.cvv;
                            }

                            function validarCartaoDeTeste() {
                                if (methodSelect.value === 'pix') return '';
                                const number = cardNumber.value.replace(/\D/g, '');
                                const card = Object.values(testCards).find((item) => item.number === number);
                                if (!card) return 'Use um dos números oficiais de cartão de teste do Mercado Pago.';
                                if (card.method !== methodSelect.value) return 'Esse cartão de teste não corresponde ao método selecionado.';
                                if (cardExpiry.value !== card.expiry) return 'Use a validade indicada para esse cartão de teste.';
                                if (cardCvv.value !== card.cvv) return 'Use o código de segurança indicado para esse cartão de teste.';
                                return '';
                            }

                            function limparDadosCartao() {
                                cardPreset.value = '';
                                cardNumber.value = '';
                                cardExpiry.value = '';
                                cardCvv.value = '';
                            }

                            methodSelect.addEventListener('change', sincronizarCamposCartao);
                            cardPreset.addEventListener('change', preencherCartaoDeTeste);
                            sincronizarCamposCartao();

                            function showFeedback(message, isError = false) {
                                feedback.textContent = message;
                                feedback.hidden = false;
                                feedback.classList.toggle('mensagem--erro', isError);
                            }

                            function showResult(data) {
                                result.hidden = false;
                                const pending = data.status === 'pendente';
                                document.getElementById('simulationStatus').textContent = pending
                                    ? 'Pagamento pendente (simulado)'
                                    : data.status === 'aprovado' ? 'Pagamento aprovado (simulado)' : 'Pagamento recusado (simulado)';
                                document.getElementById('simulationMessage').textContent = data.message || '';
                                const hasPixCode = typeof data.qr_code === 'string' && data.qr_code.length > 0;
                                document.getElementById('simulationPixLabel').hidden = !hasPixCode;
                                document.getElementById('simulationPixCode').value = hasPixCode ? data.qr_code : '';
                                document.getElementById('confirmSimulationPix').hidden = !pending;
                                const receipt = document.getElementById('simulationReceipt');
                                receipt.hidden = !data.order_id;
                                if (data.order_id) receipt.href = 'index.php?recibo=' + encodeURIComponent(data.order_id) + '#recibo';
                                document.getElementById('simulationRetry').hidden = data.status !== 'recusado';
                                form.hidden = data.status === 'aprovado';
                            }

                            async function enviar(url, body) {
                                const response = await fetch(url, {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify(body),
                                });
                                const data = await response.json();
                                if (!response.ok) throw new Error(data.message || 'Não foi possível concluir a simulação.');
                                return data;
                            }

                            form.addEventListener('submit', async (event) => {
                                event.preventDefault();
                                if (!form.reportValidity()) return;
                                const cardError = validarCartaoDeTeste();
                                if (cardError) {
                                    showFeedback(cardError, true);
                                    return;
                                }
                                const fields = new FormData(form);
                                const customer = {
                                    name: String(fields.get('name')).trim(),
                                    identification: String(fields.get('identification')),
                                    email: String(fields.get('email')).trim(),
                                };
                                const payment = {
                                    payment_method_id: String(fields.get('payment_method_id')),
                                    simulation_status: String(fields.get('simulation_status')),
                                };
                                showFeedback('Registrando pagamento fictício no banco local…');
                                try {
                                    const data = await enviar('process_payment.php', { csrf_token: csrfToken, customer, payment });
                                    limparDadosCartao();
                                    currentOrderId = data.order_id;
                                    showResult(data);
                                    feedback.hidden = true;
                                } catch (error) {
                                    showFeedback(error.message || 'Falha na simulação local.', true);
                                }
                            });

                            document.getElementById('confirmSimulationPix').addEventListener('click', async () => {
                                if (!currentOrderId) return;
                                showFeedback('Confirmando o resultado fictício do Pix…');
                                try {
                                    const data = await enviar('refresh_payment.php', { csrf_token: csrfToken });
                                    showResult(data);
                                    feedback.hidden = true;
                                } catch (error) {
                                    showFeedback(error.message || 'Falha ao confirmar a simulação do Pix.', true);
                                }
                            });

                            document.getElementById('simulationRetry').addEventListener('click', () => {
                                result.hidden = true;
                                currentOrderId = null;
                                showFeedback('Escolha o método e o resultado para outra simulação.');
                            });
                        })();
                        </script>
                    <?php endif; ?>
                <?php elseif ($paymentMode === 'mercadopago_test'): ?>
                    <?php if ($publicKey === ''): ?>
                        <div class="mensagem mensagem--erro">
                            Configure <code>MP_TEST_PUBLIC_KEY</code> e <code>MP_TEST_ACCESS_TOKEN</code> no arquivo <code>.env</code> para usar a API de teste do Mercado Pago.
                        </div>
                    <?php elseif (!$paymentSchemaReady): ?>
                        <div class="mensagem mensagem--erro">
                            Faça backup do banco e execute <code>database/migrate_mercadopago_sandbox.sql</code> uma única vez antes de testar pagamentos.
                        </div>
                    <?php else: ?>
                    <p class="muted">O formulário seguro do Mercado Pago oferece cartão de crédito, débito e Pix. Os dados do cartão são tokenizados pelo provedor.</p>
                    <form id="customerForm" class="campos checkout__cliente">
                        <label>Nome completo<input id="customerName" maxlength="160" autocomplete="name" required></label>
                        <label>CPF de teste<input id="customerIdentification" inputmode="numeric" autocomplete="off" maxlength="14" pattern="\d{3}\.?\d{3}\.?\d{3}-?\d{2}" required></label>
                        <label>E-mail de teste<input id="customerEmail" type="email" maxlength="190" autocomplete="email" required></label>
                        <div class="checkout__acao"><button class="botao" type="submit">Continuar para pagamento</button></div>
                    </form>
                    <div id="paymentStep" hidden>
                        <p class="muted">Escolha crédito, débito ou Pix no formulário abaixo.</p>
                        <div id="paymentBrick_container"></div>
                        <div id="paymentFeedback" class="mensagem" role="status" aria-live="polite" hidden></div>
                        <section id="paymentResult" class="painel pagamento-resultado" aria-live="polite" hidden>
                            <h4 id="paymentStatus"></h4>
                            <p id="paymentMessage" class="muted"></p>
                            <img id="pixQr" alt="QR Code Pix de teste" hidden>
                            <label id="pixCodeLabel" class="campo-pagamento" hidden>Pix Copia e Cola
                                <textarea id="pixCode" rows="4" readonly></textarea>
                            </label>
                            <button id="copyPix" class="botao botao--claro" type="button" hidden>Copiar código Pix</button>
                            <button id="refreshPayment" class="botao botao--claro" type="button" hidden>Consultar status</button>
                            <a id="receiptLink" class="botao" href="#" hidden>Ver pedido</a>
                            <button id="retryPayment" class="botao botao--claro" type="button" hidden>Tentar outro pagamento</button>
                        </section>
                    </div>
                    <script src="https://sdk.mercadopago.com/js/v2"></script>
                    <script>
                    (() => {
                        const publicKey = <?= json_encode($publicKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                        const csrfToken = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                        const amount = <?= json_encode(round($carrinho->totalCentavos() / 100, 2)) ?>;
                        const customerForm = document.getElementById('customerForm');
                        const paymentStep = document.getElementById('paymentStep');
                        const feedback = document.getElementById('paymentFeedback');
                        const resultBox = document.getElementById('paymentResult');
                        let lastOrderId = null;
                        let refreshTimer = null;

                        function showFeedback(message, isError = false) {
                            feedback.textContent = message;
                            feedback.hidden = false;
                            feedback.classList.toggle('mensagem--erro', isError);
                        }

                        function showResult(data) {
                            resultBox.hidden = false;
                            document.getElementById('paymentStatus').textContent = data.status === 'aprovado'
                                ? 'Pagamento aprovado'
                                : data.status === 'recusado' ? 'Pagamento recusado' : 'Pagamento pendente';
                            document.getElementById('paymentMessage').textContent = data.message || '';
                            const qr = document.getElementById('pixQr');
                            const code = document.getElementById('pixCode');
                            const hasQr = typeof data.qr_code_base64 === 'string' && /^[A-Za-z0-9+/=]+$/.test(data.qr_code_base64);
                            qr.hidden = !hasQr;
                            if (hasQr) qr.src = 'data:image/png;base64,' + data.qr_code_base64;
                            const hasCode = typeof data.qr_code === 'string' && data.qr_code.length > 0;
                            document.getElementById('pixCodeLabel').hidden = !hasCode;
                            document.getElementById('copyPix').hidden = !hasCode;
                            if (hasCode) code.value = data.qr_code;

                            const refreshButton = document.getElementById('refreshPayment');
                            refreshButton.hidden = data.status !== 'pendente';
                            const receipt = document.getElementById('receiptLink');
                            receipt.hidden = !data.order_id;
                            if (data.order_id) receipt.href = 'index.php?recibo=' + encodeURIComponent(data.order_id) + '#recibo';
                            document.getElementById('retryPayment').hidden = data.status !== 'recusado';
                            if (data.status === 'pendente') {
                                if (refreshTimer) window.clearTimeout(refreshTimer);
                                refreshTimer = window.setTimeout(() => consultarPagamento(false), 15000);
                            } else if (refreshTimer) {
                                window.clearTimeout(refreshTimer);
                                refreshTimer = null;
                            }
                            if (data.status === 'aprovado' && data.order_id) {
                                window.location.href = receipt.href;
                            }
                        }

                        async function enviarPagamento(formData) {
                            const customer = {
                                name: document.getElementById('customerName').value.trim(),
                                identification: document.getElementById('customerIdentification').value,
                                email: document.getElementById('customerEmail').value.trim(),
                            };
                            const response = await fetch('process_payment.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ csrf_token: csrfToken, customer, payment: formData }),
                            });
                            const data = await response.json();
                            if (!response.ok) throw new Error(data.message || 'Não foi possível criar o pagamento de teste.');
                            lastOrderId = data.order_id;
                            showResult(data);
                            return data;
                        }

                        async function consultarPagamento(showErrors = true) {
                            if (!lastOrderId) return;
                            try {
                                const response = await fetch('refresh_payment.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ csrf_token: csrfToken }),
                                });
                                const data = await response.json();
                                if (!response.ok) throw new Error(data.message || 'Não foi possível consultar o pagamento.');
                                showResult(data);
                            } catch (error) {
                                if (showErrors) showFeedback(error.message || 'Falha ao consultar o pagamento.', true);
                            }
                        }

                        document.getElementById('copyPix').addEventListener('click', async () => {
                            try {
                                await navigator.clipboard.writeText(document.getElementById('pixCode').value);
                                showFeedback('Código Pix copiado.');
                            } catch {
                                showFeedback('Não foi possível copiar automaticamente; selecione o código Pix.', true);
                            }
                        });
                        document.getElementById('refreshPayment').addEventListener('click', () => consultarPagamento(true));
                        document.getElementById('retryPayment').addEventListener('click', () => window.location.reload());

                        customerForm.addEventListener('submit', async (event) => {
                            event.preventDefault();
                            if (!customerForm.reportValidity()) return;
                            const documentDigits = document.getElementById('customerIdentification').value.replace(/\D/g, '');
                            if (documentDigits.length !== 11) {
                                showFeedback('Informe um CPF de teste com 11 dígitos.', true);
                                return;
                            }
                            customerForm.hidden = true;
                            paymentStep.hidden = false;
                            showFeedback('Carregando o formulário seguro do Mercado Pago…');
                            try {
                                const mp = new MercadoPago(publicKey, { locale: 'pt-BR' });
                                const bricksBuilder = mp.bricks();
                                const settings = {
                                    initialization: {
                                        amount,
                                        payer: {
                                            email: document.getElementById('customerEmail').value.trim(),
                                            identification: { type: 'CPF', number: documentDigits },
                                        },
                                    },
                                    customization: {
                                        paymentMethods: { creditCard: 'all', debitCard: 'all', bankTransfer: ['pix'] },
                                    },
                                    callbacks: {
                                        onReady: () => showFeedback('Escolha a forma de pagamento para continuar.'),
                                        onError: (error) => {
                                            console.error(error);
                                            showFeedback('O formulário de pagamento não carregou. Confira a internet e as credenciais de teste.', true);
                                        },
                                        onSubmit: async ({ formData }) => {
                                            try {
                                                await enviarPagamento(formData);
                                                return Promise.resolve();
                                            } catch (error) {
                                                showFeedback(error.message || 'Não foi possível criar o pagamento de teste.', true);
                                                return Promise.reject(error);
                                            }
                                        },
                                    },
                                };
                                await bricksBuilder.create('payment', 'paymentBrick_container', settings);
                                feedback.hidden = true;
                            } catch (error) {
                                showFeedback(error.message || 'Não foi possível iniciar o formulário de teste.', true);
                                customerForm.hidden = false;
                                paymentStep.hidden = true;
                            }
                        });
                    })();
                    </script>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="mensagem mensagem--erro">
                        A simulação local só funciona acessando a loja neste computador. Para usar o sandbox externo, configure <code>PAYMENT_MODE=mercadopago_test</code> e as credenciais de teste.
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </section>
</main>
<footer class="rodape">Biblioteca Vendas · Demonstração acadêmica de classes e orientação a objetos</footer>
</body>
</html>
