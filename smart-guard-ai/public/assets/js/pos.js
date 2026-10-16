/* Caisse simplifiée */
(function () {
  'use strict';
  const cart = new Map();
  const $ = (id) => document.getElementById(id);
  const fmt = (n) => Math.round(n).toLocaleString('fr-FR') + ' FCFA';
  let timer = null;

  function render() {
    let total = 0;
    $('cart').innerHTML = [...cart.values()].map((it) => {
      const lt = it.price * it.qty; total += lt;
      return `<tr><td>${sgEsc(it.name)}</td><td><input type="number" min="0.001" step="1" value="${it.qty}" data-id="${it.id}" class="form-control form-control-sm qty"></td>
        <td class="text-end">${fmt(lt)}</td><td><button class="btn btn-sm btn-link text-danger p-0" data-del="${it.id}"><i class="bi bi-x-lg"></i></button></td></tr>`;
    }).join('') || '<tr><td colspan="4" class="text-muted">Panier vide</td></tr>';
    $('cartTotal').textContent = fmt(total);
    $('btnPay').disabled = cart.size === 0;
    const paid = parseFloat($('payAmount').value);
    $('change').textContent = paid > 0 ? (paid >= total ? 'Monnaie à rendre : ' + fmt(paid - total) : 'Montant insuffisant') : '';
  }
  function add(p) {
    const it = cart.get(p.id) || { id: p.id, name: p.name, price: +p.sale_price, qty: 0 };
    it.qty += 1; cart.set(p.id, it); render();
    $('posSearch').value = ''; $('posResults').innerHTML = ''; $('posSearch').focus();
  }
  async function search(q, exact) {
    if (!q) { $('posResults').innerHTML = ''; return; }
    const d = await sgFetch('products/search?q=' + encodeURIComponent(q));
    if (exact && d.items.length && d.items[0].barcode === q) { add(d.items[0]); return; }
    $('posResults').innerHTML = d.items.map((p, i) => `<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between" data-i="${i}">
      <span>${sgEsc(p.name)} <small class="text-muted">${sgEsc(p.barcode || '')}</small></span><span>${fmt(+p.sale_price)}</span></button>`).join('');
    $('posResults').querySelectorAll('[data-i]').forEach((b) => b.addEventListener('click', () => add(d.items[+b.dataset.i])));
  }
  $('posSearch').addEventListener('input', (e) => { clearTimeout(timer); timer = setTimeout(() => search(e.target.value.trim(), false), 250); });
  $('posSearch').addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); search(e.target.value.trim(), true); } });
  $('cart').addEventListener('change', (e) => {
    if (!e.target.classList.contains('qty')) return;
    const it = cart.get(+e.target.dataset.id); const v = parseFloat(e.target.value);
    if (v > 0) it.qty = v; else cart.delete(it.id);
    render();
  });
  $('cart').addEventListener('click', (e) => { const b = e.target.closest('[data-del]'); if (b) { cart.delete(+b.dataset.del); render(); } });
  $('payAmount').addEventListener('input', render);
  $('btnPay').addEventListener('click', async () => {
    $('btnPay').disabled = true;
    try {
      const d = await sgFetch('pos/sales', { json: { items: [...cart.values()].map((i) => ({ product_id: i.id, quantity: i.qty })), payment_method: $('payMethod').value, amount_paid: $('payAmount').value || null } });
      $('posMsg').innerHTML = `<span class="text-success">Vente ${sgEsc(d.sale.receipt)} enregistrée.</span> <a href="${d.url}">Voir le ticket</a>`;
      cart.clear(); $('payAmount').value = ''; render();
    } catch (e) { $('posMsg').innerHTML = `<span class="text-danger">${sgEsc(e.message)}</span>`; $('btnPay').disabled = false; }
  });
  render();
})();
