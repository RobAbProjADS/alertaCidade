<?php
declare(strict_types=1);

require dirname(__DIR__) . '/database.php';

$statuses = ['Recebido', 'Em análise', 'Em atendimento', 'Resolvido'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    $configuredPassword = getenv('ALERTACIDADE_ADMIN_PASSWORD') ?: '';
    $password = (string)($_POST['password'] ?? '');

    if ($configuredPassword === '' || !hash_equals($configuredPassword, $password)) {
        $error = 'Senha administrativa incorreta ou não configurada.';
    } else {
        $_SESSION['is_manager'] = true;
    }
}

if (isset($_GET['sair'])) {
    unset($_SESSION['is_manager']);
    redirect('index.php');
}

if (empty($_SESSION['is_manager'])):
?>
<!doctype html>
<html lang="pt-BR">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AlertaCidade — Gestor</title></head>
<body style="font:16px Arial;max-width:420px;margin:50px auto;padding:16px">
    <h1>Painel do gestor</h1>
    <?php if ($error): ?><p style="color:red"><?= h($error) ?></p><?php endif; ?>
    <form method="post">
        <?= csrfField() ?>
        <label>Senha administrativa</label>
        <input type="password" name="password" required style="width:100%;padding:10px">
        <button style="padding:10px;margin-top:10px">Entrar</button>
    </form>
</body>
</html>
<?php
exit;
endif;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = filter_input(INPUT_POST, 'alert_id', FILTER_VALIDATE_INT);
    $status = (string)($_POST['status'] ?? '');

    if ($id && in_array($status, $statuses, true)) {
        $pdo->prepare('UPDATE alerts SET status = ? WHERE id = ?')->execute([$status, $id]);
        $pdo->prepare("
            INSERT INTO status_history (alert_id, status, changed_by)
            VALUES (?, ?, 'Gestor')
        ")->execute([$id, $status]);

        redirect('index.php');
    }
}

$categoryFilter = trim((string)($_GET['category'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));

$where = [];
$params = [];

if ($categoryFilter !== '') {
    $where[] = 'category = ?';
    $params[] = $categoryFilter;
}
if ($statusFilter !== '' && in_array($statusFilter, $statuses, true)) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}

$sql = 'SELECT * FROM alerts';
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$byStatus = $pdo->query("
    SELECT status, COUNT(*) AS total
    FROM alerts GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$byCategory = $pdo->query("
    SELECT category, COUNT(*) AS total
    FROM alerts GROUP BY category
    ORDER BY total DESC
")->fetchAll(PDO::FETCH_ASSOC);

$categories = $pdo->query("
    SELECT DISTINCT category FROM alerts ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

$total = (int)$pdo->query('SELECT COUNT(*) FROM alerts')->fetchColumn();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>AlertaCidade — Painel do gestor</title>
<style>
body{font:15px Arial;margin:0;background:#f2f5f4;color:#18312a}
header{background:#18312a;color:white;padding:18px}
main{max-width:1100px;margin:20px auto;padding:0 15px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.card{background:white;padding:16px;border-radius:10px;margin:12px 0}
table{border-collapse:collapse;width:100%;background:white}
th,td{text-align:left;padding:10px;border-bottom:1px solid #ddd}
input,select,button{padding:8px}
.thumb{width:90px;height:65px;object-fit:cover}
</style>
</head>
<body>
<header>
    <h1>Painel de gestão</h1>
    <a href="?sair=1" style="color:white">Sair</a>
</header>
<main>
    <h2>Indicadores</h2>
    <div class="cards">
        <div class="card"><strong>Total de alertas</strong><p><?= $total ?></p></div>
        <?php foreach ($statuses as $status): ?>
            <div class="card">
                <strong><?= h($status) ?></strong>
                <p><?= (int)($byStatus[$status] ?? 0) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="card">
        <h2>Alertas por categoria</h2>
        <?php if (!$byCategory): ?>
            <p>Sem dados ainda.</p>
        <?php else: ?>
            <ul>
                <?php foreach ($byCategory as $row): ?>
                    <li><?= h($row['category']) ?>: <?= (int)$row['total'] ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Ocorrências</h2>
        <form method="get">
            <label>Categoria</label>
            <select name="category">
                <option value="">Todas</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= h($category) ?>"
                        <?= $categoryFilter === $category ? 'selected' : '' ?>>
                        <?= h($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Status</label>
            <select name="status">
                <option value="">Todos</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= h($status) ?>"
                        <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= h($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button>Filtrar</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Foto</th><th>Ocorrência</th><th>Categoria</th>
                    <th>Local</th><th>Status</th><th>Atualizar</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($alerts as $alert): ?>
                <tr>
                    <td>
                        <?php if ($alert['thumbnail_path']): ?>
                            <img class="thumb" src="../<?= h($alert['thumbnail_path']) ?>"
                                 loading="lazy" alt="">
                        <?php endif; ?>
                    </td>
                    <td>
                        <strong><?= h($alert['title']) ?></strong><br>
                        <?= h(mb_strimwidth($alert['description'], 0, 100, '…', 'UTF-8')) ?>
                    </td>
                    <td><?= h($alert['category']) ?></td>
                    <td><?= h($alert['address']) ?></td>
                    <td><?= h($alert['status']) ?></td>
                    <td>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="update_status" value="1">
                            <input type="hidden" name="alert_id" value="<?= (int)$alert['id'] ?>">
                            <select name="status">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= h($status) ?>"
                                        <?= $alert['status'] === $status ? 'selected' : '' ?>>
                                        <?= h($status) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button>Salvar</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>

