"""Écriture des images d'événements et des extraits vidéo dans storage/ (partagé avec PHP).

Les extraits sont stockés sous forme de séquences JPEG (lisibles dans tous les navigateurs,
sans codec particulier) : storage/videos/cam<ID>/<AAAA-MM-JJ>/<uuid>/frame_000.jpg
"""
from __future__ import annotations

import re
import time
from pathlib import Path

import cv2
import numpy as np

from .config import settings

_SAFE = re.compile(r"^[0-9a-fA-F-]{8,64}$")


def _day_dir(kind: str, camera_id: int) -> tuple[Path, str]:
    day = time.strftime("%Y-%m-%d")
    rel = f"{kind}/cam{int(camera_id)}/{day}"
    path = settings.storage_dir / rel
    path.mkdir(parents=True, exist_ok=True)
    return path, rel


def save_snapshot(camera_id: int, event_uuid: str, frame: np.ndarray, quality: int = 85) -> tuple[str, int]:
    if not _SAFE.match(event_uuid):
        raise ValueError("uuid invalide")
    path, rel = _day_dir("snapshots", camera_id)
    file = path / f"{event_uuid}.jpg"
    ok, buf = cv2.imencode(".jpg", frame, [cv2.IMWRITE_JPEG_QUALITY, quality])
    if not ok:
        raise RuntimeError("encodage JPEG impossible")
    file.write_bytes(buf.tobytes())
    return f"{rel}/{event_uuid}.jpg", len(buf)


def save_clip(camera_id: int, event_uuid: str, jpeg_frames: list[bytes]) -> tuple[str, int, int]:
    if not _SAFE.match(event_uuid):
        raise ValueError("uuid invalide")
    path, rel = _day_dir("videos", camera_id)
    clip_dir = path / event_uuid
    clip_dir.mkdir(parents=True, exist_ok=True)
    size = 0
    for i, jpg in enumerate(jpeg_frames):
        (clip_dir / f"frame_{i:03d}.jpg").write_bytes(jpg)
        size += len(jpg)
    return f"{rel}/{event_uuid}", len(jpeg_frames), size
