(function () {
  'use strict';
  const charts = {};
  const C = { blue: '#0f4c81', orange: '#e08a00', green: '#2e8b57', red: '#c0392b', grey: '#8a94a6', teal: '#17a2b8' };
  function chart(id, type, labels, datasets, opts = {}) {
    const el = document.getElementById(id);
    if (!el) return;
    if (charts[id]) charts[id].destroy();
    charts[id] = new Chart(el, { type, data: { labels, datasets }, options: Object.assign({ responsive: true, plugins: { legend: { display: datasets.length > 1 || type === 'doughnut' } }, scales: type === 'doughnut' ? {} : { y: { beginAtZero: true, ticks: { precision: 0 } } } }, opts) });
  }
  const levelLabel = { information: 'Information', a_verifier: 'À vérifier', prioritaire: 'Prioritaire' };
  async function load() {
    let d;
    try { d = await sgFetch('dashboard/data'); } catch (e) { document.getElementById('sysBox').textContent = e.message; return; }
    const k = d.kpi;
    for (const [key, v] of Object.entries(k)) {
      const el = document.querySelector(`[data-kpi="${key}"]`);
      if (!el) continue;
      if (key === 'validated_accuracy') el.textContent = v === null ? '—' : Math.round(v * 100) + ' %';
      else el.textContent = v ?? '—';
    }
    const acc = document.querySelector('[data-kpi="validated_accuracy"]');
    if (acc && k.validated_tests) acc.title = `Mesuré sur ${k.validated_tests} photos de test (version ${k.model_version})`;
    const s = d.system;
    const p = s.perf;
    document.getElementById('sysBox').innerHTML = `
      <div class="mb-2">Service IA : ${s.ai_ok ? '<span class="badge bg-success">opérationnel</span>' : '<span class="badge bg-danger">indisponible</span> ' + sgEsc(s.ai_error || '')}</div>
      ${s.ai ? `<div>Détecteur : ${sgEsc(s.ai.detector)}<br>Empreintes : ${sgEsc(s.ai.embedder)}<br>Reconnaissance : ${s.ai.recognition.active ? 'version ' + sgEsc(s.ai.recognition.version) + ' (' + s.ai.recognition.products + ' produits)' : 'aucune version active'}<br>
        Événements en attente d'envoi : ${s.ai.events.pending}</div>` : ''}
      ${p ? `<hr class="my-2"><div>Processeur : ${p.cpu_percent} %<br>Mémoire : ${p.memory.used_percent} % (service IA : ${p.service_memory_mb} Mo)<br>
        GPU : ${p.gpu ? sgEsc(p.gpu.name) : 'non utilisé'}<br>
        Temps moyen de traitement : ${Object.values(p.cameras).map((c) => Math.round(c.proc_ms) + ' ms').join(', ') || '—'}</div>` : ''}
      <hr class="my-2"><div>Espace disque libre : <span class="${s.disk_low ? 'text-danger fw-bold' : ''}">${sgEsc(s.disk_free)}</span></div>`;
    const ch = d.charts;
    chart('chHourly', 'bar', ch.hourly.map((_, h) => h + 'h'), [
      { label: 'Sorties', data: ch.hourly.map((x) => x.exits), backgroundColor: C.orange },
      { label: 'Entrées', data: ch.hourly.map((x) => x.entries), backgroundColor: C.blue }]);
    chart('chDaily', 'line', ch.daily.map((x) => x.date.slice(5)), [
      { label: 'Sorties observées', data: ch.daily.map((x) => x.exits), borderColor: C.orange, tension: .3 },
      { label: 'Sorties non rapprochées', data: ch.daily.map((x) => x.unmatched), borderColor: C.red, tension: .3 }]);
    chart('chTopExits', 'bar', ch.top_exits.map((x) => x.name), [{ label: 'Sorties', data: ch.top_exits.map((x) => x.n), backgroundColor: C.orange }], { indexAxis: 'y' });
    chart('chTopDet', 'bar', ch.top_detected.map((x) => x.name), [{ label: 'Détections', data: ch.top_detected.map((x) => x.n), backgroundColor: C.blue }], { indexAxis: 'y' });
    chart('chCam', 'bar', ch.by_camera.map((x) => x.name), [{ label: 'Franchissements', data: ch.by_camera.map((x) => x.n), backgroundColor: C.teal }]);
    chart('chLevel', 'doughnut', ch.by_level.map((x) => levelLabel[x.level] || x.level), [{ data: ch.by_level.map((x) => x.n), backgroundColor: [C.teal, C.orange, C.red] }]);
    chart('chReview', 'doughnut', ch.reviews.map((x) => x.label), [{ data: ch.reviews.map((x) => x.n), backgroundColor: [C.blue, C.orange, C.green, C.green, C.grey, C.red, C.grey, '#333'] }]);
    chart('chVar', 'line', ch.variance.map((x) => x.d), [{ label: 'Écart absolu', data: ch.variance.map((x) => +x.v), borderColor: C.red }]);
  }
  load();
  setInterval(load, 60000);
})();
