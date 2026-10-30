/* JOB NIGER — comportements de l'interface (aucun script en ligne : CSP stricte) */
(function () {
  'use strict';

  // Afficher / masquer un mot de passe
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle-password'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      var icon = btn.querySelector('i');
      if (icon) icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    });
  });

  // Impression (fiches de paie, contrats) sans script en ligne
  document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });
  document.querySelectorAll('[data-back]').forEach(function (a) { a.addEventListener('click', function (e) { if (history.length > 1) { e.preventDefault(); history.back(); } }); });

  // Champs affichés selon un choix (ex : entretien en ligne / en présentiel, Mobile Money / espèces)
  document.querySelectorAll('[data-toggle-group]').forEach(function (group) {
    var name = group.getAttribute('data-toggle-group');
    function sync() {
      var checked = document.querySelector('[name="' + name + '"]:checked') || document.querySelector('select[name="' + name + '"]');
      var value = checked ? checked.value : '';
      group.querySelectorAll('[data-when]').forEach(function (el) {
        var show = el.getAttribute('data-when').split(',').indexOf(value) !== -1;
        el.hidden = !show;
        el.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !show; });
      });
    }
    document.querySelectorAll('[name="' + name + '"]').forEach(function (i) { i.addEventListener('change', sync); });
    sync();
  });

  // Confirmation avant une action sensible : <form data-confirm="Message">
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
  });

  // Empêcher les doubles soumissions (connexions lentes)
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function () {
      if (form.dataset.submitted === '1') return;
      form.dataset.submitted = '1';
      form.querySelectorAll('button[type="submit"]').forEach(function (b) {
        b.disabled = true;
        if (b.dataset.loading) b.textContent = b.dataset.loading;
      });
      // Réactiver si l'utilisateur revient en arrière (cache navigateur)
      setTimeout(function () { form.dataset.submitted = '0'; form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = false; }); }, 15000);
    });
  });

  // Inscription : champs spécifiques selon le type de compte
  var typeInputs = document.querySelectorAll('input[name="account_type"]');
  function syncAccountType() {
    var checked = document.querySelector('input[name="account_type"]:checked');
    var type = checked ? checked.value : 'WORKER';
    document.querySelectorAll('[data-show-for]').forEach(function (el) {
      var visible = el.getAttribute('data-show-for').split(',').indexOf(type) !== -1;
      el.hidden = !visible;
      el.querySelectorAll('input, select').forEach(function (i) { i.disabled = !visible; });
    });
  }
  typeInputs.forEach(function (i) { i.addEventListener('change', syncAccountType); });
  if (typeInputs.length) syncAccountType();

  // Indicateur de robustesse du mot de passe (indicatif, la règle est vérifiée côté serveur)
  var pwd = document.querySelector('[data-strength]');
  var meter = document.getElementById('password-strength');
  if (pwd && meter) {
    pwd.addEventListener('input', function () {
      var v = pwd.value, score = 0;
      if (v.length >= 8) score++;
      if (v.length >= 12) score++;
      if (/[a-zA-Z]/.test(v) && /\d/.test(v)) score++;
      if (/[^a-zA-Z0-9]/.test(v)) score++;
      var labels = ['Trop court', 'Faible', 'Correct', 'Bon', 'Excellent'];
      meter.textContent = v ? 'Robustesse : ' + labels[score] : '';
    });
  }
})();
