#!/usr/bin/env python3
"""Génère la couverture (4e de couverture + 1re de couverture) du manuel, au format de la 1re édition (612 × 396 pt)."""
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LOGO = (ROOT / "src/ill/logo.svg").read_text(encoding="utf-8").replace('width="16mm" height="16mm"', 'width="17mm" height="17mm"')

TOMES = {
    "manuel": dict(num="", sub="MAÎTRISE PRATIQUE DE L’INFORMATIQUE :<br>Ordinateur, Windows, Internet, Traitement de texte,<br>Tableur et Intelligence artificielle",
                   pts=["Comprendre l’ordinateur et Windows", "Internet, e-mail, cloud et cybersécurité",
                        "Word et Excel pas à pas", "L’intelligence artificielle expliquée simplement",
                        "Astuces, exercices et corrigés"]),
}

CSS = """
@page { size: 612pt 396pt; margin: 0; }
* { box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
@font-face { font-family: 'Garamond HM'; src: url(../assets/fonts/eb-garamond-latin-400-normal.woff2); font-weight: 400; }\n@font-face { font-family: 'Garamond HM'; src: url(../assets/fonts/eb-garamond-latin-700-normal.woff2); font-weight: 700; }\nbody { margin: 0; font-family: 'Garamond HM', serif; }
.spread { width: 612pt; height: 396pt; display: flex; position: relative; overflow: hidden; break-after: page; }
.back { width: 306pt; height: 396pt; background: #fff; position: relative; }
.back .top { background: #21A045; color: #fff; height: 94pt; border-bottom-right-radius: 160pt 54pt; padding: 18pt 24pt 0; }
.back .top .hand { font: italic 600 21pt/1.1 'DejaVu Serif', serif; }
.back .tags { margin: 10pt 22pt 0; }
.back .tags span { display: inline-block; background: #FF0000; color: #fff; font-weight: 700; font-size: 9pt; padding: 2pt 8pt; margin: 0 0 4pt; border-left: 4pt solid #2E74B5; }
.back .ill { margin: 6pt 22pt 0; border: 1.5pt solid #FF0000; height: 76pt; background: url(../assets/img/cover_x40.png) center/cover; }
.back ul { margin: 7pt 22pt 0 34pt; padding: 0; font-size: 8.4pt; color: #1C2433; line-height: 1.45; }
.back li::marker { color: #21A045; }
.back .foot { position: absolute; left: 0; right: 0; bottom: 0; height: 74pt; border-top: 1pt solid #21A045; text-align: center; font-size: 7.6pt; color: #1C2433; padding-top: 5pt; line-height: 1.45; }
.isbnimg { display: block; margin: 4pt auto 0; height: 30pt; }\n.back .isbn { display: inline-block; margin-top: 4pt; border: .6pt dashed #94A3B8; padding: 3pt 10pt; font: 600 6.5pt 'DejaVu Sans Mono', monospace; color: #5B6678; }
.front { width: 306pt; height: 396pt; position: relative; background: #fff; border-left: 2pt solid #2E74B5; }
.front .corner { position: absolute; right: 0; top: 0; width: 64pt; height: 60pt; background: #2E74B5; border-bottom-left-radius: 38pt; display: flex; align-items: center; justify-content: center; }
.front .title { position: absolute; left: 0; right: 0; top: 30pt; text-align: center; }
.front .t1 { font: 900 25pt/1.02 'Inter Display', Inter, sans-serif; color: #FF0000; letter-spacing: .01em; text-shadow: 0 1.2pt 0 rgba(0,0,0,.18); }
.front .t2 { font: 400 11pt Inter, sans-serif; color: #2E74B5; letter-spacing: .25em; margin-top: 3pt; }
.front .tome { display: inline-block; margin-top: 7pt; background: #2E74B5; color: #fff; font: 800 8pt Inter, sans-serif; letter-spacing: .15em; padding: 2pt 10pt; border-radius: 10pt; }
.front .sub { font: 400 9pt/1.35 'Garamond HM', serif; color: #2E74B5; margin-top: 8pt; }
.front .photo { position: absolute; left: 50%; transform: translateX(-42%); top: 150pt; height: 172pt; }
.front .ai { position: absolute; left: 20pt; top: 160pt; width: 64pt; height: 64pt; border-radius: 50%; background: #6D35D6; color: #fff; text-align: center; font: 800 7pt/1.15 Inter, sans-serif; display: flex; align-items: center; justify-content: center; flex-direction: column; box-shadow: 0 2pt 6pt rgba(0,0,0,.2); }
.front .ai b { font-size: 17pt; line-height: 1; }
.front .bottom { position: absolute; left: 18pt; right: 18pt; bottom: 28pt; text-align: center; }
.front .bottom .m { font: 600 7.4pt Inter, sans-serif; color: #2E74B5; }
.front .bottom .ce { margin-top: 5pt; border: 1pt solid #FF0000; border-radius: 3pt; padding: 3pt; font: 400 11pt 'Garamond HM', serif; letter-spacing: .06em; color: #000; }
.front .ed { position: absolute; right: 0; bottom: 0; left: 0; height: 22pt; background: #2E74B5; color: #fff; font: 800 8.5pt Inter, sans-serif; letter-spacing: .2em; display: flex; align-items: center; justify-content: center; }
.front .ed b { background: #fff; color: #2E74B5; border-radius: 3pt; padding: 0 5pt; margin-right: 6pt; font-size: 11pt; letter-spacing: 0; }
"""


def spread(t):
    pts = "".join(f"<li>{p}</li>" for p in t["pts"])
    return f"""
<div class="spread">
  <div class="back">
    <div class="top"><div class="hand">Un manuel<br>pratique connecté</div></div>
    <div class="tags"><span>Le traitement automatique</span><br><span>avec l’ordinateur</span></div>
    <div class="ill"></div>
    <ul>{pts}<li>Chaque leçon est reliée à l’application <b>HELP-ME</b> par un code-barres</li></ul>
    <div class="foot"><b>©HELP-ME</b>, tous droits réservés.<br>☎ <b>+227</b> 92 08 12 82 / 87 22 52 02 / 96 42 00 80<br><span style="color:#2E74B5">bureau@helpme.it</span> · www.helpme.it<br><img class="isbnimg" src="../assets/img/cover_x35.png"></div>
  </div>
  <div class="front">
    <div class="corner">{LOGO}</div>
    <div class="title"><div class="t1">APPRENDRE<br>L’INFORMATIQUE</div><div class="t2">DE BUREAU</div>
      <div class="sub">{t["sub"]}</div></div>
    <img class="photo" src="../assets/img/cover_x11.png">
    
    <div class="bottom"><div class="ce">COURS ET EXERCICES</div></div>
    <div class="ed"><b>1</b>ÉDITION</div>
  </div>
</div>"""


def main():
    out = ROOT / "build"
    for k, t in TOMES.items():
        h = out / f"cover_{k}.html"
        h.write_text(f"<!doctype html><html lang='fr'><head><meta charset='utf-8'><style>{CSS}</style></head><body>{spread(t)}</body></html>", encoding="utf-8")
        pdf = out / "HELP-ME_Couverture.pdf"
        js = (f"const {{chromium}}=require('playwright');(async()=>{{const b=await chromium.launch();const p=await b.newPage();"
              f"await p.goto('file://{h}');await p.evaluate(()=>document.fonts.ready);"
              f"await p.pdf({{path:'{pdf}',preferCSSPageSize:true,printBackground:true}});await b.close();}})();")
        subprocess.run(["node", "-e", js], check=True, stderr=subprocess.DEVNULL)
        print(pdf.name)


if __name__ == "__main__":
    main()
