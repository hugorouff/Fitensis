<?php

namespace App\Controller;

use App\Service\GymStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

class GymController extends AbstractController
{
    public function __construct(private GymStore $store) {}
    private const RESOURCES = [
        'workouts' => ['user_id', 'date', 'exercise', 'sets', 'reps', 'weight', 'rpe', 'notes'],
        'measurements' => ['user_id', 'date', 'weight', 'waist', 'body_fat', 'notes'],
        'goals' => ['user_id', 'title', 'target', 'current', 'unit', 'deadline', 'done'],
        'programs' => ['user_id', 'title', 'goal', 'weeks', 'content'],
        'equipment' => ['name', 'zone', 'status', 'next_service', 'notes'],
        'memberships' => ['user_id', 'plan', 'starts_on', 'ends_on', 'price_cents', 'status'],
        'payments' => ['membership_id', 'amount_cents', 'paid_on', 'method', 'reference'],
    ];
    private function admin(): void
    {
        if (($this->store->user()['role'] ?? '') !== 'admin') {
            throw $this->createAccessDeniedException();
        }
    }

    private function staff(): void
    {
        if (!$this->store->staff()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function date(string $value, bool $time = false): string
    {
        $format = $time ? 'Y-m-d H:i' : 'Y-m-d';
        $value = str_replace('T', ' ', $value);
        $d = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        if (!$d || $d->format($format) !== $value) {
            throw new \InvalidArgumentException('Date invalide.');
        }
        return $value;
    }

    private function text(array $d, string $key, int $max = 250): string
    {
        $s = trim((string) ($d[$key] ?? ''));
        if ($s === '' || mb_strlen($s) > $max) {
            throw new \InvalidArgumentException("Champ $key requis (maximum $max caractères).");
        }
        return $s;
    }

    private function number(mixed $v, float $min, float $max): float
    {
        if (!is_numeric($v) || !is_finite((float) $v) || (float) $v < $min || (float) $v > $max) {
            throw new \InvalidArgumentException("Nombre attendu entre $min et $max.");
        }
        return (float) $v;
    }

    private function integer(mixed $v, int $min, int $max): int
    {
        $n = $this->number($v, $min, $max);
        if (floor($n) !== $n) {
            throw new \InvalidArgumentException('Nombre entier attendu.');
        }
        return (int) $n;
    }

    private function finish(Request $r, callable $action, string $back): Response
    {
        try {
            $action();
            $this->addFlash('success', 'Modification enregistrée.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirect($back);
    }

    #[Route(
        '/espace/{resource}',
        name: 'resource',
        methods: ['GET'],
        requirements: [
            'resource' => 'workouts|measurements|goals|programs|equipment|memberships|payments',
        ],
    )]
    public function listing(string $resource, Request $r): Response
    {
        if (in_array($resource, ['memberships', 'payments'])) {
            $this->admin();
        }
        if ($resource === 'equipment') {
            $this->staff();
        }
        $db = $this->store->db();
        $id = $this->store->user()['id'];
        $where =
            in_array($resource, ['equipment', 'memberships', 'payments']) ||
            ($resource === 'programs' && $this->store->staff())
                ? '1=1'
                : 't.user_id=' . (int) $id;
        if ($resource === 'memberships') {
            $rows = $db->fetchAllAssociative(
                'SELECT t.*,u.name member,COALESCE((SELECT SUM(p.amount_cents) FROM payments p WHERE p.membership_id=t.id),0) paid_cents FROM memberships t JOIN users u ON u.id=t.user_id ORDER BY t.ends_on DESC',
            );
        } elseif ($resource === 'payments') {
            $rows = $db->fetchAllAssociative(
                'SELECT t.*,u.name member,m.plan FROM payments t JOIN memberships m ON m.id=t.membership_id JOIN users u ON u.id=m.user_id ORDER BY t.paid_on DESC',
            );
        } elseif ($resource === 'equipment') {
            $rows = $db->fetchAllAssociative('SELECT * FROM equipment ORDER BY name');
        } else {
            $rows = $db->fetchAllAssociative(
                "SELECT t.*,u.name member FROM $resource t JOIN users u ON u.id=t.user_id WHERE $where ORDER BY t.id DESC",
            );
        }
        $users = $this->store->staff()
            ? $db->fetchAllAssociative('SELECT id,name FROM users WHERE active=1 ORDER BY name')
            : [];
        $memberships =
            $resource === 'payments'
                ? $db->fetchAllAssociative(
                    'SELECT m.*,u.name member FROM memberships m JOIN users u ON u.id=m.user_id',
                )
                : [];
        $edit = null;
        if ($r->query->getInt('edit')) {
            foreach ($rows as $row) {
                if ((int) $row['id'] === $r->query->getInt('edit')) {
                    $edit = $row;
                }
            };
        }
        $prefillExercise =
            $resource === 'workouts'
                ? trim(mb_substr($r->query->getString('exercise'), 0, 250))
                : '';
        return $this->render(
            'resource.html.twig',
            compact('resource', 'rows', 'users', 'memberships', 'edit', 'prefillExercise'),
        );
    }

    #[Route('/espace/{resource}/enregistrer', methods: ['POST'])]
    public function save(string $resource, Request $r): Response
    {
        if (!isset(self::RESOURCES[$resource])) {
            throw $this->createNotFoundException();
        }
        if (in_array($resource, ['equipment', 'memberships', 'payments'])) {
            $this->admin();
        }
        if ($resource === 'programs') {
            $this->staff();
        }
        return $this->finish(
            $r,
            function () use ($resource, $r) {
                $d = $r->request->all();
                $db = $this->store->db();
                $id = (int) ($d['id'] ?? 0);
                $data = [];
                foreach (self::RESOURCES[$resource] as $field) {
                    $data[$field] = $d[$field] ?? '';
                }
                if (in_array('user_id', self::RESOURCES[$resource])) {
                    $data['user_id'] = in_array($resource, ['programs', 'memberships'])
                        ? (int) ($d['user_id'] ?? 0)
                        : $this->store->user()['id'];
                    $this->store->requireUser($data['user_id']);
                }
                if ($id) {
                    $old = $db->fetchAssociative("SELECT * FROM $resource WHERE id=?", [$id]);
                    if (!$old) {
                        throw new \InvalidArgumentException('Élément introuvable.');
                    }
                    if (
                        in_array($resource, ['workouts', 'measurements', 'goals']) &&
                        (int) $old['user_id'] !== $this->store->user()['id']
                    ) {
                        throw $this->createAccessDeniedException();
                    }
                }
                foreach (
                    ['date', 'deadline', 'starts_on', 'ends_on', 'next_service', 'paid_on']
                    as $f
                ) {
                    if (array_key_exists($f, $data)) {
                        $data[$f] = $this->date($data[$f]);
                    }
                }
                foreach (
                    ['title', 'name', 'zone', 'plan', 'exercise', 'goal', 'unit', 'method']
                    as $f
                ) {
                    if (array_key_exists($f, $data)) {
                        $data[$f] = $this->text($d, $f);
                    }
                }
                $data = array_map(fn($v) => is_string($v) ? mb_substr($v, 0, 10000) : $v, $data);
                if ($resource === 'workouts') {
                    $data['sets'] = $this->integer($d['sets'], 1, 50);
                    $data['reps'] = $this->integer($d['reps'], 1, 200);
                    $data['weight'] = $this->number($d['weight'], 0, 1000);
                    $data['rpe'] = $this->number($d['rpe'], 1, 10);
                }
                if ($resource === 'measurements') {
                    $data['weight'] = $this->number($d['weight'], 25, 350);
                    foreach (['waist' => [30, 250], 'body_fat' => [1, 70]] as $f => $bounds) {
                        $data[$f] = $d[$f] !== '' ? $this->number($d[$f], ...$bounds) : null;
                    }
                }
                if ($resource === 'goals') {
                    $data['target'] = $this->number($d['target'], 0.1, 1000000);
                    $data['current'] = $this->number($d['current'], 0, 1000000);
                    $data['done'] = isset($d['done']) ? 1 : 0;
                }
                if ($resource === 'programs') {
                    $data['weeks'] = $this->integer($d['weeks'], 1, 104);
                    $data['content'] = $this->text($d, 'content', 10000);
                }
                if (
                    $resource === 'equipment' &&
                    !in_array($d['status'], ['Disponible', 'Maintenance', 'Hors service'])
                ) {
                    throw new \InvalidArgumentException('État invalide.');
                }
                if ($resource === 'memberships') {
                    $data['price_cents'] = (int) round(
                        $this->number($d['price'] ?? '', 0, 100000) * 100,
                    );
                    if ($data['ends_on'] < $data['starts_on']) {
                        throw new \InvalidArgumentException('Dates incohérentes.');
                    }
                    if (!in_array($d['status'], ['Actif', 'Suspendu', 'Terminé'])) {
                        throw new \InvalidArgumentException('État invalide.');
                    }
                }
                if ($resource === 'payments') {
                    $data['amount_cents'] = (int) round(
                        $this->number($d['amount'] ?? '', 0.01, 100000) * 100,
                    );
                    $data['membership_id'] = (int) $d['membership_id'];
                    if (
                        !$db->fetchOne('SELECT id FROM memberships WHERE id=?', [
                            $data['membership_id'],
                        ])
                    ) {
                        throw new \InvalidArgumentException('Abonnement introuvable.');
                    }
                }
                if ($id) {
                    $db->update($resource, $data, ['id' => $id]);
                } else {
                    $db->insert($resource, $data);
                }
            },
            '/espace/' . $resource,
        );
    }

    #[Route('/espace/{resource}/{id}/supprimer', methods: ['POST'])]
    public function remove(string $resource, int $id, Request $r): Response
    {
        if (!isset(self::RESOURCES[$resource])) {
            throw $this->createNotFoundException();
        }
        if (in_array($resource, ['equipment', 'memberships', 'payments'])) {
            $this->admin();
        }
        if ($resource === 'programs') {
            $this->staff();
        }
        return $this->finish(
            $r,
            function () use ($resource, $id) {
                $db = $this->store->db();
                $row = $db->fetchAssociative("SELECT * FROM $resource WHERE id=?", [$id]);
                if (!$row) {
                    throw new \InvalidArgumentException('Élément introuvable.');
                }
                if (
                    in_array($resource, ['workouts', 'measurements', 'goals']) &&
                    (int) $row['user_id'] !== $this->store->user()['id']
                ) {
                    throw $this->createAccessDeniedException();
                }
                if (
                    $resource === 'memberships' &&
                    $db->fetchOne('SELECT COUNT(*) FROM payments WHERE membership_id=?', [$id])
                ) {
                    throw new \InvalidArgumentException(
                        'Un abonnement avec paiements doit être clôturé, pas supprimé.',
                    );
                }
                $db->delete($resource, ['id' => $id]);
            },
            '/espace/' . $resource,
        );
    }

    #[Route('/adherents', name: 'members', methods: ['GET'])]
    public function members(): Response
    {
        $this->staff();
        $rows = $this->store
            ->db()
            ->fetchAllAssociative(
                'SELECT id,name,email,role,phone,active FROM users ORDER BY name',
            );
        return $this->render('members.html.twig', compact('rows'));
    }

    #[Route('/adherents/enregistrer', methods: ['POST'])]
    public function saveMember(Request $r): Response
    {
        $this->admin();
        return $this->finish(
            $r,
            function () use ($r) {
                $d = $r->request->all();
                $id = (int) ($d['id'] ?? 0);
                $email = $this->text($d, 'email');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new \InvalidArgumentException('Adresse mail invalide.');
                }
                if (!in_array($d['role'] ?? '', ['admin', 'coach', 'member'])) {
                    throw new \InvalidArgumentException('Rôle invalide.');
                }
                $data = [
                    'name' => $this->text($d, 'name'),
                    'email' => $email,
                    'role' => $d['role'],
                    'phone' => mb_substr($d['phone'] ?? '', 0, 40),
                    'active' => isset($d['active']) ? 1 : 0,
                ];
                $db = $this->store->db();
                if (
                    $db->fetchOne('SELECT COUNT(*) FROM users WHERE email=? AND id!=?', [
                        $email,
                        $id,
                    ])
                ) {
                    throw new \InvalidArgumentException('Cette adresse mail est déjà utilisée.');
                }
                if (
                    $id === $this->store->user()['id'] &&
                    ($data['role'] !== 'admin' || !$data['active'])
                ) {
                    throw new \InvalidArgumentException(
                        'Tu ne peux pas retirer ton propre accès administrateur.',
                    );
                }
                if (!$id || !empty($d['password'])) {
                    if (strlen($d['password'] ?? '') < 12) {
                        throw new \InvalidArgumentException(
                            'Mot de passe : 12 caractères minimum.',
                        );
                    }
                    $data['password'] = password_hash($d['password'], PASSWORD_DEFAULT);
                }
                if ($id) {
                    if (!$db->fetchOne('SELECT id FROM users WHERE id=?', [$id])) {
                        throw new \InvalidArgumentException('Compte introuvable.');
                    }
                    $db->update('users', $data, ['id' => $id]);
                } else {
                    $db->insert('users', $data);
                }
            },
            '/adherents',
        );
    }

    #[Route('/profil', name: 'profile', methods: ['GET', 'POST'])]
    public function profile(Request $r): Response
    {
        if ($r->isMethod('POST')) {
            return $this->finish(
                $r,
                function () use ($r) {
                    $d = $r->request->all();
                    $data = [
                        'name' => $this->text($d, 'name'),
                        'phone' => mb_substr($d['phone'] ?? '', 0, 40),
                    ];
                    $u = $this->store
                        ->db()
                        ->fetchAssociative('SELECT * FROM users WHERE id=?', [
                            $this->store->user()['id'],
                        ]);
                    if (!empty($d['password'])) {
                        if (!password_verify($d['current_password'] ?? '', $u['password'])) {
                            throw new \InvalidArgumentException('Mot de passe actuel incorrect.');
                        }
                        if (strlen($d['password']) < 12) {
                            throw new \InvalidArgumentException('12 caractères minimum.');
                        }
                        $data['password'] = password_hash($d['password'], PASSWORD_DEFAULT);
                        $r->getSession()->migrate(true);
                    }
                    $this->store->db()->update('users', $data, ['id' => $u['id']]);
                },
                '/profil',
            );
        }
        $memberships = $this->store
            ->db()
            ->fetchAllAssociative(
                'SELECT * FROM memberships WHERE user_id=? ORDER BY ends_on DESC',
                [$this->store->user()['id']],
            );
        return $this->render('profile.html.twig', compact('memberships'));
    }

    #[Route('/presence', methods: ['POST'])]
    public function checkin(Request $r): Response
    {
        return $this->finish(
            $r,
            function () use ($r) {
                $id = $this->store->user()['id'];
                if ($this->store->staff() && $r->request->get('user_id')) {
                    $id = (int) $r->request->get('user_id');
                }
                $this->store->requireUser($id);
                $this->store
                    ->db()
                    ->executeStatement(
                        'INSERT INTO checkins(user_id,day) VALUES(?,?) ON CONFLICT(user_id,day) DO NOTHING',
                        [$id, date('Y-m-d')],
                    );
            },
            '/',
        );
    }

    #[Route(
        '/export/{type}.csv',
        methods: ['GET'],
        requirements: ['type' => 'workouts|measurements|members'],
    )]
    public function export(string $type): Response
    {
        $db = $this->store->db();
        $id = $this->store->user()['id'];
        if ($type === 'members') {
            $this->admin();
            $rows = $db->fetchAllAssociative('SELECT name,email,phone,role,active FROM users');
        } else {
            $rows = $db->fetchAllAssociative("SELECT * FROM $type WHERE user_id=?", [$id]);
        }
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        if ($rows) {
            fputcsv($f, array_keys($rows[0]), ';', '"', '');
            foreach ($rows as $row) {
                fputcsv(
                    $f,
                    array_map(
                        fn($v) => is_string($v) && preg_match('/^[=+@\-\t\r]/', $v) ? "'" . $v : $v,
                        $row,
                    ),
                    ';',
                    '"',
                    '',
                );
            }
        }
        rewind($f);
        return new Response(stream_get_contents($f), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $type . '.csv"',
        ]);
    }
}
