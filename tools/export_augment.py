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
