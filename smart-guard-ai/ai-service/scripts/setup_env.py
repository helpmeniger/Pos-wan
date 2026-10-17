"""Préparation automatique du service IA (bibliothèque standard uniquement).

Exécuté avec le Python « système » par ai-service\\setup_ai.bat (ou automatiquement par
run_ai.bat si l'environnement est absent). Étapes :
  1. vérifie la version de Python (3.10 à 3.13 conseillés, 64 bits) ;
  2. crée l'environnement virtuel ai-service/.venv ;
  3. installe requirements.txt ;
  4. crée ai-service/.env et synchronise les secrets avec le .env de l'application PHP
     (AI_SERVICE_KEY et AI_CALLBACK_TOKEN doivent être IDENTIQUES des deux côtés).
Peut être relancé sans risque : ce qui existe déjà est conservé.
"""
from __future__ import annotations

import os
import platform
import re
import secrets
import shutil
import subprocess
import sys
from pathlib import Path

SERVICE = Path(__file__).resolve().parent.parent
ROOT = SERVICE.parent
VENV = SERVICE / ".venv"


def say(msg: str) -> None:
    print(msg, flush=True)


def read_env(path: Path) -> dict[str, str]:
    out: dict[str, str] = {}
    if path.is_file():
        for line in path.read_text(encoding="utf-8", errors="replace").splitlines():
            m = re.match(r"^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$", line)
            if m:
                v = m.group(2)
                if len(v) >= 2 and v[0] == v[-1] and v[0] in "\"'":
                    v = v[1:-1]
                out[m.group(1)] = v
    return out


def write_env(path: Path, values: dict[str, str], overwrite: bool) -> None:
    """Met à jour des clés dans un fichier .env en conservant le reste du fichier."""
    lines = path.read_text(encoding="utf-8", errors="replace").splitlines() if path.is_file() else []
    done = set()
    for i, line in enumerate(lines):
        m = re.match(r"^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$", line)
        if m and m.group(1) in values:
            done.add(m.group(1))
            if overwrite or m.group(2) == "":
                lines[i] = f"{m.group(1)}={values[m.group(1)]}"
    for k, v in values.items():
        if k not in done:
            lines.append(f"{k}={v}")
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def venv_python() -> Path:
    return VENV / ("Scripts/python.exe" if os.name == "nt" else "bin/python")


def main() -> int:
    say("=== SMART GUARD AI - preparation du service IA ===")
    v = sys.version_info
    say(f"Python {platform.python_version()} ({platform.architecture()[0]}) : {sys.executable}")
    if v < (3, 10):
        say("[ERREUR] Python 3.10 minimum est requis. Installez Python 3.12 (64 bits) depuis python.org.")
        return 1
    if platform.architecture()[0] != "64bit":
        say("[ERREUR] Python 32 bits detecte : installez la version 64 bits (OpenCV n'existe pas en 32 bits).")
        return 1
    if v >= (3, 14):
        say("[ATTENTION] Python tres recent : si l'installation d'OpenCV/numpy echoue, installez Python 3.12.")

    # 1. Environnement virtuel (recréé s'il est incomplet)
    py = venv_python()
    if VENV.exists() and not py.is_file():
        say("Environnement .venv incomplet : suppression et recreation.")
        shutil.rmtree(VENV, ignore_errors=True)
    if not py.is_file():
        say("Creation de l'environnement virtuel ai-service\\.venv ...")
        subprocess.check_call([sys.executable, "-m", "venv", str(VENV)])
    # 2. Dépendances
    say("Installation des dependances (connexion Internet necessaire la premiere fois) ...")
    subprocess.call([str(py), "-m", "pip", "install", "--upgrade", "pip"])
    rc = subprocess.call([str(py), "-m", "pip", "install", "-r", str(SERVICE / "requirements.txt")])
    if rc != 0:
        say("[ERREUR] L'installation des dependances a echoue (voir les messages ci-dessus).")
        say("         Verifiez la connexion Internet, ou installez Python 3.12 64 bits puis relancez.")
        return 1
    rc = subprocess.call([str(py), "-c", "import cv2, numpy, fastapi, uvicorn, httpx, psutil; print('Modules OK, OpenCV', cv2.__version__)"])
    if rc != 0:
        say("[ERREUR] Les modules ne se chargent pas correctement.")
        say("         Installez le « Microsoft Visual C++ Redistributable » (x64) puis relancez.")
        return 1

    # 3. Fichiers .env et secrets partagés avec PHP
    ai_env, php_env = SERVICE / ".env", ROOT / ".env"
    if not ai_env.is_file():
        shutil.copy(SERVICE / ".env.example", ai_env)
        say("Cree : ai-service\\.env")
    php = read_env(php_env)
    ai = read_env(ai_env)
    key = php.get("AI_SERVICE_KEY") or ai.get("AI_SERVICE_KEY") or secrets.token_hex(24)
    token = php.get("AI_CALLBACK_TOKEN") or ai.get("AI_CALLBACK_TOKEN") or secrets.token_hex(24)
    write_env(ai_env, {"AI_SERVICE_KEY": key, "AI_CALLBACK_TOKEN": token}, overwrite=True)
    if php_env.is_file():
        write_env(php_env, {"AI_SERVICE_KEY": key, "AI_CALLBACK_TOKEN": token}, overwrite=False)
        base = php.get("APP_BASE_PATH", "").strip()
        if base and base != "/" and ai.get("PHP_CALLBACK_URL", "") in ("", "http://127.0.0.1/smart-guard-ai/api/ai/events"):
            write_env(ai_env, {"PHP_CALLBACK_URL": f"http://127.0.0.1{base.rstrip('/')}/api/ai/events"}, overwrite=True)
        say("Secrets synchronises avec le fichier .env de l'application.")
    else:
        say("[ATTENTION] Le fichier .env de l'application (racine du projet) n'existe pas encore.")
        say("            Lancez scripts\\install.bat : les secrets seront alors synchronises.")
    (SERVICE / "runtime").mkdir(exist_ok=True)
    say("Service IA pret. Demarrage : ai-service\\run_ai.bat ou scripts\\start.bat")
    return 0


if __name__ == "__main__":
    sys.exit(main())
