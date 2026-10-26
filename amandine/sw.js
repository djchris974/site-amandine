// Service worker de l'appli installable : tout passe par le réseau (données toujours à jour) ;
// seule une page « hors connexion » est gardée en mémoire pour quand il n'y a pas de réseau.
const CACHE = 'amandine-v1';
const HORS_LIGNE = 'hors-ligne.html';

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll([HORS_LIGNE, 'icones/icone-192.png'])));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((cles) => Promise.all(cles.filter((k) => k !== CACHE).map((k) => caches.delete(k)))));
  self.clients.claim();
});

self.addEventListener('fetch', (e) => {
  if (e.request.mode === 'navigate') {
    e.respondWith(fetch(e.request).catch(() => caches.match(HORS_LIGNE)));
  }
});
