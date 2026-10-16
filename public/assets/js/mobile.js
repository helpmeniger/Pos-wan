/* Accès mobile : test de l'adresse réseau du serveur (depuis ce navigateur). */
(function () {
  'use strict';
  const b = document.getElementById('testAccess');
  if (!b) return;
  const out = document.getElementById('testResult');
  b.addEventListener('click', async () => {
    out.className = 'small mt-2 text-secondary'; out.textContent = 'Test en cours…';
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), 6000);
    try {
      const r = await fetch(b.dataset.health, { signal: ctl.signal, cache: 'no-store' });
      const d = await r.json();
      out.className = 'small mt-2 ' + (d.ok ? 'text-success' : 'text-danger');
      out.textContent = d.ok ? '✔ Le serveur répond à cette adresse (application et base de données). Si le smartphone affiche « Forbidden » ou ne charge pas, appliquez les réglages Apache / pare-feu ci-contre.' : '✖ Le serveur répond, mais la base de données est indisponible (MySQL/MariaDB arrêté ?).';
    } catch (e) {
      out.className = 'small mt-2 text-danger';
      out.textContent = '✖ Aucune réponse à cette adresse : vérifiez que l\'ordinateur est connecté au réseau, puis le pare-feu Windows (réglages ci-contre).';
    } finally { clearTimeout(timer); }
  });
})();
