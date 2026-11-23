@echo off
setlocal
cd /d "%~dp0"
php -r "foreach (['zip','gd','simplexml','dom','xmlreader','xmlwriter'] as $extension) {if (!extension_loaded($extension)) {fwrite(STDERR, 'Extension PHP requise pour Excel : '.$extension.'. Verifiez php --ini puis activez cette extension.'.PHP_EOL); exit(1);}}"
if errorlevel 1 goto :error
where php >nul 2>nul
if errorlevel 1 (
 echo PHP est introuvable. Installez PHP 8.2 ou plus et ajoutez-le au PATH.
 pause
 exit /b 1
)
php -r "if (PHP_VERSION_ID < 80200 || !extension_loaded('pdo_sqlite') || !extension_loaded('mbstring') || !extension_loaded('xml')) {fwrite(STDERR, 'Activez pdo_sqlite, mbstring, xml dans php.ini et utilisez PHP 8.2+ '.PHP_EOL); exit(1);}"
if errorlevel 1 goto :error
if exist vendor\autoload.php goto :setup
where composer >nul 2>nul
if errorlevel 1 (
 echo Composer est requis pour installer les dependances.
 goto :error
)
call composer install --no-interaction --prefer-dist
if errorlevel 1 goto :error
:setup
if not exist .env.local php -r "file_put_contents('.env.local', 'APP_SECRET='.bin2hex(random_bytes(32)).PHP_EOL);"
php bin\console app:setup --demo
if errorlevel 1 goto :error
php bin\console cache:clear
if errorlevel 1 goto :error
echo.
echo Installation terminee. Conservez les identifiants affiches ci-dessus.
echo Lancez DEMARRER.bat et ouvrez http://127.0.0.1:8000
pause
exit /b 0
:error
echo.
echo Installation interrompue. Consultez le message d'erreur ci-dessus.
pause
exit /b 1
