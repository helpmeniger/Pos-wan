#!/usr/bin/env python3
"""Horaires des phrases pour les leçons animées (assets/anim/lessons/timings.js).

Pour chaque leçon : la voix (audio/<code>.m4a) est découpée sur ses pauses et chaque phrase du texte lu
reçoit son intervalle [début, fin] (même méthode que montage.py). Sans audio, la leçon utilise la voix
de synthèse du téléphone et des durées estimées.

Usage : python3 anim_timings.py
"""
import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
from montage import align, speech_segments  # noqa: E402

ROOT = HERE.parents[2]
ANIM = ROOT / "app_android" / "app" / "src" / "main" / "assets" / "anim"
LESSONS = {"2019": "vid1", "2018": "vid2", "2001": "vid3", "2002": "vid4", "2025": "vid5",
           "2016": "vid6", "1000": "vid7", "1001": "vid8", "1011": "tabetmotpassevid",
           "1006": "ramplacer", "1010": "sectionvid", "1013": "saisirdonnee", "2003": "telecharvid9", "2014": "tableauexcel",
           "1003": "puce", "1007": "pagegarde", "1018": "protectionetmotsexcel", "P001": "pointpresentation", "P002": "pointdiapositiv", "P003": "pointfinal"}


def align_dp(sentences, segs):
    """Horaires [début, fin] de chaque phrase à partir des segments de parole (séparés par des pauses).

    On travaille en « temps de parole » (pauses retirées). Chaque frontière entre deux phrases est
    placée soit sur une pause (préféré, d'autant plus que la pause est longue), soit à l'intérieur
    d'un segment quand la voix enchaîne deux phrases sans s'arrêter (pénalité). Programmation
    dynamique : les durées obtenues doivent rester proches de longueur / débit moyen.
    """
    n = len(sentences)
    chars = [max(len(x), 4) for x in sentences]
    speech = [b - a for a, b in segs]
    total = sum(speech)
    rate = sum(chars) / total
    # candidats de frontière : (temps de parole, fin réelle de la phrase précédente, début réel de la suivante, coût)
    cands, acc = [], 0.0
    for j, (a, b) in enumerate(segs):
        k = 0.4
        while k < (b - a) - 0.4:                # à l'intérieur du segment
            cands.append((acc + k, a + k, a + k, 2.2))
            k += 0.25
        acc += b - a
        if j + 1 < len(segs):                   # sur la pause qui suit
            gap = segs[j + 1][0] - b
            cands.append((acc, b, segs[j + 1][0], -0.9 * min(gap, 1.5)))
    cands.sort()
    K = len(cands)
    INF = float("inf")
    want = [c / rate for c in chars]
    cost = lambda d, w: (d - w) ** 2 / (w + 1.0)
    best = [[INF] * K for _ in range(n - 1)]
    back = [[-1] * K for _ in range(n - 1)]
    for c in range(K):
        best[0][c] = cost(cands[c][0], want[0]) + cands[c][3]
    for i in range(1, n - 1):
        for c in range(K):
            tc = cands[c][0]
            for p in range(c - 1, -1, -1):
                d = tc - cands[p][0]
                if d > 3 * want[i] + 4:
                    break
                if best[i - 1][p] == INF or d < 0.3:
                    continue
                v = best[i - 1][p] + cost(d, want[i]) + cands[c][3]
                if v < best[i][c]:
                    best[i][c], back[i][c] = v, p
    last = min(range(K), key=lambda c: best[n - 2][c] + cost(total - cands[c][0], want[n - 1]) if best[n - 2][c] < INF else INF)
    idx = [last]
    for i in range(n - 2, 0, -1):
        idx.append(back[i][idx[-1]])
    idx = idx[::-1]
    out, start = [], segs[0][0]
    for c in idx:
        out.append([round(start, 2), round(cands[c][1], 2)])
        start = cands[c][2]
    out.append([round(start, 2), round(segs[-1][1], 2)])
    return out


def main():
    out = {}
    for code, vid in LESSONS.items():
        cfg = json.loads((HERE / "configs" / f"{vid}.json").read_text(encoding="utf-8"))
        audio = ANIM / "audio" / f"{code}.m4a"
        entry = {"texts": cfg["sentences"]}
        if audio.exists():
            segs, total = speech_segments(str(audio), cfg.get("noise", "-32dB"))
            entry["sentences"] = align_dp(cfg["sentences"], segs)
            entry["duration"] = round(total + 0.6, 2)
            entry["audio"] = f"audio/{code}.m4a"
        out[code] = entry
        print(code, "voix" if "audio" in entry else "synthèse", len(cfg["sentences"]), "phrases")
    js = "// Généré par app_update/tools/montage/anim_timings.py : ne pas modifier à la main.\nwindow.T = " + \
         json.dumps(out, ensure_ascii=False, indent=1) + ";\n"
    (ANIM / "lessons" / "timings.js").write_text(js, encoding="utf-8")


if __name__ == "__main__":
    main()
