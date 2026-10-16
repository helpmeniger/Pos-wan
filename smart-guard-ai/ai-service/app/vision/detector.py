"""Détecteurs d'objets.

- MotionDetector (défaut) : soustraction de fond MOG2 (OpenCV). Aucun modèle à télécharger,
  fonctionne hors ligne. Adapté à une scène contrôlée (comptoir, passage de sortie) où
  les objets en mouvement sont principalement les articles. Il NE distingue PAS une personne
  d'un article : la reconnaissance par similarité (recognizer) décide ensuite si l'objet
  ressemble à un produit enregistré, sinon « Produit non identifié ».
- YoloDetector (facultatif) : Ultralytics YOLO pré-entraîné (COCO). Licence AGPL-3.0.
  Les personnes sont ignorées (aucune identification de personne, aucune reconnaissance faciale).
"""
from __future__ import annotations

import logging

import cv2
import numpy as np

from .geometry import Detection, iou

log = logging.getLogger("detector")


class MotionDetector:
    name = "motion-mog2"
    supports_static_images = False

    def __init__(self, min_area: float = 0.002, max_area: float = 0.35, warmup_frames: int = 15,
                 var_threshold: float = 32.0, history: int = 300) -> None:
        self.min_area = min_area
        self.max_area = max_area
        self.warmup_frames = warmup_frames
        self._sub = cv2.createBackgroundSubtractorMOG2(history=history, varThreshold=var_threshold, detectShadows=True)
        self._frames = 0
        self._kernel_open = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3))
        self._kernel_close = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (9, 9))

    def reset(self) -> None:
        self._sub = cv2.createBackgroundSubtractorMOG2(history=300, varThreshold=32.0, detectShadows=True)
        self._frames = 0

    def detect(self, frame: np.ndarray) -> list[Detection]:
        h, w = frame.shape[:2]
        mask = self._sub.apply(frame)
        self._frames += 1
        if self._frames <= self.warmup_frames:
            return []
        # 255 = premier plan, 127 = ombre (ignorée)
        _, mask = cv2.threshold(mask, 200, 255, cv2.THRESH_BINARY)
        mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, self._kernel_open)
        mask = cv2.morphologyEx(mask, cv2.MORPH_CLOSE, self._kernel_close, iterations=2)
        contours, _ = cv2.findContours(mask, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        frame_area = float(w * h)
        dets: list[Detection] = []
        for c in contours:
            x, y, bw, bh = cv2.boundingRect(c)
            area = bw * bh
            if area < self.min_area * frame_area or area > self.max_area * frame_area:
                continue
            fill = cv2.contourArea(c) / max(1.0, area)
            dets.append(Detection(float(x), float(y), float(bw), float(bh), float(min(1.0, 0.4 + fill * 0.6)), "objet_en_mouvement"))
        return _merge_overlaps(dets)


class YoloDetector:
    name = "yolo"
    supports_static_images = True
    IGNORED = {"person"}  # aucune analyse de personnes

    def __init__(self, model_path: str, conf: float = 0.25) -> None:
        from ultralytics import YOLO  # import tardif : dépendance facultative (AGPL-3.0)
        self._model = YOLO(model_path)
        self.conf = conf
        self.name = f"yolo:{model_path.split('/')[-1]}"

    def reset(self) -> None:
        pass

    def detect(self, frame: np.ndarray) -> list[Detection]:
        res = self._model.predict(frame, conf=self.conf, verbose=False)[0]
        names = res.names
        dets = []
        for box, score, cls in zip(res.boxes.xyxy.cpu().numpy(), res.boxes.conf.cpu().numpy(), res.boxes.cls.cpu().numpy()):
            label = names.get(int(cls), "objet")
            if label in self.IGNORED:
                continue
            x1, y1, x2, y2 = box.tolist()
            dets.append(Detection(x1, y1, x2 - x1, y2 - y1, float(score), label))
        return dets


def _merge_overlaps(dets: list[Detection], thr: float = 0.3) -> list[Detection]:
    dets = sorted(dets, key=lambda d: d.w * d.h, reverse=True)
    out: list[Detection] = []
    for d in dets:
        merged = False
        for o in out:
            if iou(d.xyxy, o.xyxy) > thr:
                x1, y1 = min(d.x, o.x), min(d.y, o.y)
                x2, y2 = max(d.x + d.w, o.x + o.w), max(d.y + d.h, o.y + o.h)
                o.x, o.y, o.w, o.h = x1, y1, x2 - x1, y2 - y1
                o.score = max(o.score, d.score)
                merged = True
                break
        if not merged:
            out.append(Detection(d.x, d.y, d.w, d.h, d.score, d.label))
    return out


def create_detector(kind: str, yolo_model_path: str | None = None, **kwargs):
    if kind == "yolo":
        try:
            return YoloDetector(yolo_model_path or "yolov8n.pt")
        except Exception as exc:  # noqa: BLE001
            log.warning("YOLO indisponible (%s) — repli sur le détecteur de mouvement.", exc)
    return MotionDetector(**kwargs)
