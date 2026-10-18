/* Étiquettes : sélection des articles (recherche, catégorie, scan), réglages, modèles, aperçu en direct. */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const form = $('#labelForm');
  if (!form) return;
  const T = (s) => (window.__ ? __(s) : s);
  const BASE = (window.PW && PW.BASE) || '';
  const CSRF = $('meta[name="csrf-token"]').content;
  const D = JSON.parse($('#labels-data').textContent);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => (window.PWMoney ? PWMoney.fmt(n) : Math.round(n) + ' F');
  const STORE = 'pm-labels-selection';
  let sel = [];
  try { sel = D.preselected.length ? D.preselected : JSON.parse(localStorage.getItem(STORE) || '[]'); } catch (e) { sel = D.preselected || []; }
  let templates = D.templates || [];
  let currentTpl = null;

  async function api(path, body) {
    const o = { headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF } };
    if (body) { o.method = 'POST'; o.headers['Content-Type'] = 'application/json'; o.body = JSON.stringify(Object.assign({ _csrf: CSRF }, body)); }
    const r = await fetch(BASE + path, o);
    return r.json().catch(() => ({ ok: false, error: T('Réponse invalide du serveur.') }));
  }

  // ------------------------------------------------------------ sélection
  function add(p, qty = 1) {
    const ex = sel.find((x) => x.id === p.id);
    if (ex) ex.qty = Math.min(500, ex.qty + qty); else sel.push({ id: p.id, name: p.name, reference: p.reference, barcode: p.barcode, price: p.price, qty });
    renderList();
  }
  function renderList() {
    sel = sel.filter((x) => x.qty > 0);
    try { localStorage.setItem(STORE, JSON.stringify(sel.slice(0, 1000))); } catch (e) { /* */ }
    const total = sel.reduce((s, x) => s + x.qty, 0);
    $('#lblCount').textContent = `${sel.length} ${T('article(s)')} · ${total} ${T('étiquette(s)')}`;
    $('#lblList').innerHTML = sel.length ? sel.map((x, i) => `<div class="lbl-row"><span class="flex-grow-1"><b>${esc(x.name)}</b><br><span class="text-secondary">${esc(x.barcode || x.reference || '')} · ${fmt(x.price)}</span>${x.barcode ? '' : ' <span class="badge bg-warning text-dark" title="' + esc(T('Sans code-barres : la référence sera imprimée')) + '">réf.</span>'}</span>
      <input type="number" min="0" max="500" value="${x.qty}" class="form-control form-control-sm" data-q="${i}"><button type="button" class="btn btn-sm btn-outline-danger" data-del="${i}">×</button></div>`).join('')
      : `<div class="text-secondary small">${T('Aucun article sélectionné.')}</div>`;
    $('#lblHidden').innerHTML = sel.map((x) => `<input type="hidden" name="items[${x.id}]" value="${x.qty}">`).join('');
    preview();
  }
  $('#lblList').addEventListener('input', (e) => { const i = e.target.dataset.q; if (i !== undefined) { sel[+i].qty = Math.max(0, Math.min(500, parseInt(e.target.value, 10) || 0)); $('#lblHidden').innerHTML = sel.map((x) => `<input type="hidden" name="items[${x.id}]" value="${x.qty}">`).join(''); preview(); } });
  $('#lblList').addEventListener('change', (e) => { if (e.target.dataset.q !== undefined) renderList(); });
  $('#lblList').addEventListener('click', (e) => { const b = e.target.closest('[data-del]'); if (b) { sel.splice(+b.dataset.del, 1); renderList(); } });
  $('#lblClear').addEventListener('click', () => { if (confirm(T('Vider la sélection ?'))) { sel = []; renderList(); } });
  $('#lblApplyAll').addEventListener('click', () => { const q = Math.max(1, Math.min(500, parseInt($('#lblAllQty').value, 10) || 1)); sel.forEach((x) => { x.qty = q; }); renderList(); });

  let st = null;
  const search = $('#lblSearch');
  search.addEventListener('input', () => {
    clearTimeout(st);
    st = setTimeout(async () => {
      const q = search.value.trim();
      if (q.length < 2) { $('#lblResults').innerHTML = ''; return; }
      const d = await api('/products/labels/search?q=' + encodeURIComponent(q));
      $('#lblResults').innerHTML = (d.items || []).map((p, i) => `<button type="button" class="list-group-item list-group-item-action" data-r="${i}">${esc(p.name)} <span class="text-secondary">${esc(p.barcode || p.reference || '')} · ${fmt(p.price)}</span></button>`).join('');
      $('#lblResults').onclick = (e) => { const b = e.target.closest('[data-r]'); if (b) { add(d.items[+b.dataset.r]); search.value = ''; $('#lblResults').innerHTML = ''; search.focus(); } };
    }, 250);
  });
  async function byCode(code) {
    const d = await api('/products/labels/search?code=' + encodeURIComponent(code));
    if (!d.ok || !d.items.length) return { ok: false, text: T('Aucun article pour ce code') };
    add(d.items[0]);
    const x = sel.find((y) => y.id === d.items[0].id);
    return { ok: true, html: `<b>${esc(d.items[0].name)}</b> · ${fmt(d.items[0].price)} · ${x.qty} ${T('étiquette(s)')}` };
  }
  // Douchette : saisie du code puis Entrée dans le champ de recherche
  search.addEventListener('keydown', async (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const v = search.value.trim();
    if (!v) return;
    const r = await byCode(v);
    if (r.ok) { search.value = ''; $('#lblResults').innerHTML = ''; }
  });
  $('#lblCamera').addEventListener('click', () => window.PMScanner && PMScanner.open({ title: T('Ajouter des articles aux étiquettes'), products: true, onCode: byCode }));
  $('#lblAddCat').addEventListener('click', async () => {
    const c = $('#lblCategory').value;
    if (!c) return;
    const d = await api('/products/labels/search?category=' + c);
    (d.items || []).forEach((p) => add(p, 1));
    if (!(d.items || []).length) alert(T('Aucun article dans cette catégorie.'));
  });

  // ------------------------------------------------------------ réglages
  function getCfg() {
    const fd = new FormData(form);
    const c = { fields: fd.getAll('cfg[fields][]'), border: fd.get('cfg[border]') === '1' };
    ['paper', 'width', 'height', 'cols', 'margin_top', 'margin_left', 'gap_x', 'gap_y', 'barcode_height', 'font'].forEach((k) => { c[k] = fd.get(`cfg[${k}]`); });
    return c;
  }
  function setCfg(c) {
    Object.entries(c || {}).forEach(([k, v]) => {
      if (k === 'fields') { $$('[name="cfg[fields][]"]').forEach((cb) => { cb.checked = v.includes(cb.value); }); return; }
      if (k === 'border') { $('#cfgBorder').checked = !!v; return; }
      const el = form.querySelector(`[name="cfg[${k}]"]`); if (el) el.value = v;
    });
    paperUi();
  }
  function paperUi() { const roll = $('#cfgPaper').value === 'roll'; $$('.a4-only').forEach((el) => el.classList.toggle('d-none', roll)); }
  $$('[data-preset]').forEach((b) => b.addEventListener('click', () => { const f = b.dataset.preset.split(','); $$('[name="cfg[fields][]"]').forEach((cb) => { cb.checked = f.includes(cb.value); }); preview(); }));
  form.addEventListener('change', (e) => { if (e.target.matches('[data-cfg]')) { if (e.target.id === 'cfgPaper') paperUi(); preview(); } });
  form.addEventListener('input', (e) => { if (e.target.matches('input[data-cfg]')) preview(); });

  function fillTemplates() {
    $('#tplSelect').innerHTML = `<option value="">${T('Charger un modèle…')}</option>` + templates.map((t) => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
  }
  $('#tplSelect').addEventListener('change', () => {
    const t = templates.find((x) => String(x.id) === $('#tplSelect').value);
    currentTpl = t || null;
    if (t) { setCfg(t.config); if ($('#tplName')) $('#tplName').value = t.name; preview(); }
  });
  const ts = $('#tplSave');
  if (ts) ts.addEventListener('click', async () => {
    const name = $('#tplName').value.trim();
    if (!name) { $('#tplName').focus(); return; }
    const d = await api('/products/labels/templates', { name, cfg: getCfg(), default: $('#tplDefault').checked });
    if (!d.ok) { alert(d.error); return; }
    templates = templates.filter((t) => t.id !== d.id).concat([{ id: d.id, name: d.name, config: d.config }]).sort((a, b) => a.name.localeCompare(b.name));
    fillTemplates(); $('#tplSelect').value = d.id; currentTpl = templates.find((t) => t.id === d.id);
  });
  const td = $('#tplDelete');
  if (td) td.addEventListener('click', async () => {
    if (!currentTpl || !confirm(T('Supprimer le modèle') + ' « ' + currentTpl.name + ' » ?')) return;
    const d = await api('/products/labels/templates/' + currentTpl.id + '/delete', {});
    if (d.ok) { templates = templates.filter((t) => t.id !== currentTpl.id); currentTpl = null; fillTemplates(); }
  });

  // ------------------------------------------------------------ aperçu / impression
  let pt = null;
  function submitTo(target, print, test) {
    $('#lblPrintFlag').value = print ? '1' : '0';
    $('#lblTestFlag').value = test ? '1' : '0';
    form.target = target;
    form.submit();
    form.target = 'labelPreview';
    $('#lblPrintFlag').value = '0'; $('#lblTestFlag').value = '0';
  }
  function preview() { clearTimeout(pt); pt = setTimeout(() => submitTo('labelPreview', false, false), 400); }
  $('#lblPrint').addEventListener('click', () => {
    if (!sel.length) { alert(T('Ajoutez au moins un article.')); return; }
    submitTo('_blank', true, false);
  });
  $('#lblTest').addEventListener('click', () => submitTo('_blank', true, true));

  setCfg(D.default);
  fillTemplates();
  renderList();
})();
