#!/usr/bin/env python3
"""Construit le manuel HELP-ME (un seul volume, format A5).

Usage : python3 tools/build.py

Étapes :
 1. assemble src/m*.html dans l'ordre ;
 2. numérote les chapitres (1., 2.…) et les sous-titres (1.1, 1.2…) comme dans l'original ;
 3. génère le sommaire (2 passes pour les numéros de page) ;
 4. imprime en PDF avec Chromium ;
 5. place chaque code-barres Code 39 à la VERTICALE, EN HAUT, dans le coin extérieur de la page
    qui contient son contenu (comme dans le fichier original), sans numéro ni texte ;
 6. relit tous les codes dans le PDF final et écrit build/codes.json.
"""
import html
import io
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC, BUILD, ASSETS = ROOT / "src", ROOT / "build", ROOT / "assets"
sys.path.insert(0, str(ROOT / "tools"))
import code39  # noqa: E402

FILES = ["m0_front.html", "m1_ordinateur.html", "m2_windows.html", "m3_internet.html", "m4_astuces.html",
         "m5_word.html", "m6_excel.html", "m7_ia.html", "m9_annexes.html"]
TITLE = "Apprendre l’informatique de bureau"
PDF = BUILD / "HELP-ME_Apprendre-l-informatique-de-bureau_Manuel.pdf"
MM = 72 / 25.4
# Code-barres : 9 mm de large, ~31 mm de haut, à 3,7 mm du bord et 7 mm du haut (proportions de l'original en A5)
BC_MODULE_MM, BC_WIDTH_MM, BC_EDGE_MM, BC_TOP_MM, BC_QUIET = 0.27, 9.0, 3.7, 7.0, 10


def inline_ill(m):
    svg = (SRC / "ill" / f"{m.group(1)}.svg").read_text(encoding="utf-8")
    svg = re.sub(r"<\?xml[^>]*>", "", svg)
    if 'class="' not in svg.split(">")[0]:
        svg = svg.replace("<svg", '<svg class="ill"', 1)
    return svg


def number_headings(body):
    """Numérote h2.lesson (1., 2.…) et h3 (1.1, 1.2…) dans chaque <section class="part">."""
    def do_section(m):
        cls, inner = m.group(1), m.group(2)
        if cls != "part":
            return m.group(0)
        n = [0, 0]

        def rep(mm):
            if mm.group(1):
                n[0] += 1; n[1] = 0
                return f'<h2{mm.group(1)}><span class="n">{n[0]}.</span> {mm.group(2)}</h2>'
            n[1] += 1
            return f'<h3{mm.group(3)}><span class="n">{n[0]}.{n[1]}</span> {mm.group(4)}</h3>'
        inner = re.sub(r'<h2((?=[^>]*class="lesson)[^>]*)>(.*?)</h2>|<h3([^>]*)>(.*?)</h3>', rep, inner, flags=re.S)
        return f'<section class="{cls}">{inner}</section>'
    return re.sub(r'<section class="([^"]+)">(.*?)</section>', do_section, body, flags=re.S)


def assemble(toc_pages):
    css = (SRC / "style.css").read_text(encoding="utf-8")
    body = "\n".join((SRC / f).read_text(encoding="utf-8") for f in FILES)
    body = re.sub(r"\{\{ill:([\w-]+)\}\}", inline_ill, body)
    body = number_headings(body)

    # Codes-barres : repères invisibles
    codes = []

    def rep_bc(m):
        attrs = dict(re.findall(r'data-(\w+)="([^"]*)"', m.group(0)))
        idx = len(codes)
        codes.append({"code": attrs["code"], "statut": "1re version (conservé)" if attrs.get("orig") else "nouveau", "idx": idx})
        return f'<span class="marker">§§BC{idx:03d}§§</span>'
    body = re.sub(r'<span class="bc"[^>]*></span>', rep_bc, body)

    # Sommaire
    toc, counter = [], [0]

    def mark(level, tag):
        def f(attrs, inner):
            counter[0] += 1
            tid = f"T{counter[0]:03d}"
            label = re.sub(r"<[^>]+>", " ", inner)
            label = re.sub(r"\s+", " ", html.unescape(label)).strip()
            alt = re.search(r'data-toc="([^"]*)"', attrs)
            if alt:
                label = html.unescape(alt.group(1))
            code = re.search(r'data-code="(\d+)"', attrs)
            toc.append({"id": tid, "level": level, "label": label, "code": code.group(1) if code else ""})
            return f'<{tag}{attrs}><span class="marker">§§{tid}§§</span>{inner}</{tag}>'
        return f

    def heading(m):
        if m.group(1):
            return mark(1, "h1")(m.group(2), m.group(3))
        return mark(2, "h2")(m.group(5), m.group(6))
    body = re.sub(r'<(h1)\b((?=[^>]*\bdata-part\b)[^>]*)>(.*?)</h1>|<(h2)\b((?=[^>]*class="lesson)[^>]*)>(.*?)</h2>',
                  heading, body, flags=re.S)

    rows = []
    for t in toc:
        pg = str(toc_pages.get(t["id"], "")) if toc_pages else "000"
        cls = "tp" if t["level"] == 1 else "tl"
        rows.append(f'<div class="{cls}"><span class="lbl">{html.escape(t["label"])}</span>'
                    f'<span class="dots"></span><span class="pg">{pg}</span></div>')
    body = body.replace("{{TOC}}", '<div class="toc">' + "\n".join(rows) + "</div>")

    doc = (f'<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>{TITLE}</title>'
           f'<base href="file://{ASSETS}/"><style>{css}</style></head><body>{body}</body></html>')
    out = BUILD / "manuel.html"
    out.write_text(doc, encoding="utf-8")
    return out, toc, codes


def render(html_path, pdf_path):
    js = (f"const {{chromium}}=require('playwright');(async()=>{{const b=await chromium.launch();const p=await b.newPage();"
          f"await p.goto('file://{html_path}',{{waitUntil:'load'}});await p.evaluate(()=>document.fonts.ready);"
          f"await p.pdf({{path:'{pdf_path}',preferCSSPageSize:true,printBackground:true}});await b.close();}})();")
    subprocess.run(["node", "-e", js], check=True, stderr=subprocess.DEVNULL)


def check_overflow(html_path):
    """Refuse la fabrication si un élément dépasse la largeur utile (Chromium réduirait tout le document)."""
    js = (f"const {{chromium}}=require('playwright');(async()=>{{const b=await chromium.launch();"
          f"const p=await b.newPage({{viewport:{{width:438,height:800}}}});await p.emulateMedia({{media:'print'}});"
          f"await p.goto('file://{html_path}');await p.evaluate(()=>document.fonts.ready);"
          f"const r=await p.evaluate(()=>{{const W=document.documentElement.clientWidth;const o=[];"
          f"for(const e of document.querySelectorAll('body *')){{if(e.closest('.opener')||e.closest('svg'))continue;"
          f"const rc=e.getBoundingClientRect();if(rc.right>W+1)o.push(e.tagName+': '+(e.textContent||'').slice(0,70).replace(/\\s+/g,' '))}}return o}});"
          f"console.log(JSON.stringify(r));await b.close();}})();")
    out = subprocess.run(["node", "-e", js], capture_output=True, text=True, check=True).stdout
    bad = json.loads(out.strip().splitlines()[-1])
    if bad:
        print("ERREUR : éléments trop larges (le document serait réduit) :")
        for b in bad[:10]:
            print("  -", b)
        sys.exit(1)


def find_markers(pdf_path):
    import pymupdf
    pages = {}
    doc = pymupdf.open(pdf_path)
    for i, page in enumerate(doc):
        for m in re.findall(r"§§((?:T|BC)\d{3})§§", page.get_text()):
            pages.setdefault(m, i + 1)
    return pages, doc.page_count


def stamp_barcodes(pdf_path, codes, pages):
    """Dessine chaque code-barres à la verticale dans le coin supérieur extérieur de sa page."""
    import pymupdf
    doc = pymupdf.open(pdf_path)
    per_page = {}
    for c in codes:
        pg = pages.get(f"BC{c['idx']:03d}")
        c["page"] = pg
        per_page.setdefault(pg, []).append(c)
    for pg, lst in per_page.items():
        page = doc[pg - 1]
        W = page.rect.width
        right = pg % 2 == 1                       # page impaire = page de droite = coin droit
        seen = []
        for k, c in enumerate(x for x in lst if x["code"] not in seen and not seen.append(x["code"])):
            seq = code39.modules(c["code"])
            m = BC_MODULE_MM * MM
            length = (sum(w for w, _ in seq) + 2 * BC_QUIET) * m
            w = BC_WIDTH_MM * MM
            x0 = W - (BC_EDGE_MM * MM) - w if right else BC_EDGE_MM * MM
            y0 = BC_TOP_MM * MM + k * (length + 3 * MM)
            shape = page.new_shape()
            shape.draw_rect(pymupdf.Rect(x0, y0, x0 + w, y0 + length))
            shape.finish(fill=(1, 1, 1), color=None, width=0)
            y = y0 + BC_QUIET * m
            for wd, bar in seq:
                if bar:
                    shape.draw_rect(pymupdf.Rect(x0, y, x0 + w, y + wd * m))
                y += wd * m
            shape.finish(fill=(0, 0, 0), color=None, width=0)
            shape.commit()
    doc.save(pdf_path, incremental=True, encryption=pymupdf.PDF_ENCRYPT_KEEP)


def verify(pdf_path, dpi=150):
    import pymupdf
    from PIL import Image
    from pyzbar.pyzbar import ZBarSymbol, decode
    found = {}
    doc = pymupdf.open(pdf_path)
    for i, page in enumerate(doc):
        clip = pymupdf.Rect(0, 0, page.rect.width, 110 * MM)
        img = Image.open(io.BytesIO(page.get_pixmap(dpi=dpi, clip=clip).tobytes("png")))
        for d in decode(img, symbols=[ZBarSymbol.CODE39]):
            found.setdefault(d.data.decode(), set()).add(i + 1)
    return found


def main():
    BUILD.mkdir(exist_ok=True)
    tmp = BUILD / "_pass.pdf"
    h, toc, codes = assemble(None)
    check_overflow(h)
    render(h, tmp)
    pages, n = find_markers(tmp)
    for _ in range(3):
        h, toc, codes = assemble(pages)
        render(h, tmp)
        pages2, n = find_markers(tmp)
        if pages2 == pages:
            break
        pages = pages2
    stamp_barcodes(tmp, codes, pages)
    tmp.replace(PDF)
    found = verify(PDF)
    ok = True
    for c in codes:
        if c["page"] not in found.get(c["code"], set()):
            ok = False
            print("  ! code non relu :", c["code"], "page", c["page"])
    titles = {t["code"]: t["label"] for t in toc if t["code"]}
    report = [{"code": c["code"], "page": c["page"], "statut": c["statut"], "lecon": titles.get(c["code"], "")} for c in codes]
    (BUILD / "codes.json").write_text(json.dumps(report, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"{PDF.name}: {n} pages, {len(codes)} codes placés, {len(found)} codes distincts relus -> {'OK' if ok else 'ERREUR'}")


if __name__ == "__main__":
    main()
