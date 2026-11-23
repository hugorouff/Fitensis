<?php
require dirname(__DIR__) . "/vendor/autoload.php";
use App\Service\{GymStore, PerformanceExcel};
use PhpOffice\PhpSpreadsheet\{
    Spreadsheet,
    Cell\DataType,
    Writer\Xlsx,
    Shared\Date as ExcelDate,
};
use Symfony\Component\HttpFoundation\{Request, RequestStack};
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
$count = 0;
function check(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $count++;
}
function rejected(callable $call): void
{
    try {
        $call();
    } catch (InvalidArgumentException) {
        check(true, "Rejet attendu");
        return;
    }
    throw new RuntimeException("Fichier ou action invalide accepté");
}
$dir = sys_get_temp_dir() . "/gym-excel-" . bin2hex(random_bytes(6));
mkdir($dir);
$stack = new RequestStack();
$request = new Request();
$session = new Session(new MockArraySessionStorage());
$request->setSession($session);
$stack->push($request);
$store = new GymStore($dir, "sqlite:///" . $dir . "/data.db", $stack);
$store->init();
$db = $store->db();
foreach ([1, 2, 3] as $uid) {
    $db->insert("users", [
        "id" => $uid,
        "name" => "User " . $uid,
        "email" => "user" . $uid . "@example.org",
        "password" => "unused",
        "role" => "member",
    ]);
}
$session->set("user_id", 1);
$service = new PerformanceExcel($store);
$db->insert("workouts", [
    "user_id" => 1,
    "date" => "2026-10-01",
    "exercise" => "Développé couché",
    "sets" => 3,
    "reps" => 10,
    "weight" => 20,
    "rpe" => 7,
    "notes" => '=HYPERLINK("https://example.org")',
]);
$db->insert("workouts", [
    "user_id" => 1,
    "date" => "2026-11-01",
    "exercise" => "Développé couché",
    "sets" => 4,
    "reps" => 8,
    "weight" => 25,
    "rpe" => 8,
    "notes" => "Progression",
]);
$db->insert("workouts", [
    "user_id" => 2,
    "date" => "2026-11-01",
    "exercise" => "PRIVATE-OTHER-USER",
    "sets" => 2,
    "reps" => 10,
    "weight" => 60,
    "rpe" => 8,
]);
$db->insert("measurements", [
    "user_id" => 1,
    "date" => "2026-11-01",
    "weight" => 75.5,
    "waist" => 82,
    "body_fat" => 20,
    "notes" => "Mesure réelle",
]);
$book = $service->workbook(false, "Développé couché");
check(
    $book->getSheetNames() === [
        "Synthese",
        "Performances",
        "Mesures",
        "Progression",
        "Graphiques",
        "Donnees_graphes",
        "Mode emploi",
    ],
    "Feuilles de suivi présentes",
);
check(
    $book->getSheetByName("Performances")->getCell("G2")->getDataType() ===
        DataType::TYPE_STRING,
    "Notes conservées en texte, pas en formule",
);
check(
    (float) $book
        ->getSheetByName("Performances")
        ->getCell("H2")
        ->getCalculatedValue() === 600.0,
    "Volume exact",
);
check(
    abs(
        $book
            ->getSheetByName("Performances")
            ->getCell("I2")
            ->getCalculatedValue() - 26.6666667,
    ) < 0.0001,
    "1RM conforme au calcul de l’application",
);
check(
    (float) $book->getSheetByName("Synthese")->getCell("F2")->getValue() ===
        5.0,
    "Écart première/dernière charge",
);
check(
    $book->getSheetByName("Performances")->getHighestDataRow() === 3,
    "Autre compte absent de l’export",
);
check(
    count($book->getSheetByName("Graphiques")->getChartCollection()) === 6,
    "Six graphiques",
);
$writer = new Xlsx($book);
$writer->setIncludeCharts(true);
$writer->save($dir . "/export.xlsx");
$book->disconnectWorksheets();
$zip = new ZipArchive();
$zip->open($dir . "/export.xlsx");
check(
    $zip->locateName("xl/charts/chart1.xml") !== false,
    "Graphiques inclus dans le XLSX",
);
$zip->close();
$session->set("user_id", 3);
$preview = $service->preview($dir . "/export.xlsx", "export.xlsx");
check(
    count($preview["rows"]) === 3 && !$preview["errors"],
    "Export réimportable malgré les colonnes calculées",
);
check(
    $preview["rows"][0]["data"]["date"] === "2026-10-01",
    "Date Excel numérique interprétée",
);
check(
    (float) $preview["rows"][2]["data"]["body_fat"] === 20.0,
    "Masse grasse conservée en points de %",
);
check(
    (int) $db->fetchOne("SELECT COUNT(*) FROM workouts WHERE user_id=3") === 0,
    "Aucun ajout pendant l’aperçu",
);
$result = $service->commit($preview);
check(
    $result["workouts"] === 2 && $result["measurements"] === 1,
    "Performances et mesures importées",
);
check($service->commit($preview)["duplicates"] === 3, "Réimport sans doublons");
check(
    (int) $db->fetchOne(
        "SELECT COUNT(*) FROM training_sessions WHERE user_id=3",
    ) === 0,
    "Aucune séance planifiée créée",
);
$session->set("user_id", 2);
rejected(fn() => $service->commit($preview));
$session->set("user_id", 1);
$model = $service->workbook(true);
(new Xlsx($model))->save($dir . "/modele.xlsx");
$model->disconnectWorksheets();
rejected(fn() => $service->preview($dir . "/modele.xlsx", "modele.xlsx"));
$csv =
    "Date;Exercice;Séries;Répétitions;Charge (kg);RPE;Notes\n02/11/2026;Rowing;3;12;20,5;;Import\n02/11/2026;Rowing;3;12;20,5;;Import\n";
file_put_contents($dir . "/input.csv", $csv);
$p = $service->preview($dir . "/input.csv", "input.csv", 8);
check(
    count($p["rows"]) === 2 && $p["duplicates"] === 1,
    "Doublon dans le fichier signalé",
);
check(
    $p["rows"][0]["data"]["weight"] === 20.5 &&
        $p["rows"][0]["data"]["rpe"] === 8.0,
    "Décimale française et RPE de remplacement",
);
check($p["default_rpe"] === 2, "RPE absent explicitement signalé");
check(
    $service->commit($p)["workouts"] === 1,
    "Doublon ignoré à la confirmation",
);
file_put_contents(
    $dir . "/bad.csv",
    $csv . "03/11/2026;Rowing;3;999;20;7;Invalide\n",
);
$bad = $service->preview($dir . "/bad.csv", "bad.csv");
check(count($bad["errors"]) === 1, "Erreur à la ligne affichée");
$before = (int) $db->fetchOne("SELECT COUNT(*) FROM workouts");
rejected(fn() => $service->commit($bad));
check(
    (int) $db->fetchOne("SELECT COUNT(*) FROM workouts") === $before,
    "Import invalide sans ajout partiel",
);
$malicious = new Spreadsheet();
$malicious
    ->getActiveSheet()
    ->setTitle("Performances")
    ->fromArray(["Date", "Exercice", "Séries", "Répétitions", "Charge (kg)"]);
$malicious
    ->getActiveSheet()
    ->fromArray(["2026-11-03", "Rowing", 3, 10, "=20+5"], null, "A2");
(new Xlsx($malicious))->save($dir . "/formula.xlsx");
$malicious->disconnectWorksheets();
check(
    count(
        $service->preview($dir . "/formula.xlsx", "formula.xlsx")["errors"],
    ) === 1,
    "Formules refusées dans les colonnes de saisie",
);
$measurement = new Spreadsheet();
$measurement
    ->getActiveSheet()
    ->setTitle("Mesures")
    ->fromArray([
        "Date",
        "Poids (kg)",
        "Tour de taille (cm)",
        "Masse grasse (%)",
    ]);
$measurement
    ->getActiveSheet()
    ->fromArray(["2026-11-03", 75, 80, 0.2], null, "A2");
$measurement
    ->getActiveSheet()
    ->getStyle("D2")
    ->getNumberFormat()
    ->setFormatCode("0%");
(new Xlsx($measurement))->save($dir . "/percent.xlsx");
$measurement->disconnectWorksheets();
check(
    $service->preview($dir . "/percent.xlsx", "percent.xlsx")["rows"][0][
        "data"
    ]["body_fat"] === 20.0,
    "Vrai pourcentage Excel converti correctement",
);
rejected(fn() => $service->preview($dir . "/input.csv", "input.xls"));
rejected(fn() => $service->preview($dir . "/input.csv", "fake.xlsx"));
file_put_contents(
    $dir . "/large.csv",
    str_repeat("a", PerformanceExcel::MAX_BYTES + 1),
);
rejected(fn() => $service->preview($dir . "/large.csv", "large.csv"));
$zip = new ZipArchive();
$zip->open($dir . "/external.xlsx", ZipArchive::CREATE);
$zip->addFromString("xl/externalLinks/externalLink1.xml", "test");
$zip->close();
rejected(fn() => $service->preview($dir . "/external.xlsx", "external.xlsx"));
$zip = new ZipArchive();
$zip->open($dir . "/entity.xlsx", ZipArchive::CREATE);
$zip->addFromString(
    "xl/workbook.xml",
    '<!DOCTYPE test [<!ENTITY x SYSTEM "file:///etc/passwd">]><test/>',
);
$zip->close();
rejected(fn() => $service->preview($dir . "/entity.xlsx", "entity.xlsx"));
file_put_contents(
    $dir . "/zero.csv",
    "Date;Exercice;Séries;Répétitions;Charge (kg);RPE\n04/11/2026;Pompes;3;12;0;7\n",
);
check(
    $service->preview($dir . "/zero.csv", "zero.csv")["rows"][0]["data"][
        "weight"
    ] === 0.0,
    "Charge zéro acceptée pour le poids du corps",
);
$mac = new Spreadsheet();
$mac->setExcelCalendar(ExcelDate::CALENDAR_MAC_1904);
ExcelDate::setExcelCalendar(ExcelDate::CALENDAR_MAC_1904);
$mac->getActiveSheet()
    ->setTitle("Performances")
    ->fromArray([
        "Date",
        "Exercice",
        "Séries",
        "Répétitions",
        "Charge (kg)",
        "RPE",
    ]);
$mac->getActiveSheet()->fromArray(
    [
        ExcelDate::PHPToExcel(new DateTimeImmutable("2026-11-04")),
        "Rowing Mac",
        3,
        10,
        20,
        7,
    ],
    null,
    "A2",
);
(new Xlsx($mac))->save($dir . "/mac.xlsx");
$mac->disconnectWorksheets();
check(
    $service->preview($dir . "/mac.xlsx", "mac.xlsx")["rows"][0]["data"][
        "date"
    ] === "2026-11-04",
    "Calendrier Excel 1904 pris en charge",
);
$win = $service->workbook(true);
check(
    $win->getExcelCalendar() === ExcelDate::CALENDAR_WINDOWS_1900,
    "Modèle suivant remis au calendrier 1900",
);
$win->disconnectWorksheets();
$db->close();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($iterator as $entry) {
    $entry->isDir()
        ? rmdir($entry->getPathname())
        : unlink($entry->getPathname());
}
rmdir($dir);
echo "$count contrôles Excel réussis (XLSX/CSV, calculs, graphiques, import, doublons, comptes et fichiers invalides).\n";
