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
