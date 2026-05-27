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
    individuals: dict[str, dict] = {}
    families: dict[str, dict] = {}

    current: dict | None = None
    current_kind: str | None = None
    sub_path: list[str] = []  # stack of last-seen tags at each level

    def commit() -> None:
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
            sub_path = []
            if len(parts) >= 3 and parts[2] == "INDI":
                current = {
                    "id": parts[1].strip("@"),
                    "name": {},
                    "spouse_families": [],
                }
                current_kind = "INDI"
            elif len(parts) >= 3 and parts[2] == "FAM":
                current = {
                    "id": parts[1].strip("@"),
                    "children": [],
                }
                current_kind = "FAM"
            continue

        if current is None:
            continue  # ignore lines outside our records (HEAD, TRLR, etc.)

        tag = parts[1]
        value = parts[2] if len(parts) >= 3 else ""

        # maintain tag stack
        sub_path = sub_path[: level - 1] + [tag]

        if current_kind == "INDI":
            if level == 1 and tag == "NAME":
                # "Given /Surname/" — slashes around surname
                given, surname = "", ""
                if "/" in value:
                    given_part, _, rest = value.partition("/")
                    surname, _, _ = rest.partition("/")
                    given = given_part.strip()
                    surname = surname.strip()
                else:
                    given = value.strip()
                display = (given + " " + surname).strip() or "Onbekend"
                current["name"] = {
                    "given": given,
                    "surname": surname,
                    "display": display,
                }
            elif level == 1 and tag == "SEX":
                current["sex"] = value.strip() or "U"
            elif level == 1 and tag == "FAMC":
                current["parents_family"] = value.strip("@")
            elif level == 1 and tag == "FAMS":
                current["spouse_families"].append(value.strip("@"))
            elif level == 1 and tag in ("BIRT", "DEAT"):
                current[tag.lower().replace("birt", "birth").replace("deat", "death")] = {}
            elif level == 2 and tag == "DATE" and len(sub_path) >= 2:
                ev = sub_path[0].lower().replace("birt", "birth").replace("deat", "death")
                if ev in ("birth", "death"):
                    current.setdefault(ev, {})["raw"] = value
            elif level == 2 and tag == "PLAC" and len(sub_path) >= 2:
                ev = sub_path[0].lower().replace("birt", "birth").replace("deat", "death")
                if ev in ("birth", "death"):
                    current.setdefault(ev, {})["place"] = value if value else None

        elif current_kind == "FAM":
            if level == 1 and tag == "HUSB":
                current["husband"] = value.strip("@")
            elif level == 1 and tag == "WIFE":
                current["wife"] = value.strip("@")
            elif level == 1 and tag == "CHIL":
                current["children"].append(value.strip("@"))
            elif level == 1 and tag == "MARR":
                current["marriage"] = {}
            elif level == 2 and tag == "DATE" and sub_path[:1] == ["MARR"]:
                current.setdefault("marriage", {})["raw"] = value
            elif level == 2 and tag == "PLAC" and sub_path[:1] == ["MARR"]:
                current.setdefault("marriage", {})["place"] = value if value else None

    commit()

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
