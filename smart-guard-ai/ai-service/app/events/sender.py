"""Envoi des événements vers l'application PHP, avec file d'attente persistante.

Si PHP/Apache est arrêté, les événements sont conservés dans runtime/outbox.jsonl puis
renvoyés automatiquement : aucun franchissement n'est perdu. PHP déduplique grâce à l'uuid.
"""
from __future__ import annotations

import json
import logging
import queue
import threading
import time
from pathlib import Path

import httpx

log = logging.getLogger("events")


class EventSender:
    def __init__(self, url: str, token: str, outbox: Path) -> None:
        self.url = url
        self.token = token
        self.outbox = outbox
        self._q: queue.Queue = queue.Queue(maxsize=10000)
        self._lock = threading.Lock()
        self._stop = threading.Event()
        self.sent = 0
        self.failed = 0
        self.last_error: str | None = None
        self._thread = threading.Thread(target=self._loop, name="event-sender", daemon=True)

    def start(self) -> None:
        if self.url and self.token:
            self._thread.start()
        else:
            log.warning("PHP_CALLBACK_URL ou AI_CALLBACK_TOKEN absent : les événements sont seulement conservés localement.")

    def stop(self) -> None:
        self._stop.set()

    def emit(self, event: dict) -> None:
        event.setdefault("emitted_at", time.strftime("%Y-%m-%d %H:%M:%S"))
        try:
            self._q.put_nowait(event)
        except queue.Full:
            self._persist([event])

    def pending(self) -> int:
        n = self._q.qsize()
        if self.outbox.is_file():
            with self._lock:
                n += sum(1 for _ in self.outbox.open(encoding="utf-8"))
        return n

    def _persist(self, events: list[dict]) -> None:
        with self._lock, self.outbox.open("a", encoding="utf-8") as fh:
            for e in events:
                fh.write(json.dumps(e, ensure_ascii=False) + "\n")

    def _post(self, events: list[dict]) -> bool:
        try:
            r = httpx.post(self.url, json={"events": events}, headers={"X-AI-Token": self.token}, timeout=10.0)
            if r.status_code == 200:
                self.sent += len(events)
                self.last_error = None
                return True
            self.last_error = f"HTTP {r.status_code}: {r.text[:200]}"
            if 400 <= r.status_code < 500 and r.status_code not in (401, 403, 408, 429):
                log.error("Événements rejetés par PHP (%s) — conservés dans l'outbox de rejet.", self.last_error)
                with (self.outbox.parent / "rejected.jsonl").open("a", encoding="utf-8") as fh:
                    for e in events:
                        fh.write(json.dumps(e, ensure_ascii=False) + "\n")
                return True
        except httpx.HTTPError as exc:
            self.last_error = str(exc)
        self.failed += 1
        return False

    def _flush_outbox(self) -> None:
        if not self.outbox.is_file():
            return
        with self._lock:
            lines = self.outbox.read_text(encoding="utf-8").splitlines()
        if not lines:
            return
        events = [json.loads(line) for line in lines if line.strip()]
        for i in range(0, len(events), 50):
            if not self._post(events[i:i + 50]):
                with self._lock:
                    remaining = events[i:]
                    self.outbox.write_text("".join(json.dumps(e, ensure_ascii=False) + "\n" for e in remaining), encoding="utf-8")
                return
        with self._lock:
            self.outbox.unlink(missing_ok=True)

    def _loop(self) -> None:
        last_outbox_try = 0.0
        while not self._stop.is_set():
            batch = []
            try:
                batch.append(self._q.get(timeout=1.0))
                while len(batch) < 50:
                    batch.append(self._q.get_nowait())
            except queue.Empty:
                pass
            if batch and not self._post(batch):
                self._persist(batch)
            if time.time() - last_outbox_try > 15:
                last_outbox_try = time.time()
                self._flush_outbox()
