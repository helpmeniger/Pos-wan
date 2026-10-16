from collections import deque
from dataclasses import dataclass, field

from app.vision.line_counter import LineConfig, LineCounter

W, H = 640, 360


@dataclass
class FakeTrack:
    track_id: int
    cx: float
    cy: float
    frames_seen: int = 10
    vx: float = 0.0
    vy: float = 0.0
    centers: deque = field(default_factory=deque)

    @property
    def center(self):
        return self.cx, self.cy


def vertical_line(direction="gauche_vers_droite", **kw):
    return LineConfig.from_dict({"id": 1, "name": "Porte", "x1": 0.5, "y1": 0.0, "x2": 0.5, "y2": 1.0, "exit_direction": direction, **kw})


def run(counter, track, xs):
    events = []
    for x in xs:
        track.cx = x
        events += counter.update(track, W, H)
    return events


def test_left_to_right_is_exit():
    c = LineCounter([vertical_line()])
    ev = run(c, FakeTrack(1, 100, 180), [100, 200, 300, 400, 500])
    assert [e.direction for e in ev] == ["sortie"]


def test_right_to_left_is_entry():
    c = LineCounter([vertical_line()])
    ev = run(c, FakeTrack(1, 500, 180), [500, 400, 300, 200])
    assert [e.direction for e in ev] == ["entree"]


def test_direction_setting_inverts():
    c = LineCounter([vertical_line("droite_vers_gauche")])
    ev = run(c, FakeTrack(1, 100, 180), [100, 250, 400])
    assert [e.direction for e in ev] == ["entree"]


def test_jitter_on_line_is_not_counted_twice():
    c = LineCounter([vertical_line(hysteresis=0.02)])
    # oscillation de +/- 5 px autour de la ligne (x=320), dans la marge d'hystérésis
    ev = run(c, FakeTrack(1, 200, 180), [200, 300, 316, 324, 317, 323, 318, 322, 400, 480])
    assert [e.direction for e in ev] == ["sortie"]


def test_out_and_back_counts_exit_then_entry():
    c = LineCounter([vertical_line()])
    ev = run(c, FakeTrack(1, 200, 180), [200, 420, 200])
    assert [e.direction for e in ev] == ["sortie", "entree"]


def test_crossing_beyond_segment_end_is_ignored():
    line = LineConfig.from_dict({"id": 1, "x1": 0.5, "y1": 0.0, "x2": 0.5, "y2": 0.4})
    c = LineCounter([line])
    ev = run(c, FakeTrack(1, 100, 300), [100, 300, 500])  # passe sous l'extrémité basse
    assert ev == []


def test_short_track_is_ambiguous():
    c = LineCounter([vertical_line(min_track_frames=5)])
    ev = run(c, FakeTrack(1, 100, 180, frames_seen=2), [100, 500])
    assert [e.direction for e in ev] == ["ambigu"]


def test_track_lost_near_line_heading_out():
    c = LineCounter([vertical_line()])
    t = FakeTrack(1, 200, 180, vx=15.0)
    run(c, t, [200, 260, 300])          # s'approche de la ligne (x=320) depuis l'intérieur
    ev = c.on_track_removed(t, W, H)
    assert [e.direction for e in ev] == ["perte_suivi"]


def test_track_lost_far_from_line_is_silent():
    c = LineCounter([vertical_line()])
    t = FakeTrack(1, 50, 180, vx=15.0)
    run(c, t, [40, 50, 60])
    assert c.on_track_removed(t, W, H) == []
