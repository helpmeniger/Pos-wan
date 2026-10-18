/* Caméras : détection des appareils du poste (USB, carte d'acquisition), essai des caméras IP. */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const T = (s) => (window.__ ? __(s) : s);
  const BASE = (window.PW && PW.BASE) || '';
  const CSRF = $('meta[name="csrf-token"]').content;
  const form = $('#camForm');
  if (!form) return;

  // Champs selon le type
  const kind = $('#camKind');
  const showKind = () => {
    const ip = kind.value === 'ip';
    $$('.cam-ip', form).forEach((el) => el.classList.toggle('d-none', !ip));
    $$('.cam-local', form).forEach((el) => el.classList.toggle('d-none', ip));
    const url = form.querySelector('[name="url"]'); if (url) url.required = ip;
  };
  kind.addEventListener('change', showKind); showKind();

  /** Liste des caméras du poste (les noms ne sont donnés qu'après autorisation de la caméra). */
  async function devices(askPermission) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) throw new Error(T('Ce navigateur ne liste pas les caméras (connexion non sécurisée ?). Ouvrez cette page sur le poste lui-même (localhost) ou en HTTPS.'));
    if (askPermission) {
      try { const s = await navigator.mediaDevices.getUserMedia({ video: true, audio: false }); s.getTracks().forEach((t) => t.stop()); } catch (e) { /* refus : noms vides */ }
    }
    return (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
  }
  $('#camDetect').addEventListener('click', async () => {
    const sel = $('#camDevice');
    try {
      const list = await devices(true);
      sel.innerHTML = `<option value="">${T('— choisir —')}</option>` + list.map((d, i) => `<option>${(d.label || T('Caméra') + ' ' + (i + 1)).replace(/[<>&]/g, '')}</option>`).join('');
      if (!list.length) sel.innerHTML = `<option value="">${T('Aucune caméra détectée sur ce poste')}</option>`;
      const cur = $('#camLabel').value; if (cur) sel.value = cur;
    } catch (e) { alert(e.message); }
  });
  $('#camDevice').addEventListener('change', (e) => { $('#camLabel').value = e.target.value; $('#camLabelShow').textContent = e.target.value || T('aucun (choisi sur chaque poste)'); });

  // Essai d'une caméra : image en direct dans l'aperçu
  const box = $('#camPreview'); const video = $('video', box); const img = $('img', box);
  let stream = null, timer = null;
  function stopPreview() {
    clearTimeout(timer); timer = null;
    if (stream) { stream.getTracks().forEach((t) => t.stop()); stream = null; }
    video.srcObject = null; img.removeAttribute('src'); box.classList.add('d-none');
  }
  $('[data-stop]', box).addEventListener('click', stopPreview);
  const msg = (t, cls) => { const m = $('[data-msg]', box); m.textContent = t; m.className = 'small mt-1 ' + (cls || ''); };
  $$('[data-test]').forEach((b) => b.addEventListener('click', async () => {
    stopPreview();
    const tr = b.closest('tr'); const id = tr.dataset.cam;
    box.classList.remove('d-none'); $('[data-title]', box).textContent = tr.querySelector('b').textContent;
    if (tr.dataset.kind === 'ip') {
      video.classList.add('d-none'); img.classList.remove('d-none');
      msg(T('Connexion à la caméra…'));
      const r = await fetch(BASE + '/vision/cameras/' + id + '/test', { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF, 'Content-Type': 'application/json' }, body: JSON.stringify({ _csrf: CSRF }) });
      const d = await r.json().catch(() => ({ ok: false, error: T('Réponse invalide du serveur.') }));
      if (!d.ok) { msg(d.error, 'text-danger'); return; }
      msg(`✔ ${T('Image reçue')} : ${d.width || '?'} × ${d.height || '?'} px, ${Math.round(d.bytes / 1024)} Ko, ${d.ms} ms`, 'text-success');
      const refresh = () => { img.src = BASE + '/vision/cameras/' + id + '/frame?t=' + Date.now(); timer = setTimeout(refresh, 1000); };
      refresh();
    } else {
      img.classList.add('d-none'); video.classList.remove('d-none');
      try {
        const list = await devices(true);
        const want = tr.dataset.label; const dev = want && list.find((d) => d.label === want);
        if (want && !dev) msg(T('Appareil « ') + want + T(' » absent de ce poste : caméra par défaut affichée.'), 'text-warning');
        stream = await navigator.mediaDevices.getUserMedia({ video: dev ? { deviceId: { exact: dev.deviceId } } : true, audio: false });
        video.srcObject = stream; await video.play().catch(() => {});
        const tr0 = stream.getVideoTracks()[0]; const st = tr0.getSettings ? tr0.getSettings() : {};
        if (!want || dev) msg(`✔ ${tr0.label || T('Caméra')} — ${st.width || '?'} × ${st.height || '?'}`, 'text-success');
      } catch (e) { msg(T('Caméra indisponible : ') + (e.message || e.name), 'text-danger'); }
    }
  }));
})();
