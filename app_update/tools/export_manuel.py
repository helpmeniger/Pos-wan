#!/usr/bin/env python3
"""Exporte le contenu du manuel HELP-ME vers les données de l'application.

Sorties :
 - app_android/.../assets/lessons.json  : une fiche par code-barres (65), pour l'écran affiché après le scan ;
 - app_update/knowledge/knowledge_manuel.json : une fiche par section du manuel, source de l'assistant
   (puis python3 app_update/tools/build_brain.py fabrique assets/brain.json).
"""
import json
import re
import unicodedata
from pathlib import Path
from bs4 import BeautifulSoup

ROOT = Path(__file__).resolve().parents[2]
HTML = ROOT / "manuel" / "build" / "manuel.html"
CODES = ROOT / "manuel" / "codes-helpme.json"
OUT = ROOT / "app_android" / "app" / "src" / "main" / "assets"
KNOW = ROOT / "app_update" / "knowledge"        # sources de l'assistant (build_brain.py en fait brain.json)

# Vidéos existantes de l'application (MainActivity8 d'origine)
VIDEOS = {"1000": "vid7", "2019": "vid1", "2018": "vid2", "2001": "vid3", "2002": "vid4", "2016": "vid6",
          "1001": "vid8", "2003": "telecharvid9", "2004": "emailvid", "2015": "combinaisonvid", "2005": "caracterevid",
          "1003": "puce", "1004": "bordure", "1006": "ramplacer", "1007": "pagegarde", "1008": "code2018",
          "1009": "filigranevid", "1010": "sectionvid", "1011": "tabetmotpassevid", "1012": "impression",
          "1013": "saisirdonnee", "1014": "copieexcel", "1015": "formuleexcel", "1016": "croiseexcel",
          "2014": "tableauexcel", "1017": "graphiqueexcel", "1018": "protectionetmotsexcel"}

# Démonstrations animées dessinées en code (remplacent une vidéo)
DEMOS = {"2017": "creer_organiser"}   # ancienne vidéo vid5.mp4

STOP = set("""le la les un une des de du d l et ou en au aux a à pour par sur dans avec sans ce cet cette ces se sa son ses
leur leurs que qui quoi est sont être avoir plus moins comme ne pas vous votre vos nous on il elle ils elles y
tout tous toute toutes faire peut peuvent si mais donc car aussi très bien""".split())


def clean(t):
    t = re.sub(r"§§[A-Z]+\d+§§", "", t)
    t = re.sub(r"\s+", " ", t).strip()
    t = re.sub(r"([’'])\s+(?=\w)", r"\1", t)
    return re.sub(r"\s+([.,;:)»!?])", lambda m: m.group(1) if m.group(1) in ".,)" else " " + m.group(1), t).replace("( ", "(")


def render(n):
    """Texte lisible pour l'application : listes en lignes numérotées, tableaux en « • a : b »."""
    name = n.name
    if name in ("ul", "ol"):
        items = [clean(li.get_text(" ")) for li in n.find_all("li", recursive=False)]
        return "\n".join((f"{i}. " if name == "ol" else "• ") + t for i, t in enumerate(items, 1) if t)
    if name == "table":
        rows = []
        for tr in n.select("tbody tr") or n.select("tr")[1:]:
            cells = [clean(td.get_text(" ")) for td in tr.find_all(["td", "th"])]
            cells = [c for c in cells if c]
            if cells and re.fullmatch(r"\d+", cells[0]) and len(cells) > 1:
                rows.append(f"{cells[0]}. " + " : ".join(cells[1:]))
            elif cells:
                rows.append("• " + cells[0] + (" : " + " ; ".join(cells[1:]) if len(cells) > 1 else ""))
        return "\n".join(rows)
    if name == "div" and n.select_one(".t"):
        t = n.select_one(".t")
        head = clean(t.get_text(" "))
        t.extract()
        return f"{head} : {clean(n.get_text(' '))}"
    return clean(n.get_text(" "))


def shorten(body, limit=900):
    if len(body) <= limit:
        return body
    cut = body[:limit]
    k = max(cut.rfind("\n"), cut.rfind(". "))
    return (cut[:k + 1] if k > limit * 0.5 else cut).rstrip() + " …"


def norm(w):
    w = unicodedata.normalize("NFD", w.lower())
    return "".join(c for c in w if unicodedata.category(c) != "Mn")


def keywords(text, n=12):
    words = re.findall(r"[A-Za-zÀ-ÿ0-9+#]{3,}", text)
    freq = {}
    for w in words:
        k = norm(w)
        if k in STOP or k.isdigit():
            continue
        freq[k] = freq.get(k, 0) + 1
    return [k for k, _ in sorted(freq.items(), key=lambda x: -x[1])[:n]]


def main():
    soup = BeautifulSoup(HTML.read_text(encoding="utf-8"), "html.parser")
    for m in soup.select(".marker"):
        m.decompose()
    codes = json.loads(CODES.read_text(encoding="utf-8"))["codes"]
    code_by_chapter = {}
    for c in codes:
        code_by_chapter.setdefault(c["chapitre"], []).append(c)

    lessons, knowledge = [], []
    part = ""
    for sec in soup.select("section.part"):
        prev = sec.find_previous("h1")
        part = clean(prev.get_text(" ")) if prev else part
        for h2 in sec.select("h2.lesson"):
            title = clean(h2.get_text(" "))
            # contenu de la leçon jusqu'au h2 suivant
            nodes = []
            for sib in h2.next_siblings:
                if getattr(sib, "name", None) == "h2":
                    break
                if getattr(sib, "name", None):
                    nodes.append(sib)
            subs, cur = [], {"titre": title, "texte": []}
            for n in nodes:
                if n.name == "h3":
                    subs.append(cur)
                    cur = {"titre": clean(n.get_text(" ")), "texte": []}
                elif n.name in ("p", "ul", "ol", "table", "div"):
                    t = render(n)
                    if t and not t.startswith("Objectifs"):
                        cur["texte"].append(t)
            subs.append(cur)
            first_p = next((clean(n.get_text(" ")) for n in nodes if n.name == "p" and clean(n.get_text(" "))), "")
            retenir = next((clean(n.get_text(" ")).replace("À retenir", "", 1).strip() for n in nodes
                            if n.name == "div" and "retenir" in (n.get("class") or [])), "")
            points = [s["titre"] for s in subs[1:]][:8]
            for c in code_by_chapter.get(title, []):
                lessons.append({"code": c["code"], "titre": re.sub(r"^\d+\.\s*", "", title), "partie": part,
                                "page": c["page"], "resume": first_p[:600], "points_cles": points,
                                "a_retenir": retenir[:500], "video": VIDEOS.get(c["code"]),
                                "demo": DEMOS.get(c["code"]),
                                "statut": c["statut"]})
            for s in subs:
                body = "\n".join(s["texte"]).strip()
                if len(body) < 60:
                    continue
                st = re.sub(r"^\d+(\.\d+)*\.?\s*", "", s["titre"])
                lt = re.sub(r"^\d+(\.\d+)*\.?\s*", "", title)
                qs = [st] + ([lt, f"{lt} : {st}"] if lt != st else [])
                codes_ch = [c["code"] for c in code_by_chapter.get(title, [])]
                knowledge.append({"intent": "manuel", "title": st, "chapter": lt,
                                  "part": re.sub(r"^\s*\w+\s+partie\s*", "", part, flags=re.I) or part,
                                  "question": qs,
                                  "tags": keywords(st + " " + st + " " + body),
                                  "answer": shorten(body),
                                  "code": codes_ch[0] if codes_ch else None,
                                  "image": None})
    lessons.sort(key=lambda x: x["page"])
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "lessons.json").write_text(json.dumps(lessons, ensure_ascii=False, indent=1), encoding="utf-8")
    KNOW.mkdir(parents=True, exist_ok=True)
    (KNOW / "knowledge_manuel.json").write_text(json.dumps(knowledge, ensure_ascii=False, indent=1), encoding="utf-8")
    print(len(lessons), "fiches leçons ;", len(knowledge), "fiches de connaissances ;",
          sum(1 for l in lessons if l["video"]), "avec vidéo,", sum(1 for l in lessons if l["demo"]), "avec démonstration animée")


if __name__ == "__main__":
    main()
