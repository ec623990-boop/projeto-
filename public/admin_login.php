<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\UsuarioRepository;
use BibliotecaVendas\Services\LoginService;
use BibliotecaVendas\Support\Seguranca;
use BibliotecaVendas\Support\SessaoWeb;

require_once dirname(__DIR__) . '/src/bootstrap.php';
SessaoWeb::iniciar();
header('Cache-Control: no-store');
if (!empty($_SESSION['equipe_id'])) { header('Location: admin.php'); exit; }
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Seguranca::validarCsrf($_POST['csrf_token'] ?? null);
        $auth = (new LoginService(new UsuarioRepository(Database::connect())))->autenticar(
            (string) ($_POST['email'] ?? ''), (string) ($_POST['senha'] ?? '')
        );
        session_regenerate_id(true);
        $_SESSION['equipe_id'] = $auth['id'];
        $_SESSION['equipe_nome'] = $auth['nome'];
        $_SESSION['equipe_perfil'] = $auth['perfil'];
        header('Location: admin.php'); exit;
    } catch (DomainException|InvalidArgumentException $e) {
        $erro = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Falha no login da equipe: ' . $e->getMessage());
        $erro = 'Não foi possível entrar. Confira se o banco e a configuração estão disponíveis.';
    }
}
$csrf = Seguranca::tokenCsrf();
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Acesso da equipe</title><link rel="stylesheet" href="assets/app.css"></head>
<body><main class="container admin-login"><section class="painel"><p class="eyebrow">Área restrita</p><h1>Entrar na gestão</h1>
<?php if ($erro !== ''): ?><div class="mensagem mensagem--erro"><?= Seguranca::escapar($erro) ?></div><?php endif; ?>
<form method="post" class="campos"><input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>"><label>E-mail<input type="email" name="email" maxlength="190" autocomplete="username" required></label><label>Senha<input type="password" name="senha" autocomplete="current-password" required></label><button class="botao" type="submit">Entrar</button></form><p class="muted"><a href="index.php">Voltar ao catálogo</a></p></section></main></body></html>
