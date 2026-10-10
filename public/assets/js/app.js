/* POS Wan — comportements communs de l'administration (aucun script inline : CSP stricte). */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const BASE = ($('meta[name="base-url"]') || {}).content || '/';
  const CSRF = ($('meta[name="csrf-token"]') || {}).content || '';
  window.PW = { $, $$, BASE: BASE.replace(/\/$/, ''), CSRF };

  // Thème clair / sombre (préférence locale du poste)
  const applyTheme = (t) => document.documentElement.setAttribute('data-bs-theme', t);
  applyTheme(localStorage.getItem('pw-theme') || 'light');
  $$('[data-theme-toggle]').forEach((b) => b.addEventListener('click', () => {
    const t = document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
    localStorage.setItem('pw-theme', t); applyTheme(t);
  }));

  // Horloge
  const clocks = $$('[data-clock]');
  const tick = () => { const d = new Date(); clocks.forEach((c) => { c.textContent = d.toLocaleDateString('fr-FR') + ' ' + d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' }); }); };
  if (clocks.length) { tick(); setInterval(tick, 15000); }

  // Menu latéral sur mobile
  $$('[data-toggle-sidebar]').forEach((b) => b.addEventListener('click', () => $('#sidebar').classList.toggle('open')));

  // Confirmation des actions sensibles : <form data-confirm="Texte" [data-reason="1"]>
  const modalEl = $('#confirmModal');
  let pendingForm = null;
  if (modalEl && window.bootstrap) {
    const modal = new bootstrap.Modal(modalEl);
    $$('form[data-confirm]').forEach((f) => f.addEventListener('submit', (ev) => {
      if (f.dataset.confirmed === '1') return;
      ev.preventDefault();
      pendingForm = f;
      $('[data-confirm-text]', modalEl).textContent = f.dataset.confirm;
      const needReason = f.dataset.reason === '1';
      const box = $('[data-confirm-reason]', modalEl);
      box.classList.toggle('d-none', !needReason);
      $('textarea', box).value = '';
      modal.show();
    }));
    $('[data-confirm-ok]', modalEl).addEventListener('click', () => {
      if (!pendingForm) return;
      if (pendingForm.dataset.reason === '1') {
        const r = $('[data-confirm-reason] textarea', modalEl).value.trim();
        if (!r) { $('[data-confirm-reason] textarea', modalEl).classList.add('is-invalid'); return; }
        let inp = pendingForm.querySelector('input[name="reason"]');
        if (!inp) { inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'reason'; pendingForm.appendChild(inp); }
        inp.value = r;
      }
      pendingForm.dataset.confirmed = '1';
      modal.hide();
      pendingForm.requestSubmit ? pendingForm.requestSubmit() : pendingForm.submit();
    });
  }

  // Empêche la double soumission des formulaires
  $$('form').forEach((f) => f.addEventListener('submit', () => {
    setTimeout(() => $$('button[type="submit"], button:not([type])', f).forEach((b) => { b.disabled = true; }), 0);
  }));

  // Sélecteurs qui soumettent automatiquement leur formulaire de filtre
  $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

  // Garde de session : ping régulier pour détecter une expiration
  if (document.body.classList.contains('admin-body')) {
    setInterval(() => {
      fetch(PW.BASE + '/session/ping', { headers: { Accept: 'application/json' } }).then((r) => { if (r.status === 401) location.href = PW.BASE + '/login'; }).catch(() => {});
    }, 120000);
  }

  // ------------------------------------------------------------------
  // Grille de lignes d'articles (commandes, réceptions, transferts...)
  // <div data-lines data-template="#tpl"> avec un champ de recherche [data-line-search]
  // ------------------------------------------------------------------
  $$('[data-lines]').forEach((wrap) => {
    const tbody = $('tbody', wrap);
    const tpl = $(wrap.dataset.template);
    const search = $('[data-line-search]', wrap);
    const results = $('[data-line-results]', wrap);
    let idx = tbody.children.length + 1000;
    const recalc = () => {
      let tot = 0;
      $$('tr', tbody).forEach((tr) => {
        const q = parseFloat(($('[data-q]', tr) || {}).value || '0') || 0;
        const c = parseFloat(($('[data-c]', tr) || {}).value || '0') || 0;
        const cell = $('[data-line-total]', tr);
        if (cell) cell.textContent = (q * c).toLocaleString('fr-FR');
        tot += q * c;
      });
      const t = $('[data-grand-total]', wrap); if (t) t.textContent = tot.toLocaleString('fr-FR') + ' F';
    };
    const add = (p) => {
      if (!tpl) return;
      const html = tpl.innerHTML.replaceAll('__i__', String(idx++));
      const tmp = document.createElement('tbody'); tmp.innerHTML = html.trim();
      const tr = tmp.firstElementChild;
      $('[data-pid]', tr).value = p.id;
      $('[data-name]', tr).textContent = p.name + (p.reference ? ' (' + p.reference + ')' : '');
      const c = $('[data-c]', tr); if (c && p.cost_price !== undefined) c.value = p.cost_price;
      const lotBox = $('[data-lot-fields]', tr); if (lotBox && !p.track_lots) lotBox.classList.add('d-none');
      const stk = $('[data-stock]', tr); if (stk && p.stock !== undefined) stk.textContent = p.stock;
      tbody.appendChild(tr);
      const q = $('[data-q]', tr); if (q) q.focus();
      recalc();
    };
    wrap.addEventListener('input', (e) => { if (e.target.matches('[data-q],[data-c]')) recalc(); });
    wrap.addEventListener('click', (e) => { const b = e.target.closest('[data-remove-line]'); if (b) { b.closest('tr').remove(); recalc(); } });
    if (search) {
      let timer;
      const run = () => {
        const q = search.value.trim();
        if (q.length < 1) { results.innerHTML = ''; return; }
        fetch(PW.BASE + '/api/products?q=' + encodeURIComponent(q) + '&wh=' + encodeURIComponent(wrap.dataset.warehouse || ''), { headers: { Accept: 'application/json' } })
          .then((r) => r.json()).then((d) => {
            results.innerHTML = '';
            (d.items || []).forEach((p) => {
              const a = document.createElement('button'); a.type = 'button'; a.className = 'list-group-item list-group-item-action';
              a.textContent = p.name + ' — ' + (p.reference || '') + (p.barcode ? ' — ' + p.barcode : '') + (p.stock !== undefined ? ' — stock ' + p.stock : '');
              a.addEventListener('click', () => { add(p); results.innerHTML = ''; search.value = ''; search.focus(); });
              results.appendChild(a);
            });
            if (d.items && d.items.length === 1 && /^\d{6,}$/.test(q)) { add(d.items[0]); results.innerHTML = ''; search.value = ''; }
          });
      };
      search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 220); });
      search.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); run(); } });
    }
    recalc();
  });

  // Aperçu d'une image avant envoi
  $$('input[type=file][data-preview]').forEach((inp) => inp.addEventListener('change', () => {
    const img = $(inp.dataset.preview); if (img && inp.files[0]) { img.src = URL.createObjectURL(inp.files[0]); img.classList.remove('d-none'); }
  }));
})();

/* Sélecteur d'article qui ouvre une page : <input data-product-picker="/url?product="> */
(function () {
  const { $, $$, BASE } = window.PW;
  $$('[data-product-picker]').forEach((inp) => {
    const box = inp.parentElement.querySelector('[data-picker-results]');
    let t;
    inp.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => {
        const q = inp.value.trim();
        if (!q) { box.innerHTML = ''; return; }
        fetch(BASE + '/api/products?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } }).then((r) => r.json()).then((d) => {
          box.innerHTML = '';
          (d.items || []).forEach((p) => {
            const a = document.createElement('a');
            a.className = 'list-group-item list-group-item-action';
            a.href = inp.dataset.productPicker + p.id;
            a.textContent = p.name + ' — ' + (p.reference || '') + (p.barcode ? ' — ' + p.barcode : '');
            box.appendChild(a);
          });
          if (d.items && d.items.length === 1 && /^\d{6,}$/.test(q)) location.href = inp.dataset.productPicker + d.items[0].id;
        });
      }, 220);
    });
  });
})();
document.querySelectorAll('[data-print-page]').forEach((b) => b.addEventListener('click', () => window.print()));
