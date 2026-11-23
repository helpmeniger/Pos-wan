/* Leçon animée « Télécharger, installer, rechercher sur le Web » (code-barres 2003, vidéo telecharvid9). */
(function () {
  const T = window.T["2003"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Télécharger et installer"], [6, "Lancer l’installation"], [13, "Rechercher efficacement"], [19, "Des sites utiles"],
            [27, "Exemple : Niger Emploi"], [39, "Télécharger une vidéo"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const D = E.desktop(d.screen, 1076, 605);
      const cur = D.cursor;

      // ---------------- fenêtre du navigateur
      const tOpen = S(1, 0.6), tCloseBr = S(6, 0.2), tReopen = S(13, 0.2);
      const br = $("div", "brw", D.layer);
      br.innerHTML = `<div class="bt"><div class="tab"><span class="ti">&#127760;</span><span class="tt">Nouvel onglet</span><span style="margin-left:auto;color:#888">&#10005;</span></div></div>
        <div class="bar">&#8592; &#8594; &#10227;<div class="url"></div>&#9734;</div>`;
      const url = br.querySelector(".url"), tt = br.querySelector(".tt");
      E.track(t => br.style.display = (t >= tOpen && t < tCloseBr) || t >= tReopen ? "flex" : "none");
      E.anim([[0, 0], [tOpen, 0], [tOpen + 0.3, 1, 0.3]], v => { br.style.opacity = v; br.style.transform = `scale(${0.94 + 0.06 * v})`; });
      const pages = [];
      const page = (html, a, b) => { const p = $("div", "pg", br); p.innerHTML = html; E.only(p, a, b); pages.push(p); return p; };
      /** Adresse affichée : [[t, texte, tape(0/1)]] ; titre de l'onglet. */
      const URLS = [], TABS = [];
      E.track(t => {
        let u = ["", 0, 0]; URLS.forEach(x => { if (t >= x[0]) u = x; });
        const txt = u[2] ? u[1].slice(0, Math.max(0, Math.round((t - u[0]) * 14))) : u[1];
        if (url.textContent !== txt) url.textContent = txt;
        let ti = "Nouvel onglet"; TABS.forEach(x => { if (t >= x[0]) ti = x[1]; }); if (tt.textContent !== ti) tt.textContent = ti;
      });

      // ---------------- partie 1 : site officiel, téléchargement, installation
      URLS.push([tOpen, "", 0], [S(2, 0.2), "www.lecteur-media.org", 1]);
      TABS.push([S(2, 0.5), "Lecteur Média – site officiel"]);
      const home = page(`<div style="text-align:center;padding-top:150px"><div style="font-size:44px;font-weight:300">Rechercher sur le Web</div>
        <div style="width:640px;height:52px;margin:30px auto;border-radius:26px;box-shadow:0 2px 10px rgba(0,0,0,.12);border:1px solid #d0d5dc"></div></div>`, tOpen, S(2, 0.6));
      const site = page(`<div style="background:linear-gradient(135deg,#ff8a00,#e65100);color:#fff;padding:60px 120px"><div style="font-size:22px;opacity:.9">Site officiel</div>
        <div style="font-size:56px;font-weight:800;margin:10px 0">Lecteur Média</div><div style="font-size:22px">Lit toutes les vidéos et musiques. Gratuit.</div>
        <div style="margin-top:34px"><span class="bigbtn dlb">&#11015; Télécharger</span> <span style="margin-left:20px;opacity:.9">Version 3.0 · Windows 64 bits</span></div></div>
        <div style="display:flex;gap:30px;padding:40px 120px;color:#555"><div>&#10003; Sans publicité</div><div>&#10003; Plus de 100 formats</div><div>&#10003; Mises à jour régulières</div></div>`, S(2, 0.6), tCloseBr);
      const dlb = site.querySelector(".dlb");
      const tDl = S(4, 0.4);
      E.cls(dlb, "hl", S(3, 0.4), tDl + 0.3);
      const dlbar = $("div", "dlbar", br);
      dlbar.innerHTML = `<div style="font-weight:600">Téléchargements</div><div style="display:flex;align-items:center;gap:12px;margin-top:10px"><span style="font-size:30px">&#128190;</span><div style="flex:1"><div>lecteur-media-setup<b>.exe</b></div><div class="prog"><i></i></div><small class="st" style="color:#667386"></small></div></div>`;
      E.only(dlbar, tDl + 0.2, tCloseBr);
      E.track(t => { const k = Math.max(0, Math.min(1, (t - tDl - 0.2) / 3)); dlbar.querySelector(".prog i").style.width = (k * 100) + "%"; const v = k < 1 ? `${Math.round(k * 42)} Mo sur 42 Mo` : "Terminé · Ouvrir le fichier"; const st = dlbar.querySelector(".st"); if (st.textContent !== v) st.textContent = v; });

      // Explorateur › Téléchargements
      const ex = E.makeWindow(D.layer, "Téléchargements", { files: ["lecteur-media-setup.exe", "CV.pdf", "Photo.jpg"] });
      Object.assign(ex.style, { left: "180px", top: "70px", width: "1100px", height: "640px" });
      const exe = ex.files.children[0];
      exe.querySelector("b").style.background = "linear-gradient(135deg,#ff8a00,#e65100)";
      E.show(ex, S(6, 0.3), S(7, 0.6), 0.25);
      E.cls(exe, "sel", S(7, 0.1), S(7, 0.6));
      // assistant d'installation
      const wz = $("div", "wiz", D.layer);
      wz.innerHTML = `<div class="wh">Installation de Lecteur Média</div><div class="wb">
        <div class="w1"><div style="font-size:24px;font-weight:600;margin-bottom:14px">Contrat de licence</div><div style="height:170px;border:1px solid #ddd;padding:10px;color:#666;font-size:14px;overflow:hidden">Conditions d’utilisation du logiciel… Le logiciel est fourni gratuitement… Vous pouvez l’installer sur vos ordinateurs…</div>
          <div class="rad acc" style="margin-top:16px;display:flex;gap:10px;align-items:center"><i style="width:18px;height:18px;border-radius:50%;border:2px solid #555;display:inline-block"></i>J’accepte les termes du contrat de licence</div></div>
        <div class="w2"><div style="font-size:24px;font-weight:600;margin-bottom:14px">Emplacement d’installation</div>Dossier de destination :<div style="border:1px solid #bbb;padding:8px 10px;margin:8px 0">C:\\Program Files\\Lecteur Média</div><small style="color:#666">Espace requis : 120 Mo</small></div>
        <div class="w3"><div style="font-size:24px;font-weight:600;margin-bottom:14px">Installation en cours…</div><div class="prog" style="height:14px"><i class="ip"></i></div><small class="ifile" style="color:#666;display:block;margin-top:10px"></small></div>
        <div class="w4"><div style="font-size:24px;font-weight:600;margin-bottom:14px">Installation terminée</div>Lecteur Média est installé sur votre ordinateur.<div style="margin-top:20px;display:flex;gap:10px;align-items:center"><span style="width:18px;height:18px;background:#326fbf;color:#fff;font-size:13px;text-align:center;line-height:18px">&#10003;</span>Lancer Lecteur Média</div></div>
        </div><div class="wf"><span class="btn">&#8249; Précédent</span><span class="btn pri nx">Suivant &#8250;</span><span class="btn">Annuler</span></div>`;
      const tWz = S(7, 0.7), tAcc = S(9, 0.25), tN1 = S(9, 0.45), tInst = S(9, 0.8), tDone = S(11, 0.9);
      E.show(wz, tWz, S(12, 0.9), 0.25);
      E.only(wz.querySelector(".w1"), -1, tN1); E.only(wz.querySelector(".w2"), tN1, tInst); E.only(wz.querySelector(".w3"), tInst, tDone); E.only(wz.querySelector(".w4"), tDone, 1e9);
      E.track(t => { const i = wz.querySelector(".acc i"); i.style.background = t >= tAcc ? "radial-gradient(#1b2330 40%, #fff 45%)" : "#fff"; });
      const nx = wz.querySelector(".nx");
      E.track(t => { const v = t >= tDone ? "Terminer" : t >= tN1 && t < tInst ? "Installer" : "Suivant ›"; if (nx.textContent !== v) nx.textContent = v; });
      E.cls(nx, "hl", tN1 - 0.25, tN1 + 0.1); E.cls(nx, "hl", tInst - 0.25, tInst + 0.1); E.cls(nx, "hl", S(12, 0.6), S(12, 0.9));
      E.track(t => { const k = Math.max(0, Math.min(1, (t - tInst) / (tDone - tInst))); wz.querySelector(".ip").style.width = (k * 100) + "%"; const f = ["lecteur.exe", "codecs.dll", "langues\\fr.dll", "aide.html"][Math.min(3, Math.floor(k * 4))]; const el = wz.querySelector(".ifile"); const v = "Copie : C:\\Program Files\\Lecteur Média\\" + f; if (el.textContent !== v) el.textContent = v; });
      // nouvelle icône sur le bureau à la fin
      const ni = $("div", "ico", D.icons[0].parentNode); ni.innerHTML = `<b style="background:linear-gradient(135deg,#ff8a00,#e65100)"></b>Lecteur Média`;
      E.show(ni, S(12, 0.9), 999, 0.4);

      // ---------------- partie 2 : recherches efficaces
      URLS.push([tReopen, "", 0]); TABS.push([tReopen, "Nouvel onglet"]);
      const sp = page(`<div style="text-align:center;padding-top:120px"><div style="font-size:44px;font-weight:300">Rechercher sur le Web</div>
        <div class="q" style="width:820px;height:56px;margin:30px auto;border-radius:28px;box-shadow:0 2px 10px rgba(0,0,0,.12);border:1px solid #d0d5dc;text-align:left;padding:0 26px;line-height:54px;font-size:21px"></div>
        <div class="bad" style="font-size:20px;color:#c42b1c"></div></div>`, tReopen, S(19, 0));
      const q = sp.querySelector(".q"), bad = sp.querySelector(".bad");
      const Q = [[S(15, 0.1), "comment est-ce que je peux trouver des exercices de maths gratuits pour mon fils qui est au lycée", 1], [S(17, 0.1), "exercice mathématique lycéens gratuits", 0], [S(18, 0.1), "meilleur logiciel gestion des employés", 0]];
      E.track(t => {
        let cur0 = null; Q.forEach(x => { if (t >= x[0]) cur0 = x; });
        const v = cur0 ? cur0[1].slice(0, Math.round((t - cur0[0]) * 22)) : "";
        if (q.textContent !== v) q.textContent = v;
        q.style.textDecoration = cur0 && cur0[2] && t > S(15, 0.7) ? "line-through" : "";
        const b = cur0 && cur0[2] && t > S(15, 0.7) ? "✗ Trop long : gardez seulement les mots importants" : cur0 && !cur0[2] ? "✓ Des mots clés précis" : "";
        if (bad.textContent !== b) { bad.textContent = b; bad.style.color = cur0 && cur0[2] ? "#c42b1c" : "#1e9e62"; }
      });

      // ---------------- partie 3 : sites utiles (cartes)
      const sites = page(`<div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;padding:40px 80px"><div><div style="font-size:30px;font-weight:700;color:#326fbf;margin-bottom:16px">&#127891; Sites éducatifs</div>
        ${[["Khan Academy", "Cours gratuits : maths, sciences, programmation"], ["Coursera", "Cours en ligne d’universités reconnues"], ["OpenClassrooms", "Formations en ligne dans plusieurs domaines"]].map(([n, dd], i) => `<div class="sc e${i}" style="border:1px solid #e1e4ea;border-radius:12px;padding:16px 20px;margin-bottom:12px"><b style="font-size:22px">${n}</b><br><span style="color:#667386">${dd}</span></div>`).join("")}</div>
        <div><div style="font-size:30px;font-weight:700;color:#1e9e62;margin-bottom:16px">&#128188; Sites de gestion</div>
        ${[["Trello", "Gérer des projets avec des tableaux et des cartes"], ["QuickBooks", "Comptabilité et finances d’une entreprise"]].map(([n, dd], i) => `<div class="sc g${i}" style="border:1px solid #e1e4ea;border-radius:12px;padding:16px 20px;margin-bottom:12px"><b style="font-size:22px">${n}</b><br><span style="color:#667386">${dd}</span></div>`).join("")}
        <div class="kw" style="margin-top:20px;font-size:18px;color:#1b2330"></div></div></div>`, S(19, 0), S(27, 0.1));
      [[".e0", 20], [".e1", 21], [".e2", 23], [".g0", 24], [".g1", 25]].forEach(([c, i]) => { const el = sites.querySelector(c); E.show(el, S(i, 0), 999, 0.3); E.track(t => { el.style.boxShadow = t >= S(i, 0) && t < S(i, 1) + 0.2 ? "0 0 0 3px #d9822b" : ""; }); });
      const kw = sites.querySelector(".kw");
      E.track(t => { const v = t >= S(26, 0) ? "Mots clés : logiciel de facturation · comptabilité pour TPE · gestion des finances" : t >= S(22, 0) ? "Mots clés : formation certifiée en ligne · apprendre Python · cours en gestion" : ""; if (kw.textContent !== v) kw.textContent = v; });
      URLS.push([S(19, 0), "Sites utiles", 0]); TABS.push([S(19, 0), "Sites utiles"]);

      // ---------------- partie 4 : Niger Emploi
      URLS.push([S(27, 0.2), "", 0], [S(31, 0.15), "Niger Emploi", 1], [S(31, 0.8), "www.nigeremploi.com", 0]);
      TABS.push([S(27, 0.2), "Nouvel onglet"], [S(31, 0.8), "Niger Emploi – offres d’emploi"]);
      page(`<div style="text-align:center;padding-top:150px;font-size:44px;font-weight:300">Rechercher sur le Web</div>`, S(27, 0.2), S(31, 0.8));
      const ne = page(`<div style="background:#0e5a3a;color:#fff;padding:26px 80px;font-size:34px;font-weight:800">Niger Emploi <span style="font-size:18px;font-weight:400;opacity:.85;margin-left:16px">Offres d’emploi au Niger</span></div>
        <div style="padding:26px 80px;display:flex;gap:12px"><div class="nq" style="flex:1;height:52px;border:2px solid #0e5a3a;border-radius:8px;padding:0 16px;line-height:48px;font-size:20px"></div><span class="nb bigbtn" style="background:#0e5a3a;padding:10px 26px;font-size:20px">Rechercher</span></div>
        <div class="res" style="padding:0 80px">${[["Ingénieur informatique", "Niamey · CDI · Télécommunications"], ["Ingénieur informatique réseaux", "Niamey · CDD 12 mois"], ["Technicien informatique", "Maradi · CDI"]].map(([n, dd], i) => `<div class="of o${i}" style="border:1px solid #e1e4ea;border-radius:10px;padding:16px 20px;margin-bottom:10px"><b style="font-size:21px;color:#0e5a3a;text-decoration:underline">${n}</b><br><span style="color:#667386">${dd}</span></div>`).join("")}</div>`, S(31, 0.8), S(37, 0.5));
      const nq = ne.querySelector(".nq"), res = ne.querySelector(".res");
      SC.typeText(E, nq, "Ingénieur informatique", S(34, 0.1), S(34, 0.9));
      E.only(res, S(35, 0.7), 1e9);
      E.cls(ne.querySelector(".nb"), "hl", S(35, 0.4), S(35, 0.7));
      E.track(t => { ne.querySelector(".o0").style.background = t >= S(37, 0.1) ? "#eef7f1" : ""; });
      const det = page(`<div style="background:#0e5a3a;color:#fff;padding:26px 80px;font-size:34px;font-weight:800">Niger Emploi</div><div style="padding:30px 80px"><div style="font-size:34px;font-weight:700">Ingénieur informatique</div>
        <div style="color:#667386;margin:8px 0 20px">Niamey · CDI · publiée il y a 2 jours</div><b>Missions</b><br>Installer et maintenir le parc informatique, gérer le réseau, assister les utilisateurs.<br><br><b>Profil</b><br>Bac + 3 en informatique, maîtrise de Windows et des réseaux.
        <div style="margin-top:26px"><span class="bigbtn post" style="background:#0e5a3a">Postuler</span></div></div>`, S(37, 0.5), S(39, 0.3));
      E.cls(det.querySelector(".post"), "hl", S(38, 0.4), S(38, 1));
      TABS.push([S(37, 0.5), "Ingénieur informatique – Niger Emploi"]);

      // ---------------- partie 5 : vidéo (adresse modifiée)
      const VURL = "www.youtube.com/watch?v=cours-excel";
      URLS.push([S(39, 0.3), VURL, 0], [S(43, 0.6), "www.ssyoutube.com/watch?v=cours-excel", 0], [S(46, 0.2), "www.site-de-telechargement.exemple", 0]);
      TABS.push([S(39, 0.3), "Cours Excel débutant – vidéo"], [S(46, 0.2), "Télécharger la vidéo"]);
      const vp = page(`<div style="padding:24px 80px;display:flex;gap:30px"><div style="width:900px"><div style="height:500px;background:#111;border-radius:12px;display:flex;align-items:center;justify-content:center"><div style="width:110px;height:76px;border-radius:18px;background:#e62117;display:flex;align-items:center;justify-content:center"><div style="border-left:34px solid #fff;border-top:20px solid transparent;border-bottom:20px solid transparent;margin-left:8px"></div></div></div>
        <div style="font-size:26px;font-weight:700;margin-top:14px">Cours Excel débutant</div></div></div>`, S(39, 0.3), S(46, 0.2));
      // « ss » inséré dans l'adresse
      E.track(t => { const on = t >= S(43, 0.1) && t < S(44, 1); url.classList.toggle("hl", on); if (t >= S(43, 0.1) && t < S(43, 0.6)) { const v = "www." + (t >= S(43, 0.35) ? "s" : "") + (t >= S(43, 0.5) ? "s" : "") + "youtube.com/watch?v=cours-excel"; if (url.textContent !== v) url.textContent = v; } });
      const dp = page(`<div style="text-align:center;padding-top:80px"><div style="font-size:36px;font-weight:700">Télécharger la vidéo</div><div style="color:#667386;margin:10px 0 30px">Cours Excel débutant</div>
        <div style="display:inline-flex;gap:14px">${["360p", "720p", "1080p"].map((r, i) => `<span class="ql q${i}" style="border:2px solid #d0d5dc;border-radius:10px;padding:14px 26px;font-size:22px">${r}</span>`).join("")}</div>
        <div style="margin-top:34px"><span class="bigbtn vdl">&#11015; Télécharger</span></div><div style="margin-top:30px;color:#667386;font-size:17px">Téléchargez uniquement des vidéos que vous avez le droit d’utiliser.</div></div>`, S(46, 0.2), 1e9);
      E.track(t => { dp.querySelector(".q1").style.borderColor = t >= S(47, 0.25) ? "#326fbf" : "#d0d5dc"; });
      E.cls(dp.querySelector(".vdl"), "hl", S(47, 0.6), S(47, 1));

      // ---------------- curseur
      const app = D.apps[3];
      SC.tour(E, D.root, D.over, cur, [[0, [800, 450], 0], [tOpen - 0.1, app, 1.2, 1], [S(2, 0.1), url, 0.6, 1], [tDl, dlb, 1.0, 1], [S(5, 0.6), [1300, 600], 0.8],
        [S(7, 0.2), exe, 1.0, 2], [tAcc, wz.querySelector(".acc i"), 0.8, 1], [tN1, nx, 0.5, 1], [tInst, nx, 0.3, 1], [S(12, 0.7), nx, 0.5, 1],
        [tReopen - 0.1, app, 1.0, 1], [S(15, 0.0), q, 0.6, 1], [S(20, 0), [1300, 700], 1.0], [S(31, 0.1), url, 1.0, 1], [S(34, 0.0), nq, 1.0, 1], [S(35, 0.5), ne.querySelector(".nb"), 0.6, 1],
        [S(37, 0.3), ne.querySelector(".o0 b"), 0.8, 1], [S(38, 0.6), det.querySelector(".post"), 0.8, 1], [S(43, 0.25), url, 1.0, 1], [S(44, 0.6), url, 0.2],
        [S(47, 0.3), dp.querySelector(".q1"), 1.0, 1], [S(47, 0.7), dp.querySelector(".vdl"), 0.5, 1]]);

      // ---------------- caméra (écran 1600 x 900)
      SC.screenCam(E, [[0, "room"], [S(0, 0.6), "room"], [S(1, 0.3), 800, 450, 1, 1.0], [S(2, 0.1), 600, 200, 1.8, 0.7], [S(2, 0.7), 600, 200, 1.8], [S(3, 0.2), 500, 330, 1.5, 0.7], [S(4, 1), 500, 330, 1.5],
        [S(5, 0.2), 1250, 230, 2.0, 0.7], [S(5, 1), 1250, 230, 2.0], [S(6, 0.3), 800, 450, 1, 0.7], [S(7, 0.6), 800, 450, 1], [S(8, 0.2), 800, 420, 1.45, 0.7], [S(12, 1), 800, 420, 1.45],
        [S(13, 0.2), 800, 450, 1, 0.7], [S(14, 0.5), 800, 330, 1.5, 0.7], [S(18, 1), 800, 330, 1.5], [S(19, 0.3), 800, 450, 1.05, 0.7], [S(26, 1), 800, 450, 1.05],
        [S(27, 0.3), 800, 450, 1, 0.5], [S(31, 0.0), 600, 200, 1.8, 0.7], [S(31, 1), 600, 200, 1.8], [S(32, 0.2), 700, 330, 1.4, 0.7], [S(38, 1), 700, 330, 1.4],
        [S(39, 0.3), 800, 450, 1, 0.6], [S(42, 1), 800, 450, 1], [S(43, 0.1), 500, 150, 2.4, 0.7], [S(44, 1), 500, 150, 2.4], [S(45, 0.3), 800, 420, 1.3, 0.7], [S(47, 1) + 0.6, 800, 420, 1.3], [S(47, 1) + 1.8, "room", 1.2]]);

      // ---------------- cartes
      E.card(`Le site officiel<small>Téléchargez toujours un logiciel depuis le site de son éditeur, jamais depuis une publicité.</small>`, S(2, 0), S(2, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "460px" });
      E.card(`Le fichier .exe<div style="font-size:60px;text-align:center;margin-top:6px">&#9881;</div><small>Un programme d’installation pour Windows.</small>`, S(5, 0), S(5, 1) + 0.3, { left: "40px", right: "auto", top: "560px", width: "380px" });
      E.card(`Suivant › Installer › Terminer<small>Lisez chaque écran : conditions, dossier d’installation…</small>`, S(8, 0), S(11, 1), { left: "40px", right: "auto", top: "640px", width: "460px" });
      E.card(`Démarrer<small>Le logiciel installé apparaît dans le menu Démarrer et sur le bureau.</small>`, S(12, 0), S(12, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "420px" });
      E.card(`Des mots clés<div style="margin-top:12px;font-size:24px;line-height:1.6"><span style="color:#c42b1c">✗ Une longue phrase</span><br><span style="color:#1e9e62">✓ Les mots essentiels</span></div>`, S(14, 0), S(18, 1) + 0.2, { left: "40px", right: "auto", top: "620px", width: "400px" });
      E.card(`Postuler en ligne<small>Ouvrez l’offre, lisez les missions et le profil, puis utilisez le bouton « Postuler ».</small>`, S(36, 0), S(38, 1) + 0.2, { left: "40px", right: "auto", top: "640px", width: "440px" });
      E.card(`Ajouter « ss »<div style="font-size:28px;margin-top:12px;font-family:Consolas,monospace">www.<b style="color:#d9822b">ss</b>youtube.com…</div>`, S(42, 0), S(44, 1) + 0.2, { left: "40px", right: "auto", top: "620px", width: "460px" });
      E.card(`Droits d’auteur<small>Téléchargez seulement des vidéos que vous avez le droit d’utiliser (cours libres, vos propres vidéos…).</small>`, S(45, 0), 999, { left: "40px", right: "auto", top: "640px", width: "440px" });
    }
  });
})();
