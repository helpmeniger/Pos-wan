/* Générateur d'illustrations des leçons HELP-ME (images fixes rendues par anim_preview / render.js).
 * Chaque illustration est décrite dans specs.js : application (word, excel, ppt, desktop, browser, keyboard, chat, info),
 * onglet, contenu, menus ou boîtes ouvertes, et repères numérotés posés sur les éléments désignés par leur texte. */
"use strict";
window.ILL = (function () {
  const W1 = 1920, H1 = 1080;
  const norm = s => (s || "").replace(/\s+/g, " ").trim().toLowerCase();

  /** Élément visible le plus précis dont le texte contient « text » (hors rubans masqués). */
  function find(root, text) {
    const t = norm(text);
    let best = null, bestLen = 1e9;
    root.querySelectorAll("*").forEach(el => {
      if (el.closest(".rb:not(.on)")) return;
      if (el.offsetWidth === 0 && el.offsetHeight === 0) return;
      const s = norm(el.textContent);
      if (!s.includes(t)) return;
      if (s.length < bestLen) { best = el; bestLen = s.length; }
    });
    return best;
  }

  // ------------------------------------------------------------------ menus, boîtes, volets communs
  function menu(E, layer, root, m) {
    const d = E.$("div", "dd", layer, { zIndex: 40, width: (m.w || 340) + "px" });
    d.innerHTML = m.items.map((it, i) => it === "-" ? `<div style="border-top:1px solid #ddd;padding:0;margin:4px 0"></div>` : `<div class="${i === m.hl ? "hl" : ""}">${it}</div>`).join("");
    let x = m.x, y = m.y;
    if (m.at) { const a = find(root, m.at); if (a) { const [cx, cy] = SC.corner(a, root); x = cx + (m.dx || 0); y = cy + a.offsetHeight + 4 + (m.dy || 0); } }
    Object.assign(d.style, { left: x + "px", top: y + "px" });
    return d;
  }
  function dialog(E, layer, g) {
    const d = E.$("div", "dlg", layer, { left: (g.x || 560) + "px", top: (g.y || 240) + "px", width: (g.w || 480) + "px", zIndex: 45 });
    d.innerHTML = `<div class="dt">${g.title}</div><div class="dbd">${g.html}${g.buttons === false ? "" : `<div style="text-align:right;margin-top:14px"><span class="btn pri">${g.ok || "OK"}</span><span class="btn">Annuler</span></div>`}</div>`;
    return d;
  }

  /** Ruban personnalisé (onglet absent du composant) : rib = [[nom du groupe, html], ...]. */
  function customRibbon(E, root, rbMap, tabs, tab, rib) {
    if (!rib) return;
    for (const k in rbMap) rbMap[k].classList.remove("on");
    const rb = E.$("div", "rb on", root.querySelector(".ribbon"));
    rib.forEach(([label, html]) => { const g = E.$("div", "grp", rb); E.$("div", "gc", g).innerHTML = html; E.$("div", "gl", g).textContent = label; });
    if (!tabs[tab]) { const sp = E.$("span", "", root.querySelector(".tabs")); sp.textContent = tab; tabs[tab] = sp; sp.style.color = "#185abd"; }
    for (const k in tabs) tabs[k].classList.toggle("on", k === tab);
  }

  // ------------------------------------------------------------------ constructeurs de scènes
  const B = {};
  B.word = (E, s) => {
    const W = SC.word(E, E.set(), W1, H1, s.title || "Document1");
    const tab = s.tab || "Accueil";
    if (s.ctx) W.tabs[s.ctx].style.display = "";
    for (const k in W.tabs) W.tabs[k].classList.toggle("on", k === tab);
    for (const k in W.rb) W.rb[k].classList.toggle("on", k === tab);
    customRibbon(E, W.root, W.rb, W.tabs, tab, s.rib);
    W.page.innerHTML = s.doc || `<p class="h1">Rapport d’activité</p><p>Ce document présente les activités du mois. Les résultats sont encourageants et l’équipe a atteint ses objectifs.</p><p>La prochaine étape consiste à former de nouveaux membres à l’outil informatique.</p>`;
    if (s.pane) { const p = E.$("div", "pane", W.root); p.innerHTML = s.pane; }
    if (s.backstage) { const bs = SC.backstage(E, W, -1, 999); bs.main.innerHTML = s.backstage.html; if (s.backstage.hl) bs.item(s.backstage.hl).classList.add("hl"); }
    return { root: W.root, layer: W.layer, k: W1 / 1600 };
  };
  B.excel = (E, s) => {
    const X = SC.excel(E, E.set(), W1, H1, s.title || "Classeur1");
    X.tabAt([[0, s.tab || "Accueil"]]);
    for (const k in X.rb) X.rb[k].classList.toggle("on", k === (s.tab || "Accueil"));   // dès la construction (repères)
    if (s.ctx) X.tabs[s.ctx].style.display = "";
    if (s.rib) customRibbon(E, X.root, X.rb, X.tabs, s.tab, s.rib);
    if (s.fx) X.fx.textContent = s.fx;
    if (s.widths) [...X.grid.querySelectorAll("th.c")].forEach((th, i) => { if (s.widths[i]) th.style.width = s.widths[i] + "px"; });
    Object.entries(s.cells || {}).forEach(([k, v]) => { const c = X.cell(k); if (!c) return; c.innerHTML = v; if (/^-?[\d\s.,]+( F)?$/.test(v)) c.classList.add("num"); });
    (s.bold || []).forEach(k => X.cell(k).style.fontWeight = "700");
    Object.entries(s.fill || {}).forEach(([k, v]) => X.cell(k).style.background = v);
    X.selectAt([[0, s.sel || "A1"]]);
    if (s.fx) E.track(() => { X.fx.textContent = s.fx; });
    if (s.range) X.range(s.range[0], s.range[1], -1, 999);
    return { root: X.root, layer: X.layer, k: W1 / 1600 };
  };
  B.ppt = (E, s) => {
    const $ = E.$;
    const root = $("div", "win word ppt");
    root.innerHTML = `<div class="wt"><span class="logo">P</span><span style="color:#555">Enregistrement automatique</span><span class="q">&#128190;</span><span class="q">&#8630;</span><span style="margin-left:60px">${s.title || "Présentation1"} - PowerPoint</span><span class="search">&#128269;&nbsp; Rechercher</span></div>`;
    const tabs = $("div", "tabs", root);
    const T = ["Fichier", "Accueil", "Insertion", "Création", "Transitions", "Animations", "Diaporama", "Révision", "Affichage"];
    T.forEach(n => { const sp = $("span", n === (s.tab || "Accueil") ? "on" : "", tabs); sp.textContent = n; });
    const rib = $("div", "ribbon", root); const rb = $("div", "rb on", rib);
    const grp = (label, html) => { const g = $("div", "grp", rb); $("div", "gc", g).innerHTML = html; $("div", "gl", g).textContent = label; };
    const big = (t, c, ic) => `<span class="b big"><i style="background:${c}">${ic}</i>${t}</span>`;
    const gal = (items, hl) => `<div class="sty" style="gap:6px">${items.map((n, i) => `<div style="width:92px;height:72px;text-align:center;padding-top:8px${i === hl ? ";box-shadow:0 0 0 3px #c43e1c inset;background:#fbe9e4" : ""}"><b style="font-size:24px;color:#c43e1c">&#9654;</b>${n}</div>`).join("")}</div>`;
    const R = {
      Accueil: () => { grp("Presse-papiers", big("Coller", "#c9a227", "&#128203;")); grp("Diapositives", big("Nouvelle diapositive", "#c43e1c", "+") + `<div style="display:flex;flex-direction:column;gap:2px"><span class="b" style="font-size:14px">Disposition &#9662;</span><span class="b" style="font-size:14px">Réinitialiser</span><span class="b" style="font-size:14px">Section &#9662;</span></div>`); grp("Police", `<div style="display:flex;gap:4px"><span class="combo" style="width:150px">Calibri Light</span><span class="combo" style="width:56px">44</span></div><div style="display:flex;gap:1px"><span class="b" style="font-weight:800">G</span><span class="b" style="font-style:italic">I</span><span class="b" style="text-decoration:underline">S</span><span class="b" style="color:#c00000;font-weight:700">A</span></div>`); grp("Paragraphe", `<div style="display:flex;gap:1px"><span class="b">&#8226;&#8801;</span><span class="b">1&#8801;</span><span class="b">&#8676;&#8801;</span><span class="b">&#8801;</span></div>`); grp("Dessin", big("Formes", "#7a5cd6", "&#9650;") + big("Organiser", "#5a6b80", "&#9638;")); },
      Insertion: () => { grp("Diapositives", big("Nouvelle diapositive", "#c43e1c", "+")); grp("Tableaux", big("Tableau", "#1d6f42", "&#9638;")); grp("Images", big("Images", "#3aa0f5", "&#128444;")); grp("Illustrations", big("Formes", "#7a5cd6", "&#9650;") + big("Icônes", "#5a6b80", "&#9733;") + big("SmartArt", "#2b7cd3", "&#9636;") + big("Graphique", "#21a366", "&#9636;")); grp("Texte", big("Zone de texte", "#5a6b80", "A")); grp("Média", big("Vidéo", "#c43e1c", "&#9654;") + big("Audio", "#7a5cd6", "&#9835;")); },
      Création: () => { grp("Thèmes", `<div class="sty" style="gap:6px">${["#fff", "#1f3864", "#c43e1c", "#21a366", "#f4b83a", "#7a5cd6"].map((c, i) => `<div style="width:96px;height:70px;background:${c};border:1px solid #ccc${i === s.hl ? ";box-shadow:0 0 0 3px #c43e1c" : ""}"><b style="color:${i ? "#fff" : "#333"};font-size:20px">Aa</b></div>`).join("")}</div>`); grp("Variantes", `<div class="sty" style="gap:6px">${["#2b7cd3", "#21a366", "#c43e1c"].map(c => `<div style="width:60px;height:70px;background:${c}"></div>`).join("")}</div>`); grp("Personnaliser", big("Taille des diapositives", "#5a6b80", "&#9645;") + big("Mise en forme de l’arrière-plan", "#5a6b80", "&#9641;")); },
      Transitions: () => { grp("Aperçu", big("Aperçu", "#c43e1c", "&#9654;")); grp("Transition vers cette diapositive", gal(["Aucune", "Morphose", "Fondu", "Pousser", "Balayer", "Diviser", "Révéler"], s.hl)); grp("Minutage", `<div style="font-size:14px;line-height:2">Durée : 01,00 s<br>&#10003; Manuellement<br>&#9744; Après : 00:05</div>` + big("Appliquer partout", "#5a6b80", "&#8801;")); },
      Animations: () => { grp("Aperçu", big("Aperçu", "#c43e1c", "&#9654;")); grp("Animation", gal(["Aucune", "Apparaître", "Estompé", "Entrée brusque", "Forme", "Balayer", "Zoom"], s.hl)); grp("Animation avancée", big("Volet Animation", "#5a6b80", "&#9776;") + big("Ajouter une animation", "#5a6b80", "+")); grp("Minutage", `<div style="font-size:14px;line-height:2">Démarrer : Au clic &#9662;<br>Durée : 00,50<br>Délai : 00,00</div>`); },
      Diaporama: () => { grp("Démarrage du diaporama", big("À partir du début", "#c43e1c", "&#9654;") + big("À partir de la diapositive actuelle", "#c43e1c", "&#9654;")); grp("Configuration", big("Configurer le diaporama", "#5a6b80", "&#9881;") + big("Vérification du minutage", "#5a6b80", "&#9201;")); },
      Affichage: () => { grp("Modes Présentation", big("Normal", "#c43e1c", "&#9645;") + big("Trieuse de diapositives", "#5a6b80", "&#9638;") + big("Page de commentaires", "#5a6b80", "&#128221;")); grp("Modes Masques", big("Masque des diapositives", "#c43e1c", "&#9636;") + big("Masque du document", "#5a6b80", "&#9636;")); grp("Afficher", `<div style="font-size:14px;line-height:2">&#9745; Règle<br>&#9744; Quadrillage<br>&#9744; Repères</div>`); },
    };
    (R[s.tab || "Accueil"] || R.Accueil)();
    const th = $("div", "thumbs", root);
    for (let i = 0; i < 4; i++) $("div", i === (s.cur || 0) ? "on" : "", th);
    const st = $("div", "stage2", root); const sl = $("div", "slide", st);
    sl.innerHTML = s.slide || `<div style="position:absolute;left:80px;top:60px;font-size:54px;font-weight:700;color:#1f3864">Notre projet</div><div style="position:absolute;left:80px;top:170px;font-size:30px;line-height:1.7;color:#333">• Objectif clair<br>• Trois idées principales<br>• Une conclusion</div>`;
    $("div", "status", root).innerHTML = "<span>Diapositive 1 de 4</span><span>Français (France)</span>";
    const layer = $("div", "abs", root, { inset: "0" });
    root.style.transform = `scale(${W1 / 1600}, ${H1 / 900})`;
    E.set().appendChild(root);
    return { root, layer, k: W1 / 1600 };
  };
  B.desktop = (E, s) => {
    const D = E.desktop(E.set(), W1, H1);
    D.root.classList.add("word");            // menus et boîtes au style Windows
    const $ = E.$;
    E.show(D.cursor, -1, -0.5, 0);
    (s.windows || []).forEach(w => {
      const el = E.makeWindow(D.layer, w.title, { explorer: w.explorer === true, files: w.files });
      Object.assign(el.style, { left: (w.x || 200) + "px", top: (w.y || 70) + "px", width: (w.w || 1100) + "px", height: (w.h || 680) + "px" });
      if (w.html) el.body.innerHTML = w.html;
    });
    if (s.start) { const sm = $("div", "startmenu", D.over); sm.innerHTML = s.start; }
    if (s.ctx) menu(E, D.over, D.root, s.ctx);
    if (s.tray) { const f = $("div", "abs", D.over, { right: "12px", bottom: "74px", width: "460px", background: "rgba(243,246,251,.98)", borderRadius: "12px", boxShadow: "0 20px 60px rgba(0,0,0,.3)", padding: "18px 20px", fontSize: "19px" }); f.innerHTML = s.tray; }
    return { root: D.root, layer: D.layer, k: W1 / 1600 };
  };
  B.browser = (E, s) => {
    const D = E.desktop(E.set(), W1, H1);
    D.root.classList.add("word");
    E.show(D.cursor, -1, -0.5, 0);
    const br = E.$("div", "brw", D.layer);
    br.innerHTML = `<div class="bt"><div class="tab"><span>&#127760;</span><span>${s.tabTitle || "Nouvel onglet"}</span><span style="margin-left:auto;color:#888">&#10005;</span></div></div><div class="bar">&#8592; &#8594; &#10227;<div class="url">${s.url || ""}</div>&#9734;</div><div class="pg"></div>`;
    br.querySelector(".pg").innerHTML = s.page || "";
    return { root: D.root, layer: D.layer, k: W1 / 1600 };
  };
  const KB = [["Échap", "F1", "F2", "F3", "F4", "F5", "F6", "F7", "F8", "F9", "F10", "F11", "F12", "Suppr"],
    ["²", "1", "2", "3", "4", "5", "6", "7", "8", "9", "0", ")", "=", "⌫"],
    ["Tab", "A", "Z", "E", "R", "T", "Y", "U", "I", "O", "P", "^", "$", "Entrée"],
    ["Verr. maj", "Q", "S", "D", "F", "G", "H", "J", "K", "L", "M", "ù", "*"],
    ["Maj", "<", "W", "X", "C", "V", "B", "N", ",", ";", ":", "!", "Maj"],
    ["Ctrl", "⊞", "Alt", "Espace", "Alt Gr", "Ctrl", "◀", "▲", "▼", "▶"]];
  B.keyboard = (E, s) => {
    const set = E.set(); set.style.background = "linear-gradient(160deg,#f4f7fc,#e2ebf7)";
    const c = E.$("div", "combo", set); c.innerHTML = (s.keys || []).map(k => `<span class="kk">${k}</span>`).join(" + ") + (s.what ? `<small>${s.what}</small>` : "");
    const kb = E.$("div", "kb", set); if (s.combo === false) c.style.display = "none";
    const on = (s.on || s.keys || []).map(x => x.toLowerCase());
    KB.forEach(row => { const r = E.$("div", "r", kb); row.forEach(k => { const d = E.$("div", "k" + (k.length > 2 ? " sm" : "") + (on.includes(k.toLowerCase()) ? " on" : ""), r); d.textContent = k; if (k === "Espace") d.style.flex = "5"; if (["Entrée", "Maj", "Verr. maj", "Tab", "⌫"].includes(k)) d.style.flex = "1.7"; }); });
    kb.style.top = s.combo === false ? "200px" : "300px";
    return { root: set, layer: set, k: 1 };
  };
  B.chat = (E, s) => {
    const set = E.set(); const ch = E.$("div", "chat", set);
    ch.innerHTML = `<div class="top"><div class="av">IA</div>${s.title || "Assistant IA"}<span style="margin-left:auto;font-size:24px;color:#667386;font-weight:500">${s.sub || ""}</span></div>`;
    let y = 150;
    (s.msgs || []).forEach(([who, html]) => { const b = E.$("div", "bub " + who, ch); b.innerHTML = html; b.style.top = y + "px"; y += b.offsetHeight + 30; });
    return { root: set, layer: set, k: 1 };
  };
  const PERSON = (c, x, y, sc) => `<svg style="position:absolute;left:${x}px;top:${y}px" width="${380 * sc}" height="${420 * sc}" viewBox="0 0 380 420"><ellipse cx="190" cy="400" rx="170" ry="16" fill="#cfd9e8"/>
    <rect x="60" y="300" width="260" height="14" rx="7" fill="#9aa6b8"/><rect x="95" y="190" width="190" height="115" rx="10" fill="#2a2f38"/><rect x="105" y="200" width="170" height="92" rx="4" fill="#9cc3f0"/>
    <path d="M150 300 q40 -10 80 0 v100 h-80z" fill="${c}"/><circle cx="190" cy="120" r="44" fill="#8d5a3b"/><path d="M146 112 q4 -48 46 -50 q42 2 44 50 q-14 -26 -44 -24 q-30 -2 -46 24z" fill="#2a1d16"/>
    <path d="M110 260 q20 -70 80 -78 q60 8 80 78 v40 h-160z" fill="${c}"/><circle cx="174" cy="122" r="4" fill="#2a1d16"/><circle cx="206" cy="122" r="4" fill="#2a1d16"/><path d="M178 140 q12 10 24 0" stroke="#2a1d16" stroke-width="4" fill="none" stroke-linecap="round"/></svg>`;
  B.info = (E, s) => {
    const set = E.set(); const inf = E.$("div", "info", set);
    inf.innerHTML = `<h1>${s.h}${s.sub ? `<small>${s.sub}</small>` : ""}</h1>${s.person !== false ? PERSON(s.pc || "#326fbf", 1480, 40, 1) : ""}<div class="tiles" style="grid-template-columns:repeat(${s.cols || 3},1fr)">${(s.tiles || []).map((t, i) => `<div class="tile2">${t[0] ? `<div class="ic${String(t[0]).length > 2 ? " small" : ""}" style="background:${t[1] || "#326fbf"}">${t[0]}</div>` : ""}${s.num ? `<div class="n">ÉTAPE ${i + 1}</div>` : ""}<b>${t[2]}</b>${t[3] || ""}</div>`).join("")}</div>`;
    if (s.person !== false) inf.querySelector(".tiles").style.right = "80px";
    return { root: set, layer: set, k: 1 };
  };

  // ------------------------------------------------------------------ repères, légende, cadrage
  function run(id) {
    const s = window.SPECS[id];
    if (!s) { document.body.innerHTML = "spec inconnue " + id; return; }
    HM.lesson({ texts: [" "], sentences: [[0, 10]], duration: 10, steps: [],
      build(E) {
        const sc = B[s.app](E, s);
        if (s.menu) menu(E, sc.layer, sc.root, s.menu);
        if (s.dialog) dialog(E, sc.layer, s.dialog);
        // cadrage : zoom fixé dans la spec, ou automatique autour des repères
        const pts = (s.marks || []).map(([txt]) => { const el = typeof txt === "string" ? find(sc.root, txt) : null; if (!el) { console.error("repère introuvable : " + txt); return null; } const [x, y] = SC.corner(el, sc.root); return { el, x, y, w: el.offsetWidth, h: el.offsetHeight }; });
        const k = sc.k;
        let cx = W1 / 2, cy = H1 / 2, z = 1;
        if (s.zoom) { cx = s.zoom[0] * k; cy = s.zoom[1] * k; z = s.zoom[2]; }
        else if (["word", "excel", "ppt", "desktop", "browser"].includes(s.app) && pts.some(Boolean)) {
          // zoom automatique : la zone des repères (et des menus ouverts) remplit l'image, lisible sur téléphone
          const box = pts.filter(Boolean).map(p => [p.x, p.y, p.x + p.w, p.y + p.h]);
          sc.root.querySelectorAll(".dd,.dlg,.pane").forEach(el => { const [x, y] = SC.corner(el, sc.root); box.push([x, y, x + el.offsetWidth, y + el.offsetHeight]); });
          const x1 = Math.min(...box.map(b => b[0])) - 60, y1 = Math.min(...box.map(b => b[1])) - 60, x2 = Math.max(...box.map(b => b[2])) + 60, y2 = Math.max(...box.map(b => b[3])) + 140;
          z = Math.max(1, Math.min(s.zmax || 1.8, Math.min(W1 / ((x2 - x1) * k), H1 / ((y2 - y1) * k))));
          cx = (x1 + x2) / 2 * k; cy = (y1 + y2) / 2 * k + 30;
        }
        E.camera([[0, cx, cy, z]]);
        const tx = Math.min(0, Math.max(W1 - W1 * z, W1 / 2 - cx * z)), ty = Math.min(0, Math.max(H1 - H1 * z, H1 / 2 - cy * z));
        const toHud = (x, y) => [x * k * z + tx, y * k * z + ty];
        pts.forEach((p, i) => {
          if (!p) return;
          const [x1, y1] = toHud(p.x, p.y), [x2, y2] = toHud(p.x + p.w, p.y + p.h);
          if (s.boxes !== false) E.$("div", "mkbox", E.hud, { left: (x1 - 6) + "px", top: (y1 - 6) + "px", width: (x2 - x1 + 12) + "px", height: (y2 - y1 + 12) + "px" });
          const m = E.$("div", "mk", E.hud, { left: Math.min(W1 - 30, x2 + 4) + "px", top: Math.max(30, y1 - 4) + "px" }); m.textContent = i + 1;
        });
        if (s.legend !== false && (s.marks || []).length) {
          const lg = E.$("div", "legend " + (s.lpos || ""), E.hud);
          lg.innerHTML = (s.lt ? `<div class="ttl">${s.lt}</div>` : "") + s.marks.map(([, t], i) => `<div class="li"><b>${i + 1}</b><span>${t}</span></div>`).join("");
          if (s.lw) lg.style.maxWidth = s.lw + "px";
        }
        if (s.note) { const n = E.$("div", "legend " + (s.npos || "right top"), E.hud); n.innerHTML = s.note; n.style.maxWidth = "620px"; }
      } });
  }
  return { run, find };
})();
