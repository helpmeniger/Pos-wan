/* Module Pressing : écran des dépôts en cours (atelier) — colonnes déposés / en traitement / prêts,
   retards et dépôts du jour signalés, avancement en un clic, actualisation automatique.
   Mode « public » : numéros des dépôts prêts pour les clients (aucun téléphone ni montant). */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const T = (s) => (window.__ ? __(s) : s);
  const BASE = ($('meta[name="base-url"]').content || '/').replace(/\/$/, '');
  const CSRF = $('meta[name="csrf-token"]').content;
  const REFRESH = Math.max(10, parseInt(document.body.dataset.refresh, 10) || 15) * 1000;
  const PUBLIC = document.body.dataset.public === '1';
  const CAN = document.body.dataset.can === '1';
  const CAN_DELIVER = document.body.dataset.deliver === '1';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = { get: (k, d) => { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } } };
  const money = (n) => Math.round(n).toLocaleString('fr-FR').replace(/ | /g, ' ') + ' F';
  const fdate = (d) => { const p = String(d).split('-'); return p.length === 3 ? p[2] + '/' + p[1] : d; };
  let data = null;
  let failures = 0;

  const NEXT = { received: [['processing', T('En traitement'), 'go']], processing: [['ready', T('Prêt'), 'ok']], ready: [], partial: [] };

  function card(o) {
    const late = o.late > 0;
    const tags = (o.express ? `<span class="tag exp">EXPRESS</span>` : '') + (late ? `<span class="tag late">${T('Retard')} ${o.late} j</span>` : (o.due_today ? `<span class="tag today">${T('Aujourd\'hui')}</span>` : '')) + (o.status === 'partial' ? `<span class="tag part">${T('Retrait partiel')}</span>` : '');
    let acts = CAN ? (NEXT[o.status] || []).map(([to, label, cls]) => `<button class="${cls}" data-id="${o.id}" data-to="${to}"><i class="bi bi-arrow-right-circle"></i> ${esc(label)}</button>`).join('') : '';
    // Prêt à livrer : « Livré » si tout est payé ; sinon le livreur encaisse d'abord depuis la fiche
    if (o.delivery && ['ready', 'partial'].includes(o.status) && CAN_DELIVER) {
      acts = o.rest > 0
        ? `<a class="pay" href="${BASE}/pressing/${o.id}"><i class="bi bi-cash-coin"></i> ${esc(T('Encaisser'))} ${money(o.rest)} ${esc(T('puis livrer'))}</a>`
        : `<button class="ok" data-id="${o.id}" data-deliver><i class="bi bi-truck"></i> ${esc(T('Livré'))}</button>`;
    }
    return `<article class="pe-card ${late ? 'late' : ''} ${o.due_today && !late ? 'today' : ''}">
      <div class="top"><a class="num" href="${BASE}/pressing/${o.id}">${esc(o.number)}</a>${tags}</div>
      <div class="who">${esc(o.name)} <span>${esc(o.phone)}</span></div>
      ${o.delivery ? `<div class="addr"><i class="bi bi-geo-alt"></i> ${esc(o.address || '')}</div>` : ''}
      <div class="clothes">${esc(o.clothes || '')}${o.pieces ? ` · <b>${o.pieces} ${T('pièce(s)')}</b>` : ''}</div>
      ${o.note ? `<div class="clothes"><i class="bi bi-chat-left-text"></i> ${esc(o.note)}</div>` : ''}
      <div class="meta"><span>${T('Prévu le')} ${esc(fdate(o.due))}</span>${o.rest > 0 ? `<span class="rest">${T('Reste')} ${money(o.rest)}</span>` : `<span>${T('Soldé')}</span>`}</div>
      ${acts ? `<div class="acts">${acts}</div>` : ''}
    </article>`;
  }

  function match(o) {
    const q = ($('#q') ? $('#q').value : '').trim().toLowerCase();
    if ($('#lateOnly') && $('#lateOnly').checked && !(o.late > 0)) return false;
    return !q || [o.number, o.name, o.phone].some((v) => String(v || '').toLowerCase().includes(q));
  }

  function render() {
    if (!data) return;
    if (PUBLIC) {
      $('#board').innerHTML = data.ready.length
        ? data.ready.map((o) => `<div class="pe-ticket"><b>${esc(o.number)}</b><span>${esc(o.name)}</span>${o.status === 'partial' ? `<div class="tag">${T('Reste à retirer')}</div>` : ''}</div>`).join('')
        : `<div class="pe-empty">${T('Aucun dépôt prêt pour le moment.')}</div>`;
      return;
    }
    const t = data.totals;
    $('#kOpen').textContent = t.open; $('#kLate').textContent = t.late; $('#kToday').textContent = t.today; $('#kReady').textContent = t.ready;
    $('#kExpress').textContent = t.express; if ($('#kDelivery')) $('#kDelivery').textContent = t.delivery || 0; $('#kPieces').textContent = t.pieces; $('#kDue').textContent = money(t.due);
    const cols = [['received', T('Déposés — à traiter'), 'bi-inbox'], ['processing', T('En traitement'), 'bi-gear'], ['ready', T('Prêts à retirer'), 'bi-bag-check'], ['delivery', T('Prêts à livrer'), 'bi-truck']];
    $('#board').innerHTML = cols.map(([k, label, ic]) => {
      const list = (data.columns[k] || []).filter(match);
      return `<section class="pe-col ${k}"><h2><span><i class="bi ${ic}"></i> ${esc(label)}</span><span>${list.length}</span></h2>${list.length ? list.map(card).join('') : `<div class="none">${T('Aucun dépôt')}</div>`}</section>`;
    }).join('');
  }

  async function load() {
    try {
      const r = await fetch(`${BASE}/pressing/ecran/feed${PUBLIC ? '?public=1' : ''}`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (r.status === 401) { location.href = BASE + '/login'; return; }
      const d = await r.json();
      if (!d.ok) throw new Error('feed');
      data = d; failures = 0;
      render();
      $('#status').textContent = ''; $('#status').classList.remove('on');
      $('#updated').textContent = T('Mis à jour à') + ' ' + d.now;
    } catch (e) {
      failures++;
      if (failures >= 2) { $('#status').textContent = T('Connexion au serveur perdue : nouvel essai automatique…'); $('#status').classList.add('on'); }
    }
  }

  document.addEventListener('click', async (e) => {
    const b = e.target.closest('button[data-to], button[data-deliver]');
    if (!b) return;
    if (b.dataset.deliver !== undefined && !confirm(T('Confirmer : le linge a été remis au client à domicile ?'))) return;
    b.disabled = true;
    try {
      const url = b.dataset.deliver !== undefined ? `${BASE}/pressing/ecran/${b.dataset.id}/livrer` : `${BASE}/pressing/ecran/${b.dataset.id}/statut`;
      const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ _csrf: CSRF, status: b.dataset.to }) });
      const d = await r.json().catch(() => ({}));
      if (!d.ok) alert(d.error || T('Enregistrement impossible.'));
    } catch (err) { alert(T('Enregistrement impossible.')); }
    load();
  });
  if ($('#q')) $('#q').addEventListener('input', render);
  if ($('#lateOnly')) {
    $('#lateOnly').checked = store.get('pe-late', '0') === '1';
    $('#lateOnly').addEventListener('change', () => { store.set('pe-late', $('#lateOnly').checked ? '1' : '0'); render(); });
  }
  $('#fsBtn').addEventListener('click', () => { if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen().catch(() => {}); });
  const tick = () => { $('#clock').textContent = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); };
  tick(); setInterval(tick, 10000);
  load(); setInterval(load, REFRESH);
})();
