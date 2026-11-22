/* Leçon animée « La souris et le pavé tactile » (code-barres 2018) — remplace la vidéo vid2. */
(function () {
  const T = window.T["2018"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "La souris"], [1, "Déplacer"], [2, "Cliquer"], [3, "Double-cliquer"], [4, "Clic droit"], [5, "La molette"],
            [6, "Le pavé tactile"], [7, "Déplacer"], [8, "Cliquer"], [9, "Clic droit"], [10, "Faire défiler"]],
    build(E) {
      const S = E.S, $ = E.$;
      const swap = S(6, 0.05);                       // passage de l'ordinateur de bureau au portable

      // ================= partie 1 : ordinateur de bureau et souris
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const desk = E.desktop(d.screen, 1076, 605);
      E.show(d.set, -1, swap + 0.4, 0.5);
      const ico = desk.icons, cur = desk.cursor;

      // fenêtre « Ce PC » ouverte par double-clic, puis défilement à la molette
      const wnd = E.makeWindow(desk.layer, "Ce PC", {
        files: ["Cours", "Exercices", "Factures", "Photos", "Rapport", "CV", "Projets", "Musique", "Archives", "Notes", "Budget", "Modèles",
                "Stage", "Lettres", "Devis", "Images", "Tableaux", "Diaporamas", "Contrats", "Divers"] });
      Object.assign(wnd.style, { left: "330px", top: "90px", width: "1000px", height: "600px" });
      const openAt = S(3, 0.55);
      E.show(wnd, openAt, S(4, 0.05), 0.25);
      E.anim([[0, 0.85], [openAt, 0.85], [openAt + 0.35, 1, 0.35]], v => { wnd.style.transform = `scale(${v})`; });
      const wnd2 = E.makeWindow(desk.layer, "Documents", { files: wnd.files ? [...wnd.files.children].map(x => x.textContent) : [] });
      Object.assign(wnd2.style, { left: "330px", top: "90px", width: "1000px", height: "600px" });
      E.show(wnd2, S(5, 0.0), swap, 0.3);
      E.anim([[0, 0], [S(5, 0.2), 0], [S(5, 0.95), -260, 2.4]], v => { wnd2.files.style.transform = `translateY(${v}px)`; });

      // menu contextuel (clic droit)
      const menu = E.makeMenu(desk.over, ["Affichage", "Trier par", "Actualiser", "-", "Coller", "Nouveau", "-", "Paramètres d’affichage", "Personnaliser"]);
      Object.assign(menu.style, { left: "1010px", top: "330px" });
      E.show(menu, S(4, 0.42), S(4, 1) + 0.2, 0.15);

      // curseur et clics
      const icoPos = i => [14 + Math.floor(i / 7) * 100 + 46, 14 + (i % 7) * 108 + 34];
      const [dx, dy] = icoPos(2), [px, py] = icoPos(0);
      E.move(cur, [
        [0, 820, 420], [S(1, 0.05), 820, 420],
        [S(1, 0.35), 1180, 320, 1.0], [S(1, 0.65), 560, 520, 1.0], [S(1, 0.95), 900, 380, 1.0],
        [S(2, 0.35), dx, dy, 1.0], [S(2, 1), dx, dy],
        [S(3, 0.3), px, py, 0.9], [S(3, 1), px, py],
        [S(4, 0.3), 1010, 330, 1.0], [S(4, 0.8), 1060, 372, 0.4], [S(4, 1), 1060, 372],
        [S(5, 0.2), 900, 450, 0.7]
      ]);
      E.cls(ico[2], "sel", S(2, 0.42), S(3, 0.35));
      E.cls(ico[0], "sel", S(3, 0.35), openAt);
      E.click(desk.over, S(2, 0.42), dx, dy);
      E.click(desk.over, S(3, 0.42), px, py, 2);
      E.click(desk.over, S(4, 0.38), 1010, 330);
      E.cls(menu.item(5), "hl", S(4, 0.8), 999);

      // souris posée sur la table : suit les mouvements du curseur, s'enfonce aux clics
      E.anim([[0, 0], [S(1, 0.05), 0], [S(1, 0.35), 40, 1.0], [S(1, 0.65), -60, 1.0], [S(1, 0.95), 0, 1.0]], v => { d.mouse.style.marginLeft = v + "px"; });

      // cartes : schéma de la souris
      SC.mouseCard(E, [
        [S(1, 0), S(1, 1) + 0.2, "move", "Déplacer"],
        [S(2, 0), S(2, 1) + 0.2, "left", "Clic gauche"],
        [S(3, 0), S(3, 1) + 0.2, "double", "Double-clic"],
        [S(4, 0), S(4, 1) + 0.2, "right", "Clic droit"],
        [S(5, 0), S(5, 1) + 0.2, "wheel", "Molette"]
      ]);

      // ================= partie 2 : ordinateur portable et pavé tactile
      const l = SC.laptopSet(E);
      E.show(l.set, swap, 999, 0.5);
      const lap = E.desktop(l.screen, 960, 540);
      const lcur = lap.cursor;
      const lwnd = E.makeWindow(lap.layer, "Documents");
      Object.assign(lwnd.style, { left: "330px", top: "90px", width: "1000px", height: "600px" });
      E.show(lwnd, S(10, 0.0), 999, 0.3);
      E.anim([[0, 0], [S(10, 0.2), 0], [S(10, 0.95), -260, 2.4]], v => { lwnd.files.style.transform = `translateY(${v}px)`; });
      const lmenu = E.makeMenu(lap.over, ["Affichage", "Trier par", "Actualiser", "-", "Coller", "Nouveau", "-", "Paramètres d’affichage", "Personnaliser"]);
      Object.assign(lmenu.style, { left: "1000px", top: "300px" });
      E.show(lmenu, S(9, 0.4), S(9, 1) + 0.2, 0.15);
      const [lx, ly] = icoPos(3);
      E.move(lcur, [[0, 800, 450], [S(7, 0.05), 800, 450], [S(7, 0.45), 1150, 300, 1.2], [S(7, 0.9), 600, 520, 1.2],
        [S(8, 0.35), lx, ly, 0.9], [S(8, 1), lx, ly], [S(9, 0.3), 1000, 300, 0.9], [S(9, 1), 1000, 300], [S(10, 0.15), 820, 450, 0.6]]);
      E.cls(lap.icons[3], "sel", S(8, 0.5), S(9, 0.3));
      E.click(lap.over, S(8, 0.5), lx, ly);
      E.click(lap.over, S(9, 0.38), 1000, 300);

      // doigts sur le pavé tactile (centre du pavé : 960, 905)
      const f1 = $("div", "finger", l.set, { width: "54px", height: "54px" });
      const f2 = $("div", "finger", l.set, { width: "54px", height: "54px" });
      E.show(f1, S(7, 0), 999, 0.3);
      E.show(f2, S(9, 0), 999, 0.3);
      E.move(f1, [[0, 933, 878], [S(7, 0.05), 933, 878], [S(7, 0.45), 1033, 850, 1.2], [S(7, 0.9), 853, 900, 1.2],
        [S(8, 0.35), 900, 870, 0.8], [S(8, 1), 900, 870], [S(9, 0.2), 893, 878, 0.6], [S(9, 1), 893, 878],
        [S(10, 0.1), 893, 840, 0.5], [S(10, 0.9), 893, 930, 2.6]]);
      E.move(f2, [[0, 973, 878], [S(9, 1), 973, 878], [S(10, 0.1), 973, 840, 0.5], [S(10, 0.9), 973, 930, 2.6]]);
      E.click(l.set, S(8, 0.5), 927, 897);
      E.click(l.set, S(9, 0.38), 920, 905); E.click(l.set, S(9, 0.38), 1000, 905);

      E.camera([[0, 960, 540, 1], [S(0, 1), 960, 540, 1], [S(1, 0.1), 1010, 470, 1.12, 0.9], [S(5, 1), 1010, 470, 1.12], [swap, 960, 540, 1, 0.01], [S(6, 0.6), 960, 640, 1.18, 1.2], [999, 960, 640, 1.18]]);

      SC.touchCard(E, S(7, 0), S(7, 1) + 0.2, "Glisser un doigt", "move");
      SC.touchCard(E, S(8, 0), S(8, 1) + 0.2, "Taper une fois", "tap");
      SC.touchCard(E, S(9, 0), S(9, 1) + 0.2, "Taper avec deux doigts", "two");
      SC.touchCard(E, S(10, 0), S(10, 1) + 0.2, "Glisser deux doigts", "scroll");
    }
  });
})();
