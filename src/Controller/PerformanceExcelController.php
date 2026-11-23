<?php

namespace App\Controller;

use App\Service\{GymStore, PerformanceExcel};
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, StreamedResponse};
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

final class PerformanceExcelController extends AbstractController
{
    public function __construct(private GymStore $store, private PerformanceExcel $excel) {}

    #[Route("/performances/excel", methods: ["GET"])]
    public function index(Request $request): Response
    {
        $pending = $request->getSession()->get("performance_excel");
        if (
            $pending &&
            ($pending["user"] !== (int) $this->store->user()["id"] || $pending["expires"] < time())
        ) {
            $request->getSession()->remove("performance_excel");
            $pending = null;
        }
        return $this->render("performance_excel.html.twig", ["pending" => $pending]);
    }

    #[Route("/performances/excel/apercu", methods: ["POST"])]
    public function preview(Request $request): Response
    {
        $request->getSession()->remove("performance_excel");
        try {
            $file = $request->files->get("file");
            if (!($file instanceof UploadedFile) || !$file->isValid()) {
                throw new \InvalidArgumentException(
                    "Choisis un fichier XLSX ou CSV valide, de 5 Mo maximum.",
                );
            }
            $rpe = $request->request->getString("default_rpe", "7");
            if (!is_numeric(str_replace(",", ".", $rpe))) {
                throw new \InvalidArgumentException(
                    "Le RPE de remplacement doit être compris entre 1 et 10.",
                );
            }
            $preview = $this->excel->preview(
                $file->getPathname(),
                $file->getClientOriginalName(),
                (float) str_replace(",", ".", $rpe),
            );
            $request->getSession()->set("performance_excel", [
                "user" => (int) $this->store->user()["id"],
                "expires" => time() + 1800,
                "token" => bin2hex(random_bytes(24)),
                "filename" => mb_substr(basename($file->getClientOriginalName()), 0, 200),
                "preview" => $preview,
            ]);
        } catch (\InvalidArgumentException $error) {
            $this->addFlash("error", $error->getMessage());
        } catch (\Throwable) {
            $this->addFlash(
                "error",
                "Le fichier ne peut pas être lu. Utilise le modèle XLSX et vérifie les valeurs.",
            );
        }
        return $this->redirect("/performances/excel");
    }

    #[Route("/performances/excel/confirmer", methods: ["POST"])]
    public function confirm(Request $request): Response
    {
        $pending = $request->getSession()->get("performance_excel");
        if (
            !$pending ||
            $pending["user"] !== (int) $this->store->user()["id"] ||
            $pending["expires"] < time() ||
            !hash_equals($pending["token"], $request->request->getString("import_token"))
        ) {
            $this->addFlash(
                "error",
                "Cet aperçu a expiré ou a déjà été importé. Choisis à nouveau ton fichier.",
            );
            return $this->redirect("/performances/excel");
        }
        try {
            $result = $this->excel->commit($pending["preview"]);
            $request->getSession()->remove("performance_excel");
            $this->addFlash(
                "success",
                $result["workouts"] .
                    " performance(s) et " .
                    $result["measurements"] .
                    " mesure(s) ajoutée(s). " .
                    $result["duplicates"] .
                    " doublon(s) ignoré(s).",
            );
            return $this->redirect("/performances");
        } catch (\InvalidArgumentException $error) {
            $this->addFlash("error", $error->getMessage());
        } catch (\Throwable) {
            $this->addFlash(
                "error",
                "L’import a été annulé sans ajout partiel. Réessaie plus tard.",
            );
        }
        return $this->redirect("/performances/excel");
    }

    #[Route("/performances/excel/annuler", methods: ["POST"])]
    public function cancel(Request $request): Response
    {
        $request->getSession()->remove("performance_excel");
        return $this->redirect("/performances/excel");
    }

    #[Route(
        "/performances/excel/{mode}.xlsx",
        methods: ["GET"],
        requirements: ["mode" => "modele|export"],
    )]
    public function download(string $mode, Request $request): Response
    {
        try {
            $book = $this->excel->workbook(
                $mode === "modele",
                $request->query->getString("exercise"),
            );
        } catch (\InvalidArgumentException $error) {
            $this->addFlash("error", $error->getMessage());
            return $this->redirect("/performances/excel");
        }
        $response = new StreamedResponse(function () use ($book) {
            try {
                $writer = new Xlsx($book);
                $writer->setIncludeCharts(true);
                $writer->save("php://output");
            } finally {
                $book->disconnectWorksheets();
            }
        });
        $response->headers->set(
            "Content-Type",
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        );
        $response->headers->set(
            "Content-Disposition",
            'attachment; filename="gym-' . $mode . "-" . date("Y-m-d") . '.xlsx"',
        );
        return $response;
    }
}
