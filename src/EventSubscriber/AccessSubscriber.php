<?php

namespace App\EventSubscriber;

use App\Service\GymStore;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class AccessSubscriber implements EventSubscriberInterface
{
    public function __construct(private GymStore $store, private CsrfTokenManagerInterface $csrf) {}
    public static function getSubscribedEvents(): array
    {
        return ['kernel.request' => ['request', 0], 'kernel.response' => 'response'];
    }

    public function request(RequestEvent $e): void
    {
        if (!$e->isMainRequest()) {
            return;
        }
        $r = $e->getRequest();
        $path = $r->getPathInfo();
        if ($path === '/health') {
            return;
        }
        if (!in_array($path, ['/connexion', '/inscription'], true) && !$this->store->user()) {
            $e->setResponse(
                str_starts_with($path, '/api/')
                    ? new JsonResponse(['detail' => 'Connexion requise.'], 401)
                    : new RedirectResponse('/connexion'),
            );
            return;
        }
        if (
            !in_array($r->getMethod(), ['GET', 'HEAD', 'OPTIONS']) &&
            !$this->csrf->isTokenValid(
                new CsrfToken(
                    'gym',
                    (string) ($r->headers->get('X-CSRF-TOKEN') ?? $r->request->get('_token')),
                ),
            )
        ) {
            $e->setResponse(
                new JsonResponse(['detail' => 'Formulaire expiré, recharge la page.'], 403),
            );
        }
    }

    public function response(ResponseEvent $e): void
    {
        $h = $e->getResponse()->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Cache-Control', 'no-store, private');
    }
}
