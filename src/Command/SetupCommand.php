<?php

namespace App\Command;

use App\Service\GymStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
#[AsCommand(
    name: 'app:setup',
    description: 'Initialise la base et le premier administrateur',
)]
class SetupCommand extends Command
{
    public function __construct(private GymStore $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'email',
            null,
            InputOption::VALUE_REQUIRED,
            'Adresse administrateur',
            'admin@gym.local',
        )
            ->addOption(
                'password',
                null,
                InputOption::VALUE_REQUIRED,
                'Mot de passe (sinon généré)',
            )
            ->addOption(
                'demo',
                null,
                InputOption::VALUE_NONE,
                'Ajouter des exemples de démonstration',
            );
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $io = new SymfonyStyle($in, $out);
        $this->store->init();
        $db = $this->store->db();
        $email = $in->getOption('email');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Adresse mail invalide.');
            return 1;
        }
        if (!$db->fetchOne('SELECT COUNT(*) FROM users')) {
            $password = $in->getOption('password') ?: bin2hex(random_bytes(9));
            if (strlen($password) < 12) {
                $io->error('12 caractères minimum.');
                return 1;
            }
            $db->insert('users', [
                'name' => 'Administrateur',
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'admin',
            ]);
            $io->success("Administrateur : $email / $password — conserve ce mot de passe.");
            if ($in->getOption('demo')) {
                foreach (
                    [
                        ['Alex Martin', 'coach@gym.local', 'coach'],
                        ['Camille Dupont', 'camille@gym.local', 'member'],
                    ]
                    as [$n, $e, $r]
                ) {
                    $db->insert('users', [
                        'name' => $n,
                        'email' => $e,
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'role' => $r,
                    ]);
                }
                foreach ([1, 3] as $uid) {
                    foreach (
                        [
                            [
                                1,
                                'Haut du corps',
                                ['chest', 'back', 'shoulders'],
                                'Développé couché',
                            ],
                            [3, 'Jambes', ['quads', 'hamstrings', 'glutes'], 'Squat'],
                            [5, 'Full body', ['chest', 'back', 'quads', 'abs'], 'Rowing haltère'],
                        ]
                        as [$offset, $title, $groups, $exercise]
                    ) {
                        $sid = $this->store->insertAndGetId('training_sessions', [
                            'user_id' => $uid,
                            'title' => $title,
                            'starts_at' => date('Y-m-d', strtotime("+$offset days")) . ' 18:00',
                            'preset' => 'custom',
                            'muscles' => json_encode($groups),
                        ]);
                        $db->insert('training_items', [
                            'session_id' => $sid,
                            'exercise' => $exercise,
                            'sets' => 3,
                            'reps' => 10,
                            'weight' => 0,
                        ]);
                    };
                }
                foreach (
                    [
                        ['Presse à cuisses', 'Plateau', 'Disponible'],
                        ['Rameur Concept', 'Cardio', 'Disponible'],
                        ['Poulie haute', 'Plateau', 'Maintenance'],
                    ]
                    as [$n, $z, $s]
                ) {
                    $db->insert('equipment', [
                        'name' => $n,
                        'zone' => $z,
                        'status' => $s,
                        'next_service' => date('Y-m-d', strtotime('+30 days')),
                        'notes' => 'Exemple de matériel',
                    ]);
                }
                $db->insert('programs', [
                    'user_id' => 3,
                    'title' => 'Reprise · Full body',
                    'goal' => 'Régularité et technique',
                    'weeks' => 6,
                    'content' =>
                        "3 séances / semaine\nSquat : 3 × 10\nDéveloppé couché : 3 × 10\nTirage : 3 × 12\nGainage : 3 × 30 secondes",
                ]);
                $db->insert('memberships', [
                    'user_id' => 3,
                    'plan' => 'Essentiel',
                    'starts_on' => date('Y-m-d'),
                    'ends_on' => date('Y-m-d', strtotime('+1 month')),
                    'price_cents' => 3990,
                    'status' => 'Actif',
                ]);
                $io->note(
                    'Comptes coach@gym.local et camille@gym.local : même mot de passe que le compte administrateur.',
                );
            }
        } else {
            $io->note('Base mise à jour, comptes existants conservés.');
        }
        $io->success('Installation terminée.');
        return 0;
    }
}
