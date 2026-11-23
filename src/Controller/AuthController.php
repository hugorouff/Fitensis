<?php

namespace App\Controller;

use App\Service\GymStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;

class AuthController extends AbstractController
{
    #[Route('/connexion', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $r, GymStore $store): Response
    {
        $error = null;
        if ($r->isMethod('POST')) {
            $db = $store->db();
            $db->executeStatement(
                'CREATE TABLE IF NOT EXISTS login_attempts(key TEXT PRIMARY KEY,failures INTEGER NOT NULL,blocked_until INTEGER NOT NULL)',
            );
            $key = hash('sha256', $r->getClientIp() ?? 'local');
            $attempt = $db->fetchAssociative('SELECT * FROM login_attempts WHERE key=?', [$key]);
            if (
                $attempt &&
                (int) $attempt['failures'] >= 8 &&
                (int) $attempt['blocked_until'] > time()
            ) {
                $error = 'Trop de tentatives. Réessaie dans 15 minutes.';
            } else {
                $u = $db->fetchAssociative('SELECT * FROM users WHERE email=? AND active=1', [
                    trim($r->request->get('email', '')),
                ]);
                if ($u && password_verify($r->request->get('password', ''), $u['password'])) {
                    $db->delete('login_attempts', ['key' => $key]);
                    $r->getSession()->migrate(true);
                    $r->getSession()->set('user_id', $u['id']);
                    return $this->redirect('/');
                }
                $n =
                    $attempt && $attempt['blocked_until'] > time()
                        ? (int) $attempt['failures'] + 1
                        : 1;
                $db->executeStatement(
                    'INSERT INTO login_attempts(key,failures,blocked_until) VALUES(?,?,?) ON CONFLICT(key) DO UPDATE SET failures=excluded.failures,blocked_until=excluded.blocked_until',
                    [$key, $n, time() + 900],
                );
                $error = 'Adresse mail ou mot de passe incorrect.';
            }
        }
        return $this->render('login.html.twig', ['error' => $error]);
    }

    #[Route('/inscription', name: 'register', methods: ['GET', 'POST'])]
    public function register(Request $r, GymStore $store): Response
    {
        if ($store->user()) {
            return $this->redirect('/');
        }
        $error = null;
        $name = '';
        $email = '';
        if ($r->isMethod('POST')) {
            $name = trim($r->request->getString('name'));
            $email = mb_strtolower(trim($r->request->getString('email')));
            $password = $r->request->getString('password');
            $confirmation = $r->request->getString('confirmation');
            if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
                $error = 'Renseigne ton nom (2 à 150 caractères).';
            } elseif (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Renseigne une adresse mail valide.';
            } elseif (strlen($password) < 12 || strlen($password) > 72) {
                $error = 'Choisis un mot de passe de 12 à 72 caractères.';
            } elseif ($password !== $confirmation) {
                $error = 'Les deux mots de passe ne correspondent pas.';
            } else {
                try {
                    // Public registration always creates a member, regardless of submitted fields.
                    $store
                        ->db()
                        ->insert('users', [
                            'name' => $name,
                            'email' => $email,
                            'password' => password_hash($password, PASSWORD_DEFAULT),
                            'role' => 'member',
                            'active' => 1,
                        ]);
                    $id = (int) $store->db()->lastInsertId();
                    $r->getSession()->migrate(true);
                    $r->getSession()->set('user_id', $id);
                    $this->addFlash('success', 'Ton compte est créé. Bienvenue au club !');
                    return $this->redirect('/');
                } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                    $error = 'Cette adresse mail est déjà utilisée. Connecte-toi avec ton compte.';
                }
            }
        }
        return $this->render('register.html.twig', compact('error', 'name', 'email'));
    }

    #[Route('/deconnexion', name: 'logout', methods: ['POST'])]
    public function logout(Request $r): Response
    {
        $r->getSession()->invalidate();
        return $this->redirect('/connexion');
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): Response
    {
        return $this->json(['status' => 'ok', 'application' => 'Gym Symfony']);
    }
}
