/* =====================================================================
   MARWA — traduction de l'interface (chargé dans <head>, avant le reste)
   Langue source : français. Le dictionnaire (window.MW_I18N) associe chaque texte
   français exact à sa traduction ; {1}, {2}… désignent des parties variables.
   Les textes sont traduits dès leur insertion dans la page (MutationObserver),
   y compris ceux créés par JavaScript (toasts, fenêtres, listes…).
   Le même moteur existe côté serveur : app/Support/I18n.php.
   ===================================================================== */
(function () {
  'use strict';
  const MW = (window.MW = window.MW || {});
  const data = window.MW_I18N || { lang: document.documentElement.lang || 'fr', dict: {} };
  const dict = data.dict || {};
  MW.lang = data.lang || 'fr';
  const active = MW.lang !== 'fr' && Object.keys(dict).length > 0;

  // Textes variables : clés contenant {1}, {2}…
  const NUM = /\d[\d\s .,:]*\d|\d/g;
  const patterns = [];
  if (active) {
    Object.keys(dict).forEach((k) => {
      if (k.indexOf('{1}') === -1 || !dict[k]) return;
      const re = new RegExp('^' + k.replace(/[.*+?^$()|[\]\\]/g, '\\$&').replace(/\\?\{(\d)\\?\}/g, '(.+?)') + '$');
      patterns.push([re, dict[k]]);
    });
  }
  const fill = (t, vals) => t.replace(/\{(\d)\}/g, (m, i) => (vals[Number(i) - 1] !== undefined ? vals[Number(i) - 1] : m));

  /** Traduit un texte français (retourne le texte inchangé s'il n'est pas connu). */
  MW.t = function (text) {
    if (!active || !text) return text;
    const s = String(text);
    const trimmed = s.trim();
    if (!trimmed) return s;
    if (Object.prototype.hasOwnProperty.call(dict, trimmed) && dict[trimmed]) return s.replace(trimmed, dict[trimmed]);
    const nums = [];
    const shape = trimmed.replace(NUM, (m) => { nums.push(m); return '{' + nums.length + '}'; });
    if (nums.length && dict[shape]) return s.replace(trimmed, fill(dict[shape], nums));
    for (let i = 0; i < patterns.length; i++) {
      const m = trimmed.match(patterns[i][0]);
      if (m) return s.replace(trimmed, fill(patterns[i][1], m.slice(1).map((v) => MW.t(v))));
    }
    return s;
  };

  if (!active) return;

  const SKIP = new Set(['SCRIPT', 'STYLE', 'TEXTAREA', 'CODE', 'PRE', 'NOSCRIPT', 'svg', 'SVG', 'path']);
  const ATTRS = ['placeholder', 'title', 'aria-label', 'alt'];
  const done = new WeakMap(); // nœud → dernière valeur écrite par la traduction

  const skipped = (el) => {
    for (let n = el; n && n.nodeType === 1; n = n.parentNode) {
      if (SKIP.has(n.nodeName) || n.isContentEditable || n.getAttribute('translate') === 'no' || n.classList.contains('notranslate')) return true;
      if (n.nodeName === 'BODY') return false;
    }
    return false;
  };
  const doText = (node) => {
    const v = node.nodeValue;
    if (!v || !v.trim() || done.get(node) === v || !node.parentNode || skipped(node.parentNode)) return;
    const t = MW.t(v);
    done.set(node, t);
    if (t !== v) node.nodeValue = t;
  };
  const doAttrs = (el) => {
    if (skipped(el)) return;
    ATTRS.forEach((a) => {
      const v = el.getAttribute && el.getAttribute(a);
      if (!v) return;
      const key = 'a:' + a;
      if (el[key] === v) return;
      const t = MW.t(v);
      el[key] = t;
      if (t !== v) el.setAttribute(a, t);
    });
    if (el.nodeName === 'INPUT' && (el.type === 'submit' || el.type === 'button') && el.value) { const t = MW.t(el.value); if (t !== el.value) el.value = t; }
  };
  const walk = (root) => {
    if (root.nodeType === 3) { doText(root); return; }
    if (root.nodeType !== 1 && root.nodeType !== 9 && root.nodeType !== 11) return;
    if (root.nodeType === 1) { if (SKIP.has(root.nodeName)) return; doAttrs(root); }
    const tw = document.createTreeWalker(root, NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT, {
      acceptNode: (n) => (n.nodeType === 1 && SKIP.has(n.nodeName) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT)
    });
    let n;
    while ((n = tw.nextNode())) { if (n.nodeType === 3) doText(n); else doAttrs(n); }
  };
  MW.translatePage = () => walk(document.body || document.documentElement);

  const obs = new MutationObserver((records) => {
    for (const r of records) {
      if (r.type === 'characterData') doText(r.target);
      else if (r.type === 'attributes') doAttrs(r.target);
      else r.addedNodes.forEach(walk);
    }
  });
  obs.observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ATTRS });
  document.addEventListener('DOMContentLoaded', () => { MW.translatePage(); document.title = MW.t(document.title); });

  // Dialogues natifs éventuels
  const nativeAlert = window.alert, nativeConfirm = window.confirm;
  window.alert = (m) => nativeAlert(MW.t(m));
  window.confirm = (m) => nativeConfirm(MW.t(m));
})();
