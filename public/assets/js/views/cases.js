import { h } from '../dom.js';
import { get, post } from '../api.js';
import { toast, fmtTime, media, confirmBox, modal } from '../ui.js';
import { ctx, route } from '../main.js';

const stars = n => '★'.repeat(n) + '☆'.repeat(5 - n);
const STATUS = { active: 'läuft', paused: 'pausiert', solved: 'gelöst', failed: 'ungelöst', abandoned: 'abgebrochen' };

async function start(slug, mode) {
  try { const r = await post('/api/sessions', { case: slug, mode }); location.hash = '#/play/' + r.id; }
  catch (e) { toast(e.message, 'error'); }
}

function caseCard(c) {
  const open = (c.my_sessions || []).find(s => ['active', 'paused'].includes(s.status));
  const solved = (c.my_sessions || []).some(s => s.status === 'solved');
  return h('article', { class: 'card case-card' },
    media(c.cover, 'Titelbild ' + c.title),
    h('div', { class: 'inner' },
      h('div', { class: 'row' }, h('span', { class: 'chip gold' }, 'Fall ' + c.case_number), h('span', { class: 'chip' }, c.type), solved && h('span', { class: 'chip' }, '✓ gelöst')),
      h('h3', {}, c.title),
      h('div', { class: 'small muted' }, `${c.location} · ${c.year} · ca. ${c.duration_min} Min. · ${c.suspects} Verdächtige`),
      h('div', { class: 'stars', 'aria-label': 'Schwierigkeit ' + c.difficulty + ' von 5' }, stars(c.difficulty)),
      h('p', { class: 'muted small grow' }, c.teaser),
      c.best_seconds != null && h('div', { class: 'small' }, 'Persönliche Bestzeit: ', h('strong', {}, fmtTime(c.best_seconds))),
      h('div', { class: 'row' },
        open ? h('a', { class: 'btn primary grow', href: '#/play/' + open.id }, 'Fortsetzen (' + fmtTime(open.active_seconds) + ')')
             : [h('button', { class: 'btn primary grow', onclick: () => start(c.slug, 'solo') }, solved ? 'Übungsmodus' : 'Solo starten'), h('button', { class: 'btn', onclick: () => start(c.slug, 'team') }, 'Team')],
        h('a', { class: 'btn ghost', href: '#/leaderboard/' + c.slug, 'aria-label': 'Rangliste' }, '🏆'))));
}

export async function dashboard() {
  const [{ cases }, me] = await Promise.all([get('/api/cases'), get('/api/me')]);
  const u = me.user;
  const code = h('input', { placeholder: 'Einladungscode', maxlength: 8, 'aria-label': 'Einladungscode', style: { textTransform: 'uppercase', maxWidth: '200px' } });
  return h('div', { class: 'wrap' },
    h('h1', {}, 'Ermittler-Dashboard'),
    h('div', { class: 'cols2' },
      h('div', { class: 'card' }, h('div', { class: 'muted small' }, 'Dienstgrad'), h('div', { class: 'stat' }, u.rank),
        h('div', { class: 'row' }, h('span', { class: 'chip' }, u.solved + ' gelöst'), h('span', { class: 'chip' }, u.running + ' laufend'), h('span', { class: 'chip' }, fmtTime(u.play_seconds) + ' aktive Zeit'))),
      h('form', { class: 'card', onsubmit: async e => { e.preventDefault(); try { const r = await post('/api/sessions/join', { code: code.value }); location.hash = '#/play/' + r.id; } catch (err) { toast(err.message, 'error'); } } },
        h('div', { class: 'muted small' }, 'Einer Ermittlungsgruppe beitreten'), h('div', { class: 'row' }, code, h('button', { class: 'btn' }, 'Beitreten')))),
    u.achievements.length > 0 && h('div', { class: 'card', style: { marginTop: '14px' } }, h('div', { class: 'muted small' }, 'Erfolge'),
      h('div', { class: 'row' }, u.achievements.map(a => h('span', { class: 'chip gold', title: a.description }, '🏅 ' + a.title)))),
    h('h2', { style: { marginTop: '28px' } }, 'Fälle'),
    h('div', { class: 'grid' }, cases.map(caseCard)));
}

export async function leaderboardView(slug) {
  const body = h('div', {});
  let mode = 'solo', hints0 = false;
  const draw = async () => {
    const r = await get(`/api/cases/${encodeURIComponent(slug)}/leaderboard?mode=${mode}${hints0 ? '&hints=0' : ''}`);
    body.replaceChildren(
      h('div', { class: 'chips' },
        h('button', { class: 'chipbtn', 'aria-pressed': mode === 'solo', onclick: () => { mode = 'solo'; draw(); } }, 'Solo'),
        h('button', { class: 'chipbtn', 'aria-pressed': mode === 'team', onclick: () => { mode = 'team'; draw(); } }, 'Team'),
        h('button', { class: 'chipbtn', 'aria-pressed': hints0, onclick: () => { hints0 = !hints0; draw(); } }, 'Nur ohne Hinweise')),
      r.entries.length ? h('div', { style: { overflowX: 'auto' } }, h('table', {},
        h('thead', {}, h('tr', {}, ['#', 'Ermittler', 'Rang', 'Zeit', 'Hinweise', 'Fehlanklagen', 'Datum'].map(t => h('th', {}, t)))),
        h('tbody', {}, r.entries.map(e => h('tr', {}, h('td', {}, e.place), h('td', {}, e.username + (e.players > 1 ? ` (+${e.players - 1})` : '')), h('td', {}, e.rank), h('td', {}, fmtTime(e.score_seconds)), h('td', {}, e.hints_used), h('td', {}, e.false_accusations), h('td', {}, e.date))))))
        : h('p', { class: 'muted' }, 'Noch keine Einträge.'),
      h('p', { class: 'small muted' }, r.rules));
  };
  await draw();
  return h('div', { class: 'wrap' }, h('a', { href: '#/' }, '← Zurück'), h('h1', {}, 'Rangliste'), body);
}

export async function accountView() {
  const u = ctx.user;
  return h('div', { class: 'wrap' }, h('a', { href: '#/' }, '← Zurück'), h('h1', {}, 'Konto & Datenschutz'),
    h('div', { class: 'card' }, h('p', {}, 'Angemeldet als ', h('strong', {}, u.username), ' (' + u.email + ')'),
      h('p', { class: 'muted small' }, 'Öffentlich sichtbar ist ausschliesslich dein Benutzername (Ranglisten). Deine E-Mail-Adresse wird nie angezeigt.'),
      h('div', { class: 'row' },
        h('button', { class: 'btn', onclick: async () => {
          const d = await get('/api/me/export');
          const a = h('a', { href: URL.createObjectURL(new Blob([JSON.stringify(d, null, 2)], { type: 'application/json' })), download: 'coldcase-export.json' }); a.click();
        } }, 'Meine Daten exportieren'),
        h('button', { class: 'btn danger', onclick: async () => {
          if (!await confirmBox('Konto löschen', 'Alle Spielstände, Notizen und Ranglisteneinträge werden unwiderruflich gelöscht. Teamsitzungen, die du erstellt hast, werden für alle Mitglieder entfernt.', 'Endgültig löschen')) return;
          const pw = prompt('Zur Bestätigung dein Passwort eingeben:'); if (!pw) return;
          try { await post('/api/me/delete', { password: pw }); ctx.user = null; location.hash = '#/'; route(); toast('Konto gelöscht.', 'ok'); } catch (e) { toast(e.message, 'error'); }
        } }, 'Konto löschen'))));
}
