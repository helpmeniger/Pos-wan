/*
 * MARWA VOICE AI — moteur vocal du poste (chargé sur toutes les pages quand le module est actif).
 *
 * Les écrans de POS Marwa émettent des événements (document « pm:event » : { event, data }).
 * Ce moteur décide s'il faut parler (réglages du responsable + réglages de ce poste + silence),
 * construit la phrase avec les seules données reçues, la place dans une file à priorités et la
 * lit sur CE poste : voix Piper du serveur si disponible, sinon voix installée dans le système
 * (Windows), sinon rien — les messages restent affichés à l'écran comme avant.
 * Aucun microphone n'est utilisé dans cette phase.
 */
(function () {
  'use strict';
  const C = window.PMVoiceCore;
  if (!C || window.PMVoice) return;
  const $ = (s, r = document) => r.querySelector(s);
  const BASE = (($('meta[name="base-url"]') || {}).content || '/').replace(/\/$/, '');
  const CSRF = () => (($('meta[name="csrf-token"]') || {}).content || '');
  const POSTE_KEY = 'pm-voix-poste';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // ------------------------------------------------------------------ réglages du poste (navigateur)
  const POSTE_DEFAULT = { muted: false, volume: null, rate: null, engine: '', voice: '', name: '', off: [], hideWidget: false };
  function poste() {
    try { return Object.assign({}, POSTE_DEFAULT, JSON.parse(localStorage.getItem(POSTE_KEY) || '{}')); } catch (e) { return Object.assign({}, POSTE_DEFAULT); }
  }
  function savePoste(p) { try { localStorage.setItem(POSTE_KEY, JSON.stringify(Object.assign(poste(), p))); } catch (e) { /* stockage indisponible */ } refreshWidget(); }

  let cfg = null;
  const early = []; // événements reçus avant la configuration
  const queue = C.Queue();
  let current = null, stopCurrent = null, lastSpoken = null, waitingGesture = false;
  const logBuf = [];
  const clientCache = new Map(); // phrase → son (Piper) déjà reçu sur ce poste

  // ------------------------------------------------------------------ historique (envoi groupé)
  function log(item, status, engine) {
    if (!cfg) return;
    if (item.level < cfg.logLevel && status !== 'erreur_voix') return;
    logBuf.push({ event: item.event, level: item.level, status, text: item.text, engine: engine || null, poste: poste().name || null, page: location.pathname.slice(-60) });
    if (logBuf.length >= 20) flushLog();
  }
  function flushLog(keepalive) {
    if (!logBuf.length) return;
    const items = logBuf.splice(0, 50);
    fetch(BASE + '/voix/journal', { method: 'POST', keepalive: !!keepalive, credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF() },
      body: JSON.stringify({ _csrf: CSRF(), items }) }).catch(() => { /* historique non essentiel */ });
  }
  setInterval(() => flushLog(false), 8000);
  addEventListener('pagehide', () => flushLog(true));

  // ------------------------------------------------------------------ voix
  function volume() { const p = poste(); return Math.max(0, Math.min(1, p.volume ?? cfg.volume ?? 0.9)); }
  function rate() { const p = poste(); return Math.max(0.6, Math.min(1.6, p.rate ?? cfg.rate ?? 1)); }
  function engineFor() {
    const e = poste().engine || cfg.engine;
    if (e === 'navigateur') return 'navigateur';
    if (e === 'piper') return cfg.piper ? 'piper' : 'navigateur';
    return cfg.piper ? 'piper' : 'navigateur';
  }
  function voices() {
    return new Promise((res) => {
      if (!('speechSynthesis' in window)) { res([]); return; }
      const v = speechSynthesis.getVoices();
      if (v.length) { res(v); return; }
      const t = setTimeout(() => res(speechSynthesis.getVoices()), 1500);
      speechSynthesis.addEventListener('voiceschanged', () => { clearTimeout(t); res(speechSynthesis.getVoices()); }, { once: true });
    });
  }
  /** Voix du système utilisable : choisie, sinon une voix française ; hors ligne : voix locales seulement. */
  async function systemVoice() {
    const all = await voices();
    const ok = all.filter((v) => !cfg.offlineOnly || v.localService);
    const want = poste().voice || cfg.browserVoice;
    return ok.find((v) => v.name === want) || ok.find((v) => /^fr/i.test(v.lang)) || null;
  }
  function blocked(e) { const err = new Error('lecture bloquée'); err.blocked = true; err.cause = e; return err; }
  async function speakSystem(text) {
    const v = await systemVoice();
    if (!v) throw new Error('Aucune voix française installée sur ce poste' + (cfg.offlineOnly ? ' (hors ligne)' : ''));
    return new Promise((resolve, reject) => {
      const u = new SpeechSynthesisUtterance(text);
      u.voice = v; u.lang = v.lang; u.volume = volume(); u.rate = rate();
      u.onend = () => resolve();
      u.onerror = (e) => (e.error === 'interrupted' || e.error === 'canceled' ? resolve() : reject(e.error === 'not-allowed' ? blocked(e) : new Error('voix du système : ' + e.error)));
      stopCurrent = () => speechSynthesis.cancel();
      speechSynthesis.cancel();
      speechSynthesis.speak(u);
    });
  }
  async function piperBlob(text) {
    if (clientCache.has(text)) return clientCache.get(text);
    const r = await fetch(BASE + '/voix/tts', { method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF() }, body: JSON.stringify({ _csrf: CSRF(), text }) });
    if (!r.ok || !/audio/.test(r.headers.get('Content-Type') || '')) {
      const d = await r.json().catch(() => ({}));
      throw new Error(d.error || 'voix du serveur indisponible');
    }
    const blob = await r.blob();
    const item = { blob, url: URL.createObjectURL(blob) };
    clientCache.set(text, item);
    if (clientCache.size > 40) { const k = clientCache.keys().next().value; URL.revokeObjectURL(clientCache.get(k).url); clientCache.delete(k); }
    return item;
  }
  let audioCtx = null;
  /** Lecture par Web Audio : secours quand l'élément <audio> ne sait pas lire le son sur ce navigateur. */
  async function playWebAudio(blob) {
    audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state !== 'running') { try { await audioCtx.resume(); } catch (e) { /* bloqué */ } }
    if (audioCtx.state !== 'running') throw blocked(new Error('AudioContext ' + audioCtx.state));
    const buf = await audioCtx.decodeAudioData(await blob.arrayBuffer());
    const src = audioCtx.createBufferSource(); const gain = audioCtx.createGain();
    src.buffer = buf; src.playbackRate.value = rate(); gain.gain.value = volume();
    src.connect(gain); gain.connect(audioCtx.destination);
    return new Promise((resolve) => { src.onended = () => resolve(); stopCurrent = () => { try { src.stop(); } catch (e) { /* déjà arrêté */ } resolve(); }; src.start(); });
  }
  async function speakPiper(text) {
    const snd = await piperBlob(text);
    const a = new Audio(snd.url);
    a.volume = volume(); a.playbackRate = rate(); a.preservesPitch = true;
    try {
      await new Promise((resolve, reject) => {
        a.onended = () => resolve();
        a.onerror = () => reject(Object.assign(new Error('lecture du son impossible'), { name: 'NotSupportedError' }));
        stopCurrent = () => { a.pause(); resolve(); };
        a.play().catch((e) => reject(e && e.name === 'NotAllowedError' ? blocked(e) : e));
      });
    } catch (e) {
      if (e.blocked || e.name !== 'NotSupportedError') throw e;
      await playWebAudio(snd.blob);
    }
  }
  /** Lit une phrase ; renvoie le moteur utilisé. Piper en échec : voix du système. */
  async function speak(text) {
    let why = '';
    if (engineFor() === 'piper') {
      try { await speakPiper(text); return 'piper'; } catch (e) { if (e.blocked) throw e; why = 'Piper : ' + (e.message || e) + ' ; '; }
    }
    try { await speakSystem(text); } catch (e) { if (e.blocked) throw e; throw new Error(why + (e.message || e)); }
    return 'navigateur';
  }

  // ------------------------------------------------------------------ file
  async function pump() {
    if (current || waitingGesture || !cfg) return;
    const item = queue.next();
    queue.takeDropped().forEach((d) => log(d.item, d.reason));
    if (!item) { refreshWidget(); return; }
    current = item; refreshWidget();
    let engine = null;
    try {
      engine = await speak(item.text);
      if (item.interrupted) { delete item.interrupted; } else { lastSpoken = item; log(item, 'lu', engine); }
    } catch (e) {
      if (e.blocked) {
        // Le navigateur exige un premier geste (clic, touche) avant de produire un son
        queue.requeue(item); waitingGesture = true; current = null; refreshWidget();
        const go = () => { waitingGesture = false; removeEventListener('pointerdown', go, true); removeEventListener('keydown', go, true); setTimeout(pump, 50); };
        addEventListener('pointerdown', go, true); addEventListener('keydown', go, true);
        return;
      }
      lastSpoken = item; log(Object.assign({}, item, { text: item.text + ' — ' + (e.message || e) }), 'erreur_voix', engineFor());
    }
    current = null; stopCurrent = null;
    setTimeout(pump, 250);
  }

  function quiet() {
    return poste().muted || C.inQuiet(cfg.quiet.start, cfg.quiet.end);
  }
  /** Événement d'un écran → annonce éventuelle. */
  function handle(event, data, force) {
    if (!cfg) { early.push([event, data, force]); return; }
    const ev = cfg.events[event];
    if (!ev || (!ev.on && !force)) return;
    const p = poste();
    if (!force && p.off.includes(ev.module)) return;
    const text = C.phrase(event, data, cfg.currency);
    if (!text) return;
    const item = { event, level: ev.level, text, key: data && data.key, group: data && data.group };
    if (!force && quiet() && !(ev.level === 4 && cfg.quiet.critical)) { log(item, 'silence'); return; }
    const r = queue.push(item);
    if (!r.accepted) { log(item, r.reason); return; }
    if (current && queue.interrupts(item, current)) { const c = current; c.interrupted = true; log(c, 'interrompu'); queue.requeue(Object.assign({}, c, { interrupted: false })); if (stopCurrent) stopCurrent(); }
    pump();
  }

  // ------------------------------------------------------------------ indicateur à l'écran
  let widget = null;
  function refreshWidget() {
    if (!cfg) return;
    const p = poste();
    if (!widget) {
      widget = document.createElement('div');
      widget.className = 'pm-voix d-print-none';
      widget.setAttribute('role', 'status');
      widget.innerHTML = '<button type="button" data-voix-mute class="pm-voix-btn"></button><span data-voix-text class="pm-voix-text"></span>'
        + '<button type="button" data-voix-repeat class="pm-voix-btn" title="Répéter la dernière annonce"><i class="bi bi-arrow-repeat"></i></button>';
      widget.querySelector('[data-voix-mute]').addEventListener('click', () => { const m = !poste().muted; savePoste({ muted: m }); if (m) { queue.clear('silence'); if (stopCurrent) stopCurrent(); } });
      widget.querySelector('[data-voix-repeat]').addEventListener('click', () => api.repeat());
      // Caisse et prise de commande : rangé avec les icônes de l'en-tête (ne masque aucun bouton)
      const dock = document.querySelector('.head-icons');
      if (dock) { widget.classList.add('docked'); dock.insertBefore(widget, dock.firstChild); } else document.body.appendChild(widget);
    }
    widget.hidden = p.hideWidget;
    widget.classList.toggle('speaking', !!current);
    widget.classList.toggle('muted', p.muted);
    const mb = widget.querySelector('[data-voix-mute]');
    mb.innerHTML = p.muted ? '<i class="bi bi-volume-mute-fill"></i>' : '<i class="bi bi-volume-up-fill"></i>';
    mb.title = p.muted ? 'Voix coupée sur ce poste (les messages restent affichés) — toucher pour réactiver' : 'Couper la voix sur ce poste';
    const t = widget.querySelector('[data-voix-text]');
    t.textContent = waitingGesture ? 'Touchez l\'écran pour activer la voix' : current ? current.text : '';
    t.title = lastSpoken ? 'Dernière annonce : ' + lastSpoken.text : '';
  }

  // ------------------------------------------------------------------ messages affichés par les pages
  function pageMessages() {
    document.querySelectorAll('main .alert[role="alert"]').forEach((el) => {
      const msg = (el.textContent || '').replace(/\s+/g, ' ').trim();
      if (!msg) return;
      if (el.classList.contains('alert-danger')) handle('page.error', { message: msg });
      else if (el.classList.contains('alert-warning')) handle('page.warning', { message: msg });
    });
  }

  // ------------------------------------------------------------------ démarrage
  const api = {
    /** Annonce d'un événement : PMVoice.say('pos.sale.saved', { total, change }) */
    say: (event, data) => handle(event, data || {}),
    /** Essai (réglages) : lu même si l'événement est désactivé ou si le poste est en silence. */
    test: (text) => { if (!cfg) return; queue.clear('efface'); handle('voix.test', { text }, true); },
    repeat: () => { if (lastSpoken) { queue.clear('efface'); queue.push(Object.assign({}, lastSpoken, { key: 'repeat|' + Date.now(), t: 0 })); pump(); } },
    stop: () => { queue.clear('efface'); if (stopCurrent) stopCurrent(); },
    poste, savePoste, voices, systemVoice,
    state: () => ({ ready: !!cfg, speaking: current ? current.text : null, queued: queue.length, last: lastSpoken ? lastSpoken.text : null, engine: cfg ? engineFor() : null, waitingGesture }),
    get config() { return cfg; },
  };
  window.PMVoice = api;
  document.addEventListener('pm:event', (e) => { const d = e.detail || {}; if (d.event) handle(String(d.event), d.data || {}); });

  fetch(BASE + '/voix/config', { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
    .then((r) => (r.ok ? r.json() : null))
    .then((d) => {
      if (!d || !d.ok || !d.active) return; // module sans droit ou désactivé : aucun son, rien ne change
      cfg = d;
      refreshWidget();
      early.splice(0).forEach(([e, data, f]) => handle(e, data, f));
      pageMessages();
      document.dispatchEvent(new CustomEvent('pm:voix-ready'));
    })
    .catch(() => { /* serveur injoignable : pas d'annonces, l'écran fonctionne normalement */ });
})();
