import { h, mount } from '../dom.js';
import { get, post, uuid } from '../api.js';
import { toast, fmtTime, modal, media, CATEGORY, player, chatBubbles, stopSpeech, canSpeak } from '../ui.js';
import { boardView } from './board.js';
import { accuseView, resolutionView, hintModal, tvModal } from './extra.js';

let S = null, sid = 0, tab = 'scene', sceneId = null, suspectId = null, root = null, main = null, head = null;
let timers = [], busy = false, dragging = false, pendingRender = false, fetchedAt = 0, zoom = false, tvLinked = false;
const compareSel = new Set();
const seenEvidence = new Set();
const TABS = [['scene', 'Tatort'], ['evidence', 'Beweise'], ['suspects', 'Verhöre'], ['phone', 'Handy'], ['timeline', 'Zeitachse'], ['board', 'Pinnwand'], ['notes', 'Notizen'], ['actions', 'Aktionen'], ['accuse', 'Anklage']];

export function stopPlay() { stopSpeech(); timers.forEach(clearInterval); timers = []; S = null; root = null; }
export const setDragging = v => { dragging = v; if (!v && pendingRender) render(); };

export async function act(type, extra = {}) {
  try {
    const r = await post(`/api/sessions/${sid}/act`, { type, client_action_id: uuid(), ...extra });
    applyState(r.state);
    return r.result;
  } catch (e) {
    if (e.code === 'version_conflict') { toast('Ein Teammitglied hat dasselbe geändert – Ansicht aktualisiert.', 'error'); await refresh(); }
    else toast(e.message, 'error');
    if (e.code === 'session_paused') await refresh();
    return null;
  }
}

async function refresh() { const r = await get(`/api/sessions/${sid}`); if (r.changed) applyState(r.state); }

function applyState(st) {
  const first = !S;
  S = st; fetchedAt = Date.now();
  for (const e of st.evidence) { if (first) seenEvidence.add(e.id); }
  if (!sceneId || !st.scenes.find(s => s.id === sceneId)) sceneId = st.scenes[0]?.id;
  if (!suspectId) suspectId = st.suspects[0]?.id;
  if (['solved', 'failed'].includes(st.status) && tab !== 'resolution' && first) tab = 'resolution';
  render();
}

export async function playView(id) {
  sid = id; S = null; tab = 'scene'; seenEvidence.clear(); compareSel.clear(); tvLinked = false;
  const r = await get(`/api/sessions/${id}`);
  root = h('div', { class: 'hub' });
  head = h('div', { class: 'hubbar' }); main = h('main', { class: 'hubmain', id: 'hubmain' });
  root.append(head, main);
  applyState(r.state);
  timers.push(setInterval(async () => {
    if (document.hidden || busy || !S) return;
    try { const p = await get(`/api/sessions/${sid}?since=${S.version}&status=${S.status}`); if (p.changed) { applyState(p.state); } } catch { /* offline: nächster Versuch */ }
  }, 3000));
  timers.push(setInterval(() => { if (!document.hidden && S && S.status === 'active') act('heartbeat'); }, 30000));
  timers.push(setInterval(tick, 1000));
  return root;
}

function tick() {
  const el = document.getElementById('timer');
  if (el && S) el.textContent = fmtTime(S.active_seconds + (S.status === 'active' ? Math.min((Date.now() - fetchedAt) / 1000, 90) : 0));
}

function render() {
  if (!root || !S) return;
  const a = document.activeElement;
  if (dragging || (a && ['INPUT', 'TEXTAREA', 'SELECT'].includes(a.tagName) && main.contains(a))) { pendingRender = true; if (!dragging) a.addEventListener('blur', () => render(), { once: true }); return; }
  pendingRender = false;
  const closed = ['solved', 'failed'].includes(S.status);
  const baseTabs = TABS.filter(t => t[0] !== 'phone' || S.phone);
  const tabs = closed ? [['resolution', 'Auflösung'], ...baseTabs.slice(0, -1)] : baseTabs;
  if (!tabs.find(t => t[0] === tab)) tab = tabs[0][0];
  const newCount = S.evidence.filter(e => !seenEvidence.has(e.id)).length;
  mount(head,
    h('div', { class: 'hubhead' },
      h('a', { class: 'icon-btn', href: '#/', 'aria-label': 'Zur Übersicht' }, '←'),
      h('div', { class: 'grow' }, h('strong', {}, `Fall ${S.meta.case_number} · ${S.meta.title}`), h('div', { class: 'small muted' }, S.mode === 'team' ? `Team · Code ${S.invite_code} · ${S.members.length} Mitglieder` : (S.ranked ? 'Solo · gewertet' : 'Solo · Übungsmodus'))),
      h('span', { class: 'timer', id: 'timer', title: 'Aktive Ermittlungszeit' }, fmtTime(S.active_seconds)),
      !closed && h('button', { class: 'btn small', onclick: () => hintModal(S, act) }, '💡'),
      h('button', { class: 'btn small', 'aria-label': 'Fernseher verbinden', onclick: () => tvModal(sid, () => { tvLinked = true; render(); }) }, '📺'),
      !closed && h('button', { class: 'btn small', onclick: () => act('pause') }, '⏸')),
    h('div', { class: 'tabs', role: 'tablist' }, tabs.map(([k, label]) => h('button', { class: 'tab', role: 'tab', 'aria-selected': tab === k, onclick: () => { tab = k; if (k === 'evidence') S.evidence.forEach(e => seenEvidence.add(e.id)); render(); } },
      label, k === 'evidence' && newCount > 0 && h('span', { class: 'badge' }, newCount)))));
  const view = { scene: sceneTab, evidence: evidenceTab, suspects: suspectsTab, phone: phoneTab, timeline: timelineTab, board: () => boardView(S, act, { showEvidence, tvShow }), notes: notesTab, actions: actionsTab, accuse: () => accuseView(S, act), resolution: () => resolutionView(S) }[tab];
  mount(main, S.status === 'paused' ? pausedOverlay() : null, view());
}

const pausedOverlay = () => h('div', { class: 'paused-overlay' }, h('h2', {}, 'Ermittlung pausiert'), h('p', { class: 'muted' }, 'Die Uhr steht. Während der Pause sind keine Aktionen möglich.'), h('button', { class: 'btn primary', onclick: () => act('resume') }, 'Fortsetzen'));

async function tvShow(type, id) {
  try { await post(`/api/sessions/${sid}/tv`, { view: { type, id } }); toast('Auf dem Fernseher angezeigt.', 'ok'); } catch (e) { toast(e.message, 'error'); }
}

// ---------------------------------------------------------------- Tatort
function sceneTab() {
  const sc = S.scenes.find(s => s.id === sceneId) || S.scenes[0];
  const view = h('div', { class: 'scene' + (zoom ? ' zoom' : ''), style: zoom ? { width: '230%', aspectRatio: '16/9', height: 'auto' } : {} },
    media(sc.image, sc.title), sc.hotspots.map(hs => h('button', {
      class: 'hs' + (hs.inspected ? ' done' : ''), style: { left: hs.x + '%', top: hs.y + '%', width: hs.w + '%', height: hs.h + '%' }, 'aria-label': hs.label,
      onclick: () => inspect(hs) }, h('span', {}, hs.label))));
  return h('div', {},
    h('div', { class: 'chips' }, S.scenes.map(s => h('button', { class: 'chipbtn', 'aria-pressed': s.id === sc.id, onclick: () => { sceneId = s.id; render(); } }, s.title))),
    h('div', { class: 'row' }, h('p', { class: 'muted grow' }, sc.description, sc.time_of_day ? ` (${sc.time_of_day})` : ''),
      h('button', { class: 'btn small', onclick: () => { zoom = !zoom; render(); } }, zoom ? 'Verkleinern' : '🔍 Vergrössern'),
      h('button', { class: 'btn small', onclick: () => tvShow('scene', sc.id) }, '📺')),
    h('div', { style: zoom ? { overflow: 'auto', maxHeight: '72vh', border: '1px solid var(--line)', borderRadius: '10px' } : {} }, view),
    h('p', { class: 'small muted' }, 'Tippe auf die markierten Bereiche, um Objekte zu untersuchen. Pulsierend = noch nicht untersucht.'));
}

async function inspect(hs) {
  const had = new Set(S.evidence.map(e => e.id));
  const r = await act('inspect', { hotspot: hs.id });
  if (!r) return;
  const fresh = r.new_evidence.map(id => S.evidence.find(e => e.id === id)).filter(Boolean);
  modal(r.title, h('div', {}, h('div', { class: 'doc' }, h('p', {}, r.text)),
    fresh.map(e => h('div', { class: 'card', style: { marginTop: '10px', borderColor: 'var(--accent)' } }, h('strong', {}, '➕ Neuer Beweis: '), e.title, h('div', { class: 'row end' }, h('button', { class: 'btn small', onclick: () => showEvidence(e.id) }, 'Ansehen'))))));
  if (fresh.length) toast(`${fresh.length} Beweis(e) gesichert`, 'ok');
}

// ---------------------------------------------------------------- Beweise
export function showEvidence(id) {
  const e = S.evidence.find(x => x.id === id); if (!e) return;
  seenEvidence.add(id);
  const onBoard = S.board.items.some(i => i.kind === 'evidence' && i.ref === id);
  modal(e.title, h('div', {},
    e.media && media(e.media, e.title),
    h('div', { class: 'doc' }, h('h4', {}, `${CATEGORY[e.category] || e.category} · ${e.id.replace('ev_', '').toUpperCase()}`),
      h('div', { class: 'meta' }, `Fundort: ${e.location}${e.time ? ' · ' + e.time : ''}`), h('p', {}, e.description)),
    e.transcript && (e.category === 'chat' ? chatBubbles(e.transcript.map(m => ({ from: m.speaker === 'Konrad Imhof' ? 'me' : m.speaker, text: m.text, time: m.t })), 'me') : player(e.transcript, S.voices, { audio: e.audio, video: e.video, title: e.title })),
    e.links.length > 0 && h('div', { class: 'chips' }, h('span', { class: 'muted small' }, 'Verknüpft: '), e.links.map(l => { const x = S.evidence.find(y => y.id === l); return h('button', { class: 'chipbtn', onclick: () => showEvidence(l) }, x.title); })),
    h('div', { class: 'row end' },
      h('button', { class: 'btn small', onclick: () => tvShow('evidence', id) }, '📺 Auf TV'),
      h('button', { class: 'btn small', disabled: onBoard, onclick: async () => { await act('board_add', { kind: 'evidence', ref: id, x: 40 + Math.random() * 400, y: 40 + Math.random() * 300 }); toast('An die Pinnwand geheftet', 'ok'); } }, onBoard ? 'Auf der Pinnwand' : '📌 An Pinnwand'))), { wide: true });
}

function evidenceTab() {
  const cats = [...new Set(S.evidence.map(e => e.category))];
  const filter = evidenceTab.filter || '';
  const list = S.evidence.filter(e => !filter || e.category === filter);
  return h('div', {},
    h('div', { class: 'row' }, h('h2', { class: 'grow' }, `Beweismittel (${S.evidence.length})`),
      compareSel.size === 2 && h('button', { class: 'btn primary small', onclick: compare }, 'Vergleichen'),
      compareSel.size > 0 && h('button', { class: 'btn ghost small', onclick: () => { compareSel.clear(); render(); } }, 'Auswahl aufheben')),
    h('div', { class: 'chips' }, h('button', { class: 'chipbtn', 'aria-pressed': !filter, onclick: () => { evidenceTab.filter = ''; render(); } }, 'Alle'),
      cats.map(c => h('button', { class: 'chipbtn', 'aria-pressed': filter === c, onclick: () => { evidenceTab.filter = c; render(); } }, CATEGORY[c] || c))),
    h('div', { class: 'ev-grid' }, list.map(e => h('div', { class: 'ev' + (compareSel.has(e.id) ? ' sel' : ''), style: { padding: 0 } },
      h('button', { class: 'ev', style: { border: 0, width: '100%' }, onclick: () => showEvidence(e.id) },
        h('span', { class: 'chip' }, CATEGORY[e.category] || e.category), h('strong', {}, e.title), h('span', { class: 'small muted' }, e.location + (e.time ? ' · ' + e.time : ''))),
      h('label', { class: 'row small', style: { padding: '0 12px 10px', margin: 0 } }, h('input', { type: 'checkbox', style: { width: 'auto', minHeight: 0 }, checked: compareSel.has(e.id), onchange: ev => { ev.target.checked ? (compareSel.size < 2 && compareSel.add(e.id)) : compareSel.delete(e.id); render(); } }), 'vergleichen')))));
}

function compare() {
  const [a, b] = [...compareSel].map(id => S.evidence.find(e => e.id === id));
  const col = e => h('div', {}, e.media && media(e.media, e.title), h('div', { class: 'doc' }, h('h4', {}, e.title), h('div', { class: 'meta' }, `${e.location}${e.time ? ' · ' + e.time : ''}`), h('p', {}, e.description)));
  modal('Beweisvergleich', h('div', { class: 'cols2' }, col(a), col(b)), { wide: true });
}

// ---------------------------------------------------------------- Verhöre
function initials(n) { return n.replace(/Dr\.\s*/, '').split(' ').map(x => x[0]).join('').slice(0, 2); }

function suspectsTab() {
  const su = S.suspects.find(s => s.id === suspectId) || S.suspects[0];
  const chat = h('div', { class: 'chat', id: 'chat' }, su.history.length ? su.history.flatMap(x => [h('div', { class: 'bubble q' }, x.question), h('div', { class: 'bubble a' }, x.answer, canSpeak() && h('button', { class: 'btn small ghost listen', 'aria-label': 'Antwort anhören', onclick: () => listen(su, x.answer) }, '🔊'))]) : h('p', { class: 'muted' }, 'Noch keine Befragung.'));
  setTimeout(() => { const c = document.getElementById('chat'); if (c) c.scrollTop = c.scrollHeight; });
  return h('div', {},
    h('div', { class: 'sus-list' }, S.suspects.map(s => h('button', { class: 'sus', 'aria-pressed': s.id === su.id, onclick: () => { suspectId = s.id; render(); } },
      h('div', { class: 'avatar' }, s.portrait ? media(s.portrait, s.name) : null, h('span', {}, initials(s.name))), h('strong', {}, s.name), h('div', { class: 'small muted' }, s.job.split(' ').slice(0, 3).join(' '))))),
    h('div', { class: 'split', style: { marginTop: '12px' } },
      h('div', { class: 'card' }, h('h3', {}, su.name), h('div', { class: 'small muted' }, `${su.age} Jahre · ${su.job}`), h('p', {}, h('strong', {}, 'Beziehung: '), su.relation), h('p', {}, su.bio),
        su.mood && h('p', { class: 'small' }, h('em', {}, 'Auftreten: ' + su.mood)), h('button', { class: 'btn small', onclick: () => tvShow('suspect', su.id) }, '📺 Auf TV'),
        h('button', { class: 'btn small', style: { marginLeft: '8px' }, onclick: () => act('board_add', { kind: 'suspect', ref: su.id, x: 40 + Math.random() * 300, y: 40 + Math.random() * 300 }).then(() => toast('An Pinnwand geheftet', 'ok')) }, '📌 Pinnwand')),
      h('div', { class: 'card' }, h('h3', {}, 'Verhör'), chat,
        su.options.length ? h('div', { class: 'opts' }, h('div', { class: 'small muted' }, 'Frage auswählen:'), su.options.map(o => h('button', { class: 'opt' + (o.confront ? ' confront' : ''), onclick: () => ask(su, o) }, (o.confront ? '📎 ' : '') + o.question)))
          : h('p', { class: 'muted small' }, 'Aktuell keine weiteren Fragen. Neue Beweise eröffnen neue Fragen.'))));
}
async function ask(su, o) {
  const r = await act('ask', { suspect: su.id, option: o.id });
  if (r && r.new_evidence?.length) toast('Neue Erkenntnis gesichert: ' + r.new_evidence.map(id => S.evidence.find(e => e.id === id)?.title).join(', '), 'ok');
}

// ---------------------------------------------------------------- Zeitachse
const mins = t => Number(t.time.slice(0, 2)) * 60 + Number(t.time.slice(3)) + (t.day || 0) * 1440;
function timelineTab() {
  const all = [...S.timeline.map(t => ({ ...t, m: mins(t) })), ...S.custom_timeline.map(t => ({ ...t, m: mins(t), id: 'c' + t.id }))].sort((a, b) => a.m - b.m);
  const out = [];
  all.forEach((t, i) => {
    const prev = all[i - 1];
    if (prev && t.m - prev.m >= 20 && t.m - prev.m < 600) out.push(h('div', { class: 'tl-gap' }, `⚠ Lücke: ${t.m - prev.m} Minuten ohne bekannte Ereignisse`));
    out.push(h('div', { class: 'tl-item' + (t.flagged ? ' flag' : '') + (t.custom ? ' custom' : '') },
      h('div', { class: 'row' }, h('span', { class: 'tl-time' }, (t.day ? '+1 ' : '') + t.time), t.uncertainty ? h('span', { class: 'chip' }, `± ${t.uncertainty} Min.`) : null, h('strong', { class: 'grow' }, t.title)),
      t.text && h('div', { class: 'small' }, t.text), t.note && h('div', { class: 'small' }, t.note),
      h('div', { class: 'row small muted' }, t.source && ('Quelle: ' + t.source),
        t.custom ? h('button', { class: 'btn small ghost', onclick: () => act('timeline_delete', { id: Number(t.id.slice(1)) }) }, 'Entfernen')
                 : h('button', { class: 'btn small ghost', onclick: () => act('timeline_flag', { ref: t.id }) }, t.flagged ? '⚑ Markierung entfernen' : '⚐ Als Widerspruch markieren'))));
  });
  const time = h('input', { type: 'time', required: true, 'aria-label': 'Uhrzeit' }), title = h('input', { placeholder: 'Eigenes Ereignis / Hypothese', required: true, maxlength: 200 });
  return h('div', {}, h('h2', {}, 'Chronologie'), h('p', { class: 'muted small' }, 'Ereignisse erscheinen, sobald du sie belegen kannst. Unsichere Zeiten sind mit ± gekennzeichnet; markiere Widersprüche und prüfe Lücken.'),
    h('div', { class: 'tl' }, out.length ? out : h('p', { class: 'muted' }, 'Noch keine Ereignisse.')),
    h('form', { class: 'card', onsubmit: async e => { e.preventDefault(); await act('timeline_add', { time: time.value, title: title.value }); } },
      h('strong', {}, 'Eigenes Ereignis hinzufügen'), h('div', { class: 'row' }, time, h('div', { class: 'grow' }, title), h('button', { class: 'btn' }, 'Hinzufügen'))));
}

// ---------------------------------------------------------------- Notizen
function notesTab() {
  const ta = h('textarea', { placeholder: 'Notiz, Theorie, offene Frage …', maxlength: 4000, 'aria-label': 'Neue Notiz' });
  return h('div', {}, h('h2', {}, 'Ermittlungsnotizen'),
    h('form', { class: 'card', onsubmit: async e => { e.preventDefault(); if (!ta.value.trim()) return; const v = ta.value; ta.value = ''; await act('note_add', { body: v }); } }, ta, h('div', { class: 'row end' }, h('button', { class: 'btn primary' }, 'Speichern'))),
    S.notes.slice().reverse().map(n => h('div', { class: 'card', style: { marginTop: '10px' } },
      h('div', { class: 'small muted' }, n.author + (S.mode === 'team' ? '' : '') + ' · ' + n.updated_at.slice(0, 16)), h('p', { style: { whiteSpace: 'pre-wrap' } }, n.body),
      n.mine && h('div', { class: 'row' },
        h('button', { class: 'btn small', onclick: () => editNote(n) }, 'Bearbeiten'), h('button', { class: 'btn small ghost', onclick: () => act('note_delete', { id: n.id }) }, 'Löschen')))));
}
function editNote(n) {
  const ta = h('textarea', { maxlength: 4000 }, n.body); ta.value = n.body;
  const m = modal('Notiz bearbeiten', h('form', { onsubmit: async e => { e.preventDefault(); m.close(); await act('note_update', { id: n.id, body: ta.value, base_version: n.version }); } }, ta, h('div', { class: 'row end' }, h('button', { class: 'btn primary' }, 'Speichern'))));
}

// ---------------------------------------------------------------- Aktionen
function actionsTab() {
  return h('div', {}, h('h2', {}, 'Ermittlungsaktionen'), h('p', { class: 'muted small' }, 'Aufträge an Labor, Provider und Behörden. Sie sind verfügbar, sobald die Voraussetzungen erfüllt sind – es gibt keine künstlichen Wartezeiten.'),
    S.actions.map(a => h('div', { class: 'card', style: { marginBottom: '10px' } }, h('strong', {}, a.title), h('p', { class: 'muted small' }, a.description),
      a.done ? h('div', { class: 'doc' }, h('p', {}, a.result)) : h('button', { class: 'btn primary', onclick: async () => {
        const r = await act('run_action', { action: a.id }); if (r?.new_evidence?.length) toast('Ergebnis liegt vor – neuer Beweis gesichert', 'ok');
      } }, 'Beauftragen'))));
}

function listen(su, text) {
  const m = modal('Verhör-Aufnahme · ' + su.name, player([{ speaker: su.name, text }], S.voices, { title: 'Verhör ' + su.name }));
}

// ---------------------------------------------------------------- Handy
let phonePin = '', phoneErr = '', phoneSub = 'chats';
function phoneTab() {
  const ph = S.phone;
  const frame = (...k) => h('div', { class: 'phone' }, h('div', { class: 'status' }, h('span', {}, ph.owner), h('span', {}, '●●●●')), ...k);
  if (!ph.unlocked) {
    const dots = h('div', { class: 'pin-dots' + (phoneErr ? ' shake' : '') }, [0, 1, 2, 3].map(i => h('i', { class: i < phonePin.length ? 'on' : '' })));
    const press = async d => {
      if (phonePin.length >= 4) return; phonePin += d;
      if (phonePin.length === 4) {
        const r = await act('phone_unlock', { pin: phonePin }); phonePin = '';
        if (r && !r.ok) { phoneErr = r.hint; toast('Falsche PIN', 'error'); render(); phoneErr = ''; } else if (r) toast('Handy entsperrt', 'ok');
        return;
      }
      render();
    };
    return frame(h('h3', { class: 'center' }, 'Gesperrt'), h('p', { class: 'center muted small' }, 'PIN eingeben. Tipp: ', ph.hint), dots,
      h('div', { class: 'keypad' }, ['1', '2', '3', '4', '5', '6', '7', '8', '9', '', '0', '⌫'].map(k => k === '' ? h('span') : h('button', { 'aria-label': k === '⌫' ? 'Löschen' : k, onclick: () => k === '⌫' ? (phonePin = phonePin.slice(0, -1), render()) : press(k) }, k))));
  }
  const openChat = async c => { if (!c.open) await act('phone_open', { item: c.id }); const f = S.phone.chats.find(x => x.id === c.id); modal('Chat · ' + f.contact, chatBubbles(f.messages)); };
  const openCall = async c => {
    if (!c.played) await act('phone_open', { item: c.id });
    const f = S.phone.calls.find(x => x.id === c.id);
    modal((f.kind === 'voicemail' ? 'Mailbox · ' : 'Anruf · ') + f.contact, h('div', {}, h('p', { class: 'muted small' }, `${f.direction} · ${f.time} · ${Math.floor(f.duration / 60)}:${String(f.duration % 60).padStart(2, '0')} Min.`), player(f.transcript, S.voices, { title: f.contact })), { wide: true });
  };
  const list = phoneSub === 'chats'
    ? S.phone.chats.map(c => h('button', { onclick: () => openChat(c) }, h('span', { class: 'dot' + (c.open ? ' read' : '') }), h('div', { class: 'grow' }, h('strong', {}, c.contact), h('div', { class: 'small muted' }, c.open ? c.preview : 'Ungelesen – antippen')), h('span', { class: 'small muted' }, c.time)))
    : S.phone.calls.map(c => h('button', { onclick: () => openCall(c) }, h('span', { class: 'dot' + (c.played ? ' read' : '') }), h('div', { class: 'grow' }, h('strong', {}, (c.kind === 'voicemail' ? '📼 ' : '📞 ') + c.contact), h('div', { class: 'small muted' }, `${c.direction} · ${c.kind === 'voicemail' ? 'Mailbox' : 'Gespräch'} · ${c.duration} s`)), h('span', { class: 'small muted' }, c.time)));
  return frame(h('div', { class: 'chips' }, h('button', { class: 'chipbtn', 'aria-pressed': phoneSub === 'chats', onclick: () => { phoneSub = 'chats'; render(); } }, '💬 Nachrichten'), h('button', { class: 'chipbtn', 'aria-pressed': phoneSub === 'calls', onclick: () => { phoneSub = 'calls'; render(); } }, '📞 Anrufe & Mailbox')),
    h('div', { class: 'plist' }, list));
}
