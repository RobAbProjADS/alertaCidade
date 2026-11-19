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
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🚨 AlertaCidade — Gestor</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🚨</text></svg>">
<style>
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 15px;
    background: #f2f5f4;
    color: #18312a;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 80vh;
    margin: 0;
    padding: 16px;
}
.login-card {
    background: #fff;
    border-radius: 12px;
    padding: 28px;
    max-width: 380px;
    width: 100%;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    border: 1px solid #dbe4e0;
}
h1 { font-size: 20px; font-weight: 700; margin: 0 0 16px 0; color: #18312a; }
label { display: block; font-size: 13px; font-weight: 700; text-transform: uppercase; color: #23433a; margin-bottom: 6px; }
input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #ccd8d3; border-radius: 8px; font-size: 14px; margin-bottom: 14px; }
button { width: 100%; padding: 10px; background: #126b53; color: white; border: 0; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px; }
button:hover { background: #0e5441; }
.error { color: #9b2215; background: #fdeeee; padding: 8px 12px; border-radius: 6px; font-size: 13px; margin-bottom: 12px; }
</style>
</head>
<body>
    <div class="login-card">
        <h1>Painel do gestor</h1>
        <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <label for="password">Senha administrativa</label>
            <input id="password" type="password" name="password" required autofocus placeholder="Digite a senha de acesso">
            <button type="submit">Entrar no painel</button>
        </form>
    </div>
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

$alertIds = array_column($alerts, 'id');
$photosByAlert = [];
if (!empty($alertIds)) {
    $in = implode(',', array_fill(0, count($alertIds), '?'));
    $stmtPhotos = $pdo->prepare("SELECT * FROM alert_photos WHERE alert_id IN ($in) ORDER BY id ASC");
    $stmtPhotos->execute($alertIds);
    foreach ($stmtPhotos->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $photosByAlert[$p['alert_id']][] = $p;
    }
}

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
<title>🚨 AlertaCidade — Painel do gestor</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🚨</text></svg>">
<style>
:root {
    --primary: #18312a;
    --accent: #126b53;
    --text-main: #18312a;
    --text-muted: #5a736a;
    --bg: #f2f5f4;
    --card-bg: #ffffff;
    --border: #dbe4e0;
}
* { box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    font-size: 14px;
    line-height: 1.5;
    margin: 0;
    background: var(--bg);
    color: var(--text-main);
    -webkit-font-smoothing: antialiased;
}
header {
    background: var(--primary);
    color: white;
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: 0 2px 6px rgba(0,0,0,0.08);
}
header h1 {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
}
header a {
    color: #fff;
    text-decoration: none;
    font-size: 13px;
    background: rgba(255,255,255,0.15);
    padding: 6px 14px;
    border-radius: 6px;
    font-weight: 500;
    transition: background 0.15s;
}
header a:hover {
    background: rgba(255,255,255,0.25);
}
main {
    max-width: 1150px;
    margin: 24px auto;
    padding: 0 20px;
}
h2 {
    font-size: 18px;
    font-weight: 700;
    color: #18312a;
    margin: 0 0 14px 0;
}
.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}
.metric-card {
    background: white;
    padding: 18px;
    border-radius: 12px;
    border: 1px solid var(--border);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.metric-label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-muted);
    letter-spacing: 0.5px;
    margin-bottom: 6px;
}
.metric-value {
    font-size: 28px;
    font-weight: 800;
    color: var(--accent);
    margin: 0;
}
.card {
    background: white;
    padding: 22px;
    border-radius: 12px;
    margin: 18px 0;
    border: 1px solid var(--border);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
table {
    border-collapse: collapse;
    width: 100%;
    margin-top: 14px;
    background: white;
}
th {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--text-muted);
    background: #f7faf9;
    padding: 12px;
    border-bottom: 2px solid var(--border);
    text-align: left;
}
td {
    padding: 12px;
    border-bottom: 1px solid #edf2f0;
    font-size: 14px;
    vertical-align: middle;
}
tr:hover td {
    background: #fcfdfd;
}
select, input {
    padding: 7px 10px;
    font-family: inherit;
    font-size: 13px;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: white;
}
select:focus, input:focus {
    outline: none;
    border-color: var(--accent);
}
button, .btn {
    font-family: inherit;
    font-size: 13px;
    font-weight: 600;
    padding: 7px 14px;
    border: 0;
    border-radius: 6px;
    background: var(--accent);
    color: white;
    cursor: pointer;
    transition: opacity 0.15s;
}
button:hover, .btn:hover {
    opacity: 0.9;
}
.badge-code {
    display: inline-block;
    background: #eaf1ee;
    color: var(--accent);
    font-weight: 700;
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 6px;
}
.thumb {
    width: 50px;
    height: 40px;
    object-fit: cover;
    border-radius: 6px;
    border: 1px solid #ddd;
}
</style>
</head>
<body>
<header>
    <h1>AlertaCidade — Gestão</h1>
    <a href="?sair=1">Sair do painel</a>
</header>
<main>
    <h2>Indicadores</h2>
    <div class="cards">
        <div class="metric-card">
            <span class="metric-label">Total de alertas</span>
            <p class="metric-value"><?= $total ?></p>
        </div>
        <?php foreach ($statuses as $status): ?>
            <div class="metric-card">
                <span class="metric-label"><?= h($status) ?></span>
                <p class="metric-value"><?= (int)($byStatus[$status] ?? 0) ?></p>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="card">
        <h2>Alertas por categoria</h2>
        <?php if (!$byCategory): ?>
            <p style="color:var(--text-muted)">Sem dados de categorias ainda.</p>
        <?php else: ?>
            <div style="display:flex;gap:16px;flex-wrap:wrap">
                <?php foreach ($byCategory as $row): ?>
                    <div style="background:#f4f7f6;padding:8px 14px;border-radius:8px;font-size:13px">
                        <strong><?= h($row['category']) ?></strong>: <span style="color:var(--accent);font-weight:700"><?= (int)$row['total'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Ocorrências cadastradas</h2>
        <form method="get" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
            <label style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-muted)">Categoria:</label>
            <select name="category">
                <option value="">Todas</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= h($category) ?>"
                        <?= $categoryFilter === $category ? 'selected' : '' ?>>
                        <?= h($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label style="font-size:12px;font-weight:700;text-transform:uppercase;color:var(--text-muted)">Status:</label>
            <select name="status">
                <option value="">Todos</option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= h($status) ?>"
                        <?= $statusFilter === $status ? 'selected' : '' ?>>
                        <?= h($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Filtrar</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th>Código</th><th>Foto</th><th>Ocorrência</th><th>Data de Registro</th><th>Categoria</th>
                    <th>Local</th><th>Status</th><th>Atualizar</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($alerts as $alert): ?>
                <?php
                $photos = $photosByAlert[$alert['id']] ?? [];
                if (empty($photos) && !empty($alert['thumbnail_path'])) {
                    $photos[] = [
                        'thumbnail_path' => $alert['thumbnail_path'],
                        'image_path'     => $alert['image_path'] ?? $alert['thumbnail_path'],
                    ];
                }
                ?>
                <tr>
                    <td><strong><?= formatAlertCode((int)$alert['id']) ?></strong></td>
                    <td>
                        <div style="display:flex;gap:4px">
                            <?php foreach ($photos as $photo): ?>
                                <a href="../<?= h($photo['image_path'] ?? $photo['thumbnail_path']) ?>" target="_blank">
                                    <img class="thumb" src="../<?= h($photo['thumbnail_path']) ?>"
                                         loading="lazy" alt="Foto" style="width:50px;height:40px;object-fit:cover;border-radius:4px">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <strong><?= h($alert['title']) ?></strong><br>
                        <?= h(mb_strimwidth($alert['description'], 0, 100, '…', 'UTF-8')) ?>
                    </td>
                    <td><?= h(formatDateTime($alert['created_at'])) ?></td>
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

