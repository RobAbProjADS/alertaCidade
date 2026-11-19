<?php
declare(strict_types=1);

require dirname(__DIR__) . '/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));

if ($title === '' && $description === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Informe o título ou a descrição']);
    exit;
}

if (mb_strlen($title, 'UTF-8') > 200 || mb_strlen($description, 'UTF-8') > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Texto muito longo']);
    exit;
}

echo json_encode(
    ['category' => sugerirCategoria($description, $title)],
    JSON_UNESCAPED_UNICODE
);
