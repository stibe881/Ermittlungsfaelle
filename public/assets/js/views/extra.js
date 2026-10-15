import { h } from '../dom.js';
import { post } from '../api.js';
import { toast, modal, confirmBox, fmtTime, CATEGORY } from '../ui.js';

// ---------------------------------------------------------------- Hinweise
export function hintModal(S, act) {
  const body = h('div', {});
  const draw = () => {
    body.replaceChildren(
      h('p', { class: 'muted' }, 'Hinweise sind optional. Jeder neue Hinweis kostet 5 Minuten Strafzeit in der Rangliste und wird in deiner Statistik gezählt. Wiederholen kostet nichts.'),
      ...[['Stufe 1 · Denkanstoss', 1], ['Stufe 2 · Ermittlungsbereich', 2], ['Stufe 3 · Konkreter Hinweis', 3], ['Stufe 4 · Starke Unterstützung', 4]].map(([label, lv]) =>
        h('button', { class: 'opt', onclick: async () => { const r = await act('hint', { level: lv }); if (r) { m.close(); showHint(lv, r.text); } } }, label)),
      S.hints.length > 0 && h('div', { style: { marginTop: '14px' } }, h('strong', {}, 'Bisherige Hinweise'), S.hints.map(x => h('div', { class: 'card', style: { marginTop: '8px' } }, h('span', { class: 'chip' }, 'Stufe ' + x.level), h('p', {}, x.text)))));
  };
  const m = modal('Hinweise (' + S.hints_used + ' verwendet)', body); draw();
}
function showHint(level, text) { modal('Hinweis · Stufe ' + level, h('div', { class: 'doc' }, h('p', {}, text))); }

// ---------------------------------------------------------------- TV
export function tvModal(sid, onLinked) {
  const code = h('input', { placeholder: 'Code vom Fernseher', maxlength: 6, style: { textTransform: 'uppercase', fontSize: '1.4rem', letterSpacing: '.3em', textAlign: 'center' }, 'aria-label': 'TV-Code' });
  const m = modal('Fernseher verbinden', h('form', { onsubmit: async e => {
    e.preventDefault();
    try { await post('/api/tv/link', { code: code.value, session_id: sid }); toast('Fernseher verbunden', 'ok'); m.close(); onLinked(); } catch (err) { toast(err.message, 'error'); }
  } },
    h('p', {}, 'Öffne ', h('strong', {}, location.origin + '/tv.html'), ' im Browser deines Fernsehers (oder per Chromecast „Tab streamen“) und gib den angezeigten 6-stelligen Code ein.'),
    code, h('p', { class: 'small muted tvnote' }, 'Auf dem Fernseher erscheinen nur freigegebene Inhalte (Beweise, Verdächtige, Pinnwand, Zeitachse) – nie Notizen oder die Lösung. Wähle im Spiel bei Beweisen, Tatorten und Verdächtigen „📺 Auf TV“.'),
    h('div', { class: 'row end' }, h('button', { class: 'btn primary' }, 'Verbinden'))));
  code.focus();
}

// ---------------------------------------------------------------- Anklage
const draft = { culprit: '', motive: '', method: '', evidence: new Set(), timeline: [] };

export function accuseView(S, act) {
  const A = S.accusation;
  if (!A.unlocked) return h('div', { class: 'card' }, h('h2', {}, 'Anklage'), h('p', {}, `Die Staatsanwaltschaft nimmt eine Anklage erst auf, wenn die Beweislage ausreicht: Sichere mindestens ${A.need_evidence} Beweise (aktuell ${S.evidence.length}).`));
  const remaining = S.max_false_accusations - S.false_accusations;
  const radio = (name, opts, key) => opts.map(o => h('label', { class: 'pick' + (draft[key] === o.id ? ' on' : '') }, h('input', { type: 'radio', name, checked: draft[key] === o.id, onchange: () => { draft[key] = o.id; rerender(); } }), h('span', {}, o.label)));
  let box;
  const rerender = () => { const n = build(); box.replaceWith(n); box = n; };
  const build = () => h('div', {},
    h('h2', {}, 'Täteranklage'),
    h('p', { class: 'muted' }, `Die Anklage ist verbindlich. Eine falsche Person kostet 10 Min. Strafzeit; nach ${S.max_false_accusations} Fehlanklagen wird der Fall eingestellt (noch ${remaining} Versuch(e)).`),
    h('h3', {}, '1 · Täter'), radio('culprit', S.suspects.map(s => ({ id: s.id, label: `${s.name} – ${s.job}` })), 'culprit'),
    h('h3', {}, '2 · Motiv'), radio('motive', A.motives, 'motive'),
    h('h3', {}, '3 · Tatmethode'), radio('method', A.methods, 'method'),
    h('h3', {}, '4 · Entscheidende Beweise'), h('p', { class: 'small muted' }, 'Wähle die Beweise, die deine Anklage tragen – nicht alle. Unpassende Beweise kosten Punkte.'),
    S.evidence.map(e => h('label', { class: 'pick' + (draft.evidence.has(e.id) ? ' on' : '') }, h('input', { type: 'checkbox', checked: draft.evidence.has(e.id), onchange: ev => { ev.target.checked ? draft.evidence.add(e.id) : draft.evidence.delete(e.id); rerender(); } }), h('span', {}, h('strong', {}, e.title), h('div', { class: 'small muted' }, CATEGORY[e.category])))),
    h('h3', {}, '5 · Tathergang rekonstruieren'), h('p', { class: 'small muted' }, 'Tippe die Ereignisse der Tatnacht in der Reihenfolge an, in der sie zur Tat führten (nur Ereignisse, die du für relevant hältst).'),
    S.timeline.map(t => { const idx = draft.timeline.indexOf(t.id); return h('button', { type: 'button', class: 'pick' + (idx >= 0 ? ' on' : ''), style: { width: '100%', textAlign: 'left' }, onclick: () => { idx >= 0 ? draft.timeline.splice(idx, 1) : draft.timeline.push(t.id); rerender(); } }, h('span', { class: 'chip gold' }, idx >= 0 ? '#' + (idx + 1) : '–'), h('span', {}, h('strong', {}, (t.day ? '+1 ' : '') + t.time + ' · ' + t.title))); }),
    h('div', { class: 'row end' }, h('button', { class: 'btn primary', disabled: !draft.culprit, onclick: submit }, 'Anklage erheben')));
  async function submit() {
    if (!await confirmBox('Anklage verbindlich erheben?', `Du klagst ${S.suspects.find(s => s.id === draft.culprit).name} an. Diese Entscheidung ist endgültig.`, 'Anklage erheben')) return;
    const r = await act('accuse', { culprit: draft.culprit, motive: draft.motive, method: draft.method, evidence: [...draft.evidence], timeline: draft.timeline });
    if (!r) return;
    if (!r.correct) modal(r.closed ? 'Verfahren eingestellt' : 'Anklage abgelehnt', h('div', {}, h('p', {}, r.message), !r.closed && h('p', { class: 'muted' }, `Noch ${r.remaining} Versuch(e). Prüfe deine Beweiskette.`)));
    else toast('Fall gelöst!', 'ok');
  }
  box = build();
  return box;
}

// ---------------------------------------------------------------- Auflösung
export function resolutionView(S) {
  const R = S.resolution, t = R.text, res = R.result;
  const suspect = S.suspects.find(s => s.id === R.culprit);
  return h('div', { class: 'resolution' },
    h('h2', {}, R.solved ? 'Fall gelöst' : 'Fall ungelöst'),
    R.solved && res && h('div', { class: 'cols2' },
      h('div', { class: 'card' }, h('div', { class: 'muted small' }, 'Bewertung'), h('div', { class: 'stat' }, R.score_points + ' / 100'),
        h('ul', {}, h('li', {}, 'Täter: ✓'), h('li', {}, 'Motiv: ' + (res.motive ? '✓ +20' : '✗')), h('li', {}, 'Tatmethode: ' + (res.method ? '✓ +20' : '✗')),
          h('li', {}, `Beweiskette: ${res.chain}/40 (${res.chain_hits} von mind. ${res.chain_needed} Schlüsselbeweisen)`), h('li', {}, `Rekonstruktion: ${res.timeline}/20`))),
      h('div', { class: 'card' }, h('div', { class: 'muted small' }, 'Statistik'), h('p', {}, `Aktive Zeit: ${fmtTime(S.active_seconds)}`), h('p', {}, `Hinweise: ${S.hints_used} · Fehlanklagen: ${S.false_accusations}`), h('p', {}, S.ranked ? 'Gewerteter Erstversuch – Platzierung siehe Rangliste.' : 'Übungsmodus – nicht gewertet.'),
        h('a', { class: 'btn', href: '#/leaderboard/' + S.slug }, '🏆 Rangliste'))),
    h('div', { class: 'doc', style: { marginTop: '14px' } }, h('h4', {}, 'Täter: ' + (suspect ? suspect.name : '–')), h('p', {}, t.summary)),
    h('h3', {}, 'Rekonstruktion'), h('ol', {}, t.reconstruction.map(x => h('li', {}, x))),
    h('h3', {}, 'Entscheidende Hinweise'), h('ul', {}, t.key_clues.map(x => h('li', {}, x))),
    h('h3', {}, 'Falsche Fährten'), h('ul', {}, t.false_leads.map(x => h('li', {}, x))));
}
