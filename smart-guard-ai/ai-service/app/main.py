"""API REST du service IA (FastAPI). Toutes les routes, sauf /health/public, exigent X-API-Key."""
from __future__ import annotations

import base64
import logging
import shutil
import time
import uuid
from concurrent.futures import ThreadPoolExecutor
from contextlib import asynccontextmanager
from typing import Literal

import cv2
import numpy as np
import psutil
from fastapi import Depends, FastAPI, File, HTTPException, Query, Request, UploadFile
from fastapi.responses import JSONResponse, Response
from pydantic import BaseModel, Field, field_validator

from . import __version__
from .cameras.manager import CameraManager, list_usb_devices
from .cameras.worker import open_capture
from .config import settings
from .events.sender import EventSender
from .security import require_api_key, safe_storage_path, validate_stream_url
from .training.trainer import TrainingJobs, image_quality
from .vision.detector import create_detector
from .vision.embedder import create_embedder
from .vision.line_counter import LineConfig
from .vision.pipeline import Pipeline, PipelineConfig, draw_overlay
from .vision.recognizer import Recognizer

logging.basicConfig(level=settings.log_level, format="%(asctime)s %(levelname)s [%(name)s] %(message)s",
                    handlers=[logging.StreamHandler(), logging.FileHandler(settings.runtime_dir / "ai-service.log", encoding="utf-8")])
log = logging.getLogger("ai-service")

STARTED = time.time()
embedder = create_embedder(settings.embedder, settings.model_path(settings.onnx_embedder_model))
recognizer = Recognizer(embedder, settings.models_dir / "galleries")
sender = EventSender(settings.php_callback_url, settings.callback_token, settings.runtime_dir / "outbox.jsonl")


def detector_factory():
    return create_detector(settings.detector, str(settings.model_path(settings.yolo_model)))


manager = CameraManager(detector_factory, recognizer, sender, settings.max_cameras)
trainer = TrainingJobs(embedder, settings.models_dir / "galleries",
                       lambda rel: safe_storage_path(rel, ("product_images",)))
video_jobs: dict[str, dict] = {}
video_pool = ThreadPoolExecutor(max_workers=1, thread_name_prefix="video-test")
_detector_name = detector_factory().name


@asynccontextmanager
async def lifespan(_app: FastAPI):
    sender.start()
    log.info("Service IA démarré — détecteur=%s, empreintes=%s, stockage=%s", _detector_name, embedder.name, settings.storage_dir)
    yield
    manager.stop_all()
    sender.stop()


app = FastAPI(title="SMART GUARD AI — Service IA", version=__version__, lifespan=lifespan,
              docs_url=None, redoc_url=None, openapi_url=None)  # pas de documentation publique exposée
auth = [Depends(require_api_key)]


@app.middleware("http")
async def limit_body(request: Request, call_next):
    cl = request.headers.get("content-length")
    limit = settings.max_upload_mb * 1024 * 1024 + 64 * 1024
    if cl and cl.isdigit() and int(cl) > limit:
        return JSONResponse({"detail": "Requête trop volumineuse."}, status_code=413)
    return await call_next(request)


# ---------------------------------------------------------------------------- schémas
class LineIn(BaseModel):
    id: int
    name: str = Field(default="Ligne", max_length=80)
    x1: float = Field(ge=0, le=1)
    y1: float = Field(ge=0, le=1)
    x2: float = Field(ge=0, le=1)
    y2: float = Field(ge=0, le=1)
    exit_direction: Literal["gauche_vers_droite", "droite_vers_gauche"] = "gauche_vers_droite"
    min_track_frames: int = Field(default=3, ge=1, le=50)
    hysteresis: float = Field(default=0.02, ge=0, le=0.2)


class SourceIn(BaseModel):
    type: Literal["usb", "url"]
    index: int | None = Field(default=None, ge=0, le=9)
    url: str | None = Field(default=None, max_length=500)
    width: int | None = Field(default=None, ge=160, le=3840)
    height: int | None = Field(default=None, ge=120, le=2160)
    fps: int | None = Field(default=None, ge=1, le=60)

    def to_capture(self) -> dict:
        if self.type == "usb":
            if self.index is None:
                raise HTTPException(422, "Index de caméra USB manquant.")
            return {"type": "usb", "index": self.index, "width": self.width, "height": self.height, "fps": self.fps}
        try:
            return {"type": "url", "url": validate_stream_url(self.url or "")}
        except ValueError as exc:
            raise HTTPException(422, str(exc)) from exc


class StartIn(BaseModel):
    source: SourceIn
    analysis_fps: float = Field(default=5, ge=0.5, le=30)
    analysis_enabled: bool = True
    recording_enabled: bool = True
    default_min_confidence: float = Field(default=0.75, ge=0, le=1)
    lines: list[LineIn] = Field(default_factory=list, max_length=8)


class ConfigIn(BaseModel):
    analysis_enabled: bool | None = None
    recording_enabled: bool | None = None
    lines: list[LineIn] = Field(default_factory=list, max_length=8)


class TrainingImage(BaseModel):
    id: int
    path: str = Field(max_length=300)
    split: Literal["reference", "test", "exclue"] = "reference"
    bbox: dict | None = None


class TrainingProduct(BaseModel):
    product_id: int
    name: str = Field(max_length=150)
    min_confidence: float = Field(default=0.75, ge=0, le=1)
    enabled: bool = True
    images: list[TrainingImage] = Field(max_length=500)


class TrainingIn(BaseModel):
    model_id: int
    version: int = Field(ge=1)
    min_images: int = Field(default=8, ge=3, le=200)
    min_accuracy: float = Field(default=0.8, ge=0, le=1)
    unknown_margin: float = Field(default=0.04, ge=0, le=0.5)
    products: list[TrainingProduct] = Field(max_length=2000)


class ActivateIn(BaseModel):
    gallery_file: str = Field(max_length=120)

    @field_validator("gallery_file")
    @classmethod
    def _name(cls, v: str) -> str:
        if not v.startswith("gallery_") or not v.endswith(".npz") or "/" in v or "\\" in v:
            raise ValueError("nom de galerie invalide")
        return v


class VideoTestIn(BaseModel):
    path: str = Field(max_length=300)
    analysis_fps: float = Field(default=5, ge=0.5, le=30)
    lines: list[LineIn] = Field(default_factory=list, max_length=8)


# ---------------------------------------------------------------------------- santé / système
@app.get("/health/public")
def health_public():
    return {"ok": True}


@app.get("/health", dependencies=auth)
def health():
    return {"ok": True, "version": __version__, "uptime_s": int(time.time() - STARTED), "detector": _detector_name,
            "embedder": embedder.name, "recognition": recognizer.status(), "cameras_running": len(manager.workers),
            "max_cameras": settings.max_cameras, "events": {"sent": sender.sent, "failed": sender.failed,
                                                            "pending": sender.pending(), "last_error": sender.last_error}}


@app.get("/system/performance", dependencies=auth)
def performance():
    vm = psutil.virtual_memory()
    disk = shutil.disk_usage(settings.storage_dir)
    proc = psutil.Process()
    gpu = None
    try:
        import torch  # facultatif
        if torch.cuda.is_available():
            gpu = {"name": torch.cuda.get_device_name(0),
                   "memory_used_mb": round(torch.cuda.memory_allocated(0) / 1e6, 1)}
    except Exception:  # noqa: BLE001
        gpu = None
    return {"cpu_percent": psutil.cpu_percent(interval=0.3), "cpu_count": psutil.cpu_count(),
            "memory": {"total_mb": round(vm.total / 1e6), "used_percent": vm.percent},
            "service_memory_mb": round(proc.memory_info().rss / 1e6, 1),
            "disk": {"total_gb": round(disk.total / 1e9, 1), "free_gb": round(disk.free / 1e9, 1)},
            "gpu": gpu, "cameras": {cid: {"proc_ms": w.proc_ms, "fps_in": round(w.fps_in, 1), "analysis_fps": w.analysis_fps}
                                    for cid, w in manager.workers.items()}}


# ---------------------------------------------------------------------------- caméras
@app.get("/cameras", dependencies=auth)
def cameras():
    return {"cameras": [w.state() for w in manager.workers.values()]}


@app.get("/cameras/devices", dependencies=auth)
def devices():
    return {"devices": list_usb_devices(),
            "note": "Seules les caméras branchées sur l'ordinateur qui exécute le service IA sont détectées."}


@app.post("/cameras/test", dependencies=auth)
def camera_test(source: SourceIn):
    cap_src = source.to_capture()
    t0 = time.time()
    cap = open_capture(cap_src)
    try:
        if not cap.isOpened():
            return {"ok": False, "error": "Impossible d'ouvrir le flux (périphérique absent, adresse ou identifiants incorrects)."}
        frame = None
        for _ in range(10):
            ok, frame = cap.read()
            if ok and frame is not None:
                break
        if frame is None:
            return {"ok": False, "error": "Flux ouvert mais aucune image reçue."}
        h, w = frame.shape[:2]
        q = image_quality(frame)
        ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, 80])
        return {"ok": True, "width": w, "height": h, "fps": round(cap.get(cv2.CAP_PROP_FPS) or 0, 1),
                "open_ms": int((time.time() - t0) * 1000), "quality": q,
                "snapshot_jpeg_b64": base64.b64encode(buf.tobytes()).decode() if ok else None}
    finally:
        cap.release()


@app.post("/cameras/{camera_id}/start", dependencies=auth)
def camera_start(camera_id: int, body: StartIn):
    cfg = body.model_dump()
    cfg["source"] = body.source.to_capture()
    try:
        w = manager.start(camera_id, cfg)
    except (RuntimeError, ValueError) as exc:
        raise HTTPException(409, str(exc)) from exc
    return {"ok": True, "session_id": w.pipeline.session_id}


@app.post("/cameras/{camera_id}/stop", dependencies=auth)
def camera_stop(camera_id: int):
    return {"ok": manager.stop(camera_id)}


@app.post("/cameras/{camera_id}/config", dependencies=auth)
def camera_config(camera_id: int, body: ConfigIn):
    w = manager.get(camera_id)
    if not w:
        raise HTTPException(404, "Caméra non démarrée.")
    try:
        w.update_config(body.model_dump(exclude_none=True))
    except ValueError as exc:
        raise HTTPException(422, str(exc)) from exc
    return {"ok": True}


@app.get("/cameras/{camera_id}/state", dependencies=auth)
def camera_state(camera_id: int):
    w = manager.get(camera_id)
    if not w:
        return {"camera_id": camera_id, "status": "arretee", "connected": False}
    return w.state()


@app.get("/cameras/{camera_id}/frame", dependencies=auth)
def camera_frame(camera_id: int, overlay: int = Query(default=1, ge=0, le=1)):
    w = manager.get(camera_id)
    if not w:
        raise HTTPException(404, "Caméra non démarrée.")
    jpg = w.latest_jpeg(overlay=bool(overlay))
    if jpg is None:
        raise HTTPException(503, "Aucune image disponible.")
    return Response(content=jpg, media_type="image/jpeg", headers={"Cache-Control": "no-store"})


# ---------------------------------------------------------------------------- détection (mode test)
def _decode_image(data: bytes) -> np.ndarray:
    arr = np.frombuffer(data, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise HTTPException(422, "Image illisible.")
    return img


@app.post("/detection/image", dependencies=auth)
async def detect_image(file: UploadFile = File(...)):
    data = await file.read(settings.max_upload_mb * 1024 * 1024 + 1)
    if len(data) > settings.max_upload_mb * 1024 * 1024:
        raise HTTPException(413, "Image trop volumineuse.")
    img = _decode_image(data)
    h, w = img.shape[:2]
    results = []
    det = detector_factory()
    boxes = det.detect(img) if getattr(det, "supports_static_images", False) else []
    if boxes:
        for b in boxes:
            x1, y1 = int(max(0, b.x)), int(max(0, b.y))
            crop = img[y1:int(b.y + b.h), x1:int(b.x + b.w)]
            m = recognizer.recognize(crop)
            results.append({**m.as_dict(), "bbox": [b.x / w, b.y / h, b.w / w, b.h / h], "detector_label": b.label,
                            "detector_confidence": round(b.score, 4)})
        mode = "detecteur"
    else:
        # Détecteur de mouvement : une image seule ne contient pas de mouvement -> image entière
        m = recognizer.recognize(img)
        results.append({**m.as_dict(), "bbox": [0, 0, 1, 1], "detector_label": "image_entiere", "detector_confidence": None})
        mode = "image_entiere"
    tracks = [{"track_number": i + 1, "product_id": r["product_id"], "label": r["label"], "confidence": r["confidence"],
               "bbox": r["bbox"]} for i, r in enumerate(results)]
    ok, buf = cv2.imencode(".jpg", draw_overlay(img, tracks, []), [cv2.IMWRITE_JPEG_QUALITY, 80])
    return {"ok": True, "mode": mode, "recognition": recognizer.status(), "results": results,
            "annotated_jpeg_b64": base64.b64encode(buf.tobytes()).decode() if ok else None}


def _run_video(job_id: str, path: str, fps: float, lines: list[dict]) -> None:
    job = video_jobs[job_id]
    try:
        cap = cv2.VideoCapture(path)
        if not cap.isOpened():
            raise RuntimeError("Vidéo illisible.")
        src_fps = cap.get(cv2.CAP_PROP_FPS) or 25.0
        total = int(cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0)
        step = max(1, round(src_fps / fps))
        pipe = Pipeline(detector_factory(), recognizer, [LineConfig.from_dict(ln) for ln in lines], PipelineConfig(analysis_fps=src_fps / step))
        events, tracks = [], []
        idx, t_proc = 0, 0.0
        base_ts = time.time()
        while True:
            ok, frame = cap.read()
            if not ok:
                break
            if idx % step == 0:
                img = pipe.prepare(frame)
                res = pipe.process(img, base_ts + idx / src_fps)
                t_proc += res.proc_ms
                events.extend(res.line_events)
                tracks.extend(res.closed_tracks)
            idx += 1
            if total:
                job["progress"] = int(100 * idx / total)
        cap.release()
        tracks.extend(pipe.finish())
        analysed = max(1, pipe.stats["frames"])
        job.update(status="termine", progress=100, result={
            "frames_total": idx, "frames_analysed": pipe.stats["frames"], "avg_proc_ms": round(t_proc / analysed, 1),
            "counters": {k: pipe.stats.get(k, 0) for k in ("sortie", "entree", "ambigu", "perte_suivi")},
            "tracks": tracks, "events": events, "objects_tracked": len(tracks)})
    except Exception as exc:  # noqa: BLE001
        log.exception("Échec analyse vidéo")
        job.update(status="echec", message=str(exc))


@app.post("/detection/video", dependencies=auth)
def detect_video(body: VideoTestIn):
    path = safe_storage_path(body.path, ("cache",))
    if not path.is_file():
        raise HTTPException(404, "Fichier vidéo introuvable.")
    job_id = uuid.uuid4().hex
    video_jobs[job_id] = {"job_id": job_id, "status": "en_cours", "progress": 0}
    if len(video_jobs) > 30:
        for k in list(video_jobs)[:-30]:
            video_jobs.pop(k, None)
    video_pool.submit(_run_video, job_id, str(path), body.analysis_fps, [ln.model_dump() for ln in body.lines])
    return {"job_id": job_id}


@app.get("/detection/video/{job_id}", dependencies=auth)
def detect_video_status(job_id: str):
    job = video_jobs.get(job_id)
    if not job:
        raise HTTPException(404, "Tâche inconnue.")
    return job


# ---------------------------------------------------------------------------- préparation / galeries
@app.post("/training/jobs", dependencies=auth)
def training_start(body: TrainingIn):
    for p in body.products:
        for im in p.images:
            safe_storage_path(im.path, ("product_images",))
    return {"job_id": trainer.submit(body.model_dump())}


@app.get("/training/jobs/{job_id}", dependencies=auth)
def training_status(job_id: str):
    job = trainer.get(job_id)
    if not job:
        raise HTTPException(404, "Tâche inconnue (le service a peut-être redémarré).")
    return job


@app.post("/recognition/activate", dependencies=auth)
def activate(body: ActivateIn):
    try:
        g = recognizer.activate(body.gallery_file)
    except FileNotFoundError as exc:
        raise HTTPException(404, "Fichier de galerie introuvable sur le service IA.") from exc
    except ValueError as exc:
        raise HTTPException(409, str(exc)) from exc
    return {"ok": True, "recognition": recognizer.status(), "products": len(g.products)}


@app.get("/recognition/status", dependencies=auth)
def recognition_status():
    return recognizer.status()



@app.post("/images/quality", dependencies=auth)
def quality(path: str = Query(max_length=300)):
    p = safe_storage_path(path, ("product_images",))
    img = cv2.imread(str(p))
    if img is None:
        raise HTTPException(422, "Image illisible.")
    return image_quality(img)
