/* Leçon animée « Manipuler une fenêtre » (code-barres 2001) — voix de synthèse du téléphone. */
(function () {
  const T = window.T["2001"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Manipuler une fenêtre"], [1, "Déplacer"], [3, "Redimensionner"], [6, "Réduire, restaurer, fermer"], [10, "Disposer les fenêtres"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = E.desktop(E.set(), 1920, 1080);          // écran Windows plein cadre (coordonnées 1600 x 900)
      const cur = d.cursor;
      const geo = (el, list) => E.anim(list.map(x => [x[0], x.slice(1, 5), x[5]]),
        v => Object.assign(el.style, { left: v[0] + "px", top: v[1] + "px", width: v[2] + "px", height: v[3] + "px" }));
      const app = i => [589 + i * 54 + 22, 869];         // centre de l'icône i de la barre des tâches

      // ---------------- fenêtre principale « Documents »
      const w = E.makeWindow(d.layer, "Documents");
      const tMin = S(8, 0.3), tBack = S(8, 0.8), tMax = S(9, 0.12), tRest = S(9, 0.4), tClose = S(9, 0.8);
      E.show(w, -1, tClose + 0.1, 0.2);
      d.apps[2].classList.add("app-open");

      // déplacer : clic maintenu sur la barre de titre puis glisser
      const grabA = S(2, 0.25), grabB = S(2, 0.8);
      // redimensionner : coin inférieur droit
      const rsA = S(5, 0.1), rsB = S(5, 0.75);
      geo(w, [
        [0, 260, 140, 700, 430], [grabA, 260, 140, 700, 430], [grabB, 620, 230, 700, 430, grabB - grabA],
        [rsA, 620, 230, 700, 430], [rsB, 620, 230, 900, 560, rsB - rsA],
        [tMax, 620, 230, 900, 560], [tMax + 0.35, 0, 0, 1600, 838, 0.35], [tRest, 0, 0, 1600, 838], [tRest + 0.35, 620, 230, 900, 560, 0.35]
      ]);
      // réduire puis restaurer depuis la barre des tâches
      E.anim([[0, 0], [tMin, 0], [tMin + 0.4, 1, 0.4], [tBack, 1], [tBack + 0.4, 0, 0.4]], v => {
        w.style.transformOrigin = "50% 100%";
        w.style.transform = `translateY(${v * 420}px) scale(${1 - 0.9 * v})`;
        w.style.filter = `opacity(${1 - v})`;
      });
      E.anim([[0, 0], [tClose, 0], [tClose + 0.15, 1, 0.15]], v => { w.style.transform = v ? `scale(${1 - 0.06 * v})` : w.style.transform; });

      // boutons du coin supérieur droit
      E.cls(w.btn("min"), "hl", S(7, 0.2), S(7, 0.45));
      E.cls(w.btn("max"), "hl", S(7, 0.45), S(7, 0.7));
      E.cls(w.btn("close"), "hl", S(7, 0.7), S(7, 1));
      E.cls(w.btn("min"), "hl", tMin - 0.4, tMin + 0.1);
      E.cls(w.btn("max"), "hl", tMax - 0.3, tMax + 0.1);
      E.cls(w.btn("max"), "hl", tRest - 0.3, tRest + 0.1);
      E.cls(w.btn("close"), "hl", tClose - 0.4, tClose + 0.15);
      E.cls(d.apps[2], "hl", tBack - 0.5, tBack + 0.2);

      // positions des boutons (fenêtre de 900 x 560 en 620, 230 ; boutons de 62 px)
      const bx = n => 620 + 900 - 62 * (3 - n) + 31, by = 230 + 23;
      const mx = n => 1600 - 62 * (3 - n) + 31, my = 23;
      const [ax, ay] = app(2);

      // ---------------- deuxième temps : disposer plusieurs fenêtres
      const lay = S(10, 0);
      const w1 = E.makeWindow(d.layer, "Rapport.docx", { explorer: false });
      const w2 = E.makeWindow(d.layer, "Budget.xlsx", { explorer: false });
      w1.body.innerHTML = `<div style="margin:40px auto;width:70%;height:80%;background:#fff;box-shadow:0 0 0 1px #dde3ec">${"<p style='height:12px;background:#e3e8f0;margin:22px 30px;border-radius:6px'></p>".repeat(8)}</div>`;
      w1.body.style.background = "#eef1f6"; w1.querySelector(".tb").style.background = "#2b579a"; w1.querySelector(".tb").style.color = "#fff";
      w2.body.style.background = `repeating-linear-gradient(0deg,#fff 0 30px,#e3e8f0 30px 31px),repeating-linear-gradient(90deg,transparent 0 110px,#e3e8f0 110px 111px)`;
      w2.body.style.backgroundBlendMode = "multiply";
      w2.querySelector(".tb").style.background = "#1d6f42"; w2.querySelector(".tb").style.color = "#fff";
      E.show(w1, lay, 999, 0.3); E.show(w2, lay + 0.15, 999, 0.3);
      const side = S(12, 0.35), stack = S(12, 0.75);
      geo(w1, [[0, 200, 110, 820, 520], [side, 200, 110, 820, 520], [side + 0.5, 0, 0, 800, 838, 0.5], [stack, 0, 0, 800, 838], [stack + 0.5, 0, 0, 1600, 419, 0.5]]);
      geo(w2, [[0, 520, 250, 820, 520], [side, 520, 250, 820, 520], [side + 0.5, 800, 0, 800, 838, 0.5], [stack, 800, 0, 800, 838], [stack + 0.5, 0, 419, 1600, 419, 0.5]]);

      const menu = E.makeMenu(d.over, ["Afficher les fenêtres en cascade", "Afficher les fenêtres empilées", "Afficher les fenêtres côte à côte", "Afficher le bureau", "-", "Gestionnaire des tâches", "Paramètres de la barre des tâches"]);
      Object.assign(menu.style, { left: "1060px", top: "540px" });
      const mA = S(11, 0.5);
      E.show(menu, mA, stack, 0.15);
      E.cls(menu.item(2), "hl", S(12, 0.15), side + 0.1);
      E.cls(menu.item(1), "hl", side + 0.1, stack);
      const mi = i => [1160, 540 + 8 + 44 * i + 22];
      const m2 = mi(2), m1 = mi(1);

      // ---------------- curseur
      E.move(cur, [
        [0, 1100, 600], [S(1, 0.1), 1100, 600],
        [grabA, 500, 160, S(2, 0.25) - S(1, 0.3)], [grabB, 860, 250, grabB - grabA],
        [S(4, 0.15), 1100, 500], [S(4, 0.7), 1320, 660, 1.0], [rsA, 1320, 660], [rsB, 1520, 790, rsB - rsA],
        [S(7, 0.15), bx(0), by, 0.8], [S(7, 0.45), bx(1), by, 0.3], [S(7, 0.7), bx(2), by, 0.3],
        [tMin - 0.3, bx(0), by, 0.5], [tMin + 0.2, bx(0), by], [tBack - 0.15, ax, ay, 0.6], [tBack + 0.3, ax, ay],
        [tMax - 0.1, bx(1), by, 0.5], [tMax + 0.1, bx(1), by], [tRest - 0.1, mx(1), my, 0.3], [tRest + 0.3, mx(1), my],
        [tClose - 0.15, bx(2), by, 0.5], [tClose + 0.2, bx(2), by],
        [mA - 0.6, 1060, 875, 0.8], [mA, 1060, 875], [S(12, 0.15), m2[0], m2[1], 0.6], [side, m2[0], m2[1]],
        [side + 0.1, m1[0], m1[1], 0.1], [999, m1[0], m1[1]]
      ]);
      // curseur de redimensionnement (flèche double) près du bord
      E.track(t => {
        const rs = t >= S(4, 0.7) && t <= rsB + 0.3;
        if (cur.dataset.mode !== (rs ? "r" : "a")) {
          cur.dataset.mode = rs ? "r" : "a";
          cur.innerHTML = rs ? E.RESIZE : E.ARROW;
          cur.style.margin = rs ? "-27px 0 0 -27px" : "0";
        }
      });
      E.click(d.over, grabA, 500, 160);
      E.ring(d.over, 860, 253, 40, grabA, grabB);
      E.ring(d.over, 1320, 660, 46, S(4, 0.7), rsA + 0.2);
      E.click(d.over, rsA, 1320, 660);
      E.click(d.over, tMin, bx(0), by);
      E.click(d.over, tBack, ax, ay);
      E.click(d.over, tMax, bx(1), by);
      E.click(d.over, tRest, mx(1), my);
      E.click(d.over, tClose, bx(2), by);
      E.click(d.over, mA, 1060, 875);
      E.click(d.over, side, m2[0], m2[1]);
      E.click(d.over, stack, m1[0], m1[1]);

      // ---------------- caméra
      E.camera([[0, 800, 450, 1], [S(1, 0), 800, 450, 1], [S(2, 0.1), 900, 330, 1.35, 0.8], [S(2, 1), 900, 330, 1.35],
        [S(4, 0.2), 1420, 720, 1.5, 0.9], [S(5, 1), 1420, 720, 1.5], [S(7, 0.05), 1600, 340, 1.8, 0.8], [S(7, 1), 1600, 340, 1.8],
        [S(8, 0.1), 800, 450, 1, 0.7], [S(10, 1), 800, 450, 1], [S(11, 0.2), 1300, 760, 1.5, 0.7], [S(12, 0.3), 1300, 760, 1.5], [S(12, 0.45), 800, 450, 1, 0.6]].map(k => [k[0], k[1] * 1.2, k[2] * 1.2, k[3], k[4]]));  // coordonnées du bureau x 1,2

      // ---------------- cartes
      E.card(`Déplacer<div style="display:flex;justify-content:center;margin:14px 0">${E.MOVE.replace('width="54" height="54"', 'width="110" height="110"')}</div>
        <small>Maintenez le clic sur la barre de titre et glissez.</small>`, S(1, 0), S(2, 1) + 0.2, { width: "380px" });
      E.card(`Redimensionner<div style="display:flex;justify-content:center;margin:14px 0">${E.RESIZE.replace('width="54" height="54"', 'width="110" height="110"')}</div>
        <small>Sur un coin ou un bord : la flèche double apparaît.</small>`, S(3, 0), S(5, 1) + 0.2, { left: "40px", right: "auto", width: "380px" });
      E.card(`Les trois boutons
        <div style="display:flex;flex-direction:column;gap:12px;margin-top:16px;font-size:24px;font-weight:600">
          <div><b style="display:inline-block;width:54px;text-align:center;background:#eef1f6;border-radius:8px">&#8212;</b> Réduire</div>
          <div><b style="display:inline-block;width:54px;text-align:center;background:#eef1f6;border-radius:8px">&#9744;</b> Agrandir / restaurer</div>
          <div><b style="display:inline-block;width:54px;text-align:center;background:#e81123;color:#fff;border-radius:8px">&#10005;</b> Fermer</div></div>`,
        S(6, 0), S(9, 1) + 0.2, { left: "40px", right: "auto", width: "420px" });
      E.card(`Côte à côte ou empilées
        <svg width="320" height="150" viewBox="0 0 320 150" style="margin-top:14px">
          <rect x="4" y="4" width="146" height="142" rx="10" fill="#e8eef7" stroke="#326fbf" stroke-width="4"/>
          <line x1="77" y1="8" x2="77" y2="142" stroke="#326fbf" stroke-width="4"/>
          <rect x="170" y="4" width="146" height="142" rx="10" fill="#e8eef7" stroke="#d9822b" stroke-width="4"/>
          <line x1="174" y1="75" x2="312" y2="75" stroke="#d9822b" stroke-width="4"/></svg>`, S(10, 0), 999, { left: "40px", right: "auto", width: "380px" });
    }
  });
})();
