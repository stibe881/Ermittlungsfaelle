import { h } from '../dom.js';
import { get, post, setCsrf } from '../api.js';
import { toast } from '../ui.js';
import { ctx, refreshMe, route } from '../main.js';

export function home() {
  return h('div', {},
    h('section', { class: 'hero' }, h('div', { class: 'wrap' },
      h('h1', {}, 'Ungelöste Fälle. Deine Beweise. Dein Urteil.'),
      h('p', {}, 'Übernimm die Akte als leitende Ermittlerin oder leitender Ermittler: Untersuche Tatorte, sichere Spuren, verhöre Verdächtige und überführe den Täter – allein oder im Team, auf dem Smartphone oder dem Fernseher.'),
      h('div', { class: 'row' }, h('a', { class: 'btn primary', href: '#/register' }, 'Ermittlung beginnen'), h('a', { class: 'btn', href: '#/login' }, 'Ich habe ein Konto')))),
    h('div', { class: 'wrap' },
      h('div', { class: 'features' },
        feature('Interaktive Tatorte', 'Untersuche Schauplätze, entdecke Spuren und schalte neue Beweise frei.'),
        feature('Verhöre mit Gedächtnis', 'Neue Beweise eröffnen neue Fragen – Verdächtige reagieren auf deinen Ermittlungsstand.'),
        feature('Pinnwand & Zeitachse', 'Verbinde Beweise, markiere Widersprüche und decke Lücken in Alibis auf.'),
        feature('Gemeinsam ermitteln', 'Bis zu 6 Personen teilen Pinnwand, Notizen und Funde. Der Fernseher zeigt die grosse Ansicht.')),
      h('p', { class: 'muted small' }, 'Alle Fälle, Personen und Ereignisse sind frei erfunden.')));
}
const feature = (t, d) => h('div', { class: 'card' }, h('h3', {}, t), h('p', { class: 'muted' }, d));

export function authForm(mode) {
  const reg = mode === 'register';
  const f = h('form', { class: 'card', style: { maxWidth: '460px', margin: '32px auto' }, onsubmit: async e => {
    e.preventDefault();
    const d = Object.fromEntries(new FormData(f));
    try {
      if (reg) {
        await post('/api/auth/register', { email: d.email, username: d.username, password: d.password, consent: !!d.consent });
        toast('Konto angelegt. Du wirst angemeldet …', 'ok');
        const r = await post('/api/auth/login', { login: d.email, password: d.password }); setCsrf(r.csrf);
      } else { const r = await post('/api/auth/login', { login: d.login, password: d.password }); setCsrf(r.csrf); }
      await refreshMe(); location.hash = '#/'; route();
    } catch (err) { toast(err.message, 'error'); }
  } },
    h('h2', {}, reg ? 'Registrieren' : 'Anmelden'),
    reg ? [h('label', {}, 'E-Mail', h('input', { name: 'email', type: 'email', required: true, autocomplete: 'email' })),
           h('label', {}, 'Benutzername (öffentlich in Ranglisten)', h('input', { name: 'username', required: true, minlength: 3, maxlength: 24, pattern: '[A-Za-z0-9_\\-]{3,24}', autocomplete: 'username' }))]
        : h('label', {}, 'E-Mail oder Benutzername', h('input', { name: 'login', required: true, autocomplete: 'username' })),
    h('label', {}, 'Passwort' + (reg ? ' (mind. 10 Zeichen)' : ''), h('input', { name: 'password', type: 'password', required: true, minlength: reg ? 10 : 1, autocomplete: reg ? 'new-password' : 'current-password' })),
    reg && h('label', { class: 'row' }, h('input', { name: 'consent', type: 'checkbox', required: true, style: { width: 'auto', minHeight: 0 } }),
      h('span', {}, 'Ich stimme der Speicherung meiner Daten gemäss Datenschutzerklärung zu. Öffentlich sichtbar ist nur mein Benutzername.')),
    h('div', { class: 'row end' }, h('button', { class: 'btn primary' }, reg ? 'Konto erstellen' : 'Anmelden')),
    !reg && h('p', { class: 'small' }, h('a', { href: '#', onclick: async e => { e.preventDefault(); const em = prompt('E-Mail-Adresse für den Zurücksetzen-Link:'); if (em) { await post('/api/auth/forgot', { email: em }); toast('Falls das Konto existiert, wurde ein Link versendet.', 'ok'); } } }, 'Passwort vergessen?')));
  return h('div', { class: 'wrap' }, f);
}

export async function verifyView(token) {
  try { await post('/api/auth/verify', { token }); return h('div', { class: 'wrap' }, h('div', { class: 'card' }, h('h2', {}, 'E-Mail bestätigt'), h('a', { class: 'btn primary', href: '#/login' }, 'Zur Anmeldung'))); }
  catch (e) { return h('div', { class: 'wrap' }, h('div', { class: 'card' }, h('h2', {}, 'Link ungültig'), h('p', {}, e.message))); }
}

export function resetView(token) {
  const f = h('form', { class: 'card', style: { maxWidth: '460px', margin: '32px auto' }, onsubmit: async e => {
    e.preventDefault();
    try { await post('/api/auth/reset', { token, password: f.password.value }); toast('Passwort geändert.', 'ok'); location.hash = '#/login'; } catch (err) { toast(err.message, 'error'); }
  } }, h('h2', {}, 'Neues Passwort'), h('label', {}, 'Passwort (mind. 10 Zeichen)', h('input', { name: 'password', type: 'password', minlength: 10, required: true })), h('div', { class: 'row end' }, h('button', { class: 'btn primary' }, 'Speichern')));
  return h('div', { class: 'wrap' }, f);
}
