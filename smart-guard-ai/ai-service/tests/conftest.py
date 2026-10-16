import os
import sys
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))


@pytest.fixture(scope="session")
def synthetic(tmp_path_factory):
    """Jeu synthétique (fictif) + dossier storage temporaire."""
    sys.path.insert(0, str(ROOT / "scripts"))
    import make_synthetic_dataset as mk
    base = tmp_path_factory.mktemp("synthetic")
    mk.main(base / "data")
    storage = base / "storage"
    (storage / "product_images").mkdir(parents=True)
    (storage / "cache").mkdir()
    import shutil
    for code in "ABC":
        shutil.copytree(base / "data" / "products" / code, storage / "product_images" / code)
    return {"data": base / "data", "storage": storage}
