<?php

declare(strict_types=1);

require dirname(__DIR__) . '/database.php';

$error = '';
$success = '';
$isManager = !empty($_SESSION['is_manager']);
$setupToken = getenv('ALERTACIDADE_SETUP_TOKEN') ?: '';

$managerCount = (int)$pdo
    ->query('SELECT COUNT(*) FROM managers')
    ->fetchColumn();

$initialSetup = $managerCount === 0 && $setupToken !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $submittedToken = (string)($_POST['setup_token'] ?? '');

    $authorized = $isManager
        || ($initialSetup && hash_equals($setupToken, $submittedToken));

    if (!$authorized) {
        $error = 'Você não tem autorização para cadastrar gestores.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/', $username)) {
        $error = 'O usuário deve ter de 3 a 50 caracteres: letras, números, ponto, hífen ou sublinhado.';
    } elseif (strlen($password) < 12) {
        $error = 'A senha deve ter pelo menos 12 caracteres.';
    } else {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO managers (username, password_hash) VALUES (?, ?)'
            );
            $stmt->execute([
                $username,
                password_hash($password, PASSWORD_DEFAULT),
            ]);

            $success = 'Gestor cadastrado com sucesso.';
            $managerCount++;
            $initialSetup = false;
        } catch (PDOException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique')) {
                $error = 'Esse nome de usuário já está cadastrado.';
            } else {
                throw $e;
            }
        }
    }
}

// Visitantes não autenticados só podem acessar o cadastro inicial.
if (
    !$isManager &&
    !$initialSetup &&
    $managerCount > 0 &&
    $success === ''
):
?>
    <!doctype html>
    <html lang="pt-BR">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Cadastrar gestor — AlertaCidade</title>
        <link rel="stylesheet" href="assets/gestor.css">
    </head>

    <body>
        <main class="login-card">
            <h1>Acesso restrito</h1>
            <p>Entre como gestor para cadastrar novas contas.</p>
            <p><a class="link" href="index.php">Ir para o login</a></p>
        </main>
    </body>

    </html>
<?php
    exit;
endif;
?>

<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cadastrar gestor — AlertaCidade</title>
    <link rel="stylesheet" href="assets/gestor.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f2f5f4;
            color: #18312a;
            display: grid;
            place-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 16px;
        }
    </style>
</head>

<body>
    <main class="login-card">
        <h1>Cadastrar gestor</h1>

        <?php if ($error !== ''): ?>
            <p class="error"><?= h($error) ?></p>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <p class="success"><?= h($success) ?></p>
            <p><a class="link" href="index.php">
                    <?= $isManager ? 'Voltar ao painel' : 'Ir para o login' ?>
                </a></p>
        <?php else: ?>
            <form method="post">
                <?= csrfField() ?>

                <label for="username">Usuário</label>
                <input
                    id="username"
                    name="username"
                    type="text"
                    minlength="3"
                    maxlength="50"
                    required
                    autocomplete="username">

                <label for="password">Senha (mínimo de 12 caracteres)</label>
                <input
                    id="password"
                    name="password"
                    type="password"
                    minlength="12"
                    required
                    autocomplete="new-password">

                <?php if ($initialSetup && !$isManager): ?>
                    <label for="setup_token">Token de configuração</label>
                    <input
                        id="setup_token"
                        name="setup_token"
                        type="password"
                        required
                        autocomplete="off">
                <?php endif; ?>

                <button type="submit">Cadastrar gestor</button>
            </form>

            <?php if ($isManager): ?>
                <p><a class="link" href="index.php">Voltar ao painel</a></p>
            <?php else: ?>
                <p><a class="link" href="index.php">Voltar ao login</a></p>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</body>

</html>