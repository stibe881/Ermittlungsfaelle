import { h } from '../dom.js';
import { toast, promptBox, confirmBox, modal } from '../ui.js';
import { setDragging } from './game.js';

const COLORS = { red: '#c0392b', blue: '#4a90d9', green: '#4caf50', yellow: '#e0b92a', purple: '#9b59b6', neutral: '#d9d4c5' };
let linkFrom = null, linkMode = false;

export function boardView(S, act, { showEvidence }) {
  const board = h('div', { class: 'board', id: 'board' });
  const svgNS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(svgNS, 'svg');
  const items = new Map(S.board.items.map(i => [i.id, i]));

  const center = i => ({ x: i.x + 75, y: i.y + 30 });
  const drawLinks = () => {
    svg.replaceChildren();
    for (const l of S.board.links) {
      const a = items.get(l.from_item), b = items.get(l.to_item); if (!a || !b) continue;
      const p = center(a), q = center(b);
      const line = document.createElementNS(svgNS, 'line');
      for (const [k, v] of Object.entries({ x1: p.x, y1: p.y, x2: q.x, y2: q.y, stroke: COLORS[l.color] || COLORS.red })) line.setAttribute(k, v);
      line.addEventListener('click', () => linkMenu(l));
      svg.append(line);
      if (l.label) {
        const t = document.createElementNS(svgNS, 'text');
        for (const [k, v] of Object.entries({ x: (p.x + q.x) / 2, y: (p.y + q.y) / 2 - 4, fill: '#f3e9c8', 'font-size': 13, 'text-anchor': 'middle', 'paint-order': 'stroke', stroke: '#000', 'stroke-width': 3 })) t.setAttribute(k, v);
        t.textContent = l.label; svg.append(t);
      }
    }
  };

  async function linkMenu(l) {
    const m = modal('Verbindung', h('div', {},
      h('div', { class: 'chips' }, Object.keys(COLORS).filter(c => c !== 'neutral').map(c => h('button', { class: 'chipbtn', style: { borderColor: COLORS[c] }, onclick: async () => { m.close(); await act('link_update', { id: l.id, color: c, base_version: l.version }); } }, c))),
      h('div', { class: 'row end' },
        h('button', { class: 'btn', onclick: async () => { const t = await promptBox('Beziehung beschriften', 'Beschriftung', l.label); if (t !== null) { m.close(); await act('link_update', { id: l.id, label: t, base_version: l.version }); } } }, 'Beschriften'),
        h('button', { class: 'btn danger', onclick: async () => { m.close(); await act('link_delete', { id: l.id }); } }, 'Löschen'))));
  }

  async function itemMenu(it) {
    const m = modal(it.label.slice(0, 40), h('div', {},
      h('div', { class: 'chips' }, Object.keys(COLORS).map(c => h('button', { class: 'chipbtn', onclick: async () => { m.close(); await act('board_update', { id: it.id, color: c, base_version: it.version }); } }, c))),
      h('div', { class: 'row end' },
        it.kind === 'evidence' && h('button', { class: 'btn', onclick: () => { m.close(); showEvidence(it.ref); } }, 'Beweis öffnen'),
        it.kind === 'note' && h('button', { class: 'btn', onclick: async () => { const t = await promptBox('Notizzettel', 'Text', it.label); if (t) { m.close(); await act('board_update', { id: it.id, label: t, base_version: it.version }); } } }, 'Bearbeiten'),
        h('button', { class: 'btn danger', onclick: async () => { m.close(); await act('board_delete', { id: it.id }); } }, 'Entfernen'))));
  }

  for (const it of S.board.items) {
    const kindLabel = { evidence: 'Beweis', suspect: 'Verdächtige/r', note: 'Notiz' }[it.kind];
    const pin = h('div', { class: 'pin ' + it.color + (linkFrom === it.id ? ' linksrc' : ''), style: { left: it.x + 'px', top: it.y + 'px' }, tabindex: 0, role: 'button', 'aria-label': kindLabel + ': ' + it.label }, h('small', {}, kindLabel), it.label);
    let sx, sy, ox, oy, moved = false;
    pin.addEventListener('pointerdown', e => {
      if (linkMode) return;
      pin.setPointerCapture(e.pointerId); sx = e.clientX; sy = e.clientY; ox = it.x; oy = it.y; moved = false; setDragging(true); pin.classList.add('dragging');
    });
    pin.addEventListener('pointermove', e => {
      if (!pin.hasPointerCapture(e.pointerId)) return;
      const dx = e.clientX - sx, dy = e.clientY - sy; if (Math.abs(dx) + Math.abs(dy) > 4) moved = true;
      it.x = Math.max(0, Math.min(1650, ox + dx)); it.y = Math.max(0, Math.min(1050, oy + dy));
      pin.style.left = it.x + 'px'; pin.style.top = it.y + 'px'; drawLinks();
    });
    pin.addEventListener('pointerup', async e => {
      if (!pin.hasPointerCapture(e.pointerId)) return;
      pin.releasePointerCapture(e.pointerId); pin.classList.remove('dragging');
      setDragging(false);
      if (moved) await act('board_move', { id: it.id, x: Math.round(it.x), y: Math.round(it.y), base_version: it.version });
      else itemMenu(it);
    });
    pin.addEventListener('click', async () => {
      if (!linkMode) return;
      if (!linkFrom) { linkFrom = it.id; pin.classList.add('linksrc'); return; }
      if (linkFrom === it.id) { linkFrom = null; pin.classList.remove('linksrc'); return; }
      const label = await promptBox('Verbindung beschriften', 'Beziehung (z. B. „widerspricht“, „belegt“)', '');
      const from = linkFrom; linkFrom = null; linkMode = false;
      if (label === null) { return act('heartbeat'); }
      await act('link_add', { from, to: it.id, label, color: 'red' });
    });
    board.append(pin);
  }
  board.prepend(svg); drawLinks();

  const onBoardEv = new Set(S.board.items.filter(i => i.kind === 'evidence').map(i => i.ref));
  const onBoardSu = new Set(S.board.items.filter(i => i.kind === 'suspect').map(i => i.ref));
  const sel = h('select', { 'aria-label': 'Element hinzufügen' }, h('option', { value: '' }, '＋ Beweis / Verdächtige/r anheften …'),
    h('optgroup', { label: 'Beweise' }, S.evidence.filter(e => !onBoardEv.has(e.id)).map(e => h('option', { value: 'evidence:' + e.id }, e.title))),
    h('optgroup', { label: 'Verdächtige' }, S.suspects.filter(s => !onBoardSu.has(s.id)).map(s => h('option', { value: 'suspect:' + s.id }, s.name))));
  sel.addEventListener('change', async () => { if (!sel.value) return; const [kind, ref] = sel.value.split(':'); await act('board_add', { kind, ref, x: 30 + Math.random() * 500, y: 30 + Math.random() * 300 }); });

  return h('div', {},
    h('div', { class: 'board-tools' }, h('div', { style: { minWidth: '220px', flex: 1 } }, sel),
      h('button', { class: 'btn', onclick: async () => { const t = await promptBox('Notizzettel', 'Text'); if (t) await act('board_add', { kind: 'note', label: t, x: 60, y: 60 }); } }, '🗒 Zettel'),
      h('button', { class: 'btn', 'aria-pressed': linkMode, style: linkMode ? { borderColor: 'var(--accent)' } : {}, onclick: () => { linkMode = !linkMode; linkFrom = null; toast(linkMode ? 'Tippe nacheinander zwei Elemente an' : 'Verbindungsmodus aus'); act('heartbeat'); } }, linkMode ? '🔗 Verbinden: aktiv' : '🔗 Verbinden')),
    h('div', { class: 'board-wrap' }, board),
    h('p', { class: 'small muted' }, 'Ziehen zum Verschieben · Antippen für Optionen · Linie antippen zum Beschriften. Änderungen werden mit allen Teammitgliedern geteilt.'));
}
