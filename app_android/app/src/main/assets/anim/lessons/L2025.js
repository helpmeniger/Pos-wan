/* Leçon animée « Créer un dossier sur le bureau » (code-barres 2025, texte de la vidéo vid5). */
(function () {
  const T = window.T["2025"];
  const FOLDER = s => `<svg viewBox="0 0 52 42" width="${s}" height="${s * 0.8}"><path d="M2 8 a4 4 0 0 1 4-4 h14 l5 5 h21 a4 4 0 0 1 4 4 v25 a4 4 0 0 1-4 4 H6 a4 4 0 0 1-4-4z" fill="#f4b83a"/>
    <path d="M2 15 h48 v22 a4 4 0 0 1-4 4 H6 a4 4 0 0 1-4-4z" fill="#ffd45e"/></svg>`;
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Créer un dossier"], [1, "Le bureau"], [2, "Clic droit"], [3, "Nouveau"], [6, "Dossier"], [9, "Renommer"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const D = E.desktop(d.screen, 1076, 605);
      const cur = D.cursor;

      // ---------------- menus : contextuel puis sous-menu « Nouveau »
      const X = 820, Y = 300;
      const m = E.makeMenu(D.over, ["Affichage", "Trier par", "Actualiser", "-", "Coller", "Nouveau  ›", "-", "Paramètres d’affichage", "Personnaliser"]);
      Object.assign(m.style, { left: X + "px", top: Y + "px" });
      const tMenu = S(2, 0.55), tHover = S(4, 0.5), tFold = S(7, 0.6);
      E.show(m, tMenu, tFold + 0.1, 0.12);
      E.cls(m.item(4), "hl", tHover - 0.2, tFold + 0.1);
      const sub = E.makeMenu(D.over, ["Dossier", "Raccourci", "-", "Document texte", "Document Microsoft Word", "Feuille de calcul Microsoft Excel"]);
      const [, iy] = SC.corner(m.item(4), D.root);
      Object.assign(sub.style, { left: (X + m.offsetWidth - 6) + "px", top: (iy - 8) + "px" });
      E.show(sub, S(5, 0.2), tFold + 0.1, 0.15);
      E.cls(sub.item(0), "hl", S(7, 0.2), tFold + 0.1);
      sub.item(0).innerHTML = `<span style="display:inline-flex;align-items:center;gap:10px">${FOLDER(26)}Dossier</span>`;

      // ---------------- nouveau dossier sur le bureau
      const f = $("div", "abs", D.layer, { left: (X - 10) + "px", top: (Y - 10) + "px", width: "120px", textAlign: "center", color: "#fff", fontSize: "16px" });
      f.innerHTML = `<div style="display:flex;justify-content:center">${FOLDER(70)}</div><div class="nm" style="display:inline-block;margin-top:4px;padding:1px 4px;border-radius:2px"></div>`;
      const nm = f.querySelector(".nm");
      E.show(f, tFold + 0.1, 999, 0.25);
      const tType = S(10, 0.2), tEnd = S(10, 0.65), tEnter = S(10, 0.8);
      E.track(t => {
        const editing = t < tEnter;
        const name = t < tType ? "Nouveau dossier" : "Mes cours".slice(0, Math.round(9 * Math.min(1, (t - tType) / (tEnd - tType))));
        const html = editing ? `<span style="background:${t < tType ? "#0a64c8" : "transparent"}">${name}</span>${t >= tType ? '<span style="display:inline-block;width:2px;height:17px;background:#111;vertical-align:-3px"></span>' : ""}` : name;
        if (nm._h !== html) { nm.innerHTML = html; nm._h = html; }
        nm.style.background = editing ? "#fff" : "transparent";
        nm.style.color = editing ? "#111" : "#fff";
        nm.style.boxShadow = editing ? "0 0 0 1px #326fbf" : "none";
        nm.style.textShadow = editing ? "none" : "0 1px 3px rgba(0,0,0,.7)";
      });

      // ---------------- curseur
      const [nx, ny] = SC.pos(m.item(4), D.root), [dx, dy] = SC.pos(sub.item(0), D.root);
      SC.tour(E, D.root, D.over, cur, [[0, [600, 600], 0], [S(1, 0.5), [600, 600], 0], [tMenu - 0.05, [X, Y], 1.0, 1],
        [tHover, [nx, ny], 0.8], [S(6, 0.6), [nx, ny], 0], [S(7, 0.3), [dx, dy], 0.6], [tFold - 0.05, [dx, dy], 0, 1], [S(8, 0.6), [X + 220, Y + 200], 0.8]]);
      E.ring(D.over, X + 50, Y + 40, 120, S(1, 0.2), S(1, 1));

      // ---------------- caméra (coordonnées de l'écran)
      SC.screenCam(E, [[0, "room"], [S(0, 0.6), "room"], [S(1, 0.3), 800, 450, 1, 1.0], [S(2, 0.3), 1050, 420, 1.6, 0.8], [S(8, 0.5), 1050, 420, 1.6],
        [S(9, 0.2), 870, 360, 2.4, 0.8], [S(10, 1) + 0.6, 870, 360, 2.4], [S(10, 1) + 1.8, "room", 1.2]]);

      // ---------------- cartes
      E.card(`Clic droit<div style="display:flex;justify-content:center">${SC.mouseSVG("right")}</div><small>Sur une zone vide du bureau.</small>`, S(2, 0), S(3, 1) + 0.2, { left: "40px", right: "auto", width: "360px" });
      E.card(`Nouveau › Dossier<div style="display:flex;justify-content:center;margin-top:14px">${FOLDER(110)}</div>`, S(6, 0), S(8, 1) + 0.2, { left: "40px", right: "auto", width: "380px" });
      SC.keysCard(E, "Renommer et valider", ["Entrée"], S(10, 0), 999, { left: "40px", right: "auto", width: "420px" }, "Tapez le nom, puis appuyez sur Entrée.");
    }
  });
})();
