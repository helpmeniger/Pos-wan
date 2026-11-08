/* =====================================================================
   POS Marwa — barre de progression du chargement (haut de l'écran).
   Apparaît pendant le chargement d'une page (lien, formulaire) et pendant les
   échanges avec le serveur qui durent (enregistrement, recherche…). Les
   actualisations automatiques rapides (écrans cuisine, pressing) ne l'affichent pas :
   elle ne se montre qu'après 350 ms d'attente.
   ===================================================================== */
(function () {
  'use strict';
  if (window.PMProgress) return;
  const bar = document.createElement('div');
  bar.setAttribute('role', 'progressbar');
  bar.setAttribute('aria-label', 'Chargement');
  bar.id = 'pm-progress';
  Object.assign(bar.style, {
    position: 'fixed', top: '0', left: '0', height: '3px', width: '0', zIndex: '2147483000', pointerEvents: 'none', opacity: '0',
    background: 'linear-gradient(90deg, #f5a623, #ffd166, #2a9d8f)', boxShadow: '0 0 8px rgba(245,166,35,.7)',
    transition: 'width .25s ease, opacity .3s ease',
  });
  const mount = () => { if (!bar.isConnected && document.body) document.body.appendChild(bar); };
  if (document.body) mount(); else document.addEventListener('DOMContentLoaded', mount);

  let pct = 0, timer = null, delay = null, pending = 0, navigating = false;
  function tick() {
    // Progression qui ralentit à l'approche de la fin (jamais 100 % avant la réponse)
    pct += (92 - pct) * (pct < 40 ? 0.12 : 0.05);
    bar.style.width = pct.toFixed(1) + '%';
  }
  function start(wait) {
    mount();
    clearTimeout(delay);
    const go = () => {
      if (timer) return;
      pct = Math.max(pct, 8);
      bar.style.transition = 'width .25s ease, opacity .3s ease';
      bar.style.opacity = '1';
      bar.style.width = pct + '%';
      timer = setInterval(tick, 200);
    };
    if (wait) delay = setTimeout(go, wait); else go();
  }
  function done() {
    clearTimeout(delay);
    if (!timer) { pct = 0; return; }
    clearInterval(timer); timer = null;
    bar.style.width = '100%';
    setTimeout(() => { bar.style.opacity = '0'; }, 200);
    setTimeout(() => { if (!timer) { bar.style.transition = 'none'; bar.style.width = '0'; pct = 0; } }, 550);
  }

  // Navigation : liens internes et formulaires (sauf nouvel onglet, téléchargement, ancre, impression)
  document.addEventListener('click', (e) => {
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    if ((a.target && a.target !== '_self') || a.hasAttribute('download') || a.dataset.noProgress !== undefined) return;
    const href = a.getAttribute('href') || '';
    if (href.startsWith('#') || /^(javascript|mailto|tel|sms|whatsapp):/i.test(href)) return;
    let u;
    try { u = new URL(a.href, location.href); } catch (err) { return; }
    if (u.origin !== location.origin || (u.pathname === location.pathname && u.search === location.search && u.hash)) return;
    // Laisser les autres scripts annuler le clic d'abord
    setTimeout(() => { if (!e.defaultPrevented) { navigating = true; start(0); } }, 0);
  });
  document.addEventListener('submit', (e) => {
    const f = e.target;
    if (!(f instanceof HTMLFormElement) || (f.target && f.target !== '_self') || f.dataset.noProgress !== undefined) return;
    setTimeout(() => { if (!e.defaultPrevented) { navigating = true; start(0); } }, 0);
  });
  // Retour arrière (page restaurée depuis le cache) ou téléchargement : on arrête
  window.addEventListener('pageshow', () => { navigating = false; done(); });
  // Lien de téléchargement (export PDF, Excel) : la page ne change pas, la barre s'arrête seule
  let guard = null;
  const watch = () => { clearTimeout(guard); guard = setTimeout(() => { if (navigating) { navigating = false; done(); } }, 15000); };
  document.addEventListener('click', () => setTimeout(() => { if (navigating) watch(); }, 0));
  document.addEventListener('submit', () => setTimeout(() => { if (navigating) watch(); }, 0));

  // Échanges avec le serveur (fetch) qui durent plus de 350 ms
  if (window.fetch) {
    const orig = window.fetch.bind(window);
    window.fetch = function (input, init) {
      if (init && init.pmNoProgress) return orig(input, init);
      pending++;
      if (pending === 1 && !navigating) start(350);
      const end = () => { pending = Math.max(0, pending - 1); if (!pending && !navigating) done(); };
      return orig(input, init).then((r) => { end(); return r; }, (err) => { end(); throw err; });
    };
  }
  window.PMProgress = { start: () => start(0), done };
})();
