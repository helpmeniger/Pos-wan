/* Leçon animée « Créer et mettre à jour une table des matières » (code-barres 1011, vidéo tablematiere). */
(function () {
  const T = window.T["1011"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "La table des matières"], [1, "Préparer le document"], [5, "Insérer la table"], [10, "Mettre à jour la table"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const W = SC.word(E, d.screen, 1076, 605, "Rapport");
      const cur = W.cursor, P = W.page;

      // ---------------- document : titres d'abord en texte normal
      const toc = $("div", "toc", P);
      toc.innerHTML = `<div class="bar">&#128462; &#9662; &nbsp; &#10227; Mettre à jour la table...</div><div class="tt">Table des matières</div>
        <div class="ln"><span>Chapitre 1 : Introduction</span><span></span><span>1</span></div>
        <div class="ln l2"><span>Les objectifs</span><span></span><span>1</span></div>
        <div class="ln"><span>Chapitre 2 : La méthode</span><span></span><span>2</span></div>
        <div class="ln new"><span>Chapitre 3 : Les résultats</span><span></span><span>3</span></div>`;
      const tocBar = toc.querySelector(".bar"), tocNew = toc.querySelector(".new");
      const blank = $("p", "", P);
      const h1 = $("p", "", P); h1.textContent = "Chapitre 1 : Introduction";
      $("p", "lorem", P).textContent = "Ce rapport présente le projet et ses étapes principales.";
      const h2 = $("p", "", P); h2.textContent = "Les objectifs";
      $("p", "lorem", P).textContent = "Former les équipes à l’outil informatique.";
      const h3 = $("p", "", P); h3.textContent = "Chapitre 2 : La méthode";
      $("p", "lorem", P).textContent = "Des séances pratiques chaque semaine.";
      const h4 = $("p", "", P);

      const tH1 = S(3, 0.3), tH2 = S(3, 0.55), tH3 = S(3, 0.8);
      E.cls(h1, "h1", tH1, 999); E.cls(h2, "h2", tH2, 999); E.cls(h3, "h1", tH3, 999); E.cls(h4, "h1", S(11, 0.75), 999);
      E.cls(h1, "sel", tH1 - 0.6, tH1 + 0.3); E.cls(h2, "sel", tH2 - 0.5, tH2 + 0.3); E.cls(h3, "sel", tH3 - 0.5, tH3 + 0.3);
      SC.typeText(E, h4, "Chapitre 3 : Les résultats", S(11, 0.2), S(11, 0.7));
      E.cls(W.el.t1, "hl", tH1 - 0.3, tH1 + 0.3); E.cls(W.el.t2, "hl", tH2 - 0.3, tH2 + 0.3); E.cls(W.el.t1, "hl", tH3 - 0.3, tH3 + 0.3);

      // ---------------- insertion de la table
      const tIns = S(9, 0.6);
      E.show(toc, tIns, 999, 0.3);
      E.anim([[0, 0], [tIns, 0], [tIns + 0.4, 1, 0.4]], v => { toc.style.maxHeight = (v * 260) + "px"; toc.style.overflow = "hidden"; });
      E.show(tocNew, S(16, 0.55), 999, 0.3);
      E.cls(toc, "tsel", S(12, 0.3), S(16, 0.9));
      E.cls(tocBar, "on", S(12, 0.3), S(16, 0.9));
      E.cls(tocBar, "hl", S(13, 0.2), S(13, 0.6));
      SC.typeText(E, blank, "", S(6, 0.5), S(6, 0.5));
      E.show(blank, -1, tIns, 0);

      W.tabAt([[0, "Accueil"], [S(7, 0.45), "Références"]]);
      E.cls(W.tabs["Références"], "hl", S(7, 0.2), S(7, 0.5));
      E.cls(W.el.toc, "hl", S(8, 0.2), S(9, 0.6));
      E.cls(W.el.tocUpd, "hl", S(14, 0.4), S(14, 0.9));

      // liste des modèles de table
      const dd = $("div", "dd", W.layer);
      const [tx, ty] = SC.corner(W.el.toc, W.root);
      Object.assign(dd.style, { left: tx + "px", top: (ty + 80) + "px", width: "360px" });
      dd.innerHTML = `<div style="font-weight:600;color:#555;font-size:14px">Prédéfini</div><div class="a1">Table automatique 1<br><small style="color:#777">Chapitre ........ 1<br>&nbsp;&nbsp;Titre ........ 1</small></div>
        <div>Table automatique 2</div><div>Table manuelle</div><div style="border-top:1px solid #ddd">Table des matières personnalisée...</div>`;
      E.show(dd, S(8, 0.55), tIns + 0.1, 0.15);
      E.cls(dd.querySelector(".a1"), "hl", S(9, 0.2), tIns);

      // boîte « Mettre à jour la table des matières »
      const dlg = $("div", "dlg", W.layer);
      Object.assign(dlg.style, { left: "640px", top: "330px", width: "430px" });
      dlg.innerHTML = `<div class="dt">Mettre à jour la table des matières</div><div class="dbd">Word met à jour la table. Choisissez :
        <div class="rad r1 on"><i></i>Mettre à jour les numéros de page uniquement</div><div class="rad r2"><i></i>Mettre à jour toute la table</div>
        <div style="text-align:right;margin-top:12px"><span class="btn pri ok">OK</span><span class="btn">Annuler</span></div></div>`;
      const r1 = dlg.querySelector(".r1"), r2 = dlg.querySelector(".r2"), ok = dlg.querySelector(".ok");
      E.show(dlg, S(13, 0.7), S(16, 0.5), 0.2);
      E.cls(r1, "on", 0, S(16, 0.15)); E.cls(r2, "on", S(16, 0.15), 999);
      E.cls(ok, "hl", S(16, 0.35), S(16, 0.5));

      // ---------------- curseur
      const p = el => W.pos(el);
      const [t1x, t1y] = p(W.el.t1), [t2x, t2y] = p(W.el.t2);
      const [c1x, c1y] = W.pos(h1, -170, 0), [c2x, c2y] = W.pos(h2, -170, 0), [c3x, c3y] = W.pos(h3, -170, 0);
      const [bx, by] = W.pos(blank, -250, 0);
      const [rx, ry] = p(W.tabs["Références"]), [ox, oy] = p(W.el.toc);
      const [ax, ay] = p(dd.querySelector(".a1")), [hx, hy] = W.pos(h4, -150, 0);
      const [tbx, tby] = [SC.corner(toc, W.root)[0] + 120, SC.corner(toc, W.root)[1] + 60];
      const [ux, uy] = [SC.corner(toc, W.root)[0] + 150, SC.corner(toc, W.root)[1] - 18];
      const [q1x, q1y] = W.pos(r1, -150, 0), [q2x, q2y] = W.pos(r2, -150, 0), [kx, ky] = p(ok);
      E.move(cur, [[0, 900, 500], [S(2, 0.2), 900, 500],
        [tH1 - 0.9, c1x, c1y, 0.6], [tH1 - 0.6, c1x, c1y], [tH1 - 0.1, t1x, t1y, 0.45], [tH2 - 0.9, c2x, c2y, 0.5], [tH2 - 0.55, c2x, c2y], [tH2 - 0.1, t2x, t2y, 0.4],
        [tH3 - 0.9, c3x, c3y, 0.5], [tH3 - 0.55, c3x, c3y], [tH3 - 0.1, t1x, t1y, 0.4],
        [S(6, 0.3), bx, by, 0.9], [S(7, 0.1), bx, by], [S(7, 0.4), rx, ry, 0.8], [S(8, 0.1), rx, ry], [S(8, 0.4), ox, oy, 0.6],
        [S(9, 0.1), ox, oy], [S(9, 0.4), ax, ay, 0.5], [S(10, 0.3), ax, ay], [S(11, 0.15), hx, hy, 0.8], [S(12, 0.1), hx, hy],
        [S(12, 0.3), tbx, tby, 0.7], [S(13, 0.1), tbx, tby], [S(13, 0.2), ux, uy, 0.4], [S(13, 0.7), ux, uy],
        [S(15, 0.3), q1x, q1y, 0.6], [S(16, 0.1), q1x, q1y], [S(16, 0.15), q2x, q2y, 0.2], [S(16, 0.35), kx, ky, 0.25], [999, kx, ky]]);
      SC.cursorModes(E, cur, [[0, "arrow"], [tH1 - 0.9, "ibeam"], [tH1 - 0.35, "arrow"], [tH2 - 0.9, "ibeam"], [tH2 - 0.35, "arrow"], [tH3 - 0.9, "ibeam"], [tH3 - 0.35, "arrow"],
        [S(6, 0.2), "ibeam"], [S(7, 0.2), "arrow"], [S(11, 0.0), "ibeam"], [S(12, 0.2), "arrow"]]);
      const cl = (t, a) => E.click(W.over, t, a[0], a[1]);
      cl(tH1, [t1x, t1y]); cl(tH2, [t2x, t2y]); cl(tH3, [t1x, t1y]); cl(S(6, 0.5), [bx, by]); cl(S(7, 0.45), [rx, ry]); cl(S(8, 0.5), [ox, oy]);
      cl(tIns - 0.1, [ax, ay]); cl(S(11, 0.15), [hx, hy]); cl(S(12, 0.3), [tbx, tby]); cl(S(13, 0.6), [ux, uy]); cl(S(16, 0.15), [q2x, q2y]); cl(S(16, 0.4), [kx, ky]);

      // ---------------- caméra (coordonnées de l'écran du moniteur)
      const [, h4y] = W.pos(h4);
      SC.screenCam(E, [[0, "room"], [S(0, 0.5), "room"], [S(1, 0.3), 800, 450, 1, 1.0],
        [S(2, 0.3), 950, 290, 1.6, 0.8], [S(4, 0.2), 950, 290, 1.6], [S(4, 0.8), 800, 420, 1.25, 0.8],
        [S(7, 0.1), 800, 420, 1.25], [S(7, 0.4), 480, 280, 1.7, 0.7], [S(9, 0.4), 480, 280, 1.7], [S(10, 0.1), 800, 420, 1.3, 0.7], [S(10, 0.9), 800, 420, 1.3], [S(11, 0.2), 800, Math.min(h4y - 60, 560), 1.3, 0.7], [S(11, 1), 800, Math.min(h4y - 60, 560), 1.3], [S(12, 0.3), 800, 420, 1.3, 0.6],
        [S(13, 0.5), 800, 420, 1.3], [S(14, 0.1), 850, 400, 1.6, 0.6], [S(16, 0.9), 850, 400, 1.6], [S(16, 0.95) + 1.2, "room", 1.2]]);

      // ---------------- cartes
      E.card(`Les styles de titre<div style="margin-top:16px;line-height:1.5"><div style="color:#2f5496;font-size:36px;font-weight:400">Titre 1</div>
        <div style="color:#2f5496;font-size:28px;font-weight:400;padding-left:24px">Titre 2</div></div><small>Accueil › Styles</small>`, S(2, 0), S(4, 1) + 0.2, { top: "640px", width: "380px" });
      E.card(`Références<div style="margin-top:14px;font-size:26px;font-weight:600">› Table des matières</div><small>Placez d’abord le curseur au début du document.</small>`, S(5, 0), S(9, 0.4), { width: "420px" });
      E.card(`Mettre à jour<div style="margin-top:14px;font-size:24px;font-weight:600;line-height:1.5">◉ Numéros de page uniquement<br>◉ Toute la table</div>
        <small>La table ne se met pas à jour toute seule.</small>`, S(10, 0), S(16, 1) + 0.3, { left: "40px", right: "auto", width: "460px" });
    }
  });
})();
