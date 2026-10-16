/*
 * POS Marwa — lecture des codes-barres par la caméra (smartphone, tablette, webcam).
 *
 *   PMScanner.open({ title, onCode: async (code) => ({ ok, text }), continuous: true })
 *
 * - Caméra en direct : BarcodeDetector du navigateur s'il existe, sinon la bibliothèque ZXing
 *   (fournie localement, fonctionne sans Internet). Exige une connexion sécurisée (HTTPS ou
 *   localhost) : c'est une règle des navigateurs.
 * - Solution de remplacement : prendre une PHOTO du code-barres (fonctionne aussi en HTTP).
 * - Anti-doublon : un même code n'est compté à nouveau que s'il a quitté le champ de la caméra
 *   (≥ 0,8 s) — le laisser devant l'objectif ne l'ajoute pas en boucle. Le bouton « +1 » ajoute
 *   volontairement une unité du dernier article.
 * - Les douchettes USB / sans fil continuent de fonctionner comme un clavier (inchangé).
 */
(function () {
  'use strict';
  const FORMATS_NATIVE = ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39', 'code_93', 'itf', 'codabar', 'qr_code', 'data_matrix'];
  const GONE_MS = 800; // durée d'absence pour qu'une nouvelle présentation du même code compte
  const T = (s) => (window.__ ? __(s) : s);
  let ui = null, stream = null, running = false, opts = null, detector = null, zx = null;
  let last = { code: null, seenAt: 0, acceptedAt: 0 };
  let audio = null;
  const SELF = (document.currentScript && document.currentScript.src) || '';
  /** ZXing n'est chargé qu'au premier usage (≈ 330 Ko), et seulement si le navigateur n'a pas de lecteur intégré. */
  function loadZxing() {
    if (window.ZXing || !SELF) return Promise.resolve();
    return new Promise((res) => {
      const s = document.createElement('script');
      s.src = SELF.replace(/js\/scanner\.js.*$/, 'vendor/zxing/zxing.min.js');
      s.onload = res; s.onerror = res;
      document.head.appendChild(s);
    });
  }

  function beep(ok) {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      const o = audio.createOscillator(); const g = audio.createGain();
      o.frequency.value = ok ? 1320 : 240; g.gain.value = 0.08; o.connect(g); g.connect(audio.destination);
      o.start(); o.stop(audio.currentTime + (ok ? 0.08 : 0.3));
    } catch (e) { /* audio indisponible */ }
    if (navigator.vibrate) navigator.vibrate(ok ? 60 : [80, 60, 80]);
  }

  function liveSupported() { return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia); }

  async function nativeDetector() {
    if (!('BarcodeDetector' in window)) return null;
    try {
      const supported = await window.BarcodeDetector.getSupportedFormats();
      const f = FORMATS_NATIVE.filter((x) => supported.includes(x));
      return f.length ? new window.BarcodeDetector({ formats: f }) : null;
    } catch (e) { return null; }
  }
  function zxingReader() {
    if (!window.ZXing) return null;
    const Z = window.ZXing;
    const hints = new Map();
    hints.set(Z.DecodeHintType.POSSIBLE_FORMATS, [Z.BarcodeFormat.EAN_13, Z.BarcodeFormat.EAN_8, Z.BarcodeFormat.UPC_A, Z.BarcodeFormat.UPC_E, Z.BarcodeFormat.CODE_128,
      Z.BarcodeFormat.CODE_39, Z.BarcodeFormat.CODE_93, Z.BarcodeFormat.ITF, Z.BarcodeFormat.CODABAR, Z.BarcodeFormat.QR_CODE, Z.BarcodeFormat.DATA_MATRIX]);
    hints.set(Z.DecodeHintType.TRY_HARDER, true);
    const r = new Z.MultiFormatReader();
    r.setHints(hints);
    return r;
  }
  /** Décode une image dessinée sur un canvas ; renvoie le texte ou null. */
  async function decodeCanvas(canvas) {
    if (detector) {
      try { const res = await detector.detect(canvas); if (res && res.length) return res[0].rawValue; } catch (e) { /* repli ZXing */ }
    }
    if (!zx) zx = zxingReader();
    if (!zx) return null;
    try {
      const Z = window.ZXing;
      const bmp = new Z.BinaryBitmap(new Z.HybridBinarizer(new Z.HTMLCanvasElementLuminanceSource(canvas)));
      return zx.decode(bmp).getText();
    } catch (e) { return null; } finally { try { zx.reset(); } catch (e) { /* */ } }
  }

  function build() {
    const el = document.createElement('div');
    el.className = 'pm-scan';
    el.innerHTML = `<div class="pm-scan-box" role="dialog" aria-modal="true">
      <div class="pm-scan-head"><b data-title></b><button type="button" class="pm-x" data-close aria-label="${T('Fermer')}">✕</button></div>
      <div class="pm-scan-view"><video playsinline muted></video><div class="pm-scan-aim"></div><div class="pm-scan-flash"></div></div>
      <div class="pm-scan-msg" data-msg>${T('Présentez le code-barres devant la caméra.')}</div>
      <div class="pm-scan-last" data-last></div>
      <div class="pm-scan-actions">
        <button type="button" class="pm-btn" data-restart>⟳ ${T('Relancer la caméra')}</button>
        <label class="pm-btn">📷 ${T('Photo du code')}<input type="file" accept="image/*" capture="environment" hidden data-photo></label>
        <button type="button" class="pm-btn pm-plus" data-plus disabled>+1</button>
        <input type="text" class="pm-manual" data-manual placeholder="${T('ou saisir le code')}" inputmode="numeric" autocomplete="off">
      </div></div>`;
    document.body.appendChild(el);
    el.addEventListener('click', (e) => { if (e.target === el || e.target.closest('[data-close]')) close(); });
    el.querySelector('[data-restart]').addEventListener('click', () => startCamera());
    el.querySelector('[data-plus]').addEventListener('click', () => { if (last.code) handle(last.code, true); });
    el.querySelector('[data-photo]').addEventListener('change', photo);
    el.querySelector('[data-manual]').addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); const v = e.target.value.trim(); e.target.value = ''; if (v) handle(v, true); }
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && ui && ui.classList.contains('open')) close(); });
    return el;
  }
  const $u = (s) => ui.querySelector(s);
  function msg(text, cls) { const m = $u('[data-msg]'); m.textContent = text; m.className = 'pm-scan-msg ' + (cls || ''); }

  async function handle(code, deliberate) {
    const now = Date.now();
    if (!deliberate && code === last.code && (now - last.seenAt < GONE_MS || now - last.acceptedAt < 1000)) {
      last.seenAt = now; // toujours devant la caméra : pas de nouveau comptage
      return;
    }
    last = { code, seenAt: now, acceptedAt: now };
    const flash = $u('.pm-scan-flash');
    flash.classList.remove('on'); void flash.offsetWidth; flash.classList.add('on');
    let r = { ok: true, text: code };
    try { r = (await opts.onCode(code)) || r; } catch (e) { r = { ok: false, text: e.message || String(e) }; }
    beep(r.ok !== false);
    $u('[data-last]').innerHTML = `<span class="${r.ok === false ? 'bad' : 'good'}">${r.ok === false ? '✖' : '✔'}</span> <code>${String(code).replace(/[<>&]/g, '')}</code> — ${r.html || String(r.text || '').replace(/[<>&]/g, '')}`;
    $u('[data-plus]').disabled = r.ok === false || opts.plus === false;
    if (opts.continuous === false && r.ok !== false) setTimeout(close, 700);
  }

  async function loop(video, canvas) {
    if (!running) return;
    if (video.readyState >= 2 && video.videoWidth) {
      const w = Math.min(960, video.videoWidth);
      const h = Math.round(video.videoHeight * (w / video.videoWidth));
      canvas.width = w; canvas.height = h;
      canvas.getContext('2d', { willReadFrequently: true }).drawImage(video, 0, 0, w, h);
      const code = await decodeCanvas(canvas);
      if (code) handle(code, false);
    }
    setTimeout(() => requestAnimationFrame(() => loop(video, canvas)), detector ? 120 : 220);
  }

  async function startCamera() {
    stopCamera();
    if (!liveSupported()) {
      msg(T('Caméra en direct indisponible ici (le navigateur exige une connexion HTTPS). Utilisez « Photo du code » ou une douchette.'), 'warn');
      return;
    }
    msg(T('Ouverture de la caméra…'));
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
    } catch (e) {
      msg(e.name === 'NotAllowedError' ? T('Accès à la caméra refusé : autorisez-le dans le navigateur puis « Relancer la caméra ».') : T('Caméra introuvable ou occupée par une autre application.'), 'bad');
      return;
    }
    const video = $u('video');
    video.srcObject = stream;
    try { await video.play(); } catch (e) { /* lecture automatique */ }
    running = true;
    msg(T('Présentez le code-barres devant la caméra.'));
    loop(video, document.createElement('canvas'));
  }
  function stopCamera() {
    running = false;
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
  }

  async function photo(e) {
    const f = e.target.files && e.target.files[0];
    e.target.value = '';
    if (!f) return;
    msg(T('Lecture de la photo…'));
    const img = new Image();
    img.src = URL.createObjectURL(f);
    await new Promise((res) => { img.onload = res; img.onerror = res; });
    const canvas = document.createElement('canvas');
    const scale = Math.min(1, 1600 / Math.max(img.width || 1, img.height || 1));
    for (const rot of [0, 90]) {
      const w = Math.round(img.width * scale), h = Math.round(img.height * scale);
      canvas.width = rot ? h : w; canvas.height = rot ? w : h;
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      ctx.save(); if (rot) { ctx.translate(h, 0); ctx.rotate(Math.PI / 2); } ctx.drawImage(img, 0, 0, w, h); ctx.restore();
      const code = await decodeCanvas(canvas);
      if (code) { URL.revokeObjectURL(img.src); msg(T('Code lu sur la photo.'), 'good'); handle(code, true); return; }
    }
    URL.revokeObjectURL(img.src);
    beep(false);
    msg(T('Aucun code-barres reconnu : rapprochez-vous, évitez les reflets, cadrez le code en entier.'), 'bad');
  }

  async function open(o) {
    opts = Object.assign({ title: T('Scanner un code-barres'), continuous: true, plus: true, onCode: async (c) => ({ ok: true, text: c }) }, o || {});
    if (!ui) ui = build();
    $u('[data-title]').textContent = opts.title;
    $u('[data-last]').innerHTML = '';
    $u('[data-plus]').disabled = true;
    $u('[data-plus]').hidden = opts.plus === false;
    last = { code: null, seenAt: 0, acceptedAt: 0 };
    ui.classList.add('open');
    document.body.classList.add('pm-scan-on');
    if (detector === null) detector = (await nativeDetector()) || false;
    if (!detector) await loadZxing();
    startCamera();
  }
  function close() {
    stopCamera();
    if (ui) ui.classList.remove('open');
    document.body.classList.remove('pm-scan-on');
    if (opts && opts.onClose) opts.onClose();
  }

  window.PMScanner = { open, close, liveSupported, decodeCanvas, _handle: handle };

  // Boutons déclaratifs : <button data-camera-scan="#champ"> remplit le champ et soumet son formulaire
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-camera-scan]');
    if (!b) return;
    e.preventDefault();
    const target = document.querySelector(b.dataset.cameraScan);
    open({ title: b.dataset.scanTitle || undefined, continuous: false, plus: false, onCode: async (code) => {
      if (target) { target.value = code; target.dispatchEvent(new Event('input', { bubbles: true })); if (b.dataset.scanSubmit !== undefined && target.form) target.form.requestSubmit ? target.form.requestSubmit() : target.form.submit(); }
      return { ok: true, text: T('Code lu') };
    } });
  });
})();
