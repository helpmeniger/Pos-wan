/* =====================================================================
   MARWA AI — page /ia : conversations, réponses en continu (Server-Sent Events sur fetch),
   blocs (indicateurs, tableaux, graphiques), sources, liens, suggestions, avis, actions confirmées.
   Le texte du modèle s'affiche au fur et à mesure qu'il arrive du serveur (pas d'effet simulé).
   ===================================================================== */
(function () {
  'use strict';
  const app = document.querySelector('[data-mai-app]');
  if (!app) return;
  const meta = (n) => (document.querySelector(`meta[name="${n}"]`) || {}).content || '';
  const BASE = (meta('base-url') || '/').replace(/\/$/, '');
  const CSRF = meta('csrf-token');
  const $ = (s, r = app) => r.querySelector(s);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const log = $('[data-mai-log]');
  const input = $('[data-mai-input]');
  const form = $('[data-mai-form]');
  const sendBtn = $('[data-mai-send]');
  const stopBtn = $('[data-mai-stop]');
  const convs = $('[data-mai-convs]');
  const titleEl = $('[data-mai-title]');
  const welcome = $('[data-mai-welcome]');
  const welcomeHtml = welcome ? welcome.outerHTML : '';
  const KEY = 'mai-conv';
  let conv = null;
  let busy = false;
  let ctrl = null;
  const page = (() => { try { const r = new URL(document.referrer); return r.origin === location.origin ? r.pathname : ''; } catch (e) { return ''; } })();

  const post = (path, body) => fetch(BASE + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body || {}) })
    .then(async (r) => { let j = {}; try { j = await r.json(); } catch (e) { j = { ok: false, error: 'Réponse invalide du serveur.' }; } return j; });
  const scroll = () => { log.scrollTop = log.scrollHeight; };

  // Texte rédigé par un modèle : échappé, puis mise en forme minimale (gras, listes, paragraphes). Aucun lien ni HTML du modèle.
  function md(t) {
    const lines = esc(t).split(/\n/);
    let h = ''; let list = null;
    const close = () => { if (list) { h += `</${list}>`; list = null; } };
    for (const raw of lines) {
      const l = raw.replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/`([^`]+)`/g, '<code>$1</code>');
      let m;
      if ((m = l.match(/^\s*[-*•]\s+(.*)$/))) { if (list !== 'ul') { close(); h += '<ul>'; list = 'ul'; } h += `<li>${m[1]}</li>`; }
      else if ((m = l.match(/^\s*\d+[.)]\s+(.*)$/))) { if (list !== 'ol') { close(); h += '<ol>'; list = 'ol'; } h += `<li>${m[1]}</li>`; }
      else if ((m = l.match(/^\s*#{1,4}\s+(.*)$/))) { close(); h += `<p class="h">${m[1]}</p>`; }
      else if (l.trim() === '') { close(); }
      else { close(); h += `<p>${l}</p>`; }
    }
    close();
    return h;
  }

  function chart(c) {
    const vals = c.values.map(Number);
    const max = Math.max(...vals, 0) || 1;
    const W = 560; const H = 180; const P = 26; const n = vals.length; const bw = (W - P * 2) / n;
    let s = `<svg viewBox="0 0 ${W} ${H + 34}" class="mai-chart" role="img" aria-label="${esc(c.title || 'Graphique')}">`;
    s += `<line x1="${P}" y1="${H}" x2="${W - P}" y2="${H}" class="ax"/>`;
    if (c.type === 'line') {
      const pts = vals.map((v, i) => `${P + bw * i + bw / 2},${H - (v / max) * (H - 20)}`).join(' ');
      s += `<polyline points="${pts}" class="ln"/>`;
      vals.forEach((v, i) => { s += `<circle cx="${P + bw * i + bw / 2}" cy="${H - (v / max) * (H - 20)}" r="3" class="pt"><title>${esc(c.labels[i])} : ${esc(v.toLocaleString('fr-FR'))}</title></circle>`; });
    } else {
      vals.forEach((v, i) => { const h = Math.max(1, (v / max) * (H - 20)); s += `<rect x="${P + bw * i + bw * 0.15}" y="${H - h}" width="${bw * 0.7}" height="${h}" rx="3" class="br"><title>${esc(c.labels[i])} : ${esc(v.toLocaleString('fr-FR'))}</title></rect>`; });
    }
    const step = Math.ceil(n / 12);
    c.labels.forEach((l, i) => { if (i % step === 0) s += `<text x="${P + bw * i + bw / 2}" y="${H + 14}" class="lb">${esc(String(l).slice(0, 10))}</text>`; });
    return `<div class="mai-block">${c.title ? `<div class="mai-bt">${esc(c.title)}</div>` : ''}${s}</svg></div>`;
  }

  function block(b) {
    if (b.type === 'cards') return '<div class="mai-cards">' + b.items.map((k) => `<div><span>${esc(k.label)}</span><b>${esc(k.value)}</b></div>`).join('') + '</div>';
    if (b.type === 'table') {
      return `<div class="mai-block">${b.title ? `<div class="mai-bt">${esc(b.title)}</div>` : ''}<div class="mai-tbl"><table><thead><tr>${b.headers.map((x) => `<th>${esc(x)}</th>`).join('')}</tr></thead><tbody>`
        + b.rows.map((r) => '<tr>' + r.map((c) => `<td>${esc(c)}</td>`).join('') + '</tr>').join('') + '</tbody></table></div>'
        + (b.more ? `<div class="mai-more">… et ${esc(b.more)} ligne(s) de plus.</div>` : '') + '</div>';
    }
    if (b.type === 'chart') return chart(b);
    if (b.type === 'note') return `<div class="mai-note"><i class="bi bi-info-circle"></i> ${esc(b.text)}</div>`;
    return '';
  }

  const SRC = { sales: 'ventes', sale_items: 'lignes de vente', products: 'articles', stock: 'stock', stock_movements: 'mouvements de stock', customers: 'clients', suppliers: 'fournisseurs', expenses: 'dépenses', cash_sessions: 'sessions de caisse', cash_movements: 'mouvements de caisse', payments: 'paiements', purchase_orders: 'commandes fournisseur', documentation: 'documentation' };
  function footer(d, el) {
    let h = '';
    if (d.links && d.links.length) h += '<div class="mai-links">' + d.links.map((l) => `<a class="btn btn-sm btn-outline-primary" href="${esc(BASE + (String(l[1]).startsWith('/') ? l[1] : '/' + l[1]))}"><i class="bi bi-box-arrow-up-right"></i> ${esc(l[0])}</a>`).join('') + '</div>';
    if (d.sources && d.sources.length) h += `<div class="mai-src"><i class="bi bi-database"></i> Sources : ${d.sources.map((s) => esc(SRC[s] || s)).join(', ')}</div>`;
    if (d.proposal) h += proposal(d.proposal);
    h += '<div class="mai-tools">' + '<button type="button" data-mai-copy title="Copier la réponse"><i class="bi bi-clipboard"></i> Copier</button>'
      + (d.message_id ? `<button type="button" data-mai-fb="1" data-mid="${esc(d.message_id)}" title="Réponse utile"><i class="bi bi-hand-thumbs-up"></i></button><button type="button" data-mai-fb="-1" data-mid="${esc(d.message_id)}" title="Réponse à améliorer"><i class="bi bi-hand-thumbs-down"></i></button>` : '') + '</div>';
    if (d.suggestions && d.suggestions.length) h += '<div class="mai-chips">' + d.suggestions.map((s) => `<button type="button" data-mai-ask="${esc(s)}">${esc(s)}</button>`).join('') + '</div>';
    el.insertAdjacentHTML('beforeend', h);
  }

  function proposal(p) {
    if (!p.token) return `<div class="mai-prop done"><b>${esc(p.label)}</b><div>${esc(p.summary)}</div><small>Proposition d'une conversation précédente : redemandez l'opération pour la confirmer.</small></div>`;
    return `<div class="mai-prop" data-pid="${esc(p.id)}" data-token="${esc(p.token)}"><b><i class="bi bi-shield-lock"></i> ${esc(p.label)}</b><div>${esc(p.summary)}</div>`
      + (p.sensitive ? '<input type="password" class="form-control form-control-sm mt-2" data-mai-secret autocomplete="current-password" placeholder="Votre mot de passe ou code PIN (opération sensible)">' : '')
      + '<div class="mt-2 d-flex gap-2"><button type="button" class="btn btn-sm btn-success" data-mai-confirm><i class="bi bi-check2"></i> Confirmer</button><button type="button" class="btn btn-sm btn-outline-secondary" data-mai-cancel>Annuler</button></div><div class="mai-prop-msg small mt-1"></div></div>';
  }

  function bubbleMe(q) {
    log.insertAdjacentHTML('beforeend', `<div class="mai-msg me"><div>${esc(q)}</div></div>`);
  }
  function bubbleBot() {
    const w = document.createElement('div');
    w.className = 'mai-msg bot';
    w.innerHTML = '<div class="mai-a"><div class="mai-status"><span class="mai-dots"><i></i><i></i><i></i></span> <span data-st>Je réfléchis…</span></div><div class="mai-body"></div></div>';
    log.appendChild(w);
    scroll();
    return w.querySelector('.mai-a');
  }

  // Lecture du flux SSE envoyé par /ia/v2/stream
  async function ask(q) {
    q = String(q || '').trim();
    if (!q || busy) return;
    busy = true;
    sendBtn.classList.add('d-none'); stopBtn.classList.remove('d-none');
    const w = $('[data-mai-welcome]'); if (w) w.remove();
    bubbleMe(q);
    const a = bubbleBot();
    const body = a.querySelector('.mai-body');
    const st = a.querySelector('[data-st]');
    let live = null; let liveText = '';
    ctrl = new AbortController();
    const handle = (ev, d) => {
      if (ev === 'meta') { if (!conv || conv !== d.conversation_id) { conv = d.conversation_id; remember(); addConv(conv, d.title); } titleEl.textContent = d.title || 'MARWA AI'; }
      else if (ev === 'status') st.textContent = d.text;
      else if (ev === 'text') body.insertAdjacentHTML('beforeend', md(d.text));
      else if (ev === 'html') body.insertAdjacentHTML('beforeend', `<div class="mai-html">${d.html}</div>`); // HTML préparé et échappé par le serveur
      else if (ev === 'block') body.insertAdjacentHTML('beforeend', block(d));
      else if (ev === 'delta') { if (!live) { live = document.createElement('div'); live.className = 'mai-live'; body.appendChild(live); } liveText += d.text; live.innerHTML = md(liveText); }
      else if (ev === 'replace') { if (!live) { live = document.createElement('div'); body.appendChild(live); } liveText = d.text; live.innerHTML = md(liveText); }
      else if (ev === 'open') {
        const u = d.url; const pdf = /export=pdf/.test(u);
        body.insertAdjacentHTML('beforeend', `<div class="mai-links"><a class="btn btn-sm ${pdf ? 'btn-danger' : 'btn-outline-success'}" href="${esc(u)}" ${pdf ? 'target="_blank" rel="noopener"' : ''}><i class="bi ${pdf ? 'bi-file-earmark-pdf' : 'bi-filetype-csv'}"></i> ${pdf ? 'Ouvrir le PDF' : 'Télécharger le fichier Excel'}</a></div>`
          + (pdf && /view=1/.test(u) ? `<iframe class="mai-pdf" src="${esc(u)}" title="Aperçu du PDF"></iframe>` : ''));
      }
      else if (ev === 'done') { a.querySelector('.mai-status').remove(); footer(d, a); refreshList(); }
      else if (ev === 'error') { a.querySelector('.mai-status')?.remove(); body.insertAdjacentHTML('beforeend', `<p class="text-danger">${esc(d.message)}</p>`); }
      scroll();
    };
    try {
      const res = await fetch(BASE + '/ia/v2/stream', { method: 'POST', credentials: 'same-origin', signal: ctrl.signal, headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ q, conversation_id: conv, page }) });
      if (!res.ok || !res.body) throw new Error(res.status === 419 ? 'Session expirée : rechargez la page.' : res.status === 403 ? 'Accès refusé.' : 'Le serveur n\'a pas répondu (' + res.status + ').');
      const reader = res.body.getReader();
      const dec = new TextDecoder();
      let buf = '';
      for (;;) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += dec.decode(value, { stream: true });
        let i;
        while ((i = buf.indexOf('\n\n')) >= 0) {
          const chunk = buf.slice(0, i); buf = buf.slice(i + 2);
          let ev = 'message'; let data = '';
          chunk.split('\n').forEach((l) => { if (l.startsWith('event: ')) ev = l.slice(7); else if (l.startsWith('data: ')) data += l.slice(6); });
          if (data) { try { handle(ev, JSON.parse(data)); } catch (e) { /* événement illisible ignoré */ } }
        }
      }
    } catch (e) {
      a.querySelector('.mai-status')?.remove();
      body.insertAdjacentHTML('beforeend', `<p class="text-danger">${esc(e.name === 'AbortError' ? 'Réponse interrompue.' : (e.message || 'Le serveur ne répond pas.'))}</p>`);
    }
    busy = false; ctrl = null;
    sendBtn.classList.remove('d-none'); stopBtn.classList.add('d-none');
    input.focus();
  }

  // Conversations
  const remember = () => { try { sessionStorage.setItem(KEY, conv ? String(conv) : ''); } catch (e) { /* ignoré */ } };
  function addConv(id, title) {
    if (convs.querySelector(`[data-id="${id}"]`)) return mark();
    convs.insertAdjacentHTML('afterbegin', `<div class="mai-conv" data-id="${esc(id)}"><button type="button" class="mai-conv-open" data-mai-open="${esc(id)}"><span>${esc(title || 'Nouvelle conversation')}</span><small>à l'instant</small></button></div>`);
    mark();
  }
  const mark = () => convs.querySelectorAll('.mai-conv').forEach((c) => c.classList.toggle('active', Number(c.dataset.id) === conv));
  async function refreshList() {
    try {
      const r = await (await fetch(BASE + '/ia/conversations', { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
      if (!r.ok) return;
      convs.innerHTML = r.items.map((c) => `<div class="mai-conv" data-id="${esc(c.id)}"><button type="button" class="mai-conv-open" data-mai-open="${esc(c.id)}"><span>${esc(c.title)}</span><small>${esc((c.updated_at || '').slice(0, 16).replace('T', ' '))}</small></button></div>`).join('');
      mark();
    } catch (e) { /* liste non rafraîchie */ }
  }
  async function open(id) {
    if (busy) return;
    const r = await (await fetch(BASE + '/ia/conversations/' + id, { credentials: 'same-origin', headers: { Accept: 'application/json' } })).json();
    if (!r.ok) { conv = null; remember(); return; }
    conv = Number(id); remember(); mark();
    const t = convs.querySelector(`[data-id="${id}"] span`); titleEl.textContent = t ? t.textContent : 'MARWA AI';
    log.innerHTML = '';
    for (const m of r.items) {
      if (m.role === 'user') { bubbleMe(m.content); continue; }
      const a = bubbleBot(); a.querySelector('.mai-status').remove();
      const body = a.querySelector('.mai-body'); const p = m.payload || {};
      if (p.html) body.insertAdjacentHTML('beforeend', `<div class="mai-html">${p.html}</div>`); else body.innerHTML = md(m.content);
      (p.blocks || []).forEach((b) => body.insertAdjacentHTML('beforeend', block(b)));
      if (p.open) body.insertAdjacentHTML('beforeend', `<div class="mai-links"><a class="btn btn-sm btn-outline-danger" href="${esc(p.open)}" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf"></i> Ouvrir le fichier</a></div>`);
      footer({ links: p.links, sources: m.sources, suggestions: [], proposal: p.proposal, message_id: m.id }, a);
    }
    app.classList.remove('side-open');
    scroll();
  }
  function reset() {
    if (busy) return;
    conv = null; remember(); mark();
    titleEl.textContent = 'MARWA AI';
    log.innerHTML = welcomeHtml;
    input.focus();
  }

  // Événements
  form.addEventListener('submit', (e) => { e.preventDefault(); const q = input.value; input.value = ''; input.style.height = ''; ask(q); });
  input.addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
  input.addEventListener('input', () => { input.style.height = ''; input.style.height = Math.min(160, input.scrollHeight) + 'px'; });
  stopBtn.addEventListener('click', () => { if (ctrl) ctrl.abort(); });
  app.addEventListener('click', async (e) => {
    let b;
    if ((b = e.target.closest('[data-mai-ask]'))) { e.preventDefault(); ask(b.dataset.maiAsk); return; }
    if ((b = e.target.closest('[data-mai-open]'))) { open(b.dataset.maiOpen); return; }
    if (e.target.closest('[data-mai-new]')) { reset(); app.classList.remove('side-open'); return; }
    if (e.target.closest('[data-mai-toggle]')) { app.classList.toggle('side-open'); return; }
    if (e.target.closest('[data-mai-rename]')) {
      if (!conv) return;
      const t = window.prompt('Nouveau titre de la conversation :', titleEl.textContent);
      if (t && t.trim()) { const r = await post(`/ia/conversations/${conv}/renommer`, { title: t.trim() }); if (r.ok) { titleEl.textContent = t.trim(); refreshList(); } }
      return;
    }
    if (e.target.closest('[data-mai-del]')) {
      if (!conv || !window.confirm('Supprimer définitivement cette conversation ?')) return;
      const r = await post(`/ia/conversations/${conv}/supprimer`);
      if (r.ok) { convs.querySelector(`[data-id="${conv}"]`)?.remove(); reset(); }
      return;
    }
    if ((b = e.target.closest('[data-mai-copy]'))) {
      const box = b.closest('.mai-a').querySelector('.mai-body').cloneNode(true);
      box.querySelectorAll('iframe, .mai-links').forEach((x) => x.remove());
      box.querySelectorAll('tr').forEach((tr) => { tr.textContent = Array.from(tr.children).map((c) => c.textContent).join('\t') + '\n'; });
      const text = box.innerText.trim();
      let ok = false;
      try { if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(text); ok = true; } } catch (err) { ok = false; }
      if (!ok) { const t = document.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; document.body.appendChild(t); t.select(); try { ok = document.execCommand('copy'); } catch (err) { ok = false; } t.remove(); }
      b.innerHTML = ok ? '<i class="bi bi-check2"></i> Copié' : 'Copie impossible';
      setTimeout(() => { b.innerHTML = '<i class="bi bi-clipboard"></i> Copier'; }, 2000);
      return;
    }
    if ((b = e.target.closest('[data-mai-fb]'))) {
      const rating = Number(b.dataset.maiFb);
      const comment = rating < 0 ? (window.prompt('Qu\'est-ce qui n\'allait pas ? (facultatif, relu par l\'administrateur)') || '') : '';
      const r = await post('/ia/avis', { message_id: Number(b.dataset.mid), rating, comment });
      if (r.ok) b.parentElement.querySelectorAll('[data-mai-fb]').forEach((x) => { x.disabled = true; x.classList.toggle('on', x === b); });
      return;
    }
    if ((b = e.target.closest('[data-mai-confirm]'))) {
      const box = b.closest('.mai-prop'); const msg = box.querySelector('.mai-prop-msg'); const sec = box.querySelector('[data-mai-secret]');
      b.disabled = true;
      const r = await post(`/ia/actions/${box.dataset.pid}/confirmer`, { token: box.dataset.token, secret: sec ? sec.value : '' });
      if (r.ok) {
        box.classList.add('done'); box.querySelectorAll('button, input').forEach((x) => x.remove());
        msg.innerHTML = `<span class="text-success"><i class="bi bi-check-circle"></i> ${esc(r.text || 'Opération effectuée.')}</span>` + (r.link && r.link[1] ? ` <a href="${esc(BASE + r.link[1])}">${esc(r.link[0] || 'Voir')}</a>` : '');
      } else {
        b.disabled = false;
        msg.innerHTML = `<span class="text-danger">${esc(r.error || 'Opération non effectuée.')}</span>`;
        if (r.code === 'AUTH_REQUIRED' && sec) sec.focus();
      }
      return;
    }
    if ((b = e.target.closest('[data-mai-cancel]'))) {
      const box = b.closest('.mai-prop');
      const r = await post(`/ia/actions/${box.dataset.pid}/annuler`);
      if (r.ok) { box.classList.add('done'); box.querySelectorAll('button, input').forEach((x) => x.remove()); box.querySelector('.mai-prop-msg').textContent = 'Opération annulée : rien n\'a été modifié.'; }
    }
  });

  let saved = null;
  try { saved = sessionStorage.getItem(KEY); } catch (e) { saved = null; }
  if (saved && convs.querySelector(`[data-id="${saved}"]`)) open(saved); else input.focus();
})();
