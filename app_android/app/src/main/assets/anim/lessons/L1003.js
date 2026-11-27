/* Leçon animée « Les listes à puces et numérotées » (code-barres 1003, vidéo puce). */
(function () {
  const T = window.T["1003"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Puces et numéros"], [1, "Les puces"], [6, "Les numéros"], [11, "Ajouter, arrêter"], [14, "Style des puces"], [21, "Style des numéros"], [27, "Listes multiniveaux"], [34, "Ajuster la liste"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {}); d.led.classList.add("on");
      const W = SC.word(E, d.screen, 1076, 605, "Liste de courses");
      const P = W.page, el = W.el;
      const h = $("p", "h1", P); h.textContent = "Préparer la formation";
      const items = ["Réserver la salle", "Installer les ordinateurs", "Imprimer les supports", "Accueillir les participants"];
      const ps = items.map(t => { const p = $("p", "", P, { margin: "0 0 8px" }); p.innerHTML = `<span class="mk" style="display:inline-block;width:46px;color:#185abd;font-weight:700"></span><span class="tx">${t}</span>`; return p; });
      const extra = $("p", "", P, { margin: "0 0 8px" }); extra.innerHTML = `<span class="mk" style="display:inline-block;width:46px;color:#185abd;font-weight:700"></span><span class="tx"></span>`;
      const sub = $("p", "", P, { margin: "0 0 8px", paddingLeft: "46px" }); sub.innerHTML = `<span class="mk" style="display:inline-block;width:46px;color:#185abd;font-weight:700"></span><span class="tx">Vérifier les prises</span>`;
      // marqueurs selon le temps : aucun → puces → ◆ → numéros → I, II → 1. / a)
      const tBul = S(3, 0.6), tStyle = S(5, 0.7), tNum = S(7, 0.8), tRom = S(10, 0.3), tEnter = S(12, 0.4), tSub = S(32, 0.6), tUp = S(33, 0.6), tChap = S(26, 0.3);
      E.track(t => {
        const all = [...ps, extra];
        all.forEach((p, i) => {
          let m = "";
          if (t >= tBul) m = "•"; if (t >= tStyle) m = "◆"; if (t >= tNum) m = (i + 1) + "."; if (t >= tRom) m = ["I.", "II.", "III.", "IV.", "V."][i];
          if (t >= S(21, 0)) m = (i + 1) + "."; if (t >= tChap && t < S(27, 0)) m = "Chap. " + (i + 1);
          if (t >= S(27, 0)) m = (i + 1) + ".";
          const mk = p.querySelector(".mk"); if (mk.textContent !== m) mk.textContent = m;
          mk.style.width = m.startsWith("Chap") ? "96px" : "46px";
        });
        E.track && 0;
      });
      // nouveau point (Entrée) puis sous-niveau (Tab) et remontée (Maj + Tab)
      E.only(extra, tEnter, 1e9);
      SC.typeText(E, extra.querySelector(".tx"), "Distribuer les attestations", tEnter + 0.2, S(12, 1));
      E.only(sub, tSub, 1e9);
      E.track(t => { const m = sub.querySelector(".mk"); const v = t >= tUp ? "3." : "a)"; if (m.textContent !== v) m.textContent = v; sub.style.paddingLeft = t >= tUp ? "0" : "46px"; });
      E.track(t => ps.forEach(p => p.querySelector(".tx").classList.toggle("sel", t >= S(2, 0.4) && t < tBul + 0.6)));
      E.cls(el.bul, "on", tBul, tNum); E.cls(el.num, "on", tNum, 1e9);

      const st = n => `<div style="display:grid;grid-template-columns:repeat(3,70px);gap:8px;padding:6px">${n.map(x => `<div style="height:60px;border:1px solid #ccc;display:flex;align-items:center;justify-content:center;font-size:26px">${x}</div>`).join("")}</div>`;
      SC.beats(E, W, [
        { s: 2, f: 0.3, go: () => ps[0].querySelector(".tx"), cam: [760, 420, 1.5] },
        { s: 3, f: 0.5, go: () => el.bul, cam: "near", z: 1.8, card: { html: `Accueil › Paragraphe<div style="font-size:44px;text-align:center;margin-top:10px">&#8226;&#8801; &nbsp; 1&#8801; &nbsp; &#8942;&#8801;</div><small>Puces, numérotation, liste multiniveaux.</small>`, until: 4 } },
        { s: 5, f: 0.3, go: () => el.bul, menu: { items: [st(["•", "◆", "➢", "✓", "■", "–"]), "-", "Définir une puce…"], hl: 0, w: 260 }, until: 5, uf: 0.75 },
        { s: 7, f: 0.6, go: () => el.num, cam: "near", z: 1.8 },
        { s: 9, f: 0.4, go: () => el.num, menu: { items: [st(["1.", "I.", "A.", "a)", "i.", "1)"])], w: 260 }, until: 10, uf: 0.4 },
        { s: 12, f: 0.2, go: () => extra, clicks: 0, cam: [760, 430, 1.5], keys: ["Nouveau point", "Entrée"] },
        { s: 13, f: 0.3, keys: ["Arrêter la liste", "Entrée", "Entrée"] },
        { s: 15, f: 0.3, go: () => el.bul, cam: "near", z: 1.7, menu: { items: [st(["•", "◆", "➢"]), "-", "Changer le niveau de liste ›", "Définir une puce…"], hl: 3, hlAt: 16, pick: true, w: 280 }, until: 16, uf: 0.9 },
        { s: 17, f: 0.1, dialog: { title: "Définir une puce", w: 520, html: `Caractère de puce :<div style="display:flex;gap:10px;margin:10px 0 14px"><span class="btn sy">Symbole…</span><span class="btn im">Image…</span><span class="btn po">Police…</span></div>Aperçu :<div style="font-size:22px;border:1px solid #ccc;padding:10px;margin-top:6px">◆ Réserver la salle<br>◆ Installer les ordinateurs</div>` }, until: 20, uf: 0.95, cam: [800, 420, 1.5] },
        { s: 22, f: 0.3, go: () => el.num, cam: "near", z: 1.7, menu: { items: [st(["1.", "I.", "A."]), "-", "Définir un nouveau format de numérotation…"], hl: 2, hlAt: 23, pick: true, w: 320 }, until: 23, uf: 0.9 },
        { s: 24, f: 0.05, dialog: { title: "Définir un nouveau format de numérotation", w: 540, html: `Style de numérotation :<div class="fld" style="display:block;margin:4px 0 10px">1, 2, 3, … &#9662;</div>Format de numérotation :<div class="fld" style="display:block;margin:4px 0 10px">Chapitre 1</div><small style="color:#666">Le texte « Chapitre » est ajouté avant le numéro.</small>` }, until: 26, uf: 0.9, cam: [800, 420, 1.5] },
        { s: 28, f: 0.4, go: () => el.mlt, cam: "near", z: 1.7, menu: { items: [`<div style="font-size:15px;line-height:1.5;padding:4px">1. Chapitre<br>&nbsp;&nbsp;1.1. Partie<br>&nbsp;&nbsp;&nbsp;&nbsp;1.1.1. Point</div>`, "-", "Définir une nouvelle liste multiniveaux…"], hl: 2, hlAt: 29, hlF: 0.6, pick: true, w: 340 }, until: 29, uf: 0.95 },
        { s: 30, f: 0.05, dialog: { title: "Définir une nouvelle liste multiniveaux", w: 560, html: `Niveau à modifier : <b>1 · 2 · 3</b><br>Style : 1, 2, 3 · a, b, c · i, ii, iii<br>Position : retrait de 0,63 cm, espacement 0,5 cm` }, until: 31, uf: 0.95, cam: [800, 420, 1.5] },
        { s: 32, f: 0.3, go: () => sub, clicks: 0, cam: [760, 450, 1.5], keys: ["Descendre d’un niveau", "Tab"] },
        { s: 33, f: 0.3, keys: ["Remonter d’un niveau", "Maj", "Tab"] },
        { s: 35, f: 0.3, go: () => ps[1].querySelector(".mk"), clicks: 0, menu: { items: ["Couper", "Copier", "-", "Ajuster les retraits de la liste…", "Recommencer à 1", "Continuer la numérotation"], hl: 3, w: 340 }, until: 35, uf: 0.95, cam: [760, 450, 1.5] },
        { s: 37, f: 0.3, go: () => ps[2].querySelector(".mk"), clicks: 0, menu: { items: ["Ajuster les retraits de la liste…", "Recommencer à 1", "Continuer la numérotation"], hl: 1, w: 340 }, until: 37, uf: 0.95 },
        { s: 38, f: 0.3, go: () => ps[3].querySelector(".mk"), clicks: 0, menu: { items: ["Ajuster les retraits de la liste…", "Recommencer à 1", "Continuer la numérotation"], hl: 2, w: 340 }, until: 38, uf: 0.95 },
      ], { last: 38 });
      E.card(`Clic droit sur un numéro<small>« Recommencer à 1 » ou « Continuer la numérotation ».</small>`, S(36, 0), 999, { top: "150px", width: "440px" });
    }
  });
})();
