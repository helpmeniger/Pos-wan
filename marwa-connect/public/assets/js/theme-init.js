/* Appliqué avant le rendu pour éviter le flash de thème. */
(function () {
    var root = document.documentElement;
    var pref = root.getAttribute('data-theme-pref') || 'auto';
    try {
        var local = localStorage.getItem('mc-theme');
        if (pref === 'auto' && local) { pref = local; }
    } catch (e) { /* stockage indisponible */ }
    if (pref === 'auto') {
        pref = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    root.setAttribute('data-bs-theme', pref);
    try {
        if (localStorage.getItem('mc-sidebar') === 'collapsed') {
            document.addEventListener('DOMContentLoaded', function () { document.body.classList.add('mc-sidebar-collapsed'); });
        }
    } catch (e) { /* ignore */ }
})();
