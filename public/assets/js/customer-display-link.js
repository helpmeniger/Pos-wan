/* =====================================================================
   POS Marwa — liaison entre la caisse et l'écran client / l'afficheur client.
   - Écran client : page /afficheur ouverte sur le second écran du poste. La caisse lui
     transmet le panier dans le navigateur (BroadcastChannel), sans passer par le serveur.
   - Afficheur client 2 lignes : branché sur ce poste (USB / série, Web Serial : Chrome ou
     Edge, en https ou sur localhost) ou relié au serveur (port COM / réseau).
   ===================================================================== */
(function () {
  'use strict';
  const cfgEl = document.getElementById('pm-display-config');
  if (!cfgEl) return;
  const CFG = JSON.parse(cfgEl.textContent);
  const BASE = ((document.querySelector('meta[name="base-url"]') || {}).content || '/').replace(/\/$/, '');
  const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const T = (s, p) => (window.__ ? __(s, p) : s);
  const STATE_KEY = 'pm-display-state';
  const channel = 'BroadcastChannel' in window ? new BroadcastChannel('pm-customer-display') : null;
  let last = { mode: 'idle' };
  let lastCart = null;
  let thanksUntil = 0;
  let thanksTimer = null;

  // ------------------------------------------------------------------ écran client
  function send(state) {
    last = state;
    try { localStorage.setItem(STATE_KEY, JSON.stringify(Object.assign({ at: Date.now() }, state))); } catch (e) { /* stockage indisponible */ }
    if (channel) channel.postMessage({ type: 'state', state });
    vfdShow(state);
  }
  if (channel) channel.onmessage = (e) => { if (e.data && e.data.type === 'hello') channel.postMessage({ type: 'state', state: last }); };

  document.addEventListener('pm:cart', (e) => {
    const c = e.detail;
    if (c.lines.length) {
      lastCart = c; thanksUntil = 0; clearTimeout(thanksTimer);
      send(Object.assign({ mode: 'cart' }, c));
    } else if (Date.now() >= thanksUntil) {
      send({ mode: 'idle' });
    }
  });
  document.addEventListener('pm:event', (e) => {
    const ev = e.detail || {};
    if (ev.event !== 'pos.sale.saved' && ev.event !== 'pos.sale.offline') return;
    const d = ev.data || {};
    const total = Number(d.total != null ? d.total : (lastCart ? lastCart.total : 0));
    const change = Number(d.change || 0);
    thanksUntil = Date.now() + CFG.thanksSeconds * 1000;
    send({ mode: 'thanks', total, change, paid: total + change });
    clearTimeout(thanksTimer);
    thanksTimer = setTimeout(() => { if (Date.now() >= thanksUntil && last.mode === 'thanks') send({ mode: 'idle' }); }, CFG.thanksSeconds * 1000 + 50);
  });

  /**
   * Ouvre l'écran client tout de suite (sans attendre : sinon le navigateur bloque la fenêtre),
   * puis la place sur le second écran dès que l'autorisation « gestion des fenêtres » est donnée.
   */
  function openScreen() {
    const w = window.open(BASE + '/afficheur', 'pm-customer-display', 'popup,width=1024,height=768');
    if (!w) { alert(T('Le navigateur a bloqué la fenêtre de l\'écran client : autorisez les fenêtres surgissantes pour ce site.')); return; }
    const manual = () => setTimeout(() => alert(T('Faites glisser la fenêtre « Écran client » sur le second écran, puis touchez-la pour la passer en plein écran.')), 300);
    if (!('getScreenDetails' in window)) { manual(); return; }
    const timeout = new Promise((resolve) => setTimeout(() => resolve(null), 15000));
    Promise.race([window.getScreenDetails().catch(() => null), timeout]).then((sd) => {
      const other = sd && (sd.screens.find((s) => s !== sd.currentScreen) || sd.screens.find((s) => !s.isPrimary));
      if (!other || w.closed) { manual(); return; }
      try { w.moveTo(other.availLeft, other.availTop); w.resizeTo(other.availWidth, other.availHeight); } catch (e) { manual(); }
    });
  }

  // ------------------------------------------------------------------ afficheur client 2 lignes
  const V = CFG.vfd || {};
  const enc = new TextEncoder();
  let port = null;
  let writer = null;
  let vfdTimer = null;
  const ascii = (s) => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/œ/g, 'oe').replace(/Œ/g, 'OE').replace(/æ/g, 'ae').replace(/Æ/g, 'AE').replace(/€/g, 'EUR').replace(/[\u2013\u2014]/g, '-').replace(/\u2019/g, "'").replace(/[«»]/g, '"')
    .replace(/[  ]/g, ' ').replace(/[^\x20-\x7e]/g, '?');
  const fit = (s) => ascii(s).slice(0, V.cols).padEnd(V.cols, ' ');
  const sym = () => ascii(window.PWMoney ? window.PWMoney.c.symbol : 'F');
  const amount = (n) => ascii(window.PWMoney ? window.PWMoney.plain(n) : String(Math.round(n))).replace(/\s/g, ' ') + ' ' + sym();
  const both = (left, right) => { const r = ascii(right); const l = ascii(left).slice(0, Math.max(0, V.cols - r.length - 1)); return l + ' '.repeat(Math.max(1, V.cols - l.length - r.length)) + r; };

  /** Octets d'un affichage deux lignes (mêmes règles que App\Services\CustomerDisplay::vfdBytes). */
  function vfdBytes(l1, l2) {
    if (V.protocol === 'cd5220') return '\x1B\x40\x0C\x1BQA' + fit(l1) + '\r\x1BQB' + fit(l2) + '\r';
    if (V.protocol === 'text') return '\r\n' + fit(l1) + '\r\n' + fit(l2);
    return '\x1B\x40\x0C\x0B' + fit(l1) + '\x1F\x24\x01\x02' + fit(l2);
  }
  function vfdLines(state) {
    if (state.mode === 'cart' && state.lines.length) {
      const l = state.lines[state.lines.length - 1];
      const q = Math.round(l.qty * 1000) / 1000;
      return [both((q !== 1 ? String(q).replace('.', ',') + ' x ' : '') + l.name, amount(l.total)), both('TOTAL', amount(state.total))];
    }
    if (state.mode === 'thanks') return [both('TOTAL', amount(state.total)), state.change > 0 ? both('RENDU', amount(state.change)) : ascii(CFG.thanks || 'MERCI')];
    return [V.line1 || 'BIENVENUE', V.line2 || ''];
  }
  function vfdShow(state) {
    if (!V.enabled) return;
    clearTimeout(vfdTimer);
    vfdTimer = setTimeout(() => { const [a, b] = vfdLines(state); vfdWrite(a, b); }, 120);
  }
  async function vfdWrite(l1, l2) {
    if (V.mode === 'server') {
      const body = new URLSearchParams({ _csrf: CSRF, line1: l1, line2: l2 });
      try { await fetch(BASE + '/afficheur/vfd', { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' }); } catch (e) { /* afficheur facultatif */ }
      return;
    }
    if (!writer) return;
    try { await writer.write(enc.encode(vfdBytes(l1, l2))); } catch (e) { writer = null; port = null; status(); }
  }
  async function vfdOpen(p) {
    try {
      await p.open({ baudRate: V.baud || 9600 });
    } catch (e) {
      if (!(e && e.name === 'InvalidStateError')) throw e; // déjà ouvert
    }
    port = p;
    writer = p.writable.getWriter();
    status();
    const [a, b] = vfdLines(last); vfdWrite(a, b);
  }
  /** Choix de l'afficheur par l'utilisateur (une fois par poste ; le navigateur s'en souvient). */
  async function vfdConnect() {
    if (!('serial' in navigator)) {
      alert(T('Ce navigateur ne pilote pas les ports USB / série. Utilisez Chrome ou Edge, en https ou sur le PC serveur (localhost) ; sinon choisissez « Relié au serveur » dans les paramètres.'));
      return false;
    }
    try { await vfdOpen(await navigator.serial.requestPort()); return true; } catch (e) { if (e && e.name !== 'NotFoundError') alert(T('Afficheur non ouvert : {m}', { m: e.message })); return false; }
  }
  async function vfdAuto() {
    if (!V.enabled || V.mode !== 'browser' || !('serial' in navigator)) return;
    try { const ports = await navigator.serial.getPorts(); if (ports.length) await vfdOpen(ports[0]); } catch (e) { /* reconnecté au prochain clic */ }
    status();
  }
  function status() {
    document.querySelectorAll('[data-cd-vfd]').forEach((b) => {
      const on = !!writer;
      b.classList.toggle('d-none', !(V.enabled && V.mode === 'browser'));
      b.classList.toggle('cd-on', on);
      b.title = on ? T('Afficheur client connecté') : T('Connecter l\'afficheur client (USB / série)');
    });
  }

  document.addEventListener('click', (e) => {
    if (e.target.closest('[data-cd-open]')) { e.preventDefault(); openScreen(); }
    if (e.target.closest('[data-cd-vfd]')) { e.preventDefault(); if (!writer) vfdConnect(); else vfdWrite('POS MARWA', T('Afficheur OK')); }
    const t = e.target.closest('[data-cd-vfd-test]');
    if (t) {
      e.preventDefault();
      (async () => {
        if (V.mode === 'server') {
          const r = await fetch(BASE + '/settings/display/vfd-test', { method: 'POST', body: new URLSearchParams({ _csrf: CSRF }), headers: { Accept: 'application/json' }, credentials: 'same-origin' });
          const d = await r.json().catch(() => ({}));
          alert(d.ok ? T('Essai envoyé à l\'afficheur.') : (d.error || T('Échec de l\'essai.')));
        } else if (writer || await vfdConnect()) {
          await vfdWrite(V.line1 || 'POS MARWA', 'ESSAI ' + new Date().toLocaleTimeString('fr-FR'));
        }
      })();
    }
  });

  window.PMDisplay = { open: openScreen, vfdConnect, vfdBytes, vfdLines, send, state: () => last };
  status();
  document.dispatchEvent(new CustomEvent('pm:cart-request')); // panier déjà présent à l'ouverture de la caisse
  vfdAuto();
  if (navigator.serial) navigator.serial.addEventListener('disconnect', () => { writer = null; port = null; status(); });
})();
