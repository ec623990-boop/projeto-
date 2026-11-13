CREATE DATABASE IF NOT EXISTS biblioteca_vendas
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE biblioteca_vendas;

CREATE TABLE IF NOT EXISTS produtos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(40) NOT NULL UNIQUE,
    nome VARCHAR(160) NOT NULL,
    preco_centavos INT UNSIGNED NOT NULL,
    estoque INT UNSIGNED NOT NULL DEFAULT 0,
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_produtos_preco CHECK (preco_centavos > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clientes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(160) NOT NULL,
    identificacao VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL UNIQUE,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pedidos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id BIGINT UNSIGNED NOT NULL,
    valor_total_centavos INT UNSIGNED NOT NULL,
    status ENUM('pendente', 'pago', 'cancelado') NOT NULL DEFAULT 'pendente',
    chave_idempotencia CHAR(64) NOT NULL UNIQUE,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pago_em TIMESTAMP NULL,
    CONSTRAINT fk_pedidos_cliente FOREIGN KEY (cliente_id)
        REFERENCES clientes(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_pedidos_cliente_data (cliente_id, criado_em),
    INDEX idx_pedidos_status_data (status, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS itens_pedido (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id BIGINT UNSIGNED NOT NULL,
    produto_id BIGINT UNSIGNED NOT NULL,
    codigo_produto VARCHAR(40) NOT NULL,
    nome_produto VARCHAR(160) NOT NULL,
    preco_unitario_centavos INT UNSIGNED NOT NULL,
    quantidade INT UNSIGNED NOT NULL,
    CONSTRAINT fk_itens_pedido_pedido FOREIGN KEY (pedido_id)
        REFERENCES pedidos(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_itens_pedido_produto FOREIGN KEY (produto_id)
        REFERENCES produtos(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_item_quantidade CHECK (quantidade > 0),
    INDEX idx_itens_pedido_pedido (pedido_id),
    INDEX idx_itens_pedido_produto (produto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pagamentos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pedido_id BIGINT UNSIGNED NOT NULL,
    provedor VARCHAR(40) NOT NULL,
    identificador_externo VARCHAR(100) NOT NULL UNIQUE,
    chave_idempotencia CHAR(64) NOT NULL UNIQUE,
    valor_centavos INT UNSIGNED NOT NULL,
    status ENUM('pendente', 'aprovado', 'recusado') NOT NULL DEFAULT 'pendente',
    mensagem VARCHAR(255) NOT NULL,
    metodo_pagamento VARCHAR(40) NULL,
    status_detalhe VARCHAR(80) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pagamentos_pedido FOREIGN KEY (pedido_id)
        REFERENCES pedidos(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_pagamentos_pedido (pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

INSERT INTO produtos (codigo, nome, preco_centavos, estoque)
VALUES
    ('LIV-001', 'O Pequeno Príncipe', 2990, 12),
    ('LIV-002', 'Dom Casmurro', 3590, 8),
    ('LIV-003', 'A Arte da Guerra', 2490, 10),
    ('LIV-004', 'Memórias Póstumas de Brás Cubas', 3290, 6)
ON DUPLICATE KEY UPDATE nome = VALUES(nome);
