/*
 * POS Marwa — module Établissement : moteur de reconnaissance faciale (dans le navigateur).
 *
 *  - Détection du visage (SSD MobileNet v1), 68 points de repère, empreinte de 128 nombres
 *    (réseau de reconnaissance faciale ResNet-34) : bibliothèque face-api (MIT), sans Internet.
 *  - Aucune photo n'est envoyée pour la reconnaissance : seule l'empreinte part au serveur à
 *    l'inscription, et le serveur renvoie les empreintes des personnes consentantes.
 *  - Contrôle de qualité : taille du visage, score de détection, visage de face (repères des
 *    yeux et du nez), visage entier dans l'image. Les images douteuses sont écartées.
 *  - Comparaison : distance euclidienne à chaque empreinte connue ; une personne n'est retenue
 *    que si sa distance est sous le seuil ET nettement plus faible que celle de la 2ᵉ personne.
 */
(function () {
  'use strict';
  const meta = (n) => { const m = document.querySelector(`meta[name="${n}"]`); return m ? m.content : ''; };
  const BASE = ((window.PW && PW.BASE) || meta('base-url') || '').replace(/\/$/, '');
  const SELF = (document.currentScript && document.currentScript.src) || '';
  const DIR = SELF.replace(/ecole-visage\.js.*$/, '');
  const D = 128;

  const E = { pret: false, chargement: null, backend: '' };

  function script(src) {
    return new Promise((res, rej) => { const s = document.createElement('script'); s.src = src; s.onload = res; s.onerror = () => rej(new Error('Fichier introuvable : ' + src)); document.head.appendChild(s); });
  }

  async function reseau(net, nom) {
    const r = await fetch(DIR + 'modeles/' + nom + '.txt', { cache: 'force-cache' });
    if (!r.ok) throw new Error('Modèle introuvable : ' + nom);
    const manifeste = await r.json();
    const poids = await faceapi.tf.io.loadWeights(manifeste, DIR + 'modeles');
    net.loadFromWeightMap(poids);
  }

  /** Charge la bibliothèque et les modèles (≈ 13 Mo, mis en cache par le navigateur). */
  async function charger(etape) {
    if (E.pret) return E;
    if (!E.chargement) {
      E.chargement = (async () => {
        if (etape) etape('Chargement de la reconnaissance faciale (≈ 13 Mo, une seule fois par appareil)…');
        // Voir vision.js : évite que TensorFlow.js tente une évaluation de code interdite par la sécurité.
        if (!('regeneratorRuntime' in window)) window.regeneratorRuntime = undefined;
        if (!window.faceapi) await script(DIR + 'face-api.js');
        const tf = faceapi.tf;
        for (const b of ['webgl', 'cpu']) {
          try { if (await tf.setBackend(b)) { await tf.ready(); break; } } catch (e) { /* essai suivant */ }
        }
        E.backend = tf.getBackend();
        await reseau(faceapi.nets.ssdMobilenetv1, 'detecteur');
        await reseau(faceapi.nets.faceLandmark68Net, 'reperes');
        await reseau(faceapi.nets.faceRecognitionNet, 'empreinte');
        // Préchauffage (le premier calcul est lent)
        const c = document.createElement('canvas'); c.width = c.height = 160;
        await faceapi.detectAllFaces(c, new faceapi.SsdMobilenetv1Options({ minConfidence: 0.5 }));
        E.pret = true;
        return E;
      })();
    }
    try { return await E.chargement; } catch (e) { E.chargement = null; throw e; }
  }

  const centre = (pts) => pts.reduce((a, p) => ({ x: a.x + p.x / pts.length, y: a.y + p.y / pts.length }), { x: 0, y: 0 });

  /**
   * Qualité d'un visage détecté. Renvoie { ok, raison, frontal }.
   *  minLargeur : largeur minimale du visage en pixels de l'image analysée.
   */
  function qualite(det, w, h, o = {}) {
    const b = det.detection.box, score = det.detection.score;
    const minL = o.minLargeur || 70;
    if (b.width < minL) return { ok: false, raison: 'Visage trop petit / trop loin' };
    if (score < (o.minScore || 0.7)) return { ok: false, raison: 'Visage peu net' };
    const m = 0.02 * Math.min(w, h);
    if (b.x < m || b.y < m || b.x + b.width > w - m || b.y + b.height > h - m) return { ok: false, raison: 'Visage coupé par le bord de l\'image' };
    const lm = det.landmarks;
    const og = centre(lm.getLeftEye()), od = centre(lm.getRightEye()), nez = lm.getNose()[3];
    const ecart = od.x - og.x;
    if (ecart <= 0) return { ok: false, raison: 'Visage non reconnaissable' };
    const lacet = (nez.x - og.x) / ecart;                         // 0,5 = parfaitement de face
    const roulis = Math.abs(Math.atan2(od.y - og.y, od.x - og.x)) * 180 / Math.PI;
    if (lacet < 0.25 || lacet > 0.75) return { ok: false, raison: 'Regardez la caméra (visage de profil)' };
    if (roulis > 25) return { ok: false, raison: 'Tête trop penchée' };
    return { ok: true, raison: '', frontal: 1 - Math.abs(lacet - 0.5) * 2 };
  }

  /** Analyse une image (vidéo, canvas, image) : liste des visages avec empreinte et qualité. */
  async function analyser(source, o = {}) {
    await charger();
    const w = source.videoWidth || source.naturalWidth || source.width, h = source.videoHeight || source.naturalHeight || source.height;
    const opt = new faceapi.SsdMobilenetv1Options({ minConfidence: o.minConfidence || 0.5, maxResults: o.maxVisages || 10 });
    const res = await faceapi.detectAllFaces(source, opt).withFaceLandmarks().withFaceDescriptors();
    return res.map((d) => ({ box: d.detection.box, score: d.detection.score, empreinte: d.descriptor, qualite: qualite(d, w, h, o) }));
  }

  /** Un seul visage net et de face (inscription) : renvoie { empreinte } ou { erreur }. */
  async function visageUnique(source) {
    const v = await analyser(source, { minLargeur: 80, minScore: 0.8 });
    if (!v.length) return { erreur: 'Aucun visage détecté : la personne doit être de face, bien éclairée, proche de la caméra.' };
    if (v.length > 1) return { erreur: 'Plusieurs visages sur la photo : une seule personne doit être face à la caméra.' };
    if (!v[0].qualite.ok) return { erreur: v[0].qualite.raison + '.' };
    return { empreinte: v[0].empreinte, box: v[0].box };
  }

  function encoder(f) {
    const u = new Uint8Array(new Float32Array(f).buffer); let s = '';
    for (let i = 0; i < u.length; i++) s += String.fromCharCode(u[i]);
    return btoa(s);
  }

  function distance(a, b, off = 0) {
    let s = 0;
    for (let i = 0; i < D; i++) { const x = a[i] - b[off + i]; s += x * x; }
    return Math.sqrt(s);
  }

  /** Galerie des empreintes connues (personnes actives et consentantes). */
  const G = { cles: [], vecteurs: new Float32Array(0), version: 0, charge: 0 };
  async function galerie(forcer) {
    if (!forcer && G.charge && Date.now() - G.charge < 300000) return G;
    const r = await fetch(BASE + '/ecole/visages/galerie?v=' + G.version, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const j = await r.json();
    if (!j.ok) throw new Error(j.error || 'Galerie indisponible.');
    const bin = atob(j.empreintes || ''), u = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) u[i] = bin.charCodeAt(i);
    G.vecteurs = new Float32Array(u.buffer);
    G.cles = j.types.map((t, i) => t + ':' + j.ids[i]);
    G.version = j.version; G.charge = Date.now();
    G.personnes = new Set(G.cles).size;
    return G;
  }

  /**
   * Personne la plus proche. Renvoie { cle, type, id, distance, marge, accepte } :
   *   distance = plus petite distance aux empreintes de la personne ;
   *   marge    = écart avec la meilleure distance d'une AUTRE personne.
   */
  function comparer(e, seuil, margeMin) {
    const best = new Map();
    for (let k = 0; k < G.cles.length; k++) {
      const d = distance(e, G.vecteurs, k * D), c = G.cles[k];
      if (!best.has(c) || d < best.get(c)) best.set(c, d);
    }
    let c1 = null, d1 = Infinity, d2 = Infinity;
    for (const [c, d] of best) {
      if (d < d1) { d2 = d1; d1 = d; c1 = c; } else if (d < d2) d2 = d;
    }
    if (!c1) return null;
    const marge = d2 === Infinity ? 1 : d2 - d1;
    const [type, id] = c1.split(':');
    return { cle: c1, type, id: +id, distance: d1, marge, accepte: d1 <= seuil && marge >= margeMin };
  }

  /** Vignette JPEG (pour la revue des passages) recadrée autour du visage. */
  function vignette(source, box, taille = 120) {
    const c = document.createElement('canvas'); c.width = c.height = taille;
    const k = 1.6, cx = box.x + box.width / 2, cy = box.y + box.height / 2, s = Math.max(box.width, box.height) * k;
    c.getContext('2d').drawImage(source, cx - s / 2, cy - s / 2, s, s, 0, 0, taille, taille);
    return c.toDataURL('image/jpeg', 0.7);
  }

  window.EcoleVisage = { charger, analyser, visageUnique, encoder, distance, galerie, comparer, vignette, moteur: E, G };
})();
