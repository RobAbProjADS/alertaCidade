## Executar localmente

É necessário ter PHP com as extensões `pdo_sqlite`, `fileinfo`, `php-gd` e `mbstring`. No terminal, dentro da pasta do projeto:

```bash
ALERTACIDADE_ADMIN_PASSWORD='troque-esta-senha' php -S localhost:8000
```

No Windows PowerShell:

```powershell
$env:ALERTACIDADE_ADMIN_PASSWORD="troque-esta-senha"
php -S localhost:8000
```

Acesse `http://localhost:8000`. Para testar a área administrativa, abra os detalhes de um alerta e informe a senha configurada.

**Observação:** o servidor embutido do PHP serve para desenvolvimento local, não para publicar o sistema. Antes de disponibilizar o MVP na internet, é importante adicionar autenticação real para administradores, limitar tentativas de apoio, validar os uploads também no servidor de produção e configurar HTTPS.
