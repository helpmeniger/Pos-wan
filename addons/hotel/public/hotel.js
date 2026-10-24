/* Module Hôtel : formulaire de réservation (disponibilités et devis en direct, recherche du client),
   folio (prestation du catalogue, montants), changement de chambre. Les montants affichés ici ne sont
   qu'indicatifs : le serveur recalcule tout. */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const T = (s) => (window.__ ? __(s) : s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const money = (n) => Math.round(n).toLocaleString('fr-FR').replace(/[  ]/g, ' ') + ' F';
  const num = (v) => parseFloat(String(v || '').replace(/[\s  ]/g, '').replace(',', '.')) || 0;
  const addDays = (d, n) => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); };
  const diff = (a, b) => Math.round((new Date(b + 'T12:00:00') - new Date(a + 'T12:00:00')) / 86400000);
  const HK = { propre: T('propre'), inspectee: T('inspectée'), sale: T('à nettoyer'), nettoyage: T('nettoyage en cours') };

  // Impression automatique de la facture après le départ (cadre invisible)
  const ap = $('[data-autoprint-url]');
  if (ap) {
    const f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0';
    f.src = ap.dataset.autoprintUrl;
    document.body.appendChild(f);
  }

  // Boutons « encaisser le solde » : remplissent le montant du formulaire
  $$('[data-fill-amount]').forEach((b) => b.addEventListener('click', () => {
    const f = b.closest('form'); const i = f && $('[data-pay-amount]', f);
    if (i) { i.value = b.dataset.fillAmount; i.focus(); }
  }));

  // ------------------------------------------------------------------ réservation / arrivée directe
  const form = $('#stayForm');
  if (form) {
    const A = $('[data-arrival]', form), Dp = $('[data-departure]', form), N = $('[data-nights]', form);
    const typeIn = $('[data-type-id]', form), roomIn = $('[data-room-id]', form), rate = $('[data-rate]', form);
    const manage = form.dataset.manage === '1';
    let avail = null; let wantRoom = parseInt(form.dataset.wantRoom, 10) || 0; let seq = 0;

    function quote() {
      const t = avail && avail.types.find((x) => String(x.id) === typeIn.value);
      const q = $('[data-quote]', form);
      if (!t) { q.innerHTML = `<span class="text-secondary">${T('Choisissez un type de chambre.')}</span>`; return; }
      const r = num(rate.value);
      const total = Math.abs(r - t.rate) >= 0.01 ? r * avail.nights : t.total;
      q.innerHTML = `<div class="d-flex justify-content-between"><span>${avail.nights} ${T('nuit(s)')} · ${esc(t.name)}</span><b>${money(total)}</b></div>
        <div class="small text-secondary">${Math.abs(r - t.rate) >= 0.01 ? T('Tarif négocié') + ' ' + money(r) : T('Tarif') + ' ' + money(t.rate) + ' ' + T('la nuit') + (t.total !== t.rate * avail.nights ? ' ' + T('(week-end compris)') : '')}${T(' · taxe de séjour éventuelle en plus')}</div>`;
      $('[data-fill-total]', form).dataset.total = String(Math.round(total));
    }
    function pickType(id, keepRate) {
      typeIn.value = String(id);
      $$('.ht-type[data-type]', form).forEach((c) => c.classList.toggle('on', c.dataset.type === String(id)));
      const t = avail.types.find((x) => x.id === id);
      if (t && !keepRate) rate.value = String(Math.round(t.rate));
      const rooms = avail.rooms.filter((r) => r.type_id === id);
      const box = $('[data-rooms]', form);
      box.innerHTML = rooms.length ? rooms.map((r) => `<button type="button" class="ht-room ${r.hk === 'sale' || r.hk === 'nettoyage' ? 'dirty' : ''}" data-room="${r.id}">
          <b>${esc(r.number)}</b><span>${esc(r.floor)}${r.beds ? ' · ' + esc(r.beds) : ''}</span><small>${esc(HK[r.hk] || '')}</small></button>`).join('')
        : `<div class="text-danger small">${T('Aucune chambre libre de ce type sur ces dates.')}</div>`;
      if (!rooms.some((r) => String(r.id) === roomIn.value)) roomIn.value = '';
      $$('.ht-room[data-room]', box).forEach((b) => b.classList.toggle('on', b.dataset.room === roomIn.value));
      quote();
    }
    async function load() {
      const a = A.value, d = Dp.value;
      if (!a || !d || d <= a) { $('[data-types]', form).innerHTML = `<div class="text-danger small">${T('La date de départ doit suivre la date d\'arrivée.')}</div>`; return; }
      const my = ++seq;
      const r = await fetch(`${form.dataset.availUrl}?arrival=${a}&departure=${d}`, { headers: { Accept: 'application/json' } });
      const j = await r.json().catch(() => null);
      if (my !== seq || !j || !j.ok) return;
      avail = j;
      $('[data-types]', form).innerHTML = j.types.map((t) => `<button type="button" class="ht-type ${t.free ? '' : 'full'}" data-type="${t.id}">
          <b>${esc(t.name)}</b><span>${money(t.rate)} / ${T('nuit')}</span><small>${t.free ? t.free + ' ' + T('libre(s)') : T('complet')} · ${t.capacity} ${T('pers.')}</small></button>`).join('');
      let typeId = parseInt(typeIn.value, 10) || 0;
      if (wantRoom) {
        const room = j.rooms.find((x) => x.id === wantRoom);
        if (room) { typeId = room.type_id; roomIn.value = String(room.id); }
        wantRoom = 0;
      }
      if (!j.types.some((t) => t.id === typeId)) typeId = (j.types.find((t) => t.free) || j.types[0] || {}).id;
      if (typeId) pickType(typeId, rate.value !== '' && String(typeId) === typeIn.value);
    }
    form.addEventListener('click', (e) => {
      const t = e.target.closest('.ht-type[data-type]');
      if (t) { roomIn.value = ''; pickType(parseInt(t.dataset.type, 10), false); return; }
      const r = e.target.closest('.ht-room[data-room]');
      if (r) { roomIn.value = roomIn.value === r.dataset.room ? '' : r.dataset.room; $$('.ht-room[data-room]', form).forEach((b) => b.classList.toggle('on', b.dataset.room === roomIn.value)); return; }
      const ft = e.target.closest('[data-fill-total]');
      if (ft && ft.dataset.total) { $('[data-pay-amount]', form).value = ft.dataset.total; }
    });
    const syncNights = () => { const n = diff(A.value, Dp.value); if (n > 0) N.value = n; };
    A.addEventListener('change', () => { if (!Dp.value || Dp.value <= A.value) Dp.value = addDays(A.value, Math.max(1, parseInt(N.value, 10) || 1)); syncNights(); load(); });
    Dp.addEventListener('change', () => { syncNights(); load(); });
    N.addEventListener('change', () => { const n = Math.max(1, parseInt(N.value, 10) || 1); Dp.value = addDays(A.value, n); load(); });
    rate.addEventListener('input', quote);
    form.addEventListener('submit', (e) => {
      if (form.querySelector('[name=walk_in]').value === '1' && !roomIn.value) { e.preventDefault(); alert(T('Choisissez la chambre du client.')); }
      if (!manage && avail) { const t = avail.types.find((x) => String(x.id) === typeIn.value); if (t) rate.value = String(Math.round(t.rate)); }
    });
    syncNights(); load();

    // Recherche d'un client déjà venu
    const search = $('[data-guest-search]', form), results = $('[data-guest-results]', form), gid = $('[data-guest-id]', form);
    let timer = null;
    search.addEventListener('input', () => {
      clearTimeout(timer);
      const q = search.value.trim();
      if (q.length < 2) { results.innerHTML = ''; return; }
      timer = setTimeout(async () => {
        const r = await fetch(`${form.dataset.guestUrl}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
        const j = await r.json().catch(() => null);
        if (!j || !j.ok) return;
        results.innerHTML = j.guests.length ? j.guests.map((g, i) => `<button type="button" class="list-group-item list-group-item-action" data-gi="${i}">
            <b>${esc(g.last_name)} ${esc(g.first_name || '')}</b>${+g.vip ? ' <i class="bi bi-star-fill text-warning"></i>' : ''}${+g.blacklisted ? ' <span class="badge bg-danger">' + T('refusé') + '</span>' : ''}
            <span class="small text-secondary"> · ${esc(g.phone || '')} ${g.id_number ? '· ' + esc(g.id_number) : ''} · ${g.stays} ${T('séjour(s)')}</span></button>`).join('')
          : `<div class="list-group-item small text-secondary">${T('Nouveau client : remplissez sa fiche ci-dessous.')}</div>`;
        results._guests = j.guests;
      }, 250);
    });
    results.addEventListener('click', (e) => {
      const b = e.target.closest('[data-gi]');
      if (!b) return;
      const g = results._guests[parseInt(b.dataset.gi, 10)];
      gid.value = g.id;
      $$('[data-g]', form).forEach((el) => { const v = g[el.dataset.g]; if (el.type === 'checkbox') el.checked = +v === 1; else el.value = v ?? ''; });
      const p = $('[data-guest-picked]', form);
      p.classList.remove('d-none');
      p.innerHTML = `<i class="bi bi-person-check"></i> ${T('Client existant')} : <b>${esc(g.last_name)} ${esc(g.first_name || '')}</b> (${g.stays} ${T('séjour(s)')}). ${T('Les modifications de la fiche seront enregistrées.')} <a href="#" data-unpick>${T('Nouveau client')}</a>`;
      results.innerHTML = ''; search.value = '';
    });
    form.addEventListener('click', (e) => {
      if (!e.target.closest('[data-unpick]')) return;
      e.preventDefault(); gid.value = '0';
      $$('[data-g]', form).forEach((el) => { if (el.type === 'checkbox') el.checked = false; else el.value = ''; });
      $('[data-guest-picked]', form).classList.add('d-none');
    });
  }

  // ------------------------------------------------------------------ folio : prestation du catalogue
  const ex = $('[data-extra-select]');
  if (ex) {
    const f = ex.closest('form');
    ex.addEventListener('change', () => {
      const o = ex.selectedOptions[0];
      $('[name=unit_price]', f).value = o && o.dataset.price ? o.dataset.price : '';
      $('[name=label]', f).value = o && o.value ? '' : $('[name=label]', f).value;
      $('[data-free-label]', f).classList.toggle('d-none', !!(o && o.value));
      $('[name=kind]', f).closest('[data-free-kind]').classList.toggle('d-none', !!(o && o.value));
    });
  }

  // ------------------------------------------------------------------ changement de chambre : chambres libres
  const cr = $('[data-change-room]');
  if (cr) {
    (async () => {
      const r = await fetch(cr.dataset.url, { headers: { Accept: 'application/json' } });
      const j = await r.json().catch(() => null);
      if (!j || !j.ok) return;
      const sel = $('select[name=room_id]', cr);
      sel.innerHTML = `<option value="">${T('— choisir —')}</option>` + j.rooms.filter((x) => String(x.id) !== cr.dataset.current)
        .map((x) => `<option value="${x.id}">${esc(x.number)} · ${esc(x.type)} · ${money(x.rate)}${x.hk === 'sale' || x.hk === 'nettoyage' ? ' · ' + T('à nettoyer') : ''}</option>`).join('');
    })();
  }

  // Ancres #arrivee, #depart, #prestation : ouvre et met en évidence le bon bloc
  if (location.hash) {
    const el = document.getElementById('blk-' + location.hash.slice(1));
    if (el) { el.classList.add('ht-flash'); el.scrollIntoView({ block: 'center' }); const i = $('input:not([type=hidden]), select', el); if (i) setTimeout(() => i.focus(), 300); }
  }
})();
