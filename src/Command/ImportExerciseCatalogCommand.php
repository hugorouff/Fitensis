<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\{ExerciseCatalog, GymStore, RemoteCache};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'app:exercices:importer', description: 'Enregistre le catalogue original des exercices dans PostgreSQL.')]
final class ImportExerciseCatalogCommand extends Command
{
    public function __construct(
        private readonly GymStore $store,
        private readonly HttpClientInterface $http,
        private readonly RemoteCache $cache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('depuis-cache', null, InputOption::VALUE_NONE, 'Reprend une seule fois le catalogue précédemment téléchargé.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->store->isPostgreSql()) {
            $io->error('Cet import nécessite la connexion PostgreSQL de Fitensis.');
            return Command::FAILURE;
        }

        $url = ExerciseCatalog::SOURCES['Gym App original'];
        $db = $this->store->db();
        $fromCache = (bool) $input->getOption('depuis-cache');

        try {
            $items = $this->fetchCatalog($url, $fromCache);
            if (!$items) {
                throw new \UnexpectedValueException('Le catalogue est vide ou son format est inconnu.');
            }
            foreach ($items as $item) {
                if (!is_array($item) || !is_string($item['name'] ?? null) || trim($item['name']) === '') {
                    throw new \UnexpectedValueException('Le catalogue contient une fiche invalide.');
                }
            }

            $items = $this->withFallbackImages($items, $fromCache);
            $changed = $db->executeStatement(
                'INSERT INTO exercise_catalog (source, payload) VALUES (?, CAST(? AS jsonb))
                 ON CONFLICT (source) ' . ($fromCache
                    ? 'DO NOTHING'
                    : 'DO UPDATE SET payload = EXCLUDED.payload, imported_at = CURRENT_TIMESTAMP'),
                [$url, json_encode($items, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)],
            );
        } catch (\UnexpectedValueException $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        } catch (\Throwable) {
            $io->error('Import impossible. Vérifie la source et applique la migration PostgreSQL avant de réessayer.');
            return Command::FAILURE;
        }

        if ($changed === 0) {
            $io->note('Le catalogue est déjà en PostgreSQL. Utilise la commande sans --depuis-cache pour le mettre à jour.');
        } else {
            $io->success(count($items) . ' fiches enregistrées dans PostgreSQL.');
        }
        return Command::SUCCESS;
    }

    private function fetchCatalog(string $url, bool $fromCache): array
    {
        $payload = $fromCache
            ? ($this->cache->read($url . '?', 900)['data'] ?? null)
            : $this->http->request('GET', $url, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 15,
                'max_duration' => 45,
            ])->toArray();
        $items = is_array($payload) ? ($payload['data'] ?? $payload['exercises'] ?? $payload) : null;
        return is_array($items) && array_is_list($items) ? $items : [];
    }

    private function withFallbackImages(array $items, bool $fromCache): array
    {
        try {
            $reference = $this->fetchCatalog(ExerciseCatalog::SOURCES['Free Exercise DB'], $fromCache);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface) {
            return $items;
        }

        $images = [];
        foreach ($reference as $row) {
            if (!is_array($row) || !is_string($row['name'] ?? null) || !is_string($row['images'][0] ?? null)) {
                continue;
            }
            $images[RemoteCache::normalize($row['name'])] =
                'https://raw.githubusercontent.com/yuhonas/free-exercise-db/main/exercises/' .
                implode('/', array_map('rawurlencode', explode('/', $row['images'][0])));
        }

        foreach ($items as &$item) {
            $aliases = is_array($item['aliases'] ?? null) ? $item['aliases'] : [];
            foreach ([$item['name'], ...$aliases] as $name) {
                if (is_string($name) && isset($images[RemoteCache::normalize($name)])) {
                    $item['fallbackImageUrl'] = $images[RemoteCache::normalize($name)];
                    break;
                }
            }
        }
        unset($item);
        return $items;
    }

}
