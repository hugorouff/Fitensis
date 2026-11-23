<?php
require dirname(__DIR__) . "/vendor/autoload.php";
use App\Service\{RemoteCache, WikiRecipes, Nutrition, Translator, RecipeSearch, TrainingBrief};
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
$count = 0;
function check(bool $condition, string $message): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $count++;
}
$dir = sys_get_temp_dir() . "/gym-v12-catalogue-" . bin2hex(random_bytes(5));
mkdir($dir);
$http = new MockHttpClient(function ($method, $url) {
    parse_str(parse_url($url, PHP_URL_QUERY) ?: "", $q);
    if (str_contains($url, "wikibooks.org")) {
        if (($q["action"] ?? "") === "parse") {
            return new MockResponse(
                json_encode([
                    "parse" => [
                        "title" => "Cookbook:Rice Curry",
                        "text" =>
                            "<h2>Ingredients</h2><ul><li>200 g rice</li></ul><h2>Preparation</h2><p>Cook the rice.</p>",
                    ],
                ]),
            );
        }
        if (isset($q["cmcontinue"])) {
            return new MockResponse(
                json_encode([
                    "query" => [
                        "categorymembers" => [
                            ["pageid" => 12, "title" => "Cookbook:Vegetable Soup"],
                        ],
                    ],
                ]),
            );
        }
        return new MockResponse(
            json_encode([
                "query" => [
                    "categorymembers" => [
                        ["pageid" => 11, "title" => "Cookbook:Rice Curry"],
                        ["pageid" => 13, "title" => "Help:Not a recipe"],
                    ],
                ],
                "continue" => ["cmcontinue" => "next"],
            ]),
        );
    }
    if (str_contains($url, "themealdb.com")) {
        $meals =
            ($q["f"] ?? "") === "c"
                ? [
                    [
                        "idMeal" => "90",
                        "strMeal" => "Chicken rice",
                        "strCategory" => "Chicken",
                        "strInstructions" => "Cook.",
                        "strIngredient1" => "Chicken",
                        "strMeasure1" => "200 g",
                        "strIngredient2" => "Rice",
                        "strMeasure2" => "100 g",
                        "strIngredient3" => "Carrots",
                        "strMeasure3" => "2",
                    ],
                ]
                : [];
        return new MockResponse(json_encode(["meals" => $meals]));
    }
    return new MockResponse("{}");
});
$cache = new RemoteCache($http, $dir);
$wiki = new WikiRecipes($cache);
$index = $wiki->catalog();
check(
    $index["complete"] && count($index["items"]) === 2,
    "Pagination Wikibooks complète et pages de recettes uniquement",
);
check($wiki->catalog() === $index, "Cache du catalogue complet réutilisé");
$detail = $wiki->details("wkb-11");
check(
    str_contains($detail["strInstructions"], "200 g rice") &&
        str_contains($detail["strInstructions"], "Cook the rice."),
    "Détail texte de la source",
);
check(!str_contains($detail["strInstructions"], "<li>"), "Aucun HTML distant injecté");
check($wiki->details("https://example.org") === null, "Identifiant distant arbitraire refusé");
$nutrition = new Nutrition(dirname(__DIR__));
$recipes = new RecipeSearch($nutrition, $cache, new Translator($cache, $nutrition), $wiki);
$all = $recipes->search(["source" => "all"]);
check($all["total"] === 3 && $all["catalog_size"] === 3, "Deux sources réunies");
check($recipes->search(["source" => "wikibooks"])["total"] === 2, "Filtre de source Wikibooks");
check($recipes->search(["source" => "themealdb"])["total"] === 1, "Filtre de source TheMealDB");
check(
    $recipes->search(["source" => "wikibooks", "exclude" => "porc"])["total"] === 0,
    "Pas de garantie d’exclusion sans ingrédients connus",
);
check(
    $recipes->search(["source" => "themealdb", "exclude" => "poulet"])["total"] === 0,
    "Aliment exclu retiré",
);
$ranked = $recipes->search(["likes" => "poulet"]);
check($ranked["data"][0]["id"] === "90", "Goûts prioritaires");
check(
    $recipes->details("wkb-11")["source_name"] === "Wikibooks",
    "Détail fédéré conservant la provenance",
);
$brief = new TrainingBrief();
$catalog = [
    [
        "id" => "catalog-chest",
        "name" => "Développé du catalogue",
        "muscle" => "chest",
        "equipment" => "dumbbell",
    ],
];
$session = $brief->sessions(
    [["group" => "chest", "minutes" => 30]],
    "uniquement des haltères",
    $catalog,
)[0];
check(
    $session["exercises"][0]["name"] === "Développé du catalogue" &&
        $session["exercises"][0]["catalog_id"] === "catalog-chest",
    "Programme alimenté par le catalogue Exercices",
);
check(
    count(array_unique(array_column($session["exercises"], "name"))) ===
        count($session["exercises"]),
    "Exercices sans doublon",
);
check(
    $brief->parse("1 séance avant-bras de 30 minutes")[0]["group"] === "forearms",
    "Avant-bras distinct de bras",
);
$calves = $brief->sessions($brief->parse("2 séances mollets de 25 minutes"), "haltères", [
    [
        "id" => "calf",
        "name" => "Mollets du catalogue",
        "muscle" => "calves",
        "equipment" => "dumbbell",
    ],
]);
check(
    count($calves) === 2 && $calves[0]["exercises"][0]["name"] === "Mollets du catalogue",
    "Groupes fins du catalogue utilisables",
);
$allDirect = $recipes->search(["all" => "1", "limit" => 1, "page" => 2]);
check(
    count($allDirect["data"]) === 3 && !$allDirect["has_more"] && $allDirect["page"] === 1,
    "Tout afficher ignore la pagination et retourne tous les résultats",
);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($dir);
echo "$count vérifications catalogue fédéré et exercices réussies.\n";
