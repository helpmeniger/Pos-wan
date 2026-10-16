/* MARWA — graphiques SVG légers (sans bibliothèque) */
(function () {
  'use strict';
  const MW = window.MW;
  MW.chart = {
    /** series : [{label, color, values:[number]}], labels : [string] */
    line(el, labels, series) {
      const W = 640, H = 240, pl = 64, pr = 12, pt = 14, pb = 28;
      const all = series.flatMap((s) => s.values);
      const max = Math.max(1, ...all);
      const nice = Math.pow(10, Math.floor(Math.log10(max))) * Math.ceil(max / Math.pow(10, Math.floor(Math.log10(max))));
      const x = (i) => pl + (labels.length <= 1 ? (W - pl - pr) / 2 : i * (W - pl - pr) / (labels.length - 1));
      const y = (v) => pt + (H - pt - pb) * (1 - v / nice);
      let g = '';
      for (let k = 0; k <= 4; k++) { const v = nice * k / 4; g += `<line class="grid-line" x1="${pl}" x2="${W - pr}" y1="${y(v)}" y2="${y(v)}"/><text x="${pl - 6}" y="${y(v) + 4}" text-anchor="end">${MW.num(v)}</text>`; }
      const step = Math.max(1, Math.ceil(labels.length / 8));
      labels.forEach((l, i) => { if (i % step === 0) g += `<text x="${x(i)}" y="${H - 8}" text-anchor="middle">${MW.esc(l)}</text>`; });
      series.forEach((s) => {
        const pts = s.values.map((v, i) => `${x(i)},${y(v)}`).join(' ');
        g += `<polyline points="${pts}" fill="none" stroke="${s.color}" stroke-width="2.5" stroke-linejoin="round"/>`;
        s.values.forEach((v, i) => { g += `<circle cx="${x(i)}" cy="${y(v)}" r="3" fill="${s.color}"><title>${MW.esc(labels[i])} : ${MW.fcfa(v)}</title></circle>`; });
      });
      el.innerHTML = `<svg class="chart" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none" role="img" aria-label="Graphique">${g}</svg>
        <div class="chart-legend">${series.map((s) => `<span><i style="background:${s.color}"></i>${MW.esc(s.label)}</span>`).join('')}</div>`;
    },
    bars(el, rows, fmt = MW.fcfa) {
      const max = Math.max(1, ...rows.map((r) => r.value));
      el.innerHTML = rows.map((r) => `<div class="bar-row"><span class="ellipsis">${MW.esc(r.label)}</span><span class="bar" style="width:${Math.max(2, r.value / max * 100)}%;background:${r.color || 'var(--green-600)'}"></span><strong class="small">${fmt(r.value)}</strong></div>`).join('') || MW.empty('Aucune donnée.');
    }
  };
})();
