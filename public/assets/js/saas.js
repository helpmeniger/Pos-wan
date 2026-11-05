// POS Marwa en ligne : confirmations et calcul du montant d'un paiement (console)
(() => {
  document.querySelectorAll('form[data-confirm]').forEach((f) => f.addEventListener('submit', (ev) => {
    if (!window.confirm(f.dataset.confirm)) ev.preventDefault();
  }));
  // Adresse de l'espace proposée d'après le nom du commerce (inscription)
  const company = document.getElementById('fCompany');
  const slug = document.getElementById('fSlug');
  if (company && slug) {
    let touched = slug.value !== '';
    slug.addEventListener('input', () => { touched = true; });
    company.addEventListener('input', () => {
      if (touched) return;
      slug.value = company.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
        .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
    });
  }
  // Montant = prix mensuel de l'offre × nombre de mois (modifiable : remise, geste commercial)
  document.querySelectorAll('form[data-pay]').forEach((f) => {
    const plan = f.querySelector('[name=plan_id]');
    const months = f.querySelector('[name=months]');
    const amount = f.querySelector('[name=amount]');
    const calc = () => {
      const price = Number(plan.selectedOptions[0]?.dataset.price || 0);
      amount.value = Math.round(price * Number(months.value || 0));
    };
    plan.addEventListener('change', calc);
    months.addEventListener('change', calc);
    if (!amount.value) calc();
  });
})();
