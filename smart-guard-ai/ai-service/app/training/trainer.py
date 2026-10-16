"""Préparation de la reconnaissance d'un ensemble de produits (galerie d'empreintes + validation).

Méthode (prototype) : reconnaissance par similarité visuelle. Aucun réseau n'est ré-entraîné :
les photos de référence sont transformées en empreintes par un extracteur pré-entraîné (ou
classique), puis on mesure sur des photos de TEST distinctes la capacité réelle à reconnaître
chaque produit. Les résultats retournés sont ceux réellement calculés.
"""
from __future__ import annotations

import logging
import threading
import time
import uuid
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

import cv2
import numpy as np

from ..vision.recognizer import Gallery

log = logging.getLogger("trainer")


def image_quality(img: np.ndarray) -> dict:
    h, w = img.shape[:2]
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    small = cv2.resize(gray, (min(512, w), int(h * min(512, w) / w)))
    sharp = float(cv2.Laplacian(small, cv2.CV_64F).var())
    bright = float(gray.mean())
    contrast = float(gray.std())
    issues = []
    if min(h, w) < 120:
        issues.append("résolution trop faible")
    if sharp < 25:
        issues.append("image très floue")
    elif sharp < 60:
        issues.append("image légèrement floue")
    if bright < 40:
        issues.append("image trop sombre")
    elif bright > 225:
        issues.append("image surexposée")
    if contrast < 12:
        issues.append("contraste très faible")
    score = min(1.0, sharp / 300) * 0.5 + (1 - abs(bright - 128) / 128) * 0.3 + min(1.0, contrast / 60) * 0.2
    return {"width": w, "height": h, "sharpness": round(sharp, 1), "brightness": round(bright, 1),
            "contrast": round(contrast, 1), "score": round(max(0.0, score), 3), "issues": issues,
            "unusable": min(h, w) < 64 or sharp < 10 or bright < 15 or bright > 245}


def augment(img: np.ndarray) -> list[np.ndarray]:
    """Variantes réalistes : éclairage, légère rotation, recadrage (pas de miroir : texte des emballages)."""
    h, w = img.shape[:2]
    out = [cv2.convertScaleAbs(img, alpha=0.75, beta=0), cv2.convertScaleAbs(img, alpha=1.25, beta=10)]
    for ang in (-8, 8):
        m = cv2.getRotationMatrix2D((w / 2, h / 2), ang, 1.0)
        out.append(cv2.warpAffine(img, m, (w, h), borderMode=cv2.BORDER_REPLICATE))
    mx, my = int(w * 0.08), int(h * 0.08)
    out.append(img[my:h - my, mx:w - mx])
    out.append(cv2.GaussianBlur(img, (5, 5), 0))
    return out


def _crop(img: np.ndarray, bbox: dict | None) -> np.ndarray:
    if not bbox:
        return img
    h, w = img.shape[:2]
    try:
        x, y = int(float(bbox["x"]) * w), int(float(bbox["y"]) * h)
        bw, bh = int(float(bbox["w"]) * w), int(float(bbox["h"]) * h)
    except (KeyError, TypeError, ValueError):
        return img
    if bw < 16 or bh < 16:
        return img
    return img[max(0, y):min(h, y + bh), max(0, x):min(w, x + bw)]


class TrainingJobs:
    def __init__(self, embedder, galleries_dir: Path, resolve_path) -> None:
        self.embedder = embedder
        self.galleries_dir = galleries_dir
        self.resolve_path = resolve_path
        self.jobs: dict[str, dict] = {}
        self._lock = threading.Lock()
        self._pool = ThreadPoolExecutor(max_workers=1, thread_name_prefix="training")

    def submit(self, payload: dict) -> str:
        job_id = uuid.uuid4().hex
        with self._lock:
            # limiter la mémoire : garder les 50 derniers jobs
            if len(self.jobs) > 50:
                for k in list(self.jobs)[:-50]:
                    self.jobs.pop(k, None)
            self.jobs[job_id] = {"job_id": job_id, "status": "en_attente", "progress": 0, "message": "En attente", "result": None,
                                 "created_at": time.time()}
        self._pool.submit(self._run, job_id, payload)
        return job_id

    def get(self, job_id: str) -> dict | None:
        with self._lock:
            j = self.jobs.get(job_id)
            return dict(j) if j else None

    def _set(self, job_id: str, **kw) -> None:
        with self._lock:
            self.jobs[job_id].update(kw)

    def _run(self, job_id: str, payload: dict) -> None:
        try:
            self._set(job_id, status="en_cours", message="Analyse des photos")
            result = self.build(payload, lambda p, m: self._set(job_id, progress=p, message=m))
            self._set(job_id, status="termine", progress=100, message="Terminé", result=result)
        except Exception as exc:  # noqa: BLE001
            log.exception("Échec de la préparation")
            self._set(job_id, status="echec", message=f"Échec : {exc}")

    # ------------------------------------------------------------------
    def build(self, payload: dict, progress=lambda p, m: None) -> dict:
        model_id = int(payload["model_id"])
        version = int(payload["version"])
        min_images = int(payload.get("min_images", 8))
        min_accuracy = float(payload.get("min_accuracy", 0.8))
        margin = float(payload.get("unknown_margin", 0.04))
        products = payload["products"]
        total_imgs = sum(len(p["images"]) for p in products) or 1
        done = 0

        image_reports: list[dict] = []
        product_reports: dict[int, dict] = {}
        ref_raw: dict[int, list[np.ndarray]] = {}
        ref_aug: dict[int, list[np.ndarray]] = {}
        tests: dict[int, list[tuple[int, np.ndarray]]] = {}

        for p in products:
            pid = int(p["product_id"])
            rep = product_reports[pid] = {"product_id": pid, "name": p["name"], "usable_images": 0, "issues": [], "included": False}
            usable = []
            for im in p["images"]:
                done += 1
                progress(int(70 * done / total_imgs), f"Analyse des photos ({done}/{total_imgs})")
                path = self.resolve_path(im["path"])
                img = cv2.imread(str(path), cv2.IMREAD_COLOR)
                if img is None:
                    image_reports.append({"image_id": im["id"], "product_id": pid, "quality": None, "issues": ["fichier illisible"], "excluded": True})
                    continue
                img = _crop(img, im.get("bbox"))
                q = image_quality(img)
                excluded = q["unusable"] or im.get("split") == "exclue"
                image_reports.append({"image_id": im["id"], "product_id": pid, "quality": q["score"], "issues": q["issues"], "excluded": excluded, "metrics": q})
                if not excluded:
                    usable.append((int(im["id"]), img, im.get("split", "reference")))
            rep["usable_images"] = len(usable)
            if len(usable) < min_images:
                rep["issues"].append(f"{len(usable)} photo(s) exploitable(s) sur {min_images} minimum")
                continue
            explicit_tests = [u for u in usable if u[2] == "test"]
            refs = [u for u in usable if u[2] != "test"]
            if not explicit_tests:
                # jeu de test automatique : 1 photo sur 4, jamais utilisée comme référence
                refs_sorted = sorted(refs, key=lambda u: u[0])
                explicit_tests = refs_sorted[3::4]
                test_ids = {u[0] for u in explicit_tests}
                refs = [u for u in refs_sorted if u[0] not in test_ids]
            if len(refs) < 3:
                rep["issues"].append("moins de 3 photos de référence après séparation du jeu de test")
                continue
            ref_raw[pid] = [self.embedder.embed(img) for _, img, _ in refs]
            ref_aug[pid] = [self.embedder.embed(a) for _, img, _ in refs for a in augment(img)]
            tests[pid] = [(iid, self.embedder.embed(img)) for iid, img, _ in explicit_tests]
            rep["reference_images"] = len(refs)
            rep["test_images"] = len(explicit_tests)
            # Diversité : similarité moyenne entre photos de référence
            E = np.stack(ref_raw[pid])
            S = E @ E.T
            n = len(E)
            mean_pair = float((S.sum() - n) / max(1, n * (n - 1)))
            rep["mean_pairwise_similarity"] = round(mean_pair, 4)
            dupes = int(((S > 0.995).sum() - n) / 2)
            if dupes:
                rep["issues"].append(f"{dupes} paire(s) de photos quasi identiques")
            if mean_pair > 0.97:
                rep["issues"].append("photos peu variées (angles/distances/éclairages à diversifier)")
            rep["included"] = True

        included = [pid for pid in ref_raw]
        if not included:
            return {"status": "echec", "message": "Aucun produit ne dispose de suffisamment de photos exploitables.",
                    "products": list(product_reports.values()), "images": image_reports, "gallery_file": None}

        progress(75, "Calibration des seuils")
        genuine, impostor = [], []
        for pid in included:
            E = np.stack(ref_raw[pid])
            for i in range(len(E)):
                others = np.delete(E, i, axis=0)
                genuine.append(float((others @ E[i]).max()))
                for qid in included:
                    if qid != pid:
                        impostor.append(float((np.stack(ref_raw[qid]) @ E[i]).max()))
        warnings = []
        g_med = float(np.median(genuine))
        if impostor:
            i_p95 = float(np.percentile(impostor, 95))
        else:
            i_p95 = float(np.percentile(genuine, 5)) - 0.05
            warnings.append("Un seul produit : le rejet des objets inconnus ne peut pas être calibré. Enregistrez au moins 2 produits.")
        if g_med <= i_p95 + 0.01:
            warnings.append("Certains produits se ressemblent fortement : la reconnaissance sera prudente (davantage de « Produit non identifié »).")
            g_med = i_p95 + 0.02

        emb, pids = [], []
        for pid in included:
            for e in ref_raw[pid] + ref_aug[pid]:
                emb.append(e)
                pids.append(pid)
        meta_products = {str(int(p["product_id"])): {"name": p["name"], "min_confidence": float(p.get("min_confidence", 0.75)),
                                                       "enabled": bool(p.get("enabled", True))}
                         for p in products if int(p["product_id"]) in included}
        meta = {"model_id": model_id, "version": version, "embedder": self.embedder.name, "unknown_margin": margin,
                "products": meta_products, "created_at": time.strftime("%Y-%m-%d %H:%M:%S"),
                "calibration": {"impostor_p95": round(i_p95, 5), "genuine_median": round(g_med, 5),
                                "genuine_samples": len(genuine), "impostor_samples": len(impostor)}}
        gallery = Gallery(np.stack(emb), np.array(pids), meta)

        progress(85, "Validation sur les photos de test")
        per_product = []
        total = correct = wrong = unknown = 0
        for pid in included:
            c = w = u = 0
            confused: dict[int, int] = {}
            details = []
            for iid, e in tests[pid]:
                m = gallery.match(e, 0.75)
                # pour la validation, on applique le seuil de confiance du produit attendu
                if m.product_id == pid:
                    c += 1
                elif m.product_id is None:
                    u += 1
                else:
                    w += 1
                    confused[m.product_id] = confused.get(m.product_id, 0) + 1
                details.append({"image_id": iid, "predicted": m.product_id, "confidence": round(m.confidence, 4),
                                "closest_product": m.product_id or m.second_product_id})
            n = len(tests[pid])
            total += n
            correct += c
            wrong += w
            unknown += u
            per_product.append({"product_id": pid, "test_images": n, "correct": c, "wrong": w, "unidentified": u,
                                "accuracy": round(c / n, 4) if n else None,
                                "most_confused_with": max(confused, key=confused.get) if confused else None, "details": details})
            product_reports[pid]["accuracy"] = round(c / n, 4) if n else None

        accuracy = correct / total if total else 0.0
        filename = f"gallery_m{model_id}_v{version}.npz"
        gallery.meta["validation"] = {"accuracy": round(accuracy, 4), "test_images": total}
        gallery.save(self.galleries_dir / filename)
        progress(100, "Terminé")
        ok = total > 0 and accuracy >= min_accuracy
        return {
            "status": "prete" if ok else "echec",
            "message": ("Validation réussie." if ok else
                        f"Précision de validation {accuracy:.0%} inférieure au minimum requis ({min_accuracy:.0%}). Ajoutez des photos plus variées."),
            "gallery_file": filename, "embedder": self.embedder.name,
            "metrics": {"accuracy": round(accuracy, 4), "test_images": total, "correct": correct, "wrong": wrong,
                        "unidentified": unknown, "false_acceptance_rate": round(wrong / total, 4) if total else None,
                        "calibration": meta["calibration"]},
            "warnings": warnings, "products": list(product_reports.values()), "validation": per_product,
            "images": image_reports, "image_count": int(len(pids)), "reference_photos": int(sum(len(ref_raw[p]) for p in included)),
            "included_products": included,
        }
