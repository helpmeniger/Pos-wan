#!/usr/bin/env python3
"""Exporte les cours de l'application HELP-ME à partir du manuel (et des compléments de l'ancienne application).

Entrées :
 - manuel/build/manuel.html                 (python3 manuel/tools/build.py)
 - app_update/knowledge/app_extras.json     (astuces, images et modèles repris de l'ancienne application)
Sorties :
 - app_android/app/src/main/assets/course.json : modules -> sections -> leçons -> blocs (texte, listes, tableaux,
   encadrés, exercices, figures) affichés nativement par LessonReaderActivity ;
 - app_android/app/src/main/res/drawable-nodpi/fig_NNN.webp : figures du manuel (captures annotées, schémas).

Usage : python3 app_update/tools/export_cours.py [--no-figures]
"""
import io
import json
import re
import subprocess
import sys
from pathlib import Path

from bs4 import BeautifulSoup, NavigableString

ROOT = Path(__file__).resolve().parents[2]
HTML = ROOT / "manuel" / "build" / "manuel.html"
APP = ROOT / "app_android" / "app" / "src" / "main"
OUT = APP / "assets" / "course.json"
FIG_DIR = APP / "res" / "drawable-nodpi"
EXTRAS = ROOT / "app_update" / "knowledge" / "app_extras.json"
LESSONS = APP / "assets" / "lessons.json"
TMP = ROOT / "manuel" / "build" / "_figs"

# Parties du manuel -> modules de l'application (ordre d'affichage, couleur, icône)
MODULES = [
    ("ordinateur", "L’ordinateur", "Comprendre l’ordinateur et ses composants", "#37474F", "ic_mod_computer", "traitement automatique"),
    ("windows", "Windows 11", "Le bureau, les fichiers et les réglages", "#0067C0", "ic_mod_windows", "windows"),
    ("internet", "Internet", "S’informer, communiquer et se protéger", "#0F8A5F", "ic_mod_internet", "internet"),
    ("word", "Word", "Rédiger et mettre en page des documents", "#2B579A", "ic_mod_word", "word"),
    ("excel", "Excel", "Calculer, analyser et représenter des données", "#1D6F42", "ic_mod_excel", "excel"),
    ("powerpoint", "PowerPoint", "Créer et présenter des diaporamas", "#C43E1C", "ic_mod_powerpoint", None),
    ("ia", "Intelligence artificielle", "Comprendre et bien utiliser l’IA", "#6A4FC2", "ic_mod_ai", "intelligence artificielle"),
    ("astuces", "Trucs et astuces", "Gagner du temps et s’exercer", "#D9822B", "ic_mod_tips", "astuces"),
    ("ressources", "Ressources", "Corrigés, raccourcis et glossaire", "#5C6B7A", "ic_mod_exercises", "corriges"),
]

KEEP_TAGS = {"b", "strong", "i", "em", "br", "sup", "sub"}


def norm(s):
    import unicodedata
    s = unicodedata.normalize("NFD", s.lower())
    return "".join(c for c in s if unicodedata.category(c) != "Mn")


def clean_text(t):
    t = re.sub(r"§§[A-Z]+\d+§§", "", t)
    t = re.sub(r"\s+", " ", t).strip()
    t = re.sub(r"\s+([.,;:)»!?])", lambda m: m.group(1) if m.group(1) in ".,)" else " " + m.group(1), t)
    return re.sub(r"([’'])\s+(?=\w)", r"\1", t.replace("( ", "("))


def rich(node):
    """HTML réduit (gras, italique, exposants) d'un nœud, pour Html.fromHtml côté Android."""
    out = []

    def walk(n):
        if isinstance(n, NavigableString):
            out.append(str(n).replace("<", "&lt;").replace(">", "&gt;"))
            return
        if "marker" in (n.get("class") or []) or "bc" in (n.get("class") or []):
            return
        if "blank" in (n.get("class") or []):
            out.append(" ______ ")
            return
        if "lvl" in (n.get("class") or []):
            return
        if n.name == "kbd":
            out.append("<b>")
            for c in n.children:
                walk(c)
            out.append("</b>")
            return
        tag = {"strong": "b", "em": "i"}.get(n.name, n.name)
        if tag in KEEP_TAGS:
            if tag == "br":
                out.append("<br>")
                return
            out.append(f"<{tag}>")
            for c in n.children:
                walk(c)
            out.append(f"</{tag}>")
        else:
            for c in n.children:
                walk(c)
    for c in (node.children if hasattr(node, "children") else [node]):
        walk(c)
    s = "".join(out)
    s = re.sub(r"§§[A-Z]+\d+§§", "", s)
    s = re.sub(r"\s+", " ", s).strip()
    s = re.sub(r"\s+([.,)])", r"\1", s)
    s = re.sub(r"([’'])\s+(?=\w)", r"\1", s)
    s = re.sub(r"<(b|i)>\s*</\1>", "", s)
    return s


class Figures:
    def __init__(self):
        self.items = []    # (id, element)

    def add(self, el):
        fid = f"fig_{len(self.items) + 1:03d}"
        el["data-figid"] = fid
        self.items.append(fid)
        return fid


def items_of(lst):
    return [rich(li) for li in lst.find_all("li", recursive=False)]


def blocks_of(el, figs):
    """Convertit un élément de premier niveau du manuel en blocs pour l'application."""
    name, cls = el.name, " ".join(el.get("class") or [])
    if name is None:
        return []
    if name == "span" or "end-part" in cls:
        return []
    if name == "h3":
        return [{"t": "h", "x": clean_text(re.sub(r"^\s*\d+(\.\d+)*\s*", "", el.get_text(" ")))}]
    if name == "h4":
        return [{"t": "h4", "x": clean_text(el.get_text(" "))}]
    if name == "p":
        x = rich(el)
        return [{"t": "note" if "muted" in cls else "p", "x": x}] if x else []
    if name in ("ul", "ol"):
        return [{"t": "ol" if name == "ol" else "ul", "items": items_of(el)}]
    if name == "table":
        head = [clean_text(th.get_text(" ")) for th in el.select("thead th")]
        rows = []
        for tr in el.select("tbody tr") or el.select("tr"):
            cells = [rich(td) for td in tr.find_all(["td", "th"])]
            if any(cells):
                rows.append(cells)
        return [{"t": "table", "head": head, "rows": rows}]
    if name in ("figure", "img") or (name == "div" and cls.startswith("row")):
        cap = el.find("figcaption")
        b = {"t": "fig", "img": figs.add(el)}
        if cap:
            b["cap"] = rich(cap)
        return [b]
    if name == "div":
        if "objectifs" in cls:
            return [{"t": "goals", "items": [rich(li) for li in el.select("li")]}]
        if cls.startswith("box"):
            t = el.select_one(".t")
            title = clean_text(t.get_text(" ")) if t else ""
            if t:
                t.extract()
            parts, inline = [], []

            def flush():
                x = rich(BeautifulSoup("<span>" + "".join(str(c) for c in inline) + "</span>", "html.parser").span)
                if x:
                    parts.append(x)
                inline.clear()
            for c in list(el.children):
                nm = getattr(c, "name", None)
                if nm in ("ul", "ol"):
                    flush()
                    parts += ["• " + x for x in items_of(c)]
                elif nm in ("p", "div", "table"):
                    flush()
                    x = rich(c)
                    if x:
                        parts.append(x)
                else:
                    inline.append(c)
            flush()
            kind = "warn" if "warn" in cls else "retenir" if "retenir" in cls else "info"
            return [{"t": "box", "k": kind, "title": title, "x": "<br>".join(parts)}]
        if cls == "ex":
            et = el.select_one(".et")
            lvl = et.select_one(".lvl") if et else None
            level = clean_text(lvl.get_text(" ")) if lvl else ""
            title = clean_text(et.get_text(" ").replace(level, "")) if et else "Exercice"
            if et:
                et.extract()
            sub = []
            for c in el.children:
                if getattr(c, "name", None):
                    sub += blocks_of(c, figs)
            return [{"t": "ex", "title": title, "lvl": level, "blocks": sub}]
        if cls == "prompt":
            lab = el.select_one(".lab")
            title = clean_text(lab.get_text(" ")) if lab else "Exemple"
            if lab:
                lab.extract()
            return [{"t": "prompt", "title": title, "x": rich(el)}]
        if cls == "formula":
            return [{"t": "code", "x": rich(el)}]
        if cls == "kv":
            sp = el.find_all("span", recursive=False)
            rows = [[rich(sp[i]), rich(sp[i + 1])] for i in range(0, len(sp) - 1, 2)]
            return [{"t": "table", "head": [], "rows": rows}]
        if cls == "grid2":
            cards = []
            for c in el.select(".card"):
                ct = c.select_one(".ct")
                title = rich(ct) if ct else ""
                if ct:
                    ct.extract()
                cards.append({"title": title, "x": rich(c)})
            return [{"t": "cards", "items": cards}]
        if cls == "glossaire":
            items = []
            for p in el.select("p"):
                b = p.find("b")
                term = clean_text(b.get_text(" ")) if b else ""
                if b:
                    b.extract()
                items.append({"term": term, "def": rich(p).lstrip(": ").strip()})
            return [{"t": "gloss", "items": items}]
        if cls == "answer":
            return [{"t": "p", "x": rich(el)}]
        out = []
        for c in el.children:
            if getattr(c, "name", None):
                out += blocks_of(c, figs)
        return out
    x = rich(el)
    return [{"t": "p", "x": x}] if x else []


def module_for(part_title):
    n = norm(part_title)
    if "corriges" in n:
        return "ressources"
    for m in MODULES:
        if m[5] and m[5] in n:
            return m[0]
    return None


def plain(blocks, n=220):
    """Résumé texte d'une leçon (premier paragraphe)."""
    for b in blocks:
        if b["t"] == "p":
            t = re.sub(r"<[^>]+>", "", b["x"])
            return t if len(t) <= n else t[:n].rsplit(" ", 1)[0] + "…"
    return ""


def render_figures(soup, figs):
    """Capture chaque figure du manuel (avec ses flèches et annotations) en WebP."""
    from PIL import Image
    TMP.mkdir(exist_ok=True)
    for f in TMP.glob("*.png"):
        f.unlink()
    page = TMP / "figs.html"
    css = ("@page{size:auto}body{width:520px;margin:0 auto;background:#fff}"
           "[data-figid] figcaption{display:none!important}[data-figid]{margin:0!important;break-inside:auto}")
    html = str(soup).replace("</head>", f"<style>{css}</style></head>", 1)
    page.write_text(html, encoding="utf-8")
    js = (f"const {{chromium}}=require('playwright');(async()=>{{const b=await chromium.launch();"
          f"const p=await b.newPage({{viewport:{{width:560,height:900}},deviceScaleFactor:2.4}});"
          f"await p.emulateMedia({{media:'print'}});"
          f"await p.goto('file://{page}',{{waitUntil:'load'}});await p.evaluate(()=>document.fonts.ready);"
          f"for(const e of await p.$$('[data-figid]')){{const id=await e.getAttribute('data-figid');"
          f"let t=e;const im=await e.$('img,svg');const n=await e.$$('img,svg');"
          f"if(n.length===1&&await e.evaluate(x=>x.tagName)==='FIGURE')t=im;"
          f"await t.scrollIntoViewIfNeeded();await t.screenshot({{path:'{TMP}/'+id+'.png'}});}}"
          f"await b.close();}})();")
    subprocess.run(["node", "-e", js], check=True)
    for old in FIG_DIR.glob("fig_*.webp"):
        old.unlink()
    total = 0
    for fid in figs.items:
        im = Image.open(TMP / f"{fid}.png").convert("RGB")
        if im.width > 1000:
            im = im.resize((1000, round(im.height * 1000 / im.width)), Image.LANCZOS)
        out = FIG_DIR / f"{fid}.webp"
        im.save(out, "WEBP", quality=74, method=6)
        total += out.stat().st_size
    return total


def strip_tags(s):
    import html as h
    return h.unescape(re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", s)).strip())


def tips_and_shortcuts(mods):
    """Astuces courtes (encadrés « Astuce », « Bon à savoir »…) et raccourcis clavier, pour l'écran Astuces."""
    tips, shortcuts, seen = [], [], set()
    for m in mods:
        for sec in m["sections"]:
            for l in sec["lessons"]:
                for b in l["blocks"]:
                    if b["t"] == "box" and b["k"] in ("info", "retenir") and 40 <= len(strip_tags(b["x"])) <= 420:
                        tips.append({"module": m["id"], "lesson": l["id"], "title": b["title"] or "Astuce", "x": b["x"]})
                    if b["t"] == "table" and "raccourci" in norm(l["title"]):
                        word = "word" in norm(l["title"])
                        for r in b["rows"]:
                            keys, act = strip_tags(r[0]), strip_tags(r[1]) if len(r) > 1 else ""
                            if not act or not re.match(r"^(Ctrl|Alt|Maj|Windows|F\d|Échap|Tab|Suppr)", keys):
                                continue
                            if not ("+" in keys or re.match(r"^F\d", keys)) or keys in seen:
                                continue
                            seen.add(keys)
                            ctx = "Word et Excel" if word else \
                                "Navigateur" if re.search(r"onglet|la page|navigat|favori|historique de navigation", norm(act)) else "Windows"
                            act = re.split(r" : | ; |\(", act)[0].strip().rstrip(".")
                            shortcuts.append({"keys": keys, "action": act[0].upper() + act[1:], "ctx": ctx, "lesson": l["id"]})
    return tips, shortcuts


def main():
    soup = BeautifulSoup(HTML.read_text(encoding="utf-8"), "html.parser")
    for m in soup.select(".marker"):
        m.decompose()
    figs = Figures()
    codes = {}
    for l in json.loads(LESSONS.read_text(encoding="utf-8")):
        codes.setdefault(norm(l["titre"]), []).append(l["code"])

    modules = {m[0]: {"id": m[0], "title": m[1], "subtitle": m[2], "color": m[3], "icon": m[4], "sections": []}
               for m in MODULES}
    for sec in soup.select("section.part"):
        h1 = sec.find_previous("h1")
        part = clean_text(h1.get_text(" ")) if h1 else ""
        mid = module_for(part)
        if not mid:
            continue
        lessons, cur = [], None
        for el in sec.find_all(recursive=False):
            if el.name == "h2" and "lesson" in (el.get("class") or []):
                title = clean_text(re.sub(r"^\s*\d+\.\s*", "", el.get_text(" ")))
                cur = {"id": f"{mid}-{len(lessons) + 1:02d}", "title": title, "blocks": []}
                cl = codes.get(norm(title))
                if cl:
                    cur["code"] = cl[0]          # code affiché
                    cur["codes"] = cl            # tous les codes-barres du chapitre (scan)
                lessons.append(cur)
            elif cur is not None:
                cur["blocks"] += blocks_of(el, figs)
        for l in lessons:
            l["summary"] = plain(l["blocks"])
            words = sum(len(re.sub(r"<[^>]+>", " ", json.dumps(b, ensure_ascii=False)).split()) for b in l["blocks"])
            l["minutes"] = max(2, round(words / 130))
            l["exercises"] = sum(1 for b in l["blocks"] if b["t"] == "ex")
        secs = modules[mid]["sections"]
        if secs and secs[0]["title"] == "Leçons du manuel":      # une partie peut s'étendre sur plusieurs sections
            for l in lessons:
                l["id"] = f"{mid}-{len(secs[0]['lessons']) + 1:02d}"
                secs[0]["lessons"].append(l)
        else:
            secs.append({"title": "Leçons du manuel", "lessons": lessons})

    # compléments repris de l'ancienne application (astuces rédigées, illustrations, modèles)
    if EXTRAS.exists():
        extras = json.loads(EXTRAS.read_text(encoding="utf-8"))
        for mid, sections in extras.items():
            for s in sections:
                for i, l in enumerate(s["lessons"]):
                    l.setdefault("id", f"{mid}-x{len(modules[mid]['sections'])}{i + 1:02d}")
                    l.setdefault("summary", plain(l.get("blocks", [])))
                    l.setdefault("minutes", 2)
                modules[mid]["sections"].append(s)

    size = 0
    if "--no-figures" not in sys.argv:
        size = render_figures(soup, figs)
    course = {"version": 1, "modules": [modules[m[0]] for m in MODULES]}
    course["tips"], course["shortcuts"] = tips_and_shortcuts(course["modules"])
    OUT.write_text(json.dumps(course, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    n = sum(len(s["lessons"]) for m in course["modules"] for s in m["sections"])
    print(f"{len(course['tips'])} astuces courtes, {len(course['shortcuts'])} raccourcis")
    print(f"{len(course['modules'])} modules, {n} leçons, {len(figs.items)} figures ({size // 1024} Ko) ; "
          f"course.json {OUT.stat().st_size // 1024} Ko")
    for m in course["modules"]:
        print(f"  {m['title']:28s} " + " | ".join(f"{s['title']} ({len(s['lessons'])})" for s in m["sections"]))


if __name__ == "__main__":
    main()
