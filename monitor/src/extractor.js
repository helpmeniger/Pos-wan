'use strict';
/**
 * Fonctions exécutées DANS la page surveillée (page.evaluate). Elles doivent être autonomes.
 * Elles LISENT uniquement le contenu visible : aucun clic, aucune saisie, aucun accès aux cookies.
 */

/** Détecte un obstacle nécessitant une intervention humaine (jamais contourné automatiquement). */
function detectBlockers() {
  const url = location.href.toLowerCase();
  const text = (document.body?.innerText || '').slice(0, 20000).toLowerCase();
  const visible = (el) => { const r = el.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
  const captchaFrame = [...document.querySelectorAll('iframe')].some((f) => /recaptcha|hcaptcha|challenges\.cloudflare\.com|arkoselabs|funcaptcha/i.test(f.src || '') && visible(f));
  if (captchaFrame || document.querySelector('#challenge-form, #cf-challenge-running, .g-recaptcha, .h-captcha, [data-sitekey]') || /\/checkpoint\/|captcha/.test(url)
      || /je ne suis pas un robot|i'm not a robot|vérifiez que vous êtes (un )?humain|verify you are human|confirm you're human/.test(text)) {
    return { state: 'captcha', message: 'CAPTCHA ou contrôle de sécurité détecté : résolvez-le manuellement dans la fenêtre. VERIFIA ne le contourne pas.' };
  }
  if (/temporairement bloqué|temporarily blocked|too many requests|rate limit|vous ne pouvez pas utiliser cette fonctionnalité|automated behavior|comportement automatisé/.test(text)) {
    return { state: 'blocked', message: 'Le site limite ou bloque la navigation automatisée. Session suspendue ; utilisez l’analyse manuelle.' };
  }
  const pwd = [...document.querySelectorAll('input[type="password"]')].some(visible);
  if (/\/login|\/signin|\/sign_in|\/accounts\/login|\/i\/flow\/login/.test(url) || (pwd && !document.querySelector('[role="feed"], article, [role="article"]'))) {
    return { state: 'login_required', message: 'Connexion requise : connectez-vous manuellement dans la fenêtre de cette session. L’analyse reprendra automatiquement.' };
  }
  return { state: 'ok', message: '' };
}

/**
 * Repère les publications visibles dans la fenêtre et en extrait texte, lien, auteur, date affichée et images.
 * Chaque publication reçoit un marqueur data-verifia-key (utilisé pour la capture d'écran par zone).
 */
function extractVisiblePosts(opts) {
  const { adapter, maxText } = opts;
  const vh = window.innerHeight, vw = window.innerWidth;
  const isVisible = (el) => { const r = el.getBoundingClientRect(); return r.bottom > 40 && r.top < vh - 40 && r.width > 120 && r.height > 40; };
  const clean = (s) => (s || '').replace(/ /g, ' ').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
  const abs = (h) => { try { return new URL(h, location.href).href; } catch (e) { return null; } };
  const hash = (s) => { let h = 2166136261; for (let i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = Math.imul(h, 16777619); } return (h >>> 0).toString(16); };

  let candidates = [];
  if (adapter === 'facebook') {
    candidates = [...document.querySelectorAll('[role="article"]')].filter((el) => !el.parentElement?.closest('[role="article"]'));
    if (!candidates.length) candidates = [...document.querySelectorAll('[role="feed"] > div')];
  } else if (adapter === 'x') {
    candidates = [...document.querySelectorAll('article[data-testid="tweet"], article')];
  } else {
    candidates = [...document.querySelectorAll('article, [role="article"]')].filter((el) => !el.parentElement?.closest('article, [role="article"]'));
    if (!candidates.length) {
      candidates = [...document.querySelectorAll('[class*="post"], [class*="entry"], [class*="story"], [class*="news-item"], [class*="article"]')]
        .filter((el) => (el.innerText || '').length > 80 && !el.querySelector('[class*="post"], [class*="entry"], [class*="story"]'));
    }
  }

  const posts = [];
  for (const el of candidates) {
    if (!isVisible(el)) continue;
    let textEl = null;
    if (adapter === 'facebook') textEl = el.querySelector('[data-ad-preview="message"], [data-ad-comet-preview="message"], [data-ad-rendering-role="story_message"]');
    if (adapter === 'x') textEl = el.querySelector('[data-testid="tweetText"]');
    let text = clean((textEl || el).innerText).slice(0, maxText);
    if (text.length < 15 && !el.querySelector('img')) continue;

    // Lien permanent
    let permalink = null;
    const links = [...el.querySelectorAll('a[href]')];
    const patterns = adapter === 'facebook' ? /\/(posts|videos|photos?|permalink\.php|story\.php|reel|watch)\b|\/groups\/[^/]+\/posts\// : adapter === 'x' ? /\/status\/\d+/ : null;
    if (patterns) {
      const a = links.find((l) => patterns.test(l.getAttribute('href') || ''));
      if (a) permalink = abs(a.getAttribute('href'));
    } else {
      const a = el.querySelector('h1 a[href], h2 a[href], h3 a[href], a[rel="bookmark"]') || links.find((l) => { const u = abs(l.getAttribute('href')); return u && u.startsWith(location.origin) && u !== location.href && !/#/.test(l.getAttribute('href')); });
      if (a) permalink = abs(a.getAttribute('href'));
    }

    // Date affichée et auteur
    const timeEl = el.querySelector('time');
    let published = timeEl ? (timeEl.getAttribute('datetime') || clean(timeEl.innerText)) : null;
    if (!published && adapter === 'facebook' && permalink) {
      const a = links.find((l) => abs(l.getAttribute('href')) === permalink);
      published = a ? clean(a.getAttribute('aria-label') || a.innerText).slice(0, 120) || null : null;
    }
    const authorEl = adapter === 'x' ? el.querySelector('[data-testid="User-Name"]')
      : adapter === 'facebook' ? el.querySelector('h2, h3, h4, strong')
      : el.querySelector('[rel="author"], [itemprop="author"], [class*="author"], [class*="byline"]');
    const author = authorEl ? clean(authorEl.innerText).split('\n')[0].slice(0, 255) : null;

    const outLinks = [...new Set(links.map((l) => abs(l.getAttribute('href'))).filter((u) => u && /^https?:/.test(u) && !u.startsWith(location.origin)))].slice(0, 20);

    const key = permalink || 'h' + hash(text.slice(0, 500) + '|' + (author || ''));
    el.setAttribute('data-verifia-key', key);

    // Images de contenu (on ignore avatars, icônes et émojis)
    const images = [];
    let n = 0;
    for (const img of el.querySelectorAll('img')) {
      const r = img.getBoundingClientRect();
      if (img.naturalWidth < 200 || r.width < 120 || r.height < 90) continue;
      if (r.bottom < 0 || r.top > vh || r.right < 0 || r.left > vw) continue;
      const id = key + '#img' + n++;
      img.setAttribute('data-verifia-img', id);
      images.push({ id, src: img.currentSrc || img.src || null, alt: (img.alt || '').slice(0, 500) });
      if (images.length >= 2) break;
    }
    const sponsored = /\b(sponsoris[ée]|sponsored|publicité)\b/i.test((el.innerText || '').slice(0, 400));
    posts.push({ key, permalink, text, author, published_label: published, links: outLinks, images, sponsored });
  }
  return posts;
}

/** Rectangle visible d'un élément marqué (pour une capture par zone sans faire défiler la page). */
function visibleRect(selector) {
  const el = document.querySelector(selector);
  if (!el) return null;
  const r = el.getBoundingClientRect();
  const x = Math.max(0, r.left), y = Math.max(0, r.top);
  const w = Math.min(window.innerWidth, r.right) - x, h = Math.min(window.innerHeight, r.bottom) - y;
  return w > 20 && h > 20 ? { x, y, width: w, height: h } : null;
}

/**
 * Rend une image entièrement visible pour la capturer (sinon l'empreinte perceptuelle serait faussée
 * par un recadrage). Retourne la position de défilement précédente pour la restaurer ensuite.
 */
function revealElement(selector) {
  const el = document.querySelector(selector);
  if (!el) return null;
  const r = el.getBoundingClientRect();
  const prevY = window.scrollY;
  if (r.top >= 0 && r.bottom <= window.innerHeight) return { prevY, moved: false };
  el.scrollIntoView({ block: 'center', behavior: 'instant' });
  return { prevY, moved: true };
}

/** Mesures utilisées par le défilement contrôlé. */
function scrollMetrics() {
  return { y: window.scrollY, height: document.documentElement.scrollHeight, viewport: window.innerHeight, ready: document.readyState };
}

/** Attend que le DOM cesse de changer (chargement de nouveaux contenus) : quietMs sans mutation, au plus maxMs. */
function waitForDomQuiet(args) {
  const { quietMs, maxMs } = args;
  return new Promise((resolve) => {
    let timer = null;
    const start = Date.now();
    const done = (reason) => { obs.disconnect(); clearTimeout(timer); clearTimeout(hard); resolve({ reason, waited: Date.now() - start }); };
    const obs = new MutationObserver(() => { clearTimeout(timer); timer = setTimeout(() => done('quiet'), quietMs); });
    obs.observe(document.body, { childList: true, subtree: true });
    timer = setTimeout(() => done('quiet'), quietMs);
    const hard = setTimeout(() => done('timeout'), maxMs);
  });
}

module.exports = { detectBlockers, extractVisiblePosts, visibleRect, revealElement, scrollMetrics, waitForDomQuiet };
