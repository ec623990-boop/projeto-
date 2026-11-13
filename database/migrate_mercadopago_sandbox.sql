-- Faça backup do banco biblioteca_vendas antes de executar.
-- Execute uma única vez em uma instalação existente para habilitar o sandbox de pagamentos.
ALTER TABLE pagamentos
    MODIFY COLUMN status ENUM('pendente', 'aprovado', 'recusado') NOT NULL DEFAULT 'pendente',
    ADD COLUMN metodo_pagamento VARCHAR(40) NULL AFTER mensagem,
    ADD COLUMN status_detalhe VARCHAR(80) NULL AFTER metodo_pagamento;
