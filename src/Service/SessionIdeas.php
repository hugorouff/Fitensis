<?php

namespace App\Service;

/** Alternative choices, independently selectable; never a scheduled programme by default. */
final class SessionIdeas
{
    public function create(
        array $blocks,
        string $constraints,
        array $catalog,
        int $wanted = 5,
    ): array {
        if ($wanted < 1 || $wanted > 5) {
            throw new AiException("Choisis entre une et cinq idées de séance.", 422);
        }
        $brief = new TrainingBrief();
        $ideas = [];
        $seen = [];
        for ($variant = 0; $variant < 24 && count($ideas) < $wanted; $variant++) {
            $sessions = $brief->sessions($blocks, $constraints, $catalog, $variant);
            $names = [];
            foreach ($sessions as $session) {
                $set = array_map(
                    static fn($exercise) => RemoteCache::normalize($exercise["name"]),
                    $session["exercises"],
                );
                sort($set);
                $names[] = [$session["muscles"], $session["duration_minutes"], $set];
            }
            $signature = json_encode($names);
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $number = count($ideas) + 1;
            foreach ($sessions as &$session) {
                $session["idea_index"] = $number;
                $session["title"] = "Idée " . $number . " · " . $session["title"];
                $session["reason"] =
                    "Une alternative au choix, avec une combinaison d’exercices différente. " .
                    $session["reason"];
            }
            unset($session);
            $ideas[] = $sessions;
        }
        return [
            "sessions" => $ideas ? array_merge(...$ideas) : [],
            "count" => count($ideas),
            "note" =>
                count($ideas) < $wanted
                    ? "Le catalogue et le matériel permettent " .
                        count($ideas) .
                        " combinaison(s) distincte(s). Les doublons ne sont pas affichés."
                    : "Ces " .
                        count($ideas) .
                        " idées sont des alternatives. Choisis celles qui te conviennent ; aucune n’est ajoutée automatiquement.",
        ];
    }

    public function inferBlocks(array $sessions): array
    {
        $blocks = [];
        foreach ($sessions as $session) {
            $muscles = $session["muscles"];
            if (count($muscles) === 1) {
                $group = $muscles[0];
            } elseif (!array_diff($muscles, ["biceps", "triceps"])) {
                $group = "arms";
            } elseif (!array_diff($muscles, ["chest", "back", "shoulders", "biceps", "triceps"])) {
                $group = "upper";
            } elseif (!array_diff($muscles, ["quads", "hamstrings", "glutes", "calves"])) {
                $group = "lower";
            } else {
                $group = "full";
            }
            $blocks[] = ["group" => $group, "minutes" => $session["duration_minutes"]];
        }
        // Free-form output represents options; use the first proposal's constraints as the brief.
        return array_slice($blocks, 0, 1);
    }
}
