/* Écran cuisine / bar : tickets en temps réel (rafraîchissement toutes les 5 s). */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const BASE = ($('meta[name="base-url"]').content || '/').replace(/\/$/, '');
  const CSRF = $('meta[name="csrf-token"]').content;
  const STATION = document.body.dataset.station;
  const COURSES = { 0: __('Boissons'), 1: __('Entrées'), 2: __('Plats'), 3: __('Desserts') };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const qty = (q) => (Math.round(q * 1000) / 1000).toLocaleString('fr-FR');
  let known = new Set(JSON.parse(sessionStorage.getItem('kds-known-' + STATION) || '[]'));
  let first = known.size === 0;
  let sound = localStorage.getItem('kds-sound') !== '0';
  let tickets = [];
  let fetchedAt = Date.now();

  function beep() {
    if (!sound) return;
    try { const c = beep.c || (beep.c = new (window.AudioContext || window.webkitAudioContext)()); [0, 0.18].forEach((t) => { const o = c.createOscillator(); const g = c.createGain(); o.frequency.value = 880; g.gain.value = 0.15; o.connect(g); g.connect(c.destination); o.start(c.currentTime + t); o.stop(c.currentTime + t + 0.12); }); } catch (e) { /* audio */ }
  }
  // Événements pour les modules (ex. annonce vocale des bons : MARWA VOICE) ; sans module : sans effet
  function emit(event, data) { try { document.dispatchEvent(new CustomEvent('pm:event', { detail: { event, data } })); } catch (e) { /* ignoré */ } }
  function voiceData(t, key) {
    return { key, id: t.id, type: t.type, order_number: t.order_number, order_type: t.order_type, table: t.table_name || '', customer: t.customer_name || '',
      waiter: t.waiter || '', previous: Number(t.previous) || 0,
      items: t.items.filter((i) => t.type === 'cancel' || i.status !== 'cancelled').map((i) => ({ quantity: Number(i.quantity), name: i.name, note: i.note || '', reason: i.cancel_reason || '' })) };
  }
  function age(t) { return t.age + Math.round((Date.now() - fetchedAt) / 1000); }
  function mmss(s) { return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); }
  function render() {
    const showServed = $('#showServed').checked;
    const list = tickets.filter((t) => showServed || t.status !== 'served');
    $('#cPending').textContent = tickets.filter((t) => t.status === 'pending').length;
    $('#cPrep').textContent = tickets.filter((t) => t.status === 'preparing').length;
    $('#cReady').textContent = tickets.filter((t) => t.status === 'ready').length;
    if (!list.length) { $('#board').innerHTML = '<div class="kds-empty"><i class="bi bi-check2-circle"></i> ' + __('Aucun ticket en attente') + '</div>'; return; }
    $('#board').innerHTML = list.map((t) => {
      const a = age(t);
      const cls = t.type === 'cancel' ? 'cancel' : t.status;
      const late = t.status !== 'ready' && t.status !== 'served' ? (a > 1200 ? 'late' : a > 600 ? 'warn' : '') : '';
      let last = null;
      const items = t.items.map((i) => {
        const sep = t.type === 'order' && i.course !== last ? `<li class="course">${esc(COURSES[i.course] || '')}</li>` : '';
        last = i.course;
        return sep + `<li class="${i.status === 'cancelled' ? 'x' : ''}"><span class="q">${qty(i.quantity)}×</span>${esc(i.name)}${i.note ? `<span class="n">» ${esc(i.note)}</span>` : ''}${i.cancel_reason ? `<span class="n">${__('Motif')} : ${esc(i.cancel_reason)}</span>` : ''}</li>`;
      }).join('');
      const where = t.table_name ? __('Table') + ' ' + esc(t.table_name) : (t.order_type === 'delivery' ? __('Livraison') : __('À emporter'));
      let actions = '';
      if (t.type === 'cancel') actions = `<button class="b-serve" data-id="${t.id}" data-s="served">${__('Vu — retirer')}</button>`;
      else if (t.status === 'pending') actions = `<button class="b-start" data-id="${t.id}" data-s="preparing">${__('Commencer')}</button><button class="b-ready" data-id="${t.id}" data-s="ready">${__('Prêt')}</button>`;
      else if (t.status === 'preparing') actions = `<button class="b-back" data-id="${t.id}" data-s="pending" title="Annuler l'étape">↶</button><button class="b-ready" data-id="${t.id}" data-s="ready">${__('Prêt')} ✔</button>`;
      else if (t.status === 'ready') actions = `<button class="b-back" data-id="${t.id}" data-s="preparing" title="Rappeler">↶</button><button class="b-serve" data-id="${t.id}" data-s="served">${__('Servi — retirer')}</button>`;
      else actions = `<button class="b-back w-100" data-id="${t.id}" data-s="ready" style="flex:1!important">${__('Rappeler le ticket')}</button>`;
      return `<article class="kt ${cls} ${late}"><div class="kt-head"><div><div class="kt-table">${t.type === 'cancel' ? '<i class="bi bi-x-octagon"></i> ' + __('ANNULATION') + '<br>' : ''}${where}</div>
        <div class="kt-sub">${window.PMVoice && PMVoice.state().ready ? `<button class="kt-read" data-read="${t.id}" title="${__('Relire à voix haute')}"><i class="bi bi-volume-up"></i></button> ` : ''}${esc(t.number)} · ${esc(t.order_number)} · ${esc(t.waiter)} · ${t.covers} couv.${t.customer_name ? ' · ' + esc(t.customer_name) : ''}</div></div>
        <span class="d-flex gap-1 align-items-start"><button class="kt-print" data-print="${t.id}" title="${__('Imprimer le bon')}"><i class="bi bi-printer"></i></button><span class="kt-time" data-t="${t.id}">${mmss(a)}</span></span></div><ul class="kt-items">${items}</ul><div class="kt-actions">${actions}</div></article>`;
    }).join('');
  }
  async function load() {
    try {
      const r = await fetch(`${BASE}/kitchen/feed?station=${encodeURIComponent(STATION)}`, { headers: { Accept: 'application/json' } });
      if (r.status === 401) { location.href = BASE + '/login'; return; }
      const d = await r.json();
      if (!d.ok) return;
      tickets = d.tickets; fetchedAt = Date.now();
      const fresh = tickets.filter((t) => t.status === 'pending' && !known.has(t.id));
      if (fresh.length && !first) {
        beep();
        // Lecture à voix haute de chaque nouveau bon, dans l'ordre d'arrivée (une seule fois par bon)
        fresh.slice().sort((a, b) => a.id - b.id).forEach((t) => emit(t.type === 'cancel' ? 'kitchen.ticket.cancel' : 'kitchen.ticket.new', voiceData(t, 'kt-' + t.id)));
      }
      first = false;
      tickets.forEach((t) => known.add(t.id));
      sessionStorage.setItem('kds-known-' + STATION, JSON.stringify([...known].slice(-300)));
      render();
    } catch (e) { /* réseau : nouvel essai au prochain cycle */ }
  }
  async function printTicket(id) {
    const r = await fetch(`${BASE}/kitchen/tickets/${id}/print`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ _csrf: CSRF }) });
    const d = await r.json().catch(() => ({}));
    (d.print || []).forEach((u) => { const f = document.createElement('iframe'); f.style.cssText = 'position:fixed;width:0;height:0;border:0'; f.src = u; document.body.appendChild(f); setTimeout(() => f.remove(), 60000); });
    if (d.print_errors && d.print_errors.length) alert(d.print_errors.join('\n'));
  }
  document.addEventListener('click', async (e) => {
    const pr = e.target.closest('[data-print]'); if (pr) { printTicket(pr.dataset.print); return; }
    const rd = e.target.closest('[data-read]');
    if (rd) { const t = tickets.find((x) => x.id === Number(rd.dataset.read)); if (t) emit('kitchen.ticket.repeat', voiceData(t, 'rd-' + t.id + '-' + Date.now())); return; }
    const b = e.target.closest('button[data-s]'); if (!b) return;
    b.disabled = true;
    const r = await fetch(`${BASE}/kitchen/tickets/${b.dataset.id}`, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ _csrf: CSRF, status: b.dataset.s }) }).catch(() => null);
    const ok = r && r.ok && (await r.json().catch(() => ({}))).ok !== false;
    const t = tickets.find((x) => x.id === Number(b.dataset.id));
    if (ok && t && b.dataset.s === 'ready') emit('kitchen.ticket.ready', voiceData(t, 'rdy-' + t.id)); // annoncé seulement si le serveur l'a enregistré
    load();
  });
  $('#showServed').addEventListener('change', render);
  const sb = $('#soundBtn');
  const paintSound = () => { sb.innerHTML = `<i class="bi bi-volume-${sound ? 'up' : 'mute'}"></i>`; };
  sb.addEventListener('click', () => { sound = !sound; localStorage.setItem('kds-sound', sound ? '1' : '0'); paintSound(); if (sound) beep(); });
  paintSound();
  $('#fsBtn').addEventListener('click', () => { if (!document.fullscreenElement) document.documentElement.requestFullscreen().catch(() => {}); else document.exitFullscreen(); });
  // Chaque seconde : seuls les chronomètres sont mis à jour (pas de reconstruction, aucun clic perdu)
  setInterval(() => {
    $('#clock').textContent = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    document.querySelectorAll('.kt-time[data-t]').forEach((el) => { const t = tickets.find((x) => x.id === Number(el.dataset.t)); if (t) el.textContent = mmss(age(t)); });
  }, 1000);
  load();
  setInterval(load, 5000);
})();
