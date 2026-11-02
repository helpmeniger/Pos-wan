/* MARWA CONNECT — graphiques (tableau de bord et monitoring). Données fournies par le serveur. */
(function () {
    'use strict';
    if (!window.Chart) { return; }
    var css = getComputedStyle(document.documentElement);
    var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var C = { red: '#D7192A', navy: dark ? '#8FB0FF' : '#1B3260', green: '#16A34A', amber: '#D97706', grey: '#94A3B8' };
    var palette = [C.red, C.navy, C.green, C.amber, '#7C3AED', '#0891B2', '#DB2777', C.grey];
    Chart.defaults.color = dark ? '#93A0BC' : '#6B7590';
    Chart.defaults.borderColor = dark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
    Chart.defaults.font.family = css.getPropertyValue('--bs-body-font-family') || 'system-ui';
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
    Chart.defaults.maintainAspectRatio = false;
    var fcfa = function (v) { return new Intl.NumberFormat('fr-FR').format(v) + ' FCFA'; };
    var empty = function (canvas, text) {
        var p = canvas.parentNode; p.textContent = '';
        var d = document.createElement('div'); d.className = 'mc-empty'; d.textContent = text; p.appendChild(d);
    };

    /* ---------- Tableau de bord ---------- */
    var dataEl = document.getElementById('mc-dash-data');
    if (dataEl) {
        var D = JSON.parse(dataEl.textContent);
        var salesCanvas = document.getElementById('mc-chart-sales');
        var salesChart = null;
        var drawSales = function (view) {
            if (salesChart) { salesChart.destroy(); }
            var cfg;
            if (view === 'weekly' || view === 'monthly') {
                cfg = { type: 'bar', data: { labels: view === 'weekly' ? D.weeks : D.months, datasets: [{ label: 'Chiffre d\'affaires', data: view === 'weekly' ? D.weekly : D.monthly, backgroundColor: C.red, borderRadius: 6 }] },
                    options: { plugins: { tooltip: { callbacks: { label: function (c) { return fcfa(c.raw); } } } }, scales: { y: { beginAtZero: true } } } };
            } else {
                var ds = [];
                if (D.money) {
                    ds.push({ type: 'bar', label: 'Caisse', data: D.physical, backgroundColor: C.red, stack: 's', borderRadius: 4, yAxisID: 'y' });
                    ds.push({ type: 'bar', label: 'En ligne', data: D.online, backgroundColor: C.navy, stack: 's', borderRadius: 4, yAxisID: 'y' });
                    ds.push({ type: 'bar', label: 'Revendeurs', data: D.reseller, backgroundColor: C.amber, stack: 's', borderRadius: 4, yAxisID: 'y' });
                }
                ds.push({ type: 'line', label: 'Connexions Wi-Fi', data: D.sessions, borderColor: C.green, backgroundColor: C.green, tension: .3, yAxisID: 'y1', pointRadius: 2 });
                cfg = { data: { labels: D.days, datasets: ds }, options: { interaction: { mode: 'index', intersect: false },
                    scales: { y: { stacked: true, beginAtZero: true, display: D.money, title: { display: true, text: 'FCFA' } }, y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'Sessions' } } } } };
            }
            salesChart = new Chart(salesCanvas, cfg);
        };
        if (salesCanvas) { drawSales('daily'); }
        document.querySelectorAll('[data-sales-view]').forEach(function (b) {
            b.addEventListener('click', function () {
                document.querySelectorAll('[data-sales-view]').forEach(function (x) { x.className = 'btn ' + (x === b ? 'btn-primary' : 'btn-outline-secondary'); });
                drawSales(b.getAttribute('data-sales-view'));
            });
        });
        var r = document.getElementById('mc-chart-routers');
        if (r) {
            if (!D.routerTotals.length) { empty(r, 'Aucune vente sur la période.'); }
            else { new Chart(r, { type: 'doughnut', data: { labels: D.routers, datasets: [{ data: D.routerTotals, backgroundColor: palette }] }, options: { cutout: '62%', plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (c) { return c.label + ' : ' + fcfa(c.raw); } } } } } }); }
        }
        var o = document.getElementById('mc-chart-offers');
        if (o) {
            if (!D.offerCounts.length) { empty(o, 'Aucun ticket vendu sur la période.'); }
            else { new Chart(o, { type: 'doughnut', data: { labels: D.offers, datasets: [{ data: D.offerCounts, backgroundColor: palette }] }, options: { cutout: '62%', plugins: { legend: { position: 'bottom' } } } }); }
        }
        var t = document.getElementById('mc-chart-traffic-days');
        if (t) { new Chart(t, { type: 'line', data: { labels: D.days, datasets: [{ label: 'Consommation (Mo)', data: D.traffic, borderColor: C.navy, backgroundColor: 'rgba(27,50,96,.12)', fill: true, tension: .3 }] }, options: { scales: { y: { beginAtZero: true } } } }); }
    }

    /* ---------- Monitoring ---------- */
    var mon = document.getElementById('mc-monitor');
    if (mon) {
        var liveUrl = mon.getAttribute('data-live');
        var histUrl = mon.getAttribute('data-history');
        var iface = document.getElementById('mc-mon-iface');
        var state = document.getElementById('mc-mon-state');
        var set = function (id, v) { var el = document.getElementById(id); if (el) { el.textContent = v; } };
        var traffic = new Chart(document.getElementById('mc-chart-traffic'), { type: 'line',
            data: { labels: [], datasets: [{ label: 'Téléchargement (Mbit/s)', data: [], borderColor: C.red, backgroundColor: 'rgba(215,25,42,.1)', fill: true, tension: .3, pointRadius: 0 },
                { label: 'Envoi (Mbit/s)', data: [], borderColor: C.navy, tension: .3, pointRadius: 0 }] },
            options: { animation: false, scales: { y: { beginAtZero: true } } } });
        var poll = function () {
            var u = liveUrl + (iface && iface.value ? '?interface=' + encodeURIComponent(iface.value) : '');
            window.MarwaApi.get(u.replace(document.querySelector('meta[name="app-base"]').content, '')).then(function (d) {
                state.className = 'mc-status ok'; state.textContent = 'En direct · ' + d.time;
                set('mc-mon-cpu', d.cpu + ' %'); set('mc-mon-mem', (d.memory === null ? '—' : d.memory + ' %')); set('mc-mon-active', d.active); set('mc-mon-uptime', d.uptime);
                if (d.rx_bps !== null) {
                    traffic.data.labels.push(d.time);
                    // tx du routeur = téléchargement des clients
                    traffic.data.datasets[0].data.push((d.tx_bps / 1e6).toFixed(2));
                    traffic.data.datasets[1].data.push((d.rx_bps / 1e6).toFixed(2));
                    if (traffic.data.labels.length > 60) { traffic.data.labels.shift(); traffic.data.datasets.forEach(function (x) { x.data.shift(); }); }
                    traffic.update();
                }
            }).catch(function (e) { state.className = 'mc-status bad'; state.textContent = e.message; });
        };
        if (iface) { iface.addEventListener('change', function () { traffic.data.labels = []; traffic.data.datasets.forEach(function (x) { x.data = []; }); poll(); }); }
        poll();
        setInterval(poll, 5000);
        window.MarwaApi.get(histUrl.replace(document.querySelector('meta[name="app-base"]').content, '')).then(function (d) {
            var labels = d.rows.map(function (r) { return r.created_at.substr(11, 5); });
            new Chart(document.getElementById('mc-chart-load'), { type: 'line', data: { labels: labels, datasets: [
                { label: 'CPU %', data: d.rows.map(function (r) { return r.cpu_load; }), borderColor: C.red, pointRadius: 0, tension: .3 },
                { label: 'Mémoire %', data: d.rows.map(function (r) { return r.mem; }), borderColor: C.navy, pointRadius: 0, tension: .3 }] }, options: { scales: { y: { beginAtZero: true, max: 100 } } } });
            new Chart(document.getElementById('mc-chart-users'), { type: 'bar', data: { labels: labels, datasets: [
                { label: 'Clients connectés', data: d.rows.map(function (r) { return r.active_users; }), backgroundColor: C.green, borderRadius: 3 }] }, options: { scales: { y: { beginAtZero: true } } } });
        });
    }
})();
