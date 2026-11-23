# Fitensis

Application Symfony de suivi sportif et nutritionnel. Elle permet de préparer ses séances, suivre sa progression et utiliser un assistant IA local en option.

## Fonctionnalités

| Espace | Usage |
|---|---|
| Exercices | Catalogue filtrable, carte musculaire, exercices personnalisés et fiches descriptives. |
| Outils et recettes | Calculs de charge et de nutrition, idées de repas, portions et filtres. |
| Planning et séances | Séances par groupe musculaire, calendrier et suivi des exercices. |
| Performances | Historique, graphiques, import et export Excel. |
| Assistant IA | Questionnaire, suggestions de séances, recettes et conseils, avec ajout aux favoris ou au planning selon le résultat. |

## Installation et données

Prérequis : PHP 8.2+ selon Composer, Composer et SQLite avec `pdo_sqlite`. Pour Excel, installer les extensions requises par PhpSpreadsheet, dont `gd`, `zip` et les extensions XML exigées par le verrou.

Sous Windows, lancer `INSTALLER.bat`, puis `DEMARRER.bat`. Pour une installation manuelle :

```bash
composer install
php bin/console app:setup
php -S 127.0.0.1:8000 -t public public/router.php
```

Utiliser `php bin/console app:setup --demo` si une installation de démonstration est souhaitée. Conserver `.env.local` et la base SQLite visée par `DATABASE_URL` lors d’une mise à jour. Les pages Accueil, Outils, Exercices et Recettes utilisent des fichiers HTML dans `data/`. Elles chargent la palette commune avec les pages Twig.

## Assistant IA

Configurer `AI_PROVIDER=ollama`, `OLLAMA_URL` et `OLLAMA_MODEL`. Le modèle local de référence est `qwen3:4b`. Lancer Ollama sur la machine qui héberge PHP ou sur une adresse privée joignable depuis cette machine.

```bash
ollama pull qwen3:4b
php bin/console app:ai:check
```

Sous Windows, `ACTIVER-IA.bat` automatise la préparation prévue par le projet. `AI_REQUESTS_PER_DAY` règle le quota. L’import et l’export Excel fonctionnent indépendamment d’Ollama. Pour vérifier la version locale, utiliser `php tests/performance_stats.php`, `php tests/excel.php` et `python tests/design.py`.
