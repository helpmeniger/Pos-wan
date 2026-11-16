/*
 * Contrôle « rayon → caisse » : panneau des vérifications demandées (actualisé en direct, signal sonore
 * pour une nouvelle alerte) et boutons de vérification (encaissé, remis, fausse alerte, perte).
 */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const T = (s) => (window.__ ? __(s) : s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const BASE = (window.PW && PW.BASE) || '';
  const CSRF = ($('meta[name="csrf-token"]') || {}).content || '';
  const ACTIONS = [['encaisse', T('Encaissé'), 'success'], ['remis', T('Remis en rayon'), 'secondary'], ['fausse', T('Fausse alerte'), 'outline-secondary'], ['perte', T('Perte confirmée'), 'danger']];
  const LEVEL = { priorite: 'danger', verifier: 'warning', faible: 'secondary' };

  async function post(path, body) {
    const r = await fetch(BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body || {}) });
    return r.json().catch(() => ({ ok: false, error: T('Réponse invalide du serveur.') }));
  }

  // Vérification : un clic sur un bouton [data-review] (page Contrôles ou panneau en direct)
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-review]'); if (!b) return;
    const row = b.closest('[data-journey]'); if (!row) return;
    let note = null;
    if (b.dataset.review === 'perte' || b.dataset.review === 'fausse') {
      note = window.prompt(b.dataset.review === 'perte' ? T('Perte confirmée : précisez (comptage du rayon, quantité…)') : T('Fausse alerte : pourquoi ? (main, reflet, article mal reconnu…) Facultatif'), '');
      if (note === null) return;
    }
    b.disabled = true;
    const d = await post('/vision/controles/' + row.dataset.journey, { action: b.dataset.review, note });
    if (!d.ok) { b.disabled = false; window.alert(d.error || T('Erreur')); return; }
    const st = row.querySelector('[data-status]'); if (st) st.textContent = d.label;
    row.querySelectorAll('[data-review]').forEach((x) => x.remove());
    row.classList.add('ctl-done');
    if (live) setTimeout(refresh, 300);
  });

  // ------------------------------------------------------------------ panneau en direct
  const live = $('#survAlerts') || $('#ctlLive');
  let since = 0, first = true, timer = null, audio = null;
  function beep() {
    const sw = $('#survSound'); if (sw && !sw.checked) return;
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.22].forEach((t) => {
        const o = audio.createOscillator(), g = audio.createGain();
        o.frequency.value = 880; o.connect(g); g.connect(audio.destination);
        g.gain.setValueAtTime(0.0001, audio.currentTime + t); g.gain.exponentialRampToValueAtTime(0.25, audio.currentTime + t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + t + 0.18);
        o.start(audio.currentTime + t); o.stop(audio.currentTime + t + 0.2);
      });
    } catch (err) { /* son indisponible */ }
  }
  function card(a) {
    return `<div class="ctl-alert ctl-${esc(a.level)}${a.new && !first ? ' ctl-new' : ''}" data-journey="${a.id}">
      ${a.thumb ? `<img src="${esc(a.thumb)}" alt="">` : '<div class="ctl-noimg"><i class="bi bi-box-seam"></i></div>'}
      <div class="ctl-body"><div><span class="badge bg-${LEVEL[a.level] || 'secondary'}">${esc(a.level_label)}</span> <b>${esc(a.product)}</b></div>
        <div class="small">${esc(a.where)} · ${T('pris à')} ${esc(a.taken_at)}</div>
        <div class="small text-secondary">${esc(a.reason || '')}</div>
        <div class="small" data-status></div>
        <div class="ctl-actions">${ACTIONS.map(([k, l, c]) => `<button type="button" class="btn btn-sm btn-${c}" data-review="${k}">${esc(l)}</button>`).join('')}</div></div></div>`;
  }
  async function refresh() {
    clearTimeout(timer);
    try {
      const r = await fetch(BASE + '/vision/controles/live?since=' + since, { headers: { Accept: 'application/json' } });
      const d = await r.json();
      if (d.ok) {
        const box = $('[data-alerts]', live);
        box.innerHTML = d.alerts.length ? d.alerts.map(card).join('') : `<span class="small text-secondary p-2 d-block">${esc(T('Aucune vérification demandée.'))}</span>`;
        const n = $('[data-alert-count]', live); if (n) n.textContent = d.alerts.length;
        const o = $('[data-open]', live); if (o) o.textContent = d.en_cours + d.vu_caisse;
        const v = $('[data-vucaisse]', live); if (v) v.textContent = d.vu_caisse;
        if (!first && d.alerts.some((a) => a.new)) { beep(); live.classList.add('ctl-flash'); setTimeout(() => live.classList.remove('ctl-flash'), 1500); }
        since = Math.max(since, d.max_id); first = false;
      }
    } catch (err) { /* hors ligne : nouvel essai */ }
    timer = setTimeout(refresh, 5000);
  }
  if (live) refresh();
  window.PMControles = { refresh: () => live && refresh() };
})();
