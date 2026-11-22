/* Leçon animée « Masquer la barre des tâches » (code-barres 2002) — voix de synthèse du téléphone. */
(function () {
  const T = window.T["2002"];
  const GEAR = `<svg viewBox="0 0 52 52" width="52" height="52"><rect width="52" height="52" rx="12" fill="#5a6b80"/><g transform="translate(26 26)" fill="none" stroke="#fff" stroke-width="4">
    <circle r="7"/><path d="M0-16v5M0 11v5M-16 0h5M11 0h5M-11-11l3.5 3.5M7.5 7.5L11 11M-11 11l3.5-3.5M7.5-7.5L11-11"/></g></svg>`;
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "La barre des tâches"], [1, "Clic droit"], [2, "Paramètres"], [3, "Masquer automatiquement"], [4, "Mode tablette"],
            [5, "Zone de notification"], [6, "Démarrer › Paramètres"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = E.desktop(E.set(), 1920, 1080);          // coordonnées du bureau : 1600 x 900
      const cur = d.cursor, tb = d.taskbar;

      // ---------------- menu contextuel de la barre des tâches
      const menu = E.makeMenu(d.over, ["Paramètres de la barre des tâches"]);
      Object.assign(menu.style, { left: "1000px", top: "770px" });
      const rc = S(1, 0.45), pick = S(2, 0.45);
      E.show(menu, rc, pick + 0.15, 0.15);
      E.cls(menu.item(0), "hl", S(2, 0.1), pick + 0.15);

      // ---------------- fenêtre Paramètres
      const w = E.makeWindow(d.layer, "Paramètres", { explorer: false });
      Object.assign(w.style, { left: "170px", top: "60px", width: "1260px", height: "740px" });
      w.body.innerHTML = `<div class="settings"><div class="nav"><div>Système</div><div>Bluetooth et appareils</div><div>Réseau et Internet</div>
        <div class="on">Personnalisation</div><div>Applications</div><div>Comptes</div><div>Heure et langue</div><div>Accessibilité</div></div>
        <div class="main"><h2>Personnalisation › Barre des tâches</h2>
          <div class="grp"><div class="hd">Éléments de la barre des tâches</div></div>
          <div class="grp"><div class="hd">Icônes de la zone de notification</div></div>
          <div class="grp"><div class="hd">Comportement de la barre des tâches</div>
            <div class="opt">Alignement de la barre des tâches : Centre</div>
            <div class="opt a1">Masquer automatiquement la barre des tâches en mode bureau<span class="toggle"></span></div>
            <div class="opt a2">Masquer automatiquement la barre des tâches en mode tablette<span class="toggle"></span></div>
            <div class="opt">Afficher mon badge sur les applications de la barre des tâches</div></div></div></div>`;
      const openAt = pick + 0.3, closeAt = S(5, 0.05);
      E.show(w, openAt, closeAt, 0.3);
      const o1 = w.querySelector(".a1"), o2 = w.querySelector(".a2");
      const on1 = S(3, 0.7), on2 = S(4, 0.45);
      E.cls(o1, "hl", S(3, 0.35), S(3, 1));
      E.cls(o1.querySelector(".toggle"), "on", on1, 999);
      E.cls(o2, "hl", S(4, 0.15), S(4, 1));
      E.cls(o2.querySelector(".toggle"), "on", on2, 999);

      // la barre des tâches se cache, puis réapparaît quand le pointeur touche le bas de l'écran
      const hideA = on1 + 0.4, peek = S(3, 1) + 0.2, back = S(5, 0);
      E.anim([[0, 0], [hideA, 0], [hideA + 0.4, 1, 0.4], [peek, 1], [peek + 0.3, 0, 0.3], [peek + 1.2, 0], [peek + 1.5, 1, 0.3], [back, 1], [back + 0.3, 0, 0.3]],
        v => { tb.style.transform = `translateY(${v * 58}px)`; });

      // ---------------- menu Démarrer
      const sm = $("div", "startmenu", d.over);
      const APPS = [["Edge", "#1f8fd8"], ["Word", "#2b579a"], ["Excel", "#1d6f42"], ["PowerPoint", "#c43e1c"], ["Courrier", "#3a7bd5"], ["Calendrier", "#2e86de"],
        ["Photos", "#3aa0f5"], ["Paramètres", ""], ["Calculatrice", "#5a6b80"], ["Horloge", "#7a5cd6"], ["Bloc-notes", "#4d8fcc"], ["Explorateur", "#f4b83a"]];
      sm.innerHTML = `<div class="search">Rechercher des applications, des paramètres et des documents</div><div class="ttl">Épinglé</div><div class="grid">${
        APPS.map(([n, c]) => `<div>${c ? `<b style="background:${c}"></b>` : `<b style="background:none">${GEAR}</b>`}${n}</div>`).join("")}</div>`;
      const st = S(6, 0.25), gear = S(6, 0.75);
      E.show(sm, st, gear + 0.6, 0.2);
      const gEl = sm.querySelectorAll(".grid div")[7];
      E.cls(gEl, "hl", S(6, 0.55), 999);
      E.cls(d.apps[0], "hl", st - 0.3, st + 0.3);
      const w2 = E.makeWindow(d.layer, "Paramètres", { explorer: false });
      Object.assign(w2.style, { left: "170px", top: "60px", width: "1260px", height: "740px" });
      w2.body.innerHTML = w.body.innerHTML;
      w2.querySelector(".main").innerHTML = "<h2>Système</h2>" + ["Écran", "Son", "Notifications", "Alimentation et batterie", "Stockage", "Multitâche", "Presse-papiers"]
        .map(n => `<div class="grp"><div class="hd">${n}</div></div>`).join("");
      w2.querySelector(".nav .on").classList.remove("on"); w2.querySelector(".nav div").classList.add("on");
      E.show(w2, gear + 0.4, 999, 0.3);

      // ---------------- curseur
      const [sx, sy] = [589 + 22, 869];                     // bouton Démarrer
      const gx = 400 + 40 + (720 / 6) * 1.5, gy = 900 - 74 - 640 + 30 + 70 + 32 + 18 + 62 + 92;
      E.move(cur, [[0, 900, 450], [S(1, 0.05), 900, 450], [rc - 0.1, 1000, 872, 0.9], [rc, 1000, 872],
        [S(2, 0.1), 1100, 795, 0.5], [pick + 0.2, 1100, 795],
        [S(3, 0.3), 700, 600, 0.9], [on1 - 0.3, 1330, 492 + 0, 0.8], [on1 + 0.3, 1330, 492],
        [peek - 0.2, 1000, 895, 0.7], [peek + 1.0, 1000, 895], [peek + 1.4, 1000, 700, 0.4],
        [S(4, 0.1), 1330, 550, 0.6], [on2 + 0.3, 1330, 550],
        [S(5, 0.3), 1470, 880, 0.8], [st - 0.3, sx, sy, 0.8], [st, sx, sy], [S(6, 0.55), gx, gy, 0.7], [999, gx, gy]]);
      E.click(d.over, rc, 1000, 872);
      E.click(d.over, pick, 1100, 795);
      E.click(d.over, on1, 1330, 492);
      E.click(d.over, on2, 1330, 550);
      E.click(d.over, st, sx, sy);
      E.click(d.over, gear, gx, gy);
      E.ring(d.over, 1436, 869, 70, S(5, 0.2), S(5, 1) + 0.2);      // zone de notification (icônes près de l'horloge)
      E.ring(d.over, 800, 869, 60, S(0, 0.3), S(0, 1));

      // ---------------- caméra (coordonnées du bureau x 1,2)
      E.camera([[0, 800, 450, 1], [S(0, 0.2), 800, 450, 1], [S(1, 0.2), 1050, 760, 1.6, 0.8], [S(2, 1), 1050, 760, 1.6],
        [S(3, 0.1), 900, 480, 1.25, 0.8], [S(4, 1), 900, 480, 1.25], [S(5, 0.1), 1380, 800, 1.8, 0.8], [S(5, 1), 1380, 800, 1.8],
        [S(6, 0.15), 800, 450, 1, 0.7]].map(k => [k[0], k[1] * 1.2, k[2] * 1.2, k[3], k[4]]));

      // ---------------- cartes
      E.card(`La barre des tâches<small>En bas de l’écran : bouton Démarrer, applications, zone de notification et horloge.</small>`, S(0, 0.1), S(0, 1) + 0.2, { width: "420px" });
      E.card(`Masquer automatiquement<div style="display:flex;align-items:center;gap:18px;margin-top:18px"><span class="toggle on" style="transform:scale(1.4);margin:8px"></span>
        <small style="margin:0">La barre se cache et réapparaît quand le pointeur touche le bas de l’écran.</small></div>`, S(3, 0.5), S(3, 1) + 1.4, { left: "40px", right: "auto", width: "460px" });
      E.card(`Mode tablette<svg width="260" height="170" viewBox="0 0 260 170" style="margin-top:12px"><rect x="10" y="10" width="240" height="150" rx="18" fill="#1b2330"/>
        <rect x="24" y="22" width="212" height="126" rx="6" fill="#9cc3f0"/><circle cx="130" cy="95" r="16" fill="#ffcfac" stroke="#c98a6e" stroke-width="3"/></svg>
        <small>Pour les appareils à écran tactile.</small>`, S(4, 0.05), S(4, 1) + 0.2, { left: "40px", right: "auto", width: "340px" });
      E.card(`Démarrer › Paramètres<div style="display:flex;gap:16px;align-items:center;margin-top:16px">${GEAR.replace(/width="52" height="52"/, 'width="80" height="80"')}
        <small style="margin:0">L’icône en forme d’engrenage ouvre les Paramètres.</small></div>`, S(6, 0.6), 999, { width: "420px" });
    }
  });
})();
