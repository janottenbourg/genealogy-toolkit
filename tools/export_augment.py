#!/usr/bin/env python3
"""Merge augment.json into a GEDCOM as one marker-fenced NOTE per person.

Usage: python3 tools/export_augment.py <input.ged> [--augment augment.json]
                                       [--out FILE] [--in-place] [-v]

augment.json is authoritative for email/mobile/facebook/linkedin/instagram/
bio. Each augmented INDI gets a dedicated NOTE fenced by BEGIN/END markers;
re-runs replace or remove that block idempotently. Genealogical notes are
never touched. See docs/superpowers/specs/2026-05-30-stamboom-augment-export-design.md
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

BEGIN_MARKER = "-- stamboom-augment begin --"
END_MARKER = "-- stamboom-augment end --"
MAX_VALUE_BYTES = 200  # keeps physical line (incl. "2 CONC ") under GEDCOM 255

# (json key, Dutch label) in deterministic emit order. bio handled separately.
FIELDS = [
    ("email", "E-mail"),
    ("mobile", "Mobiel"),
    ("facebook", "Facebook"),
    ("linkedin", "LinkedIn"),
    ("instagram", "Instagram"),
]


def escape_at(s: str) -> str:
    """GEDCOM 5.5.1: a literal '@' in a value must be doubled."""
    return s.replace("@", "@@")


def split_value(s: str, max_bytes: int = MAX_VALUE_BYTES) -> list[str]:
    """Split a logical line into chunks each ≤ max_bytes in UTF-8, never
    breaking a multibyte sequence. Empty string → [''] (one empty chunk)."""
    if s == "":
        return [""]
    chunks: list[str] = []
    cur: list[str] = []
    cur_bytes = 0
    for ch in s:
        b = len(ch.encode("utf-8"))
        if cur and cur_bytes + b > max_bytes:
            chunks.append("".join(cur))
            cur, cur_bytes = [], 0
        cur.append(ch)
        cur_bytes += b
    chunks.append("".join(cur))
    return chunks


def encode_logical_lines(logical_lines: list[str]) -> list[str]:
    """Encode logical NOTE lines into physical GEDCOM lines.
    First logical line → '1 NOTE ...'; the rest → '2 CONT ...'.
    Over-long logical lines spill into '2 CONC ...' continuations.
    An empty value emits the bare tag with no trailing space."""
    out: list[str] = []
    for i, ll in enumerate(logical_lines):
        chunks = split_value(ll)
        lvl, tag = ("1", "NOTE") if i == 0 else ("2", "CONT")
        out.append(f"{lvl} {tag} {chunks[0]}" if chunks[0] != "" else f"{lvl} {tag}")
        for c in chunks[1:]:
            out.append(f"2 CONC {c}" if c != "" else "2 CONC")
    return out


def _bio_of(aug: dict) -> str:
    bio = str(aug.get("bio") or "")
    return bio.replace("\r\n", "\n").replace("\r", "\n")


def has_any(aug: dict) -> bool:
    """True if at least one user-visible augmentation field is non-empty."""
    if _bio_of(aug).strip() != "":
        return True
    return any(str(aug.get(k) or "").strip() != "" for k, _ in FIELDS)


def build_block_lines(aug: dict) -> list[str]:
    """Augmentation dict → physical GEDCOM lines for the stamboom NOTE block.
    Only non-empty fields are emitted, in FIELDS order, then bio."""
    logical = [BEGIN_MARKER]
    for key, label in FIELDS:
        val = str(aug.get(key) or "").strip()
        if val != "":
            logical.append(f"{label}: {escape_at(val)}")
    bio = _bio_of(aug)
    if bio.strip() != "":
        bio_lines = bio.split("\n")
        logical.append(f"Bio: {escape_at(bio_lines[0])}")
        for extra in bio_lines[1:]:
            logical.append(escape_at(extra))
    logical.append(END_MARKER)
    return encode_logical_lines(logical)


def parse_records(lines: list[str]) -> list[list[str]]:
    """Group physical lines into records. A new record starts at each
    level-0 line. Every line is preserved verbatim in some record."""
    records: list[list[str]] = []
    cur: list[str] = []
    for line in lines:
        lvl = line.split(" ", 1)[0] if line else ""
        if lvl == "0":
            if cur:
                records.append(cur)
            cur = [line]
        else:
            if not cur:
                cur = [line]  # stray leading line (shouldn't happen) — keep it
            else:
                cur.append(line)
    if cur:
        records.append(cur)
    return records


def record_header(record: list[str]) -> tuple[str | None, str]:
    """Return (xref_id_without_@, type) from a record's level-0 line.
    HEAD/TRLR have no xref → (None, 'HEAD')."""
    parts = record[0].split(" ", 2)
    if len(parts) >= 3 and parts[1].startswith("@"):
        return parts[1].strip("@"), parts[2].strip()
    if len(parts) >= 2:
        return None, parts[1].strip()
    return None, ""


def merge_record(record: list[str], aug: dict) -> tuple[list[str], str]:
    """Apply augmentation to one INDI record. Returns (new_lines, action)
    where action ∈ {'added','updated','removed','noop'}."""
    span = find_stamboom_note_span(record)
    if has_any(aug):
        block = build_block_lines(aug)
        if span:
            s, e = span
            return record[:s] + block + record[e:], "updated"
        return record + block, "added"
    if span:
        s, e = span
        return record[:s] + record[e:], "removed"
    return record, "noop"


def find_stamboom_note_span(record: list[str]) -> tuple[int, int] | None:
    """Return (start, end) line indices of the dedicated stamboom NOTE
    (the one whose first logical line is BEGIN_MARKER), or None. `end` is
    exclusive. The span covers the '1 NOTE' line and its level≥2 children."""
    n = len(record)
    i = 0
    while i < n:
        if record[i].startswith("1 NOTE"):
            first_val = record[i][len("1 NOTE"):].lstrip(" ")
            j = i + 1
            while j < n:
                lvl = record[j].split(" ", 1)[0]
                if lvl.isdigit() and int(lvl) >= 2:
                    j += 1
                else:
                    break
            if first_val.replace("@@", "@") == BEGIN_MARKER:
                return (i, j)
            i = j
        else:
            i += 1
    return None


def _read_text(path: Path) -> tuple[str, str, bool]:
    """Return (text, newline, had_trailing_newline). Decodes UTF-8 with
    CP1252 fallback (matches build.py). Detects CRLF vs LF."""
    raw = path.read_bytes()
    try:
        text = raw.decode("utf-8")
    except UnicodeDecodeError:
        text = raw.decode("cp1252")
    nl = "\r\n" if "\r\n" in text else "\n"
    had_trailing = text.endswith(nl)
    if had_trailing:
        text = text[: -len(nl)]
    return text, nl, had_trailing


def _atomic_write(path: Path, data: str) -> None:
    tmp = path.with_suffix(path.suffix + ".new")
    tmp.write_text(data, encoding="utf-8")
    os.replace(tmp, path)


def main(argv: list[str] | None = None) -> int:
    p = argparse.ArgumentParser(description="Merge augment.json into a GEDCOM.")
    p.add_argument("ged", type=Path)
    p.add_argument("--augment", type=Path, default=Path("augment.json"))
    p.add_argument("--out", type=Path, default=None)
    p.add_argument("--in-place", action="store_true")
    p.add_argument("-v", "--verbose", action="store_true")
    args = p.parse_args(argv)

    if args.in_place and args.out is not None:
        print("error: --in-place cannot be combined with --out", file=sys.stderr)
        return 2

    out_path = args.ged if args.in_place else (
        args.out if args.out is not None
        else args.ged.with_name(args.ged.stem + "_augmented" + args.ged.suffix)
    )
    if out_path.resolve() == args.ged.resolve() and not args.in_place:
        print("error: --out equals input; use --in-place to overwrite",
              file=sys.stderr)
        return 2

    try:
        aug_data = json.loads(args.augment.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as e:
        print(f"error: cannot read {args.augment}: {e}", file=sys.stderr)
        return 2
    augmentations = aug_data.get("augmentations", {})
    if not isinstance(augmentations, dict):
        print("error: augment.json 'augmentations' must be an object",
              file=sys.stderr)
        return 2

    try:
        text, nl, had_trailing = _read_text(args.ged)
    except OSError as e:
        print(f"error: cannot read {args.ged}: {e}", file=sys.stderr)
        return 2

    records = parse_records(text.split(nl))

    counts = {"added": 0, "updated": 0, "removed": 0}
    seen_ids: set[str] = set()
    for idx, rec in enumerate(records):
        rid, rtype = record_header(rec)
        if rtype == "INDI" and rid is not None and rid in augmentations:
            seen_ids.add(rid)
            new_rec, action = merge_record(rec, augmentations[rid])
            records[idx] = new_rec
            if action in counts:
                counts[action] += 1
            if args.verbose:
                print(f"{rid}: {action}", file=sys.stderr)

    not_found = sorted(set(augmentations) - seen_ids)
    for rid in not_found:
        print(f"warning: {rid}: not found in GEDCOM", file=sys.stderr)

    body = nl.join(nl.join(rec) for rec in records)
    if had_trailing:
        body += nl

    if args.in_place:
        backup = args.ged.with_suffix(args.ged.suffix + ".bak")
        backup.write_bytes(args.ged.read_bytes())
    _atomic_write(out_path, body)

    print(f"added {counts['added']} · updated {counts['updated']} · "
          f"removed {counts['removed']} · not-found {len(not_found)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
