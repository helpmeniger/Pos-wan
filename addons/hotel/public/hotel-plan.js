/* Module Hôtel : plan des chambres en temps réel — une tuile par chambre, rangées par étage, couleur
   selon l'état (libre, à nettoyer, occupée, arrivée attendue, départ du jour, départ dépassé, hors service),
   filtres, recherche, panneau d'actions (installer un client, réserver, ouvrir le séjour, ménage). */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const T = (s) => (window.__ ? __(s) : s);
  const BASE = ($('meta[name="base-url"]').content || '/').replace(/\/$/, '');
  const CSRF = $('meta[name="csrf-token"]').content;
  const D = document.body.dataset;
  const CAN = { reception: D.canReception === '1', hk: D.canHk === '1', manage: D.canManage === '1' };
  const REFRESH = Math.max(10, parseInt(D.refresh, 10) || 20) * 1000;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const store = { get: (k, d) => { try { const v = localStorage.getItem(k); return v === null ? d : v; } catch (e) { return d; } }, set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) { /* stockage indisponible */ } } };
  const money = (n) => Math.round(n).toLocaleString('fr-FR').replace(/[  ]/g, ' ') + ' F';
  const fdate = (d) => { const p = String(d || '').split('-'); return p.length === 3 ? p[2] + '/' + p[1] : d; };
  const STATE = { libre: T('Libre'), occupee: T('Occupée'), arrivee: T('Arrivée attendue'), depart: T('Départ aujourd\'hui'), depart_retard: T('Départ dépassé'), hors_service: T('Hors service') };
  const HK_ICON = { propre: ['bi-check-circle-fill', '#22c55e'], inspectee: ['bi-patch-check-fill', '#2dd4bf'], sale: ['bi-bucket-fill', '#f59e0b'], nettoyage: ['bi-stars', '#fbbf24'] };
  let data = null;
  let failures = 0;
  let filter = store.get('hp-filter', '');
  let openId = null;

  const dirty = (r) => r.hk === 'sale' || r.hk === 'nettoyage';
  function matches(r) {
    const f = filter;
    if (f === 'libre' && r.state !== 'libre') return false;
    if (f === 'sale' && !dirty(r)) return false;
    if (f === 'occupee' && !['occupee', 'depart', 'depart_retard'].includes(r.state)) return false;
    if (f === 'depart' && !['depart', 'depart_retard'].includes(r.state)) return false;
    if (['arrivee', 'hors_service'].includes(f) && r.state !== f) return false;
    if ($('#floorSel').value && r.floor !== $('#floorSel').value) return false;
    if ($('#typeSel').value && String(r.type_id) !== $('#typeSel').value) return false;
    const q = $('#q').value.trim().toLowerCase();
    return !q || [r.number, r.type, r.stay && r.stay.name, r.stay && r.stay.number, r.stay && r.stay.phone].some((v) => String(v || '').toLowerCase().includes(q));
  }

  function tile(r) {
    const s = r.stay;
    const hk = HK_ICON[r.hk] || HK_ICON.propre;
    const label = r.state === 'libre' && dirty(r) ? (r.hk === 'nettoyage' ? T('Nettoyage en cours') : T('Libre — à nettoyer')) : STATE[r.state];
    let body = '';
    if (r.state === 'hors_service') {
      body = `<div class="who">${esc(r.ooo_reason || T('Hors service'))}</div><div class="meta">${r.ooo_until ? T('jusqu\'au') + ' ' + esc(fdate(r.ooo_until)) : ''}</div>`;
    } else if (s) {
      const when = s.status === 'en_cours' ? `${T('Nuit')} ${Math.min(s.night_no, Math.max(1, s.nights))}/${s.nights} · ${T('départ')} ${esc(fdate(s.departure))}` : `${T('Arrivée')} ${esc(fdate(s.arrival))} · ${s.nights} ${T('nuit(s)')}`;
      body = `<div class="who">${s.vip ? '<i class="bi bi-star-fill vip"></i> ' : ''}${esc(s.name)}</div>
        <div class="meta"><span>${when}</span><span><i class="bi bi-people"></i> ${s.adults + s.children}</span>${s.due !== null && s.due > 0 ? `<span class="due">${T('Reste')} ${money(s.due)}</span>` : ''}</div>`;
    } else {
      body = `<div class="who" style="color:var(--mut);font-weight:500">${esc(r.beds || r.type)}</div><div class="meta"><span><i class="bi bi-people"></i> ${r.capacity}</span></div>`;
    }
    return `<button type="button" class="room ${r.state}${dirty(r) ? ' dirty' : ''}" data-id="${r.id}" aria-label="${esc(T('Chambre') + ' ' + r.number)}">
      <div class="top"><span class="no">${esc(r.number)}</span><span class="ty" style="background:${esc(r.color)}">${esc(r.type_code)}</span><i class="bi ${hk[0]} hk" style="color:${hk[1]}" title="${esc(data.hk[r.hk] || '')}"></i></div>
      <div class="st">${esc(label)}</div>${body}</button>`;
  }

  function render() {
    if (!data) return;
    const t = data.totals;
    $('#kOcc').textContent = t.occupancy; $('#kOccupied').textContent = t.occupied; $('#kFree').textContent = t.free; $('#kArr').textContent = t.arrivals;
    $('#kDep').textContent = t.departures; $('#kDirty').textContent = t.dirty; $('#kOoo').textContent = t.ooo; $('#kGuests').textContent = t.guests;
    const counts = { '': data.rooms.length, libre: 0, occupee: 0, arrivee: 0, depart: 0, sale: 0, hors_service: 0 };
    data.rooms.forEach((r) => {
      if (r.state === 'libre') counts.libre++;
      if (dirty(r)) counts.sale++;
      if (['occupee', 'depart', 'depart_retard'].includes(r.state)) counts.occupee++;
      if (['depart', 'depart_retard'].includes(r.state)) counts.depart++;
      if (r.state === 'arrivee') counts.arrivee++;
      if (r.state === 'hors_service') counts.hors_service++;
    });
    document.querySelectorAll('[data-count]').forEach((el) => { el.textContent = counts[el.dataset.count] ?? ''; });
    document.querySelectorAll('#stateFilters button').forEach((b) => b.classList.toggle('on', b.dataset.state === filter));
    const fs = $('#floorSel');
    if (fs.options.length === 1) data.floors.forEach((f) => fs.add(new Option(f, f)));
    const list = data.rooms.filter(matches);
    if (!list.length) { $('#board').innerHTML = `<div class="hp-empty">${T('Aucune chambre ne correspond.')}</div>`; return; }
    const floors = [];
    list.forEach((r) => { let f = floors.find((x) => x.name === r.floor); if (!f) { f = { name: r.floor, rooms: [] }; floors.push(f); } f.rooms.push(r); });
    $('#board').innerHTML = floors.map((f) => {
      const occ = f.rooms.filter((r) => r.stay && r.stay.status === 'en_cours').length;
      return `<section class="hp-floor"><h2>${esc(f.name)} <span>${f.rooms.length} ${T('chambre(s)')} · ${occ} ${T('occupée(s)')}</span></h2><div class="hp-grid">${f.rooms.map(tile).join('')}</div></section>`;
    }).join('');
    if (openId) drawer(openId, true);
  }

  function drawer(id, refresh) {
    const r = data && data.rooms.find((x) => x.id === id);
    if (!r) return close();
    openId = id;
    const s = r.stay;
    const u = (p, q) => BASE + p + (q ? '?' + new URLSearchParams(q).toString() : '');
    let acts = '';
    if (CAN.reception && r.state === 'libre') {
      acts += `<a class="btn btn-success" href="${u('/hotel/reservations/nouvelle', { walkin: 1, room: r.id })}"><i class="bi bi-person-plus"></i> ${T('Installer un client')}</a>`;
      acts += `<a class="btn btn-primary" href="${u('/hotel/reservations/nouvelle', { room: r.id })}"><i class="bi bi-calendar-plus"></i> ${T('Réserver')}</a>`;
    }
    if (s && s.status !== 'en_cours' && CAN.reception) acts += `<a class="btn btn-info" href="${u('/hotel/sejours/' + s.id)}#arrivee"><i class="bi bi-box-arrow-in-right"></i> ${T('Enregistrer l\'arrivée')}</a>`;
    if (s && s.status === 'en_cours') {
      acts += `<a class="btn btn-primary" href="${u('/hotel/sejours/' + s.id)}"><i class="bi bi-folder2-open"></i> ${T('Ouvrir le séjour')}</a>`;
      if (CAN.reception) {
        acts += `<a class="btn btn-outline-light" href="${u('/hotel/sejours/' + s.id)}#prestation"><i class="bi bi-cup-hot"></i> ${T('Prestation')}</a>`;
        acts += `<a class="btn ${r.state === 'occupee' ? 'btn-outline-light' : 'btn-warning'}" href="${u('/hotel/sejours/' + s.id)}#depart"><i class="bi bi-box-arrow-right"></i> ${T('Départ')}</a>`;
      }
    }
    acts += `<a class="btn btn-outline-secondary" href="${u('/hotel/chambres/' + r.id)}"><i class="bi bi-door-closed"></i> ${T('Fiche de la chambre')}</a>`;
    const hk = CAN.hk && r.state !== 'hors_service' ? `<div class="box"><h4>${T('Ménage')}</h4><div class="hkbtns">${Object.entries(data.hk).map(([k, l]) => `<button type="button" data-hk="${k}" class="${r.hk === k ? 'cur' : ''}">${esc(l)}</button>`).join('')}</div></div>` : '';
    const guest = s ? `<div class="box"><h4>${s.status === 'en_cours' ? T('Client présent') : T('Arrivée attendue')}</h4>
        <div class="fs-5 fw-bold">${s.vip ? '<i class="bi bi-star-fill text-warning"></i> ' : ''}${esc(s.name)}</div>
        <div class="sub">${esc(s.number)}${s.phone ? ' · <a href="tel:' + esc(s.phone.replace(/[^0-9+]/g, '')) + '">' + esc(s.phone) + '</a>' : ''}</div>
        <div class="mt-1">${T('Du')} <b>${esc(fdate(s.arrival))}</b> ${T('au')} <b>${esc(fdate(s.departure))}</b> · ${s.nights} ${T('nuit(s)')} · ${s.adults} ${T('adulte(s)')}${s.children ? ', ' + s.children + ' ' + T('enfant(s)') : ''}</div>
        ${s.due !== null ? `<div class="mt-1">${s.due > 0 ? `${T('Reste à payer (estimé)')} : <b class="text-danger">${money(s.due)}</b>` : `<span class="text-success">${T('Séjour réglé')}</span>`}</div>` : ''}
        ${s.note ? `<div class="sub mt-1"><i class="bi bi-chat-left-text"></i> ${esc(s.note)}</div>` : ''}</div>` : '';
    $('#drawerBody').innerHTML = `<button type="button" class="close" data-close aria-label="${esc(T('Fermer'))}">&times;</button>
      <h3>${esc(r.number)}</h3><div class="sub">${esc(r.type)} · ${esc(r.floor)}${r.beds ? ' · ' + esc(r.beds) : ''} · ${r.capacity} ${T('pers.')}</div>
      <div class="mt-1"><span class="badge" style="background:${esc(r.color)}">${esc(STATE[r.state])}</span> <span class="badge bg-secondary">${esc(data.hk[r.hk] || '')}</span></div>
      ${r.state === 'hors_service' ? `<div class="box"><h4>${T('Hors service')}</h4>${esc(r.ooo_reason)}${r.ooo_until ? '<br>' + T('jusqu\'au') + ' ' + esc(fdate(r.ooo_until)) : ''}</div>` : ''}
      ${guest}${hk}<div class="acts">${acts}</div>`;
    if (!refresh) { $('#drawer').classList.add('on'); $('#veil').classList.add('on'); $('#drawer').setAttribute('aria-hidden', 'false'); }
  }
  function close() { openId = null; $('#drawer').classList.remove('on'); $('#veil').classList.remove('on'); $('#drawer').setAttribute('aria-hidden', 'true'); }

  async function load() {
    try {
      const r = await fetch(`${BASE}/hotel/plan/feed`, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (r.status === 401) { location.href = BASE + '/login'; return; }
      const d = await r.json();
      if (!d.ok) throw new Error('feed');
      data = d; failures = 0;
      render();
      $('#status').classList.remove('on');
      $('#updated').textContent = T('Mis à jour à') + ' ' + d.now;
      $('#today').textContent = new Date(d.date + 'T12:00:00').toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' });
    } catch (e) {
      failures++;
      if (failures >= 2) { $('#status').textContent = T('Connexion au serveur perdue : nouvel essai automatique…'); $('#status').classList.add('on'); }
    }
  }

  document.addEventListener('click', async (e) => {
    const room = e.target.closest('.room');
    if (room) { drawer(parseInt(room.dataset.id, 10)); return; }
    if (e.target.closest('[data-close]') || e.target.id === 'veil') { close(); return; }
    const f = e.target.closest('#stateFilters button');
    if (f) { filter = f.dataset.state; store.set('hp-filter', filter); render(); return; }
    const b = e.target.closest('button[data-hk]');
    if (b && openId) {
      b.disabled = true;
      try {
        const r = await fetch(`${BASE}/hotel/chambres/${openId}/menage`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ _csrf: CSRF, state: b.dataset.hk }) });
        const d = await r.json().catch(() => ({}));
        if (!d.ok) alert(d.error || T('Enregistrement impossible.'));
      } catch (err) { alert(T('Enregistrement impossible.')); }
      load();
    }
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  ['#floorSel', '#typeSel'].forEach((s) => $(s).addEventListener('change', render));
  $('#q').addEventListener('input', render);
  if (store.get('hp-theme', 'dark') === 'light') { document.body.classList.add('light'); document.documentElement.dataset.bsTheme = 'light'; }
  $('#themeBtn').addEventListener('click', () => {
    const light = document.body.classList.toggle('light');
    document.documentElement.dataset.bsTheme = light ? 'light' : 'dark';
    store.set('hp-theme', light ? 'light' : 'dark');
  });
  $('#fsBtn').addEventListener('click', () => { if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen().catch(() => {}); });
  const tick = () => { $('#clock').textContent = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); };
  tick(); setInterval(tick, 10000);
  load(); setInterval(load, REFRESH);
})();
