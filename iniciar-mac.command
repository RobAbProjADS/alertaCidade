#!/usr/bin/env bash
set -e

DIR="$(cd "$(dirname "$0")" && pwd)"

if ! command -v php >/dev/null 2>&1; then
    echo "[ERRO] O PHP não foi encontrado no macOS."
    echo "Instale pelo Homebrew com: brew install php"
    read -r -p "Pressione Enter para sair..."
    exit 1
fi

export ALERTACIDADE_SETUP_TOKEN="${ALERTACIDADE_SETUP_TOKEN:-token123456}"

echo "========================================================"
echo "  Iniciando AlertaCidade em http://localhost:8080"
echo "  - Cidadão: http://localhost:8080/cidadao/"
echo "  - Gestor:  http://localhost:8080/gestor/"
echo "  - Token de configuração do gestor: $ALERTACIDADE_SETUP_TOKEN"
echo "  (Pressione Ctrl+C ou feche a janela para encerrar)"
echo "========================================================"

(sleep 1 && open "http://localhost:8080") &
exec php -S localhost:8080 -t "$DIR/MVP"
