/* Leçon animée « Mettre en forme les caractères et les paragraphes » (code-barres 1001, vidéo vid8). */
(function () {
  const T = window.T["1001"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Mettre en forme le texte"], [1, "Sélectionner"], [5, "Police et taille"], [11, "Couleur"], [14, "Gras, italique, souligné"],
            [18, "Surlignage et barré"], [23, "La boîte « Police »"], [26, "Les paragraphes"], [30, "Les alignements"], [35, "Les retraits"],
            [41, "Les espacements"], [44, "La règle"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const W = SC.word(E, d.screen, 1076, 605, "Le Niger");
      const cur = W.cursor, P = W.page, el = W.el;

      // ---------------- document
      const p0 = $("p", "", P, { fontSize: "30px", margin: "0 0 22px" });
      const tgt = $("span", "", p0); tgt.textContent = "Le fleuve Niger";
      const pA = $("p", "", P);
      pA.textContent = "Le Niger est le troisième fleuve d’Afrique par sa longueur. Il traverse la Guinée, le Mali, le Niger, le Bénin et le Nigeria avant de se jeter dans l’océan Atlantique.";
      const pB = $("p", "", P); pB.textContent = "Niamey, la capitale du Niger, est construite sur ses rives.";

      // sélection du titre (le reste de la leçon s'applique à cette sélection)
      E.cls(tgt, "sel", S(1, 0.4), S(2, 0.2));
      E.cls(tgt, "sel2", S(2, 0.2), S(25, 1));
      E.cls(pA, "sel2", S(27, 0.2), S(43, 1));

      // ---------------- mise en forme des caractères (selon le temps)
      const tFont1 = S(7, 0.1), tFont2 = S(7, 0.65), tSize1 = S(10, 0.15), tSize2 = S(10, 0.6), tColor = S(13, 0.55);
      const tB = S(15, 0.55), tI = S(16, 0.4), tU = S(17, 0.4), tH = S(20, 0.6), tS = S(22, 0.6), tS2 = S(22, 1) + 0.3;
      E.track(t => {
        tgt.style.fontFamily = t >= tFont2 ? "Verdana, DejaVu Sans, sans-serif" : t >= tFont1 ? "'Times New Roman', 'Liberation Serif', serif" : "";
        tgt.style.fontSize = t >= tSize2 ? "40px" : t >= tSize1 ? "34px" : "";
        tgt.style.color = t >= tColor ? "#c00000" : "";
        tgt.style.fontWeight = t >= tB ? "700" : "";
        tgt.style.fontStyle = t >= tI ? "italic" : "";
        tgt.style.textDecoration = [t >= tU ? "underline" : "", t >= tS && t < tS2 ? "line-through" : ""].join(" ").trim() || "none";
        tgt.style.backgroundColor = t >= tH ? "#fff200" : "";
        el.font.textContent = t >= tFont2 ? "Verdana" : t >= tFont1 ? "Times New Roman" : "Calibri (Corps)";
        el.size.textContent = t >= tSize2 ? "14" : t >= tSize1 ? "12" : "11";
      });
      [[el.bold, tB], [el.italic, tI], [el.under, tU]].forEach(([b, t0]) => E.cls(b, "on", t0, S(25, 1)));

      // menus déroulants : polices, tailles, couleurs
      const ddF = $("div", "dd", W.layer), ddS = $("div", "dd", W.layer), ddC = $("div", "dd", W.layer);
      const [fx, fy] = SC.corner(el.font, W.root), [sx, sy] = SC.corner(el.size, W.root), [cx, cy] = SC.corner(el.color, W.root);
      Object.assign(ddF.style, { left: fx + "px", top: (fy + 30) + "px", width: "250px" });
      ddF.innerHTML = ["Arial", "Calibri", "Cambria", "Times New Roman", "Verdana"].map(f => `<div style="font-family:'${f}'">${f}</div>`).join("");
      Object.assign(ddS.style, { left: sx + "px", top: (sy + 30) + "px", width: "70px" });
      ddS.innerHTML = [8, 9, 10, 11, 12, 14, 16, 18, 20].map(n => `<div>${n}</div>`).join("");
      Object.assign(ddC.style, { left: (cx - 100) + "px", top: (cy + 36) + "px" });
      const SW = ["#000000", "#ffffff", "#44546a", "#4472c4", "#ed7d31", "#a5a5a5", "#ffc000", "#5b9bd5", "#c00000", "#ff0000", "#ffc000", "#ffff00", "#92d050", "#00b050", "#00b0f0", "#7030a0"];
      ddC.innerHTML = `<div style="padding:4px 10px;font-size:13px;color:#555">Couleurs standard</div><div class="sw">${SW.map(c => `<i style="background:${c}"></i>`).join("")}</div>`;
      const fItems = ddF.querySelectorAll("div"), sItems = ddS.querySelectorAll("div"), red = ddC.querySelectorAll("i")[8];
      E.show(ddF, S(6, 0.45), tFont2 + 0.1, 0.12); E.cls(fItems[3], "hl", tFont1 - 0.4, tFont1 + 0.2); E.cls(fItems[4], "hl", tFont2 - 0.3, tFont2 + 0.1);
      E.show(ddS, S(9, 0.55), tSize2 + 0.1, 0.12); E.cls(sItems[4], "hl", tSize1 - 0.4, tSize1 + 0.2); E.cls(sItems[5], "hl", tSize2 - 0.3, tSize2 + 0.1);
      E.show(ddC, S(12, 0.7), tColor + 0.1, 0.12); E.cls(red, "hl", tColor - 0.5, tColor + 0.1);
      E.cls(el.font, "hl", S(6, 0.1), tFont2); E.cls(el.size, "hl", S(9, 0.1), tSize2);

      // boîte de dialogue « Police »
      const dlgF = $("div", "dlg", W.layer);
      Object.assign(dlgF.style, { left: "560px", top: "220px", width: "520px" });
      dlgF.innerHTML = `<div class="dt">Police</div><div class="dbd">Police :<br><span class="fld">Verdana</span><span class="fld" style="min-width:110px">Gras italique</span><span class="fld" style="min-width:50px">14</span>
        <div style="margin:6px 0 4px;font-weight:600">Effets</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 12px"><span class="chk"><i></i>Barré</span><span class="chk"><i></i>Petites majuscules</span>
        <span class="chk"><i></i>Barré double</span><span class="chk"><i></i>Majuscules</span><span class="chk ex"><i></i>Exposant</span><span class="chk"><i></i>Masqué</span>
        <span class="chk"><i></i>Indice</span><span class="chk sh"><i></i>Ombre</span></div>
        <div style="text-align:right;margin-top:14px"><span class="btn pri">OK</span><span class="btn">Annuler</span></div></div>`;
      E.show(dlgF, S(24, 0.75), S(25, 1) + 0.3, 0.2);
      E.cls(dlgF.querySelector(".ex"), "hl", S(25, 0.2), S(25, 0.5));
      E.cls(dlgF.querySelector(".sh"), "hl", S(25, 0.5), S(25, 0.9));
      E.cls(el.launchFont, "hl", S(24, 0.3), S(24, 0.8));

      // ---------------- paragraphes
      const ctx = E.makeMenu(W.layer, ["Couper", "Copier", "Options de collage :", "-", "Police...", "Paragraphe...", "-", "Rechercher"]);
      const [pax, pay] = SC.corner(pA, W.root);
      Object.assign(ctx.style, { left: (pax + 380) + "px", top: (pay + 30) + "px", minWidth: "250px", zIndex: 30 });
      E.show(ctx, S(28, 0.4), S(28, 1) + 0.1, 0.12);
      E.cls(ctx.item(4), "hl", S(28, 0.65), S(28, 1) + 0.1);
      const tAl = [S(31, 0.3), S(32, 0.3), S(33, 0.3), S(34, 0.3)];
      const tIn = [S(37, 0.35), S(38, 0.35), S(39, 0.35), S(40, 0.35), S(41, 0)];
      const tLh = S(42, 0.55), tSp = S(43, 0.5);
      E.track(t => {
        let a = "left"; if (t >= tAl[1]) a = "center"; if (t >= tAl[2]) a = "right"; if (t >= tAl[3]) a = "justify";
        pA.style.textAlign = a;
        const k = tIn.filter(x => t >= x).length;   // retrait en cours (0 = aucun)
        pA.style.marginLeft = k === 1 ? "70px" : "0";
        pA.style.marginRight = k === 2 ? "70px" : "0";
        pA.style.textIndent = k === 3 ? "50px" : k === 4 ? "-50px" : "0";
        pA.style.paddingLeft = k === 4 ? "50px" : "0";
        pA.style.lineHeight = t >= tLh ? "2.1" : "";
        pA.style.marginBottom = t >= tSp ? "44px" : "";
      });
      E.cls(el.al, "on", tAl[0], tAl[1]); E.cls(el.ac, "on", tAl[1], tAl[2]); E.cls(el.ar, "on", tAl[2], tAl[3]); E.cls(el.aj, "on", tAl[3], S(43, 1));
      E.cls(el.indr, "hl", tIn[0] - 0.4, tIn[0] + 0.3); E.cls(el.indl, "hl", tIn[1] - 0.4, tIn[1] + 0.3);
      const ddL = $("div", "dd", W.layer);
      const [lx, ly] = SC.corner(el.sp, W.root);
      Object.assign(ddL.style, { left: lx + "px", top: (ly + 34) + "px", width: "260px" });
      ddL.innerHTML = ["1,0", "1,15", "1,5", "2,0", "2,5", "3,0", "Ajouter un espace avant le paragraphe", "Ajouter un espace après le paragraphe"].map(n => `<div>${n}</div>`).join("");
      const lItems = ddL.querySelectorAll("div");
      E.show(ddL, S(42, 0.3), tSp + 0.1, 0.12); E.cls(lItems[3], "hl", tLh - 0.3, tLh + 0.1);
      E.cls(lItems[7], "hl", tSp - 0.3, tSp + 0.1);

      // ---------------- règle : onglet Affichage
      const tRule = S(45, 0.65);
      W.tabAt([[0, "Accueil"], [S(45, 0.3), "Affichage"]]);
      E.cls(el.rule, "on", tRule, 999); E.cls(el.rule, "hl", tRule - 0.4, tRule + 0.3);
      E.cls(W.ruler, "on", tRule, 999);
      E.cls(W.tabs["Affichage"], "hl", S(45, 0.1), S(45, 0.35));

      // ---------------- curseur
      const [t0x, t0y] = SC.corner(tgt, W.root), tw = tgt.offsetWidth;
      const pAmid = [pax + 380, pay + 30];
      SC.tour(E, W.root, W.over, cur, [
        [0, [1150, 650], 0], [S(1, 0.25), [t0x + 2, t0y + 22], 0.8], [S(1, 0.9), [t0x + tw, t0y + 22], 0.6],
        [S(3, 0.5), W.tabs.Accueil, 0.8, 1], [S(6, 0.4), el.font, 0.8, 1], [tFont1 - 0.05, fItems[3], 0.4, 1], [S(7, 0.45), el.font, 0.3, 1], [tFont2 - 0.05, fItems[4], 0.3, 1],
        [S(9, 0.5), el.size, 0.7, 1], [tSize1 - 0.05, sItems[4], 0.4, 1], [S(10, 0.45), el.size, 0.3, 1], [tSize2 - 0.05, sItems[5], 0.3, 1],
        [S(12, 0.6), el.color, 0.8, 1], [tColor - 0.05, red, 0.4, 1],
        [tB - 0.05, el.bold, 0.7, 1], [tI - 0.05, el.italic, 0.5, 1], [tU - 0.05, el.under, 0.5, 1],
        [tH - 0.05, el.high, 0.8, 1], [tS - 0.05, el.strike, 0.7, 1], [tS2 - 0.05, el.strike, 0.2, 1],
        [S(24, 0.6), el.launchFont, 0.9, 1], [S(25, 0.3), dlgF.querySelector(".ex"), 0.6], [S(25, 0.7), dlgF.querySelector(".sh"), 0.4],
        [S(27, 0.4), [pax + 4, pay + 14], 0.9], [S(27, 0.95), [pax + 560, pay + 70], 0.6],
        [S(28, 0.4), pAmid, 0.5, 1], [S(28, 0.75), ctx.item(5), 0.4], [S(29, 0.6), el.al, 0.9],
        [tAl[0] - 0.05, el.al, 0.6, 1], [tAl[1] - 0.05, el.ac, 0.5, 1], [tAl[2] - 0.05, el.ar, 0.5, 1], [tAl[3] - 0.05, el.aj, 0.5, 1],
        [tIn[0] - 0.05, el.indr, 0.7, 1], [tIn[1] - 0.05, el.indl, 0.6, 1], [tIn[2] - 0.05, el.launchPara, 0.6, 1], [tIn[3] - 0.05, el.launchPara, 0.3, 1],
        [S(42, 0.25), el.sp, 0.8, 1], [tLh - 0.05, lItems[3], 0.4, 1], [S(43, 0.15), el.sp, 0.4, 1], [tSp - 0.05, lItems[7], 0.4, 1],
        [S(45, 0.25), W.tabs.Affichage, 0.9, 1], [tRule - 0.05, el.rule, 0.5, 1], [999, el.rule, 0]
      ]);
      SC.cursorModes(E, cur, [[0, "arrow"], [S(1, 0.1), "ibeam"], [S(2, 0.5), "arrow"], [S(27, 0.2), "ibeam"], [S(28, 0.3), "arrow"]]);

      // ---------------- caméra (coordonnées de l'écran du moniteur)
      const g = e => W.pos(e);
      const gfx = Math.max(g(el.grpFont)[0], 420), gpx = Math.max(g(el.grpPara)[0], 420);
      const page = [760, pay + 120];
      SC.screenCam(E, [[0, "room"], [S(0, 0.6), "room"], [S(1, 0.1), page[0], page[1], 1.6, 1.0], [S(2, 0.6), page[0], page[1], 1.6],
        [S(3, 0.3), gfx + 80, 300, 1.7, 0.8], [S(5, 0.2), gfx + 80, 300, 1.7], [S(6, 0.1), gfx + 60, 300, 2.0, 0.6], [S(10, 1), gfx + 60, 300, 2.0],
        [S(11, 0.2), gfx + 60, 300, 1.7, 0.5], [S(22, 1) + 0.3, gfx + 60, 300, 1.7],
        [S(23, 0.3), 820, 420, 1.4, 0.7], [S(25, 1), 820, 420, 1.4], [S(26, 0.4), page[0], page[1], 1.6, 0.8], [S(28, 1), page[0], page[1], 1.6],
        [S(29, 0.3), gpx + 60, 300, 1.7, 0.8], [S(41, 0.1), gpx + 60, 300, 1.7],
        [S(42, 0.1), gpx + 60, 320, 1.6, 0.4], [S(43, 1), gpx + 60, 320, 1.6], [S(44, 0.3), 800, 330, 1.25, 0.8], [S(45, 1) + 0.4, 800, 330, 1.25], [S(45, 1) + 1.6, "room", 1.2]]);

      // ---------------- cartes
      E.card(`Accueil › Police<div style="display:flex;gap:10px;justify-content:center;margin-top:18px;font-size:40px">
        <b>G</b><i style="font-family:serif">I</i><u>S</u><s>ab</s><span style="background:#fff200">&#9998;</span><span style="color:#c00000">A</span></div>
        <small>G = gras, I = italique, S = souligné</small>`, S(14, 0), S(17, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "430px" });
      E.card(`Exemples<div style="margin-top:12px;font-size:30px;line-height:1.5;font-weight:400"><div style="font-family:'Times New Roman','Liberation Serif',serif">Times New Roman</div><div style="font-family:Verdana,'DejaVu Sans',sans-serif">Verdana</div></div>`,
        S(7, 0), S(7, 1) + 0.3, { left: "40px", right: "auto", top: "640px", width: "400px" });
      E.card(`Options avancées<small>Le petit carré avec une flèche, en bas à droite du groupe Police : exposant, indice, ombre…</small>`, S(24, 0), S(25, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "460px" });
      const alg = (w, a) => `<svg width="64" height="48" viewBox="0 0 64 48">${[0, 1, 2, 3].map(i => { const l = [64, 44, 58, 36][i]; const x = a === "c" ? (64 - l) / 2 : a === "r" ? 64 - l : 0; return `<rect x="${x}" y="${i * 12 + 2}" width="${a === "j" ? 64 : l}" height="6" rx="3" fill="${w ? "#326fbf" : "#9aa6b8"}"/>`; }).join("")}</svg>`;
      const alc = E.card(`Les alignements<div style="display:flex;gap:16px;justify-content:center;margin-top:16px">${["l", "c", "r", "j"].map(a => `<span class="a-${a}">${alg(false, a)}</span>`).join("")}</div>
        <small style="text-align:center">Gauche · Centré · Droite · Justifié</small>`, S(30, 0), S(34, 1) + 0.2, { top: "150px", width: "460px" });
      E.track(t => ["l", "c", "r", "j"].forEach((a, i) => { const on = t >= tAl[i] && (i === 3 || t < tAl[i + 1]); alc.querySelector(".a-" + a).innerHTML = alg(on, a); }));
      E.card(`Les retraits<div class="rt" style="margin-top:12px;font-size:25px;font-weight:600;line-height:1.6"></div>`, S(35, 0), S(40, 1) + 0.2, { top: "150px", width: "460px" });
      const rt = document.querySelectorAll(".rt"); const rtc = rt[rt.length - 1];
      E.track(t => { const k = tIn.filter(x => t >= x).length; const h = ["Espace entre le texte et les marges", "Retrait de gauche", "Retrait de droite", "Retrait de première ligne", "Retrait suspendu", "Espace entre le texte et les marges"][k]; if (rtc._h !== h) { rtc.textContent = h; rtc._h = h; } });
      E.card(`Interligne et espacement<small>Entre les lignes, et avant ou après le paragraphe.</small>`, S(41, 0), S(43, 1) + 0.2, { top: "150px", width: "460px" });
      E.card(`Affichage › Règle<small>Cochez « Règle » pour régler les retraits à la souris.</small>`, S(44, 0), 999, { top: "150px", width: "460px" });
    }
  });
})();
