/* Reconnaissance par photo — page d'apprentissage : photos des articles, fiches, ressemblances, test. */
(function () {
  'use strict';
  const V = window.PMVision;
  const $ = (s, r = document) => r.querySelector(s);
  const T = (s) => (window.__ ? __(s) : s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  if (!V || !$('#visModal')) return;
  const modal = $('#visModal');
  const video = $('video', modal);
  const TIPS = ['face avant', 'dos', 'côté gauche', 'côté droit', 'de plus près', 'de plus loin', 'incliné', 'posé autrement', 'sous un autre éclairage', 'tenu dans la main'];
  let cur = null, stream = null, shots = 0, busy = false;

  const msg = (t, cls) => { const m = $('[data-msg]', modal); m.textContent = t; m.className = 'vis-msg ' + (cls || ''); };
  const progress = (html) => { const p = $('#visProgress'); p.classList.toggle('d-none', !html); p.innerHTML = html || ''; };
  function tip() { $('[data-tip]', modal).innerHTML = `${T('Photo suivante conseillée')} : <b>${esc(T(TIPS[shots % TIPS.length]))}</b> · ${shots} ${T('photo(s) ajoutée(s)')}${shots >= 5 ? ' — ' + T('c\'est suffisant pour commencer') : ''}`; }

  async function openFor(tr) {
    cur = { id: Number(tr.dataset.product), name: tr.dataset.name, tr };
    shots = 0;
    $('[data-title]', modal).textContent = T('Apprendre') + ' : ' + cur.name;
    modal.hidden = false; document.body.classList.add('pm-scan-on');
    tip(); await thumbs();
    msg(T('Chargement de l\'intelligence…'));
    try { await Promise.all([V.loadEngine((t) => msg(t)), V.loadIndex()]); } catch (e) { msg(e.message, 'bad'); return; }
    if (!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
      $('[data-shot]', modal).disabled = true;
      msg(T('Caméra en direct indisponible (connexion non sécurisée) : utilisez « Photos… » pour choisir ou prendre des photos.'), 'bad');
      return;
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
      video.srcObject = stream; await video.play().catch(() => {});
      $('[data-shot]', modal).disabled = false;
      msg(T('Placez l\'article dans le cadre jaune, puis « Prendre la photo ».') + ' (' + (V.engine.backend === 'webgl' ? T('accélération graphique active') : T('calcul sans accélération graphique : plus lent')) + ')');
    } catch (e) {
      $('[data-shot]', modal).disabled = true;
      msg(T('Caméra indisponible : utilisez « Photos… ».'), 'bad');
    }
  }
  function closeModal() {
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
    modal.hidden = true; document.body.classList.remove('pm-scan-on'); cur = null;
  }
  modal.addEventListener('click', (e) => { if (e.target === modal || e.target.closest('[data-close]')) closeModal(); });
  document.addEventListener('keydown', (e) => {
    if (modal.hidden) return;
    if (e.key === 'Escape') closeModal();
    if (e.code === 'Space' && !e.target.matches('input, textarea')) { e.preventDefault(); shoot(); }
  });

  async function thumbs() {
    const d = await V.api('/vision/refs?product=' + cur.id);
    const box = $('[data-thumbs]', modal);
    box.innerHTML = (d.items || []).map((r) => `<div class="vis-thumb" title="${esc(r.label + ' · ' + r.date)}">${r.thumb ? `<img src="${esc(r.thumb)}" alt="">` : ''}<span>${esc(r.label)}</span><button type="button" data-del="${r.id}" title="${esc(T('Oublier cette photo'))}">✕</button></div>`).join('')
      + ((d.items || []).length ? `<button type="button" class="btn btn-sm btn-outline-danger align-self-center" data-clear>${T('Tout oublier')}</button>` : '');
    setCount((d.items || []).length);
  }
  function setCount(n) {
    const b = cur && cur.tr.querySelector('[data-count]');
    if (b) { b.textContent = n; b.className = 'badge ' + (n >= 5 ? 'bg-success' : n >= 3 ? 'bg-info text-dark' : n ? 'bg-warning text-dark' : 'bg-secondary'); }
    const s = cur && cur.tr.querySelector('[data-show]'); if (s) s.disabled = !n;
  }
  $('[data-thumbs]', modal).addEventListener('click', async (e) => {
    const d = e.target.closest('[data-del]');
    if (d && confirm(T('Oublier cette photo ?'))) { const r = await V.api('/vision/refs/' + d.dataset.del + '/delete', {}); if (!r.ok) alert(r.error); await thumbs(); await V.loadIndex(true); }
    if (e.target.closest('[data-clear]') && confirm(T('Oublier toutes les photos de cet article ?'))) { const r = await V.api('/vision/products/' + cur.id + '/clear', {}); if (!r.ok) alert(r.error); await thumbs(); await V.loadIndex(true); }
  });

  /** Enregistre une empreinte et prévient si l'article ressemble beaucoup à un autre. */
  async function learn(e, thumb, source) {
    const ranking = V.match(e.f); // avant l'ajout : à quoi cette photo ressemble-t-elle le plus ?
    const hasOwn = ranking.some((r) => r.pid === cur.id);
    const d = await V.api('/vision/refs', { product_id: cur.id, vec: V.b64(e.q), thumb, source });
    if (!d.ok) { msg(d.error || T('Erreur'), 'bad'); return false; }
    V.addLocal(cur.id, e.q); V.IDX.version = d.version;
    shots++; tip();
    const near = ranking[0];
    if (hasOwn && near && near.pid !== cur.id) {
      const [p] = await V.products([near.pid]);
      msg(`${T('Photo apprise. Attention : elle ressemble plus à')} « ${p ? p.name : '?'} » ${T('qu\'à cet article : photographiez la face qui les distingue, ou utilisez le code-barres.')}`, 'bad');
    } else msg(T('Photo apprise.'), 'good');
    return true;
  }
  async function shoot() {
    if (busy || !stream || !cur) return;
    busy = true; $('[data-shot]', modal).disabled = true;
    try {
      const region = V.aimRegion(video, $('.vis-aim', modal));
      const f = $('.vis-flash', modal); f.classList.remove('on'); void f.offsetWidth; f.classList.add('on');
      const e = await V.embed(video, region);
      if (await learn(e, V.thumbOf(video, region), 'photo')) await thumbs();
    } catch (err) { msg(err.message || String(err), 'bad'); } finally { busy = false; $('[data-shot]', modal).disabled = false; }
  }
  $('[data-shot]', modal).addEventListener('click', shoot);
  $('[data-files]', modal).addEventListener('change', async (ev) => {
    const files = Array.from(ev.target.files || []); ev.target.value = '';
    for (const [i, file] of files.entries()) {
      msg(`${T('Analyse de la photo')} ${i + 1}/${files.length}…`);
      try {
        const img = await V.loadImage(URL.createObjectURL(file));
        const region = V.centerRegion(img.naturalWidth, img.naturalHeight, 0.9);
        await learn(await V.embed(img, region), V.thumbOf(img, region), 'photo');
        URL.revokeObjectURL(img.src);
      } catch (err) { msg(err.message || String(err), 'bad'); }
    }
    await thumbs();
  });

  document.querySelector('.vis-table') && document.querySelector('.vis-table').addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-product]');
    if (tr && (e.target.closest('[data-learn]') || e.target.closest('[data-show]'))) openFor(tr);
  });

  // Photos déjà présentes sur les fiches articles
  const fb = $('#visFiches');
  if (fb) fb.addEventListener('click', async () => {
    if (!confirm(T('Apprendre la photo de chaque fiche article qui en a une ? Cela peut prendre quelques minutes.'))) return;
    fb.disabled = true;
    try {
      progress(T('Chargement de l\'intelligence…'));
      await V.loadEngine((t) => progress(esc(t)));
      const d = await V.api('/vision/fiches');
      let ok = 0, bad = 0;
      for (const [i, it] of (d.items || []).entries()) {
        progress(`${T('Apprentissage des photos des fiches')} : ${i + 1}/${d.items.length} — ${esc(it.name)}`);
        try {
          const img = await V.loadImage(it.image);
          const region = V.centerRegion(img.naturalWidth, img.naturalHeight, 1);
          const e = await V.embed(img, region);
          const r = await V.api('/vision/refs', { product_id: it.id, vec: V.b64(e.q), thumb: V.thumbOf(img, region), source: 'fiche' });
          if (r.ok) ok++; else bad++;
        } catch (err) { bad++; }
      }
      progress(`${ok} ${T('photo(s) de fiche apprise(s)')}${bad ? ` · ${bad} ${T('illisible(s)')}` : ''}. ${T('Actualisation…')}`);
      setTimeout(() => location.reload(), 1200);
    } catch (e) { progress(esc(e.message)); fb.disabled = false; }
  });

  // Articles qui se ressemblent (risque de confusion)
  const sb = $('#visSimilar');
  if (sb) sb.addEventListener('click', async () => {
    const out = $('#visSimilarOut'); out.classList.remove('d-none');
    const box = $('[data-out]', out); box.textContent = T('Calcul…');
    try {
      const I = await V.loadIndex();
      const byP = new Map();
      for (let r = 0; r < I.n; r++) { const pid = I.pids[r]; if (!byP.has(pid)) byP.set(pid, []); byP.get(pid).push(r); }
      let pids = [...byP.keys()];
      const capped = pids.length > 800; if (capped) pids = pids.sort((a, b) => byP.get(b).length - byP.get(a).length).slice(0, 800);
      const P = 512, cent = new Map();
      pids.forEach((pid) => { const v = new Float32Array(P); byP.get(pid).forEach((r) => { for (let j = 0; j < P; j++) v[j] += I.mat[r * P + j]; }); let n = 0; for (const x of v) n += x * x; n = Math.sqrt(n) || 1; cent.set(pid, v.map((x) => x / n)); });
      const pairs = [];
      for (let a = 0; a < pids.length; a++) for (let b = a + 1; b < pids.length; b++) {
        const va = cent.get(pids[a]), vb = cent.get(pids[b]); let d = 0; for (let j = 0; j < P; j++) d += va[j] * vb[j];
        pairs.push([d, pids[a], pids[b]]);
      }
      pairs.sort((x, y) => y[0] - x[0]);
      const top = pairs.slice(0, 10); // les 10 paires les plus proches (classement relatif)
      if (!top.length) { box.textContent = pids.length < 2 ? T('Apprenez au moins deux articles.') : T('Aucune paire d\'articles à comparer.'); return; }
      const info = new Map((await V.products([...new Set(top.flatMap((p) => [p[1], p[2]]))])).map((p) => [p.id, p]));
      box.innerHTML = `<p class="mb-1">${T('Ces articles se ressemblent à la caméra : ajoutez des photos de la face qui les distingue, ou utilisez leur code-barres.')}</p><ol class="mb-0">`
        + top.map(([d, a, b]) => `<li>« ${esc((info.get(a) || {}).name)} » ↔ « ${esc((info.get(b) || {}).name)} » </li>`).join('') + '</ol>'
        + (capped ? `<p class="text-secondary mb-0">${T('Calcul limité aux 800 articles les plus photographiés.')}</p>` : '');
    } catch (e) { box.textContent = e.message; }
  });

  // Test de la reconnaissance (n'ajoute rien, n'apprend rien)
  const tb = $('#visTest');
  if (tb) tb.addEventListener('click', () => PMScanner.open({
    title: T('Tester la reconnaissance'), products: true, plus: false, startTool: 'vision', visionLearn: false, visionAuto: false, visionLog: false,
    onCode: async (code) => ({ ok: true, text: T('Reconnu') + ' : ' + code }),
  }));
})();
