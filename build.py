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


_MONTH = {
    "JAN": "01", "FEB": "02", "MAR": "03", "APR": "04",
    "MAY": "05", "JUN": "06", "JUL": "07", "AUG": "08",
    "SEP": "09", "OCT": "10", "NOV": "11", "DEC": "12",
}


def gedcom_date_to_iso(raw: str) -> str | None:
    """Return YYYY-MM-DD only if the date is unambiguous and complete.
    Fuzzy modifiers (ABT/BEF/AFT/EST/BET) and year-only dates return None."""
    if not raw:
        return None
    parts = raw.strip().split()
    if not parts:
        return None
    # Any fuzzy modifier disqualifies precise ISO.
    if parts[0].upper() in ("ABT", "BEF", "AFT", "EST", "CAL", "BET"):
        return None
    # Exact: "DD MON YYYY"
    if len(parts) == 3 and parts[1].upper() in _MONTH:
        day = parts[0].zfill(2)
        mon = _MONTH[parts[1].upper()]
        year = parts[2]
        if len(year) == 4 and year.isdigit() and day.isdigit() and 1 <= int(day) <= 31:
            return f"{year}-{mon}-{day}"
    # Month + year only, or year only → leave unresolved (don't fake precision).
    return None


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
                    current.setdefault(ev, {})
                    current[ev]["raw"] = value
                    current[ev]["iso"] = gedcom_date_to_iso(value)
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
                current.setdefault("marriage", {})
                current["marriage"]["raw"] = value
                current["marriage"]["iso"] = gedcom_date_to_iso(value)
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


def detect_cycles(tree: dict) -> None:
    """DFS through parent links. Any individual reachable from itself is in a cycle.
    Mutates `tree` in place: sets individuals[ID]['cycle'] = True for offenders."""
    inds = tree["individuals"]
    fams = tree["families"]

    def parents_of(ind_id: str) -> list[str]:
        ind = inds.get(ind_id)
        if not ind or "parents_family" not in ind:
            return []
        fam = fams.get(ind["parents_family"])
        if not fam:
            return []
        return [p for p in (fam.get("husband"), fam.get("wife")) if p]

    for start in inds:
        seen = set()
        stack = [start]
        while stack:
            cur = stack.pop()
            for p in parents_of(cur):
                if p == start:
                    inds[start]["cycle"] = True
                    print(f"warning: cycle detected involving {start}", file=sys.stderr)
                    stack.clear()
                    break
                if p in seen:
                    continue
                seen.add(p)
                stack.append(p)


def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("ged", type=Path)
    p.add_argument("--out", type=Path, default=Path("tree.json"))
    p.add_argument("--root", type=str, default=None)
    args = p.parse_args()

    raw_bytes = args.ged.read_bytes()
    try:
        text = raw_bytes.decode("utf-8")
    except UnicodeDecodeError as e:
        print(f"warning: {args.ged} not valid UTF-8 ({e}); falling back to CP1252", file=sys.stderr)
        text = raw_bytes.decode("cp1252")
    tree = parse_gedcom(text)
    detect_cycles(tree)

    tmp = args.out.with_suffix(args.out.suffix + ".new")
    tmp.write_text(json.dumps(tree, ensure_ascii=False, indent=2), encoding="utf-8")
    os.replace(tmp, args.out)
    return 0


if __name__ == "__main__":
    sys.exit(main())
