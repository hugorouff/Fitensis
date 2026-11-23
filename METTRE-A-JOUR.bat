@echo off
cd /d "%~dp0"
php -r "foreach (['zip','gd','simplexml','dom','xmlreader','xmlwriter'] as $extension) {if (!extension_loaded($extension)) {fwrite(STDERR, 'Extension PHP requise pour Excel : '.$extension.'. Verifiez php --ini puis activez cette extension.'.PHP_EOL); exit(1);}}"
if errorlevel 1 goto :error
rem Retirer uniquement les anciens fichiers de la recherche Web abandonnee.
if exist src\Controller\WebSearchController.php del /q src\Controller\WebSearchController.php
if exist src\Service\WebSearch.php del /q src\Service\WebSearch.php
if exist templates\web_search.html.twig del /q templates\web_search.html.twig
if exist templates\dashboard.html.twig del /q templates\dashboard.html.twig
if exist vendor\autoload.php goto :database
call composer install --no-interaction --prefer-dist
if errorlevel 1 goto :error
:database
php bin\console app:setup
if errorlevel 1 goto :error
php bin\console cache:clear
if errorlevel 1 goto :error
echo Mise a jour terminee, donnees conservees.
pause
exit /b 0
:error
echo Mise a jour interrompue.
pause
exit /b 1
