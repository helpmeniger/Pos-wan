"""Acquisition et analyse d'une caméra (USB ou IP) dans des threads dédiés.

- Thread de capture : lit en continu (évite le retard du tampon), garde la dernière image.
- Thread d'analyse : traite les images à la fréquence `analysis_fps` (réglable selon la machine).
- Reconnexion automatique avec temporisation croissante ; événements de statut envoyés à PHP.
- Mémoire tampon circulaire (JPEG) pour constituer un extrait vidéo autour de chaque événement.
"""
from __future__ import annotations

import logging
import platform
import threading
import time
import uuid
from collections import deque

import cv2
import numpy as np

from .. import media
from ..config import settings
from ..security import mask_url
from ..vision.line_counter import LineConfig
from ..vision.pipeline import Pipeline, PipelineConfig, draw_overlay, iso_ms

log = logging.getLogger("camera")


def open_capture(source: dict) -> cv2.VideoCapture:
    if source["type"] == "usb":
        idx = int(source["index"])
        backend = cv2.CAP_DSHOW if platform.system() == "Windows" else cv2.CAP_ANY
        cap = cv2.VideoCapture(idx, backend)
        if source.get("width"):
            cap.set(cv2.CAP_PROP_FRAME_WIDTH, int(source["width"]))
            cap.set(cv2.CAP_PROP_FRAME_HEIGHT, int(source["height"]))
        if source.get("fps"):
            cap.set(cv2.CAP_PROP_FPS, int(source["fps"]))
        return cap
    if source["type"] == "file":
        return cv2.VideoCapture(str(source["path"]))
    # Flux IP : TCP pour RTSP (plus fiable en Wi-Fi) et délais d'ouverture/lecture bornés
    cap = cv2.VideoCapture(source["url"], cv2.CAP_FFMPEG, [cv2.CAP_PROP_OPEN_TIMEOUT_MSEC, 8000, cv2.CAP_PROP_READ_TIMEOUT_MSEC, 8000])
    cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
    return cap


def describe_source(source: dict) -> str:
    if source["type"] == "usb":
        return f"USB #{source['index']}"
    if source["type"] == "file":
        return "fichier vidéo"
    return mask_url(source["url"])


class CameraWorker:
    def __init__(self, camera_id: int, config: dict, detector_factory, recognizer, sender) -> None:
        self.camera_id = camera_id
        self.config = config
        self.source = config["source"]
        self.detector_factory = detector_factory
        self.recognizer = recognizer
        self.sender = sender
        self.analysis_fps = float(min(30, max(0.5, config.get("analysis_fps", 5))))
        self.analysis_enabled = bool(config.get("analysis_enabled", True))
        self.recording = bool(config.get("recording_enabled", True))
        self.lines = [LineConfig.from_dict(ln) for ln in config.get("lines", [])]
        self.pipeline = self._new_pipeline()
        self._lock = threading.Lock()
        self._stop = threading.Event()
        self._frame: np.ndarray | None = None
        self._frame_ts = 0.0
        self._frame_seq = 0
        self._last_tracks: list[dict] = []
        self._ring: deque = deque(maxlen=max(1, int(settings.clip_pre_seconds * self.analysis_fps)))
        self._pending_clips: list[dict] = []
        self.status = "demarrage"
        self.last_error: str | None = None
        self.connected = False
        self.unusable = False
        self.fps_in = 0.0
        self.proc_ms = 0.0
        self.started_at = time.time()
        self._cap_thread = threading.Thread(target=self._capture_loop, name=f"cap-{camera_id}", daemon=True)
        self._ana_thread = threading.Thread(target=self._analysis_loop, name=f"ana-{camera_id}", daemon=True)

    def _new_pipeline(self) -> Pipeline:
        cfg = PipelineConfig(analysis_fps=self.analysis_fps, default_min_confidence=float(self.config.get("default_min_confidence", 0.75)))
        return Pipeline(self.detector_factory(), self.recognizer, self.lines, cfg)

    # ------------------------------------------------------------------ cycle de vie
    def start(self) -> None:
        self._cap_thread.start()
        self._ana_thread.start()

    def stop(self) -> None:
        self._stop.set()
        self._cap_thread.join(timeout=5)
        self._ana_thread.join(timeout=5)
        for t in self.pipeline.finish():
            self._emit_track(t)
        self._status_event("analyse_arretee", "Analyse arrêtée")

    def update_config(self, config: dict) -> None:
        with self._lock:
            self.lines = [LineConfig.from_dict(ln) for ln in config.get("lines", [])]
            self.pipeline.set_lines(self.lines)
            self.analysis_enabled = bool(config.get("analysis_enabled", self.analysis_enabled))
            self.recording = bool(config.get("recording_enabled", self.recording))

    # ------------------------------------------------------------------ événements
    def _status_event(self, event_type: str, message: str) -> None:
        self.sender.emit({"type": "camera_status", "uuid": str(uuid.uuid4()), "camera_id": self.camera_id,
                          "event_type": event_type, "message": message[:250], "occurred_at": iso_ms(time.time())})

    def _emit_track(self, t: dict) -> None:
        self.sender.emit({"type": "track_closed", "uuid": str(uuid.uuid4()), "camera_id": self.camera_id,
                          "session_id": self.pipeline.session_id, **t, "model_version": self._model_version()})

    def _model_version(self):
        g = self.recognizer.gallery
        return {"model_id": g.model_id, "version": g.version} if g else None

    # ------------------------------------------------------------------ capture
    def _capture_loop(self) -> None:
        backoff = [1, 2, 5, 10, 30]
        attempt = 0
        first = True
        dark_frames = 0
        while not self._stop.is_set():
            cap = open_capture(self.source)
            if not cap.isOpened():
                cap.release()
                self.connected = False
                self.status = "hors_ligne"
                self.last_error = f"Impossible d'ouvrir {describe_source(self.source)}"
                if attempt == 0:
                    self._status_event("deconnexion" if not first else "erreur", self.last_error)
                wait = backoff[min(attempt, len(backoff) - 1)]
                attempt += 1
                self._stop.wait(wait)
                continue
            self.connected = True
            self.status = "en_ligne"
            self.last_error = None
            self._status_event("connexion" if first else "reconnexion", f"Flux ouvert : {describe_source(self.source)}")
            if first and self.analysis_enabled:
                self._status_event("analyse_demarree", "Analyse démarrée")
            first = False
            attempt = 0
            failures = 0
            count, t0 = 0, time.time()
            while not self._stop.is_set():
                ok, frame = cap.read()
                if not ok or frame is None:
                    failures += 1
                    if self.source["type"] == "file":
                        self._stop.set()
                        break
                    if failures > 25:
                        break
                    time.sleep(0.04)
                    continue
                failures = 0
                # Flux inutilisable (caméra masquée, image noire/blanche uniforme)
                small = cv2.resize(frame, (64, 36))
                if small.std() < 3 or small.mean() < 6 or small.mean() > 250:
                    dark_frames += 1
                    if dark_frames == 50 and not self.unusable:
                        self.unusable = True
                        self._status_event("flux_inutilisable", "Image uniforme (caméra masquée, obscurité ou panne ?)")
                else:
                    if self.unusable:
                        self._status_event("reconnexion", "Image exploitable à nouveau")
                    dark_frames = 0
                    self.unusable = False
                with self._lock:
                    self._frame = frame
                    self._frame_ts = time.time()
                    self._frame_seq += 1
                count += 1
                if time.time() - t0 >= 2:
                    self.fps_in = count / (time.time() - t0)
                    count, t0 = 0, time.time()
                if self.source["type"] == "file":
                    time.sleep(1 / 25)
            cap.release()
            if not self._stop.is_set():
                self.connected = False
                self.status = "hors_ligne"
                self.last_error = "Flux interrompu"
                self._status_event("deconnexion", f"Flux interrompu : {describe_source(self.source)}")
                self._stop.wait(backoff[0])

    # ------------------------------------------------------------------ analyse
    def _analysis_loop(self) -> None:
        last_seq = -1
        period = 1.0 / self.analysis_fps
        while not self._stop.is_set():
            t_start = time.time()
            with self._lock:
                frame, seq, ts = self._frame, self._frame_seq, self._frame_ts
            if frame is None or seq == last_seq or not self.analysis_enabled or self.unusable:
                self._stop.wait(0.05 if frame is None or seq == last_seq else period)
                continue
            last_seq = seq
            try:
                self._process(frame, ts)
            except Exception as exc:  # noqa: BLE001
                log.exception("Erreur d'analyse caméra %s", self.camera_id)
                self.last_error = f"Erreur d'analyse : {exc}"
            elapsed = time.time() - t_start
            self._stop.wait(max(0.0, period - elapsed))

    def _process(self, frame: np.ndarray, ts: float) -> None:
        with self._lock:
            pipeline = self.pipeline
        img = pipeline.prepare(frame)
        res = pipeline.process(img, ts)
        self.proc_ms = 0.8 * self.proc_ms + 0.2 * res.proc_ms if self.proc_ms else res.proc_ms
        self._last_tracks = res.tracks
        jpg = None
        if self.recording:
            ok, buf = cv2.imencode(".jpg", img, [cv2.IMWRITE_JPEG_QUALITY, 70])
            jpg = buf.tobytes() if ok else None
            # compléter les extraits en attente
            for pc in list(self._pending_clips):
                if jpg:
                    pc["frames"].append(jpg)
                if ts >= pc["until"]:
                    self._pending_clips.remove(pc)
                    self._finalize_clip(pc)
            if jpg:
                self._ring.append(jpg)
        for t in res.closed_tracks:
            self._emit_track(t)
        for ev in res.line_events:
            self._handle_line_event(ev, img, res.tracks)

    def _handle_line_event(self, ev: dict, img: np.ndarray, tracks: list[dict]) -> None:
        highlight = [t for t in tracks if t["track_number"] == ev["track_number"]] or [
            {**{k: ev[k] for k in ("track_number", "product_id", "label", "confidence", "bbox")}}]
        snap = draw_overlay(img, highlight, self.lines)
        event = {"type": "line_event", "camera_id": self.camera_id, **ev, "model_version": self._model_version(),
                 "snapshot_path": None, "clip_path": None}
        try:
            event["snapshot_path"], event["snapshot_size"] = media.save_snapshot(self.camera_id, ev["uuid"], snap)
        except Exception as exc:  # noqa: BLE001
            log.warning("Image d'événement non enregistrée : %s", exc)
        if self.recording:
            self._pending_clips.append({"event": event, "frames": list(self._ring), "until": time.time() + settings.clip_post_seconds})
        else:
            self.sender.emit(event)

    def _finalize_clip(self, pc: dict) -> None:
        event = pc["event"]
        try:
            event["clip_path"], event["clip_frames"], event["clip_size"] = media.save_clip(self.camera_id, event["uuid"], pc["frames"])
            event["clip_fps"] = self.analysis_fps
        except Exception as exc:  # noqa: BLE001
            log.warning("Extrait vidéo non enregistré : %s", exc)
        self.sender.emit(event)

    # ------------------------------------------------------------------ consultation
    def latest_jpeg(self, overlay: bool = True, quality: int = 75, max_width: int = 960) -> bytes | None:
        with self._lock:
            frame = None if self._frame is None else self._frame.copy()
        if frame is None:
            return None
        h, w = frame.shape[:2]
        if w > max_width:
            frame = cv2.resize(frame, (max_width, int(h * max_width / w)), interpolation=cv2.INTER_AREA)
        if overlay:
            frame = draw_overlay(frame, self._last_tracks if self.analysis_enabled else [], self.lines)
        ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, quality])
        return buf.tobytes() if ok else None

    def state(self) -> dict:
        return {"camera_id": self.camera_id, "status": self.status, "connected": self.connected, "unusable": self.unusable,
                "analysis_enabled": self.analysis_enabled, "recording_enabled": self.recording,
                "source": describe_source(self.source), "fps_in": round(self.fps_in, 1), "analysis_fps": self.analysis_fps,
                "proc_ms": round(self.proc_ms, 1), "tracks": self._last_tracks, "tracked_objects": len(self._last_tracks),
                "counters": dict(self.pipeline.stats), "last_error": self.last_error, "session_id": self.pipeline.session_id,
                "uptime_s": int(time.time() - self.started_at), "lines": [ln.__dict__ for ln in self.lines]}
