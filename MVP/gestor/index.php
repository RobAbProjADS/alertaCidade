<?php

declare(strict_types=1);

require dirname(__DIR__) . '/database.php';

$statuses = ['Recebido', 'Em análise', 'Em atendimento', 'Resolvido'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();

    if (isset($_POST['login'])) {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $stmt = $pdo->prepare(
            'SELECT id, username, password_hash
             FROM managers
             WHERE username = :username
             LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $manager = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$manager || !password_verify($password, $manager['password_hash'])) {
            $error = 'Usuário ou senha incorretos.';
        } else {
            session_regenerate_id(true);
            $_SESSION['is_manager'] = true;
            $_SESSION['manager_id'] = (int)$manager['id'];
            $_SESSION['manager_username'] = $manager['username'];

            redirect('index.php');
        }
    }
}

if (isset($_GET['sair'])) {
    unset($_SESSION['is_manager']);
    redirect('logout.php');
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
        <link rel="stylesheet" href="assets/gestor.css">
        <style>
            * {
                box-sizing: border-box;
            }

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
            <h1>Painel do gestor</h1>

            <?php if ($error !== ''): ?>
                <p class="error"><?= h($error) ?></p>
            <?php endif; ?>

            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="login" value="1">

                <label for="username">Usuário</label>
                <input id="username" type="text" name="username" required autofocus autocomplete="username">

                <label for="password">Senha</label>
                <input id="password" type="password" name="password" required autocomplete="current-password">

                <button type="submit">Entrar no painel</button>
            </form>

            <p><a class="link" href="register.php">Cadastrar gestor</a></p>
        </main>

    </body>

    </html>

<?php
    exit;
endif;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    checkCsrf();

    $id = filter_var($_POST['alert_id'] ?? null, FILTER_VALIDATE_INT);
    $status = (string)($_POST['status'] ?? '');
    $managerUsername = (string)($_SESSION['manager_username'] ?? 'Gestor');

    if ($id && in_array($status, $statuses, true)) {
        $pdo->beginTransaction();

        try {
            $update = $pdo->prepare('UPDATE alerts SET status = ? WHERE id = ?');
            $update->execute([$status, $id]);

            $history = $pdo->prepare("
                INSERT INTO status_history (alert_id, status, changed_by)
                VALUES (?, ?, ?)
            ");
            $history->execute([$id, $status, $managerUsername]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    $returnQuery = (string)($_POST['return_query'] ?? '');
    if (!preg_match('/^[A-Za-z0-9%=&+_.\-]*$/', $returnQuery)) {
        $returnQuery = '';
    }

    redirect($returnQuery === '' ? 'index.php' : 'index.php?' . $returnQuery);
}

$categoryFilter = trim((string)($_GET['category'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$criticalityFilter = trim((string)($_GET['criticality'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$criticalityOptions = ['Baixa', 'Média', 'Alta', 'Crítica'];

$validDate = static fn(string $date): bool =>
$date === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);

if (!$validDate($dateFrom)) {
    $dateFrom = '';
}
if (!$validDate($dateTo)) {
    $dateTo = '';
}
if (!in_array($statusFilter, $statuses, true)) {
    $statusFilter = '';
}
if (!in_array($criticalityFilter, $criticalityOptions, true)) {
    $criticalityFilter = '';
}

$where = [];
$params = [];

if ($categoryFilter !== '') {
    $where[] = 'category = ?';
    $params[] = $categoryFilter;
}
if ($statusFilter !== '') {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if ($dateFrom !== '') {
    $where[] = "date(created_at) >= date(?)";
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = "date(created_at) <= date(?)";
    $params[] = $dateTo;
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT alerts.*,
           (SELECT COUNT(*) FROM supports s WHERE s.alert_id = alerts.id) AS support_count
    FROM alerts
    $whereSql
    ORDER BY support_count DESC, created_at DESC
");
$stmt->execute($params);
$allAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$alerts = [];
foreach ($allAlerts as $al) {
    $crit = obterNivelCriticidade((int)($al['support_count'] ?? 0));
    if ($criticalityFilter !== '' && $crit['nivel'] !== $criticalityFilter) {
        continue;
    }
    $alerts[] = $al;
}

$total = count($alerts);
$byStatus = array_fill_keys($statuses, 0);
$byCategory = [];
$highCriticalityCount = 0;
$mapAlerts = [];

foreach ($alerts as $alert) {
    $status = (string)$alert['status'];
    if (isset($byStatus[$status])) {
        $byStatus[$status]++;
    }

    $supportCount = (int)($alert['support_count'] ?? 0);
    $critInfo = obterNivelCriticidade($supportCount);
    if ($supportCount >= 5) {
        $highCriticalityCount++;
    }

    $category = (string)$alert['category'];
    $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;

    $lat = $alert['latitude'] ?? null;
    $lng = $alert['longitude'] ?? null;

    if (
        $lat !== null && $lng !== null &&
        is_numeric($lat) && is_numeric($lng) &&
        (float)$lat >= -90 && (float)$lat <= 90 &&
        (float)$lng >= -180 && (float)$lng <= 180
    ) {
        $mapAlerts[] = [
            'code' => formatAlertCode((int)$alert['id']),
            'title' => (string)$alert['title'],
            'category' => $category,
            'status' => $status,
            'criticality' => $critInfo['icone'] . ' ' . $critInfo['nivel'],
            'support_count' => $supportCount,
            'address' => (string)$alert['address'],
            'latitude' => (float)$lat,
            'longitude' => (float)$lng,
        ];
    }
}

arsort($byCategory);

// Categorias disponíveis para o filtro
$categories = $pdo->query("
    SELECT DISTINCT category
    FROM alerts
    ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

// Alertas por mês no período filtrado
$monthlyStmt = $pdo->prepare("
    SELECT strftime('%Y-%m', created_at) AS month, COUNT(*) AS total
    FROM alerts
    $whereSql
    GROUP BY strftime('%Y-%m', created_at)
    ORDER BY month
");
$monthlyStmt->execute($params);
$monthlyRows = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);

$monthlyLabels = array_column($monthlyRows, 'month');
$monthlyTotals = array_map('intval', array_column($monthlyRows, 'total'));

$categoryLabels = array_keys($byCategory);
$categoryTotals = array_values($byCategory);

$filterQuery = http_build_query([
    'category' => $categoryFilter,
    'status' => $statusFilter,
    'criticality' => $criticalityFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
]);
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>🚨 AlertaCidade — Painel do gestor</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🚨</text></svg>">

    <link rel="stylesheet" href="assets/gestor.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body>
    <header class="dashboard-header">
        <h1>AlertaCidade — Gestão</h1>

        <nav class="header-actions" aria-label="Ações do painel">
            <a href="register.php">Cadastrar gestor</a>
            <a class="logout-button" href="?sair=1">Sair do painel</a>
        </nav>
    </header>

    <main>
        <section class="card">
            <h2>Filtros do painel</h2>
            <form method="get" class="filters">
                <div class="filter-field">
                    <label for="category">Categoria</label>
                    <select id="category" name="category">
                        <option value="">Todas</option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= h((string)$category) ?>"
                                <?= $categoryFilter === $category ? 'selected' : '' ?>>
                                <?= h((string)$category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">Todos</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= h($status) ?>"
                                <?= $statusFilter === $status ? 'selected' : '' ?>>
                                <?= h($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="criticality">Criticidade</label>
                    <select id="criticality" name="criticality">
                        <option value="">Todas</option>
                        <?php foreach ($criticalityOptions as $critOpt): ?>
                            <option value="<?= h($critOpt) ?>"
                                <?= $criticalityFilter === $critOpt ? 'selected' : '' ?>>
                                <?= h($critOpt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label for="date_from">A partir de</label>
                    <input id="date_from" type="date" name="date_from"
                        value="<?= h($dateFrom) ?>">
                </div>

                <div class="filter-field">
                    <label for="date_to">Até</label>
                    <input id="date_to" type="date" name="date_to"
                        value="<?= h($dateTo) ?>">
                </div>

                <button type="submit">Aplicar filtros</button>
                <a class="button secondary" href="index.php">Limpar</a>
            </form>
        </section>

        <section class="cards">
            <div class="metric">
                <span class="metric-label">Alertas no filtro</span>
                <span class="metric-value"><?= $total ?></span>
            </div>

            <?php foreach ($statuses as $status): ?>
                <div class="metric">
                    <span class="metric-label"><?= h($status) ?></span>
                    <span class="metric-value"><?= $byStatus[$status] ?></span>
                </div>
            <?php endforeach; ?>

            <div class="metric">
                <span class="metric-label">Criticidade Alta / Crítica</span>
                <span class="metric-value" style="color:#c75100"><?= $highCriticalityCount ?></span>
            </div>

            <div class="metric">
                <span class="metric-label">Com localização no mapa</span>
                <span class="metric-value"><?= count($mapAlerts) ?></span>
            </div>
        </section>

        <div class="grid">
            <section class="card">
                <h2>Alertas registrados por mês</h2>
                <?php if (!$monthlyRows): ?>
                    <p class="no-data">Não há dados para os filtros selecionados.</p>
                <?php else: ?>
                    <div class="chart-wrap"><canvas id="monthlyChart"></canvas></div>
                <?php endif; ?>
            </section>

            <section class="card">
                <h2>Alertas por categoria</h2>
                <?php if (!$categoryLabels): ?>
                    <p class="no-data">Não há dados para os filtros selecionados.</p>
                <?php else: ?>
                    <div class="chart-wrap"><canvas id="categoryChart"></canvas></div>
                <?php endif; ?>
            </section>
        </div>

        <section class="card">
            <h2>Mapa de ocorrências</h2>
            <p class="no-data">
                <?= count($mapAlerts) ?> alerta(s) com coordenadas.
                Se um alerta não aparecer, confira se latitude e longitude foram salvas no cadastro.
            </p>
            <div id="alerts-map"></div>
        </section>

        <section class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:10px">
                <h2 style="margin:0">Ocorrências cadastradas</h2>
                <div style="font-size:12px;color:#5a736a;background:#f4f7f6;padding:6px 10px;border-radius:8px">
                    <strong>Escala de criticidade por apoios:</strong>
                    🟢 Baixa (0–1) · 🟡 Média (2–4) · 🟠 Alta (5–9) · 🔴 Crítica (10+)
                </div>
            </div>
            <?php if (!$alerts): ?>
                <p class="no-data">Nenhum alerta encontrado com esses filtros.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Ocorrência</th>
                                <th>Data</th>
                                <th>Categoria</th>
                                <th>Local</th>
                                <th>Criticidade / Apoios</th>
                                <th>Status</th>
                                <th>Atualizar status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alerts as $alert): ?>
                                <?php
                                $supCount = (int)($alert['support_count'] ?? 0);
                                $crit = obterNivelCriticidade($supCount);
                                ?>
                                <tr>
                                    <td><strong><?= h(formatAlertCode((int)$alert['id'])) ?></strong></td>
                                    <td class="description">
                                        <strong><?= h((string)$alert['title']) ?></strong><br>
                                        <?= h(mb_strimwidth(
                                            (string)$alert['description'],
                                            0,
                                            140,
                                            '…',
                                            'UTF-8'
                                        )) ?>
                                    </td>
                                    <td><?= h(formatDateTime((string)$alert['created_at'])) ?></td>
                                    <td><?= h((string)$alert['category']) ?></td>
                                    <td><?= h((string)$alert['address']) ?></td>
                                    <td>
                                        <div style="display:flex;flex-direction:column;gap:4px;align-items:flex-start">
                                            <span style="display:inline-block;padding:3px 9px;border-radius:6px;font-size:12px;font-weight:700;background:<?= h($crit['bg']) ?>;color:<?= h($crit['cor']) ?>;white-space:nowrap">
                                                <?= h($crit['icone'] . ' ' . $crit['nivel']) ?>
                                            </span>
                                            <span style="font-size:12px;color:#5a736a;font-weight:600;white-space:nowrap">
                                                🤝 <?= $supCount ?> <?= $supCount === 1 ? 'apoio' : 'apoios' ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php $progGestor = obterDadosProgressoStatus((string)$alert['status']); ?>
                                        <div style="min-width:140px">
                                            <div style="display:flex;justify-content:space-between;align-items:center;gap:6px;margin-bottom:4px">
                                                <span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:12px;font-weight:700;background:<?= h($progGestor['bg']) ?>;color:<?= h($progGestor['cor']) ?>">
                                                    <?= h((string)$alert['status']) ?>
                                                </span>
                                                <span style="font-size:11px;font-weight:700;color:<?= h($progGestor['cor']) ?>"><?= (int)$progGestor['percentual'] ?>%</span>
                                            </div>
                                            <div style="width:100%;height:6px;background:#dfe9e5;border-radius:999px;overflow:hidden">
                                                <div style="width:<?= (int)$progGestor['percentual'] ?>%;height:100%;background:<?= h($progGestor['cor']) ?>;border-radius:999px"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <form method="post" class="filter-row">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="update_status" value="1">
                                            <input type="hidden" name="alert_id"
                                                value="<?= (int)$alert['id'] ?>">
                                            <input type="hidden" name="return_query"
                                                value="<?= h($filterQuery) ?>">
                                            <select name="status" aria-label="Novo status">
                                                <?php foreach ($statuses as $status): ?>
                                                    <option value="<?= h($status) ?>"
                                                        <?= $alert['status'] === $status ? 'selected' : '' ?>>
                                                        <?= h($status) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit">Salvar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        const monthlyLabels = <?= json_encode(
                                    $monthlyLabels,
                                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                                ) ?>;

        const monthlyTotals = <?= json_encode($monthlyTotals) ?>;

        const categoryLabels = <?= json_encode(
                                    $categoryLabels,
                                    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                                ) ?>;

        const categoryTotals = <?= json_encode($categoryTotals) ?>;

        const alertsOnMap = <?= json_encode(
                                $mapAlerts,
                                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
                            ) ?>;

        if (document.getElementById('monthlyChart')) {
            new Chart(document.getElementById('monthlyChart'), {
                type: 'line',
                data: {
                    labels: monthlyLabels,
                    datasets: [{
                        label: 'Alertas',
                        data: monthlyTotals,
                        borderColor: '#126b53',
                        backgroundColor: 'rgba(18, 107, 83, .12)',
                        fill: true,
                        tension: .25
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        }

        if (document.getElementById('categoryChart')) {
            new Chart(document.getElementById('categoryChart'), {
                type: 'bar',
                data: {
                    labels: categoryLabels,
                    datasets: [{
                        label: 'Alertas',
                        data: categoryTotals,
                        backgroundColor: '#126b53'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    scales: {
                        x: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        }

        const map = L.map('alerts-map').setView([-14.235, -51.9253], 4);

        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const bounds = [];

        alertsOnMap.forEach(alert => {
            const marker = L.marker([alert.latitude, alert.longitude]).addTo(map);
            const popup = document.createElement('div');

            const title = document.createElement('strong');
            title.textContent = `${alert.code} — ${alert.title}`;

            const details = document.createElement('p');
            details.textContent =
                `${alert.category} · ${alert.status} · Criticidade: ${alert.criticality} (${alert.support_count} apoio(s)) · ${alert.address}`;

            const streetView = document.createElement('a');
            streetView.textContent = 'Abrir Google Maps / Street View';
            streetView.href =
                `https://www.google.com/maps?q=&layer=c&cbll=${alert.latitude},${alert.longitude}`;
            streetView.target = '_blank';
            streetView.rel = 'noopener noreferrer';

            popup.append(title, details, streetView);
            marker.bindPopup(popup);
            bounds.push([alert.latitude, alert.longitude]);
        });

        if (bounds.length === 1) {
            map.setView(bounds[0], 15);
        } else if (bounds.length > 1) {
            map.fitBounds(bounds, {
                padding: [25, 25]
            });
        }
    </script>
</body>

</html>