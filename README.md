# Gym App — Symfony : entraînement, nutrition et performances

Projet complet en **Symfony 7.4, PHP 8.2+, Twig et SQLite**, repris du Gym App Python fourni. Les dépendances PHP (`vendor`) et `composer.lock` sont incluses. Aucun serveur Python ni Symfony CLI n’est nécessaire pour utiliser l’application.

Le calendrier est maintenant un **planning personnel** : choix d’une séance, des muscles à travailler, des exercices, puis saisie des séries, répétitions et charges réalisées. Il n’y a plus de parcours de réservation de cours.

## Plusieurs idées de séances dans l’assistant IA

L’assistant affiche désormais **5 propositions alternatives par défaut**, avec des combinaisons d’exercices différentes et un ajout au planning indépendant pour chaque séance. Le sélecteur « Idées de séances à comparer » permet de choisir 1, 3 ou 5 idées.

Le champ du questionnaire « Séances par proposition » est distinct et commence à 1 : pour trois séances par proposition, chaque alternative contient un programme de trois séances. Aucune proposition n’est inscrite automatiquement au planning. Les boutons permettent de choisir uniquement les séances voulues.

Les variantes reprennent les muscles, les durées, le matériel et les exercices compatibles du catalogue. Les doublons sont comparés en ignorant l’ordre et les répétitions : changer seulement le titre ou l’ordre ne compte pas comme une nouvelle idée. Si les exercices disponibles ne permettent pas cinq combinaisons distinctes, le nombre réel est annoncé. L’IA intervient toujours pour les conseils et les demandes libres ; les variantes structurées sont construites et vérifiées par l’application.

Test : `php tests/session_ideas.php`. Les tests d’intégration utilisent des réponses de modèle simulées ; une génération réelle sur votre modèle Ollama reste à vérifier sur votre installation.

## Groupes combinés et cartes repliables

Dans le questionnaire IA, le menu « Point de départ » peut être complété par plusieurs groupes cochés. Les raccourcis Push (pectoraux/épaules/triceps), Pull (dos/biceps/avant-bras), bras complets et jambes/fessiers préremplissent les cases. La durée choisie est celle de la séance entière, et non une durée à répéter pour chaque muscle. Jusqu’à huit groupes sont possibles, avec au moins cinq minutes prévues par groupe dans la durée totale. Les séances utilisent les exercices des groupes sélectionnés, sans remplacer la combinaison par un groupe unique.

Les recettes, favoris et idées de séances de l’assistant sont fermés à l’arrivée : cliquer sur leur en-tête (ou Entrée/Espace au clavier) ouvre les détails et les actions. Les cartes de la page Recettes restent entièrement affichées : le repliement concerne uniquement les propositions de l’assistant IA et ses favoris. Les boutons de favoris, portions et planning restent dans la carte ouverte. Plusieurs cartes peuvent rester ouvertes pour comparer les propositions. Aucun nouvel envoi à l’IA n’est déclenché par l’ouverture d’une carte.

## Excel : importer et exporter mes progressions

Dans **Performances**, le bouton **Importer un Excel** ouvre le parcours de suivi : téléchargement d’un modèle, import XLSX/CSV, aperçu puis confirmation. L’import est limité à 5 Mo et 2 000 lignes au total. Les anciens fichiers XLS et les classeurs avec macros ne sont pas acceptés.

Le modèle contient les feuilles **Performances** (date, exercice, séries, répétitions, charge en kg, RPE, notes) et **Mesures** (date, poids en kg, tour de taille en cm, masse grasse en %, notes). Les autres feuilles calculées de l’export ne sont pas importées. Une ligne représente plusieurs séries à charge et répétitions identiques. Aucun exemple de performance n’est ajouté au modèle. Les dates Excel 1900/1904, AAAA-MM-JJ ou JJ/MM/AAAA, les décimales françaises et les CSV UTF-8/Windows sont pris en charge.

Le fichier est analysé côté serveur. L’aperçu affiche les données, les erreurs par ligne, les doublons et les RPE remplacés ; il n’ajoute aucune donnée. Le RPE de remplacement est choisi sur la page (7 proposé) et n’est utilisé que si le fichier n’en contient pas. Les valeurs doivent respecter les mêmes bornes que le carnet. Une erreur bloque la confirmation de tout le fichier. L’aperçu expire au bout de 30 minutes ou lors d’un nouvel envoi. Les données appartiennent uniquement au compte connecté ; aucun identifiant utilisateur du fichier n’est accepté.

La confirmation ajoute les performances au carnet et les mesures à leur historique. Les entrées strictement identiques sont ignorées, aucun enregistrement existant n’est modifié et aucune séance n’est créée dans le planning. Les performances importées alimentent les graphiques et les statistiques de l’application.

**Exporter en Excel** produit un classeur personnel : Synthese, Performances, Mesures, Progression par jour/exercice, Graphiques, Donnees_graphes et Mode emploi. Les colonnes volume et 1RM du détail contiennent les formules ; les tableaux agrégés sont des instantanés calculés lors de l’export. L’onglet Graphiques s’ouvre directement et propose jusqu’à six diagrammes natifs Excel selon les données disponibles : charge maximale et volume de l’exercice sélectionné, top 8 des exercices par jours distincts, jours actifs par semaine, volume hebdomadaire et poids corporel. Les courbes affichent jusqu’aux 365 dernières dates et les graphiques hebdomadaires jusqu’aux 12 dernières semaines enregistrées, y compris les semaines sans entraînement. Donnees_graphes expose leurs valeurs sources ; l’historique complet reste dans les autres feuilles. Le fichier contient uniquement les enregistrements du compte connecté, avec un maximum de 25 000 lignes par export Excel ; le CSV reste disponible pour les historiques plus grands.

### Extensions PHP nécessaires pour Excel

Les dépendances PHP mises à jour sont incluses dans `vendor`, avec compatibilité PHP 8.2 conservée. Vérifier les extensions avec `php -m` et le fichier actif avec `php --ini`. **ZIP et GD** doivent notamment être activées ; DOM, SimpleXML, XMLReader, XMLWriter et mbstring sont également requis par la bibliothèque. Les scripts d’installation/mise à jour signalent une extension absente.

Pour accepter des fichiers de 5 Mo, configurer dans `php.ini` puis redémarrer PHP :

```ini
extension=zip
extension=gd
upload_max_filesize=5M
post_max_size=8M
```

Ne pas ajouter de ligne `extension` en double si elle est déjà activée. La bibliothèque PhpSpreadsheet est utilisée pour la lecture et l’écriture ; les formules des colonnes de saisie, les liens externes de classeur et les éléments de macros sont refusés à l’import. Le fichier brut n’est pas conservé après traitement ; l’aperçu privé reste temporairement dans la session serveur.

Sur **Performances**, les filtres de période (30/90/365 jours ou tout l’historique), d’exercice et d’indicateur pilotent le tableau de bord. Les classements comptent les jours distincts, pas les lignes du carnet. Les points des courbes se lisent au survol, au clic, au toucher et au clavier (tabulation puis flèches). Les tables restent consultables sous les graphiques. Les mesures de poids apparaissent si elles sont enregistrées. Les données manquantes de 1RM ne deviennent pas des zéros. Ces filtres ne limitent pas l’export Excel, qui conserve tout l’historique personnel.

Validation : `php tests/performance_stats.php`, `php tests/excel.php` et `python tests/smoke.py`. La fonctionnalité Excel n’utilise pas Ollama et ne transmet pas les fichiers à un service IA.

## Démarrer sous Windows

1. Extraire tout le ZIP dans un dossier.
2. Vérifier `php -v` : PHP 8.2 minimum. Activer `pdo_sqlite`, `mbstring`, `dom`, `xml`, `ctype`, `iconv`, `openssl`, `zip` et `gd` dans le `php.ini` indiqué par `php --ini`. `curl` est conseillé pour les recherches Internet.
3. Lancer **INSTALLER.bat**. Le script crée la base et un secret local. Le mot de passe de démonstration est généré et affiché : le conserver avant de fermer la fenêtre.
4. Lancer **DEMARRER.bat** et ouvrir **http://127.0.0.1:8000**.
5. Pour son propre compte, cliquer sur **Créer mon compte** sur la page de connexion : nom, adresse mail, mot de passe et confirmation. La connexion est immédiate, sans validation administrateur ni mail de confirmation.

Les exemples de démonstration sont ajoutés seulement lors de la première installation. Comptes de démonstration : `admin@gym.local`, `coach@gym.local`, `camille@gym.local`, avec le mot de passe affiché à l’installation. Les comptes inscrits via le formulaire ont le rôle adhérent.

Le script utilise `php -S`, pas `symfony.exe`. Composer est requis uniquement si les dépendances livrées sont absentes. Si la politique du poste bloque PHP ou les fichiers `.bat`, faire autoriser ces exécutables par l’administrateur du poste.

## Mettre à jour une installation existante

1. Arrêter le serveur local et sauvegarder le dossier, particulièrement `var/gym.db` et `.env.local`.
2. Copier les fichiers de cette version dans le dossier du projet, en **conservant sa base `var/gym.db` et son `.env.local`**. Ne pas remplacer le dossier `var` existant par un dossier vide.
3. Dans PowerShell, depuis le dossier du projet :

```powershell
.\METTRE-A-JOUR.bat
.\DEMARRER.bat
```

La mise à jour crée les tables du planning personnel et de l’assistant IA et ajoute le lien aux performances. Les comptes, mots de passe et entrées existantes du carnet sont conservés. Les anciennes données de cours/réservations restent dans la base pour éviter de les détruire, mais leurs pages et actions sont retirées. Elles ne sont pas converties automatiquement en séances personnelles.

Le script retire également les anciens fichiers de la page Recherche Internet. Il est donc recommandé de l’utiliser après avoir copié cette version.

## Assistant IA avec Ollama (local par défaut)

L’onglet **Assistant IA** propose plusieurs recettes, séances et conseils à partir d’une demande en français. Il conserve les échanges privés de chaque compte, permet d’affiner une réponse, de garder des recettes en favoris et d’ajouter une séance choisie au planning. Une proposition ajoutée ne crée aucune performance réalisée : les séries et charges réelles restent à saisir pendant l’entraînement.

1. Installer [Ollama pour Windows](https://ollama.com/download/windows) et le lancer.
2. Depuis le dossier du projet, lancer **ACTIVER-IA.bat**, ou télécharger le modèle dans PowerShell :

```powershell
ollama pull qwen3:4b
php bin/console app:setup
php bin/console cache:clear
php bin/console app:ai:check
```

3. Démarrer l’application normalement puis ouvrir **Assistant IA**. Le bouton « Revérifier la connexion » contrôle la présence du modèle.

Le téléchargement de `qwen3:4b` représente environ 2,5 Go. La mémoire utilisée dépasse la seule taille du fichier ; la vitesse dépend du processeur, de la mémoire et de la carte graphique. Le premier chargement peut être lent. Aucun compte ni clé API payante n’est nécessaire pour ce modèle local. Un appel de génération dispose au maximum de trois minutes côté serveur ; la recherche de recettes et une éventuelle correction du format peuvent allonger le traitement. Réduire la demande si la machine est lente.

Les valeurs par défaut sont dans `.env` ; pour les personnaliser, ajouter uniquement les lignes nécessaires à son `.env.local` existant :

```dotenv
AI_PROVIDER=ollama
OLLAMA_URL=http://127.0.0.1:11434
OLLAMA_MODEL=qwen3:4b
AI_REQUESTS_PER_DAY=100
```

Ollama doit tourner sur la machine qui exécute PHP. Pour un serveur distant ou un conteneur, `127.0.0.1` désigne ce serveur ou ce conteneur : configurer une adresse privée joignable adaptée à l’hébergement. Ne pas exposer directement l’API Ollama sur Internet. `ACTIVER-IA.bat` installe le modèle par défaut ; si un autre modèle est configuré, le télécharger avec `ollama pull NOM_DU_MODELE`.

Le frigo de ce compte et ses douze dernières performances sont **facultatifs et décochés par défaut**. La demande, les contraintes, l’historique choisi et, si nécessaire, les exemples publics d’exercices en cache sont transmis au moteur ; les performances sont limitées au compte connecté. Ni adresse mail, ni mot de passe, ni mensurations ne sont ajoutés automatiquement. Le frigo reste stocké par compte dans le navigateur. Le moteur local utilise l’adresse configurée ; une adresse distante transmet les données à cet hôte. Effacer l’historique IA conserve les séances ajoutées et les recettes favorites.

L’assistant recherche maintenant les repas dans le catalogue Internet **TheMealDB**, avec actualisation du cache public après 24 h et repli sur ce cache si la source échoue. Il vise 40 recettes distinctes, classées selon les ingrédients demandés, l’objectif et la variété. Les huiles, sauces seules, desserts et boissons ne sont pas présentés comme des repas. Un bouillon de poulet ne suffit pas à faire considérer un plat comme un repas au poulet. Si les critères ne donnent pas 40 résultats, le nombre réel est annoncé : aucun complément n’est inventé. Les liens mènent à la fiche et au site source ; une traduction indisponible laisse le texte source identifiable.

**Portions :** chaque carte comporte « Portions souhaitées » et « Portions de la recette source ». Les nombres, décimales, fractions et fourchettes de quantités sont recalculés ; les indications non chiffrées restent inchangées. TheMealDB ne précise pas les portions : la base de 2 affichée est une hypothèse à confirmer et corriger selon la recette d’origine. Les temps de cuisson ne sont pas multipliés automatiquement et aucun temps de préparation n’est inventé. Les favoris conservent les portions choisies lors de l’enregistrement.

**Séances :** les demandes explicites comme « 30 min pecs et 1 h bras » sont converties par l’application en deux blocs vérifiés (3 et 6 exercices). Ollama ne peut pas remplacer les muscles ou durées de ces blocs. Une demande ambiguë peut être précisée dans « Préciser les blocs de séance ». Les modèles structurés utilisent des exercices de base proposés pour salle ou domicile avec matériel précisé ; ils restent modifiables. Ce ne sont pas des programmes validés par un coach. Les demandes « haut du corps », « bas du corps » et « full body » sont reconnues, ainsi que le nombre de séances (1 à 6, en chiffres ou en lettres). Exemple : « Prépare-moi 3 séances haut du corps de 45 minutes avec des haltères » produit trois blocs de 45 minutes adaptés aux haltères. Les autres formulations utilisent les propositions du modèle : une liste courte ne bloque plus toute la réponse sur la seule base de la durée ; les exercices vides et les valeurs incohérentes restent refusés. Les blocs sont des séances séparées dans le planning, que l’on peut placer à des horaires successifs.

L’introduction est construite par l’application : aucune analyse interne du modèle n’y est affichée, même pour les anciennes conversations. Les champs de conseils sont contrôlés et une réponse de format incorrect donne lieu à une tentative de correction automatique. Une erreur retire l’ancienne réponse de l’écran pour ne pas la confondre avec le nouveau résultat.

L’utilisateur choisit explicitement chaque enregistrement. Limite : 100 demandes par compte sur 24 h et quatre secondes entre deux demandes, réglable via `AI_REQUESTS_PER_DAY`.

### Questionnaire guidé

Trois étapes préparent une demande modifiable : objectif et niveau, séance/muscles/durée/matériel, puis goûts et portions. Le parcours recettes/conseils saute l’étape entraînement. Rien n’est envoyé au moteur avant « Me proposer des idées » et les cases de partage restent facultatives.

Les groupes fins sont ceux de la page Exercices ; un aperçu charge les exemples de ce même catalogue. Les programmes structurés réutilisent les exercices compatibles connus (muscle, matériel et niveau), avec un lien vers leur fiche. Les variantes de base restent un secours pour les groupes usuels ; pour un groupe fin absent de la source, l’application explique que le catalogue doit être chargé plutôt que d’inventer un exercice. Les portions du questionnaire sont appliquées aux recettes de l’assistant avec la réserve habituelle sur la base source inconnue.

La navigation commune utilise `aria-current="page"` et un accent vert sur la page active, y compris pour les fiches de séance et les liens vers Exercices.

### Alternative facultative OpenAI

Pour utiliser une API hébergée à la place du moteur local, ajouter dans `.env.local` :

```dotenv
AI_PROVIDER=openai
OPENAI_API_KEY=sa_cle_serveur
OPENAI_MODEL=gpt-5-mini
```

La clé reste côté serveur. Cette option nécessite un compte API avec facturation et transmet le contexte choisi à OpenAI. `store=false` est demandé à l’API Responses ; ce paramètre ne constitue pas une garantie d’absence de journaux techniques chez le fournisseur. Ne pas publier `.env.local`. Vider le cache après un changement de moteur. Le contrôle de connexion OpenAI vérifie la présence de la clé ; son accès au modèle est vérifié lors d’une génération.

### Validation de cette version

```powershell
php tests/ai.php
php tests/catalogue.php
# Facultatif, Node.js uniquement pour ce test de quantités :
node tests/portions.cjs
python tests/smoke.py
php tests/remote.php
python tests/migration.py
python tests/design.py
```

Les tests IA utilisent des réponses simulées pour vérifier le protocole Ollama, la validation, la confidentialité entre comptes, les quotas et l’ajout au planning/favoris. Ils ne mesurent pas la qualité ni la vitesse d’un modèle réel. La récupération de recettes a également été vérifiée sur la source réelle. Aucun Ollama avec modèle n’était installé dans l’environnement de préparation ; une génération réelle reste à vérifier sur le PC après activation.

## Mon parcours d’entraînement

| Étape | Fonctionnement |
| --- | --- |
| Choisir ma séance | Haut du corps, jambes, Push, Pull, Full body, abdos/gainage ou personnalisée |
| Choisir mes muscles | Les cases sont préremplies par le type de séance et restent modifiables |
| Planifier | Date, heure repère, durée, notes et répétition hebdomadaire de 1 à 12 occurrences |
| Préparer mes exercices | Recherche dans le catalogue ou saisie libre ; séries, répétitions et charge prévue |
| Réaliser | Saisir les séries, répétitions, charge réelle, RPE et notes ; la dernière performance du même exercice est rappelée |
| Corriger | Modifier ou supprimer une performance enregistrée |
| Terminer | Marquer la séance terminée, non faite ou à refaire |
| Réutiliser | Dupliquer la séance une semaine plus tard avec ses exercices, sans copier les performances réalisées |
| Mesurer mes progrès | Charge maximale notée par jour, historique par exercice, volume et 1RM indicatif |
| Exporter | CSV du carnet et des mensurations ; fichier `.ics` du planning personnel |

Les valeurs prévues ne sont jamais comptées comme performances réalisées. Une saisie décrit un groupe de séries avec les mêmes répétitions et la même charge. Pour des séries différentes, enregistrer plusieurs lignes. La charge peut être 0 pour le poids du corps.

Le 1RM est une estimation (formule d’Epley) calculée uniquement pour les séries de 1 à 30 répétitions dans la page Performances. Comparer aussi séries et répétitions avant d’interpréter une hausse de charge. Le volume affiché est la somme séries × répétitions × charge en kg ; il ne mesure pas à lui seul la qualité de l’entraînement.

Supprimer une séance du planning conserve ses performances dans le carnet, en retirant leur lien à cette séance. Chaque planning et chaque historique sont privés, y compris vis-à-vis des coachs et administrateurs.

## Autres fonctions conservées

- Carnet indépendant avec modification, suppression, export et chronomètre de repos 60/90/120 secondes.
- Mensurations, historique de poids et objectifs personnels.
- Bibliothèque anatomique d’exercices avec médias homme/femme lorsque disponibles.
- Calculateurs de charges, nutrition, placard, recettes et menus quotidiens.
- Inscription libre, connexion, profil et changement de mot de passe.
- Outils d’administration secondaires : comptes, programmes attribués, matériel, abonnements et encaissements manuels.

Les outils d’administration restent secondaires et ne sont pas nécessaires pour utiliser le calendrier ou suivre ses performances. Le registre des paiements n’effectue aucune transaction bancaire. Les programmes textuels attribués par les coachs ne remplissent pas automatiquement le planning.

## Exercices récupérés automatiquement

Source principale conservée du projet original :
https://raw.githubusercontent.com/luisaraujoc/free-exercise-db-api/main/data/exercises.json

Source de secours :
https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/dist/exercises.json

L’ancienne source secondaire `exercise-database.zenithfits.com` répondait HTTP 410 pendant les vérifications du 03/11/2026 et a été remplacée. Les médias, étapes, conseils, erreurs, respiration, difficulté et alias sont conservés. Les fiches non classées restent visibles. Le muscle explicitement ciblé prime sur les métadonnées secondaires incohérentes ; les exercices de poignet sont reclassés dans les avant-bras. La recherche prend également en compte les noms français du dictionnaire d’origine.

Cache de 15 minutes. Si les sources sont indisponibles, la dernière copie enregistrée reste accessible ; sans copie, un catalogue local de 15 exercices est affiché en mode de secours explicite. Le nombre d’exercices et le nom de la source reflètent les résultats obtenus. Les photos et vidéos restent hébergées par les sources et peuvent être indisponibles indépendamment du catalogue.

L’ouverture d’une fiche charge sa traduction française à la demande via MyMemory. Les traductions réussies sont mises en cache. Si le service ou son quota ne permet pas une traduction, le texte original reste consultable. Les fiches permettent de préremplir le carnet, préparer une séance et préparer une séance personnelle.

## Recettes et nutrition

Les 12 cartes génériques de l’ancienne page sont désactivées. Leur tableau d’origine est conservé dans un commentaire de `templates/recettes/index.html.twig` (`archivedGenericRecipes`), avec un tableau actif vide. Les calculateurs et le générateur nutritionnel indépendant sont conservés.

La page Recettes réunit désormais deux catalogues réels : **TheMealDB** (26 lettres de l’alphabet) et **Wikibooks Cookbook** (catégorie Recipes, toutes les pages de continuation). Le cache public dure 24 heures. Ce périmètre couvre les sources prises en charge, pas toutes les recettes d’Internet. Les titres sont dédupliqués par identifiant de source ; deux variantes provenant de sources différentes peuvent rester visibles. Le compteur indique les fiches réellement récupérées. Une panne ou une indexation incomplète est signalée ; « Continuer le chargement des sources » permet de poursuivre si nécessaire.

La page demande tous les résultats en une réponse (`all=1`) et affiche toutes leurs cartes, sans bouton de pagination ni attente du défilement. De petits rendus techniques de 100 cartes évitent de bloquer le navigateur ; ils se poursuivent automatiquement même sans défilement. Les filtres continuent à s’appliquer à l’ensemble du catalogue. Les détails se récupèrent à l’ouverture pour éviter de télécharger des milliers de préparations avant d’afficher la page.

**Mes goûts :** aliments appréciés, exclusions, choix des sources et tri par pertinence/goûts, nom ou ingrédients du placard. Les préférences sont mémorisées dans ce navigateur pour le compte connecté. Une exclusion retire aussi les fiches dont les ingrédients sont inconnus ; le titre d’une recette ne suffit pas à garantir une exclusion. Ces filtres ne constituent pas une certification d’absence d’allergène. La recherche reconnaît aussi les variantes françaises/anglaises des noms et ingrédients connus.

Wikibooks ne fournit pas les ingrédients, portions ou macros sous forme structurée dans son index : ces champs ne sont pas inventés. La préparation en texte et les ingrédients sont consultables sur la fiche et le site source, souvent en anglais. Les textes Wikibooks restent attribués à leur page d’origine (CC BY-SA, conditions et historique sur la source). Aucune donnée de démonstration DummyJSON n’est ajoutée.

Les résultats indiquent les ingrédients disponibles/manquants, les quantités fournies par la source, sa préparation et ses liens. La préparation se traduit à l’ouverture du détail et le texte original reste accessible. Les dictionnaires français locaux, traductions et réponses distantes utilisent un cache. Les erreurs de réseau sont affichées clairement.

**Les recettes Web n’ont pas de calories/macros vérifiées.** Les valeurs chiffrées du générateur de menus proviennent du référentiel alimentaire du projet (`data/catalog.json`), avec calcul 4/4/9 et optimisation des quantités pour quatre repas. Les résultats Internet ne sont pas transformés en nutrition vérifiée.

Le placard et les préférences nutritionnelles sont stockés dans le navigateur, séparés par identifiant de compte. Ils ne se synchronisent pas entre appareils. Les anciens éléments stockés par le projet FastAPI ne sont pas importés automatiquement.

## Design et recherches d’origine

L’accueil redevient la page originale `GYM APP` : fond sombre, accents vert vif, grand titre « Track your strength. Build your physique. », calculateurs et cartes d’origine. Les règles CSS intégrées aux trois pages originales (accueil, exercices et recettes) sont conservées et réindentées. Les pages de suivi personnel utilisent le même thème sombre et vert, avec une navigation horizontale.

Il n’y a plus de page de recherche Web, de connexion SerpApi ni de paramétrage de clé Google. La recherche fonctionne dans les pages prévues au départ : catalogue d’exercices par nom/muscle et recettes selon les aliments du placard et l’objectif. La récupération distante se fait depuis les sources d’origine et les sources de secours documentées, sans clé à renseigner.

Les liens Mes séances, Performances et Mon compte ajoutent les fonctions personnelles demandées. Les corrections de récupération, de cache et de traduction restent actives, ainsi que les préparations détaillées et les liens vers les sources des recettes.

## Commandes utiles dans PowerShell

```powershell
# Vérifier les sources Internet et préchauffer leur cache :
php bin/console app:sources:check
php bin/console app:sources:check --exercises-only
php bin/console app:sources:check --ingredients="poulet,riz,oeufs"

# Supprimer définitivement un utilisateur par son adresse mail :
php bin/console app:user:delete "adresse@exemple.fr"
```

La suppression affiche le compte et demande confirmation (`y`). Elle supprime ses séances et données personnelles, ainsi que les abonnements/encaissements associés. Le dernier administrateur actif est protégé. Sauvegarder la base auparavant si ces données doivent être conservées.

## Installation Linux / macOS

```bash
# Seulement si vendor est absent :
composer install
php -r "file_put_contents('.env.local', 'APP_SECRET='.bin2hex(random_bytes(32)).PHP_EOL);"
php bin/console app:setup --demo
php -S 127.0.0.1:8000 -t public public/router.php
```

Sans démonstration, omettre `--demo`. La commande d’installation ne recrée pas les comptes existants et n’efface pas la base.

## Données et hébergement

Base SQLite : `var/gym.db`. Sessions : `var/sessions/`. Réponses publiques en cache : `var/remote/`. Configuration privée : `.env.local`. Pour sauvegarder, utiliser SQLite `.backup` ou copier la base après arrêt du serveur, avec la configuration privée.

Pour l’hébergement, utiliser PHP-FPM avec Nginx/Apache, racine Web sur `public/`, réécriture vers `public/index.php`, HTTPS, `APP_ENV=prod`, `APP_DEBUG=0`, un `APP_SECRET` aléatoire et un répertoire `var` persistant accessible à PHP. Cette édition utilise SQLite et une seule instance d’application. PostgreSQL n’est pas pris en charge par le schéma livré.

Le Compose utilise une base externe fournie par `DATABASE_URL`. Pour une installation neuve, crée une base PostgreSQL `fitensis` et un compte dédié autorisé à créer le schéma applicatif. Conserve `APP_SECRET` et `DATABASE_URL` dans `.env.server` sur la VM. L’assistant IA est désactivé par défaut dans Docker.

```bash
docker compose --env-file .env.server config --quiet
docker compose --env-file .env.server build gym
docker compose --env-file .env.server run --rm gym php bin/console app:setup --email=admin@example.fr
docker compose --env-file .env.server up -d gym
```

Le volume conserve les sessions et fichiers applicatifs. Les données PostgreSQL sont sur la VM BDD. Les données d'une ancienne base SQLite demandent un transfert distinct. Pour un hébergement public, prévoir un serveur PHP de production en remplacement du serveur intégré.

Connexion par sessions, mots de passe hachés, CSRF et contrôle de propriété des données. Les rôles coach et administrateur restent attribués par la salle. Aucun secret personnel ni compte déjà initialisé n’est livré dans l’archive.

## Vérifications

```bash
php bin/console lint:twig templates
php bin/console lint:yaml config
php bin/console lint:container
php tests/remote.php
python tests/smoke.py
python tests/migration.py
python tests/design.py
```

Les tests HTTP utilisent une base temporaire. Les tests distants simulent les sources, pannes, traductions, variantes d’ingrédients sans appel externe. Le test de migration vérifie la conservation d’un ancien carnet et de son compte. Python est requis uniquement pour ces tests de développement.

Les médias et sources distantes dépendent de leurs fournisseurs. Les calculs nutritionnels et le 1RM restent indicatifs ; le poids cible et sa date ne sont pas des promesses de résultat.

## Navigation et palette harmonisées

Toutes les pages utilisent désormais la même barre de navigation (logo GYM APP, Outils, Exercices, Recettes, Mes séances, Performances, Mon compte, Catalogue), générée par un unique composant. Sur mobile, le menu peut défiler horizontalement. La page Exercices conserve sa carte musculaire et ses fonctionnalités, avec le fond sombre et les accents verts du reste du site. Le bouton Créer un exercice se trouve sous la navigation. Les styles d’origine restent dans les fichiers ; une feuille de thème dédiée remplace uniquement la palette des exercices.

## Présentation du code

Les fichiers du projet sont réindentés : PHP, Twig, HTML, JavaScript, CSS, JSON et tests Python utilisent quatre espaces ; YAML utilise deux espaces. Le fichier `.editorconfig` conserve ces conventions lors des prochaines modifications. Les dépendances tierces du dossier `vendor` restent dans leur présentation officielle. Le test de design compare les règles CSS en ignorant les changements de mise en forme.
