/*
 * POS Marwa — module Établissement : pointage par caméra (reconnaissance faciale).
 *
 * Pour limiter les erreurs, une personne n'est pointée que si TOUTES ces conditions sont réunies :
 *  1. le visage est net, de face, assez grand et entier dans l'image (contrôle de qualité) ;
 *  2. sur au moins 3 des 5 dernières images de bonne qualité du même visage (suivi image par
 *     image), la même personne est retenue avec une distance sous le seuil ET un écart suffisant
 *     avec la 2ᵉ personne la plus proche ;
 *  3. l'empreinte moyenne de ces images confirme encore la même personne.
 * Sinon, après 6 images de bonne qualité, le visage est classé « inconnu » et gardé (vignette)
 * pour vérification humaine — il n'est jamais attribué au hasard.
 * Une personne déjà pointée n'est pas renvoyée au serveur avant 2 minutes.
 */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const meta = (n) => { const m = document.querySelector(`meta[name="${n}"]`); return m ? m.content : ''; };
  const BASE = meta('base-url').replace(/\/$/, '');
  const CSRF = () => meta('csrf-token') || (($('input[name="_csrf"]') || {}).value || '');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const cfgEl = $('#ecole-pointage-data');
  if (!cfgEl || !window.EcoleVisage) return;
  const CFG = JSON.parse(cfgEl.textContent);
  const SEUIL = CFG.reglages.seuil || 0.5, MARGE = CFG.reglages.marge || 0.05;
  const VOTES = 3, FENETRE = 5, INCONNU_APRES = 6, PAUSE_PERSONNE = 120000, OUBLI_SUIVI = 2000;
  const APPRENDRE_DIST = 0.4, APPRENDRE_MARGE = 0.15;

  const video = $('#ptVideo'), ov = $('#ptOverlay'), ctx = ov.getContext('2d'), msg = $('#ptMsg'), etat = $('#ptEtat');
  const selPoint = $('#ptPoint'), selRole = $('#ptRole'), selDev = $('#ptDevice'), bStart = $('#ptStart'), bStop = $('#ptStop');
  const S = { image: 0, erreur: false, marche: false, flux: null, point: null, suivis: [], nextId: 1, vus: new Map(), appris: new Set(), nb: { a: 0, d: 0, i: 0 } };

  const dire = (t) => { msg.hidden = !t; msg.textContent = t || ''; };
  // (erreurs réseau de pointage : affichées puis effacées à la prochaine image analysée)
  const pause = (ms) => new Promise((r) => setTimeout(r, ms));

  async function post(u, data) {
    const fd = new FormData(); fd.append('_csrf', CSRF());
    Object.entries(data).forEach(([k, v]) => { if (Array.isArray(v)) v.forEach((x) => fd.append(k + '[]', x)); else if (v !== null && v !== undefined) fd.append(k, v); });
    const r = await fetch(BASE + u, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF() }, credentials: 'same-origin' });
    const j = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide (' + r.status + ')' }));
    if (!j.ok) throw new Error(j.error || 'Erreur ' + r.status);
    return j;
  }

  // ------------------------------------------------------------------ caméras
  const point = () => CFG.points.find((p) => String(p.id) === selPoint.value) || null;
  async function listerCameras() {
    try {
      const devs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
      const p = point(), voulu = p && p.device ? p.device.toLowerCase() : '';
      selDev.innerHTML = devs.map((d, i) => `<option value="${esc(d.deviceId)}" ${voulu && (d.label || '').toLowerCase().includes(voulu) ? 'selected' : ''}>${esc(d.label || 'Caméra ' + (i + 1))}</option>`).join('');
      selDev.hidden = devs.length < 2 || !!(p && p.ip);
    } catch (e) { selDev.hidden = true; }
  }
  selPoint.addEventListener('change', () => { const p = point(); selRole.disabled = !!p; if (p) selRole.value = p.role; listerCameras(); });

  async function ouvrirLocale() {
    const c = { width: { ideal: 1280 }, height: { ideal: 720 } };
    if (selDev.value) c.deviceId = { exact: selDev.value };
    S.flux = await navigator.mediaDevices.getUserMedia({ video: c, audio: false });
    video.srcObject = S.flux; video.hidden = false; await video.play();
    await listerCameras();
    return () => video;
  }

  // Caméra IP : image relayée par le serveur (identifiants jamais exposés au navigateur)
  const tampon = document.createElement('canvas');
  async function imageIP(p) {
    const r = await fetch(BASE + '/ecole/points/' + p.id + '/image?t=' + Date.now(), { credentials: 'same-origin' });
    if (!r.ok) throw new Error((await r.text()) || 'Caméra IP injoignable');
    const bmp = await createImageBitmap(await r.blob());
    tampon.width = bmp.width; tampon.height = bmp.height;
    tampon.getContext('2d').drawImage(bmp, 0, 0); bmp.close && bmp.close();
    return tampon;
  }

  // ------------------------------------------------------------------ suivi des visages
  function associer(visages, t) {
    S.suivis = S.suivis.filter((s) => t - s.vu < OUBLI_SUIVI);
    const libres = new Set(S.suivis);
    for (const v of visages) {
      const cx = v.box.x + v.box.width / 2, cy = v.box.y + v.box.height / 2;
      let best = null, bd = Infinity;
      for (const s of libres) {
        const d = Math.hypot(cx - s.cx, cy - s.cy);
        if (d < Math.max(v.box.width, s.w) * 0.8 && d < bd) { bd = d; best = s; }
      }
      if (!best) { best = { id: S.nextId++, images: [], decision: null, texte: '' }; S.suivis.push(best); }
      libres.delete(best);
      Object.assign(best, { cx, cy, w: v.box.width, box: v.box, vu: t, image: S.image, visage: v });
    }
  }

  function moyenne(emps) {
    const m = new Float32Array(128);
    emps.forEach((e) => { for (let i = 0; i < 128; i++) m[i] += e[i] / emps.length; });
    return m;
  }

  async function decider(s, source) {
    const v = s.visage;
    if (!v.qualite.ok) { if (!s.decision) s.texte = v.qualite.raison; return; }
    const r = EcoleVisage.comparer(v.empreinte, SEUIL, MARGE);
    s.bonnes = (s.bonnes || 0) + 1;
    s.images.push({ r, emp: v.empreinte, frontal: v.qualite.frontal, box: v.box, thumb: null });
    if (s.images.length > 8) s.images.shift();
    const derniere = s.images[s.images.length - 1];
    if (!s.meilleure || derniere.frontal > s.meilleure.frontal) { derniere.thumb = EcoleVisage.vignette(source, v.box); s.meilleure = derniere; }
    if (s.decision) return;
    const fen = s.images.slice(-FENETRE), votes = new Map();
    fen.forEach((x) => { if (x.r && x.r.accepte) votes.set(x.r.cle, (votes.get(x.r.cle) || 0) + 1); });
    let cle = null, n = 0;
    votes.forEach((c, k) => { if (c > n) { n = c; cle = k; } });
    if (cle && n >= VOTES) {
      const ims = fen.filter((x) => x.r && x.r.cle === cle);
      const conf = EcoleVisage.comparer(moyenne(ims.map((x) => x.emp)), SEUIL, MARGE);
      if (conf && conf.accepte && conf.cle === cle) {
        const med = (a) => a.sort((x, y) => x - y)[Math.floor(a.length / 2)];
        s.decision = 'reconnu';
        s.resultat = { cle, type: conf.type, id: conf.id, distance: med(ims.map((x) => x.r.distance)), marge: med(ims.map((x) => x.r.marge)) };
        s.texte = 'Reconnu(e)…';
        pointer(s); // sans attendre la réponse : l'analyse continue
        return;
      }
    }
    s.texte = cle ? 'Vérification…' : 'Analyse…';
    if ((s.bonnes >= INCONNU_APRES && !cle) || s.bonnes >= 15) { // 15 images sans certitude : douteux → vérification humaine
      s.decision = 'inconnu'; s.texte = 'Inconnu';
      S.nb.i++; $('#ptNbI').textContent = S.nb.i;
      journal({ inconnu: true, thumb: s.meilleure && s.meilleure.thumb });
      post('/ecole/pointage/evenement', { inconnu: 1, point_id: S.point ? S.point.id : null, role: S.point ? null : selRole.value, thumb: s.meilleure ? s.meilleure.thumb : '' }).catch((e) => { S.erreur = true; dire(e.message); });
    }
  }

  async function pointer(s) {
    const r = s.resultat, t = Date.now();
    const deja = S.vus.get(r.cle);
    if (deja && t - deja.t < PAUSE_PERSONNE) { s.texte = deja.nom; carte(deja.personne, 'Déjà pointé(e) il y a ' + Math.round((t - deja.t) / 1000) + ' s', r); return; }
    S.vus.set(r.cle, { t, nom: '…' });
    try {
      const j = await post('/ecole/pointage/evenement', { type: r.type, id: r.id, distance: r.distance.toFixed(3), marge: r.marge.toFixed(3),
        point_id: S.point ? S.point.id : null, role: S.point ? null : selRole.value, thumb: s.meilleure ? s.meilleure.thumb : '' });
      const p = j.personne;
      S.vus.set(r.cle, { t, nom: p.nom, personne: p });
      s.texte = p.nom;
      const evt = j.evenement === 'arrivee' ? 'Arrivée ' + (j.arrivee || '').slice(0, 5) + (j.statut === 'retard' ? ' — en retard' : '')
        : j.evenement === 'depart' ? 'Départ ' + (j.depart || '').slice(0, 5) : 'Déjà pointé(e) aujourd\'hui';
      if (j.evenement === 'arrivee') { S.nb.a++; $('#ptNbA').textContent = S.nb.a; }
      if (j.evenement === 'depart') { S.nb.d++; $('#ptNbD').textContent = S.nb.d; }
      carte(p, evt, r, j.statut);
      journal({ personne: p, evt, retard: j.statut === 'retard' });
      apprendre(s, r);
    } catch (e) { S.vus.delete(r.cle); s.decision = null; s.images = []; S.erreur = true; dire(e.message); }
  }

  /** Apprentissage prudent : seulement si la reconnaissance est très sûre, une fois par jour et par personne. */
  function apprendre(s, r) {
    if (!CFG.apprendre || r.distance > APPRENDRE_DIST || r.marge < APPRENDRE_MARGE || !s.meilleure) return;
    const k = new Date().toISOString().slice(0, 10) + ':' + r.cle;
    if (S.appris.has(k)) return;
    S.appris.add(k);
    post('/ecole/visages', { type: r.type, id: r.id, source: 'pointage', empreintes: [EcoleVisage.encoder(s.meilleure.emp)] }).catch(() => { /* consentement retiré, droits… : sans effet */ });
  }

  // ------------------------------------------------------------------ affichage
  function carte(p, evt, r, statut) {
    if (!p) return;
    const c = $('#ptCarte'); c.hidden = false;
    const ph = $('[data-c="photo"]', c); ph.hidden = !p.photo; if (p.photo) ph.src = p.photo;
    $('[data-c="nom"]', c).textContent = p.nom;
    $('[data-c="detail"]', c).textContent = p.matricule + ' · ' + p.detail;
    $('[data-c="contact"]', c).textContent = p.contact || '';
    $('[data-c="evt"]', c).innerHTML = `<span class="badge ${statut === 'retard' ? 'bg-warning text-dark' : 'bg-success'} fs-6">${esc(evt)}</span>`;
    $('[data-c="score"]', c).textContent = r ? 'distance ' + r.distance.toFixed(2) + ' · écart ' + r.marge.toFixed(2) : '';
  }

  function journal(x) {
    const ul = $('#ptJournal');
    if (ul.firstElementChild && ul.firstElementChild.classList.contains('text-secondary')) ul.innerHTML = '';
    const li = document.createElement('li');
    li.className = 'list-group-item d-flex gap-2 align-items-center';
    const h = new Date().toTimeString().slice(0, 5);
    li.innerHTML = x.inconnu
      ? `${x.thumb ? `<img class="ec-mini" src="${esc(x.thumb)}" alt="">` : ''}<span class="text-danger">Visage inconnu</span><span class="ms-auto text-secondary">${h}</span>`
      : `${x.personne.photo ? `<img class="ec-mini" src="${esc(x.personne.photo)}" alt="">` : '<span class="ec-mini ec-mini-empty"><i class="bi bi-person"></i></span>'}<span><b>${esc(x.personne.nom)}</b><br><span class="${x.retard ? 'text-warning' : 'text-secondary'}">${esc(x.evt)}</span></span><span class="ms-auto text-secondary">${h}</span>`;
    ul.prepend(li);
    while (ul.children.length > 40) ul.lastElementChild.remove();
  }

  function dessiner(source, w, h, ip) {
    if (ov.width !== w || ov.height !== h) { ov.width = w; ov.height = h; }
    ctx.clearRect(0, 0, w, h);
    if (ip) ctx.drawImage(source, 0, 0, w, h);
    ctx.lineWidth = Math.max(2, w / 320);
    ctx.font = `${Math.max(14, Math.round(w / 45))}px sans-serif`;
    for (const s of S.suivis) {
      const b = s.box, col = s.decision === 'reconnu' ? '#16a34a' : s.decision === 'inconnu' ? '#dc2626' : '#f59e0b';
      ctx.strokeStyle = col; ctx.strokeRect(b.x, b.y, b.width, b.height);
      const t = s.texte || '';
      if (t) {
        const tw = ctx.measureText(t).width + 10, th = parseInt(ctx.font, 10) + 8;
        ctx.fillStyle = col; ctx.fillRect(b.x, Math.max(0, b.y - th), tw, th);
        ctx.fillStyle = '#fff'; ctx.fillText(t, b.x + 5, Math.max(0, b.y - th) + th - 7);
      }
    }
  }

  // ------------------------------------------------------------------ boucle
  async function boucle(prendre) {
    const p = S.point, intervalle = p && p.ip ? 1000 / Math.max(1, Math.min(10, p.fps || 3)) : 120;
    let erreurs = 0;
    while (S.marche) {
      const t0 = performance.now();
      try {
        const src = await prendre();
        if (!S.marche) break;
        const w = src.videoWidth || src.width, h = src.videoHeight || src.height;
        if (!w) { await pause(200); continue; }
        await EcoleVisage.galerie(false).catch(() => {});
        const visages = await EcoleVisage.analyser(src, { minLargeur: 60, minScore: 0.7, maxVisages: 10 });
        S.image++;
        associer(visages, Date.now());
        for (const s of S.suivis) if (s.image === S.image) await decider(s, src);
        dessiner(src, w, h, p && p.ip);
        erreurs = 0;
        if (S.erreur) { S.erreur = false; dire(''); }
      } catch (e) {
        erreurs++; S.erreur = true;
        dire(e.message + (erreurs > 2 ? ' — nouvelle tentative…' : ''));
        await pause(Math.min(10000, 1000 * erreurs));
      }
      const reste = intervalle - (performance.now() - t0);
      if (reste > 0) await pause(reste);
    }
  }

  bStart.addEventListener('click', async () => {
    bStart.disabled = true;
    try {
      S.point = point();
      await EcoleVisage.charger((t) => dire(t));
      dire('Chargement des visages enregistrés…');
      const g = await EcoleVisage.galerie(true);
      if (!g.cles.length) throw new Error('Aucun visage enregistré : prenez d\'abord les photos sur les fiches (avec consentement).');
      let prendre;
      if (S.point && S.point.ip) { video.hidden = true; prendre = () => imageIP(S.point); } else { prendre = await ouvrirLocale(); }
      S.marche = true; S.suivis = [];
      bStart.hidden = true; bStop.hidden = false; selPoint.disabled = true; selDev.disabled = true;
      etat.textContent = 'En marche · ' + g.personnes + ' personne(s) · ' + EcoleVisage.moteur.backend;
      dire('');
      boucle(prendre);
    } catch (e) {
      dire(e && e.name === 'NotAllowedError' ? 'Caméra refusée : autorisez la caméra pour ce site.' : e.message);
      arreter();
    } finally { bStart.disabled = false; }
  });

  function arreter() {
    S.marche = false;
    if (S.flux) S.flux.getTracks().forEach((t) => t.stop());
    S.flux = null; S.suivis = [];
    ctx.clearRect(0, 0, ov.width, ov.height);
    bStart.hidden = false; bStop.hidden = true; selPoint.disabled = false; selDev.disabled = false;
    etat.textContent = 'Arrêté';
  }
  bStop.addEventListener('click', () => { arreter(); dire('Pointage arrêté.'); });
  window.addEventListener('pagehide', arreter);
  listerCameras();
})();
