-- Atualização pontual para bancos já instalados. Faça backup antes de executar.
-- Não execute novamente depois que estas colunas e tabelas tiverem sido criadas.
USE biblioteca_vendas;

ALTER TABLE produtos
    ADD COLUMN isbn VARCHAR(20) NULL UNIQUE AFTER nome,
    ADD COLUMN autor VARCHAR(180) NULL AFTER isbn,
    ADD COLUMN editora VARCHAR(140) NULL AFTER autor,
    ADD COLUMN categoria VARCHAR(100) NULL AFTER editora,
    ADD COLUMN ano_publicacao SMALLINT UNSIGNED NULL AFTER categoria,
    ADD COLUMN descricao TEXT NULL AFTER ano_publicacao,
    ADD COLUMN imagem_url VARCHAR(500) NULL AFTER descricao,
    ADD COLUMN custo_centavos INT UNSIGNED NULL AFTER preco_centavos,
    ADD COLUMN estoque_minimo INT UNSIGNED NOT NULL DEFAULT 2 AFTER estoque,
    ADD COLUMN localizacao VARCHAR(80) NULL AFTER estoque_minimo;

CREATE TABLE usuarios_equipe (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(160) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('admin','equipe') NOT NULL DEFAULT 'equipe',
    ativo BOOLEAN NOT NULL DEFAULT TRUE,
    tentativas_login TINYINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME NULL,
    ultimo_login_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE movimentacoes_estoque (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produto_id BIGINT UNSIGNED NOT NULL,
    equipe_id BIGINT UNSIGNED NULL,
    pedido_id BIGINT UNSIGNED NULL,
    tipo ENUM('entrada','saida','reserva_venda','venda','estorno_venda') NOT NULL,
    quantidade INT UNSIGNED NOT NULL,
    observacao VARCHAR(255) NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mov_estoque_produto FOREIGN KEY (produto_id)
        REFERENCES produtos(id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_mov_estoque_equipe FOREIGN KEY (equipe_id)
        REFERENCES usuarios_equipe(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_mov_estoque_pedido FOREIGN KEY (pedido_id)
        REFERENCES pedidos(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT chk_mov_estoque_quantidade CHECK (quantidade > 0),
    INDEX idx_mov_estoque_produto_data (produto_id, criado_em),
    INDEX idx_mov_estoque_pedido (pedido_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
