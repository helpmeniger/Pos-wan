/* Leçon animée « Créer et mettre à jour une table des matières » (code-barres 1011, vidéo tablematiere). */
(function () {
  const T = window.T["1011"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "La table des matières"], [1, "Préparer le document"], [5, "Insérer la table"], [10, "Mettre à jour la table"],
            [17, "Protéger par mot de passe"], [24, "Restreindre les modifications"]],
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
        [S(13, 0.5), 800, 420, 1.3], [S(14, 0.1), 850, 400, 1.6, 0.6], [S(16, 0.9), 850, 400, 1.6], [S(17, 0.3), "room", 1.0], [S(18, 0.3), 800, 450, 1, 0.9],
        [S(19, 0.4), 560, 380, 1.4, 0.8], [S(20, 1), 560, 380, 1.4], [S(21, 0.1), 800, 420, 1.5, 0.6], [S(23, 0.5), 800, 420, 1.5], [S(23, 0.9), 400, 230, 2.0, 0.6], [S(24, 0.4), 400, 230, 2.0],
        [S(25, 0.2), 1250, 200, 1.7, 0.8], [S(25, 1), 1250, 200, 1.7], [S(26, 0.2), 1250, 480, 1.5, 0.7], [S(27, 1), 1250, 480, 1.5], [S(28, 0.2), 800, 420, 1.5, 0.6], [S(28, 1), 800, 420, 1.5],
        [S(29, 0.3), 800, 450, 1, 0.7], [S(29, 1) + 0.6, 800, 450, 1], [S(29, 1) + 1.8, "room", 1.2]]);

      // ---------------- cartes
      E.card(`Les styles de titre<div style="margin-top:16px;line-height:1.5"><div style="color:#2f5496;font-size:36px;font-weight:400">Titre 1</div>
        <div style="color:#2f5496;font-size:28px;font-weight:400;padding-left:24px">Titre 2</div></div><small>Accueil › Styles</small>`, S(2, 0), S(4, 1) + 0.2, { top: "640px", width: "380px" });
      E.card(`Références<div style="margin-top:14px;font-size:26px;font-weight:600">› Table des matières</div><small>Placez d’abord le curseur au début du document.</small>`, S(5, 0), S(9, 0.4), { width: "420px" });
      E.card(`Mettre à jour<div style="margin-top:14px;font-size:24px;font-weight:600;line-height:1.5">◉ Numéros de page uniquement<br>◉ Toute la table</div>
        <small>La table ne se met pas à jour toute seule.</small>`, S(10, 0), S(16, 1) + 0.3, { left: "40px", right: "auto", width: "460px" });

      // ================= partie 2 : protéger le document
      const el = W.el;
      E.only(cur, -1, S(17, 0));
      const cur2 = $("div", "cursor", W.over); cur2.innerHTML = E.ARROW; E.only(cur2, S(17, 0), 1e9);
      const tFile = S(19, 0.25), tInfo = S(19, 0.5), tProt = S(19, 0.8), tEnc = S(20, 0.5), tOk1 = S(21, 0.85), tOk2 = S(22, 0.85);
      const B = SC.backstage(E, W, tFile, S(23, 0.3));
      B.main.innerHTML = `<h3>Informations</h3><div class="tile prot"><i class="lk" style="background:#eef1f6">&#128274;</i><div><b>Protéger le document</b><br><small class="pm" style="color:#666">Contrôlez les modifications que les autres peuvent apporter à ce document.</small></div></div>
        <div class="tile"><i>&#128269;</i><div><b>Inspecter le document</b><br><small style="color:#666">Propriétés, auteur, commentaires…</small></div></div>`;
      E.only(B.main, tInfo, 1e9);
      E.cls(B.item("Informations"), "hl", tInfo - 0.2, 1e9);
      const prot = B.main.querySelector(".prot"), lk = B.main.querySelector(".lk"), pm = B.main.querySelector(".pm");
      E.cls(prot, "hl", tProt - 0.3, tEnc + 0.2);
      E.track(t => { const on = t >= tOk2; lk.style.background = on ? "#ffd54a" : "#eef1f6"; const v = on ? "Un mot de passe est nécessaire pour ouvrir ce document." : "Contrôlez les modifications que les autres peuvent apporter à ce document."; if (pm.textContent !== v) pm.textContent = v; });
      const pd = $("div", "dd", W.layer, { zIndex: 20 });
      const [px, py] = SC.corner(prot, W.root);
      Object.assign(pd.style, { left: (px + 20) + "px", top: (py + 70) + "px", width: "400px" });
      pd.innerHTML = `<div>&#128214; Toujours ouvrir en lecture seule</div><div class="enc">&#128274; Chiffrer avec mot de passe</div><div>&#128221; Restreindre la modification</div><div>&#9997; Ajouter une signature numérique</div><div>&#10004; Marquer comme final</div>`;
      E.show(pd, tProt, tEnc + 0.1, 0.12);
      E.cls(pd.querySelector(".enc"), "hl", tEnc - 0.4, tEnc + 0.1);
      const mkDlg = (title, label, a, b, tType) => {
        const g = $("div", "dlg", W.layer, { left: "600px", top: "300px", width: "420px", zIndex: 25 });
        g.innerHTML = `<div class="dt">${title}</div><div class="dbd">${label}<div class="fld pw" style="display:block;margin:6px 0 10px;letter-spacing:3px"></div><small style="color:#a33">Attention : un mot de passe oublié ne peut pas être récupéré.</small>
          <div style="text-align:right;margin-top:14px"><span class="btn pri ok">OK</span><span class="btn">Annuler</span></div></div>`;
        E.show(g, a, b, 0.15);
        const f = g.querySelector(".pw");
        E.track(t => { const n = t < tType ? 0 : Math.min(8, Math.floor((t - tType) * 7)); const v = "●".repeat(n); if (f.textContent !== v) f.textContent = v; });
        E.cls(g.querySelector(".ok"), "hl", b - 0.35, b);
        return g;
      };
      const d1 = mkDlg("Chiffrer le document", "Chiffrer le contenu de ce fichier<br>Mot de passe :", tEnc + 0.1, tOk1, S(21, 0.2));
      const d2 = mkDlg("Confirmer le mot de passe", "Entrez de nouveau le mot de passe :", tOk1 + 0.05, tOk2, S(22, 0.15));
      E.cls(el.save, "hl", S(23, 0.5), S(23, 1));

      // ---- Révision › Restreindre la modification
      const tRev = S(25, 0.35), tRes = S(25, 0.75), tC1 = S(26, 0.45), tYes = S(27, 0.6), tOk3 = S(28, 0.85);
      W.tabAt([[0, "Accueil"], [S(7, 0.45), "Références"], [S(17, 0.2), "Accueil"], [tRev, "Révision"]]);
      E.cls(W.tabs["Révision"], "hl", tRev - 0.3, tRev);
      E.cls(el.restrict, "hl", tRes - 0.3, tRes + 0.3);
      const pane = $("div", "pane", W.root);
      pane.innerHTML = `<h4>Restreindre la modification &#10005;</h4><div class="sec">1. Restrictions de mise en forme</div><span class="chk"><i></i>Limiter la mise en forme à une sélection de styles</span>
        <div class="sec">2. Restrictions de modifications</div><span class="chk c1"><i></i>Autoriser uniquement ce type de modifications dans le document :</span>
        <div class="fld" style="display:block;margin:8px 0">Aucune modification (Lecture seule) &#9662;</div><small style="color:#666">Autres possibilités : commentaires, remplissage de formulaires…</small>
        <div class="sec">3. Activation de la protection</div><span class="btn yes" style="margin:4px 0 0">Oui, activer la protection</span>`;
      E.show(pane, tRes, 1e9, 0.25);
      E.cls(pane.querySelector(".c1"), "on", tC1, 1e9); E.cls(pane.querySelector(".c1"), "hl", tC1 - 0.2, tC1 + 0.4);
      E.cls(pane.querySelector(".yes"), "hl", tYes - 0.3, tYes + 0.2);
      const d3 = $("div", "dlg", W.layer, { left: "560px", top: "280px", width: "480px", zIndex: 25 });
      d3.innerHTML = `<div class="dt">Commencer l’application de la protection</div><div class="dbd">Méthode de protection : ● Mot de passe (facultatif)<br><br>Entrez le nouveau mot de passe :<div class="fld p1" style="display:block;margin:4px 0 8px;letter-spacing:3px"></div>
        Confirmez le mot de passe :<div class="fld p2" style="display:block;margin:4px 0 8px;letter-spacing:3px"></div><div style="text-align:right;margin-top:10px"><span class="btn pri ok">OK</span><span class="btn">Annuler</span></div></div>`;
      E.show(d3, tYes + 0.1, tOk3, 0.15);
      [[".p1", S(28, 0.15)], [".p2", S(28, 0.45)]].forEach(([q, a]) => { const f = d3.querySelector(q); E.track(t => { const n = t < a ? 0 : Math.min(6, Math.floor((t - a) * 8)); const v = "●".repeat(n); if (f.textContent !== v) f.textContent = v; }); });
      E.cls(d3.querySelector(".ok"), "hl", tOk3 - 0.3, tOk3);

      SC.tour(E, W.root, W.over, cur2, [[S(17, 0), [1100, 600], 0], [tFile - 0.05, W.tabs.Fichier, 0.9, 1], [tInfo - 0.05, B.item("Informations"), 0.4, 1], [tProt - 0.05, prot, 0.5, 1],
        [tEnc - 0.05, pd.querySelector(".enc"), 0.5, 1], [S(21, 0.15), d1.querySelector(".pw"), 0.5, 1], [tOk1 - 0.05, d1.querySelector(".ok"), 0.5, 1], [tOk2 - 0.05, d2.querySelector(".ok"), 0.5, 1],
        [S(23, 0.75), el.save, 0.9, 1], [tRev - 0.05, W.tabs["Révision"], 0.9, 1], [tRes - 0.05, el.restrict, 0.5, 1], [tC1 - 0.05, pane.querySelector(".c1 i"), 0.8, 1],
        [tYes - 0.05, pane.querySelector(".yes"), 0.8, 1], [S(28, 0.1), d3.querySelector(".p1"), 0.5, 1], [tOk3 - 0.05, d3.querySelector(".ok"), 0.6, 1], [S(29, 0.6), el.save, 1.0, 1]]);
      E.card(`Fichier › Informations<small>Protéger le document › Chiffrer avec mot de passe.</small>`, S(19, 0), S(20, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "440px" });
      E.card(`Le mot de passe<div style="font-size:40px;letter-spacing:6px;text-align:center;margin-top:10px">&#128274; ●●●●</div><small>Tapez-le deux fois. Notez-le bien : il ne peut pas être récupéré.</small>`, S(21, 0), S(22, 1) + 0.2, { left: "40px", right: "auto", top: "560px", width: "440px" });
      SC.keysCard(E, "Enregistrer", ["Ctrl", "S"], S(23, 0), S(23, 1) + 0.3, { top: "640px", left: "40px", right: "auto", width: "360px" }, "La protection s’applique à l’enregistrement.");
      E.card(`Révision › Restreindre<small>Lecture seule, commentaires uniquement ou remplissage de formulaires.</small>`, S(25, 0), S(27, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "440px" });
      SC.keysCard(E, "Enregistrer", ["Ctrl", "S"], S(29, 0), 999, { top: "640px", left: "40px", right: "auto", width: "360px" });
    }
  });
})();
