/* =====================================================================
   MARWA — cartographie (Leaflet + OpenStreetMap)
   - icônes de véhicules orientées selon le cap GPS
   - sélecteur de lieu : recherche, position GPS, clic sur la carte,
     repères, adresses favorites, saisie manuelle
   ===================================================================== */
(function () {
  'use strict';
  const MW = window.MW;
  let configPromise = null;

  MW.geoConfig = function () {
    if (!configPromise) {
      configPromise = MW.get('/geo/config', { cache: true }).catch(() => ({ tile_url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', attribution: '© OpenStreetMap', center: [13.5127, 2.1126], zoom: 13, cities: [] }));
    }
    return configPromise;
  };

  MW.createMap = async function (el, opts = {}) {
    if (!window.L) { el.innerHTML = '<div class="empty">Carte indisponible (bibliothèque non chargée).</div>'; return null; }
    const cfg = await MW.geoConfig();
    const map = L.map(el, { zoomControl: true, attributionControl: true, preferCanvas: true }).setView(opts.center || cfg.center, opts.zoom || cfg.zoom);
    L.tileLayer(cfg.tile_url, { maxZoom: 19, attribution: cfg.attribution, crossOrigin: true }).addTo(map);
    setTimeout(() => map.invalidateSize(), 150);
    return map;
  };

  // ------------------------------------------------------------------ Icônes
  const VEH = {
    car: (c) => `<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="22" fill="#fff"/><g transform="translate(24 24)"><rect x="-8" y="-15" width="16" height="30" rx="6" fill="${c}"/><rect x="-6" y="-9" width="12" height="7" rx="2" fill="#cfe9ff"/><rect x="-6" y="5" width="12" height="5" rx="2" fill="#cfe9ff"/><rect x="-9.5" y="-11" width="3" height="5" rx="1.2" fill="#222"/><rect x="6.5" y="-11" width="3" height="5" rx="1.2" fill="#222"/><rect x="-9.5" y="6" width="3" height="5" rx="1.2" fill="#222"/><rect x="6.5" y="6" width="3" height="5" rx="1.2" fill="#222"/><circle cx="-4" cy="-14" r="1.4" fill="#ffe08a"/><circle cx="4" cy="-14" r="1.4" fill="#ffe08a"/></g></svg>`,
    moto: (c) => `<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="22" fill="#fff"/><g transform="translate(24 24)"><rect x="-2.6" y="-17" width="5.2" height="9" rx="2.5" fill="#222"/><rect x="-2.6" y="8" width="5.2" height="9" rx="2.5" fill="#222"/><rect x="-4.5" y="-9" width="9" height="18" rx="4" fill="${c}"/><rect x="-9" y="-11" width="18" height="2.6" rx="1.3" fill="#333"/><circle cx="0" cy="0" r="4" fill="#ffd166"/></g></svg>`,
    bicycle: (c) => `<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="22" fill="#fff"/><g transform="translate(24 24)"><rect x="-1.8" y="-16" width="3.6" height="10" rx="1.8" fill="#222"/><rect x="-1.8" y="6" width="3.6" height="10" rx="1.8" fill="#222"/><rect x="-1.5" y="-8" width="3" height="16" fill="${c}"/><rect x="-8" y="-9" width="16" height="2.4" rx="1.2" fill="#333"/><circle r="3.4" fill="#ffd166"/></g></svg>`,
    van: (c) => `<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="22" fill="#fff"/><g transform="translate(24 24)"><rect x="-9" y="-17" width="18" height="34" rx="4" fill="${c}"/><rect x="-7" y="-13" width="14" height="6" rx="2" fill="#cfe9ff"/><rect x="-10.5" y="-12" width="3" height="5" rx="1.2" fill="#222"/><rect x="7.5" y="-12" width="3" height="5" rx="1.2" fill="#222"/><rect x="-10.5" y="8" width="3" height="5" rx="1.2" fill="#222"/><rect x="7.5" y="8" width="3" height="5" rx="1.2" fill="#222"/></g></svg>`
  };
  MW.vehicleIcon = function (type, heading, opts = {}) {
    const color = opts.color || (type === 'moto' || type === 'bicycle' ? '#d98a0b' : '#0b6e4f');
    const svg = (VEH[type] || VEH.car)(color);
    return L.divIcon({ className: 'mw-marker', iconSize: [44, 44], iconAnchor: [22, 22],
      html: `<div class="mw-vehicle ${opts.stale ? 'stale' : ''} ${opts.nearby ? 'nearby' : ''}" style="transform:rotate(${Number(heading) || 0}deg)">${svg}</div>` });
  };
  MW.pinIcon = function (kind) {
    const c = kind === 'dropoff' ? '#f4a623' : kind === 'merchant' ? '#1f6fb2' : '#0b6e4f';
    const inner = kind === 'dropoff' ? '<rect x="10" y="9" width="10" height="10" rx="2" fill="#fff"/>' : '<circle cx="15" cy="14" r="5" fill="#fff"/>';
    return L.divIcon({ className: 'mw-marker', iconSize: [30, 40], iconAnchor: [15, 39],
      html: `<div class="mw-pin"><svg viewBox="0 0 30 40"><path d="M15 39C15 39 2 23 2 14a13 13 0 0126 0c0 9-13 25-13 25z" fill="${c}" stroke="#fff" stroke-width="2"/>${inner}</svg></div>` });
  };
  MW.meIcon = () => L.divIcon({ className: 'mw-marker', iconSize: [22, 22], iconAnchor: [11, 11], html: '<div class="mw-me"></div>' });

  /** Déplace un marqueur en douceur entre deux positions réelles (aucune position inventée). */
  MW.glide = function (marker, to, ms = 900) {
    const from = marker.getLatLng();
    if (!from || from.distanceTo(to) > 2000) { marker.setLatLng(to); return; }
    const start = performance.now();
    const step = (t) => {
      const k = Math.min(1, (t - start) / ms);
      marker.setLatLng([from.lat + (to[0] - from.lat) * k, from.lng + (to[1] - from.lng) * k]);
      if (k < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };

  // ------------------------------------------------------------------ Géolocalisation
  MW.locate = (opts = {}) => new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error('La géolocalisation n\'est pas disponible sur cet appareil.'));
    navigator.geolocation.getCurrentPosition((p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy }),
      (e) => reject(new Error(e.code === 1 ? 'Autorisation de localisation refusée. Saisissez l\'adresse ou touchez la carte.' : 'Position introuvable. Saisissez l\'adresse ou touchez la carte.')),
      { enableHighAccuracy: true, timeout: opts.timeout || 12000, maximumAge: 30000 });
  });

  // ------------------------------------------------------------------ Autorisation de localisation
  /** État de l'autorisation : 'granted' | 'prompt' | 'denied' | 'unsupported'. */
  MW.geoPermission = async function () {
    if (!navigator.geolocation) return 'unsupported';
    try { if (navigator.permissions) return (await navigator.permissions.query({ name: 'geolocation' })).state; } catch (e) { /* Safari ancien */ }
    return 'prompt';
  };
  const deniedHelp = () => `<p>La localisation est <strong>bloquée</strong> pour MARWA. Pour l'activer :</p>
    <ol class="small" style="padding-left:18px">
      <li><strong>Android (Chrome)</strong> : touchez le cadenas à gauche de l'adresse → <em>Autorisations</em> → <em>Position</em> → <em>Autoriser</em>.</li>
      <li><strong>iPhone (Safari)</strong> : Réglages → <em>Safari</em> → <em>Position</em> → <em>Demander</em> ou <em>Autoriser</em> ; vérifiez aussi Réglages → Confidentialité → Service de localisation.</li>
      <li>Vérifiez que la <strong>localisation (GPS)</strong> du téléphone est activée.</li></ol>
    <p class="small muted">Vous pouvez aussi saisir l'adresse ou toucher la carte.</p>`;
  /**
   * Explique pourquoi la position est demandée, puis déclenche la demande du navigateur.
   * Retourne la position, ou null si refusée / indisponible. opts.reason : texte affiché.
   */
  MW.askLocation = async function (opts = {}) {
    const state = await MW.geoPermission();
    if (state === 'unsupported') { if (!opts.silent) MW.toast('Cet appareil ne permet pas la géolocalisation.', 'warn'); return null; }
    if (state === 'denied') { if (!opts.silent) MW.modal('Activer la localisation', deniedHelp()); return null; }
    if (state === 'prompt' && !opts.skipExplain) {
      const ok = await new Promise((resolve) => {
        const m = MW.modal('Autoriser la localisation', `<div class="perm-explain">${MW.icon('locate')}
          <p>${MW.esc(opts.reason || 'MARWA utilise votre position pour trouver les taxis et livreurs les plus proches et vous situer sur la carte.')}</p>
          <p class="small muted">Votre position n'est partagée qu'avec le prestataire de votre commande. Votre téléphone va vous demander l'autorisation : choisissez <strong>Autoriser</strong>.</p></div>
          <div class="modal-actions"><button class="btn btn-outline" data-no>Plus tard</button><button class="btn btn-primary" data-yes>${MW.icon('locate')} Activer ma position</button></div>`, { onClose: () => resolve(false) });
        m.el.querySelector('[data-no]').onclick = () => m.close();
        m.el.querySelector('[data-yes]').onclick = () => { resolve(true); m.close(); };
      });
      if (!ok) return null;
    }
    try { return await MW.locate({ timeout: opts.timeout || 15000 }); } catch (e) {
      if ((await MW.geoPermission()) === 'denied') { if (!opts.silent) MW.modal('Activer la localisation', deniedHelp()); } else if (!opts.silent) MW.toast(e.message, 'warn');
      return null;
    }
  };

  // ------------------------------------------------------------------ Véhicules à proximité
  /**
   * Affiche en direct les véhicules libres autour d'un point (positions GPS réelles des
   * prestataires, approximatives à ~100 m, sans identité). center() → [lat, lng] ou null.
   */
  MW.NearbyLayer = class {
    constructor(map, { center, service = 'taxi', badge = null, every = 10000 }) {
      this.map = map; this.center = center; this.service = service; this.badge = badge; this.markers = new Map();
      this.timer = null; this.every = every; this.stopped = false;
      this.tick();
    }
    async tick() {
      if (this.stopped) return;
      const c = this.center();
      if (c && !document.hidden && this.map) {
        try {
          const d = await MW.get(`/nearby?lat=${c[0]}&lng=${c[1]}&service=${encodeURIComponent(this.service)}`, { retries: 0 });
          if (this.stopped) return;
          const seen = new Set();
          d.vehicles.forEach((v) => {
            seen.add(v.k);
            const icon = MW.vehicleIcon(v.icon, v.heading, { color: v.icon === 'moto' ? '#d98a0b' : '#1d6f5a', nearby: true });
            const m = this.markers.get(v.k);
            if (m) { m.setIcon(icon); MW.glide(m, [v.lat, v.lng], 1500); } else this.markers.set(v.k, L.marker([v.lat, v.lng], { icon, interactive: false, keyboard: false, zIndexOffset: 200 }).addTo(this.map));
          });
          this.markers.forEach((m, k) => { if (!seen.has(k)) { m.remove(); this.markers.delete(k); } });
          if (this.badge) {
            const label = this.service === 'taxi' || this.service === 'pro' ? (d.count > 1 ? 'taxis disponibles' : 'taxi disponible') : (d.count > 1 ? 'livreurs disponibles' : 'livreur disponible');
            this.badge.hidden = false;
            this.badge.innerHTML = d.count ? `${MW.icon('car')} <strong>${d.count}</strong> ${label} à proximité · ~${d.nearest_eta_min} min` : `${MW.icon('clock')} Aucun véhicule libre à proximité pour le moment`;
          }
        } catch (e) { /* réseau : nouvel essai au prochain cycle */ }
      }
      this.timer = setTimeout(() => this.tick(), this.every);
    }
    refresh() { clearTimeout(this.timer); this.tick(); }
    stop() { this.stopped = true; clearTimeout(this.timer); this.markers.forEach((m) => m.remove()); this.markers.clear(); if (this.badge) this.badge.hidden = true; }
  };

  MW.navLinks = (lat, lng) => `<div class="row"><a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="geo:${lat},${lng}?q=${lat},${lng}">${MW.icon('nav')} Navigation</a>
    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="https://www.openstreetmap.org/directions?route=%3B${lat}%2C${lng}#map=16/${lat}/${lng}">OpenStreetMap</a>
    <a class="btn btn-sm btn-outline" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}">Google Maps</a></div>`;

  // ------------------------------------------------------------------ Sélecteur de lieu
  /**
   * new MW.PlaceField(container, { kind: 'pickup'|'dropoff', label, map, favorites, onChange })
   * Valeur : { text, lat, lng }
   */
  MW.PlaceField = class {
    constructor(container, opts) {
      this.opts = opts; this.value = null; this.marker = null;
      const id = 'pl_' + Math.random().toString(36).slice(2, 7);
      container.innerHTML = `<div class="field" data-field="${opts.kind}_text"><label for="${id}">${MW.esc(opts.label)}</label>
        <div class="place-input"><span class="dot ${opts.kind === 'dropoff' ? 'dest' : ''}"></span>
          <input class="input" id="${id}" autocomplete="off" placeholder="${MW.esc(opts.placeholder || 'Quartier, repère, adresse…')}" aria-autocomplete="list">
          <div class="place-tools">${opts.kind === 'pickup' ? `<button type="button" title="Ma position" data-gps>${MW.icon('locate')}</button>` : ''}<button type="button" title="Choisir sur la carte" data-mappick>${MW.icon('pin')}</button></div>
          <div class="suggestions" hidden></div></div>
        <div class="hint" data-hint></div>
        ${opts.favorites && opts.favorites.length ? `<div class="chips">${opts.favorites.map((f, i) => `<button type="button" class="chip" data-fav="${i}">★ ${MW.esc(f.label)}</button>`).join('')}</div>` : ''}</div>`;
      this.input = container.querySelector('input');
      this.box = container.querySelector('.suggestions');
      this.hint = container.querySelector('[data-hint]');
      let timer = null;
      this.input.addEventListener('input', () => {
        this.value = this.input.value.trim() ? { text: this.input.value.trim(), lat: this.value?.lat ?? null, lng: this.value?.lng ?? null, manual: true } : null;
        clearTimeout(timer); timer = setTimeout(() => this.search(), 350);
        this.setHint(this.value && this.value.lat ? 'Position conservée. Touchez la carte pour l\'ajuster.' : 'Choisissez une suggestion ou placez le point sur la carte.');
      });
      this.input.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.box.hidden = true; });
      this.input.addEventListener('blur', () => setTimeout(() => (this.box.hidden = true), 200));
      container.querySelector('[data-gps]')?.addEventListener('click', () => this.useGps());
      container.querySelector('[data-mappick]').addEventListener('click', () => this.armMapPick());
      container.querySelectorAll('[data-fav]').forEach((b) => b.addEventListener('click', () => {
        const f = opts.favorites[Number(b.dataset.fav)];
        if (f.lat) this.set({ text: f.address_text, lat: Number(f.lat), lng: Number(f.lng) }, true); else { this.input.value = f.address_text; this.setHint('Adresse sans coordonnées : placez le point sur la carte.'); }
      }));
    }
    setHint(t, warn) { this.hint.textContent = t || ''; this.hint.style.color = warn ? 'var(--red-600)' : ''; }
    async search() {
      const q = this.input.value.trim();
      if (q.length < 2) { this.box.hidden = true; return; }
      try {
        const res = await MW.get('/geo/search?q=' + encodeURIComponent(q), { retries: 0 });
        this.box.innerHTML = res.length ? res.map((r, i) => `<button type="button" data-i="${i}"><div class="s-title">${MW.esc(r.label)}</div><div class="s-detail">${MW.esc(r.detail || '')}</div></button>`).join('')
          : '<div class="muted small" style="padding:10px 14px">Aucun résultat. Touchez la carte pour placer le point.</div>';
        this.box.hidden = false;
        this.box.querySelectorAll('[data-i]').forEach((b) => b.addEventListener('mousedown', (e) => { e.preventDefault(); const r = res[Number(b.dataset.i)]; this.set({ text: r.label, lat: r.lat, lng: r.lng }, true); this.box.hidden = true; }));
      } catch (e) { this.box.hidden = true; }
    }
    async useGps() {
      this.setHint('Localisation en cours…');
      const p = await MW.askLocation({ skipExplain: true });
      if (!p) { this.setHint('Position indisponible. Saisissez l\'adresse ou touchez la carte.', true); return; }
      this.set({ text: 'Ma position', lat: p.lat, lng: p.lng }, true);
      this.setHint(`Précision ≈ ${Math.round(p.accuracy)} m. Déplacez le point si besoin.`);
      this.reverse(p.lat, p.lng);
    }
    armMapPick() {
      const map = this.opts.map;
      if (!map) return;
      MW.toast('Touchez la carte à l\'endroit souhaité.');
      map.getContainer().style.cursor = 'crosshair';
      map.getContainer().scrollIntoView({ behavior: 'smooth', block: 'center' });
      map.once('click', (e) => { map.getContainer().style.cursor = ''; this.set({ text: 'Point choisi sur la carte', lat: e.latlng.lat, lng: e.latlng.lng }, true); this.reverse(e.latlng.lat, e.latlng.lng); });
    }
    async reverse(lat, lng) {
      try { const r = await MW.get(`/geo/reverse?lat=${lat}&lng=${lng}`, { retries: 0 }); if (this.value && this.value.lat === lat) { this.value.text = r.label; this.input.value = r.label; this.emit(); } } catch (e) { /* hors ligne : on garde les coordonnées */ }
    }
    set(v, pan) {
      this.value = { text: v.text, lat: Number(v.lat), lng: Number(v.lng) };
      this.input.value = v.text;
      const map = this.opts.map;
      if (map && window.L) {
        if (!this.marker) {
          this.marker = L.marker([v.lat, v.lng], { icon: MW.pinIcon(this.opts.kind), draggable: true }).addTo(map);
          this.marker.on('dragend', () => { const ll = this.marker.getLatLng(); this.value.lat = ll.lat; this.value.lng = ll.lng; this.reverse(ll.lat, ll.lng); this.emit(); });
        } else this.marker.setLatLng([v.lat, v.lng]);
        if (pan) map.setView([v.lat, v.lng], Math.max(map.getZoom(), 15));
      }
      this.setHint('');
      this.emit();
    }
    emit() { this.opts.onChange && this.opts.onChange(this.value); }
    get() { return this.value && this.value.text ? this.value : null; }
    valid() { return !!(this.value && this.value.text && this.value.lat !== null && this.value.lat !== undefined && !isNaN(this.value.lat)); }
  };

  MW.fitTo = function (map, points) {
    const pts = points.filter((p) => p && p[0] !== null && !isNaN(p[0]));
    if (!map || !pts.length) return;
    if (pts.length === 1) map.setView(pts[0], 15); else map.fitBounds(L.latLngBounds(pts), { padding: [40, 40], maxZoom: 16 });
  };
})();
