"""Suivi multi-objets inspiré de ByteTrack (association en deux étapes, haute puis basse confiance).

Implémentation autonome (aucune dépendance) :
- prédiction à vitesse constante pour gérer les mouvements rapides ;
- conservation des pistes « perdues » pendant max_lost images pour les ré-associer après une
  occultation (personne qui passe devant, objet masqué) ;
- repli sur la distance des centres lorsque l'IoU est nulle (faible fréquence d'analyse).
Les identifiants sont temporaires et propres à une session d'analyse.
"""
from __future__ import annotations

from collections import deque
from dataclasses import dataclass, field

from .geometry import Detection, iou

TENTATIVE, CONFIRMED, LOST, REMOVED = "tentative", "confirmed", "lost", "removed"


@dataclass
class Track:
    track_id: int
    box: tuple[float, float, float, float]        # x1, y1, x2, y2 (pixels)
    score: float
    det_label: str
    first_ts: float
    last_ts: float
    state: str = TENTATIVE
    hits: int = 1
    frames_seen: int = 1
    time_since_update: int = 0
    vx: float = 0.0
    vy: float = 0.0
    best_score: float = 0.0
    centers: deque = field(default_factory=lambda: deque(maxlen=64))
    # Reconnaissance (votes)
    recog_count: int = 0
    votes: dict = field(default_factory=dict)       # product_id -> [somme des confiances, nombre]
    unknown_votes: int = 0
    best_match: dict | None = None
    best_crop_box: tuple | None = None
    end_reason: str = "actif"

    @property
    def center(self) -> tuple[float, float]:
        return (self.box[0] + self.box[2]) / 2.0, (self.box[1] + self.box[3]) / 2.0

    def predicted_box(self) -> tuple[float, float, float, float]:
        k = self.time_since_update + 1
        dx, dy = self.vx * k, self.vy * k
        return self.box[0] + dx, self.box[1] + dy, self.box[2] + dx, self.box[3] + dy

    def identity(self) -> tuple[int | None, float]:
        """Produit retenu par vote majoritaire pondéré, sinon (None, meilleure confiance)."""
        if not self.votes:
            return None, (self.best_match or {}).get("confidence", 0.0) if self.best_match else 0.0
        pid, (total, n) = max(self.votes.items(), key=lambda kv: kv[1][0])
        identified_votes = sum(v[1] for v in self.votes.values())
        if n < max(1, round(0.4 * (identified_votes + self.unknown_votes))):
            return None, total / n
        return int(pid), total / n


class ByteTracker:
    def __init__(self, high_thresh: float = 0.5, low_thresh: float = 0.15, max_lost: int = 30,
                 min_hits: int = 2, center_gate: float = 0.7) -> None:
        self.high = high_thresh
        self.low = low_thresh
        self.max_lost = max_lost
        self.min_hits = min_hits
        self.center_gate = center_gate
        self.tracks: list[Track] = []
        self._next_id = 1

    def _score(self, t: Track, d: Detection) -> float:
        pb = t.predicted_box()
        dcx, dcy = d.x + d.w / 2, d.y + d.h / 2
        # Cohérence du mouvement : une piste rapide ne repart pas brutalement en sens inverse
        # (évite d'attribuer l'identifiant d'un objet sortant à un nouvel objet qui entre).
        speed = (t.vx ** 2 + t.vy ** 2) ** 0.5
        if speed > 2.0 and t.hits >= 3:
            cx, cy = t.center
            mx, my = dcx - cx, dcy - cy
            if (mx * t.vx + my * t.vy) < -0.25 * speed * max(1.0, (mx * mx + my * my) ** 0.5):
                return 0.0
        ov = iou(pb, d.xyxy)
        if ov >= 0.05:
            return ov
        pw, ph = pb[2] - pb[0], pb[3] - pb[1]
        diag = max(1.0, (pw ** 2 + ph ** 2) ** 0.5)
        pcx, pcy = (pb[0] + pb[2]) / 2, (pb[1] + pb[3]) / 2
        dist = ((pcx - dcx) ** 2 + (pcy - dcy) ** 2) ** 0.5 / diag
        ratio = (d.w * d.h) / max(1.0, pw * ph)
        gate = self.center_gate * (1 + 0.25 * min(t.time_since_update, 4))
        if dist < gate and 0.33 < ratio < 3.0:
            return 0.049 * (1 - dist / gate)
        return 0.0

    def _associate(self, tracks: list[Track], dets: list[Detection], min_score: float):
        pairs = []
        for ti, t in enumerate(tracks):
            for di, d in enumerate(dets):
                s = self._score(t, d)
                if s > min_score:
                    pairs.append((s, ti, di))
        pairs.sort(reverse=True)
        used_t, used_d, matches = set(), set(), []
        for s, ti, di in pairs:
            if ti in used_t or di in used_d:
                continue
            used_t.add(ti)
            used_d.add(di)
            matches.append((ti, di))
        return matches, [i for i in range(len(tracks)) if i not in used_t], [i for i in range(len(dets)) if i not in used_d]

    def _apply(self, t: Track, d: Detection, ts: float) -> None:
        old_c = t.center
        t.box = d.xyxy
        new_c = t.center
        steps = t.time_since_update + 1
        a = 0.6
        t.vx = a * ((new_c[0] - old_c[0]) / steps) + (1 - a) * t.vx
        t.vy = a * ((new_c[1] - old_c[1]) / steps) + (1 - a) * t.vy
        t.score = d.score
        t.best_score = max(t.best_score, d.score)
        t.hits += 1
        t.frames_seen += 1
        t.time_since_update = 0
        t.last_ts = ts
        t.centers.append(new_c)
        if t.state in (TENTATIVE, LOST) and t.hits >= self.min_hits:
            t.state = CONFIRMED
        elif t.state == LOST:
            t.state = CONFIRMED

    def update(self, dets: list[Detection], ts: float) -> tuple[list[Track], list[Track]]:
        """Retourne (pistes visibles dans cette image, pistes supprimées à cette étape)."""
        high = [d for d in dets if d.score >= self.high]
        low = [d for d in dets if self.low <= d.score < self.high]
        active = [t for t in self.tracks if t.state != REMOVED]

        # Étape 1 : détections fiables <-> toutes les pistes (y compris perdues)
        m1, un_t, un_d = self._associate(active, high, 0.0)
        for ti, di in m1:
            self._apply(active[ti], high[di], ts)
        # Étape 2 : détections faibles <-> pistes confirmées restantes (occultations partielles)
        remaining = [active[i] for i in un_t if active[i].state == CONFIRMED]
        m2, un_t2, _ = self._associate(remaining, low, 0.1)
        for ti, di in m2:
            self._apply(remaining[ti], low[di], ts)
        matched_ids = {active[ti].track_id for ti, _ in m1} | {remaining[ti].track_id for ti, _ in m2}

        removed: list[Track] = []
        for t in active:
            if t.track_id in matched_ids:
                continue
            t.time_since_update += 1
            if t.state == TENTATIVE:
                t.state = REMOVED
                t.end_reason = "perdu"
            elif t.time_since_update > self.max_lost:
                t.state = REMOVED
                t.end_reason = "perdu"
                removed.append(t)
            else:
                t.state = LOST

        for di in un_d:
            d = high[di]
            t = Track(self._next_id, d.xyxy, d.score, d.label, ts, ts, best_score=d.score)
            t.centers.append(t.center)
            if self.min_hits <= 1:
                t.state = CONFIRMED
            self._next_id += 1
            self.tracks.append(t)

        self.tracks = [t for t in self.tracks if t.state != REMOVED]
        visible = [t for t in self.tracks if t.time_since_update == 0]
        return visible, removed

    def flush(self) -> list[Track]:
        """Termine toutes les pistes (fin de vidéo / arrêt de l'analyse)."""
        out = [t for t in self.tracks if t.state in (CONFIRMED, LOST)]
        for t in out:
            t.end_reason = "sorti_du_champ"
        self.tracks = []
        return out
