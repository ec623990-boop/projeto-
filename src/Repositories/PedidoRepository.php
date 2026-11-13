<?php
declare(strict_types=1);

namespace BibliotecaVendas\Repositories;

use BibliotecaVendas\Entities\Recibo;
use PDO;

final class PedidoRepository
{
    public function __construct(private PDO $pdo) {}

    public function buscarRecibo(int $id): ?Recibo
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.valor_total_centavos, p.status, c.nome AS cliente_nome,
                    c.identificacao, c.email, pg.identificador_externo, pg.mensagem
             FROM pedidos p
             JOIN clientes c ON c.id = p.cliente_id
             LEFT JOIN pagamentos pg ON pg.pedido_id = p.id
             WHERE p.id = ?
             ORDER BY pg.id DESC LIMIT 1'
        );
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) return null;

        $items = $this->pdo->prepare(
            'SELECT codigo_produto, nome_produto, preco_unitario_centavos, quantidade
             FROM itens_pedido WHERE pedido_id = ? ORDER BY id'
        );
        $items->execute([$id]);
        $order['itens'] = $items->fetchAll();
        return Recibo::aPartirDaConsulta($order);
    }

    public function buscarPorChaveIdempotencia(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, status FROM pedidos WHERE chave_idempotencia = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function buscarPagamentoPorPedido(int $pedidoId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT provedor, identificador_externo, chave_idempotencia, valor_centavos,
                    status, metodo_pagamento, status_detalhe
             FROM pagamentos WHERE pedido_id = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$pedidoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
