(function () {
    var box = document.querySelector('[data-order-poll]');
    if (!box || box.getAttribute('data-order-status') !== 'awaiting_payment') { return; }
    var url = box.getAttribute('data-order-poll');
    setInterval(function () {
        fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.status !== 'awaiting_payment') { window.location.reload(); } })
            .catch(function () {});
    }, 8000);
})();
