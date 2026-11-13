<?php
declare(strict_types=1);

use BibliotecaVendas\Infrastructure\Database;
use BibliotecaVendas\Repositories\UsuarioRepository;
use BibliotecaVendas\Support\Seguranca;
use BibliotecaVendas\Support\SessaoWeb;
use BibliotecaVendas\Support\Texto;

require_once dirname(__DIR__) . '/src/bootstrap.php';

$enderecoRemoto = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($enderecoRemoto, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    http_response_code(404);
    exit('Página não encontrada.');
}

SessaoWeb::iniciar();
header('Cache-Control: no-store');

if (!empty($_SESSION['equipe_id'])) {
    header('Location: admin.php');
    exit;
}

$erro = '';
try {
    $pdo = Database::connect();
    $adminExiste = static fn (): bool => (int) $pdo
        ->query("SELECT COUNT(*) FROM usuarios_equipe WHERE perfil = 'admin'")
        ->fetchColumn() > 0;

    if ($adminExiste()) {
        header('Location: admin_login.php');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        Seguranca::validarCsrf($_POST['csrf_token'] ?? null);

        if ($adminExiste()) {
            header('Location: admin_login.php');
            exit;
        }

        $nome = trim((string) ($_POST['nome'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $senha = (string) ($_POST['senha'] ?? '');
        $confirmacao = (string) ($_POST['confirmacao_senha'] ?? '');

        if ($nome === '' || Texto::comprimento($nome) > 160 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um nome e um e-mail válidos.');
        }
        if (strlen($senha) < 12) {
            throw new InvalidArgumentException('A senha precisa ter pelo menos 12 caracteres.');
        }
        if (!hash_equals($senha, $confirmacao)) {
            throw new InvalidArgumentException('As senhas não conferem.');
        }

        (new UsuarioRepository($pdo))->criar($nome, $email, $senha, 'admin');
        $_SESSION['aviso_login'] = 'Administrador criado. Entre com o e-mail e a senha que acabou de cadastrar.';
        header('Location: admin_login.php');
        exit;
    }
} catch (DomainException|InvalidArgumentException $e) {
    $erro = $e->getMessage();
} catch (PDOException $e) {
    error_log('Falha na configuração inicial do administrador: ' . $e->getMessage());
    $erro = match ((string) $e->getCode()) {
        '42S02' => 'A tabela da equipe ainda não existe neste banco. Faça um backup completo e execute php scripts/migrar_gestao_catalogo.php no terminal; ele verifica as partes já existentes e pede confirmação antes de adicionar somente o que falta.',
        '1049' => 'O banco configurado não existe. Confira o nome em .env; importe schema.sql somente ao preparar um banco novo.',
        '2002', '2003' => 'Não foi possível conectar ao MySQL. Inicie o serviço e confira a conexão configurada em .env.',
        default => 'Não foi possível consultar o banco. Confira a configuração e as migrações antes de tentar novamente.',
    };
} catch (Throwable $e) {
    error_log('Falha na configuração inicial do administrador: ' . $e->getMessage());
    $erro = 'Não foi possível criar a conta. Confira a conexão e a instalação do banco de dados.';
}

$csrf = Seguranca::tokenCsrf();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configuração inicial da equipe</title>
    <link rel="stylesheet" href="assets/app.css">
</head>
<body>
<main class="container admin-login">
    <section class="painel">
        <p class="eyebrow">Primeiro acesso local</p>
        <h1>Criar administrador</h1>
        <p class="muted">Esta configuração funciona somente neste computador e fica bloqueada após a criação do primeiro administrador.</p>
        <?php if ($erro !== ''): ?><div class="mensagem mensagem--erro"><?= Seguranca::escapar($erro) ?></div><?php endif; ?>
        <form method="post" class="campos">
            <input type="hidden" name="csrf_token" value="<?= Seguranca::escapar($csrf) ?>">
            <label>Nome<input name="nome" maxlength="160" autocomplete="name" required></label>
            <label>E-mail<input type="email" name="email" maxlength="190" autocomplete="email" required></label>
            <label>Senha (mínimo de 12 caracteres)<input type="password" name="senha" minlength="12" autocomplete="new-password" required></label>
            <label>Confirme a senha<input type="password" name="confirmacao_senha" minlength="12" autocomplete="new-password" required></label>
            <div class="campo-largo"><button class="botao" type="submit">Criar primeiro administrador</button></div>
        </form>
        <p class="muted"><a href="admin_login.php">Voltar ao login</a></p>
    </section>
</main>
</body>
</html>
