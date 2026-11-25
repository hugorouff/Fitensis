<?php

namespace App\Service;

final class AssistantService
{
    public function __construct(
        private GymStore $store,
        private AiClient $client,
        private Nutrition $nutrition,
        private RecipeSearch $recipes,
        private ExerciseCatalog $exercises,
        private int $requestsPerDay,
    ) {}

    private function userId(): int
    {
        $id = $this->store->user()["id"] ?? null;
        if (!$id) {
            throw new AiException("Connexion requise.", 401);
        }
        return (int) $id;
    }

    public function run(int $id): array
    {
        $row = $this->store
            ->db()
            ->fetchAssociative("SELECT * FROM ai_runs WHERE id=? AND user_id=?", [
                $id,
                $this->userId(),
            ]);
        if (!$row) {
            throw new AiException("Conversation introuvable.", 404);
        }
        $row["data"] = $row["result"]
            ? json_decode($row["result"], true, 64, JSON_THROW_ON_ERROR)
            : null;
        if ($row["data"]) {
            // Old conversations also stop displaying internal model narration.
            $row["data"]["answer"] = $this->summary($row["data"]);
        }
        $row["saved"] = json_decode($row["saved_sessions"], true) ?: [];
        return $row;
    }

    private function history(?int $parent): array
    {
        $history = [];
        for ($i = 0; $parent && $i < 2; $i++) {
            $run = $this->run($parent);
            if ($run["status"] !== "completed") {
                throw new AiException(
                    "Cette réponse ne peut pas servir de conversation précédente.",
                    422,
                );
            }
            $history[] = [
                "question" => mb_substr($run["question"], 0, 1500),
                "reponse" => mb_substr(json_encode($run["data"], JSON_UNESCAPED_UNICODE), 0, 3500),
            ];
            $parent = $run["parent_id"] ? (int) $run["parent_id"] : null;
        }
        return array_reverse($history);
    }

    public function ask(array $input): array
    {
        $uid = $this->userId();
        $question = trim(is_string($input["message"] ?? null) ? $input["message"] : "");
        $topic = $input["topic"] ?? "auto";
        $goal = $input["goal"] ?? "all";
        if ($question === "" || mb_strlen($question) > 3000) {
            throw new AiException("Écris une demande de 1 à 3 000 caractères.", 422);
        }
        if (
            !in_array($topic, ["auto", "recipes", "sessions", "advice"], true) ||
            !in_array($goal, ["all", "cut", "maintain", "bulk"], true)
        ) {
            throw new AiException("Type de demande ou objectif invalide.", 422);
        }
        if (!$this->client->configured()) {
            throw new AiException("L’assistant n’est pas configuré sur cette installation.");
        }
        $parent =
            isset($input["parent_id"]) && $input["parent_id"] !== null
                ? (int) $input["parent_id"]
                : null;
        $history = $this->history($parent);
        $pantry = [];
        if (($input["share_pantry"] ?? false) === true) {
            foreach (
                array_slice(is_array($input["pantry"] ?? null) ? $input["pantry"] : [], 0, 30)
                as $item
            ) {
                if (is_string($item) && trim($item) !== "") {
                    $pantry[] = mb_substr(trim($item), 0, 80);
                }
            }
        }
        $context = [
            "demande" => $question,
            "type" => $topic,
            "objectif" => $goal,
            "contraintes" => mb_substr(
                is_string($input["constraints"] ?? null) ? $input["constraints"] : "",
                0,
                1000,
            ),
        ];
        $shared = ["demande", "contraintes"];
        if (($input["share_pantry"] ?? false) === true) {
            $context["aliments_disponibles"] = array_values(array_unique($pantry));
            $shared[] = "aliments du frigo";
        }
        if (($input["share_training"] ?? false) === true) {
            $context["performances_recentes"] = $this->store
                ->db()
                ->fetchAllAssociative(
                    "SELECT date,exercise,sets,reps,weight,rpe FROM workouts WHERE user_id=? ORDER BY date DESC,id DESC LIMIT 12",
                    [$uid],
                );
            $shared[] = "12 dernières performances";
        }
        $wantsRecipes =
            $topic === "recipes" ||
            ($topic === "auto" &&
                preg_match(
                    "/\b(repas|recettes?|manger|nutrition|aliment|avant|apres|frigo)\b/",
                    RemoteCache::normalize($question),
                ));
        $ideaCount = $input["idea_count"] ?? 5;
        if (!is_int($ideaCount) || $ideaCount < 1 || $ideaCount > 5) {
            throw new AiException("Choisis entre une et cinq idées.", 422);
        }
        $brief = new TrainingBrief();
        $blocks =
            $topic !== "recipes"
                ? $brief->parse(
                    $question,
                    is_array($input["training_blocks"] ?? null) ? $input["training_blocks"] : [],
                )
                : [];
        $requestedMuscles = array_values(
            array_intersect(
                array_filter(
                    is_array($input["requested_muscles"] ?? null)
                        ? $input["requested_muscles"]
                        : [],
                    "is_string",
                ),
                array_keys($this->nutrition->catalog("MUSCLE_LABELS")),
            ),
        );
        $context["muscles_demandes"] = $requestedMuscles;
        $catalogExamples = $this->exercises->cachedSuggestions(120);
        $structuredSessions = $blocks
            ? $brief->sessions($blocks, $question . " " . $context["contraintes"], $catalogExamples)
            : [];
        $context["blocs_seance_verifies"] = $blocks;
        $context["alternatives_gerees_par_application"] = $ideaCount > 1;
        $context["nombre_idees"] = $ideaCount;
        $context["recettes_gerees_par_application"] = true;
        $context["seances_gerees_par_application"] =
            (bool) $blocks || in_array($topic, ["recipes", "advice"], true);
        if (in_array($topic, ["auto", "sessions"], true) && !$blocks) {
            $context["exemples_exercices_catalogue"] = array_values(
                array_filter(
                    $catalogExamples,
                    static fn($exercise) => !$requestedMuscles ||
                        in_array($exercise["muscle"], $requestedMuscles, true),
                ),
            );
        }

        $db = $this->store->db();
        $this->store->beginWrite();
        try {
            $count = (int) $db->fetchOne(
                "SELECT COUNT(*) FROM ai_runs WHERE user_id=? AND created_at>=?",
                [$uid, gmdate("Y-m-d H:i:s", time() - 86400)],
            );
            if ($count >= max(1, $this->requestsPerDay)) {
                throw new AiException(
                    "La limite quotidienne de l’assistant est atteinte sur ce compte.",
                    429,
                );
            }
            if (
                $db->fetchOne(
                    "SELECT COUNT(*) FROM ai_runs WHERE user_id=? AND created_at>?",
                    [$uid, gmdate("Y-m-d H:i:s", time() - 4)],
                )
            ) {
                throw new AiException(
                    "Attends quelques secondes avant de relancer une demande.",
                    429,
                );
            }
            $id = $this->store->insertAndGetId("ai_runs", [
                "user_id" => $uid,
                "parent_id" => $parent,
                "question" => $question,
                "topic" => $topic,
                "status" => "pending",
                "provider" => $this->client->providerName(),
                "shared_context" => json_encode($shared),
            ]);
            $db->executeStatement("COMMIT");
        } catch (\Throwable $error) {
            $db->executeStatement("ROLLBACK");
            throw $error;
        }
        try {
            $result = null;
            $generationStarted = microtime(true);
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    $raw = $this->client->generate(
                        $context,
                        $history,
                        $this->nutrition->catalog("MUSCLE_LABELS"),
                    );
                    // The introductory answer is app-owned: model analysis cannot leak through it.
                    $raw["answer"] = "Voici les propositions correspondant à ta demande.";
                    $raw["recipes"] = [];
                    if ($blocks) {
                        $raw["sessions"] = $structuredSessions;
                    }
                    if (in_array($topic, ["recipes", "advice"], true)) {
                        $raw["sessions"] = [];
                    }
                    $result = $this->validateResult($raw);
                    if ($blocks) {
                        $result["sessions"] = $structuredSessions;
                    }
                    break;
                } catch (AiException $error) {
                    if (
                        microtime(true) - $generationStarted > 45 ||
                        $attempt ||
                        $error->httpStatus !== 503 ||
                        !preg_match(
                            "/format|incomplet|renvoy|propos|assez|invalide/i",
                            $error->getMessage(),
                        )
                    ) {
                        throw $error;
                    }
                    $context["correction_format"] =
                        "Réponds directement en français. Aucun raisonnement, aucune auto-instruction. Textes courts. Les tableaux recettes et les séances déjà structurées doivent être vides.";
                }
            }
            if (
                $ideaCount > 1 &&
                $result["sessions"] &&
                in_array($topic, ["auto", "sessions"], true)
            ) {
                $ideas = (new SessionIdeas())->create(
                    $blocks ?: (new SessionIdeas())->inferBlocks($result["sessions"]),
                    $question . " " . $context["contraintes"],
                    $catalogExamples,
                    $ideaCount,
                );
                $result["sessions"] = $ideas["sessions"];
                $result["session_ideas"] = $ideas["count"];
                $result["idea_note"] = $ideas["note"];
            }
            $selection = $wantsRecipes
                ? $this->recipes->assistantSelection(
                    $question,
                    $pantry,
                    $goal,
                    $context["contraintes"],
                )
                : null;
            $result["recipes"] = $selection["recipes"] ?? [];
            if (
                isset($input["portions"]) &&
                is_int($input["portions"]) &&
                $input["portions"] >= 1 &&
                $input["portions"] <= 12
            ) {
                $result["recipes"] = array_map(
                    static fn($recipe) => RecipePortions::adjust(
                        $recipe,
                        $input["portions"],
                        $recipe["base_portions"],
                    ),
                    $result["recipes"],
                );
            }
            $result["recipe_note"] = $selection
                ? $selection["note"] .
                    ($selection["degraded"] ? " Source partielle ou cache de secours utilisé." : "")
                : "";
            $result["structured_training"] = (bool) $blocks;
            $result["answer"] = $this->summary($result);
            $db->update(
                "ai_runs",
                [
                    "status" => "completed",
                    "result" => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ],
                ["id" => $id],
            );
        } catch (\Throwable $error) {
            $db->update("ai_runs", ["status" => "failed"], ["id" => $id]);
            if ($error instanceof AiException) {
                throw $error;
            }
            throw new AiException("La réponse n’a pas pu être enregistrée. Réessaie plus tard.");
        }
        return $this->run($id);
    }

    private function summary(array $result): string
    {
        if (isset($result["session_ideas"])) {
            return $result["session_ideas"] .
                " idée(s) au choix et " .
                count($result["recipes"] ?? []) .
                " recette(s). Ajoute au planning uniquement les séances qui te plaisent.";
        }
        return count($result["sessions"] ?? []) .
            " bloc(s) de séance et " .
            count($result["recipes"] ?? []) .
            " recette(s). Consulte les détails ci-dessous et enregistre uniquement ce qui te convient.";
    }

    private function hasInternalNarration(string $text): bool
    {
        return (bool) preg_match(
            "/<\/?(?:think|analysis)>|je dois|je vais (?:d.abord|analyser|proposer|cr[eé]er|répondre)|l.utilisateur|contexte json|sch[eé]ma fourni|let me think|i need to|system prompt|chain.of.thought/iu",
            $text,
        );
    }

    private function text(mixed $value, int $max = 1500, bool $allowEmpty = false): string
    {
        if (
            !is_string($value) ||
            (!$allowEmpty && trim($value) === "") ||
            mb_strlen($value) > $max
        ) {
            throw new AiException(
                "L’IA a renvoyé un texte incomplet ou trop long. Reformule ta demande.",
            );
        }
        $value = preg_replace("/<think>.*?<\/think>/isu", "", $value);
        if ($this->hasInternalNarration($value)) {
            throw new AiException(
                "Le modèle a renvoyé une analyse au lieu d’une proposition. Réessaie avec une demande précise.",
            );
        }
        return trim($value);
    }

    private function number(mixed $value, float $min, float $max, bool $integer = true): int|float
    {
        if (
            (!is_int($value) && !is_float($value)) ||
            !is_finite((float) $value) ||
            $value < $min ||
            $value > $max ||
            ($integer && floor($value) !== (float) $value)
        ) {
            throw new AiException(
                "L’IA a renvoyé des valeurs incohérentes. Essaie une demande plus simple.",
            );
        }
        return $integer ? (int) $value : (float) $value;
    }

    private function texts(mixed $value, int $maxItems, int $maxLength = 500): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $maxItems) {
            throw new AiException("La liste de propositions IA est invalide.");
        }
        return array_map(fn($v) => $this->text($v, $maxLength), $value);
    }

    public function validateResult(array $raw, array $candidates = []): array
    {
        $result = [
            "answer" => $this->text($raw["answer"] ?? null, 5000),
            "recipes" => [],
            "sessions" => [],
            "advice" => $this->texts($raw["advice"] ?? null, 10, 1200),
            "follow_up" => $this->text($raw["follow_up"] ?? null, 1200, true),
        ];
        $catalog = [];
        foreach ($candidates as $candidate) {
            $catalog[(string) $candidate["id"]] = $candidate;
        }
        foreach (["recipes" => 8, "sessions" => 6] as $key => $max) {
            if (
                !is_array($raw[$key] ?? null) ||
                !array_is_list($raw[$key]) ||
                count($raw[$key]) > $max
            ) {
                throw new AiException("Les propositions IA sont incomplètes.");
            }
        }
        foreach ($raw["recipes"] as $recipe) {
            if (!is_array($recipe)) {
                throw new AiException("Une recette proposée est invalide.");
            }
            $id = $recipe["catalog_id"] ?? null;
            $source = is_string($id) ? $catalog[$id] ?? null : null;
            // Unknown claimed source IDs are rejected, never presented as verified references.
            if ($id !== null && !$source) {
                throw new AiException(
                    "L’IA a cité une recette absente du contexte. Reformule ta demande.",
                );
            }
            $result["recipes"][] = [
                "title" => $this->text($recipe["title"] ?? null, 250),
                "reason" => $this->text($recipe["reason"] ?? null, 1500, true),
                "catalog_id" => $source ? (string) $id : null,
                "source_title" => $source["name_fr"] ?? null,
                "minutes" => $this->number($recipe["minutes"] ?? null, 1, 240),
                "portions" => $this->number($recipe["portions"] ?? null, 1, 12),
                "ingredients" => $this->texts($recipe["ingredients"] ?? null, 30),
                "steps" => $this->texts($recipe["steps"] ?? null, 20, 1000),
            ];
        }
        $muscles = $this->nutrition->catalog("MUSCLE_LABELS");
        foreach ($raw["sessions"] as $session) {
            if (!is_array($session)) {
                throw new AiException("Une séance proposée est invalide.");
            }
            $groups = $this->texts($session["muscles"] ?? null, 12, 40);
            if (!$groups || array_diff($groups, array_keys($muscles))) {
                throw new AiException("Les groupes musculaires proposés sont invalides.");
            }
            $exercises = $session["exercises"] ?? null;
            if (
                !is_array($exercises) ||
                !array_is_list($exercises) ||
                !$exercises ||
                count($exercises) > 12
            ) {
                throw new AiException("La séance proposée ne contient pas d’exercices valides.");
            }
            $duration = $this->number($session["duration_minutes"] ?? null, 10, 180);
            $items = [];
            foreach ($exercises as $exercise) {
                if (!is_array($exercise)) {
                    throw new AiException("Un exercice proposé est invalide.");
                }
                $items[] = [
                    "name" => $this->text($exercise["name"] ?? null, 250),
                    "sets" => $this->number($exercise["sets"] ?? null, 1, 8),
                    "reps" => $this->number($exercise["reps"] ?? null, 1, 40),
                    "weight" =>
                        ($exercise["weight"] ?? null) === null
                            ? null
                            : $this->number($exercise["weight"], 0, 500, false),
                    "rest_seconds" => $this->number($exercise["rest_seconds"] ?? null, 15, 300),
                    "instructions" => $this->text($exercise["instructions"] ?? null, 1500, true),
                ];
            }
            $result["sessions"][] = [
                "title" => $this->text($session["title"] ?? null, 250),
                "reason" => $this->text($session["reason"] ?? null, 1500, true),
                "duration_minutes" => $this->number($session["duration_minutes"] ?? null, 10, 180),
                "muscles" => array_values(array_unique($groups)),
                "notes" => $this->text($session["notes"] ?? null, 3000, true),
                "exercises" => $items,
            ];
        }
        return $result;
    }

    public function addSession(int $runId, int $index, string $day, string $time): int
    {
        $date = \DateTimeImmutable::createFromFormat("!Y-m-d H:i", $day . " " . $time);
        if (!$date || $date->format("Y-m-d H:i") !== $day . " " . $time) {
            throw new AiException("Choisis une date et une heure valides.", 422);
        }
        $uid = $this->userId();
        $db = $this->store->db();
        $this->store->beginWrite();
        try {
            $run = $this->run($runId);
            $proposal =
                $run["status"] === "completed" ? $run["data"]["sessions"][$index] ?? null : null;
            if (!$proposal) {
                throw new AiException("Cette proposition de séance est introuvable.", 404);
            }
            $existing = $run["saved"][(string) $index] ?? null;
            if (
                $existing &&
                $db->fetchOne("SELECT id FROM training_sessions WHERE id=? AND user_id=?", [
                    $existing,
                    $uid,
                ])
            ) {
                $db->executeStatement("COMMIT");
                return (int) $existing;
            }
            $notes = "Proposition IA à adapter.\n" . $proposal["notes"];
            foreach ($proposal["exercises"] as $item) {
                $notes .=
                    "\n" .
                    $item["name"] .
                    " — repos " .
                    $item["rest_seconds"] .
                    " s : " .
                    $item["instructions"];
            }
            $id = $this->store->insertAndGetId("training_sessions", [
                "user_id" => $uid,
                "title" => $proposal["title"],
                "starts_at" => $date->format("Y-m-d H:i"),
                "duration_minutes" => $proposal["duration_minutes"],
                "preset" => "custom",
                "muscles" => json_encode($proposal["muscles"]),
                "status" => "planned",
                "notes" => mb_substr($notes, 0, 5000),
            ]);
            foreach ($proposal["exercises"] as $item) {
                $db->insert("training_items", [
                    "session_id" => $id,
                    "exercise" => $item["name"],
                    "sets" => $item["sets"],
                    "reps" => $item["reps"],
                    "weight" => $item["weight"] ?? 0,
                ]);
            }
            $saved = $run["saved"];
            $saved[(string) $index] = $id;
            $db->update(
                "ai_runs",
                ["saved_sessions" => json_encode((object) $saved)],
                ["id" => $runId],
            );
            $db->executeStatement("COMMIT");
            return $id;
        } catch (\Throwable $error) {
            $db->executeStatement("ROLLBACK");
            throw $error;
        }
    }

    public function favorite(int $runId, int $index, ?int $portions = null, ?int $base = null): void
    {
        $run = $this->run($runId);
        $recipe = $run["status"] === "completed" ? $run["data"]["recipes"][$index] ?? null : null;
        if (!$recipe) {
            throw new AiException("Cette proposition de recette est introuvable.", 404);
        }
        if ($portions !== null) {
            $recipe = RecipePortions::adjust(
                $recipe,
                $portions,
                $base ?? ($recipe["base_portions"] ?? $recipe["portions"]),
            );
        }
        $key = hash("sha256", json_encode([$recipe["catalog_id"], $recipe["title"]]));
        $this->store
            ->db()
            ->executeStatement(
                "INSERT INTO ai_favorites(user_id,recipe_key,payload) VALUES(?,?,?) ON CONFLICT(user_id,recipe_key) DO UPDATE SET payload=excluded.payload",
                [
                    $this->userId(),
                    $key,
                    json_encode($recipe, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ],
            );
    }
}
