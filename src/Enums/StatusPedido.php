<?php
declare(strict_types=1);

namespace BibliotecaVendas\Enums;

enum StatusPedido: string
{
    case PENDENTE = 'pendente';
    case PAGO = 'pago';
    case CANCELADO = 'cancelado';
}
