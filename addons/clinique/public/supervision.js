/* Module Clinique : écran de supervision des malades hospitalisés / en observation.
   Actualisation automatique, alertes (constantes hors seuils), soins en retard, bouton « Fait ». */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const T = (s) => (window.__ ? __(s) : s);
  const BASE = ($('meta[name="base-url"]').content || '/').replace(/\/$/, '');
  const CSRF = $('meta[name="csrf-token"]').content;
  const REFRESH = Math.max(10, parseInt(document.body.dataset.refresh, 10) || 20) * 1000;
  const CAN_LOG = document.body.dataset.canLog === '1';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = { get: (k, d) => { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } } };
  let data = null;
  let sound = store.get('sup-sound', '1') !== '0';
  let knownAlerts = null; // ids des séjours déjà en alerte (pour ne sonner qu'aux nouvelles alertes)
  let failures = 0;

  function beep() {
    if (!sound) return;
    try {
      const c = beep.c || (beep.c = new (window.AudioContext || window.webkitAudioContext)());
      [0, 0.25, 0.5].forEach((t) => { const o = c.createOscillator(); const g = c.createGain(); o.frequency.value = 990; g.gain.value = 0.18; o.connect(g); g.connect(c.destination); o.start(c.currentTime + t); o.stop(c.currentTime + t + 0.15); });
    } catch (e) { /* audio indisponible */ }
  }
  function mins(m) { m = Math.abs(m); return m >= 60 ? Math.floor(m / 60) + ' h ' + String(m % 60).padStart(2, '0') : m + ' min'; }

  function vital(label, value, unit, bad) {
    return `<div class="v ${bad ? 'bad' : ''} ${value === null || value === undefined ? 'none' : ''}"><span>${label}</span><b>${value === null || value === undefined ? '—' : esc(value)}</b>${unit && value !== null && value !== undefined ? `<i>${unit}</i>` : ''}</div>`;
  }

  function card(c) {
    const s = c.stay;
    if (!s) return `<article class="bed free"><div class="bed-head"><span class="bed-name">${esc(c.bed)}</span><span class="bed-room">${esc(c.room)}</span></div><div class="bed-free"><i class="bi bi-check2-circle"></i> ${T('Lit libre')}</div></article>`;
    const v = s.vitals || {};
    const has = (k) => s.flags.includes(k);
    const cls = s.alerts.length ? 'alert' : (s.late ? 'late' : (s.due || s.vitals_old ? 'due' : 'ok'));
    const care = s.care.slice(0, 4).map((x) => {
      const st = x.min < 0 ? 'late' : (x.min <= 60 ? 'due' : '');
      const when = x.min < 0 ? T('retard') + ' ' + mins(x.min) : (x.min <= 60 ? T('dans') + ' ' + mins(x.min) : x.at);
      const btn = CAN_LOG && x.min <= 60 ? `<button class="done" data-care="${x.id}" title="${T('Soin effectué')}"><i class="bi bi-check2"></i> ${T('Fait')}</button>` : '';
      return `<li class="${st}"><span class="c-l">${esc(x.label)}${x.dose ? ' · ' + esc(x.dose) : ''}${x.route ? ' · ' + esc(x.route) : ''}</span><span class="c-t">${esc(when)}</span>${btn}</li>`;
    }).join('');
    return `<article class="bed ${cls}">
      <div class="bed-head"><span class="bed-name">${esc(c.bed)}</span><span class="bed-room">${esc(c.room)}</span>${s.kind === 'observation' ? `<span class="tag obs">${T('Observation')}</span>` : ''}<span class="tag day">J${s.day}</span></div>
      <a class="pt" href="${BASE}/clinique/sejours/${s.id}"><b>${esc(s.patient)}</b> <span>${esc(s.sex)}${s.age ? ' · ' + esc(s.age) : ''}</span></a>
      <div class="dx">${esc(s.diagnosis || s.reason)}${s.doctor ? ` <span class="doc">· Dr ${esc(s.doctor)}</span>` : ''}</div>
      ${s.allergies ? `<div class="allergy"><i class="bi bi-exclamation-octagon"></i> ${esc(s.allergies)}</div>` : ''}
      <div class="vitals">
        ${vital('T°', v.t, '°C', has('t'))}
        ${vital('TA', v.ta, '', has('ta'))}
        ${vital(T('Pouls'), v.p, '/min', has('p'))}
        ${vital('SpO₂', v.spo2, '%', has('spo2'))}
        ${vital(T('Glyc.'), v.gly, 'g/L', has('gly'))}
      </div>
      <div class="v-at ${s.vitals_old ? 'old' : ''}">${s.vitals ? T('Relevé') + ' ' + esc(s.vitals.date === new Date().toISOString().slice(0, 10) ? '' : s.vitals.date + ' ') + esc(s.vitals.at) : T('Aucun relevé')}${s.vitals_old ? ' — <b>' + T('constantes à reprendre') + '</b>' : ''}</div>
      ${s.alerts.length ? `<div class="alerts"><i class="bi bi-activity"></i> ${s.alerts.map(esc).join(' · ')}</div>` : ''}
      <ul class="care">${care || `<li class="none">${T('Aucun soin programmé')}</li>`}</ul>
    </article>`;
  }

  function render() {
    if (!data) return;
    const ward = $('#ward').value;
    const showFree = $('#showFree').checked;
    const t = data.totals;
    $('#kPatients').textContent = t.patients; $('#kAlerts').textContent = t.alerts; $('#kLate').textContent = t.late;
    $('#kDue').textContent = t.due; $('#kVitals').textContent = t.vitals; $('#kFree').textContent = data.free;
    document.body.classList.toggle('has-alert', t.alerts > 0);
    const list = data.beds.filter((c) => (!ward || c.ward === ward) && (c.stay || showFree));
    $('#board').innerHTML = list.length ? list.map(card).join('') : `<div class="sup-empty"><i class="bi bi-moon-stars"></i> ${T('Aucun patient hospitalisé')}</div>`;
  }

  function fillWards() {
    const sel = $('#ward');
    const wards = [...new Set(data.beds.map((c) => c.ward))].sort();
    const cur = sel.value || store.get('sup-ward', '');
    if (sel.options.length - 1 !== wards.length) {
      sel.innerHTML = `<option value="">${T('Tous les services')}</option>` + wards.map((w) => `<option>${esc(w)}</option>`).join('');
    }
    sel.value = wards.includes(cur) ? cur : '';
  }

  async function load() {
    try {
      const r = await fetch(`${BASE}/clinique/supervision/feed`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (r.status === 401) { location.href = BASE + '/login'; return; }
      const d = await r.json();
      if (!d.ok) throw new Error('feed');
      data = d; failures = 0;
      const alerting = new Set(d.beds.filter((c) => c.stay && c.stay.alerts.length).map((c) => c.stay.id));
      if (knownAlerts && [...alerting].some((id) => !knownAlerts.has(id))) beep();
      knownAlerts = alerting;
      fillWards();
      render();
      $('#status').textContent = ''; $('#status').classList.remove('on');
      $('#updated').textContent = T('Mis à jour à') + ' ' + d.now;
    } catch (e) {
      failures++;
      if (failures >= 2) { $('#status').textContent = T('Connexion au serveur perdue : nouvel essai automatique…'); $('#status').classList.add('on'); }
    }
  }

  document.addEventListener('click', async (e) => {
    const b = e.target.closest('button[data-care]');
    if (!b) return;
    b.disabled = true;
    try {
      const r = await fetch(`${BASE}/clinique/soins/${b.dataset.care}/log`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ _csrf: CSRF, status: 'done' }) });
      const d = await r.json().catch(() => ({}));
      if (!d.ok) alert(d.error || T('Enregistrement impossible.'));
    } catch (err) { alert(T('Enregistrement impossible.')); }
    load();
  });
  $('#ward').addEventListener('change', () => { store.set('sup-ward', $('#ward').value); render(); });
  $('#showFree').checked = store.get('sup-free', '0') === '1';
  $('#showFree').addEventListener('change', () => { store.set('sup-free', $('#showFree').checked ? '1' : '0'); render(); });
  const sb = $('#soundBtn');
  const paintSound = () => { sb.innerHTML = `<i class="bi bi-volume-${sound ? 'up' : 'mute'}"></i>`; };
  paintSound();
  sb.addEventListener('click', () => { sound = !sound; store.set('sup-sound', sound ? '1' : '0'); paintSound(); if (sound) beep(); });
  $('#fsBtn').addEventListener('click', () => { if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen().catch(() => {}); });
  const tick = () => { $('#clock').textContent = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); };
  tick(); setInterval(tick, 10000);
  load(); setInterval(load, REFRESH);
})();
