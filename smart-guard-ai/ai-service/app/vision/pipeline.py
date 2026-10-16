"""Chaîne d'analyse d'une image : détection -> suivi -> reconnaissance -> lignes virtuelles."""
from __future__ import annotations

import time
import uuid
from dataclasses import dataclass, field
from datetime import datetime, timezone

import cv2
import numpy as np

from .line_counter import LineConfig, LineCounter, LineEvent
from .recognizer import UNKNOWN_LABEL, Recognizer
from .tracker import CONFIRMED, ByteTracker, Track


def iso_ms(ts: float) -> str:
    """Horodatage ISO 8601 en UTC (ex. 2024-05-12T09:42:16.123+00:00). PHP le convertit dans le
    fuseau APP_TIMEZONE : pas d'erreur si l'horloge ou le fuseau de Windows diffèrent."""
    return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat(timespec="milliseconds")


@dataclass
class FrameResult:
    ts: float
    tracks: list[dict]
    line_events: list[dict]
    closed_tracks: list[dict]
    proc_ms: float


@dataclass
class PipelineConfig:
    analysis_fps: float = 5.0
    max_lost_seconds: float = 3.0
    min_hits: int = 2
    recog_every: int = 4          # reconnaissance toutes les N images par piste
    recog_max: int = 15           # nombre max de reconnaissances par piste
    default_min_confidence: float = 0.75
    max_width: int = 960          # redimensionnement pour l'analyse
    product_min_conf: dict = field(default_factory=dict)


class Pipeline:
    def __init__(self, detector, recognizer: Recognizer, lines: list[LineConfig], cfg: PipelineConfig) -> None:
        self.detector = detector
        self.recognizer = recognizer
        self.cfg = cfg
        self.tracker = ByteTracker(max_lost=max(3, int(cfg.max_lost_seconds * cfg.analysis_fps)), min_hits=cfg.min_hits)
        self.counter = LineCounter(lines)
        self.session_id = uuid.uuid4().hex[:16]
        self.frame_w = 0
        self.frame_h = 0
        self.stats = {"frames": 0, "sortie": 0, "entree": 0, "ambigu": 0, "perte_suivi": 0, "tracks": 0}

    def set_lines(self, lines: list[LineConfig]) -> None:
        self.counter = LineCounter(lines)

    def prepare(self, frame: np.ndarray) -> np.ndarray:
        h, w = frame.shape[:2]
        if w > self.cfg.max_width:
            scale = self.cfg.max_width / w
            frame = cv2.resize(frame, (self.cfg.max_width, int(h * scale)), interpolation=cv2.INTER_AREA)
        return frame

    def _recognize(self, frame: np.ndarray, t: Track) -> None:
        if t.recog_count >= self.cfg.recog_max:
            return
        if t.recog_count >= 3 and (t.frames_seen % self.cfg.recog_every) != 0:
            return
        x1, y1, x2, y2 = t.box
        pad_x, pad_y = (x2 - x1) * 0.05, (y2 - y1) * 0.05
        xa, ya = int(max(0, x1 - pad_x)), int(max(0, y1 - pad_y))
        xb, yb = int(min(self.frame_w, x2 + pad_x)), int(min(self.frame_h, y2 + pad_y))
        crop = frame[ya:yb, xa:xb]
        m = self.recognizer.recognize(crop)
        t.recog_count += 1
        if m.product_id is None:
            t.unknown_votes += 1
        else:
            v = t.votes.setdefault(m.product_id, [0.0, 0])
            v[0] += m.confidence
            v[1] += 1
        if t.best_match is None or m.confidence > t.best_match["confidence"]:
            t.best_match = m.as_dict()
            t.best_crop_box = t.box

    def _labels(self, t: Track) -> tuple[int | None, str, float]:
        pid, conf = t.identity()
        if pid is None:
            return None, UNKNOWN_LABEL, float(conf or 0.0)
        g = self.recognizer.gallery
        name = g.products.get(pid, {}).get("name", f"Produit {pid}") if g else f"Produit {pid}"
        return pid, name, float(conf)

    def track_dict(self, t: Track) -> dict:
        pid, label, conf = self._labels(t)
        x1, y1, x2, y2 = t.box
        w, h = max(1, self.frame_w), max(1, self.frame_h)
        return {
            "track_number": t.track_id, "product_id": pid, "label": label, "confidence": round(conf, 4),
            "detector_label": t.det_label, "detector_confidence": round(t.best_score, 4),
            "bbox": [round(x1 / w, 5), round(y1 / h, 5), round((x2 - x1) / w, 5), round((y2 - y1) / h, 5)],
            "frames_seen": t.frames_seen, "state": t.state,
            "first_seen_at": iso_ms(t.first_ts), "last_seen_at": iso_ms(t.last_ts), "end_reason": t.end_reason,
        }

    def _event_dict(self, ev: LineEvent, t: Track, ts: float) -> dict:
        d = self.track_dict(t)
        self.stats[ev.direction] = self.stats.get(ev.direction, 0) + 1
        return {"uuid": str(uuid.uuid4()), "line_id": ev.line_id, "direction": ev.direction, "reason": ev.reason,
                "point": [round(ev.point[0], 4), round(ev.point[1], 4)], "occurred_at": iso_ms(ts),
                "session_id": self.session_id, "track_frames": t.frames_seen, **{k: d[k] for k in (
                    "track_number", "product_id", "label", "confidence", "detector_label", "detector_confidence", "bbox")}}

    def process(self, frame: np.ndarray, ts: float | None = None) -> FrameResult:
        ts = ts if ts is not None else time.time()
        start = time.perf_counter()
        self.frame_h, self.frame_w = frame.shape[:2]
        dets = self.detector.detect(frame)
        visible, removed = self.tracker.update(dets, ts)
        line_events: list[dict] = []
        for t in visible:
            if t.state != CONFIRMED:
                continue
            self._recognize(frame, t)
            for ev in self.counter.update(t, self.frame_w, self.frame_h):
                line_events.append(self._event_dict(ev, t, ts))
        closed: list[dict] = []
        for t in removed:
            for ev in self.counter.on_track_removed(t, self.frame_w, self.frame_h):
                line_events.append(self._event_dict(ev, t, t.last_ts))
            if t.frames_seen >= self.cfg.min_hits:
                closed.append(self.track_dict(t))
                self.stats["tracks"] += 1
        self.stats["frames"] += 1
        return FrameResult(ts, [self.track_dict(t) for t in visible if t.state == CONFIRMED], line_events, closed,
                           (time.perf_counter() - start) * 1000)

    def finish(self) -> list[dict]:
        out = []
        for t in self.tracker.flush():
            if t.frames_seen >= self.cfg.min_hits:
                out.append(self.track_dict(t))
                self.stats["tracks"] += 1
        return out


def draw_overlay(frame: np.ndarray, tracks: list[dict], lines: list[LineConfig], show_tracks: bool = True) -> np.ndarray:
    """Dessine les lignes virtuelles (avec flèche de sortie) et les boîtes de suivi."""
    img = frame.copy()
    h, w = img.shape[:2]
    for ln in lines:
        a = (int(ln.x1 * w), int(ln.y1 * h))
        b = (int(ln.x2 * w), int(ln.y2 * h))
        cv2.line(img, a, b, (0, 0, 255), 2)
        mx, my = (a[0] + b[0]) / 2, (a[1] + b[1]) / 2
        dx, dy = b[0] - a[0], b[1] - a[1]
        n = max(1e-6, (dx * dx + dy * dy) ** 0.5)
        nx, ny = -dy / n, dx / n                     # vers le côté « gauche »
        s = -ln.exit_from_side                        # sens de sortie
        tip = (int(mx + s * nx * 40), int(my + s * ny * 40))
        cv2.arrowedLine(img, (int(mx), int(my)), tip, (0, 0, 255), 2, tipLength=0.35)
        cv2.putText(img, f"{ln.name} (SORTIE)", (tip[0] + 4, tip[1]), cv2.FONT_HERSHEY_SIMPLEX, 0.45, (0, 0, 255), 1, cv2.LINE_AA)
    if show_tracks:
        for t in tracks:
            x, y, bw, bh = t["bbox"]
            p1, p2 = (int(x * w), int(y * h)), (int((x + bw) * w), int((y + bh) * h))
            color = (40, 180, 40) if t["product_id"] else (0, 165, 255)
            cv2.rectangle(img, p1, p2, color, 2)
            txt = f"#{t['track_number']} {t['label'][:28]} {int(t['confidence'] * 100)}%"
            (tw, th), _ = cv2.getTextSize(txt, cv2.FONT_HERSHEY_SIMPLEX, 0.45, 1)
            cv2.rectangle(img, (p1[0], max(0, p1[1] - th - 6)), (p1[0] + tw + 4, p1[1]), color, -1)
            cv2.putText(img, txt, (p1[0] + 2, p1[1] - 4), cv2.FONT_HERSHEY_SIMPLEX, 0.45, (255, 255, 255), 1, cv2.LINE_AA)
    return img
