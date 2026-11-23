/* Simulateur Windows 11 HELP-ME : noyau (icônes, fichiers, fenêtres, bureau, barre des tâches, menu Démarrer). */
"use strict";
const SIM = window.SIM = { apps: {}, wins: [], z: 10, bus: [] };
const $ = (tag, cls, parent, html) => { const e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; if (parent) parent.appendChild(e); return e; };
const esc = s => String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
SIM.$ = $; SIM.esc = esc;

// ------------------------------------------------------------------ icônes (dessinées en SVG)
const IC = SIM.IC = {
  folder: `<svg viewBox="0 0 48 48"><path d="M4 12a4 4 0 0 1 4-4h11l4 4h17a4 4 0 0 1 4 4v20a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#e8a317"/><path d="M4 18h40v18a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#ffc83d"/></svg>`,
  pc: `<svg viewBox="0 0 48 48"><rect x="4" y="7" width="40" height="27" rx="3" fill="#2b88d8"/><rect x="7" y="10" width="34" height="21" rx="1" fill="#9fd8ff"/><path d="M18 38h12l2 4H16z" fill="#7a8a99"/></svg>`,
  bin: `<svg viewBox="0 0 48 48"><path d="M12 14h24l-2.4 27a3 3 0 0 1-3 2.6H17.4a3 3 0 0 1-3-2.6z" fill="#c9d6e3" stroke="#8aa0b6" stroke-width="2"/><rect x="9" y="9" width="30" height="5" rx="2" fill="#8aa0b6"/><path d="M20 19v19M28 19v19" stroke="#8aa0b6" stroke-width="2"/></svg>`,
  binfull: `<svg viewBox="0 0 48 48"><path d="M12 14h24l-2.4 27a3 3 0 0 1-3 2.6H17.4a3 3 0 0 1-3-2.6z" fill="#c9d6e3" stroke="#8aa0b6" stroke-width="2"/><rect x="9" y="9" width="30" height="5" rx="2" fill="#8aa0b6"/><path d="M15 9l6-5 8 3 7-2 1 4z" fill="#f4f4f4" stroke="#aab" /></svg>`,
  word: `<svg viewBox="0 0 48 48"><rect x="14" y="6" width="30" height="36" rx="3" fill="#41a5ee"/><rect x="14" y="16" width="30" height="10" fill="#2b7cd3"/><rect x="14" y="26" width="30" height="16" rx="0" fill="#185abd"/><rect x="4" y="13" width="22" height="22" rx="3" fill="#185abd"/><path d="M8 18l2.5 12h2.2l2.3-8 2.3 8h2.2L22 18h-2.4l-1.4 8.5L16 18h-2l-2.2 8.5L10.4 18z" fill="#fff"/></svg>`,
  excel: `<svg viewBox="0 0 48 48"><rect x="14" y="6" width="30" height="36" rx="3" fill="#33c481"/><rect x="29" y="6" width="15" height="12" fill="#21a366"/><rect x="14" y="24" width="30" height="18" fill="#107c41"/><rect x="4" y="13" width="22" height="22" rx="3" fill="#107c41"/><path d="M9 18h3l3 5 3-5h3l-4.5 6L21 30h-3l-3-5-3 5H9l4.5-6z" fill="#fff"/></svg>`,
  ppt: `<svg viewBox="0 0 48 48"><circle cx="29" cy="24" r="17" fill="#ed6c47"/><path d="M29 7a17 17 0 0 1 17 17H29z" fill="#ff8f6b"/><rect x="4" y="13" width="22" height="22" rx="3" fill="#c43e1c"/><path d="M11 18h5.5a4 4 0 0 1 0 8H14v4h-3zm3 2.6v2.8h2.2a1.4 1.4 0 0 0 0-2.8z" fill="#fff"/></svg>`,
  notepad: `<svg viewBox="0 0 48 48"><rect x="9" y="6" width="30" height="38" rx="3" fill="#fff" stroke="#5a8ed6" stroke-width="2"/><rect x="9" y="6" width="30" height="8" rx="3" fill="#5a8ed6"/><path d="M15 21h18M15 27h18M15 33h12" stroke="#7a8ba0" stroke-width="2.2"/></svg>`,
  txt: `<svg viewBox="0 0 48 48"><path d="M10 4h20l10 10v30H10z" fill="#fff" stroke="#9aa6b8" stroke-width="2"/><path d="M30 4v10h10" fill="#e6ebf2" stroke="#9aa6b8" stroke-width="2"/><path d="M16 22h18M16 28h18M16 34h12" stroke="#9aa6b8" stroke-width="2"/></svg>`,
  docx: `<svg viewBox="0 0 48 48"><path d="M12 4h20l10 10v30H12z" fill="#fff" stroke="#9aa6b8" stroke-width="2"/><rect x="4" y="20" width="20" height="18" rx="3" fill="#185abd"/><path d="M7.5 24l2 9h1.8l1.9-6 1.9 6h1.8l2-9h-2l-1.1 6-1.8-6h-1.6l-1.8 6-1.1-6z" fill="#fff"/></svg>`,
  xlsx: `<svg viewBox="0 0 48 48"><path d="M12 4h20l10 10v30H12z" fill="#fff" stroke="#9aa6b8" stroke-width="2"/><rect x="4" y="20" width="20" height="18" rx="3" fill="#107c41"/><path d="M8 24h2.5l2.5 4 2.5-4H18l-3.7 4.5L18 33h-2.5L13 29l-2.5 4H8l3.7-4.5z" fill="#fff"/></svg>`,
  img: `<svg viewBox="0 0 48 48"><rect x="5" y="8" width="38" height="32" rx="4" fill="#3aa0f5"/><circle cx="16" cy="18" r="4" fill="#fff59d"/><path d="M5 34l12-12 9 9 6-6 11 11v1a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3z" fill="#1e7a3c"/></svg>`,
  calc: `<svg viewBox="0 0 48 48"><rect x="9" y="4" width="30" height="40" rx="4" fill="#4a5568"/><rect x="13" y="8" width="22" height="9" rx="2" fill="#c7e6ff"/>${[0, 1, 2].map(r => [0, 1, 2].map(c => `<rect x="${13 + c * 8}" y="${21 + r * 7.5}" width="6" height="5.5" rx="1.2" fill="${c === 2 && r === 2 ? "#ff8c00" : "#e2e8f0"}"/>`).join("")).join("")}</svg>`,
  browser: `<svg viewBox="0 0 48 48"><circle cx="24" cy="24" r="19" fill="#1fa1e5"/><path d="M5 24h38M24 5c6 6 6 32 0 38M24 5c-6 6-6 32 0 38" stroke="#fff" stroke-width="2.4" fill="none"/><path d="M9 14h30M9 34h30" stroke="#fff" stroke-width="2" fill="none" opacity=".7"/></svg>`,
  settings: `<svg viewBox="0 0 48 48"><g transform="translate(24 24)"><path d="M-4-20h8l1.4 5.2a14 14 0 0 1 4 2.3l5.2-1.5 4 7-3.8 3.7a14 14 0 0 1 0 4.6l3.8 3.7-4 7-5.2-1.5a14 14 0 0 1-4 2.3L4 20h-8l-1.4-5.2a14 14 0 0 1-4-2.3l-5.2 1.5-4-7 3.8-3.7a14 14 0 0 1 0-4.6L-18.6-5l4-7 5.2 1.5a14 14 0 0 1 4-2.3z" fill="#6b7a8c"/><circle r="6" fill="#e6ebf2"/></g></svg>`,
  keyboard: `<svg viewBox="0 0 48 48"><rect x="3" y="12" width="42" height="26" rx="4" fill="#4a5568"/>${[0, 1, 2].map(r => [0, 1, 2, 3, 4, 5, 6].map(c => `<rect x="${7 + c * 5.2}" y="${16 + r * 5.5}" width="4" height="4" rx="1" fill="#e2e8f0"/>`).join("")).join("")}<rect x="14" y="32" width="20" height="3.5" rx="1" fill="#e2e8f0"/></svg>`,
  paint: `<svg viewBox="0 0 48 48"><path d="M24 5C12 5 4 13 4 23c0 9 7 16 15 16 3 0 4-2 4-4 0-3-2-4 1-6 3-1 7 1 11-1 5-2 9-6 9-11C44 10 35 5 24 5z" fill="#f3d8b6" stroke="#c49a6c" stroke-width="2"/><circle cx="14" cy="20" r="3.5" fill="#e53935"/><circle cx="21" cy="13" r="3.5" fill="#fdd835"/><circle cx="30" cy="13" r="3.5" fill="#43a047"/><circle cx="36" cy="20" r="3.5" fill="#1e88e5"/></svg>`,
  exo: `<svg viewBox="0 0 48 48"><rect x="7" y="5" width="34" height="40" rx="4" fill="#fff" stroke="#326fbf" stroke-width="2.4"/><rect x="17" y="2" width="14" height="7" rx="2" fill="#326fbf"/><path d="M13 20l3 3 5-6M13 31l3 3 5-6" stroke="#1e9e62" stroke-width="2.6" fill="none" stroke-linecap="round"/><path d="M25 21h10M25 32h10" stroke="#7a8ba0" stroke-width="2.4"/></svg>`,
  docs: `<svg viewBox="0 0 48 48"><path d="M4 12a4 4 0 0 1 4-4h11l4 4h17a4 4 0 0 1 4 4v20a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#e8a317"/><path d="M4 18h40v18a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#ffc83d"/><rect x="18" y="22" width="12" height="14" rx="1" fill="#fff"/><path d="M20.5 26h7M20.5 29h7M20.5 32h5" stroke="#9aa6b8" stroke-width="1.4"/></svg>`,
  dl: `<svg viewBox="0 0 48 48"><path d="M4 12a4 4 0 0 1 4-4h11l4 4h17a4 4 0 0 1 4 4v20a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#e8a317"/><path d="M4 18h40v18a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#ffc83d"/><path d="M24 21v11m-5-5l5 5 5-5" stroke="#1e7a3c" stroke-width="3" fill="none" stroke-linecap="round"/></svg>`,
  pics: `<svg viewBox="0 0 48 48"><path d="M4 12a4 4 0 0 1 4-4h11l4 4h17a4 4 0 0 1 4 4v20a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#e8a317"/><path d="M4 18h40v18a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#ffc83d"/><rect x="16" y="22" width="16" height="12" rx="2" fill="#3aa0f5"/><path d="M16 32l5-5 4 4 3-3 4 4" stroke="#fff" stroke-width="1.6" fill="none"/></svg>`,
  music: `<svg viewBox="0 0 48 48"><path d="M4 12a4 4 0 0 1 4-4h11l4 4h17a4 4 0 0 1 4 4v20a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#e8a317"/><path d="M4 18h40v18a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#ffc83d"/><path d="M22 23l8-2v9" stroke="#e24c8a" stroke-width="2.4" fill="none"/><circle cx="20" cy="32" r="3" fill="#e24c8a"/><circle cx="28" cy="30" r="3" fill="#e24c8a"/></svg>`,
  video: `<svg viewBox="0 0 48 48"><path d="M4 12a4 4 0 0 1 4-4h11l4 4h17a4 4 0 0 1 4 4v20a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#e8a317"/><path d="M4 18h40v18a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z" fill="#ffc83d"/><rect x="16" y="23" width="12" height="10" rx="2" fill="#7a5cd6"/><path d="M28 26l5-3v10l-5-3z" fill="#7a5cd6"/></svg>`,
  usb: `<svg viewBox="0 0 48 48"><rect x="8" y="14" width="32" height="20" rx="3" fill="#7a8a99"/><rect x="40" y="18" width="6" height="12" rx="1" fill="#aab6c2"/><circle cx="16" cy="24" r="2.2" fill="#6cf0a0"/></svg>`,
  disk: `<svg viewBox="0 0 48 48"><rect x="4" y="14" width="40" height="20" rx="3" fill="#9aa6b8"/><rect x="4" y="27" width="40" height="7" rx="2" fill="#7a8a99"/><circle cx="37" cy="30.5" r="1.6" fill="#6cf0a0"/><rect x="10" y="18" width="12" height="4" rx="1" fill="#0078d4"/></svg>`,
  search: `<svg viewBox="0 0 20 20" width="16" height="16"><circle cx="8.5" cy="8.5" r="5.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12.5 12.5l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>`,
  start: `<svg viewBox="0 0 24 24"><rect x="2" y="2" width="9.5" height="9.5" rx="1" fill="#0078d4"/><rect x="12.5" y="2" width="9.5" height="9.5" rx="1" fill="#0078d4"/><rect x="2" y="12.5" width="9.5" height="9.5" rx="1" fill="#0078d4"/><rect x="12.5" y="12.5" width="9.5" height="9.5" rx="1" fill="#0078d4"/></svg>`,
  wifi: `<svg viewBox="0 0 24 20" width="18" height="15"><g fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 7a14 14 0 0 1 20 0"/><path d="M5.5 10.7a9 9 0 0 1 13 0"/><path d="M9 14.3a4 4 0 0 1 6 0"/></g><circle cx="12" cy="17.5" r="1.5" fill="currentColor"/></svg>`,
  vol: `<svg viewBox="0 0 24 20" width="18" height="15"><path d="M3 7h4l5-4v14l-5-4H3z" fill="currentColor"/><path d="M15 6a5 5 0 0 1 0 8M17.5 3.5a9 9 0 0 1 0 13" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/></svg>`,
  bat: `<svg viewBox="0 0 26 14" width="22" height="12"><rect x="1" y="1" width="21" height="12" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.6"/><rect x="3.5" y="3.5" width="14" height="7" rx="1" fill="currentColor"/><rect x="23" y="4.5" width="2" height="5" rx="1" fill="currentColor"/></svg>`,
  power: `<svg viewBox="0 0 20 20" width="16" height="16"><path d="M10 2v7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/><path d="M5.5 5a7 7 0 1 0 9 0" stroke="currentColor" stroke-width="1.8" fill="none" stroke-linecap="round"/></svg>`,
  min: `<svg viewBox="0 0 10 10" width="10" height="10"><path d="M0 5h10" stroke="currentColor"/></svg>`,
  max: `<svg viewBox="0 0 10 10" width="10" height="10"><rect x=".5" y=".5" width="9" height="9" rx="1" fill="none" stroke="currentColor"/></svg>`,
  rest: `<svg viewBox="0 0 10 10" width="10" height="10"><rect x=".5" y="2.5" width="7" height="7" rx="1" fill="none" stroke="currentColor"/><path d="M2.5 2.5v-1a1 1 0 0 1 1-1h5a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1h-1" fill="none" stroke="currentColor"/></svg>`,
  close: `<svg viewBox="0 0 10 10" width="10" height="10"><path d="M0 0l10 10M10 0L0 10" stroke="currentColor"/></svg>`,
  cut: `<svg viewBox="0 0 20 20"><circle cx="5" cy="15" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/><circle cx="15" cy="15" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M7 13L15 2M13 13L5 2" stroke="currentColor" stroke-width="1.5"/></svg>`,
  copy: `<svg viewBox="0 0 20 20"><rect x="6" y="6" width="11" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M4 14V4a2 2 0 0 1 2-2h8" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>`,
  paste: `<svg viewBox="0 0 20 20"><rect x="4" y="4" width="12" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><rect x="7" y="2" width="6" height="4" rx="1" fill="currentColor"/></svg>`,
  ren: `<svg viewBox="0 0 20 20"><rect x="2" y="6" width="16" height="8" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="M12 3v14M10 3h4M10 17h4" stroke="currentColor" stroke-width="1.5"/></svg>`,
  del: `<svg viewBox="0 0 20 20"><path d="M5 6h10l-1 11H6zM3 6h14M8 6V3h4v3" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>`,
  plus: `<svg viewBox="0 0 20 20"><circle cx="10" cy="10" r="8" fill="#0067c0"/><path d="M10 6v8M6 10h8" stroke="#fff" stroke-width="1.8"/></svg>`,
  up: `<svg viewBox="0 0 20 20" width="16" height="16"><path d="M10 16V4M5 9l5-5 5 5" stroke="currentColor" stroke-width="1.6" fill="none"/></svg>`,
  back: `<svg viewBox="0 0 20 20" width="16" height="16"><path d="M16 10H4M9 5l-5 5 5 5" stroke="currentColor" stroke-width="1.6" fill="none"/></svg>`,
  fwd: `<svg viewBox="0 0 20 20" width="16" height="16"><path d="M4 10h12M11 5l5 5-5 5" stroke="currentColor" stroke-width="1.6" fill="none"/></svg>`,
  refresh: `<svg viewBox="0 0 20 20" width="16" height="16"><path d="M16 10a6 6 0 1 1-2-4.5M16 3v4h-4" stroke="currentColor" stroke-width="1.6" fill="none"/></svg>`,
  ok: `<svg viewBox="0 0 28 28"><circle cx="14" cy="14" r="13" fill="#0f7b0f"/><path d="M8 14.5l4 4 8-9" stroke="#fff" stroke-width="2.6" fill="none" stroke-linecap="round"/></svg>`,
  info: `<svg viewBox="0 0 28 28"><circle cx="14" cy="14" r="13" fill="#0067c0"/><path d="M14 12v8" stroke="#fff" stroke-width="2.6" stroke-linecap="round"/><circle cx="14" cy="8" r="1.7" fill="#fff"/></svg>`,
};
SIM.icon = n => IC[n] || IC.txt;

// ------------------------------------------------------------------ stockage local (tolérant aux erreurs)
const store = SIM.store = {
  get(k, d) { try { const v = localStorage.getItem("hmsim." + k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
  set(k, v) { try { localStorage.setItem("hmsim." + k, JSON.stringify(v)); } catch (e) { /* stockage indisponible : on continue en mémoire */ } }
};

// ------------------------------------------------------------------ système de fichiers virtuel
// nœud : { id, name, type: "dir" | "txt" | "docx" | "xlsx" | "img", parent, content, mtime, from (pour la corbeille) }
const FS = SIM.FS = {
  nodes: {}, seq: 1,
  init() {
    const saved = store.get("fs", null);
    if (saved && saved.nodes && saved.nodes.root) { this.nodes = saved.nodes; this.seq = saved.seq; return; }
    this.nodes = { root: { id: "root", name: "Ce PC", type: "dir", parent: null } };
    const mk = (id, name, parent) => { this.nodes[id] = { id, name, type: "dir", parent, mtime: Date.now() }; };
    mk("desktop", "Bureau", "root"); mk("docs", "Documents", "root"); mk("dl", "Téléchargements", "root"); mk("pics", "Images", "root");
    mk("music", "Musique", "root"); mk("videos", "Vidéos", "root"); mk("trash", "Corbeille", null);
    this.create("docs", "Lisez-moi.txt", "txt", "Bienvenue dans le simulateur Windows de HELP-ME !\r\n\r\nIci, vous pouvez tout essayer sans risque : ouvrir des fenêtres, créer des dossiers, écrire, calculer, dessiner.\r\nOuvrez l’application « Exercices » sur le bureau pour suivre les missions.");
    this.create("docs", "Cours", "dir");
    this.create("pics", "Fleuve Niger.png", "img", "niger");
    this.create("dl", "Fiche d’inscription.docx", "docx", "<h1>Fiche d’inscription</h1><p>Nom : ……………………</p><p>Prénom : ……………………</p><p>Formation : Informatique de bureau</p>");
    this.save();
  },
  save() { store.set("fs", { nodes: this.nodes, seq: this.seq }); },
  get(id) { return this.nodes[id]; },
  kids(id) { return Object.values(this.nodes).filter(n => n.parent === id).sort((a, b) => (a.type === "dir") === (b.type === "dir") ? a.name.localeCompare(b.name, "fr") : a.type === "dir" ? -1 : 1); },
  uniq(parent, name) {
    const names = new Set(this.kids(parent).map(n => n.name.toLowerCase()));
    if (!names.has(name.toLowerCase())) return name;
    const dot = name.lastIndexOf("."), base = dot > 0 ? name.slice(0, dot) : name, ext = dot > 0 ? name.slice(dot) : "";
    for (let i = 2; ; i++) { const n = `${base} (${i})${ext}`; if (!names.has(n.toLowerCase())) return n; }
  },
  create(parent, name, type, content) {
    const id = "n" + (this.seq++);
    this.nodes[id] = { id, name: this.uniq(parent, name), type, parent, content: content || "", mtime: Date.now() };
    this.save(); SIM.changed(parent); return this.nodes[id];
  },
  rename(id, name) {
    const n = this.nodes[id]; name = name.trim().replace(/[\\/:*?"<>|]/g, "");
    if (!name || name === n.name) return false;
    n.name = this.uniq(n.parent, name); n.mtime = Date.now(); this.save(); SIM.changed(n.parent); SIM.emit("rename", n); return true;
  },
  move(id, parent) {
    const n = this.nodes[id]; if (!n || n.parent === parent || this.isInside(parent, id)) return;
    const old = n.parent; n.parent = parent; n.name = this.uniq(parent, n.name); this.save(); SIM.changed(old); SIM.changed(parent); SIM.emit("move", n);
  },
  copy(id, parent) {
    const n = this.nodes[id]; const c = this.create(parent, n.name, n.type, n.content);
    if (n.type === "dir") this.kids(id).forEach(k => this.copy(k.id, c.id));
    SIM.emit("copy", c); return c;
  },
  remove(id) {                                   // vers la corbeille
    const n = this.nodes[id]; if (!n || ["root", "desktop", "docs", "dl", "pics", "music", "videos", "trash"].includes(id)) return;
    const old = n.parent; n.from = old; n.parent = "trash"; this.save(); SIM.changed(old); SIM.changed("trash"); SIM.emit("delete", n);
  },
  restore(id) { const n = this.nodes[id]; if (!n) return; const to = this.nodes[n.from] ? n.from : "desktop"; n.parent = to; n.name = this.uniq(to, n.name); delete n.from; this.save(); SIM.changed("trash"); SIM.changed(to); },
  purge(id) { this.kids(id).forEach(k => this.purge(k.id)); const p = this.nodes[id].parent; delete this.nodes[id]; this.save(); SIM.changed(p); },
  emptyTrash() { const k = this.kids("trash"); k.forEach(n => this.purge(n.id)); SIM.changed("trash"); SIM.emit("emptytrash", k.length); },
  isInside(id, anc) { for (let n = this.nodes[id]; n; n = this.nodes[n.parent]) if (n.id === anc) return true; return false; },
  path(id) { const p = []; for (let n = this.nodes[id]; n; n = this.nodes[n.parent]) p.unshift(n); return p; },
  iconOf(n) {
    if (n.type === "dir") return { desktop: "folder", docs: "docs", dl: "dl", pics: "pics", music: "music", videos: "video", trash: FS.kids("trash").length ? "binfull" : "bin" }[n.id] || "folder";
    return { txt: "txt", docx: "docx", xlsx: "xlsx", img: "img" }[n.type] || "txt";
  }
};

// ------------------------------------------------------------------ événements (pour les exercices et l'actualisation des vues)
SIM.listeners = {};
SIM.on = (ev, fn) => { (SIM.listeners[ev] = SIM.listeners[ev] || []).push(fn); };
SIM.emit = (ev, data) => { (SIM.listeners[ev] || []).forEach(f => { try { f(data); } catch (e) { console.error(e); } }); (SIM.listeners["*"] || []).forEach(f => f(ev, data)); };
SIM.changed = dir => SIM.emit("fs", dir);

// ------------------------------------------------------------------ notifications
SIM.toast = (title, text, icon) => {
  const box = document.getElementById("toasts"); while (box.children.length >= 3) box.firstChild.remove();
  const t = $("div", "toast", document.getElementById("toasts"), `<div class="ic">${IC[icon || "info"]}</div><div><b>${esc(title)}</b><small>${esc(text || "")}</small></div>`);
  setTimeout(() => { t.style.transition = "opacity .4s, transform .4s"; t.style.opacity = 0; t.style.transform = "translateX(40px)"; setTimeout(() => t.remove(), 420); }, 4200);
};

// ------------------------------------------------------------------ menus contextuels
let ctxEl = null;
SIM.closeMenus = () => { document.querySelectorAll(".ctx").forEach(m => m.remove()); ctxEl = null; };
/** items : [{ t, k (raccourci), ic, fn, dis, sub: [...] }] ou "-" */
SIM.menu = (x, y, items, parent) => {
  if (!parent) SIM.closeMenus();
  const m = $("div", "ctx", document.body);
  items.forEach(it => {
    if (it === "-") { $("hr", "", m); return; }
    const mi = $("div", "mi" + (it.dis ? " dis" : ""), m, `${it.ic ? `<span class="ic">${IC[it.ic] || ""}</span>` : `<span class="ic"></span>`}<span>${esc(it.t)}</span>${it.k ? `<span class="k">${esc(it.k)}</span>` : it.sub ? `<span class="k">›</span>` : ""}`);
    if (it.sub) {
      let sm = null;
      const open = () => { m.querySelectorAll(".mi").forEach(o => o.classList.remove("hl")); mi.classList.add("hl"); if (m._sub) m._sub.remove(); const r = mi.getBoundingClientRect(); sm = m._sub = SIM.menu(r.right - 4, r.top - 4, it.sub, m); };
      mi.addEventListener("pointerenter", open);
      mi.addEventListener("click", e => { e.stopPropagation(); open(); });
    } else {
      mi.addEventListener("pointerenter", () => { if (m._sub) { m._sub.remove(); m._sub = null; } m.querySelectorAll(".mi").forEach(o => o.classList.remove("hl")); });
      mi.addEventListener("click", e => { e.stopPropagation(); if (it.dis) return; SIM.closeMenus(); if (parent) parent.remove(); it.fn && it.fn(); });
    }
  });
  const W = innerWidth, H = innerHeight, r = m.getBoundingClientRect();
  if (parent && x + r.width > W - 4) x = parent.getBoundingClientRect().left - r.width + 4;   // sous-menu : à gauche s'il manque de place
  m.style.left = Math.max(2, Math.min(x, W - r.width - 4)) + "px";
  m.style.top = Math.max(2, Math.min(y, H - r.height - 4)) + "px";
  if (!parent) ctxEl = m; else m.addEventListener("pointerdown", e => e.stopPropagation());
  m.addEventListener("pointerdown", e => e.stopPropagation());
  m.addEventListener("contextmenu", e => e.preventDefault());
  return m;
};

/** Appui long (tactile) = clic droit ; double-tap = double-clic. */
SIM.touchy = (el, { open, ctx }) => {
  let timer = null, sx = 0, sy = 0, last = 0, fired = false;
  el.addEventListener("pointerdown", e => {
    fired = false; sx = e.clientX; sy = e.clientY;
    if (e.pointerType !== "mouse") timer = setTimeout(() => { fired = true; ctx && ctx(e.clientX, e.clientY, e); }, 550);
  });
  el.addEventListener("pointermove", e => { if (Math.hypot(e.clientX - sx, e.clientY - sy) > 8) clearTimeout(timer); });
  el.addEventListener("pointerup", e => {
    clearTimeout(timer);
    if (fired) return;
    const now = Date.now();
    if (now - last < 380 && open) { open(e); last = 0; } else last = now;
  });
  el.addEventListener("pointercancel", () => clearTimeout(timer));
  el.addEventListener("contextmenu", e => { e.preventDefault(); if (!fired && ctx) ctx(e.clientX, e.clientY, e); });
};

// ------------------------------------------------------------------ boîtes de dialogue (dans une fenêtre ou sur le bureau)
SIM.dialog = (host, title, html, buttons) => new Promise(res => {
  const back = $("div", "dlgback", host || document.body);
  if (!host) back.style.zIndex = 9700;
  const d = $("div", "dlg", back, `<div class="dh">${esc(title)}</div><div class="db">${html}</div><div class="df"></div>`);
  const f = d.querySelector(".df");
  (buttons || [["OK", true]]).forEach(([t, v, pri]) => { const b = $("button", "btn" + (pri || v === true ? " pri" : ""), f, esc(t)); b.onclick = () => { back.remove(); res({ v, el: d }); }; });
  back.addEventListener("pointerdown", e => e.stopPropagation());
  const inp = d.querySelector("input"); if (inp) { setTimeout(() => { inp.focus(); inp.select && inp.select(); }, 30); inp.addEventListener("keydown", e => { if (e.key === "Enter") { back.remove(); res({ v: true, el: d }); } }); }
});
SIM.prompt = async (host, title, label, value) => {
  const r = await SIM.dialog(host, title, `<div style="margin-bottom:8px">${esc(label)}</div><input class="field" style="width:100%" value="${esc(value || "")}">`, [["OK", true], ["Annuler", false]]);
  return r.v ? r.el.querySelector("input").value : null;
};

// ------------------------------------------------------------------ gestionnaire de fenêtres
const WM = SIM.WM = {
  open(appId, opts) {
    const app = SIM.apps[appId]; if (!app) return null;
    opts = opts || {};
    if (app.single) { const ex = SIM.wins.find(w => w.app === appId); if (ex) { WM.restore(ex); if (app.reopen) app.reopen(ex, opts); return ex; } }
    const W = innerWidth, H = innerHeight - 48;
    const ww = Math.min(app.w || 820, W - 20), hh = Math.min(app.h || 520, H - 20);
    const n = SIM.wins.length;
    const el = $("div", "win anim-open", document.getElementById("wins"));
    const w = { id: "w" + Date.now() + Math.random(), app: appId, el, title: app.name, icon: app.icon };
    el.innerHTML = `<div class="tbar"><span class="ic">${IC[app.icon]}</span><span class="tt"></span><button class="wb mn" title="Réduire">${IC.min}</button><button class="wb mx" title="Agrandir">${IC.max}</button><button class="wb cl" title="Fermer">${IC.close}</button></div><div class="wbody"></div><div class="grip"></div>`;
    w.body = el.querySelector(".wbody");
    w.setTitle = t => { w.title = t; el.querySelector(".tt").textContent = t; SIM.taskbar(); };
    w.setTitle(opts.title || app.name);
    const small = W < 700 || H < 420;
    Object.assign(el.style, { width: ww + "px", height: hh + "px", left: Math.max(4, (W - ww) / 2 + (n % 5) * 26 - 50) + "px", top: Math.max(4, (H - hh) / 2 + (n % 5) * 22 - 40) + "px" });
    SIM.wins.push(w);
    el.querySelector(".mn").onclick = e => { e.stopPropagation(); WM.minimize(w); };
    el.querySelector(".mx").onclick = e => { e.stopPropagation(); WM.toggleMax(w); };
    el.querySelector(".cl").onclick = e => { e.stopPropagation(); WM.close(w); };
    el.addEventListener("pointerdown", () => WM.focus(w), true);
    WM.drag(w); WM.resize(w);
    app.mount(w, opts);
    if (small || app.max) WM.toggleMax(w, true);
    WM.focus(w);
    SIM.emit("open", appId);
    setTimeout(() => el.classList.remove("anim-open"), 250);
    return w;
  },
  focus(w) {
    SIM.wins.forEach(o => o.el.classList.toggle("front", o === w));
    w.el.style.zIndex = ++SIM.z; SIM.active = w; SIM.taskbar();
  },
  close(w) {
    if (w.beforeClose && w.beforeClose() === false) return;
    w.el.style.pointerEvents = "none"; w.el.style.transition = "transform .12s, opacity .12s"; w.el.style.transform = "scale(.95)"; w.el.style.opacity = 0;
    setTimeout(() => w.el.remove(), 130);
    SIM.wins = SIM.wins.filter(o => o !== w);
    if (SIM.active === w) SIM.active = SIM.wins[SIM.wins.length - 1];
    w.onClose && w.onClose();
    SIM.emit("close", w.app); SIM.taskbar();
  },
  minimize(w) { w.el.classList.add("min"); w.minimized = true; if (SIM.active === w) SIM.active = null; SIM.emit("minimize", w.app); SIM.taskbar(); },
  restore(w) { w.el.classList.remove("min"); w.minimized = false; WM.focus(w); },
  toggleMax(w, quiet) {
    const el = w.el;
    if (!w.maxed) { w.prev = { l: el.style.left, t: el.style.top, w: el.style.width, h: el.style.height }; Object.assign(el.style, { left: 0, top: 0, width: "100%", height: `calc(100% - var(--tb))` }); el.classList.add("max"); w.maxed = true; }
    else { Object.assign(el.style, { left: w.prev.l, top: w.prev.t, width: w.prev.w, height: w.prev.h }); el.classList.remove("max"); w.maxed = false; }
    el.querySelector(".mx").innerHTML = w.maxed ? IC.rest : IC.max;
    if (!quiet) SIM.emit(w.maxed ? "maximize" : "unmaximize", w.app);
    w.onResize && w.onResize();
  },
  drag(w) {
    const bar = w.el.querySelector(".tbar"); let sx, sy, ox, oy, moved = false, ghost = null, lastTap = 0;
    bar.addEventListener("pointerdown", e => {
      if (e.target.closest(".wb")) return;
      const now = Date.now(); if (now - lastTap < 350) { WM.toggleMax(w); lastTap = 0; return; } lastTap = now;
      sx = e.clientX; sy = e.clientY; ox = w.el.offsetLeft; oy = w.el.offsetTop; moved = false;
      bar.setPointerCapture(e.pointerId);
      const mv = ev => {
        const dx = ev.clientX - sx, dy = ev.clientY - sy;
        if (!moved && Math.hypot(dx, dy) < 4) return;
        if (!moved && w.maxed) { const r = w.prev; WM.toggleMax(w, true); ox = ev.clientX - parseFloat(r.w) / 2; oy = 0; sx = ev.clientX; sy = ev.clientY; }
        moved = true;
        w.el.style.left = (ox + ev.clientX - sx) + "px";
        w.el.style.top = Math.max(0, Math.min(innerHeight - 80, oy + ev.clientY - sy)) + "px";
        // ancrage (snap) : bords gauche/droit = demi-écran, haut = plein écran
        const zone = ev.clientX < 6 ? "l" : ev.clientX > innerWidth - 6 ? "r" : ev.clientY < 4 ? "t" : null;
        if (zone && !ghost) ghost = $("div", "snapghost", document.getElementById("desk"));
        if (!zone && ghost) { ghost.remove(); ghost = null; }
        if (ghost) Object.assign(ghost.style, zone === "t" ? { left: "4px", top: "4px", width: "calc(100% - 8px)", height: "calc(100% - var(--tb) - 8px)" } : { left: zone === "l" ? "4px" : "50%", top: "4px", width: "calc(50% - 8px)", height: "calc(100% - var(--tb) - 8px)" });
        w.snapZone = zone;
      };
      const up = () => {
        bar.removeEventListener("pointermove", mv); bar.removeEventListener("pointerup", up);
        if (ghost) { ghost.remove(); ghost = null; }
        if (moved && w.snapZone) {
          if (w.snapZone === "t") WM.toggleMax(w);
          else { w.prev = { l: w.el.style.left, t: "60px", w: w.el.style.width, h: w.el.style.height }; Object.assign(w.el.style, { left: w.snapZone === "l" ? "0" : "50%", top: 0, width: "50%", height: "calc(100% - var(--tb))" }); SIM.emit("snap", w.app); }
        } else if (moved) SIM.emit("move-window", w.app);
        w.snapZone = null; w.onResize && w.onResize();
      };
      bar.addEventListener("pointermove", mv); bar.addEventListener("pointerup", up);
    });
  },
  resize(w) {
    const g = w.el.querySelector(".grip");
    g.addEventListener("pointerdown", e => {
      e.stopPropagation(); g.setPointerCapture(e.pointerId);
      const sx = e.clientX, sy = e.clientY, ow = w.el.offsetWidth, oh = w.el.offsetHeight;
      const mv = ev => { w.el.style.width = Math.max(260, ow + ev.clientX - sx) + "px"; w.el.style.height = Math.max(170, oh + ev.clientY - sy) + "px"; w.onResize && w.onResize(); };
      const up = () => { g.removeEventListener("pointermove", mv); g.removeEventListener("pointerup", up); SIM.emit("resize-window", w.app); };
      g.addEventListener("pointermove", mv); g.addEventListener("pointerup", up);
    });
  }
};

// ------------------------------------------------------------------ ouvrir un fichier avec la bonne application
SIM.openNode = n => {
  if (!n) return;
  if (n.type === "dir") { if (n.id === "trash") return WM.open("explorer", { dir: "trash" }); return WM.open("explorer", { dir: n.id }); }
  const app = { txt: "notepad", docx: "word", xlsx: "excel", img: "photos" }[n.type] || "notepad";
  WM.open(app, { file: n.id });
};

// ------------------------------------------------------------------ bureau
const DESK_APPS = [["pc", "explorer", "Ce PC", { dir: "root" }], ["bin", "explorer", "Corbeille", { dir: "trash" }], ["exo", "exercises", "Exercices"],
  ["word", "word", "Word"], ["excel", "excel", "Excel"], ["notepad", "notepad", "Bloc-notes"], ["browser", "browser", "Navigateur"],
  ["calc", "calc", "Calculatrice"], ["paint", "paint", "Paint"], ["keyboard", "keyboard", "Clavier visuel"], ["settings", "settings", "Paramètres"]];
SIM.renderDesk = () => {
  const box = document.getElementById("icons"); box.innerHTML = "";
  const items = DESK_APPS.map(([ic, app, name, o]) => ({ kind: "app", ic: app === "explorer" && o.dir === "trash" ? FS.iconOf(FS.get("trash")) : ic, app, name, o }))
    .concat(FS.kids("desktop").map(n => ({ kind: "file", n, ic: FS.iconOf(n), name: n.name })));
  items.forEach(it => {
    const d = $("div", "dico" + (it.n && SIM.clip && SIM.clip.cut && SIM.clip.id === it.n.id ? " cut" : ""), box, `<div class="ic">${IC[it.ic]}</div><div class="nm">${esc(it.name)}</div>`);
    d.dataset.id = it.n ? it.n.id : it.app;
    d.addEventListener("pointerdown", e => { e.stopPropagation(); SIM.closeMenus(); SIM.closeFly(); box.querySelectorAll(".dico").forEach(o => o.classList.toggle("sel", o === d)); });
    const open = () => { d.classList.remove("sel"); it.kind === "app" ? WM.open(it.app, it.o) : SIM.openNode(it.n); };
    d.addEventListener("dblclick", open);
    SIM.touchy(d, {
      open: e => { if (e.pointerType !== "mouse") open(); },
      ctx: (x, y) => {
        box.querySelectorAll(".dico").forEach(o => o.classList.toggle("sel", o === d));
        if (it.kind === "app") {
          const extra = it.o && it.o.dir === "trash" ? [{ t: "Vider la Corbeille", ic: "del", fn: () => SIM.emptyTrash(), dis: !FS.kids("trash").length }] : [];
          SIM.menu(x, y, [{ t: "Ouvrir", fn: open }, ...extra, "-", { t: "Propriétés", fn: () => SIM.toast(it.name, "Application du simulateur HELP-ME") }]);
        } else SIM.fileMenu(x, y, it.n, null, () => SIM.renameDesk(it.n.id));
      }
    });
    if (it.n && SIM.pendingRename === it.n.id) { SIM.pendingRename = null; setTimeout(() => SIM.renameDesk(it.n.id), 30); }
  });
};
SIM.renameDesk = id => {
  const d = document.querySelector(`#icons .dico[data-id="${id}"]`); if (!d) return;
  const n = FS.get(id), nm = d.querySelector(".nm");
  nm.innerHTML = ""; const inp = $("input", "rename", nm); inp.value = n.name;
  inp.addEventListener("pointerdown", e => e.stopPropagation());
  const dot = n.type !== "dir" ? n.name.lastIndexOf(".") : -1;
  setTimeout(() => { inp.focus(); inp.setSelectionRange(0, dot > 0 ? dot : n.name.length); }, 20);
  let done = false;
  const fin = ok => { if (done) return; done = true; if (ok) FS.rename(id, inp.value); SIM.renderDesk(); };
  inp.addEventListener("keydown", e => { if (e.key === "Enter") fin(true); if (e.key === "Escape") fin(false); e.stopPropagation(); });
  inp.addEventListener("blur", () => fin(true));
};
SIM.emptyTrash = async () => {
  const k = FS.kids("trash").length; if (!k) return;
  const r = await SIM.dialog(null, "Supprimer définitivement", `Voulez-vous vraiment supprimer définitivement ${k > 1 ? `ces ${k} éléments` : "cet élément"} ?`, [["Oui", true], ["Non", false]]);
  if (r.v) FS.emptyTrash();
};
/** Menu contextuel d'un fichier ou dossier (bureau ou Explorateur). */
SIM.fileMenu = (x, y, n, host, rename) => {
  if (n.parent === "trash") return SIM.menu(x, y, [{ t: "Restaurer", fn: () => FS.restore(n.id) }, "-", { t: "Supprimer définitivement", ic: "del", fn: () => FS.purge(n.id) }]);
  const isDir = n.type === "dir";
  SIM.menu(x, y, [{ t: "Ouvrir", fn: () => SIM.openNode(n) },
    ...(isDir ? [] : [{ t: "Ouvrir avec", sub: [{ t: "Bloc-notes", ic: "notepad", fn: () => WM.open("notepad", { file: n.id }) }, { t: n.type === "xlsx" ? "Excel" : "Word", ic: n.type === "xlsx" ? "excel" : "word", fn: () => SIM.openNode(n) }] }]),
    "-", { t: "Couper", k: "Ctrl+X", ic: "cut", fn: () => SIM.setClip(n.id, true) }, { t: "Copier", k: "Ctrl+C", ic: "copy", fn: () => SIM.setClip(n.id, false) },
    ...(isDir && SIM.clip ? [{ t: "Coller dans le dossier", ic: "paste", fn: () => SIM.paste(n.id) }] : []),
    "-", { t: "Renommer", k: "F2", ic: "ren", fn: rename }, { t: "Supprimer", k: "Suppr", ic: "del", fn: () => FS.remove(n.id) }, "-",
    { t: "Propriétés", fn: () => SIM.dialog(host, "Propriétés", `<b>${esc(n.name)}</b><br><br>Type : ${isDir ? "Dossier de fichiers" : { txt: "Document texte", docx: "Document Microsoft Word", xlsx: "Feuille de calcul Excel", img: "Image PNG" }[n.type]}<br>Emplacement : ${esc(FS.path(n.parent).map(p => p.name).join(" › "))}<br>Modifié le : ${new Date(n.mtime || Date.now()).toLocaleString("fr-FR")}${isDir ? `<br>Contenu : ${FS.kids(n.id).length} élément(s)` : ""}`) }]);
};
SIM.setClip = (id, cut) => { SIM.clip = { id, cut }; SIM.emit(cut ? "cut" : "copyclip", id); SIM.renderDesk(); SIM.changed(FS.get(id).parent); };
SIM.paste = dir => {
  const c = SIM.clip; if (!c || !FS.get(c.id)) return;
  if (c.cut) { FS.move(c.id, dir); SIM.clip = null; } else FS.copy(c.id, dir);
  SIM.emit("paste", dir); SIM.renderDesk();
};
SIM.newItem = (dir, type) => {
  const names = { dir: "Nouveau dossier", txt: "Nouveau document texte.txt", docx: "Nouveau document Microsoft Word.docx", xlsx: "Nouvelle feuille de calcul Microsoft Excel.xlsx" };
  const n = FS.create(dir, names[type], type, type === "xlsx" ? {} : "");
  SIM.emit("new", n); return n;
};
SIM.deskMenu = (x, y) => {
  SIM.menu(x, y, [
    { t: "Affichage", sub: [{ t: "Grandes icônes", fn: () => document.body.style.setProperty("--dico", "1") }, { t: "Icônes moyennes", fn: () => 0 }] },
    { t: "Trier par", sub: [{ t: "Nom", fn: () => SIM.renderDesk() }, { t: "Date de modification", fn: () => SIM.renderDesk() }] },
    { t: "Actualiser", ic: "refresh", fn: () => { const b = document.getElementById("icons"); b.style.opacity = 0; setTimeout(() => { SIM.renderDesk(); b.style.opacity = 1; }, 120); } }, "-",
    { t: "Coller", k: "Ctrl+V", ic: "paste", dis: !SIM.clip, fn: () => SIM.paste("desktop") }, "-",
    { t: "Nouveau", ic: "plus", sub: [
      { t: "Dossier", ic: "folder", fn: () => { const n = SIM.newItem("desktop", "dir"); SIM.pendingRename = n.id; SIM.renderDesk(); } },
      "-",
      { t: "Document texte", ic: "txt", fn: () => { const n = SIM.newItem("desktop", "txt"); SIM.pendingRename = n.id; SIM.renderDesk(); } },
      { t: "Document Microsoft Word", ic: "docx", fn: () => { const n = SIM.newItem("desktop", "docx"); SIM.pendingRename = n.id; SIM.renderDesk(); } },
      { t: "Feuille de calcul Microsoft Excel", ic: "xlsx", fn: () => { const n = SIM.newItem("desktop", "xlsx"); SIM.pendingRename = n.id; SIM.renderDesk(); } }] }, "-",
    { t: "Paramètres d’affichage", ic: "settings", fn: () => WM.open("settings", { page: "system" }) },
    { t: "Personnaliser", ic: "paint", fn: () => WM.open("settings", { page: "perso" }) }]);
};

// ------------------------------------------------------------------ barre des tâches
const PINNED = ["explorer", "browser", "word", "excel", "notepad", "exercises"];
SIM.taskbar = () => {
  const box = document.getElementById("tbapps"); if (!box) return;
  box.innerHTML = "";
  const st = $("button", "tbi", box, `<span class="ic">${IC.start}</span>`); st.title = "Démarrer"; st.id = "startbtn";
  st.onclick = e => { e.stopPropagation(); SIM.toggleFly("start"); };
  const sr = $("button", "tbsearch", box, `${IC.search}<span>Rechercher</span>`);
  sr.onclick = e => { e.stopPropagation(); SIM.toggleFly("start", true); };
  const ids = PINNED.concat(SIM.wins.map(w => w.app).filter((a, i, arr) => !PINNED.includes(a) && arr.indexOf(a) === i));
  ids.forEach(a => {
    const app = SIM.apps[a]; if (!app) return;
    const ws = SIM.wins.filter(w => w.app === a);
    const b = $("button", "tbi" + (ws.length ? " run" : "") + (SIM.active && SIM.active.app === a && !SIM.active.minimized ? " act" : ""), box, `<span class="ic">${IC[app.icon]}</span><i class="dot"></i>`);
    b.title = app.name;
    b.onclick = e => {
      e.stopPropagation(); SIM.closeFly();
      if (!ws.length) return WM.open(a, a === "explorer" ? { dir: "root" } : {});
      const w = ws[ws.length - 1];
      if (w.minimized) WM.restore(w); else if (SIM.active === w) WM.minimize(w); else WM.focus(w);
    };
    b.addEventListener("contextmenu", e => { e.preventDefault(); e.stopPropagation();
      SIM.menu(e.clientX, innerHeight - 52 - (ws.length ? 110 : 74), [{ t: app.name, ic: app.icon, fn: () => WM.open(a, a === "explorer" ? { dir: "root" } : {}) }, ...(ws.length ? ["-", { t: ws.length > 1 ? "Fermer toutes les fenêtres" : "Fermer la fenêtre", ic: "close", fn: () => ws.forEach(w => WM.close(w)) }] : [])]); });
  });
};
SIM.tbMenu = (x, y) => SIM.menu(x, y - 10, [{ t: "Gestionnaire des tâches", k: "Ctrl+Maj+Échap", fn: () => SIM.toast("Gestionnaire des tâches", SIM.wins.length + " application(s) ouverte(s)") },
  { t: "Afficher le bureau", fn: () => SIM.wins.forEach(w => WM.minimize(w)) }, "-", { t: "Paramètres de la barre des tâches", ic: "settings", fn: () => WM.open("settings", { page: "taskbar" }) }]);

// horloge et date en français
SIM.clock = () => {
  const d = new Date();
  const c = document.getElementById("clock"); if (c) c.innerHTML = `${d.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" })}<br>${d.toLocaleDateString("fr-FR")}`;
  const lt = document.querySelector("#lock .tm"); if (lt) lt.textContent = d.toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" });
  const ld = document.querySelector("#lock .dt"); if (ld) ld.textContent = d.toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long" });
};

// ------------------------------------------------------------------ volets
SIM.closeFly = () => { document.querySelectorAll(".flyout.open").forEach(f => f.classList.remove("open")); };
SIM.toggleFly = (id, focusSearch) => {
  const f = document.getElementById(id), was = f.classList.contains("open");
  SIM.closeFly(); SIM.closeMenus();
  if (!was || focusSearch) {
    f.classList.add("open");
    if (id === "start") { SIM.renderStart(""); const i = f.querySelector("input"); i.value = ""; if (focusSearch) setTimeout(() => i.focus(), 50); SIM.emit("start"); }
    if (id === "cal") SIM.renderCal();
  }
};
const START_PINNED = ["word", "excel", "ppt", "notepad", "browser", "explorer", "calc", "paint", "settings", "keyboard", "photos", "exercises"];
SIM.renderStart = q => {
  const body = document.querySelector("#start .sm-body");
  const all = Object.entries(SIM.apps).filter(([, a]) => !a.hidden).sort((a, b) => a[1].name.localeCompare(b[1].name, "fr"));
  const openApp = id => { SIM.closeFly(); WM.open(id, id === "explorer" ? { dir: "root" } : {}); };
  if (q) {
    const qq = q.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
    const norm = s => s.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");
    const apps = all.filter(([, a]) => norm(a.name).includes(qq) || (a.kw || "").includes(qq));
    const files = Object.values(FS.nodes).filter(n => n.parent && n.parent !== "trash" && n.id.startsWith("n") && norm(n.name).includes(qq)).slice(0, 6);
    body.innerHTML = `<div class="sm-h">Meilleurs résultats</div>`;
    if (!apps.length && !files.length) $("div", "", body, `<div style="padding:30px 8px;color:var(--sub)">Aucun résultat pour « ${esc(q)} »</div>`);
    apps.forEach(([id, a]) => { const r = $("div", "li", body, `<span class="ic">${IC[a.icon]}</span><div>${esc(a.name)}<small>Application</small></div>`); r.onclick = () => { openApp(id); SIM.emit("search-open", id); }; });
    files.forEach(n => { const r = $("div", "li", body, `<span class="ic">${IC[FS.iconOf(n)]}</span><div>${esc(n.name)}<small>${esc(FS.path(n.parent).map(p => p.name).join(" › "))}</small></div>`); r.onclick = () => { SIM.closeFly(); SIM.openNode(n); }; });
    return;
  }
  if (SIM.startAll) {
    body.innerHTML = `<div class="sm-h">Toutes les applications <button>‹ Retour</button></div>`;
    body.querySelector("button").onclick = () => { SIM.startAll = false; SIM.renderStart(""); };
    let L = "";
    all.forEach(([id, a]) => { const l = a.name[0].toUpperCase(); if (l !== L) { L = l; $("div", "", body, `<div style="padding:8px 10px 2px;font-weight:600">${l}</div>`); } const r = $("div", "li", body, `<span class="ic">${IC[a.icon]}</span>${esc(a.name)}`); r.onclick = () => openApp(id); });
    return;
  }
  body.innerHTML = `<div class="sm-h">Épinglé <button>Toutes les applications ›</button></div><div class="grid6"></div><div class="sm-h">Recommandé</div><div class="rec"></div>`;
  body.querySelector("button").onclick = () => { SIM.startAll = true; SIM.renderStart(""); };
  const g = body.querySelector(".grid6");
  START_PINNED.forEach(id => { const a = SIM.apps[id]; if (!a) return; const c = $("div", "gi", g, `<div class="ic">${IC[a.icon]}</div>${esc(a.name)}`); c.onclick = () => openApp(id); });
  const rec = body.querySelector(".rec");
  Object.values(FS.nodes).filter(n => n.id.startsWith("n") && n.type !== "dir" && n.parent !== "trash").sort((a, b) => (b.mtime || 0) - (a.mtime || 0)).slice(0, 4)
    .forEach(n => { const r = $("div", "li", rec, `<span class="ic">${IC[FS.iconOf(n)]}</span><div>${esc(n.name)}<small>${new Date(n.mtime || Date.now()).toLocaleDateString("fr-FR")}</small></div>`); r.onclick = () => { SIM.closeFly(); SIM.openNode(n); }; });
};
SIM.renderCal = () => {
  const c = document.getElementById("cal"), d = new Date(), y = d.getFullYear(), m = d.getMonth();
  const first = (new Date(y, m, 1).getDay() + 6) % 7, days = new Date(y, m + 1, 0).getDate();
  let h = `<div style="font-size:15px;font-weight:600">${d.toLocaleDateString("fr-FR", { weekday: "long", day: "numeric", month: "long" })}</div>
    <div style="margin-top:12px;font-weight:600">${d.toLocaleDateString("fr-FR", { month: "long", year: "numeric" })}</div><div class="calg">${["lu", "ma", "me", "je", "ve", "sa", "di"].map(x => `<span style="font-size:12px">${x}</span>`).join("")}`;
  for (let i = 0; i < first; i++) h += `<span class="dim">${new Date(y, m, i - first + 1).getDate()}</span>`;
  for (let i = 1; i <= days; i++) h += `<span class="${i === d.getDate() ? "today" : ""}">${i}</span>`;
  c.innerHTML = h + "</div>";
};

// ------------------------------------------------------------------ alimentation, verrouillage
SIM.power = act => {
  SIM.closeFly();
  const b = document.getElementById("black");
  if (act === "lock") { SIM.lock(); return; }
  b.innerHTML = `<div class="spin">${[0, 1, 2, 3, 4].map(i => `<i style="animation-delay:${i * .12}s"></i>`).join("")}</div><div>${act === "restart" ? "Redémarrage" : "Arrêt"} en cours…</div>`;
  b.classList.add("on");
  SIM.emit(act);
  setTimeout(() => {
    SIM.wins.slice().forEach(w => { w.el.remove(); }); SIM.wins = []; SIM.taskbar();
    if (act === "restart") { b.innerHTML = `<div class="blogo"><i></i><i></i><i></i><i></i></div><div class="spin">${[0, 1, 2, 3, 4].map(i => `<i style="animation-delay:${i * .12}s"></i>`).join("")}</div>`; setTimeout(() => { b.classList.remove("on"); SIM.lock(); }, 2200); }
    else { b.innerHTML = `<div style="opacity:.8">L’ordinateur est éteint.</div><button class="btn pri" style="height:44px;padding:0 26px">${IC.power}&nbsp; Rallumer</button>`;
      b.querySelector("button").onclick = () => { b.innerHTML = `<div class="blogo"><i></i><i></i><i></i><i></i></div><div class="spin">${[0, 1, 2, 3, 4].map(i => `<i style="animation-delay:${i * .12}s"></i>`).join("")}</div>`; setTimeout(() => { b.classList.remove("on"); SIM.lock(); }, 2200); }; }
  }, 1600);
};
SIM.lock = () => {
  const l = document.getElementById("lock"); l.className = ""; SIM.clock();
  SIM.emit("lock");
};
SIM.unlock = () => { const l = document.getElementById("lock"); if (l.classList.contains("log")) { l.classList.add("gone"); SIM.emit("login"); } else l.classList.add("log"); };

// ------------------------------------------------------------------ bouton « retour » d'Android
SIM.back = () => {
  if (document.querySelector(".ctx")) { SIM.closeMenus(); return true; }
  if (document.querySelector(".flyout.open")) { SIM.closeFly(); return true; }
  const dlg = document.querySelector(".dlgback"); if (dlg) { dlg.remove(); return true; }
  const w = SIM.active && !SIM.active.minimized ? SIM.active : null; if (w) { WM.close(w); return true; }
  return false;
};
