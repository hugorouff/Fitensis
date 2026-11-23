@echo off
setlocal
cd /d "%~dp0"
if not exist vendor\autoload.php (
 echo Executez INSTALLER.bat avant de demarrer.
 pause
 exit /b 1
)
echo Ouvrez http://127.0.0.1:8000 - Ctrl+C pour arreter.
php -S 127.0.0.1:8000 -t public public/router.php
pause
