import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).parent.parent
SAMPLE = ROOT / "sample.ged"


def run_build(tmp_path, ged=SAMPLE, extra_args=()):
    """Run build.py against `ged`, writing to tmp_path/tree.json. Returns parsed JSON."""
    out = tmp_path / "tree.json"
    subprocess.run(
        [sys.executable, str(ROOT / "build.py"), str(ged), "--out", str(out), *extra_args],
        check=True,
        cwd=tmp_path,
    )
    return json.loads(out.read_text(encoding="utf-8"))


def test_individual_count(tmp_path):
    tree = run_build(tmp_path)
    assert tree["meta"]["individuals"] == 15
    assert len(tree["individuals"]) == 15
