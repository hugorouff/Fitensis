<?php

namespace App\Controller;

use App\Service\{GymStore, Nutrition, ExerciseCatalog, RecipeSearch};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class ToolsController extends AbstractController
{
    public function __construct(
        private GymStore $store,
        private Nutrition $nutrition,
        private ExerciseCatalog $exercises,
        private RecipeSearch $recipes,
    ) {}
    #[Route("/", name: "home", methods: ["GET"], defaults: ["page" => "index"])]
    #[Route("/outils", methods: ["GET"], defaults: ["page" => "index"])]
    #[Route("/exercises", methods: ["GET"], defaults: ["page" => "exercises"])]
    #[Route("/recipes", methods: ["GET"], defaults: ["page" => "recipes"])]
    public function page(string $page, CsrfTokenManagerInterface $csrf): Response
    {
        $path = $this->getParameter("kernel.project_dir") . "/data/" . $page . ".html";
        return new Response(
            str_replace(
                ["__CSRF__", "__USER__", "__FLASHES__", "__NAV__"],
                [
                    (string) $csrf->getToken("gym"),
                    (string) $this->store->user()["id"],
                    $this->renderView("_flashes.html.twig"),
                    $this->renderView("_navigation.html.twig"),
                ],
                file_get_contents($path),
            ),
        );
    }

    #[Route("/api/calculate/1rm", methods: ["POST"])]
    #[Route(
        "/api/nutrition/{action}",
        methods: ["POST"],
        requirements: ["action" => "calculate|calibrate"],
    )]
    public function calculate(Request $r, string $action = "1rm"): Response
    {
        try {
            $d = $r->toArray();
            if ($action === "1rm") {
                $w = $d["weight"] ?? 0;
                $reps = $d["reps"] ?? 0;
                if (
                    !is_numeric($w) ||
                    !is_numeric($reps) ||
                    $w <= 0 ||
                    $w > 1500 ||
                    $reps < 1 ||
                    $reps > 30 ||
                    floor((float) $reps) != (float) $reps
                ) {
                    throw new \InvalidArgumentException(
                        "Charge positive et 1 à 30 répétitions entières requises.",
                    );
                }
                $rm = $w * (1 + $reps / 30);
                $ranges = [];
                foreach (
                    [
                        "strength" => [0.85, 0.9],
                        "hypertrophy" => [0.65, 0.8],
                        "endurance" => [0.5, 0.65],
                    ]
                    as $g => [$min, $max]
                ) {
                    $ranges[$g] = ["min" => round($rm * $min, 1), "max" => round($rm * $max, 1)];
                }
                return $this->json([
                    "weight" => $w,
                    "reps" => $reps,
                    "estimated_1rm" => round($rm, 1),
                    "working_weights" => $ranges,
                ]);
            }
            return $this->json($this->nutrition->$action($d));
        } catch (\InvalidArgumentException | \Symfony\Component\HttpFoundation\Exception\JsonException $e) {
            return $this->json(["detail" => $e->getMessage()], 422);
        }
    }

    #[Route("/api/daily-menu", methods: ["GET"])]
    public function menu(Request $r): Response
    {
        try {
            return $this->json($this->nutrition->menu($r->query->all()));
        } catch (\InvalidArgumentException $e) {
            return $this->json(["detail" => $e->getMessage()], 422);
        }
    }

    #[Route("/api/internet-recipes", methods: ["GET"])]
    public function recipes(Request $r): Response
    {
        try {
            return $this->json($this->recipes->search($r->query->all()));
        } catch (\InvalidArgumentException $e) {
            return $this->json(["detail" => $e->getMessage()], 422);
        }
    }

    #[Route("/api/muscles", methods: ["GET"])]
    public function muscles(): Response
    {
        return $this->json($this->nutrition->catalog("MUSCLE_LABELS"));
    }

    #[Route("/api/exercises", methods: ["GET"])]
    public function exercises(Request $r): Response
    {
        $data = $this->exercises->all();
        $muscle = $r->query->get("muscle");
        $q = \App\Service\RemoteCache::normalize($r->query->get("search", ""));
        $secondary = $r->query->getBoolean("include_secondary", true);
        $data = array_values(
            array_filter(
                $data,
                fn($e) => (!$muscle ||
                    $e["primaryGroup"] === $muscle ||
                    ($secondary && in_array($muscle, $e["secondaryGroups"]))) &&
                    (!$q ||
                        str_contains(
                            \App\Service\RemoteCache::normalize(
                                $e["name"] .
                                    " " .
                                    ($e["name_fr"] ?? "") .
                                    " " .
                                    $e["target"] .
                                    " " .
                                    ($this->nutrition->catalog("MUSCLE_LABELS")[
                                        $e["primaryGroup"]
                                    ] ??
                                        "") .
                                    " " .
                                    $e["equipment"] .
                                    " " .
                                    $e["description"] .
                                    " " .
                                    implode(" ", $e["aliases"] ?? []),
                            ),
                            $q,
                        )),
            ),
        );
        $limit = max(1, min(500, $r->query->getInt("limit", 400)));
        return $this->json([
            "success" => true,
            "count" => min(count($data), $limit),
            "total" => count($data),
            "data" => array_slice($data, 0, $limit),
            "source_status" => $this->exercises->status(),
        ]);
    }

    #[Route("/api/custom-exercises", methods: ["GET", "POST"])]
    public function custom(Request $r): Response
    {
        $db = $this->store->db();
        $uid = $this->store->user()["id"];
        if ($r->isMethod("GET")) {
            $rows = $db->fetchAllAssociative("SELECT * FROM custom_exercises WHERE user_id=?", [
                $uid,
            ]);
            $data = array_map($this->exercises->custom(...), $rows);
            return $this->json(["success" => true, "count" => count($data), "data" => $data]);
        }
        try {
            $d = $r->toArray();
            $name = trim($d["name"] ?? "");
            $labels = $this->nutrition->catalog("MUSCLE_LABELS");
            $second = $d["secondary_muscles"] ?? [];
            if (
                mb_strlen($name) < 2 ||
                mb_strlen($name) > 200 ||
                !isset($labels[$d["primary_muscle"] ?? ""]) ||
                !is_array($second) ||
                array_diff($second, array_keys($labels))
            ) {
                throw new \InvalidArgumentException("Nom ou muscles invalides.");
            }
            $d["name"] = $name;
            $d["secondary_muscles"] = array_values(array_unique($second));
            foreach (["image_url", "male_video_url", "female_video_url"] as $f) {
                if (!empty($d[$f]) && !preg_match('~^https?://[^\s<>"\x27]+$~i', $d[$f])) {
                    throw new \InvalidArgumentException("URL HTTP ou HTTPS requise.");
                }
            }
            foreach (["name", "equipment", "description"] as $f) {
                if (isset($d[$f])) {
                    $d[$f] = mb_substr($d[$f], 0, 5000);
                }
            }
            $db->insert("custom_exercises", [
                "user_id" => $uid,
                "payload" => json_encode($d, JSON_THROW_ON_ERROR),
            ]);
            return $this->json(
                [
                    "success" => true,
                    "data" => $this->exercises->custom([
                        "id" => $db->lastInsertId(),
                        "user_id" => $uid,
                        "payload" => json_encode($d),
                    ]),
                ],
                201,
            );
        } catch (\InvalidArgumentException | \Symfony\Component\HttpFoundation\Exception\JsonException $e) {
            return $this->json(["detail" => $e->getMessage()], 422);
        }
    }

    #[Route("/api/custom-exercises/{id}", methods: ["DELETE"])]
    public function remove(int $id): Response
    {
        $count = $this->store
            ->db()
            ->delete("custom_exercises", ["id" => $id, "user_id" => $this->store->user()["id"]]);
        return $count
            ? $this->json(["success" => true])
            : $this->json(["detail" => "Exercice introuvable."], 404);
    }

    #[Route("/api/exercises/{id}/details", methods: ["GET"])]
    public function exerciseDetails(string $id): Response
    {
        $item = $this->exercises->details($id);
        return $item
            ? $this->json(["success" => true, "data" => $item])
            : $this->json(["detail" => "Exercice introuvable."], 404);
    }

    #[Route(
        "/api/recipes/{id}/details",
        methods: ["GET"],
        requirements: ["id" => "(?:[0-9]+|wkb-[0-9]+)"],
    )]
    public function recipeDetails(string $id, Request $r): Response
    {
        $item = $this->recipes->details($id, $r->query->getString("ingredients"));
        return $item
            ? $this->json(["success" => true, "data" => $item])
            : $this->json(["detail" => "Recette indisponible."], 404);
    }

    #[Route("/api/exercises/source-status", methods: ["GET"])]
    public function sources(): Response
    {
        return $this->json($this->exercises->status());
    }

    #[Route("/api/exercises/audit", methods: ["GET"])]
    public function exerciseAudit(): Response
    {
        $all = $this->exercises->all();
        $remote = array_filter($all, fn($e) => $e["source"] === "remote");
        return $this->json([
            "remoteCount" => count($remote),
            "mappedPrimaryCount" => count(
                array_filter($remote, fn($e) => $e["primaryGroup"] !== null),
            ),
            "unknownTargets" => new \stdClass(),
            "unknownSecondaryMuscles" => new \stdClass(),
        ]);
    }

    #[Route("/api/recipes/audit", methods: ["GET"])]
    public function recipeAudit(): Response
    {
        return $this->json([
            "source" => "TheMealDB",
            "nutrition_verified" => false,
            "translation" =>
                "Dictionnaire local et traduction distante en cache, texte source conservé",
            "templates" => count($this->nutrition->catalog("MEAL_TEMPLATES")),
        ]);
    }
}
