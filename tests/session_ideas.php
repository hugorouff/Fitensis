<?php
require dirname(__DIR__) . "/vendor/autoload.php";
use App\Service\{SessionIdeas, TrainingBrief, AiException};
$count = 0;
function check(bool $condition, string $message): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $count++;
}
$ideas = new SessionIdeas();
$brief = new TrainingBrief();
foreach (
    ["30 min pecs", "45 min jambes", "60 min bras", "45 min haut du corps", "40 min dos"]
    as $question
) {
    $result = $ideas->create($brief->parse($question), "uniquement des haltères", [], 5);
    check($result["count"] === 5, "Cinq idées : " . $question);
    $signatures = [];
    foreach ($result["sessions"] as $session) {
        $names = array_column($session["exercises"], "name");
        check(count($names) === count(array_unique($names)), "Pas de doublon dans une séance");
        sort($names);
        $signatures[] = json_encode($names);
        check(!preg_match("/poulie|machine/i", implode(" ", $names)), "Matériel respecté");
        check(
            $session["duration_minutes"] === $brief->parse($question)[0]["minutes"],
            "Durée respectée",
        );
    }
    check(
        count(array_unique($signatures)) === 5,
        "Combinaisons réellement distinctes, indépendamment de l’ordre",
    );
}
$combined = $ideas->create($brief->parse("30 min pecs et 1 h bras"), "haltères", [], 5);
check(
    count($combined["sessions"]) === 10 && $combined["count"] === 5,
    "Cinq idées contenant chacune les deux blocs demandés",
);
check(
    $combined["sessions"][0]["muscles"] === ["chest"] &&
        $combined["sessions"][1]["muscles"] === ["biceps", "triceps"],
    "Muscles des blocs conservés",
);
$small = $ideas->create(
    [["group" => "calves", "minutes" => 30]],
    "haltères",
    [["id" => "calf", "name" => "Mollets debout", "muscle" => "calves", "equipment" => "dumbbell"]],
    5,
);
check(
    $small["count"] === 1,
    "Catalogue restreint : pas de fausses alternatives par simple réordonnancement",
);
try {
    $ideas->create([["group" => "chest", "minutes" => 30]], "", [], 6);
    throw new RuntimeException("Limite ignorée");
} catch (AiException $e) {
    check($e->httpStatus === 422, "Nombre d’idées validé");
}
$mixedBlocks = $brief->parse("", [
    ["group" => "mixed", "muscles" => ["triceps", "chest", "shoulders"], "minutes" => 45],
]);
$mixed = $ideas->create($mixedBlocks, "haltères", [], 5);
check(
    $mixed["count"] === 5 && count($mixed["sessions"]) === 5,
    "Cinq idées pour trois muscles dans une même séance",
);
foreach ($mixed["sessions"] as $session) {
    check(
        $session["muscles"] === ["triceps", "chest", "shoulders"],
        "Tous les groupes choisis restent présents",
    );
    check($session["duration_minutes"] === 45, "45 minutes au total, pas 45 minutes par groupe");
    check(count($session["exercises"]) >= 3, "Un exercice au moins pour chaque groupe");
}
check(
    $brief->parse("45 min pecs + triceps")[0]["group"] === "mixed",
    "Combinaison libre avec + reconnue",
);
try {
    $brief->parse("", [["group" => "mixed", "muscles" => ["chest", "fake"], "minutes" => 45]]);
    throw new RuntimeException("Groupe invalide accepté");
} catch (AiException $e) {
    check($e->httpStatus === 422, "Groupe invalide refusé");
}
try {
    $brief->parse("", [
        ["group" => "mixed", "muscles" => ["chest", "back", "triceps"], "minutes" => 10],
    ]);
    throw new RuntimeException("Durée irréaliste acceptée");
} catch (AiException $e) {
    check($e->httpStatus === 422, "Durée totale validée");
}
$programme = $brief->sessions(
    $brief->parse(
        "",
        array_fill(0, 3, ["group" => "mixed", "muscles" => ["chest", "triceps"], "minutes" => 40]),
    ),
    "haltères",
);
check(
    count($programme) === 3 && str_contains($programme[1]["title"], "séance 2"),
    "Programme combiné avec séances identifiables",
);
echo "$count vérifications des alternatives de séances réussies.\n";
