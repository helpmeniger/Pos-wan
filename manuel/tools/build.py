#!/usr/bin/env python3
"""Construit les PDF du manuel HELP-ME (2e édition).

Usage : python3 tools/build.py [tome1|tome2|all] [--fast]

Étapes :
 1. assemble les fichiers src/*.html d'un tome ;
 2. remplace les blocs <div class="scan" data-code=…> par le code-barres Code 39 ;
 3. génère le sommaire (2 passes pour les numéros de page) ;
 4. imprime en PDF avec Chromium (Playwright) ;
 5. relit chaque page, décode tous les codes-barres et écrit build/codes.json.
"""
import html
import io
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "src"
BUILD = ROOT / "build"
ASSETS = ROOT / "assets"
sys.path.insert(0, str(ROOT / "tools"))
import code39  # noqa: E402

TOMES = {
    "tome1": {
        "title": "Apprendre l’informatique de bureau — Tome 1",
        "files": ["t1_front.html", "t1_p1.html", "t1_p2.html", "t1_p3.html",
                  "t1_p4_ia.html", "t1_p5.html", "t1_annexes.html"],
        "pdf": "HELP-ME_Tome1_Informatique-Windows-Internet-IA_2e-edition.pdf",
    },
    "tome2": {
        "title": "Apprendre l’informatique de bureau — Tome 2",
        "files": ["t2_front.html", "t2_word.html", "t2_excel.html", "t2_annexes.html"],
        "pdf": "HELP-ME_Tome2_Word-Excel_2e-edition.pdf",
    },
}

PHONE = ('<svg class="phone" viewBox="0 0 26 40"><rect x="1" y="1" width="24" height="38" rx="4" '
         'fill="none" stroke="currentColor" stroke-width="2"/><rect x="5" y="7" width="16" height="22" rx="1" '
         'fill="currentColor" opacity=".15"/><path d="M7 14v-4h4M19 14v-4h-4M7 22v4h4M19 22v4h-4" fill="none" '
         'stroke="currentColor" stroke-width="1.6"/><path d="M8 18h10" stroke="#E0182D" stroke-width="1.4"/>'
         '<circle cx="13" cy="34" r="1.6" fill="currentColor"/></svg>')


def scan_block(code: str, title: str, kind: str) -> str:
    bars = code39.svg(code, module_mm=0.30, height_mm=8.5, quiet=10)
    label = {"existant": "Code de la 1re édition, inchangé",
             "nouveau": "Nouveau code, 2e édition"}[kind]
    return (
        f'<div class="scan" data-code="{code}">'
        f'<span style="color:var(--accent)">{PHONE}</span>'
        f'<div class="txt"><b>Scannez avec l’application HELP-ME</b>'
        f'{html.escape(title)}<br><span class="lt">Vidéo, explications et quiz de la leçon · {label}</span></div>'
        f'<div class="code">{bars}<div class="hr">*{code}*</div></div></div>'
    )


def inline_ill(m):
    name = m.group(1)
    p = SRC / "ill" / f"{name}.svg"
    svg = p.read_text(encoding="utf-8")
    svg = re.sub(r"<\?xml[^>]*>", "", svg)
    if 'class="' not in svg.split(">")[0]:
        svg = svg.replace("<svg", '<svg class="ill"', 1)
    return svg


def assemble(tome: str, toc_pages: dict | None):
    cfg = TOMES[tome]
    css = (SRC / "style.css").read_text(encoding="utf-8")
    body = "\n".join((SRC / f).read_text(encoding="utf-8") for f in cfg["files"] if (SRC / f).exists())
    body = re.sub(r"\{\{ill:([\w-]+)\}\}", inline_ill, body)

    codes = []

    def rep_scan(m):
        attrs = dict(re.findall(r'data-(\w+)="([^"]*)"', m.group(0)))
        code = attrs["code"]
        kind = attrs.get("kind", "existant")
        codes.append({"code": code, "titre": attrs.get("title", ""), "statut": kind})
        return scan_block(code, attrs.get("title", ""), kind)

    body = re.sub(r'<div class="scan"[^>]*></div>', rep_scan, body)

    # Sommaire : parties (h1 dans .opener) et leçons (h2.lesson)
    toc = []
    counter = [0]

    def mark(level):
        def f(m):
            counter[0] += 1
            tid = f"T{counter[0]:03d}"
            attrs, inner = m.group(1), m.group(2)
            label = re.sub(r"<[^>]+>", " ", inner)
            label = re.sub(r"\s+", " ", html.unescape(label)).strip().replace(" ’", "’")
            code = re.search(r'data-code="(\d+)"', attrs)
            alt = re.search(r'data-toc="([^"]*)"', attrs)
            if alt:
                label = alt.group(1)
            toc.append({"id": tid, "level": level, "label": label, "code": code.group(1) if code else ""})
            tagname = "h1" if level == 1 else "h2"
            return f'<{tagname}{attrs}><span class="marker">§§{tid}§§</span>{inner}</{tagname}>'
        return f

    def any_heading(m):
        if m.group(1) == "h1":
            return mark(1)(re.match(r"<h1(.*?)>(.*)</h1>", m.group(0), re.S))
        return mark(2)(re.match(r"<h2(.*?)>(.*)</h2>", m.group(0), re.S))

    body = re.sub(r'<(h1)\b(?=[^>]*\bdata-part\b)[^>]*>.*?</h1>|<(h2)\b(?=[^>]*class="lesson)[^>]*>.*?</h2>',
                  lambda m: any_heading(type("M", (), {"group": lambda self, i=0: m.group(0) if i == 0 else ("h1" if m.group(1) else "h2")})()),
                  body, flags=re.S)

    rows = []
    for t in toc:
        pg = str(toc_pages.get(t["id"], "")) if toc_pages else "000"
        cls = "tp" if t["level"] == 1 else "tl"
        code = f'<span class="code">{t["code"]}</span>' if t["code"] else ""
        rows.append(f'<div class="{cls}"><span class="lbl">{html.escape(t["label"])}</span>'
                    f'<span class="dots"></span><span class="pg">{pg}</span>{code}</div>')
    body = body.replace("{{TOC}}", '<div class="toc">' + "\n".join(rows) + "</div>")
    crow = []
    for t in toc:
        if t["code"]:
            pg = str(toc_pages.get(t["id"], "")) if toc_pages else "000"
            crow.append(f'<tr><td><b>{t["code"]}</b></td><td>{html.escape(t["label"])}</td><td class="c">{pg}</td></tr>')
    body = body.replace("{{CODES}}", '<table class="compact"><thead><tr><th>Code</th><th>Leçon</th><th class="c">Page</th></tr></thead><tbody>'
                        + "".join(crow) + "</tbody></table>")

    doc = (f'<!doctype html><html lang="fr"><head><meta charset="utf-8">'
           f'<title>{cfg["title"]}</title><base href="file://{ASSETS}/">'
           f'<style>{css}</style></head><body>{body}</body></html>')
    out = BUILD / f"{tome}.html"
    out.write_text(doc, encoding="utf-8")
    return out, toc, codes


def render(html_path: Path, pdf_path: Path):
    js = f"""
const {{chromium}} = require('playwright');
(async () => {{
  const b = await chromium.launch();
  const p = await b.newPage();
  await p.goto('file://{html_path}', {{waitUntil: 'load'}});
  await p.evaluate(() => document.fonts.ready);
  await p.pdf({{path: '{pdf_path}', preferCSSPageSize: true, printBackground: true}});
  await b.close();
}})();"""
    subprocess.run(["node", "-e", js], check=True, stderr=subprocess.DEVNULL)


def find_markers(pdf_path: Path):
    import pymupdf
    pages = {}
    doc = pymupdf.open(pdf_path)
    for i, page in enumerate(doc):
        for m in re.findall(r"§§(T\d{3})§§", page.get_text()):
            pages.setdefault(m, i + 1)
    return pages, doc.page_count


def verify_codes(pdf_path: Path):
    import pymupdf
    from PIL import Image
    from pyzbar.pyzbar import ZBarSymbol, decode
    found = []
    doc = pymupdf.open(pdf_path)
    for i, page in enumerate(doc):
        if "§§" not in page.get_text() and "*" not in page.get_text():
            continue
        img = Image.open(io.BytesIO(page.get_pixmap(dpi=300).tobytes("png")))
        for d in decode(img, symbols=[ZBarSymbol.CODE39]):
            found.append({"code": d.data.decode(), "page": i + 1})
    return found


def build(tome: str):
    BUILD.mkdir(exist_ok=True)
    pdf = BUILD / TOMES[tome]["pdf"]
    h, toc, codes = assemble(tome, None)
    render(h, pdf)
    pages, n = find_markers(pdf)
    h, toc, codes = assemble(tome, pages)
    render(h, pdf)
    pages2, n2 = find_markers(pdf)
    if pages2 != pages:
        print("  ! pagination modifiée entre les passes, 3e passe")
        h, toc, codes = assemble(tome, pages2)
        render(h, pdf)
    found = verify_codes(pdf)
    by_code = {}
    for f in found:
        by_code.setdefault(f["code"], []).append(f["page"])
    report = []
    ok = True
    for c in codes:
        pg = by_code.get(c["code"], [])
        if not pg:
            ok = False
        report.append({**c, "pages_pdf": sorted(set(pg))})
    print(f"{tome}: {n2} pages, {len(codes)} codes déclarés, "
          f"{len(found)} codes lus dans le PDF -> {'OK' if ok else 'ERREUR'}")
    for r in report:
        if not r["pages_pdf"]:
            print("  ! code non relu :", r["code"], r["titre"])
    return {"tome": tome, "pdf": pdf.name, "pages": n2, "codes": report,
            "sommaire": [{**t, "page": pages2.get(t["id"])} for t in toc]}


if __name__ == "__main__":
    which = sys.argv[1] if len(sys.argv) > 1 else "all"
    tomes = list(TOMES) if which == "all" else [which]
    results = [build(t) for t in tomes]
    if which == "all":
        (BUILD / "codes.json").write_text(json.dumps(results, ensure_ascii=False, indent=1), encoding="utf-8")
