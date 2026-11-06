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
    if (!marker) { files = []; return T('Ce dossier ne contient pas POS Marwa (app/bootstrap.php introuvable).'); }
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
  const sleep = (ms) => new Promise((res) => setTimeout(res, ms));
  /** « Failed to fetch » : message compréhensible (connexion coupée, antivirus, fichier verrouillé). */
  function netError(e, what) {
    if (e instanceof TypeError || /fetch|network/i.test(e.message || '')) {
      return new Error(T('Connexion au serveur interrompue') + (what ? ' (' + what + ')' : '') + ' : ' + T('vérifiez que WampServer est vert, puis réessayez. Un antivirus peut aussi bloquer l\'envoi : autorisez le dossier de POS Marwa.') + ' [' + (e.message || e) + ']');
    }
    return e;
  }
  /** Fichiers que le navigateur ne peut pas lire (supprimés, verrouillés ou mis en quarantaine par un antivirus). */
  async function unreadable(list) {
    const bad = [];
    for (const x of list) { try { await x.f.slice(0, 1).arrayBuffer(); } catch (e) { bad.push(x.rel); } }
    return bad;
  }
  /** Envoi d'un lot ; en cas d'échec répété, le lot est découpé jusqu'au fichier seul pour nommer le fichier en cause. */
  async function sendBatch(batch) {
    const make = (b) => { const fd = new FormData(); fd.append('token', token); b.forEach((x) => { fd.append('files[]', x.f, x.f.name); fd.append('paths[]', x.rel); }); return fd; };
    let last = null;
    for (let tries = 0; tries < 4; tries++) {
      try { await post('/update/upload', make(batch), false); return; } catch (e) {
        last = e;
        if (!(e instanceof TypeError)) throw e; // refus du serveur : message clair, inutile de réessayer
        await sleep(1500 * (tries + 1));
      }
    }
    if (batch.length > 1) { const h = Math.ceil(batch.length / 2); await sendBatch(batch.slice(0, h)); await sendBatch(batch.slice(h)); return; }
    throw netError(last, batch[0].rel);
  }
  async function post(path, body, json = true) {
    const opt = { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': CSRF } };
    if (json) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(Object.assign({ _csrf: CSRF }, body)); } else { body.append('_csrf', CSRF); opt.body = body; }
    const r = await fetch(BASE + path, opt);
    const d = await r.json().catch(() => ({ ok: false, error: 'Réponse invalide du serveur (' + r.status + ').' }));
    if (!d.ok) { const e = new Error(d.error || 'Erreur'); e.status = r.status; e.transient = [0, 408, 429, 500, 502, 503, 504, 520, 522, 524].includes(r.status) && !d.error?.startsWith('Mise à jour interrompue'); throw e; }
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
      progress(0, files.length, T('Lecture des fichiers…'));
      const bad = await unreadable(files);
      if (bad.length) throw new Error(T('Fichiers illisibles par le navigateur (verrouillés ou bloqués par un antivirus)') + ' : ' + bad.slice(0, 5).join(', ') + (bad.length > 5 ? '…' : '') + '. ' + T('Décompressez à nouveau le ZIP, autorisez ce dossier dans l\'antivirus, puis réessayez.'));
      try { token = (await post('/update/start', { password: $('#upPassword').value })).token; } catch (e) { throw netError(e); }
      let i = 0;
      while (i < files.length) {
        const batch = [];
        let bytes = 0;
        while (i < files.length && batch.length < LIM.files && (batch.length === 0 || bytes + files[i].f.size <= LIM.bytes)) {
          batch.push(files[i]); bytes += files[i].f.size; i++;
        }
        await sendBatch(batch); // nouvel essai automatique en cas de coupure réseau
        progress(i, files.length, T('Envoi') + ' : ' + i + ' / ' + files.length);
      }
      progress(files.length, files.length, T('Vérification…'));
      let c;
      try { c = await post('/update/check', { token }); } catch (e) { throw netError(e, T('vérification')); }
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
    box.innerHTML = `<div class="alert ${ok ? (c.manifest ? 'alert-success' : 'alert-warning') : 'alert-danger'} mb-2">
      <div class="fw-semibold mb-1">${ok ? T('Prêt') : T('Mise à jour impossible')} : v${esc(c.current)} → v${esc(c.version || '?')} · ${c.files} ${T('fichiers')}${c.manifest ? ' · <i class="bi bi-shield-check"></i> ' + T('intégrité contrôlée avant toute modification') : ''}</div>
      ${c.errors.map((x) => '<div><i class="bi bi-x-octagon"></i> ' + esc(x) + '</div>').join('')}
      ${c.warnings.map((x) => '<div><i class="bi bi-exclamation-triangle"></i> ' + esc(x) + '</div>').join('')}</div>
      ${ok ? `${c.manifest ? '' : `<label class="small d-block mb-2"><input type="checkbox" id="upAccept"> ${T('Je confirme vouloir installer cette copie non vérifiée.')}</label>`}
      <button class="btn btn-success" id="upApply"><i class="bi bi-check2-circle"></i> ${T('Sauvegarder puis installer la version')} ${esc(c.version)}</button>` : ''}`;
    const a = $('#upApply');
    if (!a) { $('#upSend').disabled = false; input.disabled = false; return; }
    a.addEventListener('click', async () => {
      if (!c.manifest && !$('#upAccept').checked) { $('#upAccept').focus(); return; }
      a.disabled = true;
      $('#upProgressBox').classList.remove('d-none');
      a.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + T('Installation en cours… ne fermez pas cette page.');
      // Étapes courtes rappelées jusqu'à la fin : aucune requête longue (hébergement mutualisé)
      let fails = 0;
      try {
        for (;;) {
          let d;
          try {
            d = await post('/update/step', { token, accept: !c.manifest });
            fails = 0;
          } catch (e) {
            if (!(e instanceof TypeError) && !e.transient) throw e;
            if (++fails > 12) {
              // Le serveur ne répond plus : la nouvelle version est peut-être déjà en place
              try { const h = await (await fetch(BASE + '/health', { cache: 'no-store' })).json(); if (h.version === c.version) break; } catch (x) { /* serveur occupé */ }
              throw netError(e, T('installation'));
            }
            $('#upStatus').textContent = T('Serveur occupé, nouvel essai…') + ' (' + fails + ')';
            await sleep(2500 * Math.min(fails, 4));
            continue;
          }
          const lbl = T(d.label) + (d.total ? ' : ' + d.done_count + ' / ' + d.total : '') + (d.changed !== undefined && d.phase !== 'verify' && d.phase !== 'diff' ? ' (' + d.changed + ' ' + T('fichier(s) modifié(s)') + ')' : '');
          progress(d.done_count, d.total || 1, lbl);
          if (d.done) break;
          if (d.phase === 'wait') await sleep(2000);
        }
        progress(1, 1, T('Mise à jour installée. Synchronisation de la base…'));
        location.href = BASE + '/update';
      } catch (e) {
        a.disabled = false; a.textContent = T('Reprendre l\'installation');
        box.insertAdjacentHTML('beforeend', '<div class="alert alert-danger mt-2">' + esc(e.message) + '</div>');
      }
    });
  }
})();
