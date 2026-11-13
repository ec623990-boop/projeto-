<?php
declare(strict_types=1);

namespace BibliotecaVendas\Repositories;

use BibliotecaVendas\Support\Isbn;
use BibliotecaVendas\Support\Texto;
use DomainException;
use PDO;
use Throwable;

final class GestaoRepository
{
    public function __construct(private PDO $pdo) {}

    public function resumo(): array
    {
        return [
            'produtos' => (int) $this->pdo->query('SELECT COUNT(*) FROM produtos WHERE ativo = 1')->fetchColumn(),
            'estoque' => (int) $this->pdo->query('SELECT COALESCE(SUM(estoque),0) FROM produtos WHERE ativo = 1')->fetchColumn(),
            'baixo_estoque' => (int) $this->pdo->query('SELECT COUNT(*) FROM produtos WHERE ativo = 1 AND estoque <= estoque_minimo')->fetchColumn(),
            'vendas_mes' => (int) $this->pdo->query("SELECT COUNT(*) FROM pedidos WHERE status = 'pago' AND pago_em >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')")->fetchColumn(),
            'receita_mes' => (int) $this->pdo->query("SELECT COALESCE(SUM(valor_total_centavos),0) FROM pedidos WHERE status = 'pago' AND pago_em >= DATE_FORMAT(CURRENT_DATE, '%Y-%m-01')")->fetchColumn(),
            'clientes' => (int) $this->pdo->query('SELECT COUNT(*) FROM clientes')->fetchColumn(),
        ];
    }

    public function produtos(): array
    {
        return $this->pdo->query('SELECT id, codigo, nome, isbn, autor, categoria, imagem_url, preco_centavos, estoque, estoque_minimo, ativo FROM produtos ORDER BY nome')->fetchAll();
    }

    public function produto(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM produtos WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function atualizarProduto(int $id, array $dados): void
    {
        $codigo = strtoupper(trim((string) ($dados['codigo'] ?? '')));
        $nome = trim((string) ($dados['nome'] ?? ''));
        $isbn = Isbn::normalizar((string) ($dados['isbn'] ?? ''));
        $ano = trim((string) ($dados['ano_publicacao'] ?? ''));
        $preco = $this->parseDinheiro((string) ($dados['preco'] ?? ''));
        $minimo = filter_var($dados['estoque_minimo'] ?? 2, FILTER_VALIDATE_INT);
        if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $codigo)) throw new DomainException('Código do livro inválido.');
        if ($nome === '' || Texto::comprimento($nome) > 160) throw new DomainException('Informe um título válido.');
        if ($isbn !== '' && !Isbn::valido($isbn)) throw new DomainException('O ISBN informado não passou na validação.');
        if ($preco < 1 || $minimo === false || $minimo < 0 || $minimo > 1000000) throw new DomainException('Preço ou estoque mínimo inválido.');
        if ($ano !== '' && (!ctype_digit($ano) || (int) $ano < 1000 || (int) $ano > (int) date('Y') + 2)) throw new DomainException('Ano de publicação inválido.');
        $image = trim((string) ($dados['imagem_url'] ?? ''));
        if ($image !== '' && (!filter_var($image, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($image, PHP_URL_SCHEME)), ['http','https'], true))) throw new DomainException('A imagem precisa usar HTTP ou HTTPS.');
        $stmt = $this->pdo->prepare('UPDATE produtos SET codigo=?,nome=?,isbn=?,autor=?,editora=?,categoria=?,ano_publicacao=?,descricao=?,imagem_url=?,preco_centavos=?,estoque_minimo=?,localizacao=? WHERE id=?');
        $stmt->execute([
            $codigo, $nome, $isbn ?: null, $this->campo($dados, 'autor', 180), $this->campo($dados, 'editora', 140),
            $this->campo($dados, 'categoria', 100), $ano === '' ? null : (int) $ano,
            $this->campo($dados, 'descricao', 10000), $image ?: null, $preco, $minimo,
            $this->campo($dados, 'localizacao', 80), $id,
        ]);
        if ($stmt->rowCount() === 0 && !$this->produto($id)) throw new DomainException('Livro não encontrado.');
    }

    public function baixoEstoque(): array
    {
        return $this->pdo->query('SELECT codigo, nome, estoque, estoque_minimo FROM produtos WHERE ativo = 1 AND estoque <= estoque_minimo ORDER BY estoque ASC, nome LIMIT 12')->fetchAll();
    }

    public function pedidosRecentes(): array
    {
        return $this->pdo->query('SELECT p.id, p.criado_em, p.pago_em, p.status, p.valor_total_centavos, c.nome AS cliente FROM pedidos p JOIN clientes c ON c.id = p.cliente_id ORDER BY p.id DESC LIMIT 12')->fetchAll();
    }

    public function movimentacoesRecentes(): array
    {
        return $this->pdo->query('SELECT m.tipo, m.quantidade, m.observacao, m.criado_em, p.nome, u.nome AS equipe FROM movimentacoes_estoque m JOIN produtos p ON p.id = m.produto_id LEFT JOIN usuarios_equipe u ON u.id = m.equipe_id ORDER BY m.id DESC LIMIT 12')->fetchAll();
    }

    public function criarProduto(array $dados, int $equipeId): void
    {
        $codigo = strtoupper(trim((string) ($dados['codigo'] ?? '')));
        $nome = trim((string) ($dados['nome'] ?? ''));
        $isbn = Isbn::normalizar((string) ($dados['isbn'] ?? ''));
        $ano = trim((string) ($dados['ano_publicacao'] ?? ''));
        $preco = $this->parseDinheiro((string) ($dados['preco'] ?? ''));
        $estoque = filter_var($dados['estoque_inicial'] ?? 0, FILTER_VALIDATE_INT);
        $minimo = filter_var($dados['estoque_minimo'] ?? 2, FILTER_VALIDATE_INT);
        if (!preg_match('/^[A-Z0-9_-]{2,40}$/', $codigo)) throw new DomainException('Use um código de 2 a 40 caracteres: letras, números, hífen ou sublinhado.');
        if ($nome === '' || Texto::comprimento($nome) > 160) throw new DomainException('Informe um título válido, com até 160 caracteres.');
        if ($isbn !== '' && !Isbn::valido($isbn)) throw new DomainException('O ISBN informado não passou na validação.');
        if ($preco < 1) throw new DomainException('Informe um preço maior que zero.');
        if ($estoque === false || $estoque < 0 || $estoque > 1000000 || $minimo === false || $minimo < 0 || $minimo > 1000000) throw new DomainException('Estoque inicial ou mínimo inválido.');
        if ($ano !== '' && (!ctype_digit($ano) || (int) $ano < 1000 || (int) $ano > (int) date('Y') + 2)) throw new DomainException('Ano de publicação inválido.');
        $image = trim((string) ($dados['imagem_url'] ?? ''));
        if ($image !== '' && (!filter_var($image, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($image, PHP_URL_SCHEME)), ['http','https'], true))) throw new DomainException('A imagem precisa usar um endereço HTTP ou HTTPS válido.');

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO produtos (codigo,nome,isbn,autor,editora,categoria,ano_publicacao,descricao,imagem_url,preco_centavos,estoque,estoque_minimo,localizacao) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?)');
            $stmt->execute([
                $codigo, $nome, $isbn ?: null, $this->campo($dados, 'autor', 180), $this->campo($dados, 'editora', 140),
                $this->campo($dados, 'categoria', 100), $ano === '' ? null : (int) $ano,
                $this->campo($dados, 'descricao', 10000), $image ?: null, $preco, $minimo,
                $this->campo($dados, 'localizacao', 80),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            if ($estoque > 0) {
                $this->pdo->prepare('UPDATE produtos SET estoque = ? WHERE id = ?')->execute([$estoque, $id]);
                $this->pdo->prepare("INSERT INTO movimentacoes_estoque (produto_id,equipe_id,tipo,quantidade,observacao) VALUES (?,?,'entrada',?,'Estoque inicial do cadastro')")->execute([$id, $equipeId, $estoque]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function movimentar(int $produtoId, int $equipeId, string $tipo, int $quantidade, string $observacao): void
    {
        if (!in_array($tipo, ['entrada', 'saida'], true) || $quantidade < 1 || $quantidade > 100000) throw new DomainException('Tipo ou quantidade de movimentação inválidos.');
        $this->pdo->beginTransaction();
        try {
            $query = $this->pdo->prepare('SELECT estoque FROM produtos WHERE id = ? AND ativo = 1 FOR UPDATE');
            $query->execute([$produtoId]);
            $stock = $query->fetchColumn();
            if ($stock === false) throw new DomainException('Livro não encontrado ou inativo.');
            if ($tipo === 'saida' && (int) $stock < $quantidade) throw new DomainException('A saída ultrapassaria o estoque disponível.');
            $operator = $tipo === 'entrada' ? '+' : '-';
            $this->pdo->prepare("UPDATE produtos SET estoque = estoque {$operator} ? WHERE id = ?")->execute([$quantidade, $produtoId]);
            $stmt = $this->pdo->prepare('INSERT INTO movimentacoes_estoque (produto_id,equipe_id,tipo,quantidade,observacao) VALUES (?,?,?,?,?)');
            $stmt->execute([$produtoId, $equipeId, $tipo, $quantidade, Texto::cortar(trim($observacao), 0, 255) ?: null]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function alterarAtivo(int $produtoId): void
    {
        $stmt = $this->pdo->prepare('UPDATE produtos SET ativo = NOT ativo WHERE id = ?');
        $stmt->execute([$produtoId]);
    }

    public function exportarVendas(): array
    {
        return $this->pdo->query("SELECT p.id, p.criado_em, p.pago_em, p.status, p.valor_total_centavos, c.nome, c.email, c.identificacao FROM pedidos p JOIN clientes c ON c.id = p.cliente_id ORDER BY p.id DESC")->fetchAll();
    }

    private function parseDinheiro(string $valor): int
    {
        $valor = preg_replace('/\s+|R\$/u', '', trim($valor)) ?? '';
        if (str_contains($valor, ',')) $valor = str_replace(',', '.', str_replace('.', '', $valor));
        if (!preg_match('/^\d{1,7}(?:\.\d{1,2})?$/', $valor)) throw new DomainException('Digite o preço no formato 29,90.');
        return (int) round((float) $valor * 100);
    }

    private function campo(array $dados, string $chave, int $limite): ?string
    {
        $valor = trim((string) ($dados[$chave] ?? ''));
        if (Texto::comprimento($valor) > $limite) throw new DomainException('O campo ' . $chave . ' excede o limite permitido.');
        return $valor === '' ? null : $valor;
    }
}
