/*
 * POS Marwa — module Établissement : caisse (inscriptions, frais de scolarité, reçus).
 * Recherche d'un élève (nom, matricule, téléphone du parent, carte scannée ou visage),
 * dossier financier, encaissement (identifiant unique : un double clic n'encaisse jamais deux fois).
 */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const meta = (n) => { const m = document.querySelector(`meta[name="${n}"]`); return m ? m.content : ''; };
  const BASE = meta('base-url').replace(/\/$/, '');
  const CSRF = () => meta('csrf-token') || (($('input[name="_csrf"]') || {}).value || '');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const fmt = (n) => (window.PWMoney ? PWMoney.fmt(n) : Math.round(n).toLocaleString('fr-FR') + ' F');
  const num = (v) => { const n = parseFloat(String(v || '').replace(/\s/g, '').replace(',', '.')); return isFinite(n) ? n : 0; };
  const uuid = () => (crypto.randomUUID ? crypto.randomUUID() : 'x'.repeat(32).replace(/x/g, () => (Math.random() * 16 | 0).toString(16)));
  const cfgEl = $('#ecole-caisse-data');
  if (!cfgEl) return;
  const CFG = JSON.parse(cfgEl.textContent);

  async function getJSON(u) {
    const r = await fetch(BASE + u, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const j = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide du serveur (' + r.status + ').' }));
    if (!j.ok) throw new Error(j.error || 'Erreur ' + r.status);
    return j;
  }
  async function post(u, data) {
    const fd = new FormData();
    fd.append('_csrf', CSRF());
    Object.entries(data).forEach(([k, v]) => (Array.isArray(v) ? v.forEach((x) => fd.append(k + '[]', x)) : fd.append(k, v ?? '')));
    const r = await fetch(BASE + u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF() }, credentials: 'same-origin' });
    const j = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide du serveur (' + r.status + ').' }));
    if (!j.ok) throw new Error(j.error || 'Erreur ' + r.status);
    return j;
  }

  // ------------------------------------------------------------------ recherche
  const q = $('#ecQ'), res = $('#ecResults');
  let tq = null, dernier = [];
  async function chercher(texte) {
    if (texte.trim().length < 2) { res.innerHTML = ''; dernier = []; return []; }
    const j = await getJSON('/ecole/eleves/recherche?q=' + encodeURIComponent(texte.trim()));
    dernier = j.items;
    res.innerHTML = j.items.map((x) => `<button type="button" class="list-group-item list-group-item-action d-flex gap-2 align-items-center" data-id="${x.id}">
      ${x.photo ? `<img class="ec-mini" src="${esc(x.photo)}" alt="">` : '<span class="ec-mini ec-mini-empty"><i class="bi bi-person"></i></span>'}
      <span><b>${esc(x.nom)}</b><br><span class="small text-secondary">${esc(x.matricule)} · ${esc(x.classe || 'non inscrit(e) cette année')}${x.statut !== 'actif' ? ' · ' + esc(x.statut) : ''}</span></span></button>`).join('')
      || '<div class="list-group-item text-secondary small">Aucun résultat.</div>';
    return j.items;
  }
  q.addEventListener('input', () => { clearTimeout(tq); tq = setTimeout(() => chercher(q.value).catch(() => {}), 250); });
  q.addEventListener('keydown', async (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault(); clearTimeout(tq);
    const items = await chercher(q.value).catch(() => []);
    const exact = items.find((x) => x.matricule.toLowerCase() === q.value.trim().toLowerCase());
    if (exact || items.length === 1) ouvrir((exact || items[0]).id);
  });
  res.addEventListener('click', (e) => { const b = e.target.closest('[data-id]'); if (b) ouvrir(+b.dataset.id); });

  // ------------------------------------------------------------------ dossier
  const dos = $('#ecDossier'), vide = $('#ecVide'), F = (n) => $(`[data-f="${n}"]`, dos);
  const montant = $('#ecMontant'), msg = $('#ecMsg'), bPay = $('#ecPay');
  let courant = null, payUuid = uuid();

  function message(t, cls) { msg.hidden = !t; msg.className = 'alert mt-2 mb-0 alert-' + (cls || 'info'); msg.textContent = t || ''; }

  async function ouvrir(id) {
    try {
      const j = await getJSON('/ecole/caisse/eleve/' + id);
      courant = j;
      const e = j.eleve;
      vide.hidden = true; dos.hidden = false; message('');
      F('photo').hidden = !e.photo; if (e.photo) F('photo').src = e.photo; F('nophoto').hidden = !!e.photo;
      F('nom').textContent = e.prenom + ' ' + e.nom;
      F('info').textContent = [e.matricule, j.inscription ? j.inscription.classe : null, e.naissance ? 'né(e) le ' + e.naissance : null, e.statut !== 'actif' ? e.statut : null].filter(Boolean).join(' · ');
      F('tuteur').textContent = e.tuteur ? 'Parent / tuteur : ' + e.tuteur : '';
      F('med').textContent = e.infos_medicales || '';
      F('fiche').href = BASE + '/ecole/eleves/' + e.id;
      F('noins').hidden = !!j.inscription;
      F('frais').hidden = !j.inscription;
      F('echeances').innerHTML = j.echeances.map((x) => `<tr class="${x.echue ? 'table-danger' : ''}">
        <td>${x.reste > 0 ? `<input type="checkbox" class="form-check-input" value="${x.id}" data-reste="${x.reste}">` : '<i class="bi bi-check2 text-success"></i>'}</td>
        <td>${esc(x.libelle)}</td><td class="small">${esc(x.date)}</td><td class="text-end">${fmt(x.montant)}</td><td class="text-end fw-bold">${x.reste > 0 ? fmt(x.reste) : '—'}</td></tr>`).join('')
        || '<tr><td colspan="5" class="text-secondary">Aucune échéance.</td></tr>';
      if (j.solde) {
        F('du').textContent = fmt(j.solde.du); F('paye').textContent = fmt(j.solde.paye); F('reste').textContent = fmt(j.solde.reste); F('echu').textContent = fmt(j.solde.echu);
        montant.value = j.solde.echu > 0 ? Math.round(j.solde.echu) : '';
        bPay.disabled = j.solde.reste <= 0 || (j.inscription && j.inscription.statut !== 'active');
      }
      F('recus').innerHTML = j.recus.map((r) => `<div><a href="${BASE}/ecole/recus/${r.id}" target="_blank" rel="noopener">${esc(r.numero)}</a> · ${esc(r.date)} · <span class="${r.annule ? 'text-decoration-line-through text-secondary' : 'fw-bold'}">${fmt(r.montant)}</span>${r.annule ? ' (annulé)' : ''}</div>`).join('') || '<span class="text-secondary">Aucun reçu.</span>';
      payUuid = uuid();
      history.replaceState(null, '', BASE + '/ecole/caisse?eleve=' + e.id);
    } catch (err) { alert(err.message); }
  }

  F('echeances').addEventListener('change', () => {
    const coches = $$('input[type=checkbox]:checked', F('echeances'));
    if (coches.length) montant.value = Math.round(coches.reduce((s, c) => s + num(c.dataset.reste), 0));
  });

  bPay.addEventListener('click', async () => {
    if (!courant || !courant.inscription) return;
    const m = num(montant.value);
    if (m <= 0) { message('Saisissez le montant reçu.', 'warning'); montant.focus(); return; }
    if (courant.solde && m > courant.solde.reste + 0.001) { message('Le montant dépasse le reste à payer (' + fmt(courant.solde.reste) + ').', 'warning'); return; }
    bPay.disabled = true; message('Enregistrement…');
    try {
      const r = await post('/ecole/caisse/paiement', { inscription_id: courant.inscription.id, uuid: payUuid, montant: m, mode: $('#ecMode').value, reference: $('#ecRef').value,
        echeances: $$('input[type=checkbox]:checked', F('echeances')).map((c) => c.value) });
      $('#ecRef').value = '';
      await ouvrir(courant.eleve.id);
      message('Paiement enregistré : reçu ' + r.numero + (r.deja ? ' (déjà enregistré)' : '') + '.', 'success');
      recu(r.id, r.numero);
    } catch (err) { message(err.message, 'danger'); bPay.disabled = false; }
  });

  // ------------------------------------------------------------------ reçu
  function modale(html) {
    const m = document.createElement('div'); m.className = 'ec-modal'; m.innerHTML = `<div class="ec-modal-box">${html}</div>`;
    document.body.appendChild(m);
    const fermer = () => { m.remove(); document.removeEventListener('keydown', esc_); if (m._close) m._close(); };
    const esc_ = (e) => { if (e.key === 'Escape') fermer(); };
    document.addEventListener('keydown', esc_);
    m.addEventListener('click', (e) => { if (e.target === m || e.target.closest('[data-close]')) fermer(); });
    m.fermer = fermer;
    return m;
  }
  function recu(id, numero) {
    const u = BASE + '/ecole/recus/' + id;
    modale(`<div class="p-3 text-center"><div class="fs-1 text-success"><i class="bi bi-check-circle"></i></div><div class="h5">Reçu ${esc(numero || '')}</div>
      <div class="d-flex flex-wrap gap-2 justify-content-center mt-3">
        <a class="btn btn-primary" href="${u}?format=t80&imprimer=1" target="_blank" rel="noopener"><i class="bi bi-printer"></i> Ticket 80 mm</a>
        <a class="btn btn-outline-primary" href="${u}?format=a4&imprimer=1" target="_blank" rel="noopener"><i class="bi bi-file-earmark"></i> Reçu A4</a>
        <button type="button" class="btn btn-outline-secondary" data-close>Fermer</button></div></div>`);
  }

  // ------------------------------------------------------------------ inscription
  const box = $('#ecNew'), form = $('#ecNewForm'), cl = $('#ecNewClasse');
  function recap() {
    const o = cl.selectedOptions[0];
    if (!o || !o.value) { $('#ecNewRecap').textContent = ''; return; }
    const ins = $('[name="sans_frais_inscription"]', form).checked ? 0 : num(o.dataset.ins);
    const sco = Math.max(0, num(o.dataset.sco) - num($('#ecNewRemise').value));
    const extra = $$('[data-extra]', form).reduce((s, i) => s + num(i.value), 0);
    const tr = Math.max(1, parseInt(o.dataset.tr, 10) || 1);
    $('#ecNewRecap').innerHTML = `Inscription <b>${fmt(ins)}</b> · scolarité <b>${fmt(sco)}</b>${tr > 1 ? ' en ' + tr + ' tranches' : ''}${extra ? ' · autres frais <b>' + fmt(extra) + '</b>' : ''} · <span class="fs-6">total de l'année <b>${fmt(ins + sco + extra)}</b></span>`;
  }
  form.addEventListener('input', recap); form.addEventListener('change', recap);

  function nouvelle(eleve) {
    form.reset();
    $('#ecNewUuid').value = uuid();
    $('#ecNewEleve').value = eleve ? eleve.id : '';
    $$('.ec-new-only', form).forEach((x) => { x.hidden = !!eleve; });
    $$('[data-req]', form).forEach((x) => { x.required = !eleve; });
    const ex = $('#ecNewExisting'); ex.hidden = !eleve; ex.textContent = eleve ? 'Inscription de ' + eleve.prenom + ' ' + eleve.nom + ' (' + eleve.matricule + ')' : '';
    $$('[data-photo-widget]', form).forEach((w) => w.dispatchEvent(new Event('ecole:reset')));
    recap();
    box.hidden = false;
    const f = eleve ? cl : $('[name="nom"]', form); if (f) f.focus();
  }
  const fermerNew = () => { box.hidden = true; $$('[data-photo-widget]', form).forEach((w) => w.dispatchEvent(new Event('ecole:reset'))); };
  $('#ecNewBtn').addEventListener('click', () => nouvelle(null));
  box.addEventListener('click', (e) => { if (e.target === box || e.target.closest('[data-close]')) fermerNew(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !box.hidden) fermerNew(); });
  dos.addEventListener('click', (e) => { if (e.target.closest('[data-act="inscrire"]') && courant) nouvelle(courant.eleve); });
  form.addEventListener('submit', (e) => {
    const p = num($('[name="premier_paiement"]', form).value);
    if (p < 0) { e.preventDefault(); alert('Montant du premier versement invalide.'); return; }
    const b = $('button:not([type=button])', form); if (b) { setTimeout(() => { b.disabled = true; }, 0); }
  });

  // ------------------------------------------------------------------ carte scannée
  $('#ecScan').addEventListener('click', () => {
    if (!window.PMScanner) { q.focus(); return; }
    PMScanner.open({ title: 'Scanner la carte', continuous: false, plus: false, products: false, onCode: async (code) => {
      const items = await chercher(code).catch(() => []);
      const x = items.find((i) => i.matricule.toLowerCase() === String(code).trim().toLowerCase()) || (items.length === 1 ? items[0] : null);
      if (!x) return { ok: false, text: 'Carte inconnue : ' + code };
      ouvrir(x.id);
      return { ok: true, text: x.nom };
    } });
  });

  // ------------------------------------------------------------------ visage
  const bFace = $('#ecFace');
  if (bFace && window.EcoleVisage) bFace.addEventListener('click', async () => {
    const m = modale(`<div class="d-flex align-items-center p-2 border-bottom"><b><i class="bi bi-person-bounding-box"></i> Reconnaissance du visage</b><button type="button" class="btn-close ms-auto" data-close aria-label="Fermer"></button></div>
      <div class="ec-pt-stage"><video playsinline muted></video></div><div class="p-2 small" data-m>Chargement…</div>`);
    const video = $('video', m), out = $('[data-m]', m);
    let flux = null, fini = false;
    m._close = () => { fini = true; if (flux) flux.getTracks().forEach((t) => t.stop()); };
    try {
      await EcoleVisage.charger((t) => { out.textContent = t; });
      const g = await EcoleVisage.galerie(true);
      if (!g.cles.length) throw new Error('Aucun visage enregistré.');
      flux = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
      video.srcObject = flux; await video.play();
      out.textContent = 'Regardez la caméra, de face.';
      const s = CFG.seuil || 0.5, mg = CFG.marge || 0.05, votes = [];
      while (!fini) {
        const v = await EcoleVisage.analyser(video, { maxVisages: 1 });
        if (fini) break;
        const f = v[0];
        if (!f) { out.textContent = 'Aucun visage.'; votes.length = 0; } else if (!f.qualite.ok) { out.textContent = f.qualite.raison; } else {
          const r = EcoleVisage.comparer(f.empreinte, s, mg);
          votes.push(r && r.accepte ? r.cle : null);
          if (votes.length > 5) votes.shift();
          const ok = r && r.accepte && votes.filter((c) => c === r.cle).length >= 3;
          if (ok && r.type === 'eleve') { m.fermer(); ouvrir(r.id); return; }
          out.textContent = ok ? 'Personne reconnue, mais ce n\'est pas un(e) élève.' : (r && r.accepte ? 'Vérification…' : 'Visage non reconnu.');
        }
        await new Promise((ok) => setTimeout(ok, 150));
      }
    } catch (err) { out.textContent = err.message; }
  });

  if (CFG.eleve) ouvrir(CFG.eleve);
  if (CFG.recu) recu(CFG.recu, '');
})();
