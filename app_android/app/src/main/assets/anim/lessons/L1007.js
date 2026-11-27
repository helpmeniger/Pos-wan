/* Leçon animée « Page de garde et tableaux » (code-barres 1007, vidéo pagegarde). */
(function () {
  const T = window.T["1007"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Page de garde et tableau"], [1, "La page de garde"], [7, "Insérer un tableau"], [14, "Ajouter lignes et colonnes"], [23, "Supprimer"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {}); d.led.classList.add("on");
      const W = SC.word(E, d.screen, 1076, 605, "Rapport de stage");
      const P = W.page, el = W.el;
      // page de garde (ajoutée au début), puis le document
      const cover = $("div", "", P, { height: "0px", overflow: "hidden", margin: "-70px -90px 0", background: "linear-gradient(160deg,#1f3864,#2f5496)", color: "#fff", padding: "0 90px" });
      cover.innerHTML = `<div style="padding-top:90px;font-size:44px;font-weight:300">Rapport de stage</div><div style="font-size:22px;margin-top:12px;opacity:.85">Awa Moussa · Formation HELP-ME · 2026</div>`;
      const tCover = S(5, 0.7);
      E.anim([[0, 0], [tCover, 0], [tCover + 0.6, 330, 0.6]], v => { cover.style.height = v + "px"; cover.style.marginBottom = v ? "30px" : "0"; });
      $("p", "h1", P).textContent = "Introduction";
      const p1 = $("p", "", P); p1.textContent = "Ce rapport présente les tâches réalisées pendant le stage.";
      const anchor = $("p", "", P); anchor.innerHTML = "&nbsp;";
      // tableau : apparaît, puis lignes et colonnes ajoutées / supprimées
      const tbl = $("table", "", P, { borderCollapse: "collapse", width: "100%", fontSize: "17px" });
      const data = [["Semaine", "Tâche", "Résultat"], ["1", "Accueil des usagers", "Fait"], ["2", "Saisie Excel", "Fait"], ["3", "Rapport Word", "En cours"]];
      const tTable = S(13, 0.2), tRowAdd = S(18, 0.6), tColAdd = S(22, 0.6), tRowDel = S(25, 0.7), tColDel = S(28, 0.7);
      E.track(t => {
        let rows = t >= tTable ? data.map(r => r.slice()) : [];
        if (t >= tRowAdd) rows.push(["4", "Classement", ""]);
        if (t >= tColAdd) rows = rows.map((r, i) => [...r, i ? "" : "Remarque"]);
        if (t >= tRowDel) rows.splice(2, 1);
        if (t >= tColDel) rows = rows.map(r => r.slice(0, 3));
        const key = JSON.stringify(rows);
        if (tbl._k === key) return; tbl._k = key;
        tbl.innerHTML = rows.map((r, i) => `<tr>${r.map(c => `<td style="border:1px solid #8a98ad;padding:6px 10px;height:36px;${i ? "" : "background:#2f5496;color:#fff;font-weight:700"}">${c}</td>`).join("")}</tr>`).join("");
      });
      E.anim([[0, 0], [tTable, 0], [tTable + 0.4, 1, 0.4]], v => { tbl.style.opacity = v; });
      const cell = (r, c) => () => { const tr = tbl.rows[Math.min(r, tbl.rows.length - 1)]; return tr ? tr.cells[Math.min(c, tr.cells.length - 1)] : tbl; };
      // pour viser les cellules au moment voulu, on force l'état du tableau pendant la construction
      const grid = `<div style="display:grid;grid-template-columns:repeat(8,24px);gap:3px;padding:6px">${Array.from({ length: 48 }, (_, i) => `<i style="display:block;width:24px;height:24px;border:1px solid #bbb;background:${i % 8 < 3 && i < 32 ? "#f6c89f" : "#fff"}"></i>`).join("")}</div><div style="padding:0 6px">Tableau 3 × 4</div>`;
      const sub = (a, b) => [`Insérer ›`, "-", a, b];
      // positions fixes des cellules (le tableau a 4 lignes de 36 px + en-tête)
      // défilement de la page vers le bas une fois la page de garde ajoutée
      const tScroll = S(7, 0.3);
      E.anim([[0, 30], [tScroll, 30], [tScroll + 0.8, -330, 0.8]], v => { P.style.top = v + "px"; });
      cover.style.height = "330px"; cover.style.marginBottom = "30px"; P.style.top = "-330px";
      tbl.innerHTML = [...data, ["4", "Classement", ""]].map((r, i) => `<tr>${[...r, i ? "" : "Remarque"].map(c => `<td style="border:1px solid #8a98ad;padding:6px 10px;height:36px;${i ? "" : "font-weight:700"}">${c}</td>`).join("")}</tr>`).join("");
      const [tx, ty] = SC.corner(tbl, W.root);
      const CP = [...tbl.rows].map(tr => [...tr.cells].map(td => SC.pos(td, W.root)));
      const cellAt = (r, c) => CP[r][c];
      SC.beats(E, W, [
        { s: 1, f: 0.4, go: () => P.querySelector(".h1"), cam: [760, 380, 1.4] },
        { s: 2, f: 0.5, tab: "Insertion", go: "Insertion", cam: [500, 200, 1.8] },
        { s: 3, f: 0.6, go: "Page de garde", menu: { items: [`<div style="display:grid;grid-template-columns:repeat(3,96px);gap:10px;padding:6px">${["#2f5496", "#c43e1c", "#1e7a3c", "#7a5cd6", "#d9822b", "#5a6b80"].map(c => `<div style="height:120px;border:1px solid #ccc;background:linear-gradient(180deg,${c} 45%,#fff 45%)"></div>`).join("")}</div>`, "-", "Supprimer la page de garde active"], w: 340 }, until: 5, uf: 0.65 },
        { s: 5, f: 0.4, go: () => document.querySelector(".dd div div div"), card: { html: `Insertion › Page de garde<small>Choisissez un modèle : il se place au début du document.</small>`, until: 6 } },
        { s: 6, f: 0.3, go: [1250, 700], clicks: 0, cam: [760, 420, 1.15] },
        { s: 8, f: 0.4, go: () => anchor, cam: [760, 400, 1.4] },
        { s: 10, f: 0.4, tab: "Insertion", go: "Tableau", cam: [560, 230, 1.7], menu: { items: [grid, "-", "Insérer un tableau…", "Dessiner un tableau"], hl: 2, hlAt: 12, hlF: 0.2, pick: true, w: 260 }, until: 12, uf: 0.4 },
        { s: 12, f: 0.5, dialog: { title: "Insérer un tableau", w: 440, x: 600, y: 300, html: `Nombre de colonnes : <span class="fld" style="min-width:60px">3</span><br><br>Nombre de lignes : <span class="fld" style="min-width:60px">4</span>`, okAt: 12, okF: 0.95 }, until: 12, uf: 0.97, cam: [800, 420, 1.5] },
        { s: 13, f: 0.4, go: [1250, 720], clicks: 0, cam: [780, 520, 1.3] },
        { s: 16, f: 0.4, go: cellAt(3, 1), card: { html: `Clic droit › Insérer<small>Une ligne au-dessus ou en dessous, une colonne à gauche ou à droite.</small>`, until: 22 } },
        { s: 17, f: 0.3, go: cellAt(3, 1), clicks: 0, menu: { items: ["Couper", "Copier", "Coller", "-", "Insérer ›", "Supprimer ›"], hl: 4, w: 260 }, until: 18, uf: 0.3 },
        { s: 18, f: 0.3, menu: { x: tx + 400, y: ty + 230, items: ["Insérer une colonne à gauche", "Insérer une colonne à droite", "Insérer une ligne au-dessus", "Insérer une ligne en dessous"], hl: 3, w: 320 }, until: 18, uf: 0.65 },
        { s: 20, f: 0.4, go: cellAt(1, 2), clicks: 1 },
        { s: 21, f: 0.3, go: cellAt(1, 2), clicks: 0, menu: { items: ["Couper", "Copier", "Coller", "-", "Insérer ›", "Supprimer ›"], hl: 4, w: 260 }, until: 22, uf: 0.3 },
        { s: 22, f: 0.3, menu: { x: tx + 580, y: ty + 140, items: ["Insérer une colonne à gauche", "Insérer une colonne à droite", "Insérer une ligne au-dessus", "Insérer une ligne en dessous"], hl: 1, w: 320 }, until: 22, uf: 0.65 },
        { s: 24, f: 0.4, go: cellAt(2, 1), card: { html: `Clic droit › Supprimer<small>« Supprimer la ligne » ou « Supprimer la colonne ».</small>`, until: 28 } },
        { s: 25, f: 0.3, go: cellAt(2, 1), clicks: 0, menu: { items: ["Insérer ›", "Supprimer ›", "-", "Supprimer la ligne", "Supprimer la colonne", "Supprimer les cellules…"], hl: 3, w: 280 }, until: 25, uf: 0.7 },
        { s: 27, f: 0.4, go: cellAt(1, 3) },
        { s: 28, f: 0.3, go: cellAt(1, 3), clicks: 0, menu: { items: ["Insérer ›", "Supprimer ›", "-", "Supprimer la ligne", "Supprimer la colonne", "Supprimer les cellules…"], hl: 4, w: 280 }, until: 28, uf: 0.7 },
      ], { last: 28 });
      SC.cursorModes(E, W.cursor, [[0, "arrow"], [S(8, 0.1), "ibeam"], [S(9, 0.4), "arrow"]]);
    }
  });
})();
