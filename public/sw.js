// Service Worker: Shell wird gecacht (offline startfähig), API/Medien immer frisch.
const V = 'cc-shell-v1';
const SHELL = ['/', '/assets/css/app.css', '/assets/js/main.js', '/assets/js/dom.js', '/assets/js/api.js', '/assets/js/ui.js',
  '/assets/js/views/home.js', '/assets/js/views/cases.js', '/assets/js/views/game.js', '/assets/js/views/board.js', '/assets/js/views/extra.js'];
self.addEventListener('install', e => { e.waitUntil(caches.open(V).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== V).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET' || u.origin !== location.origin || u.pathname.startsWith('/api/')) return;
  if (e.request.mode === 'navigate') { e.respondWith(fetch(e.request).catch(() => caches.match('/'))); return; }
  e.respondWith(fetch(e.request).then(r => { const c = r.clone(); if (r.ok && SHELL.includes(u.pathname)) caches.open(V).then(x => x.put(e.request, c)); return r; }).catch(() => caches.match(e.request)));
});
