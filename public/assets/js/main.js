import { h, mount, $ } from './dom.js';
import { get, post, setCsrf } from './api.js';
import { toast } from './ui.js';
import { home, authForm, verifyView, resetView } from './views/home.js';
import { dashboard, leaderboardView, accountView } from './views/cases.js';
import { playView, stopPlay } from './views/game.js';

export const ctx = { user: null };

export async function refreshMe() {
  const r = await get('/api/me');
  setCsrf(r.csrf); ctx.user = r.user;
  return r.user;
}

function topbar() {
  const u = ctx.user;
  return h('header', { class: 'topbar' },
    h('a', { class: 'brand', href: '#/' }, 'COLD CASE'),
    h('nav', {},
      u ? [h('a', { class: 'btn small ghost', href: '#/' }, 'Dashboard'), h('a', { class: 'btn small ghost', href: '#/account' }, u.username + ' · ' + u.rank),
           u.role === 'admin' && h('a', { class: 'btn small ghost', href: '/admin.html' }, 'Admin'),
           h('button', { class: 'btn small ghost', onclick: async () => { const r = await post('/api/auth/logout'); setCsrf(r.csrf); ctx.user = null; location.hash = '#/'; route(); } }, 'Abmelden')]
        : [h('a', { class: 'btn small ghost', href: '#/login' }, 'Anmelden'), h('a', { class: 'btn small primary', href: '#/register' }, 'Registrieren')]));
}

export async function route() {
  stopPlay();
  const app = $('#app');
  const parts = (location.hash.replace(/^#\/?/, '') || '').split('/');
  const view = parts[0];
  try {
    if (view === 'play' && ctx.user) return mount(app, await playView(Number(parts[1])));
    const page = h('div', {});
    let body;
    if (view === 'verify') body = await verifyView(parts[1]);
    else if (view === 'reset') body = resetView(parts[1]);
    else if (view === 'login' || view === 'register') body = authForm(view);
    else if (!ctx.user) body = home();
    else if (view === 'leaderboard') body = await leaderboardView(parts[1]);
    else if (view === 'account') body = await accountView();
    else body = await dashboard();
    mount(app, topbar(), body);
    window.scrollTo(0, 0);
  } catch (e) {
    if (e.status === 401) { ctx.user = null; location.hash = '#/login'; return; }
    toast(e.message || 'Fehler', 'error');
    if (view === 'play') location.hash = '#/';
  }
}

window.addEventListener('hashchange', route);
(async () => {
  try { await refreshMe(); } catch { toast('Server nicht erreichbar', 'error'); }
  route();
  if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
})();
