/* Leçon animée « Rechercher, remplacer et enregistrer dans Word » (code-barres 1006, vidéo ramplacer). */
(function () {
  const T = window.T["1006"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Rechercher et remplacer"], [2, "Ouvrir Remplacer"], [5, "Rechercher / Remplacer par"], [7, "Remplacer tout"], [9, "Enregistrer sous"], [16, "Enregistrer (Ctrl + S)"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const W = SC.word(E, d.screen, 1076, 605, "Document1");
      const cur = W.cursor, P = W.page, el = W.el;

      // ---------------- document avec trois « 2025 »
      const h = $("p", "h1", P); h.innerHTML = `Réunion annuelle <span class="o">2025</span>`;
      const p1 = $("p", "", P); p1.innerHTML = `Le bilan <span class="o">2025</span> de l’association sera présenté à tous les membres.`;
      const p2 = $("p", "", P); p2.innerHTML = `Merci de confirmer votre présence. Programme <span class="o">2025</span> : formation, ateliers et visites.`;
      const occ = [...P.querySelectorAll(".o")];
      const tOne = S(7, 0.3), tAll = S(7, 0.75);
      E.track(t => occ.forEach((o, i) => {
        const done = i === 0 ? t >= tOne : t >= tAll;
        const v = done ? "2026" : "2025"; if (o.textContent !== v) o.textContent = v;
        o.style.background = !done && t >= S(5, 0.8) && t < tAll && i === (t >= tOne ? 1 : 0) ? "#b4cdf0" : done && t < tAll + 1.5 ? "#fff2a8" : "";
      }));

      // ---------------- boîte « Rechercher et remplacer »
      const dlg = $("div", "dlg", W.layer);
      Object.assign(dlg.style, { left: "520px", top: "250px", width: "560px" });
      dlg.innerHTML = `<div class="dt">Rechercher et remplacer</div><div class="dbd"><div style="display:flex;gap:20px;border-bottom:1px solid #ddd;margin-bottom:12px"><span>Rechercher</span><span style="color:#185abd;border-bottom:2px solid #185abd">Remplacer</span><span>Atteindre</span></div>
        Rechercher :<div class="fld f1" style="display:block;margin:4px 0 10px"></div>Remplacer par :<div class="fld f2" style="display:block;margin:4px 0 14px"></div>
        <div style="text-align:right"><span class="btn b1">Remplacer</span><span class="btn b2">Remplacer tout</span><span class="btn">Suivant</span><span class="btn b3">Fermer</span></div></div>`;
      const f1 = dlg.querySelector(".f1"), f2 = dlg.querySelector(".f2"), b1 = dlg.querySelector(".b1"), b2 = dlg.querySelector(".b2"), b3 = dlg.querySelector(".b3");
      const tDlg = S(4, 0.55);
      E.show(dlg, tDlg, S(8, 0.6), 0.2);
      SC.typeText(E, f1, "2025", S(5, 0.4), S(5, 0.8)); SC.typeText(E, f2, "2026", S(6, 0.45), S(6, 0.8));
      E.cls(b1, "hl", tOne - 0.3, tOne + 0.2); E.cls(b2, "hl", tAll - 0.3, tAll + 0.2); E.cls(b3, "hl", S(8, 0.3), S(8, 0.6));
      const msg = $("div", "dlg", W.layer, { left: "640px", top: "380px", width: "360px", zIndex: 30 });
      msg.innerHTML = `<div class="dbd" style="padding:20px">Word a effectué 2 remplacements.<div style="text-align:right;margin-top:12px"><span class="btn pri">OK</span></div></div>`;
      E.show(msg, tAll + 0.15, S(8, 0.15), 0.15);
      E.cls(el.repl, "hl", S(4, 0.25), tDlg);

      // ---------------- Fichier › Enregistrer sous
      const tFile = S(10, 0.75), tSaveAs = S(11, 0.5), tSave = S(15, 0.5);
      const B = SC.backstage(E, W, tFile, tSave + 0.3);
      B.main.innerHTML = `<h3>Enregistrer sous</h3><div class="tile t0"><i>&#128187;</i><div>Ce PC<br><small style="color:#666">Bureau, Documents…</small></div></div><div class="tile"><i>&#128193;</i>Parcourir</div>`;
      E.cls(B.item("Enregistrer sous"), "hl", tSaveAs - 0.2, tSave + 0.3);
      E.cls(B.main.querySelector(".t0"), "hl", S(12, 0.4), tSave + 0.3);
      E.only(B.main, tSaveAs, 1e9);
      const sv = $("div", "dlg", W.layer, { left: "430px", top: "220px", width: "720px", zIndex: 20 });
      sv.innerHTML = `<div class="dt">Enregistrer sous</div><div class="dbd" style="display:flex;gap:16px"><div style="width:170px;line-height:2;border-right:1px solid #ddd">
        <div class="lk">&#128187; Ce PC</div><div class="lk bureau">&#128421; Bureau</div><div class="lk">&#128196; Documents</div><div class="lk">&#11015; Téléchargements</div></div>
        <div style="flex:1">Nom du fichier :<div class="fld nm" style="display:block;margin:4px 0 10px"></div>Type :<div class="fld ty" style="display:block;margin:4px 0 6px">Document Word (*.docx)</div>
        <div class="tys" style="border:1px solid #bbb;background:#fff;font-size:14px"><div style="padding:3px 8px">Document Word (*.docx)</div><div class="pdf" style="padding:3px 8px">PDF (*.pdf)</div><div style="padding:3px 8px">Modèle Word (*.dotx)</div></div>
        <div style="text-align:right;margin-top:14px"><span class="btn pri go">Enregistrer</span><span class="btn">Annuler</span></div></div></div>`;
      E.show(sv, S(13, 0.2), tSave + 0.1, 0.2);
      E.track(t => { sv.querySelector(".bureau").style.background = t >= S(13, 0.5) ? "#dbe6f6" : ""; });
      SC.typeText(E, sv.querySelector(".nm"), "Réunion annuelle", S(14, 0.05), S(14, 0.35));
      E.only(sv.querySelector(".tys"), S(14, 0.45), S(14, 0.9));
      E.cls(sv.querySelector(".go"), "hl", tSave - 0.3, tSave + 0.1);
      E.track(t => { const v = t >= tSave ? "Réunion annuelle - Word" : "Document1 - Word"; if (el.title.textContent !== v) el.title.textContent = v; });
      W.tabAt([[0, "Accueil"]]);
      E.cls(W.tabs.Fichier, "hl", tFile - 0.4, tFile);
      E.cls(el.save, "hl", S(16, 0.2), S(16, 0.6));

      // ---------------- curseur
      const pos = x => W.pos(x);
      SC.tour(E, W.root, W.over, cur, [[0, [1150, 650], 0], [S(3, 0.5), W.tabs.Accueil, 1.0, 1], [S(4, 0.25), el.repl, 0.8, 1], [S(5, 0.3), f1, 0.8, 1], [S(6, 0.35), f2, 0.6, 1],
        [tOne - 0.05, b1, 0.6, 1], [tAll - 0.05, b2, 0.5, 1], [tAll + 0.9, pos(msg.querySelector(".btn")), 0.5, 1], [S(8, 0.45), b3, 0.6, 1],
        [tFile - 0.05, W.tabs.Fichier, 1.0, 1], [tSaveAs - 0.05, B.item("Enregistrer sous"), 0.6, 1], [S(12, 0.4), B.main.querySelector(".t0"), 0.7, 1],
        [S(13, 0.5), sv.querySelector(".bureau"), 0.7, 1], [S(14, 0.0), sv.querySelector(".nm"), 0.5, 1], [S(14, 0.5), sv.querySelector(".ty"), 0.5, 1], [S(14, 0.85), sv.querySelector(".pdf"), 0.3],
        [tSave - 0.05, sv.querySelector(".go"), 0.6, 1], [S(16, 0.35), el.save, 1.0, 1]]);

      // ---------------- caméra
      SC.screenCam(E, [[0, "room"], [S(1, 0.5), "room"], [S(2, 0.3), 800, 420, 1.3, 1.0], [S(3, 0.2), 1300, 160, 1.9, 0.8], [S(4, 0.5), 1300, 160, 1.9],
        [S(4, 0.8), 820, 380, 1.5, 0.6], [S(8, 1), 820, 380, 1.5], [S(9, 0.2), 800, 420, 1.2, 0.6], [S(10, 0.3), 380, 300, 1.6, 0.8], [S(11, 1), 380, 300, 1.6],
        [S(12, 0.2), 780, 420, 1.3, 0.6], [S(15, 1), 780, 420, 1.3], [S(16, 0.1), 400, 230, 2.0, 0.7], [S(16, 1), 400, 230, 2.0], [S(16, 1) + 1.2, "room", 1.0]]);

      // ---------------- cartes
      SC.keysCard(E, "Raccourci", ["Ctrl", "H"], S(4, 0.4), S(4, 1) + 0.2, { top: "640px", left: "40px", right: "auto", width: "380px" });
      E.card(`Remplacer / Remplacer tout<small>« Remplacer » : une occurrence à la fois. « Remplacer tout » : toutes d’un coup.</small>`, S(7, 0), S(8, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "460px" });
      E.card(`Le format<div style="display:flex;gap:16px;justify-content:center;margin-top:14px;font-size:24px"><span style="background:#185abd;color:#fff;padding:6px 14px;border-radius:8px">.docx</span><span style="background:#c42b1c;color:#fff;padding:6px 14px;border-radius:8px">.pdf</span></div>`,
        S(14, 0), S(14, 1) + 0.3, { left: "40px", right: "auto", top: "640px", width: "380px" });
      SC.keysCard(E, "Enregistrer", ["Ctrl", "S"], S(16, 0), 999, { top: "640px", left: "40px", right: "auto", width: "380px" }, "Ou l’icône de disquette en haut à gauche.");
    }
  });
})();
