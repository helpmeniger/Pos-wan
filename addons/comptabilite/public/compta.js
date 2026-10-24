/* Module Comptabilité : grille de saisie (équilibre en direct, lignes automatiques), sélection pour le lettrage. */
(function () {
  'use strict';
  const qs = (s, r) => (r || document).querySelector(s);
  const qsa = (s, r) => Array.from((r || document).querySelectorAll(s));
  const parse = (v) => {
    const s = String(v || '').replace(/[\s  ]/g, '').replace(',', '.');
    const n = parseFloat(s);
    return Number.isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0;
  };
  const fmt = (n) => (Math.round(n * 100) / 100).toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 2 }).replace(/ /g, ' ');
  const lead = (v) => (String(v || '').match(/^\s*([0-9A-Za-z]+)/) || [])[1] || '';

  // ------------------------------------------------------------------ saisie
  const form = qs('[data-cpt-grid]');
  if (form) {
    const body = qs('[data-rows]', form);
    const types = {};
    qsa('#dl-comptes option').forEach((o) => { types[lead(o.value)] = o.dataset.t || ''; });
    let index = qsa('[data-row]', body).length;
    let current = null;

    const rowOf = (el) => el.closest('[data-row]');
    const field = (row, f) => qs('[data-f="' + f + '"]', row);
    const rows = () => qsa('[data-row]', body);

    const refreshTiers = (row) => {
      const t = field(row, 'tiers');
      const collectif = types[lead(field(row, 'compte').value)];
      t.disabled = !collectif;
      t.placeholder = collectif ? 'Tiers obligatoire' : '';
      if (!collectif) t.value = '';
    };

    const totals = () => {
      let d = 0, c = 0;
      rows().forEach((r) => { d += parse(field(r, 'debit').value); c += parse(field(r, 'credit').value); });
      d = Math.round(d * 100) / 100; c = Math.round(c * 100) / 100;
      const diff = Math.round((d - c) * 100) / 100;
      qs('[data-td]', form).textContent = fmt(d);
      qs('[data-tc]', form).textContent = fmt(c);
      const el = qs('[data-diff]', form);
      el.textContent = diff === 0 ? '0' : fmt(Math.abs(diff)) + (diff > 0 ? ' (débit)' : ' (crédit)');
      el.className = diff === 0 && d > 0 ? 'ok' : 'ko';
      qsa('[data-need-balance]', form).forEach((b) => { b.disabled = !(diff === 0 && d > 0); });
      return diff;
    };

    const addRow = () => {
      const tpl = rows()[0].cloneNode(true);
      qsa('input', tpl).forEach((i) => {
        i.value = '';
        i.disabled = false;
        i.name = i.name.replace(/lignes\[\d+\]/, 'lignes[' + index + ']');
      });
      index++;
      body.appendChild(tpl);
      refreshTiers(tpl);
      return tpl;
    };

    body.addEventListener('focusin', (ev) => {
      const row = rowOf(ev.target);
      if (!row) return;
      current = row;
      // Libellé de ligne repris du libellé de l'écriture ; montant proposé pour équilibrer
      if (ev.target.dataset.f === 'libelle' && !ev.target.value) ev.target.value = qs('[data-head-label]', form).value;
    });
    body.addEventListener('input', (ev) => {
      const row = rowOf(ev.target);
      if (!row) return;
      const f = ev.target.dataset.f;
      if (f === 'debit' && parse(ev.target.value)) field(row, 'credit').value = '';
      if (f === 'credit' && parse(ev.target.value)) field(row, 'debit').value = '';
      if (f === 'compte') refreshTiers(row);
      if (row === rows()[rows().length - 1] && ev.target.value) addRow();
      totals();
    });
    body.addEventListener('change', (ev) => {
      const row = rowOf(ev.target);
      if (row && ev.target.dataset.f === 'compte') refreshTiers(row);
      if (row && (ev.target.dataset.f === 'debit' || ev.target.dataset.f === 'credit') && parse(ev.target.value)) ev.target.value = fmt(parse(ev.target.value));
    });
    body.addEventListener('click', (ev) => {
      const b = ev.target.closest('[data-del]');
      if (!b) return;
      const row = rowOf(b);
      if (rows().length > 2) row.remove(); else qsa('input', row).forEach((i) => { i.value = ''; });
      totals();
    });
    // « Entrée » passe au champ suivant au lieu d'envoyer le formulaire
    form.addEventListener('keydown', (ev) => {
      if (ev.key !== 'Enter' || ev.target.tagName !== 'INPUT') return;
      ev.preventDefault();
      const inputs = qsa('input:not([disabled]):not([type=hidden]), select', form);
      const i = inputs.indexOf(ev.target);
      if (i >= 0 && inputs[i + 1]) inputs[i + 1].focus();
    });
    qs('[data-add]', form).addEventListener('click', () => { const r = addRow(); field(r, 'compte').focus(); });
    qs('[data-equilibrer]', form).addEventListener('click', () => {
      const diff = totals();
      if (diff === 0) return;
      const cp = (qs('[data-journal]', form).selectedOptions[0] || {}).dataset?.contrepartie || '';
      let row = null;
      if (cp) {
        row = rows().find((r) => lead(field(r, 'compte').value) === cp) || rows().find((r) => !field(r, 'compte').value && !parse(field(r, 'debit').value) && !parse(field(r, 'credit').value)) || addRow();
        if (!field(row, 'compte').value) {
          const opt = qsa('#dl-comptes option').find((o) => lead(o.value) === cp);
          field(row, 'compte').value = opt ? opt.value : cp;
          refreshTiers(row);
        }
      } else {
        row = current && !parse(field(current, 'debit').value) && !parse(field(current, 'credit').value) ? current
          : rows().find((r) => !parse(field(r, 'debit').value) && !parse(field(r, 'credit').value)) || addRow();
      }
      const cur = parse(field(row, 'debit').value) - parse(field(row, 'credit').value) - diff;
      field(row, 'debit').value = cur > 0 ? fmt(cur) : '';
      field(row, 'credit').value = cur < 0 ? fmt(-cur) : '';
      if (!field(row, 'libelle').value) field(row, 'libelle').value = qs('[data-head-label]', form).value;
      totals();
      if (rows()[rows().length - 1] === row) addRow();
      field(row, 'compte').value ? field(row, 'libelle').focus() : field(row, 'compte').focus();
    });
    rows().forEach(refreshTiers);
    totals();
    const first = rows().find((r) => !field(r, 'compte').value);
    if (first && !qs('[data-head-label]', form).value) qs('[data-head-label]', form).focus();
  }

  // ------------------------------------------------------------------ lettrage : total des lignes cochées
  const lt = qs('[data-cpt-lettrage]');
  if (lt) {
    const out = qs('[data-sel-total]', lt);
    const btn = qs('[data-sel-submit]', lt);
    const upd = () => {
      let d = 0, c = 0, n = 0;
      qsa('input[name="lignes[]"]:checked', lt).forEach((i) => { d += parseFloat(i.dataset.d || 0); c += parseFloat(i.dataset.c || 0); n++; });
      const diff = Math.round((d - c) * 100) / 100;
      out.textContent = n + ' ligne(s) — débit ' + fmt(d) + ' / crédit ' + fmt(c) + ' — écart ' + fmt(Math.abs(diff));
      out.className = n >= 2 && diff === 0 ? 'text-success fw-semibold' : 'text-secondary';
      if (btn) btn.disabled = !(n >= 2 && diff === 0);
    };
    lt.addEventListener('change', upd);
    upd();
  }

  // ------------------------------------------------------------------ cases « tout cocher »
  qsa('[data-check-all]').forEach((all) => all.addEventListener('change', () => {
    qsa('input[type=checkbox][name="' + all.dataset.checkAll + '"]').forEach((i) => { if (!i.disabled) i.checked = all.checked; });
    all.closest('form')?.dispatchEvent(new Event('change', { bubbles: true }));
  }));
})();
