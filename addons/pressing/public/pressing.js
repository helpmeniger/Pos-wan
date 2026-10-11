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

  function service() { const r = $('input[name="cur_service"]:checked'); return r ? r.value : 'lavage'; }

  function addLine(aid) {
    const a = byId[aid]; const svc = service();
    if (!a) return;
    if (a.prices[svc] === undefined) { alert(T('Service non proposé pour cet article.') + ' (' + a.name + ' — ' + D.services[svc] + ')'); return; }
    const same = $$('tr[data-line]', tbody).find((tr) => tr.dataset.article === String(aid) && tr.dataset.service === svc && !$('[data-color]', tr).value && !$('[data-defects]', tr).value);
    if (same) { const q = $('[data-q]', same); q.value = num(q.value) + 1; recalc(); return; }
    const i = idx++;
    const tr = document.createElement('tr');
    tr.dataset.line = '1'; tr.dataset.article = String(aid); tr.dataset.service = svc;
    tr.innerHTML = `<td><input type="hidden" name="lines[${i}][article_id]" value="${aid}"><input type="hidden" name="lines[${i}][service]" value="${esc(svc)}"><b>${esc(a.name)}</b><br><small class="text-secondary">${esc(D.services[svc])}</small></td>
      <td><input type="number" min="1" max="200" name="lines[${i}][quantity]" value="1" class="form-control form-control-sm" data-q></td>
      <td><input name="lines[${i}][unit_price]" value="${a.prices[svc]}" class="form-control form-control-sm text-end" inputmode="numeric" data-p ${D.canPrice ? '' : 'readonly'}></td>
      <td><input name="lines[${i}][color]" class="form-control form-control-sm" maxlength="60" placeholder="${esc(T('ex. bleu'))}" data-color></td>
      <td><input name="lines[${i}][brand]" class="form-control form-control-sm" maxlength="80"></td>
      <td><input name="lines[${i}][defects]" class="form-control form-control-sm" maxlength="255" placeholder="${esc(T('tache, trou…'))}" data-defects></td>
      <td class="num fw-semibold" data-lt></td>
      <td><button type="button" class="btn btn-sm btn-link text-danger" data-del aria-label="${esc(T('Retirer'))}"><i class="bi bi-x-circle"></i></button></td>`;
    const empty = $('tr.empty', tbody); if (empty) empty.remove();
    tbody.appendChild(tr);
    recalc();
  }

  function totals() {
    let sub = 0; let pieces = 0;
    $$('tr[data-line]', tbody).forEach((tr) => {
      const q = Math.max(0, Math.round(num($('[data-q]', tr).value))); const p = num($('[data-p]', tr).value);
      $('[data-lt]', tr).textContent = fmt(q * p); sub += q * p; pieces += q;
    });
    const exp = $('[data-express]').checked ? Math.round(sub * D.expressPct / 100) : 0;
    const disc = Math.min(num($('[data-discount]').value), sub + exp);
    return { sub, exp, pieces, total: Math.max(0, sub + exp - disc) };
  }

  function recalc() {
    const t = totals();
    const set = (k, v) => { const el = $(`[data-t="${k}"]`); if (el) el.textContent = v; };
    set('pieces', t.pieces); set('sub', fmt(t.sub)); set('exp', fmt(t.exp)); set('total', fmt(t.total));
    const paid = num($('[data-pay-amount]').value);
    set('rest', fmt(Math.max(0, t.total - paid)));
    const ch = $('[data-t="change"]');
    ch.classList.toggle('d-none', paid <= t.total);
    ch.textContent = T('Monnaie à rendre') + ' : ' + fmt(paid - t.total);
    $('[data-submit]').disabled = t.pieces === 0;
    form.dataset.total = String(t.total);
  }

  form.addEventListener('click', (e) => {
    const tile = e.target.closest('[data-article]'); if (tile) { addLine(Number(tile.dataset.article)); return; }
    const del = e.target.closest('[data-del]');
    if (del) { del.closest('tr').remove(); if (!$('tr[data-line]', tbody)) tbody.innerHTML = `<tr class="empty"><td colspan="8" class="text-center text-secondary py-3">${esc(T('Aucun article.'))}</td></tr>`; recalc(); }
  });
  form.addEventListener('input', recalc);
  $('[data-express]').addEventListener('change', (e) => {
    const d = new Date(); d.setDate(d.getDate() + (e.target.checked ? D.delaiExpress : D.delai));
    $('[data-due]').value = d.toISOString().slice(0, 10);
    recalc();
  });
  $('[data-pay-full]').addEventListener('click', () => { $('[data-pay-amount]').value = Math.round(totals().total); recalc(); });
  $('[data-pay-half]').addEventListener('click', () => { $('[data-pay-amount]').value = Math.round(totals().total / 2); recalc(); });
  // Client déjà venu : le téléphone se remplit à partir de la liste
  $('[data-client-name]').addEventListener('change', (e) => {
    const o = $$('#pClients option').find((x) => x.value === e.target.value);
    if (o && !$('[data-client-phone]').value) $('[data-client-phone]').value = o.dataset.phone;
  });
  form.addEventListener('submit', () => { $('[data-submit]').disabled = true; });
  recalc();
})();
