import { h, mount } from './dom.js';
import { media, CATEGORY } from './ui.js';

// TV-Client: kein Login. Token liegt nur im Speicher/sessionStorage des TV-Browsers.
const root = document.getElementById('tv');
let token = null, ver = '', timer = null;
try { token = sessionStorage.getItem('cc_tv'); } catch { /* ignore */ }

async function api(path, opts) { const r = await fetch(path, opts); const d = await r.json().catch(() => ({})); if (!r.ok) throw Object.assign(new Error(d?.error?.message || 'Fehler'), { status: r.status }); return d; }

async function pair() {
  const r = await api('/api/tv/pair', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' });
  token = r.token; try { sessionStorage.setItem('cc_tv', token); } catch { /* ignore */ }
  return r.code;
}

function waiting(code) {
  mount(root, h('div', { class: 'tv-head' }, h('span', {}, 'COLD CASE'), h('span', {}, 'Fernseher')),
    h('div', { class: 'tv-center' }, h('p', { class: 'muted' }, 'Öffne COLD CASE auf deinem Smartphone, tippe auf 📺 und gib diesen Code ein:'), h('div', { class: 'tv-code', 'aria-live': 'polite' }, code.slice(0, 3) + ' ' + code.slice(3)),
      h('p', { class: 'muted small' }, 'Warte auf Verbindung …')));
}

function draw(s) {
  const head = h('div', { class: 'tv-head' }, h('span', {}, `Fall ${s.case_number} · ${s.title}`), h('span', {}, s.members.join(' · ')));
  let body;
  if (s.view === 'evidence') {
    const e = s.evidence;
    body = h('div', { class: 'tv-main' + (e.media ? '' : ' one') }, e.media ? media(e.media, e.title) : null,
      h('div', { class: 'doc' }, h('h4', {}, `${CATEGORY[e.category] || e.category}: ${e.title}`), h('div', { class: 'meta' }, `${e.location}${e.time ? ' · ' + e.time : ''}`), h('p', {}, e.description)));
  } else if (s.view === 'scene') {
    body = h('div', { class: 'tv-center' }, h('div', { style: { width: '100%', aspectRatio: '16/9' } }, media(s.scene.image, s.scene.title, 'tvscene')), h('h1', {}, s.scene.title), h('p', { class: 'muted' }, s.scene.description));
  } else if (s.view === 'suspect') {
    const x = s.suspect;
    body = h('div', { class: 'tv-main' }, h('div', { class: 'tv-center' }, media(x.portrait, x.name), h('h1', {}, x.name), h('p', { class: 'muted' }, `${x.age} Jahre · ${x.job}`)),
      h('div', { class: 'doc' }, h('p', {}, h('strong', {}, 'Beziehung: '), x.relation), h('p', {}, x.bio), x.history.slice(-3).map(q => h('p', {}, h('em', {}, '„' + q.question + '“'), ' – ', q.answer))));
  } else if (s.view === 'timeline') {
    body = h('div', { class: 'tl tv-tl', style: { overflow: 'auto', flex: 1 } }, s.timeline.map(t => h('div', { class: 'tl-item' + (t.flagged ? ' flag' : '') }, h('span', { class: 'tl-time' }, (t.day ? '+1 ' : '') + t.time), ' ', t.uncertainty ? `(±${t.uncertainty} Min.) ` : '', h('strong', {}, t.title), h('div', { class: 'small' }, t.text))));
  } else if (s.view === 'board') {
    const items = new Map(s.board.items.map(i => [i.id, i]));
    const svgNS = 'http://www.w3.org/2000/svg'; const svg = document.createElementNS(svgNS, 'svg');
    svg.setAttribute('viewBox', '0 0 1800 1100'); svg.setAttribute('preserveAspectRatio', 'xMidYMid meet'); svg.style.cssText = 'position:absolute;inset:0;width:100%;height:100%';
    const wrap = document.createElementNS(svgNS, 'g'); svg.append(wrap);
    for (const l of s.board.links) { const a = items.get(l.from_item), b = items.get(l.to_item); if (!a || !b) continue; const ln = document.createElementNS(svgNS, 'line'); [['x1', a.x + 75], ['y1', a.y + 30], ['x2', b.x + 75], ['y2', b.y + 30], ['stroke', '#c0392b'], ['stroke-width', 3]].forEach(([k, v]) => ln.setAttribute(k, v)); wrap.append(ln);
      if (l.label) { const t = document.createElementNS(svgNS, 'text'); [['x', (a.x + b.x) / 2 + 75], ['y', (a.y + b.y) / 2 + 26], ['fill', '#f3e9c8'], ['font-size', 22], ['text-anchor', 'middle']].forEach(([k, v]) => t.setAttribute(k, v)); t.textContent = l.label; wrap.append(t); } }
    for (const i of s.board.items) { const fo = document.createElementNS(svgNS, 'foreignObject'); [['x', i.x], ['y', i.y], ['width', 170], ['height', 90]].forEach(([k, v]) => fo.setAttribute(k, v)); const d = document.createElement('div'); d.className = 'pin ' + i.color; d.style.cssText = 'position:static;width:150px'; d.textContent = i.label; fo.append(d); svg.append(fo); }
    body = h('div', { class: 'tv-board' }, svg);
  } else if (s.view === 'resolution') {
    body = h('div', { class: 'doc' }, h('h4', {}, 'Fall gelöst'), h('p', {}, s.resolution.text.summary));
  } else {
    body = h('div', { class: 'tv-center' }, h('h1', {}, s.title), h('p', { class: 'muted' }, `${s.evidence_count} Beweise gesichert`), h('p', { class: 'muted small' }, 'Wähle auf dem Smartphone, was hier gezeigt werden soll.'));
  }
  mount(root, head, body);
}

async function loop() {
  try {
    if (!token) waiting(await pair());
    const r = await api(`/api/tv/poll?token=${encodeURIComponent(token)}&v=${encodeURIComponent(ver)}`);
    if (!r.linked) { if (ver !== 'w:' + r.code) { ver = 'w:' + r.code; waiting(r.code); } }
    else if (r.changed) { ver = r.v; draw(r.screen); }
  } catch (e) {
    if (e.status === 404) { token = null; ver = ''; try { sessionStorage.removeItem('cc_tv'); } catch { /* */ } }
  }
  timer = setTimeout(loop, 2000);
}
loop();
