<?php

namespace App\Service;

/** Explicit muscle/time blocks are resolved by the app, never guessed by a small model. */
final class TrainingBrief
{
    private const GROUPS = [
        "forearms" => "avant bras",
        "traps" => "trapezes?",
        "obliques" => "obliques?",
        "lowerback" => "lombaires?",
        "quads" => "quadriceps",
        "hamstrings" => "ischio jambiers?|ischios?",
        "glutes" => "fessiers?",
        "adductors" => "adducteurs?",
        "abductors" => "abducteurs?",
        "calves" => "mollets?",
        "serratus" => "dentele anterieur",
        "neck" => "cou",
        "cardio" => "cardio",

        "upper" => "haut du corps|upper(?: body)?",
        "lower" => "bas du corps|lower(?: body)?",
        "full" => "full body|corps entier|tout le corps",
        "chest" => "pecs?|pectoraux|poitrine",
        "back" => "dos|dorsaux",
        "arms" => "bras",
        "biceps" => "biceps",
        "triceps" => "triceps",
        "shoulders" => "epaules?",
        "legs" => "jambes?",
        "abs" => "abdos?|abdominaux|gainage",
    ];
    private const MUSCLES = [
        "forearms" => ["forearms"],
        "traps" => ["traps"],
        "obliques" => ["obliques"],
        "lowerback" => ["lowerback"],
        "quads" => ["quads"],
        "hamstrings" => ["hamstrings"],
        "glutes" => ["glutes"],
        "adductors" => ["adductors"],
        "abductors" => ["abductors"],
        "calves" => ["calves"],
        "serratus" => ["serratus"],
        "neck" => ["neck"],
        "cardio" => ["cardio"],

        "upper" => ["chest", "back", "shoulders", "biceps", "triceps"],
        "lower" => ["quads", "hamstrings", "glutes", "calves"],
        "full" => ["chest", "back", "shoulders", "quads", "hamstrings", "glutes", "abs"],
        "chest" => ["chest"],
        "back" => ["back"],
        "arms" => ["biceps", "triceps"],
        "biceps" => ["biceps"],
        "triceps" => ["triceps"],
        "shoulders" => ["shoulders"],
        "legs" => ["quads", "hamstrings", "glutes", "calves"],
        "abs" => ["abs"],
    ];
    private const LABELS = [
        "forearms" => "Avant-bras",
        "traps" => "Trapèzes",
        "obliques" => "Obliques",
        "lowerback" => "Lombaires",
        "quads" => "Quadriceps",
        "hamstrings" => "Ischio-jambiers",
        "glutes" => "Fessiers",
        "adductors" => "Adducteurs",
        "abductors" => "Abducteurs",
        "calves" => "Mollets",
        "serratus" => "Dentelé antérieur",
        "neck" => "Cou",
        "cardio" => "Cardio",

        "upper" => "Haut du corps",
        "lower" => "Bas du corps",
        "full" => "Full body",
        "chest" => "Pectoraux",
        "back" => "Dos",
        "arms" => "Bras : biceps et triceps",
        "biceps" => "Biceps",
        "triceps" => "Triceps",
        "shoulders" => "Épaules",
        "legs" => "Jambes",
        "abs" => "Abdominaux",
    ];
    private const BANK = [
        "upper" => [
            "Développé couché avec haltères|Pompes inclinées",
            "Rowing avec haltères|Rowing avec élastique",
            "Développé épaules avec haltères|Développé avec élastique",
            "Curl avec haltères|Curl avec élastique",
            "Extension triceps à la poulie|Extension triceps avec élastique",
            "Tirage horizontal à la poulie|Tirage assis avec élastique",
        ],
        "full" => [
            "Squat goblet|Squat au poids du corps",
            "Développé couché avec haltères|Pompes inclinées",
            "Rowing avec haltères|Rowing avec élastique",
            "Soulevé de terre roumain avec haltères|Charnière de hanche au poids du corps",
            "Développé épaules avec haltères|Développé avec élastique",
            "Dead bug|Dead bug",
        ],
        "lower" => [
            "Squat goblet|Squat au poids du corps",
            "Soulevé de terre roumain avec haltères|Charnière de hanche au poids du corps",
            "Fentes arrière|Fentes arrière au poids du corps",
            "Hip thrust|Pont fessier au sol",
            "Leg curl|Flexion des jambes avec serviette au sol",
            "Mollets debout|Mollets debout au poids du corps",
        ],
        "chest" => [
            "Développé couché avec haltères|Pompes inclinées",
            "Développé incliné avec haltères|Pompes classiques",
            "Écartés à la poulie|Pompes à amplitude confortable",
            "Presse pectorale|Pompes avec pause",
            "Écartés inclinés légers|Pompes lentes",
            "Presse convergente|Pompes sur les genoux",
        ],
        "back" => [
            "Tirage vertical à la poulie|Tirage avec élastique",
            "Rowing avec haltères|Rowing avec élastique",
            "Tirage horizontal à la poulie|Tirage assis avec élastique",
            "Rowing unilatéral avec haltère|Rowing unilatéral avec élastique",
            "Pull-over à la poulie|Pull-over avec élastique",
            "Rowing poitrine sur banc|Rowing avec élastique en prise neutre",
        ],
        "arms" => [
            "Curl avec haltères|Curl avec élastique",
            "Extension triceps à la poulie|Extension triceps avec élastique",
            "Curl marteau|Curl marteau avec élastique",
            "Extension triceps au-dessus de la tête avec haltère|Extension triceps au-dessus de la tête avec élastique",
            "Curl incliné avec haltères|Curl unilatéral avec élastique",
            "Extension triceps à la corde|Pompes prise serrée inclinées",
        ],
        "biceps" => [
            "Curl avec haltères|Curl avec élastique",
            "Curl marteau|Curl marteau avec élastique",
            "Curl incliné avec haltères|Curl unilatéral avec élastique",
            "Curl au pupitre|Curl concentré avec élastique",
            "Curl à la poulie|Curl avec élastique en prise basse",
            "Curl concentré|Curl avec élastique lent",
        ],
        "triceps" => [
            "Extension à la poulie|Extension avec élastique",
            "Extension au-dessus de la tête avec haltère|Extension au-dessus de la tête avec élastique",
            "Extension à la corde|Pompes prise serrée inclinées",
            "Extension unilatérale à la poulie|Extension unilatérale avec élastique",
            "Extension couchée avec haltères|Pompes serrées sur les genoux",
            "Extension à la poulie en prise inversée|Extension avec élastique lente",
        ],
        "shoulders" => [
            "Développé épaules avec haltères|Développé avec élastique",
            "Élévations latérales|Élévations latérales avec élastique",
            "Oiseau avec haltères|Écartés inversés avec élastique",
            "Élévations latérales à la poulie|Élévations unilatérales avec élastique",
            "Oiseau sur banc|Écartés inversés en prise basse",
            "Développé assis léger|Développé avec élastique léger",
        ],
        "legs" => [
            "Squat goblet|Squat au poids du corps",
            "Soulevé de terre roumain avec haltères|Charnière de hanche au poids du corps",
            "Fentes arrière|Fentes arrière au poids du corps",
            "Hip thrust|Pont fessier au sol",
            "Leg curl|Flexion des jambes avec serviette au sol",
            "Mollets debout|Mollets debout au poids du corps",
        ],
        "abs" => [
            "Dead bug|Dead bug",
            "Planche courte|Planche courte",
            "Crunch contrôlé|Crunch contrôlé",
            "Bird dog|Bird dog",
            "Crunch inversé|Crunch inversé",
            "Planche latérale courte|Planche latérale courte",
        ],
    ];

    public function parse(string $message, array $explicit = []): array
    {
        if ($explicit) {
            if (count($explicit) > 6) {
                throw new AiException("Six blocs maximum.", 422);
            }
            return array_map(function ($block) {
                $group = $block["group"] ?? "";
                $minutes = $block["minutes"] ?? null;
                if ($group === "mixed") {
                    $muscles = $block["muscles"] ?? [];
                    if (
                        !is_array($muscles) ||
                        !array_is_list($muscles) ||
                        !$muscles ||
                        count($muscles) > 8 ||
                        !is_int($minutes) ||
                        $minutes < 10 ||
                        $minutes > 120
                    ) {
                        throw new AiException(
                            "Choisis de 1 à 8 groupes et une durée de 10 à 120 minutes.",
                            422,
                        );
                    }
                    foreach ($muscles as $muscle) {
                        if (
                            !is_string($muscle) ||
                            !isset(self::MUSCLES[$muscle]) ||
                            self::MUSCLES[$muscle] !== [$muscle]
                        ) {
                            throw new AiException("Groupe musculaire non reconnu.", 422);
                        }
                    }
                    $muscles = array_values(array_unique($muscles));
                    if ($minutes < count($muscles) * 5) {
                        throw new AiException(
                            "Prévois au moins 5 minutes par groupe sélectionné ou réduis le nombre de groupes.",
                            422,
                        );
                    }
                    return ["group" => "mixed", "muscles" => $muscles, "minutes" => $minutes];
                }
                if (
                    !isset(self::MUSCLES[$group]) ||
                    !is_int($minutes) ||
                    $minutes < 10 ||
                    $minutes > 120
                ) {
                    throw new AiException(
                        "Chaque bloc doit indiquer un groupe et une durée de 10 à 120 minutes.",
                        422,
                    );
                }
                return ["group" => $group, "minutes" => $minutes];
            }, $explicit);
        }
        $text = preg_replace(
            "/(?<=\d)(?=[a-z])|(?<=[a-z])(?=\d)/",
            " ",
            RemoteCache::normalize($message),
        );
        if (
            str_contains($message, "+") &&
            preg_match_all(
                "/\b(\d{1,3})\s*(heures?|h|minutes?|min)\b(?:\s*(\d{1,2}))?/",
                $text,
                $times,
                PREG_SET_ORDER,
            ) === 1
        ) {
            $groups = [];
            foreach (self::GROUPS as $key => $pattern) {
                if (preg_match("/\b(?:" . $pattern . ")\b/", $text)) {
                    $groups[] = $key;
                }
            }
            if (in_array("forearms", $groups, true)) {
                $groups = array_values(array_diff($groups, ["arms"]));
            }
            $muscles = [];
            foreach ($groups as $group) {
                $muscles = array_merge($muscles, self::MUSCLES[$group]);
            }
            $muscles = array_values(array_unique($muscles));
            if (count($muscles) > 1) {
                $minutes = (int) $times[0][1] * (str_starts_with($times[0][2], "h") ? 60 : 1);
                if (str_starts_with($times[0][2], "h")) {
                    $minutes += (int) ($times[0][3] ?? 0);
                }
                $copies = preg_match("/\b([1-6])\s+seances?\b/", $text, $quantity)
                    ? (int) $quantity[1]
                    : 1;
                return $this->parse(
                    "",
                    array_fill(0, $copies, [
                        "group" => "mixed",
                        "muscles" => $muscles,
                        "minutes" => $minutes,
                    ]),
                );
            }
        }
        // Keep clauses together: “dos pendant 30 minutes et bras pendant 1 h”.
        $clauses = preg_split("/\b(?:et|puis|ensuite)\b/", $text);
        $out = [];
        $unresolved = false;
        foreach ($clauses as $clause) {
            $groups = [];
            foreach (self::GROUPS as $key => $pattern) {
                if (
                    preg_match("/\b(?:" . $pattern . ")\b/", $clause, $match, PREG_OFFSET_CAPTURE)
                ) {
                    $prefix = substr($clause, max(0, $match[0][1] - 12), min(12, $match[0][1]));
                    if (preg_match('/(?:pas de|sans|eviter)\s*$/', $prefix)) {
                        continue;
                    }
                    $groups[] = $key;
                }
            }
            if (in_array("forearms", $groups, true)) {
                $groups = array_values(array_diff($groups, ["arms"]));
            }
            if (!$groups) {
                continue;
            }
            if (
                !preg_match(
                    "/\b(\d{1,3})\s*(heures?|h|minutes?|min)\b(?:\s*(\d{1,2}))?/",
                    $clause,
                    $time,
                )
            ) {
                $unresolved = true;
                continue;
            }
            $minutes = (int) $time[1] * (str_starts_with($time[2], "h") ? 60 : 1);
            if (str_starts_with($time[2], "h")) {
                $minutes += (int) ($time[3] ?? 0);
            }
            if ($minutes < 10 || $minutes > 120) {
                throw new AiException("Choisis des blocs de 10 à 120 minutes.", 422);
            }
            // A combined clause shares its duration instead of doubling it.
            if (count($groups) > 1) {
                throw new AiException(
                    "Précise la durée de chaque groupe dans les blocs de séance pour éviter une répartition ambiguë.",
                    422,
                );
            }
            $copies = 1;
            if (
                preg_match(
                    "/\b(\d+|une|deux|trois|quatre|cinq|six)\s+seances?\b/",
                    $clause,
                    $quantity,
                )
            ) {
                $copies = is_numeric($quantity[1])
                    ? (int) $quantity[1]
                    : [
                        "une" => 1,
                        "deux" => 2,
                        "trois" => 3,
                        "quatre" => 4,
                        "cinq" => 5,
                        "six" => 6,
                    ][$quantity[1]];
                if ($copies < 1 || $copies > 6) {
                    throw new AiException("Demande de une à six séances à la fois.", 422);
                }
            }
            for ($copy = 0; $copy < $copies; $copy++) {
                $out[] = ["group" => $groups[0], "minutes" => $minutes];
            }
        }
        if ($unresolved && $out) {
            throw new AiException(
                "Un groupe musculaire n’a pas de durée précise. Complète les blocs de séance pour chaque groupe.",
                422,
            );
        }
        if (count($out) > 6) {
            throw new AiException(
                "Six séances maximum par demande ; répartis la suite dans une autre demande.",
                422,
            );
        }
        return $out;
    }

    public function sessions(
        array $blocks,
        string $constraints = "",
        array $catalog = [],
        int $variant = 0,
    ): array {
        $text = RemoteCache::normalize($constraints);
        $home = preg_match(
            "/\b(maison|sans machine|elastiques?|halteres?|poids du corps)\b/",
            $text,
        );
        // No invented available equipment: bodyweight-only arms/back need clarification.
        if ($home && !str_contains($text, "elastique") && !str_contains($text, "haltere")) {
            foreach ($blocks as $block) {
                if (
                    in_array(
                        $block["group"],
                        ["back", "arms", "biceps", "triceps", "shoulders", "upper", "full"],
                        true,
                    )
                ) {
                    throw new AiException(
                        "Pour ce programme à domicile, précise si tu disposes d’haltères ou d’un élastique, ou choisis les exercices avec ton matériel.",
                        422,
                    );
                }
            }
        }
        $out = [];
        foreach ($blocks as $sessionIndex => $block) {
            $group = $block["group"];
            $minutes = $block["minutes"];
            if ($group === "mixed") {
                $muscles = $block["muscles"];
                $targetCount = max(count($muscles), min(8, (int) ceil($minutes / 10)));
                $pools = [];
                foreach ($muscles as $muscle) {
                    $pools[$muscle] = $this->sessions(
                        [["group" => $muscle, "minutes" => $minutes]],
                        $constraints,
                        $catalog,
                        $variant + $sessionIndex,
                    )[0];
                }
                $items = [];
                $used = [];
                for ($round = 0; $round < 12 && count($items) < $targetCount; $round++) {
                    foreach ($muscles as $muscle) {
                        $item = $pools[$muscle]["exercises"][$round] ?? null;
                        if (!$item || isset($used[RemoteCache::normalize($item["name"])])) {
                            continue;
                        }
                        $used[RemoteCache::normalize($item["name"])] = true;
                        $items[] = $item;
                        if (count($items) === $targetCount) {
                            break;
                        }
                    }
                }
                $sets = $minutes < 25 ? 2 : 3;
                $workSeconds =
                    ($minutes -
                        min(5, (int) floor($minutes / 5)) -
                        min(3, (int) floor($minutes / 10))) *
                    60;
                foreach ($items as &$item) {
                    $item["sets"] = $sets;
                    $item["rest_seconds"] = max(
                        30,
                        min(
                            120,
                            (int) floor(
                                ($workSeconds / count($items) - 30 - $sets * 40) /
                                    max(1, $sets - 1) /
                                    15,
                            ) * 15,
                        ),
                    );
                }
                unset($item);
                foreach ($muscles as $muscle) {
                    if (!$pools[$muscle]["exercises"]) {
                        throw new AiException(
                            "Un groupe sélectionné ne possède aucun exercice compatible.",
                            422,
                        );
                    }
                }
                $session = reset($pools);
                $session["title"] =
                    implode(
                        " + ",
                        array_map(static fn($muscle) => self::LABELS[$muscle], $muscles),
                    ) .
                    " · " .
                    $minutes .
                    " min" .
                    (count($blocks) > 1 ? " · séance " . ($sessionIndex + 1) : "");
                $session["muscles"] = $muscles;
                $session["exercises"] = $items;
                $session["reason"] =
                    "Une séance combinée pour les groupes sélectionnés, dans la durée totale choisie.";
                $session["notes"] =
                    "Durée totale : " .
                    $minutes .
                    " min, échauffement et repos compris. Alterne les groupes et adapte le nombre de séries au temps restant ; les durées ne s’additionnent pas par muscle.";
                $out[] = $session;
                continue;
            }

            $count = max(2, min(6, (int) ceil($minutes / 10)));
            if ($variant > 0 && $count === 6) {
                $count = 5;
            }
            $warmup = min(5, (int) floor($minutes / 5));
            $cooldown = min(3, (int) floor($minutes / 10));
            $work = $minutes - $warmup - $cooldown;
            $items = [];
            $bank = self::BANK[$group] ?? [];
            if (!$bank) {
                foreach ($catalog as $candidate) {
                    if (($candidate["muscle"] ?? "") !== $group) {
                        continue;
                    }
                    $eq = RemoteCache::normalize(
                        is_array($candidate["equipment"] ?? "")
                            ? implode(" ", $candidate["equipment"])
                            : (string) ($candidate["equipment"] ?? ""),
                    );
                    if (str_contains($text, "haltere") && !preg_match("/dumbbell|haltere/", $eq)) {
                        continue;
                    }
                    if (str_contains($text, "elastique") && !preg_match("/band|elastique/", $eq)) {
                        continue;
                    }
                    if (
                        str_contains($text, "sans materiel") &&
                        !preg_match("/body|poids du corps|none/", $eq)
                    ) {
                        continue;
                    }
                    $bank[] = $candidate["name"] . "|" . $candidate["name"];
                }
                if (!$bank) {
                    throw new AiException(
                        "Les exercices de ce groupe ne sont pas encore disponibles. Ouvre le catalogue Exercices pour charger sa source puis réessaie.",
                        422,
                    );
                }
            }
            $shift = $variant % count($bank);
            $bank = array_merge(array_slice($bank, $shift), array_slice($bank, 0, $shift));
            foreach (array_slice($bank, 0, $count) as $name) {
                $choices = explode("|", $name);
                $bodyweight = in_array($group, ["abs", "legs", "lower", "chest"], true) && $home;
                $exercise =
                    $choices[$bodyweight || ($home && str_contains($text, "elastique")) ? 1 : 0];
                if ($home && str_contains($text, "haltere") && !str_contains($text, "elastique")) {
                    $dumbbells = [
                        "upper" => [
                            "Développé couché au sol avec haltères",
                            "Rowing unilatéral avec haltère",
                            "Développé épaules avec haltères",
                            "Curl alterné avec haltères",
                            "Extension triceps au-dessus de la tête avec haltère",
                            "Rowing avec haltères en prise neutre",
                        ],
                        "full" => [
                            "Squat goblet avec haltère",
                            "Développé couché au sol avec haltères",
                            "Rowing unilatéral avec haltère",
                            "Soulevé de terre roumain avec haltères",
                            "Développé épaules avec haltères",
                            "Dead bug",
                        ],
                        "lower" => [
                            "Squat goblet avec haltère",
                            "Soulevé de terre roumain avec haltères",
                            "Fentes arrière avec haltères",
                            "Pont fessier au sol",
                            "Flexion des jambes avec serviette au sol",
                            "Mollets debout avec haltères",
                        ],
                        "chest" => [
                            "Développé couché au sol avec haltères",
                            "Développé avec haltères en prise neutre au sol",
                            "Squeeze press avec haltères au sol",
                            "Développé unilatéral au sol avec haltère",
                            "Pompes inclinées",
                            "Pompes classiques",
                        ],
                        "legs" => [
                            "Squat goblet avec haltère",
                            "Soulevé de terre roumain avec haltères",
                            "Fentes arrière avec haltères",
                            "Pont fessier au sol",
                            "Flexion des jambes avec serviette au sol",
                            "Mollets debout avec haltères",
                        ],

                        "back" => [
                            "Rowing avec haltères",
                            "Rowing unilatéral avec haltère",
                            "Rowing poitrine sur banc avec haltères",
                            "Rowing avec haltères en prise neutre",
                            "Pull-over avec haltère léger",
                            "Rowing avec haltères avec pause",
                        ],
                        "arms" => [
                            "Curl avec haltères",
                            "Extension triceps avec haltère au-dessus de la tête",
                            "Curl marteau",
                            "Extension triceps couchée avec haltères",
                            "Curl concentré",
                            "Kickback triceps léger avec haltère",
                        ],
                        "triceps" => [
                            "Extension triceps avec haltère au-dessus de la tête",
                            "Extension triceps couchée avec haltères",
                            "Kickback triceps avec haltère",
                            "Extension triceps unilatérale avec haltère",
                            "Extension triceps assise avec haltère",
                            "Pompes prise serrée inclinées",
                        ],
                        "biceps" => [
                            "Curl avec haltères",
                            "Curl marteau",
                            "Curl concentré",
                            "Curl alterné",
                            "Curl prise neutre avec pause",
                            "Curl avec haltères lent",
                        ],
                        "shoulders" => [
                            "Développé avec haltères",
                            "Élévations latérales avec haltères",
                            "Oiseau avec haltères",
                            "Élévations latérales unilatérales",
                            "Oiseau assis avec haltères",
                            "Développé léger avec haltères",
                        ],
                    ];
                    if (isset($dumbbells[$group])) {
                        $exercise =
                            $dumbbells[$group][
                                (count($items) + $variant) % count($dumbbells[$group])
                            ];
                        if (in_array($group, ["upper", "full"], true)) {
                            $roles =
                                $group === "upper"
                                    ? ["chest", "back", "shoulders", "biceps", "triceps", "back"]
                                    : ["legs", "chest", "back", "legs", "shoulders", "abs"];
                            $role = $roles[count($items)];
                            if (isset($dumbbells[$role])) {
                                $exercise =
                                    $dumbbells[$role][
                                        ($variant + (int) floor(count($items) / 5)) %
                                            count($dumbbells[$role])
                                    ];
                            }
                        }
                    }
                }
                $sets = $minutes < 20 ? 2 : 3;
                $rest = max(
                    30,
                    min(
                        120,
                        (int) floor(
                            (($work * 60) / $count - 30 - $sets * 40) / max(1, $sets - 1) / 15,
                        ) * 15,
                    ),
                );
                $items[] = [
                    "name" => $exercise,
                    "sets" => $sets,
                    "reps" => 10,
                    "weight" => null,
                    "rest_seconds" => $rest,
                    "instructions" =>
                        "Mouvement contrôlé, amplitude confortable ; adapte la charge et arrête en cas de douleur. " .
                        (str_contains(mb_strtolower($exercise), "planche")
                            ? "Pour le gainage : 10 = 10 secondes par maintien."
                            : ""),
                ];
            }
            $used = [];
            $targets = self::MUSCLES[$group];
            foreach ($items as $itemIndex => &$item) {
                $target = $targets[$itemIndex % count($targets)];
                if ($variant > 0 && ($itemIndex + $variant) % 3 === 0) {
                    continue;
                }
                $targetPool = array_values(
                    array_filter(
                        $catalog,
                        static fn($candidate) => ($candidate["muscle"] ?? "") === $target,
                    ),
                );
                $offset = $targetPool ? $variant % count($targetPool) : 0;
                $targetPool = array_merge(
                    array_slice($targetPool, $offset),
                    array_slice($targetPool, 0, $offset),
                );
                foreach ($targetPool as $candidate) {
                    if (
                        ($candidate["muscle"] ?? "") !== $target ||
                        isset($used[$candidate["name"]])
                    ) {
                        continue;
                    }
                    if (
                        preg_match("/debut|reprend/", $text) &&
                        ($candidate["difficulty"] ?? "") === "advanced"
                    ) {
                        continue;
                    }
                    $equipment = RemoteCache::normalize(
                        is_array($candidate["equipment"] ?? "")
                            ? implode(" ", $candidate["equipment"])
                            : (string) ($candidate["equipment"] ?? ""),
                    );
                    if (
                        str_contains($text, "haltere") &&
                        !preg_match("/dumbbell|haltere/", $equipment)
                    ) {
                        continue;
                    }
                    if (
                        str_contains($text, "elastique") &&
                        !preg_match("/band|elastique/", $equipment)
                    ) {
                        continue;
                    }
                    if (
                        str_contains($text, "sans materiel") &&
                        !preg_match("/body|poids du corps|none/", $equipment)
                    ) {
                        continue;
                    }
                    $item["name"] = $candidate["name"];
                    $item["catalog_id"] = $candidate["id"] ?? null;
                    $item["instructions"] .=
                        " Exercice issu du catalogue Exercices ; consulte sa fiche pour la technique.";
                    $used[$candidate["name"]] = true;
                    break;
                }
            }
            unset($item);
            $uniqueItems = [];
            foreach ($items as $item) {
                $uniqueItems[RemoteCache::normalize($item["name"])] = $item;
            }
            $items = array_values($uniqueItems);
            if ($sessionIndex % 2) {
                $items = array_reverse($items);
            } elseif ($sessionIndex % 3 === 2) {
                $items = array_merge(array_slice($items, 1), [$items[0]]);
            }
            if ($sessionIndex % 3 === 1) {
                foreach ($items as &$item) {
                    $item["reps"] = 12;
                    $item["instructions"] .=
                        " Variante de répétitions : ajuste la charge au nombre prévu.";
                }
                unset($item);
            }
            $out[] = [
                "title" =>
                    self::LABELS[$group] .
                    " · " .
                    $minutes .
                    " min" .
                    (count($blocks) > 1 ? " · séance " . ($sessionIndex + 1) : ""),
                "reason" => "Bloc construit à partir des muscles et de la durée de ta demande.",
                "duration_minutes" => $minutes,
                "muscles" => self::MUSCLES[$group],
                "notes" =>
                    "Échauffement : $warmup min. Exercices et transitions : $work min. Retour au calme : $cooldown min. Durée cible incluant les repos ; adapte le nombre de séries au temps restant. " .
                    ($home
                        ? "Variante domicile selon le matériel précisé."
                        : "Variante salle : machines/haltères à confirmer selon ton équipement."),
                "exercises" => $items,
            ];
        }
        return $out;
    }
}
