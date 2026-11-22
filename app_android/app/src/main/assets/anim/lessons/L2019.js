/* Leçon animée « Allumer l'ordinateur » (code-barres 2019) — remplace la vidéo vid1. */
(function () {
  const T = window.T["2019"];
  HM.lesson({
    audio: T.audio, sentences: T.sentences, texts: T.texts, duration: T.duration,
    steps: [[0, "Allumer l’ordinateur"], [1, "Vérifier le câble"], [2, "Repérer le bouton d’alimentation"],
            [4, "Comprendre le symbole"], [5, "Appuyer une fois"], [6, "Attendre le démarrage"]],
    build(E) {
      const S = E.S, $ = E.$;
      const d = SC.deskSet(E, { tower: true, outlet: true });
      const press = S(5, 0.35);

      // ---------------- écran : éteint, démarrage, verrouillage, bureau
      const boot = $("div", "boot", d.screen);
      const logo = $("div", "logo", boot, { transform: "scale(.62)" });
      const dots = $("div", "dots", boot, { marginTop: "40px" });
      const dotEls = [0, 1, 2, 3, 4].map(() => $("i", "", dots));
      const lock = $("div", "lock", d.screen);
      lock.innerHTML = `<div class="t" style="margin-top:120px;font-size:110px">14:05</div><div class="d" style="font-size:28px">lundi 24 novembre</div>`;
      const desk = E.desktop(d.screen, 1076, 605);
      E.show(boot, press + 0.7, S(7, 0.38), 0.25);
      E.show(lock, S(7, 0.32), S(7, 0.72) + 0.4, 0.3);
      E.show(desk.root, S(7, 0.66), 999, 0.5);
      E.show(desk.cursor, S(7, 0.8), 999);
      E.move(desk.cursor, [[0, 800, 420]]);
      E.track(t => dotEls.forEach((el, i) => { el.style.transform = `rotate(${((t * 300) - i * 24) % 360}deg)`; }));
      E.cls(d.led, "on", press, 999);
      E.cls(d.button, "lit", press, 999);

      // ---------------- câble branché dans la prise
      E.move(d.plug, [[0, 250, 760], [S(1, 0.12), 250, 760], [S(1, 0.55), 25, 731, 1.1]]);
      E.track(() => {                      // câble : de la fiche jusqu'au bas de l'unité centrale
        const x = parseFloat(d.plug.style.left) + 66, y = parseFloat(d.plug.style.top) + 31;
        const x2 = 160, y2 = 840, len = Math.hypot(x2 - x, y2 - y), ang = Math.atan2(y2 - y, x2 - x) * 180 / Math.PI;
        Object.assign(d.cable.style, { left: x + "px", top: (y - 6) + "px", width: len + "px", transform: `rotate(${ang}deg)` });
      });
      E.ring(d.set, 60, 762, 64, S(1, 0.62), S(1, 1) + 0.3);

      // ---------------- bouton d'alimentation
      E.ring(d.set, 255, 602, 66, S(2, 0.1), S(3, 0.25));
      const finger = $("div", "finger", d.set, { width: "60px", height: "60px" });
      E.show(finger, press - 0.9, press + 1.0, 0.25);
      E.move(finger, [[0, 430, 640], [press - 0.7, 430, 640], [press - 0.08, 236, 582, 0.55], [press + 0.25, 236, 582], [press + 0.85, 430, 660, 0.5]]);
      E.anim([[0, 1], [press - 0.05, 1], [press + 0.05, 0.9, 0.1], [press + 0.3, 1, 0.25]], v => { d.button.style.transform = `scale(${v})`; });
      E.click(d.set, press, 255, 602, 2);

      // ---------------- caméra (zooms sur les parties expliquées)
      E.camera([
        [0, 960, 540, 1], [S(0, 1), 960, 540, 1],
        [S(1, 0.08), 300, 700, 1.9, 1.0], [S(1, 1), 300, 700, 1.9],
        [S(2, 0.12), 270, 610, 2.3, 0.9], [S(4, 1), 270, 610, 2.3],
        [press - 0.6, 300, 610, 2.0, 0.6], [press + 0.9, 300, 610, 2.0],
        [S(5, 1), 960, 390, 1.28, 1.2], [S(7, 0.3), 960, 390, 1.28],
        [S(7, 0.78), 960, 540, 1, 1.1]
      ]);

      // ---------------- cartes d'explication
      const sym = E.card(`Le symbole d’alimentation
        <svg width="300" height="240" viewBox="0 0 300 240">
          <g class="zero"><circle cx="150" cy="120" r="70" fill="none" stroke="#326fbf" stroke-width="16"/><text x="138" y="232" font-size="34" font-weight="800" fill="#326fbf">0</text></g>
          <g class="one"><line x1="150" y1="25" x2="150" y2="115" stroke="#d9822b" stroke-width="16" stroke-linecap="round"/><text x="141" y="18" font-size="0" fill="#d9822b">1</text></g>
          <rect class="gap" x="120" y="40" width="60" height="22" fill="#fff"/>
        </svg><small>Un cercle ouvert et une ligne verticale : le 0 et le 1 réunis.</small>`, S(3, 0.05), S(3, 1) + 0.25);
      E.track(t => {
        const k = E.easeOut((t - S(3, 0.15)) / 1.4);
        sym.querySelector(".zero").setAttribute("transform", `translate(${-90 * (1 - k)},0)`);
        sym.querySelector(".one").setAttribute("transform", `translate(${90 * (1 - k)},0)`);
        sym.querySelector(".gap").style.opacity = k;
      });
      E.card(`Le langage binaire
        <div style="display:flex;gap:22px;margin-top:18px">
          <div style="flex:1;text-align:center;background:#e6f5ec;border-radius:22px;padding:16px"><div style="font-size:80px;color:#1e9e62">1</div><small style="margin:0;color:#1e9e62">actif</small></div>
          <div style="flex:1;text-align:center;background:#eef1f6;border-radius:22px;padding:16px"><div style="font-size:80px;color:#667386">0</div><small style="margin:0">inactif</small></div>
        </div>`, S(4, 0.03), S(4, 1) + 0.25);
      E.card(`Une simple pression<div style="margin-top:16px;display:flex;justify-content:center">${E.svgPower(120, "#326fbf", 5)}</div>
        <small>Inutile d’appuyer longtemps.</small>`, S(5, 0.05), S(5, 1) + 0.2);
      const wait = E.card(`Démarrage en cours<div class="wd" style="position:relative;width:90px;height:90px;margin:26px auto 6px"></div>
        <small>De quelques secondes à quelques minutes : logos et écrans de chargement s’affichent.</small>`, S(6, 0), S(7, 0.5));
      const wd = wait.querySelector(".wd");
      const wdots = [0, 1, 2, 3, 4].map(() => $("i", "", wd, { position: "absolute", left: "40px", top: "0", width: "11px", height: "11px", borderRadius: "50%", background: "#326fbf", transformOrigin: "5px 45px" }));
      E.track(t => wdots.forEach((el, i) => { el.style.transform = `rotate(${((t * 300) - i * 24) % 360}deg)`; }));
    }
  });
})();
