/* Décors et schémas partagés par les leçons animées HELP-ME. */
window.SC = (function () {
  "use strict";

  /** Bureau : pièce, table, écran (éteint par défaut), clavier, souris, pot à crayons, unité centrale, prise. */
  function deskSet(E, opt) {
    opt = opt || {};
    const $ = E.$, set = E.set();
    $("div", "room", set);
    $("div", "window-light", set, { left: "300px" }); $("div", "window-light", set, { left: "1650px" });
    $("div", "desk", set);
    const r = {};
    if (opt.outlet) {
      r.outlet = $("div", "outlet", set);
      $("div", "sock", r.outlet, { top: "18px" }); $("div", "sock", r.outlet, { top: "94px" });
      r.cable = $("div", "cable", set);
      r.plug = $("div", "plug", set);
    }
    if (opt.tower) {
      r.tower = $("div", "tower", set);
      $("div", "grill", r.tower);
      r.button = $("div", "powerbtn", r.tower); r.button.innerHTML = E.svgPower(52, "#9fb4cc", 5);
      $("div", "slot", r.tower); $("div", "usb", r.tower);
    } else {
      $("div", "pencils", set);
    }
    r.monitor = $("div", "monitor", set);
    r.screen = $("div", "screen", r.monitor);
    r.led = $("div", "led", r.monitor);
    $("div", "stand", set); $("div", "foot", set);
    r.keyboard = $("div", "keyboard", set);
    for (let y = 0; y < 3; y++) for (let x = 0; x < 15; x++) $("i", "", r.keyboard, { left: 16 + x * 52 + "px", top: 12 + y * 26 + "px" });
    r.mouse = $("div", "mousedev", set);
    r.set = set;
    return r;
  }

  /** Ordinateur portable (pièce aux tons roses, comme dans les vidéos d'origine). */
  function laptopSet(E) {
    const $ = E.$, set = E.set("laptop");
    $("div", "room pink", set);
    $("div", "desk", set, { top: "760px" });
    const r = { set };
    r.lscreen = $("div", "lscreen", set);
    r.screen = $("div", "screen", r.lscreen);
    $("div", "base", set); $("div", "deck", set);
    r.touchpad = $("div", "touchpad", set);
    r.pwr = $("div", "pwr", set);
    return r;
  }

  /** Schéma de souris (SVG) : met en valeur la partie utilisée. */
  function mouseSVG(part) {
    const hl = "#326fbf", base = "#f2f4f8", line = "#465063";
    const L = part === "left" || part === "double" ? hl : base, R = part === "right" ? hl : base, Wh = part === "wheel" ? hl : "#7a8496";
    return `<svg width="250" height="330" viewBox="0 0 250 330">
      <rect x="35" y="15" width="180" height="300" rx="88" fill="${base}" stroke="${line}" stroke-width="6"/>
      <path d="M41 103 L125 103 L125 21 C 75 21 41 50 41 103 Z" fill="${L}"/>
      <path d="M209 103 L125 103 L125 21 C 175 21 209 50 209 103 Z" fill="${R}"/>
      <line x1="125" y1="18" x2="125" y2="105" stroke="${line}" stroke-width="5"/>
      <line x1="38" y1="105" x2="212" y2="105" stroke="${line}" stroke-width="5"/>
      <rect x="112" y="38" width="26" height="48" rx="13" fill="${Wh}"/>
      ${part === "double" ? '<text x="83" y="78" font-size="34" font-weight="800" fill="#fff">×2</text>' : ""}
    </svg>`;
  }

  /** Carte de souris dont la partie mise en valeur suit le temps. */
  function mouseCard(E, plan) {           // plan: [[t0, t1, part, titre], ...]
    const cards = plan.map(([a, b, part, title]) => E.card(`${title}<svg width="250" height="330"></svg><small></small>`, a, b, { top: "120px", width: "330px" }));
    plan.forEach(([a, b, part], i) => {
      const c = cards[i], holder = c.querySelector("svg");
      holder.outerHTML = mouseSVG(part);
      if (part === "move") {
        const svg = c.querySelector("svg");
        E.track(t => { if (t >= a && t <= b) svg.style.transform = `translateX(${Math.sin((t - a) * 3.4) * 26}px)`; });
      }
      if (part === "wheel") {
        const svg = c.querySelector("svg");
        E.track(t => { if (t >= a && t <= b) svg.querySelector("rect:nth-of-type(2)").setAttribute("y", 38 + ((t - a) * 30 % 14)); });
      }
      if (part === "left" || part === "double" || part === "right") {
        const svg = c.querySelector("svg");
        E.track(t => { if (t >= a && t <= b) { const k = (Math.sin((t - a) * (part === "double" ? 14 : 7)) + 1) / 2; svg.style.filter = `brightness(${1 + 0.15 * k})`; } });
      }
    });
  }

  /** Carte du pavé tactile : doigts qui glissent ou tapotent. */
  function touchCard(E, a, b, title, gesture) {
    const c = E.card(`${title}<svg width="360" height="230" viewBox="0 0 360 230">
       <rect x="10" y="10" width="340" height="210" rx="26" fill="#e7ebf2" stroke="#9ea7b5" stroke-width="5"/>
       <circle class="f1" r="24" fill="#ffcfac" stroke="#c98a6e" stroke-width="4"/>
       <circle class="f2" r="24" fill="#ffcfac" stroke="#c98a6e" stroke-width="4"/>
       <circle class="w1" r="30" fill="none" stroke="#326fbf" stroke-width="5"/>
       <circle class="w2" r="30" fill="none" stroke="#326fbf" stroke-width="5"/>
       <path class="arr" d="M325 40 l-14 24 h28 z M325 190 l-14 -24 h28 z" fill="#326fbf"/></svg>`, a, b, { top: "120px", width: "440px" });
    const q = s => c.querySelector(s);
    const two = gesture === "two" || gesture === "scroll";
    q(".f2").style.display = two ? "" : "none"; q(".w2").style.display = gesture === "two" ? "" : "none";
    q(".w1").style.display = gesture === "tap" || gesture === "two" ? "" : "none";
    q(".arr").style.display = gesture === "scroll" ? "" : "none";
    E.track(t => {
      if (t < a || t > b) return;
      const ph = ((t - a) % 1.6) / 1.6;
      let x = 180, y = 115;
      if (gesture === "move") x = 70 + 220 * E.ease(1 - Math.abs(2 * ph - 1));
      if (gesture === "scroll") y = 50 + 130 * E.ease(ph);
      const set = (el, cx, cy) => { el.setAttribute("cx", cx); el.setAttribute("cy", cy); };
      set(q(".f1"), two ? x - 38 : x, y); set(q(".f2"), x + 38, y);
      const r = 26 + 60 * ph;
      [q(".w1"), q(".w2")].forEach((w, i) => { set(w, two ? x + (i ? 38 : -38) : x, y); w.setAttribute("r", r); w.style.opacity = 1 - ph; });
    });
  }


  // ------------------------------------------------------------------ outils de placement et caméra
  /** Centre de l'élément el, en coordonnées de root (1600 x 900 pour un écran Windows). */
  function pos(el, root, dx, dy) {
    let x = el.offsetWidth / 2, y = el.offsetHeight / 2;
    for (let e = el; e && e !== root; e = e.offsetParent) { x += e.offsetLeft; y += e.offsetTop; }
    return [x + (dx || 0), y + (dy || 0)];
  }
  /** Coin haut-gauche de l'élément el en coordonnées de root. */
  function corner(el, root) {
    let x = 0, y = 0;
    for (let e = el; e && e !== root; e = e.offsetParent) { x += e.offsetLeft; y += e.offsetTop; }
    return [x, y];
  }
  /**
   * Caméra exprimée en coordonnées de l'écran du moniteur (1600 x 900) : [[t, x, y, zoom, durée]],
   * zoom 1 = l'écran remplit l'image ; [t, "room", durée] = vue d'ensemble de la pièce.
   */
  function screenCam(E, list) {
    const k = 1076 / 1600, Z = 1920 / 1076;
    const clampTo = (v, z, size) => { const half = size / z / 2; return Math.min(Math.max(v, half), size - half); };   // reste dans l'écran
    E.camera(list.map(e => e[1] === "room" ? [e[0], 960, 540, 1, e[2]]
      : [e[0], 422 + clampTo(e[1], e[3], 1600) * k, 62 + clampTo(e[2], e[3], 900) * k, Z * e[3], e[4]]));
  }

  const IBEAM = `<svg viewBox="0 0 38 54" width="38" height="54"><path d="M11 6 h6 l2 2 l2 -2 h6 M19 8 v38 M11 48 h6 l2 -2 l2 2 h6" fill="none" stroke="#fff" stroke-width="7" stroke-linecap="round"/>
    <path d="M11 6 h6 l2 2 l2 -2 h6 M19 8 v38 M11 48 h6 l2 -2 l2 2 h6" fill="none" stroke="#111" stroke-width="2.6" stroke-linecap="round"/></svg>`;

  /** Bascule le dessin du curseur selon le temps : [[t, "arrow" | "ibeam"]]. */
  function cursorModes(E, cur, list) {
    E.track(t => {
      let m = "arrow";
      for (const [a, v] of list) if (t >= a) m = v;
      if (cur.dataset.m !== m) { cur.dataset.m = m; cur.innerHTML = m === "ibeam" ? IBEAM : E.ARROW; cur.style.margin = m === "ibeam" ? "-27px 0 0 -19px" : "0"; }
    });
  }

  /** Texte tapé progressivement dans el entre a et b (avec point d'insertion clignotant). */
  function typeText(E, el, text, a, b, before) {
    before = before || "";
    const esc = x => x.replace(/&/g, "&amp;").replace(/</g, "&lt;");
    E.track(t => {
      const n = t <= a ? 0 : t >= b ? text.length : Math.round(text.length * (t - a) / (b - a));
      const typing = text.length > 0 && t > a && t < b;
      const caret = t > a - 0.6 && t < b + 1.4 && (typing || Math.floor(t * 2.2) % 2 === 0);
      const html = esc(before) + esc(text.slice(0, n)) + (caret ? '<span class="caret"></span>' : "");
      if (el._h !== html) { el.innerHTML = html; el._h = html; }
    });
  }

  /** Carte avec des touches du clavier (enfoncées à tour de rôle). keys : ["Ctrl", "A"]. */
  function keysCard(E, title, keys, a, b, pos, note) {
    const c = E.card(`${title}<div style="margin-top:20px;text-align:center">${keys.map((k, i) => (i ? '<span style="font-size:30px;color:#667386"> + </span>' : "") + `<span class="kbd">${k}</span>`).join("")}</div>${note ? `<small>${note}</small>` : ""}`, a, b, pos);
    const ks = [...c.querySelectorAll(".kbd")];
    E.track(t => { const on = t > a + 0.6 && ((t - a) % 2.2) > 0.6 && ((t - a) % 2.2) < 1.6; ks.forEach(k => k.classList.toggle("dn", on)); });
    return c;
  }

  /** Remplit el avec un <span> par mot (pour les sélections) ; renvoie la liste des mots. */
  function words(E, el, text) {
    return text.split(/(?<= )/).map(w => { const s = E.$("span", "", el); s.textContent = w; return s; });
  }
  /** Sélection progressive : les mots list[i0..i1] passent en surbrillance de a à b, restent jusqu'à c. */
  function selectRange(E, list, i0, i1, a, b, c) {
    list.forEach((w, i) => {
      if (i < i0 || i > i1) return;
      if (!w._sr) { w._sr = []; E.track(t => w.classList.toggle("sel", w._sr.some(f => f(t)))); }
      w._sr.push(t => {
        const k = t < a ? -1 : t >= b ? i1 : i0 + Math.floor((i1 - i0 + 1) * (t - a) / Math.max(b - a, 1e-3));
        return t >= a && t <= c && i <= k;
      });
    });
  }

  /**
   * Parcours du curseur : [[t, cible, durée, clics]] ; la cible est un élément de root ou [x, y].
   * Le curseur arrive sur la cible à l'instant t (déplacement de « durée » secondes) ; clics = 0, 1 ou 2.
   */
  function tour(E, root, over, cur, list) {
    const keys = [];
    list.forEach(([t, target, dur, n]) => {
      const [x, y] = Array.isArray(target) ? target : pos(target, root);
      if (keys.length) keys.push([Math.max(t - (dur == null ? 0.6 : dur), keys[keys.length - 1][0] + 0.01), keys[keys.length - 1][1], keys[keys.length - 1][2]]);
      keys.push([t, x, y, dur == null ? 0.6 : dur]);
      if (n) E.click(over, t + 0.05, x, y, n);
    });
    E.move(cur, keys);
  }

  // ------------------------------------------------------------------ Microsoft Word
  /**
   * Fenêtre Word plein écran (1600 x 900) posée dans host (écran du moniteur).
   * Renvoie les éléments utiles : onglets, rubans, boutons, page, calques et curseur.
   */
  function word(E, host, w, h, docTitle) {
    const $ = E.$;
    const root = $("div", "win word");
    const W = { root, el: {} };
    const t = $("div", "wt", root);
    t.innerHTML = `<span class="logo">W</span><span style="color:#555">Enregistrement automatique</span><span class="q sv">&#128190;</span>
      <span class="q undo">&#8630;</span><span class="q redo">&#8631;</span><span style="margin-left:60px">${docTitle || "Document1"} - Word</span><span class="search">&#128269;&nbsp; Rechercher</span>`;
    W.el.undo = t.querySelector(".undo"); W.el.redo = t.querySelector(".redo");
    const tabs = $("div", "tabs", root);
    W.tabs = {};
    ["Fichier", "Accueil", "Insertion", "Dessin", "Conception", "Mise en page", "Références", "Publipostage", "Révision", "Affichage", "Aide"]
      .forEach(n => { W.tabs[n] = $("span", "", tabs); W.tabs[n].textContent = n; });
    const ribbon = $("div", "ribbon", root);
    W.rb = {};
    const grp = (rb, label, html, launch) => {
      const g = $("div", "grp", rb); const c = $("div", "gc", g); c.innerHTML = html; $("div", "gl", g).textContent = label;
      if (launch) g.launch = $("i", "launch", g);
      return g;
    };
    // Accueil
    let rb = W.rb.Accueil = $("div", "rb", ribbon);
    grp(rb, "Presse-papiers", `<span class="b big"><i style="background:#c9a227">&#128203;</i>Coller</span><div style="display:flex;flex-direction:column;gap:2px"><span class="b">&#9986;</span><span class="b">&#10697;</span></div>`);
    const gp = grp(rb, "Police", `<div style="display:flex;gap:4px;width:250px"><span class="combo font" style="width:170px">Calibri (Corps)</span><span class="combo size" style="width:56px">11</span><span class="b" style="font-size:15px">A<sup>+</sup></span></div>
      <div style="display:flex;gap:1px;width:300px"><span class="b bold" style="font-weight:800">G</span><span class="b italic" style="font-style:italic;font-family:serif">I</span><span class="b under" style="text-decoration:underline">S</span>
      <span class="b strike" style="text-decoration:line-through">ab</span><span class="b">x<sub>2</sub></span><span class="b">x<sup>2</sup></span>
      <span class="b high" style="flex-direction:column;line-height:14px">&#9998;<i style="display:block;width:20px;height:5px;background:#ffeb00"></i></span>
      <span class="b color" style="flex-direction:column;line-height:16px;font-weight:700">A<i style="display:block;width:20px;height:5px;background:#e00000"></i></span></div>`, true);
    const gpa = grp(rb, "Paragraphe", `<div style="display:flex;gap:1px;width:250px"><span class="b bul">&#8226;&#8801;</span><span class="b num">1&#8801;</span><span class="b indl">&#8676;</span><span class="b indr">&#8677;</span><span class="b">&#182;</span></div>
      <div style="display:flex;gap:1px"><span class="b al">&#8676;&#8801;</span><span class="b ac">&#8801;</span><span class="b ar">&#8801;&#8677;</span><span class="b aj">&#9776;</span><span class="b sp">&#8693;&#8801;</span></div>`, true);
    const gs = grp(rb, "Styles", `<div class="sty"><div><span style="font-size:15px">AaBbCc</span><br>Normal</div><div><span style="font-size:15px">AaBbCc</span><br>Sans interligne</div><div class="t1"><b>AaBbCc</b>Titre 1</div><div class="t2"><b style="font-size:15px">AaBbCcD</b>Titre 2</div><div><b style="font-size:21px">AaB</b>Titre</div></div>`);
    grp(rb, "Édition", `<span class="b big"><i style="background:#5a6b80">&#128269;</i>Rechercher</span>`);
    Object.assign(W.el, {
      font: gp.querySelector(".font"), size: gp.querySelector(".size"), bold: gp.querySelector(".bold"), italic: gp.querySelector(".italic"), under: gp.querySelector(".under"),
      strike: gp.querySelector(".strike"), high: gp.querySelector(".high"), color: gp.querySelector(".color"), launchFont: gp.launch, launchPara: gpa.launch,
      bul: gpa.querySelector(".bul"), indl: gpa.querySelector(".indl"), indr: gpa.querySelector(".indr"),
      al: gpa.querySelector(".al"), ac: gpa.querySelector(".ac"), ar: gpa.querySelector(".ar"), aj: gpa.querySelector(".aj"), sp: gpa.querySelector(".sp"),
      t1: gs.querySelector(".t1"), t2: gs.querySelector(".t2"), grpFont: gp, grpPara: gpa, grpStyles: gs
    });
    // Insertion (décor)
    rb = W.rb.Insertion = $("div", "rb", ribbon);
    grp(rb, "Pages", `<span class="b big"><i style="background:#326fbf">&#128196;</i>Page de garde</span><span class="b big"><i style="background:#5a6b80">&#9645;</i>Page vierge</span>`);
    grp(rb, "Tableaux", `<span class="b big"><i style="background:#1d6f42">&#9638;</i>Tableau</span>`);
    grp(rb, "Illustrations", `<span class="b big"><i style="background:#c43e1c">&#128444;</i>Images</span><span class="b big"><i style="background:#7a5cd6">&#9650;</i>Formes</span>`);
    // Références
    rb = W.rb["Références"] = $("div", "rb", ribbon);
    const gt = grp(rb, "Table des matières", `<span class="b big toc"><i style="background:#326fbf">&#9776;</i>Table des<br>matières</span><div style="display:flex;flex-direction:column;gap:2px"><span class="b" style="font-size:14px">+ Ajouter le texte</span><span class="b tocupd" style="font-size:14px">&#10227; Mettre à jour la table</span></div>`);
    grp(rb, "Notes de bas de page", `<span class="b big"><i style="background:#5a6b80">ab<sup>1</sup></i>Insérer une note</span>`);
    grp(rb, "Citations et bibliographie", `<span class="b big"><i style="background:#5a6b80">&#10077;</i>Insérer une citation</span>`);
    grp(rb, "Légendes", `<span class="b big"><i style="background:#5a6b80">&#9635;</i>Insérer une légende</span>`);
    W.el.toc = gt.querySelector(".toc"); W.el.tocUpd = gt.querySelector(".tocupd");
    // Affichage
    rb = W.rb.Affichage = $("div", "rb", ribbon);
    grp(rb, "Affichages", `<span class="b big"><i style="background:#5a6b80">&#128214;</i>Mode Lecture</span><span class="b big on"><i style="background:#326fbf">&#128196;</i>Page</span><span class="b big"><i style="background:#5a6b80">&#127760;</i>Web</span>`);
    const ga = grp(rb, "Afficher", `<div style="display:flex;flex-direction:column;gap:6px;padding-top:4px"><span class="chk rule"><i></i>Règle</span><span class="chk"><i></i>Quadrillage</span><span class="chk"><i></i>Volet de navigation</span></div>`);
    grp(rb, "Zoom", `<span class="b big"><i style="background:#5a6b80">&#128269;</i>Zoom</span><span class="b big"><i style="background:#5a6b80">100</i>100 %</span>`);
    W.el.rule = ga.querySelector(".rule");
    // règle, document, barre d'état
    W.ruler = $("div", "ruler", root); $("i", "", W.ruler);
    W.docarea = $("div", "docarea", root);
    W.page = $("div", "page", W.docarea);
    $("div", "status", root).innerHTML = "<span>Page 1 sur 1</span><span class=\"words\">0 mot</span><span>Français (France)</span><span style=\"margin-left:auto\">Focus &nbsp; &#9636; &#9637; &nbsp; ─────●──── 100 %</span>";
    W.layer = $("div", "abs", root, { inset: "0" });
    W.over = $("div", "abs", root, { inset: "0" });
    W.cursor = $("div", "cursor", W.over); W.cursor.innerHTML = E.ARROW;
    root.style.transform = `scale(${w / 1600}, ${h / 900})`;
    host.appendChild(root);
    /** Onglet actif selon le temps : [[t, "Accueil"], ...]. */
    W.tabAt = list => E.track(tt => {
      let n = "Accueil"; for (const [a, v] of list) if (tt >= a) n = v;
      if (W._tab !== n) { W._tab = n; for (const k in W.tabs) W.tabs[k].classList.toggle("on", k === n); for (const k in W.rb) W.rb[k].classList.toggle("on", k === n); }
    });
    W.pos = (el, dx, dy) => pos(el, root, dx, dy);
    return W;
  }

  return { deskSet, laptopSet, mouseCard, touchCard, mouseSVG, pos, corner, screenCam, IBEAM, cursorModes, typeText, keysCard, word, words, selectRange, tour };
})();
