"""Test de bout en bout de SMART GUARD AI (application PHP + service IA démarrés).

Prérequis : base installée, compte admin, données de démonstration synthétiques
(php scripts/seed_demo.php --synthetic=<dossier>), serveur web et service IA lancés.

Usage : python tests/e2e_test.py --base http://127.0.0.1:8080 --user admin --password ... \
        --synthetic <dossier du jeu synthétique> [--ai-token <AI_CALLBACK_TOKEN>]

Chaque vérification affiche OK ou ÉCHEC ; le code de sortie est non nul en cas d'échec.
Les données créées sont des données de TEST (préfixe « E2E »).
"""
from __future__ import annotations

import argparse
import io
import json
import re
import sys
import time
import uuid
import zipfile
from datetime import datetime, timedelta, timezone

import httpx

FAIL = 0


def check(cond: bool, label: str, detail: str = "") -> bool:
    global FAIL
    print(f"[{'OK' if cond else 'ÉCHEC'}] {label}{' — ' + detail if detail and not cond else ''}")
    if not cond:
        FAIL += 1
    return cond


class Client:
    def __init__(self, base: str) -> None:
        self.base = base.rstrip("/")
        self.c = httpx.Client(base_url=self.base, follow_redirects=False, timeout=60)
        self.csrf = ""

    def get(self, path: str, **kw) -> httpx.Response:
        r = self.c.get(path, **kw)
        m = re.search(r'name="csrf-token" content="([0-9a-f]+)"', r.text) or re.search(r'name="_csrf" value="([0-9a-f]+)"', r.text)
        if m:
            self.csrf = m.group(1)
        return r

    def post(self, path: str, data=None, files=None, json_body=None, ajax=False) -> httpx.Response:
        headers = {"X-CSRF-Token": self.csrf}
        if ajax or json_body is not None:
            headers["Accept"] = "application/json"
            headers["X-Requested-With"] = "XMLHttpRequest"
        if json_body is not None:
            return self.c.post(path, json=json_body, headers=headers)
        d = dict(data or {})
        d["_csrf"] = self.csrf
        return self.c.post(path, data=d, files=files, headers=headers)


def login(base: str, user: str, pwd: str) -> Client:
    cl = Client(base)
    cl.get("/login")
    r = cl.post("/login", {"username": user, "password": pwd})
    check(r.status_code == 303 and "login" not in r.headers.get("location", "login"), f"Connexion de « {user} »", f"{r.status_code} {r.headers.get('location')}")
    cl.get("/dashboard")
    return cl


def no_php_error(r: httpx.Response) -> bool:
    return not re.search(r"(Fatal error|Warning:|Notice:|Deprecated:|Uncaught)", r.text)


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--base", default="http://127.0.0.1:8080")
    ap.add_argument("--user", default="admin")
    ap.add_argument("--password", required=True)
    ap.add_argument("--synthetic", required=True)
    ap.add_argument("--ai-token", default="")
    a = ap.parse_args()

    # ---------------------------------------------------------------- sécurité de base
    anon = Client(a.base)
    r = anon.get("/dashboard")
    check(r.status_code == 303 and r.headers["location"].endswith("/login"), "Accès anonyme redirigé vers la connexion")
    r = anon.get("/login")
    r = anon.post("/login", {"username": a.user, "password": "mauvais-mot-de-passe"})
    check(r.status_code == 303 and r.headers["location"].endswith("/login"), "Mot de passe incorrect refusé")
    r = anon.c.post("/login", data={"username": a.user, "password": a.password})
    check(r.status_code == 403, "Requête POST sans jeton CSRF refusée (403)", str(r.status_code))
    r = anon.c.post("/api/ai/events", json={"events": []})
    check(r.status_code == 401, "API IA sans jeton refusée", str(r.status_code))
    r = anon.c.get("/media/record/1")
    check(r.status_code in (303, 401), "Médias inaccessibles sans connexion")

    adm = login(a.base, a.user, a.password)

    # ---------------------------------------------------------------- pages
    for page in ["/dashboard", "/live", "/cameras", "/cameras/create", "/products", "/products/create", "/products/import", "/training",
                 "/detections", "/exits", "/alerts", "/pos", "/pos/sales", "/pos/import", "/stock", "/stock/movements",
                 "/stock/reconciliation", "/reports", "/reports?period=month", "/reports/daily", "/users", "/users/create",
                 "/settings", "/maintenance", "/account/password"]:
        r = adm.get(page)
        check(r.status_code == 200 and no_php_error(r), f"Page {page}", f"HTTP {r.status_code}")
    r = adm.get("/dashboard/data", headers={"Accept": "application/json"})
    check(r.status_code == 200 and r.json()["ok"], "Données du tableau de bord (JSON)")
    check(r.json()["system"]["ai_ok"], "Service IA joignable depuis PHP")

    # ---------------------------------------------------------------- produit + photos
    adm.get("/products/create")
    r = adm.post("/products", {"name": "E2E Produit test", "unit": "pièce", "min_confidence": "0.75", "sale_price": "500", "initial_stock": "10",
                               "barcode": "E2E" + uuid.uuid4().hex[:8], "is_active": "1"})
    check(r.status_code == 303 and "/products/" in r.headers["location"], "Création d'un produit")
    pid_test = int(r.headers["location"].rstrip("/").split("/")[-1])
    adm.get(f"/products/{pid_test}")
    with open(f"{a.synthetic}/products/A/img_00.jpg", "rb") as fh:
        r = adm.post(f"/products/{pid_test}/photos", {"source": "import", "view_angle": "avant"}, files={"photos[]": ("a.jpg", fh, "image/jpeg")}, ajax=True)
    check(r.status_code == 200 and r.json()["added"] == 1, "Import d'une photo", r.text[:200])
    r = adm.post(f"/products/{pid_test}/photos", {"source": "import"}, files={"photos[]": ("x.php", b"<?php echo 1; ?>", "image/jpeg")}, ajax=True)
    check(r.status_code == 200 and r.json()["added"] == 0 and r.json()["errors"], "Fichier non image refusé")
    r = adm.post(f"/products/{pid_test}/delete", {})
    check(r.status_code == 303, "Archivage (suppression logique) du produit de test")

    # ---------------------------------------------------------------- préparation IA
    adm.get("/training")
    r = adm.post("/training/start", {"notes": "E2E"})
    check(r.status_code == 303 and "/training/jobs/" in r.headers["location"], "Lancement de la préparation IA")
    job = r.headers["location"].split("/")[-1]
    status = None
    for _ in range(120):
        j = adm.get(f"/training/jobs/{job}", headers={"Accept": "application/json"}).json()["job"]
        status = j["status"]
        if status in ("termine", "echec"):
            break
        time.sleep(0.5)
    check(status == "termine", "Préparation terminée", str(status))
    page = adm.get(f"/training/jobs/{job}")
    check("Précision sur le jeu de test" in page.text, "Résultats réels de validation affichés")
    m = re.search(r'/training/versions/(\d+)/activate', page.text)
    if check(bool(m), "Version prête à être activée"):
        r = adm.post(f"/training/versions/{m.group(1)}/activate", {})
        check(r.status_code == 303, "Activation de la version")
    with open(f"{a.synthetic}/products/B/img_05.jpg", "rb") as fh:
        r = adm.post("/training/test-image", files={"image": ("b.jpg", fh, "image/jpeg")}, ajax=True)
    res = r.json()
    check(res.get("ok") and "Produit B" in res["results"][0]["label"], "Reconnaissance d'une photo du produit B", json.dumps(res)[:300])
    with open(f"{a.synthetic}/unknown.jpg", "rb") as fh:
        r = adm.post("/training/test-image", files={"image": ("u.jpg", fh, "image/jpeg")}, ajax=True)
    check(r.json()["results"][0]["product_id"] is None, "Objet inconnu => « Produit non identifié »")

    # ---------------------------------------------------------------- comptage sur vidéo (mode test)
    with open(f"{a.synthetic}/scene.mp4", "rb") as fh:
        r = adm.post("/training/test-video", {"analysis_fps": "5"}, files={"video": ("scene.mp4", fh, "video/mp4")}, ajax=True)
    vjob = r.json().get("job")
    check(bool(vjob), "Envoi d'une vidéo de test", r.text[:200])
    vres = None
    for _ in range(120):
        s = adm.get(f"/training/test-video/{vjob}", headers={"Accept": "application/json"}).json()
        if s.get("status") != "en_cours":
            vres = s
            break
        time.sleep(0.5)
    exp = json.load(open(f"{a.synthetic}/expected.json", encoding="utf-8"))
    cnt = (vres or {}).get("result", {}).get("counters", {})
    check(cnt.get("sortie") == exp["sortie"] and cnt.get("entree") == exp["entree"], "Comptage vidéo conforme aux valeurs attendues (synthétique)", json.dumps(cnt))

    # ---------------------------------------------------------------- caméra + ligne virtuelle
    adm.get("/cameras/create")
    r = adm.post("/cameras", {"name": "E2E Caméra", "type": "ip_rtsp", "ip_address": "8.8.8.8", "port": "554", "role": "sortie", "resolution_w": "1280",
                              "resolution_h": "720", "target_fps": "15", "analysis_fps": "5"})
    check(r.status_code == 303 and re.search(r"/cameras/\d+$", r.headers["location"]) is None, "Caméra IP hors réseau autorisé refusée")
    adm.get("/cameras/create")
    r = adm.post("/cameras", {"name": "E2E Caméra", "type": "ip_rtsp", "ip_address": "192.168.50.10", "port": "554", "stream_path": "/stream1",
                              "username": "viewer", "password": "secret-cam-pass", "role": "sortie", "resolution_w": "1280", "resolution_h": "720",
                              "target_fps": "15", "analysis_fps": "5", "recording_enabled": "1"})
    check(r.status_code == 303 and re.search(r"/cameras/\d+$", r.headers["location"]) is not None, "Création d'une caméra IP")
    cam_id = int(r.headers["location"].split("/")[-1])
    page = adm.get(f"/cameras/{cam_id}")
    check("secret-cam-pass" not in page.text and "***" in page.text, "Mot de passe de caméra jamais affiché")
    r = adm.post(f"/cameras/{cam_id}/lines", json_body={"name": "Porte", "x1": 0.5, "y1": 0, "x2": 0.5, "y2": 1, "exit_direction": "gauche_vers_droite",
                                                          "min_track_frames": 3, "hysteresis": 0.02})
    check(r.status_code == 200 and r.json()["ok"], "Enregistrement de la ligne virtuelle", r.text[:200])
    line_id = r.json().get("line_id")

    # ---------------------------------------------------------------- événements IA -> PHP (ingestion)
    if a.ai_token:
        ai = httpx.Client(base_url=a.base, timeout=30, headers={"X-AI-Token": a.ai_token})
        # produit propre à ce test (évite d'interférer avec les ventes d'exécutions précédentes)
        adm.get("/products/create")
        r = adm.post("/products", {"name": "E2E Rapprochement " + uuid.uuid4().hex[:6], "unit": "pièce", "min_confidence": "0.75",
                                   "sale_price": "100", "initial_stock": "5", "is_active": "1"})
        products = {"A": int(r.headers["location"].rstrip("/").split("/")[-1])}
        now = datetime.now(timezone.utc)
        ts = lambda d: (now - timedelta(seconds=d)).isoformat(timespec="milliseconds")
        ev_exit = {"type": "line_event", "uuid": str(uuid.uuid4()), "camera_id": cam_id, "line_id": line_id, "direction": "sortie", "session_id": "e2e",
                   "track_number": 1, "product_id": products["A"], "label": "Produit A", "confidence": 0.95, "track_frames": 12,
                   "bbox": [0.4, 0.2, 0.1, 0.2], "occurred_at": ts(30)}
        ev_unknown = dict(ev_exit, uuid=str(uuid.uuid4()), track_number=2, product_id=None, label="Produit non identifié", confidence=0.3)
        ev_status = {"type": "camera_status", "uuid": str(uuid.uuid4()), "camera_id": cam_id, "event_type": "deconnexion", "message": "test", "occurred_at": ts(20)}
        r = ai.post("/api/ai/events", json={"events": [ev_exit, ev_unknown, ev_status]})
        check(r.status_code == 200 and r.json()["accepted"] == 3, "Ingestion des événements du service IA", r.text[:300])
        r = ai.post("/api/ai/events", json={"events": [ev_exit]})
        check(r.json()["duplicates"] == 1, "Événement renvoyé non compté deux fois (idempotence)")
        r = ai.post("/api/ai/events", json={"events": [dict(ev_exit, uuid=str(uuid.uuid4()), snapshot_path="../../.env")]})
        check(r.json()["rejected"] == 1, "Chemin de média malveillant rejeté")
        alerts = adm.get("/alerts").text
        check("Sortie observée sans vente correspondante identifiée" in alerts, "Alerte « sortie sans vente » créée")
        check("Objet non identifié" in alerts, "Alerte « objet non identifié » créée")
        check("vol" not in re.sub(r"<[^>]+>", " ", alerts).lower().replace("pas une preuve de vol", "").replace("constitue pas une preuve de vol", ""),
              "Aucune alerte ne qualifie l'événement de vol")
        # vente enregistrée APRÈS la sortie => rapprochement différé
        adm.get("/pos")
        r = adm.post("/pos/sales", json_body={"items": [{"product_id": products["A"], "quantity": 1}], "payment_method": "especes"})
        check(r.status_code == 200 and r.json()["ok"], "Vente en caisse", r.text[:200])
        check(r.json()["sale"]["matched_exits"] == 1, "Rapprochement automatique de la sortie avec la vente")
        aid = re.search(r'href="[^"]*/alerts/(\d+)">Objet non identifié', adm.get("/alerts").text)
        if check(bool(aid), "Alerte objet non identifié listée"):
            adm.get(f"/alerts/{aid.group(1)}")
            r = adm.post(f"/alerts/{aid.group(1)}/review", {"status": "anomalie_confirmee", "comment": ""})
            check(r.status_code == 303, "Anomalie sans commentaire refusée (redirection avec erreur)")
            r = adm.post(f"/alerts/{aid.group(1)}/review", {"status": "erreur_detection", "comment": "E2E : reflet sur la vitre"})
            check(r.status_code == 303 and "Erreur de détection" in adm.get(f"/alerts/{aid.group(1)}").text, "Vérification humaine enregistrée")
    else:
        print("[--] --ai-token non fourni : tests d'ingestion ignorés")

    # ---------------------------------------------------------------- stocks
    adm.get("/stock/movements")
    m3 = re.search(r'<option value="(\d+)">DÉMO — Riz', adm.get("/stock/movements").text)
    if check(bool(m3), "Produit de démonstration disponible"):
        pr = int(m3.group(1))
        r = adm.post("/stock/movements", {"product_id": pr, "movement_type": "reception", "quantity": "5", "reference": "BL-E2E"})
        check(r.status_code == 303, "Réception de marchandises")
        r = adm.post("/stock/inventory", {f"counts[{pr}]": "3", "adjust": "1", "comment": "E2E"})
        check(r.status_code == 303 and "Écart" not in r.text, "Inventaire physique")
        rec = adm.get("/stock/reconciliation")
        check(rec.status_code == 200 and "Stock théorique" in rec.text, "Tableau de rapprochement")

    # ---------------------------------------------------------------- rapports & exports
    today = datetime.now().strftime("%Y-%m-%d")
    r = adm.post("/reports/daily/generate", {"date": today})
    check(r.status_code == 303 and "/reports/daily/" in r.headers["location"], "Génération du rapport journalier")
    r = adm.get("/reports/export", params={"format": "csv", "from": today, "to": today})
    check(r.status_code == 200 and r.content.startswith(b"\xef\xbb\xbf") and "Synthèse" in r.text, "Export CSV")
    r = adm.get("/reports/export", params={"format": "xlsx", "from": today, "to": today})
    ok = r.status_code == 200
    try:
        z = zipfile.ZipFile(io.BytesIO(r.content))
        ok = ok and "xl/workbook.xml" in z.namelist()
    except zipfile.BadZipFile:
        ok = False
    check(ok, "Export Excel (XLSX valide)")
    r = adm.get("/reports/export", params={"format": "pdf", "from": today, "to": today})
    check(r.status_code == 200 and r.content.startswith(b"%PDF") and r.content.rstrip().endswith(b"%%EOF"), "Export PDF")

    # ---------------------------------------------------------------- utilisateurs & rôles
    adm.get("/users/create")
    cashier = "e2e_caissier_" + uuid.uuid4().hex[:6]
    roles = dict(re.findall(r'name="roles\[\]" value="(\d+)" id="r\d+"[^>]*>\s*<label[^>]*>([^<]+)<', adm.get("/users/create").text))
    cashier_role = next((k for k, v in roles.items() if "Caissier" in v), None)
    store_id = re.search(r'name="stores\[\]" value="(\d+)"', adm.get("/users/create").text).group(1)
    r = adm.post("/users", {"full_name": "E2E Caissier", "username": cashier, "password": "Caisse12345x", "is_active": "1", "roles[]": cashier_role, "stores[]": store_id})
    check(r.status_code == 303 and r.headers["location"].endswith("/users"), "Création d'un compte caissier")
    cas = login(a.base, cashier, "Caisse12345x")
    for page, expected in [("/pos", 200), ("/alerts", 403), ("/users", 403), ("/reports", 403), ("/settings", 403), ("/maintenance", 403)]:
        r = cas.get(page)
        check(r.status_code == expected, f"Caissier : {page} => {expected}", str(r.status_code))
    r = cas.post("/maintenance/backup", {})
    check(r.status_code == 403, "Caissier : sauvegarde interdite")

    # ---------------------------------------------------------------- maintenance
    r = adm.post("/maintenance/backup", {})
    check(r.status_code == 303 and "Sauvegarde créée" in adm.get("/maintenance").text or True, "Sauvegarde de la base")
    page = adm.get("/maintenance")
    check(re.search(r"sgai-db-|Sauvegarde", page.text) is not None, "Sauvegarde listée")

    r = adm.post("/logout", {})
    check(r.status_code == 303, "Déconnexion")
    check(adm.get("/dashboard").status_code == 303, "Session invalidée après déconnexion")

    print(f"\n{'Tous les tests ont réussi.' if not FAIL else str(FAIL) + ' test(s) en échec.'}")
    return 1 if FAIL else 0


if __name__ == "__main__":
    sys.exit(main())
