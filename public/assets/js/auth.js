/* MARWA — pages de connexion, inscription et mot de passe */
(function () {
  'use strict';
  const MW = window.MW;
  const submit = (form, fn) => form && form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('[type=submit]');
    MW.clearErrors(form);
    btn.disabled = true; btn.classList.add('loading');
    try { await fn(MW.values(form)); } catch (err) { MW.showErrors(form, err); } finally { btn.disabled = false; btn.classList.remove('loading'); }
  });

  document.addEventListener('DOMContentLoaded', () => {
    // Onglets « code par SMS » / « mot de passe » (dernier choix mémorisé sur l'appareil)
    const tabs = document.querySelectorAll('[data-tab]');
    const showTab = (t) => {
      tabs.forEach((b) => b.classList.toggle('active', b.dataset.tab === t));
      document.querySelectorAll('[data-pane]').forEach((p) => { p.hidden = p.dataset.pane !== t; });
      try { localStorage.setItem('mw:loginTab', t); } catch (e) { /* privé */ }
    };
    tabs.forEach((b) => b.addEventListener('click', () => showTab(b.dataset.tab)));
    let savedTab = null; try { savedTab = localStorage.getItem('mw:loginTab'); } catch (e) { /* privé */ }
    if (tabs.length && (savedTab === 'password' || /[?&]mode=motdepasse/.test(location.search))) showTab('password');

    const phoneForm = document.getElementById('phone-form');
    if (phoneForm) {
      const pane = phoneForm.parentElement;
      const back = document.getElementById('login-form')?.dataset.back;
      const go = (r) => { location.href = back ? MW.base + back : r.data.redirect; };
      submit(phoneForm, async (v) => {
        const r = await MW.post('/auth/phone/send', { phone: v.phone });
        if (r.data.password_required) { MW.toast(r.data.message, 'warn', 7000); showTab('password'); const id = document.getElementById('identifier'); if (id) id.value = v.phone; return; }
        const remember = v.remember;
        phoneForm.hidden = true;
        const f = document.createElement('form'); f.id = 'code-form'; f.noValidate = true;
        f.innerHTML = `<div class="alert alert-info">${MW.esc(r.data.message)}${r.data.debug_code ? `<br><strong>Mode démonstration (aucun SMS réel) — code : <span translate="no">${MW.esc(r.data.debug_code)}</span></strong>` : ''}</div>
          <div class="field" data-field="code"><label for="otp">Code reçu par SMS</label><input class="input otp-input" id="otp" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></div>
          <button class="btn btn-primary btn-block btn-lg" type="submit">Se connecter</button>
          <div class="row between" style="margin-top:10px"><button type="button" class="btn btn-ghost btn-sm" data-other>Changer de numéro</button><button type="button" class="btn btn-ghost btn-sm" data-resend>Renvoyer le code</button></div>`;
        pane.appendChild(f); f.querySelector('input').focus();
        f.querySelector('[data-other]').onclick = () => { f.remove(); phoneForm.hidden = false; };
        f.querySelector('[data-resend]').onclick = (e) => MW.busy(e.currentTarget, async () => { const x = await MW.post('/auth/phone/send', { phone: v.phone }); MW.toast(x.data.debug_code ? 'Code (démonstration) : ' + x.data.debug_code : x.data.message, 'success', 9000); });
        // Lecture automatique du code SMS (Android Chrome, si le SMS respecte le format WebOTP)
        if ('OTPCredential' in window) navigator.credentials.get({ otp: { transport: ['sms'] } }).then((o) => { if (o && o.code) { f.code.value = o.code; f.requestSubmit(); } }).catch(() => {});
        submit(f, async (c) => {
          const x = await MW.post('/auth/phone/verify', { phone: v.phone, code: c.code, remember });
          if (x.data.two_factor) { MW.toast('Double authentification activée : utilisez l\'onglet mot de passe.', 'warn'); return; }
          if (!x.data.needs_profile) return go(x);
          // Nouveau client : nom et conditions d'utilisation
          f.remove();
          const g = document.createElement('form'); g.noValidate = true;
          g.innerHTML = `<div class="alert alert-success">Numéro vérifié. Dernière étape : comment vous appelez-vous ?</div>
            <div class="field" data-field="full_name"><label for="fn">Nom et prénom</label><input class="input" id="fn" name="full_name" autocomplete="name" required maxlength="120"></div>
            <label class="check" data-field="accept_terms"><input type="checkbox" name="accept_terms"> <span>J'accepte les <a href="${MW.base}/pages/cgu" target="_blank">conditions d'utilisation</a> et la <a href="${MW.base}/pages/confidentialite" target="_blank">politique de confidentialité</a>.</span></label>
            <button class="btn btn-primary btn-block btn-lg" type="submit">Créer mon compte</button>`;
          pane.appendChild(g); g.querySelector('input').focus();
          submit(g, async (w) => go(await MW.post('/auth/phone/register', { phone: v.phone, signup_token: x.data.signup_token, full_name: w.full_name, accept_terms: w.accept_terms, remember })));
        });
      });
    }

    const login = document.getElementById('login-form');
    const done = (r) => { const back = login.dataset.back; location.href = back && !r.data.two_factor_setup_required ? MW.base + back : r.data.redirect; };
    submit(login, async (v) => {
      const r = await MW.post('/auth/login', v);
      if (!r.data.two_factor) return done(r);
      // Double authentification : code de l'application (ou code de secours)
      MW.csrf = r.data.csrf || MW.csrf;
      login.hidden = true;
      const f = document.createElement('form'); f.id = 'twofa-form'; f.noValidate = true;
      f.innerHTML = `<div class="alert alert-info">Saisissez le code à 6 chiffres affiché par votre application d'authentification (ou un code de secours).</div>
        <div class="field" data-field="code"><label for="code">Code de vérification</label><input class="input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="9" required autofocus></div>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Vérifier</button>`;
      login.after(f); f.querySelector('input').focus();
      submit(f, async (c) => done(await MW.post('/auth/2fa', c)));
    });

    const reg = document.getElementById('register-form');
    if (reg) {
      let place = null;
      const sync = async () => {
        const role = reg.querySelector('[name=role]:checked').value;
        const pro = ['merchant', 'business'].includes(role);
        reg.querySelector('[data-pro]').hidden = !pro;
        reg.querySelector('[data-merchant]').hidden = role !== 'merchant';
        reg.querySelector('[data-pro-note]').hidden = role === 'client';
        if (role === 'merchant' && !place) {
          const map = await MW.createMap(document.getElementById('merchant-map'), { zoom: 13 });
          place = new MW.PlaceField(document.getElementById('merchant-place'), { kind: 'pickup', label: 'Adresse du commerce *', map, placeholder: 'Quartier, rue, repère…' });
        }
      };
      reg.querySelectorAll('[name=role]').forEach((r) => r.addEventListener('change', sync));
      sync();
      submit(reg, async (v) => {
        if (v.role === 'merchant') {
          if (!place || !place.valid()) throw new MW.ApiError(422, { error: { message: 'Placez votre commerce sur la carte.', fields: { pickup_text: 'Utilisez la recherche, votre position ou touchez la carte.' } } });
          const p = place.get();
          Object.assign(v, { merchant_address: p.text, merchant_lat: p.lat, merchant_lng: p.lng });
        }
        const r = await MW.post('/auth/register', v);
        location.href = r.data.redirect;
      });
    }

    const forgot = document.getElementById('forgot-form');
    const otpForm = document.getElementById('otp-reset-form');
    let identifier = '';
    submit(forgot, async (v) => {
      identifier = v.identifier;
      const r = await MW.post('/auth/password/forgot', v);
      MW.toast(r.message, 'success', 7000);
      if (!identifier.includes('@')) otpForm.hidden = false;
      if (r.debug_code) MW.toast('Mode développement — code : ' + r.debug_code, 'warn', 15000);
    });
    submit(otpForm, async (v) => {
      await MW.post('/auth/password/reset', { phone: identifier, code: v.code, password: v.password });
      MW.toast('Mot de passe modifié.', 'success');
      setTimeout(() => (location.href = MW.base + '/connexion'), 1200);
    });

    const reset = document.getElementById('reset-form');
    submit(reset, async (v) => {
      await MW.post('/auth/password/reset', { token: reset.dataset.token, password: v.password });
      MW.toast('Mot de passe modifié.', 'success');
      setTimeout(() => (location.href = MW.base + '/connexion'), 1200);
    });
  });
})();
