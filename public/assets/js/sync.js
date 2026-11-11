/* Hébergement et synchronisation : test de connexion et synchronisation pas à pas avec progression. */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const BASE = (($('meta[name="base-url"]') || {}).content || '/').replace(/\/$/, '');
  const CSRF = ($('meta[name="csrf-token"]') || {}).content || '';
  const out = $('#syncOut'); const bar = $('#syncBar');
  if (!out) return;
  async function post(path, body) {
    const r = await fetch(BASE + path, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify(body || {}) });
    return r.json();
  }
  function say(text, type) { out.className = 'alert py-2 small alert-' + (type || 'info'); out.textContent = text; out.classList.remove('d-none'); }
  const t = $('#syncTest');
  if (t) t.addEventListener('click', async () => {
    t.disabled = true; say(__('Connexion à la base en ligne…'));
    try { const d = await post('/settings/hosting/test'); say(d.ok ? d.message : d.error, d.ok ? (d.foreign ? 'warning' : 'success') : 'danger'); }
    catch (e) { say(__('Pas de réponse du serveur.'), 'danger'); }
    t.disabled = false;
  });
  const go = $('#syncGo');
  if (go) go.addEventListener('click', async () => {
    const confirmBox = $('#syncConfirm');
    go.disabled = true; let from = 0; let rows = 0; const lines = [];
    bar.parentElement.classList.remove('d-none'); bar.style.width = '2%';
    say(__('Synchronisation en cours : ne fermez pas cette page.'));
    try {
      for (;;) {
        const d = await post('/settings/hosting/sync', { from, confirm: confirmBox && confirmBox.checked ? '1' : '0' });
        if (!d.ok) { say(d.error, 'danger'); break; }
        rows += d.rows; (d.tables || []).forEach((x) => lines.push(x));
        bar.style.width = Math.max(2, Math.round(d.done * 100 / Math.max(1, d.total))) + '%';
        if (d.finished) {
          say(__('Synchronisation terminée : {t} table(s) contrôlée(s), {r} ligne(s) envoyée(s).', { t: d.total, r: rows }) + (lines.length ? ' ' + lines.slice(0, 12).join(' · ') : ''), 'success');
          setTimeout(() => location.reload(), 2500);
          break;
        }
        from = d.next;
        say(__('Synchronisation en cours : {a} / {b} tables…', { a: d.done, b: d.total }));
      }
    } catch (e) { say(__('Connexion interrompue pendant la synchronisation. Relancez : elle reprendra là où il faut.'), 'danger'); }
    go.disabled = false;
  });
})();
