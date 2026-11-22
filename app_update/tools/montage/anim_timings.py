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
LESSONS = {"2019": "vid1", "2018": "vid2", "2001": "vid3", "2002": "vid4"}


def main():
    out = {}
    for code, vid in LESSONS.items():
        cfg = json.loads((HERE / "configs" / f"{vid}.json").read_text(encoding="utf-8"))
        audio = ANIM / "audio" / f"{code}.m4a"
        entry = {"texts": cfg["sentences"]}
        if audio.exists():
            segs, total = speech_segments(str(audio), cfg.get("noise", "-32dB"))
            items = align(cfg["sentences"], segs)
            entry["sentences"] = [[round(i["t0"], 2), round(i["t1"], 2)] for i in items]
            entry["duration"] = round(total + 0.6, 2)
            entry["audio"] = f"audio/{code}.m4a"
        out[code] = entry
        print(code, "voix" if "audio" in entry else "synthèse", len(cfg["sentences"]), "phrases")
    js = "// Généré par app_update/tools/montage/anim_timings.py : ne pas modifier à la main.\nwindow.T = " + \
         json.dumps(out, ensure_ascii=False, indent=1) + ";\n"
    (ANIM / "lessons" / "timings.js").write_text(js, encoding="utf-8")


if __name__ == "__main__":
    main()
