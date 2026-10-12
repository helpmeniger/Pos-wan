'use strict';
/** Client de l'API interne PHP (api/monitor.php). Toutes les requêtes portent le jeton partagé. */
class Api {
  constructor(baseUrl, token) {
    this.url = baseUrl + '/api/monitor.php';
    this.token = token;
  }

  async call(action, body, timeoutMs = 30000) {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), timeoutMs);
    try {
      const r = await fetch(`${this.url}?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Verifia-Token': this.token },
        body: JSON.stringify(body),
        signal: ctrl.signal,
      });
      const text = await r.text();
      let data;
      try { data = JSON.parse(text); } catch (e) { throw new Error(`Réponse invalide de l'API (${r.status}) : ${text.slice(0, 200)}`); }
      if (!r.ok) throw new Error(`API ${action} : ${data.error || r.status}`);
      return data;
    } finally {
      clearTimeout(t);
    }
  }

  poll(body) { return this.call('poll', body, 15000); }
  ingest(sessionId, pageUrl, posts) { return this.call('ingest', { session_id: sessionId, page_url: pageUrl, posts }, 60000); }
  event(level, message, sessionId = null, context = {}) {
    return this.call('event', { level, message, session_id: sessionId, context }).catch(() => {});
  }
  commandDone(id, ok) { return this.call('command_done', { id, ok }).catch(() => {}); }
}

module.exports = { Api };
