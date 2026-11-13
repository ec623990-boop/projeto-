<?php
declare(strict_types=1);

namespace BibliotecaVendas\Services;

use BibliotecaVendas\Support\Isbn;
use BibliotecaVendas\Support\Texto;
use DomainException;

final class CatalogoLivrosClient
{
    public function buscarPorIsbn(string $isbn): ?array
    {
        $isbn = Isbn::normalizar($isbn);
        if (!Isbn::valido($isbn)) throw new DomainException('Informe um ISBN-10 ou ISBN-13 válido.');
        return $this->buscarPorTermo($isbn, 1)[0] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function buscarPorTermo(string $termo, int $limite = 10): array
    {
        $termo = trim($termo);
        $comprimento = Texto::comprimento($termo);
        if ($comprimento < 2 || $comprimento > 120) {
            throw new DomainException('Digite pelo menos 2 caracteres e no máximo 120 para buscar.');
        }

        return (new OpenLibraryClient())->buscarPorTermo($termo, $limite);
    }
}
