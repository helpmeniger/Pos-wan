/* Leçon animée « Saisir du texte dans Word » (code-barres 1000, vidéo vid7). */
(function () {
  const T = window.T["1000"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Saisir du texte dans Word"], [1, "Ouvrir Word"], [3, "Placer le curseur et taper"], [7, "Sélectionner du texte"],
            [14, "Tout sélectionner"], [16, "Annuler une action"], [21, "Rétablir une action"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const desk = E.desktop(d.screen, 1076, 605);
      const W = SC.word(E, d.screen, 1076, 605, "Document1");
      const tOpen = S(2, 0.8);
      E.show(W.root, tOpen, 999, 0.35);
      E.show(desk.cursor, -1, tOpen + 0.2, 0);
      const cur = W.cursor, P = W.page;

      // ---------------- ouvrir Word depuis le bureau
      const wIco = desk.icons[3];
      const [wx, wy] = SC.pos(wIco, desk.root);
      E.move(desk.cursor, [[0, 900, 450], [S(1, 0.2), 900, 450], [S(2, 0.4), wx, wy, 1.0], [999, wx, wy]]);
      E.cls(wIco, "sel", S(2, 0.45), tOpen);
      E.click(desk.over, S(2, 0.5), wx, wy, 2);

      // ---------------- saisie
      const p1 = $("p", "", P), p2 = $("p", "", P);
      const L1 = "Bonjour, je m’appelle Awa et j’apprends Word. ", L2 = "La saisie du texte est simple et rapide.";
      const tClick = S(3, 0.45), ty1 = [S(5, 0.05), S(5, 0.95)], ty2 = [S(6, 0.0), S(6, 0.55)];
      const p1t = $("span", "", p1), p2t = $("span", "", p2);
      SC.typeText(E, p1t, "", tClick, tClick);
      E.only(p1t, -1, ty1[0]);
      const p1a = $("span", "", p1); SC.typeText(E, p1a, L1, ty1[0], ty1[1]); E.only(p1a, ty1[0], S(7, 0));
      const p2a = $("span", "", p2); SC.typeText(E, p2a, L2, ty2[0], ty2[1]); E.only(p2a, ty2[0], S(7, 0));
      // texte définitif, mot par mot (pour les sélections)
      const w1 = $("span", "", p1), w2 = $("span", "", p2);
      const A = SC.words(E, w1, L1), B = SC.words(E, w2, L2);
      E.only(w1, S(7, 0), 1e9); E.only(w2, S(7, 0), 1e9);
      // le mot tapé par erreur, annulé puis rétabli
      const err = $("span", "", w2); err.textContent = " xqzw"; err.style.color = "#c0392b";
      const tErr = S(17, 0.35), tUndo = S(18, 0.75), tUndoK = S(19, 0.6), tRedo = S(23, 0.75), tRedoK = S(24, 0.6);
      E.anim([[0, 0], [tErr, 0], [tErr + 0.6, 5, 0.6], [tUndo, 5], [tUndo + 0.01, 0, 0.01], [S(19, 0.1), 0], [S(19, 0.3), 5, 0.3], [tUndoK, 5], [tUndoK + 0.01, 0, 0.01],
        [tRedo, 0], [tRedo + 0.01, 5, 0.01], [tRedoK - 0.6, 5], [tRedoK - 0.59, 0, 0.01], [tRedoK, 0], [tRedoK + 0.01, 5, 0.01]],
        v => { err.textContent = " xqzw".slice(0, Math.round(v)); });

      // sélections : souris (4 premiers mots), clavier (2e phrase), tout (Ctrl + A)
      SC.selectRange(E, A, 0, 3, S(10, 0.15), S(10, 0.85), S(12, 0.0));
      SC.selectRange(E, B, 0, B.length - 1, S(13, 0.3), S(13, 0.9), S(14, 0.2));
      SC.selectRange(E, A, 0, A.length - 1, S(15, 0.4), S(15, 0.45), S(16, 0.4));
      SC.selectRange(E, B, 0, B.length - 1, S(15, 0.4), S(15, 0.45), S(16, 0.4));
      E.cls(W.el.undo, "hl", tUndo - 0.5, tUndo + 0.3);
      E.cls(W.el.redo, "hl", tRedo - 0.5, tRedo + 0.3);
      W.tabAt([[0, "Accueil"]]);

      // ---------------- curseur de Word
      const [ax, ay] = W.pos(A[0], -W.pos(A[0])[0] + SC.corner(A[0], W.root)[0] + 2, 0);
      const [ex, ey] = SC.corner(A[3], W.root).map((v, i) => i ? v + 14 : v + A[3].offsetWidth - 6);
      const [bx, by] = [SC.corner(B[0], W.root)[0] + 2, SC.corner(B[0], W.root)[1] + 14];
      const [ux, uy] = W.pos(W.el.undo), [rx, ry] = W.pos(W.el.redo);
      const [ix, iy] = [SC.corner(P, W.root)[0] + 300, SC.corner(P, W.root)[1] + 90];
      E.show(cur, tOpen, 999, 0);
      E.move(cur, [[0, 1100, 600], [tOpen, 1100, 600], [S(3, 0.3), ix, iy, 0.8], [S(5, 0), ix, iy], [S(5, 0.2), 1250, 650, 0.6],
        [S(9, 0.2), 1250, 650], [S(9, 0.8), ax, ay, 0.8], [S(10, 0.15), ax, ay], [S(10, 0.85), ex, ey, 2.4], [S(12, 0.3), ex, ey], [S(12, 0.8), bx, by, 0.6],
        [S(14, 0), bx, by], [S(14, 0.6), 1250, 650, 0.6], [S(18, 0.1), 1250, 650], [tUndo - 0.15, ux, uy, 1.0], [tUndo + 0.6, ux, uy], [tUndo + 1.4, 1250, 650, 0.7],
        [S(23, 0.1), 1250, 650], [tRedo - 0.15, rx, ry, 1.0], [tRedo + 0.6, rx, ry], [tRedo + 1.4, 1250, 650, 0.7]]);
      SC.cursorModes(E, cur, [[0, "arrow"], [S(3, 0.1), "ibeam"], [S(18, 0.2), "arrow"], [tUndo + 0.8, "ibeam"], [S(23, 0.2), "arrow"], [tRedo + 0.8, "ibeam"]]);
      E.click(W.over, tClick, ix, iy);
      E.click(W.over, S(10, 0.15), ax, ay);
      E.click(W.over, S(12, 0.85), bx, by);
      E.click(W.over, tUndo, ux, uy);
      E.click(W.over, tRedo, rx, ry);

      // ---------------- caméra
      const [, pageY] = SC.corner(P, W.root);
      SC.screenCam(E, [[0, "room"], [S(0, 0.6), "room"], [S(1, 0.2), 800, 450, 1, 1.0], [S(2, 0.0), 400, 330, 1.6, 0.8], [tOpen, 400, 330, 1.6],
        [tOpen + 0.5, 800, 450, 1, 0.7], [S(3, 0.3), 800, 450, 1], [S(3, 0.9), 760, pageY + 150, 1.9, 0.8], [S(17, 1), 760, pageY + 150, 1.9],
        [S(18, 0.3), 400, 230, 2.0, 0.8], [S(18, 1), 400, 230, 2.0], [S(19, 0.2), 760, pageY + 150, 1.9, 0.7], [S(22, 1), 760, pageY + 150, 1.9],
        [S(23, 0.3), 400, 230, 2.0, 0.8], [S(23, 1), 400, 230, 2.0], [S(24, 0.2), 760, pageY + 150, 1.9, 0.7], [S(25, 0.6), 760, pageY + 150, 1.9], [S(25, 1) + 0.6, "room", 1.4]]);

      // ---------------- cartes
      E.card(`Le point d’insertion<div style="margin:20px auto 4px;width:6px;height:70px;background:#111;border-radius:2px" class="blink"></div>
        <small>Une petite barre verticale qui clignote : le texte s’écrit à cet endroit.</small>`, S(4, 0), S(5, 1) + 0.2, { left: "40px", right: "auto", top: "560px", width: "420px" });
      SC.keysCard(E, "Déplacer le curseur", ["&#8592;", "&#8594;", "&#8593;", "&#8595;"], S(6, 0), S(6, 1) + 0.3, { top: "640px" });
      SC.mouseCard(E, [[S(9, 0), S(11, 1) + 0.2, "left", "Cliquer-glisser"]]);
      SC.keysCard(E, "Sélection au clavier", ["Maj", "&#8594;"], S(12, 0), S(13, 1) + 0.2, { top: "640px" }, "Shift (Maj) + flèches");
      SC.keysCard(E, "Tout sélectionner", ["Ctrl", "A"], S(14, 0), S(15, 1) + 0.3, { top: "640px" });
      E.card(`Annuler<div style="font-size:90px;text-align:center;line-height:1.2;color:#326fbf">&#8630;</div>`, S(18, 0), S(18, 1) + 0.2, { top: "160px", width: "300px" });
      SC.keysCard(E, "Annuler", ["Ctrl", "Z"], S(19, 0), S(20, 1) + 0.2, { top: "640px" });
      E.card(`Rétablir<div style="font-size:90px;text-align:center;line-height:1.2;color:#326fbf">&#8631;</div>`, S(23, 0), S(23, 1) + 0.2, { top: "160px", width: "300px" });
      SC.keysCard(E, "Rétablir", ["Ctrl", "Y"], S(24, 0), S(25, 1) + 0.2, { top: "640px" });
    }
  });
})();
