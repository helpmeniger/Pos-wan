'use strict';
(() => {
  const { alertId } = V.pageData();
  const reload = () => location.reload();

  document.querySelectorAll('[data-status]').forEach((b) => b.addEventListener('click', async () => {
    const r = await V.api('api/alerts.php', { op: 'status', id: alertId, status: b.dataset.status });
    r.ok ? reload() : V.toast(V.esc(r.message), 'error');
  }));
  document.getElementById('assign')?.addEventListener('change', async (e) => {
    const r = await V.api('api/alerts.php', { op: 'assign', id: alertId, user_id: e.target.value ? +e.target.value : null });
    r.ok ? reload() : V.toast(V.esc(r.message), 'error');
  });
  document.getElementById('btn-focus')?.addEventListener('click', async (e) => {
    const r = await V.api('api/sessions.php', { op: 'action', id: +e.target.dataset.session, action: 'focus' });
    V.toast(V.esc(r.message), r.ok ? '' : 'error');
  });
  const formData = (f) => Object.fromEntries(new FormData(f).entries());
  document.querySelectorAll('.evidence-form').forEach((f) => f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await V.api('api/claims.php', { op: 'add_evidence', ...formData(f) });
    r.ok ? reload() : V.toast(V.esc(r.message), 'error');
  }));
  document.querySelectorAll('.verdict-form').forEach((f) => f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const r = await V.api('api/claims.php', { op: 'verdict', ...formData(f) });
    r.ok ? reload() : V.toast(V.esc(r.message), 'error');
  }));
  document.querySelectorAll('[data-del-evidence]').forEach((b) => b.addEventListener('click', async () => {
    if (!confirm('Supprimer cette source ?')) return;
    const r = await V.api('api/claims.php', { op: 'delete_evidence', id: +b.dataset.delEvidence });
    r.ok ? reload() : V.toast(V.esc(r.message), 'error');
  }));
})();
