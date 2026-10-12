'use strict';
/**
 * Une session de surveillance = un contexte de navigation persistant et indépendant
 * (profil séparé dans storage/profiles/session-<id>, fenêtre séparée).
 * Chaque session a sa propre boucle asynchrone : une session lente ou bloquée n'arrête pas les autres.
 */
const fs = require('node:fs');
const path = require('node:path');
const ex = require('./extractor');

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const MAX_AUTO_RESTARTS = 3;

class SessionRunner {
  constructor(cfg, engine) {
    this.id = cfg.id;
    this.cfg = cfg;
    this.engine = engine;            // { api, chromium, launchOptions, storagePath, log }
    this.context = null;
    this.page = null;
    this.runState = 'stopped';
    this.connState = 'unknown';
    this.message = '';
    this.seen = new Set();          // clés déjà transmises (dédoublonnage local)
    this.processed = 0;             // publications transmises depuis le démarrage
    this.startedAt = null;
    this.loopPromise = null;
    this.stopping = false;
    this.userClosed = false;
    this.crashCount = 0;
    this.limitReached = null;
    this.manualScan = false;
    this.lastPreview = 0;
    this.restarting = false;
  }

  /** Le moteur peut-il (re)démarrer automatiquement cette session ? */
  get canAutoStart() {
    return !this.context && !this.userClosed && !this.restarting && !this.stopping && this.runState !== 'error' && this.runState !== 'starting';
  }

  /** Réinitialise les blocages quand l'utilisateur arrête explicitement la session. */
  resetFlags() {
    this.userClosed = false;
    this.crashCount = 0;
    if (this.runState === 'error') this.setStatus('stopped', this.message);
  }

  report() {
    let currentUrl = null;
    try { currentUrl = this.page && !this.page.isClosed() ? this.page.url() : null; } catch (e) { /* page fermée */ }
    return { id: this.id, run_state: this.runState, connection_state: this.connState, status_message: this.message, current_url: currentUrl };
  }

  get isOpen() { return !!this.context; }

  applyConfig(cfg) {
    const restartNeeded = this.cfg.start_url !== cfg.start_url;
    this.cfg = cfg;
    return restartNeeded;
  }

  setStatus(run, msg = this.message, conn = this.connState) {
    this.runState = run;
    this.message = msg;
    this.connState = conn;
  }

  async start() {
    if (this.context || this.stopping) return;
    this.userClosed = false;
    this.limitReached = null;
    this.setStatus('starting', 'Ouverture du navigateur…');
    const profileDir = path.join(this.engine.storagePath, 'profiles', 'session-' + this.id);
    fs.mkdirSync(profileDir, { recursive: true });
    const args = [];
    const c = this.cfg;
    if (c.window_x !== null && c.window_y !== null && c.window_x !== undefined) args.push(`--window-position=${c.window_x},${c.window_y}`);
    if (c.window_w && c.window_h) args.push(`--window-size=${c.window_w},${c.window_h}`);
    try {
      this.context = await this.engine.chromium.launchPersistentContext(profileDir, {
        ...this.engine.launchOptions,
        viewport: this.engine.launchOptions.headless ? { width: 1280, height: 900 } : null,
        args: [...(this.engine.launchOptions.args || []), ...args],
      });
    } catch (e) {
      this.context = null;
      this.setStatus('error', 'Impossible d’ouvrir le navigateur : ' + e.message.split('\n')[0]);
      await this.engine.api.event('error', this.message, this.id);
      return;
    }
    this.context.on('close', () => this.onContextClosed());
    this.page = this.context.pages()[0] || await this.context.newPage();
    this.page.on('crash', () => this.onCrash('La page a planté (mémoire insuffisante ?)'));
    this.startedAt = Date.now();
    this.processed = 0;
    try {
      const resp = await this.page.goto(c.start_url, { waitUntil: 'domcontentloaded', timeout: 60000 });
      if (resp && [403, 429].includes(resp.status())) {
        this.setStatus('paused', `Le site a répondu ${resp.status()} : accès refusé ou limité. Session suspendue.`, 'blocked');
        await this.engine.api.event('warning', this.message, this.id);
      } else {
        this.setStatus('running', 'Page ouverte.', 'unknown');
      }
    } catch (e) {
      this.setStatus('error', 'Chargement impossible : ' + e.message.split('\n')[0], 'offline');
      await this.engine.api.event('error', this.message, this.id, { url: c.start_url });
    }
    await this.engine.api.event('info', 'Navigateur ouvert (' + c.start_url + ')', this.id);
    this.loopPromise = this.loop().catch((e) => this.onCrash(e.message));
  }

  async stop(reason = 'Session arrêtée.') {
    this.stopping = true;
    const ctx = this.context;
    this.context = null;
    this.page = null;
    if (ctx) {
      try { await ctx.close(); } catch (e) { /* déjà fermé */ }
    }
    try { await this.loopPromise; } catch (e) { /* ignoré */ }
    this.loopPromise = null;
    this.stopping = false;
    this.setStatus('stopped', reason, 'unknown');
    try { fs.rmSync(this.previewPath(), { force: true }); } catch (e) { /* ignoré */ }
  }

  async restart() {
    await this.stop('Redémarrage…');
    this.crashCount = 0;
    await this.start();
  }

  onContextClosed() {
    if (this.stopping || !this.context) return;
    // Fenêtre fermée par l'utilisateur : on ne la rouvre pas automatiquement.
    this.context = null;
    this.page = null;
    this.userClosed = true;
    this.setStatus('stopped', 'Fenêtre du navigateur fermée manuellement. Cliquez sur « Redémarrer » pour la rouvrir.', 'unknown');
    this.engine.api.event('warning', this.message, this.id);
  }

  async onCrash(msg) {
    if (this.stopping) return;
    this.crashCount++;
    await this.engine.api.event('error', `Panne de la session (${this.crashCount}/${MAX_AUTO_RESTARTS}) : ${msg}`, this.id);
    const ctx = this.context;
    this.context = null;
    this.page = null;
    try { await ctx?.close(); } catch (e) { /* ignoré */ }
    if (this.crashCount <= MAX_AUTO_RESTARTS) {
      // Redémarrage contrôlé avec délai croissant
      this.restarting = true;
      this.setStatus('starting', `Panne détectée, redémarrage automatique dans ${5 * this.crashCount} s…`);
      await sleep(5000 * this.crashCount);
      this.restarting = false;
      if (!this.userClosed && ['running', 'paused'].includes(this.engine.wants(this.id))) await this.start();
    } else {
      this.setStatus('error', `Session en erreur après ${MAX_AUTO_RESTARTS} redémarrages automatiques : ${msg}. Utilisez « Redémarrer ».`);
    }
  }

  /** Boucle de surveillance propre à la session. */
  async loop() {
    while (this.context && !this.stopping) {
      const desired = this.engine.wants(this.id);
      const c = this.cfg;
      if (desired === 'paused' && !this.manualScan) {
        if (this.runState !== 'paused' || this.connState === 'ok') this.setStatus('paused', 'Session suspendue par l’utilisateur.');
        await this.maybePreview();
        await sleep(1000);
        continue;
      }
      try {
        const blocker = await this.page.evaluate(ex.detectBlockers);
        if (blocker.state !== 'ok') {
          if (this.connState !== blocker.state) await this.engine.api.event('warning', blocker.message, this.id);
          this.setStatus('paused', blocker.message, blocker.state);
          this.manualScan = false;
          await this.maybePreview();
          await sleep(3000);
          continue;
        }
        if (this.connState !== 'ok') this.setStatus('running', 'Surveillance active.', 'ok');

        const automationAllowed = c.automation_allowed !== 0;
        const doScan = c.analysis_enabled && (automationAllowed || this.manualScan) && !this.limitReached;
        if (doScan || this.manualScan) {
          const n = await this.scanVisible();
          if (this.manualScan) {
            this.setStatus(this.runState, `Analyse manuelle : ${n} nouvelle(s) publication(s) transmise(s).`);
            this.manualScan = false;
          }
        }
        this.checkLimits();
        if (!automationAllowed) {
          this.setStatus('running', 'Automatisation désactivée pour ce site : défilement arrêté. Utilisez « Analyser maintenant » pour analyser les publications visibles.', 'ok');
        } else if (this.limitReached) {
          this.setStatus('paused', this.limitReached, 'ok');
        } else if (c.autoscroll_enabled) {
          await this.scrollStep();
        } else {
          this.setStatus('running', 'Surveillance active (défilement automatique désactivé).', 'ok');
        }
        await this.maybePreview();
        await sleep(Math.max(500, c.processing_interval_ms));
      } catch (e) {
        if (!this.context || this.stopping) break;
        const m = e.message || String(e);
        if (/Target page, context or browser has been closed|Target closed/.test(m)) break;
        if (/Execution context was destroyed|navigation/i.test(m)) { await sleep(1500); continue; } // navigation en cours
        await this.engine.api.event('error', 'Erreur de surveillance : ' + m.split('\n')[0], this.id);
        this.setStatus('error', 'Erreur : ' + m.split('\n')[0] + ' — nouvelle tentative dans 10 s.');
        await sleep(10000);
      }
    }
  }

  checkLimits() {
    const c = this.cfg;
    if (this.processed >= c.max_posts) this.limitReached = `Limite de ${c.max_posts} publications atteinte : défilement arrêté. Redémarrez la session pour continuer.`;
    else if (this.startedAt && Date.now() - this.startedAt > c.max_duration_min * 60000) this.limitReached = `Durée maximale de ${c.max_duration_min} min atteinte : défilement arrêté. Redémarrez la session pour continuer.`;
  }

  /** Défilement contrôlé : un pas, puis attente ; si de nouveaux contenus se chargent, on attend la fin du chargement. */
  async scrollStep() {
    const c = this.cfg;
    const before = await this.page.evaluate(ex.scrollMetrics);
    await this.page.evaluate((step) => window.scrollBy({ top: step, behavior: 'smooth' }), c.scroll_step_px);
    await sleep(600);
    const after = await this.page.evaluate(ex.scrollMetrics);
    if (after.height !== before.height || after.ready !== 'complete') {
      this.setStatus('running', 'Chargement de nouveaux contenus : défilement en pause…', 'ok');
      await this.page.evaluate(ex.waitForDomQuiet, { quietMs: 1200, maxMs: 10000 });
    }
    const atBottom = after.y + after.viewport >= after.height - 5 && after.y === before.y;
    this.setStatus('running', atBottom ? 'Fin de page atteinte : en attente de nouveaux contenus.' : `Défilement automatique actif (${this.processed} publication(s) transmise(s)).`, 'ok');
    await sleep(Math.max(0, c.scroll_delay_ms - 600));
  }

  /** Extrait les publications visibles non encore traitées et les transmet au serveur. */
  async scanVisible() {
    const posts = await this.page.evaluate(ex.extractVisiblePosts, { adapter: this.cfg.adapter || 'generic', maxText: 20000 });
    const fresh = posts.filter((p) => !this.seen.has(p.key));
    let sent = 0;
    for (const p of fresh) {
      if (this.processed >= this.cfg.max_posts) break;
      const payload = { key: p.key, permalink: p.permalink, text: p.text, author: p.author, published_label: p.published_label, links: p.links, images: [] };
      payload.thumbnail_base64 = await this.capture(`[data-verifia-key="${cssEscape(p.key)}"]`, 55);
      for (const img of p.images) {
        const data = await this.capture(`[data-verifia-img="${cssEscape(img.id)}"]`, 85, true);
        if (data) payload.images.push({ data_base64: data, src: img.src, alt: img.alt });
      }
      try {
        // Une publication par requête : reste sous la limite post_max_size de PHP.
        const r = await this.engine.api.ingest(this.id, this.page.url(), [payload]);
        const res = r.results?.[0];
        if (!res || res.status === 'error') continue; // erreur serveur temporaire : nouvel essai au prochain cycle
        this.seen.add(p.key);
        if (res.status !== 'rejected') { this.processed++; sent++; }
      } catch (e) {
        await this.engine.api.event('warning', 'Transmission échouée, nouvelle tentative au prochain cycle : ' + e.message, this.id);
        break;
      }
    }
    if (this.seen.size > 20000) this.seen = new Set([...this.seen].slice(-10000));
    return sent;
  }

  /**
   * Capture JPEG d'un élément. Miniature : partie visible uniquement (aucun défilement).
   * Image (reveal = true) : l'image est rendue entièrement visible, capturée, puis la position de défilement est restaurée.
   */
  async capture(selector, quality, reveal = false) {
    let rv = null;
    try {
      if (reveal) {
        rv = await this.page.evaluate(ex.revealElement, selector);
        if (rv?.moved) await sleep(250);
      }
      const clip = await this.page.evaluate(ex.visibleRect, selector);
      if (!clip) return null;
      clip.height = Math.min(clip.height, 1400);
      const buf = await this.page.screenshot({ clip, type: 'jpeg', quality, animations: 'disabled', timeout: 10000 });
      return buf.length < 2_500_000 ? buf.toString('base64') : null;
    } catch (e) {
      return null;
    } finally {
      if (rv?.moved) await this.page.evaluate((y) => window.scrollTo({ top: y, behavior: 'instant' }), rv.prevY).catch(() => {});
    }
  }

  previewPath() { return path.join(this.engine.storagePath, 'run', 'screens', `session_${this.id}.jpg`); }

  async maybePreview() {
    if (!this.engine.previews || !this.page || Date.now() - this.lastPreview < 10000) return;
    this.lastPreview = Date.now();
    try {
      const buf = await this.page.screenshot({ type: 'jpeg', quality: 40, timeout: 10000, scale: 'css' });
      fs.mkdirSync(path.dirname(this.previewPath()), { recursive: true });
      fs.writeFileSync(this.previewPath() + '.tmp', buf);
      fs.renameSync(this.previewPath() + '.tmp', this.previewPath());
    } catch (e) { /* aperçu facultatif */ }
  }

  async command(cmd) {
    switch (cmd.command) {
      case 'scan_once':
        if (!this.page) return false;
        this.manualScan = true;
        return true;
      case 'focus':
      case 'open_window':
        if (!this.page) return false;
        await this.page.bringToFront();
        return true;
      case 'restart':
        await this.restart();
        return true;
      case 'reload_config':
        return true;
      default:
        return false;
    }
  }
}

function cssEscape(s) { return String(s).replace(/["\\]/g, '\\$&'); }

module.exports = { SessionRunner, sleep };
