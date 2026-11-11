/* Synchronisation automatique vers la base en ligne : déclenchée en arrière-plan quand elle est due (poste local). */
(function () {
  'use strict';
  const el = document.querySelector('meta[name="pm-sync-auto"]');
  if (!el) return;
  const BASE = ((document.querySelector('meta[name="base-url"]') || {}).content || '/').replace(/\/$/, '');
  const CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  async function tick(from) {
    try {
      const r = await fetch(BASE + '/sync/tick', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': CSRF }, body: JSON.stringify({ from }) });
      const d = await r.json();
      if (d.ok && !d.idle && !d.finished && d.next != null) setTimeout(() => tick(d.next), 400);
    } catch (e) { /* pas d'Internet : nouvel essai plus tard */ }
  }
  setTimeout(() => tick(0), 4000);
})();
