#!/usr/bin/env python3
"""GEDCOM → tree.json converter for genealogy-toolkit.

Usage:  python3 build.py <input.ged> [--out tree.json] [--root I123]
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import sys
from datetime import datetime, timezone
from pathlib import Path


def parse_gedcom(text: str) -> dict:
    """Parse GEDCOM text into the tree.json shape. Returns the dict."""
    individuals: dict[str, dict] = {}
    families: dict[str, dict] = {}

    current: dict | None = None
    current_kind: str | None = None  # 'INDI' or 'FAM'

    def commit() -> None:
        """Persist `current` into the right bucket if any. Resets state."""
        nonlocal current, current_kind
        if current is None:
            return
        if current_kind == "INDI":
            individuals[current["id"]] = current
        elif current_kind == "FAM":
            families[current["id"]] = current
        current = None
        current_kind = None

    for raw_line in text.splitlines():
        line = raw_line.rstrip("\r\n")
        if not line.strip():
            continue
        parts = line.split(" ", 2)
        level = int(parts[0])
        if level == 0:
            commit()
            if len(parts) >= 3 and parts[2] == "INDI":
                current = {"id": parts[1].strip("@"), "name": {}, "spouse_families": []}
                current_kind = "INDI"
            elif len(parts) >= 3 and parts[2] == "FAM":
                current = {"id": parts[1].strip("@"), "children": []}
                current_kind = "FAM"
        # level >= 1 lines: ignored in this slice; Task 4 will extend here.

    commit()  # final record

    return {
        "meta": {
            "individuals": len(individuals),
            "families": len(families),
        },
        "individuals": individuals,
        "families": families,
    }


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("ged", type=Path)
    p.add_argument("--out", type=Path, default=Path("tree.json"))
    p.add_argument("--root", type=str, default=None)
    args = p.parse_args()

    text = args.ged.read_text(encoding="utf-8")
    tree = parse_gedcom(text)

    tmp = args.out.with_suffix(args.out.suffix + ".new")
    tmp.write_text(json.dumps(tree, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(tmp, args.out)
    return 0


if __name__ == "__main__":
    sys.exit(main())
