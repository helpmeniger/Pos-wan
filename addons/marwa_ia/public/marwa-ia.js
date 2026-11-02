/* =====================================================================
   MARWA IA — conversation avec l'assistant (page /ia et bulle sur toutes les pages).
   Les réponses viennent du serveur de la boutique (aucun service Internet).
   ===================================================================== */
(function () {
  'use strict';
  const meta = (n) => (document.querySelector(`meta[name="${n}"]`) || {}).content || '';
  const BASE = (meta('base-url') || '/').replace(/\/$/, '');
  const CSRF = meta('csrf-token');
  const KEY = 'mia-log-v1';
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY) || '[]'); } catch (e) { return []; } };
  const save = (log) => { try { sessionStorage.setItem(KEY, JSON.stringify(log.slice(-24))); } catch (e) { /* stockage plein */ } };

  // Lecture à voix haute (voix française du système, si le navigateur en propose une)
  const TTS = 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window;
  function speak(el) {
    if (!TTS) return;
    const synth = window.speechSynthesis;
    if (synth.speaking) { synth.cancel(); return; }
    const box = el.cloneNode(true);
    box.querySelectorAll('.mia-sug, .mia-act, .mia-say, table, iframe, details').forEach((x) => x.remove());
    const text = box.textContent.replace(/\bPOS\b/g, 'Pose').replace(/\s+/g, ' ').trim().slice(0, 1800);
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'fr-FR';
    const v = synth.getVoices().find((x) => /^fr/i.test(x.lang));
    if (v) u.voice = v;
    u.rate = 1;
    synth.speak(u);
  }
  document.addEventListener('click', (e) => { const b = e.target.closest('[data-mia-say]'); if (b) { e.preventDefault(); speak(b.closest('.mia-a')); } });
  // Copier un texte prêt à publier (publication, statut, SMS)
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-mia-copy]');
    if (!b) return;
    e.preventDefault();
    const pre = b.closest('.mia-copy').querySelector('pre');
    const text = pre.textContent;
    let done = false;
    try { if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); done = true; } } catch (err) { done = false; }
    if (!done) {
      const t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select();
      try { done = document.execCommand('copy'); } catch (err) { done = false; }
      t.remove();
    }
    b.textContent = done ? 'Copié ✓' : 'Sélectionnez le texte';
    if (!done) { const r = document.createRange(); r.selectNodeContents(pre); const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r); }
    setTimeout(() => { b.textContent = 'Copier'; }, 2000);
  });

  function renderAnswer(r) {
    let h = '<div class="mia-a">';
    if (r.title) h += `<div class="mia-t">${esc(r.title)}${r.subtitle ? `<span>${esc(r.subtitle)}</span>` : ''}</div>`;
    h += `<div class="mia-html">${r.html || ''}</div>`; // HTML préparé et échappé par le serveur
    if (r.kpis && r.kpis.length) h += '<div class="mia-kpis">' + r.kpis.map((k) => `<div><span>${esc(k.label)}</span><b>${esc(k.value)}</b></div>`).join('') + '</div>';
    if (r.table && r.table.rows.length) {
      h += '<div class="mia-tbl"><table><thead><tr>' + r.table.headers.map((x) => `<th>${esc(x)}</th>`).join('') + '</tr></thead><tbody>'
        + r.table.rows.map((row) => '<tr>' + row.map((c) => `<td>${esc(c)}</td>`).join('') + '</tr>').join('') + '</tbody></table>'
        + (r.table.more ? `<div class="mia-more">… et ${r.table.more} ligne(s) de plus dans le PDF ou le fichier Excel.</div>` : '') + '</div>';
    }
    if (r.open && /export=pdf/.test(r.open) && r.full) h += `<iframe class="mia-pdf" src="${esc(r.open)}" title="Aperçu du PDF"></iframe>`;
    if (r.actions && r.actions.length) {
      h += '<div class="mia-act">' + r.actions.map((a) => {
        const ic = { view: 'bi-file-earmark-pdf', pdf: 'bi-download', csv: 'bi-filetype-csv', link: 'bi-box-arrow-up-right' }[a.kind] || 'bi-link';
        const cls = a.kind === 'view' ? 'btn-danger' : a.kind === 'pdf' ? 'btn-outline-danger' : a.kind === 'csv' ? 'btn-outline-success' : 'btn-outline-secondary';
        const tgt = a.kind === 'view' ? ' target="_blank" rel="noopener"' : '';
        return `<a class="btn btn-sm ${cls}" href="${esc(a.url)}"${tgt}><i class="bi ${ic}"></i> ${esc(a.label)}</a>`;
      }).join('') + '</div>';
    }
    if (TTS) h += '<button type="button" class="mia-say" data-mia-say title="Écouter la réponse"><i class="bi bi-volume-up"></i> Écouter</button>';
    if (r.suggestions && r.suggestions.length) h += '<div class="mia-sug' + (r.quiz ? ' mia-choices' : '') + '">' + r.suggestions.map((s) => `<button type="button" data-mia-ask="${esc(s)}">${esc(s)}</button>`).join('') + '</div>';
    return h + '</div>';
  }

  function Chat(root) {
    const logEl = root.querySelector('[data-mia-log]');
    const form = root.querySelector('[data-mia-form]');
    const input = root.querySelector('[data-mia-input]');
    let log = load();
    let busy = false;
    const scroll = () => { logEl.scrollTop = logEl.scrollHeight; };
    function draw() {
      if (!log.length) {
        const chips = ['Mon point du jour', 'Bilan de la journée', 'Mon plan marketing en PDF', 'Comment augmenter mes ventes ?', 'Ma formation', 'Vérifie ma configuration', 'Que sais-tu faire ?'];
        logEl.innerHTML = '<div class="mia-msg bot"><div class="mia-a mia-welcome"><div class="mia-html"><p class="h"><i class="bi bi-stars"></i> Bonjour ! Je suis MARWA IA.</p><p>Je prépare vos <b>rapports</b> (« ventes d\'hier en PDF », « rapport du client Moussa »), je vous donne des <b>idées</b> pour mieux gérer, je vous <b>forme</b> pas à pas, je vous coache en <b>marketing</b> (réseaux sociaux, publicité, plan en PDF, textes prêts à publier), je vérifie ce qui a été <b>oublié</b> dans la configuration et je fais vos <b>calculs</b> de prix et de marge.</p></div>'
          + '<div class="mia-sug">' + chips.map((c) => `<button type="button" data-mia-ask="${esc(c)}">${esc(c)}</button>`).join('') + '</div></div></div>';
        return;
      }
      logEl.innerHTML = log.map((m) => m.u !== undefined ? `<div class="mia-msg me"><div>${esc(m.u)}</div></div>` : `<div class="mia-msg bot">${renderAnswer(m.r)}</div>`).join('');
      scroll();
    }
    async function ask(q) {
      q = String(q || '').trim();
      if (!q || busy) return;
      busy = true;
      // Demande de PDF : la fenêtre s'ouvre tout de suite (sinon le navigateur la bloque), puis reçoit le PDF
      let win = null;
      if (/\b(pdf|imprim)/i.test(q)) {
        try { win = window.open('', '_blank'); if (win) win.document.write('<p style="font-family:sans-serif;padding:2em;color:#14213d">MARWA IA prépare votre PDF…</p>'); } catch (e) { win = null; }
      }
      log.push({ u: q });
      draw();
      logEl.insertAdjacentHTML('beforeend', '<div class="mia-msg bot mia-wait"><span></span><span></span><span></span></div>');
      scroll();
      let r;
      try {
        const res = await fetch(BASE + '/ia/ask', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ q, _csrf: CSRF }) });
        r = await res.json();
        if (!res.ok || !r.ok) r = { html: `<p>${esc(r && r.error ? r.error : 'Réponse impossible pour le moment.')}</p>` };
      } catch (e) {
        r = { html: '<p>Le serveur ne répond pas : vérifiez la connexion au serveur de la boutique.</p>' };
      }
      if (root.hasAttribute('data-mia-full')) r.full = true;
      log.push({ r });
      save(log);
      busy = false;
      draw();
      if (win) {
        if (r.open && /export=pdf/.test(r.open)) win.location.href = new URL(r.open, window.location.href).href; else win.close();
      } else if (r.open && /export=csv/.test(r.open)) {
        window.location.href = r.open; // fichier Excel : téléchargement direct
      }
    }
    form.addEventListener('submit', (e) => { e.preventDefault(); const q = input.value; input.value = ''; ask(q); });
    root.addEventListener('click', (e) => { const b = e.target.closest('[data-mia-ask]'); if (b) { e.preventDefault(); ask(b.dataset.miaAsk); } });
    draw();
    return { ask, focus: () => input.focus(), clear: () => { log = []; save(log); draw(); } };
  }

  // Page /ia : conversation en grand, et les exemples de la colonne de droite
  const page = document.querySelector('[data-mia-chat][data-mia-full]');
  if (page) {
    const chat = Chat(page);
    document.addEventListener('click', (e) => { const b = e.target.closest('.mia-page [data-mia-ask]'); if (b && !page.contains(b)) { e.preventDefault(); chat.ask(b.dataset.miaAsk); } });
    chat.focus();
    return;
  }
  // Bulle sur les autres pages (jamais sur l'écran de caisse ni les impressions)
  if (document.body.classList.contains('pos-body') || !document.querySelector('.sidebar, nav, .app')) return;
  (async () => {
    let st = null;
    try { st = JSON.parse(sessionStorage.getItem('mia-state') || 'null'); } catch (e) { st = null; }
    if (!st || Date.now() - st.at > 300000) {
      try {
        const res = await fetch(BASE + '/ia/etat', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        st = res.ok ? Object.assign(await res.json(), { at: Date.now() }) : { ok: false, at: Date.now() };
      } catch (e) { return; }
      try { sessionStorage.setItem('mia-state', JSON.stringify(st)); } catch (e) { /* ignoré */ }
    }
    if (!st.ok || !st.bubble) return;
    const btn = document.createElement('button');
    btn.type = 'button'; btn.className = 'mia-bubble'; btn.title = 'MARWA IA — votre assistant';
    btn.innerHTML = '<i class="bi bi-stars"></i><span>MARWA IA</span>';
    const panel = document.createElement('div');
    panel.className = 'mia-panel d-none';
    panel.innerHTML = `<div class="mia-head"><b><i class="bi bi-stars"></i> MARWA IA</b><a href="${esc(st.page)}" title="Ouvrir en grand"><i class="bi bi-arrows-fullscreen"></i></a><button type="button" data-mia-new title="Nouvelle conversation"><i class="bi bi-arrow-counterclockwise"></i></button><button type="button" data-mia-close title="Fermer"><i class="bi bi-x-lg"></i></button></div>`
      + '<div class="mia-chat" data-mia-chat><div class="mia-log" data-mia-log></div><form class="mia-form" data-mia-form data-no-lock><input class="form-control" data-mia-input maxlength="500" autocomplete="off" placeholder="Votre question…" aria-label="Question pour MARWA IA"><button class="btn btn-primary" type="submit" aria-label="Envoyer"><i class="bi bi-send"></i></button></form></div>';
    document.body.append(btn, panel);
    const chat = Chat(panel.querySelector('[data-mia-chat]'));
    const toggle = (on) => { panel.classList.toggle('d-none', !on); btn.classList.toggle('d-none', on); try { sessionStorage.setItem('mia-open', on ? '1' : '0'); } catch (e) { /* ignoré */ } if (on) chat.focus(); };
    btn.addEventListener('click', () => toggle(true));
    panel.querySelector('[data-mia-close]').addEventListener('click', () => toggle(false));
    panel.querySelector('[data-mia-new]').addEventListener('click', () => chat.clear());
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.classList.contains('d-none')) toggle(false); });
    if (sessionStorage.getItem('mia-open') === '1') toggle(true);
  })();
})();
