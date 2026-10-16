from app.vision.geometry import Detection
from app.vision.tracker import ByteTracker


def box(x, y, s=60, score=0.9):
    return Detection(x, y, s, s, score)


def test_same_object_keeps_id_across_frames():
    tr = ByteTracker(min_hits=2)
    ids = set()
    for k in range(10):
        vis, _ = tr.update([box(50 + 20 * k, 100)], ts=k)
        ids |= {t.track_id for t in vis}
    assert ids == {1}


def test_reassociation_after_short_occlusion():
    tr = ByteTracker(min_hits=2, max_lost=10)
    for k in range(5):
        tr.update([box(50 + 20 * k, 100)], ts=k)
    for k in range(5, 8):            # occulté 3 images
        tr.update([], ts=k)
    vis, removed = tr.update([box(50 + 20 * 8, 100)], ts=8)
    assert [t.track_id for t in vis] == [1]
    assert removed == []


def test_two_objects_get_two_ids():
    tr = ByteTracker(min_hits=1)
    vis, _ = tr.update([box(10, 10), box(300, 200)], ts=0)
    vis, _ = tr.update([box(20, 10), box(290, 200)], ts=1)
    assert sorted(t.track_id for t in vis) == [1, 2]


def test_no_id_switch_on_reverse_direction():
    """Un objet qui sort à gauche ne transmet pas son identifiant à un objet qui entre à gauche."""
    tr = ByteTracker(min_hits=2, max_lost=10)
    for k in range(8):
        tr.update([box(400 - 30 * k, 200)], ts=k)          # se déplace vers la gauche
    tr.update([], ts=8)
    vis, _ = tr.update([box(150, 190)], ts=9)              # nouvel objet, repart vers la droite
    vis, _ = tr.update([box(180, 190)], ts=10)
    assert all(t.track_id != 1 for t in vis)


def test_lost_track_is_removed_after_max_lost():
    tr = ByteTracker(min_hits=1, max_lost=3)
    tr.update([box(10, 10)], ts=0)
    tr.update([box(15, 10)], ts=1)
    removed_all = []
    for k in range(2, 7):
        _, removed = tr.update([], ts=k)
        removed_all += removed
    assert [t.track_id for t in removed_all] == [1]
