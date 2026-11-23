<?php

namespace App\Command;

use App\Service\GymStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputArgument};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
#[AsCommand(
    name: 'app:user:delete',
    description: 'Supprime un utilisateur et ses données personnelles par son adresse mail',
)]
class DeleteUserCommand extends Command
{
    public function __construct(private GymStore $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Adresse mail du compte à supprimer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim($input->getArgument('email'));
        $db = $this->store->db();
        $user = $db->fetchAssociative('SELECT id,name,email,role FROM users WHERE email=?', [
            $email,
        ]);
        if (!$user) {
            $io->error('Aucun utilisateur ne correspond à cette adresse mail.');
            return Command::FAILURE;
        }
        $io->warning(
            "Suppression définitive de {$user['name']} ({$user['email']}) : séances personnelles, carnet, mesures, objectifs, programmes, exercices personnels, abonnements et encaissements associés.",
        );
        if (!$input->isInteractive() || !$io->confirm('Confirmer la suppression ?', false)) {
            $io->note('Suppression annulée.');
            return Command::SUCCESS;
        }
        try {
            $db->executeStatement('BEGIN IMMEDIATE');
            $current = $db->fetchAssociative('SELECT * FROM users WHERE id=?', [$user['id']]);
            if (!$current) {
                throw new \InvalidArgumentException('Ce compte a déjà été supprimé.');
            }
            if (
                $current['role'] === 'admin' &&
                $current['active'] &&
                (int) $db->fetchOne("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1") <=
                    1
            ) {
                throw new \InvalidArgumentException(
                    'Impossible de supprimer le dernier administrateur actif.',
                );
            }
            $id = (int) $user['id'];
            $sessions = $db->fetchAllAssociative(
                "SELECT s.* FROM bookings b JOIN gym_sessions s ON s.id=b.session_id WHERE b.user_id=? AND b.status='confirmed' AND s.cancelled=0 AND s.starts_at>?",
                [$id, date('Y-m-d H:i')],
            );
            $db->executeStatement(
                'DELETE FROM payments WHERE membership_id IN (SELECT id FROM memberships WHERE user_id=?)',
                [$id],
            );
            foreach (
                [
                    'ai_favorites',
                    'ai_runs',
                    'bookings',
                    'workouts',
                    'measurements',
                    'goals',
                    'programs',
                    'custom_exercises',
                    'memberships',
                    'checkins',
                ]
                as $table
            ) {
                $db->delete($table, ['user_id' => $id]);
            }
            $db->executeStatement(
                'DELETE FROM training_items WHERE session_id IN (SELECT id FROM training_sessions WHERE user_id=?)',
                [$id],
            );
            $db->delete('training_sessions', ['user_id' => $id]);
            $db->executeStatement('UPDATE gym_sessions SET coach_id=NULL WHERE coach_id=?', [$id]);
            $db->delete('users', ['id' => $id]);
            foreach ($sessions as $s) {
                $count = (int) $db->fetchOne(
                    "SELECT COUNT(*) FROM bookings WHERE session_id=? AND status='confirmed'",
                    [$s['id']],
                );
                if ($count >= (int) $s['capacity']) {
                    continue;
                }
                $wait = $db->fetchAllAssociative(
                    "SELECT b.* FROM bookings b JOIN users u ON u.id=b.user_id WHERE b.session_id=? AND b.status='waiting' AND u.active=1 ORDER BY b.created_at,b.id",
                    [$s['id']],
                );
                foreach ($wait as $w) {
                    $conflict = $db->fetchOne(
                        "SELECT COUNT(*) FROM bookings b JOIN gym_sessions s ON s.id=b.session_id WHERE b.user_id=? AND b.status='confirmed' AND s.cancelled=0 AND s.starts_at<? AND s.ends_at>?",
                        [$w['user_id'], $s['ends_at'], $s['starts_at']],
                    );
                    if (!$conflict) {
                        $db->update('bookings', ['status' => 'confirmed'], ['id' => $w['id']]);
                        break;
                    }
                }
            }
            $db->executeStatement('COMMIT');
        } catch (\Throwable $e) {
            $db->executeStatement('ROLLBACK');
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->success(
            "Compte {$user['email']} supprimé. Cette adresse mail peut être réutilisée pour une inscription.",
        );
        return Command::SUCCESS;
    }
}
