/* Décors et schémas partagés par les leçons animées HELP-ME. */
window.SC = (function () {
  "use strict";

  /** Bureau : pièce, table, écran (éteint par défaut), clavier, souris, pot à crayons, unité centrale, prise. */
  function deskSet(E, opt) {
    opt = opt || {};
    const $ = E.$, set = E.set();
    $("div", "room", set);
    $("div", "window-light", set, { left: "300px" }); $("div", "window-light", set, { left: "1650px" });
    $("div", "desk", set);
    const r = {};
    if (opt.outlet) {
      r.outlet = $("div", "outlet", set);
      $("div", "sock", r.outlet, { top: "18px" }); $("div", "sock", r.outlet, { top: "94px" });
      r.cable = $("div", "cable", set);
      r.plug = $("div", "plug", set);
    }
    if (opt.tower) {
      r.tower = $("div", "tower", set);
      $("div", "grill", r.tower);
      r.button = $("div", "powerbtn", r.tower); r.button.innerHTML = E.svgPower(52, "#9fb4cc", 5);
      $("div", "slot", r.tower); $("div", "usb", r.tower);
    } else {
      $("div", "pencils", set);
    }
    r.monitor = $("div", "monitor", set);
    r.screen = $("div", "screen", r.monitor);
    r.led = $("div", "led", r.monitor);
    $("div", "stand", set); $("div", "foot", set);
    r.keyboard = $("div", "keyboard", set);
    for (let y = 0; y < 3; y++) for (let x = 0; x < 15; x++) $("i", "", r.keyboard, { left: 16 + x * 52 + "px", top: 12 + y * 26 + "px" });
    r.mouse = $("div", "mousedev", set);
    r.set = set;
    return r;
  }

  /** Ordinateur portable (pièce aux tons roses, comme dans les vidéos d'origine). */
  function laptopSet(E) {
    const $ = E.$, set = E.set("laptop");
    $("div", "room pink", set);
    $("div", "desk", set, { top: "760px" });
    const r = { set };
    r.lscreen = $("div", "lscreen", set);
    r.screen = $("div", "screen", r.lscreen);
    $("div", "base", set); $("div", "deck", set);
    r.touchpad = $("div", "touchpad", set);
    r.pwr = $("div", "pwr", set);
    return r;
  }

  /** Schéma de souris (SVG) : met en valeur la partie utilisée. */
  function mouseSVG(part) {
    const hl = "#326fbf", base = "#f2f4f8", line = "#465063";
    const L = part === "left" || part === "double" ? hl : base, R = part === "right" ? hl : base, Wh = part === "wheel" ? hl : "#7a8496";
    return `<svg width="250" height="330" viewBox="0 0 250 330">
      <rect x="35" y="15" width="180" height="300" rx="88" fill="${base}" stroke="${line}" stroke-width="6"/>
      <path d="M41 103 L125 103 L125 21 C 75 21 41 50 41 103 Z" fill="${L}"/>
      <path d="M209 103 L125 103 L125 21 C 175 21 209 50 209 103 Z" fill="${R}"/>
      <line x1="125" y1="18" x2="125" y2="105" stroke="${line}" stroke-width="5"/>
      <line x1="38" y1="105" x2="212" y2="105" stroke="${line}" stroke-width="5"/>
      <rect x="112" y="38" width="26" height="48" rx="13" fill="${Wh}"/>
      ${part === "double" ? '<text x="83" y="78" font-size="34" font-weight="800" fill="#fff">×2</text>' : ""}
    </svg>`;
  }

  /** Carte de souris dont la partie mise en valeur suit le temps. */
  function mouseCard(E, plan) {           // plan: [[t0, t1, part, titre], ...]
    const cards = plan.map(([a, b, part, title]) => E.card(`${title}<svg width="250" height="330"></svg><small></small>`, a, b, { top: "120px", width: "330px" }));
    plan.forEach(([a, b, part], i) => {
      const c = cards[i], holder = c.querySelector("svg");
      holder.outerHTML = mouseSVG(part);
      if (part === "move") {
        const svg = c.querySelector("svg");
        E.track(t => { if (t >= a && t <= b) svg.style.transform = `translateX(${Math.sin((t - a) * 3.4) * 26}px)`; });
      }
      if (part === "wheel") {
        const svg = c.querySelector("svg");
        E.track(t => { if (t >= a && t <= b) svg.querySelector("rect:nth-of-type(2)").setAttribute("y", 38 + ((t - a) * 30 % 14)); });
      }
      if (part === "left" || part === "double" || part === "right") {
        const svg = c.querySelector("svg");
        E.track(t => { if (t >= a && t <= b) { const k = (Math.sin((t - a) * (part === "double" ? 14 : 7)) + 1) / 2; svg.style.filter = `brightness(${1 + 0.15 * k})`; } });
      }
    });
  }

  /** Carte du pavé tactile : doigts qui glissent ou tapotent. */
  function touchCard(E, a, b, title, gesture) {
    const c = E.card(`${title}<svg width="360" height="230" viewBox="0 0 360 230">
       <rect x="10" y="10" width="340" height="210" rx="26" fill="#e7ebf2" stroke="#9ea7b5" stroke-width="5"/>
       <circle class="f1" r="24" fill="#ffcfac" stroke="#c98a6e" stroke-width="4"/>
       <circle class="f2" r="24" fill="#ffcfac" stroke="#c98a6e" stroke-width="4"/>
       <circle class="w1" r="30" fill="none" stroke="#326fbf" stroke-width="5"/>
       <circle class="w2" r="30" fill="none" stroke="#326fbf" stroke-width="5"/>
       <path class="arr" d="M325 40 l-14 24 h28 z M325 190 l-14 -24 h28 z" fill="#326fbf"/></svg>`, a, b, { top: "120px", width: "440px" });
    const q = s => c.querySelector(s);
    const two = gesture === "two" || gesture === "scroll";
    q(".f2").style.display = two ? "" : "none"; q(".w2").style.display = gesture === "two" ? "" : "none";
    q(".w1").style.display = gesture === "tap" || gesture === "two" ? "" : "none";
    q(".arr").style.display = gesture === "scroll" ? "" : "none";
    E.track(t => {
      if (t < a || t > b) return;
      const ph = ((t - a) % 1.6) / 1.6;
      let x = 180, y = 115;
      if (gesture === "move") x = 70 + 220 * E.ease(1 - Math.abs(2 * ph - 1));
      if (gesture === "scroll") y = 50 + 130 * E.ease(ph);
      const set = (el, cx, cy) => { el.setAttribute("cx", cx); el.setAttribute("cy", cy); };
      set(q(".f1"), two ? x - 38 : x, y); set(q(".f2"), x + 38, y);
      const r = 26 + 60 * ph;
      [q(".w1"), q(".w2")].forEach((w, i) => { set(w, two ? x + (i ? 38 : -38) : x, y); w.setAttribute("r", r); w.style.opacity = 1 - ph; });
    });
  }

  return { deskSet, laptopSet, mouseCard, touchCard, mouseSVG };
})();
