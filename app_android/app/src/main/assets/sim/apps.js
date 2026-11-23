/* Simulateur Windows 11 HELP-ME : applications. */
"use strict";
(function () {
  const { $, esc, IC, FS, WM } = SIM;

  // ================================================================== Explorateur de fichiers
  SIM.apps.explorer = { name: "Explorateur de fichiers", icon: "folder", w: 860, h: 520, kw: "dossier fichiers ce pc documents",
    mount(w, o) {
      let cur = o.dir || "root", hist = [cur], hi = 0, sel = null;
      w.body.innerHTML = `<div class="cmdbar"><div class="cb nw">${IC.plus}<span>Nouveau</span> ▾</div><div class="vsep"></div>
        <div class="cb ct" title="Couper">${IC.cut}</div><div class="cb cp" title="Copier">${IC.copy}</div><div class="cb ps" title="Coller">${IC.paste}</div>
        <div class="cb rn" title="Renommer">${IC.ren}</div><div class="cb dl" title="Supprimer">${IC.del}</div><div class="vsep"></div><div class="cb et" style="display:none">${IC.del}<span>Vider la Corbeille</span></div></div>
        <div class="ex-addr"><div class="cb bk">${IC.back}</div><div class="cb fw">${IC.fwd}</div><div class="cb upb">${IC.up}</div><div class="ex-path"></div></div>
        <div class="ex-main"><div class="ex-nav"></div><div class="ex-files"></div></div><div class="status"></div>`;
      const q = s => w.body.querySelector(s);
      const nav = q(".ex-nav"), files = q(".ex-files");
      [["root", "pc", "Ce PC"], ["desktop", "folder", "Bureau"], ["docs", "docs", "Documents"], ["dl", "dl", "Téléchargements"], ["pics", "pics", "Images"], ["music", "music", "Musique"], ["videos", "video", "Vidéos"], ["trash", "bin", "Corbeille"]]
        .forEach(([id, ic, nm]) => { const r = $("div", "li", nav, `<span class="ic">${IC[ic]}</span><span>${nm}</span>`); r.dataset.id = id; r.onclick = () => go(id); });
      const go = (id, noHist) => { if (!FS.get(id)) id = "root"; cur = id; sel = null; if (!noHist) { hist = hist.slice(0, hi + 1); hist.push(id); hi = hist.length - 1; } render(); SIM.emit("explore", id); };
      const render = () => {
        const n = FS.get(cur);
        w.setTitle(n.name);
        const p = q(".ex-path"); p.innerHTML = "";
        (cur === "trash" ? [n] : FS.path(cur)).forEach((x, i) => { if (i) $("span", "", p, " › "); const s = $("span", "", p, esc(x.name)); s.style.padding = "2px 4px"; s.onclick = () => go(x.id); });
        nav.querySelectorAll(".li").forEach(r => r.style.background = r.dataset.id === cur ? "var(--hover)" : "");
        files.innerHTML = "";
        let list = FS.kids(cur);
        if (cur === "root") list = list.concat([{ id: "_c", name: "Disque local (C:)", type: "drive" }, { id: "_e", name: "Clé USB (E:)", type: "usb" }]);
        if (!list.length) $("div", "empty", files, cur === "trash" ? "La Corbeille est vide." : "Ce dossier est vide.<br><br>Clic droit › Nouveau pour créer un élément.");
        list.forEach(x => {
          const ic = x.type === "drive" ? "disk" : x.type === "usb" ? "usb" : FS.iconOf(x);
          const f = $("div", "fi" + (SIM.clip && SIM.clip.cut && SIM.clip.id === x.id ? " cut" : ""), files, `<div class="ic">${IC[ic]}</div><div class="nm">${esc(x.name)}</div>`);
          f.dataset.id = x.id;
          if (x.type === "drive" || x.type === "usb") { f.ondblclick = () => go("docs"); SIM.touchy(f, { open: e => e.pointerType !== "mouse" && go("docs") }); return; }
          f.addEventListener("pointerdown", e => { e.stopPropagation(); select(x.id); });
          f.addEventListener("dblclick", () => open(x));
          SIM.touchy(f, { open: e => { if (e.pointerType !== "mouse") open(x); }, ctx: (cx, cy) => { select(x.id); SIM.fileMenu(cx, cy, x, w.body, () => renameInline(x.id)); } });
          if (pendingRename === x.id) { pendingRename = null; setTimeout(() => renameInline(x.id), 30); }
        });
        q(".status").innerHTML = `<span>${FS.kids(cur).length} élément(s)</span>${sel ? `<span>1 élément sélectionné</span>` : ""}`;
        q(".et").style.display = cur === "trash" ? "" : "none";
        q(".bk").classList.toggle("dis", hi === 0); q(".fw").classList.toggle("dis", hi >= hist.length - 1); q(".upb").classList.toggle("dis", !n.parent);
        ["ct", "cp", "rn", "dl"].forEach(c => q("." + c).classList.toggle("dis", !sel));
        q(".ps").classList.toggle("dis", !SIM.clip);
      };
      let pendingRename = null;
      const select = id => { sel = id; files.querySelectorAll(".fi").forEach(f => f.classList.toggle("sel", f.dataset.id === id)); ["ct", "cp", "rn", "dl"].forEach(c => q("." + c).classList.toggle("dis", !sel)); };
      const open = x => { if (x.type === "dir") go(x.id); else SIM.openNode(x); };
      const renameInline = id => {
        const f = files.querySelector(`.fi[data-id="${id}"]`); if (!f) return;
        const n = FS.get(id), nm = f.querySelector(".nm"); nm.innerHTML = "";
        const inp = $("input", "rename", nm); inp.value = n.name; inp.addEventListener("pointerdown", e => e.stopPropagation());
        const dot = n.type !== "dir" ? n.name.lastIndexOf(".") : -1;
        setTimeout(() => { inp.focus(); inp.setSelectionRange(0, dot > 0 ? dot : n.name.length); }, 20);
        let done = false; const fin = ok => { if (done) return; done = true; if (ok) FS.rename(id, inp.value); render(); };
        inp.addEventListener("keydown", e => { e.stopPropagation(); if (e.key === "Enter") fin(true); if (e.key === "Escape") fin(false); });
        inp.addEventListener("blur", () => fin(true));
      };
      const newMenu = (x, y) => SIM.menu(x, y, [
        { t: "Dossier", ic: "folder", fn: () => { const n = SIM.newItem(cur, "dir"); pendingRename = n.id; render(); } }, "-",
        { t: "Document texte", ic: "txt", fn: () => { const n = SIM.newItem(cur, "txt"); pendingRename = n.id; render(); } },
        { t: "Document Microsoft Word", ic: "docx", fn: () => { const n = SIM.newItem(cur, "docx"); pendingRename = n.id; render(); } },
        { t: "Feuille de calcul Microsoft Excel", ic: "xlsx", fn: () => { const n = SIM.newItem(cur, "xlsx"); pendingRename = n.id; render(); } }]);
      q(".nw").onclick = e => { e.stopPropagation(); if (cur === "trash" || cur === "root") return SIM.toast("Impossible ici", "Ouvrez un dossier (Documents, Bureau…) pour créer un élément."); const r = e.currentTarget.getBoundingClientRect(); newMenu(r.left, r.bottom + 2); };
      q(".ct").onclick = () => sel && SIM.setClip(sel, true);
      q(".cp").onclick = () => sel && SIM.setClip(sel, false);
      q(".ps").onclick = () => SIM.clip && cur !== "root" && cur !== "trash" && SIM.paste(cur);
      q(".rn").onclick = () => sel && renameInline(sel);
      q(".dl").onclick = () => sel && (cur === "trash" ? FS.purge(sel) : FS.remove(sel));
      q(".et").onclick = () => SIM.emptyTrash();
      q(".bk").onclick = () => { if (hi > 0) { hi--; go(hist[hi], true); } };
      q(".fw").onclick = () => { if (hi < hist.length - 1) { hi++; go(hist[hi], true); } };
      q(".upb").onclick = () => { const n = FS.get(cur); if (n.parent) go(n.parent); };
      files.addEventListener("pointerdown", () => select(null));
      SIM.touchy(files, { ctx: (x, y, e) => {
        if (e.target.closest(".fi")) return;
        if (cur === "trash") return SIM.menu(x, y, [{ t: "Vider la Corbeille", ic: "del", fn: () => SIM.emptyTrash() }]);
        if (cur === "root") return;
        SIM.menu(x, y, [{ t: "Affichage", sub: [{ t: "Grandes icônes", fn: () => files.style.gridTemplateColumns = "repeat(auto-fill, 110px)" }, { t: "Icônes moyennes", fn: () => files.style.gridTemplateColumns = "" }] },
          { t: "Actualiser", ic: "refresh", fn: render }, "-", { t: "Coller", k: "Ctrl+V", ic: "paste", dis: !SIM.clip, fn: () => SIM.paste(cur) }, "-",
          { t: "Nouveau", ic: "plus", sub: [{ t: "Dossier", ic: "folder", fn: () => { const n = SIM.newItem(cur, "dir"); pendingRename = n.id; render(); } }, "-",
            { t: "Document texte", ic: "txt", fn: () => { const n = SIM.newItem(cur, "txt"); pendingRename = n.id; render(); } },
            { t: "Document Microsoft Word", ic: "docx", fn: () => { const n = SIM.newItem(cur, "docx"); pendingRename = n.id; render(); } },
            { t: "Feuille de calcul Microsoft Excel", ic: "xlsx", fn: () => { const n = SIM.newItem(cur, "xlsx"); pendingRename = n.id; render(); } }] }, "-",
          { t: "Propriétés", fn: () => SIM.dialog(w.body, "Propriétés", `<b>${esc(FS.get(cur).name)}</b><br><br>${FS.kids(cur).length} élément(s)`) }]);
      } });
      w.keys = e => {
        if (e.key === "F2" && sel) { e.preventDefault(); renameInline(sel); }
        if (e.key === "Delete" && sel) FS.remove(sel);
        if (e.ctrlKey && e.key.toLowerCase() === "c" && sel) SIM.setClip(sel, false);
        if (e.ctrlKey && e.key.toLowerCase() === "x" && sel) SIM.setClip(sel, true);
        if (e.ctrlKey && e.key.toLowerCase() === "v") SIM.paste(cur);
        if (e.key === "Backspace") { const n = FS.get(cur); if (n.parent) go(n.parent); }
      };
      const onfs = d => { if (d === cur || !FS.get(cur)) render(); };
      SIM.on("fs", onfs);
      w.onClose = () => { SIM.listeners.fs = SIM.listeners.fs.filter(f => f !== onfs); };
      w.go = go;
      go(cur, true);
    },
    reopen(w, o) { if (o && o.dir) w.go(o.dir); }
  };

  // ================================================================== enregistrement commun (Bloc-notes, Word, Excel)
  /** Boîte « Enregistrer sous » : choisit le dossier et le nom ; renvoie le nœud créé. */
  SIM.saveAs = async (w, defName, type, content) => {
    const dirs = [["docs", "Documents"], ["desktop", "Bureau"], ["dl", "Téléchargements"], ["pics", "Images"]].concat(FS.kids("docs").filter(n => n.type === "dir").map(n => [n.id, "Documents › " + n.name]));
    const r = await SIM.dialog(w.body, "Enregistrer sous", `<div style="margin-bottom:6px">Dossier</div><select class="field" style="width:100%">${dirs.map(([id, nm]) => `<option value="${id}">${esc(nm)}</option>`).join("")}</select>
      <div style="margin:12px 0 6px">Nom du fichier</div><input class="field" style="width:100%" value="${esc(defName)}">`, [["Enregistrer", true], ["Annuler", false]]);
    if (!r.v) return null;
    let name = r.el.querySelector("input").value.trim() || defName;
    const ext = "." + type; if (!name.toLowerCase().endsWith(ext)) name += ext;
    const n = FS.create(r.el.querySelector("select").value, name, type, content);
    SIM.toast("Fichier enregistré", `${n.name} dans ${FS.get(n.parent).name}`, "ok");
    SIM.emit("save", n); SIM.renderDesk();
    return n;
  };

  // ================================================================== Bloc-notes
  SIM.apps.notepad = { name: "Bloc-notes", icon: "notepad", w: 680, h: 440, kw: "notepad texte",
    mount(w, o) {
      let file = o.file ? FS.get(o.file) : null, dirty = false;
      w.body.innerHTML = `<div class="menubar"><span class="mb f">Fichier</span><span class="mb e">Édition</span><span class="mb a">Affichage</span></div><textarea class="np" spellcheck="false"></textarea><div class="status"><span class="pos">Ln 1, Col 1</span><span class="cnt">0 caractère</span><span style="margin-left:auto">UTF-8</span></div>`;
      const ta = w.body.querySelector("textarea");
      const title = () => w.setTitle(`${dirty ? "*" : ""}${file ? file.name : "Sans titre"} – Bloc-notes`);
      ta.value = file ? (typeof file.content === "string" ? file.content.replace(/<[^>]+>/g, "") : "") : "";
      title();
      const stat = () => { const v = ta.value.slice(0, ta.selectionStart).split("\n"); w.body.querySelector(".pos").textContent = `Ln ${v.length}, Col ${v[v.length - 1].length + 1}`; w.body.querySelector(".cnt").textContent = `${ta.value.length} caractère${ta.value.length > 1 ? "s" : ""}`; };
      ta.addEventListener("input", () => { dirty = true; title(); stat(); SIM.emit("type", { app: "notepad", text: ta.value }); });
      ta.addEventListener("keyup", stat); ta.addEventListener("click", stat);
      const save = async as => {
        if (file && !as) { file.content = ta.value; file.mtime = Date.now(); FS.save(); SIM.emit("save", file); SIM.toast("Enregistré", file.name, "ok"); }
        else { const n = await SIM.saveAs(w, "Sans titre", "txt", ta.value); if (!n) return; file = n; }
        dirty = false; title();
      };
      w.body.querySelector(".f").onclick = e => { const r = e.target.getBoundingClientRect(); SIM.menu(r.left, r.bottom, [
        { t: "Nouveau", k: "Ctrl+N", fn: () => WM.open("notepad") }, { t: "Ouvrir…", k: "Ctrl+O", fn: () => WM.open("explorer", { dir: "docs" }) },
        { t: "Enregistrer", k: "Ctrl+S", fn: () => save() }, { t: "Enregistrer sous…", fn: () => save(true) }, "-", { t: "Imprimer…", k: "Ctrl+P", fn: () => SIM.toast("Impression", "Aucune imprimante dans le simulateur.") }, "-", { t: "Quitter", fn: () => WM.close(w) }]); };
      w.body.querySelector(".e").onclick = e => { const r = e.target.getBoundingClientRect(); SIM.menu(r.left, r.bottom, [
        { t: "Tout sélectionner", k: "Ctrl+A", fn: () => { ta.focus(); ta.select(); } }, { t: "Heure/Date", k: "F5", fn: () => { ta.setRangeText(new Date().toLocaleString("fr-FR"), ta.selectionStart, ta.selectionEnd, "end"); dirty = true; title(); } }]); };
      w.body.querySelector(".a").onclick = e => { const r = e.target.getBoundingClientRect(); SIM.menu(r.left, r.bottom, [{ t: "Zoom avant", k: "Ctrl++", fn: () => ta.style.fontSize = (parseFloat(getComputedStyle(ta).fontSize) + 2) + "px" }, { t: "Zoom arrière", k: "Ctrl+-", fn: () => ta.style.fontSize = (parseFloat(getComputedStyle(ta).fontSize) - 2) + "px" }]); };
      w.keys = e => { if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); save(); } };
      w.input = ta;
      setTimeout(() => ta.focus(), 100);
    }
  };

  // ================================================================== Word
  SIM.apps.word = { name: "Word", icon: "word", w: 900, h: 560, kw: "traitement de texte document",
    mount(w, o) {
      let file = o.file ? FS.get(o.file) : null;
      w.body.innerHTML = `<div class="ribtabs"><span>Fichier</span><span class="on">Accueil</span><span>Insertion</span><span>Mise en page</span><span>Affichage</span></div>
        <div class="ribbon"><span class="rb sv" title="Enregistrer (Ctrl+S)">&#128190;</span><span class="rb" data-c="undo" title="Annuler (Ctrl+Z)">&#8630;</span><span class="rb" data-c="redo" title="Rétablir (Ctrl+Y)">&#8631;</span><span class="vsep"></span>
        <select class="rsel ff"><option>Calibri</option><option>Arial</option><option>Times New Roman</option><option>Verdana</option><option>Georgia</option><option>Courier New</option></select>
        <select class="rsel fs">${[8, 9, 10, 11, 12, 14, 16, 18, 20, 24, 28, 36].map(n => `<option${n === 11 ? " selected" : ""}>${n}</option>`).join("")}</select>
        <span class="rb" data-c="bold" style="font-weight:800" title="Gras (Ctrl+G)">G</span><span class="rb" data-c="italic" style="font-style:italic;font-family:serif" title="Italique (Ctrl+I)">I</span>
        <span class="rb" data-c="underline" style="text-decoration:underline" title="Souligné (Ctrl+U)">S</span><span class="rb" data-c="strikeThrough" style="text-decoration:line-through">ab</span>
        <label class="rb" title="Couleur de police" style="position:relative;font-weight:700;color:#c00000">A<input type="color" value="#c00000" class="fc" style="position:absolute;opacity:0;inset:0"></label>
        <span class="rb hi" title="Surlignage" style="background:linear-gradient(transparent 70%,#fff200 70%)">&#9998;</span><span class="vsep"></span>
        <span class="rb" data-c="justifyLeft" title="Aligner à gauche">&#8676;</span><span class="rb" data-c="justifyCenter" title="Centrer">&#8801;</span><span class="rb" data-c="justifyRight" title="Aligner à droite">&#8677;</span><span class="rb" data-c="justifyFull" title="Justifier">&#9776;</span>
        <span class="rb" data-c="insertUnorderedList" title="Puces">&#8226;&#8801;</span><span class="rb" data-c="insertOrderedList" title="Numérotation">1&#8801;</span><span class="vsep"></span>
        <select class="rsel st"><option value="p">Normal</option><option value="h1">Titre 1</option><option value="h2">Titre 2</option></select></div>
        <div class="wdoc"><div class="wpage" contenteditable="true" spellcheck="false"></div></div><div class="status"><span class="pg">Page 1 sur 1</span><span class="wc">0 mot</span><span>Français (France)</span></div>`;
      const page = w.body.querySelector(".wpage");
      page.innerHTML = file && file.content ? file.content : "<p><br></p>";
      const title = () => w.setTitle(`${file ? file.name.replace(/\.docx$/, "") : "Document1"} - Word`);
      title();
      const count = () => { const n = (page.innerText.trim().match(/\S+/g) || []).length; w.body.querySelector(".wc").textContent = `${n} mot${n > 1 ? "s" : ""}`; };
      const state = () => w.body.querySelectorAll(".rb[data-c]").forEach(b => { try { b.classList.toggle("on", ["bold", "italic", "underline", "strikeThrough", "justifyLeft", "justifyCenter", "justifyRight", "justifyFull"].includes(b.dataset.c) && document.queryCommandState(b.dataset.c)); } catch (e) { /* commande inconnue */ } });
      w.body.querySelectorAll(".rb[data-c]").forEach(b => b.addEventListener("pointerdown", e => { e.preventDefault(); document.execCommand(b.dataset.c); state(); SIM.emit("format", { app: "word", cmd: b.dataset.c }); }));
      w.body.querySelector(".ff").onchange = e => { page.focus(); document.execCommand("fontName", false, e.target.value); SIM.emit("format", { app: "word", cmd: "font" }); };
      w.body.querySelector(".fs").onchange = e => { page.focus(); const map = { 8: 1, 9: 1, 10: 2, 11: 2, 12: 3, 14: 4, 16: 4, 18: 5, 20: 5, 24: 6, 28: 6, 36: 7 }; document.execCommand("fontSize", false, map[e.target.value] || 3); SIM.emit("format", { app: "word", cmd: "size" }); };
      w.body.querySelector(".fc").oninput = e => { page.focus(); document.execCommand("foreColor", false, e.target.value); SIM.emit("format", { app: "word", cmd: "color" }); };
      w.body.querySelector(".hi").addEventListener("pointerdown", e => { e.preventDefault(); document.execCommand("hiliteColor", false, "#fff200"); SIM.emit("format", { app: "word", cmd: "highlight" }); });
      w.body.querySelector(".st").onchange = e => { page.focus(); document.execCommand("formatBlock", false, e.target.value); SIM.emit("format", { app: "word", cmd: "style" }); };
      page.addEventListener("input", () => { count(); SIM.emit("type", { app: "word", text: page.innerText }); });
      page.addEventListener("keyup", state); page.addEventListener("mouseup", state);
      const save = async () => {
        if (file) { file.content = page.innerHTML; file.mtime = Date.now(); FS.save(); SIM.emit("save", file); SIM.toast("Enregistré", file.name, "ok"); }
        else { const n = await SIM.saveAs(w, "Document1", "docx", page.innerHTML); if (n) { file = n; title(); } }
      };
      w.body.querySelector(".sv").onclick = save;
      w.body.querySelector(".ribtabs span").onclick = e => { const r = e.target.getBoundingClientRect(); SIM.menu(r.left, r.bottom, [{ t: "Enregistrer", k: "Ctrl+S", fn: save }, { t: "Enregistrer sous…", fn: async () => { const n = await SIM.saveAs(w, "Document1", "docx", page.innerHTML); if (n) { file = n; title(); } } }, { t: "Exporter en PDF", fn: () => SIM.toast("Exporter", "Un fichier PDF serait créé ici.") }, "-", { t: "Fermer", fn: () => WM.close(w) }]); };
      w.keys = e => {
        if (!e.ctrlKey) return;
        const k = e.key.toLowerCase();
        if (k === "s") { e.preventDefault(); save(); }
        if (k === "g") { e.preventDefault(); document.execCommand("bold"); state(); SIM.emit("format", { app: "word", cmd: "bold" }); }
      };
      w.input = page;
      count();
      setTimeout(() => page.focus(), 120);
    }
  };

  // ================================================================== Excel
  const COLS = "ABCDEFGHIJKLMNOP".split(""), ROWS = 40;
  const ref = (c, r) => COLS[c] + (r + 1);
  const parseRef = s => { const m = /^([A-P])(\d+)$/i.exec(s.trim()); return m ? [COLS.indexOf(m[1].toUpperCase()), parseInt(m[2], 10) - 1] : null; };
  /** Évalue une formule simple : =A1+B2*3, =SOMME(A1:A5), MOYENNE, MAX, MIN, NB. */
  function evalCell(data, key, seen) {
    const raw = data[key] == null ? "" : String(data[key]);
    if (!raw.startsWith("=")) { const n = parseFloat(raw.replace(",", ".")); return raw !== "" && !isNaN(n) && /^-?[\d\s.,]+$/.test(raw) ? n : raw; }
    seen = seen || {}; if (seen[key]) return "#REF!"; seen[key] = true;
    const range = (a, b) => { const A = parseRef(a), B = parseRef(b); if (!A || !B) return []; const out = []; for (let c = Math.min(A[0], B[0]); c <= Math.max(A[0], B[0]); c++) for (let r = Math.min(A[1], B[1]); r <= Math.max(A[1], B[1]); r++) { const v = evalCell(data, ref(c, r), { ...seen }); if (typeof v === "number") out.push(v); } return out; };
    let ex = raw.slice(1).toUpperCase().replace(/;/g, ",");
    const fns = { SOMME: a => a.reduce((x, y) => x + y, 0), SUM: a => a.reduce((x, y) => x + y, 0), MOYENNE: a => a.length ? a.reduce((x, y) => x + y, 0) / a.length : 0, AVERAGE: a => a.length ? a.reduce((x, y) => x + y, 0) / a.length : 0, MAX: a => a.length ? Math.max(...a) : 0, MIN: a => a.length ? Math.min(...a) : 0, NB: a => a.length, COUNT: a => a.length };
    try {
      ex = ex.replace(/([A-Z]+)\(([^()]*)\)/g, (m, f, args) => {
        if (!fns[f]) throw new Error("#NOM?");
        const vals = []; args.split(",").forEach(a => { a = a.trim(); if (a.includes(":")) { const [x, y] = a.split(":"); vals.push(...range(x, y)); } else { const p = parseRef(a); if (p) { const v = evalCell(data, ref(p[0], p[1]), { ...seen }); if (typeof v === "number") vals.push(v); } else if (a !== "" && !isNaN(parseFloat(a))) vals.push(parseFloat(a)); } });
        return "(" + fns[f](vals) + ")";
      });
      ex = ex.replace(/\b([A-P])(\d+)\b/g, (m) => { const v = evalCell(data, m, { ...seen }); return typeof v === "number" ? "(" + v + ")" : "0"; });
      ex = ex.replace(/,/g, ".");
      if (!/^[\d+\-*/().\s^%]*$/.test(ex)) return "#VALEUR!";
      const v = Function("return (" + ex.replace(/\^/g, "**") + ")")();
      return typeof v === "number" && isFinite(v) ? Math.round(v * 1e10) / 1e10 : "#DIV/0!";
    } catch (e) { return e.message && e.message.startsWith("#") ? e.message : "#VALEUR!"; }
  }
  SIM.evalCell = evalCell;
  SIM.apps.excel = { name: "Excel", icon: "excel", w: 900, h: 560, kw: "tableur classeur calcul",
    mount(w, o) {
      let file = o.file ? FS.get(o.file) : null;
      const data = file && file.content && typeof file.content === "object" ? { ...file.content } : {};
      const fmt = (file && file.fmt) ? { ...file.fmt } : {};
      let cur = [0, 0], anchor = null, editing = null;
      w.body.classList.add("xl");
      w.body.innerHTML = `<div class="ribtabs"><span>Fichier</span><span class="on">Accueil</span><span>Insertion</span><span>Formules</span><span>Données</span></div>
        <div class="ribbon"><span class="rb sv" title="Enregistrer">&#128190;</span><span class="vsep"></span><span class="rb b" style="font-weight:800" title="Gras">G</span><span class="rb i" style="font-style:italic;font-family:serif">I</span>
        <span class="rb fill" title="Couleur de remplissage" style="background:linear-gradient(transparent 70%,#ffd966 70%)">&#9641;</span><span class="vsep"></span>
        <span class="rb sum" title="Somme automatique">&Sigma; Somme</span><span class="rb avg" title="Moyenne">Moyenne</span><span class="vsep"></span><span class="rb clr" title="Effacer">&#10005; Effacer</span></div>
        <div class="fx"><div class="namebox">A1</div><span style="font-style:italic;color:var(--sub)">fx</span><input class="fxin" spellcheck="false"></div>
        <div class="sheet"><table></table></div><div class="tabsbar"><span class="on">Feuil1</span><span style="color:var(--sub)">+</span></div><div class="status"><span class="sm"></span></div>`;
      const tbl = w.body.querySelector("table"), fx = w.body.querySelector(".fxin"), nb = w.body.querySelector(".namebox");
      let h = `<tr><th class="corner rh"></th>${COLS.map(c => `<th data-c="${c}">${c}</th>`).join("")}</tr>`;
      for (let r = 0; r < ROWS; r++) h += `<tr><th class="rh">${r + 1}</th>${COLS.map((c, ci) => `<td data-k="${c}${r + 1}" data-c="${ci}" data-r="${r}"></td>`).join("")}</tr>`;
      tbl.innerHTML = h;
      const cell = k => tbl.querySelector(`td[data-k="${k}"]`);
      const paint = () => tbl.querySelectorAll("td").forEach(td => {
        if (td === editing) return;
        const k = td.dataset.k, v = evalCell(data, k);
        td.textContent = typeof v === "number" ? String(v).replace(".", ",") : v;
        td.classList.toggle("num", typeof v === "number");
        const f = fmt[k] || {}; td.style.fontWeight = f.b ? "700" : ""; td.style.fontStyle = f.i ? "italic" : ""; td.style.background = f.fill || "";
      });
      const inRange = (c, r) => { if (!anchor) return false; return c >= Math.min(anchor[0], cur[0]) && c <= Math.max(anchor[0], cur[0]) && r >= Math.min(anchor[1], cur[1]) && r <= Math.max(anchor[1], cur[1]); };
      const selCells = () => { const out = []; const a = anchor || cur; for (let c = Math.min(a[0], cur[0]); c <= Math.max(a[0], cur[0]); c++) for (let r = Math.min(a[1], cur[1]); r <= Math.max(a[1], cur[1]); r++) out.push(ref(c, r)); return out; };
      const show = () => {
        tbl.querySelectorAll("td.cur, td.rng").forEach(t => t.classList.remove("cur", "rng"));
        tbl.querySelectorAll("th.hl").forEach(t => t.classList.remove("hl"));
        tbl.querySelectorAll("td").forEach(td => { if (inRange(+td.dataset.c, +td.dataset.r)) td.classList.add("rng"); });
        const td = cell(ref(cur[0], cur[1])); td.classList.add("cur"); td.scrollIntoView({ block: "nearest", inline: "nearest" });
        tbl.querySelector(`th[data-c="${COLS[cur[0]]}"]`).classList.add("hl"); tbl.rows[cur[1] + 1].cells[0].classList.add("hl");
        const sc = selCells();
        nb.textContent = sc.length > 1 ? `${sc[0]}:${sc[sc.length - 1]}` : ref(cur[0], cur[1]);
        fx.value = data[ref(cur[0], cur[1])] || "";
        const nums = sc.map(k => evalCell(data, k)).filter(v => typeof v === "number");
        w.body.querySelector(".sm").textContent = nums.length > 1 ? `Moyenne : ${(nums.reduce((a, b) => a + b, 0) / nums.length).toFixed(2).replace(".", ",")}    Nombre : ${nums.length}    Somme : ${String(nums.reduce((a, b) => a + b, 0)).replace(".", ",")}` : "Prêt";
        SIM.emit("xlselect", sc);
      };
      const setVal = (k, v) => { if (v === "") delete data[k]; else data[k] = v; paint(); SIM.emit("cell", { k, v, data, val: evalCell(data, k) }); };
      const startEdit = (initial) => {
        const k = ref(cur[0], cur[1]), td = cell(k); editing = td;
        td.innerHTML = ""; const inp = $("input", "", td); inp.value = initial != null ? initial : (data[k] || "");
        inp.focus(); w.input = inp;
        const fin = (ok, move) => { if (editing !== td) return; editing = null; w.input = fx; if (ok) setVal(k, inp.value); else paint(); if (move) { cur = [Math.min(COLS.length - 1, Math.max(0, cur[0] + move[0])), Math.min(ROWS - 1, Math.max(0, cur[1] + move[1]))]; anchor = null; } show(); };
        inp.addEventListener("keydown", e => { e.stopPropagation(); if (e.key === "Enter") fin(true, [0, 1]); else if (e.key === "Tab") { e.preventDefault(); fin(true, [1, 0]); } else if (e.key === "Escape") fin(false); });
        inp.addEventListener("input", () => fx.value = inp.value);
        inp.addEventListener("blur", () => fin(true));
      };
      let dragging = false;
      tbl.addEventListener("pointerdown", e => {
        const td = e.target.closest("td"); if (!td || td === editing) return;
        if (editing) editing.querySelector("input").blur();
        const p = [+td.dataset.c, +td.dataset.r];
        if (e.shiftKey) { anchor = anchor || cur.slice(); cur = p; } else { anchor = null; cur = p; }
        dragging = true; show();
        if (e.pointerType === "mouse") e.preventDefault();
      });
      tbl.addEventListener("pointermove", e => { if (!dragging || e.pointerType !== "mouse") return; const td = document.elementFromPoint(e.clientX, e.clientY); if (!td || !td.dataset || td.dataset.c == null || td.tagName !== "TD") return; const p = [+td.dataset.c, +td.dataset.r]; if (p[0] !== cur[0] || p[1] !== cur[1]) { anchor = anchor || cur.slice(); cur = p; show(); } });
      addEventListener("pointerup", () => { if (dragging) { dragging = false; if (anchor) SIM.emit("xlrange", selCells()); } });
      tbl.addEventListener("dblclick", e => { if (e.target.closest("td")) startEdit(); });
      SIM.touchy(tbl, { open: e => { if (e.pointerType !== "mouse" && e.target.closest("td")) startEdit(); } });
      fx.addEventListener("keydown", e => { e.stopPropagation(); if (e.key === "Enter") { setVal(ref(cur[0], cur[1]), fx.value); cur[1] = Math.min(ROWS - 1, cur[1] + 1); show(); } });
      w.keys = e => {
        if (editing || document.activeElement === fx) return;
        const mv = { ArrowUp: [0, -1], ArrowDown: [0, 1], ArrowLeft: [-1, 0], ArrowRight: [1, 0], Enter: [0, 1], Tab: [1, 0] }[e.key];
        if (mv) { e.preventDefault(); if (e.shiftKey && e.key.startsWith("Arrow")) anchor = anchor || cur.slice(); else anchor = null; cur = [Math.min(COLS.length - 1, Math.max(0, cur[0] + mv[0])), Math.min(ROWS - 1, Math.max(0, cur[1] + mv[1]))]; show(); return; }
        if (e.key === "Delete" || e.key === "Backspace") { selCells().forEach(k => delete data[k]); paint(); show(); return; }
        if (e.ctrlKey && e.key.toLowerCase() === "s") { e.preventDefault(); save(); return; }
        if (e.key === "F2") { startEdit(); return; }
        if (e.key.length === 1 && !e.ctrlKey && !e.altKey) { e.preventDefault(); startEdit(e.key); }
      };
      const toggleFmt = p => { const sc = selCells(); const on = !(fmt[sc[0]] || {})[p]; sc.forEach(k => { fmt[k] = { ...(fmt[k] || {}), [p]: on }; }); paint(); SIM.emit("xlformat", p); };
      w.body.querySelector(".b").onclick = () => toggleFmt("b");
      w.body.querySelector(".i").onclick = () => toggleFmt("i");
      w.body.querySelector(".fill").onclick = () => { const sc = selCells(); const on = !(fmt[sc[0]] || {}).fill; sc.forEach(k => { fmt[k] = { ...(fmt[k] || {}), fill: on ? "#ffd966" : "" }; }); paint(); SIM.emit("xlformat", "fill"); };
      w.body.querySelector(".clr").onclick = () => { selCells().forEach(k => { delete data[k]; delete fmt[k]; }); paint(); show(); };
      const auto = fn => { // insère =SOMME(...) sous la plage ou la colonne au-dessus
        const sc = selCells(); let target, rng;
        if (sc.length > 1) { const a = parseRef(sc[0]), b = parseRef(sc[sc.length - 1]); target = ref(a[0], b[1] + 1); rng = `${sc[0]}:${sc[sc.length - 1]}`; }
        else { let r = cur[1] - 1; while (r >= 0 && typeof evalCell(data, ref(cur[0], r)) === "number") r--; if (r === cur[1] - 1) return SIM.toast("Somme automatique", "Sélectionnez d’abord des nombres."); target = ref(cur[0], cur[1]); rng = `${ref(cur[0], r + 1)}:${ref(cur[0], cur[1] - 1)}`; }
        setVal(target, `=${fn}(${rng})`); const p = parseRef(target); cur = p; anchor = null; show();
      };
      w.body.querySelector(".sum").onclick = () => auto("SOMME");
      w.body.querySelector(".avg").onclick = () => auto("MOYENNE");
      const save = async () => {
        if (file) { file.content = { ...data }; file.fmt = { ...fmt }; file.mtime = Date.now(); FS.save(); SIM.emit("save", file); SIM.toast("Enregistré", file.name, "ok"); }
        else { const n = await SIM.saveAs(w, "Classeur1", "xlsx", { ...data }); if (n) { file = n; file.fmt = { ...fmt }; FS.save(); w.setTitle(file.name.replace(/\.xlsx$/, "") + " - Excel"); } }
      };
      w.body.querySelector(".sv").onclick = save;
      w.setTitle(`${file ? file.name.replace(/\.xlsx$/, "") : "Classeur1"} - Excel`);
      w.input = fx;
      paint(); show();
    }
  };

  // ================================================================== PowerPoint (découverte)
  SIM.apps.ppt = { name: "PowerPoint", icon: "ppt", w: 860, h: 520, kw: "présentation diapositive",
    mount(w) {
      w.body.innerHTML = `<div class="ribtabs"><span>Fichier</span><span class="on" style="color:#c43e1c;border-color:#c43e1c">Accueil</span><span>Insertion</span><span>Création</span><span>Diaporama</span></div>
        <div style="flex:1;display:flex;min-height:0;background:#e9e9e9"><div class="thumbs" style="width:130px;overflow:auto;padding:8px;display:flex;flex-direction:column;gap:8px"></div>
        <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:12px"><div class="slide" contenteditable="true" style="width:min(100%,640px);aspect-ratio:16/9;background:#fff;box-shadow:0 1px 6px rgba(0,0,0,.2);padding:6% 8%;color:#222;outline:0;font:18px Calibri,Arial,sans-serif"></div></div></div>
        <div class="status"><span class="sn"></span><span class="cb add" style="margin-left:auto;height:20px">+ Nouvelle diapositive</span></div>`;
      const slides = [`<div style="font-size:34px;font-weight:600;text-align:center;margin-top:12%">Mon exposé</div><div style="text-align:center;color:#666;margin-top:12px">Cliquez pour écrire un sous-titre</div>`];
      let cur = 0; const slide = w.body.querySelector(".slide");
      const draw = () => { const t = w.body.querySelector(".thumbs"); t.innerHTML = ""; slides.forEach((s, i) => { const d = $("div", "", t, `<div style="aspect-ratio:16/9;background:#fff;border:2px solid ${i === cur ? "#c43e1c" : "#ccc"};font-size:5px;overflow:hidden;padding:4px">${s}</div>`); d.onclick = () => { slides[cur] = slide.innerHTML; cur = i; draw(); }; }); slide.innerHTML = slides[cur]; w.body.querySelector(".sn").textContent = `Diapositive ${cur + 1} sur ${slides.length}`; };
      w.body.querySelector(".add").onclick = () => { slides[cur] = slide.innerHTML; slides.push(`<div style="font-size:26px;font-weight:600">Titre de la diapositive</div><ul style="margin:16px 0 0 24px"><li>Premier point</li><li>Deuxième point</li></ul>`); cur = slides.length - 1; draw(); SIM.emit("ppt-slide"); };
      w.input = slide; draw();
    }
  };

  // ================================================================== Calculatrice
  SIM.apps.calc = { name: "Calculatrice", icon: "calc", w: 320, h: 470, single: true, kw: "calcul",
    mount(w) {
      w.body.innerHTML = `<div class="calc"><div class="cdisp"><div class="ex"></div><div class="v">0</div></div><div class="ckeys"></div></div>`;
      const K = ["%", "CE", "C", "⌫", "1/x", "x²", "√x", "÷", "7", "8", "9", "×", "4", "5", "6", "−", "1", "2", "3", "+", "±", "0", ",", "="];
      let v = "0", acc = null, op = null, fresh = false;
      const ex = w.body.querySelector(".ex"), vd = w.body.querySelector(".v");
      const num = () => parseFloat(v.replace(",", "."));
      const out = n => { const s = String(Math.round(n * 1e10) / 1e10); return s.replace(".", ","); };
      const apply = () => { if (op == null || acc == null) return num(); const b = num(); return op === "+" ? acc + b : op === "−" ? acc - b : op === "×" ? acc * b : b === 0 ? NaN : acc / b; };
      const press = k => {
        if (/^\d$/.test(k)) { v = fresh || v === "0" ? k : v + k; fresh = false; }
        else if (k === ",") { if (fresh) { v = "0"; fresh = false; } if (!v.includes(",")) v += ","; }
        else if (k === "C") { v = "0"; acc = null; op = null; ex.textContent = ""; }
        else if (k === "CE") v = "0";
        else if (k === "⌫") v = v.length > 1 ? v.slice(0, -1) : "0";
        else if (k === "±") v = v.startsWith("-") ? v.slice(1) : v === "0" ? v : "-" + v;
        else if (k === "%") v = out(num() / 100);
        else if (k === "x²") v = out(num() * num());
        else if (k === "√x") v = out(Math.sqrt(num()));
        else if (k === "1/x") v = out(1 / num());
        else if ("+−×÷".includes(k)) { acc = op && !fresh ? apply() : num(); op = k; fresh = true; ex.textContent = `${out(acc)} ${k}`; v = out(acc); }
        else if (k === "=") { if (op) { const r = apply(); ex.textContent = `${out(acc)} ${op} ${v} =`; v = isNaN(r) ? "Impossible de diviser par zéro" : out(r); SIM.emit("calc", { a: acc, op, r }); acc = null; op = null; fresh = true; } }
        vd.textContent = v; vd.style.fontSize = v.length > 12 ? "24px" : "";
      };
      const keys = w.body.querySelector(".ckeys");
      K.forEach(k => { const b = $("button", "ck" + ("+−×÷".includes(k) || /^[%C⌫1√x]/.test(k) && !/^\d$/.test(k) ? " op" : "") + (k === "=" ? " eq" : ""), keys, k); b.onclick = () => press(k); });
      w.keys = e => { const m = { "+": "+", "-": "−", "*": "×", "/": "÷", Enter: "=", "=": "=", ",": ",", ".": ",", Backspace: "⌫", Escape: "C" }; if (/^\d$/.test(e.key)) press(e.key); else if (m[e.key]) { e.preventDefault(); press(m[e.key]); } };
    }
  };

  // ================================================================== Paint
  SIM.apps.paint = { name: "Paint", icon: "paint", w: 820, h: 540, kw: "dessin",
    mount(w) {
      const cols = ["#000000", "#7f7f7f", "#c00000", "#ff6a00", "#ffd800", "#21a366", "#0067c0", "#7a5cd6", "#e24c8a", "#ffffff"];
      w.body.innerHTML = `<div class="paint"><div class="ptools">${cols.map((c, i) => `<span class="sw${i === 0 ? " on" : ""}" data-c="${c}" style="background:${c}"></span>`).join("")}
        <span class="vsep"></span><span class="rb sz" data-s="3">●</span><span class="rb sz on" data-s="7" style="font-size:20px">●</span><span class="rb sz" data-s="16" style="font-size:28px">●</span>
        <span class="vsep"></span><span class="rb er" title="Gomme">&#9003; Gomme</span><span class="rb cl" title="Tout effacer">Effacer</span><span class="rb sv" title="Enregistrer">&#128190; Enregistrer</span></div>
        <div class="pcan"><canvas></canvas></div></div>`;
      const cv = w.body.querySelector("canvas"), ctx = cv.getContext("2d"), host = w.body.querySelector(".pcan");
      let color = "#000000", size = 7, erase = false, drawing = false, last = null;
      const fit = () => { const r = host.getBoundingClientRect(); const W = Math.max(200, r.width - 24), H = Math.max(120, r.height - 24); if (cv.width === Math.round(W) && cv.height === Math.round(H)) return; const img = cv.width ? ctx.getImageData(0, 0, cv.width, cv.height) : null; cv.width = W; cv.height = H; ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, W, H); if (img) ctx.putImageData(img, 0, 0); };
      setTimeout(fit, 30); w.onResize = () => setTimeout(fit, 30);
      const pt = e => { const r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; };
      cv.addEventListener("pointerdown", e => { drawing = true; last = pt(e); cv.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.arc(last[0], last[1], size / 2, 0, 7); ctx.fillStyle = erase ? "#fff" : color; ctx.fill(); });
      cv.addEventListener("pointermove", e => { if (!drawing) return; const p = pt(e); ctx.strokeStyle = erase ? "#fff" : color; ctx.lineWidth = size; ctx.lineCap = "round"; ctx.beginPath(); ctx.moveTo(last[0], last[1]); ctx.lineTo(p[0], p[1]); ctx.stroke(); last = p; });
      cv.addEventListener("pointerup", () => { if (drawing) SIM.emit("draw"); drawing = false; });
      w.body.querySelectorAll(".sw").forEach(s => s.onclick = () => { color = s.dataset.c; erase = false; w.body.querySelectorAll(".sw").forEach(o => o.classList.toggle("on", o === s)); w.body.querySelector(".er").classList.remove("on"); });
      w.body.querySelectorAll(".sz").forEach(s => s.onclick = () => { size = +s.dataset.s; w.body.querySelectorAll(".sz").forEach(o => o.classList.toggle("on", o === s)); });
      w.body.querySelector(".er").onclick = e => { erase = !erase; e.currentTarget.classList.toggle("on", erase); };
      w.body.querySelector(".cl").onclick = () => { ctx.fillStyle = "#fff"; ctx.fillRect(0, 0, cv.width, cv.height); };
      w.body.querySelector(".sv").onclick = () => SIM.saveAs(w, "Mon dessin", "png", cv.toDataURL("image/png")).then(n => { if (n) { n.type = "img"; FS.save(); SIM.changed(n.parent); } });
    }
  };

  // ================================================================== Photos
  const NIGER = `<svg viewBox="0 0 400 250" width="100%" height="100%"><defs><linearGradient id="sk" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#ffb35c"/><stop offset="1" stop-color="#ffe3b0"/></linearGradient></defs>
    <rect width="400" height="250" fill="url(#sk)"/><circle cx="300" cy="90" r="34" fill="#fff4c2"/><path d="M0 150 Q100 135 200 150 T400 148 V250 H0z" fill="#3b82c4"/><path d="M0 165 Q120 158 240 170 T400 168" stroke="#9cc9f0" stroke-width="3" fill="none"/>
    <path d="M0 150 Q60 120 120 140 Q160 110 210 138 L210 150 Q100 140 0 156z" fill="#2f6b3a"/><path d="M40 150 l8-28 8 28z M150 146 l6-22 6 22z" fill="#1e4d2b"/><path d="M250 175 h60 l-8 10 h-44z" fill="#5a3a22"/><path d="M280 175 v-24 l14 18" stroke="#5a3a22" stroke-width="3" fill="none"/></svg>`;
  SIM.apps.photos = { name: "Photos", icon: "img", w: 700, h: 470, kw: "image",
    mount(w, o) {
      const n = o.file ? FS.get(o.file) : null;
      w.setTitle((n ? n.name : "Photos") + " - Photos");
      w.body.style.background = "#1b1b1b";
      const c = n && typeof n.content === "string" && n.content.startsWith("data:") ? `<img src="${n.content}" style="max-width:100%;max-height:100%">` : NIGER;
      w.body.innerHTML = `<div style="flex:1;display:flex;align-items:center;justify-content:center;padding:16px">${c}</div>`;
    }
  };

  // ================================================================== Clavier visuel
  const KEYS = [
    [["Échap", "Échap : annule ou ferme un menu."], ["²"], ["&", "1"], ["é", "2"], ["\"", "3"], ["'", "4"], ["(", "5"], ["-", "6"], ["è", "7"], ["_", "8"], ["ç", "9"], ["à", "0"], [")", "°"], ["=", "+"], ["⌫", "Retour arrière : efface le caractère à gauche du curseur.", 1.6]],
    [["Tab", "Tabulation : passe au champ suivant ou ajoute un retrait.", 1.4], ["a"], ["z"], ["e"], ["r"], ["t"], ["y"], ["u"], ["i"], ["o"], ["p"], ["^"], ["$"], ["Entrée", "Entrée : valide ou passe à la ligne suivante.", 1.4]],
    [["Verr.maj", "Verrouillage majuscules : tout s’écrit en MAJUSCULES.", 1.7], ["q"], ["s"], ["d"], ["f"], ["g"], ["h"], ["j"], ["k"], ["l"], ["m"], ["ù"], ["*"], ["Entrée", "Entrée : valide ou passe à la ligne suivante.", 1.1]],
    [["Maj", "Maj (Shift) : majuscule ou caractère du haut de la touche.", 1.3], ["<"], ["w"], ["x"], ["c"], ["v"], ["b"], ["n"], [",", "?"], [";", "."], [":", "/"], ["!", "§"], ["Maj", "Maj (Shift) : majuscule ou caractère du haut de la touche.", 2.2]],
    [["Ctrl", "Ctrl : raccourcis (Ctrl+C copier, Ctrl+V coller, Ctrl+Z annuler).", 1.3], ["⊞", "Touche Windows : ouvre le menu Démarrer.", 1.1], ["Alt", "Alt : raccourcis (Alt+Tab change de fenêtre).", 1.1], ["Espace", "Barre d’espace : ajoute une espace entre les mots.", 6], ["Alt Gr", "Alt Gr : 3e caractère (@, €, #…).", 1.1], ["◀"], ["▲"], ["▼"], ["▶"]]];
  SIM.apps.keyboard = { name: "Clavier visuel", icon: "keyboard", w: 860, h: 330, single: true, kw: "clavier touches osk",
    mount(w) {
      w.body.innerHTML = `<div class="osk"><div class="tip">Touchez une touche pour écrire dans la fenêtre active. Le nom et le rôle des touches spéciales s’affichent ici.</div></div>`;
      const box = w.body.querySelector(".osk"), tip = box.querySelector(".tip");
      let shift = false, caps = false; const els = [];
      KEYS.forEach(row => { const r = $("div", "kr", box); row.forEach(([k, alt, fl]) => {
        const special = k.length > 1 && !["²"].includes(k) || ["⊞", "⌫", "◀", "▲", "▼", "▶"].includes(k);
        const label = special ? k : alt && alt.length === 1 ? `<small style="opacity:.6;font-size:10px">${alt}</small><br>${k}` : k;
        const b = $("button", "kk" + (special ? " mod" : ""), r, label); if (fl) b.style.flex = fl;
        els.push([k, b]);
        b.addEventListener("pointerdown", e => e.preventDefault());
        b.onclick = () => {
          if (special && alt) tip.innerHTML = `<b>${esc(k)}</b> — ${esc(alt.split(" : ")[1] || alt)}`;
          if (k === "Maj") { shift = !shift; els.filter(x => x[0] === "Maj").forEach(x => x[1].classList.toggle("on", shift)); return; }
          if (k === "Verr.maj") { caps = !caps; b.classList.toggle("on", caps); return; }
          if (k === "⊞") { SIM.toggleFly("start"); return; }
          let ch = k === "Espace" ? " " : k === "Entrée" ? "\n" : k === "Tab" ? "\t" : !special ? ((shift || caps) && alt && alt.length === 1 && !/[a-zé]/.test(k) ? alt : (shift !== caps ? k.toUpperCase() : k)) : null;
          SIM.typeInto(ch, k);
          if (shift) { shift = false; els.filter(x => x[0] === "Maj").forEach(x => x[1].classList.remove("on")); }
          SIM.emit("osk", k);
        };
      }); });
      // reflète aussi les touches du vrai clavier
      w.flash = key => { const map = { " ": "Espace", Enter: "Entrée", Backspace: "⌫", Tab: "Tab", Shift: "Maj", Control: "Ctrl", Alt: "Alt", Escape: "Échap", CapsLock: "Verr.maj", ArrowLeft: "◀", ArrowRight: "▶", ArrowUp: "▲", ArrowDown: "▼", Meta: "⊞" };
        const k = map[key] || key.toLowerCase(); els.filter(x => x[0] === k).forEach(x => { x[1].classList.add("dn"); setTimeout(() => x[1].classList.remove("dn"), 160); });
        const sp = KEYS.flat().find(x => x[0] === map[key] && x[1]); if (sp) tip.innerHTML = `<b>${esc(sp[0])}</b> — ${esc(sp[1].split(" : ")[1] || sp[1])}`; };
    }
  };
  /** Écrit un caractère dans le champ actif de la dernière fenêtre utilisée (hors clavier visuel). */
  SIM.typeInto = (ch, key) => {
    const w = SIM.wins.slice().reverse().find(x => x.app !== "keyboard" && !x.minimized && x.input);
    if (!w) return SIM.toast("Clavier visuel", "Ouvrez d’abord le Bloc-notes ou Word pour écrire.");
    const el = w.input;
    if (el.isContentEditable) {
      el.focus();
      if (key === "⌫") document.execCommand("delete"); else if (key === "◀" || key === "▶") { const s = getSelection(); s.modify && s.modify("move", key === "◀" ? "backward" : "forward", "character"); }
      else if (ch === "\n") document.execCommand("insertParagraph"); else if (ch) document.execCommand("insertText", false, ch);
    } else {
      el.focus(); const a = el.selectionStart, b = el.selectionEnd;
      if (key === "⌫") { if (a === b && a > 0) el.setRangeText("", a - 1, b, "end"); else el.setRangeText("", a, b, "end"); }
      else if (key === "◀") el.setSelectionRange(Math.max(0, a - 1), Math.max(0, a - 1));
      else if (key === "▶") el.setSelectionRange(a + 1, a + 1);
      else if (ch) el.setRangeText(ch, a, b, "end");
    }
    el.dispatchEvent(new Event("input", { bubbles: true }));
  };

  // ================================================================== Navigateur (pages intégrées, sans Internet)
  const PAGES = {
    "accueil": () => `<div class="br-home"><h1>Rechercher sur le Web</h1><input class="br-q" placeholder="Tapez votre recherche, par exemple : apprendre Excel"><div class="tiles">
      ${[["help", "browser", "HELP-ME"], ["telechargement", "dl", "Logiciels"], ["emploi", "docs", "Offres d’emploi"], ["meteo", "img", "Météo"]].map(([p, ic, t]) => `<div class="tile" data-p="${p}"><div class="ic">${IC[ic]}</div>${t}</div>`).join("")}</div>
      <p style="margin-top:34px;color:#667386;font-size:13px">Simulateur : ces pages sont enregistrées dans l’application et fonctionnent sans connexion.</p></div>`,
    "help": () => `<div class="art"><h1>HELP-ME : apprendre l’informatique</h1><p>Bienvenue sur la page de démonstration. Un site web est un ensemble de pages reliées par des <a data-p="liens">liens</a>.</p><h2>Ce que vous pouvez faire ici</h2><p>• Taper une adresse dans la barre en haut.<br>• Faire une recherche depuis la page d’accueil.<br>• Télécharger un fichier depuis la page <a data-p="telechargement">Logiciels</a>.</p></div>`,
    "liens": () => `<div class="art"><h1>Les liens</h1><p>Un lien est souligné et le curseur prend la forme d’une main. Cliquez dessus pour ouvrir une autre page. Utilisez la flèche ← pour revenir en arrière.</p><p><a data-p="accueil">Retour à l’accueil</a></p></div>`,
    "telechargement": () => `<div class="art"><h1>Logiciels gratuits</h1><p>Téléchargez toujours un logiciel depuis son <b>site officiel</b>.</p><div class="dlbar"><div class="ic" style="width:36px;height:36px">${IC.txt}</div><div style="flex:1"><b>Guide du débutant.txt</b><br><small>Fichier texte · 2 Ko</small></div><button class="btn pri dlb">Télécharger</button></div>
      <div class="dlbar"><div class="ic" style="width:36px;height:36px">${IC.xlsx}</div><div style="flex:1"><b>Budget familial.xlsx</b><br><small>Classeur Excel · 8 Ko</small></div><button class="btn pri dlb2">Télécharger</button></div></div>`,
    "emploi": () => `<div class="art"><h1>Offres d’emploi (exemple)</h1><input class="br-q" style="height:38px;margin:10px 0" placeholder="Métier, mot clé…"><div class="dlbar"><div style="flex:1"><b>Assistant(e) administratif(ve)</b><br><small>Niamey · Word, Excel, messagerie</small></div></div><div class="dlbar"><div style="flex:1"><b>Technicien(ne) informatique</b><br><small>Maradi · Maintenance, réseaux</small></div></div><div class="dlbar"><div style="flex:1"><b>Secrétaire comptable</b><br><small>Zinder · Excel, facturation</small></div></div></div>`,
    "meteo": () => `<div class="art"><h1>Météo (exemple)</h1><p style="font-size:42px;margin:10px 0">☀️ 34 °C</p><p>Niamey — ensoleillé, vent faible.</p><p style="color:#667386">Données fictives pour l’exercice.</p></div>`,
  };
  SIM.apps.browser = { name: "Navigateur", icon: "browser", w: 900, h: 560, kw: "internet web edge chrome",
    mount(w) {
      w.body.innerHTML = `<div class="br-tabs"><div class="br-tab"><span class="ic" style="width:16px;height:16px">${IC.browser}</span><span class="tn">Nouvel onglet</span></div></div>
        <div class="br-bar"><div class="cb bk">${IC.back}</div><div class="cb fw">${IC.fwd}</div><div class="cb rf">${IC.refresh}</div><input class="br-url" spellcheck="false"></div><div class="br-page"></div>`;
      const page = w.body.querySelector(".br-page"), url = w.body.querySelector(".br-url");
      let hist = [], hi = -1;
      const titles = { accueil: "Nouvel onglet", help: "HELP-ME", liens: "Les liens", telechargement: "Logiciels gratuits", emploi: "Offres d’emploi", meteo: "Météo", recherche: "Résultats" };
      const go = (p, q, nohist) => {
        if (!nohist) { hist = hist.slice(0, hi + 1); hist.push([p, q]); hi = hist.length - 1; }
        if (p === "recherche") page.innerHTML = `<div class="art"><p style="color:#667386">Résultats pour « ${esc(q)} »</p>${[["help", "HELP-ME : apprendre l’informatique", "Cours de Word, Excel, Internet…"], ["telechargement", "Logiciels gratuits à télécharger", "Guides et modèles"], ["emploi", "Offres d’emploi", "Trouver un poste au Niger"]].map(([pp, t, d]) => `<div style="margin:16px 0"><a data-p="${pp}" style="font-size:18px">${t}</a><br><small style="color:#1e7a3c">www.${pp}.exemple</small><br>${d}</div>`).join("")}</div>`;
        else if (PAGES[p]) page.innerHTML = PAGES[p]();
        else page.innerHTML = `<div class="art" style="text-align:center;padding-top:60px"><h1>Hmm… impossible d’atteindre cette page</h1><p>Le simulateur ne se connecte pas à Internet. Essayez <a data-p="accueil">la page d’accueil</a>.</p></div>`;
        url.value = p === "accueil" ? "" : p === "recherche" ? `Recherche : ${q}` : `https://www.${p}.exemple`;
        w.body.querySelector(".tn").textContent = titles[p] || p; w.setTitle((titles[p] || p) + " - Navigateur");
        page.querySelectorAll("[data-p]").forEach(a => a.onclick = () => { go(a.dataset.p); SIM.emit("link", a.dataset.p); });
        const qin = page.querySelector(".br-q"); if (qin) qin.addEventListener("keydown", e => { if (e.key === "Enter" && qin.value.trim()) { go("recherche", qin.value.trim()); SIM.emit("websearch", qin.value); } });
        const dl = (sel, name, type, content) => { const b = page.querySelector(sel); if (b) b.onclick = () => { const n = FS.create("dl", name, type, content); SIM.toast("Téléchargement terminé", `${n.name} › Téléchargements`, "ok"); SIM.emit("download", n); }; };
        dl(".dlb", "Guide du débutant.txt", "txt", "Guide du débutant\r\n\r\n1. Allumer l’ordinateur.\r\n2. Ouvrir une application avec le menu Démarrer.\r\n3. Enregistrer souvent (Ctrl + S).");
        dl(".dlb2", "Budget familial.xlsx", "xlsx", { A1: "Dépense", B1: "Montant", A2: "Loyer", B2: "50000", A3: "Nourriture", B3: "40000", A4: "Transport", B4: "15000", A5: "Total", B5: "=SOMME(B2:B4)" });
        w.body.querySelector(".bk").classList.toggle("dis", hi <= 0); w.body.querySelector(".fw").classList.toggle("dis", hi >= hist.length - 1);
        SIM.emit("page", p);
      };
      url.addEventListener("keydown", e => { if (e.key !== "Enter") return; e.stopPropagation(); const v = url.value.trim().toLowerCase().replace(/^https?:\/\//, "").replace(/^www\./, "").replace(/\.(exemple|com|ne|fr|org)\/?$/, ""); if (!v) return; if (PAGES[v]) go(v); else if (v.includes(" ") || !v.includes(".")) { if (PAGES[v]) go(v); else { go("recherche", url.value.trim()); SIM.emit("websearch", url.value); } } else go(v); });
      w.body.querySelector(".bk").onclick = () => { if (hi > 0) { hi--; go(hist[hi][0], hist[hi][1], true); } };
      w.body.querySelector(".fw").onclick = () => { if (hi < hist.length - 1) { hi++; go(hist[hi][0], hist[hi][1], true); } };
      w.body.querySelector(".rf").onclick = () => go(hist[hi][0], hist[hi][1], true);
      w.input = url;
      go("accueil");
    }
  };

  // ================================================================== Paramètres
  const WALLS = [["bloom", "linear-gradient(135deg,#9ec5ff,#1a6cf0)"], ["sunset", "linear-gradient(160deg,#ffb35c,#e0567a 60%,#5b3a8c)"], ["sahel", "linear-gradient(180deg,#ffd9a0,#e8a35a 55%,#b9733a)"],
    ["forest", "linear-gradient(160deg,#a8e6a1,#2f8f5b 60%,#14532d)"], ["night", "linear-gradient(160deg,#1b2a4a,#0b1020)"], ["sky", "linear-gradient(180deg,#bfe6ff,#e9f6ff)"]];
  SIM.WALLS = WALLS;
  SIM.apps.settings = { name: "Paramètres", icon: "settings", w: 880, h: 560, single: true, kw: "réglages configuration fond d'écran thème",
    mount(w, o) {
      w.body.innerHTML = `<div class="set"><div class="set-nav"></div><div class="set-main"></div></div>`;
      const nav = w.body.querySelector(".set-nav"), main = w.body.querySelector(".set-main");
      const P = [["system", "pc", "Système"], ["perso", "paint", "Personnalisation"], ["taskbar", "settings", "Barre des tâches"], ["network", "browser", "Réseau et Internet"], ["time", "calc", "Heure et langue"], ["about", "info", "Informations système"]];
      const cfg = SIM.cfg;
      const tg = (on, fn) => { const t = $("div", "tg" + (on ? " on" : "")); t.onclick = () => { t.classList.toggle("on"); fn(t.classList.contains("on")); }; return t; };
      const row = (title, sub, ctrl) => { const r = $("div", "srow", main, `<div class="grow">${esc(title)}${sub ? `<small>${esc(sub)}</small>` : ""}</div>`); if (ctrl) r.appendChild(ctrl); return r; };
      const show = id => {
        nav.querySelectorAll(".li").forEach(l => l.classList.toggle("on", l.dataset.id === id));
        main.innerHTML = `<h2>${P.find(p => p[0] === id)[2]}</h2>`;
        if (id === "system") {
          const r = row("Luminosité", "Réglez la luminosité de l’écran"); const sl = $("input", "", r); sl.type = "range"; sl.min = 40; sl.max = 100; sl.value = cfg.bright; sl.oninput = () => { cfg.bright = +sl.value; SIM.applyCfg(); };
          row("Son", "Volume principal").appendChild(Object.assign($("input"), { type: "range", value: 60 }));
          row("Notifications", "Afficher les messages des applications", tg(true, () => 0));
          row("Stockage", "Disque local (C:) — 128 Go utilisés sur 256 Go");
        }
        if (id === "perso") {
          const r = row("Arrière-plan", "Choisissez un fond d’écran"); const ws = $("div", "walls", r);
          WALLS.forEach(([k, bg]) => { const d = $("div", k === cfg.wall ? "on" : "", ws); d.style.background = bg; d.onclick = () => { cfg.wall = k; SIM.applyCfg(); ws.querySelectorAll("div").forEach(x => x.classList.toggle("on", x === d)); SIM.emit("wallpaper", k); }; });
          row("Mode sombre", "Couleurs sombres pour les fenêtres et les menus", tg(cfg.dark, v => { cfg.dark = v; SIM.applyCfg(); SIM.emit("dark", v); }));
          const ac = row("Couleur d’accentuation", ""); const as = $("div", "accs", ac);
          ["#0067c0", "#c42b1c", "#0f7b0f", "#8e4ec6", "#c37000", "#2d7d9a"].forEach(c => { const i = $("i", "", as); i.style.background = c; i.onclick = () => { cfg.accent = c; SIM.applyCfg(); SIM.emit("accent", c); }; });
        }
        if (id === "taskbar") {
          row("Alignement de la barre des tâches", "Centre ou gauche (comme les anciennes versions)", tg(cfg.left, v => { cfg.left = v; SIM.applyCfg(); SIM.emit("tbalign", v); }));
          row("Masquer automatiquement la barre des tâches", "Elle réapparaît quand le pointeur touche le bas de l’écran", tg(cfg.hide, v => { cfg.hide = v; SIM.applyCfg(); SIM.emit("tbhide", v); }));
        }
        if (id === "network") { row("Wi-Fi", "Connecté — Réseau HELP-ME (simulation)", tg(true, v => SIM.toast("Wi-Fi", v ? "Activé" : "Désactivé"))); row("Mode avion", "Coupe toutes les connexions sans fil", tg(false, () => 0)); }
        if (id === "time") { row("Heure et date", new Date().toLocaleString("fr-FR")); row("Langue d’affichage", "Français (France)"); row("Disposition du clavier", "Français (AZERTY)"); }
        if (id === "about") { row("Nom de l’appareil", "PC-HELPME"); row("Processeur", "Simulateur HELP-ME — 4 cœurs"); row("Mémoire (RAM)", "8 Go"); row("Édition", "Windows 11 Famille (simulation)"); }
        SIM.emit("settings", id);
      };
      P.forEach(([id, ic, nm]) => { const l = $("div", "li", nav, `<span class="ic">${IC[ic]}</span><span>${nm}</span>`); l.dataset.id = id; l.onclick = () => show(id); });
      w.show = show; show(o.page || "system");
    },
    reopen(w, o) { if (o && o.page) w.show(o.page); }
  };
})();
