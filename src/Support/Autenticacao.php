<?php
declare(strict_types=1);

namespace BibliotecaVendas\Support;

final class Autenticacao
{
    public static function exigirEquipe(): void
    {
        SessaoWeb::iniciar();
        if (($_SESSION['equipe_id'] ?? 0) < 1) {
            header('Location: admin_login.php');
            exit;
        }
    }
}
