"""Diagnostic du service IA : versions, caméras USB visibles, configuration, accès au stockage."""
import platform
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))


def line(ok, label, detail=""):
    print(f"[{'OK' if ok else '!!'}] {label}{' : ' + detail if detail else ''}")


def main() -> int:
    errors = 0
    line(sys.version_info >= (3, 10), "Python", platform.python_version() + " " + platform.architecture()[0])
    for mod in ("numpy", "cv2", "fastapi", "uvicorn", "httpx", "psutil"):
        try:
            m = __import__(mod)
            line(True, mod, getattr(m, "__version__", ""))
        except ImportError as exc:
            errors += 1
            line(False, mod, f"absent ({exc}) — pip install -r requirements.txt")
    for mod, note in (("onnxruntime", "facultatif : empreintes ONNX"), ("ultralytics", "facultatif : YOLO (AGPL-3.0)")):
        try:
            __import__(mod)
            line(True, mod, note)
        except ImportError:
            print(f"[--] {mod} non installé ({note})")
    from app.config import settings
    line(len(settings.api_key) >= 24, "AI_SERVICE_KEY", "configurée" if settings.api_key else "ABSENTE (ai-service/.env)")
    errors += len(settings.api_key) < 24
    line(bool(settings.php_callback_url and settings.callback_token), "Envoi des événements vers PHP", settings.php_callback_url or "non configuré")
    line(settings.storage_dir.is_dir(), "Dossier storage partagé", str(settings.storage_dir))
    errors += not settings.storage_dir.is_dir()
    onnx = settings.model_path(settings.onnx_embedder_model)
    print(f"[--] Extracteur ONNX : {'présent' if onnx.is_file() else 'absent (extracteur classique utilisé)'}")
    print(f"[--] Détecteur configuré : {settings.detector}")
    from app.cameras.manager import list_usb_devices
    devs = list_usb_devices()
    line(bool(devs), "Caméras USB détectées", ", ".join(f"#{d['index']} {d['width']}x{d['height']}" for d in devs) or "aucune")
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
