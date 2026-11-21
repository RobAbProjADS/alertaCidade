<?php

declare(strict_types=1);

session_start();

$root = __DIR__;

foreach (
    [
        $root . '/data',
        $root . '/storage/originals',
        $root . '/storage/thumbnails',
    ] as $dir
) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

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

$pdo->exec("
    CREATE TABLE IF NOT EXISTS alert_photos (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        alert_id INTEGER NOT NULL,
        image_path TEXT NOT NULL,
        thumbnail_path TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(alert_id) REFERENCES alerts(id) ON DELETE CASCADE
    )
");

$pdo->exec("
    INSERT INTO alert_photos (alert_id, image_path, thumbnail_path)
    SELECT id, image_path, thumbnail_path
    FROM alerts
    WHERE thumbnail_path IS NOT NULL AND thumbnail_path != ''
      AND id NOT IN (SELECT DISTINCT alert_id FROM alert_photos)
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS citizens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )
");

$pdo->exec("
    CREATE TABLE IF NOT EXISTS managers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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

function formatDateTime(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    try {
        $date = new DateTime($datetime, new DateTimeZone('UTC'));
        $date->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        return $date->format('d/m/Y \à\s H:i');
    } catch (Exception) {
        return $datetime;
    }
}

function formatAlertCode(int $id): string
{
    return sprintf('#%04d', $id);
}

function calcularDistanciaKm(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $raioTerraKm = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
        cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
        sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $raioTerraKm * $c;
}

function formatarDistancia(float $km): string
{
    if ($km < 1) {
        $metros = (int)round($km * 1000);
        return $metros . ' m de você';
    }
    return number_format($km, 1, ',', '.') . ' km de você';
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
        $thumb,
        $image,
        0,
        0,
        0,
        0,
        $newWidth,
        $newHeight,
        $width,
        $height
    );

    $result = imagejpeg($thumb, $destination, 72);
    imagedestroy($image);
    imagedestroy($thumb);

    return $result;
}

function normalizarTexto(string $str): string
{
    $str = mb_strtolower($str, 'UTF-8');
    $map = [
        'á' => 'a',
        'à' => 'a',
        'ã' => 'a',
        'â' => 'a',
        'ä' => 'a',
        'é' => 'e',
        'è' => 'e',
        'ê' => 'e',
        'ë' => 'e',
        'í' => 'i',
        'ì' => 'i',
        'î' => 'i',
        'ï' => 'i',
        'ó' => 'o',
        'ò' => 'o',
        'õ' => 'o',
        'ô' => 'o',
        'ö' => 'o',
        'ú' => 'u',
        'ù' => 'u',
        'û' => 'u',
        'ü' => 'u',
        'ç' => 'c',
        'ñ' => 'n',
    ];
    $str = strtr($str, $map);
    $str = preg_replace('/[^\w\s]/u', ' ', $str) ?? $str;
    return preg_replace('/\s+/', ' ', trim($str)) ?? $str;
}

function sugerirCategoria(string $texto, ?string $titulo = null): ?string
{
    $textoNorm = normalizarTexto($texto);
    $tituloNorm = $titulo ? normalizarTexto($titulo) : '';

    if ($textoNorm === '' && $tituloNorm === '') {
        return null;
    }

    $regras = [
        'Iluminação pública' => [
            'termos_fortes' => [
                'luz apagada',
                'poste caido',
                'poste quebrado',
                'poste sem luz',
                'lampada queimada',
                'lampada apagada',
                'braco de luz',
                'iluminacao publica',
                'sem iluminacao',
                'escuridao total',
                'fiacao solta',
                'fiacao partida',
            ],
            'termos' => [
                'poste',
                'lampada',
                'iluminacao',
                'escuro',
                'escuridao',
                'luminaria',
                'reator',
                'apagada',
                'fiacao',
                'fio caido',
                'blackout',
            ],
        ],
        'Buraco em via' => [
            'termos_fortes' => [
                'buraco na via',
                'buraco na rua',
                'buraco na pista',
                'cratera na pista',
                'tapa buraco',
                'asfalto afundando',
                'via esburacada',
                'rua esburacada',
                'bueiro sem tampa',
                'boca de lobo quebrada',
                'lombada quebrada',
            ],
            'termos' => [
                'buraco',
                'asfalto',
                'cratera',
                'pavimento',
                'pavimentacao',
                'remendo',
                'desnivel',
                'afundamento',
                'valeta',
                'depressao',
                'lombada',
                'bueiro',
                'boca de lobo',
                'paralelepipedo',
            ],
        ],
        'Descarte irregular de resíduos' => [
            'termos_fortes' => [
                'lixo acumulado',
                'descarte irregular',
                'entulho na calcada',
                'entulho na rua',
                'resto de obra',
                'resto de poda',
                'sofa velho',
                'lixao a ceu aberto',
                'cacamba irregular',
            ],
            'termos' => [
                'lixo',
                'entulho',
                'residuo',
                'descarte',
                'lixeira',
                'sujeira',
                'cacamba',
                'moveis',
                'sofa',
                'carcaca',
                'bota fora',
            ],
        ],
        'Calçada' => [
            'termos_fortes' => [
                'calcada quebrada',
                'calcada intransitavel',
                'rampa de acessibilidade',
                'piso tatil',
                'pedestre sem passagem',
                'meio fio quebrado',
            ],
            'termos' => [
                'calcada',
                'calcadao',
                'passeio',
                'rampa',
                'acessibilidade',
                'guia',
                'meio fio',
                'sarjeta',
                'lajota',
                'pedra portuguesa',
            ],
        ],
        'Sinalização' => [
            'termos_fortes' => [
                'faixa de pedestre',
                'semaforo quebrado',
                'semaforo apagado',
                'sinal apagado',
                'placa caida',
                'placa de pare',
                'pintura de faixa',
                'placa quebrada',
                'placa pichada',
                'sinalizacao apagada',
            ],
            'termos' => [
                'placa',
                'semaforo',
                'sinalizacao',
                'sinal',
                'faixa',
                'radar',
                'cone',
                'tartaruga',
                'prisma',
                'pare',
            ],
        ],
        'Árvores e áreas verdes' => [
            'termos_fortes' => [
                'arvore caida',
                'galho quebrado',
                'galho caido',
                'poda de arvore',
                'arvore em risco',
                'mato alto',
                'praca abandonada',
            ],
            'termos' => [
                'arvore',
                'galho',
                'tronco',
                'poda',
                'raiz',
                'mato',
                'matagal',
                'capina',
                'folhagem',
                'canteiro',
                'praca',
            ],
        ],
        'Água e esgoto' => [
            'termos_fortes' => [
                'vazamento de agua',
                'vazamento de esgoto',
                'cano estourado',
                'agua vazando',
                'esgoto a ceu aberto',
                'esgoto correndo',
                'bueiro entupido',
                'agua servida',
            ],
            'termos' => [
                'vazamento',
                'esgoto',
                'cano',
                'tubulacao',
                'infiltracao',
                'sabesp',
                'alegamento',
                'mau cheiro',
                'bueiro',
                'poca',
            ],
        ],
        'Zoonoses e animais' => [
            'termos_fortes' => [
                'maus tratos',
                'animal abandonado',
                'animal ferido',
                'foco de dengue',
                'agua parada',
                'infestacao de insetos',
            ],
            'termos' => [
                'animal',
                'animais',
                'cachorro',
                'cao',
                'gato',
                'cavalo',
                'zoonose',
                'dengue',
                'escorpiao',
                'carrapato',
                'morcego',
                'rato',
            ],
        ],
    ];

    $scores = [];

    foreach ($regras as $categoria => $grupos) {
        $score = 0;

        foreach ($grupos['termos_fortes'] as $termo) {
            if ($tituloNorm !== '' && str_contains($tituloNorm, $termo)) {
                $score += 6;
            }
            if ($textoNorm !== '' && str_contains($textoNorm, $termo)) {
                $score += 4;
            }
        }

        foreach ($grupos['termos'] as $termo) {
            if ($tituloNorm !== '' && preg_match('/\b' . preg_quote($termo, '/') . '\b/u', $tituloNorm)) {
                $score += 3;
            } elseif ($tituloNorm !== '' && str_contains($tituloNorm, $termo)) {
                $score += 2;
            }

            if ($textoNorm !== '' && preg_match('/\b' . preg_quote($termo, '/') . '\b/u', $textoNorm)) {
                $score += 2;
            } elseif ($textoNorm !== '' && str_contains($textoNorm, $termo)) {
                $score += 1;
            }
        }

        if ($score > 0) {
            $scores[$categoria] = $score;
        }
    }

    if (empty($scores)) {
        return null;
    }

    arsort($scores);
    return array_key_first($scores);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}
