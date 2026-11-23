<?php

namespace App\Service;

final class PerformanceStats
{
    public const METRICS = [
        "weight" => ["Charge maximale", "kg"],
        "volume" => ["Volume réalisé", "kg"],
        "rm" => ["1RM estimé", "kg"],
        "sets" => ["Séries réalisées", "séries"],
        "reps" => ["Répétitions réalisées", "répétitions"],
    ];
    public function __construct(private GymStore $store) {}

    public function dashboard(string $exercise, string $period): array
    {
        $uid = $this->store->user()["id"];
        $period = in_array($period, ["30", "90", "365", "all"], true)
            ? $period
            : "all";
        $from =
            $period === "all"
                ? null
                : (new \DateTimeImmutable("today"))
                    ->modify("-" . ((int) $period - 1) . " days")
                    ->format("Y-m-d");
        $where = "user_id=?" . ($from ? " AND date>=?" : "");
        $args = $from ? [$uid, $from] : [$uid];
        return $this->build(
            $this->store
                ->db()
                ->iterateAssociative(
                    "SELECT date,exercise,sets,reps,weight FROM workouts WHERE " .
                        $where .
                        " ORDER BY date,id",
                    $args,
                ),
            $this->store
                ->db()
                ->iterateAssociative(
                    "SELECT date,weight FROM measurements WHERE " .
                        $where .
                        " ORDER BY date,id",
                    $args,
                ),
            $exercise,
        ) + ["period" => $period];
    }

    public function build(
        iterable $logs,
        iterable $measurements,
        string $exercise = "",
    ): array {
        $groups = [];
        $days = [];
        $weeks = [];
        $daily = [];
        $totalSets = 0;
        $totalVolume = 0;
        foreach ($logs as $row) {
            $key = mb_strtolower(trim($row["exercise"]));
            $date = $row["date"];
            $sets = (int) $row["sets"];
            $reps = (int) $row["reps"];
            $weight = (float) $row["weight"];
            $volume = $sets * $reps * $weight;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    "exercise" => $row["exercise"],
                    "entries" => 0,
                    "best_weight" => 0,
                    "best_rm" => null,
                    "volume" => 0,
                    "sets" => 0,
                    "dates" => [],
                ];
            }
            $g = &$groups[$key];
            $g["entries"]++;
            $g["sets"] += $sets;
            $g["volume"] += $volume;
            $g["best_weight"] = max($g["best_weight"], $weight);
            $rm = $reps <= 30 ? $weight * (1 + $reps / 30) : null;
            if ($rm !== null) {
                $g["best_rm"] = max($g["best_rm"] ?? 0, $rm);
            }
            $g["dates"][$date] = true;
            unset($g);
            $days[$date] = true;
            $totalSets += $sets;
            $totalVolume += $volume;
            $monday = (new \DateTimeImmutable($date))
                ->modify("monday this week")
                ->format("Y-m-d");
            if (!isset($weeks[$monday])) {
                $weeks[$monday] = [
                    "date" => $monday,
                    "days" => [],
                    "volume" => 0,
                    "sets" => 0,
                ];
            }
            $weeks[$monday]["days"][$date] = true;
            $weeks[$monday]["volume"] += $volume;
            $weeks[$monday]["sets"] += $sets;
            if (!isset($daily[$key][$date])) {
                $daily[$key][$date] = [
                    "date" => $date,
                    "weight" => 0,
                    "volume" => 0,
                    "sets" => 0,
                    "reps" => 0,
                    "rm" => null,
                ];
            }
            $d = &$daily[$key][$date];
            $d["weight"] = max($d["weight"], $weight);
            $d["volume"] += $volume;
            $d["sets"] += $sets;
            $d["reps"] += $sets * $reps;
            if ($rm !== null) {
                $d["rm"] = max($d["rm"] ?? 0, $rm);
            }
            unset($d);
        }
        ksort($groups);
        $records = [];
        foreach ($groups as $g) {
            $g["days"] = count($g["dates"]);
            unset($g["dates"]);
            $records[] = $g;
        }
        $selected = mb_strtolower(trim($exercise));
        if (!isset($groups[$selected])) {
            $selected = array_key_first($groups);
        }
        $exercise = $selected !== null ? $groups[$selected]["exercise"] : "";
        $history = $selected !== null ? $daily[$selected] : [];
        ksort($history);
        $history = array_values($history);
        $frequency = $records;
        usort(
            $frequency,
            static fn($a, $b) => $b["days"] <=> $a["days"] ?:
            $b["sets"] <=> $a["sets"] ?:
            strcmp($a["exercise"], $b["exercise"]),
        );
        $frequency = array_slice($frequency, 0, 8);
        $weekly = [];
        if ($weeks) {
            ksort($weeks);
            $keys = array_keys($weeks);
            $last = new \DateTimeImmutable(end($keys));
            $start = $last->modify("-11 weeks");
            $earliest = new \DateTimeImmutable($keys[0]);
            if ($start < $earliest) {
                $start = $earliest;
            }
            for (
                $week = $start;
                $week <= $last;
                $week = $week->modify("+1 week")
            ) {
                $key = $week->format("Y-m-d");
                $row = $weeks[$key] ?? [
                    "date" => $key,
                    "days" => [],
                    "volume" => 0,
                    "sets" => 0,
                ];
                $row["days"] = count($row["days"]);
                $row["label"] = $week->format("d/m");
                $weekly[] = $row;
            }
        }
        $body = [];
        foreach ($measurements as $row) {
            $body[$row["date"]] = [
                "date" => $row["date"],
                "weight" => (float) $row["weight"],
            ];
        }
        ksort($body);
        return [
            "records" => $records,
            "exercise" => $exercise,
            "history" => array_slice($history, -365),
            "frequency" => $frequency,
            "weekly" => $weekly,
            "body" => array_slice(array_values($body), -365),
            "total_days" => count($days),
            "total_sets" => $totalSets,
            "total_volume" => $totalVolume,
            "total_exercises" => count($records),
            "exercise_days" => count($history),
        ];
    }

    public function line(array $rows, string $field, string $unit): array
    {
        $values = array_values(
            array_filter($rows, static fn($row) => isset($row[$field])),
        );
        if (!$values) {
            return [
                "points" => [],
                "polyline" => "",
                "segments" => [],
                "max" => 1,
                "unit" => $unit,
                "first" => null,
                "last" => null,
                "change" => null,
            ];
        }
        $max = max(1, max(array_column($values, $field)) * 1.08);
        $points = [];
        $segments = [];
        $segment = [];
        $count = count($rows);
        foreach ($rows as $i => $row) {
            if (!isset($row[$field])) {
                if ($segment) {
                    $segments[] = implode(" ", $segment);
                }
                $segment = [];
                continue;
            }
            $value = (float) $row[$field];
            $x = $count === 1 ? 300 : 48 + ($i * 500) / ($count - 1);
            $y = 180 - ($value / $max) * 150;
            $point = [
                "date" => $row["date"],
                "label" => (new \DateTimeImmutable($row["date"]))->format(
                    "d/m/Y",
                ),
                "value" => $value,
                "display" => number_format($value, 1, ",", " ") . " " . $unit,
                "x" => round($x, 2),
                "y" => round($y, 2),
            ];
            $points[] = $point;
            $segment[] = $point["x"] . "," . $point["y"];
        }
        if ($segment) {
            $segments[] = implode(" ", $segment);
        }
        return [
            "points" => $points,
            "segments" => $segments,
            "polyline" => implode(
                " ",
                array_map(static fn($p) => $p["x"] . "," . $p["y"], $points),
            ),
            "max" => $max,
            "unit" => $unit,
            "first" => $points[0],
            "last" => $points[array_key_last($points)],
            "change" =>
                $points[array_key_last($points)]["value"] - $points[0]["value"],
        ];
    }
}
