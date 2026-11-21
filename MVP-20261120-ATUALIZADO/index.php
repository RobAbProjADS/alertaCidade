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
    <title>🚨 AlertaCidade — Bem-vindo</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🚨</text></svg>">
    <style>
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        background: #f2f6f4;
        color: #18312a;
        margin: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 90vh;
        padding: 20px;
    }
    .container {
        background: #ffffff;
        border-radius: 16px;
        padding: 36px;
        max-width: 520px;
        width: 100%;
        box-shadow: 0 4px 20px rgba(0,0,0,0.06);
        border: 1px solid #dbe4e0;
        text-align: center;
    }
    h1 {
        font-size: 26px;
        font-weight: 800;
        color: #126b53;
        margin: 0 0 8px 0;
        letter-spacing: -0.5px;
    }
    .subtitle {
        font-size: 15px;
        color: #5a736a;
        margin: 0 0 28px 0;
    }
    .choices {
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .btn-card {
        display: block;
        padding: 18px 20px;
        border-radius: 12px;
        text-decoration: none;
        transition: transform 0.15s, box-shadow 0.15s;
        text-align: left;
    }
    .btn-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .btn-citizen {
        background: #126b53;
        color: #ffffff;
    }
    .btn-manager {
        background: #f4f8f6;
        color: #18312a;
        border: 1px solid #cce0d8;
    }
    .btn-title {
        display: block;
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .btn-desc {
        display: block;
        font-size: 13px;
        opacity: 0.85;
    }
    </style>
</head>
<body>
    <div class="container">
        <h1>AlertaCidade</h1>
        <p class="subtitle">Plataforma comunitária de zeladoria e ocorrências urbanas</p>

        <div class="choices">
            <a href="/cidadao/" class="btn-card btn-citizen">
                <span class="btn-title">Área do Cidadão</span>
                <span class="btn-desc">Visualizar feed, consultar chamados e registrar novas ocorrências</span>
            </a>
            <a href="/gestor/" class="btn-card btn-manager">
                <span class="btn-title">Painel de Gestão</span>
                <span class="btn-desc">Acesso restrito para triagem, atualização de status e relatórios</span>
            </a>
        </div>
    </div>
</body>
</html>
