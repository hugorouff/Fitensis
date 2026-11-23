<?php

namespace App\Service;

use PhpOffice\PhpSpreadsheet\{
    Spreadsheet,
    Cell\DataType,
    Reader\Xlsx as XlsxReader,
    Reader\Csv,
    Shared\Date as ExcelDate,
    Style\Fill,
    Writer\Xlsx,
};
use PhpOffice\PhpSpreadsheet\Chart\{
    Chart,
    DataSeries,
    DataSeriesValues,
    PlotArea,
    Legend,
    Title,
};

final class PerformanceExcel
{
    public const MAX_ROWS = 2000;
    public const MAX_BYTES = 5242880;
    public function __construct(private GymStore $store) {}

    private function owner(): int
    {
        $id = $this->store->user()["id"] ?? null;
        if (!$id) {
            throw new \InvalidArgumentException("Connexion requise.");
        }
        return (int) $id;
    }

    private function number(
        mixed $value,
        float $min,
        float $max,
        bool $integer = false,
    ): float|int {
        if (is_string($value)) {
            $value = str_replace(
                ["\u{00A0}", " ", ","],
                ["", "", "."],
                trim($value),
            );
        }
        if (
            !is_numeric($value) ||
            !is_finite((float) $value) ||
            $value < $min ||
            $value > $max ||
            ($integer && floor((float) $value) != (float) $value)
        ) {
            throw new \InvalidArgumentException(
                "Valeur attendue entre $min et $max" .
                    ($integer ? " (entier)." : "."),
            );
        }
        return $integer ? (int) $value : (float) $value;
    }

    private function date(mixed $value): string
    {
        if (is_numeric($value)) {
            if (
                (float) $value < 1 ||
                (float) $value > 73415 ||
                (int) $value === 60
            ) {
                throw new \InvalidArgumentException("Date Excel invalide.");
            }
            $value = ExcelDate::excelToDateTimeObject((float) $value)->format(
                "Y-m-d",
            );
        }
        foreach (["!Y-m-d", "!d/m/Y", "!d-m-Y"] as $format) {
            $date = \DateTimeImmutable::createFromFormat(
                $format,
                (string) $value,
            );
            if (
                $date &&
                $date->format(substr($format, 1)) === (string) $value &&
                (int) $date->format("Y") >= 1900 &&
                (int) $date->format("Y") <= 2100
            ) {
                return $date->format("Y-m-d");
            }
        }
        throw new \InvalidArgumentException(
            "Date attendue au format AAAA-MM-JJ ou JJ/MM/AAAA.",
        );
    }

    private function inspectZip(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException(
                "Ce fichier n’est pas un classeur XLSX valide.",
            );
        }
        try {
            $total = 0;
            if ($zip->numFiles > 1000) {
                throw new \InvalidArgumentException(
                    "Le classeur contient trop de fichiers internes.",
                );
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $file = $zip->statIndex($i);
                $total += $file["size"];
                if (
                    $file["size"] > 10485760 ||
                    $total > 31457280 ||
                    preg_match(
                        '~vbaproject|externallinks|\.bin$~i',
                        $file["name"],
                    )
                ) {
                    throw new \InvalidArgumentException(
                        "Classeur trop volumineux ou contenant des éléments non pris en charge. Utilise le modèle XLSX.",
                    );
                }
                if (str_ends_with(strtolower($file["name"]), ".xml")) {
                    $xml = $zip->getFromIndex($i);
                    if (preg_match("/<!DOCTYPE|<!ENTITY/i", $xml)) {
                        throw new \InvalidArgumentException(
                            "Contenu XML non pris en charge.",
                        );
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

    public function preview(
        string $path,
        string $filename,
        float $defaultRpe = 7,
    ): array {
        $this->owner();
        $this->number($defaultRpe, 1, 10);
        if (!is_file($path) || filesize($path) > self::MAX_BYTES) {
            throw new \InvalidArgumentException("Fichier limité à 5 Mo.");
        }
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, ["xlsx", "csv"], true)) {
            throw new \InvalidArgumentException(
                "Formats acceptés : XLSX et CSV. Les anciens XLS et les fichiers avec macros ne sont pas acceptés.",
            );
        }
        if ($extension === "xlsx") {
            $this->inspectZip($path);
            $reader = new XlsxReader();
        } else {
            $reader = new Csv();
            $sample = (string) file_get_contents($path, false, null, 0, 4096);
            $reader->setDelimiter(
                substr_count(strtok($sample, "\r\n") ?: "", ";") >=
                substr_count(strtok($sample, "\r\n") ?: "", ",")
                    ? ";"
                    : ",",
            );
            $reader->setInputEncoding(Csv::GUESS_ENCODING);
        }
        $info = $reader->listWorksheetInfo($path);
        $wanted = [];
        foreach ($info as $sheet) {
            if (
                in_array(
                    RemoteCache::normalize($sheet["worksheetName"]),
                    [
                        "performances",
                        "workouts",
                        "mesures",
                        "mensurations",
                        "measurements",
                    ],
                    true,
                )
            ) {
                $wanted[] = $sheet["worksheetName"];
            }
        }
        if (!$wanted && $info) {
            $wanted = [$info[0]["worksheetName"]];
        }
        foreach ($info as $sheet) {
            if (
                in_array($sheet["worksheetName"], $wanted, true) &&
                ($sheet["totalRows"] > self::MAX_ROWS + 1 ||
                    $sheet["lastColumnIndex"] > 30)
            ) {
                throw new \InvalidArgumentException(
                    "Maximum 2 000 lignes et 30 colonnes par feuille.",
                );
            }
        }
        if ($reader instanceof XlsxReader) {
            $reader->setLoadSheetsOnly($wanted);
        }
        $book = $reader->load($path);
        ExcelDate::setExcelCalendar($book->getExcelCalendar());
        $result = [
            "owner" => $this->owner(),
            "rows" => [],
            "errors" => [],
            "duplicates" => 0,
            "default_rpe" => 0,
            "total" => 0,
        ];
        $seen = [];
        try {
            foreach ($book->getAllSheets() as $sheet) {
                if (!in_array($sheet->getTitle(), $wanted, true)) {
                    continue;
                }
                $headers = [];
                foreach ($sheet->getRowIterator(1, 1) as $row) {
                    foreach ($row->getCellIterator() as $cell) {
                        $headers[$cell->getColumn()] = RemoteCache::normalize(
                            (string) $cell->getValue(),
                        );
                    }
                }
                $kind = count(
                    array_intersect($headers, [
                        "exercice",
                        "exercise",
                        "nom exercice",
                    ]),
                )
                    ? "workouts"
                    : "measurements";
                $aliases = [
                    "date" => ["date", "jour"],
                    "notes" => ["notes", "note", "commentaire", "commentaires"],
                    "exercise" => ["exercice", "exercise", "nom exercice"],
                    "sets" => ["series", "sets"],
                    "reps" => ["repetitions", "reps", "repetitions par serie"],
                    "rpe" => ["rpe"],
                    "weight" =>
                        $kind === "workouts"
                            ? [
                                "charge kg",
                                "charge",
                                "weight",
                                "poids kg",
                                "poids",
                            ]
                            : ["poids kg", "poids", "weight"],
                    "waist" => ["tour de taille cm", "tour de taille", "waist"],
                    "body_fat" => [
                        "masse grasse",
                        "masse grasse pourcent",
                        "masse grasse %",
                        "body fat",
                        "body fat %",
                    ],
                ];
                $columns = [];
                foreach ($headers as $column => $header) {
                    foreach ($aliases as $key => $names) {
                        if (in_array($header, $names, true)) {
                            if (isset($columns[$key])) {
                                throw new \InvalidArgumentException(
                                    "Colonne répétée : " . $header,
                                );
                            }
                            $columns[$key] = $column;
                        }
                    }
                }
                $required =
                    $kind === "workouts"
                        ? ["date", "exercise", "sets", "reps", "weight"]
                        : ["date", "weight"];
                foreach ($required as $key) {
                    if (!isset($columns[$key])) {
                        throw new \InvalidArgumentException(
                            "Colonnes manquantes dans « " .
                                $sheet->getTitle() .
                                " ». Utilise le modèle fourni (date, exercice, séries, répétitions, charge).",
                        );
                    }
                }
                for ($r = 2; $r <= $sheet->getHighestDataRow(); $r++) {
                    $values = [];
                    foreach ($columns as $key => $column) {
                        $values[$key] = $sheet
                            ->getCell($column . $r)
                            ->getValue();
                    }
                    if (
                        !array_filter(
                            $values,
                            static fn($v) => $v !== null && $v !== "",
                        )
                    ) {
                        continue;
                    }
                    if (++$result["total"] > self::MAX_ROWS) {
                        throw new \InvalidArgumentException(
                            "Maximum 2 000 lignes au total par import.",
                        );
                    }
                    try {
                        foreach ($columns as $key => $column) {
                            if (
                                $sheet->getCell($column . $r)->getDataType() ===
                                    DataType::TYPE_FORMULA ||
                                ($extension === "csv" &&
                                    is_string($values[$key]) &&
                                    str_starts_with(ltrim($values[$key]), "="))
                            ) {
                                throw new \InvalidArgumentException(
                                    "Les cellules de saisie doivent contenir des valeurs, pas des formules.",
                                );
                            }
                        }
                        $data = [
                            "date" => $this->date($values["date"]),
                            "weight" => $this->number(
                                $values["weight"],
                                $kind === "workouts" ? 0 : 25,
                                $kind === "workouts" ? 1000 : 350,
                            ),
                            "notes" => trim((string) ($values["notes"] ?? "")),
                        ];
                        if (mb_strlen($data["notes"]) > 5000) {
                            throw new \InvalidArgumentException(
                                "Notes limitées à 5 000 caractères.",
                            );
                        }
                        $default = false;
                        if ($kind === "workouts") {
                            $data["exercise"] = trim(
                                (string) ($values["exercise"] ?? ""),
                            );
                            if (
                                $data["exercise"] === "" ||
                                mb_strlen($data["exercise"]) > 250
                            ) {
                                throw new \InvalidArgumentException(
                                    "Nom d’exercice requis, 250 caractères maximum.",
                                );
                            }
                            $data["sets"] = $this->number(
                                $values["sets"],
                                1,
                                50,
                                true,
                            );
                            $data["reps"] = $this->number(
                                $values["reps"],
                                1,
                                200,
                                true,
                            );
                            $default =
                                ($values["rpe"] ?? "") === "" ||
                                ($values["rpe"] ?? null) === null;
                            $data["rpe"] = $this->number(
                                $default ? $defaultRpe : $values["rpe"],
                                1,
                                10,
                            );
                        } else {
                            $data["waist"] =
                                ($values["waist"] ?? "") !== "" &&
                                ($values["waist"] ?? null) !== null
                                    ? $this->number($values["waist"], 30, 250)
                                    : null;
                            $fat = $values["body_fat"] ?? null;
                            if (
                                $fat !== null &&
                                $fat !== "" &&
                                str_contains(
                                    $sheet
                                        ->getCell($columns["body_fat"] . $r)
                                        ->getStyle()
                                        ->getNumberFormat()
                                        ->getFormatCode(),
                                    "%",
                                )
                            ) {
                                $fat = (float) $fat * 100;
                            }
                            $data["body_fat"] =
                                $fat !== null && $fat !== ""
                                    ? $this->number($fat, 1, 70)
                                    : null;
                        }
                        $canonical = $data;
                        if (isset($canonical["exercise"])) {
                            $canonical["exercise"] = mb_strtolower(
                                $canonical["exercise"],
                            );
                        }
                        $fingerprint = $kind . ":" . json_encode($canonical);
                        $duplicate =
                            isset($seen[$fingerprint]) ||
                            $this->exists($kind, $data);
                        $seen[$fingerprint] = true;
                        if ($duplicate) {
                            $result["duplicates"]++;
                        }
                        if ($default) {
                            $result["default_rpe"]++;
                        }
                        $result["rows"][] = [
                            "kind" => $kind,
                            "sheet" => $sheet->getTitle(),
                            "line" => $r,
                            "data" => $data,
                            "duplicate" => $duplicate,
                        ];
                    } catch (\InvalidArgumentException $e) {
                        $result["errors"][] =
                            $sheet->getTitle() .
                            " · ligne " .
                            $r .
                            " : " .
                            $e->getMessage();
                    }
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }
        if (!$result["total"]) {
            throw new \InvalidArgumentException(
                "Aucune ligne de données trouvée dans le fichier.",
            );
        }
        return $result;
    }

    private function exists(string $kind, array $data): bool
    {
        $where = ["user_id = ?"];
        $parameters = [$this->owner()];
        foreach ($data as $key => $value) {
            if ($value === null) {
                $where[] = $key . " IS NULL";
            } else {
                $where[] =
                    $key .
                    " = ?" .
                    ($key === "exercise" ? " COLLATE NOCASE" : "");
                $parameters[] = $value;
            }
        }
        return (bool) $this->store
            ->db()
            ->fetchOne(
                "SELECT id FROM " .
                    $kind .
                    " WHERE " .
                    implode(" AND ", $where) .
                    " LIMIT 1",
                $parameters,
            );
    }

    public function commit(array $preview): array
    {
        if (($preview["owner"] ?? null) !== $this->owner()) {
            throw new \InvalidArgumentException(
                "Cet aperçu appartient à un autre compte.",
            );
        }
        if (
            $preview["errors"] ||
            !$preview["rows"] ||
            count($preview["rows"]) > self::MAX_ROWS
        ) {
            throw new \InvalidArgumentException(
                "Corrige toutes les erreurs puis importe à nouveau le fichier.",
            );
        }
        $db = $this->store->db();
        $uid = $this->owner();
        $added = ["workouts" => 0, "measurements" => 0, "duplicates" => 0];
        $db->executeStatement("BEGIN IMMEDIATE");
        try {
            foreach ($preview["rows"] as $row) {
                if ($this->exists($row["kind"], $row["data"])) {
                    $added["duplicates"]++;
                    continue;
                }
                $db->insert($row["kind"], $row["data"] + ["user_id" => $uid]);
                $added[$row["kind"]]++;
            }
            $db->executeStatement("COMMIT");
        } catch (\Throwable $error) {
            $db->executeStatement("ROLLBACK");
            throw $error;
        }
        return $added;
    }

    public function workbook(
        bool $template = false,
        string $exercise = "",
    ): Spreadsheet {
        ExcelDate::setExcelCalendar(ExcelDate::CALENDAR_WINDOWS_1900);
        $uid = $this->owner();
        $db = $this->store->db();
        if (
            !$template &&
            (int) $db->fetchOne(
                "SELECT (SELECT COUNT(*) FROM workouts WHERE user_id=?)+(SELECT COUNT(*) FROM measurements WHERE user_id=?)",
                [$uid, $uid],
            ) > 25000
        ) {
            throw new \InvalidArgumentException(
                "L’export Excel est limité à 25 000 lignes. Utilise les exports CSV pour cet historique.",
            );
        }
        $logs = $template
            ? []
            : $db->fetchAllAssociative(
                "SELECT date,exercise,sets,reps,weight,rpe,notes FROM workouts WHERE user_id=? ORDER BY date,id",
                [$uid],
            );
        $measures = $template
            ? []
            : $db->fetchAllAssociative(
                "SELECT date,weight,waist,body_fat,notes FROM measurements WHERE user_id=? ORDER BY date,id",
                [$uid],
            );
        if (count($logs) + count($measures) > 25000) {
            throw new \InvalidArgumentException(
                "L’export Excel est limité à 25 000 lignes. Utilise les exports CSV pour cet historique.",
            );
        }
        $book = new Spreadsheet();
        $book
            ->getProperties()
            ->setCreator("Gym App")
            ->setTitle(
                $template
                    ? "Modèle de suivi des performances"
                    : "Historique de progression",
            );
        $book->getActiveSheet()->setTitle("Synthese");
        $perf = $book->createSheet()->setTitle("Performances");
        $headers = [
            "Date",
            "Exercice",
            "Séries",
            "Répétitions",
            "Charge (kg)",
            "RPE",
            "Notes",
            "Volume (kg)",
            "1RM estimé (kg)",
        ];
        $perf->fromArray($headers, null, "A1");
        $groups = [];
        foreach ($logs as $index => $log) {
            $r = $index + 2;
            $perf->setCellValue(
                "A" . $r,
                ExcelDate::PHPToExcel(new \DateTimeImmutable($log["date"])),
            );
            foreach (["exercise" => "B", "notes" => "G"] as $key => $col) {
                $perf->setCellValueExplicit(
                    $col . $r,
                    $log[$key],
                    DataType::TYPE_STRING,
                );
            }
            foreach (
                ["sets" => "C", "reps" => "D", "weight" => "E", "rpe" => "F"]
                as $key => $col
            ) {
                $perf->setCellValue($col . $r, (float) $log[$key]);
            }
            $perf->setCellValue("H" . $r, "=C" . $r . "*D" . $r . "*E" . $r);
            $perf->setCellValue(
                "I" . $r,
                "=IF(D" . $r . "<=30,E" . $r . "*(1+D" . $r . '/30),"")',
            );
            $key = mb_strtolower($log["exercise"]);
            $g = &$groups[$key];
            if (!$g) {
                $g = [
                    "exercise" => $log["exercise"],
                    "days" => [],
                    "entries" => 0,
                ];
            }
            $day = &$g["days"][$log["date"]];
            if (!$day) {
                $day = [
                    "weight" => 0,
                    "volume" => 0,
                    "sets" => 0,
                    "rm" => null,
                ];
            }
            $day["weight"] = max($day["weight"], $log["weight"]);
            $day["volume"] += $log["sets"] * $log["reps"] * $log["weight"];
            $day["sets"] += $log["sets"];
            if ($log["reps"] <= 30) {
                $day["rm"] = max(
                    $day["rm"] ?? 0,
                    $log["weight"] * (1 + $log["reps"] / 30),
                );
            }
            $g["entries"]++;
            unset($g, $day);
        }
        $perf
            ->getStyle("A2:A" . max(2, count($logs) + 1))
            ->getNumberFormat()
            ->setFormatCode("dd/mm/yyyy");
        $perf
            ->getStyle("E2:I" . max(2, count($logs) + 1))
            ->getNumberFormat()
            ->setFormatCode("0.0");
        $measurement = $book->createSheet()->setTitle("Mesures");
        $measurement->fromArray(
            [
                "Date",
                "Poids (kg)",
                "Tour de taille (cm)",
                "Masse grasse (%)",
                "Notes",
            ],
            null,
            "A1",
        );
        foreach ($measures as $index => $row) {
            $r = $index + 2;
            $measurement->setCellValue(
                "A" . $r,
                ExcelDate::PHPToExcel(new \DateTimeImmutable($row["date"])),
            );
            foreach (
                ["weight" => "B", "waist" => "C", "body_fat" => "D"]
                as $key => $column
            ) {
                if ($row[$key] !== null) {
                    $measurement->setCellValue(
                        $column . $r,
                        (float) $row[$key],
                    );
                }
            }
            $measurement->setCellValueExplicit(
                "E" . $r,
                $row["notes"],
                DataType::TYPE_STRING,
            );
        }
        $measurement
            ->getStyle("A2:A" . max(2, count($measures) + 1))
            ->getNumberFormat()
            ->setFormatCode("dd/mm/yyyy");
        $progress = $book->createSheet()->setTitle("Progression");
        $progress->fromArray(
            [
                "Date",
                "Exercice",
                "Meilleure charge (kg)",
                "Volume du jour (kg)",
                "Séries du jour",
                "Meilleur 1RM estimé (kg)",
            ],
            null,
            "A1",
        );
        $summary = $book->getSheetByName("Synthese");
        $summary->fromArray(
            [
                "Exercice",
                "Entrées",
                "Première charge (kg)",
                "Dernière charge (kg)",
                "Meilleure charge (kg)",
                "Écart dernière / première (kg)",
                "Volume cumulé (kg)",
            ],
            null,
            "A1",
        );
        ksort($groups);
        $r = 2;
        $s = 2;
        if (!$exercise && $groups) {
            $exercise = reset($groups)["exercise"];
        }
        foreach ($groups as $g) {
            $days = $g["days"];
            ksort($days);
            $first = reset($days);
            $last = end($days);
            $summary->setCellValueExplicit(
                "A" . $s,
                $g["exercise"],
                DataType::TYPE_STRING,
            );
            $summary->fromArray(
                [
                    $g["entries"],
                    $first["weight"],
                    $last["weight"],
                    max(array_column($days, "weight")),
                    $last["weight"] - $first["weight"],
                    array_sum(array_column($days, "volume")),
                ],
                null,
                "B" . $s,
                true,
            );
            $s++;
            foreach ($days as $date => $day) {
                $progress->setCellValue(
                    "A" . $r,
                    ExcelDate::PHPToExcel(new \DateTimeImmutable($date)),
                );
                $progress->setCellValueExplicit(
                    "B" . $r,
                    $g["exercise"],
                    DataType::TYPE_STRING,
                );
                $progress->fromArray(
                    [$day["weight"], $day["volume"], $day["sets"], $day["rm"]],
                    null,
                    "C" . $r,
                    true,
                );
                $r++;
                if (
                    mb_strtolower($g["exercise"]) === mb_strtolower($exercise)
                ) {
                    $chartData[] = [$date, $day["weight"], $day["volume"]];
                }
            }
        }
        $progress
            ->getStyle("A2:A" . max(2, $r - 1))
            ->getNumberFormat()
            ->setFormatCode("dd/mm/yyyy");
        $stats = (new PerformanceStats($this->store))->build(
            $logs,
            $measures,
            $exercise,
        );
        $graph = $book->createSheet()->setTitle("Graphiques");
        $graph
            ->mergeCells("A1:N1")
            ->setCellValue("A1", "Gym App · ton entraînement en graphiques");
        $graph
            ->mergeCells("A2:N2")
            ->setCellValue(
                "A2",
                "Historique personnel · fréquence en jours distincts · exercice : " .
                    ($stats["exercise"] ?: "aucun"),
            );
        $data = $book->createSheet()->setTitle("Donnees_graphes");
        $data->fromArray(
            ["Date", "Charge maximale (kg)", "Volume exercice (kg)"],
            null,
            "A1",
        );
        foreach ($stats["history"] as $i => $row) {
            $data->fromArray(
                [$row["date"], $row["weight"], $row["volume"]],
                null,
                "A" . ($i + 2),
                true,
            );
        }
        $data->fromArray(["Exercice", "Jours distincts"], null, "E1");
        foreach ($stats["frequency"] as $i => $row) {
            $data->setCellValueExplicit(
                "E" . ($i + 2),
                $row["exercise"],
                DataType::TYPE_STRING,
            );
            $data->setCellValue("F" . ($i + 2), $row["days"]);
        }
        $data->fromArray(
            ["Semaine du lundi", "Jours actifs", "Volume global (kg)"],
            null,
            "H1",
        );
        foreach ($stats["weekly"] as $i => $row) {
            $data->fromArray(
                [$row["date"], $row["days"], $row["volume"]],
                null,
                "H" . ($i + 2),
                true,
            );
        }
        $data->fromArray(["Date mesure", "Poids corporel (kg)"], null, "L1");
        foreach ($stats["body"] as $i => $row) {
            $data->fromArray(
                [$row["date"], $row["weight"]],
                null,
                "L" . ($i + 2),
                true,
            );
        }
        $this->addChart(
            $graph,
            "Charge maximale · " . $stats["exercise"],
            "A",
            "B",
            count($stats["history"]),
            "A4",
            "H20",
            "kg",
        );
        $this->addChart(
            $graph,
            "Volume · " . $stats["exercise"],
            "A",
            "C",
            count($stats["history"]),
            "H4",
            "O20",
            "kg",
        );
        $this->addChart(
            $graph,
            "Exercices les plus pratiqués · jours distincts",
            "E",
            "F",
            count($stats["frequency"]),
            "A22",
            "H38",
            "jours",
            "bar",
        );
        $this->addChart(
            $graph,
            "Régularité · dernières semaines enregistrées",
            "H",
            "I",
            count($stats["weekly"]),
            "H22",
            "O38",
            "jours / semaine",
            "column",
        );
        $this->addChart(
            $graph,
            "Volume global par semaine",
            "H",
            "J",
            count($stats["weekly"]),
            "A40",
            "H56",
            "kg",
        );
        $this->addChart(
            $graph,
            "Évolution du poids corporel",
            "L",
            "M",
            count($stats["body"]),
            "H40",
            "O56",
            "kg",
        );
        if (!$stats["history"] && !$stats["body"]) {
            $graph
                ->mergeCells("A4:N5")
                ->setCellValue(
                    "A4",
                    "Aucune donnée : remplis Performances ou Mesures, puis importe ton fichier sur le site.",
                );
        }
        $guide = $book->createSheet()->setTitle("Mode emploi");
        $lines = [
            ["Gym App · suivi des performances"],
            [
                "Import : remplir Performances et/ou Mesures à partir de la ligne 2. Conserver les en-têtes de la ligne 1.",
            ],
            [
                "Dates : JJ/MM/AAAA, AAAA-MM-JJ ou dates Excel. Charges/poids en kg, tour de taille en cm, masse grasse en points de %.",
            ],
            [
                "Une ligne = un groupe de séries ayant les mêmes répétitions et charge. Les lignes strictement identiques sont ignorées à l’import.",
            ],
            [
                "RPE absent : utiliser la valeur de remplacement choisie sur la page d’import, affichée dans l’aperçu.",
            ],
            [
                "Import : maximum 5 Mo et 2 000 lignes au total ; valeurs uniquement dans les colonnes de saisie.",
            ],
            [
                "Les onglets de synthèse, graphiques et colonnes calculées ne sont pas importés. Aucun planning n’est créé automatiquement.",
            ],
            [
                "Volume = séries × répétitions × charge. Le 1RM est une estimation Epley, pour 1 à 30 répétitions uniquement.",
            ],
            [
                "Comparer charges, séries et répétitions ensemble. Une hausse de charge maximale seule ne démontre pas une progression équivalente à répétitions constantes.",
            ],
            [
                "Export : instantané de votre historique personnel. Graphiques : exercice sélectionné sur le site, 365 dernières dates maximum.",
            ],
            [
                "Fréquence : jours distincts par exercice (top 8). Régularité : jusqu’à 12 semaines enregistrées, semaines sans entraînement incluses. Donnees_graphes contient les valeurs des diagrammes.",
            ],
            [
                "Les exemples sont absents du modèle pour éviter d’importer des performances fictives.",
            ],
        ];
        $guide->fromArray($lines, null, "A1");
        $guide->getColumnDimension("A")->setWidth(110);
        $guide->getStyle("A1:A12")->getAlignment()->setWrapText(true);
        $guide->getDefaultRowDimension()->setRowHeight(36);
        foreach ($book->getAllSheets() as $sheet) {
            $last = $sheet->getHighestDataColumn();
            $sheet
                ->getStyle("A1:" . $last . "1")
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setRGB("183C26");
            $sheet
                ->getStyle("A1:" . $last . "1")
                ->getFont()
                ->setBold(true)
                ->getColor()
                ->setRGB("FFFFFF");
            $sheet
                ->getStyle("A1:" . $last . "1")
                ->getAlignment()
                ->setWrapText(true);
            $sheet->getRowDimension(1)->setRowHeight(42);
            $sheet->freezePane("A2");
            if ($sheet !== $guide && $sheet !== $graph) {
                $sheet->setAutoFilter(
                    "A1:" . $last . max(2, $sheet->getHighestDataRow()),
                );
                foreach (range("A", $last) as $column) {
                    $sheet
                        ->getColumnDimension($column)
                        ->setWidth(
                            in_array($column, ["B", "G"], true) ? 30 : 22,
                        );
                }
            }
        }
        foreach (range("A", "N") as $column) {
            $graph->getColumnDimension($column)->setWidth(12);
        }
        $graph->setShowGridlines(false);
        $graph->freezePane("A4");
        $graph->getStyle("A2:N2")->getAlignment()->setWrapText(true);
        $graph->getRowDimension(2)->setRowHeight(36);
        if ($template) {
            $book->setActiveSheetIndexByName("Performances");
        } else {
            $book->setActiveSheetIndexByName("Graphiques");
        }
        return $book;
    }
    private function addChart(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $title,
        string $category,
        string $value,
        int $count,
        string $start,
        string $end,
        string $unit,
        string $kind = "line",
    ): void {
        if (!$count) {
            return;
        }
        $last = $count + 1;
        $series = new DataSeries(
            $kind === "line"
                ? DataSeries::TYPE_LINECHART
                : DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_STANDARD,
            [0],
            [
                new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_STRING,
                    'Donnees_graphes!$' . $value . '$1',
                    null,
                    1,
                ),
            ],
            [
                new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_STRING,
                    'Donnees_graphes!$' .
                        $category .
                        '$2:$' .
                        $category .
                        '$' .
                        $last,
                    null,
                    $count,
                ),
            ],
            [
                new DataSeriesValues(
                    DataSeriesValues::DATASERIES_TYPE_NUMBER,
                    'Donnees_graphes!$' .
                        $value .
                        '$2:$' .
                        $value .
                        '$' .
                        $last,
                    null,
                    $count,
                ),
            ],
        );
        if ($kind !== "line") {
            $series->setPlotDirection(
                $kind === "bar"
                    ? DataSeries::DIRECTION_BAR
                    : DataSeries::DIRECTION_COL,
            );
        }
        $chart = new Chart(
            "gym_" . $value,
            new Title($title),
            new Legend(),
            new PlotArea(null, [$series]),
            true,
            0,
            null,
            new Title($unit),
        );
        $chart->setTopLeftPosition($start)->setBottomRightPosition($end);
        $sheet->addChart($chart);
    }
}
