-- Faça backup do banco antes de executar esta migração.
-- A tabela mantém o resultado da integração de pedidos com o Senior X.

CREATE TABLE IF NOT EXISTS integracao_senior_pedidos (
    pedido_id BIGINT UNSIGNED PRIMARY KEY,
    external_id VARCHAR(36) NOT NULL UNIQUE,
    status ENUM('pendente', 'sincronizado', 'erro') NOT NULL DEFAULT 'pendente',
    senior_order_id VARCHAR(100) NULL,
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ultima_mensagem VARCHAR(500) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_senior_sync_pedido FOREIGN KEY (pedido_id)
        REFERENCES pedidos(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_senior_sync_status_data (status, atualizado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
