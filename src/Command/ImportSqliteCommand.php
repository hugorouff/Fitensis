<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GymStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:import-sqlite', description: 'Importe un fichier gym.db dans la base PostgreSQL Fitensis vide.')]
final class ImportSqliteCommand extends Command
{
    private const TABLES = [
        'users', 'gym_sessions', 'bookings', 'programs', 'training_sessions',
        'training_items', 'workouts', 'measurements', 'goals', 'equipment',
        'memberships', 'payments', 'checkins', 'custom_exercises', 'ai_runs', 'ai_favorites',
    ];

    public function __construct(private readonly GymStore $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::REQUIRED, 'Fichier SQLite à importer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = realpath((string) $input->getArgument('source'));
        if (!$path || !is_file($path) || !is_readable($path)) {
            $io->error('Fichier SQLite introuvable ou illisible.');
            return Command::FAILURE;
        }

        try {
            $source = new \PDO('sqlite:'.$path, null, null, [
                \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $source->beginTransaction();
            if ($source->query('PRAGMA quick_check')->fetchColumn() !== 'ok'
                || $source->query('PRAGMA foreign_key_check')->fetch() !== false) {
                throw new \RuntimeException('Le fichier SQLite contient des données incohérentes.');
            }
            $tables = $source->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN);
            if (!in_array('users', $tables, true) || array_diff($tables, self::TABLES, ['sqlite_sequence', 'login_attempts'])) {
                throw new \RuntimeException('Le fichier contient une structure Fitensis inattendue.');
            }
            if (!$this->store->isPostgreSql() || $this->store->db()->fetchOne('SELECT current_database()') !== 'fitensis') {
                throw new \RuntimeException('DATABASE_URL doit viser la base PostgreSQL fitensis.');
            }

            $counts = $this->store->db()->transactional(function (Connection $target) use ($source, $tables): array {
                $target->executeQuery('SELECT pg_advisory_xact_lock(177610, 1)')->free();
                $this->store->init();
                $target->executeStatement('LOCK TABLE '.implode(', ', self::TABLES).' IN SHARE ROW EXCLUSIVE MODE');
                foreach (self::TABLES as $table) {
                    if ((int) $target->fetchOne('SELECT COUNT(*) FROM '.$table) !== 0) {
                        throw new \RuntimeException('La base cible contient déjà des données dans '.$table.'. Import annulé.');
                    }
                }

                $counts = [];
                foreach (self::TABLES as $table) {
                    if (!in_array($table, $tables, true)) {
                        continue;
                    }
                    $counts[$table] = $this->copyTable($source, $target, $table, in_array('sqlite_sequence', $tables, true));
                }
                return $counts;
            });
        } catch (\RuntimeException $error) {
            $io->error($error instanceof \PDOException || $error instanceof \Doctrine\DBAL\Exception
                ? 'Erreur de base de données. Vérifiez la connexion, les droits et le schéma de la cible. Import annulé.'
                : $error->getMessage());
            return Command::FAILURE;
        } catch (\Throwable) {
            $io->error('Import interrompu. Aucune donnée partielle n’a été validée.');
            return Command::FAILURE;
        }

        foreach ($counts as $table => $count) {
            $io->writeln(sprintf('%s : %d ligne(s)', $table, $count));
        }
        $io->success('Import PostgreSQL terminé.');
        return Command::SUCCESS;
    }

    private function copyTable(\PDO $source, Connection $target, string $table, bool $hasSequences): int
    {
        $columns = array_keys($target->createSchemaManager()->listTableColumns($table));
        $count = 0;
        $lastId = 0;
        $rows = $source->query('SELECT * FROM '.$table.' ORDER BY id');
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
            if (array_diff(array_keys($row), $columns)) {
                throw new \RuntimeException('Colonnes non reconnues dans la table '.$table.'.');
            }
            if ($table === 'ai_runs' && $row['parent_id'] !== null && (int) $row['parent_id'] >= (int) $row['id']) {
                throw new \RuntimeException('Ordre des conversations inattendu. Import annulé.');
            }
            $target->insert($table, $row);
            $lastId = max($lastId, (int) $row['id']);
            ++$count;
        }
        if ($hasSequences) {
            $sequence = $source->prepare('SELECT seq FROM sqlite_sequence WHERE name = ?');
            $sequence->execute([$table]);
            $lastId = max($lastId, (int) $sequence->fetchColumn());
        }
        $target->executeStatement('ALTER TABLE '.$table.' ALTER COLUMN id RESTART WITH '.($lastId + 1));
        return $count;
    }
}
