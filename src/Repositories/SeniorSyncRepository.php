<?php
declare(strict_types=1);

namespace BibliotecaVendas\Repositories;

use PDO;

final class SeniorSyncRepository
{
    public function __construct(private PDO $pdo) {}

    public function tabelaDisponivel(): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(['integracao_senior_pedidos']);
        return (int) $stmt->fetchColumn() === 1;
    }

    public function registrarPendente(int $pedidoId, string $externalId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO integracao_senior_pedidos (pedido_id, external_id, status)
             VALUES (?, ?, 'pendente')
             ON DUPLICATE KEY UPDATE atualizado_em = CURRENT_TIMESTAMP"
        );
        $stmt->execute([$pedidoId, $externalId]);
    }

    public function marcarSincronizado(int $pedidoId, string $seniorOrderId, string $mensagem): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE integracao_senior_pedidos
             SET status = 'sincronizado', senior_order_id = ?, tentativas = tentativas + 1,
                 ultima_mensagem = ?, atualizado_em = CURRENT_TIMESTAMP
             WHERE pedido_id = ?"
        );
        $stmt->execute([$seniorOrderId, substr($mensagem, 0, 500), $pedidoId]);
    }

    public function marcarErro(int $pedidoId, string $mensagem): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE integracao_senior_pedidos
             SET status = 'erro', tentativas = tentativas + 1, ultima_mensagem = ?,
                 atualizado_em = CURRENT_TIMESTAMP
             WHERE pedido_id = ?"
        );
        $stmt->execute([substr($mensagem, 0, 500), $pedidoId]);
    }

    public function buscar(int $pedidoId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT pedido_id, external_id, status, senior_order_id, tentativas, ultima_mensagem, atualizado_em
             FROM integracao_senior_pedidos WHERE pedido_id = ?'
        );
        $stmt->execute([$pedidoId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
