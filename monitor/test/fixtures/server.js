'use strict';
/**
 * Site d'actualités FICTIF servant aux tests de bout en bout (aucun site réel n'est sollicité).
 *  /feed     : fil avec défilement infini (chargement différé de nouvelles publications)
 *  /captcha  : page simulant un contrôle anti-robot
 *  /login    : page de connexion
 */
const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');

const POSTS = [
  { title: 'Archives : inondations à Agadez', date: '2021-08-14', img: 'img1.png', text: 'Archives du 14 août 2021 : vue du quartier Dagmanet à Agadez après les inondations, selon la protection civile.' },
  { title: 'Marché de Katako', date: '2026-10-11', text: 'Le marché de Katako ouvrira normalement demain matin selon la mairie de Niamey.' },
  { title: 'Révélation', date: '2026-10-12', text: "URGENT !!! Ce qu'on vous cache : le ministre des finances a été arrêté ce matin à Tahoua. Les médias ne vous diront pas la vérité. Partagez avant que ce soit supprimé !" },
  { title: 'Rentrée scolaire', date: '2026-10-10', img: 'img2.png', text: "La rentrée scolaire est fixée au 1er octobre d'après le ministère de l'Éducation nationale. Les inscriptions restent ouvertes." },
  { title: 'Tribune', date: '2026-10-09', text: 'À mon avis, la nouvelle politique agricole est une erreur. Je pense que les producteurs méritent mieux.' },
  { title: 'Santé', date: '2026-10-12', text: 'SCANDALE !!! Remède miracle : cette plante guérit le paludisme en 24 heures, les médecins détestent. Partagez massivement !' },
  { title: 'Sport', date: '2026-10-08', img: 'img3.png', text: 'Le championnat national de football reprendra samedi avec douze équipes engagées au stade Général Seyni Kountché.' },
  { title: 'Économie', date: '2026-10-07', text: 'Selon la BCEAO, le taux directeur reste inchangé à 3,5 % pour le trimestre en cours.' },
  { title: 'Inondations', date: '2026-10-12', img: 'img1_repost.jpg', text: "Aujourd'hui à Niamey, la ville est entièrement sous les eaux ! Regardez cette photo prise il y a une heure." },
  { title: 'Satire', date: '2026-10-06', text: '#satire Le gouvernement annonce que les lundis seront supprimés en 2027 pour améliorer le moral des fonctionnaires.' },
  { title: 'Culture', date: '2026-10-05', img: 'img4.png', text: 'Le festival de la musique sahélienne se tiendra à Zinder du 20 au 23 novembre avec quarante artistes invités.' },
  { title: 'Sécurité', date: '2026-10-12', text: "ALERTE : une attaque aurait fait 40 morts près de Diffa cette nuit, information non confirmée. Partagez avant qu'il soit supprimé !!!" },
];
const PAGE = 4;

function article(i) {
  const p = POSTS[i];
  return `<article class="post" style="margin:16px 0;padding:12px;border:1px solid #ccc;min-height:260px">
    <h2><a href="/article/${i + 1}">${p.title}</a></h2>
    <div class="author">Rédaction Test</div>
    <time datetime="${p.date}">${p.date}</time>
    <p>${p.text}</p>
    ${p.img ? `<img src="/img/${p.img}" width="480" height="315" alt="${p.title}">` : ''}
  </article>`;
}

function feedPage() {
  return `<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>Fil de test</title></head>
  <body style="font-family:sans-serif;max-width:720px;margin:auto">
  <h1>Actualités (site fictif de test)</h1>
  <div id="feed">${POSTS.slice(0, PAGE).map((_, i) => article(i)).join('')}</div>
  <div id="loader" style="height:40px">…</div>
  <script>
    let page = 1, loading = false;
    window.addEventListener('scroll', async () => {
      if (loading || window.innerHeight + window.scrollY < document.body.scrollHeight - 300) return;
      loading = true;
      const r = await fetch('/more?page=' + page);
      const html = await r.text();
      if (html) { document.getElementById('feed').insertAdjacentHTML('beforeend', html); page++; }
      loading = false;
    });
  </script></body></html>`;
}

function start(port, imgDir) {
  const server = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname === '/feed') { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); return res.end(feedPage()); }
    if (u.pathname === '/more') {
      const page = +u.searchParams.get('page');
      const items = POSTS.slice(page * PAGE, page * PAGE + PAGE).map((_, k) => article(page * PAGE + k)).join('');
      return setTimeout(() => { res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }); res.end(items); }, 700); // chargement différé
    }
    if (u.pathname === '/captcha') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end('<!doctype html><html><body><h1>Vérification</h1><div class="g-recaptcha" data-sitekey="test"></div><p>Je ne suis pas un robot</p><article><p>Contenu derrière le contrôle, ne doit pas être lu.</p></article></body></html>');
    }
    if (u.pathname === '/login') {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
      return res.end('<!doctype html><html><body><form><input name="email"><input type="password" name="pass"><button>Connexion</button></form></body></html>');
    }
    if (u.pathname.startsWith('/img/')) {
      const f = path.join(imgDir, path.basename(u.pathname));
      if (fs.existsSync(f)) { res.writeHead(200, { 'Content-Type': f.endsWith('.png') ? 'image/png' : 'image/jpeg' }); return fs.createReadStream(f).pipe(res); }
    }
    if (u.pathname.startsWith('/article/')) { res.writeHead(200, { 'Content-Type': 'text/html' }); return res.end('<p>article</p>'); }
    res.writeHead(404); res.end();
  });
  return new Promise((resolve) => server.listen(port, '127.0.0.1', () => resolve(server)));
}

module.exports = { start, POSTS };
if (require.main === module) {
  start(+(process.argv[2] || 8092), process.argv[3] || path.join(__dirname, 'img')).then(() => console.log('Site de test prêt'));
}
