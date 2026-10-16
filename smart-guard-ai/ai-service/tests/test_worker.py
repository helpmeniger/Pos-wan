"""Le CameraWorker lit la vidéo synthétique comme s'il s'agissait d'une caméra et doit
émettre statuts, pistes et franchissements avec image + extrait vidéo enregistrés."""
import json
import time

from app.cameras.worker import CameraWorker
from app.config import settings
from app.training.trainer import TrainingJobs
from app.vision.detector import MotionDetector
from app.vision.embedder import ClassicEmbedder
from app.vision.recognizer import Recognizer


class ListSender:
    def __init__(self):
        self.events = []

    def emit(self, e):
        self.events.append(e)


def test_worker_emits_events_with_media(synthetic, tmp_path, monkeypatch):
    monkeypatch.setattr(settings, "storage_dir", synthetic["storage"])
    monkeypatch.setattr(settings, "clip_post_seconds", 0.3)
    emb = ClassicEmbedder()
    jobs = TrainingJobs(emb, tmp_path, lambda rel: synthetic["storage"] / rel)
    products = [{"product_id": pid, "name": f"Produit {c}",
                 "images": [{"id": pid * 100 + i, "path": f"product_images/{c}/img_{i:02d}.jpg"} for i in range(12)]}
                for c, pid in {"A": 1, "B": 2, "C": 3}.items()]
    rec = Recognizer(emb, tmp_path)
    rec.activate(jobs.build({"model_id": 1, "version": 1, "products": products})["gallery_file"])
    exp = json.loads((synthetic["data"] / "expected.json").read_text(encoding="utf-8"))

    sender = ListSender()
    w = CameraWorker(7, {"source": {"type": "file", "path": str(synthetic["data"] / exp["video"])},
                         "analysis_fps": 5, "lines": [exp["line"]], "recording_enabled": True},
                     MotionDetector, rec, sender)
    w.start()
    deadline = time.time() + 60
    while time.time() < deadline and w._cap_thread.is_alive():
        time.sleep(0.2)
    time.sleep(1.0)
    w.stop()

    types = [e["type"] for e in sender.events]
    assert "camera_status" in types
    lines = [e for e in sender.events if e["type"] == "line_event"]
    assert lines, "aucun franchissement émis"
    for ev in lines:
        assert (synthetic["storage"] / ev["snapshot_path"]).is_file()
        if ev.get("clip_path"):
            assert any((synthetic["storage"] / ev["clip_path"]).glob("frame_*.jpg"))
    assert any(e["type"] == "track_closed" for e in sender.events)
