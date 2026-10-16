"""Extraction d'empreintes visuelles (embeddings) pour la reconnaissance par similarité.

- ClassicEmbedder : descripteurs classiques (couleur HSV + HOG + texture), sans réseau de
  neurones. Fonctionne partout, hors ligne, mais distingue moins bien des emballages proches.
- OnnxEmbedder : réseau pré-entraîné exporté en ONNX (ex. MobileNetV3, voir
  scripts/export_embedder.py). Nettement plus robuste aux changements d'angle et d'éclairage.
"""
from __future__ import annotations

import logging
from pathlib import Path

import cv2
import numpy as np

log = logging.getLogger("embedder")


def _l2(v: np.ndarray) -> np.ndarray:
    n = float(np.linalg.norm(v))
    return (v / n).astype(np.float32) if n > 1e-9 else v.astype(np.float32)


def hog_descriptor(gray: np.ndarray, cell: int = 16, bins: int = 9) -> np.ndarray:
    """Histogrammes d'orientations de gradients (HOG) — implémentation numpy, indépendante
    de la version d'OpenCV. Cellules de `cell` px, blocs 2x2 normalisés (L2-Hys)."""
    g = gray.astype(np.float32)
    gx = cv2.Sobel(g, cv2.CV_32F, 1, 0, ksize=1)
    gy = cv2.Sobel(g, cv2.CV_32F, 0, 1, ksize=1)
    mag = np.sqrt(gx * gx + gy * gy)
    ang = (np.degrees(np.arctan2(gy, gx)) + 180.0) % 180.0
    h, w = g.shape
    cy, cx = h // cell, w // cell
    idx = np.minimum((ang / (180.0 / bins)).astype(np.int32), bins - 1)
    hist = np.zeros((cy, cx, bins), np.float32)
    for b in range(bins):
        m = np.where(idx == b, mag, 0.0)[: cy * cell, : cx * cell]
        hist[:, :, b] = m.reshape(cy, cell, cx, cell).sum(axis=(1, 3))
    blocks = []
    for y in range(cy - 1):
        for x in range(cx - 1):
            v = hist[y:y + 2, x:x + 2].ravel()
            v = v / (np.linalg.norm(v) + 1e-6)
            v = np.minimum(v, 0.2)
            blocks.append(v / (np.linalg.norm(v) + 1e-6))
    return np.concatenate(blocks).astype(np.float32)


class ClassicEmbedder:
    name = "classic-hsv-hog-v1"

    def __init__(self) -> None:
        self._size = 96
        self._clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(4, 4))

    def embed(self, bgr: np.ndarray) -> np.ndarray:
        img = cv2.resize(bgr, (self._size, self._size), interpolation=cv2.INTER_AREA)
        hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
        # Couleur : histogramme H/S pondéré (peu sensible à la luminosité)
        hist_hs = cv2.calcHist([hsv], [0, 1], None, [18, 8], [0, 180, 0, 256]).flatten()
        hist_hs = _l2(np.sqrt(hist_hs))
        # Couleur spatiale grossière (grille 4x4, moyenne Lab normalisée)
        lab = cv2.cvtColor(img, cv2.COLOR_BGR2LAB).astype(np.float32)
        grid = cv2.resize(lab, (4, 4), interpolation=cv2.INTER_AREA).reshape(-1, 3)
        grid[:, 0] = (grid[:, 0] - grid[:, 0].mean())  # luminance relative
        spatial = _l2(grid.flatten())
        # Forme / texture : HOG sur niveaux de gris égalisés
        gray = self._clahe.apply(cv2.cvtColor(img, cv2.COLOR_BGR2GRAY))
        hog = _l2(hog_descriptor(gray))
        vec = np.concatenate([hist_hs * 1.0, spatial * 0.6, hog * 1.0])
        return _l2(vec)


class OnnxEmbedder:
    def __init__(self, model_path: Path) -> None:
        import onnxruntime as ort  # dépendance facultative
        providers = [p for p in ("CUDAExecutionProvider", "DmlExecutionProvider", "CPUExecutionProvider") if p in ort.get_available_providers()]
        self._sess = ort.InferenceSession(str(model_path), providers=providers)
        self._input = self._sess.get_inputs()[0].name
        shape = self._sess.get_inputs()[0].shape
        self._size = int(shape[2]) if isinstance(shape[2], int) else 224
        self.name = f"onnx:{model_path.name}"
        self._mean = np.array([0.485, 0.456, 0.406], dtype=np.float32)
        self._std = np.array([0.229, 0.224, 0.225], dtype=np.float32)

    def embed(self, bgr: np.ndarray) -> np.ndarray:
        rgb = cv2.cvtColor(cv2.resize(bgr, (self._size, self._size), interpolation=cv2.INTER_AREA), cv2.COLOR_BGR2RGB)
        x = ((rgb.astype(np.float32) / 255.0 - self._mean) / self._std).transpose(2, 0, 1)[None]
        out = self._sess.run(None, {self._input: x})[0].reshape(-1)
        return _l2(out)


def create_embedder(kind: str, onnx_path: Path):
    if kind in ("auto", "onnx") and onnx_path.is_file():
        try:
            emb = OnnxEmbedder(onnx_path)
            log.info("Empreintes visuelles : %s", emb.name)
            return emb
        except Exception as exc:  # noqa: BLE001
            log.warning("Modèle ONNX inutilisable (%s) — repli sur l'extracteur classique.", exc)
    elif kind == "onnx":
        log.warning("EMBEDDER=onnx mais %s est absent — repli sur l'extracteur classique.", onnx_path)
    return ClassicEmbedder()
