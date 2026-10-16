from __future__ import annotations

import platform
import threading

import cv2

from .worker import CameraWorker


class CameraManager:
    def __init__(self, detector_factory, recognizer, sender, max_cameras: int) -> None:
        self.detector_factory = detector_factory
        self.recognizer = recognizer
        self.sender = sender
        self.max_cameras = max_cameras
        self.workers: dict[int, CameraWorker] = {}
        self._lock = threading.Lock()

    def start(self, camera_id: int, config: dict) -> CameraWorker:
        with self._lock:
            old = self.workers.pop(camera_id, None)
        if old:
            old.stop()
        with self._lock:
            if len(self.workers) >= self.max_cameras:
                raise RuntimeError(f"Nombre maximal de caméras simultanées atteint ({self.max_cameras}). Voir MAX_CAMERAS.")
            w = CameraWorker(camera_id, config, self.detector_factory, self.recognizer, self.sender)
            self.workers[camera_id] = w
        w.start()
        return w

    def stop(self, camera_id: int) -> bool:
        with self._lock:
            w = self.workers.pop(camera_id, None)
        if w:
            w.stop()
            return True
        return False

    def get(self, camera_id: int) -> CameraWorker | None:
        with self._lock:
            return self.workers.get(camera_id)

    def stop_all(self) -> None:
        for cid in list(self.workers):
            self.stop(cid)


def list_usb_devices(max_index: int = 6) -> list[dict]:
    """Sonde les index 0..max_index. Seules les caméras branchées sur CET ordinateur sont visibles."""
    found = []
    backend = cv2.CAP_DSHOW if platform.system() == "Windows" else cv2.CAP_ANY
    for i in range(max_index):
        cap = cv2.VideoCapture(i, backend)
        if cap.isOpened():
            ok, frame = cap.read()
            found.append({"index": i, "readable": bool(ok), "width": int(cap.get(cv2.CAP_PROP_FRAME_WIDTH)),
                          "height": int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT)), "fps": round(cap.get(cv2.CAP_PROP_FPS) or 0, 1)})
        cap.release()
    return found
