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
    const login = document.getElementById('login-form');
    submit(login, async (v) => {
      const r = await MW.post('/auth/login', v);
      const back = login.dataset.back;
      location.href = back ? MW.base + back : r.data.redirect;
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
