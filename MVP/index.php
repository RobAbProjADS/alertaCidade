<?php
$userAgent = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');

$isMobile = preg_match(
    '/android|iphone|ipod|mobile|windows phone|blackberry|opera mini/i',
    $userAgent
);

if ($isMobile) {
    header('Location: /cidadao/');
    exit;
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AlertaCidade</title>
</head>
<body>
    <h1>AlertaCidade</h1>
    <p>Escolha como deseja acessar:</p>
    <a href="/cidadao/">Área do cidadão</a>
    <a href="/gestor/">Painel do gestor</a>
</body>
</html>
