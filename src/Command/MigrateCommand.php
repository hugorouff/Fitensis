<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\GymStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:migrations:migrate', description: 'Applique les migrations PostgreSQL de Fitensis.')]
final class MigrateCommand extends Command
{
    private const SCHEMA = 'Version20261125000100';
    private const IMPORT = 'Version20261125000200';
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
        $this->addOption('source', null, InputOption::VALUE_REQUIRED, 'Fichier privé fitensis-donnees.json à importer une fois.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if (!$this->store->isPostgreSql() || $this->store->db()->fetchOne('SELECT current_database()') !== 'fitensis') {
                throw new \InvalidArgumentException('DATABASE_URL doit viser la base PostgreSQL fitensis.');
            }

            $db = $this->store->db();
            $this->apply($db, self::SCHEMA, fn () => $this->store->init(), $io);
            $file = $input->getOption('source');
            if ($file !== null) {
                $this->apply($db, self::IMPORT, fn () => $this->import($db, $file), $io);
            } else {
                $io->note('Pour transférer les données existantes, ajoute --source=/import/fitensis-donnees.json.');
            }
        } catch (\InvalidArgumentException $error) {
            $io->error($error->getMessage());
            return Command::FAILURE;
        } catch (\Throwable) {
            $io->error('Échec de la migration. Vérifie la connexion PostgreSQL et les droits du rôle Fitensis. La version en cours a été annulée.');
            return Command::FAILURE;
        }

        $io->success('Migrations terminées.');
        return Command::SUCCESS;
    }

    private function apply(Connection $db, string $version, callable $migration, SymfonyStyle $io): void
    {
        $applied = $db->transactional(function (Connection $db) use ($version, $migration): bool {
            $db->executeQuery('SELECT pg_advisory_xact_lock(177610, 1)')->free();
            $db->executeStatement('CREATE TABLE IF NOT EXISTS fitensis_migration_versions (
                version VARCHAR(64) PRIMARY KEY,
                executed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
            )');
            if ($db->fetchOne('SELECT 1 FROM fitensis_migration_versions WHERE version = ?', [$version]) !== false) {
                return false;
            }

            $migration();
            $db->insert('fitensis_migration_versions', ['version' => $version]);
            return true;
        });
        $io->writeln($version.($applied ? ' appliquée.' : ' déjà appliquée.'));
    }

    private function import(Connection $db, string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new \InvalidArgumentException('Le fichier privé à importer est introuvable ou illisible.');
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || ($data['format'] ?? null) !== 1 || !is_array($data['tables'] ?? null)
            || array_diff(self::TABLES, array_keys($data['tables']))
            || array_diff(array_keys($data['tables']), self::TABLES)) {
            throw new \InvalidArgumentException('Le format du fichier de migration Fitensis est invalide.');
        }

        $db->executeStatement('LOCK TABLE '.implode(', ', self::TABLES).' IN SHARE ROW EXCLUSIVE MODE');
        $schema = $db->createSchemaManager();
        foreach (self::TABLES as $table) {
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM '.$table) !== 0) {
                throw new \InvalidArgumentException('Import annulé : '.$table.' contient déjà des données.');
            }
        }

        foreach (self::TABLES as $table) {
            $entry = $data['tables'][$table];
            if (!is_array($entry) || !is_array($entry['rows'] ?? null) || !array_is_list($entry['rows'])
                || !is_int($entry['next_id'] ?? null) || $entry['next_id'] < 1 || $entry['next_id'] > 2147483647) {
                throw new \InvalidArgumentException('Données invalides dans '.$table.'.');
            }
            $columns = array_keys($schema->listTableColumns($table));
            foreach ($entry['rows'] as $row) {
                if (!is_array($row) || array_diff(array_keys($row), $columns) || !is_int($row['id'] ?? null)
                    || $row['id'] < 1 || $row['id'] >= $entry['next_id']) {
                    throw new \InvalidArgumentException('Ligne invalide dans '.$table.'.');
                }
                $db->insert($table, $row);
            }
            if ((int) $db->fetchOne('SELECT COUNT(*) FROM '.$table) !== count($entry['rows'])) {
                throw new \InvalidArgumentException('Nombre de lignes inattendu dans '.$table.'.');
            }
            $db->executeStatement('ALTER TABLE '.$table.' ALTER COLUMN id RESTART WITH '.$entry['next_id']);
        }
    }
}
