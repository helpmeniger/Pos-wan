/* =====================================================================
   MARWA — navigation intégrée (chauffeur / livreur)
   Guidage étape par étape sans quitter l'application :
   - itinéraire calculé par le serveur (OSRM) depuis la position GPS réelle ;
   - suivi de la position, distance jusqu'à la prochaine manœuvre, heure d'arrivée ;
   - recalcul automatique en cas d'écart de route ;
   - annonces vocales facultatives (synthèse vocale du téléphone).
   Sans OSRM, une ligne directe et la direction à suivre sont affichées (signalé « approximatif »).
   ===================================================================== */
(function () {
  'use strict';
  const MW = window.MW;
  const R = Math.PI / 180;

  const toXY = (p, lat0) => [p[1] * 111320 * Math.cos(lat0 * R), p[0] * 110540];
  const hav = (a, b) => { const dLat = (b[0] - a[0]) * R, dLng = (b[1] - a[1]) * R;
    const x = Math.sin(dLat / 2) ** 2 + Math.cos(a[0] * R) * Math.cos(b[0] * R) * Math.sin(dLng / 2) ** 2; return 12742000 * Math.asin(Math.sqrt(x)); };
  const bearing = (a, b) => { const y = Math.sin((b[1] - a[1]) * R) * Math.cos(b[0] * R);
    const x = Math.cos(a[0] * R) * Math.sin(b[0] * R) - Math.sin(a[0] * R) * Math.cos(b[0] * R) * Math.cos((b[1] - a[1]) * R); return (Math.atan2(y, x) / R + 360) % 360; };
  const COMPASS = ['le nord', 'le nord-est', 'l\'est', 'le sud-est', 'le sud', 'le sud-ouest', 'l\'ouest', 'le nord-ouest'];
  const compass = (deg) => COMPASS[Math.round(deg / 45) % 8];
  const ANGLE = { straight: 0, 'slight right': 40, right: 90, 'sharp right': 135, uturn: 180, 'sharp left': -135, left: -90, 'slight left': -40 };

  /** Géométrie d'un itinéraire : projection d'une position sur la ligne, distances cumulées. */
  class Line {
    constructor(points) {
      this.pts = points; this.lat0 = points.length ? points[0][0] : 13.5;
      this.xy = points.map((p) => toXY(p, this.lat0));
      this.cum = [0];
      for (let i = 1; i < this.xy.length; i++) this.cum.push(this.cum[i - 1] + Math.hypot(this.xy[i][0] - this.xy[i - 1][0], this.xy[i][1] - this.xy[i - 1][1]));
      this.length = this.cum[this.cum.length - 1] || 0;
    }
    project(p) {
      const q = toXY(p, this.lat0);
      let best = { dist: Infinity, along: 0 };
      for (let i = 0; i < this.xy.length - 1; i++) {
        const a = this.xy[i], b = this.xy[i + 1];
        const dx = b[0] - a[0], dy = b[1] - a[1]; const len2 = dx * dx + dy * dy;
        const t = len2 ? Math.max(0, Math.min(1, ((q[0] - a[0]) * dx + (q[1] - a[1]) * dy) / len2)) : 0;
        const d = Math.hypot(q[0] - (a[0] + t * dx), q[1] - (a[1] + t * dy));
        if (d < best.dist) best = { dist: d, along: this.cum[i] + t * Math.sqrt(len2) };
      }
      return best;
    }
  }

  const arrowSvg = (step, relBearing) => {
    if (step && (step.type === 'arrive')) return `<svg viewBox="0 0 48 48"><path d="M24 44s-13-12-13-22a13 13 0 0126 0c0 10-13 22-13 22z" fill="currentColor"/><circle cx="24" cy="22" r="5" fill="#0b6e4f"/></svg>`;
    if (step && /roundabout|rotary/.test(step.type)) return `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"><circle cx="24" cy="22" r="8"/><path d="M24 44V30M32 22h9m-5-5l5 5-5 5"/></svg>`;
    if (step && step.modifier === 'uturn') return `<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 42V18a8 8 0 0116 0v12m-7-6l7 7 7-7"/></svg>`;
    const angle = step ? (ANGLE[step.modifier] ?? 0) : relBearing;
    return `<svg viewBox="0 0 48 48" style="transform:rotate(${Math.round(angle || 0)}deg)"><path d="M24 4l14 18h-9v22h-10V22h-9z" fill="currentColor"/></svg>`;
  };

  MW.Navigator = class {
    /**
     * opts : { orderId, gps (objet GPS de l'espace prestataire), vehicle ('car'|'moto'…),
     *          action: { label, run } | null (bouton principal, ex. « Je suis arrivé ») }
     */
    constructor(opts) {
      this.o = opts; this.route = null; this.line = null; this.stepAlong = []; this.follow = true;
      this.offCount = 0; this.lastFetch = 0; this.fetching = false; this.announced = {}; this.arrived = false;
      try { this.voice = localStorage.getItem('mw:navVoice') !== '0'; } catch (e) { this.voice = true; }
      this.onGps = () => this.update();
    }

    async open() {
      const el = document.createElement('div'); el.className = 'nav-screen'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-label', 'Navigation');
      el.innerHTML = `<div class="nav-top"><div class="nav-arrow" data-arrow></div><div class="grow"><div class="nav-dist" data-ndist>…</div><div class="nav-instr" data-instr>Calcul de l'itinéraire…</div></div></div>
        <div class="nav-then" data-then hidden></div>
        <div class="nav-map-wrap"><div class="nav-map" data-map></div>
          <button class="nav-fab" data-recenter aria-label="Recentrer" hidden>${MW.icon('locate')}</button>
          <div class="nav-approx" data-approx hidden>Itinéraire routier indisponible : direction à vol d'oiseau (approximative).</div></div>
        <div class="nav-bottom">
          <div class="nav-info"><div class="grow" style="min-width:0"><div class="nav-eta"><strong data-eta>—</strong> <span class="muted" data-left></span> · <span data-rem></span></div>
            <div class="small muted ellipsis" data-target></div></div>
            <div class="nav-tools"><button class="icon-btn" data-voice aria-label="Annonces vocales">${this.voiceIcon()}</button>
            <button class="icon-btn" data-close aria-label="Quitter la navigation">${MW.icon('x')}</button></div></div>
          ${this.o.action ? `<button class="btn btn-primary btn-block btn-lg" data-main style="margin-top:8px">${MW.esc(this.o.action.label)}</button>` : ''}
        </div>`;
      document.body.appendChild(el); document.body.classList.add('nav-open');
      this.el = el;
      el.querySelector('[data-close]').onclick = () => this.close();
      el.querySelector('[data-voice]').onclick = (e) => { this.voice = !this.voice; try { localStorage.setItem('mw:navVoice', this.voice ? '1' : '0'); } catch (x) { /* privé */ } e.currentTarget.innerHTML = this.voiceIcon(); if (!this.voice) speechSynthesis?.cancel(); };
      el.querySelector('[data-recenter]').onclick = () => { this.follow = true; el.querySelector('[data-recenter]').hidden = true; this.update(); };
      el.querySelector('[data-main]')?.addEventListener('click', () => { const a = this.o.action; this.close(); a && a.run(); });
      this.map = await MW.createMap(el.querySelector('[data-map]'), { zoom: 16 });
      if (this.map) this.map.on('dragstart', () => { this.follow = false; el.querySelector('[data-recenter]').hidden = false; });
      this.o.gps.start(); this.o.gps.on(this.onGps);
      this.history = () => this.close();
      window.addEventListener('popstate', this.history);
      await this.fetch();
      this.timer = setInterval(() => { if (Date.now() - this.lastFetch > 90000) this.fetch(); }, 15000);
    }

    voiceIcon() { return this.voice ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M4 9h4l5-4v14l-5-4H4zM16 9a4 4 0 010 6m2.5-8.5a7.5 7.5 0 010 11"/></svg>' : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"><path d="M4 9h4l5-4v14l-5-4H4zM17 10l4 4m0-4l-4 4"/></svg>'; }

    close() {
      clearInterval(this.timer);
      this.o.gps.listeners = this.o.gps.listeners.filter((f) => f !== this.onGps);
      window.removeEventListener('popstate', this.history);
      try { speechSynthesis && speechSynthesis.cancel(); } catch (e) { /* non pris en charge */ }
      this.el && this.el.remove(); document.body.classList.remove('nav-open');
      this.o.onClose && this.o.onClose();
    }

    async fetch() {
      const g = this.o.gps.last;
      if (!g || this.fetching) { if (!g) this.setInstr('En attente du signal GPS…', '', null); return; }
      this.fetching = true; this.lastFetch = Date.now();
      try {
        const r = await MW.get(`/provider/orders/${this.o.orderId}/navigation?lat=${g.lat}&lng=${g.lng}`, { retries: 1 });
        this.route = r; this.line = r.geometry && r.geometry.length > 1 ? new Line(r.geometry) : null;
        this.stepAlong = (r.steps || []).map((s) => (s.location && this.line ? this.line.project(s.location).along : 0));
        this.announced = {}; this.offCount = 0;
        this.draw(); this.update(true);
      } catch (e) { this.setInstr(e.message || 'Itinéraire indisponible.', '', null); }
      finally { this.fetching = false; }
    }

    draw() {
      if (!this.map || !this.route) return;
      const r = this.route;
      this.layers && this.layers.forEach((l) => l.remove());
      this.layers = [];
      if (!r.approximate && this.line) {
        this.layers.push(L.polyline(r.geometry, { color: '#0d47a1', weight: 9, opacity: .25 }).addTo(this.map));
        this.layers.push(L.polyline(r.geometry, { color: '#1f6fb2', weight: 6, opacity: .95 }).addTo(this.map));
      }
      this.layers.push(L.marker([r.target.lat, r.target.lng], { icon: MW.pinIcon(r.target.kind === 'dropoff' ? 'dropoff' : r.target.kind === 'merchant' ? 'merchant' : 'pickup') }).addTo(this.map));
      this.el.querySelector('[data-target]').textContent = (r.target.kind === 'dropoff' ? 'Destination : ' : r.target.kind === 'merchant' ? 'Commerce : ' : 'Prise en charge : ') + r.target.text;
      this.el.querySelector('[data-approx]').hidden = !r.approximate;
    }

    update(force) {
      const g = this.o.gps.last;
      if (!g || !this.map) return;
      const pos = [g.lat, g.lng];
      if (!this.me) this.me = L.marker(pos, { icon: MW.vehicleIcon(this.o.vehicle, g.heading || 0), zIndexOffset: 1000 }).addTo(this.map);
      else { this.me.setIcon(MW.vehicleIcon(this.o.vehicle, g.heading || 0)); MW.glide(this.me, pos, 700); }
      if (this.follow) this.map.setView(pos, Math.max(this.map.getZoom(), 16), { animate: !force });
      const r = this.route; if (!r) return;
      const target = [r.target.lat, r.target.lng];
      const toTarget = hav(pos, target);

      if (toTarget < 35) {
        if (!this.arrived) { this.arrived = true; navigator.vibrate && navigator.vibrate([300, 120, 300]); this.say('Vous êtes arrivé.'); }
        this.setInstr('Vous êtes arrivé', r.target.text, { type: 'arrive' });
        this.setEta(0, 0);
        return;
      }
      this.arrived = false;

      if (r.approximate || !this.line || !(r.steps || []).length) {
        // Mode dégradé honnête : direction et distance à vol d'oiseau
        const b = bearing(pos, target);
        const rel = g.heading !== null && g.heading !== undefined ? b - g.heading : b;
        if (this.direct) this.direct.setLatLngs([pos, target]); else this.direct = L.polyline([pos, target], { color: '#1f6fb2', weight: 4, dashArray: '8 10' }).addTo(this.map);
        this.setInstr(`Dirigez-vous vers ${compass(b)}`, MW.dist(toTarget) + ' à vol d\'oiseau', null, rel);
        const est = toTarget * 1.35;
        this.setEta(est, r.distance_m ? r.duration_s * est / Math.max(1, r.distance_m) : est / 6.5);
        return;
      }

      const pr = this.line.project(pos);
      // Écart de route : recalcul après deux positions consécutives à plus de 50 m (si le GPS est précis)
      if (pr.dist > 50 && (g.accuracy || 0) < 60) {
        this.offCount++;
        if (this.offCount >= 2 && Date.now() - this.lastFetch > 8000) { this.setInstr('Recalcul de l\'itinéraire…', '', null); this.fetch(); return; }
      } else this.offCount = 0;

      const steps = r.steps;
      let i = steps.findIndex((s, k) => k > 0 && this.stepAlong[k] > pr.along + 8);
      if (i < 0) i = steps.length - 1;
      const step = steps[i];
      const toNext = Math.max(0, this.stepAlong[i] - pr.along);
      this.setInstr(step.instruction, MW.dist(toNext), step);
      const then = steps[i + 1];
      const thenEl = this.el.querySelector('[data-then]');
      if (then && (this.stepAlong[i + 1] - this.stepAlong[i]) < 150 && then.type !== 'arrive') { thenEl.hidden = false; thenEl.textContent = 'Puis : ' + then.instruction; } else thenEl.hidden = true;
      const remaining = Math.max(0, this.line.length - pr.along);
      this.setEta(remaining, r.duration_s * remaining / Math.max(1, this.line.length));
      // Annonces vocales : à l'approche (~200 m) puis juste avant la manœuvre (~40 m)
      if (toNext < 220 && toNext > 60 && !this.announced[i + 'a']) { this.announced[i + 'a'] = 1; this.say(`Dans ${MW.dist(Math.round(toNext / 10) * 10)}, ${step.instruction}`); }
      if (toNext <= 45 && !this.announced[i + 'b']) { this.announced[i + 'b'] = 1; this.say(step.instruction); }
    }

    setInstr(text, dist, step, relBearing) {
      if (!this.el) return;
      this.el.querySelector('[data-instr]').textContent = text;
      this.el.querySelector('[data-ndist]').textContent = dist || '';
      this.el.querySelector('[data-arrow]').innerHTML = arrowSvg(step, relBearing);
    }

    setEta(remainingM, remainingS) {
      const at = new Date(Date.now() + remainingS * 1000);
      this.el.querySelector('[data-eta]').textContent = MW.time(at);
      this.el.querySelector('[data-left]').textContent = MW.dur(remainingS);
      this.el.querySelector('[data-rem]').textContent = MW.dist(remainingM);
    }

    say(text) {
      if (!this.voice || !('speechSynthesis' in window)) return;
      try {
        const lang = (document.documentElement.lang || 'fr').slice(0, 2);
        const voices = speechSynthesis.getVoices();
        const voice = voices.find((v) => v.lang && v.lang.toLowerCase().startsWith(lang)) || null;
        if (!voice && voices.length && lang !== 'fr') return; // pas de voix pour cette langue : affichage seul
        const u = new SpeechSynthesisUtterance(text); u.lang = voice ? voice.lang : 'fr-FR'; if (voice) u.voice = voice; u.rate = 1;
        speechSynthesis.cancel(); speechSynthesis.speak(u);
      } catch (e) { /* synthèse vocale indisponible */ }
    }
  };
})();
