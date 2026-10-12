'use strict';
/**
 * Lecture de la configuration : le moteur interroge PHP (bin/monitor_config.php)
 * pour réutiliser config/config.php sans dupliquer le jeton ni l'URL.
 * Variables d'environnement prioritaires : VERIFIA_BASE_URL, VERIFIA_TOKEN, VERIFIA_PHP, VERIFIA_BROWSER_PATH, VERIFIA_HEADLESS.
 */
const { execFileSync } = require('node:child_process');
const path = require('node:path');

function loadConfig() {
  const root = path.resolve(__dirname, '..', '..');
  let cfg = {};
  const php = process.env.VERIFIA_PHP || 'php';
  try {
    const out = execFileSync(php, [path.join(root, 'bin', 'monitor_config.php')], { encoding: 'utf8', timeout: 15000, env: process.env });
    cfg = JSON.parse(out);
  } catch (e) {
    if (!process.env.VERIFIA_TOKEN) {
      throw new Error(`Impossible de lire la configuration via PHP (${php}). Vérifiez que php.exe est dans le PATH ou définissez VERIFIA_PHP. Détail : ${e.message}`);
    }
  }
  const env = process.env;
  return {
    baseUrl: (env.VERIFIA_BASE_URL || cfg.base_url || 'http://localhost/verifia/public').replace(/\/$/, ''),
    token: env.VERIFIA_TOKEN || cfg.token,
    browserPath: env.VERIFIA_BROWSER_PATH ?? cfg.browser_path ?? '',
    browserChannel: env.VERIFIA_BROWSER_CHANNEL ?? cfg.browser_channel ?? '',
    headless: env.VERIFIA_HEADLESS ? env.VERIFIA_HEADLESS === '1' : !!cfg.headless,
    storagePath: env.VERIFIA_STORAGE || cfg.storage_path || path.join(root, 'storage'),
    pollIntervalMs: +(env.VERIFIA_POLL_MS || 1000),
  };
}

module.exports = { loadConfig };
