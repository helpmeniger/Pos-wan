/*
 * Moteur des leçons animées HELP-ME (remplace les vidéos).
 * Tout est dessiné en HTML/CSS/SVG et piloté par le temps de la voix off : l'état de la scène est une
 * fonction du temps t (caméra, curseur, fenêtres, cartes...), ce qui permet la pause, la reprise et
 * le déplacement dans la barre de progression.
 *
 * Une leçon (lessons/L<code>.js) appelle HM.lesson({...}) avec :
 *   audio      : fichier de la voix (audio/<code>.m4a) ou null (voix de synthèse du téléphone)
 *   sentences  : [[début, fin], ...] en secondes (calés sur la voix) — ou texts + estimation
 *   texts      : phrases lues (pour la voix de synthèse si pas d'audio)
 *   steps      : [[indexPhrase, "libellé"], ...]
 *   build(E)   : construit le décor et déclare les animations avec l'API E (voir plus bas)
 */
(function () {
  "use strict";
  const W = 1920, H = 1080;
  const $ = (tag, cls, parent, style) => {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (style) Object.assign(e.style, style);
    if (parent) parent.appendChild(e);
    return e;
  };
  const clamp = (x, a, b) => Math.max(a, Math.min(b, x));
  const ease = x => { x = clamp(x, 0, 1); return x * x * (3 - 2 * x); };
  const easeOut = x => 1 - Math.pow(1 - clamp(x, 0, 1), 3);
  const lerp = (a, b, k) => a + (b - a) * k;

  // ------------------------------------------------------------------ pistes d'animation
  const tracks = [];            // fonctions (t) appelées à chaque image
  function keys(list, t, def) {  // list: [[t, v], ...] trié ; interpolation douce entre deux clés
    if (!list.length) return def;
    if (t <= list[0][0]) return list[0][1];
    for (let i = 1; i < list.length; i++) {
      const [t1, v1] = list[i], [t0, v0] = list[i - 1];
      if (t <= t1) {
        const d = list[i][2] != null ? list[i][2] : (t1 - t0);   // durée de la transition (par défaut : tout l'intervalle)
        const k = ease((t - (t1 - d)) / Math.max(d, 1e-3));
        if (Array.isArray(v0)) return v0.map((x, j) => lerp(x, v1[j], k));
        return lerp(v0, v1, k);
      }
    }
    return list[list.length - 1][1];
  }
  function window01(t, a, b, f) {  // opacité 0..1 entre a et b avec fondus
    f = f == null ? 0.35 : f;
    if (t < a || t > b) return 0;
    return ease(Math.min((t - a) / f, (b - t) / f, 1));
  }

  // ------------------------------------------------------------------ fabrique de décors
  const svgPower = (size, color, width) =>
    `<svg width="${size}" height="${size}" viewBox="0 0 52 52"><path d="M15 13 A18 18 0 1 0 37 13" fill="none" stroke="${color}" stroke-width="${width}" stroke-linecap="round"/>` +
    `<line x1="26" y1="5" x2="26" y2="25" stroke="${color}" stroke-width="${width}" stroke-linecap="round"/></svg>`;
  const ARROW = `<svg viewBox="0 0 38 54" width="38" height="54"><path d="M3 2 L3 44 L13 34 L20 51 L27 48 L20 32 L34 32 Z" fill="#fff" stroke="#111" stroke-width="2.4" stroke-linejoin="round"/></svg>`;
  const RESIZE = `<svg viewBox="0 0 54 54" width="54" height="54"><path d="M8 8 L22 8 L17 13 L41 37 L46 32 L46 46 L32 46 L37 41 L13 17 L8 22 Z" fill="#fff" stroke="#111" stroke-width="2.4" stroke-linejoin="round"/></svg>`;
  const MOVE = `<svg viewBox="0 0 54 54" width="54" height="54"><path d="M27 3 L35 12 L30 12 L30 24 L42 24 L42 19 L51 27 L42 35 L42 30 L30 30 L30 42 L35 42 L27 51 L19 42 L24 42 L24 30 L12 30 L12 35 L3 27 L12 19 L12 24 L24 24 L24 12 L19 12 Z" fill="#fff" stroke="#111" stroke-width="2.2" stroke-linejoin="round"/></svg>`;
  const ICONS = [["Ce PC", "#5a8fd6"], ["Corbeille", "#9fb4cc"], ["Documents", "#f4b83a"], ["Word", "#2b579a"], ["Excel", "#1d6f42"],
    ["PowerPoint", "#c43e1c"], ["Google Chrome", "#e0a21c"], ["Microsoft Edge", "#1f8fd8"], ["Photos", "#3aa0f5"], ["Musique", "#e24c8a"],
    ["Vidéos", "#7a5cd6"], ["Zoom", "#2d8cff"], ["Téléchargements", "#2aa36b"], ["Manuel HELP-ME", "#326fbf"]];
  const BLOOM = `<svg viewBox="0 0 1100 820"><defs>
    <linearGradient id="b1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#0a3fbf"/><stop offset=".6" stop-color="#1a6cf0"/><stop offset="1" stop-color="#9cc8ff"/></linearGradient>
    <linearGradient id="b2" x1="1" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#1450d8"/><stop offset="1" stop-color="#5aa2ff"/></linearGradient></defs>
    <path d="M520 410 C 380 160, 640 40, 760 160 C 880 280, 760 420, 620 430 Z" fill="url(#b1)" opacity=".95"/>
    <path d="M520 410 C 420 600, 640 790, 820 700 C 980 620, 900 470, 700 450 Z" fill="url(#b2)" opacity=".9"/>
    <path d="M520 410 C 330 330, 120 470, 210 640 C 300 800, 470 640, 520 470 Z" fill="url(#b1)" opacity=".85"/>
    <path d="M520 410 C 600 250, 980 230, 1020 420 C 1050 560, 820 560, 680 470 Z" fill="url(#b2)" opacity=".8"/>
    <path d="M520 410 C 470 250, 300 120, 230 260 C 170 380, 380 430, 520 430 Z" fill="url(#b2)" opacity=".7"/></svg>`;

  function scaleInto(inner, host, w, h) {      // place un « écran Windows » 1600x900 dans un écran de taille w x h
    inner.style.transform = `scale(${w / 1600}, ${h / 900})`;
    host.appendChild(inner);
  }

  /** Bureau Windows 11 (1600 x 900) : fond, icônes, barre des tâches, calque des fenêtres, curseur. */
  function desktop(host, w, h) {
    const root = $("div", "win");
    const wall = $("div", "wall", root); wall.innerHTML = BLOOM;
    const icons = $("div", "icons", root);
    const iconEls = ICONS.map(([n, c]) => { const d = $("div", "ico", icons); d.innerHTML = `<b style="background:${c}"></b>${n}`; return d; });
    const layer = $("div", "abs", root, { inset: "0" });
    const tb = $("div", "taskbar", root);
    const apps = $("div", "apps", tb);
    const appEls = ["start", "#5a6b80", "#f4b83a", "#1f8fd8", "#2b579a", "#1d6f42", "#c43e1c", "#e0a21c"].map((c, i) => {
      const s = $("span", i === 0 ? "startbtn" : "", apps);
      if (i) s.style.background = c;
      return s;
    });
    const tray = $("div", "tray", tb); for (let i = 0; i < 3; i++) $("i", "", tray);
    $("div", "clock", tb).innerHTML = "14:05<br>24/11/2026";
    const over = $("div", "abs", root, { inset: "0" });              // menus, repères, curseur
    const cur = $("div", "cursor", over); cur.innerHTML = ARROW;
    scaleInto(root, host, w, h);
    return { root, icons: iconEls, layer, over, taskbar: tb, apps: appEls, cursor: cur };
  }

  function makeWindow(parent, title, opts) {
    opts = opts || {};
    const w = $("div", "wnd", parent);
    const tb = $("div", "tb", w);
    tb.innerHTML = `<span>${title}</span><div class="btns"><span class="min">&#8212;</span><span class="max">&#9744;</span><span class="close">&#10005;</span></div>`;
    const body = $("div", "body", w);
    if (opts.explorer !== false) {
      $("div", "side", body).innerHTML = "Accueil<br>Bureau<br>Documents<br>Images<br>Musique<br>Vidéos<br>Ce PC<br>Clé USB (E:)";
      const f = $("div", "files", body);
      (opts.files || ["Cours", "Exercices", "Factures", "Photos", "Rapport", "CV", "Projets", "Musique", "Archives", "Notes", "Budget", "Modèles"])
        .forEach(n => { $("div", "", f).innerHTML = `<b></b>${n}`; });
      w.files = f;
    }
    w.btn = n => tb.querySelector("." + n);
    w.body = body;
    return w;
  }

  function makeMenu(parent, items) {
    const m = $("div", "menu", parent);
    items.forEach(it => { if (it === "-") $("hr", "", m); else $("div", "", m).textContent = it; });
    m.item = i => m.querySelectorAll("div")[i];
    return m;
  }

  // ------------------------------------------------------------------ API des leçons
  function api(L) {
    const S = (i, f) => {                                // instant dans la phrase i : f < 1 = fraction, sinon secondes
      const s = L.sentences[clamp(i, 0, L.sentences.length - 1)];
      f = f == null ? 0 : f;
      return f <= 1 && f >= 0 ? s[0] + (s[1] - s[0]) * f : s[0] + f;
    };
    const E = {
      S, $, svgPower, ARROW, RESIZE, MOVE, desktop, makeWindow, makeMenu, ease, easeOut, keys,
      world: null, hud: null,
      track(fn) { tracks.push(fn); },
      set(cls) { const s = $("div", "set " + (cls || ""), E.world); return s; },
      /** Visible entre a et b (fondu). */
      show(el, a, b, fade) { el.style.opacity = 0; tracks.push(t => { const v = window01(t, a, b, fade); el.style.opacity = v; el.style.visibility = v > 0 ? "visible" : "hidden"; }); },
      /** Classe CSS active entre a et b. */
      cls(el, c, a, b) { tracks.push(t => el.classList.toggle(c, t >= a && t <= b)); },
      /** Caméra : [[t, cx, cy, zoom, durée]] — centre l'image sur (cx, cy) avec le zoom donné. */
      camera(list) {
        const k = list.map(x => [x[0], [x[1], x[2], x[3]], x[4]]);
        tracks.push(t => {
          const [cx, cy, z] = keys(k, t, [W / 2, H / 2, 1]);
          let tx = W / 2 - cx * z, ty = H / 2 - cy * z;
          tx = clamp(tx, W - W * z, 0); ty = clamp(ty, H - H * z, 0);
          E.world.style.transform = `translate(${tx}px,${ty}px) scale(${z})`;
        });
      },
      /** Position animée d'un élément : [[t, x, y, durée]]. */
      move(el, list) {
        const k = list.map(x => [x[0], [x[1], x[2]], x[3]]);
        tracks.push(t => { const [x, y] = keys(k, t, [0, 0]); el.style.left = x + "px"; el.style.top = y + "px"; });
      },
      /** Valeur numérique animée appliquée par fn(v). */
      anim(list, fn) { tracks.push(t => fn(keys(list, t, list[0][1]), t)); },
      /** Clic (onde) à l'instant t, à la position (x, y) du calque. */
      click(layer, t, x, y, n) {
        for (let k = 0; k < (n || 1); k++) {
          const r = $("div", "ripple", layer);
          const t0 = t + k * 0.22;
          tracks.push(tt => {
            const p = (tt - t0) / 0.55;
            r.style.display = p >= 0 && p <= 1 ? "block" : "none";
            if (p >= 0 && p <= 1) { const s = 1 + p * 4; r.style.left = x + "px"; r.style.top = y + "px"; r.style.transform = `scale(${s})`; r.style.opacity = 1 - p; }
          });
        }
      },
      /** Repère (cercle orange) autour d'une zone, visible entre a et b. */
      ring(layer, x, y, r, a, b) {
        const e = $("div", "ring", layer, { left: (x - r) + "px", top: (y - r) + "px", width: 2 * r + "px", height: 2 * r + "px" });
        tracks.push(t => { const v = window01(t, a, b, 0.3); e.style.opacity = v; const p = (t - a) * 3; e.style.transform = `scale(${1 + 0.06 * Math.sin(p)})`; e.style.display = v > 0 ? "block" : "none"; });
        return e;
      },
      /** Carte d'explication (au-dessus de la scène) : glisse et apparaît entre a et b. */
      card(html, a, b, pos) {
        const c = $("div", "card", E.hud, Object.assign({ right: "40px", top: "130px" }, pos || {}));
        c.innerHTML = html;
        tracks.push(t => { const v = window01(t, a, b, 0.4); c.style.opacity = v; c.style.display = v > 0 ? "block" : "none"; c.style.transform = `translateY(${(1 - easeOut((t - a) / 0.5)) * 30}px)`; });
        return c;
      },
    };
    return E;
  }

  // ------------------------------------------------------------------ lecteur
  const params = new URLSearchParams(location.search);
  const bridge = window.HM_ANDROID || null;      // pont Android (voix de synthèse, fermeture)

  window.HM = {
    lesson(L) {
      const stage = $("div", "", document.body); stage.id = "stage";
      const world = $("div", "", stage); world.id = "world";
      const hud = $("div", "", stage); hud.id = "hud";
      const E = api(L); E.world = world; E.hud = hud;
      // durée de chaque phrase : calée sur la voix, ou estimée pour la voix de synthèse
      if (!L.sentences) {
        let t = 0.6; L.sentences = L.texts.map(s => { const d = Math.max(1.6, s.length / 14.5); const r = [t, t + d]; t += d + 0.55; return r; });
      }
      L.duration = L.duration || L.sentences[L.sentences.length - 1][1] + 1.2;
      L.build(E);
      // étape en cours
      const step = $("div", "step fade", hud);
      const steps = L.steps || [];
      tracks.push(t => {
        let k = -1;
        for (let i = 0; i < steps.length; i++) if (t >= E.S(steps[i][0]) - 0.25) k = i;
        step.style.opacity = k >= 0 ? 1 : 0;
        if (k >= 0) {
          const html = `<b>Étape ${k + 1} sur ${steps.length}</b>${steps[k][1]}`;
          if (step._h !== html) { step.innerHTML = html; step._h = html; step.animate([{ transform: "translateX(-14px)", opacity: .3 }, { transform: "none", opacity: 1 }], { duration: 350, easing: "ease-out" }); }
        }
      });
      fit(stage);
      window.addEventListener("resize", () => fit(stage));
      const render = t => { for (const f of tracks) f(t); };

      // --- image fixe (contrôle et captures) ---
      window.HM.duration = L.duration;
      if (params.has("t")) { render(parseFloat(params.get("t"))); document.body.dataset.ready = "1"; window.HM.render = render; return; }

      // --- commandes ---
      const big = $("button", "", document.body); big.id = "big"; big.innerHTML = "&#9654;";
      const back = $("button", "", document.body); back.id = "back"; back.innerHTML = "&#8592;";
      back.onclick = () => { if (bridge && bridge.close) bridge.close(); else history.back(); };
      const bar = $("div", "", document.body); bar.id = "controls";
      const pp = $("button", "", bar); pp.innerHTML = "&#10074;&#10074;";
      const rr = $("button", "", bar); rr.innerHTML = "&#8634;";
      const track = $("div", "", bar); track.id = "bar"; const fill = $("i", "", track); const knob = $("b", "", track);
      const time = $("div", "", bar); time.id = "time";
      const fmt = s => `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, "0")}`;

      // horloge : l'audio de la voix, ou une horloge interne + voix de synthèse phrase par phrase
      const audio = L.audio ? new Audio(L.audio) : null;
      let playing = false, t0 = 0, tStart = 0, spoken = -1, hideTimer = 0;
      const now = () => audio ? audio.currentTime : (playing ? tStart + (performance.now() - t0) / 1000 : tStart);
      function play() {
        if (now() >= L.duration - 0.05) seek(0);
        playing = true; big.classList.add("hide"); pp.innerHTML = "&#10074;&#10074;";
        if (audio) audio.play(); else { t0 = performance.now(); }
        autoHide();
      }
      function pause() {
        if (!audio) tStart = now();
        playing = false; pp.innerHTML = "&#9654;"; bar.classList.remove("hide");
        if (audio) audio.pause();
        if (bridge && bridge.stop) bridge.stop();
      }
      function seek(t) {
        t = clamp(t, 0, L.duration);
        if (audio) audio.currentTime = t; else { tStart = t; t0 = performance.now(); }
        spoken = -1; if (bridge && bridge.stop) bridge.stop();
        L.sentences.forEach((s, i) => { if (s[1] < t) spoken = i; });
      }
      function autoHide() { clearTimeout(hideTimer); hideTimer = setTimeout(() => { if (playing) bar.classList.add("hide"); }, 2600); }
      window.HM.pause = () => { if (playing) pause(); };
      big.onclick = play;
      pp.onclick = e => { e.stopPropagation(); playing ? pause() : play(); };
      rr.onclick = e => { e.stopPropagation(); seek(0); play(); };
      stage.onclick = () => { bar.classList.toggle("hide"); autoHide(); };
      const scrub = e => { const r = track.getBoundingClientRect(); const x = (e.touches ? e.touches[0].clientX : e.clientX); seek((x - r.left) / r.width * L.duration); render(now()); };
      track.addEventListener("click", e => { e.stopPropagation(); scrub(e); });
      track.addEventListener("touchmove", e => { e.stopPropagation(); scrub(e); }, { passive: true });
      if (audio) audio.onended = () => { playing = false; pp.innerHTML = "&#9654;"; bar.classList.remove("hide"); big.innerHTML = "&#8634;"; big.classList.remove("hide"); };

      function frame() {
        let t = now();
        if (!audio && playing && t >= L.duration) { playing = false; tStart = L.duration; t = L.duration; big.innerHTML = "&#8634;"; big.classList.remove("hide"); bar.classList.remove("hide"); }
        // voix de synthèse : lit chaque phrase à son début
        if (!audio && playing && bridge && bridge.speak) {
          for (let i = spoken + 1; i < L.sentences.length; i++) {
            if (t >= L.sentences[i][0]) { bridge.speak(L.texts[i]); spoken = i; } else break;
          }
        }
        render(t);
        const p = t / L.duration * 100;
        fill.style.width = p + "%"; knob.style.left = p + "%";
        time.textContent = `${fmt(t)} / ${fmt(L.duration)}`;
        requestAnimationFrame(frame);
      }
      render(0);
      requestAnimationFrame(frame);
      document.body.dataset.ready = "1";
      if (params.get("autoplay") === "1") play();
    }
  };

  function fit(stage) {
    const s = Math.min(innerWidth / W, innerHeight / H);
    stage.style.transform = `translate(${-W * s / 2}px, ${-H * s / 2}px) scale(${s})`;
  }
})();
