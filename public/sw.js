self.addEventListener('install', event => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', event => {
    event.waitUntil(self.clients.claim());
});

// Les réponses privées et les identifiants de session ne sont jamais stockés par le service worker.
self.addEventListener('fetch', event => {
    if (event.request.mode !== 'navigate' || event.request.method !== 'GET') return;

    event.respondWith(fetch(event.request).catch(() => new Response(
        '<!doctype html><html lang="fr"><meta charset="utf-8"><title>Fitensis</title><h1>Fitensis est hors ligne</h1><p>Reconnecte-toi au réseau puis recharge cette page.</p></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } }
    )));
});
