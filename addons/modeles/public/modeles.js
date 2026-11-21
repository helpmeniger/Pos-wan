/* Modèles de documents : aperçu immédiat pendant la personnalisation (page /modeles/…). */
(function () {
  const form = document.getElementById('mdlForm');
  const frame = document.getElementById('mdlPreview');
  if (!form || !frame) return;
  let timer = null, seq = 0;
  const kind = () => (document.querySelector('input[name="mdlKind"]:checked') || {}).value || '';
  const refresh = () => {
    const fd = new FormData(form);
    fd.set('kind', kind());
    const n = ++seq;
    fetch(form.dataset.preview, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then((r) => (r.ok ? r.text() : Promise.reject(r.status)))
      .then((html) => { if (n === seq) frame.srcdoc = html; })
      .catch(() => { if (n === seq) frame.srcdoc = '<p style="font:14px Arial;padding:1rem">Aperçu indisponible : vérifiez la connexion puis modifiez un réglage.</p>'; });
  };
  const later = () => { clearTimeout(timer); timer = setTimeout(refresh, 350); };
  form.addEventListener('input', later);
  form.addEventListener('change', later);
  document.querySelectorAll('input[name="mdlKind"]').forEach((r) => r.addEventListener('change', refresh));
  refresh();
})();
