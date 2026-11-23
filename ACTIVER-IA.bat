@echo off
cd /d "%~dp0"
where ollama >nul 2>nul
if errorlevel 1 (
    echo Installe Ollama : https://ollama.com/download/windows
    echo Lance Ollama puis relance ce fichier.
    pause
    exit /b 1
)
echo Telechargement du modele local qwen3:4b, environ 2.5 Go.
call ollama pull qwen3:4b
if errorlevel 1 goto :error
php bin\console app:setup
if errorlevel 1 goto :error
php bin\console cache:clear
if errorlevel 1 goto :error
php bin\console app:ai:check
pause
exit /b 0
:error
echo Activation interrompue. Verifie qu'Ollama est lance et que PHP fonctionne.
pause
exit /b 1
