#!/usr/bin/env bash
set -e

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

if ! command -v php >/dev/null 2>&1; then
    echo "[ERRO] O PHP não foi encontrado no sistema."
    echo "Instale com: sudo apt update && sudo apt install php-cli php-sqlite3 php-gd php-mbstring"
    exit 1
fi

export ALERTACIDADE_SETUP_TOKEN="${ALERTACIDADE_SETUP_TOKEN:-token123456}"

echo "========================================================"
echo "  Iniciando AlertaCidade em http://localhost:8080"
echo "  - Cidadão: http://localhost:8080/cidadao/"
echo "  - Gestor:  http://localhost:8080/gestor/"
echo "  - Token de configuração do gestor: $ALERTACIDADE_SETUP_TOKEN"
echo "  (Pressione Ctrl+C para encerrar o servidor)"
echo "========================================================"

if command -v xdg-open >/dev/null 2>&1; then
    (sleep 1 && xdg-open "http://localhost:8080" >/dev/null 2>&1) &
fi

exec php -S localhost:8080 -t "$DIR/MVP"
