/* Simulateur Windows 11 HELP-ME : exercices guidés et démarrage. */
"use strict";
(function () {
  const { $, esc, IC, FS, WM } = SIM;

  // ================================================================== missions (vérifiées automatiquement)
  const MISSIONS = [
    ["Découvrir Windows", [
      ["start", "Ouvrir le menu Démarrer", "Cliquez sur le logo Windows, au centre de la barre des tâches.", (e, d) => e === "start"],
      ["search", "Trouver une application avec la recherche", "Dans Démarrer, tapez « calc » puis ouvrez la Calculatrice.", (e, d) => e === "search-open"],
      ["calc", "Faire un calcul", "Dans la Calculatrice : 25 × 4 =", (e, d) => e === "calc"],
      ["min", "Réduire une fenêtre", "Bouton — en haut à droite d’une fenêtre (elle va dans la barre des tâches).", (e) => e === "minimize"],
      ["max", "Agrandir une fenêtre", "Bouton ☐, ou double-clic sur la barre de titre.", (e) => e === "maximize"],
      ["move", "Déplacer ou redimensionner une fenêtre", "Glissez la barre de titre, ou le coin inférieur droit.", (e) => e === "move-window" || e === "resize-window" || e === "snap"],
    ]],
    ["Fichiers et dossiers", [
      ["newdir", "Créer un dossier sur le bureau", "Clic droit sur le bureau (appui long sur écran tactile) › Nouveau › Dossier.", (e, d) => e === "new" && d.type === "dir"],
      ["rename", "Renommer un dossier « Mes cours »", "Clic droit sur le dossier › Renommer, tapez le nom puis Entrée.", (e, d) => e === "rename" && d.type === "dir" && /mes cours/i.test(d.name)],
      ["explore", "Ouvrir le dossier Documents", "Ouvrez l’Explorateur de fichiers (Ce PC) puis Documents.", (e, d) => e === "explore" && d === "docs"],
      ["moveinto", "Déplacer un fichier dans un dossier", "Clic droit sur un fichier › Couper, ouvrez le dossier, puis Coller.", (e, d) => e === "move" && d.type !== "dir"],
      ["trash", "Supprimer puis vider la Corbeille", "Supprimez un élément, puis clic droit sur la Corbeille › Vider.", (e, d) => e === "emptytrash" && d > 0],
    ]],
    ["Bureautique", [
      ["type", "Écrire une phrase dans le Bloc-notes", "Ouvrez le Bloc-notes et tapez au moins 15 caractères.", (e, d) => e === "type" && d.app === "notepad" && d.text.trim().length >= 15],
      ["save", "Enregistrer un fichier texte dans Documents", "Fichier › Enregistrer (Ctrl + S), choisissez Documents.", (e, d) => e === "save" && d.type === "txt" && d.parent === "docs"],
      ["bold", "Mettre un mot en gras dans Word", "Sélectionnez un mot puis cliquez sur G (gras).", (e, d) => e === "format" && d.cmd === "bold"],
      ["align", "Centrer un paragraphe dans Word", "Cliquez dans le paragraphe puis sur le bouton Centrer.", (e, d) => e === "format" && d.cmd === "justifyCenter"],
      ["sum", "Calculer une somme dans Excel", "Tapez des nombres dans A1, A2, A3, puis =SOMME(A1:A3) en A4.", (e, d) => e === "cell" && /^=\s*(SOMME|SUM)\(/i.test(d.v) && typeof d.val === "number"],
      ["xlsave", "Enregistrer un classeur Excel", "Cliquez sur la disquette, choisissez un dossier et un nom.", (e, d) => e === "save" && d.type === "xlsx"],
    ]],
    ["Internet et personnalisation", [
      ["search2", "Faire une recherche dans le Navigateur", "Tapez une recherche puis Entrée.", (e) => e === "websearch"],
      ["download", "Télécharger un fichier", "Page « Logiciels » › Télécharger, puis regardez dans Téléchargements.", (e) => e === "download"],
      ["wall", "Changer le fond d’écran", "Clic droit sur le bureau › Personnaliser.", (e) => e === "wallpaper"],
      ["dark", "Activer le mode sombre", "Paramètres › Personnalisation › Mode sombre.", (e, d) => e === "dark" && d],
      ["osk", "Écrire avec le Clavier visuel", "Ouvrez le Bloc-notes, puis le Clavier visuel, et touchez des lettres.", (e) => e === "osk"],
      ["draw", "Dessiner dans Paint", "Choisissez une couleur et dessinez avec le doigt ou la souris.", (e) => e === "draw"],
    ]],
  ];
  const done = SIM.store.get("done", {});
  const all = MISSIONS.flatMap(g => g[1]);
  SIM.on("*", (ev, data) => {
    all.forEach(([id, title, , test]) => {
      if (done[id]) return;
      let ok = false; try { ok = test(ev, data || {}); } catch (e) { ok = false; }
      if (!ok) return;
      done[id] = Date.now(); SIM.store.set("done", done);
      SIM.toast("Exercice réussi !", title, "ok");
      SIM.emit("progress");
      if (Object.keys(done).length === all.length) setTimeout(() => SIM.toast("Bravo !", "Vous avez réussi tous les exercices du simulateur.", "ok"), 1500);
    });
  });
  SIM.apps.exercises = { name: "Exercices", icon: "exo", w: 620, h: 560, single: true, kw: "missions entraînement",
    mount(w) {
      const box = $("div", "exo", w.body);
      const render = () => {
        const n = Object.keys(done).length;
        box.innerHTML = `<h2>Exercices du simulateur</h2><div style="color:var(--sub);margin-top:4px">${n} réussi${n > 1 ? "s" : ""} sur ${all.length} — chaque mission se coche toute seule quand vous la réalisez.</div>
          <div class="pbar"><i style="width:${Math.round(n / all.length * 100)}%"></i></div>`;
        MISSIONS.forEach(([g, list]) => {
          $("div", "", box, `<div style="font-weight:600;margin:14px 0 8px">${esc(g)}</div>`);
          list.forEach(([id, t, hint]) => $("div", "mis" + (done[id] ? " done" : ""), box, `<div class="chk">${done[id] ? "✓" : ""}</div><div><div class="t">${esc(t)}</div><small>${esc(hint)}</small></div>`));
        });
        const r = $("button", "btn", box, "Recommencer les exercices"); r.style.marginTop = "14px";
        r.onclick = async () => { const a = await SIM.dialog(w.body, "Recommencer", "Effacer la progression des exercices ?", [["Oui", true], ["Non", false]]); if (a.v) { Object.keys(done).forEach(k => delete done[k]); SIM.store.set("done", done); render(); } };
      };
      SIM.on("progress", render); render();
    }
  };

  // ================================================================== réglages d'affichage
  SIM.cfg = Object.assign({ wall: "bloom", dark: false, accent: "#0067c0", left: false, hide: false, bright: 100 }, SIM.store.get("cfg", {}));
  const BLOOM = `<svg viewBox="0 0 1100 820" preserveAspectRatio="xMidYMid slice"><defs><linearGradient id="b1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0a3fbf"/><stop offset=".6" stop-color="#1a6cf0"/><stop offset="1" stop-color="#9cc8ff"/></linearGradient>
    <linearGradient id="b2" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1450d8"/><stop offset="1" stop-color="#5aa2ff"/></linearGradient></defs>
    <path d="M520 410 C 380 160, 640 40, 760 160 C 880 280, 760 420, 620 430 Z" fill="url(#b1)" opacity=".95"/><path d="M520 410 C 420 600, 640 790, 820 700 C 980 620, 900 470, 700 450 Z" fill="url(#b2)" opacity=".9"/>
    <path d="M520 410 C 330 330, 120 470, 210 640 C 300 800, 470 640, 520 470 Z" fill="url(#b1)" opacity=".85"/><path d="M520 410 C 600 250, 980 230, 1020 420 C 1050 560, 820 560, 680 470 Z" fill="url(#b2)" opacity=".8"/>
    <path d="M520 410 C 470 250, 300 120, 230 260 C 170 380, 380 430, 520 430 Z" fill="url(#b2)" opacity=".7"/></svg>`;
  SIM.applyCfg = () => {
    const c = SIM.cfg; SIM.store.set("cfg", c);
    const bg = (SIM.WALLS.find(x => x[0] === c.wall) || SIM.WALLS[0])[1];
    [document.getElementById("wall"), document.querySelector("#lock .wl")].forEach(el => { el.style.background = c.wall === "bloom" ? "linear-gradient(135deg,#7fa8e6,#3f73c9)" : bg; el.innerHTML = c.wall === "bloom" ? BLOOM : ""; });
    document.body.classList.toggle("dark", !!c.dark);
    document.body.classList.toggle("tb-left", !!c.left);
    document.body.classList.toggle("tb-hide", !!c.hide);
    document.documentElement.style.setProperty("--accent", c.accent);
    document.getElementById("desk").style.filter = c.bright < 100 ? `brightness(${c.bright / 100})` : "";
  };

  // ================================================================== construction de l'écran
  document.body.innerHTML = `<div id="desk"><div id="wall"></div><div id="icons"></div><div id="wins"></div>
    <div id="taskbar"><div id="tbapps"></div><div id="tray"><div class="tr" id="qbtn">${IC.wifi}${IC.vol}${IC.bat}</div><div class="tr" id="clock"></div></div></div>
    <div id="start" class="flyout"><div class="sm-search">${IC.search}<input placeholder="Rechercher des applications, des paramètres et des documents"></div><div class="sm-body"></div>
      <div class="sm-foot"><div class="user"><div class="avatar">É</div>Élève</div><button class="pwr" title="Marche/Arrêt">${IC.power}</button></div></div>
    <div id="quick" class="flyout"><div class="qs"><div class="qb on">${IC.wifi} Wi-Fi</div><div class="qb">Bluetooth</div><div class="qb">Mode avion</div><div class="qb on">Économiseur</div><div class="qb dk">Mode sombre</div><div class="qb ks">${IC.keyboard.replace("<svg", '<svg width="18" height="18"')} Clavier</div></div>
      <div class="qr">☀<input type="range" min="40" max="100" class="br"></div><div class="qr">${IC.vol}<input type="range" value="60"></div>
      <div class="qr" style="justify-content:space-between;color:var(--sub)"><span>${IC.bat} 82 %</span><span class="cb" id="qset">${IC.settings.replace("<svg", '<svg width="18" height="18"')}</span></div></div>
    <div id="cal" class="flyout"></div><div id="toasts"></div></div>
    <div id="lock"><div class="wl"></div><div class="tm"></div><div class="dt"></div><div class="hint">Touchez l’écran ou appuyez sur une touche pour déverrouiller</div>
      <div class="login"><div class="bigav">É</div><div class="uname">Élève</div><button class="btn pri" style="height:38px;padding:0 34px">Se connecter</button><div style="opacity:.8;font-size:12px">Simulateur HELP-ME : aucun mot de passe nécessaire</div></div></div>
    <div id="black"></div>`;

  FS.init();
  SIM.applyCfg();
  SIM.renderDesk(); SIM.taskbar(); SIM.clock(); setInterval(SIM.clock, 15000);
  SIM.on("fs", d => { if (d === "desktop" || d === "trash") SIM.renderDesk(); });

  // bureau : sélection, clic droit, menu
  const desk = document.getElementById("desk");
  desk.addEventListener("pointerdown", e => {
    if (!e.target.closest(".ctx")) SIM.closeMenus();
    if (!e.target.closest(".flyout") && !e.target.closest("#taskbar")) SIM.closeFly();
    if (e.target.id === "icons" || e.target.id === "wall" || e.target.closest("#wall")) document.querySelectorAll(".dico.sel").forEach(d => d.classList.remove("sel"));
  });
  const onDeskBg = el => SIM.touchy(el, { ctx: (x, y) => SIM.deskMenu(x, y) });
  onDeskBg(document.getElementById("wall")); onDeskBg(document.getElementById("icons"));
  document.getElementById("icons").addEventListener("contextmenu", e => { if (e.target.id !== "icons") return; });
  const tb = document.getElementById("taskbar");
  tb.addEventListener("contextmenu", e => { e.preventDefault(); if (!e.target.closest(".tbi")) SIM.tbMenu(e.clientX, e.clientY); });
  tb.addEventListener("pointerdown", e => { if (!e.target.closest(".tbi,.tbsearch,.tr")) SIM.closeFly(); });
  document.getElementById("qbtn").onclick = e => { e.stopPropagation(); SIM.toggleFly("quick"); };
  document.getElementById("clock").onclick = e => { e.stopPropagation(); SIM.toggleFly("cal"); };
  document.querySelector("#quick .dk").onclick = e => { SIM.cfg.dark = !SIM.cfg.dark; SIM.applyCfg(); e.currentTarget.classList.toggle("on", SIM.cfg.dark); SIM.emit("dark", SIM.cfg.dark); };
  document.querySelector("#quick .dk").classList.toggle("on", SIM.cfg.dark);
  document.querySelector("#quick .ks").onclick = () => { SIM.closeFly(); WM.open("keyboard"); };
  document.querySelectorAll("#quick .qb:not(.dk):not(.ks)").forEach(b => b.onclick = () => b.classList.toggle("on"));
  const br = document.querySelector("#quick .br"); br.value = SIM.cfg.bright; br.oninput = () => { SIM.cfg.bright = +br.value; SIM.applyCfg(); };
  document.getElementById("qset").onclick = () => { SIM.closeFly(); WM.open("settings"); };
  document.querySelectorAll(".flyout").forEach(f => f.addEventListener("pointerdown", e => e.stopPropagation()));
  const si = document.querySelector("#start input");
  si.addEventListener("input", () => SIM.renderStart(si.value.trim()));
  si.addEventListener("keydown", e => { if (e.key === "Enter") { const f = document.querySelector("#start .sm-body .li"); if (f) f.click(); } e.stopPropagation(); });
  document.querySelector("#start .pwr").onclick = e => { const r = e.currentTarget.getBoundingClientRect(); SIM.menu(r.left - 120, r.top - 110, [{ t: "Verrouiller", fn: () => SIM.power("lock") }, { t: "Redémarrer", ic: "refresh", fn: () => SIM.power("restart") }, { t: "Arrêter", ic: "power", fn: () => SIM.power("shutdown") }]); };
  document.querySelector("#start .user").onclick = () => SIM.toast("Compte local", "Élève — simulateur HELP-ME");

  // écran de verrouillage
  const lock = document.getElementById("lock");
  lock.addEventListener("click", e => { if (!lock.classList.contains("log")) SIM.unlock(); });
  lock.querySelector(".login button").onclick = e => { e.stopPropagation(); SIM.unlock(); };
  SIM.on("login", () => { if (!SIM.store.get("welcomed", false)) { SIM.store.set("welcomed", true); setTimeout(() => { WM.open("exercises"); SIM.toast("Bienvenue !", "Suivez les exercices ou explorez librement. Appui long = clic droit.", "info"); }, 700); } });

  // clavier physique
  addEventListener("keydown", e => {
    if (!lock.classList.contains("gone")) { if (!lock.classList.contains("log")) SIM.unlock(); else if (e.key === "Enter") SIM.unlock(); return; }
    const kb = SIM.wins.find(w => w.app === "keyboard"); if (kb && kb.flash) kb.flash(e.key);
    if (e.key === "Meta" || e.key === "OS") { e.preventDefault(); SIM.toggleFly("start"); return; }
    if (e.key === "Escape") { SIM.closeMenus(); SIM.closeFly(); }
    // menu Démarrer ouvert : la frappe va dans la recherche, comme sur Windows
    if (document.getElementById("start").classList.contains("open") && document.activeElement !== si && e.key.length === 1 && !e.ctrlKey) { si.focus(); return; }
    if (e.altKey && e.key === "Tab") { e.preventDefault(); const v = SIM.wins.filter(w => !w.minimized); if (v.length > 1) WM.focus(v[0]); return; }
    if (e.altKey && e.key === "F4" && SIM.active) { e.preventDefault(); WM.close(SIM.active); return; }
    const t = e.target; const typing = t && (t.tagName === "INPUT" || t.tagName === "TEXTAREA" || t.isContentEditable);
    if (SIM.active && SIM.active.keys && (!typing || e.ctrlKey || SIM.active.app === "excel" || SIM.active.app === "calc")) SIM.active.keys(e);
    else if (!SIM.active && !typing) {
      const sel = document.querySelector(".dico.sel"); if (!sel) return;
      const id = sel.dataset.id, n = FS.get(id);
      if (e.key === "Enter") sel.dispatchEvent(new MouseEvent("dblclick"));
      if (n && e.key === "F2") { e.preventDefault(); SIM.renameDesk(id); }
      if (n && e.key === "Delete") FS.remove(id);
      if (n && e.ctrlKey && e.key.toLowerCase() === "c") SIM.setClip(id, false);
      if (n && e.ctrlKey && e.key.toLowerCase() === "x") SIM.setClip(id, true);
    }
    if (!SIM.active && e.ctrlKey && e.key.toLowerCase() === "v" && !typing) SIM.paste("desktop");
  });
  addEventListener("contextmenu", e => { if (!e.target.closest("input,textarea,[contenteditable]")) e.preventDefault(); });
  SIM.lock();
})();
