/* Leçon animée « Créer un tableau Excel » (code-barres 2014, vidéo tableauexcel). */
(function () {
  const T = window.T["2014"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Créer un tableau Excel"], [1, "Saisir les données"], [3, "Sélectionner"], [5, "Insertion › Tableau"], [10, "Le style"], [12, "Trier et filtrer"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const X = SC.excel(E, d.screen, 1076, 605, "Ventes");
      const cur = X.cursor;
      const HEAD = ["Produits", "Entrée de stock", "Sortie de stock", "Prix unitaire"];
      const ROWS = [["Riz", "120", "80", "450"], ["Sucre", "60", "45", "600"], ["Huile", "40", "25", "1200"], ["Lait", "90", "70", "350"], ["Savon", "150", "110", "250"]];
      const SORTED = ROWS.slice().sort((a, b) => a[0].localeCompare(b[0], "fr"));
      const C = "ABCD";
      // colonnes plus larges pour les en-têtes
      [...X.grid.querySelectorAll("th.c")].slice(0, 4).forEach(th => th.style.width = "170px");

      const tFill = [S(1, 0.2), S(2, 0.95)], tTable = S(9, 0.6), tStyle = S(11, 0.75), tSort = S(13, 0.75);
      const cells = [];
      for (let r = 0; r <= 5; r++) for (let c = 0; c < 4; c++) cells.push([r, c, X.cell(C[c] + (r + 1))]);
      E.track(t => {
        const n = Math.floor(Math.max(0, Math.min(1, (t - tFill[0]) / (tFill[1] - tFill[0]))) * cells.length);
        const sty = X.STY[t >= tStyle ? 1 : 0];
        cells.forEach(([r, c, td], i) => {
          const rows = t >= tSort ? SORTED : ROWS;
          const v = i < n ? (r === 0 ? HEAD[c] : rows[r - 1][c]) : "";
          if (td._v !== v) { td.textContent = v; td._v = v; }
          td.classList.toggle("num", r > 0 && c > 0);
          const tb = t >= tTable;
          td.style.background = tb ? (r === 0 ? sty[0] : r % 2 ? sty[1] : "#fff") : "";
          td.style.color = tb && r === 0 ? "#fff" : "";
          td.style.fontWeight = r === 0 ? "700" : "";
          if (r === 0 && tb && !td.querySelector(".flt")) { const f = $("span", "flt", td); f.textContent = "▼"; }
          if (r === 0 && !tb) { const f = td.querySelector(".flt"); if (f) f.remove(); }
        });
      });
      X.selectAt([[0, "A1"], [S(4, 0.2), "A1"], [S(13, 0.3), "A1"]]);
      X.range("A1", "D6", S(4, 0.2), tTable + 0.3, S(4, 0.85) - S(4, 0.2));

      // onglets et ruban
      X.tabAt([[0, "Accueil"], [S(5, 0.5), "Insertion"], [S(11, 0.3), "Création de tableau"]]);
      E.track(t => { X.tabs["Création de tableau"].style.display = t >= tTable ? "" : "none"; });
      E.cls(X.tabs.Insertion, "hl", S(5, 0.2), S(5, 0.55));
      E.cls(X.el.tbl, "hl", S(6, 0.3), S(7, 0.1));
      E.cls(X.el.styles[1], "hl", tStyle - 0.4, tStyle + 0.5);

      // boîte « Créer un tableau »
      const dlg = $("div", "dlg", X.layer);
      Object.assign(dlg.style, { left: "560px", top: "300px", width: "420px" });
      dlg.innerHTML = `<div class="dt">Créer un tableau</div><div class="dbd">Où se trouvent les données de votre tableau ?<div class="fld" style="display:block;margin:8px 0 12px">=$A$1:$D$6</div>
        <span class="chk hdr on"><i></i>Mon tableau comporte des en-têtes</span><div style="text-align:right;margin-top:16px"><span class="btn pri ok">OK</span><span class="btn">Annuler</span></div></div>`;
      E.show(dlg, S(7, 0.1), tTable, 0.2);
      E.cls(dlg.querySelector(".hdr"), "hl", S(8, 0.2), S(8, 1));
      E.cls(dlg.querySelector(".ok"), "hl", tTable - 0.4, tTable);

      // menu du filtre (Produits)
      const fm = $("div", "dd", X.layer);
      const [fx0, fy0] = SC.corner(X.cell("A1"), X.root);
      Object.assign(fm.style, { left: (fx0 + 20) + "px", top: (fy0 + 30) + "px", width: "290px" });
      fm.innerHTML = `<div class="az">&#8595; Trier de A à Z</div><div>&#8593; Trier de Z à A</div><div style="border-top:1px solid #ddd">Filtres textuels ›</div>
        ${["(Sélectionner tout)", ...SORTED.map(r => r[0])].map(n => `<div><span class="chk on" style="font-size:15px"><i></i>${n}</span></div>`).join("")}`;
      E.show(fm, S(13, 0.3), tSort + 0.1, 0.15);
      E.cls(fm.querySelector(".az"), "hl", tSort - 0.4, tSort + 0.1);

      // ---------------- curseur
      const at = X.at, P = X.pos;
      const flt = () => { const [x, y] = SC.corner(X.cell("A1"), X.root); return [x + 170 - 16, y + 14]; };
      SC.tour(E, X.root, X.over, cur, [[0, [1000, 600], 0], [S(4, 0.2), at("A1"), 1.0, 1], [S(4, 0.85), at("D6"), S(4, 0.85) - S(4, 0.2)],
        [S(5, 0.45), X.tabs.Insertion, 0.8, 1], [S(6, 0.55), X.el.tbl, 0.7, 1], [S(8, 0.4), P(dlg.querySelector(".hdr i")), 0.8], [tTable - 0.1, P(dlg.querySelector(".ok")), 0.6, 1],
        [S(11, 0.25), X.tabs["Création de tableau"], 0.9, 1], [tStyle - 0.1, X.el.styles[1], 0.6, 1], [S(13, 0.25), flt(), 0.9, 1], [tSort - 0.1, P(fm.querySelector(".az")), 0.5, 1]]);

      // ---------------- caméra
      SC.screenCam(E, [[0, "room"], [S(0, 0.6), "room"], [S(1, 0.1), 500, 350, 1.6, 1.0], [S(4, 1), 500, 350, 1.6], [S(5, 0.2), 430, 160, 1.9, 0.7], [S(6, 1), 430, 160, 1.9],
        [S(7, 0.2), 560, 400, 1.5, 0.6], [S(10, 1), 560, 400, 1.5], [S(11, 0.2), 900, 170, 1.4, 0.7], [S(11, 1), 900, 170, 1.4], [S(12, 0.3), 420, 350, 1.7, 0.7], [S(13, 1) + 1, 420, 350, 1.7], [S(13, 1) + 2.2, "room", 1.1]]);

      // ---------------- cartes
      E.card(`Insertion › Tableau<div style="display:flex;justify-content:center;margin-top:14px;font-size:70px;color:#107c41">&#9636;</div>`, S(5, 0), S(6, 1) + 0.2, { width: "380px", top: "600px" });
      E.card(`Les en-têtes<small>La première ligne (Produits, Entrée de stock…) sert de titres aux colonnes.</small>`, S(8, 0), S(9, 1), { width: "420px", left: "40px", right: "auto", top: "600px" });
      E.card(`Trier ou filtrer<small>La flèche ▼ de chaque en-tête : trier de A à Z, ou n’afficher que certaines lignes.</small>`, S(12, 0), 999, { width: "440px", top: "600px" });
    }
  });
})();
