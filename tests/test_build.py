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


def test_fuzzy_date_preserved_in_raw(tmp_path):
    tree = run_build(tmp_path)
    assert tree["individuals"]["I2"]["birth"]["raw"] == "ABT 1912"
    assert tree["individuals"]["I5"]["birth"]["raw"] == "BEF 1900"
    assert tree["individuals"]["I5"]["death"]["raw"] == "BET 1960 AND 1965"
    assert tree["individuals"]["I11"]["birth"]["raw"] == "EST 1700"


def test_iso_dates_when_parseable(tmp_path):
    tree = run_build(tmp_path)
    assert tree["individuals"]["I1"]["birth"]["iso"] == "1910-11-04"
    assert tree["individuals"]["I1"]["death"]["iso"] == "1992-01-23"
    # Year-only resolves to YYYY-01-01? No — leave year-only unresolved (iso=None)
    # because we don't want to fake precision. Test that year-only dates are NOT
    # resolved to fake months/days.
    assert tree["individuals"]["I4"]["birth"]["iso"] is None
    assert tree["individuals"]["I4"]["birth"]["raw"] == "1942"


def test_iso_null_when_unparseable(tmp_path):
    tree = run_build(tmp_path)
    assert tree["individuals"]["I2"]["birth"]["iso"] is None
    assert tree["individuals"]["I5"]["birth"]["iso"] is None
    assert tree["individuals"]["I5"]["death"]["iso"] is None


def test_diacritics_roundtrip_utf8(tmp_path):
    tree = run_build(tmp_path)
    assert tree["individuals"]["I1"]["name"]["display"] == "Désiré Janssens"
    assert tree["individuals"]["I2"]["name"]["display"] == "Hélène De Smet"
    assert tree["individuals"]["I5"]["name"]["display"] == "François Peeters"
    # Round-trip: re-read tree.json as raw bytes, must be UTF-8.
    out = tmp_path / "tree.json"
    raw = out.read_bytes()
    raw.decode("utf-8")  # must not raise


def test_cp1252_fallback(tmp_path):
    """If the .ged is mis-encoded as CP1252 (Geneanet sometimes does this),
    build.py falls back and produces correct UTF-8 output."""
    src = SAMPLE.read_text(encoding="utf-8")
    cp1252_ged = tmp_path / "cp1252.ged"
    cp1252_ged.write_bytes(src.encode("cp1252"))
    tree = run_build(tmp_path, ged=cp1252_ged)
    assert tree["individuals"]["I1"]["name"]["display"] == "Désiré Janssens"
