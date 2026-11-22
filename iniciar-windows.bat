@echo off
chcp 65001 >nul
title AlertaCidade - Servidor Local

where php >nul 2>nul
if %errorlevel% neq 0 (
    echo [ERRO] O PHP nao foi encontrado no sistema.
    echo Instale o PHP 8 pelo PowerShell com: winget install PHP.PHP.8.3
    pause
    exit /b 1
)

if "%ALERTACIDADE_SETUP_TOKEN%"=="" (
    set "ALERTACIDADE_SETUP_TOKEN=token123456"
)

echo ========================================================
echo   Iniciando AlertaCidade em http://localhost:8080
echo   - Cidadao: http://localhost:8080/cidadao/
echo   - Gestor:  http://localhost:8080/gestor/
echo   - Token de configuracao do gestor: %ALERTACIDADE_SETUP_TOKEN%
echo   (Pressione Ctrl+C ou feche esta janela para encerrar)
echo ========================================================
echo.

start "" "http://localhost:8080"
php -S localhost:8080 -t "%~dp0MVP"
pause
