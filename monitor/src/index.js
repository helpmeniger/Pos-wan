'use strict';
/**
 * VERIFIA — moteur de surveillance (processus indépendant du serveur web).
 * Boucle de supervision : toutes les secondes, il
 *   1. rapporte au serveur l'état réel des sessions et les ressources de la machine ;
 *   2. reçoit l'état demandé par l'interface et les commandes ponctuelles ;
 *   3. ouvre / suspend / ferme les navigateurs pour converger vers cet état.
 * Arrêt propre : Ctrl+C, signal SIGTERM ou fichier storage/run/monitor.stop.
 */
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { loadConfig } = require('./config');
const { Api } = require('./api');
const { SessionRunner, sleep } = require('./sessionRunner');

const log = (...a) => console.log(`[${new Date().toLocaleTimeString('fr-FR')}]`, ...a);

class Engine {
  constructor(cfg, chromium) {
    this.cfg = cfg;
    this.api = new Api(cfg.baseUrl, cfg.token);
    this.chromium = chromium;
    this.storagePath = cfg.storagePath;
    this.runners = new Map();
    this.desired = new Map();
    this.limits = { max_sessions: 4, session_memory_mb: 450, memory_reserve_mb: 1500 };
    this.previews = true;
    this.running = true;
    this.consecutiveFailures = 0;
    this.launchOptions = { headless: cfg.headless, args: ['--no-first-run', '--no-default-browser-check'] };
    if (cfg.browserPath) this.launchOptions.executablePath = cfg.browserPath;
    else if (cfg.browserChannel) this.launchOptions.channel = cfg.browserChannel;
  }

  wants(id) { return this.desired.get(id) || 'stopped'; }

  hostInfo() {
    const open = [...this.runners.values()].filter((r) => r.isOpen).length;
    return {
      pid: process.pid,
      platform: `${os.type()} ${os.release()}`,
      total_mem_mb: Math.round(os.totalmem() / 1048576),
      free_mem_mb: Math.round(os.freemem() / 1048576),
      cpu_count: os.cpus().length,
      cpu_load: process.platform === 'win32' ? null : os.loadavg()[0].toFixed(2),
      rss_mb: Math.round(process.memoryUsage().rss / 1048576),
      open_browsers: open,
      node: process.version,
    };
  }

  /** Vérifie la capacité avant d'ouvrir un navigateur supplémentaire. */
  capacityOk() {
    const open = [...this.runners.values()].filter((r) => r.isOpen || r.runState === 'starting').length;
    if (open >= this.limits.max_sessions) return `Capacité atteinte (${open}/${this.limits.max_sessions} navigateurs ouverts).`;
    const freeMb = os.freemem() / 1048576;
    if (freeMb - this.limits.memory_reserve_mb < this.limits.session_memory_mb) {
      return `Mémoire libre insuffisante (${Math.round(freeMb)} Mo libres, réserve ${this.limits.memory_reserve_mb} Mo + ${this.limits.session_memory_mb} Mo par navigateur).`;
    }
    return null;
  }

  async tick() {
    const resp = await this.api.poll({ host: this.hostInfo(), sessions: [...this.runners.values()].map((r) => r.report()) });
    this.consecutiveFailures = 0;
    this.limits = { max_sessions: resp.max_sessions, session_memory_mb: resp.session_memory_mb, memory_reserve_mb: resp.memory_reserve_mb };
    this.previews = resp.session_previews !== false;

    const known = new Set();
    for (const s of resp.sessions) {
      known.add(s.id);
      const desired = resp.emergency_stop ? 'stopped' : s.desired_state;
      this.desired.set(s.id, desired);
      let r = this.runners.get(s.id);
      if (!r) { r = new SessionRunner(s, this); this.runners.set(s.id, r); }
      const urlChanged = r.applyConfig(s);
      if (desired === 'running' || desired === 'paused') {
        if (urlChanged && r.isOpen) { log(`Session ${s.id} : nouvelle URL, redémarrage`); r.restart(); continue; }
        if (r.canAutoStart) {
          const why = this.capacityOk();
          if (why) {
            if (r.message !== why) { r.setStatus('stopped', why); this.api.event('warning', 'Démarrage refusé : ' + why, s.id); }
          } else {
            log(`Session ${s.id} (${s.name}) : ouverture`);
            r.start();
          }
        }
      } else if (r.isOpen || r.runState === 'starting') {
        log(`Session ${s.id} : fermeture (${desired})`);
        await r.stop(resp.emergency_stop ? 'Arrêt immédiat global.' : desired === 'closed' ? 'Session fermée.' : 'Session arrêtée.');
        if (desired === 'closed') r.setStatus('closed', 'Session fermée.');
      } else {
        r.resetFlags();
        if (desired === 'closed' && r.runState !== 'closed') r.setStatus('closed', 'Session fermée.');
      }
    }
    // Sessions supprimées ou fermées et déjà rapportées
    for (const [id, r] of this.runners) {
      if (!known.has(id)) { if (r.isOpen) await r.stop(); this.runners.delete(id); this.desired.delete(id); }
    }
    for (const c of resp.commands) {
      const r = this.runners.get(c.session_id);
      let ok = false;
      try {
        if (r && !resp.emergency_stop) {
          ok = c.command === 'restart' && !r.isOpen ? true : await r.command(c);
          if (c.command === 'restart') { r.resetFlags(); if (!r.isOpen && r.canAutoStart && !this.capacityOk()) r.start(); }
        }
      } catch (e) { this.api.event('error', `Commande ${c.command} échouée : ${e.message}`, c.session_id); }
      this.api.commandDone(c.id, ok);
    }
  }

  async run() {
    log(`Moteur de surveillance démarré (PID ${process.pid}) → ${this.cfg.baseUrl}`);
    const stopFile = path.join(this.storagePath, 'run', 'monitor.stop');
    await this.api.event('info', `Moteur de surveillance démarré (PID ${process.pid})`).catch(() => {});
    while (this.running) {
      if (fs.existsSync(stopFile)) { try { fs.unlinkSync(stopFile); } catch (e) { /* ignoré */ } break; }
      try {
        await this.tick();
      } catch (e) {
        this.consecutiveFailures++;
        if (this.consecutiveFailures === 1 || this.consecutiveFailures % 30 === 0) log('Serveur VERIFIA injoignable :', e.message);
        // Sans contact avec le serveur pendant 60 s, on ferme les navigateurs par sécurité (l'arrêt immédiat doit rester effectif).
        if (this.consecutiveFailures === 60) {
          log('Serveur injoignable depuis 60 s : fermeture des navigateurs par sécurité.');
          for (const r of this.runners.values()) await r.stop('Serveur VERIFIA injoignable : navigateur fermé par sécurité.');
        }
      }
      await sleep(this.cfg.pollIntervalMs);
    }
    await this.shutdown();
  }

  async shutdown() {
    this.running = false;
    log('Arrêt du moteur : fermeture des navigateurs…');
    await Promise.all([...this.runners.values()].map((r) => r.stop('Moteur de surveillance arrêté.')));
    try { await this.api.poll({ host: this.hostInfo(), sessions: [...this.runners.values()].map((r) => r.report()) }); } catch (e) { /* serveur indisponible */ }
    await this.api.event('info', 'Moteur de surveillance arrêté.').catch(() => {});
    log('Moteur arrêté.');
  }
}

async function main() {
  const cfg = loadConfig();
  if (!cfg.token || cfg.token === 'CHANGE-ME') throw new Error('Jeton du moteur absent : lancez install.bat.');
  let chromium;
  try { ({ chromium } = require('playwright')); } catch (e) {
    throw new Error('Playwright n’est pas installé : exécutez « npm install » dans le dossier monitor (install.bat le fait).');
  }
  const engine = new Engine(cfg, chromium);
  let signals = 0;
  const onSignal = () => { if (++signals > 1) process.exit(1); engine.running = false; };
  process.on('SIGINT', onSignal);
  process.on('SIGTERM', onSignal);
  process.on('unhandledRejection', (e) => { log('Erreur non gérée :', e?.message || e); engine.api.event('error', 'Erreur non gérée du moteur : ' + (e?.message || e)); });
  await engine.run();
}

if (require.main === module) {
  main().then(() => process.exit(0)).catch((e) => { console.error('[VERIFIA] ' + e.message); process.exit(2); });
}

module.exports = { Engine };
