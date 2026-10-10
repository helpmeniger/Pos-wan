/* Encaissement restaurant : sélection des articles (addition séparée), remise, monnaie. */
(function () {
  'use strict';
  const { $, $$ } = window.PW;
  const form = $('#payForm'); if (!form) return;
  const num = (v) => { const n = parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };
  const fmt = (n) => PWMoney.fmt(n);
  let toPay = 0;
  function calc() {
    let sel = 0;
    $$('tr[data-price]').forEach((tr) => {
      const q = Math.min(num($('[data-q]', tr).value), num($('[data-q]', tr).dataset.max));
      const v = q * num(tr.dataset.price); sel += v; $('[data-line]', tr).textContent = fmt(v);
    });
    const dv = num($('[data-disc]').value);
    const disc = form.discount_type.value === 'percent' ? sel * Math.min(dv, 100) / 100 : Math.min(dv, sel);
    toPay = Math.round((sel - disc) * 100) / 100;
    $('#selTotal').textContent = fmt(sel); $('#toPay').textContent = fmt(toPay);
    const rec = $$('[data-amount]').reduce((a, i) => a + num(i.value), 0);
    $('#received').textContent = fmt(rec);
    $('#change').textContent = fmt(Math.max(0, rec - toPay));
    $('#rest').textContent = fmt(Math.max(0, toPay - rec)); $('#restRow').classList.toggle('d-none', rec <= 0 || rec >= toPay);
    const opts = new Set([toPay]); [500, 1000, 2000, 5000, 10000].forEach((b) => { if (toPay > 0) opts.add(Math.ceil(toPay / b) * b); });
    $('#quick').innerHTML = [...opts].filter((x) => x > 0).sort((a, b) => a - b).slice(0, 6).map((v) => `<button type="button" class="btn btn-sm btn-outline-success" data-qc="${v}">${fmt(v)}</button>`).join('');
  }
  form.addEventListener('input', calc);
  form.addEventListener('change', calc);
  form.addEventListener('click', (e) => {
    const s = e.target.closest('[data-step]');
    if (s) { const inp = $('[data-q]', s.closest('td')); inp.value = Math.max(0, Math.min(num(inp.dataset.max), num(inp.value) + Number(s.dataset.step))); calc(); }
    const a = e.target.closest('[data-select]');
    if (a) { $$('[data-q]').forEach((i) => { i.value = a.dataset.select === 'all' ? i.dataset.max : 0; }); calc(); }
    const q = e.target.closest('[data-qc]');
    if (q) { $$('[data-amount]').forEach((i, n) => { i.value = n === 0 ? q.dataset.qc : ''; }); calc(); }
    if (e.target.closest('[data-split]')) {
      const parts = parseInt(prompt('Diviser le reste en combien de parts égales ?', '2'), 10);
      if (parts > 1) { $$('[data-amount]').forEach((i, n) => { i.value = n === 0 ? Math.ceil(toPay / parts) : ''; }); calc(); }
    }
  });
  form.addEventListener('submit', () => { if (!$$('[data-amount]').some((i) => num(i.value) > 0)) $('[data-amount]').value = toPay; });
  calc();
})();
