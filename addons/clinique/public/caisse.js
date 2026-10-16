/* Caisse clinique : patient, prestations, prise en charge, encaissement (complet ou partiel). */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const T = (s) => (window.__ ? __(s) : s);
  const BASE = (window.PW && PW.BASE) || '';
  const CSRF = $('meta[name="csrf-token"]').content;
  const fmt = (n) => (window.PWMoney ? PWMoney.fmt(n) : Math.round(n) + ' F');
  const num = (v) => { const n = parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const dataEl = $('#caisse-data');
  if (!dataEl) return;
  const ACTS = {};
  JSON.parse(dataEl.textContent).acts.forEach((a) => { ACTS[a.id] = a; });
  let patient = null;
  let lines = [];
  let busy = false;
  let amountTouched = false;

  async function api(path, opt = {}) {
    const o = { headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF }, method: opt.method || 'GET' };
    if (opt.body) { o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(Object.assign({ _csrf: CSRF }, opt.body)); }
    const r = await fetch(BASE + path, o);
    if (r.status === 401) { location.href = BASE + '/login'; return { ok: false }; }
    return r.json().catch(() => ({ ok: false, error: T('Réponse invalide du serveur.') }));
  }

  // ------------------------------------------------------------ patient
  let timer = null;
  $('#patSearch').addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      const q = $('#patSearch').value.trim();
      if (q.length < 2) { $('#patResults').innerHTML = ''; return; }
      const d = await api('/clinique/caisse/patients?q=' + encodeURIComponent(q));
      $('#patResults').innerHTML = (d.items || []).map((p, i) => `<button type="button" class="list-group-item list-group-item-action" data-pi="${i}"><b>${esc(p.name)}</b> <span class="text-secondary">${esc(p.number)} · ${esc(p.sex)}${p.age ? ' · ' + esc(p.age) : ''}${p.phone ? ' · ' + esc(p.phone) : ''}</span></button>`).join('')
        || `<div class="list-group-item text-secondary">${T('Aucun patient')}</div>`;
      $('#patResults').onclick = (e) => { const b = e.target.closest('[data-pi]'); if (b) choose(d.items[+b.dataset.pi]); };
    }, 250);
  });
  function choose(p) {
    patient = p;
    $('#patResults').innerHTML = ''; $('#patSearch').value = '';
    $('#patCard').classList.remove('d-none');
    $('#patCard').innerHTML = `<div class="border rounded p-2"><b>${esc(p.name)}</b> <span class="small text-secondary">${esc(p.number)}</span>
      <div class="small">${esc(p.sex)}${p.age ? ' · ' + esc(p.age) : ''}${p.phone ? ' · ' + esc(p.phone) : ''}</div>
      ${p.coverage > 0 ? `<div class="small text-success">${T('Prise en charge')} ${esc(p.insurer || '')} : ${p.coverage} %</div>` : ''}
      ${p.allergies ? `<div class="small text-danger"><i class="bi bi-exclamation-octagon"></i> ${esc(p.allergies)}</div>` : ''}
      <button type="button" class="btn btn-sm btn-link px-0" id="patChange">${T('Changer de patient')}</button></div>`;
    $('#patChange').onclick = () => { patient = null; $('#patCard').classList.add('d-none'); $('#patSearch').focus(); render(); };
    render();
  }
  $('#patNewToggle').addEventListener('click', () => $('#patNew').classList.toggle('d-none'));
  $('#patCreate').addEventListener('click', async () => {
    const body = {};
    $$('[data-np]').forEach((el) => { body[el.dataset.np] = el.value; });
    const d = await api('/clinique/caisse/patients', { method: 'POST', body });
    if (!d.ok) { alert(d.error || T('Erreur')); return; }
    $$('[data-np]').forEach((el) => { el.value = ''; });
    $('#patNew').classList.add('d-none');
    choose(d.patient);
  });

  // ------------------------------------------------------------ prestations
  $('.cl-acts').addEventListener('click', (e) => {
    const t = e.target.closest('[data-act]');
    if (!t) return;
    const id = +t.dataset.act;
    const l = lines.find((x) => x.act_id === id);
    if (l) l.quantity += 1; else lines.push({ act_id: id, quantity: 1 });
    render();
  });
  $('#actFilter').addEventListener('input', () => {
    const q = $('#actFilter').value.trim().toLowerCase();
    $$('.cl-tile').forEach((b) => { b.style.display = !q || b.dataset.name.includes(q) ? '' : 'none'; });
  });
  $('#clLines').addEventListener('input', (e) => {
    const i = e.target.dataset.q;
    if (i !== undefined) { lines[+i].quantity = Math.max(0, num(e.target.value)); render(false); }
  });
  $('#clLines').addEventListener('click', (e) => {
    const b = e.target.closest('[data-del]');
    if (b) { lines.splice(+b.dataset.del, 1); render(); }
  });
  function totals() {
    const total = lines.reduce((s, l) => s + l.quantity * ACTS[l.act_id].price, 0);
    const cov = patient && patient.coverage > 0 ? Math.round(total * patient.coverage) / 100 : 0;
    return { total, cov, due: Math.round((total - cov) * 100) / 100 };
  }
  function render(full = true) {
    lines = lines.filter((l) => full ? l.quantity > 0 : true);
    if (full) {
      $('#clLines').innerHTML = lines.length ? lines.map((l, i) => `<li class="list-group-item d-flex align-items-center gap-2 cl-line"><span class="flex-grow-1">${esc(ACTS[l.act_id].name)}<br><span class="text-secondary">${fmt(ACTS[l.act_id].price)}</span></span>
        <input class="form-control form-control-sm text-end" data-q="${i}" value="${l.quantity}" inputmode="numeric"><b>${fmt(l.quantity * ACTS[l.act_id].price)}</b>
        <button type="button" class="btn btn-sm btn-outline-danger" data-del="${i}" title="${T('Retirer')}"><i class="bi bi-x"></i></button></li>`).join('')
        : `<li class="list-group-item text-secondary">${T('Touchez une prestation pour l\'ajouter.')}</li>`;
    }
    const t = totals();
    $('#clTotal').textContent = fmt(t.total);
    $('#clCov').textContent = t.cov ? '− ' + fmt(t.cov) : fmt(0);
    $('#clCovLabel').textContent = T('Prise en charge') + (patient && patient.coverage ? ' (' + (patient.insurer || '') + ' ' + patient.coverage + ' %)' : '');
    $('#clDue').textContent = fmt(t.due);
    const amt = $('[data-pay-amount]');
    if (amt && !amountTouched) amt.value = t.due > 0 ? t.due : '';
    $('#clCheckout').disabled = busy || !patient || !lines.length;
  }
  const amt = $('[data-pay-amount]');
  if (amt) amt.addEventListener('input', () => { amountTouched = true; });
  $('#clQueue').addEventListener('change', () => $('#clQueueBox').classList.toggle('d-none', !$('#clQueue').checked));

  // ------------------------------------------------------------ encaissement
  $('#clCheckout').addEventListener('click', async () => {
    if (busy || !patient || !lines.length) return;
    busy = true; render(false);
    const msg = $('#clMsg');
    msg.className = 'small mt-2 text-secondary'; msg.textContent = T('Enregistrement…');
    const d = await api('/clinique/caisse', { method: 'POST', body: {
      patient_id: patient.id, lines, queue: $('#clQueue').checked, practitioner_id: $('#clDoctor').value, priority: $('#clPriority').value, reason: $('#clReason').value,
      pay: { method: $('[name="pay_method"]').value, amount: $('[data-pay-amount]').value, reference: $('[name="pay_reference"]').value },
    } });
    busy = false;
    if (!d.ok) { msg.className = 'small mt-2 text-danger fw-semibold'; msg.textContent = d.error || T('Erreur'); render(false); return; }
    msg.className = 'small mt-2 text-success fw-semibold';
    msg.innerHTML = `${T('Facture')} <a href="${esc(d.bill_url)}">${esc(d.number)}</a> : ${T('payé')} ${fmt(d.paid)}${d.due > 0 ? ' · ' + T('reste') + ' ' + fmt(d.due) : ''}${d.change > 0 ? ' · ' + T('monnaie à rendre') + ' <b>' + fmt(d.change) + '</b>' : ''}${d.visit_id ? ' · ' + T('patient en file d\'attente') : ''}`;
    if (d.paid > 0) { const f = document.createElement('iframe'); f.style.cssText = 'position:fixed;width:0;height:0;border:0'; f.src = d.ticket_url; document.body.appendChild(f); }
    lines = []; patient = null; amountTouched = false;
    $('#patCard').classList.add('d-none'); $('#clQueue').checked = false; $('#clQueueBox').classList.add('d-none'); $('#clReason').value = '';
    render();
    $('#patSearch').focus();
  });
  render();
})();
