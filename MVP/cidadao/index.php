<?php
declare(strict_types=1);

session_start();

require dirname(__DIR__) . '/database.php';

if (empty($_SESSION['citizen'])) {
    $_SESSION['citizen'] = bin2hex(random_bytes(16));
}

$citizenId = $_SESSION['citizen'];

$categories = [
    'Iluminação pública',
    'Buraco em via',
    'Descarte irregular de resíduos',
    'Calçada',
    'Sinalização',
    'Outro',
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
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
                  ($lng !== '' && !is_numeric($lng))) {
            $error = 'Coordenadas inválidas.';
        } else {
            $imagePath = null;
            $thumbPath = null;

            if (!empty($_FILES['photo']['name'])) {
                $file = $_FILES['photo'];

                if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
                    $error = 'A foto deve ter até 5 MB.';
                } else {
                    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                    $extensions = [
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                    ];

                    if (!isset($extensions[$mime])) {
                        $error = 'Use uma imagem JPG, PNG ou WebP.';
                    } else {
                        $name = bin2hex(random_bytes(16));
                        $ext = $extensions[$mime];

                        $originalFile = dirname(__DIR__) . "/storage/originals/$name.$ext";
                        $thumbFile = dirname(__DIR__) . "/storage/thumbnails/$name.jpg";

                        if (move_uploaded_file($file['tmp_name'], $originalFile)
                            && makeThumbnail($originalFile, $thumbFile)) {
                            $imagePath = "storage/originals/$name.$ext";
                            $thumbPath = "storage/thumbnails/$name.jpg";
                        } else {
                            $error = 'Não foi possível processar a foto.';
                        }
                    }
                }
            }

            if ($error === '') {
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
                    $thumbPath,
                    $imagePath,
                    'Cidadão',
                    $citizenId,
                ]);

                $id = (int)$pdo->lastInsertId();
                $pdo->prepare("
                    INSERT INTO status_history (alert_id, status, changed_by)
                    VALUES (?, 'Recebido', ?)
                ")->execute([$id, $_SESSION['citizen']]);

                redirect('index.php?created=1');
            }
        }
    }

  if ($action === 'support') {
    $id = filter_input(INPUT_POST, 'alert_id', FILTER_VALIDATE_INT);

    if ($id) {
        $stmt = $pdo->prepare(
            'INSERT OR IGNORE INTO supports (alert_id, supporter) VALUES (?, ?)'
        );
        $stmt->execute([$id, $_SESSION['citizen']]);
    }

    redirect('index.php');
}
}

$mine = isset($_GET['mine']);

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
    $params[] = $citizenId;
}

$sql .= ' ORDER BY a.created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$showCreate = isset($_GET['novo']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AlertaCidade — Cidadão</title>
<style>
body{font:16px Arial;margin:0;background:#f2f6f4;color:#18312a}
header{background:#126b53;color:white;padding:18px}
main{max-width:850px;margin:20px auto;padding:0 14px}
.card{background:white;border-radius:12px;padding:16px;margin:14px 0}
a{color:#126b53}
button{background:#126b53;color:white;border:0;border-radius:7px;padding:11px;cursor:pointer}
input,textarea,select{width:100%;padding:10px;margin:6px 0 12px;box-sizing:border-box}
.thumb{width:100%;max-height:220px;object-fit:cover;border-radius:8px}
nav a{color:white;margin-right:15px}
#map{height:280px;margin:10px 0;border-radius:8px}
.error{color:#9b2215}
</style>
<?php if ($showCreate): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<?php endif; ?>
</head>
<body>
<header>
    <h1>AlertaCidade</h1>
    <nav>
        <a href="index.php">Feed</a>
        <a href="index.php?mine=1">Meus alertas</a>
        <a href="index.php?novo=1">Registrar ocorrência</a>
    </nav>
</header>

<main>
    <?php if (isset($_GET['created'])): ?>
        <div class="card">Alerta registrado com sucesso.</div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <p class="error"><?= h($error) ?></p>
    <?php endif; ?>

    <?php if ($showCreate): ?>
    <section class="card">
        <h2>Registrar ocorrência</h2>
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">

            <label>Título</label>
            <input name="title" maxlength="120" required>

            <label for="description">Descrição</label>
            <textarea id="description" name="description" rows="4" maxlength="2000" required></textarea>

            <button type="button" id="suggest-category">
                Sugerir categoria
            </button>

            <p id="suggestion-message" aria-live="polite"></p>

            <label for="category">Categoria</label>
            <select id="category" name="category" required>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= h($category) ?>">
                        <?= h($category) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Endereço ou referência</label>
            <input name="address" required placeholder="Rua, número ou ponto de referência">

            <label>Localização no mapa</label>
            <p>Toque no mapa para marcar o local ou use sua localização atual.</p>
            <div id="map"></div>
            <button type="button" id="locate">Usar minha localização</button>
            <input type="hidden" name="latitude" id="latitude">
            <input type="hidden" name="longitude" id="longitude">

            <label>Foto (máximo 5 MB)</label>
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">

            <button type="submit">Publicar alerta</button>
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
        document.getElementById('latitude').value = lat;
        document.getElementById('longitude').value = lng;
        if (marker) marker.setLatLng([lat, lng]);
        else marker = L.marker([lat, lng]).addTo(map);
    }

    map.on('click', e => setLocation(e.latlng.lat, e.latlng.lng));

    document.getElementById('locate').onclick = () => {
        navigator.geolocation.getCurrentPosition(position => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            setLocation(lat, lng);
            map.setView([lat, lng], 16);
        }, () => alert('Não foi possível obter sua localização.'));
    };
    </script>
    <script>
      const suggestButton = document.getElementById('suggest-category');

      if (suggestButton) {
          suggestButton.addEventListener('click', async () => {
              const description = document
                  .getElementById('description')
                  .value
                  .trim();

              const message = document.getElementById('suggestion-message');

              if (!description) {
                  message.textContent = 'Escreva uma descrição primeiro.';
                  return;
              }

              suggestButton.disabled = true;
              message.textContent = 'Analisando descrição...';

              try {
                  const formData = new FormData();
                  formData.append('description', description);

                  const response = await fetch('suggest-category.php', {
                      method: 'POST',
                      body: formData
                  });

                  const result = await response.json();

                  if (!response.ok) {
                      message.textContent = result.error || 'Não foi possível obter uma sugestão.';
                      return;
                  }

                  if (result.category) {
                      const categorySelect = document.getElementById('category');

                      // Só seleciona se a categoria existir no seletor.
                      const optionExists = Array.from(categorySelect.options)
                          .some(option => option.value === result.category);

                      if (optionExists) {
                          categorySelect.value = result.category;
                          message.textContent =
                              'Sugestão: ' + result.category +
                              '. Você pode alterá-la antes de publicar.';
                      } else {
                          message.textContent =
                              'Sugestão recebida, mas a categoria não existe no formulário.';
                      }
                  } else {
                      message.textContent =
                          'Não encontrei uma categoria provável. Escolha uma manualmente.';
                  }
              } catch (error) {
                  message.textContent =
                      'Erro ao obter a sugestão. Escolha uma categoria manualmente.';
              } finally {
                  suggestButton.disabled = false;
              }
          });
      }
    </script>
    <?php else: ?>

    <h2><?= $mine ? 'Meus alertas' : 'Ocorrências da comunidade' ?></h2>

    <?php if (!$alerts): ?>
        <div class="card">Ainda não há ocorrências para exibir.</div>
    <?php endif; ?>

    <?php foreach ($alerts as $alert): ?>
        <article class="card">
            <?php if ($alert['thumbnail_path']): ?>
                <img class="thumb" src="../<?= h($alert['thumbnail_path']) ?>"
                     loading="lazy" alt="Foto da ocorrência">
            <?php endif; ?>
            <h3><?= h($alert['title']) ?></h3>
            <p><strong><?= h($alert['category']) ?></strong> · <?= h($alert['status']) ?></p>
            <p><?= h(mb_strimwidth($alert['description'], 0, 180, '…', 'UTF-8')) ?></p>
            <p><?= h($alert['address']) ?></p>
            <p>Apoios: <?= (int)$alert['support_count'] ?></p>

            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="support">
                <input type="hidden" name="alert_id" value="<?= (int)$alert['id'] ?>">
                <button type="submit">Apoiar</button>
            </form>
        </article>
    <?php endforeach; ?>

    <?php endif; ?>
</main>
</body>
</html>

