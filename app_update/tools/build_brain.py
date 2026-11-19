#!/usr/bin/env python3
"""Fabrique la base de connaissances unique de l'assistant HELP-ME (hors ligne).

Entrées  : app_update/knowledge/ : knowledge_manuel.json (produit par export_manuel.py), knowledge_plus.json
           (fiches rédigées à la main), knowledge_pro.json, data_clean.json, data.json (bases d'origine de l'app)
Sortie   : app_android/app/src/main/assets/brain.json

Étapes :
 1. lecture des 4 sources, avec une priorité (manuel > pro > clean > data) ;
 2. nettoyage (questions artificielles, espaces, emojis de tête) ;
 3. classement par thème (word, excel, windows, internet, ia, ...) ;
 4. fusion des doublons : réponses presque identiques -> une seule fiche, questions réunies ;
 5. calcul des fiches voisines (suggestions « Voir aussi ») ;
 6. écriture d'un JSON compact.
"""
import json
import math
import re
import unicodedata
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ASSETS = ROOT / "app_android" / "app" / "src" / "main" / "assets"
SRC = ROOT / "app_update" / "knowledge"
OUT = ASSETS / "brain.json"

SOURCES = [("manuel", SRC / "knowledge_manuel.json", 5),
           ("plus", SRC / "knowledge_plus.json", 4),     # fiches rédigées pour combler les manques
           ("pro", SRC / "knowledge_pro.json", 3),
           ("clean", SRC / "data_clean.json", 2),
           ("data", SRC / "data.json", 1)]

STOP = set("""le la les un une des de du d l et ou en au aux a à pour par sur dans avec sans ce cet cette ces se sa son ses
leur leurs que qui quoi est sont etre avoir plus moins comme ne pas vous votre vos nous on il elle ils elles y je tu
me te mon ma mes ton ta tes tout tous toute toutes faire fait peut peuvent si mais donc car aussi tres bien comment
quel quelle quels quelles pourquoi quand ou c qu s n j m t est-ce ca cela ceci""".split())

TOPICS = [  # (thème, mots déclencheurs) : l'ordre compte, le premier trouvé l'emporte
    ("erreur", r"\b0x[0-9a-f]{4,}|message d'erreur|code d'erreur|code erreur|ecran bleu|ecran noir|plantage|crash|\bbug|ne demarre"),
    ("excel", r"excel|tableur|classeur|cellule|formule|somme|recherchev|tableau croise|feuille de calcul"),
    ("word", r"\bword\b|traitement de texte|paragraphe|mise en page|sommaire|en-tete|pied de page|publipostage|police"),
    ("powerpoint", r"powerpoint|\bppt\b|diapositive|diaporama|presentation|transition"),
    ("ia", r"intelligence artificielle|\bia\b|chatgpt|gemini|copilot|claude|prompt|machine learning|deep learning|\bai\b"),
    ("securite", r"securite|virus|antivirus|mot de passe|pirat|phishing|hameconnage|arnaque|vpn|pare-feu"),
    ("internet", r"internet|navigateur|web|e-mail|email|courriel|gmail|outlook|recherche google|wi-?fi|reseau|cloud|drive"),
    ("windows", r"windows|bureau|explorateur|dossier|fichier|menu demarrer|barre des taches|parametres|raccourci"),
    ("ordinateur", r"ordinateur|processeur|memoire|ram|disque|clavier|souris|ecran|peripherique|materiel|logiciel|unite centrale"),
    ("programmation", r"python|javascript|programm|coder|code source|developpeur"),
    ("carriere", r"metier|emploi|carriere|entrepren|business|client|argent|marketing|cv\b|reseaux sociaux|facebook|tiktok"),
    ("bien_etre", r"stress|motivation|confiance|emotion|fatigue|procrastin|patience|leadership|equipe|objectif|gratitude"),
]
SMALLTALK = re.compile(r"^(salutation|smalltalk)$")


def strip_accents(s):
    s = unicodedata.normalize("NFD", s)
    return "".join(c for c in s if unicodedata.category(c) != "Mn")


def norm(s):
    s = strip_accents((s or "").lower()).replace("’", "'")
    return re.sub(r"\s+", " ", re.sub(r"[^a-z0-9+#' -]", " ", s)).strip()


def words(s):
    return [w for w in re.findall(r"[a-z0-9+#]+", norm(s)) if len(w) > 1 and w not in STOP]


def tidy(s):
    s = (s or "").replace("\r", "")
    s = re.sub(r"[ \t]+", " ", s)
    s = re.sub(r"\n{3,}", "\n\n", s)
    return s.strip()


def natural(q):
    """Vrai si la question ressemble à une phrase (et non à une liste de mots-clés)."""
    w = q.split()
    return 2 <= len(w) <= 16 and "_" not in q and not re.fullmatch(r"[a-z0-9 ]+", q.strip())


def topic_of(intent, text):
    i = (intent or "").lower()
    if SMALLTALK.match(i):
        return "smalltalk"
    if i.startswith("error_") or i.endswith("_error"):
        return "erreur"
    for t, rx in TOPICS:
        if i.startswith(t) or i.startswith({"powerpoint": "ppt", "ia": "ai_"}.get(t, "\0")):
            return t
    n = norm(text)
    for t, rx in TOPICS:
        if re.search(rx, n):
            return t
    return "general"


PART_TOPIC = [("word", "word"), ("excel", "excel"), ("windows", "windows"), ("internet", "internet"),
              ("intelligence artificielle", "ia"), ("traitement automatique", "ordinateur")]


def manual_topic(d, short):
    """Fiches du manuel : le thème est celui de la partie (sauf astuces et annexes, classées d'après leur texte)."""
    part = norm(d["part"] or "")
    for key, t in PART_TOPIC:
        if key in part:
            return t
    return topic_of("", short)


def load():
    docs = []
    for src, path, prio in SOURCES:
        for x in json.loads(path.read_text(encoding="utf-8")):
            q = x.get("question")
            qs = q if isinstance(q, list) else ([q] if q else [])
            qs = [tidy(s.replace("_", " ")) for s in qs if s and s.strip()]
            # questions tronquées par un ancien « stemmer » (« octob » à côté de « octobre ») : supprimées
            full = " ".join(qs).lower()
            qs = [q for q in qs if " " in q or not re.search(r"\b" + re.escape(q.lower()) + r"[a-zà-ÿ]+", full)] or qs
            answer = tidy(x.get("answer", ""))
            if x.get("explanation"):
                answer += "\n\n" + tidy(x["explanation"])
            if not answer:
                continue
            if src == "manuel" and x.get("chapter"):      # le chapitre va dans les mots-clés, pas dans les questions
                qs = [q for q in qs if q != x["chapter"] and not q.startswith(x["chapter"] + " : ")] or qs
            # anciennes fiches « à quelle page se trouve… » : numéros de pages de l'ancien livre, devenus faux
            if re.search(r"se trouve (a|à) la section|pages? \d+ (a|à) \d+", answer) or \
                    any(re.search(r"(quelle|quel) page", q, re.I) for q in qs):
                continue
            title = x.get("title") or next((s for s in qs if natural(s)), None)
            d = {"src": src, "p": prio, "intent": x.get("intent") or "", "title": title, "q": qs,
                 "k": [t for t in (x.get("tags") or []) if t and t != "autre"], "a": answer,
                 "img": x.get("image") or None, "code": x.get("code"), "ch": x.get("chapter"), "part": x.get("part")}
            short = " ".join(qs) + " " + " ".join(d["k"])
            d["t"] = (manual_topic(d, short) if src == "manuel" else
                      d["intent"] if src == "plus" else topic_of(d["intent"], short))
            if d["t"] == "general":
                d["t"] = topic_of(d["intent"], short + " " + answer[:300])
            # réactions de conversation (« Absolument ! … », « Bye ! … ») déclenchées par un ou deux mots
            if src not in ("manuel", "plus") and d["t"] in ("general", "bien_etre") and all(len(q.split()) <= 4 for q in qs) \
                    and re.match(r"^[^.?]{0,40}!", answer) and len(answer) < 260:
                d["t"] = "smalltalk"
            # définitions de glossaire : « api » -> « Interface permettant ... »
            if src not in ("manuel", "plus") and len(answer) < 160 and qs and all(len(q.split()) <= 3 for q in qs) \
                    and d["t"] != "smalltalk":
                d["f"] = "def"
            docs.append(d)
    return docs


def prune(qs):
    """Retire les questions d'un seul mot déjà contenues (ou tronquées) dans une question plus longue."""
    longs = strip_accents(" ".join(q.lower() for q in qs if " " in q.strip()))
    out = [q for q in qs if " " in q.strip() or not re.search(r"\b" + re.escape(strip_accents(q.lower().strip())), longs)]
    return out or qs


def dedup(docs):
    """Fusionne les fiches dont les réponses sont quasi identiques (Jaccard >= 0,7 sur les mots)."""
    sig = [set(words(d["a"])) for d in docs]
    buckets = defaultdict(list)
    for i, s in enumerate(sig):
        for w in sorted(s, key=lambda w: (len(w) < 5, w))[:3]:
            buckets[w].append(i)
    parent = list(range(len(docs)))

    def find(i):
        while parent[i] != i:
            parent[i] = parent[parent[i]]
            i = parent[i]
        return i
    checked = set()
    for ids in buckets.values():
        if len(ids) > 400:
            continue
        for a in range(len(ids)):
            for b in range(a + 1, len(ids)):
                i, j = ids[a], ids[b]
                if (i, j) in checked:
                    continue
                checked.add((i, j))
                si, sj = sig[i], sig[j]
                if not si or not sj:
                    continue
                if len(si & sj) / len(si | sj) >= 0.7:
                    parent[find(j)] = find(i)
    groups = defaultdict(list)
    for i in range(len(docs)):
        groups[find(i)].append(docs[i])
    merged = []
    for g in groups.values():
        g.sort(key=lambda d: (-d["p"], -len(d["a"])))
        best = dict(g[0])
        qs, ks = list(best["q"]), list(best["k"])
        for o in g[1:]:
            qs += [q for q in o["q"] if q not in qs]
            ks += [k for k in o["k"] if k not in ks]
            best["img"] = best["img"] or o["img"]
            best["title"] = best["title"] or o["title"]
        best["q"], best["k"] = prune(qs)[:24], ks[:20]
        merged.append(best)
    return merged, len(docs) - len(merged)


def related(docs, n=3):
    """Pour chaque fiche, les n fiches les plus proches du même thème (TF-IDF sur titre, questions, mots-clés)."""
    vecs = []
    df = Counter()
    for d in docs:
        c = Counter(words(" ".join([d["title"] or ""] * 2 + d["q"] + d["k"])))
        vecs.append(c)
        df.update(c.keys())
    N = len(docs)
    w = []
    for c in vecs:
        v = {t: (1 + math.log(f)) * math.log(N / df[t]) for t, f in c.items()}
        nrm = math.sqrt(sum(x * x for x in v.values())) or 1
        w.append({t: x / nrm for t, x in v.items()})
    by_topic = defaultdict(list)
    for i, d in enumerate(docs):
        if d["title"] and d["t"] != "smalltalk":
            by_topic[d["t"]].append(i)
    for i, d in enumerate(docs):
        cands = []
        for j in by_topic.get(d["t"], []):
            if j == i:
                continue
            s = sum(x * w[j].get(t, 0) for t, x in w[i].items())
            if s > 0.12:
                cands.append((s, j))
        cands.sort(reverse=True)
        d["rel"] = [j for _, j in cands[:n]]


def drawables():
    """Noms des images présentes dans l'application (les autres noms d'image des anciennes bases sont ignorés)."""
    res = ROOT / "app_android" / "app" / "src" / "main" / "res"
    return {f.stem.split(".")[0] for d in res.iterdir() if d.name.startswith(("drawable", "mipmap")) for f in d.iterdir()}


def main():
    have = drawables()
    docs = load()
    for d in docs:
        if d["img"] and d["img"] not in have:
            d["img"] = None
    n0 = len(docs)
    docs, merged = dedup(docs)
    # manuel d'abord, puis par priorité, pour des identifiants stables et lisibles
    docs.sort(key=lambda d: (-d["p"], d["t"], d["title"] or "~"))
    related(docs)
    out = []
    for i, d in enumerate(docs):
        e = {"id": i, "t": d["t"], "q": d["q"], "a": d["a"], "p": d["p"]}
        for k in ("title", "img", "code", "ch", "part", "f"):
            if d.get(k):
                e[{"title": "ti"}.get(k, k)] = d[k]
        if d["k"]:
            e["k"] = d["k"]
        if d.get("rel"):
            e["rel"] = d["rel"]
        out.append(e)
    OUT.write_text(json.dumps({"version": 1, "docs": out}, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"{n0} fiches lues -> {len(out)} fiches ({merged} doublons fusionnés) ; "
          f"{OUT.stat().st_size // 1024} Ko ; thèmes : {dict(Counter(d['t'] for d in out).most_common())}")


if __name__ == "__main__":
    main()
