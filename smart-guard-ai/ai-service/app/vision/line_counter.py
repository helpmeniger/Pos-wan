"""Comptage des franchissements d'une ligne virtuelle.

Chaque ligne est définie par deux points normalisés A(x1,y1) -> B(x2,y2). Le côté d'un point P
est le signe du produit vectoriel (B-A) x (P-A) calculé en pixels : côté « gauche » (> 0) et
côté « droit » (< 0). Pour une ligne tracée de haut en bas, « gauche » correspond à la partie
gauche de l'image. exit_direction indique le sens qui correspond à une SORTIE.

Garde-fous contre les doubles comptages :
- hystérésis : le côté n'est mis à jour que si le centre est à plus de `hysteresis` (fraction
  de la diagonale) de la ligne ; les oscillations sur la ligne ne comptent donc pas ;
- un franchissement n'est compté que si le point de passage est sur le segment (pas au-delà
  de ses extrémités) ;
- une piste ne peut produire deux événements consécutifs dans le même sens ;
- une piste trop courte (< min_track_frames) produit un événement « ambigu » à vérifier ;
- une piste perdue près de la ligne en se dirigeant vers la sortie produit « perte_suivi ».
"""
from __future__ import annotations

from dataclasses import dataclass, field


@dataclass
class LineConfig:
    line_id: int
    name: str
    x1: float
    y1: float
    x2: float
    y2: float
    exit_direction: str = "gauche_vers_droite"
    min_track_frames: int = 3
    hysteresis: float = 0.02
    near_zone: float = 0.08

    @classmethod
    def from_dict(cls, d: dict) -> "LineConfig":
        def clamp(v):
            return min(1.0, max(0.0, float(v)))
        direction = d.get("exit_direction", "gauche_vers_droite")
        if direction not in ("gauche_vers_droite", "droite_vers_gauche"):
            raise ValueError("exit_direction invalide")
        cfg = cls(int(d["id"]), str(d.get("name", "Ligne"))[:80], clamp(d["x1"]), clamp(d["y1"]), clamp(d["x2"]), clamp(d["y2"]),
                  direction, max(1, int(d.get("min_track_frames", 3))), min(0.2, max(0.0, float(d.get("hysteresis", 0.02)))))
        if abs(cfg.x1 - cfg.x2) + abs(cfg.y1 - cfg.y2) < 0.02:
            raise ValueError("Ligne trop courte")
        return cfg

    @property
    def exit_from_side(self) -> int:
        return 1 if self.exit_direction == "gauche_vers_droite" else -1


@dataclass
class LineEvent:
    line_id: int
    track_id: int
    direction: str     # sortie | entree | ambigu | perte_suivi
    reason: str
    point: tuple[float, float]


@dataclass
class _State:
    side: int = 0
    last_event: str | None = None
    last_signed: float = 0.0
    crossed: bool = False


@dataclass
class LineCounter:
    lines: list[LineConfig]
    states: dict = field(default_factory=dict)  # (track_id, line_id) -> _State

    def _geom(self, line: LineConfig, px: float, py: float, w: int, h: int) -> tuple[float, float]:
        ax, ay, bx, by = line.x1 * w, line.y1 * h, line.x2 * w, line.y2 * h
        dx, dy = bx - ax, by - ay
        length = max(1e-6, (dx * dx + dy * dy) ** 0.5)
        cross = dx * (py - ay) - dy * (px - ax)
        diag = (w * w + h * h) ** 0.5
        signed = (cross / length) / diag            # distance signée normalisée
        t = ((px - ax) * dx + (py - ay) * dy) / (length * length)  # position le long du segment
        return signed, t

    def update(self, track, frame_w: int, frame_h: int) -> list[LineEvent]:
        events: list[LineEvent] = []
        cx, cy = track.center
        for line in self.lines:
            key = (track.track_id, line.line_id)
            st = self.states.setdefault(key, _State())
            signed, t = self._geom(line, cx, cy, frame_w, frame_h)
            st.last_signed = signed
            if abs(signed) <= line.hysteresis:
                continue
            side = 1 if signed > 0 else -1
            if st.side == 0:
                st.side = side
                continue
            if side == st.side:
                continue
            prev = st.side
            st.side = side
            if not (-0.05 <= t <= 1.05):
                continue  # passage au-delà des extrémités du segment
            direction = "sortie" if prev == line.exit_from_side else "entree"
            if st.last_event == direction:
                continue
            st.last_event = direction
            st.crossed = True
            norm_point = (cx / frame_w, cy / frame_h)
            if track.frames_seen < line.min_track_frames:
                events.append(LineEvent(line.line_id, track.track_id, "ambigu",
                                        f"suivi trop court ({track.frames_seen} images) — sens présumé : {direction}", norm_point))
            else:
                events.append(LineEvent(line.line_id, track.track_id, direction, "franchissement net", norm_point))
        return events

    def on_track_removed(self, track, frame_w: int, frame_h: int) -> list[LineEvent]:
        events: list[LineEvent] = []
        for line in self.lines:
            st = self.states.pop((track.track_id, line.line_id), None)
            if st is None or st.crossed or track.frames_seen < line.min_track_frames:
                continue
            on_inside = st.side == line.exit_from_side or st.side == 0
            near = abs(st.last_signed) < line.near_zone
            # se dirigeait-il vers la ligne ?
            ax, ay, bx, by = line.x1 * frame_w, line.y1 * frame_h, line.x2 * frame_w, line.y2 * frame_h
            nx, ny = -(by - ay), (bx - ax)          # normale vers le côté « gauche »
            v_toward_right = -(track.vx * nx + track.vy * ny)
            heading_out = v_toward_right * line.exit_from_side > 0
            if on_inside and near and heading_out:
                cx, cy = track.center
                events.append(LineEvent(line.line_id, track.track_id, "perte_suivi",
                                        "piste perdue à proximité de la ligne en direction de la sortie", (cx / frame_w, cy / frame_h)))
        return events
