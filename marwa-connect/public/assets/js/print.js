(function () {
    var b = document.getElementById('do-print');
    if (b) { b.addEventListener('click', function () { window.print(); }); }
    window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });
})();
