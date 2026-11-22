## Executar localmente

É necessário PHP 8 ou superior com as extensões `pdo_sqlite`, `gd`, `mbstring` e `fileinfo`. Os comandos abaixo partem desta pasta (`MVP-20261120-ATUALIZADO`).

Cidadão e gestor usam o mesmo servidor:

- http://localhost:8080/cidadao/
- http://localhost:8080/gestor/

Para o painel do gestor, entre com o usuário `admin`. A senha fica só no ambiente local e não é publicada no repositório.

Cadastrar outro gestor exige o token. Pare o servidor e inicie de novo com ele:

```bash
ALERTACIDADE_SETUP_TOKEN="coloque-um-token-longo-aqui" php -S localhost:8080
```

No PowerShell: `$env:ALERTACIDADE_SETUP_TOKEN="coloque-um-token-longo-aqui"` antes de `php -S localhost:8080`. No formulário "Cadastrar gestor", digite o mesmo token.

### Linux (Debian, Ubuntu e derivados)

```bash
sudo apt update
sudo apt install php-cli php-sqlite3 php-gd php-mbstring
php -S localhost:8080
```

### macOS

```bash
brew install php
php -S localhost:8080
```

O PHP instalado pelo Homebrew já traz SQLite, GD, mbstring e fileinfo, inclusive em Macs com Apple Silicon.

### Windows (PowerShell)

```powershell
winget install PHP.PHP.8.3
php -S localhost:8080
```

Se `php -m` não listar `pdo_sqlite`, `gd`, `mbstring` ou `fileinfo`, habilite essas linhas no `php.ini` indicado por `php --ini`.

Pare o servidor com Ctrl+C.

**Observação:** o servidor embutido do PHP serve para desenvolvimento local, não para publicar o sistema. Antes de disponibilizar o MVP na internet, use HTTPS e valide os uploads também no ambiente de produção.
