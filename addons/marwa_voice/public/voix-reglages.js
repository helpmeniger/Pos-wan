/* MARWA VOICE AI — page « Assistant vocal » : réglages du poste, essai, voix disponibles, test de Piper. */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const BASE = (($('meta[name="base-url"]') || {}).content || '/').replace(/\/$/, '');
  const CSRF = () => (($('meta[name="csrf-token"]') || {}).content || '');

  function voiceList() {
    if (!('speechSynthesis' in window)) return Promise.resolve([]);
    return window.PMVoice ? window.PMVoice.voices() : Promise.resolve(speechSynthesis.getVoices());
  }

  // Liste des voix du poste (réglages du responsable : suggestions de noms)
  const dl = $('#vVoiceList');
  if (dl) voiceList().then((vs) => { dl.innerHTML = vs.filter((v) => /^fr/i.test(v.lang)).map((v) => `<option value="${esc(v.name)}">`).join(''); });

  // ---------------------------------------------------------------- réglages du poste
  const box = $('#voixPoste');
  if (box) {
    let done = false;
    const init = () => {
      if (done) return;
      done = true;
      const V = window.PMVoice;
      if (!V || !V.config) { $('[data-voix-off]', box).classList.remove('d-none'); }
      const p = V ? V.poste() : {};
      const cfg = (V && V.config) || { volume: 0.9, rate: 1 };
      $('[data-p="name"]', box).value = p.name || '';
      $('[data-p="muted"]', box).checked = !!p.muted;
      $('[data-p="hideWidget"]', box).checked = !!p.hideWidget;
      $('[data-p="volume"]', box).value = p.volume ?? cfg.volume;
      $('[data-p="rate"]', box).value = p.rate ?? cfg.rate;
      $('[data-p="engine"]', box).value = p.engine || '';
      $$('[data-off]', box).forEach((c) => { c.checked = (p.off || []).includes(c.dataset.off); });
      const show = () => { $('[data-show="volume"]', box).textContent = Math.round($('[data-p="volume"]', box).value * 100) + ' %'; $('[data-show="rate"]', box).textContent = '×' + Number($('[data-p="rate"]', box).value).toFixed(2); };
      $$('input[type=range]', box).forEach((r) => r.addEventListener('input', show)); show();
      voiceList().then((vs) => {
        const sel = $('[data-p="voice"]', box);
        sel.innerHTML = '<option value="">Automatique (française)</option>' + vs.filter((v) => /^fr/i.test(v.lang)).map((v) => `<option value="${esc(v.name)}">${esc(v.name)} — ${v.localService ? 'locale' : 'en ligne'}</option>`).join('');
        sel.value = p.voice || '';
        $('[data-voices]', box).innerHTML = vs.length
          ? vs.filter((v) => /^(fr|ha|en)/i.test(v.lang)).map((v) => `<li>${esc(v.name)} <span class="text-secondary">(${esc(v.lang)}, ${v.localService ? 'locale' : 'en ligne'})</span></li>`).join('') || '<li>Aucune voix française, anglaise ni haoussa.</li>'
          : '<li class="text-warning">Aucune voix installée dans ce navigateur. Sous Windows : Paramètres → Heure et langue → Voix → ajouter « Français ».</li>';
      });
    };
    if (window.PMVoice && window.PMVoice.config) init(); else { document.addEventListener('pm:voix-ready', init, { once: true }); setTimeout(init, 2500); }

    $('[data-save]', box).addEventListener('click', () => {
      if (!window.PMVoice) return;
      window.PMVoice.savePoste({
        name: $('[data-p="name"]', box).value.trim().slice(0, 40), muted: $('[data-p="muted"]', box).checked, hideWidget: $('[data-p="hideWidget"]', box).checked,
        volume: Number($('[data-p="volume"]', box).value), rate: Number($('[data-p="rate"]', box).value),
        engine: $('[data-p="engine"]', box).value, voice: $('[data-p="voice"]', box).value,
        off: $$('[data-off]', box).filter((c) => c.checked).map((c) => c.dataset.off),
      });
      const s = $('[data-saved]', box); s.classList.remove('d-none'); setTimeout(() => s.classList.add('d-none'), 2000);
    });
    $('[data-test]', box).addEventListener('click', () => {
      const V = window.PMVoice;
      const out = $('[data-test-out]', box);
      if (!V || !V.config) { out.textContent = 'Assistant vocal indisponible pour ce compte.'; return; }
      V.test($('[data-test-text]', box).value);
      out.textContent = 'Lecture avec : ' + (V.state().engine === 'piper' ? 'Piper (serveur)' : 'voix de ce poste') + '…';
      const t = setInterval(() => { const st = V.state(); if (st.waitingGesture) out.textContent = 'Le navigateur bloque le son : touchez la page puis réessayez.'; if (!st.speaking && !st.queued) { clearInterval(t); out.textContent = st.last ? 'Lu : « ' + st.last + ' »' : 'Non lu : voir Diagnostic (aucune voix disponible ?).'; } }, 300);
    });
  }

  // ---------------------------------------------------------------- diagnostic : test de Piper
  const pt = $('[data-piper-test]');
  if (pt) pt.addEventListener('click', async () => {
    const out = $('[data-piper-out]');
    out.innerHTML = '<span class="text-secondary">Génération…</span>';
    const t0 = performance.now();
    const text = 'Essai de Piper : ' + new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + '.';
    try {
      const r = await fetch(BASE + '/voix/tts', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF() }, body: JSON.stringify({ _csrf: CSRF(), text: window.PMVoiceCore ? PMVoiceCore.normalize(text) : text }) });
      if (!r.ok) { const d = await r.json().catch(() => ({})); throw new Error(d.error || ('HTTP ' + r.status)); }
      const url = URL.createObjectURL(await r.blob());
      out.innerHTML = `<span class="text-success">✔ Son reçu en ${Math.round(performance.now() - t0)} ms (${esc(r.headers.get('X-Voix-Via') || '')}, génération ${esc(r.headers.get('X-Voix-Ms') || '?')} ms)</span> <audio controls src="${url}" class="d-block mt-1"></audio>`;
      out.querySelector('audio').play().catch(() => { /* lecture manuelle */ });
    } catch (e) { out.innerHTML = '<span class="text-danger">✘ ' + esc(e.message) + '</span>'; }
  });
})();
