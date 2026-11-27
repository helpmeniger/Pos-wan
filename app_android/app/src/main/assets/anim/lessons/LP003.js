/* Leçon animée « Finaliser et présenter un diaporama » (code P003, vidéo pointfinal). */
(function () {
  const T = window.T["P003"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Finaliser le diaporama"], [1, "Relire"], [4, "Un design uniforme"], [7, "Le multimédia"], [10, "Transitions et animations"], [17, "Configurer le diaporama"], [21, "Les notes"], [24, "Tester"], [27, "Les minutages"], [29, "Créer une vidéo"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {}); d.led.classList.add("on");
      const P = SC.ppt(E, d.screen, 1076, 605, "Formation 2026");
      const tFix = S(3, 0.6), tTheme = S(6, 0.6);
      const slide = t => { const dark = t >= tTheme; return `<div style="position:absolute;inset:0;background:${dark ? "#1f3864" : "#fff"};color:${dark ? "#fff" : "#1f3864"}"><div style="position:absolute;left:60px;top:40px;font-size:46px;font-weight:700">Nos ${t >= tFix ? "formations" : `<span style="text-decoration:underline wavy #e00">formasions</span>`}</div>
        <div style="position:absolute;left:70px;top:150px;font-size:28px;line-height:1.9">• Word et Excel<br>• Internet<br>• <span style="color:${dark ? "#9cc8ff" : "#0563c1"};text-decoration:underline">Inscription en ligne</span></div>
        <div style="position:absolute;right:60px;top:150px;width:360px;height:220px;background:#111;border-radius:10px;display:flex;align-items:center;justify-content:center"><div style="border-left:44px solid #fff;border-top:26px solid transparent;border-bottom:26px solid transparent"></div></div></div>`; };
      E.track(t => {
        const h = slide(t), k = h;
        if (P._k === k) return; P._k = k;
        P.slide.innerHTML = h;
        P.thumbs.forEach((th, i) => { th.style.display = i < 4 ? "" : "none"; th.classList.toggle("on", i === 1); th.innerHTML = `<i>${i + 1}</i><div style="position:absolute;left:0;top:0;width:1000px;height:562px;transform:scale(.196);transform-origin:0 0">${slide(t)}</div>`; });
      });
      // notes du présentateur
      const tNotes = [S(22, 0.3), S(22, 0.95)];
      E.track(t => { const txt = "Saluer le public · présenter les 3 formations · montrer la vidéo"; const n = t < tNotes[0] ? -1 : Math.round(Math.min(1, (t - tNotes[0]) / (tNotes[1] - tNotes[0])) * txt.length); const v = n < 0 ? "Cliquez pour ajouter des commentaires" : txt.slice(0, n); if (P.notes.textContent !== v) { P.notes.textContent = v; P.notes.style.color = n < 0 ? "#888" : "#1b2330"; } });
      // mode présentateur
      const pres = $("div", "abs", P.root, { inset: "0", background: "#202124", zIndex: 45, color: "#fff", fontFamily: "Segoe UI, Roboto, sans-serif" });
      pres.innerHTML = `<div style="position:absolute;left:40px;top:40px;font-size:22px;opacity:.8">Mode Présentateur · 00:02:15</div><div class="cur" style="position:absolute;left:40px;top:90px;width:960px;height:540px;transform-origin:0 0"></div>
        <div style="position:absolute;left:1040px;top:90px;font-size:20px;opacity:.8">Diapositive suivante</div><div style="position:absolute;left:1040px;top:125px;width:500px;height:281px;background:#fff;color:#1f3864;font-size:24px;padding:20px;font-weight:700">Conclusion</div>
        <div style="position:absolute;left:1040px;top:440px;right:40px;font-size:26px;line-height:1.5;background:#2d2e31;padding:20px;border-radius:8px">Saluer le public · présenter les 3 formations · montrer la vidéo</div>`;
      E.only(pres, S(23, 0.3), S(23, 1) + 0.2);
      const pc = pres.querySelector(".cur");
      E.track(t => { if (pc._h) return; pc.innerHTML = `<div style="position:relative;width:1000px;height:562px;transform:scale(.96);transform-origin:0 0">${slide(999)}</div>`; pc._h = 1; });
      // diaporama (F5) et répétition des minutages
      const tShow = S(25, 0.6), tShowEnd = S(26, 1) + 0.3;
      E.only(P.showLayer, tShow, tShowEnd);
      const sl = $("div", "slide", P.showLayer); sl.innerHTML = slide(999);
      const rec = $("div", "abs", P.root, { left: "40px", top: "40px", background: "#fff", borderRadius: "8px", boxShadow: "0 6px 20px rgba(0,0,0,.3)", padding: "10px 18px", fontSize: "22px", zIndex: 46 });
      rec.innerHTML = `Enregistrement &nbsp; ▶ &nbsp; ❚❚ &nbsp; <b class="tm">0:00:00</b> &nbsp; ⟲`;
      const tRec = S(28, 0.7);
      E.only(rec, tRec, S(28, 1) + 1.5);
      E.track(t => { const s = Math.max(0, Math.floor((t - tRec) * 1)); const v = `0:00:${String(s).padStart(2, "0")}`; const e = rec.querySelector(".tm"); if (e.textContent !== v) e.textContent = v; });
      // volet Animation
      const pane = $("div", "pane", P.root); pane.style.top = "196px"; pane.style.width = "360px";
      pane.innerHTML = `<h4>Volet Animation</h4>${["1 ★ Titre 1 · Estompé", "2 ★ Zone de texte · Balayer", "3 ▶ Vidéo · Lecture"].map(x => `<div style="padding:8px 10px;border:1px solid #ddd;border-radius:6px;margin-bottom:6px">${x}</div>`).join("")}<div style="margin-top:10px;font-size:14px;color:#666">Glissez pour changer l’ordre · Minutage : Après la précédente</div>`;
      E.show(pane, S(14, 0.5), S(15, 1), 0.2);
      // Fichier › Exporter › Créer une vidéo
      const tExp = S(30, 0.3);
      const B = SC.backstage(E, P, tExp, 1e9);
      B.main.innerHTML = `<h3>Exporter</h3><div class="tile"><i>&#128196;</i><div><b>Créer un document PDF/XPS</b></div></div><div class="tile vid"><i>&#9654;</i><div><b>Créer une vidéo</b><br><small style="color:#666">Qualité Full HD · durée de chaque diapositive : 5 s</small></div></div>`;
      E.cls(B.item("Exporter"), "hl", tExp + 0.4, 1e9); E.cls(B.main.querySelector(".vid"), "hl", S(30, 0.8), 1e9);
      const gal = n => () => [...P.rb.Transitions.querySelectorAll(".sty > div")].find(x => x.textContent.includes(n));
      SC.beats(E, P, [
        { s: 2, f: 0.3, tab: "Révision", go: "Orthographe", cam: [420, 210, 1.8] },
        { s: 3, f: 0.05, dialog: { title: "Orthographe", x: 1000, y: 230, w: 460, html: `Mot inconnu : <b style="color:#c00">formasions</b><div style="margin:10px 0 4px">Suggestion :</div><div class="fld" style="display:block;background:#dbe6f6">formations</div><div style="margin-top:10px"><span class="btn">Ignorer</span><span class="btn pri">Modifier</span></div>`, buttons: false }, until: 3, uf: 0.7, cam: [1000, 380, 1.3] },
        { s: 5, f: 0.2, go: () => P.thumbs[0], clicks: 0, cam: [800, 450, 1.0], card: { html: `Un design uniforme<small>Mêmes couleurs, polices et alignements sur toutes les diapositives.</small>`, until: 5 } },
        { s: 6, f: 0.4, tab: "Création", go: () => P.rb["Création"].querySelectorAll(".th6 > div")[1], cam: [440, 200, 1.8] },
        { s: 8, f: 0.3, go: [1150, 480], clicks: 1, cam: [880, 460, 1.3], card: { html: `Vérifier les médias<small>Lancez chaque vidéo et chaque son, cliquez sur chaque lien.</small>`, until: 9 } },
        { s: 9, f: 0.4, go: [520, 610], clicks: 1 },
        { s: 11, f: 0.4, tab: "Transitions", go: gal("Fondu"), cam: [600, 200, 1.7] },
        { s: 12, f: 0.3, go: gal("Balayer"), dur: 0.5 },
        { s: 12, f: 0.7, go: gal("Cube"), dur: 0.5 },
        { s: 13, f: 0.4, tab: "Animations", go: "Estompé" },
        { s: 14, f: 0.4, go: "Volet Animation", cam: [1150, 420, 1.4] },
        { s: 15, f: 0.2, cam: [800, 450, 1.0], card: { html: `Avec modération<small>Des effets discrets et professionnels : trop d’effets distraient le public.</small>`, until: 16 } },
        { s: 18, f: 0.4, tab: "Diaporama", go: "Configurer le diaporama", cam: [560, 210, 1.8] },
        { s: 19, f: 0.05, dialog: { title: "Paramètres du diaporama", x: 560, y: 230, w: 560, html: `Type de diaporama :<div class="rad r1 on" style="margin-top:8px"><i></i>Présenté par un orateur (plein écran)</div><div class="rad r3"><i></i>Visionné sur une borne (plein écran)</div><div style="margin-top:10px"><span class="chk c1"><i></i>Exécuter en continu jusqu’à ÉCHAP</span></div>`, okAt: 20, okF: 0.85 }, until: 20, uf: 0.95, cam: [820, 420, 1.45],
          run: (t, app, b) => { const r1 = b._dialog.querySelector(".r1"), r3 = b._dialog.querySelector(".r3"), c1 = b._dialog.querySelector(".c1"); E.cls(r1, "on", 0, S(20, 0.3)); E.cls(r3, "on", S(20, 0.3), 1e9); E.cls(c1, "on", S(20, 0.4), 1e9); } },
        { s: 22, f: 0.2, go: () => P.notes, cam: [900, 760, 1.6], card: { html: `Les notes<small>Visibles seulement par vous en mode Présentateur, pas par le public.</small>`, until: 23 } },
        { s: 23, f: 0.25, cam: [800, 450, 1.0] },
        { s: 25, f: 0.3, go: () => P.el.showBtn, cam: [1300, 860, 2.0], keys: ["Diaporama", "F5"] },
        { s: 26, f: 0.1, cam: [800, 450, 1.0] },
        { s: 28, f: 0.4, tab: "Diaporama", go: "Répéter les minutages", cam: [600, 210, 1.7] },
        { s: 29, f: 0.1, cam: [800, 450, 1.0] },
        { s: 30, f: 0.2, go: () => P.tabs.Fichier, cam: [400, 200, 1.8] },
        { s: 30, f: 0.7, go: () => B.main.querySelector(".vid"), cam: [700, 330, 1.4] },
        { s: 31, f: 0.2, card: { html: `Une vidéo<small>Pour une présentation à distance ou un partage en ligne.</small>`, until: 31 } },
      ], { last: 31 });
    }
  });
})();
