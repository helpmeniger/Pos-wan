"""Test de bout en bout sur données SYNTHÉTIQUES : préparation, validation, suivi, comptage.
Ce test vérifie le logiciel ; il ne mesure pas la précision sur de vrais produits."""
import json

import cv2

from app.training.trainer import TrainingJobs
from app.vision.detector import MotionDetector
from app.vision.embedder import ClassicEmbedder
from app.vision.line_counter import LineConfig
from app.vision.pipeline import Pipeline, PipelineConfig
from app.vision.recognizer import UNKNOWN_LABEL, Recognizer

IDS = {"A": 1, "B": 2, "C": 3}


def _train(synthetic, tmp_path, min_images=8):
    emb = ClassicEmbedder()
    jobs = TrainingJobs(emb, tmp_path, lambda rel: synthetic["storage"] / rel)
    products = [{"product_id": pid, "name": f"Produit {c}", "min_confidence": 0.75,
                 "images": [{"id": pid * 100 + i, "path": f"product_images/{c}/img_{i:02d}.jpg"} for i in range(12)]}
                for c, pid in IDS.items()]
    res = jobs.build({"model_id": 1, "version": 1, "min_images": min_images, "products": products})
    rec = Recognizer(emb, tmp_path)
    if res["gallery_file"]:
        rec.activate(res["gallery_file"])
    return res, rec


def test_training_reports_real_validation(synthetic, tmp_path):
    res, rec = _train(synthetic, tmp_path)
    assert res["status"] == "prete"
    m = res["metrics"]
    assert m["test_images"] == 9                     # 3 photos de test par produit, jamais en référence
    assert m["correct"] + m["wrong"] + m["unidentified"] == m["test_images"]
    assert (tmp_path / "gallery_m1_v1.npz").is_file()
    assert rec.status()["products"] == 3


def test_unknown_object_is_not_named(synthetic, tmp_path):
    _, rec = _train(synthetic, tmp_path)
    m = rec.recognize(cv2.imread(str(synthetic["data"] / "unknown.jpg")))
    assert m.product_id is None and m.label == UNKNOWN_LABEL


def test_insufficient_photos_are_reported(synthetic, tmp_path):
    res, _ = _train(synthetic, tmp_path, min_images=20)
    assert res["status"] == "echec" and res["gallery_file"] is None
    assert all(p["issues"] for p in res["products"])


def test_video_counts_match_expected(synthetic, tmp_path):
    _, rec = _train(synthetic, tmp_path)
    exp = json.loads((synthetic["data"] / "expected.json").read_text(encoding="utf-8"))
    cap = cv2.VideoCapture(str(synthetic["data"] / exp["video"]))
    pipe = Pipeline(MotionDetector(), rec, [LineConfig.from_dict(exp["line"])], PipelineConfig(analysis_fps=5))
    events, i = [], 0
    while True:
        ok, frame = cap.read()
        if not ok:
            break
        if i % 5 == 0:
            events += pipe.process(pipe.prepare(frame), 1000 + i / 25).line_events
        i += 1
    exits = [e for e in events if e["direction"] == "sortie"]
    entries = [e for e in events if e["direction"] == "entree"]
    assert len(exits) == exp["sortie"]
    assert len(entries) == exp["entree"]
    assert sorted(str(e["product_id"]) for e in exits) == sorted(["1", "3", "None"])
    assert entries[0]["product_id"] == 2
    # pas de double comptage : un seul événement par piste
    assert len({e["track_number"] for e in events}) == len(events)
