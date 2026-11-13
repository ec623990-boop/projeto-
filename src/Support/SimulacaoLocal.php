<?php
declare(strict_types=1);

namespace BibliotecaVendas\Support;

use DomainException;

final class SimulacaoLocal
{
    public static function requisicaoLocal(): bool
    {
        return in_array(
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ['127.0.0.1', '::1', '::ffff:127.0.0.1'],
            true
        );
    }

    public static function exigirAcessoLocal(): void
    {
        if (!self::requisicaoLocal()) {
            throw new DomainException('A simulação de pagamentos está disponível somente no computador local.');
        }
    }
}
