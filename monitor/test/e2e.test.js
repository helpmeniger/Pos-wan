'use strict';
/**
 * Test de bout en bout : serveur PHP + MySQL réels, moteur d'analyse, moteur de surveillance Playwright
 * (headless), site fictif local. Aucune donnée n'est simulée côté VERIFIA.
 *
 * Prérequis : MySQL/MariaDB accessible, php dans le PATH (ou VERIFIA_PHP), dépendances installées.
 * Variables : VERIFIA_E2E_DB_USER (root), VERIFIA_E2E_DB_PASS (''), VERIFIA_E2E_DB_HOST (127.0.0.1).
 * La base « verifia_e2e » est EFFACÉE puis recréée.
 */
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { spawn, execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const crypto = require('node:crypto');
const fixtures = require('./fixtures/server');

const ROOT = path.resolve(__dirname, '..', '..');
const PHP = process.env.VERIFIA_PHP || 'php';
const WEB_PORT = 8091, SITE_PORT = 8092;
const BASE = `http://127.0.0.1:${WEB_PORT}/public`;
const SITE = `http://127.0.0.1:${SITE_PORT}`;
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'verifia-e2e-'));
const token = crypto.randomBytes(24).toString('hex');
const configFile = path.join(tmp, 'config.php');
const procs = [];
let siteServer;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const log = [];

function writeConfig() {
  const db = { user: process.env.VERIFIA_E2E_DB_USER || 'root', pass: process.env.VERIFIA_E2E_DB_PASS || '', host: process.env.VERIFIA_E2E_DB_HOST || '127.0.0.1' };
  const q = (s) => "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
  fs.writeFileSync(configFile, `<?php return [
    'app' => ['name' => 'VERIFIA', 'base_url' => ${q(BASE)}, 'timezone' => 'UTC', 'debug' => true],
    'db' => ['dsn' => ${q(`mysql:host=${db.host};port=3306;dbname=verifia_e2e;charset=utf8mb4`)}, 'user' => ${q(db.user)}, 'password' => ${q(db.pass)}],
    'monitor' => ['token' => ${q(token)}, 'allowed_ips' => ['127.0.0.1', '::1'], 'browser_path' => '', 'browser_channel' => '', 'headless' => true],
    'storage' => ['path' => ${q(path.join(tmp, 'storage'))}],
    'security' => ['allowed_origins' => [${q('http://127.0.0.1:' + WEB_PORT)}], 'session_name' => 'VERIFIASESSID', 'login_max_attempts' => 5, 'login_lock_minutes' => 15],
  ];`);
  return db;
}

function startProc(name, cmd, args, extraEnv = {}) {
  const p = spawn(cmd, args, { cwd: ROOT, env: { ...process.env, VERIFIA_CONFIG: configFile, ...extraEnv }, stdio: ['ignore', 'pipe', 'pipe'] });
  const push = (d) => log.push(`[${name}] ${d.toString().trim()}`);
  p.stdout.on('data', push);
  p.stderr.on('data', push);
  procs.push(p);
  return p;
}

// --- Client HTTP avec cookies (navigateur minimal) ---
class Client {
  constructor() { this.cookies = {}; this.csrf = ''; }
  async req(url, { method = 'GET', body = null, json = null, headers = {} } = {}) {
    const h = { ...headers, Cookie: Object.entries(this.cookies).map(([k, v]) => `${k}=${v}`).join('; ') };
    if (json) { h['Content-Type'] = 'application/json'; body = JSON.stringify(json); }
    const r = await fetch(BASE + '/' + url, { method, body, headers: h, redirect: 'manual' });
    for (const c of r.headers.getSetCookie?.() || []) { const [kv] = c.split(';'); const [k, v] = kv.split('='); this.cookies[k] = v; }
    return r;
  }
  async api(url, json = null, withCsrf = true) {
    const r = await this.req(url, json ? { method: 'POST', json, headers: withCsrf ? { 'X-CSRF-Token': this.csrf } : {} } : {});
    const data = await r.json().catch(() => ({}));
    data._status = r.status;
    return data;
  }
  async login(user, pass) {
    const page = await (await this.req('login.php')).text();
    const csrf = page.match(/name="_csrf" value="([a-f0-9]+)"/)[1];
    const r = await this.req('login.php', { method: 'POST', body: new URLSearchParams({ _csrf: csrf, username: user, password: pass }), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
    assert.equal(r.status, 303, 'connexion réussie');
    const home = await (await this.req('index.php')).text();
    this.csrf = home.match(/name="csrf-token" content="([a-f0-9]+)"/)[1];
  }
}

async function waitFor(label, fn, timeoutMs = 60000, every = 1000) {
  const end = Date.now() + timeoutMs;
  let last;
  while (Date.now() < end) {
    last = await fn();
    if (last) return last;
    await sleep(every);
  }
  throw new Error(`Délai dépassé : ${label}\n--- journaux ---\n${log.slice(-40).join('\n')}`);
}

const admin = new Client();
const sessions = {};

before(async () => {
  writeConfig();
  // Base de test vierge
  execFileSync(PHP, ['-r', `$c = require '${configFile}'; $pdo = new PDO(preg_replace('/dbname=[^;]+;?/', '', $c['db']['dsn']), $c['db']['user'], $c['db']['password']); $pdo->exec('DROP DATABASE IF EXISTS verifia_e2e');`]);
  execFileSync(PHP, [path.join(ROOT, 'bin', 'install.php'), `--config=${configFile}`, '--admin-user=admin', '--admin-pass=AdminE2E-2026'], { env: { ...process.env, VERIFIA_CONFIG: configFile } });
  execFileSync(PHP, ['-r', `require '${ROOT}/src/bootstrap.php'; Verifia\\Auth::createUser('lecteur', 'Lecteur', 'LecteurE2E-2026', 'viewer'); Verifia\\Settings::set('max_concurrent_sessions', '4'); Verifia\\Settings::set('memory_reserve_mb', '0'); Verifia\\Settings::set('session_memory_mb', '100');`], { env: { ...process.env, VERIFIA_CONFIG: configFile } });
  const imgDir = path.join(tmp, 'img');
  execFileSync(PHP, [path.join(__dirname, 'fixtures', 'make_images.php'), imgDir]);
  siteServer = await fixtures.start(SITE_PORT, imgDir);
  startProc('web', PHP, ['-S', `127.0.0.1:${WEB_PORT}`, '-t', ROOT], { PHP_CLI_SERVER_WORKERS: '8' });
  await waitFor('serveur web', async () => (await fetch(BASE + '/login.php').catch(() => null))?.ok, 15000, 300);
  startProc('worker', PHP, [path.join(ROOT, 'bin', 'worker.php')]);
  startProc('monitor', process.execPath, [path.join(ROOT, 'monitor', 'src', 'index.js')], { VERIFIA_HEADLESS: '1' });
  await admin.login('admin', 'AdminE2E-2026');
});

after(async () => {
  for (const p of procs.reverse()) {
    p.kill('SIGTERM');
    await new Promise((r) => { const t = setTimeout(r, 8000); p.on('exit', () => { clearTimeout(t); r(); }); });
  }
  siteServer?.close();
  if (process.env.VERIFIA_E2E_KEEP) console.log('Fichiers conservés dans', tmp);
  else fs.rmSync(tmp, { recursive: true, force: true });
});

const db = (sql) => JSON.parse(execFileSync(PHP, ['-r', `require '${ROOT}/src/bootstrap.php'; echo json_encode(Verifia\\Database::all(${JSON.stringify(sql)}));`], { env: { ...process.env, VERIFIA_CONFIG: configFile } }).toString());

test('1. services démarrés (moteur de surveillance et moteur d\'analyse)', async () => {
  const s = await waitFor('signaux des services', async () => {
    const r = await admin.api('api/stats.php');
    return r.overview?.services?.monitor?.alive && r.overview?.services?.worker?.alive ? r.overview : null;
  }, 30000);
  assert.ok(s.services.monitor.info.total_mem_mb > 0, 'ressources de la machine rapportées');
});

test('2. plusieurs sessions indépendantes créées et démarrées', async () => {
  for (const [k, url, name] of [['a', `${SITE}/feed`, 'Fil A'], ['b', `${SITE}/feed`, 'Fil B (même site)'], ['c', `${SITE}/captcha`, 'Site protégé'], ['d', `${SITE}/login`, 'Site avec connexion']]) {
    const r = await admin.api('api/sessions.php', { op: 'create', name, start_url: url, scroll_step_px: 700, scroll_delay_ms: 1200, processing_interval_ms: 500, max_posts: 100, max_duration_min: 30 });
    assert.equal(r.ok, true, JSON.stringify(r));
    sessions[k] = r.id;
    const a = await admin.api('api/sessions.php', { op: 'action', id: r.id, action: 'start' });
    assert.equal(a.ok, true, a.message);
  }
  await waitFor('4 navigateurs ouverts', async () => {
    const r = await admin.api('api/sessions.php');
    return r.sessions.filter((s) => ['running', 'paused'].includes(s.run_state)).length === 4;
  }, 60000);
});

test('3. CAPTCHA et connexion requise détectés : session suspendue, rien n\'est lu', async () => {
  const st = await waitFor('détection', async () => {
    const r = await admin.api('api/sessions.php');
    const c = r.sessions.find((s) => s.id === sessions.c), d = r.sessions.find((s) => s.id === sessions.d);
    return c.connection_state === 'captcha' && d.connection_state === 'login_required' ? { c, d } : null;
  }, 30000);
  assert.equal(st.c.run_state, 'paused');
  assert.match(st.c.status_message, /manuellement/);
  assert.equal(+st.c.posts_seen, 0, 'aucune publication lue derrière le CAPTCHA');
});

test('4. défilement automatique : publications chargées dynamiquement détectées', async () => {
  const total = fixtures.POSTS.length;
  await waitFor('toutes les publications du fil', async () => {
    const r = db('SELECT COUNT(*) n FROM detected_publications');
    return +r[0].n >= total - 2;
  }, 120000, 2000);
  const late = db("SELECT COUNT(*) n FROM detected_publications WHERE permalink LIKE '%/article/1_' OR permalink LIKE '%/article/9'");
  assert.ok(+late[0].n >= 2, 'des publications chargées après défilement (pages 2 et 3) ont été vues');
});

test('5. déduplication : une publication vue par deux sessions n\'est analysée qu\'une fois', async () => {
  await waitFor('occurrences multiples', async () => +db('SELECT COUNT(*) n FROM detected_publications WHERE occurrences >= 2')[0].n >= 4, 90000, 2000);
  const pubs = +db('SELECT COUNT(*) n FROM detected_publications')[0].n;
  const jobs = +db('SELECT COUNT(*) n FROM analysis_jobs')[0].n;
  assert.equal(jobs, pubs, 'exactement une tâche d\'analyse par publication');
  const dup = db('SELECT permalink, COUNT(*) n FROM detected_publications GROUP BY permalink HAVING n > 1');
  assert.deepEqual(dup, [], 'aucun doublon de publication');
  const ingestErrors = db("SELECT COUNT(*) n FROM system_events WHERE component = 'ingest' AND level = 'error'");
  assert.equal(+ingestErrors[0].n, 0, 'détections simultanées par deux sessions sans erreur (transactions rejouées)');
});

test('6. analyses en arrière-plan et alertes dans le centre (API + flux SSE)', async () => {
  await waitFor('analyses terminées', async () => +db("SELECT COUNT(*) n FROM analysis_jobs WHERE status IN ('pending','running')")[0].n === 0, 60000);
  const alerts = await admin.api('api/alerts.php');
  assert.ok(alerts.total >= 3, `au moins 3 alertes (obtenu ${alerts.total})`);
  const reasons = alerts.items.map((a) => a.reason).join(' | ');
  assert.match(reasons, /Image déjà rencontrée/, 'image ancienne republiée signalée');
  const matches = db('SELECT m.match_type, m.distance, a.publication_id AS old_pub, b.publication_id AS new_pub FROM image_matches m JOIN image_assets a ON a.id = m.matched_image_id JOIN image_assets b ON b.id = m.image_id');
  assert.equal(matches.length, 1, 'une seule correspondance (pas de faux positif entre images différentes) : ' + JSON.stringify(matches));
  assert.ok(alerts.items.every((a) => a.session_name && a.site_name), 'session et site indiqués');
  const opinion = db("SELECT r.priority, r.category FROM analysis_results r JOIN publication_content c ON c.publication_id = r.publication_id WHERE c.text_content LIKE '%À mon avis%'");
  assert.equal(opinion[0].category, 'opinion');
  assert.ok(['low', 'normal'].includes(opinion[0].priority), 'l\'opinion n\'est pas assimilée à une fausse information');
  const satire = db("SELECT r.category FROM analysis_results r JOIN publication_content c ON c.publication_id = r.publication_id WHERE c.text_content LIKE '%#satire%'");
  assert.equal(satire[0].category, 'satire');

  // Flux temps réel : une alerte créée maintenant arrive par SSE
  const ctrl = new AbortController();
  const r = await admin.req('api/stream.php?since_id=' + Math.max(...alerts.items.map((a) => +a.id)), { headers: { Accept: 'text/event-stream' } });
  const reader = r.body.getReader();
  const manual = await admin.req('manual.php');
  const csrf = (await manual.text()).match(/name="_csrf" value="([a-f0-9]+)"/)[1];
  const fd = new FormData();
  fd.set('_csrf', csrf);
  fd.set('text', "URGENT !!! Ce qu'on vous cache : le barrage de Kandadji a cédé ce matin. Partagez avant que ce soit supprimé ! Photo de 2019 à Tillabéri.");
  await admin.req('manual.php', { method: 'POST', body: fd });
  let buf = '';
  const deadline = Date.now() + 20000;
  while (!/event: alert/.test(buf) && Date.now() < deadline) {
    const { value, done } = await reader.read();
    if (done) break;
    buf += new TextDecoder().decode(value);
  }
  ctrl.abort();
  reader.cancel().catch(() => {});
  assert.match(buf, /event: alert/, 'alerte reçue en temps réel');
  assert.match(buf, /Kandadji/);
});

test('7. fiche détaillée et rapport PDF', async () => {
  const alerts = await admin.api('api/alerts.php');
  const img = alerts.items.find((a) => /Image déjà rencontrée/.test(a.reason));
  const html = await (await admin.req('alert.php?id=' + img.id)).text();
  assert.match(html, /Chronologie des occurrences/);
  assert.match(html, /pas nécessairement à la date de création/);
  assert.match(html, /aucune recherche externe enregistrée|Aucune affirmation/);
  const pdf = await admin.req('report.php?id=' + img.id);
  assert.equal(pdf.headers.get('content-type'), 'application/pdf');
  const bytes = Buffer.from(await pdf.arrayBuffer());
  assert.equal(bytes.subarray(0, 4).toString(), '%PDF');
});

test('8. suspendre / reprendre / arrêter une session sans affecter les autres', async () => {
  await admin.api('api/sessions.php', { op: 'action', id: sessions.a, action: 'pause' });
  await waitFor('pause', async () => (await admin.api('api/sessions.php')).sessions.find((s) => s.id === sessions.a).run_state === 'paused', 15000);
  await admin.api('api/sessions.php', { op: 'action', id: sessions.a, action: 'resume' });
  await waitFor('reprise', async () => (await admin.api('api/sessions.php')).sessions.find((s) => s.id === sessions.a).run_state === 'running', 15000);
  await admin.api('api/sessions.php', { op: 'action', id: sessions.b, action: 'stop' });
  const st = await waitFor('arrêt de B', async () => { const r = await admin.api('api/sessions.php'); const b = r.sessions.find((s) => s.id === sessions.b); return b.run_state === 'stopped' ? r : null; }, 20000);
  assert.equal(st.sessions.find((s) => s.id === sessions.a).run_state, 'running', 'A continue pendant l\'arrêt de B');
});

test('9. analyse manuelle d\'une session (« Analyser maintenant ») est transmise', async () => {
  const r = await admin.api('api/sessions.php', { op: 'action', id: sessions.a, action: 'scan_once' });
  assert.equal(r.ok, true);
  await waitFor('commande exécutée', async () => db(`SELECT status FROM session_commands WHERE session_id = ${sessions.a} AND command = 'scan_once'`)[0]?.status === 'done', 15000);
});

test('10. arrêt immédiat de toute la surveillance', async () => {
  const r = await admin.api('api/sessions.php', { op: 'emergency_stop' });
  assert.equal(r.ok, true);
  await waitFor('tous les navigateurs fermés', async () => {
    const s = await admin.api('api/stats.php');
    const list = await admin.api('api/sessions.php');
    return s.overview.services.monitor.info.open_browsers === 0 && list.sessions.every((x) => ['stopped', 'closed'].includes(x.run_state));
  }, 30000);
  const refused = await admin.api('api/sessions.php', { op: 'action', id: sessions.a, action: 'start' });
  assert.equal(refused.ok, false, 'démarrage refusé pendant l\'arrêt immédiat');
  await admin.api('api/sessions.php', { op: 'emergency_release' });
});

test('11. les alertes restent consultables après fermeture des sessions', async () => {
  const before = (await admin.api('api/alerts.php')).total;
  for (const id of Object.values(sessions)) await admin.api('api/sessions.php', { op: 'action', id, action: 'close' });
  const after = await admin.api('api/alerts.php');
  assert.equal(after.total, before);
  assert.ok(after.items.some((a) => a.session_available != 1));
  const html = await (await admin.req('alert.php?id=' + after.items[0].id)).text();
  assert.match(html, /Alerte n°/);
});

test('12. erreurs enregistrées et visibles dans le journal', async () => {
  const ev = db("SELECT COUNT(*) n FROM system_events WHERE level IN ('warning','error') AND component IN ('session','monitor')");
  assert.ok(+ev[0].n >= 2, 'CAPTCHA / connexion consignés');
  const html = await (await admin.req('events.php')).text();
  assert.match(html, /Intervention manuelle requise|CAPTCHA/);
});

test('13. sécurité : authentification, rôles, CSRF, jeton du moteur, XSS, chemins', async () => {
  const anon = new Client();
  assert.equal((await anon.api('api/alerts.php'))._status, 401, 'API refusée sans connexion');
  assert.equal((await anon.req('index.php')).status, 303, 'page redirigée vers la connexion');
  assert.equal((await admin.api('api/sessions.php', { op: 'create', start_url: 'https://x.example' }, false))._status, 419, 'POST sans jeton CSRF refusé');
  const evil = await admin.req('api/sessions.php', { method: 'POST', json: { op: 'emergency_stop' }, headers: { 'X-CSRF-Token': admin.csrf, Origin: 'http://evil.example' } });
  assert.equal(evil.status, 419, 'origine étrangère refusée');

  const viewer = new Client();
  await viewer.login('lecteur', 'LecteurE2E-2026');
  assert.equal((await viewer.api('api/sessions.php', { op: 'emergency_stop' }))._status, 403, 'un observateur ne peut pas agir');
  assert.equal((await viewer.req('settings.php')).status, 403, 'administration réservée');
  assert.ok((await viewer.api('api/alerts.php')).total >= 1, 'un observateur peut consulter');

  const m = await fetch(BASE + '/api/monitor.php?action=poll', { method: 'POST', headers: { 'X-Verifia-Token': 'faux' }, body: '{}' });
  assert.equal(m.status, 403, 'API du moteur protégée par jeton');

  for (const f of ['../config/config.php', 'images/../../config/config.php', 'run/screens/x.jpg']) {
    assert.equal((await admin.req('media.php?f=' + encodeURIComponent(f))).status, 404, 'chemin refusé : ' + f);
  }
  const x = await admin.api('api/sessions.php', { op: 'create', name: '<img src=x onerror=alert(1)>', start_url: `${SITE}/feed` });
  const page = await (await admin.req('alerts.php?q=%3Cscript%3E')).text();
  assert.ok(!/<script>alert/.test(page));
  const pub = db("SELECT p.id FROM detected_publications p ORDER BY id DESC LIMIT 1")[0].id;
  const fd = new FormData();
  fd.set('_csrf', (await (await admin.req('manual.php')).text()).match(/name="_csrf" value="([a-f0-9]+)"/)[1]);
  fd.set('text', 'Test XSS <script>alert(1)</script> dans une publication à analyser.');
  await admin.req('manual.php', { method: 'POST', body: fd });
  const last = db('SELECT id FROM detected_publications ORDER BY id DESC LIMIT 1')[0].id;
  assert.ok(last > pub);
  const fiche = await (await admin.req('alert.php?pub=' + last)).text();
  assert.match(fiche, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/, 'contenu échappé');
  assert.ok(!fiche.includes('<script>alert(1)</script>'));
  const csp = (await admin.req('index.php')).headers.get('content-security-policy');
  assert.match(csp, /script-src 'self'/);
  assert.equal(x.ok, true);
});
