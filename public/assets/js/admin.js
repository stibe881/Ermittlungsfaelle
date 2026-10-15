import { h, mount } from './dom.js';
import { get, post, api, setCsrf } from './api.js';
import { toast } from './ui.js';

const app = document.getElementById('app');

async function boot() {
  const me = await get('/api/me'); setCsrf(me.csrf);
  if (!me.user || me.user.role !== 'admin') return mount(app, h('div', { class: 'wrap' }, h('h1', {}, 'Kein Zugriff'), h('a', { href: '/' }, 'Zur Anmeldung')));
  await list();
}

async function list() {
  const [{ cases }, stats, abuse] = await Promise.all([get('/api/admin/cases'), get('/api/admin/stats'), get('/api/admin/abuse')]);
  mount(app, h('header', { class: 'topbar' }, h('a', { class: 'brand', href: '/' }, 'COLD CASE'), h('span', { class: 'muted' }, 'Administration')),
    h('div', { class: 'wrap' },
      h('div', { class: 'cols2' },
        h('div', { class: 'card' }, h('div', { class: 'muted small' }, 'Benutzer'), h('div', { class: 'stat' }, stats.users)),
        h('div', { class: 'card' }, h('div', { class: 'muted small' }, 'Sitzungen'), h('div', {}, stats.sessions.map(s => h('span', { class: 'chip', style: { marginRight: '6px' } }, `${s.status}: ${s.n}`))))),
      h('h2', {}, 'Fälle'),
      h('table', {}, h('thead', {}, h('tr', {}, ['Nr', 'Slug', 'Status', 'Version', 'Sitzungen', ''].map(x => h('th', {}, x)))),
        h('tbody', {}, cases.map(c => h('tr', {}, h('td', {}, c.case_number), h('td', {}, c.slug), h('td', {}, c.status), h('td', {}, 'v' + c.latest_version), h('td', {}, c.sessions), h('td', {}, h('button', { class: 'btn small', onclick: () => edit(c.slug) }, 'Bearbeiten')))))),
      h('div', { class: 'row end' }, h('button', { class: 'btn', onclick: () => { const s = prompt('Slug für neuen Fall (a-z, 0-9, -):'); if (s) edit(s, true); } }, '＋ Neuer Fall (JSON)')),
      h('h2', {}, 'Statistik je Fall'), h('table', {}, h('tbody', {}, stats.solved_by_case.map(r => h('tr', {}, h('td', {}, r.slug), h('td', {}, r.solved + ' gelöst'), h('td', {}, 'Ø ' + Math.round(r.avg_seconds / 60) + ' Min.'), h('td', {}, 'Ø ' + r.avg_hints + ' Hinweise'))))),
      h('h2', {}, 'Missbrauchsprüfung (Lösung < 5 Min.)'), abuse.suspicious.length ? h('table', {}, h('tbody', {}, abuse.suspicious.map(r => h('tr', {}, h('td', {}, r.username), h('td', {}, r.slug), h('td', {}, r.active_seconds + ' s'), h('td', {}, r.hints_used + ' Hinweise'))))) : h('p', { class: 'muted' }, 'Keine Auffälligkeiten.')));
}

async function edit(slug, isNew = false) {
  let data = { content: {}, report: null, version_id: null };
  if (!isNew) data = await get('/api/admin/cases/' + slug);
  const ta = h('textarea', { style: { minHeight: '60vh', fontFamily: 'ui-monospace,monospace', fontSize: '13px' }, spellcheck: 'false' });
  ta.value = JSON.stringify(data.content, null, 2);
  const out = h('div', { class: 'card' });
  const parse = () => { try { return JSON.parse(ta.value); } catch (e) { toast('JSON ungültig: ' + e.message, 'error'); return null; } };
  const show = r => mount(out, h('strong', {}, r.ok ? '✓ Validierung bestanden' : '✗ Validierung fehlgeschlagen'), r.errors.map(e => h('div', { style: { color: 'var(--danger)' } }, '• ' + e)), r.warnings.map(w => h('div', { class: 'muted' }, '• ' + w)), h('pre', { class: 'small muted' }, JSON.stringify(r.stats)));
  if (data.report) show(data.report);
  let versionId = data.version_id;
  mount(app, h('header', { class: 'topbar' }, h('a', { class: 'brand', href: '/admin.html' }, '← Fälle'), h('span', {}, slug)),
    h('div', { class: 'wrap' }, ta, h('div', { class: 'row' },
      h('button', { class: 'btn', onclick: async () => { const c = parse(); if (c) show(await post(`/api/admin/cases/${slug}/validate`, { content: c })); } }, 'Validieren'),
      h('button', { class: 'btn', onclick: async () => { const c = parse(); if (!c) return; const r = await api('PUT', `/api/admin/cases/${slug}`, { content: c }); versionId = r.version_id; show(r.report); toast('Als Version ' + r.version + ' gespeichert', 'ok'); } }, 'Speichern (neue Version)'),
      h('button', { class: 'btn primary', onclick: async () => { try { await post(`/api/admin/cases/${slug}/publish`, { version_id: versionId }); toast('Veröffentlicht', 'ok'); } catch (e) { toast(e.message, 'error'); } } }, 'Veröffentlichen'),
      h('button', { class: 'btn', onclick: async () => { try { const r = await post(`/api/admin/cases/${slug}/test`, { version_id: versionId }); location.href = '/#/play/' + r.id; } catch (e) { toast(e.message, 'error'); } } }, 'Testspiel (nicht gewertet)'),
      h('button', { class: 'btn danger', onclick: async () => { if (confirm('Fall archivieren?')) { await post(`/api/admin/cases/${slug}/archive`); toast('Archiviert', 'ok'); } } }, 'Archivieren')),
      out));
}
boot().catch(e => toast(e.message, 'error'));
