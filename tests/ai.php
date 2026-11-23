<?php
/** Tests déterministes : aucun modèle téléchargé, aucune requête externe. */
require dirname(__DIR__) . "/vendor/autoload.php";

use App\Service\{
    AiClient,
    AiException,
    AssistantService,
    GymStore,
    Nutrition,
    RemoteCache,
    Translator,
    RecipeSearch,
    ExerciseCatalog,
};
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\{Request, RequestStack};
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

$count = 0;
function check(bool $condition, string $message): void
{
    global $count;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $count++;
}
function rejected(callable $action, int $status = 503): void
{
    try {
        $action();
    } catch (AiException $error) {
        check(
            $error->httpStatus === $status,
            "Statut attendu : " . $status . " / " . $error->getMessage(),
        );
        return;
    }
    throw new RuntimeException("Action invalide acceptée");
}
function client(callable $handler, string $provider = "ollama"): AiClient
{
    return new AiClient(
        new MockHttpClient($handler),
        "test-secret-never-display",
        "gpt-5-mini",
        $provider,
        "http://127.0.0.1:11434",
        "qwen3:4b",
    );
}
function response(array $body, int $status = 200): MockResponse
{
    return new MockResponse(json_encode($body), ["http_code" => $status]);
}
$root = dirname(__DIR__);
$dir = sys_get_temp_dir() . "/gym-ai-test-" . bin2hex(random_bytes(6));
mkdir($dir);
$stack = new RequestStack();
$request = new Request();
$session = new Session(new MockArraySessionStorage());
$request->setSession($session);
$stack->push($request);
$store = new GymStore($dir, "sqlite:///" . $dir . "/test.db", $stack);
$store->init();
$db = $store->db();
foreach ([1, 2] as $uid) {
    $db->insert("users", [
        "id" => $uid,
        "name" => "PRIVATE-NAME-" . $uid,
        "email" => "private" . $uid . "@example.org",
        "password" => "PRIVATE-PASSWORD",
        "role" => "member",
    ]);
    $db->insert("workouts", [
        "user_id" => $uid,
        "date" => "2026-11-01",
        "exercise" => "PRIVATE-EXERCISE-" . $uid,
        "sets" => 3,
        "reps" => 10,
        "weight" => 20,
        "rpe" => 7,
    ]);
}
$session->set("user_id", 1);
$nutrition = new Nutrition($root);
$muscles = $nutrition->catalog("MUSCLE_LABELS");
$fixture = [
    "answer" => "<script>alert(1)</script> Voici des idées.",
    "recipes" => [
        [
            "title" => "Bol de riz",
            "reason" => "Simple",
            "catalog_id" => null,
            "minutes" => 20,
            "portions" => 2,
            "ingredients" => ["100 g de riz"],
            "steps" => ["Cuire selon le paquet."],
        ],
    ],
    "sessions" => [
        [
            "title" => "Séance adaptée",
            "reason" => "Reprise",
            "duration_minutes" => 45,
            "muscles" => [array_key_first($muscles)],
            "notes" => "Échauffement progressif.",
            "exercises" => [
                [
                    "name" => "Exercice exemple",
                    "sets" => 3,
                    "reps" => 10,
                    "weight" => null,
                    "rest_seconds" => 90,
                    "instructions" => "Adapter la charge.",
                ],
            ],
        ],
    ],
    "advice" => ["Prévoir de la récupération."],
    "follow_up" => "",
];
$fixture["sessions"][0]["exercises"] = array_fill(0, 4, $fixture["sessions"][0]["exercises"][0]);
$captured = [];
$ai = client(function ($method, $url, $options) use (&$captured, $fixture) {
    check(
        $method === "POST" && $url === "http://127.0.0.1:11434/api/chat",
        "Ollama local uniquement",
    );
    $payload = json_decode($options["body"], true);
    check(
        $payload["stream"] === false && $payload["think"] === false,
        "Protocole local non streamé",
    );
    check($payload["format"]["additionalProperties"] === false, "Schéma structuré");
    $captured[] = $payload;
    return response(["done" => true, "message" => ["content" => json_encode($fixture)]]);
});
$cache = new RemoteCache(
    new MockHttpClient(function () {
        throw new RuntimeException("Accès catalogue réseau inattendu");
    }),
    $dir,
);
$translator = new Translator($cache, $nutrition);
$recipes = new RecipeSearch($nutrition, $cache, $translator);
$exercises = new ExerciseCatalog($store, $nutrition, $cache, $translator);
// Public source fixtures; never depend on an installed model or the live Internet.
$meals = [];
for ($i = 0; $i < 46; $i++) {
    $meals[] = [
        "idMeal" => (string) (70000 + $i),
        "strMeal" => "Chicken rice bowl " . $i,
        "strCategory" => "Chicken",
        "strIngredient1" => "Chicken",
        "strMeasure1" => "200 g",
        "strIngredient2" => "Rice",
        "strMeasure2" => "100 g",
        "strIngredient3" => "Carrots",
        "strMeasure3" => "2",
        "strInstructions" => "Cook the ingredients.",
        "strSource" => "https://example.org/recipe/" . $i,
    ];
}
foreach (range("a", "z") as $letter) {
    $cache->write("https://www.themealdb.com/api/json/v1/1/search.php?f=" . $letter, [
        "meals" => $letter === "c" ? $meals : [],
    ]);
}
$assistant = new AssistantService($store, $ai, $nutrition, $recipes, $exercises, 100);
$run = $assistant->ask([
    "idea_count" => 1,
    "message" => "Des idées de repas",
    "pantry" => ["PRIVATE-PANTRY"],
]);
check($run["status"] === "completed", "Réponse enregistrée");
$sent = json_encode($captured[0]);
check(
    !str_contains($sent, "PRIVATE-") && !str_contains($sent, "@example.org"),
    "Aucune donnée personnelle sans choix",
);
check(!str_contains($sent, "test-secret"), "Pas de clé dans le contexte");
check(
    (int) $db->fetchOne("SELECT COUNT(*) FROM training_sessions") === 0,
    "Pas de modification automatique du planning",
);
rejected(fn() => $assistant->ask(["idea_count" => 1, "message" => "Trop rapide"]), 429);
$db->executeStatement("UPDATE ai_runs SET created_at=datetime('now','-10 seconds')");
$next = $assistant->ask([
    "idea_count" => 1,
    "message" => "Affine",
    "parent_id" => $run["id"],
    "share_pantry" => true,
    "pantry" => ["PRIVATE-PANTRY"],
    "share_training" => true,
]);
$sent = json_encode($captured[1]);
check(
    str_contains($sent, "PRIVATE-PANTRY") && str_contains($sent, "PRIVATE-EXERCISE-1"),
    "Contexte choisi transmis",
);
check(
    !str_contains($sent, "PRIVATE-EXERCISE-2") && !str_contains($sent, "PRIVATE-NAME"),
    "Contexte limité au propriétaire et aux performances",
);
check(
    count(json_decode($captured[1]["messages"][1]["content"], true)["conversation_precedente"]) ===
        1,
    "Précisions avec historique",
);
$id = $assistant->addSession((int) $run["id"], 0, "2026-11-05", "18:00");
check(
    $id === $assistant->addSession((int) $run["id"], 0, "2026-11-05", "18:00"),
    "Import idempotent",
);
check(
    (int) $db->fetchOne("SELECT COUNT(*) FROM training_items WHERE session_id=?", [$id]) === 4,
    "Exercices importés",
);
check(
    (int) $db->fetchOne("SELECT COUNT(*) FROM workouts") === 2,
    "Aucune performance fictive ajoutée",
);
check(
    $db->fetchOne("SELECT status FROM training_sessions WHERE id=?", [$id]) === "planned",
    "Séance prévue",
);
rejected(fn() => $assistant->addSession((int) $run["id"], 0, "2026-02-30", "18:00"), 422);
rejected(fn() => $assistant->addSession((int) $run["id"], 99, "2026-11-05", "18:00"), 404);
$assistant->favorite((int) $run["id"], 0);
$assistant->favorite((int) $run["id"], 0);
check((int) $db->fetchOne("SELECT COUNT(*) FROM ai_favorites") === 1, "Favoris sans doublons");
$session->set("user_id", 2);
rejected(fn() => $assistant->run((int) $run["id"]), 404);
rejected(fn() => $assistant->favorite((int) $run["id"], 0), 404);
rejected(fn() => $assistant->addSession((int) $run["id"], 0, "2026-11-05", "18:00"), 404);
rejected(
    fn() => $assistant->ask([
        "idea_count" => 1,
        "message" => "Vol historique",
        "parent_id" => $run["id"],
    ]),
    404,
);
$session->set("user_id", 1);
$bad = $fixture;
$bad["sessions"][0]["exercises"][0]["sets"] = 999;
rejected(fn() => $assistant->validateResult($bad));
$bad = $fixture;
$bad["sessions"][0]["muscles"] = ["inconnu"];
rejected(fn() => $assistant->validateResult($bad));
$bad = $fixture;
$bad["recipes"][0]["catalog_id"] = "fake-id";
rejected(fn() => $assistant->validateResult($bad));
$valid = $fixture;
$valid["recipes"][0]["catalog_id"] = "12";
$valid["recipes"][0]["source_url"] = "javascript:alert(1)";
$validated = $assistant->validateResult($valid, [["id" => "12", "name_fr" => "Référence réelle"]]);
check(
    $validated["recipes"][0]["source_title"] === "Référence réelle" &&
        !isset($validated["recipes"][0]["source_url"]),
    "Source attribuée par le serveur uniquement",
);
rejected(fn() => $assistant->ask(["idea_count" => 1, "message" => str_repeat("x", 3001)]), 422);
$limited = new AssistantService($store, $ai, $nutrition, $recipes, $exercises, 1);
rejected(fn() => $limited->ask(["idea_count" => 1, "message" => "Quota"]), 429);
check(
    client(fn() => response(["models" => [["name" => "qwen3:4b"]]]))->status()["ready"],
    "Modèle installé",
);
check(!client(fn() => response(["models" => []]))->status()["ready"], "Modèle absent");
check(
    !client(function () {
        throw new RuntimeException("SECRET");
    })->status()["ready"],
    "Moteur inaccessible",
);
foreach (
    [
        ["done" => false],
        ["done" => true, "done_reason" => "length"],
        ["done" => true, "message" => ["content" => "invalid"]],
    ]
    as $body
) {
    rejected(fn() => client(fn() => response($body))->generate([], [], $muscles));
}
rejected(fn() => client(fn() => response(["error" => "SECRET"], 404))->generate([], [], $muscles));
$cloud = client(function ($method, $url, $options) use ($fixture) {
    $body = json_decode($options["body"], true);
    check(
        $url === "https://api.openai.com/v1/responses" && $body["store"] === false,
        "API cloud facultative et stockage désactivé",
    );
    check($body["text"]["format"]["strict"] === true, "Schéma cloud strict");
    return response([
        "status" => "completed",
        "output" => [["content" => [["type" => "output_text", "text" => json_encode($fixture)]]]],
    ]);
}, "openai");
check(
    $cloud->generate([], [], $muscles)["recipes"][0]["title"] === "Bol de riz",
    "Alternative cloud",
);
rejected(fn() => client(fn() => response([], 429), "openai")->generate([], [], $muscles), 429);
$brief = new \App\Service\TrainingBrief();
foreach (
    [
        "30 min pec et 1h bras",
        "Je veux travailler les pectoraux pendant 30 minutes et les bras pendant 1 heure",
        "30min pecs puis 1h bras",
    ]
    as $question
) {
    $blocks = $brief->parse($question);
    check(
        $blocks === [["group" => "chest", "minutes" => 30], ["group" => "arms", "minutes" => 60]],
        "Muscles et durées : " . $question,
    );
    $plans = $brief->sessions($blocks);
    check(
        $plans[0]["muscles"] === ["chest"] && $plans[1]["muscles"] === ["biceps", "triceps"],
        "Aucun dos à la place des pecs",
    );
    check(
        count($plans[0]["exercises"]) === 3 && count($plans[1]["exercises"]) === 6,
        "Volume adapté 30 et 60 min",
    );
}
check(
    $brief->parse("30 minutes dos et 1 h bras")[0]["group"] === "back",
    "Dos reconnu quand demandé",
);
check(
    $brief->parse("30 min pec", [["group" => "arms", "minutes" => 60]])[0]["group"] === "arms",
    "Bloc explicite prioritaire",
);
rejected(fn() => $brief->parse("", [["group" => "inconnu", "minutes" => 30]]), 422);
$plans = $brief->sessions([["group" => "arms", "minutes" => 60]], "maison avec haltères");
check(!str_contains(json_encode($plans), "poulie"), "Pas de poulie quand haltères à domicile");
rejected(fn() => $brief->parse("30 min pec et bras"), 422);
$homeChest = $brief->sessions([["group" => "chest", "minutes" => 30]], "uniquement des haltères");
check(
    !str_contains(json_encode($homeChest), "poulie"),
    "Matériel haltères respecté sans mot maison",
);
$selection = $recipes->assistantSelection("Repas avec poulet", [], "all");
check(count($selection["recipes"]) === 40, "40 vraies recettes si disponibles");
check(
    count(array_unique(array_column($selection["recipes"], "catalog_id"))) === 40,
    "Recettes uniques",
);
check(
    $selection["recipes"][0]["online"] && $selection["recipes"][0]["minutes"] === null,
    "Provenance réelle, pas de temps inventé",
);
check(
    !$recipes->assistantSelection("Repas sans poulet", [], "all")["recipes"],
    "Exclusion alimentaire respectée",
);
foreach (
    [
        ["200 g riz", 2, "400 g riz"],
        ["1/2 tasse riz", 2, "1 tasse riz"],
        ["1 1/2 tasse riz", 2, "3 tasse riz"],
        ["½ tasse riz", 2, "1 tasse riz"],
        ["2–3 carottes", 2, "4–6 carottes"],
        ["Sel au goût", 2, "Sel au goût"],
        ["0,5 litre", 2, "1 litre"],
    ]
    as [$line, $factor, $expected]
) {
    check(\App\Service\RecipePortions::scale($line, $factor) === $expected, "Portions : " . $line);
}
rejected(fn() => \App\Service\RecipePortions::adjust($run["data"]["recipes"][0], 0, 2), 422);
$assistant->favorite((int) $run["id"], 0, 4, 2);
$favorite = json_decode($db->fetchOne("SELECT payload FROM ai_favorites WHERE user_id=1"), true);
check(
    $favorite["portions"] === 4 && $favorite["ingredients"][0] === "400 g poulet",
    "Portions conservées dans les favoris",
);
check(
    !str_contains($run["data"]["answer"], "<script>") &&
        !str_contains($run["data"]["answer"], "Je dois"),
    "Introduction maîtrisée par application",
);
$db->executeStatement("UPDATE ai_runs SET created_at=datetime('now','-10 seconds')");
$attempts = 0;
$noisyClient = client(function () use (&$attempts, $fixture) {
    $attempts++;
    $raw = $fixture;
    $raw["answer"] = str_repeat("Je dois analyser le contexte JSON. ", 500);
    $raw["sessions"][0]["muscles"] = ["back"];
    $raw["sessions"][0]["duration_minutes"] = 30;
    if ($attempts === 1) {
        $raw["advice"] = ["Je dois analyser le contexte JSON avant de répondre."];
    }
    return response(["done" => true, "message" => ["content" => json_encode($raw)]]);
});
$strictAssistant = new AssistantService(
    $store,
    $noisyClient,
    $nutrition,
    $recipes,
    $exercises,
    100,
);
$strictRun = $strictAssistant->ask([
    "idea_count" => 1,
    "message" => "30 min pec et 1h bras, avec des repas",
]);
check($attempts === 2, "Une correction automatique du format, sans afficher le raisonnement");
check(
    !str_contains(json_encode($strictRun["data"]), "Je dois"),
    "Aucun texte de raisonnement enregistré",
);
check(
    $strictRun["data"]["sessions"][0]["muscles"] === ["chest"] &&
        $strictRun["data"]["sessions"][1]["duration_minutes"] === 60,
    "Une réponse hors sujet du modèle ne remplace pas les blocs demandés",
);
check(
    count($strictRun["data"]["recipes"]) === 40,
    "Recettes Internet indépendantes du texte du modèle",
);
$upperQuestion =
    "Prépare-moi 3 séances haut du corps de 45 minutes avec des haltères. Je reprends progressivement.";
$upperBlocks = $brief->parse($upperQuestion);
check(count($upperBlocks) === 3, "Trois séances haut du corps reconnues");
$upperPlans = $brief->sessions($upperBlocks, $upperQuestion);
check(
    count($upperPlans) === 3 && array_column($upperPlans, "duration_minutes") === [45, 45, 45],
    "Trois blocs de 45 minutes",
);
foreach ($upperPlans as $plan) {
    check(
        in_array("chest", $plan["muscles"], true) && in_array("back", $plan["muscles"], true),
        "Haut du corps complet",
    );
    check(
        !preg_match("/poulie|machine|banc/i", json_encode($plan)),
        "Haltères seuls, pas de machine ou banc inventés",
    );
}
$shortList = $fixture;
$shortList["sessions"][0]["exercises"] = [$fixture["sessions"][0]["exercises"][0]];
check(
    count($assistant->validateResult($shortList)["sessions"][0]["exercises"]) === 1,
    "Une liste courte valide ne rejette plus toute la réponse",
);
$emptyList = $fixture;
$emptyList["sessions"][0]["exercises"] = [];
rejected(fn() => $assistant->validateResult($emptyList));
check(
    count($brief->parse("Trois séances bas du corps de 45 min")) === 3,
    "Quantité en lettres et bas du corps",
);
rejected(fn() => $brief->parse("7 séances haut du corps de 45 min"), 422);
$db->executeStatement("UPDATE ai_runs SET created_at=datetime('now','-10 seconds')");
$upperRun = $strictAssistant->ask(["idea_count" => 1, "message" => $upperQuestion]);
check(
    count($upperRun["data"]["sessions"]) === 3 &&
        $upperRun["data"]["sessions"][0]["duration_minutes"] === 45,
    "Parcours complet : exemple de la page accepté",
);
$db->delete("ai_runs", ["user_id" => 1]);
check(
    (int) $db->fetchOne("SELECT COUNT(*) FROM ai_favorites") === 1 &&
        (int) $db->fetchOne("SELECT COUNT(*) FROM training_sessions") === 1,
    "Effacer historique conserve favoris et planning",
);
$db->executeStatement("UPDATE ai_runs SET created_at=datetime('now','-10 seconds')");
$alternativeRun = $strictAssistant->ask(["message" => "45 min haut du corps avec des haltères"]);
check(
    $alternativeRun["data"]["session_ideas"] === 5 &&
        count($alternativeRun["data"]["sessions"]) === 5,
    "Assistant : cinq alternatives par défaut",
);
$firstChoice = $strictAssistant->addSession((int) $alternativeRun["id"], 0, "2026-11-06", "18:00");
$fifthChoice = $strictAssistant->addSession((int) $alternativeRun["id"], 4, "2026-11-07", "18:00");
check($firstChoice !== $fifthChoice, "Chaque proposition peut être ajoutée indépendamment");
check(
    $firstChoice ===
        $strictAssistant->addSession((int) $alternativeRun["id"], 0, "2026-11-06", "18:00"),
    "Ajouter à nouveau la même idée reste idempotent",
);
check(
    (int) $db->fetchOne("SELECT COUNT(*) FROM workouts") === 2,
    "Les alternatives ne créent pas de performances fictives",
);
$db->executeStatement("UPDATE ai_runs SET created_at=datetime('now','-10 seconds')");
$mixedRun = $strictAssistant->ask([
    "message" => "Triceps, pectoraux et épaules ensemble",
    "topic" => "sessions",
    "training_blocks" => [
        ["group" => "mixed", "muscles" => ["triceps", "chest", "shoulders"], "minutes" => 45],
    ],
]);
check(
    $mixedRun["data"]["session_ideas"] === 5,
    "Assistant : cinq alternatives pour une combinaison libre",
);
foreach ($mixedRun["data"]["sessions"] as $session) {
    check(
        $session["muscles"] === ["triceps", "chest", "shoulders"] &&
            $session["duration_minutes"] === 45,
        "Combinaison et durée conservées de bout en bout",
    );
}
$db->close();
function cleanup(string $path): void
{
    foreach (scandir($path) as $name) {
        if ($name === "." || $name === "..") {
            continue;
        }
        $item = $path . "/" . $name;
        is_dir($item) ? cleanup($item) : unlink($item);
    }
    rmdir($path);
}
cleanup($dir);
echo "$count vérifications IA réussies (réponses simulées, aucun modèle réel).\n";
