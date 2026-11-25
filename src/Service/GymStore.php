<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\HttpFoundation\RequestStack;

class GymStore
{
    private ?Connection $connection = null;
    public function __construct(
        private string $projectDir,
        private string $databaseUrl,
        private RequestStack $requests,
    ) {}
    public function db(): Connection
    {
        if (!$this->connection) {
            $url = str_replace('%kernel.project_dir%', $this->projectDir, $this->databaseUrl);
            if (str_starts_with($url, 'sqlite:///')) {
                $connection = DriverManager::getConnection([
                    'driver' => 'pdo_sqlite',
                    'path' => substr($url, 10),
                ]);
                $connection->executeStatement('PRAGMA foreign_keys=ON');
                $connection->executeStatement('PRAGMA busy_timeout=5000');
            } elseif (preg_match('~^postgres(?:ql)?://~', $url)) {
                $parser = new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']);
                $connection = DriverManager::getConnection($parser->parse($url));
            } else {
                throw new \RuntimeException('DATABASE_URL doit désigner une base PostgreSQL ou SQLite.');
            }
            $this->connection = $connection;
        }
        return $this->connection;
    }

    public function isPostgreSql(): bool
    {
        return $this->db()->getParams()['driver'] === 'pdo_pgsql';
    }

    public function beginWrite(): void
    {
        $db = $this->db();
        if (!$this->isPostgreSql()) {
            $db->executeStatement('BEGIN IMMEDIATE');
            return;
        }

        $db->executeStatement('BEGIN');
        try {
            // Sérialise les opérations de vérification suivies d’une écriture.
            $db->executeQuery('SELECT pg_advisory_xact_lock(177610, 1)')->free();
        } catch (\Throwable $error) {
            $db->executeStatement('ROLLBACK');
            throw $error;
        }
    }

    public function insertAndGetId(string $table, array $data): int
    {
        $db = $this->db();
        if (!$this->isPostgreSql()) {
            $db->insert($table, $data);
            return (int) $db->lastInsertId();
        }

        $platform = $db->getDatabasePlatform();
        $columns = array_map($platform->quoteSingleIdentifier(...), array_keys($data));
        $values = implode(', ', array_fill(0, count($data), '?'));
        return (int) $db->fetchOne(
            'INSERT INTO '.$platform->quoteSingleIdentifier($table).' ('.implode(', ', $columns).') VALUES ('.$values.') RETURNING id',
            array_values($data),
        );
    }

    public function user(): ?array
    {
        $id = $this->requests->getCurrentRequest()?->getSession()->get('user_id');
        if (!$id) {
            return null;
        }
        $u = $this->db()->fetchAssociative(
            'SELECT id,name,email,role,phone,active FROM users WHERE id=? AND active=1',
            [$id],
        );
        return $u ?: null;
    }

    public function staff(): bool
    {
        return in_array($this->user()['role'] ?? '', ['admin', 'coach']);
    }

    public function init(): void
    {
        $sql = <<<'SQL'
        CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,email TEXT NOT NULL COLLATE NOCASE UNIQUE,password TEXT NOT NULL,role TEXT NOT NULL CHECK(role IN ('admin','coach','member')),phone TEXT NOT NULL DEFAULT '',active INTEGER NOT NULL DEFAULT 1,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS gym_sessions(id INTEGER PRIMARY KEY AUTOINCREMENT,title TEXT NOT NULL,starts_at TEXT NOT NULL,ends_at TEXT NOT NULL,coach_id INTEGER REFERENCES users(id),room TEXT NOT NULL,capacity INTEGER NOT NULL CHECK(capacity BETWEEN 1 AND 200),category TEXT NOT NULL DEFAULT 'Musculation',description TEXT NOT NULL DEFAULT '',cancelled INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS bookings(id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER NOT NULL REFERENCES gym_sessions(id),user_id INTEGER NOT NULL REFERENCES users(id),status TEXT NOT NULL CHECK(status IN ('confirmed','waiting','cancelled')),attended INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(session_id,user_id));
        CREATE INDEX IF NOT EXISTS booking_capacity ON bookings(session_id,status);
        CREATE TABLE IF NOT EXISTS programs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),title TEXT NOT NULL,goal TEXT NOT NULL,weeks INTEGER NOT NULL,content TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS training_sessions(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),title TEXT NOT NULL,starts_at TEXT NOT NULL,duration_minutes INTEGER NOT NULL DEFAULT 60,preset TEXT NOT NULL DEFAULT 'custom',muscles TEXT NOT NULL DEFAULT '[]',status TEXT NOT NULL DEFAULT 'planned' CHECK(status IN ('planned','completed','skipped')),notes TEXT NOT NULL DEFAULT '');
        CREATE INDEX IF NOT EXISTS training_personal_date ON training_sessions(user_id,starts_at);
        CREATE TABLE IF NOT EXISTS training_items(id INTEGER PRIMARY KEY AUTOINCREMENT,session_id INTEGER NOT NULL REFERENCES training_sessions(id) ON DELETE CASCADE,exercise TEXT NOT NULL,sets INTEGER NOT NULL DEFAULT 3,reps INTEGER NOT NULL DEFAULT 10,weight REAL NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS workouts(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),date TEXT NOT NULL,exercise TEXT NOT NULL,sets INTEGER NOT NULL,reps INTEGER NOT NULL,weight REAL NOT NULL,rpe REAL NOT NULL,notes TEXT NOT NULL DEFAULT '',session_id INTEGER REFERENCES training_sessions(id) ON DELETE SET NULL);
        CREATE INDEX IF NOT EXISTS workouts_owner_date_exercise ON workouts(user_id,date,exercise COLLATE NOCASE);
        CREATE TABLE IF NOT EXISTS measurements(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),date TEXT NOT NULL,weight REAL NOT NULL,waist REAL,body_fat REAL,notes TEXT NOT NULL DEFAULT '');
        CREATE INDEX IF NOT EXISTS measurements_owner_date_weight ON measurements(user_id,date,weight);
        CREATE TABLE IF NOT EXISTS goals(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),title TEXT NOT NULL,target REAL NOT NULL,current REAL NOT NULL DEFAULT 0,unit TEXT NOT NULL,deadline TEXT NOT NULL,done INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS equipment(id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT NOT NULL,zone TEXT NOT NULL,status TEXT NOT NULL CHECK(status IN ('Disponible','Maintenance','Hors service')),next_service TEXT NOT NULL,notes TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS memberships(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),plan TEXT NOT NULL,starts_on TEXT NOT NULL,ends_on TEXT NOT NULL,price_cents INTEGER NOT NULL CHECK(price_cents>=0),status TEXT NOT NULL CHECK(status IN ('Actif','Suspendu','Terminé')));
        CREATE TABLE IF NOT EXISTS payments(id INTEGER PRIMARY KEY AUTOINCREMENT,membership_id INTEGER NOT NULL REFERENCES memberships(id),amount_cents INTEGER NOT NULL CHECK(amount_cents>0),paid_on TEXT NOT NULL,method TEXT NOT NULL,reference TEXT NOT NULL DEFAULT '');
        CREATE TABLE IF NOT EXISTS checkins(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),day TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(user_id,day));
        CREATE TABLE IF NOT EXISTS custom_exercises(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),payload TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS ai_runs(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),parent_id INTEGER REFERENCES ai_runs(id) ON DELETE SET NULL,question TEXT NOT NULL,topic TEXT NOT NULL,status TEXT NOT NULL CHECK(status IN ('pending','completed','failed')),provider TEXT NOT NULL,result TEXT,shared_context TEXT NOT NULL DEFAULT '[]',saved_sessions TEXT NOT NULL DEFAULT '{}',created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE INDEX IF NOT EXISTS ai_runs_owner_date ON ai_runs(user_id,created_at);
        CREATE TABLE IF NOT EXISTS ai_favorites(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id),recipe_key TEXT NOT NULL,payload TEXT NOT NULL,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE(user_id,recipe_key));
        SQL;
        if ($this->isPostgreSql()) {
            $sql = str_replace(
                ['INTEGER PRIMARY KEY AUTOINCREMENT', ' COLLATE NOCASE', 'DEFAULT CURRENT_TIMESTAMP'],
                ['INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY', '', "DEFAULT to_char(CURRENT_TIMESTAMP AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')"],
                $sql,
            );
            $sql .= "; CREATE UNIQUE INDEX IF NOT EXISTS users_email_ci ON users ((LOWER(email)))";
        }

        foreach (explode(';', $sql) as $s) {
            if (trim($s)) {
                $this->db()->executeStatement($s);
            }
        }
        if ($this->isPostgreSql()) {
            $this->db()->executeStatement(
                'ALTER TABLE workouts ADD COLUMN IF NOT EXISTS session_id INTEGER REFERENCES training_sessions(id) ON DELETE SET NULL',
            );
            return;
        }
        $columns = $this->db()->fetchAllAssociative('PRAGMA table_info(workouts)');
        if (!in_array('session_id', array_column($columns, 'name'), true)) {
            $this->db()->executeStatement(
                'ALTER TABLE workouts ADD COLUMN session_id INTEGER REFERENCES training_sessions(id) ON DELETE SET NULL',
            );
        }
    }

    public function requireUser(int $id): array
    {
        $u = $this->db()->fetchAssociative('SELECT * FROM users WHERE id=? AND active=1', [$id]);
        if (!$u) {
            throw new \InvalidArgumentException('Adhérent introuvable.');
        }
        return $u;
    }
}
