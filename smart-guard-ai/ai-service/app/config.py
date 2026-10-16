"""Configuration du service IA, lue depuis ai-service/.env puis les variables d'environnement."""
from __future__ import annotations

import ipaddress
import os
from dataclasses import dataclass, field
from pathlib import Path

SERVICE_DIR = Path(__file__).resolve().parent.parent


def _load_env_file(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            value = value[1:-1]
        values[key.strip()] = value
    return values


@dataclass
class Settings:
    host: str = "127.0.0.1"
    port: int = 8765
    api_key: str = ""
    php_callback_url: str = ""
    callback_token: str = ""
    storage_dir: Path = field(default_factory=lambda: (SERVICE_DIR.parent / "storage").resolve())
    models_dir: Path = field(default_factory=lambda: (SERVICE_DIR / "models").resolve())
    runtime_dir: Path = field(default_factory=lambda: (SERVICE_DIR / "runtime").resolve())
    allowed_networks: list = field(default_factory=list)
    detector: str = "motion"
    yolo_model: str = "models/yolov8n.pt"
    embedder: str = "auto"
    onnx_embedder_model: str = "models/embedder.onnx"
    max_cameras: int = 4
    clip_pre_seconds: float = 3.0
    clip_post_seconds: float = 2.0
    max_upload_mb: int = 10
    log_level: str = "INFO"

    @classmethod
    def load(cls) -> "Settings":
        env = _load_env_file(SERVICE_DIR / ".env")
        env.update({k: v for k, v in os.environ.items() if k.startswith(("AI_", "PHP_", "SHARED_", "CAMERA_", "DETECTOR", "YOLO_", "EMBEDDER", "ONNX_", "MAX_", "CLIP_", "LOG_"))})
        s = cls()
        s.host = env.get("AI_HOST", s.host)
        s.port = int(env.get("AI_PORT", s.port))
        s.api_key = env.get("AI_SERVICE_KEY", "")
        s.php_callback_url = env.get("PHP_CALLBACK_URL", "")
        s.callback_token = env.get("AI_CALLBACK_TOKEN", "")
        if env.get("SHARED_STORAGE_DIR"):
            p = Path(env["SHARED_STORAGE_DIR"])
            s.storage_dir = (p if p.is_absolute() else SERVICE_DIR / p).resolve()
        nets = env.get("CAMERA_ALLOWED_NETWORKS", "192.168.0.0/16,10.0.0.0/8,172.16.0.0/12")
        s.allowed_networks = [ipaddress.ip_network(n.strip(), strict=False) for n in nets.split(",") if n.strip()]
        s.detector = env.get("DETECTOR", s.detector).lower()
        s.yolo_model = env.get("YOLO_MODEL", s.yolo_model)
        s.embedder = env.get("EMBEDDER", s.embedder).lower()
        s.onnx_embedder_model = env.get("ONNX_EMBEDDER_MODEL", s.onnx_embedder_model)
        s.max_cameras = int(env.get("MAX_CAMERAS", s.max_cameras))
        s.clip_pre_seconds = float(env.get("CLIP_PRE_SECONDS", s.clip_pre_seconds))
        s.clip_post_seconds = float(env.get("CLIP_POST_SECONDS", s.clip_post_seconds))
        s.max_upload_mb = int(env.get("MAX_UPLOAD_MB", s.max_upload_mb))
        s.log_level = env.get("LOG_LEVEL", s.log_level).upper()
        s.runtime_dir.mkdir(parents=True, exist_ok=True)
        (s.models_dir / "galleries").mkdir(parents=True, exist_ok=True)
        return s

    def model_path(self, rel: str) -> Path:
        p = Path(rel)
        return p if p.is_absolute() else (SERVICE_DIR / p).resolve()


settings = Settings.load()
