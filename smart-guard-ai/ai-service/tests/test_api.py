import base64
import importlib
import time

import pytest
from fastapi.testclient import TestClient

KEY = "k" * 32


@pytest.fixture()
def client(synthetic, monkeypatch, tmp_path):
    monkeypatch.setenv("AI_SERVICE_KEY", KEY)
    monkeypatch.setenv("SHARED_STORAGE_DIR", str(synthetic["storage"]))
    monkeypatch.setenv("PHP_CALLBACK_URL", "")
    import app.config as cfg
    importlib.reload(cfg)
    cfg.settings.models_dir = tmp_path / "models"
    (cfg.settings.models_dir / "galleries").mkdir(parents=True)
    import app.security as sec
    importlib.reload(sec)
    import app.main as main
    importlib.reload(main)
    with TestClient(main.app) as c:
        yield c


H = {"X-API-Key": KEY}


def test_requires_api_key(client):
    assert client.get("/health").status_code == 401
    assert client.get("/health", headers={"X-API-Key": "mauvaise"}).status_code == 401
    assert client.get("/health", headers=H).json()["ok"] is True


@pytest.mark.parametrize("url", [
    "rtsp://127.0.0.1:554/stream",           # boucle locale
    "http://localhost:8765/health",           # nom d'hôte
    "file:///etc/passwd",                     # schéma interdit
    "rtsp://8.8.8.8/stream",                  # hors réseaux autorisés
    "http://192.168.1.10:3306/",              # port de service interne
])
def test_camera_url_validation_blocks_ssrf(client, url):
    r = client.post("/cameras/test", headers=H, json={"type": "url", "url": url})
    assert r.status_code == 422


def test_path_traversal_refused(client):
    r = client.post("/detection/video", headers=H, json={"path": "../../etc/passwd"})
    assert r.status_code == 403


def test_training_and_detection_flow(client):
    products = [{"product_id": pid, "name": f"Produit {c}",
                 "images": [{"id": pid * 100 + i, "path": f"product_images/{c}/img_{i:02d}.jpg"} for i in range(12)]}
                for c, pid in {"A": 1, "B": 2, "C": 3}.items()]
    job = client.post("/training/jobs", headers=H, json={"model_id": 1, "version": 1, "products": products}).json()["job_id"]
    for _ in range(100):
        st = client.get(f"/training/jobs/{job}", headers=H).json()
        if st["status"] in ("termine", "echec"):
            break
        time.sleep(0.1)
    assert st["status"] == "termine", st
    gfile = st["result"]["gallery_file"]
    assert client.post("/recognition/activate", headers=H, json={"gallery_file": gfile}).json()["ok"]
    assert client.post("/recognition/activate", headers=H, json={"gallery_file": "../x.npz"}).status_code == 422

    with _img() as fh:
        r = client.post("/detection/image", headers=H, files={"file": ("a.jpg", fh, "image/jpeg")})
    assert r.status_code == 200
    body = r.json()
    assert body["results"][0]["label"]
    assert base64.b64decode(body["annotated_jpeg_b64"])[:2] == b"\xff\xd8"


def _img():
    import app.config as cfg
    return open(cfg.settings.storage_dir / "product_images" / "A" / "img_03.jpg", "rb")
