<?php

namespace App\Controller;

use App\Service\{GymStore, Nutrition, PerformanceStats};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

class TrainingController extends AbstractController
{
    public const PRESETS = [
        "upper" => [
            "label" => "Haut du corps",
            "muscles" => ["chest", "back", "shoulders", "biceps", "triceps"],
        ],
        "lower" => [
            "label" => "Bas du corps / jambes",
            "muscles" => ["quads", "hamstrings", "glutes", "calves"],
        ],
        "push" => [
            "label" => "Push · pectoraux, épaules, triceps",
            "muscles" => ["chest", "shoulders", "triceps"],
        ],
        "pull" => [
            "label" => "Pull · dos, biceps, avant-bras",
            "muscles" => ["back", "biceps", "forearms"],
        ],
        "fullbody" => [
            "label" => "Full body",
            "muscles" => [
                "chest",
                "back",
                "shoulders",
                "quads",
                "hamstrings",
                "glutes",
                "abs",
            ],
        ],
        "core" => [
            "label" => "Abdos & gainage",
            "muscles" => ["abs", "obliques", "lowerback"],
        ],
        "custom" => ["label" => "Séance personnalisée", "muscles" => []],
    ];
    public function __construct(
        private GymStore $store,
        private Nutrition $nutrition,
    ) {}
    private function own(int $id): array
    {
        $s = $this->store
            ->db()
            ->fetchAssociative(
                "SELECT * FROM training_sessions WHERE id=? AND user_id=?",
                [$id, $this->store->user()["id"]],
            );
        if (!$s) {
            throw $this->createNotFoundException("Séance introuvable.");
        }
        $s["muscle_ids"] = json_decode($s["muscles"], true) ?: [];
        return $s;
    }

    private function n(
        mixed $v,
        float $min,
        float $max,
        bool $integer = false,
    ): float|int {
        if (
            !is_numeric($v) ||
            !is_finite((float) $v) ||
            $v < $min ||
            $v > $max ||
            ($integer && floor((float) $v) != (float) $v)
        ) {
            throw new \InvalidArgumentException(
                "Valeur attendue entre $min et $max.",
            );
        }
        return $integer ? (int) $v : (float) $v;
    }

    private function text(string $v): string
    {
        $v = trim($v);
        if ($v === "" || mb_strlen($v) > 250) {
            throw new \InvalidArgumentException(
                "Renseigne un nom de 1 à 250 caractères.",
            );
        }
        return $v;
    }

    private function finish(callable $fn, string $back): Response
    {
        try {
            $fn();
            $this->addFlash("success", "Enregistré.");
        } catch (\InvalidArgumentException $e) {
            $this->addFlash("error", $e->getMessage());
        }
        return $this->redirect($back);
    }

    #[Route("/calendrier", name: "calendar", methods: ["GET"])]
    public function calendar(Request $r): Response
    {
        $date = \DateTimeImmutable::createFromFormat(
            "!Y-m-d",
            $r->query->getString("date", date("Y-m-d")),
        );
        if (!$date) {
            $date = new \DateTimeImmutable("today");
        }
        $start = $date->modify("monday this week");
        $end = $start->modify("+7 days");
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $days[] = $start->modify("+$i days");
        }
        $sessions = $this->store
            ->db()
            ->fetchAllAssociative(
                "SELECT s.*,(SELECT COUNT(*) FROM training_items i WHERE i.session_id=s.id) exercise_count,(SELECT COALESCE(SUM(w.sets*w.reps*w.weight),0) FROM workouts w WHERE w.session_id=s.id AND w.user_id=s.user_id) volume FROM training_sessions s WHERE user_id=? AND starts_at>=? AND starts_at<? ORDER BY starts_at",
                [
                    $this->store->user()["id"],
                    $start->format("Y-m-d"),
                    $end->format("Y-m-d"),
                ],
            );
        foreach ($sessions as &$s) {
            $s["muscle_ids"] = json_decode($s["muscles"], true) ?: [];
        }
        unset($s);
        $edit = $r->query->getInt("edit")
            ? $this->own($r->query->getInt("edit"))
            : null;
        return $this->render("calendar.html.twig", [
            "sessions" => $sessions,
            "days" => $days,
            "start" => $start,
            "prev" => $start->modify("-7 days"),
            "next" => $end,
            "presets" => self::PRESETS,
            "muscles" => $this->nutrition->catalog("MUSCLE_LABELS"),
            "edit" => $edit,
            "selectedDate" => $date->format("Y-m-d"),
            "recentExercises" => $edit ? [] : $this->store->db()->fetchFirstColumn(
                "SELECT exercise FROM workouts WHERE user_id=? ORDER BY date DESC, id DESC LIMIT 30",
                [$this->store->user()["id"]],
            ),
            "initialGroups" => isset(
                $this->nutrition->catalog("MUSCLE_LABELS")[
                    $r->query->getString("muscle")
                ],
            )
                ? [$r->query->getString("muscle")]
                : self::PRESETS["upper"]["muscles"],
            "initialPreset" => isset(
                $this->nutrition->catalog("MUSCLE_LABELS")[
                    $r->query->getString("muscle")
                ],
            )
                ? "custom"
                : "upper",
            "exercise" => mb_substr($r->query->getString("exercise"), 0, 250),
        ]);
    }

    #[Route("/entrainements/enregistrer", methods: ["POST"])]
    public function save(Request $r): Response
    {
        $id = (int) $r->request->getString("id", "0");
        if ($id) {
            $this->own($id);
        }
        $created = 0;
        try {
            $preset = $r->request->getString("preset", "custom");
            if (!isset(self::PRESETS[$preset])) {
                throw new \InvalidArgumentException("Type de séance invalide.");
            }
            $day = $r->request->getString("date");
            $time = $r->request->getString("time", "18:00");
            $start = \DateTimeImmutable::createFromFormat(
                "!Y-m-d H:i",
                $day . " " . $time,
            );
            if (!$start || $start->format("Y-m-d H:i") !== $day . " " . $time) {
                throw new \InvalidArgumentException("Date ou heure invalide.");
            }
            $groups = $r->request->all("muscles");
            $labels = $this->nutrition->catalog("MUSCLE_LABELS");
            if (!$groups) {
                $groups = self::PRESETS[$preset]["muscles"];
            }
            if (
                !$groups ||
                array_filter(
                    $groups,
                    fn($g) => !is_string($g) || !isset($labels[$g]),
                )
            ) {
                throw new \InvalidArgumentException(
                    "Choisis au moins une partie du corps à entraîner.",
                );
            }
            $title = $r->request->getString("title");
            if (trim($title) === "") {
                $title = self::PRESETS[$preset]["label"];
            }
            $row = [
                "user_id" => $this->store->user()["id"],
                "title" => $this->text($title),
                "starts_at" => $start->format("Y-m-d H:i"),
                "duration_minutes" => $this->n(
                    $r->request->get("duration", 60),
                    10,
                    300,
                    true,
                ),
                "preset" => $preset,
                "muscles" => json_encode(array_values(array_unique($groups))),
                "notes" => mb_substr($r->request->getString("notes"), 0, 5000),
            ];
            $repeat = $id
                ? 1
                : $this->n($r->request->get("repeat", 1), 1, 12, true);
            $exercise = trim($r->request->getString("exercise"));
            $items = $r->request->all("items");
            if (count($items) > 20) {
                throw new \InvalidArgumentException("Ajoute au maximum 20 exercices par séance.");
            }
            $exercises = [];
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item["name"] ?? null)) {
                    throw new \InvalidArgumentException("Exercice invalide.");
                }
                $exercises[] = [
                    "exercise" => $this->text($item["name"]),
                    "sets" => $this->n($item["sets"] ?? null, 1, 50, true),
                    "reps" => $this->n($item["reps"] ?? null, 1, 200, true),
                    "weight" => $this->n($item["weight"] ?? null, 0, 1000),
                ];
            }
            if ($exercise !== "") {
                $exercises[] = ["exercise" => $this->text($exercise), "sets" => 3, "reps" => 10, "weight" => 0];
            }
            $this->store
                ->db()
                ->transactional(function ($db) use (
                    $id,
                    $repeat,
                    $row,
                    $start,
                    $exercises,
                    &$created,
                ) {
                    for ($i = 0; $i < $repeat; $i++) {
                        $data = $row;
                        $data["starts_at"] = $start
                            ->modify("+$i weeks")
                            ->format("Y-m-d H:i");
                        if ($id) {
                            $db->update("training_sessions", $data, [
                                "id" => $id,
                            ]);
                            $sid = $id;
                        } else {
                            $sid = $this->store->insertAndGetId("training_sessions", $data);
                        }
                        if (!$created) {
                            $created = $sid;
                        }
                        foreach ($exercises as $exerciseRow) {
                            $db->insert("training_items", ["session_id" => $sid] + $exerciseRow);
                        }
                    }
                });
            $this->addFlash(
                "success",
                "Séance personnelle enregistrée. Ajoute tes exercices et note tes performances.",
            );
            return $this->redirect("/entrainements/" . $created);
        } catch (\InvalidArgumentException $e) {
            $this->addFlash("error", $e->getMessage());
            return $this->redirect("/calendrier");
        }
    }

    #[Route(
        "/entrainements/{id}",
        name: "training_show",
        methods: ["GET"],
        requirements: ["id" => "[0-9]+"],
    )]
    public function show(int $id, Request $r): Response
    {
        $session = $this->own($id);
        $db = $this->store->db();
        $uid = $this->store->user()["id"];
        $items = $db->fetchAllAssociative(
            "SELECT i.* FROM training_items i WHERE i.session_id=? AND NOT EXISTS (SELECT 1 FROM workouts w WHERE w.session_id=i.session_id AND w.user_id=? AND LOWER(TRIM(w.exercise))=LOWER(TRIM(i.exercise))) ORDER BY i.id",
            [$id, $uid],
        );
        foreach ($items as &$i) {
            $i["previous"] =
                $db->fetchAssociative(
                    "SELECT date,sets,reps,weight FROM workouts WHERE user_id=? AND LOWER(exercise)=LOWER(?) AND (session_id IS NULL OR session_id!=?) ORDER BY date DESC,id DESC LIMIT 1",
                    [$uid, $i["exercise"], $id],
                ) ?:
                null;
        }
        unset($i);
        $logs = $db->fetchAllAssociative(
            "SELECT * FROM workouts WHERE user_id=? AND session_id=? ORDER BY id",
            [$uid, $id],
        );
        $volume = 0;
        foreach ($logs as $w) {
            $volume += $w["sets"] * $w["reps"] * $w["weight"];
        }
        return $this->render("training.html.twig", [
            "session" => $session,
            "items" => $items,
            "logs" => $logs,
            "volume" => $volume,
            "muscles" => $this->nutrition->catalog("MUSCLE_LABELS"),
            "exercise" => mb_substr($r->query->getString("exercise"), 0, 250),
        ]);
    }

    #[Route("/entrainements/{id}/exercices", methods: ["POST"])]
    public function addItem(int $id, Request $r): Response
    {
        $this->own($id);
        return $this->finish(function () use ($id, $r) {
            $db = $this->store->db();
            $exercise = $this->text($r->request->getString("exercise"));
            if ($db->fetchOne("SELECT COUNT(*) FROM workouts WHERE session_id=? AND user_id=? AND LOWER(TRIM(exercise))=LOWER(TRIM(?))", [$id, $this->store->user()["id"], $exercise])) {
                throw new \InvalidArgumentException("Cet exercice est déjà terminé dans cette séance.");
            }
            $db->insert("training_items", [
                "session_id" => $id,
                "exercise" => $exercise,
                "sets" => $this->n($r->request->get("sets"), 1, 50, true),
                "reps" => $this->n($r->request->get("reps"), 1, 200, true),
                "weight" => $this->n($r->request->get("weight"), 0, 1000),
            ]);
        }, "/entrainements/" . $id);
    }

    #[Route(
        "/entrainements/{id}/exercices/{item}/supprimer",
        methods: ["POST"],
    )]
    public function removeItem(int $id, int $item): Response
    {
        $this->own($id);
        $this->store
            ->db()
            ->delete("training_items", ["id" => $item, "session_id" => $id]);
        return $this->redirect("/entrainements/" . $id);
    }

    #[Route("/entrainements/{id}/performances", methods: ["POST"])]
    public function log(int $id, Request $r): Response
    {
        $session = $this->own($id);
        return $this->finish(function () use ($id, $r, $session) {
            $db = $this->store->db();
            $data = [
                "user_id" => $this->store->user()["id"],
                "session_id" => $id,
                "date" => substr($session["starts_at"], 0, 10),
                "exercise" => $this->text($r->request->getString("exercise")),
                "sets" => $this->n($r->request->get("sets"), 1, 50, true),
                "reps" => $this->n($r->request->get("reps"), 1, 200, true),
                "weight" => $this->n($r->request->get("weight"), 0, 1000),
                "rpe" => $this->n($r->request->get("rpe", 7), 1, 10),
                "notes" => mb_substr($r->request->getString("notes"), 0, 5000),
            ];
            $logId = $r->request->getInt("log_id");
            if ($logId) {
                $old = $db->fetchOne(
                    "SELECT id FROM workouts WHERE id=? AND user_id=? AND session_id=?",
                    [$logId, $data["user_id"], $id],
                );
                if (!$old) {
                    throw $this->createNotFoundException();
                }
                $db->update("workouts", $data, ["id" => $logId]);
            } else {
                $itemId = $r->request->getInt("item_id");
                $db->transactional(function ($db) use ($id, $itemId, $data) {
                    // Serialize completion of the same session on PostgreSQL.
                    $db->executeStatement("UPDATE training_sessions SET id=id WHERE id=?", [$id]);
                    if ($db->fetchOne("SELECT COUNT(*) FROM workouts WHERE session_id=? AND user_id=? AND LOWER(TRIM(exercise))=LOWER(TRIM(?))", [$id, $data["user_id"], $data["exercise"]])) {
                        throw new \InvalidArgumentException("Cet exercice est déjà terminé dans cette séance.");
                    }
                    if ($itemId && !$db->fetchOne("SELECT id FROM training_items WHERE id=? AND session_id=? AND LOWER(TRIM(exercise))=LOWER(TRIM(?))", [$itemId, $id, $data["exercise"]])) {
                        throw new \InvalidArgumentException("Cet exercice a été retiré de la séance.");
                    }
                    $db->insert("workouts", $data);
                    $db->executeStatement("DELETE FROM training_items WHERE session_id=? AND LOWER(TRIM(exercise))=LOWER(TRIM(?))", [$id, $data["exercise"]]);
                });
            }
        }, "/entrainements/" . $id);
    }

    #[Route(
        "/entrainements/{id}/performances/{log}/supprimer",
        methods: ["POST"],
    )]
    public function removeLog(int $id, int $log): Response
    {
        $this->own($id);
        $this->store->db()->delete("workouts", [
            "id" => $log,
            "session_id" => $id,
            "user_id" => $this->store->user()["id"],
        ]);
        return $this->redirect("/entrainements/" . $id);
    }

    #[Route("/entrainements/{id}/etat", methods: ["POST"])]
    public function state(int $id, Request $r): Response
    {
        $this->own($id);
        return $this->finish(function () use ($id, $r) {
            $status = $r->request->getString("status");
            if (!in_array($status, ["planned", "completed", "skipped"])) {
                throw new \InvalidArgumentException("État invalide.");
            }
            $this->store
                ->db()
                ->update(
                    "training_sessions",
                    ["status" => $status],
                    ["id" => $id],
                );
        }, "/entrainements/" . $id);
    }

    #[Route("/entrainements/{id}/dupliquer", methods: ["POST"])]
    public function duplicate(int $id): Response
    {
        $s = $this->own($id);
        unset($s["id"], $s["muscle_ids"]);
        $s["starts_at"] = (new \DateTimeImmutable($s["starts_at"]))
            ->modify("+7 days")
            ->format("Y-m-d H:i");
        $s["status"] = "planned";
        $new = $this->store->db()->transactional(function ($db) use ($s, $id) {
            $new = $this->store->insertAndGetId("training_sessions", $s);
            foreach (
                $db->fetchAllAssociative(
                    "SELECT exercise,sets,reps,weight FROM training_items WHERE session_id=?",
                    [$id],
                )
                as $i
            ) {
                $db->insert("training_items", ["session_id" => $new] + $i);
            }
            return $new;
        });
        return $this->redirect("/entrainements/" . $new);
    }

    #[Route("/entrainements/{id}/supprimer", methods: ["POST"])]
    public function delete(int $id): Response
    {
        $this->own($id);
        $this->store->db()->delete("training_sessions", ["id" => $id]);
        $this->addFlash(
            "success",
            "Planning supprimé. Les performances restent dans ton carnet.",
        );
        return $this->redirect("/calendrier");
    }

    #[Route("/performances", name: "performances", methods: ["GET"])]
    public function performance(Request $r, PerformanceStats $stats): Response
    {
        $data = $stats->dashboard(
            $r->query->getString("exercise"),
            $r->query->getString("period", "all"),
        );
        $metric = $r->query->getString("metric", "weight");
        if (!isset(PerformanceStats::METRICS[$metric])) {
            $metric = "weight";
        }
        $data["metric"] = $metric;
        $data["metrics"] = PerformanceStats::METRICS;
        $data["line"] = $stats->line(
            $data["history"],
            $metric,
            PerformanceStats::METRICS[$metric][1],
        );
        $data["body_line"] = $stats->line($data["body"], "weight", "kg");
        return $this->render("performances.html.twig", $data);
    }

    #[Route("/calendrier.ics", methods: ["GET"])]
    public function ics(): Response
    {
        $rows = $this->store
            ->db()
            ->fetchAllAssociative(
                "SELECT * FROM training_sessions WHERE user_id=? AND status!='skipped' ORDER BY starts_at",
                [$this->store->user()["id"]],
            );
        $text =
            "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Gym App//FR\r\nCALSCALE:GREGORIAN\r\n";
        $escape = fn($v) => str_replace(
            ["\\", "\r", "\n", ",", ";"],
            ["\\\\", "", "\\n", "\\,", "\\;"],
            $v,
        );
        foreach ($rows as $s) {
            $date = new \DateTimeImmutable(
                $s["starts_at"],
                new \DateTimeZone("Europe/Paris"),
            );
            $start = $date
                ->setTimezone(new \DateTimeZone("UTC"))
                ->format("Ymd\THis\Z");
            $end = $date
                ->modify("+" . $s["duration_minutes"] . " minutes")
                ->setTimezone(new \DateTimeZone("UTC"))
                ->format("Ymd\THis\Z");
            $text .=
                "BEGIN:VEVENT\r\nUID:training-{$s["id"]}@gym.local\r\nDTSTAMP:" .
                gmdate("Ymd\THis\Z") .
                "\r\nDTSTART:$start\r\nDTEND:$end\r\nSUMMARY:" .
                $escape($s["title"]) .
                "\r\nEND:VEVENT\r\n";
        }
        return new Response($text . "END:VCALENDAR\r\n", 200, [
            "Content-Type" => "text/calendar; charset=UTF-8",
            "Content-Disposition" =>
                'attachment; filename="mes-entrainements.ics"',
        ]);
    }
}
