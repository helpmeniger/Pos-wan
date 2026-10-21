/*
 * MARWA VOICE AI — cœur sans dépendance (navigateur et Node pour les tests).
 * - Lecture en français : nombres en lettres, montants, quantités, heures.
 * - File d'annonces : priorités, doublons, regroupement, expiration, interruption, silence.
 * Aucun accès au réseau ni à la base : les données lues viennent toujours de POS Marwa.
 */
(function (root) {
  'use strict';

  // ------------------------------------------------------------------ nombres en lettres
  const U = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
    'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];
  const T = { 2: 'vingt', 3: 'trente', 4: 'quarante', 5: 'cinquante', 6: 'soixante' };

  function below100(n) {
    if (n < 20) return U[n];
    const t = Math.floor(n / 10), u = n % 10;
    if (t === 7 || t === 9) { // soixante-dix…, quatre-vingt-dix…
      const base = t === 7 ? 'soixante' : 'quatre-vingt';
      return base + (t === 7 && u === 1 ? ' et ' : '-') + U[10 + u];
    }
    if (t === 8) return u === 0 ? 'quatre-vingts' : 'quatre-vingt-' + U[u];
    if (u === 0) return T[t];
    return T[t] + (u === 1 ? ' et un' : '-' + U[u]);
  }
  function below1000(n) {
    const c = Math.floor(n / 100), r = n % 100;
    let s = '';
    if (c === 1) s = 'cent';
    else if (c > 1) s = U[c] + (r === 0 ? ' cents' : ' cent');
    if (r) s += (s ? ' ' : '') + below100(r);
    return s || 'zéro';
  }
  /** Entier en lettres (jusqu'à 999 milliards) ; « vingt et un », « quatre-vingts », « deux cents ». */
  function words(n) {
    n = Math.round(Number(n) || 0);
    if (n < 0) return 'moins ' + words(-n);
    if (n === 0) return 'zéro';
    const parts = [];
    const scales = [[1e9, 'milliard'], [1e6, 'million'], [1e3, 'mille']];
    for (const [v, name] of scales) {
      const q = Math.floor(n / v);
      if (!q) continue;
      n %= v;
      if (name === 'mille') parts.push(q === 1 ? 'mille' : below1000(q).replace(/ cents$/, ' cent').replace(/vingts$/, 'vingt') + ' mille');
      else parts.push(below1000(q) + ' ' + name + (q > 1 ? 's' : ''));
    }
    if (n) parts.push(below1000(n));
    return parts.join(' ');
  }
  /** Nombre décimal : « deux virgule cinq ». */
  function decimal(x, maxDec = 3) {
    x = Number(x) || 0;
    const neg = x < 0; x = Math.abs(x);
    const s = x.toFixed(maxDec).replace(/0+$/, '').replace(/\.$/, '');
    const [i, d] = s.split('.');
    let out = words(Number(i));
    if (d) out += ' virgule ' + (d.startsWith('0') ? d.split('').map((c) => U[Number(c)]).join(' ') : words(Number(d)));
    return (neg ? 'moins ' : '') + out;
  }

  // Noms de devise lus à voix haute (code ISO des paramètres de POS Marwa)
  const CURRENCIES = { XOF: ['franc CFA', 'francs CFA'], XAF: ['franc CFA', 'francs CFA'], EUR: ['euro', 'euros'], USD: ['dollar', 'dollars'], NGN: ['naira', 'nairas'], GHS: ['cedi', 'cedis'], MAD: ['dirham', 'dirhams'], GNF: ['franc guinéen', 'francs guinéens'] };
  /** Montant : « douze mille cinq cents francs CFA ». */
  function amount(x, code = 'XOF', decimals = 0, name = '') {
    x = Number(x) || 0;
    const [one, many] = CURRENCIES[code] || [name || code, name || code];
    const neg = x < 0; x = Math.abs(x);
    const ent = Math.floor(x + 1e-9);
    const cents = decimals > 0 ? Math.round((x - ent) * Math.pow(10, decimals)) : 0;
    let s = words(ent) + ' ' + (ent > 1 ? many : one);
    if (cents) s += ' ' + words(cents);
    return (neg ? 'moins ' : '') + s;
  }
  /** Quantité : entier en lettres, sinon décimal ; unité accordée. */
  function quantity(q, unit) {
    q = Number(q) || 0;
    const s = Number.isInteger(q) ? words(q) : decimal(q);
    if (!unit) return s;
    return s + ' ' + (Math.abs(q) >= 2 && !/[sx]$/.test(unit) ? unit + 's' : unit);
  }
  /** Heure « 14:05 » → « quatorze heures cinq ». */
  function time(hhmm) {
    const m = /^(\d{1,2})[:h](\d{2})$/.exec(String(hhmm).trim());
    if (!m) return String(hhmm);
    const h = Number(m[1]), mi = Number(m[2]);
    return words(h) + (h > 1 ? ' heures' : ' heure') + (mi ? ' ' + words(mi) : '');
  }
  /**
   * Texte prêt à être lu : nombres écrits en chiffres (« 12 500 », « 3,5 ») convertis en lettres,
   * abréviations courantes développées. Les références (V-2026-00012) ne sont pas modifiées.
   */
  function normalize(text) {
    let s = String(text == null ? '' : text);
    s = s.replace(/[\u0000-\u0008\u000b-\u001f\u007f]/g, ' ');
    s = s.replace(/\bFCFA\b|\bF CFA\b/g, 'francs CFA');
    s = s.replace(/\bN°\s*/g, 'numéro ');
    s = s.replace(/(\d{1,2})[:h](\d{2})\b/g, (m0, h, mi) => (Number(h) < 24 && Number(mi) < 60 ? time(h + ':' + mi) : m0));
    // nombres isolés (pas à l'intérieur d'une référence comme V-2026-00012 ou ABC123)
    s = s.replace(/(^|[\s(«"'])(-?\d{1,3}(?:[   ]\d{3})+|-?\d+)(?:,(\d+))?(?=$|[\s.,;:!?)»"'%])/g, (m0, pre, int, dec) => {
      const n = Number(int.replace(/[   ]/g, ''));
      if (!Number.isFinite(n) || Math.abs(n) > 999999999999) return m0;
      return pre + (dec ? decimal(Number(n + '.' + dec), dec.length) : words(n));
    });
    s = s.replace(/%/g, ' pour cent');
    return s.replace(/\s+/g, ' ').trim();
  }

  // ------------------------------------------------------------------ niveaux
  const LEVELS = ['info', 'notification', 'avertissement', 'erreur', 'critique'];
  // durée de validité d'une annonce en attente (s) : une information périmée n'est plus lue
  const TTL = [8, 20, 60, 120, 600];

  // ------------------------------------------------------------------ silence
  /** Heure courante dans la plage de silence « 22:00 » → « 06:00 » (plage pouvant passer minuit). */
  function inQuiet(start, end, now = new Date()) {
    const p = (x) => { const m = /^(\d{1,2}):(\d{2})$/.exec(String(x || '')); return m ? Number(m[1]) * 60 + Number(m[2]) : null; };
    const a = p(start), b = p(end);
    if (a === null || b === null || a === b) return false;
    const t = now.getHours() * 60 + now.getMinutes();
    return a < b ? t >= a && t < b : t >= a || t < b;
  }

  // ------------------------------------------------------------------ file d'annonces
  /**
   * File centrale : un message à la fois, priorité d'abord, puis ordre d'arrivée.
   * opts : { dedupMs, groupAfter, maxLen, now() }
   * push(item) item = { event, level 0-4, text, key?, group?, groupText?(n), t? } → { accepted, reason }
   * next() → prochain message à lire (null si vide) ; les messages périmés sont écartés.
   * interrupts(item, current) → un nouveau message doit-il couper la lecture en cours ?
   */
  function Queue(opts = {}) {
    const o = Object.assign({ dedupMs: 10000, groupAfter: 3, maxLen: 30, now: () => Date.now() }, opts);
    const items = [];
    const recent = new Map(); // clé → date de dernière acceptation
    const dropped = []; // { item, reason } : pour le journal
    let seq = 0;
    function drop(item, reason) { dropped.push({ item, reason }); if (dropped.length > 200) dropped.shift(); }
    return {
      items,
      push(item) {
        const now = o.now();
        item = Object.assign({ level: 1, key: null, group: null }, item, { t: item.t || now, seq: ++seq });
        item.level = Math.max(0, Math.min(4, Number(item.level) || 0));
        const key = item.key || item.event + '|' + item.text;
        const last = recent.get(key);
        if (last && now - last < o.dedupMs) { drop(item, 'doublon'); return { accepted: false, reason: 'doublon' }; }
        recent.set(key, now);
        if (recent.size > 500) recent.delete(recent.keys().next().value);
        // Regroupement : au-delà de groupAfter messages du même groupe en attente, un seul résumé
        if (item.group) {
          const sum = items.find((x) => x.group === item.group && x.grouped);
          if (sum && typeof item.groupText === 'function') { // résumé déjà en attente : il est complété
            sum.count += 1; sum.text = item.groupText(sum.count); sum.level = Math.max(sum.level, item.level); sum.t = now;
            drop(item, 'groupe');
            return { accepted: true, reason: 'groupe', count: sum.count };
          }
          const same = items.filter((x) => x.group === item.group);
          if (same.length + 1 > o.groupAfter && typeof item.groupText === 'function') {
            const n = same.reduce((a, x) => a + (x.count || 1), 0) + 1;
            same.forEach((x) => { items.splice(items.indexOf(x), 1); drop(x, 'groupe'); });
            const lvl = Math.max(item.level, ...same.map((x) => x.level));
            items.push(Object.assign({}, item, { level: lvl, text: item.groupText(n), count: n, grouped: true }));
            return { accepted: true, reason: 'groupe', count: n };
          }
        }
        items.push(item);
        // File pleine : on écarte d'abord les messages les moins importants et les plus anciens
        while (items.length > o.maxLen) {
          let w = 0;
          for (let i = 1; i < items.length; i++) if (items[i].level < items[w].level || (items[i].level === items[w].level && items[i].seq < items[w].seq)) w = i;
          drop(items.splice(w, 1)[0], 'file_pleine');
        }
        return { accepted: true, reason: 'ok' };
      },
      next() {
        const now = o.now();
        for (let i = items.length - 1; i >= 0; i--) {
          if (now - items[i].t > TTL[items[i].level] * 1000) drop(items.splice(i, 1)[0], 'expire');
        }
        if (!items.length) return null;
        let b = 0;
        for (let i = 1; i < items.length; i++) if (items[i].level > items[b].level || (items[i].level === items[b].level && items[i].seq < items[b].seq)) b = i;
        return items.splice(b, 1)[0];
      },
      /** Remet en tête un message interrompu (non critique, encore utile). */
      requeue(item) { if (item.level >= 1) { item.seq = 0; item.t = o.now(); items.push(item); } else drop(item, 'interrompu'); },
      interrupts(item, current) { return !!current && item.level === 4 && current.level < 4; },
      clear(reason = 'efface') { items.splice(0).forEach((x) => drop(x, reason)); },
      takeDropped() { return dropped.splice(0); },
      get length() { return items.length; },
    };
  }

  // ------------------------------------------------------------------ textes des annonces
  /**
   * Phrases par événement : construites uniquement à partir des données transmises par
   * POS Marwa (montants, quantités, messages du serveur). Jamais de chiffre inventé.
   * cur = { code, decimals } ; renvoie null si l'événement ne doit pas être lu.
   */
  const PHRASES = {
    'pos.sale.saved': (d, cur) => d.duplicate
      ? 'Cette vente était déjà enregistrée. Elle n\'a pas été enregistrée une deuxième fois.'
      : 'Vente enregistrée. Total : ' + amount(d.total, cur.code, cur.decimals, cur.name) + '.'
        + (Number(d.change) > 0 ? ' Monnaie à rendre : ' + amount(d.change, cur.code, cur.decimals, cur.name) + '.' : ''),
    'pos.sale.refused': (d) => 'Attention, la vente n\'a pas été enregistrée. ' + (d.error ? normalize(d.error) : 'Vérifiez les informations avant de continuer.'),
    'pos.sale.unknown': () => 'La connexion au serveur a été interrompue. La vente n\'est pas confirmée. Validez à nouveau : elle ne sera pas enregistrée deux fois.',
    'pos.auth.required': () => 'Cette opération demande l\'autorisation d\'un responsable.',
    'pos.print.failed': () => 'La vente est enregistrée, mais l\'impression du ticket a échoué. Vous pouvez réimprimer le ticket.',
    'pos.print.sent': () => 'Ticket envoyé à l\'imprimante.',
    'pos.stock.insufficient': (d) => 'Attention, stock insuffisant pour ' + normalize(d.name) + '. Quantité disponible : ' + quantity(d.stock) + '.',
    'pos.product.notfound': () => 'Article introuvable. Vérifiez le code.',
    'pos.product.added': (d, cur) => normalize(d.name) + (Number(d.qty) > 1 ? ', ' + quantity(d.qty) : '') + '. Total du panier : ' + amount(d.total, cur.code, cur.decimals, cur.name) + '.',
    'pos.cart.held': () => 'Vente mise en attente.',
    'pos.register.closed': () => 'La caisse est fermée. Ouvrez la caisse avant de vendre.',
    'pos.payment.insufficient': () => 'Le montant reçu est insuffisant. Complétez le paiement.',
    'pos.payment.error': (d) => 'Le paiement n\'a pas été accepté. ' + (d.error ? normalize(d.error) : ''),
    'page.error': (d) => normalize(d.message),
    'page.warning': (d) => normalize(d.message),
    'voix.test': (d) => normalize(d.text || 'Test de la voix de POS Marwa.'),
  };
  function phrase(event, data, cur) {
    const f = PHRASES[event];
    if (!f) return null;
    const s = f(data || {}, cur || { code: 'XOF', decimals: 0 });
    return s ? s.replace(/\s+/g, ' ').trim() : null;
  }

  const api = { words, decimal, amount, quantity, time, normalize, inQuiet, Queue, LEVELS, TTL, PHRASES, phrase };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.PMVoiceCore = api;
})(typeof window !== 'undefined' ? window : this);
