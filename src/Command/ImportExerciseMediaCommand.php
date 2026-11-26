<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\{ExerciseCatalog, GymStore};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:exercices:importer-medias', description: 'Copie les JPG et MP4 du catalogue dans PostgreSQL.')]
final class ImportExerciseMediaCommand extends Command
{
    public function __construct(private readonly GymStore $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('dossier', InputArgument::REQUIRED, 'Dossier contenant videos et thumbnails.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = realpath((string) $input->getArgument('dossier'));
        if (!$root || !is_dir($root) || !$this->store->isPostgreSql()) {
            $io->error('Indique un dossier accessible et utilise la connexion PostgreSQL de Fitensis.');
            return Command::FAILURE;
        }

        $db = $this->store->db();
        $payload = $db->fetchOne('SELECT payload FROM exercise_catalog WHERE source = ?', [
            ExerciseCatalog::SOURCES['Gym App original'],
        ]);
        if ($payload === false) {
            $io->error('Importe d’abord les fiches du catalogue original.');
            return Command::FAILURE;
        }

        $files = [];
        foreach (json_decode($payload, true, 64, JSON_THROW_ON_ERROR) as $item) {
            foreach (['thumbnails' => 'image/jpeg', 'videos' => 'video/mp4'] as $directory => $mime) {
                foreach (['male', 'female'] as $variant) {
                    $url = $item[$directory][$variant] ?? '';
                    $name = basename((string) parse_url($url, PHP_URL_PATH));
                    if ($name !== '' && $name !== '.' && $name !== '..') {
                        $files[hash('sha256', $url)] = [$directory.'/'.$variant.'/'.$name, $mime];
                    }
                }
            }
        }

        $stored = array_fill_keys($db->fetchFirstColumn('SELECT id FROM exercise_media'), true);
        $detector = new \finfo(FILEINFO_MIME_TYPE);
        $imported = $existing = $missing = $rejected = 0;
        foreach ($files as $id => [$relative, $mime]) {
            if (isset($stored[$id])) {
                $existing++;
                continue;
            }

            $path = realpath($root.'/'.$relative);
            if (!$path || !str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) || !is_file($path)) {
                $missing++;
                continue;
            }

            $size = filesize($path);
            if (!$size || $size > 32 * 1024 * 1024 || $detector->file($path) !== $mime) {
                $rejected++;
                $io->warning('Fichier invalide ou supérieur à 32 Mio : '.$relative);
                continue;
            }
            $data = file_get_contents($path);
            if ($data === false || strlen($data) !== $size) {
                $rejected++;
                continue;
            }

            $imported += $db->executeStatement(
                "INSERT INTO exercise_media (id, mime, content) VALUES (?, ?, decode(?, 'base64')) ON CONFLICT (id) DO NOTHING",
                [$id, $mime, base64_encode($data)],
            );
        }

        $io->text(sprintf('%d importés, %d déjà en base, %d introuvables, %d refusés.', $imported, $existing, $missing, $rejected));
        return $missing || $rejected || !$files ? Command::FAILURE : Command::SUCCESS;
    }
}
