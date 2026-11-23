<?php

declare(strict_types=1);

require dirname(__DIR__) . '/database.php';

if (isset($_GET['logout'])) {
    unset($_SESSION['citizen_user']);
    redirect('index.php');
}

$loggedUser = $_SESSION['citizen_user'] ?? null;
$isLoggedIn = !empty($loggedUser);
$authError = '';
$activeAuthTab = 'login';

$categories = [
    'Iluminação pública',
    'Buraco em via',
    'Descarte irregular de resíduos',
    'Calçada',
    'Sinalização',
    'Árvores e áreas verdes',
    'Água e esgoto',
    'Zoonoses e animais',
    'Outro',
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $loginInput = trim((string)($_POST['username'] ?? $_POST['login'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $digitsOnly = preg_replace('/\D/', '', $loginInput) ?? '';

        $stmtUser = $pdo->prepare(
            "SELECT id, username, password_hash, name, email, phone, cpf, address_info
             FROM citizens
             WHERE LOWER(username) = LOWER(:ident)
                OR (name IS NOT NULL AND TRIM(name) != '' AND LOWER(TRIM(name)) = LOWER(:ident))
                OR (email IS NOT NULL AND TRIM(email) != '' AND LOWER(TRIM(email)) = LOWER(:ident))
                OR (cpf IS NOT NULL AND TRIM(cpf) != '' AND (
                    TRIM(cpf) = :ident
                    OR (:digits != '' AND REPLACE(REPLACE(REPLACE(TRIM(cpf), '.', ''), '-', ''), ' ', '') = :digits)
                ))
             ORDER BY id DESC"
        );
        $stmtUser->execute([
            'ident' => $loginInput,
            'digits' => $digitsOnly,
        ]);
        $candidates = $stmtUser->fetchAll(PDO::FETCH_ASSOC);

        $matchedUser = null;
        foreach ($candidates as $candidate) {
            if (password_verify($password, (string)$candidate['password_hash'])) {
                $matchedUser = $candidate;
                break;
            }
        }

        if ($matchedUser) {
            session_regenerate_id(true);

            $_SESSION['citizen_user'] = [
                'id' => (int)$matchedUser['id'],
                'username' => $matchedUser['username'],
                'name' => (string)($matchedUser['name'] ?? ''),
                'email' => (string)($matchedUser['email'] ?? ''),
                'phone' => (string)($matchedUser['phone'] ?? ''),
                'cpf' => (string)($matchedUser['cpf'] ?? ''),
                'address_info' => (string)($matchedUser['address_info'] ?? ''),
            ];

            $redirectTo = !empty($_POST['redirect_to'])
                ? $_POST['redirect_to']
                : 'index.php?novo=1';

            redirect($redirectTo);
        } else {
            $authError = 'Nome/usuário, e-mail, CPF ou senha incorretos.';
            $activeAuthTab = 'login';
        }
    }

    if ($action === 'register') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $cpf = trim((string)($_POST['cpf'] ?? ''));
        $addressInfo = trim((string)($_POST['address_info'] ?? ''));

        if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
            $authError = 'O usuário deve ter de 3 a 32 caracteres: letras, números ou _.';
            $activeAuthTab = 'register';
        } elseif (strlen($password) < 8) {
            $authError = 'A senha deve ter no mínimo 8 caracteres.';
            $activeAuthTab = 'register';
        } else {
            $check = $pdo->prepare('SELECT id FROM citizens WHERE username = ?');
            $check->execute([$username]);

            if ($check->fetch()) {
                $authError = 'Esse nome de usuário já está em uso.';
                $activeAuthTab = 'register';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $insert = $pdo->prepare(
                    'INSERT INTO citizens (username, password_hash, name, email, phone, cpf, address_info) VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([$username, $hash, $name, $email, $phone, $cpf, $addressInfo]);

                session_regenerate_id(true);
                $_SESSION['citizen_user'] = [
                    'id' => (int)$pdo->lastInsertId(),
                    'username' => $username,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'cpf' => $cpf,
                    'address_info' => $addressInfo,
                ];

                $redirectTo = !empty($_POST['redirect_to'])
                    ? $_POST['redirect_to']
                    : 'index.php?novo=1';

                redirect($redirectTo);
            }
        }
    }

    if ($action === 'update_profile' && $isLoggedIn) {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $cpf = trim((string)($_POST['cpf'] ?? ''));
        $addressInfo = trim((string)($_POST['address_info'] ?? ''));

        $upd = $pdo->prepare(
            'UPDATE citizens SET name = ?, email = ?, phone = ?, cpf = ?, address_info = ? WHERE id = ?'
        );
        $upd->execute([$name, $email, $phone, $cpf, $addressInfo, (int)$loggedUser['id']]);

        $_SESSION['citizen_user']['name'] = $name;
        $_SESSION['citizen_user']['email'] = $email;
        $_SESSION['citizen_user']['phone'] = $phone;
        $_SESSION['citizen_user']['cpf'] = $cpf;
        $_SESSION['citizen_user']['address_info'] = $addressInfo;

        redirect('index.php?profile_saved=1');
    }

    if ($action === 'create') {
        if (!$isLoggedIn) {
            $authError = 'Você precisa entrar na sua conta para registrar uma ocorrência.';
        } else {
            $title = trim((string)($_POST['title'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $category = trim((string)($_POST['category'] ?? ''));
            $address = trim((string)($_POST['address'] ?? ''));
            $lat = trim((string)($_POST['latitude'] ?? ''));
            $lng = trim((string)($_POST['longitude'] ?? ''));

            if ($title === '' || $description === '' || $address === '') {
                $error = 'Preencha título, descrição e endereço.';
            } elseif (!in_array($category, $categories, true)) {
                $error = 'Categoria inválida.';
            } elseif (($lat !== '' && !is_numeric($lat)) ||
                ($lng !== '' && !is_numeric($lng))
            ) {
                $error = 'Coordenadas inválidas.';
            } else {
                $uploadedPhotos = [];
                $sensitiveTermDetected = detectarPalavraSensivel($description, $title);
                $photosInput = ($sensitiveTermDetected === null)
                    ? ($_FILES['photos'] ?? $_FILES['photo'] ?? null)
                    : null;

                if ($photosInput && !empty($photosInput['name'])) {
                    $names = is_array($photosInput['name']) ? $photosInput['name'] : [$photosInput['name']];
                    $tmpNames = is_array($photosInput['tmp_name']) ? $photosInput['tmp_name'] : [$photosInput['tmp_name']];
                    $errors = is_array($photosInput['error']) ? $photosInput['error'] : [$photosInput['error']];
                    $sizes = is_array($photosInput['size']) ? $photosInput['size'] : [$photosInput['size']];

                    $validIndexes = [];
                    foreach ($errors as $idx => $errCode) {
                        if ($errCode !== UPLOAD_ERR_NO_FILE) {
                            $validIndexes[] = $idx;
                        }
                    }

                    if (count($validIndexes) > 3) {
                        $error = 'Você pode enviar no máximo 3 fotos.';
                    } else {
                        $extensions = [
                            'image/jpeg' => 'jpg',
                            'image/png'  => 'png',
                            'image/webp' => 'webp',
                        ];

                        foreach ($validIndexes as $i) {
                            $tmpFile = $tmpNames[$i];
                            $fileSize = $sizes[$i];
                            $fileError = $errors[$i];

                            if ($fileError !== UPLOAD_ERR_OK || $fileSize > 5 * 1024 * 1024) {
                                $error = 'Cada foto deve ter até 5 MB.';
                                break;
                            }

                            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpFile);
                            if (!isset($extensions[$mime])) {
                                $error = 'Use apenas imagens JPG, PNG ou WebP.';
                                break;
                            }

                            $name = bin2hex(random_bytes(16));
                            $ext = $extensions[$mime];
                            $originalFile = dirname(__DIR__) . "/storage/originals/$name.$ext";
                            $thumbFile = dirname(__DIR__) . "/storage/thumbnails/$name.jpg";

                            if (move_uploaded_file($tmpFile, $originalFile) && makeThumbnail($originalFile, $thumbFile)) {
                                $uploadedPhotos[] = [
                                    'image_path'     => "storage/originals/$name.$ext",
                                    'thumbnail_path' => "storage/thumbnails/$name.jpg",
                                ];
                            } else {
                                $error = 'Não foi possível processar uma das fotos.';
                                break;
                            }
                        }
                    }
                }

                if ($error === '') {
                    $primaryThumb = $uploadedPhotos[0]['thumbnail_path'] ?? null;
                    $primaryImage = $uploadedPhotos[0]['image_path'] ?? null;
                    $authorName = trim((string)($loggedUser['username'] ?? ''));
                    if ($authorName === '') {
                        $authorName = 'Cidadão';
                    }
                    $ownerId = (string)$loggedUser['id'];

                    $stmt = $pdo->prepare("
                        INSERT INTO alerts (
                            title,
                            description,
                            category,
                            address,
                            latitude,
                            longitude,
                            thumbnail_path,
                            image_path,
                            author,
                            owner_id
                        )
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");

                    $stmt->execute([
                        $title,
                        $description,
                        $category,
                        $address,
                        $lat === '' ? null : (float)$lat,
                        $lng === '' ? null : (float)$lng,
                        $primaryThumb,
                        $primaryImage,
                        $authorName,
                        $ownerId,
                    ]);

                    $id = (int)$pdo->lastInsertId();

                    if (!empty($uploadedPhotos)) {
                        $photoStmt = $pdo->prepare("
                            INSERT INTO alert_photos (alert_id, image_path, thumbnail_path)
                            VALUES (?, ?, ?)
                        ");
                        foreach ($uploadedPhotos as $photo) {
                            $photoStmt->execute([$id, $photo['image_path'], $photo['thumbnail_path']]);
                        }
                    }

                    $pdo->prepare("
                        INSERT INTO status_history (alert_id, status, changed_by)
                        VALUES (?, 'Recebido', ?)
                    ")->execute([$id, $authorName]);

                    redirect('index.php?created=' . $id);
                }
            }
        }
    }

    if ($action === 'support') {
        $id = filter_input(INPUT_POST, 'alert_id', FILTER_VALIDATE_INT);

        if ($id) {
            $supporterId = $isLoggedIn ? 'user_' . $loggedUser['id'] : $citizenId;
            $stmt = $pdo->prepare(
                'INSERT OR IGNORE INTO supports (alert_id, supporter) VALUES (?, ?)'
            );
            $stmt->execute([$id, $supporterId]);
        }

        $qs = trim((string)($_SERVER['QUERY_STRING'] ?? ''));
        redirect($qs !== '' ? 'index.php?' . $qs : 'index.php');
    }
}

$mine = isset($_GET['mine']);
$showCreateRequested = isset($_GET['novo']);
$showAuthRequested = isset($_GET['auth']) || ($showCreateRequested && !$isLoggedIn);
$showCreate = $showCreateRequested && $isLoggedIn;

$filterLat = isset($_GET['lat']) && is_numeric($_GET['lat']) ? (float)$_GET['lat'] : null;
$filterLng = isset($_GET['lng']) && is_numeric($_GET['lng']) ? (float)$_GET['lng'] : null;
$filterRadius = 100.0;
$filterRef = trim((string)($_GET['ref'] ?? ''));

$isGeoFilterActive = ($filterLat !== null && $filterLng !== null);

$sql = "
    SELECT a.*,
           (SELECT COUNT(*)
            FROM supports s
            WHERE s.alert_id = a.id) AS support_count
    FROM alerts a
";

$params = [];

if ($mine) {
    $sql .= ' WHERE a.owner_id = ?';
    $params[] = $isLoggedIn ? (string)$loggedUser['id'] : '---none---';
}

$sql .= ' ORDER BY a.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($isGeoFilterActive) {
    foreach ($alerts as &$al) {
        if ($al['latitude'] !== null && $al['longitude'] !== null) {
            $al['distance_km'] = calcularDistanciaKm($filterLat, $filterLng, (float)$al['latitude'], (float)$al['longitude']);
        } else {
            $al['distance_km'] = null;
        }
    }
    unset($al);

    if ($filterRadius > 0) {
        $alerts = array_values(array_filter($alerts, function ($al) use ($filterRadius) {
            return $al['distance_km'] !== null && $al['distance_km'] <= $filterRadius;
        }));
    }

    usort($alerts, function ($a, $b) {
        if ($a['distance_km'] === null && $b['distance_km'] === null) return 0;
        if ($a['distance_km'] === null) return 1;
        if ($b['distance_km'] === null) return -1;
        return $a['distance_km'] <=> $b['distance_km'];
    });
} else {
    foreach ($alerts as &$al) {
        $al['distance_km'] = null;
    }
    unset($al);
}

$alertIds = array_column($alerts, 'id');
$photosByAlert = [];
$latestStatusByAlert = [];
if (!empty($alertIds)) {
    $in = implode(',', array_fill(0, count($alertIds), '?'));
    $stmtPhotos = $pdo->prepare("SELECT * FROM alert_photos WHERE alert_id IN ($in) ORDER BY id ASC");
    $stmtPhotos->execute($alertIds);
    foreach ($stmtPhotos->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $photosByAlert[$p['alert_id']][] = $p;
    }

    $stmtHist = $pdo->prepare("SELECT alert_id, status, changed_by, created_at FROM status_history WHERE alert_id IN ($in) ORDER BY id ASC");
    $stmtHist->execute($alertIds);
    foreach ($stmtHist->fetchAll(PDO::FETCH_ASSOC) as $hRow) {
        $latestStatusByAlert[(int)$hRow['alert_id']] = $hRow;
    }
}
?>
<!doctype html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>🚨 AlertaCidade — Cidadão</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🚨</text></svg>">
    <style>
        :root {
            --primary: #126b53;
            --primary-hover: #0e5441;
            --text-main: #18312a;
            --text-muted: #5a736a;
            --bg: #f2f6f4;
            --card-bg: #ffffff;
            --border: #dbe4e0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 15px;
            line-height: 1.5;
            margin: 0;
            background: var(--bg);
            color: var(--text-main);
            -webkit-font-smoothing: antialiased;
        }

        header {
            background: var(--primary);
            color: white;
            padding: 16px 20px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
        }

        header h1 {
            margin: 0 0 10px 0;
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.3px;
        }

        nav {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        nav a {
            color: rgba(255, 255, 255, 0.92);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 6px 12px;
            border-radius: 6px;
            transition: background 0.15s;
        }

        nav a:hover,
        nav a.active {
            background: rgba(255, 255, 255, 0.18);
            color: #fff;
        }

        main {
            max-width: 820px;
            margin: 24px auto;
            padding: 0 16px;
        }

        h2 {
            font-size: 20px;
            font-weight: 700;
            color: #124d3c;
            margin: 0 0 16px 0;
            letter-spacing: -0.2px;
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            margin: 16px 0;
            border: 1px solid var(--border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #23433a;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 16px 0 6px 0;
        }

        label:first-of-type {
            margin-top: 0;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 10px 12px;
            font-family: inherit;
            font-size: 14px;
            color: var(--text-main);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 10px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(18, 107, 83, 0.15);
        }

        button,
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: inherit;
            font-size: 14px;
            font-weight: 600;
            background: var(--primary);
            color: #fff;
            border: 0;
            border-radius: 8px;
            padding: 10px 18px;
            cursor: pointer;
            transition: background 0.15s, opacity 0.15s;
        }

        button:hover,
        .btn:hover {
            background: var(--primary-hover);
        }

        .btn-secondary {
            background: #eaf1ee;
            color: #126b53;
            border: 1px solid #c8dbd4;
            padding: 7px 14px;
            font-size: 13px;
        }

        .btn-secondary:hover {
            background: #dce9e4;
        }

        .form-help {
            font-size: 13px;
            color: var(--text-muted);
            margin: 2px 0 12px 0;
        }

        #map {
            height: 280px;
            margin: 8px 0 16px 0;
            border-radius: 10px;
            border: 1px solid var(--border);
        }

        .alert-title {
            font-size: 18px;
            font-weight: 700;
            margin: 0 0 8px 0;
            color: var(--text-main);
            line-height: 1.35;
        }

        .alert-code {
            display: inline-block;
            background: #eaf1ee;
            color: var(--primary);
            font-size: 12px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 6px;
            margin-right: 6px;
        }

        .meta-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            font-size: 12px;
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 12px;
        }

        .badge-category {
            background: #e4eee9;
            color: #135644;
        }

        .badge-status {
            background: #e9ecef;
            color: #495057;
        }

        .alert-date {
            color: var(--text-muted);
            font-size: 12px;
            margin-left: auto;
        }

        .alert-desc {
            font-size: 15px;
            line-height: 1.5;
            color: #2c423b;
            margin: 10px 0;
        }

        .alert-address {
            font-size: 13px;
            color: var(--text-muted);
            margin: 8px 0;
        }

        .card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 14px;
            padding-top: 12px;
            border-top: 1px solid #f0f4f2;
        }

        .support-count {
            font-size: 13px;
            font-weight: 600;
            color: #2d554a;
        }

        .status-progress-box {
            background: #f7faf9;
            border: 1px solid #e3ece8;
            border-radius: 10px;
            padding: 12px 14px;
            margin: 12px 0;
        }

        .status-progress-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .status-progress-track {
            width: 100%;
            height: 8px;
            background: #dfe9e5;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .status-progress-fill {
            height: 100%;
            border-radius: 999px;
            transition: width 0.35s ease;
        }

        .status-steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 4px;
            font-size: 11px;
        }

        .status-step {
            text-align: center;
            color: #879c94;
            font-weight: 500;
            padding: 2px 4px;
            border-radius: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .status-step.done {
            color: #126b53;
            font-weight: 600;
        }

        .status-step.current {
            color: #18312a;
            font-weight: 700;
            background: #e8f2ee;
        }

        .error {
            color: #9b2215;
            background: #fdeeee;
            border: 1px solid #f9cccc;
            padding: 10px 14px;
            border-radius: 8px;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 35, 29, 0.62);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 16px;
            overflow-y: auto;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: #ffffff;
            border-radius: 14px;
            max-width: 520px;
            width: 100%;
            padding: 24px;
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.22);
            border: 1px solid var(--border);
            max-height: 92vh;
            overflow-y: auto;
        }

        .mvp-notice {
            background: #eef7f4;
            border: 1px solid #b8dcd0;
            border-left: 4px solid var(--primary);
            color: #16493a;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.45;
            margin-bottom: 14px;
        }

        .field-mvp-hint {
            display: block;
            font-size: 11px;
            font-weight: 500;
            text-transform: none;
            letter-spacing: 0;
            color: #126b53;
            margin-top: 2px;
        }
    </style>
    <?php if ($showCreate): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <?php endif; ?>
</head>

<body>
    <header>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
            <h1 style="margin:0">AlertaCidade</h1>
            <div style="font-size:13px;display:flex;align-items:center;gap:12px">
                <?php if ($isLoggedIn): ?>
                    <span>👤 Olá, <strong><?= h(trim((string)($loggedUser['name'] ?? '')) ?: (string)$loggedUser['username']) ?></strong></span>
                    <button type="button" id="btn-open-profile-modal" style="background:rgba(255,255,255,0.18);color:#fff;border:1px solid rgba(255,255,255,0.35);padding:4px 10px;border-radius:6px;font-size:12px;cursor:pointer">Dados cadastrais</button>
                    <a href="index.php?logout=1" style="color:rgba(255,255,255,0.9);text-decoration:underline;font-size:13px">Sair</a>
                <?php else: ?>
                    <a href="index.php?auth=1" style="color:#fff;background:rgba(255,255,255,0.18);padding:5px 12px;border-radius:6px;text-decoration:none;font-weight:600">Entrar / Cadastrar</a>
                <?php endif; ?>
            </div>
        </div>
        <nav style="margin-top:12px">
            <a href="index.php" class="<?= !$showCreateRequested && !$showAuthRequested && !$mine ? 'active' : '' ?>">Feed</a>
            <a href="index.php?mine=1" class="<?= $mine ? 'active' : '' ?>">Meus alertas</a>
            <a href="index.php?novo=1" class="<?= $showCreateRequested ? 'active' : '' ?>">Registrar ocorrência</a>
        </nav>
    </header>

    <?php if ($isLoggedIn): ?>
        <div id="profile-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="profile-modal-title">
            <div class="modal-box">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <h2 id="profile-modal-title" style="margin:0;font-size:18px">Dados cadastrais do usuário</h2>
                    <button type="button" id="btn-close-profile-modal" style="background:none;border:0;color:var(--text-muted);font-size:18px;padding:4px 8px;cursor:pointer">✕</button>
                </div>

                <div class="mvp-notice">
                    <strong>Aviso de MVP:</strong> Não é preciso informar dados pessoais reais ou sensíveis, pois este sistema é um MVP. Você pode preencher com <strong>dados fictícios</strong>.
                </div>

                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_profile">

                    <label for="prof_name">
                        Nome completo
                        <span class="field-mvp-hint">Não é preciso adicionar dado sensível (MVP) — aceita nome fictício</span>
                    </label>
                    <input id="prof_name" name="name" value="<?= h((string)($loggedUser['name'] ?? '')) ?>" placeholder="Ex.: Maria Silva Fictícia (dado fictício)">

                    <label for="prof_email">
                        E-mail
                        <span class="field-mvp-hint">Não é preciso adicionar e-mail real/sensível (MVP) — aceita e-mail fictício</span>
                    </label>
                    <input id="prof_email" type="text" name="email" value="<?= h((string)($loggedUser['email'] ?? '')) ?>" placeholder="Ex.: usuario.ficticio@mvp.teste">

                    <label for="prof_phone">
                        Telefone / Celular
                        <span class="field-mvp-hint">Não é preciso adicionar telefone real/sensível (MVP) — aceita número fictício</span>
                    </label>
                    <input id="prof_phone" name="phone" value="<?= h((string)($loggedUser['phone'] ?? '')) ?>" placeholder="Ex.: (11) 90000-0000 (dado fictício)">

                    <label for="prof_cpf">
                        CPF / Documento (Dados cadastrais)
                        <span class="field-mvp-hint">Não é preciso adicionar CPF real/sensível (MVP) — aceita número fictício</span>
                    </label>
                    <input id="prof_cpf" name="cpf" value="<?= h((string)($loggedUser['cpf'] ?? '')) ?>" placeholder="Ex.: 000.000.000-00 (dado fictício)">

                    <label for="prof_address_info">
                        Bairro / Cidade (Dados cadastrais)
                        <span class="field-mvp-hint">Não é preciso adicionar endereço sensível (MVP) — aceita dado fictício</span>
                    </label>
                    <input id="prof_address_info" name="address_info" value="<?= h((string)($loggedUser['address_info'] ?? '')) ?>" placeholder="Ex.: Centro - Cidade Fictícia">

                    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
                        <button type="button" id="btn-fill-fictitious-prof" class="btn btn-secondary" style="flex:1">Preencher dados fictícios</button>
                        <button type="submit" class="btn" style="flex:1">Salvar dados</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
            (function() {
                const modal = document.getElementById('profile-modal');
                const btnOpen = document.getElementById('btn-open-profile-modal');
                const btnClose = document.getElementById('btn-close-profile-modal');
                const btnFill = document.getElementById('btn-fill-fictitious-prof');

                if (btnOpen && modal) {
                    btnOpen.addEventListener('click', () => modal.classList.add('open'));
                }
                if (btnClose && modal) {
                    btnClose.addEventListener('click', () => modal.classList.remove('open'));
                }
                if (modal) {
                    modal.addEventListener('click', (e) => {
                        if (e.target === modal) modal.classList.remove('open');
                    });
                }
                if (btnFill) {
                    btnFill.addEventListener('click', () => {
                        document.getElementById('prof_name').value = 'Cidadão Teste MVP (Fictício)';
                        document.getElementById('prof_email').value = 'cidadao.ficticio@mvp.teste';
                        document.getElementById('prof_phone').value = '(11) 90000-0000';
                        document.getElementById('prof_cpf').value = '000.000.000-00';
                        document.getElementById('prof_address_info').value = 'Bairro Exemplo - Cidade MVP';
                    });
                }
            })();
        </script>
    <?php endif; ?>

    <main>
        <?php if (isset($_GET['profile_saved'])): ?>
            <div class="card" style="border-left: 4px solid #126b53;">
                <strong style="color:#126b53">✓ Dados cadastrais atualizados com sucesso!</strong>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['created'])): ?>
            <div class="card" style="border-left: 4px solid #126b53;">
                <strong style="color:#126b53">✓ Ocorrência registrada com sucesso!</strong>
                <?php if (is_numeric($_GET['created'])): ?>
                    <p class="form-help" style="margin-top:6px">Guarde o código da ocorrência: <strong><?= formatAlertCode((int)$_GET['created']) ?></strong></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="error"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($showAuthRequested): ?>
            <section class="card" style="max-width:480px;margin:24px auto">
                <div style="text-align:center;margin-bottom:18px">
                    <h2 style="margin-bottom:6px">Acesso do Cidadão</h2>
                    <p class="form-help">Para registrar sua ocorrência, entre com sua conta ou cadastre-se abaixo.</p>
                </div>

                <?php if ($authError !== ''): ?>
                    <div class="error" style="margin-bottom:16px"><?= h($authError) ?></div>
                <?php endif; ?>

                <div style="display:flex;border-bottom:2px solid var(--border);margin-bottom:18px">
                    <button type="button" id="tab-btn-login" style="flex:1;background:none;border:0;padding:10px;font-weight:<?= $activeAuthTab === 'login' ? '700' : '600' ?>;font-size:14px;border-bottom:<?= $activeAuthTab === 'login' ? '3px solid var(--primary)' : 'none' ?>;color:<?= $activeAuthTab === 'login' ? 'var(--primary)' : 'var(--text-muted)' ?>">Entrar</button>
                    <button type="button" id="tab-btn-register" style="flex:1;background:none;border:0;padding:10px;font-weight:<?= $activeAuthTab === 'register' ? '700' : '600' ?>;font-size:14px;border-bottom:<?= $activeAuthTab === 'register' ? '3px solid var(--primary)' : 'none' ?>;color:<?= $activeAuthTab === 'register' ? 'var(--primary)' : 'var(--text-muted)' ?>">Criar conta</button>
                </div>

                <!-- Formulário Login -->
                <form id="form-login" method="post" style="display:<?= $activeAuthTab === 'login' ? 'block' : 'none' ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="redirect_to" value="<?= $showCreateRequested ? 'index.php?novo=1' : 'index.php' ?>">

                    <label for="login_username">Nome, e-mail ou CPF</label>
                    <input id="login_username" name="username" required autocomplete="username" placeholder="Digite seu nome (ou usuário), e-mail ou CPF">

                    <label for="login_password">Senha</label>
                    <input id="login_password" type="password" name="password" required autocomplete="current-password" placeholder="Digite sua senha">

                    <button type="submit" class="btn" style="width:100%;margin-top:8px;padding:12px">Entrar e continuar</button>
                </form>

                <!-- Formulário Cadastro -->
                <form id="form-register" method="post" style="display:<?= $activeAuthTab === 'register' ? 'block' : 'none' ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="redirect_to" value="<?= $showCreateRequested ? 'index.php?novo=1' : 'index.php' ?>">

                    <label for="reg_username">Nome de usuário</label>
                    <input
                        id="reg_username"
                        name="username"
                        required
                        minlength="3"
                        maxlength="32"
                        pattern="[A-Za-z0-9_]{3,32}"
                        autocomplete="username"
                        placeholder="3 a 32 letras, números ou _">

                    <label for="reg_password">Senha</label>
                    <input
                        id="reg_password"
                        type="password"
                        name="password"
                        minlength="8"
                        required
                        autocomplete="new-password"
                        placeholder="Crie uma senha com pelo menos 8 caracteres">

                    <button type="button" id="btn-open-register-popup" class="btn" style="width:100%;margin-top:8px;padding:12px">Cadastrar e preencher dados cadastrais</button>

                    <!-- Popup Modal de Dados Cadastrais ao criar usuário -->
                    <div id="register-popup-modal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="reg-modal-title">
                        <div class="modal-box">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                                <h2 id="reg-modal-title" style="margin:0;font-size:18px">Dados Cadastrais do Usuário</h2>
                                <button type="button" id="btn-close-register-popup" style="background:none;border:0;color:var(--text-muted);font-size:18px;padding:4px 8px;cursor:pointer">✕</button>
                            </div>

                            <div class="mvp-notice">
                                <strong>Aviso importante (MVP):</strong> Não é preciso adicionar dados reais ou sensíveis, pois este sistema é um <strong>MVP</strong>. Você pode preencher os campos abaixo com <strong>dados fictícios</strong>.
                            </div>

                            <label for="reg_name">
                                Nome completo
                                <span class="field-mvp-hint">Não é preciso adicionar dado sensível pois é um MVP — aceita nome fictício</span>
                            </label>
                            <input
                                id="reg_name"
                                name="name"
                                placeholder="Ex.: Maria da Silva Fictícia (não precisa ser real - MVP)">

                            <label for="reg_email">
                                E-mail
                                <span class="field-mvp-hint">Não é preciso adicionar e-mail sensível pois é um MVP — aceita e-mail fictício</span>
                            </label>
                            <input
                                id="reg_email"
                                type="text"
                                name="email"
                                placeholder="Ex.: email.ficticio@mvp.teste (aceita dados fictícios)">

                            <label for="reg_phone">
                                Telefone / Celular
                                <span class="field-mvp-hint">Não é preciso adicionar telefone real/sensível pois é um MVP — aceita fictício</span>
                            </label>
                            <input
                                id="reg_phone"
                                name="phone"
                                placeholder="Ex.: (11) 90000-0000 (dado fictício para MVP)">

                            <label for="reg_cpf">
                                CPF / Documento (Dados cadastrais)
                                <span class="field-mvp-hint">Não é preciso adicionar CPF real/sensível pois é um MVP — aceita fictício</span>
                            </label>
                            <input
                                id="reg_cpf"
                                name="cpf"
                                placeholder="Ex.: 000.000.000-00 (use números fictícios)">

                            <label for="reg_address_info">
                                Endereço / Cidade (Dados cadastrais)
                                <span class="field-mvp-hint">Não é preciso adicionar endereço sensível pois é um MVP — aceita fictício</span>
                            </label>
                            <input
                                id="reg_address_info"
                                name="address_info"
                                placeholder="Ex.: Bairro Exemplo - Cidade Teste (dado fictício)">

                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:14px">
                                <button type="button" id="btn-fill-fictitious-reg" class="btn btn-secondary" style="flex:1">Preencher com dados fictícios</button>
                                <button type="submit" class="btn" style="flex:1">Concluir cadastro</button>
                            </div>
                        </div>
                    </div>
                </form>
            </section>

            <script>
                const tabLogin = document.getElementById('tab-btn-login');
                const tabRegister = document.getElementById('tab-btn-register');
                const formLogin = document.getElementById('form-login');
                const formRegister = document.getElementById('form-register');

                if (tabLogin && tabRegister && formLogin && formRegister) {
                    tabLogin.addEventListener('click', () => {
                        formLogin.style.display = 'block';
                        formRegister.style.display = 'none';
                        tabLogin.style.borderBottom = '3px solid var(--primary)';
                        tabLogin.style.color = 'var(--primary)';
                        tabLogin.style.fontWeight = '700';
                        tabRegister.style.borderBottom = 'none';
                        tabRegister.style.color = 'var(--text-muted)';
                        tabRegister.style.fontWeight = '600';
                    });
                    tabRegister.addEventListener('click', () => {
                        formLogin.style.display = 'none';
                        formRegister.style.display = 'block';
                        tabRegister.style.borderBottom = '3px solid var(--primary)';
                        tabRegister.style.color = 'var(--primary)';
                        tabRegister.style.fontWeight = '700';
                        tabLogin.style.borderBottom = 'none';
                        tabLogin.style.color = 'var(--text-muted)';
                        tabLogin.style.fontWeight = '600';
                    });
                }

                const btnOpenRegPopup = document.getElementById('btn-open-register-popup');
                const btnCloseRegPopup = document.getElementById('btn-close-register-popup');
                const regPopupModal = document.getElementById('register-popup-modal');
                const regUsernameInput = document.getElementById('reg_username');
                const regPasswordInput = document.getElementById('reg_password');
                const btnFillFictitiousReg = document.getElementById('btn-fill-fictitious-reg');

                if (btnOpenRegPopup && regPopupModal && regUsernameInput && regPasswordInput) {
                    const abrirPopupCadastro = () => {
                        if (!regUsernameInput.reportValidity() || !regPasswordInput.reportValidity()) {
                            return;
                        }
                        regPopupModal.classList.add('open');
                        const nameField = document.getElementById('reg_name');
                        if (nameField) nameField.focus();
                    };

                    btnOpenRegPopup.addEventListener('click', abrirPopupCadastro);

                    if (formRegister) {
                        formRegister.addEventListener('submit', (e) => {
                            if (!regPopupModal.classList.contains('open')) {
                                e.preventDefault();
                                abrirPopupCadastro();
                            }
                        });
                    }
                }

                if (btnCloseRegPopup && regPopupModal) {
                    btnCloseRegPopup.addEventListener('click', () => {
                        regPopupModal.classList.remove('open');
                    });
                }

                if (regPopupModal) {
                    regPopupModal.addEventListener('click', (e) => {
                        if (e.target === regPopupModal) {
                            regPopupModal.classList.remove('open');
                        }
                    });
                }

                if (btnFillFictitiousReg) {
                    btnFillFictitiousReg.addEventListener('click', () => {
                        const u = (regUsernameInput && regUsernameInput.value.trim()) ? regUsernameInput.value.trim() : 'cidadao';
                        document.getElementById('reg_name').value = 'Usuário Fictício (' + u + ')';
                        document.getElementById('reg_email').value = u + '.ficticio@mvp.teste';
                        document.getElementById('reg_phone').value = '(11) 90000-0000';
                        document.getElementById('reg_cpf').value = '000.000.000-00';
                        document.getElementById('reg_address_info').value = 'Bairro Centro - Cidade Fictícia (MVP)';
                    });
                }
            </script>

        <?php elseif ($showCreate): ?>
            <section class="card">
                <h2>Registrar ocorrência</h2>
                <form method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="create">

                    <label for="title">Título da ocorrência</label>
                    <input id="title" name="title" maxlength="120" required placeholder="Ex.: Lâmpada de poste apagada, buraco na via...">

                    <label for="description">Descrição detalhada</label>
                    <textarea id="description" name="description" rows="4" maxlength="2000" required placeholder="Descreva a situação com mais detalhes..."></textarea>

                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px">
                        <button type="button" id="suggest-category" class="btn btn-secondary">
                            ✦ Sugerir categoria
                        </button>
                        <span id="suggestion-message" style="font-size:13px" aria-live="polite"></span>
                    </div>

                    <label for="category">Categoria</label>
                    <select id="category" name="category" required>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= h($category) ?>">
                                <?= h($category) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="address">Endereço ou ponto de referência</label>
                    <div style="margin:0 0 4px 0">
                        <button type="button" id="btn-clear-address" style="background:none;border:none;color:#9b2215;font-size:12px;cursor:pointer;padding:0;text-decoration:underline">Limpar endereço</button>
                    </div>
                    <div style="display:flex;gap:8px;margin-bottom:4px;flex-wrap:wrap">
                        <input id="address" name="address" required placeholder="Rua, número ou ponto de referência" style="flex:1;min-width:220px;margin:0">
                        <button type="button" id="search-address" class="btn btn-secondary" style="white-space:nowrap">Localizar no mapa</button>
                        <button type="button" id="btn-use-my-gps" class="btn btn-secondary" style="white-space:nowrap" title="Recarregar localização atual pelo GPS">Usar meu GPS</button>
                    </div>
                    <p id="geo-status" class="form-help" aria-live="polite"></p>

                    <label>Localização exata no mapa</label>
                    <p class="form-help">Toque no mapa para marcar o local ou arraste o pin para o ponto exato.</p>
                    <div id="map"></div>
                    <input type="hidden" name="latitude" id="latitude">
                    <input type="hidden" name="longitude" id="longitude">

                    <div id="photos-upload-section">
                        <label for="photos">Fotos da ocorrência <small style="font-weight:normal;text-transform:none;color:#5a736a">(opcional — até 3 fotos, JPG, PNG ou WebP até 5 MB cada)</small></label>
                        <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
                        <div id="photos-preview-container" style="display:none;margin-bottom:16px;gap:8px;flex-wrap:wrap"></div>
                    </div>
                    <div id="photos-sensitive-notice" style="display:none;background:#fff4e5;border:1px solid #f5c278;border-left:4px solid #c76a00;color:#663500;padding:12px 14px;border-radius:8px;font-size:13px;margin:14px 0 18px 0">
                        <strong>⚠️ Envio de fotos desativado:</strong> Foi detectado um termo sensível no relato (<span id="sensitive-word-found" style="font-weight:700"></span>). Para preservar a integridade do site e a privacidade dos envolvidos, a opção de subir fotos foi descartada para esta ocorrência.
                    </div>

                    <button type="submit" class="btn" style="width:100%;padding:13px;font-size:16px">Publicar alerta</button>
                </form>
            </section>

            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <script>
                const map = L.map('map').setView([-23.1896, -45.8841], 13);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap'
                }).addTo(map);

                let marker;

                function setLocation(lat, lng) {
                    document.getElementById('latitude').value = Number(lat).toFixed(6);
                    document.getElementById('longitude').value = Number(lng).toFixed(6);

                    if (marker) {
                        marker.setLatLng([lat, lng]);
                    } else {
                        marker = L.marker([lat, lng], {
                            draggable: true
                        }).addTo(map);
                        marker.bindPopup('Ponto da ocorrência<br><small>Arraste para ajustar o endereço</small>');

                        marker.on('dragend', function(e) {
                            const pos = e.target.getLatLng();
                            document.getElementById('latitude').value = pos.lat.toFixed(6);
                            document.getElementById('longitude').value = pos.lng.toFixed(6);
                            updateAddressFromCoords(pos.lat, pos.lng);
                        });
                    }
                }

                async function updateAddressFromCoords(lat, lng) {
                    const addressInput = document.getElementById('address');
                    if (!addressInput) return;

                    if (geoStatus) {
                        geoStatus.style.color = '#5c7068';
                        geoStatus.textContent = 'Atualizando endereço a partir da posição do pin...';
                    }

                    try {
                        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`);
                        const data = await res.json();
                        if (data) {
                            let formatted = data.display_name;
                            if (data.address) {
                                const parts = [];
                                const logradouro = data.address.road || data.address.pedestrian || data.address.footway || data.address.street;
                                if (logradouro) {
                                    let line = logradouro;
                                    if (data.address.house_number) {
                                        line += ', ' + data.address.house_number;
                                    }
                                    parts.push(line);
                                }
                                const bairro = data.address.suburb || data.address.neighbourhood || data.address.residential;
                                if (bairro) {
                                    parts.push(bairro);
                                }
                                const cidade = data.address.city || data.address.town || data.address.municipality || data.address.village;
                                if (cidade) {
                                    parts.push(cidade);
                                }
                                if (parts.length > 0) {
                                    formatted = parts.join(' - ');
                                }
                            }
                            addressInput.value = formatted;
                            if (geoStatus) {
                                geoStatus.style.color = '#126b53';
                                geoStatus.textContent = '✓ Endereço atualizado com a posição do pin: ' + formatted;
                            }
                        }
                    } catch (_) {
                        if (geoStatus) {
                            geoStatus.style.color = '#126b53';
                            geoStatus.textContent = '✓ Pin ajustado (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + ').';
                        }
                    }
                }

                map.on('click', e => {
                    setLocation(e.latlng.lat, e.latlng.lng);
                    updateAddressFromCoords(e.latlng.lat, e.latlng.lng);
                });

                const searchAddressBtn = document.getElementById('search-address');
                const btnUseMyGps = document.getElementById('btn-use-my-gps');
                const btnClearAddress = document.getElementById('btn-clear-address');
                const addressInput = document.getElementById('address');
                const geoStatus = document.getElementById('geo-status');

                if (addressInput) {
                    addressInput.addEventListener('input', () => {
                        addressInput.dataset.manualEdit = 'true';
                    });
                }

                if (btnClearAddress && addressInput) {
                    btnClearAddress.addEventListener('click', () => {
                        addressInput.value = '';
                        addressInput.dataset.manualEdit = 'true';
                        document.getElementById('latitude').value = '';
                        document.getElementById('longitude').value = '';
                        if (marker) {
                            map.removeLayer(marker);
                            marker = null;
                        }
                        if (geoStatus) {
                            geoStatus.style.color = 'var(--text-muted)';
                            geoStatus.textContent = 'Endereço limpo. Digite o endereço ou toque no mapa.';
                        }
                        addressInput.focus();
                    });
                }

                async function aplicarLocalizacaoGPS(lat, lng) {
                    setLocation(lat, lng);
                    map.setView([lat, lng], 16);

                    if (geoStatus) {
                        geoStatus.style.color = '#5c7068';
                        geoStatus.textContent = 'Identificando endereço da sua localização...';
                    }

                    try {
                        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`);
                        const data = await res.json();
                        if (data) {
                            let formatted = data.display_name;
                            if (data.address) {
                                const parts = [];
                                const logradouro = data.address.road || data.address.pedestrian || data.address.footway || data.address.street;
                                if (logradouro) {
                                    let line = logradouro;
                                    if (data.address.house_number) {
                                        line += ', ' + data.address.house_number;
                                    }
                                    parts.push(line);
                                }
                                const bairro = data.address.suburb || data.address.neighbourhood || data.address.residential;
                                if (bairro) parts.push(bairro);
                                const cidade = data.address.city || data.address.town || data.address.municipality || data.address.village;
                                if (cidade) parts.push(cidade);
                                if (parts.length > 0) formatted = parts.join(' - ');
                            }
                            if (addressInput) {
                                addressInput.value = formatted;
                            }
                            if (geoStatus) {
                                geoStatus.style.color = '#126b53';
                                geoStatus.textContent = '✓ Endereço preenchido automaticamente pelo GPS. Você pode alterá-lo ou mover o pin.';
                            }
                        }
                    } catch (_) {
                        if (geoStatus) {
                            geoStatus.style.color = '#126b53';
                            geoStatus.textContent = '✓ Localização obtida (' + lat.toFixed(5) + ', ' + lng.toFixed(5) + '). Você pode editar o endereço ou mover o pin.';
                        }
                    }
                }

                async function fallbackRede(msgErro) {
                    try {
                        if (geoStatus) {
                            geoStatus.style.color = '#5c7068';
                            geoStatus.textContent = 'GPS indisponível. Tentando localização pela rede...';
                        }
                        const res = await fetch('https://ipapi.co/json/');
                        const data = await res.json();
                        if (data && data.latitude && data.longitude) {
                            await aplicarLocalizacaoGPS(data.latitude, data.longitude);
                            return;
                        }
                    } catch (_) {}

                    if (geoStatus) {
                        geoStatus.style.color = 'var(--text-muted)';
                        geoStatus.textContent = msgErro || 'Informe o endereço acima ou toque no mapa para posicionar o pin.';
                    }
                }

                function obterLocalizacaoAtual(forcar = false) {
                    if (!navigator.geolocation) {
                        fallbackRede('Geolocalização não suportada pelo navegador. Digite o endereço ou toque no mapa.');
                        return;
                    }

                    if (geoStatus) {
                        geoStatus.style.color = '#5c7068';
                        geoStatus.textContent = 'Detectando sua localização atual via GPS...';
                    }

                    const geoOptions = {
                        enableHighAccuracy: false,
                        timeout: 8000,
                        maximumAge: 60000
                    };

                    navigator.geolocation.getCurrentPosition(
                        async position => {
                                if (forcar || !addressInput || !addressInput.value.trim() || addressInput.dataset.manualEdit !== 'true') {
                                    await aplicarLocalizacaoGPS(position.coords.latitude, position.coords.longitude);
                                }
                            },
                            () => {
                                fallbackRede('Não foi possível obter o sinal de GPS. Você pode digitar o endereço ou tocar no mapa.');
                            },
                            geoOptions
                    );
                }

                // Inicialização automática ao abrir o formulário
                obterLocalizacaoAtual(false);

                if (btnUseMyGps) {
                    btnUseMyGps.addEventListener('click', () => {
                        if (addressInput) addressInput.dataset.manualEdit = 'false';
                        obterLocalizacaoAtual(true);
                    });
                }

                async function localizarPorEndereco(query) {
                    if (!query) return false;
                    if (geoStatus) {
                        geoStatus.style.color = '#5c7068';
                        geoStatus.textContent = 'Localizando no mapa a partir do endereço...';
                    }

                    try {
                        const url = `https://nominatim.openstreetmap.org/search?format=json&countrycodes=br&limit=1&q=${encodeURIComponent(query)}`;
                        const response = await fetch(url);
                        const data = await response.json();

                        if (data && data.length > 0) {
                            const lat = parseFloat(data[0].lat);
                            const lng = parseFloat(data[0].lon);
                            setLocation(lat, lng);
                            map.setView([lat, lng], 16);
                            if (geoStatus) {
                                geoStatus.style.color = '#126b53';
                                geoStatus.textContent = '✓ Pin posicionado no local! Você também pode clicar ou arrastar o pin para ajustar.';
                            }
                            return true;
                        } else {
                            if (geoStatus) {
                                geoStatus.style.color = '#9b2215';
                                geoStatus.textContent = 'Endereço não localizado automaticamente. Toque no mapa para posicionar o pin manualmente.';
                            }
                            return false;
                        }
                    } catch (err) {
                        if (geoStatus) {
                            geoStatus.style.color = '#9b2215';
                            geoStatus.textContent = 'Erro ao buscar endereço. Você pode tocar no mapa para posicionar o pin.';
                        }
                        return false;
                    }
                }

                if (searchAddressBtn && addressInput) {
                    searchAddressBtn.addEventListener('click', async () => {
                        const query = addressInput.value.trim();
                        if (!query) {
                            if (geoStatus) {
                                geoStatus.style.color = '#9b2215';
                                geoStatus.textContent = 'Digite um endereço ou referência antes de buscar.';
                            }
                            addressInput.focus();
                            return;
                        }

                        searchAddressBtn.disabled = true;
                        await localizarPorEndereco(query);
                        searchAddressBtn.disabled = false;
                    });

                    addressInput.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            searchAddressBtn.click();
                        }
                    });
                }
            </script>
            <script>
                const suggestButton = document.getElementById('suggest-category');
                const titleInput = document.getElementById('title');
                const descriptionInput = document.getElementById('description');
                const categorySelect = document.getElementById('category');
                const message = document.getElementById('suggestion-message');
                let manualCategoryChosen = false;

                if (categorySelect) {
                    categorySelect.addEventListener('change', () => {
                        manualCategoryChosen = true;
                    });
                }

                async function obterSugestao(automatica = false) {
                    const title = titleInput ? titleInput.value.trim() : '';
                    const description = descriptionInput ? descriptionInput.value.trim() : '';

                    if (!title && !description) {
                        if (!automatica && message) {
                            message.textContent = 'Preencha o título ou a descrição primeiro.';
                        }
                        return;
                    }

                    if (automatica && manualCategoryChosen) {
                        return;
                    }

                    if (suggestButton) suggestButton.disabled = true;
                    if (message) {
                        message.style.color = '#5c7068';
                        message.textContent = 'Analisando ocorrência para sugerir categoria...';
                    }

                    try {
                        const formData = new FormData();
                        formData.append('title', title);
                        formData.append('description', description);

                        const response = await fetch('suggest-category.php', {
                            method: 'POST',
                            body: formData
                        });

                        const result = await response.json();

                        if (!response.ok) {
                            if (message) {
                                message.style.color = '#9b2215';
                                message.textContent = result.error || 'Não foi possível obter uma sugestão.';
                            }
                            return;
                        }

                        if (result.category) {
                            const optionExists = Array.from(categorySelect.options)
                                .some(option => option.value === result.category);

                            if (optionExists) {
                                categorySelect.value = result.category;
                                if (message) {
                                    message.style.color = '#126b53';
                                    message.textContent = '✓ Categoria sugerida: "' + result.category + '" (você pode alterá-la se desejar).';
                                }
                            }
                        } else {
                            if (message) {
                                message.style.color = '#5c7068';
                                message.textContent = 'Não encontramos uma categoria com certeza. Por favor, escolha manualmente.';
                            }
                        }
                    } catch (error) {
                        if (!automatica && message) {
                            message.style.color = '#9b2215';
                            message.textContent = 'Erro ao obter sugestão. Escolha uma categoria manualmente.';
                        }
                    } finally {
                        if (suggestButton) suggestButton.disabled = false;
                    }
                }

                if (suggestButton) {
                    suggestButton.addEventListener('click', () => obterSugestao(false));
                }

                if (titleInput) {
                    titleInput.addEventListener('blur', () => {
                        if (titleInput.value.trim().length > 3) {
                            obterSugestao(true);
                        }
                    });
                }
                if (descriptionInput) {
                    descriptionInput.addEventListener('blur', () => {
                        if (descriptionInput.value.trim().length > 3) {
                            obterSugestao(true);
                        }
                    });
                }

                const photosInput = document.getElementById('photos');
                const previewContainer = document.getElementById('photos-preview-container');
                const photosUploadSection = document.getElementById('photos-upload-section');
                const photosSensitiveNotice = document.getElementById('photos-sensitive-notice');
                const sensitiveWordFoundSpan = document.getElementById('sensitive-word-found');
                const sensitiveTerms = <?= json_encode(obterPalavrasSensiveis(), JSON_UNESCAPED_UNICODE) ?>;

                function normalizarTextoJS(str) {
                    return (str || '')
                        .toLowerCase()
                        .normalize('NFD')
                        .replace(/[\u0300-\u036f]/g, '')
                        .replace(/[^\w\s]/g, ' ')
                        .replace(/\s+/g, ' ')
                        .trim();
                }

                function verificarPalavrasSensiveis() {
                    const titulo = titleInput ? titleInput.value : '';
                    const descricao = descriptionInput ? descriptionInput.value : '';
                    const combinado = normalizarTextoJS(titulo + ' ' + descricao);

                    let encontrado = null;
                    if (combinado !== '') {
                        for (const termo of sensitiveTerms) {
                            const escaped = termo.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                            const regex = new RegExp('\\b' + escaped + '\\b', 'i');
                            if (regex.test(combinado)) {
                                encontrado = termo;
                                break;
                            }
                        }
                    }

                    if (encontrado) {
                        if (photosInput) {
                            photosInput.value = '';
                            photosInput.disabled = true;
                        }
                        if (previewContainer) {
                            previewContainer.innerHTML = '';
                            previewContainer.style.display = 'none';
                        }
                        if (photosUploadSection) {
                            photosUploadSection.style.display = 'none';
                        }
                        if (photosSensitiveNotice) {
                            if (sensitiveWordFoundSpan) {
                                sensitiveWordFoundSpan.textContent = '"' + encontrado + '"';
                            }
                            photosSensitiveNotice.style.display = 'block';
                        }
                    } else {
                        if (photosInput) {
                            photosInput.disabled = false;
                        }
                        if (photosUploadSection) {
                            photosUploadSection.style.display = 'block';
                        }
                        if (photosSensitiveNotice) {
                            photosSensitiveNotice.style.display = 'none';
                        }
                    }
                }

                if (titleInput) {
                    titleInput.addEventListener('input', verificarPalavrasSensiveis);
                    titleInput.addEventListener('change', verificarPalavrasSensiveis);
                }
                if (descriptionInput) {
                    descriptionInput.addEventListener('input', verificarPalavrasSensiveis);
                    descriptionInput.addEventListener('change', verificarPalavrasSensiveis);
                }
                verificarPalavrasSensiveis();

                if (photosInput && previewContainer) {
                    photosInput.addEventListener('change', function() {
                        previewContainer.innerHTML = '';
                        const files = Array.from(this.files || []);

                        if (files.length > 3) {
                            alert('Você pode selecionar no máximo 3 fotos.');
                            this.value = '';
                            previewContainer.style.display = 'none';
                            return;
                        }

                        if (files.length > 0) {
                            previewContainer.style.display = 'flex';
                            files.forEach(file => {
                                const img = document.createElement('img');
                                img.src = URL.createObjectURL(file);
                                img.alt = 'Prévia';
                                img.style.width = '100px';
                                img.style.height = '100px';
                                img.style.objectFit = 'cover';
                                img.style.borderRadius = '8px';
                                img.style.border = '1px solid #ccc';
                                previewContainer.appendChild(img);
                            });
                        } else {
                            previewContainer.style.display = 'none';
                        }
                    });
                }
            </script>
        <?php elseif ($mine && !$isLoggedIn): ?>
            <section class="card" style="text-align:center;padding:36px 20px;max-width:500px;margin:24px auto">
                <h2>Meus alertas</h2>
                <p class="form-help">Você precisa estar conectado à sua conta para visualizar as ocorrências que você registrou.</p>
                <a href="index.php?auth=1" class="btn" style="text-decoration:none;margin-top:12px;display:inline-block">Entrar ou cadastrar-se</a>
            </section>
        <?php else: ?>

            <div class="card" style="margin-bottom:20px;padding:16px 20px;border-left:4px solid var(--primary)">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px">
                    <div>
                        <strong style="font-size:14px;color:var(--text-main)">Filtrar por proximidade</strong>
                        <?php if ($isGeoFilterActive): ?>
                            <p style="margin:2px 0 0;font-size:13px;color:var(--primary)">
                                Filtrando em até 100 km de: <strong><?= h($filterRef ?: 'Local marcado') ?></strong>
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php if ($isGeoFilterActive): ?>
                        <a href="index.php<?= $mine ? '?mine=1' : '' ?>" style="font-size:13px;color:#9b2215;text-decoration:underline">✕ Limpar filtro</a>
                    <?php endif; ?>
                </div>

                <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">
                    <button type="button" id="btn-near-me" class="btn btn-secondary" style="padding:8px 14px">
                        Perto de mim (usar GPS)
                    </button>
                    <span id="near-me-status" style="font-size:12px;color:var(--text-muted)"></span>
                </div>

                <div>
                    <label style="margin:0 0 2px 0;font-size:12px">Ou perto de um endereço / referência:</label>
                    <div style="margin:0 0 4px 0">
                        <button type="button" id="btn-clear-near-address" style="background:none;border:none;color:#9b2215;font-size:12px;cursor:pointer;padding:0;text-decoration:underline">Limpar endereço</button>
                    </div>
                    <div style="display:flex;gap:8px">
                        <input type="text" id="address-query" value="<?= h($filterRef) ?>" placeholder="Ex.: Centro, Praça Afonso Pena, Rua..." style="flex:1;margin:0">
                        <button type="button" id="btn-search-near-address" class="btn btn-secondary" style="white-space:nowrap;padding:8px 16px">
                            Buscar
                        </button>
                    </div>
                    <p id="near-address-status" style="font-size:12px;margin:4px 0 0 0;color:var(--text-muted)"></p>
                </div>
            </div>

            <h2><?= $mine ? 'Meus alertas' : 'Ocorrências da comunidade' ?></h2>

            <?php if (!$alerts): ?>
                <div class="card">Ainda não há ocorrências para exibir <?= $isGeoFilterActive ? 'nesta região com o raio selecionado' : '' ?>.</div>
            <?php endif; ?>

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
                <article class="card">
                    <?php if (!empty($photos)): ?>
                        <div style="display:flex;gap:8px;margin-bottom:14px;overflow-x:auto">
                            <?php foreach ($photos as $photo): ?>
                                <a href="../<?= h($photo['image_path'] ?? $photo['thumbnail_path']) ?>" target="_blank" style="flex:1;max-width:<?= count($photos) === 1 ? '100%' : '33%' ?>;display:block">
                                    <img class="thumb" src="../<?= h($photo['thumbnail_path']) ?>"
                                        loading="lazy" alt="Foto da ocorrência" style="width:100%;height:<?= count($photos) === 1 ? '220px' : '130px' ?>;object-fit:cover;border-radius:8px">
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <h3 class="alert-title">
                        <span class="alert-code"><?= formatAlertCode((int)$alert['id']) ?></span>
                        <?= h($alert['title']) ?>
                    </h3>

                    <?php
                    $prog = obterDadosProgressoStatus((string)$alert['status']);
                    $lastStatusInfo = $latestStatusByAlert[(int)$alert['id']] ?? null;
                    ?>
                    <div class="meta-bar">
                        <span class="badge badge-category"><?= h($alert['category']) ?></span>
                        <span class="badge" style="background:<?= h($prog['bg']) ?>;color:<?= h($prog['cor']) ?>;font-weight:700">
                            Status: <?= h($alert['status']) ?>
                        </span>
                        <?php if ($alert['distance_km'] !== null): ?>
                            <span class="badge" style="background:#e3f5ec;color:#0d6948;font-weight:700">📍 <?= formatarDistancia($alert['distance_km']) ?></span>
                        <?php endif; ?>
                        <span class="alert-date">📅 <?= h(formatDateTime($alert['created_at'])) ?></span>
                    </div>

                    <p class="alert-desc"><?= h(mb_strimwidth($alert['description'], 0, 220, '…', 'UTF-8')) ?></p>
                    <p class="alert-address">📍 <?= h($alert['address']) ?></p>

                    <div class="status-progress-box" aria-label="Progresso do status da ocorrência">
                        <div class="status-progress-header">
                            <span style="font-weight:700;color:var(--text-main)">
                                Andamento da ocorrência: <span style="color:<?= h($prog['cor']) ?>"><?= h($alert['status']) ?></span>
                            </span>
                            <span style="font-weight:700;color:<?= h($prog['cor']) ?>">
                                <?= (int)$prog['percentual'] ?>% (Etapa <?= (int)$prog['etapa_numero'] ?> de <?= (int)$prog['total_etapas'] ?>)
                            </span>
                        </div>
                        <div class="status-progress-track">
                            <div class="status-progress-fill" style="width:<?= (int)$prog['percentual'] ?>%;background:<?= h($prog['cor']) ?>"></div>
                        </div>
                        <div class="status-steps">
                            <?php foreach ($prog['etapas'] as $idx => $nomeEtapa): ?>
                                <?php
                                $stepClass = '';
                                $prefix = '';
                                if ($idx < $prog['indice_atual']) {
                                    $stepClass = 'done';
                                    $prefix = '✓ ';
                                } elseif ($idx === $prog['indice_atual']) {
                                    $stepClass = 'current';
                                    $prefix = ($idx === $prog['total_etapas'] - 1) ? '✓ ' : '● ';
                                }
                                ?>
                                <div class="status-step <?= $stepClass ?>" title="<?= h($nomeEtapa) ?>">
                                    <?= h($prefix . $nomeEtapa) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($lastStatusInfo && !empty($lastStatusInfo['created_at'])): ?>
                            <div style="font-size:11px;color:var(--text-muted);margin-top:6px;text-align:right">
                                Última atualização de status: <?= h(formatDateTime((string)$lastStatusInfo['created_at'])) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="card-footer">
                        <span class="support-count">🤝 <?= (int)$alert['support_count'] ?> <?= (int)$alert['support_count'] === 1 ? 'apoio' : 'apoios' ?></span>

                        <form method="post" style="margin:0">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="support">
                            <input type="hidden" name="alert_id" value="<?= (int)$alert['id'] ?>">
                            <button type="submit" class="btn btn-secondary" style="padding:6px 14px">Apoiar ocorrência</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>

            <script>
                const btnNearMe = document.getElementById('btn-near-me');
                const nearMeStatus = document.getElementById('near-me-status');
                const btnNearAddress = document.getElementById('btn-search-near-address');
                const btnClearNearAddress = document.getElementById('btn-clear-near-address');
                const addressQuery = document.getElementById('address-query');
                const nearAddressStatus = document.getElementById('near-address-status');
                const isMine = <?= $mine ? 'true' : 'false' ?>;

                if (btnClearNearAddress && addressQuery) {
                    btnClearNearAddress.addEventListener('click', () => {
                        addressQuery.value = '';
                        if (nearAddressStatus) nearAddressStatus.textContent = '';
                        addressQuery.focus();
                    });
                }

                if (btnNearMe) {
                    btnNearMe.addEventListener('click', () => {
                        if (!navigator.geolocation) {
                            if (nearMeStatus) {
                                nearMeStatus.style.color = '#9b2215';
                                nearMeStatus.textContent = 'Geolocalização não é suportada pelo seu navegador.';
                            }
                            return;
                        }

                        btnNearMe.disabled = true;
                        if (nearMeStatus) {
                            nearMeStatus.style.color = 'var(--text-muted)';
                            nearMeStatus.textContent = 'Solicitando localização ao dispositivo...';
                        }

                        const geoOptions = {
                            enableHighAccuracy: false,
                            timeout: 8000,
                            maximumAge: 60000
                        };

                        async function fallbackRede(msgErro) {
                            if (nearMeStatus) {
                                nearMeStatus.style.color = '#5a736a';
                                nearMeStatus.textContent = 'GPS indisponível. Tentando localização pela rede...';
                            }
                            try {
                                const res = await fetch('https://ipapi.co/json/');
                                const data = await res.json();
                                if (data && data.latitude && data.longitude) {
                                    const lat = data.latitude;
                                    const lng = data.longitude;
                                    const raio = 100;
                                    const localNome = data.city ? data.city + ' (aproximado)' : 'Minha região';
                                    const base = isMine ? 'index.php?mine=1&' : 'index.php?';
                                    window.location.href = base + 'lat=' + lat + '&lng=' + lng + '&raio=' + raio + '&ref=' + encodeURIComponent(localNome);
                                    return;
                                }
                            } catch (_) {}

                            btnNearMe.disabled = false;
                            if (nearMeStatus) {
                                nearMeStatus.style.color = '#9b2215';
                                nearMeStatus.textContent = msgErro || 'Não foi possível obter localização. Digite um endereço ao lado.';
                            }
                        }

                        navigator.geolocation.getCurrentPosition(
                            async position => {
                                    const lat = position.coords.latitude;
                                    const lng = position.coords.longitude;
                                    const raio = 100;
                                    const base = isMine ? 'index.php?mine=1&' : 'index.php?';

                                    if (nearMeStatus) {
                                        nearMeStatus.style.color = '#126b53';
                                        nearMeStatus.textContent = 'GPS obtido! Identificando endereço...';
                                    }

                                    let localNome = 'Minha localização atual';
                                    try {
                                        const res = await fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`);
                                        const data = await res.json();
                                        if (data) {
                                            let formatted = data.display_name;
                                            if (data.address) {
                                                const parts = [];
                                                const rua = data.address.road || data.address.pedestrian || data.address.street;
                                                if (rua) parts.push(rua + (data.address.house_number ? ', ' + data.address.house_number : ''));
                                                const bairro = data.address.suburb || data.address.neighbourhood;
                                                if (bairro) parts.push(bairro);
                                                const cidade = data.address.city || data.address.town || data.address.municipality;
                                                if (cidade) parts.push(cidade);
                                                if (parts.length > 0) formatted = parts.join(' - ');
                                            }
                                            localNome = formatted;
                                        }
                                    } catch (_) {}

                                    if (addressQuery) addressQuery.value = localNome;
                                    window.location.href = base + 'lat=' + lat + '&lng=' + lng + '&raio=' + raio + '&ref=' + encodeURIComponent(localNome);
                                },
                                err => {
                                    let detalhe = '';
                                    if (err.code === 1) {
                                        detalhe = 'Permissão de localização negada no navegador. Permita o acesso ao lado da barra de endereço.';
                                        btnNearMe.disabled = false;
                                        if (nearMeStatus) {
                                            nearMeStatus.style.color = '#9b2215';
                                            nearMeStatus.textContent = detalhe;
                                        }
                                    } else if (err.code === 2) {
                                        fallbackRede('Sinal de GPS indisponível no dispositivo.');
                                    } else if (err.code === 3) {
                                        fallbackRede('Tempo de resposta do GPS esgotado.');
                                    } else {
                                        fallbackRede('Erro ao obter GPS.');
                                    }
                                },
                                geoOptions
                        );
                    });
                }

                if (btnNearAddress && addressQuery) {
                    async function buscarPorEnderecoProximo() {
                        const query = addressQuery.value.trim();
                        if (!query) {
                            if (nearAddressStatus) {
                                nearAddressStatus.style.color = '#9b2215';
                                nearAddressStatus.textContent = 'Digite um endereço antes de buscar.';
                            }
                            addressQuery.focus();
                            return;
                        }

                        btnNearAddress.disabled = true;
                        if (nearAddressStatus) {
                            nearAddressStatus.style.color = 'var(--text-muted)';
                            nearAddressStatus.textContent = 'Localizando endereço...';
                        }

                        try {
                            const url = `https://nominatim.openstreetmap.org/search?format=json&countrycodes=br&limit=1&q=${encodeURIComponent(query)}`;
                            const res = await fetch(url);
                            const data = await res.json();

                            if (data && data.length > 0) {
                                const lat = parseFloat(data[0].lat);
                                const lng = parseFloat(data[0].lon);
                                const raio = 100;
                                const base = isMine ? 'index.php?mine=1&' : 'index.php?';
                                window.location.href = base + 'lat=' + lat + '&lng=' + lng + '&raio=' + raio + '&ref=' + encodeURIComponent(query);
                            } else {
                                if (nearAddressStatus) {
                                    nearAddressStatus.style.color = '#9b2215';
                                    nearAddressStatus.textContent = 'Endereço não localizado. Tente especificar bairro ou cidade.';
                                }
                            }
                        } catch (_) {
                            if (nearAddressStatus) {
                                nearAddressStatus.style.color = '#9b2215';
                                nearAddressStatus.textContent = 'Erro ao buscar endereço.';
                            }
                        } finally {
                            btnNearAddress.disabled = false;
                        }
                    }

                    btnNearAddress.addEventListener('click', buscarPorEnderecoProximo);
                    addressQuery.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            buscarPorEnderecoProximo();
                        }
                    });
                }
            </script>

        <?php endif; ?>
    </main>
</body>

</html>