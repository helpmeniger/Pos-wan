/* Leçon animée « Se connecter à un point d'accès Wi-Fi » (code-barres 2016, vidéo vid6). */
(function () {
  const T = window.T["2016"];
  const WIFI = (c, s) => `<svg viewBox="0 0 40 32" width="${s}" height="${s * 0.8}"><g fill="none" stroke="${c}" stroke-width="3.4" stroke-linecap="round">
    <path d="M4 11 a24 24 0 0 1 32 0"/><path d="M9.5 17 a16 16 0 0 1 21 0"/><path d="M15 23 a8 8 0 0 1 10 0"/></g><circle cx="20" cy="28" r="2.8" fill="${c}"/></svg>`;
  const GLOBE = (c, s) => `<svg viewBox="0 0 32 32" width="${s}" height="${s}"><g fill="none" stroke="${c}" stroke-width="2.4"><circle cx="16" cy="16" r="12"/><ellipse cx="16" cy="16" rx="5" ry="12"/><path d="M4 16h24"/></g></svg>`;
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Se connecter au Wi-Fi"], [1, "Avant de commencer"], [3, "Ouvrir la liste des réseaux"], [6, "Choisir son réseau"],
            [8, "Saisir le mot de passe"], [11, "Vérifier la connexion"], [14, "Dépannage"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, {});
      d.led.classList.add("on");
      const D = E.desktop(d.screen, 1076, 605);
      const cur = D.cursor;

      // ---------------- icône réseau de la barre des tâches
      const ti = D.taskbar.querySelector(".tray i");
      Object.assign(ti.style, { width: "30px", height: "26px", background: "none", opacity: 1, marginTop: "-3px" });
      const tConn = S(10, 0.85);
      E.track(t => { const h = t >= tConn ? WIFI("#1b2330", 30) : GLOBE("#1b2330", 26); if (ti._h !== h) { ti.innerHTML = h; ti._h = h; } });
      const [ix, iy] = SC.pos(ti, D.root);

      // ---------------- volet Wi-Fi
      const fly = $("div", "abs", D.over, { right: "12px", bottom: "74px", width: "520px", background: "rgba(243,246,251,.98)", borderRadius: "12px",
        boxShadow: "0 20px 60px rgba(0,0,0,.3)", padding: "18px 20px", fontSize: "19px", color: "#1b2330" });
      fly.innerHTML = `<div style="display:flex;align-items:center;gap:12px;font-weight:600;margin-bottom:12px">&#8249; Wi-Fi<span class="toggle on" style="margin-left:auto;transform:scale(.8)"></span></div>
        ${[["Box-Maison", "Sécurisé"], ["Galaxy de Awa", "Sécurisé"], ["Voisin-5G", "Sécurisé"], ["CAFE_WIFI", "Ouvert"]].map(([n, s], i) =>
          `<div class="net n${i}" style="border-radius:8px;padding:10px 12px;margin-bottom:6px"><div style="display:flex;align-items:center;gap:14px">${WIFI("#3a4556", 28)}<div><div>${n}</div><div class="st" style="font-size:15px;color:#667386">${s}</div></div></div>
          ${i === 1 ? `<div class="x1" style="margin:10px 0 2px 42px"><span class="chk" style="font-size:16px"><i style="width:16px;height:16px;border:1.5px solid #555;display:inline-block;border-radius:3px;background:#326fbf;vertical-align:-3px"></i> Se connecter automatiquement</span>
            <div class="pw" style="margin-top:10px;font-size:16px">Entrer la clé de sécurité réseau<div class="fld" style="margin-top:6px;height:38px;border:2px solid #326fbf;border-radius:5px;background:#fff;padding:4px 10px;font-size:22px;letter-spacing:3px"></div></div>
            <div style="text-align:right;margin-top:10px"><span class="go" style="display:inline-block;background:#326fbf;color:#fff;border-radius:6px;padding:7px 22px;font-size:17px">Se connecter</span></div></div>` : ""}</div>`).join("")}
        <div style="border-top:1px solid #dde2ea;margin-top:6px;padding-top:10px;font-size:16px;color:#326fbf">Plus de paramètres Wi-Fi</div>`;
      const tOpen = S(4, 0.75), tPick = S(7, 0.55), tPw = S(9, 0.55), tGo = S(10, 0.75);
      E.show(fly, tOpen, S(13, 0.1), 0.2);
      E.anim([[0, 30], [tOpen, 30], [tOpen + 0.3, 0, 0.3]], v => { fly.style.transform = `translateY(${v}px)`; });
      const n1 = fly.querySelector(".n1"), x1 = fly.querySelector(".x1"), pw = fly.querySelector(".pw"), fld = fly.querySelector(".fld"), go = fly.querySelector(".go"), st1 = n1.querySelector(".st");
      E.only(x1, tPick, 1e9); E.only(pw, tPw, 1e9);
      E.track(t => { n1.style.background = t >= tPick - 0.3 ? "#e1e9f5" : ""; });
      E.track(t => { const st = t >= tConn ? "Connecté, sécurisé" : "Sécurisé"; if (st1.textContent !== st) st1.textContent = st; st1.style.color = t >= tConn ? "#1e9e62" : "#667386"; });
      E.track(t => { const n = t < S(10, 0.1) ? 0 : Math.min(10, Math.floor((t - S(10, 0.1)) * 6)); const h = "●".repeat(n); if (fld._h !== h) { fld.textContent = h; fld._h = h; } });
      E.cls(go, "hl", tGo - 0.3, tGo + 0.2);
      E.track(t => { go.textContent = t >= tPw ? "Connecter" : "Se connecter"; go.style.filter = t >= tGo - 0.3 && t < tGo + 0.2 ? "brightness(1.2)" : ""; });

      // ---------------- navigateur : test de la connexion
      const br = E.makeWindow(D.layer, "Nouvel onglet", { explorer: false });
      Object.assign(br.style, { left: "200px", top: "70px", width: "1200px", height: "730px" });
      br.body.innerHTML = `<div style="height:52px;background:#f1f4f9;display:flex;align-items:center;padding:0 16px;gap:14px;font-size:20px;color:#555">&#8592; &#8594; &#10227;
        <div style="flex:1;height:36px;border-radius:18px;background:#fff;box-shadow:0 0 0 1px #d6dbe3;padding:6px 18px;font-size:17px;color:#1b2330">Rechercher ou saisir une adresse web</div></div>
        <div style="text-align:center;padding-top:150px;font-size:44px;color:#1b2330;font-weight:300">La connexion fonctionne
        <div style="margin-top:30px;font-size:24px;color:#1e9e62">&#10003; Vous êtes connecté à Internet</div></div>`;
      E.show(br, S(13, 0.45), S(14, 0.1), 0.3);

      // ---------------- dépannage : menu du clic droit sur l'icône réseau
      const men = E.makeMenu(D.over, ["Dépanner les problèmes", "Paramètres réseau et Internet"]);
      Object.assign(men.style, { right: "80px", bottom: "74px", left: "auto", minWidth: "360px" });
      const tMenu = S(17, 0.45);
      E.show(men, tMenu, 999, 0.15);
      E.cls(men.item(0), "hl", tMenu + 0.6, 999);
      const [mx, my] = SC.pos(men.item(0), D.root);

      // ---------------- curseur
      const [nx, ny] = SC.pos(n1, D.root), [gx, gy] = SC.pos(go, D.root), [fx, fy] = SC.pos(fld, D.root);
      const appEdge = D.apps[3];
      SC.tour(E, D.root, D.over, cur, [[0, [800, 450], 0], [S(4, 0.1), [800, 450], 0], [tOpen - 0.1, [ix, iy], 1.0, 1],
        [tPick - 0.1, [nx, ny], 1.0, 1], [S(9, 0.9), [fx, fy], 0.7, 1], [tGo, [gx, gy], 0.7, 1], [S(12, 0.6), [ix, iy], 1.0],
        [S(13, 0.35), appEdge, 1.0, 1], [S(14, 0.5), [900, 450], 0.8], [tMenu - 0.05, [ix, iy], 1.0, 1], [tMenu + 0.6, [mx, my], 0.5]]);
      E.ring(D.over, ix, iy, 46, S(4, 0.2), tOpen + 0.3);
      E.ring(D.over, ix, iy, 46, S(12, 0.3), S(12, 1));

      // ---------------- caméra (coordonnées de l'écran)
      SC.screenCam(E, [[0, "room"], [S(2, 1), "room"], [S(3, 0.3), 800, 450, 1, 1.0], [S(4, 0.3), 1280, 700, 1.9, 0.9], [S(4, 1), 1280, 700, 1.9],
        [S(5, 0.4), 1230, 560, 1.55, 0.6], [S(12, 0.4), 1230, 560, 1.55], [S(12, 0.6), 1400, 760, 2.4, 0.6], [S(12, 1), 1400, 760, 2.4],
        [S(13, 0.3), 800, 450, 1, 0.7], [S(14, 0.2), 800, 450, 1], [S(14, 0.6), "room", 1.0], [S(16, 1), "room"], [S(17, 0.3), 1280, 700, 1.9, 0.9], [999, 1280, 700, 1.9]]);

      // ---------------- cartes
      E.card(`Le point d’accès<div style="display:flex;gap:30px;justify-content:center;align-items:flex-end;margin-top:18px">
        <svg width="90" height="150" viewBox="0 0 90 150"><rect x="5" y="5" width="80" height="140" rx="14" fill="#1b2330"/><rect x="12" y="20" width="66" height="105" rx="4" fill="#9cc3f0"/>
          <g transform="translate(25 52)">${WIFI("#fff", 40).replace(/<svg[^>]*>|<\/svg>/g, "")}</g></svg>
        <svg width="170" height="110" viewBox="0 0 170 110"><rect x="10" y="50" width="150" height="50" rx="12" fill="#e9edf3" stroke="#9aa6b8" stroke-width="3"/>
          <line x1="40" y1="50" x2="30" y2="8" stroke="#9aa6b8" stroke-width="5" stroke-linecap="round"/><line x1="130" y1="50" x2="140" y2="8" stroke="#9aa6b8" stroke-width="5" stroke-linecap="round"/>
          ${[0, 1, 2, 3].map(i => `<circle class="lt" cx="${45 + i * 26}" cy="75" r="6" fill="#1e9e62"/>`).join("")}</svg></div>
        <small>Téléphone (point d’accès mobile) ou box : allumé et prêt.</small>`, S(1, 0), S(2, 1) + 0.2, { width: "480px" });
      E.card(`Le Wi-Fi est activé<div style="display:flex;align-items:center;gap:22px;margin-top:18px">${WIFI("#326fbf", 70)}<span class="toggle on" style="transform:scale(1.5);margin-left:20px"></span></div>`,
        S(2, 0), S(2, 1) + 0.2, { left: "40px", right: "auto", top: "560px", width: "400px" });
      E.card(`Près de l’horloge<div style="display:flex;justify-content:center;margin-top:16px">${GLOBE("#1b2330", 70)}</div><small>Cliquez sur l’icône réseau.</small>`, S(4, 0), S(4, 1) + 0.3, { left: "40px", right: "auto", width: "380px" });
      E.card(`Connecté<div style="display:flex;justify-content:center;margin-top:16px">${WIFI("#1e9e62", 100)}</div><small>L’icône Wi-Fi apparaît dans la barre des tâches.</small>`, S(12, 0), S(12, 1) + 0.3, { left: "40px", right: "auto", width: "400px" });
      E.card(`Trop loin ?<div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-top:16px;font-size:60px">&#128187;<span style="font-size:36px;color:#d9822b">&#8592; &#8594;</span>&#128241;</div><small>Rapprochez l’ordinateur du point d’accès.</small>`,
        S(15, 0), S(15, 1) + 0.2, { width: "440px" });
      E.card(`Redémarrer<div style="display:flex;justify-content:center;margin-top:14px">${E.svgPower(90, "#326fbf", 5)}</div><small>Éteignez puis rallumez le point d’accès ou la box, puis réessayez.</small>`,
        S(16, 0), S(16, 1) + 0.2, { width: "440px" });
      E.card(`« Dépanner »<small>Windows recherche et corrige les problèmes de connexion.</small>`, S(17, 0.3), 999, { left: "40px", right: "auto", width: "420px" });
    }
  });
})();
