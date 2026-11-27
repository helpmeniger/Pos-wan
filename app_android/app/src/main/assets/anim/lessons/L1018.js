/* Leçon animée « Imprimer et protéger un classeur Excel » (code-barres 1018, vidéo protectionetmotsexcel). */
(function () {
  const T = window.T["1018"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Imprimer dans Excel"], [1, "Préparer la feuille"], [6, "Les paramètres"], [15, "L’échelle et l’aperçu"], [20, "Mot de passe du classeur"], [29, "Protéger la feuille"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {}); d.led.classList.add("on");
      const X = SC.excel(E, d.screen, 1076, 605, "Ventes 2026");
      [...X.grid.querySelectorAll("th.c")].slice(0, 4).forEach(th => th.style.width = "150px");
      const rows = [["Mois", "Riz", "Sucre", "Huile"], ["Janvier", "120", "60", "40"], ["Février", "135", "55", "38"], ["Mars", "98", "70", "45"], ["Avril", "140", "62", "41"], ["Mai", "150", "75", "50"]];
      rows.forEach((r, i) => r.forEach((v, j) => { const c = X.cell("ABCD"[j] + (i + 1)); c.textContent = v; if (i) { if (j) c.classList.add("num"); } else c.style.fontWeight = "700"; }));
      X.range("A1", "D6", S(3, 0.3), S(4, 0.3), 1.2);
      // ruban Révision (absent du composant de base)
      const rv = X.rb["Révision"] = $("div", "rb", X.root.querySelector(".ribbon"));
      [["Vérification", `<span class="b big"><i style="background:#5a6b80">&#10003;</i>Orthographe</span>`], ["Protéger", `<span class="b big"><i style="background:#107c41">&#128274;</i>Protéger la feuille</span><span class="b big"><i style="background:#107c41">&#128274;</i>Protéger le classeur</span><span class="b big"><i style="background:#5a6b80">&#9638;</i>Permettre la modification des plages</span>`]]
        .forEach(([l, h]) => { const g = $("div", "grp", rv); $("div", "gc", g).innerHTML = h; $("div", "gl", g).textContent = l; });

      // ---------------- Fichier › Imprimer (aperçu et paramètres)
      const tFile = S(4, 0.5), tPrint = S(5, 0.4), tEndPrint = S(19, 1), tLand = S(12, 0.7), tFit = S(17, 0.7);
      const B = SC.backstage(E, X, tFile, tEndPrint);
      const pv = B.main;
      pv.innerHTML = `<div style="display:flex;gap:30px"><div style="width:420px"><h3 style="margin-bottom:10px">Imprimer</h3><span class="btn pri" style="display:inline-block;padding:14px 26px">&#128424; Imprimer</span>
        <div style="margin:18px 0 6px;font-weight:600">Imprimante</div><div class="fld f-prn" style="display:block">Imprimante du bureau (HP)</div>
        <div style="margin:16px 0 6px;font-weight:600">Paramètres</div><div class="fld f-sel" style="display:block;margin-bottom:8px"></div><div class="fld f-ori" style="display:block;margin-bottom:8px"></div>
        <div class="fld f-size" style="display:block;margin-bottom:8px">A4 · 21 cm x 29,7 cm</div><div class="fld f-fit" style="display:block"></div></div>
        <div style="flex:1;display:flex;justify-content:center;align-items:flex-start;background:#f0f0f0;padding:24px"><div class="paper" style="background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.25);padding:30px;font-size:14px"></div></div></div>`;
      E.only(pv, tPrint, 1e9);
      E.cls(B.item("Imprimer"), "hl", tPrint - 0.2, tEndPrint);
      const paper = pv.querySelector(".paper");
      E.track(t => {
        const land = t >= tLand, fit = t >= tFit;
        const v = { sel: t >= S(10, 0.6) ? "Imprimer la sélection" : "Imprimer les feuilles actives", ori: land ? "Orientation paysage" : "Orientation portrait", fit: fit ? "Ajuster la feuille à une page" : "Aucune mise à l’échelle" };
        const set = (c, x) => { const e = pv.querySelector(c); if (e.textContent !== x) e.textContent = x; };
        set(".f-sel", v.sel); set(".f-ori", v.ori); set(".f-fit", v.fit);
        paper.style.width = land ? "460px" : "330px"; paper.style.height = land ? "330px" : "460px";
        const html = `<table style="border-collapse:collapse;width:100%;font-size:${fit ? 13 : 15}px">${rows.map((r, i) => `<tr>${r.map(c => `<td style="border:1px solid #bbb;padding:3px 6px;${i ? "" : "font-weight:700"}">${c}</td>`).join("")}</tr>`).join("")}</table>`;
        if (paper._h !== html) { paper.innerHTML = html; paper._h = html; }
      });

      // ---------------- Fichier › Informations › Protéger le classeur
      const tInfo = S(23, 0.4), tEnd2 = S(28, 1);
      const B2 = SC.backstage(E, X, S(22, 0.4), tEnd2);
      B2.main.innerHTML = `<h3>Informations</h3><div class="tile prot"><i class="lk" style="background:#eef1f6">&#128274;</i><div><b>Protéger le classeur</b><br><small class="pm" style="color:#666">Contrôlez les types de modifications que les autres peuvent apporter à ce classeur.</small></div></div>`;
      E.only(B2.main, tInfo, 1e9);
      E.cls(B2.item("Informations"), "hl", tInfo - 0.2, tEnd2);
      const lk = B2.main.querySelector(".lk"), pm = B2.main.querySelector(".pm"), tPw2 = S(26, 0.85);
      E.track(t => { lk.style.background = t >= tPw2 ? "#ffd54a" : "#eef1f6"; const v = t >= tPw2 ? "Un mot de passe est nécessaire pour ouvrir ce classeur." : "Contrôlez les types de modifications que les autres peuvent apporter à ce classeur."; if (pm.textContent !== v) pm.textContent = v; });

      const pw = (a, n) => { const f = document.createElement("span"); E.track(t => { const k = t < a ? 0 : Math.min(n || 8, Math.floor((t - a) * 7)); const v = "●".repeat(k); if (f.textContent !== v) f.textContent = v; }); return f; };
      const list = [
        { s: 2, f: 0.3, go: () => X.cell("A1"), cam: [500, 360, 1.6] },
        { s: 3, f: 0.3, go: () => X.cell("A1"), card: { html: `Que faut-il imprimer ?<small>Sélectionnez une zone, ou rien pour imprimer toute la feuille.</small>`, until: 3 } },
        { s: 3, f: 0.8, go: () => X.cell("D6"), clicks: 0, dur: 1.0 },
        { s: 4, f: 0.45, go: () => X.tabs.Fichier, cam: [400, 200, 1.8] },
        { s: 5, f: 0.35, go: () => B.item("Imprimer"), cam: [800, 450, 1.0] },
        { s: 8, f: 0.3, go: () => pv.querySelector(".f-prn"), cam: [560, 420, 1.6], menu: { items: ["Imprimante du bureau (HP)", "Microsoft Print to PDF", "Ajouter une imprimante…"], hl: 0, w: 380 }, until: 8, uf: 0.95 },
        { s: 10, f: 0.3, go: () => pv.querySelector(".f-sel"), menu: { items: ["Imprimer les feuilles actives", "Imprimer le classeur entier", "Imprimer la sélection"], hl: 2, hlAt: 10, hlF: 0.55, w: 380 }, until: 10, uf: 0.7 },
        { s: 12, f: 0.3, go: () => pv.querySelector(".f-ori"), menu: { items: ["Orientation portrait", "Orientation paysage"], hl: 1, hlAt: 12, hlF: 0.6, w: 380 }, until: 12, uf: 0.75, cam: [800, 440, 1.25] },
        { s: 14, f: 0.3, go: () => pv.querySelector(".f-size"), menu: { items: ["A4 · 21 cm x 29,7 cm", "Lettre · 21,59 cm x 27,94 cm", "A5 · 14,8 cm x 21 cm"], hl: 0, w: 380 }, until: 14, uf: 0.9 },
        { s: 16, f: 0.3, go: () => pv.querySelector(".f-fit"), menu: { items: ["Aucune mise à l’échelle", "Ajuster la feuille à une page", "Ajuster toutes les colonnes à une page", "Options de mise à l’échelle personnalisées…"], hl: 1, hlAt: 17, hlF: 0.6, w: 420 }, until: 17, uf: 0.75, card: { html: `Mise à l’échelle<small>« Ajuster la feuille à une page » ou un pourcentage (100 %…).</small>`, until: 17 } },
        { s: 19, f: 0.2, go: () => paper, clicks: 0, cam: [1080, 420, 1.3], card: { html: `L’aperçu<small>Vérifiez qu’aucune colonne n’est coupée avant d’imprimer.</small>`, until: 19 } },
        { s: 21, f: 0.2, go: [900, 500], clicks: 0, cam: [800, 450, 1.0] },
        { s: 22, f: 0.35, go: () => X.tabs.Fichier, cam: [400, 200, 1.8] },
        { s: 23, f: 0.35, go: () => B2.item("Informations"), cam: [560, 330, 1.4] },
        { s: 24, f: 0.3, go: () => B2.main.querySelector(".prot"), menu: { items: ["Toujours ouvrir en lecture seule", "Chiffrer avec mot de passe", "Protéger la feuille active", "Protéger la structure du classeur"], hl: 1, hlAt: 24, hlF: 0.75, pick: true, w: 380 }, until: 24, uf: 0.95 },
        { s: 25, f: 0.05, dialog: { title: "Chiffrer le document", x: 600, y: 300, w: 440, html: `Mot de passe :<div class="fld pw1" style="display:block;margin:6px 0;letter-spacing:3px"></div><small style="color:#a33">Un mot de passe oublié ne peut pas être récupéré.</small>`, okAt: 25, okF: 0.9 }, until: 25, uf: 0.95, cam: [820, 420, 1.5],
          run: (t, app, b) => b._dialog.querySelector(".pw1").appendChild(pw(t + 0.6)) },
        { s: 26, f: 0.02, dialog: { title: "Confirmer le mot de passe", x: 600, y: 300, w: 440, html: `Entrez de nouveau le mot de passe :<div class="fld pw2" style="display:block;margin:6px 0;letter-spacing:3px"></div>`, okAt: 26, okF: 0.8 }, until: 26, uf: 0.85,
          run: (t, app, b) => b._dialog.querySelector(".pw2").appendChild(pw(t + 0.4)) },
        { s: 27, f: 0.3, keys: ["Enregistrer", "Ctrl", "S"] },
        { s: 28, f: 0.2, card: { html: `À l’ouverture<div style="font-size:38px;text-align:center;margin-top:8px">&#128274; ●●●●●●</div><small>Excel demande le mot de passe.</small>`, until: 28 } },
        { s: 31, f: 0.3, tab: "Révision", go: () => X.tabs["Révision"], cam: [450, 200, 1.8] },
        { s: 32, f: 0.3, go: "Protéger la feuille" },
        { s: 33, f: 0.05, dialog: { title: "Protéger la feuille", x: 560, y: 220, w: 520, html: `<span class="chk on"><i></i>Protéger la feuille et le contenu des cellules verrouillées</span><div style="margin:10px 0 4px">Mot de passe pour ôter la protection :</div><div class="fld pw3" style="display:block;letter-spacing:3px"></div>
          <div style="margin:10px 0 4px">Autoriser tous les utilisateurs à :</div><span class="chk on"><i></i>Sélectionner les cellules verrouillées</span><br><span class="chk on"><i></i>Sélectionner les cellules déverrouillées</span><br><span class="chk"><i></i>Insérer des lignes</span>`, okAt: 35, okF: 0.85 }, until: 35, uf: 0.95, cam: [820, 420, 1.45],
          run: (t, app, b) => b._dialog.querySelector(".pw3").appendChild(pw(S(35, 0.3), 6)) },
        { s: 36, f: 0.1, go: () => X.cell("B2"), clicks: 1, cam: [520, 380, 1.5], card: { html: `Cellules à remplir<small>Avant de protéger : Format de cellule (Ctrl + 1) › Protection › décocher « Verrouillée ».</small>`, until: 36 } },
        { s: 36, f: 0.45, dialog: { title: "Format de cellule", x: 380, y: 300, w: 480, html: `<div style="display:flex;gap:16px;border-bottom:1px solid #ddd;margin-bottom:12px"><span>Nombre</span><span>Alignement</span><span>Police</span><span style="color:#107c41;border-bottom:2px solid #107c41">Protection</span></div><span class="chk vr"><i></i>Verrouillée</span><br><br><span class="chk"><i></i>Masquée</span>`, okAt: 36, okF: 0.95 }, until: 36, uf: 0.97 },
        { s: 37, f: 0.3, go: [1250, 700], clicks: 0, cam: [800, 450, 1.0] },
      ];
      SC.beats(E, X, list, { last: 37 });
    }
  });
})();
