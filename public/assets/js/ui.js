import { h, $, append } from './dom.js';

export function toast(msg, kind = 'info') {
  const t = h('div', { class: 'toast ' + kind, role: 'status' }, msg);
  $('#toasts').append(t);
  setTimeout(() => t.classList.add('out'), 3800);
  setTimeout(() => t.remove(), 4300);
}

export function fmtTime(sec) {
  sec = Math.max(0, Math.floor(sec));
  const hh = Math.floor(sec / 3600), mm = Math.floor(sec % 3600 / 60), ss = sec % 60;
  return (hh ? hh + ':' + String(mm).padStart(2, '0') : mm) + ':' + String(ss).padStart(2, '0');
}

export function modal(title, content, { wide = false, onclose } = {}) {
  const prev = document.activeElement;
  const close = () => { back.remove(); document.removeEventListener('keydown', esc); onclose && onclose(); prev && prev.focus && prev.focus(); };
  const esc = e => { if (e.key === 'Escape') close(); };
  const body = h('div', { class: 'modal-body' });
  append(body, [content]);
  const box = h('div', { class: 'modal' + (wide ? ' wide' : ''), role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
    h('div', { class: 'modal-head' }, h('h3', {}, title), h('button', { class: 'icon-btn', 'aria-label': 'Schliessen', onclick: close }, '✕')), body);
  const back = h('div', { class: 'modal-back', onclick: e => { if (e.target === back) close(); } }, box);
  document.body.append(back);
  document.addEventListener('keydown', esc);
  box.querySelector('button')?.focus();
  return { close, body };
}

export function confirmBox(title, text, okLabel = 'Bestätigen') {
  return new Promise(res => {
    const done = v => { m.close(); res(v); };
    const m = modal(title, h('div', {}, h('p', {}, text), h('div', { class: 'row end' },
      h('button', { class: 'btn ghost', onclick: () => done(false) }, 'Abbrechen'),
      h('button', { class: 'btn primary', onclick: () => done(true) }, okLabel))), { onclose: () => res(false) });
  });
}

export function promptBox(title, label, value = '') {
  return new Promise(res => {
    const input = h('input', { type: 'text', maxlength: 120, value, 'aria-label': label });
    const done = v => { m.close(); res(v); };
    const m = modal(title, h('form', { onsubmit: e => { e.preventDefault(); done(input.value.trim()); } },
      h('label', {}, label, input), h('div', { class: 'row end' },
        h('button', { type: 'button', class: 'btn ghost', onclick: () => done(null) }, 'Abbrechen'),
        h('button', { class: 'btn primary' }, 'OK'))), { onclose: () => res(null) });
    input.focus();
  });
}

/** Bild mit gekennzeichnetem Platzhalter, falls die Datei (noch) nicht existiert. */
export function media(src, alt, cls = '') {
  const wrap = h('div', { class: 'media ' + cls });
  const placeholder = () => { wrap.classList.add('missing'); wrap.replaceChildren(h('div', { class: 'ph' }, h('strong', {}, 'PLATZHALTER'), h('span', {}, 'Bild fehlt: ' + (src || 'nicht definiert')), h('small', {}, alt || ''))); };
  if (!src) { placeholder(); return wrap; }
  const img = h('img', { src: '/assets/media/' + src, alt: alt || '', loading: 'lazy', decoding: 'async' });
  img.addEventListener('error', placeholder);
  wrap.append(img);
  return wrap;
}

export const CATEGORY = {
  foto: 'Foto', fingerabdruck: 'Fingerabdruck', dna: 'DNA / Labor', blut: 'Blutspur', autopsie: 'Autopsie', telefon: 'Telefon', chat: 'Chat', email: 'E-Mail',
  bank: 'Bank', video: 'Video', fahrzeug: 'Fahrzeug', aussage: 'Aussage', brief: 'Brief', quittung: 'Quittung', vertrag: 'Vertrag', tagebuch: 'Tagebuch',
  audio: 'Audio', gps: 'GPS', digital: 'Digital', dokument: 'Dokument', objekt: 'Objekt', protokoll: 'Protokoll',
};

// ---------------------------------------------------------------- Audio/Video-Wiedergabe (Deutsch)
// Echte Aufnahmen (audio/video-Datei) werden bevorzugt. Fehlt die Datei, liest die Sprachausgabe des Geräts
// (deutsche Stimme) das Transkript vor – synthetisch, als gekennzeichneter Ersatz. Nie automatisch; Start nur per Tippen.
function germanVoices() {
  const vs = (window.speechSynthesis && speechSynthesis.getVoices()) || [];
  const de = vs.filter(v => /^de/i.test(v.lang));
  return de.sort((a, b) => (/de[-_]CH/i.test(b.lang) ? 1 : 0) - (/de[-_]CH/i.test(a.lang) ? 1 : 0));
}
function pickVoice(speaker, cfg) {
  const de = germanVoices(); if (!de.length) return null;
  const female = /^f/i.test(cfg?.gender || '');
  const nameHint = de.filter(v => female ? /(anna|petra|female|weiblich|marlene|vicki|katja|hedda|amala|elke|seraphina)/i.test(v.name) : /(markus|stefan|male|männlich|conrad|killian|florian|jonas|hans|stefan)/i.test(v.name));
  const pool = nameHint.length ? nameHint : de;
  let h = 0; for (const ch of speaker) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  return pool[h % pool.length];
}
export const canSpeak = () => 'speechSynthesis' in window;
let current = null;
export function stopSpeech() { if (current) { current.cancelled = true; current = null; } if (canSpeak()) speechSynthesis.cancel(); }

function speakLine(text, speaker, voices) {
  return new Promise(res => {
    const token = current;
    const cfg = voices?.[speaker] || {};
    if (!canSpeak() || !germanVoices().length) { setTimeout(res, Math.min(9000, 600 + text.length * 55)); return; }
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'de-DE'; const v = pickVoice(speaker, cfg); if (v) { u.voice = v; u.lang = v.lang; }
    u.pitch = cfg.pitch || 1; u.rate = cfg.rate || 1;
    u.onend = u.onerror = () => res();
    speechSynthesis.speak(u);
  });
}

/** Transkript-Player: Untertitel, Abspielen/Pause/Neu. lines: [{speaker,text,t?}] */
export function player(lines, voices, { audio = null, video = null, title = '' } = {}) {
  const rows = lines.map(l => h('div', { class: 'tr-line' }, h('strong', {}, l.speaker + (l.t ? ' · ' + l.t : '') + ': '), l.text));
  const status = h('div', { class: 'small muted' });
  const btn = h('button', { class: 'btn primary small' }, '▶ Anhören');
  let playing = false, idx = 0, run = 0;
  const media = video ? h('video', { src: '/assets/media/' + video, controls: true, playsinline: true, preload: 'none', style: { width: '100%', borderRadius: '8px' } })
              : audio ? h('audio', { src: '/assets/media/' + audio, controls: true, preload: 'none', style: { width: '100%' } }) : null;
  if (media) { media.addEventListener('error', () => { media.remove(); status.textContent = 'Aufnahme nicht verfügbar – Sprachausgabe als Ersatz.'; }); }
  const mark = () => rows.forEach((r, i) => r.classList.toggle('now', playing && i === idx - 1));
  async function play() {
    stopSpeech(); const my = ++run; current = { cancelled: false }; playing = true; btn.textContent = '⏸ Pause';
    status.textContent = germanVoices().length || !canSpeak() ? (canSpeak() ? 'Synthetische deutsche Stimme (Platzhalter für Originalaufnahme)' : 'Keine Sprachausgabe verfügbar – Untertitel laufen mit.') : 'Keine deutsche Stimme auf diesem Gerät – Untertitel laufen mit.';
    while (idx < lines.length && my === run) { idx++; mark(); rows[idx - 1].scrollIntoView({ block: 'nearest' }); await speakLine(lines[idx - 1].text, lines[idx - 1].speaker, voices); }
    if (my === run) { playing = false; idx = 0; btn.textContent = '↺ Nochmal anhören'; mark(); }
  }
  btn.addEventListener('click', () => { if (playing) { run++; playing = false; stopSpeech(); btn.textContent = '▶ Fortsetzen'; idx = Math.max(0, idx - 1); mark(); } else play(); });
  return h('div', { class: 'player', 'aria-label': title || 'Wiedergabe' }, media, h('div', { class: 'row' }, btn, h('button', { class: 'btn small ghost', onclick: () => { run++; playing = false; idx = 0; stopSpeech(); btn.textContent = '▶ Anhören'; mark(); } }, '■ Stopp')), status,
    h('div', { class: 'transcript' }, h('div', { class: 'small muted' }, 'Transkript / Untertitel (Deutsch)'), rows));
}

export function chatBubbles(msgs, meName = 'me') {
  return h('div', { class: 'chat phone-chat' }, msgs.map(m => h('div', { class: 'bubble ' + (m.from === meName ? 'q' : 'a') }, m.from !== meName && h('div', { class: 'small muted' }, m.from), m.text, h('div', { class: 'small muted', style: { textAlign: 'right' } }, m.time))));
}
