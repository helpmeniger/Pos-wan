/* Module Clinique : ordonnance (lignes de médicaments), actes à facturer, délivrance pharmacie,
   impression automatique de l'ordonnance et courbe des constantes. */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const T = (s) => (window.__ ? __(s) : s);
  const fmt = (n) => (window.PWMoney ? PWMoney.fmt(n) : Math.round(n).toLocaleString('fr-FR') + ' F');
  const num = (v) => { const n = parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };

  // ---------------------------------------------------------------- impression automatique de l'ordonnance
  const ap = $('[data-autoprint-url]');
  if (ap) {
    const f = document.createElement('iframe');
    f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0';
    f.src = ap.dataset.autoprintUrl;
    document.body.appendChild(f);
  }

  // ---------------------------------------------------------------- ordonnance : lignes de médicaments
  $$('form[data-presc-form]').forEach((form) => {
    const list = $('#' + ($('[data-drug]', form).getAttribute('list') || 'clinDrugList'));
    const byName = {};
    if (list) $$('option', list).forEach((o) => { byName[o.value] = { id: o.dataset.id, info: o.label }; });
    const body = $('[data-presc-lines]', form);
    const tpl = $('[data-presc-line]', body).cloneNode(true);

    function sync(tr) {
      const name = $('[data-drug]', tr).value.trim();
      const hit = byName[name];
      $('[data-pid]', tr).value = hit ? hit.id : '';
      $('[data-dname]', tr).value = name;
      $('[data-drug-info]', tr).textContent = !name ? '' : (hit ? hit.info : T('Hors stock : figurera sur l\'ordonnance, non délivré ici'));
    }
    function addLine() {
      const tr = tpl.cloneNode(true);
      $$('input', tr).forEach((i) => { i.value = i.hasAttribute('data-qty') ? '1' : ''; });
      $('[data-drug-info]', tr).textContent = '';
      body.appendChild(tr);
      $('[data-drug]', tr).focus();
    }
    body.addEventListener('input', (e) => { const tr = e.target.closest('tr'); if (e.target.matches('[data-drug]')) sync(tr); });
    body.addEventListener('click', (e) => {
      const b = e.target.closest('[data-del-line]');
      if (!b) return;
      const rows = $$('tr', body);
      if (rows.length > 1) b.closest('tr').remove(); else $$('input', rows[0]).forEach((i) => { i.value = i.hasAttribute('data-qty') ? '1' : ''; });
    });
    $('[data-add-line]', form).addEventListener('click', addLine);
    // Nommage des champs au moment de l'envoi : drugs[n][product_id|drug_name|posology|duration|quantity]
    form.addEventListener('submit', (e) => {
      let n = 0;
      $$('tr', body).forEach((tr) => {
        sync(tr);
        const named = (sel, key) => { $(sel, tr).name = `drugs[${n}][${key}]`; };
        if (!$('[data-drug]', tr).value.trim()) { $$('input', tr).forEach((i) => i.removeAttribute('name')); return; }
        named('[data-pid]', 'product_id'); named('[data-dname]', 'drug_name'); named('[data-posology]', 'posology');
        named('[data-duration]', 'duration'); named('[data-qty]', 'quantity');
        n++;
      });
      if (!n) { e.preventDefault(); e.stopImmediatePropagation(); alert(T('Ajoutez au moins un médicament à l\'ordonnance.')); }
    }, true);
  });

  // ---------------------------------------------------------------- actes à facturer : total
  $$('[data-acts]').forEach((box) => {
    const calc = () => {
      let t = 0;
      $$('input[type="checkbox"][data-act-price]', box).forEach((c) => {
        if (c.checked) t += num(c.dataset.actPrice) * num($('[data-act-qty]', c.parentElement).value);
      });
      $('[data-acts-total]', box).textContent = fmt(t);
    };
    box.addEventListener('input', calc);
    box.addEventListener('change', calc);
    calc();
  });

  // ---------------------------------------------------------------- délivrance pharmacie : total estimé
  const df = $('form[data-dispense-form]');
  if (df) {
    const amount = $('[data-pay-amount]', df);
    let touched = false;
    if (amount) amount.addEventListener('input', () => { touched = true; });
    const calc = () => {
      let t = 0;
      $$('[data-dq]', df).forEach((i) => {
        const q = Math.min(num(i.value), num(i.dataset.max));
        if (num(i.value) > num(i.dataset.max)) i.classList.add('is-invalid'); else i.classList.remove('is-invalid');
        t += Math.max(0, q) * num(i.dataset.price);
      });
      const out = $('[data-dispense-total]', df);
      if (out) out.textContent = fmt(t);
      if (amount && !touched) amount.value = Math.round(t * 100) / 100;
    };
    df.addEventListener('input', (e) => { if (e.target.matches('[data-dq]')) calc(); });
    calc();
    // Vérification par scan : chaque boîte scannée doit correspondre à une ligne de l'ordonnance
    const sb = $('#dispenseScan');
    let scanned = {};
    if (sb && window.PMScanner) sb.addEventListener('click', () => {
      scanned = {};
      PMScanner.open({ title: T('Scanner les boîtes délivrées'), products: true, onCode: async (code) => {
        const inp = $$('[data-dq]', df).find((i) => (i.dataset.codes || '').split('|').includes(code));
        if (!inp) return { ok: false, text: T('Ce produit ne figure pas sur l\'ordonnance') };
        const id = inp.name;
        scanned[id] = (scanned[id] || 0) + 1;
        const max = num(inp.dataset.max);
        if (scanned[id] > max) { scanned[id] = max; return { ok: false, text: inp.dataset.drugName + ' : ' + T('quantité prescrite déjà atteinte') + ' (' + max + ')' }; }
        inp.value = scanned[id];
        calc();
        return { ok: true, text: inp.dataset.drugName + ' : ' + scanned[id] + ' / ' + max };
      } });
    });
  }

  // ---------------------------------------------------------------- courbe des constantes (séjour)
  const cv = $('#clinChart');
  const src = $('#clin-chart');
  if (cv && src) {
    const pts = JSON.parse(src.textContent || '[]');
    const ctx = cv.getContext('2d');
    const draw = () => {
      const w = cv.width = cv.clientWidth * (window.devicePixelRatio || 1);
      const h = cv.height = 160 * (window.devicePixelRatio || 1);
      const dpr = window.devicePixelRatio || 1;
      ctx.clearRect(0, 0, w, h);
      const css = getComputedStyle(document.body);
      ctx.font = `${11 * dpr}px system-ui, sans-serif`;
      ctx.fillStyle = css.getPropertyValue('--bs-secondary-color') || '#888';
      if (pts.length < 2) { ctx.fillText(T('Au moins deux relevés sont nécessaires pour tracer la courbe.'), 10 * dpr, 20 * dpr); return; }
      const pad = 28 * dpr;
      const x = (i) => pad + (i * (w - 2 * pad)) / (pts.length - 1);
      const series = [
        { k: 't', c: '#e53935', min: 35, max: 41 },
        { k: 'p', c: '#1e88e5', min: 40, max: 160 },
        { k: 'sys', c: '#43a047', min: 60, max: 200 },
      ];
      ctx.strokeStyle = 'rgba(128,128,128,.25)'; ctx.lineWidth = 1;
      for (let g = 0; g <= 4; g++) { const y = pad / 2 + (g * (h - pad)) / 4; ctx.beginPath(); ctx.moveTo(pad, y); ctx.lineTo(w - pad, y); ctx.stroke(); }
      // Seuil de fièvre (échelle température)
      const fever = 38.5;
      const yf = pad / 2 + (h - pad) * (1 - (fever - 35) / 6);
      ctx.setLineDash([4 * dpr, 4 * dpr]); ctx.strokeStyle = 'rgba(229,57,53,.5)'; ctx.beginPath(); ctx.moveTo(pad, yf); ctx.lineTo(w - pad, yf); ctx.stroke(); ctx.setLineDash([]);
      series.forEach((s) => {
        ctx.strokeStyle = s.c; ctx.fillStyle = s.c; ctx.lineWidth = 2 * dpr; ctx.beginPath();
        let started = false;
        pts.forEach((p, i) => {
          if (p[s.k] === null) return;
          const v = Math.max(s.min, Math.min(s.max, p[s.k]));
          const y = pad / 2 + (h - pad) * (1 - (v - s.min) / (s.max - s.min));
          if (!started) { ctx.moveTo(x(i), y); started = true; } else ctx.lineTo(x(i), y);
        });
        ctx.stroke();
        pts.forEach((p, i) => {
          if (p[s.k] === null) return;
          const v = Math.max(s.min, Math.min(s.max, p[s.k]));
          const y = pad / 2 + (h - pad) * (1 - (v - s.min) / (s.max - s.min));
          ctx.beginPath(); ctx.arc(x(i), y, 3 * dpr, 0, 6.3); ctx.fill();
        });
      });
      ctx.fillStyle = css.getPropertyValue('--bs-secondary-color') || '#888';
      [0, pts.length - 1].forEach((i) => { ctx.fillText(pts[i].at, Math.min(x(i), w - 80 * dpr), h - 4 * dpr); });
    };
    draw();
    window.addEventListener('resize', draw);
  }
})();
