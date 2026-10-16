"""Contrôles de sécurité : clé d'API, validation des chemins et des sources vidéo."""
from __future__ import annotations

import hmac
import ipaddress
import re
import socket
from pathlib import Path
from urllib.parse import urlsplit

from fastapi import Header, HTTPException

from .config import settings


def require_api_key(x_api_key: str | None = Header(default=None)) -> None:
    """Dépendance FastAPI : refuse tout appel sans la clé partagée."""
    if not settings.api_key or len(settings.api_key) < 24:
        raise HTTPException(status_code=503, detail="AI_SERVICE_KEY non configurée (24 caractères minimum).")
    if not x_api_key or not hmac.compare_digest(x_api_key.encode(), settings.api_key.encode()):
        raise HTTPException(status_code=401, detail="Clé d'API invalide.")


def safe_storage_path(rel: str, subdirs: tuple[str, ...]) -> Path:
    """Résout un chemin relatif fourni par PHP et vérifie qu'il reste dans storage/<subdir>."""
    if not rel or "\x00" in rel or len(rel) > 400:
        raise HTTPException(status_code=422, detail="Chemin invalide.")
    rel = rel.replace("\\", "/").lstrip("/")
    candidate = (settings.storage_dir / rel).resolve()
    for sub in subdirs:
        root = (settings.storage_dir / sub).resolve()
        if candidate == root or root in candidate.parents:
            return candidate
    raise HTTPException(status_code=403, detail="Chemin hors des dossiers autorisés.")


_HOST_RE = re.compile(r"^[A-Za-z0-9.\-:\[\]]+$")


def validate_stream_url(url: str) -> str:
    """Valide l'URL d'une caméra IP : schéma autorisé, hôte = adresse IP d'un réseau autorisé.

    Empêche l'utilisation du service IA pour atteindre des services internes arbitraires (SSRF) :
    seules les adresses IP littérales appartenant à CAMERA_ALLOWED_NETWORKS sont acceptées,
    jamais 127.0.0.0/8 ni les noms d'hôte (qui pourraient être résolus vers une autre cible).
    """
    if len(url) > 500 or any(c in url for c in "\r\n\x00 "):
        raise ValueError("URL de flux invalide.")
    parts = urlsplit(url)
    if parts.scheme not in ("rtsp", "rtsps", "http", "https"):
        raise ValueError("Schéma non autorisé (rtsp, rtsps, http, https uniquement).")
    host = parts.hostname or ""
    if not host or not _HOST_RE.match(host):
        raise ValueError("Hôte de caméra invalide.")
    try:
        ip = ipaddress.ip_address(host)
    except ValueError as exc:
        raise ValueError("L'adresse de la caméra doit être une adresse IP (pas un nom d'hôte).") from exc
    if ip.is_loopback or ip.is_unspecified or ip.is_multicast or ip.is_link_local:
        raise ValueError("Adresse IP de caméra non autorisée.")
    if not any(ip in net for net in settings.allowed_networks):
        raise ValueError("Adresse IP hors des réseaux de caméras autorisés (CAMERA_ALLOWED_NETWORKS).")
    if parts.port is not None and not (1 <= parts.port <= 65535):
        raise ValueError("Port invalide.")
    if parts.port in (3306, 22, 23, 25, 445, 3389, 8765):
        raise ValueError("Port non autorisé pour un flux vidéo.")
    return url


def mask_url(url: str) -> str:
    """Masque les identifiants d'une URL pour les journaux."""
    parts = urlsplit(url)
    if parts.username or parts.password:
        netloc = "***:***@" + (parts.hostname or "") + (f":{parts.port}" if parts.port else "")
        return parts._replace(netloc=netloc).geturl()
    return url


def tcp_reachable(host: str, port: int, timeout: float = 2.0) -> bool:
    try:
        with socket.create_connection((host, port), timeout=timeout):
            return True
    except OSError:
        return False
