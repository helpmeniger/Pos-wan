/* Graphiques génériques : <canvas data-chart='{"type":"bar","labels":[...],"series":[{"label":"..","data":[..],"color":".."}]}'> */
(function () {
  if (!window.Chart) return;
  const palette = ['#27ae60', '#2e86c1', '#f39c12', '#8e44ad', '#e74c3c', '#16a085', '#d35400', '#34495e', '#e84393', '#7f8c8d'];
  document.querySelectorAll('canvas[data-chart]').forEach((cv) => {
    const c = JSON.parse(cv.dataset.chart);
    const round = c.type === 'doughnut' || c.type === 'pie';
    new Chart(cv, {
      type: c.type,
      data: { labels: c.labels, datasets: c.series.map((s, i) => ({ label: s.label, data: s.data, type: s.type, backgroundColor: round ? (s.colors || palette) : (s.color || palette[i]), borderColor: s.color || palette[i], tension: 0.3, borderRadius: 4 })) },
      options: { plugins: { legend: { display: round || c.series.length > 1, position: 'bottom' }, tooltip: { callbacks: { label: (x) => (x.dataset.label ? x.dataset.label + ' : ' : '') + Number(round ? x.parsed : (c.horizontal ? x.parsed.x : x.parsed.y)).toLocaleString('fr-FR') + (c.unit || ' F') } } }, indexAxis: c.horizontal ? 'y' : 'x' },
    });
  });
})();
