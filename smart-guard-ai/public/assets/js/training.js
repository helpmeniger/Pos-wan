(function () {
  'use strict';
  // Suivi d'une tâche de préparation
  const box = document.getElementById('jobBox');
  if (box && ['en_attente', 'en_cours'].includes(box.dataset.status)) {
    const tick = async () => {
      try {
        const d = await sgFetch(`training/jobs/${box.dataset.id}`);
        const j = d.job;
        document.getElementById('jobBar').style.width = j.progress + '%';
        document.getElementById('jobBar').textContent = j.progress + ' %';
        document.getElementById('jobMsg').textContent = j.message || '';
        if (['termine', 'echec'].includes(j.status)) { location.reload(); return; }
      } catch (e) { document.getElementById('jobMsg').textContent = e.message; }
      setTimeout(tick, 1500);
    };
    tick();
  }
  // Test sur image
  const fi = document.getElementById('testImageForm');
  fi?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const out = document.getElementById('testImageOut');
    out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Analyse…';
    try {
      const d = await sgFetch('training/test-image', { method: 'POST', body: new FormData(fi) });
      out.innerHTML = (d.annotated_jpeg_b64 ? `<img class="img-fluid rounded mb-2" src="data:image/jpeg;base64,${d.annotated_jpeg_b64}">` : '') +
        (d.recognition.active ? '' : '<div class="text-warning">Aucune version de reconnaissance active : tout objet sera « Produit non identifié ».</div>') +
        d.results.map((r) => `<div><strong>${sgEsc(r.label)}</strong> — confiance ${Math.round(r.confidence * 100)} %` +
          (r.product_id ? '' : (r.second_product_id ? ` <span class="text-muted">(le plus proche : produit #${r.second_product_id}, insuffisant)</span>` : '')) + '</div>').join('') +
        `<div class="text-muted mt-1">Mode : ${d.mode === 'image_entiere' ? 'image entière (le détecteur de mouvement ne s\'applique pas à une image fixe)' : 'détecteur d\'objets'}.</div>`;
    } catch (err) { out.innerHTML = `<span class="text-danger">${sgEsc(err.message)}</span>`; }
  });
  // Test sur vidéo
  const fv = document.getElementById('testVideoForm');
  fv?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const out = document.getElementById('testVideoOut');
    out.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Envoi de la vidéo…';
    try {
      const d = await sgFetch('training/test-video', { method: 'POST', body: new FormData(fv) });
      const poll = async () => {
        const s = await sgFetch(`training/test-video/${d.job}`);
        if (s.status === 'en_cours') { out.innerHTML = `<span class="spinner-border spinner-border-sm"></span> Analyse : ${s.progress} %`; setTimeout(poll, 1000); return; }
        if (s.status === 'echec') { out.innerHTML = `<span class="text-danger">${sgEsc(s.message)}</span>`; return; }
        const r = s.result;
        out.innerHTML = `<div class="mb-1">${r.frames_analysed} images analysées sur ${r.frames_total} — ${r.avg_proc_ms} ms par image — ${r.objects_tracked} objet(s) suivi(s)</div>
          <div><strong>Sorties : ${r.counters.sortie}</strong> · Entrées : ${r.counters.entree} · Ambigus : ${r.counters.ambigu} · Pertes de suivi : ${r.counters.perte_suivi}</div>
          <ul class="mt-2">${r.events.map((ev) => `<li>${sgEsc(new Date(ev.occurred_at).toLocaleTimeString('fr-FR'))} — ${sgEsc(ev.direction)} — ${sgEsc(ev.label)} (${Math.round(ev.confidence * 100)} %) — objet n° ${ev.track_number}</li>`).join('')}</ul>
          <div class="text-muted">Comparez ces résultats avec un comptage manuel de la vidéo (procédure docs/TESTS.md).</div>`;
      };
      poll();
    } catch (err) { out.innerHTML = `<span class="text-danger">${sgEsc(err.message)}</span>`; }
  });
})();
