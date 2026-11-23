<?php

namespace App\Controller;

use App\Service\{AiClient, AiException, AssistantService, GymStore, Nutrition};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

final class AssistantController extends AbstractController
{
    public function __construct(
        private GymStore $store,
        private AiClient $client,
        private AssistantService $assistant,
        private Nutrition $nutrition,
    ) {}

    #[Route("/assistant", name: "assistant", methods: ["GET"])]
    public function index(Request $request): Response
    {
        $selected = null;
        if ($request->query->getInt("run")) {
            try {
                $selected = $this->assistant->run($request->query->getInt("run"));
            } catch (AiException $error) {
                throw $this->createNotFoundException($error->getMessage());
            }
        }
        $uid = $this->store->user()["id"];
        $history = $this->store
            ->db()
            ->fetchAllAssociative(
                "SELECT id,question,status,created_at FROM ai_runs WHERE user_id=? ORDER BY id DESC LIMIT 12",
                [$uid],
            );
        $favorites = $this->store
            ->db()
            ->fetchAllAssociative(
                "SELECT id,payload FROM ai_favorites WHERE user_id=? ORDER BY id DESC LIMIT 12",
                [$uid],
            );
        foreach ($favorites as &$favorite) {
            $favorite["recipe"] = json_decode($favorite["payload"], true, 64, JSON_THROW_ON_ERROR);
        }
        unset($favorite);
        return $this->render("assistant.html.twig", [
            "selected" => $selected,
            "history" => $history,
            "favorites" => $favorites,
            "provider" => $this->client->providerName(),
            "model" => $this->client->modelName(),
            "muscles" => $this->nutrition->catalog("MUSCLE_LABELS"),
        ]);
    }

    #[Route("/api/assistant/status", methods: ["GET"])]
    public function status(): Response
    {
        return $this->json($this->client->status());
    }

    #[Route("/api/assistant/message", methods: ["POST"])]
    public function message(Request $request): Response
    {
        try {
            $input = $request->toArray();
        } catch (\Throwable) {
            return $this->json(["detail" => "La demande JSON est invalide."], 422);
        }
        if (function_exists("set_time_limit")) {
            @set_time_limit(240);
        }
        try {
            $run = $this->assistant->ask($input);
            return $this->json([
                "run_id" => $run["id"],
                "html" => $this->renderView("_assistant_result.html.twig", [
                    "run" => $run,
                    "muscles" => $this->nutrition->catalog("MUSCLE_LABELS"),
                ]),
            ]);
        } catch (AiException $error) {
            return $this->json(["detail" => $error->getMessage()], $error->httpStatus);
        }
    }

    #[Route(
        "/assistant/{id}/seance/{index}",
        methods: ["POST"],
        requirements: ["id" => "[0-9]+", "index" => "[0-9]+"],
    )]
    public function addSession(int $id, int $index, Request $request): Response
    {
        try {
            $session = $this->assistant->addSession(
                $id,
                $index,
                $request->request->getString("date"),
                $request->request->getString("time"),
            );
            $this->addFlash(
                "success",
                "La séance proposée est dans ton planning. Tu peux l’adapter avant de la réaliser.",
            );
            return $this->redirect("/entrainements/" . $session);
        } catch (AiException $error) {
            if ($error->httpStatus === 404) {
                throw $this->createNotFoundException($error->getMessage());
            }
            $this->addFlash("error", $error->getMessage());
            return $this->redirect("/assistant?run=" . $id);
        }
    }

    #[Route(
        "/assistant/{id}/recette/{index}",
        methods: ["POST"],
        requirements: ["id" => "[0-9]+", "index" => "[0-9]+"],
    )]
    public function favorite(int $id, int $index, Request $request): Response
    {
        try {
            $this->assistant->favorite(
                $id,
                $index,
                $request->request->has("portions") ? $request->request->getInt("portions") : null,
                $request->request->has("base_portions")
                    ? $request->request->getInt("base_portions")
                    : null,
            );
        } catch (AiException $error) {
            throw $this->createNotFoundException($error->getMessage());
        }
        $this->addFlash("success", "Recette gardée dans tes favoris IA.");
        return $this->redirect("/assistant?run=" . $id . "#ai-favorites");
    }

    #[Route("/assistant/favoris/{id}/retirer", methods: ["POST"], requirements: ["id" => "[0-9]+"])]
    public function removeFavorite(int $id): Response
    {
        $this->store
            ->db()
            ->delete("ai_favorites", ["id" => $id, "user_id" => $this->store->user()["id"]]);
        return $this->redirect("/assistant#ai-favorites");
    }

    #[Route("/assistant/historique/effacer", methods: ["POST"])]
    public function clearHistory(): Response
    {
        $this->store->db()->delete("ai_runs", ["user_id" => $this->store->user()["id"]]);
        $this->addFlash(
            "success",
            "Historique IA effacé. Tes séances et recettes favorites sont conservées.",
        );
        return $this->redirect("/assistant");
    }
}
