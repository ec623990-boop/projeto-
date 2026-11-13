<?php
declare(strict_types=1);

namespace BibliotecaVendas\Enums;

enum StatusPagamento: string
{
    case PENDENTE = 'pendente';
    case APROVADO = 'aprovado';
    case RECUSADO = 'recusado';
}
