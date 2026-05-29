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
