<?php

namespace App\Command;

use App\Service\AiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: "app:ai:check",
    description: "Vérifier la connexion au moteur IA sans générer de réponse.",
)]
final class CheckAiCommand extends Command
{
    public function __construct(private AiClient $client)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $status = $this->client->status();
        $io->table(
            ["Moteur", "Modèle", "Prêt"],
            [[$status["provider"], $status["model"], $status["ready"] ? "Oui" : "Non"]],
        );
        $io->note($status["message"]);
        return $status["ready"] ? Command::SUCCESS : Command::FAILURE;
    }
}
