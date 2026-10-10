/* Formatage monétaire (devise des paramètres) et traduction des textes générés en JavaScript. */
(function () {
  const m = document.querySelector('meta[name="pw-currency"]');
  let c = { symbol: 'F', position: 'after', decimals: 0 };
  try { if (m) c = Object.assign(c, JSON.parse(m.content)); } catch (e) { /* valeurs par défaut */ }
  const num = (n, d) => (Number(n) || 0).toLocaleString('fr-FR', { minimumFractionDigits: d, maximumFractionDigits: d }).replace(/ /g, ' ');
  window.PWMoney = {
    c,
    /** montant sans symbole */
    plain: (n) => num(n, c.decimals),
    /** montant avec symbole */
    fmt: (n) => {
      const s = c.position === 'before' ? c.symbol + ' ' + num(n, c.decimals) : num(n, c.decimals) + ' ' + c.symbol;
      // Arabe : montant isolé en gauche à droite (chiffres et symbole non réordonnés)
      return document.documentElement.dir === 'rtl' ? '\u2066' + s + '\u2069' : s;
    },
    round: (n) => { const f = Math.pow(10, c.decimals); return Math.round((Number(n) || 0) * f) / f; },
  };
})();

/* __('Texte {x}', {x: 1}) : traduction (dictionnaire <script id="pw-i18n">), français par défaut. */
(function () {
  let dict = {};
  const el = document.getElementById('pw-i18n');
  try { if (el) dict = JSON.parse(el.textContent); } catch (e) { dict = {}; }
  window.__ = function (s, params) {
    let out = Object.prototype.hasOwnProperty.call(dict, s) ? dict[s] : s;
    if (params) Object.keys(params).forEach((k) => { out = out.split('{' + k + '}').join(String(params[k])); });
    return out;
  };
})();
