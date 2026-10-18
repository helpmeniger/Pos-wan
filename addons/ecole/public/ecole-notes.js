/*
 * POS Marwa — module Établissement : saisie rapide des notes (clavier) et appel (« tout marquer »).
 */
(function () {
  'use strict';
  // Grille de notes : Entrée / ↓ = élève suivant, ↑ = précédent ; contrôle du barème à la saisie.
  const champs = Array.from(document.querySelectorAll('#ecNotes .ec-note'));
  const valide = (i) => {
    const v = i.value.trim().toLowerCase().replace(',', '.');
    const ok = v === '' || ['abs', 'a', 'absent'].includes(v) || (/^\d+(\.\d+)?$/.test(v) && +v >= 0 && +v <= parseFloat(i.dataset.bareme));
    i.classList.toggle('is-invalid', !ok);
    return ok;
  };
  champs.forEach((i, k) => {
    i.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === 'ArrowDown') { e.preventDefault(); valide(i); (champs[k + 1] || i).focus(); (champs[k + 1] || i).select(); }
      if (e.key === 'ArrowUp') { e.preventDefault(); (champs[k - 1] || i).focus(); (champs[k - 1] || i).select(); }
    });
    i.addEventListener('blur', () => valide(i));
  });
  const f = document.getElementById('ecNotes');
  if (f && champs.length) f.addEventListener('submit', (e) => {
    const bad = champs.filter((i) => !valide(i));
    if (bad.length) { e.preventDefault(); bad[0].focus(); alert('Note invalide : entre 0 et ' + bad[0].dataset.bareme + ', ou « abs ».'); }
  });

  // Appel : marquer tout le monde d'un clic (les cases restent modifiables une à une)
  const appel = document.getElementById('ecAppel');
  if (appel) appel.addEventListener('click', (e) => {
    const b = e.target.closest('[data-tous]');
    if (!b) return;
    appel.querySelectorAll(`input[type=radio][value="${b.dataset.tous}"]`).forEach((r) => { r.checked = true; });
  });
})();
