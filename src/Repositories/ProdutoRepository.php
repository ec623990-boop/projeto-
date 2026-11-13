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
            'SELECT id, codigo, nome, preco_centavos, estoque FROM produtos WHERE ativo = 1 ORDER BY nome'
        )->fetchAll();
        return array_map(fn (array $row): Produto => $this->mapear($row), $rows);
    }

    public function buscarPorId(int $id): ?Produto
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, codigo, nome, preco_centavos, estoque FROM produtos WHERE id = ? AND ativo = 1'
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
            "SELECT id, codigo, nome, preco_centavos, estoque
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
            (int) $row['estoque']
        );
    }
}
