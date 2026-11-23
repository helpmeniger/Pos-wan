/* Leçon animée « Saisir des données dans Excel » (code-barres 1013, vidéo saisirdonnee). */
(function () {
  const T = window.T["1013"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Saisir des données dans Excel"], [1, "Colonnes et lignes"], [2, "L’adresse des cellules"], [7, "Saisir dans une cellule"],
            [11, "Exemple pratique"], [16, "Les plages de cellules"]],
    build(E) {
      const S = E.S;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const X = SC.excel(E, d.screen, 1076, 605, "Classeur1");
      const cur = X.cursor;

      // colonnes puis lignes mises en évidence (balayage)
      const ths = [...X.grid.querySelectorAll("th.c")], trs = [...X.grid.querySelectorAll("th.r")].filter(t => t.dataset.r);
      E.track(t => {
        const a = S(1, 0.1), m = S(1, 0.55), b = S(1, 0.98);
        const kc = t >= a && t < m ? Math.floor((t - a) / (m - a) * ths.length) : -1;
        const kr = t >= m && t < b ? Math.floor((t - m) / (b - m) * trs.length) : -1;
        ths.forEach((th, i) => { th.style.background = i <= kc ? "#d3eadd" : ""; });
        trs.forEach((th, i) => { th.style.background = i <= kr ? "#d3eadd" : ""; });
      });

      // case active au fil de la leçon
      const tA1 = S(4, 0.2), tD5 = S(5, 0.15), tB3 = S(8, 0.3);
      const e1 = [S(12, 0.25), S(12, 0.7)], e2 = [S(13, 0.25), S(13, 0.7)], e3 = [S(14, 0.25), S(14, 0.7)], e4 = [S(15, 0.25), S(15, 0.6)];
      const tF = S(21, 0.2);
      X.selectAt([[0, "A1"], [tA1, "A1"], [tD5, "D5"], [S(7, 0), "B3"], [tB3, "B3"], [S(10, 0.5), "B4"], [S(12, 0.1), "A1"], [S(12, 0.85), "A2"], [S(13, 0.1), "B1"], [S(13, 0.85), "B2"],
        [S(14, 0.1), "A2"], [S(14, 0.85), "A3"], [S(15, 0.1), "B2"], [S(15, 0.85), "B3"], [S(16, 0.2), "A1"], [S(21, 0.05), "D2"]]);
      X.type("B3", "Bonjour", S(9, 0.2), S(9, 0.8));
      E.track(t => { X.cell("B3").style.visibility = t < S(11, 0.5) ? "visible" : "hidden"; });
      X.type("A1", "Nom", e1[0], e1[1], "hd"); X.type("B1", "Âge", e2[0], e2[1], "hd"); X.type("A2", "Ali", e3[0], e3[1]); X.type("B2", "25", e4[0], e4[1]);
      X.type("D2", "=SOMME(A1:A10)", tF, S(21, 0.75));
      E.cls(X.nbox, "hl", S(6, 0.2), S(6, 1));

      // plages : A1:D5 (exemple), puis cliquer-glisser A1 → B3
      X.range("A1", "D5", S(19, 0.1), S(19, 1) + 0.2);
      X.range("A1", "B3", S(20, 0.4), S(21, 0), S(20, 0.9) - S(20, 0.4));

      // ---------------- curseur
      const at = X.at;
      SC.tour(E, X.root, X.over, cur, [[0, [900, 600], 0], [tA1, at("A1"), 1.0, 1], [tD5, at("D5"), 0.8, 1], [S(6, 0.3), X.pos(X.nbox), 0.8],
        [tB3, at("B3"), 0.9, 1], [e1[0] - 0.1, at("A1"), 0.8, 1], [e2[0] - 0.1, at("B1"), 0.6, 1], [e3[0] - 0.1, at("A2"), 0.6, 1], [e4[0] - 0.1, at("B2"), 0.6, 1],
        [S(19, 0.1), at("D5"), 0.8], [S(20, 0.4), at("A1"), 0.8, 1], [S(20, 0.9), at("B3"), S(20, 0.9) - S(20, 0.4)], [tF - 0.1, at("D2"), 0.8, 1]]);

      // ---------------- caméra
      const [ax, ay] = at("B3");
      SC.screenCam(E, [[0, "room"], [S(0, 0.5), "room"], [S(1, 0.0), 800, 450, 1, 1.0], [S(3, 0.3), 800, 450, 1], [S(4, 0.1), 420, 420, 1.7, 0.8], [S(5, 1), 420, 420, 1.7],
        [S(6, 0.1), 240, 260, 2.3, 0.6], [S(6, 1), 240, 260, 2.3], [S(7, 0.2), ax, ay + 20, 1.8, 0.7], [S(10, 1), ax, ay + 20, 1.8],
        [S(11, 0.3), 360, 330, 2.0, 0.7], [S(15, 1), 360, 330, 2.0], [S(16, 0.3), 480, 380, 1.6, 0.7], [S(21, 1), 480, 380, 1.6], [S(21, 1) + 1.4, "room", 1.2]]);

      // ---------------- cartes
      E.card(`Colonnes et lignes<div style="display:flex;gap:26px;justify-content:center;margin-top:14px;font-size:34px"><span style="color:#107c41">A, B, C…</span><span style="color:#326fbf">1, 2, 3…</span></div><small>Lettres en haut, numéros à gauche.</small>`,
        S(1, 0), S(1, 1) + 0.2, { width: "440px" });
      E.card(`L’adresse d’une cellule<div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-top:16px;font-size:40px"><span style="color:#107c41">D</span>+<span style="color:#326fbf">5</span>=<b style="border:4px solid #107c41;padding:0 10px">D5</b></div><small>Lettre de la colonne + numéro de la ligne.</small>`,
        S(2, 0), S(5, 1) + 0.2, { width: "440px" });
      E.card(`La zone Nom<small>En haut à gauche, à côté de la barre de formule : elle affiche l’adresse de la cellule sélectionnée.</small>`, S(6, 0), S(6, 1) + 0.3, { width: "460px", top: "560px" });
      SC.keysCard(E, "Valider", ["Entrée"], S(10, 0), S(10, 1) + 0.3, { width: "380px", top: "600px" });
      E.card(`Une plage<div style="margin-top:14px;font-size:44px;text-align:center;color:#107c41">A1:D5</div><small>Première cellule : dernière cellule.</small>`, S(17, 0), S(19, 1) + 0.2, { width: "400px", top: "600px" });
      E.card(`Dans une formule<div style="margin-top:14px;font-size:34px;text-align:center;font-family:Consolas,monospace">=SOMME(A1:A10)</div>`, S(21, 0), 999, { width: "460px", top: "600px" });
    }
  });
})();
