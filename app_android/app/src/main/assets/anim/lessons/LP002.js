/* Leçon animée « Manipuler les diapositives » (code P002, vidéo pointdiapositiv). */
(function () {
  const T = window.T["P002"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Manipuler les diapositives"], [1, "Créer"], [8, "Disposition"], [12, "Dupliquer"], [17, "Supprimer"], [22, "Réorganiser"], [29, "Copier-coller"], [32, "Masquer"], [36, "Exporter"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {}); d.led.classList.add("on");
      const P = SC.ppt(E, d.screen, 1076, 605, "Formation 2026");
      const COL = { "Titre": "#1f3864", "Introduction": "#2b7cd3", "Atelier Word": "#185abd", "Conclusion": "#21a366", "Nouvelle diapositive": "#c43e1c", "Comparaison": "#7a5cd6" };
      // ---------------- liste des diapositives selon le temps (opérations successives)
      const ops = [
        [S(3, 0.7), s => s.splice(1, 0, { n: "Nouvelle diapositive" })],
        [S(11, 0.6), s => { s[1].n = "Comparaison"; s[1].lay = 1; }],
        [S(14, 0.6), s => s.splice(3, 0, { ...s[2], dup: 1 })],
        [S(19, 0.6), s => s.splice(3, 1)],
        [S(25, 0.85), s => { const [m] = s.splice(4, 1); s.splice(2, 0, m); }],
        [S(28, 0.7), s => { const [m] = s.splice(2, 1); s.splice(4, 0, m); }],
        [S(31, 0.7), s => s.push({ ...s[0], dup: 1 })],
        [S(34, 0.75), s => { s[2].hid = 1; }],
      ];
      const state = t => { const s = [{ n: "Titre" }, { n: "Introduction" }, { n: "Atelier Word" }, { n: "Conclusion" }]; ops.forEach(([a, f]) => { if (t >= a) f(s); }); return s; };
      const sel = t => { const k = [[0, 0], [S(4, 0), 1], [S(9, 0.4), 1], [S(13, 0.3), 2], [S(18, 0.3), 3], [S(24, 0.4), 4], [S(25, 0.85), 2], [S(30, 0.3), 0], [S(31, 0.7), 5], [S(34, 0.3), 2]]; let v = 0; k.forEach(([a, i]) => { if (t >= a) v = i; }); return v; };
      const mini = (sl, big) => `<div style="position:absolute;inset:0;background:${sl.lay ? "#fff" : "#fff"}"><div style="position:absolute;left:0;right:0;top:0;height:${big ? 90 : 22}px;background:${COL[sl.n] || "#5a6b80"}"></div>
        <div style="position:absolute;left:${big ? 50 : 8}px;top:${big ? 22 : 4}px;color:#fff;font-weight:700;font-size:${big ? 40 : 11}px">${sl.n}</div>
        ${sl.lay ? `<div style="position:absolute;left:${big ? 50 : 8}px;right:${big ? 50 : 8}px;top:${big ? 130 : 32}px;bottom:${big ? 40 : 8}px;display:flex;gap:${big ? 30 : 6}px"><div style="flex:1;border:${big ? 3 : 1}px dashed #aaa"></div><div style="flex:1;border:${big ? 3 : 1}px dashed #aaa"></div></div>` : `<div style="position:absolute;left:${big ? 60 : 10}px;top:${big ? 140 : 34}px;font-size:${big ? 28 : 7}px;line-height:1.8;color:#333">• Premier point<br>• Deuxième point<br>• Troisième point</div>`}</div>`;
      E.track(t => {
        const s = state(t), c = Math.min(sel(t), s.length - 1);
        const key = JSON.stringify(s) + c;
        if (P._k === key) return; P._k = key;
        P.thumbs.forEach((th, i) => {
          th.style.display = i < s.length ? "" : "none";
          if (i >= s.length) return;
          th.classList.toggle("on", i === c); th.classList.toggle("hid", !!s[i].hid);
          th.innerHTML = `<i>${s[i].hid ? `<s>${i + 1}</s>` : i + 1}</i>${mini(s[i], false)}`;
        });
        P.slide.innerHTML = mini(s[c], true);
        const st = P.root.querySelector(".status span"); st.textContent = `Diapositive ${c + 1} de ${s.length}`;
      });
      // ---------------- mode Trieuse de diapositives
      const sorter = $("div", "abs", P.root, { left: "0", right: "0", top: "196px", bottom: "26px", background: "#e9e9e9", zIndex: 8 });
      const grid = $("div", "", sorter, { padding: "40px", display: "flex", flexWrap: "wrap", gap: "40px", alignContent: "flex-start" });
      const tSortA = S(27, 0.5), tSortB = S(29, 0.2);
      E.only(sorter, tSortA, tSortB);
      E.track(t => {
        if (t < tSortA || t > tSortB) return;
        const s = state(t), key = JSON.stringify(s);
        if (sorter._k === key) return; sorter._k = key;
        grid.innerHTML = s.map((sl, i) => `<div style="text-align:center;font-size:18px"><div style="position:relative;width:300px;height:169px;background:#fff;border:3px solid ${i === 2 ? "#c43e1c" : "#ccc"}"><div style="position:absolute;inset:0;transform:scale(1);">${mini(sl, false).replace(/font-size:7px/g, "font-size:11px")}</div></div>${i + 1}</div>`).join("");
      });
      // ---------------- Fichier › Exporter
      const tExp = S(38, 0.5);
      const B = SC.backstage(E, P, tExp, 1e9);
      B.main.innerHTML = `<h3>Exporter</h3><div class="tile pdf"><i>&#128196;</i><div><b>Créer un document PDF/XPS</b></div></div><div class="tile vid"><i>&#9654;</i><div><b>Créer une vidéo</b></div></div><div class="tile img"><i>&#128444;</i><div><b>Modifier le type de fichier</b><br><small style="color:#666">Image JPEG, image PNG…</small></div></div>`;
      E.cls(B.item("Exporter"), "hl", tExp + 0.4, 1e9);
      const ctx = (items, hl) => ({ items, hl, w: 300 });
      const th = i => () => P.thumbs[i];
      SC.beats(E, P, [
        { s: 2, f: 0.4, go: "Accueil", cam: [420, 210, 1.8] },
        { s: 3, f: 0.5, go: "Nouvelle diapositive" },
        { s: 4, f: 0.2, go: th(1), clicks: 0, cam: [600, 450, 1.25] },
        { s: 5, f: 0.4, go: "Nouvelle diapositive", clicks: 0, cam: [420, 210, 1.8], menu: { items: [`<div style="display:grid;grid-template-columns:repeat(3,90px);gap:8px;padding:6px;font-size:12px;text-align:center">${["Titre", "Titre et contenu", "Deux contenus", "Comparaison", "Titre seul", "Vide"].map(n => `<div><div style="height:52px;border:1px solid #ccc;margin-bottom:3px;background:#fff"></div>${n}</div>`).join("")}</div>`], w: 330 }, until: 5, uf: 0.95 },
        { s: 7, f: 0.2, keys: ["Nouvelle diapositive", "Ctrl", "M"] },
        { s: 9, f: 0.4, go: th(1), cam: [600, 450, 1.25] },
        { s: 10, f: 0.5, go: "Disposition ▾", cam: [500, 210, 1.8], menu: { items: ["Titre", "Titre et contenu", "Deux contenus", "Comparaison", "Titre seul", "Vide"], hl: 3, hlAt: 11, hlF: 0.5, pick: true, w: 240 }, until: 11, uf: 0.65 },
        { s: 13, f: 0.4, go: th(2), clicks: 0, cam: [400, 450, 1.5], menu: { items: ["Couper", "Copier", "Coller", "-", "Nouvelle diapositive", "Dupliquer la diapositive", "Supprimer la diapositive", "-", "Masquer la diapositive"], hl: 5, hlAt: 14, hlF: 0.4, pick: true, w: 300, dx: 160, dy: -150 }, until: 14, uf: 0.6 },
        { s: 16, f: 0.2, keys: ["Dupliquer", "Ctrl", "D"] },
        { s: 18, f: 0.4, go: th(3), clicks: 0, menu: { items: ["Couper", "Copier", "Coller", "-", "Nouvelle diapositive", "Dupliquer la diapositive", "Supprimer la diapositive", "-", "Masquer la diapositive"], hl: 6, hlAt: 19, hlF: 0.4, pick: true, w: 300, dx: 160, dy: -150 }, until: 19, uf: 0.6 },
        { s: 21, f: 0.2, keys: ["Supprimer", "Suppr"] },
        { s: 24, f: 0.4, go: th(4), card: { html: `Glisser-déposer<small>Maintenez le clic sur la miniature et déplacez-la à la bonne place.</small>`, until: 25 } },
        { s: 25, f: 0.8, go: th(2), clicks: 0, dur: 1.4 },
        { s: 27, f: 0.3, tab: "Affichage", go: "Trieuse de diapositives", cam: [480, 200, 1.8] },
        { s: 28, f: 0.3, go: [380, 330], clicks: 0, cam: [800, 450, 1.0] },
        { s: 28, f: 0.75, go: [1100, 330], clicks: 0, dur: 1.2 },
        { s: 30, f: 0.3, tab: "Accueil", go: th(0), cam: [400, 450, 1.5], keys: ["Copier", "Ctrl", "C"] },
        { s: 31, f: 0.5, go: th(4), keys: ["Coller", "Ctrl", "V"] },
        { s: 34, f: 0.3, go: th(2), clicks: 0, menu: { items: ["Couper", "Copier", "Coller", "-", "Nouvelle diapositive", "Dupliquer la diapositive", "Supprimer la diapositive", "-", "Masquer la diapositive"], hl: 8, hlAt: 34, hlF: 0.6, pick: true, w: 300, dx: 160, dy: -150 }, until: 34, uf: 0.85, card: { html: `Diapositive masquée<small>Son numéro est barré : elle reste dans le fichier mais ne s’affiche pas pendant le diaporama.</small>`, until: 35 } },
        { s: 38, f: 0.4, go: () => P.tabs.Fichier, cam: [400, 200, 1.8] },
        { s: 39, f: 0.2, go: () => B.main.querySelector(".pdf"), clicks: 0, cam: [700, 330, 1.4], card: { html: `Exporter<small>PDF, vidéo, ou image JPEG/PNG.</small>`, until: 39 } },
        { s: 39, f: 0.6, go: () => B.main.querySelector(".img"), clicks: 0, dur: 0.6 },
      ], { last: 39 });
    }
  });
})();
