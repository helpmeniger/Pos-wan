/* =====================================================================
   POS Marwa — écran client : préparation des images AVANT l'envoi.
   Une photo de téléphone (4000 px, 4 à 8 Mo) dépasse souvent la limite d'envoi de PHP
   (2 Mo par défaut) et était refusée. Elle est réduite ici à 1920 px (sens de prise de vue
   respecté) puis envoyée en JPEG ; une image avec transparence reste en PNG. Les GIF
   (animations) et les vidéos ne sont pas modifiés, leur taille est seulement contrôlée.
   ===================================================================== */
(function () {
  'use strict';
  // Diagnostic : une vignette qui ne s'affiche pas indique la raison exacte (au lieu d'un carré noir)
  document.querySelectorAll('[data-media-check]').forEach((el) => {
    const report = async () => {
      const box = el.closest('li') && el.closest('li').querySelector('[data-media-error]');
      if (!box) return;
      let why = 'le navigateur ne peut pas lire ce fichier';
      try {
        const r = await fetch(el.currentSrc || el.src, { method: 'HEAD', credentials: 'same-origin', cache: 'no-store' });
        why = r.ok ? `le serveur répond mais le contenu n'est pas lisible (${r.headers.get('Content-Type') || 'type inconnu'})` : `le serveur répond « ${r.status} »`;
      } catch (e) { why = 'le serveur ne répond pas'; }
      box.querySelector('.badge').textContent = 'Aperçu impossible : ' + why + '. Supprimez ce fichier et ajoutez-le de nouveau ; si le problème continue, envoyez cette ligne à l\'assistance.';
      box.classList.remove('d-none');
    };
    el.addEventListener('error', report);
    if (el.tagName === 'IMG' && el.complete && el.naturalWidth === 0) report();
  });
  document.querySelectorAll('input[data-display-upload]').forEach(setup);
  function setup(input) {
  const info = input.parentElement.querySelector('[data-upload-info]');
  const form = input.form;
  const btn = form.querySelector('button:not([type=button])');
  const MAX = 1920;
  const phpMax = Number(input.dataset.phpMax || 0);
  const mo = (b) => (b / 1048576).toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' Mo';
  const say = (html, cls) => { if (info) { info.innerHTML = html; info.className = 'form-text ' + (cls || ''); } };
  let busy = false;

  async function bitmap(file) {
    if ('createImageBitmap' in window) {
      try { return await createImageBitmap(file, { imageOrientation: 'from-image' }); } catch (e) { /* repli ci-dessous */ }
    }
    const url = URL.createObjectURL(file);
    try {
      const img = new Image();
      img.src = url;
      await img.decode();
      return img;
    } finally { setTimeout(() => URL.revokeObjectURL(url), 1000); }
  }

  /** Transparence réelle ? (échantillon réduit de l'image) */
  function hasAlpha(src, w, h) {
    const c = document.createElement('canvas');
    c.width = Math.min(64, w); c.height = Math.min(64, h);
    const g = c.getContext('2d');
    g.drawImage(src, 0, 0, c.width, c.height);
    const d = g.getImageData(0, 0, c.width, c.height).data;
    for (let i = 3; i < d.length; i += 4) if (d[i] < 250) return true;
    return false;
  }

  async function prepare(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return null; // GIF animé, vidéo : inchangés
    const src = await bitmap(file);
    const w = src.width, h = src.height;
    const big = w > MAX || h > MAX;
    if (!big && file.size <= 1.5 * 1048576) return null;
    const r = Math.min(1, MAX / Math.max(w, h));
    const c = document.createElement('canvas');
    c.width = Math.round(w * r); c.height = Math.round(h * r);
    const g = c.getContext('2d');
    g.imageSmoothingQuality = 'high';
    const png = file.type !== 'image/jpeg' && hasAlpha(src, w, h);
    if (!png) { g.fillStyle = '#000'; g.fillRect(0, 0, c.width, c.height); }
    g.drawImage(src, 0, 0, c.width, c.height);
    if (src.close) src.close();
    const type = png ? 'image/png' : 'image/jpeg';
    const blob = await new Promise((res) => c.toBlob(res, type, 0.88));
    if (!blob || blob.size >= file.size) return null;
    const name = file.name.replace(/\.[^.]+$/, '') + (png ? '.png' : '.jpg');
    return { file: new File([blob], name, { type, lastModified: Date.now() }), from: [w, h, file.size], to: [c.width, c.height, blob.size] };
  }

  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    say('');
    if (!file) return;
    busy = true; if (btn) btn.disabled = true;
    try {
      const isVideo = /^video\//.test(file.type);
      let out = null;
      if (!isVideo) {
        say('<i class="bi bi-hourglass-split"></i> Préparation de l\'image…');
        out = await prepare(file);
      }
      if (out && 'DataTransfer' in window) {
        const dt = new DataTransfer();
        dt.items.add(out.file);
        input.files = dt.files;
        input.dispatchEvent(new CustomEvent('pm-prepared'));
        say(`<i class="bi bi-magic"></i> Image préparée pour l'écran : ${out.from[0]}×${out.from[1]} → <b>${out.to[0]}×${out.to[1]}</b>, ${mo(out.from[2])} → <b>${mo(out.to[2])}</b>.`, 'text-success');
      }
      const size = input.files[0].size;
      const limit = Math.min(...[phpMax, Number(isVideo ? input.dataset.maxVideo : input.dataset.maxImage)].filter((x) => x > 0));
      if (limit && size > limit) {
        say(`<i class="bi bi-exclamation-triangle"></i> Fichier de ${mo(size)} : la limite de ce serveur est ${mo(limit)}. ${isVideo ? 'Réduisez la vidéo (720p, H.264) ou augmentez « upload_max_filesize » et « post_max_size » dans php.ini.' : 'Choisissez une image plus petite.'}`, 'text-danger');
        input.value = '';
      }
    } catch (e) {
      say('Image non préparée dans le navigateur : elle sera envoyée telle quelle.', 'text-warning');
    } finally {
      busy = false; if (btn) btn.disabled = false;
    }
  });
  form.addEventListener('submit', (ev) => {
    if (busy) { ev.preventDefault(); return; }
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Envoi…'; }
  });
  }
})();
