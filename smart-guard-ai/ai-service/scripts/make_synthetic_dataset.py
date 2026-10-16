"""Génère un jeu de données SYNTHÉTIQUE (fictif) pour tester la chaîne complète sans caméra.

ATTENTION : ces images et cette vidéo sont artificielles. Elles servent uniquement à vérifier
le fonctionnement logiciel (suivi, ligne virtuelle, comptage, reconnaissance). Elles ne
représentent PAS des observations réelles et ne permettent pas d'estimer la précision réelle
sur de vrais produits : utilisez pour cela la procédure de docs/TESTS.md avec vos articles.

Usage : python scripts/make_synthetic_dataset.py <dossier_sortie>
Produit :
  <sortie>/products/<code>/img_XX.jpg   photos de « référence » de 3 produits fictifs
  <sortie>/unknown.jpg                  un objet non enregistré
  <sortie>/scene.mp4 (ou .avi)          vidéo : A et C sortent (gauche -> droite), B entre, X (inconnu) sort
  <sortie>/expected.json                comptages attendus
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

import cv2
import numpy as np

RNG = np.random.default_rng(42)
SIZE = 90


def texture(code: str) -> np.ndarray:
    img = np.zeros((SIZE, SIZE, 3), np.uint8)
    if code == "A":   # rouge à rayures jaunes
        img[:] = (30, 30, 200)
        for y in range(0, SIZE, 18):
            img[y:y + 6] = (40, 220, 240)
    elif code == "B":  # bleu avec cercles blancs
        img[:] = (180, 90, 20)
        for (x, y) in [(22, 22), (66, 22), (44, 66)]:
            cv2.circle(img, (x, y), 14, (250, 250, 250), -1)
    elif code == "C":  # vert damier noir
        img[:] = (40, 170, 40)
        for y in range(0, SIZE, 30):
            for x in range(0, SIZE, 30):
                if (x + y) // 30 % 2 == 0:
                    img[y:y + 30, x:x + 30] = (20, 20, 20)
    else:              # objet inconnu : violet à bandes verticales orange
        img[:] = (160, 40, 150)
        for x in range(0, SIZE, 22):
            img[:, x:x + 8] = (0, 140, 255)
    cv2.rectangle(img, (0, 0), (SIZE - 1, SIZE - 1), (0, 0, 0), 2)
    return img


def background(w: int, h: int) -> np.ndarray:
    bg = np.full((h, w, 3), 150, np.uint8)
    noise = RNG.integers(-12, 12, (h, w, 1))
    bg = np.clip(bg.astype(int) + noise, 0, 255).astype(np.uint8)
    cv2.rectangle(bg, (0, h - 40), (w, h), (110, 110, 110), -1)  # « sol »
    return bg


def photo(tex: np.ndarray, i: int) -> np.ndarray:
    """Photo de référence : angle, échelle, luminosité et fond variables."""
    canvas = np.full((240, 240, 3), int(RNG.integers(90, 220)), np.uint8)
    canvas = np.clip(canvas.astype(int) + RNG.integers(-20, 20, (240, 240, 3)), 0, 255).astype(np.uint8)
    scale = RNG.uniform(1.4, 2.2)
    t = cv2.resize(tex, None, fx=scale, fy=scale)
    ang = RNG.uniform(-15, 15)
    m = cv2.getRotationMatrix2D((t.shape[1] / 2, t.shape[0] / 2), ang, 1.0)
    t = cv2.warpAffine(t, m, (t.shape[1], t.shape[0]), borderMode=cv2.BORDER_REPLICATE)
    th, tw = t.shape[:2]
    y0, x0 = (240 - th) // 2, (240 - tw) // 2
    canvas[max(0, y0):y0 + th, max(0, x0):x0 + tw] = t[:min(th, 240), :min(tw, 240)]
    canvas = cv2.convertScaleAbs(canvas, alpha=RNG.uniform(0.75, 1.25), beta=RNG.uniform(-15, 15))
    crop = canvas[max(0, y0 - 10):y0 + th + 10, max(0, x0 - 10):x0 + tw + 10]
    return crop


def main(out: Path) -> None:
    out.mkdir(parents=True, exist_ok=True)
    for code in "ABC":
        d = out / "products" / code
        d.mkdir(parents=True, exist_ok=True)
        for i in range(12):
            cv2.imwrite(str(d / f"img_{i:02d}.jpg"), photo(texture(code), i))
    cv2.imwrite(str(out / "unknown.jpg"), photo(texture("X"), 0))

    w, h, fps = 640, 360, 25
    bg = background(w, h)
    # (code, y, x_depart, x_arrivee, image_de_depart)
    moves = [("A", 60, -20, 660, 100), ("B", 200, 660, -20, 210), ("C", 140, -20, 660, 330), ("X", 230, -20, 660, 450)]
    n_frames = 600
    path = out / "scene.mp4"
    writer = cv2.VideoWriter(str(path), cv2.VideoWriter_fourcc(*"mp4v"), fps, (w, h))
    if not writer.isOpened():
        path = out / "scene.avi"
        writer = cv2.VideoWriter(str(path), cv2.VideoWriter_fourcc(*"MJPG"), fps, (w, h))
    speed = 6  # pixels par image
    for f in range(n_frames):
        frame = bg.copy()
        frame = np.clip(frame.astype(int) + RNG.integers(-3, 3, frame.shape), 0, 255).astype(np.uint8)
        for code, y, xs, xe, start in moves:
            k = f - start
            if k < 0:
                continue
            direction = 1 if xe > xs else -1
            x = xs + direction * speed * k
            if (direction == 1 and x > xe) or (direction == -1 and x < xe):
                continue
            tex = texture(code)
            x0, x1 = int(x), int(x) + SIZE
            sx0, sx1 = max(0, -x0), SIZE - max(0, x1 - w)
            if sx1 <= sx0:
                continue
            frame[y:y + SIZE, max(0, x0):min(w, x1)] = tex[:, sx0:sx1]
        writer.write(frame)
    writer.release()
    expected = {"note": "Données synthétiques — comptages attendus pour une ligne verticale x=0.5 tracée de haut en bas, sortie = gauche_vers_droite",
                "line": {"id": 1, "name": "Porte", "x1": 0.5, "y1": 0.0, "x2": 0.5, "y2": 1.0, "exit_direction": "gauche_vers_droite"},
                "video": path.name, "sortie": 3, "entree": 1, "sortie_par_produit": {"A": 1, "C": 1, "inconnu": 1}, "entree_par_produit": {"B": 1}}
    (out / "expected.json").write_text(json.dumps(expected, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"Jeu synthétique écrit dans {out}")


if __name__ == "__main__":
    main(Path(sys.argv[1] if len(sys.argv) > 1 else "synthetic_dataset"))
