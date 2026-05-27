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


def test_family_count(tmp_path):
    tree = run_build(tmp_path)
    assert tree["meta"]["families"] == 7
    assert len(tree["families"]) == 7


def test_husband_wife_children_linked(tmp_path):
    tree = run_build(tmp_path)
    f1 = tree["families"]["F1"]
    assert f1["husband"] == "I1"
    assert f1["wife"] == "I2"
    assert f1["children"] == ["I3", "I4"]


def test_individual_famc_fams(tmp_path):
    tree = run_build(tmp_path)
    i3 = tree["individuals"]["I3"]
    assert i3["parents_family"] == "F1"
    assert set(i3["spouse_families"]) == {"F2", "F3"}


def test_names_split_into_given_surname(tmp_path):
    tree = run_build(tmp_path)
    i1 = tree["individuals"]["I1"]
    assert i1["name"]["given"] == "Désiré"
    assert i1["name"]["surname"] == "Janssens"
    assert i1["name"]["display"] == "Désiré Janssens"
