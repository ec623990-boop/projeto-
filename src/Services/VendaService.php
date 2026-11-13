<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Contracts\GatewayPagamento;
use BibliotecaVendas\Entities\CarrinhoDeCompras;
use BibliotecaVendas\Entities\Cliente;
use BibliotecaVendas\Entities\ItemPedido;
use BibliotecaVendas\Entities\Pedido;
use BibliotecaVendas\Entities\Produto;
use BibliotecaVendas\Enums\StatusPagamento;
use BibliotecaVendas\Enums\StatusPedido;
use BibliotecaVendas\Repositories\ClienteRepository;
use BibliotecaVendas\Repositories\PedidoRepository;
use BibliotecaVendas\Repositories\ProdutoRepository;
use BibliotecaVendas\ValueObjects\ResultadoPagamento;
use DomainException;
use PDO;
use Throwable;

final class VendaService
{
    public function __construct(
        private PDO $pdo,
        private ProdutoRepository $produtos,
        private ClienteRepository $clientes,
        private PedidoRepository $pedidos,
        private GatewayPagamento $gateway
    ) {}

    /** @param array<string, mixed> $dadosPagamento
     *  @return array{pedidoId: int, pagamento: ResultadoPagamento}
     */
    public function finalizarVenda(Cliente $cliente, CarrinhoDeCompras $carrinho, string $chavePedido, array $dadosPagamento = []): array
    {
        if ($carrinho->estaVazio()) throw new DomainException('Adicione pelo menos um produto ao carrinho.');
        if (!preg_match('/^[a-f0-9]{64}$/', $chavePedido)) throw new DomainException('Chave do pedido inválida.');

        $existing = $this->pedidos->buscarPorChaveIdempotencia($chavePedido);
        if ($existing) {
            $orderId = (int) $existing['id'];
            if ($existing['status'] !== StatusPedido::PENDENTE->value) {
                $storedPayment = $this->pedidos->buscarPagamentoPorPedido($orderId);
                if (!$storedPayment) throw new DomainException('O pedido já foi concluído e não possui pagamento registrado.');
                return ['pedidoId' => $orderId, 'pagamento' => $this->resultadoArmazenado($storedPayment)];
            }
        } else {
            $orderId = $this->criarPedidoPendente($cliente, $carrinho, $chavePedido);
        }

        $pedido = $this->carregarPedido($orderId);
        if ($pedido->status() !== StatusPedido::PENDENTE) return $orderId;

        $paymentKey = hash('sha256', $chavePedido . ':pagamento');
        $resultado = $this->gateway->cobrar($pedido, $paymentKey, $dadosPagamento);
        $this->registrarResultado($pedido, $resultado, $paymentKey);
        return ['pedidoId' => $orderId, 'pagamento' => $resultado];
    }

    public function atualizarPagamento(int $pedidoId, ResultadoPagamento $resultado, string $paymentKey): void
    {
        $pedido = $this->carregarPedido($pedidoId);
        $this->registrarResultado($pedido, $resultado, $paymentKey);
    }

    private function criarPedidoPendente(Cliente $cliente, CarrinhoDeCompras $carrinho, string $key): int
    {
        $this->pdo->beginTransaction();
        try {
            $cartItems = $carrinho->itens();
            $ids = array_map(static fn (ItemPedido $item): int => $item->produto()->id(), $cartItems);
            $products = $this->produtos->buscarVariosParaAtualizarEstoque($ids);
            if (count($products) !== count(array_unique($ids))) {
                throw new DomainException('Um ou mais produtos não estão disponíveis.');
            }

            $orderItems = [];
            $total = 0;
            foreach ($cartItems as $cartItem) {
                $product = $products[$cartItem->produto()->id()];
                if (!$product->possuiEstoque($cartItem->quantidade())) {
                    throw new DomainException('Estoque insuficiente para ' . $product->nome() . '.');
                }
                $item = new ItemPedido($product, $cartItem->quantidade());
                $orderItems[] = $item;
                $total += $item->subtotalCentavos();
            }

            $customerId = $this->clientes->encontrarOuCriar($cliente);
            $insertOrder = $this->pdo->prepare(
                "INSERT INTO pedidos (cliente_id, valor_total_centavos, status, chave_idempotencia)
                 VALUES (?, ?, 'pendente', ?)"
            );
            $insertOrder->execute([$customerId, $total, $key]);
            $orderId = (int) $this->pdo->lastInsertId();

            $insertItem = $this->pdo->prepare(
                'INSERT INTO itens_pedido
                 (pedido_id, produto_id, codigo_produto, nome_produto, preco_unitario_centavos, quantidade)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $decrease = $this->pdo->prepare(
                'UPDATE produtos SET estoque = estoque - ? WHERE id = ? AND estoque >= ?'
            );
            $movement = $this->pdo->prepare(
                "INSERT INTO movimentacoes_estoque (produto_id, pedido_id, tipo, quantidade, observacao)
                 VALUES (?, ?, 'reserva_venda', ?, 'Reserva automática do pedido')"
            );

            foreach ($orderItems as $item) {
                $product = $item->produto();
                $insertItem->execute([
                    $orderId, $product->id(), $product->codigo(), $product->nome(),
                    $product->precoCentavos(), $item->quantidade()
                ]);
                $decrease->execute([$item->quantidade(), $product->id(), $item->quantidade()]);
                if ($decrease->rowCount() !== 1) {
                    throw new DomainException('O estoque mudou durante a compra. Atualize o carrinho.');
                }
                $movement->execute([$product->id(), $orderId, $item->quantidade()]);
            }

            $this->pdo->commit();
            return $orderId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function carregarPedido(int $id): Pedido
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.valor_total_centavos, p.status, p.chave_idempotencia,
                    c.nome, c.identificacao, c.email
             FROM pedidos p JOIN clientes c ON c.id = p.cliente_id WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) throw new DomainException('Pedido não encontrado.');

        $query = $this->pdo->prepare(
            'SELECT produto_id, codigo_produto, nome_produto, preco_unitario_centavos, quantidade
             FROM itens_pedido WHERE pedido_id = ? ORDER BY id'
        );
        $query->execute([$id]);
        $items = [];
        foreach ($query->fetchAll() as $item) {
            $product = new Produto(
                (int) $item['produto_id'],
                (string) $item['codigo_produto'],
                (string) $item['nome_produto'],
                (int) $item['preco_unitario_centavos'],
                0
            );
            $items[] = new ItemPedido($product, (int) $item['quantidade']);
        }

        return new Pedido(
            (int) $row['id'],
            new Cliente((string) $row['nome'], (string) $row['identificacao'], (string) $row['email']),
            $items,
            (int) $row['valor_total_centavos'],
            StatusPedido::from((string) $row['status']),
            (string) $row['chave_idempotencia']
        );
    }

    private function registrarResultado(Pedido $pedido, ResultadoPagamento $resultado, string $paymentKey): void
    {
        $this->pdo->beginTransaction();
        try {
            $lock = $this->pdo->prepare('SELECT status FROM pedidos WHERE id = ? FOR UPDATE');
            $lock->execute([$pedido->id()]);
            if ($lock->fetchColumn() !== StatusPedido::PENDENTE->value) {
                $this->pdo->commit();
                return;
            }

            $payment = $this->pdo->prepare(
                'INSERT INTO pagamentos
                 (pedido_id, provedor, identificador_externo, chave_idempotencia, valor_centavos, status, mensagem, metodo_pagamento, status_detalhe)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    identificador_externo = VALUES(identificador_externo),
                    status = VALUES(status),
                    mensagem = VALUES(mensagem),
                    metodo_pagamento = VALUES(metodo_pagamento),
                    status_detalhe = VALUES(status_detalhe)'
            );
            $payment->execute([
                $pedido->id(), $resultado->provedor, $resultado->identificadorExterno, $paymentKey,
                $pedido->valorTotalCentavos(), $resultado->status->value, $resultado->mensagem,
                $resultado->metodoPagamento, $resultado->statusDetalhe
            ]);

            if ($resultado->status === StatusPagamento::APROVADO) {
                $pedido->marcarComoPago();
                $update = $this->pdo->prepare(
                    "UPDATE pedidos SET status = 'pago', pago_em = CURRENT_TIMESTAMP WHERE id = ?"
                );
                $this->pdo->prepare("UPDATE movimentacoes_estoque SET tipo = 'venda', observacao = 'Venda confirmada pelo pagamento' WHERE pedido_id = ? AND tipo = 'reserva_venda'")->execute([$pedido->id()]);
            } elseif ($resultado->status === StatusPagamento::RECUSADO) {
                $pedido->cancelar();
                $restore = $this->pdo->prepare(
                    'UPDATE produtos p JOIN itens_pedido i ON i.produto_id = p.id
                     SET p.estoque = p.estoque + i.quantidade WHERE i.pedido_id = ?'
                );
                $restore->execute([$pedido->id()]);
                $movement = $this->pdo->prepare(
                    "INSERT INTO movimentacoes_estoque (produto_id, pedido_id, tipo, quantidade, observacao)
                     SELECT produto_id, pedido_id, 'estorno_venda', quantidade, 'Estorno automático de pagamento recusado'
                     FROM movimentacoes_estoque WHERE pedido_id = ? AND tipo = 'reserva_venda'"
                );
                $movement->execute([$pedido->id()]);
                $update = $this->pdo->prepare("UPDATE pedidos SET status = 'cancelado' WHERE id = ?");
            }
            if (isset($update)) $update->execute([$pedido->id()]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $row */
    private function resultadoArmazenado(array $row): ResultadoPagamento
    {
        return new ResultadoPagamento(
            StatusPagamento::from((string) $row['status']),
            (string) $row['identificador_externo'],
            'Resultado de pagamento já registrado.',
            (string) $row['provedor'],
            isset($row['metodo_pagamento']) ? (string) $row['metodo_pagamento'] : null,
            isset($row['status_detalhe']) ? (string) $row['status_detalhe'] : null
        );
    }
}
