from __future__ import annotations

from dataclasses import dataclass


@dataclass
class Detection:
    """Boîte en pixels (x, y, w, h) + score du détecteur."""
    x: float
    y: float
    w: float
    h: float
    score: float
    label: str = "objet"

    @property
    def xyxy(self) -> tuple[float, float, float, float]:
        return self.x, self.y, self.x + self.w, self.y + self.h


def iou(a: tuple[float, float, float, float], b: tuple[float, float, float, float]) -> float:
    ix1, iy1 = max(a[0], b[0]), max(a[1], b[1])
    ix2, iy2 = min(a[2], b[2]), min(a[3], b[3])
    iw, ih = max(0.0, ix2 - ix1), max(0.0, iy2 - iy1)
    inter = iw * ih
    if inter <= 0:
        return 0.0
    area_a = (a[2] - a[0]) * (a[3] - a[1])
    area_b = (b[2] - b[0]) * (b[3] - b[1])
    return inter / max(1e-9, area_a + area_b - inter)
