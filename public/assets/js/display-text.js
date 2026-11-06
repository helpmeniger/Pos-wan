/* =====================================================================
   POS Marwa — publications de l'écran client (texte seul ou texte + photo).
   Mise en forme automatique : une ligne « Nom … prix » devient une ligne de menu avec son prix,
   « # Titre » une rubrique, « - texte » une puce ; sinon un paragraphe. Taille du texte ajustée
   au nombre de lignes, apparitions animées, fond animé. Utilisé par l'écran client ET par
   l'aperçu des paramètres (même rendu).
   ===================================================================== */
(function () {
  'use strict';
  const PALETTES = {
    nuit: { bg: '#0b1426', fg: '#ffffff', accent: '#f5a623', label: 'Nuit et or' },
    soleil: { bg: '#c2410c', fg: '#fff7ed', accent: '#fde047', label: 'Soleil' },
    foret: { bg: '#064e3b', fg: '#ecfdf5', accent: '#a3e635', label: 'Forêt' },
    bordeaux: { bg: '#4a0d1e', fg: '#fff1f2', accent: '#fbbf24', label: 'Bordeaux' },
    ocean: { bg: '#0c4a6e', fg: '#f0f9ff', accent: '#38bdf8', label: 'Océan' },
    violet: { bg: '#2e1065', fg: '#faf5ff', accent: '#f472b6', label: 'Violet' },
    clair: { bg: '#f8fafc', fg: '#0f172a', accent: '#dc2626', label: 'Clair' },
  };
  const PRICE = /^(.*?\S)[\s.:…_–—-]*[=|]?\s*((?:\d[\d\s  .,]*\d|\d)\s*(?:F\s?CFA|FCFA|CFA|F|€|\$|XOF)?)\s*$/i;

  function parse(body) {
    return String(body || '').split(/\r?\n/).map((l) => l.trim()).filter(Boolean).slice(0, 14).map((l) => {
      if (/^#{1,3}\s*/.test(l)) return { t: 'head', text: l.replace(/^#{1,3}\s*/, '') };
      // Puce : « - texte » (avec espace) ; « -20 % » reste du texte
      const bullet = /^[-•*·]\s+/.test(l) || /^[•·]/.test(l);
      const txt = bullet ? l.replace(/^[-•*·]\s*/, '') : l;
      const m = txt.match(PRICE);
      // Prix : au moins 3 chiffres ou une devise (« 2 pour 1 » reste une phrase)
      if (m && /[a-zà-ÿ]/i.test(m[1]) && (m[2].replace(/\D/g, '').length >= 3 || /[a-z€$]/i.test(m[2]))) return { t: 'item', text: m[1].replace(/[\s.:…_–—=|-]+$/, ''), price: m[2].replace(/\s+/g, ' ').trim() };
      return { t: bullet ? 'bullet' : 'line', text: txt };
    });
  }

  function colors(style) {
    const s = style || {};
    const p = PALETTES[s.palette] || PALETTES.nuit;
    const ok = (c) => (/^#[0-9a-f]{6}$/i.test(c || '') ? c : null);
    return { bg: ok(s.bg) || p.bg, fg: ok(s.fg) || p.fg, accent: ok(s.accent) || p.accent };
  }

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined) e.textContent = text;
    return e;
  }

  /** Élément de la publication. item : {title, body, style:{palette,bg,fg,accent,layout}, url} */
  function render(item) {
    const style = item.style || {};
    const c = colors(style);
    const lines = parse(item.body);
    const hasImg = !!item.url;
    let layout = style.layout || 'auto';
    if (!hasImg) layout = 'text';
    else if (layout === 'auto' || layout === 'text') layout = lines.length > 6 ? 'split' : 'overlay';
    const root = el('div', `cdt cdt-lay-${layout}${lines.some((l) => l.t === 'item') ? ' cdt-menu' : ''}`);
    root.style.setProperty('--cdt-bg', c.bg);
    // Couleur de fond en r,g,b : voile dégradé lisible, compatible avec les navigateurs des téléviseurs
    root.style.setProperty('--cdt-bg-rgb', [1, 3, 5].map((i) => parseInt(c.bg.substr(i, 2), 16)).join(','));
    root.style.setProperty('--cdt-fg', c.fg);
    root.style.setProperty('--cdt-accent', c.accent);
    // Taille ajustée : plus il y a de lignes, plus le texte est petit (toujours lisible de loin)
    const n = Math.max(1, lines.length);
    root.style.setProperty('--cdt-line', Math.max(2.4, Math.min(5.2, 17 / (n + 2.2))) + 'vh');
    root.style.setProperty('--cdt-title', Math.max(5, Math.min(11, 70 / Math.max(6, (item.title || '').length))) + 'vh');
    root.appendChild(el('div', 'cdt-blob cdt-b1'));
    root.appendChild(el('div', 'cdt-blob cdt-b2'));
    if (hasImg) {
      const ph = el('div', 'cdt-photo');
      const img = el('img');
      img.src = item.url; img.alt = '';
      ph.appendChild(img);
      root.appendChild(ph);
    }
    const box = el('div', 'cdt-text');
    if (item.title) {
      box.appendChild(el('div', 'cdt-bar'));
      const h = el('div', 'cdt-title');
      // Mots qui montent un à un
      String(item.title).split(/\s+/).forEach((w, i) => {
        const s = el('span', 'cdt-w', w);
        s.style.animationDelay = (0.25 + i * 0.09) + 's';
        h.appendChild(s);
        h.appendChild(document.createTextNode(' '));
      });
      box.appendChild(h);
    }
    const list = el('div', 'cdt-lines');
    lines.forEach((l, i) => {
      const row = el('div', 'cdt-l cdt-' + l.t);
      row.style.animationDelay = (0.7 + i * 0.16) + 's';
      if (l.t === 'item') {
        row.appendChild(el('span', 'cdt-name', l.text));
        row.appendChild(el('span', 'cdt-dots'));
        row.appendChild(el('span', 'cdt-price', l.price));
      } else {
        row.textContent = l.text;
      }
      list.appendChild(row);
    });
    box.appendChild(list);
    root.appendChild(box);
    return root;
  }

  window.PMTextSlide = { PALETTES, parse, colors, render };
})();
