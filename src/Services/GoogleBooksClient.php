<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Support\Isbn;
use BibliotecaVendas\Support\Texto;
use DomainException;
use RuntimeException;

final class GoogleBooksClient
{
    public function buscarPorIsbn(string $isbn): ?array
    {
        $isbn = Isbn::normalizar($isbn);
        if (!Isbn::valido($isbn)) throw new DomainException('Informe um ISBN-10 ou ISBN-13 válido.');
        $url = 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . rawurlencode($isbn) . '&maxResults=1';
        $context = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nUser-Agent: BibliotecaVendas/1.0\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $raw = @file_get_contents($url, false, $context);
        if (!is_string($raw)) throw new RuntimeException('Serviço de consulta bibliográfica indisponível.');
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['items'][0]['volumeInfo'])) return null;
        $book = $data['items'][0]['volumeInfo'];
        $images = $book['imageLinks'] ?? [];
        return [
            'isbn' => $isbn,
            'nome' => Texto::cortar(trim((string) ($book['title'] ?? '')), 0, 160),
            'autor' => Texto::cortar(implode(', ', array_slice($book['authors'] ?? [], 0, 4)), 0, 180),
            'editora' => Texto::cortar((string) ($book['publisher'] ?? ''), 0, 140),
            'categoria' => Texto::cortar((string) (($book['categories'][0] ?? '') ?: ''), 0, 100),
            'ano_publicacao' => isset($book['publishedDate']) && preg_match('/^\d{4}/', (string) $book['publishedDate'], $m) ? (int) $m[0] : null,
            'descricao' => Texto::cortar(strip_tags((string) ($book['description'] ?? '')), 0, 10000),
            'imagem_url' => Texto::cortar((string) ($images['thumbnail'] ?? $images['smallThumbnail'] ?? ''), 0, 500),
        ];
    }
}
