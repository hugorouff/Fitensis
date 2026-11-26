<?php

namespace App\Service;
class ExerciseCatalog
{
    private array $metadata = [];
    public const SOURCES = [
        "Gym App original" =>
            "https://raw.githubusercontent.com/luisaraujoc/free-exercise-db-api/main/data/exercises.json",
        "Free Exercise DB" =>
            "https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/dist/exercises.json",
    ];
    public function __construct(
        private GymStore $store,
        private Nutrition $nutrition,
        private RemoteCache $cache,
        private Translator $translator,
    ) {}
    public function canonical(mixed $value): ?string
    {
        $raw = mb_strtolower(trim((string) $value));
        $labels = $this->nutrition->catalog("MUSCLE_LABELS");
        $map = $this->nutrition->catalog("TARGET_TO_GROUP");
        if (isset($labels[$raw])) {
            return $raw;
        }
        if (isset($map[$raw])) {
            return $map[$raw];
        }
        if (str_contains($raw, "deltoid") || str_contains($raw, "deltoide")) {
            return "shoulders";
        }
        if (str_contains($raw, "triceps")) {
            return "triceps";
        }
        if (str_contains($raw, "biceps")) {
            return "biceps";
        }
        if (
            preg_match(
                "/forearm|wrist flex|wrist extens|flexor carpi|extensor carpi|^pronator /",
                $raw,
            )
        ) {
            return "forearms";
        }
        return null;
    }

    private function lines(mixed $v): array
    {
        if (is_array($v)) {
            return array_values(array_filter($v, "is_string"));
        }
        return is_string($v) && trim($v) !== "" ? [trim($v)] : [];
    }

    public function frenchName(string $name): string
    {
        $name = mb_strtolower($name);
        foreach ($this->nutrition->catalog("EXERCISE_PHRASES_FR") as [$en, $fr]) {
            $name = str_replace($en, $fr, $name);
        }
        $words = $this->nutrition->catalog("EXERCISE_WORDS_FR");
        return implode(" ", array_map(fn($w) => $words[$w] ?? $w, preg_split("/\s+/", $name)));
    }

    public function normalize(array $e, string $source, string $url): array
    {
        $target = (string) ($e["target"] ?? ($e["primaryMuscles"][0] ?? ($e["muscleGroup"] ?? "")));
        $group = $this->canonical($target);
        $name = (string) ($e["name"] ?? "");
        $part = strtolower($e["bodyPart"] ?? "");
        $mg = $this->canonical($e["muscleGroup"] ?? "");
        if (
            $part === "lower arms" ||
            preg_match(
                "/wrist curl|wrist extension|wrist flexion|wrist circle|forearm curl|reverse wrist/i",
                $name,
            )
        ) {
            $group = "forearms";
        } elseif ($part === "shoulders") {
            $group = $group ?? ($mg ?? "shoulders");
        } else {
            $group ??= $mg;
        }
        $secondary = [];
        foreach ($this->lines($e["secondaryMuscles"] ?? []) as $m) {
            $g = $this->canonical($m);
            if ($g && $g !== $group) {
                $secondary[] = $g;
            }
        }
        $videos = [
            "male" => RemoteCache::safeUrl($e["videos"]["male"] ?? ""),
            "female" => RemoteCache::safeUrl($e["videos"]["female"] ?? ""),
        ];
        $image = RemoteCache::safeUrl($e["gifUrl"] ?? ($e["imageUrl"] ?? ""));
        if (!$image && !empty($e["images"][0])) {
            $image =
                "https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/" .
                implode("/", array_map("rawurlencode", explode("/", $e["images"][0])));
        }
        $thumbs = [
            "male" => RemoteCache::safeUrl($e["thumbnails"]["male"] ?? $image),
            "female" => RemoteCache::safeUrl($e["thumbnails"]["female"] ?? $image),
        ];
        $steps = $this->lines($e["steps"] ?? ($e["instructions"] ?? []));
        $description =
            (string) ($e["shortDescription"] ??
                ($e["description"] ??
                    (is_string($e["instructions"] ?? null) ? $e["instructions"] : "")));
        return [
            "id" => (string) ($e["id"] ?? hash("sha256", $name)),
            "source" => "remote",
            "source_name" => $source,
            "source_url" => $url,
            "name" => $name,
            "name_fr" => $this->frenchName($name),
            "target" => $target,
            "bodyPart" => $e["bodyPart"] ?? $group,
            "primaryGroup" => $group,
            "secondaryGroups" => array_values(array_unique($secondary)),
            "secondaryMuscles" => $this->lines($e["secondaryMuscles"] ?? []),
            "equipment" => $e["equipment"] ?? "Non renseigné",
            "difficulty" => $e["difficulty"] ?? ($e["level"] ?? "intermediate"),
            "shortDescription" => $description,
            "description" => $description,
            "steps" => $steps,
            "formCues" => $this->lines($e["formCues"] ?? []),
            "commonMistakes" => $this->lines($e["commonMistakes"] ?? []),
            "breathing" => (string) ($e["breathing"] ?? ""),
            "compound" => (bool) ($e["compound"] ?? ($e["mechanic"] ?? "") === "compound"),
            "unilateral" => (bool) ($e["unilateral"] ?? false),
            "videos" => $videos,
            "thumbnails" => $thumbs,
            "gifUrl" => $image,
            "imageUrl" => $image,
            "aliases" => $this->lines($e["aliases"] ?? []),
        ];
    }

    public function all(): array
    {
        $data = [];
        $errors = [];
        $staleCandidate = null;
        foreach (self::SOURCES as $name => $url) {
            $r = $this->cache->json($url, [], 900);
            $p = $r["data"];
            $list = is_array($p) ? $p["data"] ?? ($p["exercises"] ?? $p) : [];
            if (!is_array($list) || !array_is_list($list) || !$list) {
                $errors[] = $name . " : réponse indisponible ou vide.";
                continue;
            }
            $items = [];
            foreach ($list as $e) {
                if (is_array($e) && is_string($e["name"] ?? null) && trim($e["name"]) !== "") {
                    $items[] = $this->normalize($e, $name, $url);
                }
            }
            if (!$items) {
                $errors[] = $name . " : format non reconnu.";
                continue;
            }
            $meta = [
                "status" => $r["stale"] ? "stale" : "ok",
                "remote_count" => count($items),
                "source" => $name,
                "source_url" => $url,
                "cached" => $r["cached"],
                "stale" => $r["stale"],
                "cache_ttl_seconds" => 900,
                "errors" => $errors,
            ];
            if ($r["stale"]) {
                $staleCandidate ??= [$items, $meta];
                continue;
            }
            $data = $items;
            $this->metadata = $meta;
            break;
        }
        if (!$data && $staleCandidate) {
            [$data, $this->metadata] = $staleCandidate;
            $this->metadata["errors"] = $errors;
        }
        if (!$data) {
            $data = $this->local();
            $this->metadata = [
                "status" => "degraded",
                "remote_count" => 0,
                "source" => "Catalogue local de secours",
                "source_url" => null,
                "cached" => false,
                "stale" => false,
                "cache_ttl_seconds" => 900,
                "errors" => $errors,
            ];
        }
        if ($data && ($this->metadata["source"] ?? null) === "Gym App original") {
            $url = self::SOURCES["Free Exercise DB"];
            $fallback = $this->cache->json($url, [], 86400);
            $images = [];
            $payload = $fallback["data"];
            $rows = is_array($payload) ? ($payload["data"] ?? $payload["exercises"] ?? $payload) : [];
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (!is_array($row) || !is_string($row["name"] ?? null) || !is_string($row["images"][0] ?? null) || $row["images"][0] === "") {
                    continue;
                }
                $images[RemoteCache::normalize($row["name"])] = "https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/" .
                    implode("/", array_map("rawurlencode", explode("/", $row["images"][0])));
            }
            foreach ($data as &$exercise) {
                $exercise["fallbackImageUrl"] = "";
                foreach ([$exercise["name"], ...$exercise["aliases"]] as $name) {
                    $image = $images[RemoteCache::normalize($name)] ?? "";
                    if ($image !== "") {
                        $exercise["fallbackImageUrl"] = $image;
                        break;
                    }
                }
            }
            unset($exercise);
        }
        $uid = $this->store->user()["id"] ?? null;
        if ($uid) {
            foreach (
                $this->store
                    ->db()
                    ->fetchAllAssociative("SELECT * FROM custom_exercises WHERE user_id=?", [$uid])
                as $row
            ) {
                $data[] = $this->custom($row);
            }
        }
        usort($data, fn($a, $b) => strcasecmp($a["name"], $b["name"]));
        return $data;
    }

    public function status(): array
    {
        if (!$this->metadata) {
            $this->all();
        }
        return $this->metadata;
    }

    /** A compact cached catalogue for the assistant; no user data and no remote fetch. */
    public function cachedSuggestions(int $limit = 40): array
    {
        $items = [];
        foreach (self::SOURCES as $name => $url) {
            $cached = $this->cache->read($url . "?", 900);
            $data = $cached["data"] ?? [];
            $list = $data["data"] ?? ($data["exercises"] ?? $data);
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $row) {
                if (is_array($row) && isset($row["name"])) {
                    $items[] = $this->normalize($row, $name, $url);
                }
            }
            if ($items) {
                break;
            }
        }
        $items = $items ?: $this->local();
        // Keep multiple muscle groups represented rather than the first alphabetic names only.
        $groups = [];
        foreach ($items as $item) {
            $group = $item["primaryGroup"] ?? "other";
            if (count($groups[$group] ?? []) < ($limit >= 120 ? 20 : 3)) {
                $groups[$group][] = [
                    "id" => $item["id"],
                    "name" => $item["name_fr"] ?? $item["name"],
                    "muscle" => $group,
                    "equipment" => $item["equipment"],
                    "difficulty" => $item["difficulty"] ?? "intermediate",
                ];
            }
        }
        $out = [];
        while (count($out) < $limit) {
            $added = false;
            foreach ($groups as &$group) {
                if ($group && count($out) < $limit) {
                    $out[] = array_shift($group);
                    $added = true;
                }
            }
            unset($group);
            if (!$added) {
                break;
            }
        }
        return $out;
    }

    public function details(string $id): ?array
    {
        $found = null;
        foreach ($this->all() as $e) {
            if ($e["id"] === $id) {
                $found = $e;
                break;
            }
        }
        if (!$found) {
            return null;
        }
        if ($found["source"] !== "remote") {
            return $found + ["language" => "fr"];
        }
        $text = [
            "name" => $found["name"],
            "description" => $found["description"],
            "breathing" => $found["breathing"],
        ];
        foreach (["steps" => 8, "formCues" => 5, "commonMistakes" => 5] as $field => $max) {
            foreach (array_slice($found[$field], 0, $max) as $i => $v) {
                $text[$field . "_" . $i] = $v;
            }
        }
        $fr = $this->translator->many($text);
        $found["name_fr"] =
            $fr["name"] !== $found["name"] ? $fr["name"] : $found["name_fr"] ?? $found["name"];
        $found["description_original"] = $found["description"];
        $found["description"] = $found["shortDescription"] = $fr["description"];
        $found["breathing_original"] = $found["breathing"];
        $found["breathing"] = $fr["breathing"];
        foreach (["steps", "formCues", "commonMistakes"] as $field) {
            $found[$field . "_original"] = $found[$field];
            foreach ($found[$field] as $i => $v) {
                if (isset($fr[$field . "_" . $i])) {
                    $found[$field][$i] = $fr[$field . "_" . $i];
                }
            }
        }
        return $found + [
            "language" => "fr-or-original",
            "translation_note" => "Traduction automatique ; texte source conservé si indisponible.",
        ];
    }

    public function custom(array $row): array
    {
        $p = json_decode($row["payload"], true);
        $labels = $this->nutrition->catalog("MUSCLE_LABELS");
        $secondary = $p["secondary_muscles"] ?? [];
        return [
            "id" => "custom-" . $row["id"],
            "customId" => (int) $row["id"],
            "source" => "custom",
            "ownerId" => (int) $row["user_id"],
            "name" => $p["name"],
            "bodyPart" => $p["primary_muscle"],
            "target" => $labels[$p["primary_muscle"]],
            "primaryGroup" => $p["primary_muscle"],
            "secondaryGroups" => $secondary,
            "secondaryMuscles" => array_map(fn($s) => $labels[$s], $secondary),
            "equipment" => $p["equipment"] ?? "Non renseigné",
            "difficulty" => $p["difficulty"] ?? "intermediate",
            "compound" => count($secondary) > 0,
            "unilateral" => false,
            "shortDescription" => $p["description"] ?? "",
            "description" => $p["description"] ?? "",
            "steps" => [],
            "formCues" => [],
            "commonMistakes" => [],
            "breathing" => "",
            "videos" => [
                "male" => $p["male_video_url"] ?? "",
                "female" => $p["female_video_url"] ?? "",
            ],
            "thumbnails" => ["male" => $p["image_url"] ?? "", "female" => $p["image_url"] ?? ""],
            "imageUrl" => $p["image_url"] ?? "",
            "gifUrl" => $p["image_url"] ?? "",
        ];
    }

    private function local(): array
    {
        $data = [];
        if (!$data) {
            foreach (
                [
                    [
                        "Développé couché",
                        "chest",
                        "Barre",
                        "Dos stable, pieds au sol, descente contrôlée.",
                    ],
                    [
                        "Pompes",
                        "chest",
                        "Poids du corps",
                        "Gardez le corps aligné et contrôlez la descente.",
                    ],
                    [
                        "Tractions",
                        "back",
                        "Barre fixe",
                        "Évitez le balancement, contrôlez la remontée.",
                    ],
                    [
                        "Rowing haltère",
                        "back",
                        "Haltère",
                        "Gardez le dos stable et tirez le coude vers la hanche.",
                    ],
                    [
                        "Squat",
                        "quads",
                        "Barre",
                        "Restez gainé et adaptez la profondeur à votre mobilité.",
                    ],
                    [
                        "Presse à cuisses",
                        "quads",
                        "Machine",
                        "Conservez le bassin en contact avec le siège.",
                    ],
                    [
                        "Soulevé de terre roumain",
                        "hamstrings",
                        "Barre",
                        "Reculez les hanches, maintenez le dos stable.",
                    ],
                    [
                        "Hip thrust",
                        "glutes",
                        "Barre",
                        "Montez le bassin sans cambrer le bas du dos.",
                    ],
                    [
                        "Élévations latérales",
                        "shoulders",
                        "Haltères",
                        "Montez les bras sans élan et sans hausser les épaules.",
                    ],
                    [
                        "Développé épaules",
                        "shoulders",
                        "Haltères",
                        "Gardez les côtes basses et contrôlez la charge.",
                    ],
                    [
                        "Curl biceps",
                        "biceps",
                        "Haltères",
                        "Fixez les coudes et évitez de balancer le dos.",
                    ],
                    ["Extension triceps", "triceps", "Poulie", "Gardez les coudes près du corps."],
                    [
                        "Gainage",
                        "abs",
                        "Poids du corps",
                        "Gardez le bassin aligné et respirez régulièrement.",
                    ],
                    [
                        "Mollets debout",
                        "calves",
                        "Machine",
                        "Utilisez une amplitude contrôlée sans rebond.",
                    ],
                    [
                        "Fentes",
                        "glutes",
                        "Haltères",
                        "Gardez le genou aligné dans la direction du pied.",
                    ],
                ]
                as $i => [$name, $group, $eq, $desc]
            ) {
                $data[] = [
                    "id" => "local-" . $i,
                    "source" => "local",
                    "name" => $name,
                    "target" => $group,
                    "bodyPart" => $group,
                    "primaryGroup" => $group,
                    "secondaryGroups" => [],
                    "secondaryMuscles" => [],
                    "equipment" => $eq,
                    "difficulty" => "intermediate",
                    "shortDescription" => $desc,
                    "description" => $desc,
                    "steps" => [$desc],
                    "formCues" => [],
                    "commonMistakes" => [],
                    "breathing" => "Respirez régulièrement.",
                    "compound" => false,
                    "unilateral" => false,
                    "videos" => ["male" => "", "female" => ""],
                    "thumbnails" => ["male" => "", "female" => ""],
                    "gifUrl" => "",
                    "imageUrl" => "",
                ];
            }
        }
        return $data;
    }
}
