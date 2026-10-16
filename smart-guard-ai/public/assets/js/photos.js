/* Capture de photos de référence depuis la webcam du navigateur */
(function () {
  'use strict';
  const video = document.getElementById('camVideo');
  if (!video) return;
  const sel = document.getElementById('camSelect');
  const shot = document.getElementById('camShot');
  const msg = document.getElementById('camMsg');
  let stream = null;
  let count = 0;

  async function start(deviceId) {
    if (!navigator.mediaDevices?.getUserMedia) { msg.textContent = 'Caméra du navigateur indisponible (HTTPS requis hors localhost). Utilisez l\'onglet « Importer / smartphone ».'; return; }
    stream?.getTracks().forEach((t) => t.stop());
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: deviceId ? { deviceId: { exact: deviceId }, width: { ideal: 1280 } } : { width: { ideal: 1280 }, facingMode: 'environment' }, audio: false });
      video.srcObject = stream;
      shot.disabled = false;
      const devs = (await navigator.mediaDevices.enumerateDevices()).filter((d) => d.kind === 'videoinput');
      sel.innerHTML = devs.map((d, i) => `<option value="${sgEsc(d.deviceId)}">${sgEsc(d.label || 'Caméra ' + (i + 1))}</option>`).join('');
      if (deviceId) sel.value = deviceId;
      msg.textContent = 'Caméra active. Tournez l\'article entre chaque photo.';
    } catch (e) { msg.textContent = 'Accès à la caméra refusé ou impossible : ' + e.message; }
  }
  document.getElementById('camStart').addEventListener('click', () => start());
  sel.addEventListener('change', () => start(sel.value));
  shot.addEventListener('click', async () => {
    const c = document.createElement('canvas');
    c.width = video.videoWidth; c.height = video.videoHeight;
    c.getContext('2d').drawImage(video, 0, 0);
    shot.disabled = true;
    try {
      const fd = new FormData();
      fd.append('image_base64', c.toDataURL('image/jpeg', 0.92));
      fd.append('source', 'webcam');
      fd.append('view_angle', document.getElementById('camAngle').value);
      const r = await sgFetch(`products/${shot.dataset.pid}/photos`, { method: 'POST', body: fd });
      count += r.added;
      msg.textContent = `${count} photo(s) ajoutée(s) pendant cette session.` + (r.errors.length ? ' ' + r.errors.map((x) => x.error).join(' ') : '') + ' Rechargez la page pour les voir.';
    } catch (e) { msg.textContent = e.message; }
    shot.disabled = false;
  });
})();
