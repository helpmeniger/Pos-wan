'use strict';
(() => {
  const VIEWS = [
    ['dashboard', 'Tableau de bord', 'index.php'],
    ['alerts', "Centre d'alertes", 'alerts.php?window=1'],
    ['sessions', 'Sessions (grille)', 'sessions.php'],
  ];
  const store = (k, v) => { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };
  let screens = [{ label: 'Écran actuel', availLeft: window.screen.availLeft ?? 0, availTop: window.screen.availTop ?? 0, availWidth: window.screen.availWidth, availHeight: window.screen.availHeight }];
  const choice = JSON.parse(store('verifia.layout') || '{}');

  function renderTable() {
    document.getElementById('screens-info').textContent = screens.length > 1
      ? `${screens.length} écrans détectés.`
      : "Un seul écran connu. Pour en détecter plusieurs, cliquez sur le bouton (navigateurs Chrome/Edge récents : API Window Management). Sinon, déplacez les fenêtres manuellement : leur position sera mémorisée par Windows.";
    document.getElementById('views').innerHTML = VIEWS.map(([k, label]) => `<tr><td>${label}</td><td><select class="form-select form-select-sm" data-view="${k}">${screens.map((s, i) => `<option value="${i}" ${String(choice[k] ?? 0) === String(i) ? 'selected' : ''}>${V.esc(s.label || 'Écran ' + (i + 1))} (${s.availWidth}×${s.availHeight})</option>`).join('')}</select></td>
      <td><button class="btn btn-sm btn-outline-light" data-open="${k}">Ouvrir</button></td></tr>`).join('');
  }
  async function detect() {
    if (!('getScreenDetails' in window)) { V.toast("Votre navigateur ne permet pas la détection des écrans. Utilisez Chrome ou Edge, ou placez les fenêtres manuellement.", 'error'); return; }
    try {
      const d = await window.getScreenDetails();
      screens = d.screens.map((s, i) => ({ label: (s.isPrimary ? 'Principal' : 'Écran ' + (i + 1)) + (s.label ? ' — ' + s.label : ''), availLeft: s.availLeft, availTop: s.availTop, availWidth: s.availWidth, availHeight: s.availHeight }));
      renderTable();
    } catch (e) { V.toast("Autorisation refusée : impossible de lister les écrans.", 'error'); }
  }
  function open(k) {
    const v = VIEWS.find((x) => x[0] === k);
    const s = screens[+(choice[k] ?? 0)] || screens[0];
    V.openPopup(v[2], 'verifia_' + k, { left: s.availLeft, top: s.availTop, width: s.availWidth, height: s.availHeight });
  }
  document.addEventListener('change', (e) => { if (e.target.dataset.view) { choice[e.target.dataset.view] = e.target.value; store('verifia.layout', JSON.stringify(choice)); } });
  document.addEventListener('click', (e) => { if (e.target.dataset.open) open(e.target.dataset.open); });
  document.getElementById('btn-detect').addEventListener('click', detect);
  document.getElementById('btn-open-all').addEventListener('click', () => VIEWS.forEach(([k]) => open(k)));
  renderTable();
})();
