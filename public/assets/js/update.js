/* Mise à jour du logiciel : envoi du dossier de la nouvelle version par lots, vérification, application. */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const input = $('#upFolder');
  if (!input) return;
  const BASE = (window.PW && PW.BASE) || ($('meta[name="base-url"]') ? $('meta[name="base-url"]').content.replace(/\/$/, '') : '');
  const CSRF = $('meta[name="csrf-token"]').content;
  const LIM = JSON.parse($('#update-limits').textContent);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const SKIP = [/^\.git\//, /^storage\//, /^public\/uploads\//, /^public\/assets\/addons\//, /^\.env$/, /(^|\/)node_modules\//, /(^|\/)(Thumbs\.db|\.DS_Store)$/];
  let files = [];
  let token = null;

  function prepare() {
    const all = Array.from(input.files || []);
    // Racine : le dossier qui contient app/bootstrap.php (le dossier choisi peut l'englober)
    const marker = all.map((f) => f.webkitRelativePath || f.name).find((p) => /(^|\/)app\/bootstrap\.php$/.test(p));
    if (!marker) { files = []; return T('Ce dossier ne contient pas POS Wan (app/bootstrap.php introuvable).'); }
    const prefix = marker.slice(0, marker.length - 'app/bootstrap.php'.length);
    files = all.map((f) => ({ f, rel: (f.webkitRelativePath || f.name) })).filter((x) => x.rel.startsWith(prefix))
      .map((x) => ({ f: x.f, rel: x.rel.slice(prefix.length) })).filter((x) => !SKIP.some((r) => r.test(x.rel)));
    const big = files.filter((x) => x.f.size > LIM.file);
    const size = files.reduce((s, x) => s + x.f.size, 0);
    return files.length + ' ' + T('fichiers à envoyer') + ' (' + (size / 1048576).toFixed(1) + ' Mo)' + (big.length ? ' — ' + T('trop gros pour le serveur') + ' : ' + big.map((x) => x.rel).join(', ') : '');
  }
  function T(s) { return window.__ ? __(s) : s; }
  function progress(done, total, text) {
    const pc = total ? Math.round((done * 100) / total) : 0;
    $('#upBar').style.width = pc + '%'; $('#upBar').textContent = pc + ' %';
    $('#upStatus').textContent = text || '';
  }
  async function post(path, body, json = true) {
    const opt = { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF } };
    if (json) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(Object.assign({ _csrf: CSRF }, body)); } else { body.append('_csrf', CSRF); opt.body = body; }
    const r = await fetch(BASE + path, opt);
    const d = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide du serveur (' + r.status + ').' }));
    if (!d.ok) throw new Error(d.error || 'Erreur');
    return d;
  }

  input.addEventListener('change', () => {
    $('#upSummary').textContent = prepare();
    $('#upSend').disabled = files.length === 0;
    $('#upCheck').classList.add('d-none');
  });

  $('#upSend').addEventListener('click', async () => {
    const btn = $('#upSend');
    btn.disabled = true; input.disabled = true;
    $('#upProgressBox').classList.remove('d-none'); $('#upCheck').classList.add('d-none');
    try {
      token = (await post('/update/start', { password: $('#upPassword').value })).token;
      let i = 0;
      while (i < files.length) {
        const fd = new FormData();
        fd.append('token', token);
        let n = 0; let bytes = 0;
        while (i < files.length && n < LIM.files && (n === 0 || bytes + files[i].f.size <= LIM.bytes)) {
          fd.append('files[]', files[i].f, files[i].f.name); fd.append('paths[]', files[i].rel);
          bytes += files[i].f.size; n++; i++;
        }
        let tries = 0;
        for (;;) { // nouvel essai automatique en cas de coupure réseau
          try { await post('/update/upload', fd, false); break; } catch (e) { if (++tries >= 3) throw e; await new Promise((res) => setTimeout(res, 1500)); }
        }
        progress(i, files.length, T('Envoi') + ' : ' + i + ' / ' + files.length);
      }
      progress(files.length, files.length, T('Vérification…'));
      const c = await post('/update/check', { token });
      showCheck(c);
    } catch (e) {
      $('#upStatus').innerHTML = '<span class="text-danger fw-semibold">' + esc(e.message) + '</span>';
      btn.disabled = false; input.disabled = false;
    }
  });

  function showCheck(c) {
    const box = $('#upCheck');
    box.classList.remove('d-none');
    const ok = !c.errors.length;
    box.innerHTML = `<div class="alert ${ok ? (c.verified ? 'alert-success' : 'alert-warning') : 'alert-danger'} mb-2">
      <div class="fw-semibold mb-1">${ok ? T('Prêt') : T('Mise à jour impossible')} : v${esc(c.current)} → v${esc(c.version || '?')} · ${c.files} ${T('fichiers')}${c.verified ? ' · <i class="bi bi-shield-check"></i> ' + T('intégrité vérifiée') : ''}</div>
      ${c.errors.map((x) => '<div><i class="bi bi-x-octagon"></i> ' + esc(x) + '</div>').join('')}
      ${c.warnings.map((x) => '<div><i class="bi bi-exclamation-triangle"></i> ' + esc(x) + '</div>').join('')}</div>
      ${ok ? `${c.verified ? '' : `<label class="small d-block mb-2"><input type="checkbox" id="upAccept"> ${T('Je confirme vouloir installer cette copie non vérifiée.')}</label>`}
      <button class="btn btn-success" id="upApply"><i class="bi bi-check2-circle"></i> ${T('Sauvegarder puis installer la version')} ${esc(c.version)}</button>` : ''}`;
    const a = $('#upApply');
    if (!a) { $('#upSend').disabled = false; input.disabled = false; return; }
    a.addEventListener('click', async () => {
      if (!c.verified && !$('#upAccept').checked) { $('#upAccept').focus(); return; }
      a.disabled = true;
      a.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + T('Sauvegarde et installation en cours… ne fermez pas cette page.');
      try {
        await post('/update/apply', { token, accept: !c.verified });
        location.href = BASE + '/update';
      } catch (e) {
        a.disabled = false; a.textContent = T('Réessayer');
        box.insertAdjacentHTML('beforeend', '<div class="alert alert-danger mt-2">' + esc(e.message) + '</div>');
      }
    });
  }
})();
