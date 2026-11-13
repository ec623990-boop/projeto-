<?php
declare(strict_types=1);

namespace BibliotecaVendas\Repositories;

use BibliotecaVendas\Entities\Produto;
use PDO;

final class ProdutoRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array<int, Produto> */
    public function listarAtivos(): array
    {
        $rows = $this->pdo->query(
            'SELECT * FROM produtos WHERE ativo = 1 ORDER BY nome'
        )->fetchAll();
        return array_map(fn (array $row): Produto => $this->mapear($row), $rows);
    }

    /** @return array<int, Produto> */
    public function buscarAtivos(string $termo): array
    {
        $termo = trim($termo);
        if ($termo === '') return $this->listarAtivos();

        $termo = strtr($termo, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $like = '%' . $termo . '%';
        $stmt = $this->pdo->prepare(
            "SELECT * FROM produtos
             WHERE ativo = 1 AND (nome LIKE ? ESCAPE '!' OR autor LIKE ? ESCAPE '!' OR categoria LIKE ? ESCAPE '!' OR isbn LIKE ? ESCAPE '!' OR codigo LIKE ? ESCAPE '!')
             ORDER BY nome LIMIT 100"
        );
        $stmt->execute([$like, $like, $like, $like, $like]);
        return array_map(fn (array $row): Produto => $this->mapear($row), $stmt->fetchAll());
    }

    public function buscarPorId(int $id): ?Produto
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM produtos WHERE id = ? AND ativo = 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? $this->mapear($row) : null;
    }

    /** @param array<int, int> $ids
     *  @return array<int, Produto>
     */
    public function buscarVariosParaAtualizarEstoque(array $ids): array
    {
        if ($ids === []) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM produtos WHERE ativo = 1 AND id IN ({$marks}) FOR UPDATE"
        );
        $stmt->execute(array_values($ids));
        $products = [];
        foreach ($stmt->fetchAll() as $row) {
            $product = $this->mapear($row);
            $products[$product->id()] = $product;
        }
        return $products;
    }

    private function mapear(array $row): Produto
    {
        return new Produto(
            (int) $row['id'],
            (string) $row['codigo'],
            (string) $row['nome'],
            (int) $row['preco_centavos'],
            (int) $row['estoque'],
            $row['isbn'] ?? null,
            $row['autor'] ?? null,
            $row['editora'] ?? null,
            $row['categoria'] ?? null,
            isset($row['ano_publicacao']) ? (int) $row['ano_publicacao'] : null,
            $row['descricao'] ?? null,
            $row['imagem_url'] ?? null,
            isset($row['estoque_minimo']) ? (int) $row['estoque_minimo'] : 2,
            $row['localizacao'] ?? null
        );
    }
}
