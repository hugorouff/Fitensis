<?php
require dirname(__DIR__) . "/vendor/autoload.php";
use App\Service\{GymStore, PerformanceStats};
use Symfony\Component\HttpFoundation\{Request, RequestStack};
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
$n = 0;
function ok(bool $value, string $label): void
{
    global $n;
    if (!$value) {
        throw new RuntimeException($label);
    }
    $n++;
}
$dir = sys_get_temp_dir() . "/gym-stats-" . bin2hex(random_bytes(6));
mkdir($dir);
$stack = new RequestStack();
$request = new Request();
$session = new Session(new MockArraySessionStorage());
$request->setSession($session);
$stack->push($request);
$store = new GymStore($dir, "sqlite:///" . $dir . "/stats.db", $stack);
$store->init();
$stats = new PerformanceStats($store);
$rows = [
    [
        "date" => "2026-10-04",
        "exercise" => "Squat",
        "sets" => 3,
        "reps" => 10,
        "weight" => 20,
    ],
    [
        "date" => "2026-10-04",
        "exercise" => "squat",
        "sets" => 2,
        "reps" => 8,
        "weight" => 25,
    ],
    [
        "date" => "2026-10-05",
        "exercise" => "Squat",
        "sets" => 4,
        "reps" => 10,
        "weight" => 30,
    ],
    [
        "date" => "2026-10-26",
        "exercise" => "Curl",
        "sets" => 2,
        "reps" => 35,
        "weight" => 5,
    ],
];
$d = $stats->build(
    $rows,
    [
        ["date" => "2026-10-04", "weight" => 75],
        ["date" => "2026-10-04", "weight" => 74.5],
    ],
    "SQUAT",
);
ok($d["total_days"] === 3, "Les jours ne sont pas les lignes");
ok($d["total_exercises"] === 2, "Casse regroupée");
ok($d["total_sets"] === 11, "Séries totales");
ok($d["total_volume"] == 2550, "Volume total");
ok($d["frequency"][0]["days"] === 2, "Fréquence distincte");
ok($d["history"][0]["weight"] === 25.0, "Maximum du jour");
ok($d["history"][0]["volume"] === 1000.0, "Volume du jour");
ok($d["history"][0]["reps"] === 46, "Répétitions cumulées");
ok(count($d["weekly"]) === 5, "Semaines du dimanche au lundi");
ok($d["weekly"][2]["days"] === 0, "Semaine vide conservée");
ok($d["body"][0]["weight"] === 74.5, "Dernière mesure du jour");
$l = $stats->line($d["history"], "weight", "kg");
ok($l["change"] === 5.0, "Écart entre dates");
ok(count($l["points"]) === 2, "Points progression");
$curl = $stats->build($rows, [], "Curl");
ok($curl["history"][0]["rm"] === null, "Pas de 1RM hors plage");
ok(
    !$stats->line($curl["history"], "rm", "kg")["points"],
    "Pas de zéro inventé",
);
$gap = $stats->line(
    [
        ["date" => "2026-01-01", "rm" => 10],
        ["date" => "2026-01-02", "rm" => null],
        ["date" => "2026-01-03", "rm" => 12],
    ],
    "rm",
    "kg",
);
ok(count($gap["segments"]) === 2, "Courbe interrompue");
$zero = $stats->line([["date" => "2026-01-01", "weight" => 0]], "weight", "kg");
ok(
    $zero["points"][0]["x"] === 300.0 && is_finite($zero["points"][0]["y"]),
    "Un point à zéro valide",
);
ok(!$stats->build([], [])["weekly"], "Historique vide");
ok(
    $stats->build($rows, [], "introuvable")["exercise"] === "Curl",
    "Exercice invalide sans données étrangères",
);
foreach ([1, 2] as $uid) {
    $store
        ->db()
        ->insert("users", [
            "id" => $uid,
            "name" => "Test",
            "email" => "stats$uid@example.org",
            "password" => "unused",
            "role" => "member",
        ]);
}
$session->set("user_id", 1);
foreach ([1, 2] as $uid) {
    $store
        ->db()
        ->insert("workouts", [
            "user_id" => $uid,
            "date" => (new DateTimeImmutable("today"))->format("Y-m-d"),
            "exercise" => $uid === 1 ? "Own" : "PRIVATE",
            "sets" => 3,
            "reps" => 10,
            "weight" => 20,
            "rpe" => 7,
        ]);
}
$store
    ->db()
    ->insert("workouts", [
        "user_id" => 1,
        "date" => "2020-01-01",
        "exercise" => "Old",
        "sets" => 2,
        "reps" => 10,
        "weight" => 10,
        "rpe" => 7,
    ]);
$d = $stats->dashboard("PRIVATE", "30");
ok(
    $d["total_exercises"] === 1 && $d["exercise"] === "Own",
    "Filtre de période et confidentialité",
);
ok(
    $stats->dashboard("", "invalid")["total_exercises"] === 2,
    "Période invalide normalisée",
);
echo "$n contrôles statistiques réussis.\n";
