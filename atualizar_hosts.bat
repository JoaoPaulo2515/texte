@echo off
title Atualizador de Hosts - SIGE Angola
echo ========================================
echo   Atualizador de Hosts - SIGE Angola
echo ========================================
echo.

:: Executar script PowerShell
powershell.exe -ExecutionPolicy Bypass -File "C:\xampp\htdocs\sige_Plataforma\auto_hosts.ps1"

:: Limpar cache DNS
echo.
echo Limpando cache DNS...
ipconfig /flushdns

echo.
echo Processo concluído!
echo.
pause