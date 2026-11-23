<?php

namespace App\Command;

use App\Service\{ExerciseCatalog, RecipeSearch};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
#[AsCommand(
    name: 'app:sources:check',
    description: 'Vérifie la récupération réelle des exercices et recettes Internet',
)]
class CheckSourcesCommand extends Command
{
    public function __construct(private ExerciseCatalog $exercises, private RecipeSearch $recipes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'ingredients',
            null,
            InputOption::VALUE_REQUIRED,
            'Aliments à rechercher',
            'poulet,riz',
        )->addOption(
            'exercises-only',
            null,
            InputOption::VALUE_NONE,
            'Vérifier seulement les exercices',
        );
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io = new SymfonyStyle($in, $out);
        $this->exercises->all();
        $status = $this->exercises->status();
        $io->table(
            ['Source exercices', 'État', 'Fiches distantes', 'Cache'],
            [
                [
                    $status['source'],
                    $status['status'],
                    $status['remote_count'],
                    $status['cached'] ? 'Oui' : 'Non',
                ],
            ],
        );
        $ok = $status['status'] === 'ok';
        if (!$ok) {
            $io->warning('Source indisponible : dernière copie ou catalogue local utilisé.');
        }
        if (!$in->getOption('exercises-only')) {
            $r = $this->recipes->search([
                'ingredients' => $in->getOption('ingredients'),
                'goal' => 'all',
                'limit' => 3,
            ]);
            $io->table(
                ['Source recettes', 'Catalogue', 'Correspondances', 'Aperçu', 'Mode'],
                [
                    [
                        $r['source'],
                        $r['catalog_size'],
                        $r['total'],
                        $r['count'],
                        $r['degraded'] ? 'Catalogue partiel' : 'En ligne ou en cache',
                    ],
                ],
            );
            foreach ($r['data'] as $meal) {
                $io->writeln(
                    '• ' . html_entity_decode($meal['name_fr'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                );
            }
            $ok = $ok && $r['count'] > 0;
        }
        $io->note(
            'Les fiches récupérées sont mises en cache. Les calories/macros des recettes Web ne sont pas inventées.',
        );
        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
