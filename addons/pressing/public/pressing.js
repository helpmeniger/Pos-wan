/* Module Pressing : saisie d'un dépôt (lignes, totaux) et impression automatique du ticket. */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const fmt = (n) => (window.PWMoney ? PWMoney.fmt(n) : Math.round(n) + ' F');
  const num = (v) => { const n = parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };
  const T = (s) => (window.__ ? __(s) : s);

  // ---------------------------------------------------------------- impression automatique (fiche du dépôt)
  const ap = $('[data-autoprint-url]');
  if (ap) {
    const f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0';
    f.src = ap.dataset.autoprintUrl;
    document.body.appendChild(f);
  }
  $$('[data-fill-amount]').forEach((b) => b.addEventListener('click', () => {
    const inp = b.closest('form').querySelector('[data-pay-amount]');
    if (inp) { inp.value = b.dataset.fillAmount; inp.focus(); }
  }));

  // ---------------------------------------------------------------- formulaire de dépôt
  const form = $('#pressingForm');
  if (!form) return;
  const D = JSON.parse($('#pressing-data').textContent);
  const byId = {};
  D.tarifs.forEach((a) => { byId[a.id] = a; });
  const tbody = $('#pLines');
  let idx = 0;
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // Unités mesurées (quantité avec virgule, une étiquette par ligne) : poids et surface
  const MEASURE = { kg: { sym: 'kg', hint: T('Poids en kg, ex. 4,5'), per: T('par kg') }, m2: { sym: 'm²', hint: T('Surface en m², ex. 6,25'), per: T('par m²') } };

  function service() { const r = $('input[name="cur_service"]:checked'); return r ? r.value : 'lavage'; }

  function addLine(aid) {
    const a = byId[aid]; const svc = service();
    if (!a) return;
    if (a.prices[svc] === undefined) { alert(T('Service non proposé pour cet article.') + ' (' + a.name + ' — ' + D.services[svc] + ')'); return; }
    const m = MEASURE[a.unit];
    const kg = !!m;
    const same = $$('tr[data-line]', tbody).find((tr) => tr.dataset.article === String(aid) && tr.dataset.service === svc && !$('[data-color]', tr).value && !$('[data-defects]', tr).value);
    // Article au kilo ou au m² : un nouveau toucher ramène à la saisie (pas d'ajout d'1 kg)
    if (same && kg) { const q = $('[data-q]', same); q.focus(); q.select(); return; }
    if (same) { const q = $('[data-q]', same); q.value = num(q.value) + 1; recalc(); return; }
    const i = idx++;
    const tr = document.createElement('tr');
    tr.dataset.line = '1'; tr.dataset.article = String(aid); tr.dataset.service = svc; tr.dataset.unit = kg ? a.unit : 'piece';
    tr.innerHTML = `<td><input type="hidden" name="lines[${i}][article_id]" value="${aid}"><input type="hidden" name="lines[${i}][service]" value="${esc(svc)}"><b>${esc(a.name)}</b><br><small class="text-secondary">${esc(D.services[svc])}</small></td>
      <td>${kg
        ? `<div class="input-group input-group-sm"><input name="lines[${i}][quantity]" value="" inputmode="decimal" autocomplete="off" title="${esc(m.hint)}" placeholder="${a.unit === 'm2' ? '6,25' : '4,5'}" class="form-control text-end" data-q><span class="input-group-text">${esc(m.sym)}</span></div>`
        : `<input name="lines[${i}][quantity]" value="1" inputmode="decimal" autocomplete="off" class="form-control form-control-sm text-end" data-q>`}
        <div class="small text-danger d-none" data-qmsg></div></td>
      <td><input name="lines[${i}][unit_price]" value="${a.prices[svc]}" class="form-control form-control-sm text-end" inputmode="numeric" data-p ${D.canPrice ? '' : 'readonly'}>${kg ? `<small class="text-secondary">${esc(m.per)}</small>` : ''}</td>
      <td><input name="lines[${i}][color]" class="form-control form-control-sm" maxlength="60" placeholder="${esc(T('ex. bleu'))}" data-color></td>
      <td><input name="lines[${i}][brand]" class="form-control form-control-sm" maxlength="80"></td>
      <td><input name="lines[${i}][defects]" class="form-control form-control-sm" maxlength="255" placeholder="${esc(T('tache, trou…'))}" data-defects></td>
      <td class="num fw-semibold" data-lt></td>
      <td><button type="button" class="btn btn-sm btn-link text-danger" data-del aria-label="${esc(T('Retirer'))}"><i class="bi bi-x-circle"></i></button></td>`;
    const empty = $('tr.empty', tbody); if (empty) empty.remove();
    tbody.appendChild(tr);
    recalc();
    if (kg) { const q = $('[data-q]', tr); q.focus(); }
  }

  // Pourcentage express : un des choix proposés (boutons) ; saisie libre pour le responsable
  function expressPct() {
    const inp = $('[data-express-pct]');
    const v = inp ? num(inp.value) : D.expressPct;
    return v >= 0 && v <= 300 ? v : D.expressPct;
  }
  function markPct() {
    const v = expressPct();
    $$('[data-exp-pct]').forEach((b) => { const on = Math.abs(num(b.dataset.expPct) - v) < 0.001; b.classList.toggle('btn-danger', on); b.classList.toggle('btn-outline-danger', !on); });
  }

  function totals() {
    let sub = 0; let pieces = 0; let weight = 0; let area = 0; let missing = 0;
    $$('tr[data-line]', tbody).forEach((tr) => {
      const m = MEASURE[tr.dataset.unit];
      const kg = !!m;
      // Champ texte (jamais de virgule avalée par le navigateur) : au kilo 4,5 / 4.5 / ,5 ;
      // à la pièce, nombre entier — une virgule est signalée au lieu d'être ignorée.
      const inp = $('[data-q]', tr); const raw = inp.value.replace(/\s/g, '');
      const isNum = /^\d*(?:[.,]\d*)?$/.test(raw) && /\d/.test(raw);
      const v = isNum ? num(raw) : 0;
      let err = '';
      if (kg) { if (raw !== '' && (!isNum || v <= 0)) err = m.hint; else if (v > 1000) err = T('1 000 au plus.') + ' (' + m.sym + ')'; }
      else if (!isNum || v < 1) err = raw === '' ? T('Quantité obligatoire.') : T('Nombre de pièces invalide.');
      else if (Math.round(v) !== v) err = T('« {a} » se compte à la pièce (nombre entier). Pour le peser ou le mesurer, réglez son unité sur « Au kilo » ou « Au m² » dans Pressing → Tarifs.').replace('{a}', byId[tr.dataset.article] ? byId[tr.dataset.article].name : '');
      else if (v > 200) err = T('200 pièces au plus.');
      inp.classList.toggle('is-invalid', !!err);
      const msg = $('[data-qmsg]', tr); msg.textContent = err; msg.classList.toggle('d-none', !err);
      const q = err ? 0 : (kg ? Math.round(v * 1000) / 1000 : v);
      const p = num($('[data-p]', tr).value);
      $('[data-lt]', tr).textContent = fmt(q * p); sub += q * p;
      if (err || (kg && q <= 0)) missing++;
      if (kg) { if (tr.dataset.unit === 'm2') area += q; else weight += q; if (q > 0) pieces += 1; } else pieces += q;
    });
    const pct = expressPct();
    const exp = $('[data-express]').checked ? Math.round(sub * pct / 100) : 0;
    const liv = $('[data-delivery]') && $('[data-delivery]').checked ? Math.max(0, num($('[data-delivery-fee]').value)) : 0;
    const noAddr = !!($('[data-delivery]') && $('[data-delivery]').checked && $('[data-delivery-address]').value.trim().length < 3);
    if (noAddr) missing++; // adresse obligatoire pour une livraison
    if ($('[data-delivery-address]')) $('[data-delivery-address]').classList.toggle('is-invalid', noAddr);
    const disc = Math.min(num($('[data-discount]').value), sub + exp + liv);
    return { sub, exp, pct, liv, pieces, weight: Math.round(weight * 1000) / 1000, area: Math.round(area * 1000) / 1000, missing, total: Math.max(0, sub + exp + liv - disc) };
  }

  function recalc() {
    const t = totals();
    const set = (k, v) => { const el = $(`[data-t="${k}"]`); if (el) el.textContent = v; };
    set('exp-pct', $('[data-express]').checked ? '(' + String(t.pct).replace('.', ',') + ' %)' : '');
    set('liv', fmt(t.liv)); const lr = $('[data-liv-row]'); if (lr) lr.classList.toggle('d-none', !(t.liv > 0));
    markPct();
    set('pieces', t.pieces); set('weight', String(t.weight).replace('.', ',') + ' kg');
    set('area', String(t.area).replace('.', ',') + ' m²');
    const hasM2 = $$('tr[data-line]', tbody).some((tr) => tr.dataset.unit === 'm2');
    const hasKg = $$('tr[data-line]', tbody).some((tr) => tr.dataset.unit === 'kg');
    const wr = $('[data-weight-row]'); if (wr) wr.classList.toggle('d-none', !(t.weight > 0 || hasKg));
    const ar = $('[data-area-row]'); if (ar) ar.classList.toggle('d-none', !(t.area > 0 || hasM2)); set('sub', fmt(t.sub)); set('exp', fmt(t.exp)); set('total', fmt(t.total));
    const paid = num($('[data-pay-amount]').value);
    set('rest', fmt(Math.max(0, t.total - paid)));
    const ch = $('[data-t="change"]');
    ch.classList.toggle('d-none', paid <= t.total);
    ch.textContent = T('Monnaie à rendre') + ' : ' + fmt(paid - t.total);
    $('[data-submit]').disabled = t.pieces === 0 || t.missing > 0; // poids à saisir, quantités à corriger
    form.dataset.total = String(t.total);
  }

  form.addEventListener('click', (e) => {
    const tile = e.target.closest('.pressing-tile[data-article]'); if (tile) { addLine(Number(tile.dataset.article)); return; }
    const del = e.target.closest('[data-del]');
    if (del) { del.closest('tr').remove(); if (!$('tr[data-line]', tbody)) tbody.innerHTML = `<tr class="empty"><td colspan="8" class="text-center text-secondary py-3">${esc(T('Aucun article.'))}</td></tr>`; recalc(); }
  });
  form.addEventListener('input', recalc);
  form.addEventListener('click', (e) => {
    const b = e.target.closest('[data-exp-pct]'); if (!b) return;
    $('[data-express-pct]').value = b.dataset.expPct.replace('.', ',');
    recalc();
  });
  const dl = $('[data-delivery]');
  if (dl) dl.addEventListener('change', () => {
    $('[data-delivery-box]').classList.toggle('d-none', !dl.checked);
    if (dl.checked) $('[data-delivery-address]').focus();
    recalc();
  });
  $('[data-express]').addEventListener('change', (e) => {
    $('[data-express-box]').classList.toggle('d-none', !e.target.checked);
    const d = new Date(); d.setDate(d.getDate() + (e.target.checked ? D.delaiExpress : D.delai));
    $('[data-due]').value = d.toISOString().slice(0, 10);
    recalc();
  });
  $('[data-pay-full]').addEventListener('click', () => { $('[data-pay-amount]').value = Math.round(totals().total); recalc(); });
  $('[data-pay-half]').addEventListener('click', () => { $('[data-pay-amount]').value = Math.round(totals().total / 2); recalc(); });
  // Client déjà venu : le téléphone se remplit à partir de la liste. Un numéro rempli ainsi est
  // sélectionné quand on clique dans le champ : ce que l'on tape le remplace (jamais de doublon),
  // et il suit le client si l'on en choisit un autre. Un numéro tapé à la main n'est jamais touché.
  const nameEl = $('[data-client-name]');
  const phoneEl = $('[data-client-phone]');
  let autoPhone = '';
  let selectOnClick = false;
  const fillPhone = () => {
    const o = $$('#pClients option').find((x) => x.value === nameEl.value.trim());
    const addr = $('[data-delivery-address]');
    if (o && o.dataset.address && addr && addr.value.trim() === '') addr.value = o.dataset.address; // adresse connue du client
    if (!o || !(phoneEl.value === '' || phoneEl.value === autoPhone)) return;
    phoneEl.value = o.dataset.phone;
    autoPhone = o.dataset.phone;
  };
  nameEl.addEventListener('input', fillPhone);
  nameEl.addEventListener('change', fillPhone);
  phoneEl.addEventListener('focus', () => {
    if (!autoPhone || phoneEl.value !== autoPhone) return;
    phoneEl.select();
    selectOnClick = true;
  });
  phoneEl.addEventListener('mouseup', (e) => { if (selectOnClick) { e.preventDefault(); phoneEl.select(); selectOnClick = false; } });
  phoneEl.addEventListener('input', () => { autoPhone = ''; selectOnClick = false; });
  form.addEventListener('submit', () => { $('[data-submit]').disabled = true; });
  recalc();
})();
