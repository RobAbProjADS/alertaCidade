<?php
declare(strict_types=1);

session_start();

$root = __DIR__;

foreach ([
    $root . '/data',
    $root . '/storage/originals',
    $root . '/storage/thumbnails',
] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

// Identificador persistente deste navegador.
if (
    empty($_COOKIE['citizen_id']) ||
    !preg_match('/^[a-f0-9]{64}$/', $_COOKIE['citizen_id'])
) {
    $citizenId = bin2hex(random_bytes(32));

    setcookie('citizen_id', $citizenId, [
        'expires' => time() + (365 * 24 * 60 * 60),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Disponibiliza o valor nesta mesma requisição.
    $_COOKIE['citizen_id'] = $citizenId;
}

$citizenId = $_COOKIE['citizen_id'];

$pdo = new PDO('sqlite:' . $root . '/data/alertacidade.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS alerts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL,
        description TEXT NOT NULL,
        category TEXT NOT NULL,
        address TEXT NOT NULL,
        latitude REAL,
        longitude REAL,
        thumbnail_path TEXT,
        image_path TEXT,
        author TEXT NOT NULL DEFAULT 'Cidadão',
        owner_id TEXT,
        status TEXT NOT NULL DEFAULT 'Recebido',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$existingColumns = $pdo->query('PRAGMA table_info(alerts)')
    ->fetchAll(PDO::FETCH_COLUMN, 1);

$migrations = [
    'thumbnail_path' => 'ALTER TABLE alerts ADD COLUMN thumbnail_path TEXT',
    'image_path'     => 'ALTER TABLE alerts ADD COLUMN image_path TEXT',
    'owner_id'       => 'ALTER TABLE alerts ADD COLUMN owner_id TEXT',
    'latitude'       => 'ALTER TABLE alerts ADD COLUMN latitude REAL',
    'longitude'      => 'ALTER TABLE alerts ADD COLUMN longitude REAL',
];

foreach ($migrations as $column => $sql) {
    if (!in_array($column, $existingColumns, true)) {
        $pdo->exec($sql);
    }
}

$pdo->exec("
    CREATE TABLE IF NOT EXISTS supports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        alert_id INTEGER NOT NULL,
        supporter TEXT NOT NULL,
        UNIQUE(alert_id, supporter),
        FOREIGN KEY(alert_id) REFERENCES alerts(id) ON DELETE CASCADE
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS status_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        alert_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        changed_by TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(alert_id) REFERENCES alerts(id) ON DELETE CASCADE
    )
");

// Atualiza bancos SQLite que já existiam antes da coluna owner_id.
$columns = $pdo->query('PRAGMA table_info(alerts)')
    ->fetchAll(PDO::FETCH_COLUMN, 1);

if (!in_array('owner_id', $columns, true)) {
    $pdo->exec('ALTER TABLE alerts ADD COLUMN owner_id TEXT');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' .
        h($_SESSION['csrf']) . '">';
}

function checkCsrf(): void
{
    $token = $_POST['csrf'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(400);
        exit('Solicitação inválida.');
    }
}

function makeThumbnail(string $source, string $destination, int $maxSize = 480): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }
    
    $info = getimagesize($source);
    if (!$info) {
        return false;
    }

    [$width, $height, $type] = $info;

    $image = match ($type) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($source),
        IMAGETYPE_PNG => imagecreatefrompng($source),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp')
            ? imagecreatefromwebp($source)
            : false,
        default => false,
    };

    if (!$image) {
        return false;
    }

    $scale = min(1, $maxSize / max($width, $height));
    $newWidth = max(1, (int)($width * $scale));
    $newHeight = max(1, (int)($height * $scale));

    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled(
        $thumb, $image,
        0, 0, 0, 0,
        $newWidth, $newHeight,
        $width, $height
    );

    $result = imagejpeg($thumb, $destination, 72);
    imagedestroy($image);
    imagedestroy($thumb);

    return $result;
}

function sugerirCategoria(string $texto): ?string
{
    $texto = mb_strtolower($texto, 'UTF-8');

    $regras = [
        'Iluminação pública' => [
            'poste', 'lâmpada', 'lampada', 'luz apagada',
            'iluminação', 'iluminacao', 'escuro',
        ],
        'Buraco em via' => [
            'buraco', 'asfalto', 'cratera', 'pavimento',
        ],
        'Descarte irregular de resíduos' => [
            'lixo', 'entulho', 'resíduo', 'residuo',
            'descarte', 'lixeira',
        ],
        'Calçada' => [
            'calçada', 'calcada', 'rampa', 'passeio',
        ],
        'Sinalização' => [
            'placa', 'semáforo', 'semaforo', 'sinalização',
            'sinalizacao', 'faixa de pedestre',
        ],
    ];

    foreach ($regras as $categoria => $palavras) {
        foreach ($palavras as $palavra) {
            if (str_contains($texto, $palavra)) {
                return $categoria;
            }
        }
    }

    return null;
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

