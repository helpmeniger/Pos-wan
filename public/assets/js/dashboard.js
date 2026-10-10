/* Graphiques du tableau de bord (Chart.js, fichier local). */
(function () {
  const el = document.getElementById('dash-data'); if (!el || !window.Chart) return;
  const d = JSON.parse(el.textContent);
  const fmt = (v) => Number(v).toLocaleString('fr-FR') + ' F';
  const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
  Chart.defaults.color = dark ? '#c9d3e6' : '#495057';
  const daily = document.getElementById('chDaily');
  if (daily) {
    const ds = [{ type: 'bar', label: "Chiffre d'affaires", data: d.daily.map((x) => x.t), backgroundColor: 'rgba(39,174,96,.75)', borderRadius: 4 }];
    if (d.showMargin) ds.push({ type: 'line', label: 'Marge brute', data: d.daily.map((x) => x.m), borderColor: '#f39c12', backgroundColor: '#f39c12', tension: .3, pointRadius: 2 });
    new Chart(daily, { data: { labels: d.daily.map((x) => x.d), datasets: ds }, options: { plugins: { tooltip: { callbacks: { label: (c) => c.dataset.label + ' : ' + fmt(c.parsed.y) } } }, scales: { y: { ticks: { callback: (v) => Number(v).toLocaleString('fr-FR') } } } } });
  }
  const cat = document.getElementById('chCat');
  if (cat && d.categories.length) new Chart(cat, { type: 'doughnut', data: { labels: d.categories.map((x) => x.l), datasets: [{ data: d.categories.map((x) => x.v), backgroundColor: d.categories.map((x) => x.c) }] }, options: { plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: (c) => c.label + ' : ' + fmt(c.parsed) } } } } });
  const m = document.getElementById('chMethods');
  if (m && d.methods.length) new Chart(m, { type: 'bar', data: { labels: d.methods.map((x) => x.l), datasets: [{ data: d.methods.map((x) => x.v), backgroundColor: ['#27ae60', '#2e86c1', '#f39c12', '#8e44ad', '#e74c3c', '#16a085'] }] }, options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => fmt(c.parsed.x) } } } } });
})();
