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
