<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Support\Isbn;
use BibliotecaVendas\Support\Texto;
use DomainException;
use RuntimeException;

final class OpenLibraryClient
{
    /** @return array<int, array<string, mixed>> */
    public function buscarPorTermo(string $termo, int $limite = 10): array
    {
        $termo = trim($termo);
        $comprimento = Texto::comprimento($termo);
        if ($comprimento < 2 || $comprimento > 120) {
            throw new DomainException('Digite pelo menos 2 caracteres e no máximo 120 para buscar.');
        }

        $isbnSemMascara = preg_replace('/[\s.-]+/u', '', $termo) ?? $termo;
        $consulta = Isbn::valido($isbnSemMascara) ? 'isbn:' . $isbnSemMascara : $termo;
        $url = 'https://openlibrary.org/search.json?' . http_build_query([
            'q' => $consulta,
            'fields' => 'key,title,author_name,publisher,first_publish_year,isbn,cover_i,subject,first_sentence',
            'limit' => max(1, min(20, $limite)),
            'lang' => 'pt',
        ], '', '&', PHP_QUERY_RFC3986);
        $context = stream_context_create([
            'http' => [
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: BibliotecaVendas/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw)) throw new RuntimeException('O catálogo Open Library está indisponível. Tente novamente em instantes.');

        $data = json_decode($raw, true);
        if (!is_array($data) || isset($data['error'])) {
            throw new RuntimeException('O catálogo Open Library retornou uma resposta inválida.');
        }

        $resultados = [];
        foreach (($data['docs'] ?? []) as $doc) {
            if (!is_array($doc)) continue;
            $nome = trim((string) ($doc['title'] ?? ''));
            if ($nome === '') continue;

            $autores = is_array($doc['author_name'] ?? null) ? array_slice($doc['author_name'], 0, 4) : [];
            $editoras = is_array($doc['publisher'] ?? null) ? array_slice($doc['publisher'], 0, 2) : [];
            $categorias = is_array($doc['subject'] ?? null) ? array_slice($doc['subject'], 0, 1) : [];
            $isbn = $this->isbnValido(is_array($doc['isbn'] ?? null) ? $doc['isbn'] : []);
            $capaId = filter_var($doc['cover_i'] ?? null, FILTER_VALIDATE_INT);
            $frases = $doc['first_sentence'] ?? '';
            $descricao = is_array($frases) ? (string) ($frases[0] ?? '') : (string) $frases;
            $obraId = $this->obraId((string) ($doc['key'] ?? ''));
            $imagemUrl = $capaId !== false && $capaId > 0
                ? 'https://covers.openlibrary.org/b/id/' . $capaId . '-L.jpg?default=false'
                : ($isbn !== '' ? 'https://covers.openlibrary.org/b/isbn/' . rawurlencode($isbn) . '-L.jpg?default=false' : '');

            $resultados[] = [
                'openlibrary_id' => $obraId,
                'source_url' => $obraId !== '' ? 'https://openlibrary.org/works/' . $obraId : '',
                'isbn' => $isbn,
                'nome' => Texto::cortar($nome, 0, 160),
                'autor' => Texto::cortar(implode(', ', array_map('strval', $autores)), 0, 180),
                'editora' => Texto::cortar(implode(', ', array_map('strval', $editoras)), 0, 140),
                'categoria' => Texto::cortar((string) ($categorias[0] ?? ''), 0, 100),
                'ano_publicacao' => isset($doc['first_publish_year']) && ctype_digit((string) $doc['first_publish_year'])
                    ? (int) $doc['first_publish_year']
                    : null,
                'descricao' => Texto::cortar(strip_tags($descricao), 0, 10000),
                'imagem_url' => Texto::cortar($imagemUrl, 0, 500),
            ];
        }

        return $resultados;
    }

    public function descricaoDaObra(string $obraId): string
    {
        $obraId = $this->obraId($obraId);
        if ($obraId === '') return '';

        $url = 'https://openlibrary.org/works/' . rawurlencode($obraId) . '.json';
        $context = stream_context_create([
            'http' => [
                'timeout' => 8,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\nUser-Agent: BibliotecaVendas/1.0\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw)) return '';

        $data = json_decode($raw, true);
        if (!is_array($data)) return '';
        $descricao = $data['description'] ?? '';
        if (is_array($descricao)) $descricao = $descricao['value'] ?? '';
        return Texto::cortar(strip_tags((string) $descricao), 0, 10000);
    }

    /** @param array<int, mixed> $valores */
    private function isbnValido(array $valores): string
    {
        foreach ($valores as $valor) {
            $isbn = Isbn::normalizar((string) $valor);
            if (Isbn::valido($isbn)) return $isbn;
        }
        return '';
    }

    private function obraId(string $valor): string
    {
        return preg_match('~(?:^|/)(OL[0-9]+W)$~i', trim($valor), $match)
            ? strtoupper($match[1])
            : '';
    }
}
