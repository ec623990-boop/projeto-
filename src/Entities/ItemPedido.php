<?php
declare(strict_types=1);

namespace BibliotecaVendas\Entities;

use InvalidArgumentException;

final class ItemPedido
{
    public function __construct(private Produto $produto, private int $quantidade)
    {
        if ($quantidade < 1) throw new InvalidArgumentException('A quantidade precisa ser maior que zero.');
    }

    public function produto(): Produto { return $this->produto; }
    public function quantidade(): int { return $this->quantidade; }
    public function subtotalCentavos(): int { return $this->produto->precoCentavos() * $this->quantidade; }
}
