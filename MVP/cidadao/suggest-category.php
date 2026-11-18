<?php
declare(strict_types=1);

require dirname(__DIR__) . '/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$description = trim((string)($_POST['description'] ?? ''));

if ($description === '' || mb_strlen($description, 'UTF-8') > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Descrição inválida']);
    exit;
}

echo json_encode(
    ['category' => sugerirCategoria($description)],
    JSON_UNESCAPED_UNICODE
);
