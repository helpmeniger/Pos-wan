/* Clinique — import d'images (DICOM, JPEG, PNG) depuis le navigateur : CD/DVD de radiologie, clé USB,
   photo d'un cliché. Envoi en morceaux de 1 Mo (limites d'envoi de WampServer), reprise d'un morceau
   en échec, puis classement automatique côté serveur. */
(function () {
  'use strict';
  const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  const BASE = ((document.querySelector('meta[name="base-url"]') || {}).content || '/').replace(/\/$/, '');
  const CHUNK = 1024 * 1024;
  const T = (s) => (window.__ ? __(s) : s);

  async function sendFile(file, extra, onPart) {
    const total = Math.max(1, Math.ceil(file.size / CHUNK));
    const id = Array.from(crypto.getRandomValues(new Uint8Array(12)), (b) => b.toString(16).padStart(2, '0')).join('');
    let last = null;
    for (let i = 0; i < total; i++) {
      const fd = new FormData();
      fd.append('_csrf', CSRF); fd.append('upload', id); fd.append('index', i); fd.append('total', total);
      Object.entries(extra).forEach(([k, v]) => { if (v) fd.append(k, v); });
      fd.append('chunk', file.slice(i * CHUNK, (i + 1) * CHUNK), 'morceau');
      let ok = false;
      for (let attempt = 0; attempt < 4 && !ok; attempt++) {
        try {
          const r = await fetch(BASE + '/clinique/imagerie/import', { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF } });
          last = await r.json();
          if (!last.ok) throw Object.assign(new Error(last.error || 'erreur'), { final: r.status === 422 });
          ok = true;
        } catch (e) {
          if (e.final || attempt === 3) throw e;
          await new Promise((res) => setTimeout(res, 800 * (attempt + 1)));
        }
      }
      onPart(i + 1, total);
    }
    return last;
  }

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-img-import]');
    if (!btn) return;
    const scope = btn.closest('form, .card, main, body');
    const input = btn.parentElement.querySelector('[data-img-files]') || scope.querySelector('[data-img-files]');
    if (!input) return;
    input.onchange = async () => {
      const files = Array.from(input.files || []).filter((f) => !/^dicomdir$/i.test(f.name) && f.size > 0);
      if (!files.length) return;
      const prog = document.querySelector('[data-img-progress]');
      const extra = { patient_id: btn.dataset.patient || '', study_id: btn.dataset.study || '', request_id: btn.dataset.request || '' };
      btn.disabled = true;
      let done = 0, dup = 0, unmatched = 0, errors = [], lastStudy = null;
      for (const f of files) {
        try {
          const r = await sendFile(f, extra, (p, t) => { if (prog) prog.textContent = `${T('Envoi')} ${done + 1}/${files.length} — ${f.name} (${Math.round(p / t * 100)} %)`; });
          done++; if (r.duplicate) dup++; if (!r.matched) unmatched++; lastStudy = r.study_id;
        } catch (err) { errors.push(f.name + ' : ' + err.message); }
      }
      btn.disabled = false; input.value = '';
      const msg = `${done} ${T('image(s) importée(s)')}${dup ? ', ' + dup + ' ' + T('déjà présente(s)') : ''}${unmatched ? ', ' + unmatched + ' ' + T('à rattacher') : ''}.` + (errors.length ? '\n' + T('Refusé(s) :') + '\n' + errors.join('\n') : '');
      if (prog) prog.textContent = msg;
      if (errors.length) alert(msg);
      if (done) {
        if (!extra.study_id && !extra.patient_id && lastStudy && files.length - errors.length === 1) location.href = BASE + '/clinique/imagerie/examens/' + lastStudy;
        else setTimeout(() => location.reload(), errors.length ? 0 : 600);
      }
    };
    input.click();
  });
})();
