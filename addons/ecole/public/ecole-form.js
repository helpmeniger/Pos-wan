/*
 * POS Marwa — module Établissement : photo de la fiche (caméra ou fichier) et enregistrement
 * du visage pour le pointage.
 *  - L'empreinte du visage est calculée dans le navigateur pour chaque photo ; elle n'est
 *    envoyée avec la fiche QUE si la case de consentement est cochée.
 *  - Chaque photo est contrôlée : un seul visage, net, de face, assez grand ; elle doit
 *    ressembler aux autres photos de la même personne, et ne pas ressembler à une autre
 *    personne déjà enregistrée (évite les erreurs d'inscription).
 */
(function () {
  'use strict';
  const MAX = 6;          // photos de visage par enregistrement
  const COHERENCE = 0.6;  // distance maximale entre deux photos de la même personne

  function init(w) {
    const video = w.querySelector('video'), img = w.querySelector('img[data-preview]'), vide = w.querySelector('.ec-photo-empty');
    const bStart = w.querySelector('[data-cam-start]'), bShot = w.querySelector('[data-cam-shot]');
    const file = w.querySelector('[data-photo-file]'), data = w.querySelector('[data-photo-data]');
    const champ = w.querySelector('[data-visages]'), compteur = w.querySelector('[data-visage-n]');
    const form = w.closest('form');
    const consent = form ? form.querySelector('[data-consentement]') : null;
    const visage = w.dataset.visage === '1' && !!window.EcoleVisage && !!champ;
    const moi = w.dataset.personne || '';
    const base = compteur ? parseInt(compteur.textContent, 10) || 0 : 0;
    const empreintes = [];
    let flux = null;

    let msg = null;
    if (visage) { msg = document.createElement('div'); msg.className = 'small mt-1'; w.appendChild(msg); }
    const dire = (t, cls) => { if (msg) { msg.className = 'small mt-1 ' + (cls || 'text-secondary'); msg.textContent = t; } };

    function majCompteur() {
      if (compteur) compteur.textContent = String(base + (consent && !consent.checked ? 0 : empreintes.length));
    }

    function arreter() {
      if (flux) flux.getTracks().forEach((t) => t.stop());
      flux = null; video.hidden = true; bShot.hidden = true; bStart.hidden = false;
    }

    function montrer(src) { img.src = src; img.hidden = false; if (vide) vide.hidden = true; }

    async function apprendre(source) {
      if (!visage) return;
      if (empreintes.length >= MAX) { dire(MAX + ' photos de visage prises : c\'est suffisant.', 'text-success'); return; }
      dire('Analyse du visage…');
      try {
        const r = await EcoleVisage.visageUnique(source);
        if (r.erreur) { dire('Photo gardée pour la fiche, mais pas pour le pointage : ' + r.erreur, 'text-warning'); return; }
        for (const e of empreintes) {
          if (EcoleVisage.distance(r.empreinte, e) > COHERENCE) {
            dire('Ce visage ne ressemble pas aux photos précédentes : photo écartée pour le pointage. Une seule personne par fiche.', 'text-danger');
            return;
          }
        }
        try {
          await EcoleVisage.galerie(true);
          const s = parseFloat(w.dataset.seuil || '0.5');
          const m = EcoleVisage.comparer(r.empreinte, s, 0);
          if (m && m.accepte && m.cle !== moi) {
            dire('Attention : ce visage ressemble fortement à une personne déjà enregistrée (' + m.type + ' n° ' + m.id + '). Photo écartée pour le pointage : vérifiez qu\'il ne s\'agit pas d\'un doublon.', 'text-danger');
            return;
          }
        } catch (e) { /* galerie non accessible (droits) : contrôle de doublon impossible, on continue */ }
        empreintes.push(r.empreinte);
        majCompteur();
        dire('Visage enregistré pour le pointage (' + empreintes.length + ' photo(s)). ' + (empreintes.length < 3 ? 'Une ou deux photos de plus, sous un autre éclairage, améliorent la reconnaissance.' : '')
          + (consent && !consent.checked ? ' Cochez le consentement pour qu\'il soit enregistré.' : ''), 'text-success');
      } catch (e) {
        dire('Reconnaissance faciale indisponible sur cet appareil : ' + e.message, 'text-warning');
      }
    }

    bStart.addEventListener('click', async () => {
      try {
        flux = await navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 1280 }, height: { ideal: 720 }, facingMode: 'user' }, audio: false });
        video.srcObject = flux; await video.play();
        video.hidden = false; img.hidden = true; if (vide) vide.hidden = true;
        bShot.hidden = false; bStart.hidden = true;
        if (visage) EcoleVisage.charger((t) => dire(t)).then(() => dire('Prêt : la personne regarde la caméra, de face.')).catch((e) => dire(e.message, 'text-warning'));
      } catch (e) {
        alert('Caméra inaccessible : ' + (e && e.name === 'NotAllowedError' ? 'autorisez la caméra pour ce site.' : (e.message || e)));
      }
    });

    bShot.addEventListener('click', async () => {
      if (!video.videoWidth) return;
      const plein = document.createElement('canvas');
      const k = Math.min(1, 960 / Math.max(video.videoWidth, video.videoHeight));
      plein.width = Math.round(video.videoWidth * k); plein.height = Math.round(video.videoHeight * k);
      plein.getContext('2d').drawImage(video, 0, 0, plein.width, plein.height);
      // Photo de la fiche : carrée, centrée, 480 px
      const c = document.createElement('canvas'), s = Math.min(plein.width, plein.height);
      c.width = c.height = Math.min(480, s);
      c.getContext('2d').drawImage(plein, (plein.width - s) / 2, (plein.height - s) / 2, s, s, 0, 0, c.width, c.height);
      const url = c.toDataURL('image/jpeg', 0.85);
      data.value = url; if (file) file.value = '';
      arreter(); montrer(url);
      await apprendre(plein);
    });

    if (file) file.addEventListener('change', () => {
      const f = file.files && file.files[0];
      if (!f) return;
      data.value = '';
      const u = URL.createObjectURL(f), im = new Image();
      im.onload = async () => { montrer(u); arreter(); await apprendre(im); };
      im.src = u;
    });

    if (consent) consent.addEventListener('change', majCompteur);
    if (form && champ) form.addEventListener('submit', () => {
      champ.value = consent && !consent.checked ? '' : JSON.stringify(empreintes.map((e) => EcoleVisage.encoder(e)));
    });
    // Réinitialisation (fenêtre d'inscription de la caisse réutilisée)
    w.addEventListener('ecole:reset', () => { arreter(); empreintes.length = 0; data.value = ''; if (champ) champ.value = ''; img.hidden = true; img.removeAttribute('src'); if (vide) vide.hidden = false; majCompteur(); dire(''); });
  }

  document.querySelectorAll('[data-photo-widget]').forEach(init);
  window.EcoleForm = { init };
})();
