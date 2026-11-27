/* Leçon animée « Faire une présentation dans PowerPoint » (code P001, vidéo pointpresentation). */
(function () {
  const T = window.T["P001"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Faire une présentation"], [2, "La diapositive titre"], [5, "Ajouter des diapositives"], [8, "Structurer"], [13, "Texte et images"], [16, "Animations et transitions"], [19, "Le design"], [21, "Vérifier, enregistrer, présenter"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {}); d.led.classList.add("on");
      const P = SC.ppt(E, d.screen, 1076, 605, "Formation 2026");
      // ---------------- contenu des diapositives selon le temps
      const tTitle = [S(3, 0.2), S(3, 0.9)], tSub = [S(4, 0.2), S(4, 0.9)], tNew = S(7, 0.7), tStruct = S(9, 0.1), tBul = [S(14, 0.1), S(14, 0.9)], tImg = S(15, 0.8), tTheme = S(20, 0.7), tShow = S(25, 0.5);
      const theme = t => t >= tTheme ? { bg: "#1f3864", fg: "#fff", ac: "#f4b83a" } : { bg: "#fff", fg: "#1f3864", ac: "#c43e1c" };
      const typed = (txt, a, t) => txt.slice(0, Math.max(0, Math.min(txt.length, Math.round((t - a[0]) / (a[1] - a[0]) * txt.length))));
      const slideHtml = (k, t) => {
        const th = theme(t);
        const base = `position:absolute;inset:0;background:${th.bg};color:${th.fg}`;
        if (k === 0) return `<div style="${base}"><div style="position:absolute;left:80px;right:80px;top:190px;text-align:center;font-size:58px;font-weight:700">${t >= tTitle[0] ? typed("Formation informatique 2026", tTitle, t) : `<span style="color:#aaa;font-weight:400;font-size:40px">Cliquez pour ajouter un titre</span>`}</div>
          <div style="position:absolute;left:80px;right:80px;top:310px;text-align:center;font-size:30px;color:${th.ac}">${t >= tSub[0] ? typed("Association HELP-ME · Niamey", tSub, t) : `<span style="color:#aaa;font-size:26px">Cliquez pour ajouter un sous-titre</span>`}</div></div>`;
        if (k > 4) return `<div style="${base}"></div>`;
        const titles = ["", "Introduction", "Les ateliers", "Les résultats", "Conclusion"];
        const bullets = [[], ["Objectif : apprendre l’informatique", "Pour qui : jeunes et adultes", "Durée : 3 mois"], ["Word et Excel", "Internet et e-mail", "Intelligence artificielle"], ["120 personnes formées", "8 villes"], ["Rejoignez la prochaine session !", "Inscription : help-me.ne"]];
        const list = k === 1 ? bullets[1].map((b, i) => i === 0 ? typed(b, tBul, t) : t >= tBul[1] ? b : "").filter(Boolean) : bullets[k];
        const img = k === 1 && t >= tImg ? `<div style="position:absolute;right:60px;top:150px;width:300px;height:220px;border-radius:12px;background:linear-gradient(180deg,#ffb35c,#ffe3b0 55%,#3b82c4 56%);box-shadow:0 6px 16px rgba(0,0,0,.25)"></div>` : "";
        return `<div style="${base}"><div style="position:absolute;left:60px;top:40px;font-size:46px;font-weight:700">${titles[k]}</div><div style="position:absolute;left:70px;top:140px;font-size:28px;line-height:1.8;color:${th.fg}">${list.map(b => `• ${b}`).join("<br>")}</div>${img}</div>`;
      };
      E.track(t => {
        const n = t < tNew ? 1 : t < tStruct ? 2 : 5;
        const cur = t < tNew ? 0 : t < S(13, 0) ? (t < tStruct ? 1 : Math.min(4, 1 + Math.floor((t - tStruct) / ((S(12, 1) - tStruct) / 4)))) : 1;
        const key = [n, cur, Math.round(t * 4)].join("|");
        if (P._k === key) return; P._k = key;
        P.slide.innerHTML = slideHtml(cur, t);
        P.thumbs.forEach((th, i) => { th.style.display = i < n ? "" : "none"; th.classList.toggle("on", i === cur); th.innerHTML = `<i>${i + 1}</i><div style="position:absolute;left:0;top:0;width:1000px;height:562px;transform:scale(.196);transform-origin:0 0">${slideHtml(i, t)}</div>`; });
        const st = P.root.querySelector(".status span"); const v = `Diapositive ${cur + 1} de ${n}`; if (st.textContent !== v) st.textContent = v;
      });
      // ---------------- démarrage : modèles
      const B = SC.backstage(E, P, -1, S(1, 0.9));
      B.main.innerHTML = `<h3>Bonjour</h3><div style="display:flex;gap:20px">${[["Nouvelle présentation", "#fff"], ["Thème Niger", "#1f3864"], ["Thème Sahel", "#d9822b"], ["Thème Fleuve", "#2b7cd3"]].map(([n, c], i) => `<div class="tp tp${i}" style="text-align:center;font-size:16px"><div style="width:220px;height:124px;border:1px solid #ccc;background:${c};margin-bottom:6px"></div>${n}</div>`).join("")}</div>`;
      B.item("Accueil").classList.add("hl");
      // ---------------- diaporama (F5)
      E.only(P.showLayer, tShow, 1e9);
      const sl = $("div", "slide", P.showLayer);
      E.track(t => { if (t < tShow) return; const h = slideHtml(0, t); if (sl._h !== h) { sl.innerHTML = h; sl._h = h; } });
      const dd = (items, hl) => ({ items, hl, w: 300 });
      SC.beats(E, P, [
        { s: 1, f: 0.5, go: () => B.main.querySelector(".tp0"), cam: [700, 330, 1.4], card: { html: `Modèle ou présentation vierge<small>Un modèle donne déjà des couleurs et des polices assorties.</small>`, until: 1 } },
        { s: 2, f: 0.3, go: () => P.slide, clicks: 0, cam: [900, 520, 1.25] },
        { s: 3, f: 0.1, go: [920, 470], dur: 0.6, card: { html: `Un titre accrocheur<small>Puis votre nom ou celui de votre organisation.</small>`, until: 4 } },
        { s: 4, f: 0.1, go: [920, 560], dur: 0.5 },
        { s: 6, f: 0.4, go: "Nouvelle diapositive", cam: [420, 210, 1.8] },
        { s: 7, f: 0.1, go: "Nouvelle diapositive", clicks: 0, menu: { items: [`<div style="display:grid;grid-template-columns:repeat(3,90px);gap:8px;padding:6px;font-size:12px;text-align:center">${["Titre", "Titre et contenu", "Deux contenus", "Comparaison", "Titre seul", "Vide"].map((n, i) => `<div style="${i === 1 ? "outline:2px solid #c43e1c;" : ""}"><div style="height:52px;border:1px solid #ccc;margin-bottom:3px;background:#fff"></div>${n}</div>`).join("")}</div>`], w: 330 }, until: 7, uf: 0.75 },
        { s: 8, f: 0.2, go: () => P.thumbs[0], clicks: 0, cam: [800, 450, 1.0], card: { html: `Le plan<div style="margin-top:12px;font-size:24px;font-weight:600;line-height:1.6">1. Introduction<br>2. Le corps (plusieurs diapositives)<br>3. La conclusion</div>`, until: 12 } },
        { s: 9, f: 0.3, go: () => P.thumbs[1], dur: 0.6 },
        { s: 10, f: 0.3, go: () => P.thumbs[2], dur: 0.6 },
        { s: 11, f: 0.4, go: () => P.thumbs[3], dur: 0.6 },
        { s: 12, f: 0.3, go: () => P.thumbs[4], dur: 0.6 },
        { s: 14, f: 0.05, go: () => P.thumbs[1], cam: [900, 520, 1.25] },
        { s: 14, f: 0.3, go: [700, 470], dur: 0.5 },
        { s: 15, f: 0.3, tab: "Insertion", go: "Image", cam: [560, 210, 1.8], menu: { items: ["Cet appareil…", "Images de stock…", "Images en ligne…"], hl: 0, w: 260 }, until: 15, uf: 0.75 },
        { s: 17, f: 0.3, tab: "Animations", go: "Estompé", cam: [700, 210, 1.7] },
        { s: 18, f: 0.3, tab: "Transitions", go: "Fondu" },
        { s: 20, f: 0.3, tab: "Création", go: () => P.rb["Création"].querySelectorAll(".th6 > div")[1], menu: null },
        { s: 21, f: 0.3, tab: "Révision", go: "Orthographe", cam: [820, 380, 1.3], dialog: { title: "Grammaire et orthographe", x: 640, y: 320, w: 460, html: `Aucune erreur trouvée.<br><small style="color:#666">Relisez aussi chaque diapositive : taille du texte, contraste.</small>` }, until: 22, uf: 0.9 },
        { s: 24, f: 0.2, keys: ["Enregistrer", "Ctrl", "S"], cam: [800, 450, 1.0] },
        { s: 25, f: 0.2, keys: ["Diaporama", "F5"] },
      ], { last: 25 });
    }
  });
})();
