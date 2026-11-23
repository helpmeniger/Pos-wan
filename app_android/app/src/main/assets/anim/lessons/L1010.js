/* Leçon animée « Sections et numérotation différente » (code-barres 1010, vidéo sectionvid). */
(function () {
  const T = window.T["1010"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Les sections"], [1, "Insérer un saut de section"], [6, "En-têtes et pieds de page"], [10, "Numérotation différente"], [14, "Exemple concret"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const W = SC.word(E, d.screen, 1076, 605, "Rapport de stage");
      const cur = W.cursor, el = W.el;
      W.page.style.display = "none";

      // ---------------- trois pages (affichage plusieurs pages)
      const lines = n => Array.from({ length: n }, (_, i) => `<p style="height:9px;background:#d9dee6;border-radius:4px;margin:0 0 11px;width:${[100, 92, 97, 85, 95, 70][i % 6]}%"></p>`).join("");
      const titles = ["Sommaire", "1. Le stage", "Annexes"];
      const pages = titles.map((ti, i) => {
        const pg = $("div", "abs", W.docarea, { left: (150 + i * 450) + "px", top: "24px", width: "360px", height: "500px", background: "#fff", boxShadow: "0 2px 8px rgba(0,0,0,.2)", padding: "34px 34px 0", fontFamily: "Calibri, Carlito, sans-serif" });
        pg.innerHTML = `<div style="font-size:22px;color:#2f5496;margin-bottom:14px">${ti}</div>${lines(i === 0 ? 9 : 12)}<div class="brk" style="display:none;font-size:11px;color:#777;border-top:1px dotted #777;text-align:center;margin-top:-2px">Saut de section (page suivante)</div>
          <div class="ft" style="position:absolute;left:0;right:0;bottom:0;height:64px;padding:8px 14px;font-size:20px;text-align:center"><span class="ftag" style="display:none;position:absolute;left:0;top:-22px;font-size:11px;background:#e6eefb;color:#185abd;padding:2px 6px">Pied de page - Section ${i + 1} -</span>
          <span class="same" style="display:none;position:absolute;right:0;top:-22px;font-size:11px;background:#e6eefb;color:#185abd;padding:2px 6px">Identique au précédent</span><span class="num"></span></div>
          <div class="sec" style="position:absolute;left:50%;top:-20px;transform:translateX(-50%);background:#326fbf;color:#fff;border-radius:12px;padding:2px 12px;font-size:14px;display:none">Section ${i + 1}</div>`;
        return pg;
      });
      const tP2 = S(4, 0.05), tP3 = S(5, 0.5);
      E.show(pages[1], tP2, 999, 0.35); E.show(pages[2], tP3, 999, 0.35);
      E.only(pages[0].querySelector(".brk"), tP2, 1e9); E.only(pages[1].querySelector(".brk"), tP3, 1e9);

      // pied de page de la section 2 : ouvert au double-clic, puis « Lier au précédent » désactivé
      const tFoot = S(7, 0.5), tUnlink = S(8, 0.75), tClose = S(9, 1);
      const ft2 = pages[1].querySelector(".ft");
      E.track(t => { const on = t >= tFoot && t < tClose; ft2.style.outline = on ? "2px dashed #185abd" : ""; });
      E.only(pages[1].querySelector(".ftag"), tFoot, tClose); E.only(pages[1].querySelector(".same"), tFoot, tUnlink);
      E.cls(el.link, "on", 0, tUnlink); E.cls(el.link, "hl", tUnlink - 0.4, tUnlink + 0.2);

      // numéros de page selon le temps
      const tFmt = S(13, 0.3), tEx1 = S(15, 0.3), tEx3 = S(17, 0.3);
      E.track(t => pages.forEach((pg, i) => {
        let v = String(i + 1);
        if (t >= tFmt && i === 1) v = "1";
        if (t >= tFmt && i === 2) v = "2";
        if (t >= tEx1 && i === 0) v = "i";
        if (t >= tEx3 && i === 2) v = "";
        const n = pg.querySelector(".num"); if (n.textContent !== v) n.textContent = v;
        n.style.color = (t >= tFmt && i === 1 && t < tFmt + 2) || (t >= tEx1 && i === 0 && t < tEx1 + 2) ? "#c00000" : "";
      }));
      pages.forEach((pg, i) => E.only(pg.querySelector(".sec"), S(14, 0) + i * 0.15 + (i ? 0 : 0), 1e9));

      // ---------------- rubans, menus, boîte
      W.tabAt([[0, "Accueil"], [S(3, 0.25), "Mise en page"], [tFoot, "En-tête et pied de page"], [S(11, 0.15), "Insertion"]]);
      E.track(t => { W.tabs["En-tête et pied de page"].style.display = t >= tFoot && t < tClose + 0.5 ? "" : "none"; });
      const mk = (anchor, html, a, b, dx) => { const m = $("div", "dd", W.layer); const [x, y] = SC.corner(anchor, W.root); Object.assign(m.style, { left: (x + (dx || 0)) + "px", top: (y + 34) + "px", width: "330px" }); m.innerHTML = html; E.show(m, a, b, 0.12); return m; };
      const brk = mk(el.breaks, `<div style="font-weight:600;color:#555;font-size:14px">Sauts de page</div><div>Page</div><div>Colonne</div><div style="font-weight:600;color:#555;font-size:14px;border-top:1px solid #ddd">Sauts de section</div><div class="ps"><b>Page suivante</b><br><small style="color:#666">La section commence sur la page suivante.</small></div><div>Continu</div><div>Page paire</div><div>Page impaire</div>`,
        S(3, 0.6), S(4, 0.05));
      E.cls(brk.querySelector(".ps"), "hl", S(3, 0.85), S(4, 0.05));
      const pn = mk(el.pgnum, `<div>Haut de page ›</div><div>Bas de page ›</div><div>Marges ›</div><div>Position actuelle ›</div><div class="fm">Format des numéros de page…</div><div>Supprimer les numéros de page</div>`,
        S(11, 0.55), S(12, 0.05), -180);
      E.cls(pn.querySelector(".fm"), "hl", S(11, 0.8), S(12, 0.05));
      const dlg = $("div", "dlg", W.layer, { left: "560px", top: "260px", width: "440px" });
      dlg.innerHTML = `<div class="dt">Format des numéros de page</div><div class="dbd">Format de nombre :<div class="fld fmtf" style="display:block;margin:4px 0 12px">1, 2, 3, …</div>
        <div style="font-weight:600;margin-bottom:4px">Numérotation des pages</div><div class="rad"><i></i>À la suite de la section précédente</div><div class="rad r2"><i></i>À partir de : <span class="fld" style="min-width:60px;margin:0 0 0 6px">1</span></div>
        <div style="text-align:right;margin-top:14px"><span class="btn pri ok">OK</span><span class="btn">Annuler</span></div></div>`;
      E.show(dlg, S(12, 0.1), tFmt, 0.2);
      E.cls(dlg.querySelector(".r2"), "on", S(12, 0.55), 999);
      E.cls(dlg.querySelector(".ok"), "hl", tFmt - 0.4, tFmt);

      // ---------------- curseur
      const endP1 = (() => { const [x, y] = SC.corner(pages[0], W.root); return [x + 260, y + 34 + 36 + 9 * 20]; })();
      const [f2x, f2y] = (() => { const [x, y] = SC.corner(ft2, W.root); return [x + 180, y + 30]; })();
      SC.tour(E, W.root, W.over, cur, [[0, [1200, 700], 0], [S(2, 0.5), endP1, 1.0, 1], [S(3, 0.3), W.tabs["Mise en page"], 0.8, 1], [S(3, 0.55), el.breaks, 0.4, 1],
        [S(4, 0.0), brk.querySelector(".ps"), 0.4, 1], [S(5, 0.3), [f2x - 120, f2y - 300], 0.8, 1], [S(5, 0.45), el.breaks, 0.5, 1],
        [tFoot - 0.05, [f2x, f2y], 1.0, 2], [tUnlink - 0.05, el.link, 0.8, 1], [S(11, 0.1), W.tabs.Insertion, 0.9, 1], [S(11, 0.5), el.pgnum, 0.5, 1],
        [S(11, 0.95), pn.querySelector(".fm"), 0.4, 1], [S(12, 0.5), dlg.querySelector(".r2 i"), 0.6, 1], [tFmt - 0.05, dlg.querySelector(".ok"), 0.6, 1], [S(14, 0.5), [1300, 740], 0.8]]);
      SC.cursorModes(E, cur, [[0, "arrow"], [S(2, 0.1), "ibeam"], [S(3, 0.1), "arrow"]]);

      // ---------------- caméra
      SC.screenCam(E, [[0, "room"], [S(0, 0.6), "room"], [S(1, 0.3), 800, 450, 1, 1.0], [S(2, 0.2), 330, 420, 1.7, 0.8], [S(2, 1), 330, 420, 1.7], [S(3, 0.3), 700, 200, 1.7, 0.7], [S(3, 1), 700, 200, 1.7],
        [S(4, 0.2), 800, 470, 1.05, 0.7], [S(6, 1), 800, 470, 1.05], [S(7, 0.3), 800, 640, 1.8, 0.8], [S(9, 1), 800, 640, 1.8], [S(10, 0.2), 800, 470, 1.05, 0.6], [S(11, 0.3), 700, 220, 1.6, 0.7], [S(11, 1), 700, 220, 1.6],
        [S(12, 0.3), 800, 420, 1.4, 0.6], [S(13, 0.5), 800, 420, 1.4], [S(13, 1), 800, 600, 1.0, 0.8], [S(17, 1) + 0.6, 800, 600, 1.0], [S(17, 1) + 1.8, "room", 1.2]]);

      // ---------------- cartes
      E.card(`Saut de section<small>Mise en page › Sauts de pages › Page suivante : la nouvelle section commence sur la page suivante.</small>`, S(3, 0.2), S(5, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "470px" });
      E.card(`« Lier au précédent »<div style="display:flex;justify-content:center;gap:14px;margin-top:14px;font-size:40px">&#128279;<span style="color:#c00000">&#10005;</span></div><small>Désactivé : chaque section a son propre pied de page.</small>`, S(8, 0), S(9, 1) + 0.2, { width: "420px" });
      E.card(`Commencer à 1<small>Insertion › Numéro de page › Format des numéros de page.</small>`, S(11, 0), S(13, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "430px" });
      E.card(`Exemple<div style="margin-top:12px;font-size:24px;font-weight:600;line-height:1.7"><div>Section 1 : <span style="color:#326fbf">i, ii, iii</span></div><div>Section 2 : <span style="color:#326fbf">1, 2, 3</span></div><div>Section 3 : <span style="color:#667386">aucun</span></div></div>`,
        S(14, 0), 999, { width: "400px" });
    }
  });
})();
