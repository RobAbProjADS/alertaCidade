#ALERTACIDADE

> **Resumo:** Plataforma de Participação Cidadã e Acompanhamento de Ocorrências Urbanas
> 
## Sobre o projeto

O AlertaCidade propõe uma plataforma digital de participação cidadã que permite registrar, visualizar, apoiar e acompanhar ocorrências urbanas, utilizando recursos de geolocalização e imagens. 

## Linguagens e tecnologias

- **Linguagens:** PHP,HTML
- **Banco de dados:** SQLite
- **Frameworks e ferramentas:** VSCODE

## Pré-requisitos

- PHP 8 ou superior
- Extensões PHP: `pdo_sqlite`, `gd`, `mbstring` e `fileinfo`
- Git

O banco é um arquivo SQLite criado na primeira execução. Não há Composer, npm nem servidor de banco para instalar. O `composer.json` na raiz só registra que o projeto pede PHP 8 ou superior.

Cidadão e gestor usam o mesmo servidor. As duas áreas são caminhos diferentes:

- Cidadão: http://localhost:8080/cidadao/
- Gestor: http://localhost:8080/gestor/

O servidor precisa ser iniciado **dentro** de `MVP-20261120-ATUALIZADO` ou de `MVP`. A raiz do repositório não tem `index.php`.

## Instalação do PHP

### Linux (Debian, Ubuntu e derivados)

```bash
sudo apt update
sudo apt install php-cli php-sqlite3 php-gd php-mbstring
php -v
php -m | grep -E 'pdo_sqlite|gd|mbstring|fileinfo'
```

### macOS

Com o [Homebrew](https://brew.sh/). No Apple Silicon o pacote já é nativo (`arm64`).

```bash
brew install php
php -v
php -m | grep -E 'pdo_sqlite|gd|mbstring|fileinfo'
```

O PHP do Homebrew já inclui SQLite, GD, mbstring e fileinfo.

### Windows

Instale o PHP 8 e marque as extensões SQLite, GD, mbstring e fileinfo. Pelo [winget](https://learn.microsoft.com/windows/package-manager/winget/):

```powershell
winget install PHP.PHP.8.3
php -v
php -m
```

Se alguma extensão não aparecer, abra o `php.ini` (o caminho sai em `php --ini`) e retire o `;` do início destas linhas:

```ini
extension=gd
extension=mbstring
extension=fileinfo
extension=pdo_sqlite
extension=sqlite3
```

Feche e abra o terminal depois de mudar o `PATH`.

## Executar

Clone o repositório e entre na pasta da aplicação:

```bash
git clone https://github.com/RobAbProjADS/alertaCidade.git
cd alertaCidade/MVP-20261120-ATUALIZADO
```

No Windows, o mesmo caminho no PowerShell ou no Prompt de Comando:

```powershell
cd alertaCidade\MVP-20261120-ATUALIZADO
```

Suba o servidor embutido do PHP. O comando é o mesmo nos três sistemas:

```bash
php -S localhost:8080
```

Para cadastrar outro gestor, o servidor precisa conhecer o token. Pare o processo e suba de novo:

```bash
ALERTACIDADE_SETUP_TOKEN="coloque-um-token-longo-aqui" php -S localhost:8080
```

No PowerShell:

```powershell
$env:ALERTACIDADE_SETUP_TOKEN="coloque-um-token-longo-aqui"
php -S localhost:8080
```

Entre como gestor, abra "Cadastrar gestor" e digite esse mesmo token no formulário. Sem o token, o cadastro não é gravado.

Abra no navegador:

- http://localhost:8080 — escolha cidadão ou gestor
- http://localhost:8080/cidadao/ — feed, cadastro e novas ocorrências
- http://localhost:8080/gestor/ — painel de gestão

Para entrar como gestor use o usuário `admin`. A senha dessa conta fica só no ambiente local e não é publicada no repositório. Ela é criada no banco na primeira visita. O cidadão se cadastra em http://localhost:8080/cidadao/ com uma senha de pelo menos 8 caracteres.

Pare o servidor com Ctrl+C.

O servidor embutido serve só para desenvolvimento. Antes de publicar, use HTTPS, autenticação adequada e validação de upload no ambiente de produção.

## Colaboradores

| Nome | GitHub | Responsabilidade |
|---|---|---|
| Roberta Cristina de Abreu | [@main e @RobAb](https://github.com/RobAbProjADS) | iniciar projeto em PHP, README e auxiliar no repositório
| [Nome do colaborador] | [@usuário](https://github.com/usuário) 

## Casos de uso (Use Case)

Descreva os atores e as ações que cada um pode realizar no sistema. 

```mermaid
flowchart LR
    visitante[Visitante] --> visualizar[Visualizar informações]
    usuario[Usuário] --> cadastrar[Cadastrar informação]
    usuario --> consultar[Consultar informações]
    administrador[Administrador] --> gerenciar[Gerenciar informações]
```

## UML

Inclua os diagramas UML relevantes para documentar a estrutura e o comportamento do sistema. O exemplo abaixo é um diagrama de classes em Mermaid; adapte as classes, atributos e relacionamentos ao projeto.

```mermaid
classDiagram
    class citizens {
        +id
        +name
        +email
        +cpf
        +phone
        +password
        +CREATE
        
    }

    class alerts {
        +id
        +title
        +description
        +category
        +adress
        +latitude Real     
        +longitude REAL
        +image
        +author
        +RECEBIDO
 
    }

    
```

## Como contribuir

1. Crie uma branch para sua alteração.
2. Faça as alterações e os testes necessários.
3. Abra um pull request descrevendo o que foi feito.





