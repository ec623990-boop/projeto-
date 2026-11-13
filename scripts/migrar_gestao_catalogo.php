<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Este script só pode ser executado pelo terminal.');
}

require_once dirname(__DIR__) . '/src/bootstrap.php';

function tabelaExiste(PDO $pdo, string $tabela): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$tabela]);
    return (int) $stmt->fetchColumn() > 0;
}

/** @return array<string, array<string, mixed>> */
function colunasDaTabela(PDO $pdo, string $tabela): array
{
    $consultas = [
        'produtos' => 'SHOW COLUMNS FROM produtos',
        'pedidos' => 'SHOW COLUMNS FROM pedidos',
        'usuarios_equipe' => 'SHOW COLUMNS FROM usuarios_equipe',
        'movimentacoes_estoque' => 'SHOW COLUMNS FROM movimentacoes_estoque',
    ];
    if (!isset($consultas[$tabela])) throw new RuntimeException('Tabela não permitida.');
    $colunas = [];
    foreach ($pdo->query($consultas[$tabela])->fetchAll(PDO::FETCH_ASSOC) as $coluna) {
        $colunas[(string) $coluna['Field']] = $coluna;
    }
    return $colunas;
}

function tipoDeChaveEstrangeira(array $coluna): string
{
    $tipo = strtolower((string) ($coluna['Type'] ?? ''));
    if (!preg_match('/^(bigint|int)(?:\(\d+\))?( unsigned)?/', $tipo, $match)) {
        throw new RuntimeException('O tipo da coluna de identificador não é compatível com a migração automática.');
    }
    return strtoupper($match[1]) . (!empty($match[2]) ? ' UNSIGNED' : '');
}

try {
    $pdo = Database::connect();
    $banco = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($banco === '') throw new RuntimeException('Nenhum banco está selecionado na configuração local.');
    if (!tabelaExiste($pdo, 'produtos')) {
        throw new RuntimeException('A tabela produtos não existe. Para um banco novo, importe database/schema.sql.');
    }
    if (!tabelaExiste($pdo, 'pedidos')) {
        throw new RuntimeException('A tabela pedidos não existe. Confira a instalação-base antes de migrar.');
    }

    $colunasProdutos = colunasDaTabela($pdo, 'produtos');
    $colunasEquipe = tabelaExiste($pdo, 'usuarios_equipe') ? colunasDaTabela($pdo, 'usuarios_equipe') : [];
    $colunasMovimentos = tabelaExiste($pdo, 'movimentacoes_estoque') ? colunasDaTabela($pdo, 'movimentacoes_estoque') : [];
    $colunasPedidos = colunasDaTabela($pdo, 'pedidos');

    $colunasNovas = [
        'isbn' => 'VARCHAR(20) NULL',
        'autor' => 'VARCHAR(180) NULL',
        'editora' => 'VARCHAR(140) NULL',
        'categoria' => 'VARCHAR(100) NULL',
        'ano_publicacao' => 'SMALLINT UNSIGNED NULL',
        'descricao' => 'TEXT NULL',
        'imagem_url' => 'VARCHAR(500) NULL',
        'custo_centavos' => 'INT UNSIGNED NULL',
        'estoque_minimo' => 'INT UNSIGNED NOT NULL DEFAULT 2',
        'localizacao' => 'VARCHAR(80) NULL',
    ];
    $faltantes = array_diff_key($colunasNovas, $colunasProdutos);
    $indiceIsbnExiste = false;
    if (isset($colunasProdutos['isbn'])) {
        $indiceStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND NON_UNIQUE = 0'
        );
        $indiceStmt->execute(['produtos', 'isbn']);
        $indiceIsbnExiste = (int) $indiceStmt->fetchColumn() > 0;
        if (!$indiceIsbnExiste) {
            $duplicatas = $pdo->query(
                "SELECT isbn, COUNT(*) AS quantidade FROM produtos
                 WHERE isbn IS NOT NULL AND TRIM(isbn) <> ''
                 GROUP BY isbn HAVING COUNT(*) > 1 LIMIT 5"
            )->fetchAll(PDO::FETCH_ASSOC);
            if ($duplicatas !== []) {
                $lista = implode(', ', array_map(
                    static fn (array $linha): string => (string) $linha['isbn'],
                    $duplicatas
                ));
                throw new RuntimeException('Há ISBNs duplicados; resolva-os antes de criar o índice único: ' . $lista);
            }
        }
    }

    $esperadasEquipe = ['id', 'nome', 'email', 'senha_hash', 'perfil', 'ativo', 'tentativas_login', 'bloqueado_ate', 'ultimo_login_em', 'criado_em'];
    if ($colunasEquipe !== [] && array_diff($esperadasEquipe, array_keys($colunasEquipe)) !== []) {
        throw new RuntimeException('A tabela usuarios_equipe já existe, mas está incompleta. Faça uma análise manual antes de continuar.');
    }
    $esperadasMovimento = ['id', 'produto_id', 'equipe_id', 'pedido_id', 'tipo', 'quantidade', 'observacao', 'criado_em'];
    if ($colunasMovimentos !== [] && array_diff($esperadasMovimento, array_keys($colunasMovimentos)) !== []) {
        throw new RuntimeException('A tabela movimentacoes_estoque já existe, mas está incompleta. Faça uma análise manual antes de continuar.');
    }

    $acoes = [];
    foreach ($faltantes as $nome => $tipo) $acoes[] = 'adicionar coluna produtos.' . $nome;
    if (!$indiceIsbnExiste) $acoes[] = 'criar índice único para produtos.isbn';
    if ($colunasEquipe === []) $acoes[] = 'criar tabela usuarios_equipe';
    if ($colunasMovimentos === []) $acoes[] = 'criar tabela movimentacoes_estoque';
    if ($acoes === []) {
        echo 'A estrutura de gestão do catálogo já está completa em ' . $banco . '.' . PHP_EOL;
        exit(0);
    }

    echo 'Banco selecionado: ' . $banco . PHP_EOL;
    echo 'Este script adicionará apenas os itens ausentes:' . PHP_EOL;
    foreach ($acoes as $acao) echo ' - ' . $acao . PHP_EOL;
    echo 'Faça um backup completo antes. Para prosseguir, digite APLICAR: ';
    $confirmacao = trim((string) fgets(STDIN));
    if ($confirmacao !== 'APLICAR') {
        echo 'Nenhuma alteração foi feita.' . PHP_EOL;
        exit(1);
    }

    foreach ($faltantes as $nome => $tipo) {
        $pdo->exec('ALTER TABLE produtos ADD COLUMN ' . $nome . ' ' . $tipo);
        echo 'Coluna produtos.' . $nome . ' criada.' . PHP_EOL;
    }
    if (!$indiceIsbnExiste) {
        $pdo->exec('ALTER TABLE produtos ADD UNIQUE INDEX uq_produtos_isbn (isbn)');
        echo 'Índice único de ISBN criado.' . PHP_EOL;
    }

    if ($colunasEquipe === []) {
        $pdo->exec(
            "CREATE TABLE usuarios_equipe (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo 'Tabela usuarios_equipe criada.' . PHP_EOL;
    }

    if ($colunasMovimentos === []) {
        $produtoId = tipoDeChaveEstrangeira($colunasProdutos['id'] ?? []);
        $pedidoId = tipoDeChaveEstrangeira($colunasPedidos['id'] ?? []);
        $equipeId = $colunasEquipe === [] ? 'BIGINT UNSIGNED' : tipoDeChaveEstrangeira($colunasEquipe['id'] ?? []);
        $pdo->exec(
            "CREATE TABLE movimentacoes_estoque (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                produto_id {$produtoId} NOT NULL,
                equipe_id {$equipeId} NULL,
                pedido_id {$pedidoId} NULL,
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        echo 'Tabela movimentacoes_estoque criada.' . PHP_EOL;
    }

    echo 'Migração concluída. Agora crie o primeiro administrador em /configurar_admin.php.' . PHP_EOL;
} catch (Throwable $e) {
    error_log('Falha na migração segura do catálogo: ' . $e->getMessage());
    fwrite(STDERR, 'Migração interrompida: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
