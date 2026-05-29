import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).parent.parent
TOOL = ROOT / "tools" / "export_augment.py"
SAMPLE = ROOT / "sample.ged"

sys.path.insert(0, str(ROOT / "tools"))
import export_augment as ea  # noqa: E402


def test_escape_at_doubles_at_signs():
    assert ea.escape_at("jan@ottenbourg.com") == "jan@@ottenbourg.com"
    assert ea.escape_at("no-at-here") == "no-at-here"
    assert ea.escape_at("@@") == "@@@@"


def test_split_value_empty_returns_single_empty_chunk():
    assert ea.split_value("") == [""]


def test_split_value_short_string_one_chunk():
    assert ea.split_value("hello", max_bytes=200) == ["hello"]


def test_split_value_splits_long_ascii():
    s = "a" * 250
    chunks = ea.split_value(s, max_bytes=200)
    assert chunks == ["a" * 200, "a" * 50]
    assert "".join(chunks) == s


def test_split_value_never_breaks_multibyte_char():
    # 'é' is 2 bytes in UTF-8; 150 of them = 300 bytes, budget 200.
    s = "é" * 150
    chunks = ea.split_value(s, max_bytes=200)
    assert "".join(chunks) == s
    for c in chunks:
        assert len(c.encode("utf-8")) <= 200
        c.encode("utf-8").decode("utf-8")  # each chunk is valid UTF-8
