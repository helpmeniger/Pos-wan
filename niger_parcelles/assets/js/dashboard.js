/* Graphiques du tableau de bord (Chart.js), données issues de MySQL. */
(function () {
    'use strict';
    const d = JSON.parse(document.getElementById('dashboard-data').textContent);
    const palette = ['#e05206', '#0d8b3e', '#5c6670', '#f4a261', '#2a9d8f', '#8d99ae', '#e76f51', '#264653', '#a8dadc', '#6d597a'];
    const statusColors = { brouillon: '#adb5bd', soumise: '#4fc3f7', en_verification: '#ffb74d', verifiee: '#66bb6a', anomalie: '#ef5350', rejetee: '#495057' };
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.animation = false; // interface sobre, sans animation superflue
    const empty = (id) => {
        const c = document.getElementById(id);
        c.replaceWith(Object.assign(document.createElement('div'), { className: 'text-muted small', textContent: 'Aucune donnée.' }));
    };

    new Chart(document.getElementById('chartEvolution'), {
        type: 'bar',
        data: { labels: d.evolution.labels, datasets: [{ label: 'Parcelles créées', data: d.evolution.values, backgroundColor: '#e05206' }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
    });
    if (d.by_category.length) {
        new Chart(document.getElementById('chartCategory'), {
            type: 'doughnut',
            data: { labels: d.by_category.map((r) => r.label), datasets: [{ data: d.by_category.map((r) => r.n), backgroundColor: palette }] },
            options: { plugins: { legend: { position: 'bottom' } } },
        });
    } else empty('chartCategory');
    if (d.by_area.length) {
        new Chart(document.getElementById('chartArea'), {
            type: 'bar',
            data: { labels: d.by_area.map((r) => r.label), datasets: [{ label: 'Parcelles', data: d.by_area.map((r) => r.n), backgroundColor: '#0d8b3e' }] },
            options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } },
        });
    } else empty('chartArea');
    if (d.by_status.length) {
        new Chart(document.getElementById('chartStatus'), {
            type: 'pie',
            data: { labels: d.by_status.map((r) => r.label), datasets: [{ data: d.by_status.map((r) => r.n), backgroundColor: d.by_status.map((r) => statusColors[r.key]) }] },
            options: { plugins: { legend: { position: 'bottom' } } },
        });
    } else empty('chartStatus');
    if (d.by_survey.length) {
        new Chart(document.getElementById('chartSurvey'), {
            type: 'pie',
            data: { labels: d.by_survey.map((r) => r.label), datasets: [{ data: d.by_survey.map((r) => r.n), backgroundColor: palette.slice(2) }] },
            options: { plugins: { legend: { position: 'bottom' } } },
        });
    } else empty('chartSurvey');
})();
