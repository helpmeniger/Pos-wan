/* MARWA — page d'accueil publique : estimation instantanée sur la carte */
(function () {
  'use strict';
  const MW = window.MW;
  document.addEventListener('DOMContentLoaded', async () => {
    const map = await MW.createMap(document.getElementById('home-map'));
    let service = 'taxi';
    let routeLine = null;
    const pickup = new MW.PlaceField(document.getElementById('home-pickup'), { kind: 'pickup', label: 'Départ', map, placeholder: 'Où êtes-vous ?' });
    const dropoff = new MW.PlaceField(document.getElementById('home-dropoff'), { kind: 'dropoff', label: 'Destination', map, placeholder: 'Où allez-vous ?' });
    const out = document.getElementById('home-estimates');

    document.querySelectorAll('[data-service]').forEach((b) => b.addEventListener('click', () => {
      service = b.dataset.service;
      document.querySelectorAll('[data-service]').forEach((x) => x.classList.toggle('active', x === b));
      out.innerHTML = '';
    }));

    document.getElementById('home-estimate').addEventListener('click', (e) => MW.busy(e.currentTarget, async () => {
      if (!pickup.valid() || !dropoff.valid()) { MW.toast('Choisissez le départ et la destination (recherche, GPS ou carte).', 'warn'); return; }
      const p = pickup.get(); const d = dropoff.get();
      const r = await MW.post('/estimate', { service_type: service, vehicle_category: service === 'parcel' ? 'moto' : null, pickup_lat: p.lat, pickup_lng: p.lng, dropoff_lat: d.lat, dropoff_lng: d.lng });
      const list = r.data;
      if (routeLine) routeLine.remove();
      if (list[0] && window.L) { routeLine = L.polyline(list[0].geometry, { color: '#0b6e4f', weight: 5, opacity: .8, dashArray: list[0].approximate ? '8 8' : null }).addTo(map); MW.fitTo(map, list[0].geometry); }
      out.innerHTML = `<div class="small muted">${MW.dist(list[0].distance_m)} · environ ${MW.dur(list[0].duration_s)}${list[0].night ? ' · tarif de nuit' : ''}</div>`
        + list.map((x) => `<div class="vehicle-option"><span class="veh-ic">${MW.icon(['moto_taxi', 'moto'].includes(x.vehicle_category) ? 'moto' : (service === 'parcel' ? 'box' : 'car'))}</span>
            <span><strong>${MW.esc(MW.L.vehicle[x.vehicle_category] || 'Livraison')}</strong><br><span class="small muted">Prix négociable dès ${MW.fcfa(x.min_offer)}</span></span><span class="price">${MW.fcfa(x.total)}</span></div>`).join('')
        + `<p class="small muted">${MW.esc(list[0].disclaimer)}</p>`
        + `<a class="btn btn-accent btn-block btn-lg" href="#" data-go>Commander</a>`;
      out.querySelector('[data-go]').addEventListener('click', (ev) => {
        ev.preventDefault();
        sessionStorage.setItem('mw:draft', JSON.stringify({ service, pickup: p, dropoff: d }));
        const target = service === 'parcel' ? '/client#/colis' : '/client#/taxi';
        location.href = MW.user ? MW.base + target : MW.base + '/connexion?retour=' + encodeURIComponent('/client');
      });
    }));

    try {
      const promos = await MW.get('/promotions');
      if (promos.length) {
        document.getElementById('home-promos').hidden = false;
        document.querySelector('[data-promos]').innerHTML = promos.slice(0, 6).map((p) => `<div class="card"><span class="badge amber">${MW.esc(p.code)}</span><h3 style="margin-top:8px">${MW.esc(p.description)}</h3><p class="small muted">Jusqu'au ${MW.date(p.ends_at)}${p.merchant ? ' · ' + MW.esc(p.merchant) : ''}</p></div>`).join('');
      }
      const restos = await MW.get('/merchants');
      if (restos.length) {
        document.getElementById('home-restaurants').hidden = false;
        document.querySelector('[data-restaurants]').innerHTML = restos.slice(0, 6).map((m) => `<a class="merchant-card" href="${MW.base}/client#/repas/${m.id}">
          <span class="logo" ${m.logo_url ? `style="background-image:url('${MW.esc(m.logo_url)}')"` : ''}>${m.logo_url ? '' : MW.esc(m.name[0])}</span>
          <span class="grow"><strong>${MW.esc(m.name)}</strong><br><span class="small muted">${MW.esc(m.cuisine || '')} · ${m.prep_time_min} min</span><br>${m.open ? '<span class="badge green">Ouvert</span>' : '<span class="badge gray">Fermé</span>'}</span></a>`).join('');
      }
    } catch (e) { /* section optionnelle */ }
  });
})();
