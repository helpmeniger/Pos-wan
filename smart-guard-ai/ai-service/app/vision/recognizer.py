"""Reconnaissance de produits par similarité d'empreintes (galerie de référence).

La galerie contient les empreintes des photos de référence de chaque produit, plus des
paramètres de calibration calculés lors de la validation. Le score de confiance affiché est
calibré : 0,5 correspond au seuil où les produits différents commencent à se ressembler
(95e centile des similarités entre produits différents) et 1,0 à la similarité médiane
entre photos d'un même produit. Si le score est insuffisant, ou si deux produits sont trop
proches (marge), le résultat est « Produit non identifié ».
"""
from __future__ import annotations

import json
import threading
from dataclasses import dataclass
from pathlib import Path

import numpy as np

UNKNOWN_LABEL = "Produit non identifié"


@dataclass
class Match:
    product_id: int | None
    label: str
    confidence: float          # score calibré 0..1
    similarity: float          # similarité cosinus brute
    second_product_id: int | None = None
    second_confidence: float = 0.0

    def as_dict(self) -> dict:
        return {"product_id": self.product_id, "label": self.label, "confidence": round(self.confidence, 4),
                "similarity": round(self.similarity, 4), "second_product_id": self.second_product_id,
                "second_confidence": round(self.second_confidence, 4)}


class Gallery:
    def __init__(self, embeddings: np.ndarray, product_ids: np.ndarray, meta: dict) -> None:
        self.embeddings = embeddings.astype(np.float32)
        self.product_ids = product_ids.astype(np.int64)
        self.meta = meta
        self.products: dict[int, dict] = {int(k): v for k, v in meta.get("products", {}).items()}
        cal = meta.get("calibration", {})
        self.sim_low = float(cal.get("impostor_p95", 0.80))
        self.sim_high = float(cal.get("genuine_median", 0.95))
        self.margin = float(meta.get("unknown_margin", 0.04))
        self.embedder = meta.get("embedder", "")
        self.model_id = meta.get("model_id")
        self.version = meta.get("version")

    @classmethod
    def load(cls, path: Path) -> "Gallery":
        data = np.load(path, allow_pickle=False)
        meta = json.loads(str(data["meta"]))
        return cls(data["embeddings"], data["product_ids"], meta)

    def save(self, path: Path) -> None:
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp = path.with_suffix(".tmp.npz")
        np.savez_compressed(tmp, embeddings=self.embeddings, product_ids=self.product_ids,
                            meta=np.array(json.dumps(self.meta, ensure_ascii=False)))
        tmp.replace(path)

    def calibrate(self, sim: float) -> float:
        span = max(1e-6, self.sim_high - self.sim_low)
        return float(np.clip(0.5 + 0.5 * (sim - self.sim_low) / span, 0.0, 1.0))

    def best_per_product(self, emb: np.ndarray) -> list[tuple[int, float]]:
        sims = self.embeddings @ emb.astype(np.float32)
        best: dict[int, float] = {}
        for pid, s in zip(self.product_ids.tolist(), sims.tolist()):
            if s > best.get(pid, -2.0):
                best[pid] = s
        return sorted(best.items(), key=lambda kv: kv[1], reverse=True)

    def match(self, emb: np.ndarray, default_min_conf: float = 0.75) -> Match:
        ranked = self.best_per_product(emb)
        if not ranked:
            return Match(None, UNKNOWN_LABEL, 0.0, 0.0)
        pid, sim = ranked[0]
        conf = self.calibrate(sim)
        second_pid, second_sim = (ranked[1] if len(ranked) > 1 else (None, -1.0))
        second_conf = self.calibrate(second_sim) if second_pid is not None else 0.0
        info = self.products.get(int(pid), {})
        min_conf = float(info.get("min_confidence", default_min_conf))
        enabled = bool(info.get("enabled", True))
        ambiguous = second_pid is not None and (sim - second_sim) < self.margin
        if not enabled or conf < min_conf or ambiguous:
            return Match(None, UNKNOWN_LABEL, conf, sim, int(pid), second_conf)
        return Match(int(pid), info.get("name", f"Produit {pid}"), conf, sim, second_pid, second_conf)


class Recognizer:
    """Galerie active, rechargeable à chaud (thread-safe)."""

    def __init__(self, embedder, galleries_dir: Path) -> None:
        self.embedder = embedder
        self.galleries_dir = galleries_dir
        self._lock = threading.RLock()
        self._gallery: Gallery | None = None
        self._active_file = galleries_dir / "active.json"
        self._load_active()

    def _load_active(self) -> None:
        if self._active_file.is_file():
            try:
                info = json.loads(self._active_file.read_text(encoding="utf-8"))
                self.activate(info["file"], persist=False)
            except Exception:  # noqa: BLE001
                self._gallery = None

    @property
    def gallery(self) -> Gallery | None:
        with self._lock:
            return self._gallery

    def activate(self, filename: str, persist: bool = True) -> Gallery:
        if "/" in filename or "\\" in filename or not filename.endswith(".npz"):
            raise ValueError("Nom de fichier de galerie invalide.")
        path = self.galleries_dir / filename
        gallery = Gallery.load(path)
        if gallery.embedder and gallery.embedder != self.embedder.name:
            raise ValueError(f"Galerie construite avec « {gallery.embedder} » mais le service utilise « {self.embedder.name} ». Relancez la préparation.")
        with self._lock:
            self._gallery = gallery
        if persist:
            self._active_file.write_text(json.dumps({"file": filename}), encoding="utf-8")
        return gallery

    def recognize(self, crop: np.ndarray) -> Match:
        g = self.gallery
        if g is None or crop.size == 0 or min(crop.shape[:2]) < 12:
            return Match(None, UNKNOWN_LABEL, 0.0, 0.0)
        return g.match(self.embedder.embed(crop))

    def status(self) -> dict:
        g = self.gallery
        if g is None:
            return {"active": False}
        return {"active": True, "model_id": g.model_id, "version": g.version, "embedder": g.embedder,
                "products": len(g.products), "images": int(len(g.product_ids)),
                "calibration": {"impostor_p95": g.sim_low, "genuine_median": g.sim_high}}
