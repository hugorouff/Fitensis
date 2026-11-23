<?php

namespace App\Service;
class RecipeSearch
{
    private array $termCache = [];
    private const BASE = "https://www.themealdb.com/api/json/v1/1/";
    public function __construct(
        private Nutrition $nutrition,
        private RemoteCache $cache,
        private Translator $translator,
        private ?WikiRecipes $wiki = null,
    ) {}
    public function terms(string $value): array
    {
        $key = RemoteCache::normalize($value);
        if (isset($this->termCache[$key])) {
            return $this->termCache[$key];
        }
        foreach ($this->nutrition->catalog("GENERIC_SEARCH_TERMS") as $fr => $terms) {
            if (RemoteCache::normalize($fr) === $key) {
                return $this->termCache[$key] = $terms;
            }
        }
        foreach ($this->nutrition->catalog("FR_TO_MEALDB") as $fr => $en) {
            if (RemoteCache::normalize($fr) === $key) {
                return $this->termCache[$key] = is_array($en) ? $en : [$en];
            }
        }
        return $this->termCache[$key] = $key !== "" ? [$key] : [];
    }

    public function phraseMatch(string $term, string $ingredient): bool
    {
        $a = RemoteCache::normalize($term);
        $b = RemoteCache::normalize($ingredient);
        return $a !== "" &&
            $b !== "" &&
            (str_contains(" " . $b . " ", " " . $a . " ") ||
                str_contains(" " . $a . " ", " " . $b . " "));
    }

    public function profile(array $meal, array $ingredients): array
    {
        $text = strtolower(
            ($meal["strMeal"] ?? "") .
                " " .
                ($meal["strCategory"] ?? "") .
                " " .
                implode(" ", array_column($ingredients, "name")),
        );
        $hits = fn($terms) => count(array_filter($terms, fn($t) => str_contains($text, $t)));
        $lean = $hits([
            "chicken",
            "turkey",
            "tuna",
            "cod",
            "prawn",
            "shrimp",
            "salmon",
            "egg",
            "yogurt",
            "beans",
            "lentils",
            "chickpeas",
        ]);
        $veg = $hits([
            "broccoli",
            "spinach",
            "green beans",
            "courgette",
            "carrot",
            "pepper",
            "tomato",
            "mushroom",
            "lettuce",
            "cabbage",
            "vegetable",
            "salad",
        ]);
        $carbs = $hits([
            "rice",
            "pasta",
            "spaghetti",
            "noodle",
            "potato",
            "bread",
            "oats",
            "flour",
            "pastry",
        ]);
        $dense = $hits([
            "butter",
            "cream",
            "double cream",
            "cheese",
            "coconut milk",
            "peanut",
            "chocolate",
            "sugar",
            "honey",
            "pastry",
            "bacon",
            "sausage",
            "chorizo",
            "fried",
        ]);
        $rich = $hits([
            "puff pastry",
            "shortcrust pastry",
            "double cream",
            "fried",
            "chocolate",
            "bacon",
            "sausage",
            "chorizo",
        ]);
        $red = $hits(["beef", "pork", "lamb"]);
        $cut =
            $lean * 3 + $veg * 2 + (str_contains($text, "salad") ? 1 : 0) - $dense * 3 - $rich * 2;
        $bulk =
            $carbs * 3 +
            $dense * 3 +
            $red +
            $lean -
            (str_contains($text, "salad") && $carbs === 0 ? 1 : 0);
        $balance = (int) ($lean > 0 || $red > 0) + (int) ($carbs > 0) + (int) ($veg > 0);
        $maintain = $balance * 3 + min($lean + $red, 2) - max($dense - 1, 0);
        $recommended =
            $bulk >= max($cut + 3, $maintain + 2) || $rich >= 2
                ? "bulk"
                : ($cut >= max($bulk + 2, $maintain) && $dense <= 1
                    ? "cut"
                    : "maintain");
        return [
            "scores" => ["cut" => $cut, "maintain" => $maintain, "bulk" => $bulk],
            "recommended_goal" => $recommended,
        ];
    }

    private function pantry(string $value): array
    {
        return array_slice(
            array_values(
                array_unique(
                    array_filter(array_map("trim", explode(",", mb_substr($value, 0, 2000)))),
                ),
            ),
            0,
            30,
        );
    }

    private function serialize(array $m, array $pantry, string $goal): array
    {
        $ingredients = [];
        $used = [];
        $missing = [];
        $ownedCount = 0;
        for ($i = 1; $i <= 20; $i++) {
            $name = trim((string) ($m["strIngredient" . $i] ?? ""));
            if ($name === "") {
                continue;
            }
            $fr = $this->translator->local($name, "EN_TO_FR");
            $matched = [];
            foreach ($pantry as $p) {
                $match = $this->phraseMatch($p, $fr);
                foreach ($this->terms($p) as $term) {
                    if ($this->phraseMatch($term, $name)) {
                        $match = true;
                    }
                }
                if ($match) {
                    $matched[] = $p;
                    $used[] = $p;
                }
            }
            $owned = (bool) $matched;
            if ($owned) {
                $ownedCount++;
            } else {
                $missing[] = $fr;
            }
            $ingredients[] = [
                "name" => $name,
                "name_fr" => $fr,
                "measure" => $this->translator->measure(
                    trim((string) ($m["strMeasure" . $i] ?? "")),
                ),
                "owned" => $owned,
                "matched_by" => $matched,
            ];
        }
        $used = array_values(array_unique($used));
        $profile = $this->profile($m, $ingredients);
        $total = count($ingredients);
        $title = (string) ($m["strMeal"] ?? "Recette");
        return [
            "id" => (string) $m["idMeal"],
            "name" => $title,
            "name_fr" => $this->translator->local($title, "RECIPE_TITLE_FR"),
            "image" => RemoteCache::safeUrl($m["strMealThumb"] ?? ""),
            "category" => $m["strCategory"] ?? "",
            "category_fr" => $this->translator->local($m["strCategory"] ?? "", "CATEGORY_FR"),
            "area" => $m["strArea"] ?? "",
            "area_fr" => $this->translator->local($m["strArea"] ?? "", "AREA_FR"),
            "ingredients" => $ingredients,
            "matched_pantry" => count($used),
            "matched_pantry_items" => $used,
            "pantry_total" => count($pantry),
            "pantry_match_percent" => count($pantry)
                ? round((100 * count($used)) / count($pantry))
                : 0,
            "owned_recipe_ingredients" => $ownedCount,
            "recipe_ingredient_total" => $total,
            "recipe_coverage_percent" => $total ? round((100 * $ownedCount) / $total) : 0,
            "missing" => $missing,
            "source" => RemoteCache::safeUrl($m["strSource"] ?? ""),
            "youtube" => RemoteCache::safeUrl($m["strYoutube"] ?? ""),
            "instructions" => (string) ($m["strInstructions"] ?? ""),
            "instructions_original" => (string) ($m["strInstructions"] ?? ""),
            "goal_scores" => $profile["scores"],
            "recommended_goal" => $profile["recommended_goal"],
            "goal_score" => $profile["scores"][$goal] ?? 0,
            "goal_match" => $profile["recommended_goal"] === $goal,
            "selected_goal_score" => $profile["scores"][$goal] ?? 0,
            "nutrition_verified" => false,
            "source_name" => $m["_source"] ?? "TheMealDB",
            "metadata_unknown" => $m["_metadata_unknown"] ?? false,
        ];
    }

    private function escape(mixed $v): mixed
    {
        if (is_array($v)) {
            return array_map($this->escape(...), $v);
        }
        return is_string($v) ? htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") : $v;
    }

    private function translateItems(array $items): array
    {
        $texts = [];
        foreach ($items as $item) {
            if ($item["name_fr"] === $item["name"]) {
                $texts["t" . $item["id"]] = $item["name"];
            }
            foreach ($item["ingredients"] as $ing) {
                if ($ing["name_fr"] === $ing["name"]) {
                    $texts["i" . hash("sha256", $ing["name"])] = $ing["name"];
                }
            }
        }
        $translated = $this->translator->many(array_slice($texts, 0, 40, true));
        foreach ($items as &$item) {
            $item["name_fr"] = $translated["t" . $item["id"]] ?? $item["name_fr"];
            $missing = [];
            foreach ($item["ingredients"] as &$ing) {
                $ing["name_fr"] =
                    $translated["i" . hash("sha256", $ing["name"])] ?? $ing["name_fr"];
                if (!$ing["owned"]) {
                    $missing[] = $ing["name_fr"];
                }
            }
            unset($ing);
            $item["missing"] = $missing;
        }
        unset($item);
        return $items;
    }

    public function search(array $d): array
    {
        $pantry = $this->pantry($d["ingredients"] ?? "");
        $goal = $d["goal"] ?? "all";
        $limit = max(1, min(48, (int) ($d["limit"] ?? 24)));
        $page = max(1, min(10000, (int) ($d["page"] ?? 1)));
        $search = RemoteCache::normalize(mb_substr($d["search"] ?? "", 0, 200));
        if (!in_array($goal, ["all", "cut", "maintain", "bulk"])) {
            throw new \InvalidArgumentException("Objectif invalide.");
        }
        // The first-letter endpoint supplies full recipe records, not just the first
        // 20 candidates. Every available record can be ranked before pagination.
        $sourceFilter = $d["source"] ?? "all";
        $queries = [];
        foreach ($sourceFilter === "wikibooks" ? [] : range("a", "z") as $letter) {
            $queries[$letter] = ["url" => self::BASE . "search.php", "query" => ["f" => $letter]];
        }
        $catalog = [];
        $errors = 0;
        $cached = false;
        $stale = false;
        $availableLetters = 0;
        foreach ($this->cache->many($queries, 86400) as $r) {
            $errors += (int) ($r["error"] !== null);
            $cached = $cached || $r["cached"];
            $stale = $stale || $r["stale"];
            if (is_array($r["data"]) && array_key_exists("meals", $r["data"])) {
                $availableLetters++;
            }
            foreach ($r["data"]["meals"] ?? [] as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $id = (string) ($m["idMeal"] ?? "");
                if (!ctype_digit($id) || !is_string($m["strMeal"] ?? null)) {
                    continue;
                }
                $catalog[$id] ??= $m;
            }
        }
        $wikiState = null;
        if ($this->wiki && $sourceFilter !== "themealdb") {
            $wikiState = $this->wiki->catalog();
            $catalog += $wikiState["items"];
        }
        $items = [];
        $tokens = $search !== "" ? array_slice(explode(" ", $search), 0, 12) : [];
        foreach ($catalog as $m) {
            $item = $this->serialize($m, $pantry, $goal);
            if (($d["filter_pantry"] ?? "1") !== "0" && $pantry && $item["matched_pantry"] === 0) {
                continue;
            }
            if ($tokens) {
                $haystack = RemoteCache::normalize(
                    $item["name"] .
                        " " .
                        $item["name_fr"] .
                        " " .
                        $item["category"] .
                        " " .
                        $item["category_fr"] .
                        " " .
                        implode(" ", array_column($item["ingredients"], "name")) .
                        " " .
                        implode(" ", array_column($item["ingredients"], "name_fr")),
                );
                $match = true;
                foreach ($tokens as $token) {
                    $one = false;
                    foreach (
                        array_unique(array_merge([$token], $this->terms($token)))
                        as $variant
                    ) {
                        if (str_contains($haystack, RemoteCache::normalize($variant))) {
                            $one = true;
                            break;
                        }
                    }
                    if (!$one) {
                        $match = false;
                        break;
                    }
                }
                if (!$match) {
                    continue;
                }
            }
            $excluded = array_filter(
                array_map("trim", explode(",", mb_substr((string) ($d["exclude"] ?? ""), 0, 500))),
            );
            $likes = array_filter(
                array_map("trim", explode(",", mb_substr((string) ($d["likes"] ?? ""), 0, 500))),
            );
            $foodText =
                $item["name"] .
                " " .
                $item["name_fr"] .
                " " .
                implode(" ", array_column($item["ingredients"], "name"));
            if ($excluded && $item["metadata_unknown"]) {
                continue;
            } // No false exclusion guarantee from a title alone.
            $reject = false;
            foreach ($excluded as $term) {
                foreach ($this->terms($term) as $variant) {
                    if ($this->phraseMatch($variant, $foodText)) {
                        $reject = true;
                    }
                }
            }
            if ($reject) {
                continue;
            }
            $item["taste_score"] = 0;
            foreach ($likes as $term) {
                foreach ($this->terms($term) as $variant) {
                    if ($this->phraseMatch($variant, $foodText)) {
                        $item["taste_score"]++;
                        break;
                    }
                }
            }
            if (
                ($d["cuisine"] ?? "") !== "" &&
                RemoteCache::normalize($item["area"]) !== RemoteCache::normalize($d["cuisine"])
            ) {
                continue;
            }
            $items[] = $item;
        }
        $sort = $d["sort"] ?? "relevance";
        usort($items, function ($a, $b) use ($sort) {
            if ($sort === "name") {
                return strcmp(mb_strtolower($a["name_fr"]), mb_strtolower($b["name_fr"]));
            }
            if (($b["taste_score"] ?? 0) !== ($a["taste_score"] ?? 0)) {
                return ($b["taste_score"] ?? 0) <=> ($a["taste_score"] ?? 0);
            }
            if (
                $sort === "pantry" &&
                $b["recipe_coverage_percent"] !== $a["recipe_coverage_percent"]
            ) {
                return $b["recipe_coverage_percent"] <=> $a["recipe_coverage_percent"];
            }
            $score = [
                $b["goal_match"],
                $b["selected_goal_score"],
                $b["recipe_coverage_percent"],
                $b["matched_pantry"],
            ] <=> [
                $a["goal_match"],
                $a["selected_goal_score"],
                $a["recipe_coverage_percent"],
                $a["matched_pantry"],
            ];
            return $score ?:
                strcmp(mb_strtolower($a["name"]), mb_strtolower($b["name"])) ?:
                strcmp($a["id"], $b["id"]);
        });
        $total = count($items);
        if (($d["all"] ?? "0") === "1") {
            $page = 1;
            $limit = max(1, $total);
        }
        $offset = ($page - 1) * $limit;
        $items = $this->translateItems(array_slice($items, $offset, $limit));
        $hasMore = $offset + count($items) < $total;
        return [
            "success" => true,
            "count" => count($items),
            "total" => $total,
            "catalog_size" => count($catalog),
            "page" => $page,
            "limit" => $limit,
            "has_more" => $hasMore,
            "next_page" => $hasMore ? $page + 1 : null,
            "total_pages" => (int) ceil($total / $limit),
            "data" => $this->escape($items),
            "source" => "TheMealDB + Wikibooks",
            "wiki_complete" => $wikiState["complete"] ?? true,
            "language" => "fr-or-original",
            "goal_mode" => "ranking",
            "goal_note" =>
                "Classement selon les ingrédients, sans calories ni macros vérifiées pour les recettes Internet. Les menus calculés restent distincts.",
            "degraded" =>
                $errors > 0 ||
                ($sourceFilter !== "wikibooks" && $availableLetters < 26) ||
                ($wikiState !== null && !$wikiState["complete"]),
            "cached" => $cached,
            "stale" => $stale,
            "available_letters" => $availableLetters,
            "message" =>
                $errors > 0 || $availableLetters < 26
                    ? "Catalogue partiel : certaines réponses de la source sont indisponibles. Les recettes en cache restent accessibles."
                    : null,
            "translation_note" =>
                "Dictionnaire local et traduction automatique ; texte original conservé si indisponible.",
        ];
    }

    /** Public reference suggestions only; no network request is triggered by the assistant. */
    public function cachedSuggestions(array $pantry, string $goal, int $limit = 8): array
    {
        $catalog = [];
        foreach (range("a", "z") as $letter) {
            $cached = $this->cache->read(self::BASE . "search.php?f=" . $letter, 86400);
            foreach ($cached["data"]["meals"] ?? [] as $meal) {
                if (is_array($meal) && ctype_digit((string) ($meal["idMeal"] ?? ""))) {
                    $catalog[(string) $meal["idMeal"]] = $meal;
                }
            }
        }
        $items = [];
        foreach ($catalog as $meal) {
            $item = $this->serialize($meal, $pantry, $goal);
            if ($pantry && $item["matched_pantry"] === 0) {
                continue;
            }
            $items[] = $item;
        }
        usort(
            $items,
            static fn($a, $b) => [
                $b["goal_match"],
                $b["matched_pantry"],
                $b["recipe_coverage_percent"],
            ] <=> [$a["goal_match"], $a["matched_pantry"], $a["recipe_coverage_percent"]],
        );
        return array_slice($items, 0, $limit);
    }

    /** Public Internet catalogue, ranked server-side. No personal prompt goes to the source. */
    public function assistantSelection(
        string $request,
        array $pantry,
        string $goal,
        string $constraints = "",
    ): array {
        $queries = [];
        foreach (range("a", "z") as $letter) {
            $queries[$letter] = [
                "url" => self::BASE . "search.php",
                "query" => ["f" => $letter],
                "timeout" => 10,
                "max_duration" => 25,
            ];
        }
        $catalog = [];
        $degraded = false;
        foreach ($this->cache->many($queries, 86400) as $record) {
            $degraded = $degraded || $record["stale"] || $record["error"] !== null;
            foreach ($record["data"]["meals"] ?? [] as $meal) {
                if (is_array($meal) && ctype_digit((string) ($meal["idMeal"] ?? ""))) {
                    $catalog[(string) $meal["idMeal"]] = $meal;
                }
            }
        }
        $text = RemoteCache::normalize($request . " " . $constraints);
        $wanted = $pantry;
        $excluded = [];
        foreach ($this->nutrition->catalog("FR_TO_MEALDB") as $fr => $unused) {
            $word = RemoteCache::normalize($fr);
            if ($word === "" || !preg_match("/\b" . preg_quote($word, "/") . "\b/", $text)) {
                continue;
            }
            if (
                preg_match(
                    "/(?:sans|pas de|pas d|exclure|allergi[a-z ]*)\s*" .
                        preg_quote($word, "/") .
                        "\b/",
                    $text,
                )
            ) {
                $excluded[] = $fr;
            } else {
                $wanted[] = $fr;
            }
        }
        if (preg_match("/\b(vegetarien|vegetarienne|vegan|vegetalien)\b/", $text)) {
            $excluded = array_merge($excluded, [
                "poulet",
                "boeuf",
                "porc",
                "agneau",
                "poisson",
                "crevette",
                "thon",
                "saumon",
                "jambon",
                "lard",
            ]);
        }
        $items = [];
        foreach ($catalog as $meal) {
            $item = $this->serialize($meal, array_values(array_unique($wanted)), $goal);
            // Meals only: oils, sauces, drinks and desserts do not replace pre/post-session meals.
            $category = RemoteCache::normalize($item["category"]);
            $title = RemoteCache::normalize($item["name"]);
            if (
                in_array($category, ["dessert", "side", "starter"]) ||
                preg_match("/\b(oil|sauce|dressing|cocktail|drink|seasoning)\b/", $title) ||
                count($item["ingredients"]) < 3 ||
                trim($item["instructions"]) === ""
            ) {
                continue;
            }
            $haystack = implode(" ", array_column($item["ingredients"], "name"));
            $exclude = false;
            foreach ($excluded as $term) {
                foreach ($this->terms($term) as $variant) {
                    if ($this->phraseMatch($variant, $haystack)) {
                        $exclude = true;
                    }
                }
            }
            if ($exclude) {
                continue;
            }
            $mainMatches = [];
            foreach ($wanted as $term) {
                foreach ($item["ingredients"] as $ingredient) {
                    $name = RemoteCache::normalize($ingredient["name"]);
                    // Chicken stock does not make a lamb stew a chicken meal.
                    if (
                        preg_match(
                            "/\b(stock|broth|bouillon|cube|seasoning|extract|garnish)\b/",
                            $name,
                        )
                    ) {
                        continue;
                    }
                    foreach ($this->terms($term) as $variant) {
                        if ($this->phraseMatch($variant, $name)) {
                            $mainMatches[] = $term;
                        }
                    }
                }
            }
            $mainMatches = array_values(array_unique($mainMatches));
            if ($wanted && !$mainMatches) {
                continue;
            }
            $item["matched_pantry_items"] = $mainMatches;
            $item["matched_pantry"] = count($mainMatches);
            // An explicit ingredient must actually occur. No unrelated filler to reach ten.
            if ($wanted && $item["matched_pantry"] === 0) {
                continue;
            }
            $item["_score"] =
                $item["matched_pantry"] * 20 +
                $item["goal_scores"][$goal === "all" ? "maintain" : $goal];
            if (in_array("poulet", $wanted, true) && $category === "chicken") {
                $item["_score"] += 20;
            }
            $items[] = $item;
        }
        usort(
            $items,
            static fn($a, $b) => $b["_score"] <=> $a["_score"] ?: strcmp($a["id"], $b["id"]),
        );
        $selected = [];
        $categories = [];
        // Prefer variety among similarly relevant meals, not alphabetical order.
        while ($items && count($selected) < 40) {
            usort(
                $items,
                static fn($a, $b) => $b["_score"] - 2 * ($categories[$b["category"]] ?? 0) <=>
                $a["_score"] - 2 * ($categories[$a["category"]] ?? 0) ?:
                strcmp($a["id"], $b["id"]),
            );
            $next = array_shift($items);
            $categories[$next["category"]] = ($categories[$next["category"]] ?? 0) + 1;
            $selected[] = $next;
        }
        $selected = $this->translateItems($selected);
        $recipes = [];
        foreach ($selected as $item) {
            $recipes[] = [
                "title" => $item["name_fr"],
                "reason" => $wanted
                    ? "Ingrédients demandés retrouvés : " .
                        implode(", ", $item["matched_pantry_items"])
                    : "Repas classé selon les ingrédients et l’objectif, avec une préférence pour la variété.",
                "catalog_id" => $item["id"],
                "source_title" => $item["name"],
                "source_url" => $item["source"] ?: "https://www.themealdb.com/meal/" . $item["id"],
                "source_name" => "TheMealDB",
                "image" => $item["image"],
                "minutes" => null,
                "portions" => 2,
                "base_portions" => 2,
                "servings_known" => false,
                "ingredients" => array_map(
                    static fn($i) => trim($i["measure"] . " " . $i["name_fr"]),
                    $item["ingredients"],
                ),
                "steps" => [],
                "online" => true,
            ];
        }
        return [
            "recipes" => $recipes,
            "degraded" => $degraded,
            "available" => count($catalog),
            "note" => !$catalog
                ? "La source Internet est indisponible et aucun catalogue en cache n’est disponible. Aucune recette n’a été inventée."
                : (count($recipes) < 40
                    ? "Moins de 40 recettes correspondent aux critères dans les données disponibles."
                    : "40 repas sélectionnés par pertinence des ingrédients, objectif et variété ; ce classement n’est pas une note nutritionnelle vérifiée."),
        ];
    }

    public function details(string $id, string $ingredients = ""): ?array
    {
        if (str_starts_with($id, "wkb-")) {
            $meal = $this->wiki?->details($id);
            return $meal ? $this->escape($this->serialize($meal, [], "all")) : null;
        }
        if (!ctype_digit($id)) {
            return null;
        }
        $r = $this->cache->json(self::BASE . "lookup.php", ["i" => $id], 86400);
        $m = $r["data"]["meals"][0] ?? null;
        if (!is_array($m)) {
            return null;
        }
        $item = $this->translateItems([
            $this->serialize($m, $this->pantry($ingredients), "all"),
        ])[0];
        $source = $item["instructions_original"];
        $chunks = [];
        while ($source !== "" && count($chunks) < 10) {
            $chunk = mb_strcut($source, 0, 450, "UTF-8");
            $chunks[] = $chunk;
            $source = substr($source, strlen($chunk));
        }
        $translations = $this->translator->many($chunks);
        $item["instructions"] =
            implode("\n", $translations) .
            ($source !== "" ? "\n[…] Suite sur le site source." : "");
        $item["translation_note"] =
            "Préparation traduite automatiquement lorsque le service est disponible. Texte original consultable.";
        return $this->escape($item + ["stale" => $r["stale"]]);
    }
}
