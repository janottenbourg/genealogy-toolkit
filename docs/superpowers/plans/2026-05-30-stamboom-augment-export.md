# stamboom augment-export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `tools/export_augment.py`, a CLI that merges augmentation data (`email`, `mobile`, `facebook`, `linkedin`, `instagram`, `bio`) from `augment.json` into a GEDCOM as one dedicated marker-fenced `NOTE` per person, idempotently.

**Architecture:** Pure-stdlib Python 3.12 script (mirrors `build.py`). The GEDCOM is split into records (level-0 boundaries); each `INDI` whose id is in `augment.json` gets its own stamboom `NOTE` added/replaced/removed, leaving every other byte untouched. Output goes to a separate `<stem>_augmented.ged` by default. `augment.json` is authoritative; the export always overwrites its own block.

**Tech Stack:** Python 3.12 stdlib (`argparse`, `json`, `os`, `sys`, `pathlib`), pytest for tests, existing GitHub Actions CI.

**Spec:** `docs/superpowers/specs/2026-05-30-stamboom-augment-export-design.md`

---

## File Structure

- **Create** `tools/export_augment.py` — the CLI tool + importable pure functions:
  - constants: `BEGIN_MARKER`, `END_MARKER`, `MAX_VALUE_BYTES`, `FIELDS`
  - `escape_at(s)` — GEDCOM `@`→`@@`
  - `split_value(s, max_bytes)` — UTF-8-byte-safe chunking for `CONC`
  - `encode_logical_lines(logical_lines)` — logical lines → `NOTE`/`CONT`/`CONC` physical lines
  - `has_any(aug)` — does this augmentation have ≥1 non-empty field?
  - `build_block_lines(aug)` — augmentation dict → block physical lines
  - `parse_records(lines)` — group lines into records by level-0
  - `record_header(record)` — `(id, type)` from a record's first line
  - `find_stamboom_note_span(record)` — `(start, end)` of the existing stamboom NOTE, or `None`
  - `merge_record(record, aug)` — `(new_record_lines, action)`
  - `main(argv)` — argument handling, file IO, atomic write, summary
- **Create** `tests/test_export_augment.py` — unit + subprocess tests
- **Modify** `.gitignore` — add `*_augmented.ged`
- **Modify** `.github/workflows/ci.yml` — add a pytest step for the new test file
- **Modify** `CLAUDE.md` — add "Augment export" section; move the follow-up to done

---

### Task 1: Scaffold module + `escape_at`

**Files:**
- Create: `tools/export_augment.py`
- Test: `tests/test_export_augment.py`

- [ ] **Step 1: Write the failing test**

```python
# tests/test_export_augment.py
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_export_augment.py::test_escape_at_doubles_at_signs -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'export_augment'`

- [ ] **Step 3: Write minimal implementation**

```python
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_export_augment.py::test_escape_at_doubles_at_signs -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add tools/export_augment.py tests/test_export_augment.py
git commit -m "feat(augment-export): scaffold export_augment.py + escape_at"
```

---

### Task 2: `split_value` (UTF-8-byte-safe chunking)

**Files:**
- Modify: `tools/export_augment.py`
- Test: `tests/test_export_augment.py`

- [ ] **Step 1: Write the failing test**

```python
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_export_augment.py -k split_value -v`
Expected: FAIL — `AttributeError: module 'export_augment' has no attribute 'split_value'`

- [ ] **Step 3: Write minimal implementation**

Add to `tools/export_augment.py`:

```python
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_export_augment.py -k split_value -v`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add tools/export_augment.py tests/test_export_augment.py
git commit -m "feat(augment-export): split_value byte-safe CONC chunking"
```

---

### Task 3: `encode_logical_lines` (NOTE/CONT/CONC)

**Files:**
- Modify: `tools/export_augment.py`
- Test: `tests/test_export_augment.py`

- [ ] **Step 1: Write the failing test**

```python
def test_encode_first_line_is_note():
    assert ea.encode_logical_lines(["alpha"]) == ["1 NOTE alpha"]


def test_encode_subsequent_lines_are_cont():
    assert ea.encode_logical_lines(["alpha", "beta"]) == [
        "1 NOTE alpha",
        "2 CONT beta",
    ]


def test_encode_empty_logical_line_has_no_trailing_space():
    assert ea.encode_logical_lines(["alpha", ""]) == [
        "1 NOTE alpha",
        "2 CONT",
    ]


def test_encode_long_line_uses_conc():
    long = "x" * 250
    out = ea.encode_logical_lines([long])
    assert out[0] == "1 NOTE " + "x" * 200
    assert out[1] == "2 CONC " + "x" * 50
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_export_augment.py -k encode -v`
Expected: FAIL — no attribute `encode_logical_lines`

- [ ] **Step 3: Write minimal implementation**

Add to `tools/export_augment.py`:

```python
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_export_augment.py -k encode -v`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add tools/export_augment.py tests/test_export_augment.py
git commit -m "feat(augment-export): encode_logical_lines NOTE/CONT/CONC"
```

---

### Task 4: `has_any` + `build_block_lines`

**Files:**
- Modify: `tools/export_augment.py`
- Test: `tests/test_export_augment.py`

- [ ] **Step 1: Write the failing test**

```python
def test_has_any_true_when_one_field_set():
    assert ea.has_any({"email": "x@y.com"}) is True
    assert ea.has_any({"bio": "hi"}) is True


def test_has_any_false_when_all_empty_or_whitespace():
    assert ea.has_any({}) is False
    assert ea.has_any({"email": "", "bio": "  "}) is False


def test_build_block_omits_empty_fields_and_uses_dutch_labels():
    block = ea.build_block_lines({
        "email": "jan@ottenbourg.com",
        "mobile": "",
        "facebook": "https://facebook.com/janottenbourg",
        "linkedin": "",
        "instagram": "",
        "bio": "",
    })
    assert block[0] == "1 NOTE " + ea.BEGIN_MARKER
    assert "2 CONT E-mail: jan@@ottenbourg.com" in block
    assert "2 CONT Facebook: https://facebook.com/janottenbourg" in block
    assert block[-1] == "2 CONT " + ea.END_MARKER
    # No empty fields emitted:
    assert not any("Mobiel:" in ln for ln in block)
    assert not any("LinkedIn:" in ln for ln in block)


def test_build_block_multiline_bio_becomes_multiple_cont():
    block = ea.build_block_lines({"bio": "Regel een.\nRegel twee."})
    assert "2 CONT Bio: Regel een." in block
    assert "2 CONT Regel twee." in block


def test_build_block_field_order_is_deterministic():
    block = ea.build_block_lines({
        "instagram": "https://instagram.com/x",
        "email": "a@b.com",
    })
    email_idx = next(i for i, ln in enumerate(block) if "E-mail:" in ln)
    insta_idx = next(i for i, ln in enumerate(block) if "Instagram:" in ln)
    assert email_idx < insta_idx
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_export_augment.py -k "has_any or build_block" -v`
Expected: FAIL — no attribute `has_any`

- [ ] **Step 3: Write minimal implementation**

Add to `tools/export_augment.py`:

```python
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_export_augment.py -k "has_any or build_block" -v`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add tools/export_augment.py tests/test_export_augment.py
git commit -m "feat(augment-export): has_any + build_block_lines"
```

---

### Task 5: `parse_records` + `record_header`

**Files:**
- Modify: `tools/export_augment.py`
- Test: `tests/test_export_augment.py`

- [ ] **Step 1: Write the failing test**

```python
def test_parse_records_groups_by_level_zero():
    lines = [
        "0 HEAD",
        "1 SOUR Geneanet",
        "0 @I1@ INDI",
        "1 NAME Jan /Test/",
        "0 TRLR",
    ]
    recs = ea.parse_records(lines)
    assert len(recs) == 3
    assert recs[0] == ["0 HEAD", "1 SOUR Geneanet"]
    assert recs[1] == ["0 @I1@ INDI", "1 NAME Jan /Test/"]
    assert recs[2] == ["0 TRLR"]


def test_record_header_indi():
    assert ea.record_header(["0 @I7@ INDI", "1 NAME x"]) == ("I7", "INDI")


def test_record_header_head_and_trlr():
    assert ea.record_header(["0 HEAD"]) == (None, "HEAD")
    assert ea.record_header(["0 TRLR"]) == (None, "TRLR")
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_export_augment.py -k "parse_records or record_header" -v`
Expected: FAIL — no attribute `parse_records`

- [ ] **Step 3: Write minimal implementation**

Add to `tools/export_augment.py`:

```python
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_export_augment.py -k "parse_records or record_header" -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add tools/export_augment.py tests/test_export_augment.py
git commit -m "feat(augment-export): parse_records + record_header"
```

---

### Task 6: `find_stamboom_note_span`

**Files:**
- Modify: `tools/export_augment.py`
- Test: `tests/test_export_augment.py`

- [ ] **Step 1: Write the failing test**

```python
def test_find_span_none_when_no_note():
    record = ["0 @I1@ INDI", "1 NAME Jan /Test/", "1 SEX M"]
    assert ea.find_stamboom_note_span(record) is None


def test_find_span_ignores_genealogical_note():
    record = ["0 @I1@ INDI", "1 NOTE A normal family note", "1 SEX M"]
    assert ea.find_stamboom_note_span(record) is None


def test_find_span_locates_stamboom_note_with_cont_lines():
    record = [
        "0 @I1@ INDI",
        "1 NAME Jan /Test/",
        "1 NOTE " + ea.BEGIN_MARKER,
        "2 CONT E-mail: a@@b.com",
        "2 CONT " + ea.END_MARKER,
        "1 SEX M",
    ]
    span = ea.find_stamboom_note_span(record)
    assert span == (2, 5)  # covers index 2,3,4; ends before "1 SEX M" at 5
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_export_augment.py -k find_span -v`
Expected: FAIL — no attribute `find_stamboom_note_span`

- [ ] **Step 3: Write minimal implementation**

Add to `tools/export_augment.py`:

```python
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_export_augment.py -k find_span -v`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add tools/export_augment.py tests/test_export_augment.py
git commit -m "feat(augment-export): find_stamboom_note_span"
```

---

### Task 7: `merge_record` (add / update / remove / noop)

**Files:**
- Modify: `tools/export_augment.py`
- Test: `tests/test_export_augment.py`

- [ ] **Step 1: Write the failing test**

```python
def test_merge_adds_block_when_absent():
    record = ["0 @I1@ INDI", "1 NAME Jan /Test/", "1 SEX M"]
    new, action = ea.merge_record(record, {"email": "a@b.com"})
    assert action == "added"
    assert new[:3] == record  # original lines preserved, block appended after
    assert new[3] == "1 NOTE " + ea.BEGIN_MARKER
    assert new[-1] == "2 CONT " + ea.END_MARKER


def test_merge_updates_existing_block_and_keeps_real_note():
    record = [
        "0 @I1@ INDI",
        "1 NOTE Echte genealogische notitie",
        "1 NOTE " + ea.BEGIN_MARKER,
        "2 CONT E-mail: old@@x.com",
        "2 CONT " + ea.END_MARKER,
        "1 SEX M",
    ]
    new, action = ea.merge_record(record, {"email": "new@x.com"})
    assert action == "updated"
    assert "1 NOTE Echte genealogische notitie" in new  # untouched
    assert "2 CONT E-mail: new@@x.com" in new
    assert not any("old@@x.com" in ln for ln in new)
    assert sum(1 for ln in new if ln == "1 NOTE " + ea.BEGIN_MARKER) == 1


def test_merge_removes_block_when_all_cleared():
    record = [
        "0 @I1@ INDI",
        "1 NOTE " + ea.BEGIN_MARKER,
        "2 CONT E-mail: a@@b.com",
        "2 CONT " + ea.END_MARKER,
        "1 SEX M",
    ]
    new, action = ea.merge_record(record, {"email": "", "bio": ""})
    assert action == "removed"
    assert new == ["0 @I1@ INDI", "1 SEX M"]


def test_merge_noop_when_no_fields_and_no_block():
    record = ["0 @I1@ INDI", "1 SEX M"]
    new, action = ea.merge_record(record, {})
    assert action == "noop"
    assert new == record
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_export_augment.py -k merge -v`
Expected: FAIL — no attribute `merge_record`

- [ ] **Step 3: Write minimal implementation**

Add to `tools/export_augment.py`:

```python
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_export_augment.py -k merge -v`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add tools/export_augment.py tests/test_export_augment.py
git commit -m "feat(augment-export): merge_record add/update/remove/noop"
```

---

### Task 8: `main()` — CLI, file IO, atomic write, summary

**Files:**
- Modify: `tools/export_augment.py`
- Test: `tests/test_export_augment.py`

- [ ] **Step 1: Write the failing test**

```python
def _write(tmp_path, augment: dict):
    g = tmp_path / "in.ged"
    g.write_bytes(SAMPLE.read_bytes())
    a = tmp_path / "augment.json"
    a.write_text(json.dumps({"augmentations": augment}), encoding="utf-8")
    return g, a


def _run(tmp_path, g, a, args=()):
    return subprocess.run(
        [sys.executable, str(TOOL), str(g), "--augment", str(a), *args],
        cwd=tmp_path, capture_output=True, text=True,
    )


def test_main_writes_default_output_with_block(tmp_path):
    g, a = _write(tmp_path, {
        "I7": {"email": "jan@ottenbourg.com",
               "facebook": "https://facebook.com/janottenbourg",
               "bio": "Regel een.\nRegel twee."}
    })
    r = _run(tmp_path, g, a)
    assert r.returncode == 0, r.stderr
    out = tmp_path / "in_augmented.ged"
    assert out.exists()
    text = out.read_text(encoding="utf-8")
    assert "1 NOTE -- stamboom-augment begin --" in text
    assert "2 CONT E-mail: jan@@ottenbourg.com" in text
    assert "2 CONT Bio: Regel een." in text
    assert "2 CONT Regel twee." in text


def test_main_warns_on_id_not_in_gedcom(tmp_path):
    g, a = _write(tmp_path, {"I999": {"email": "ghost@x.com"}})
    r = _run(tmp_path, g, a)
    assert r.returncode == 0
    assert "I999" in r.stderr
    assert "not found" in r.stderr.lower()


def test_main_does_not_overwrite_input_by_default(tmp_path):
    g, a = _write(tmp_path, {"I7": {"email": "jan@ottenbourg.com"}})
    before = g.read_bytes()
    _run(tmp_path, g, a)
    assert g.read_bytes() == before  # input untouched


def test_main_in_place_writes_bak_and_rewrites_input(tmp_path):
    g, a = _write(tmp_path, {"I7": {"email": "jan@ottenbourg.com"}})
    before = g.read_bytes()
    r = _run(tmp_path, g, a, args=("--in-place",))
    assert r.returncode == 0
    assert (tmp_path / "in.ged.bak").read_bytes() == before
    assert "1 NOTE -- stamboom-augment begin --" in g.read_text(encoding="utf-8")


def test_main_rejects_in_place_with_out(tmp_path):
    g, a = _write(tmp_path, {"I7": {"email": "jan@ottenbourg.com"}})
    r = _run(tmp_path, g, a, args=("--in-place", "--out", str(tmp_path / "x.ged")))
    assert r.returncode == 2
```

- [ ] **Step 2: Run test to verify it fails**

Run: `pytest tests/test_export_augment.py -k main -v`
Expected: FAIL — script has no `main`/CLI yet (no output file produced; returncode nonzero or AttributeError)

- [ ] **Step 3: Write minimal implementation**

Append to `tools/export_augment.py`:

```python
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
    if out_path == args.ged and not args.in_place:
        print("error: --out equals input; use --in-place to overwrite",
              file=sys.stderr)
        return 2

    try:
        aug_data = json.loads(args.augment.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as e:
        print(f"error: cannot read {args.augment}: {e}", file=sys.stderr)
        return 2
    augmentations = aug_data.get("augmentations", {})

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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `pytest tests/test_export_augment.py -k main -v`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add tools/export_augment.py tests/test_export_augment.py
git commit -m "feat(augment-export): main() CLI, atomic write, summary"
```

---

### Task 9: End-to-end idempotency + preservation

**Files:**
- Modify: `tests/test_export_augment.py`

- [ ] **Step 1: Write the failing test**

```python
def test_idempotent_rerun_on_augmented_file_is_byte_identical(tmp_path):
    g, a = _write(tmp_path, {
        "I7": {"email": "jan@ottenbourg.com", "bio": "Lang " * 80},
        "I3": {"facebook": "https://facebook.com/x"},
    })
    r1 = _run(tmp_path, g, a)
    assert r1.returncode == 0, r1.stderr
    out1 = (tmp_path / "in_augmented.ged").read_bytes()

    # Re-run using the already-augmented file as input.
    g2 = tmp_path / "in_augmented.ged"
    r2 = subprocess.run(
        [sys.executable, str(TOOL), str(g2), "--augment", str(a)],
        cwd=tmp_path, capture_output=True, text=True,
    )
    assert r2.returncode == 0, r2.stderr
    out2 = (tmp_path / "in_augmented_augmented.ged").read_bytes()
    assert out2 == out1  # byte-identical → no duplicate blocks, stable order


def test_exactly_one_block_per_augmented_indi(tmp_path):
    g, a = _write(tmp_path, {"I7": {"email": "jan@ottenbourg.com"}})
    _run(tmp_path, g, a)
    text = (tmp_path / "in_augmented.ged").read_text(encoding="utf-8")
    assert text.count("1 NOTE " + ea.BEGIN_MARKER) == 1


def test_output_still_parses_with_build_py_unchanged_counts(tmp_path):
    g, a = _write(tmp_path, {"I7": {"email": "jan@ottenbourg.com",
                                    "bio": "Regel een.\nRegel twee."}})
    _run(tmp_path, g, a)
    out = tmp_path / "in_augmented.ged"
    tree_out = tmp_path / "tree.json"
    r = subprocess.run(
        [sys.executable, str(ROOT / "build.py"), str(out), "--out", str(tree_out)],
        cwd=tmp_path, capture_output=True, text=True,
    )
    assert r.returncode == 0, r.stderr
    tree = json.loads(tree_out.read_text(encoding="utf-8"))
    assert tree["meta"]["individuals"] == 15  # same as sample.ged
    assert tree["meta"]["families"] == 7


def test_non_augmented_records_unchanged(tmp_path):
    g, a = _write(tmp_path, {"I7": {"email": "jan@ottenbourg.com"}})
    _run(tmp_path, g, a)
    src = SAMPLE.read_text(encoding="utf-8")
    out = (tmp_path / "in_augmented.ged").read_text(encoding="utf-8")
    # I1's record block is untouched (appears verbatim in output).
    i1_block = "0 @I1@ INDI\n1 NAME Désiré /Janssens/\n1 SEX M"
    assert i1_block in src and i1_block in out
```

- [ ] **Step 2: Run test to verify it fails (or passes)**

Run: `pytest tests/test_export_augment.py -k "idempotent or one_block or build_py or unchanged" -v`
Expected: PASS if Tasks 1-8 are correct. If any fail, fix the implementation in `tools/export_augment.py` until green — these are the acceptance tests for the spec's idempotency + preservation guarantees.

- [ ] **Step 3: (only if red) fix implementation**

Adjust `tools/export_augment.py` per failure. Common culprits: newline handling in `_read_text`/reassembly, or block placement in `merge_record`.

- [ ] **Step 4: Run the full test file**

Run: `pytest tests/test_export_augment.py -v`
Expected: PASS (all tests)

- [ ] **Step 5: Commit**

```bash
git add tests/test_export_augment.py
git commit -m "test(augment-export): idempotency + preservation e2e"
```

---

### Task 10: Wire-up — .gitignore, CI, docs

**Files:**
- Modify: `.gitignore`
- Modify: `.github/workflows/ci.yml`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add the output pattern to `.gitignore`**

In `.gitignore`, under the "Private family data — NEVER commit" section, add a line after `*.ged.bak`:

```
*_augmented.ged
```

- [ ] **Step 2: Add a CI step for the new tests**

In `.github/workflows/ci.yml`, immediately after the `pytest (parser)` step, add:

```yaml
      - name: pytest (augment-export)
        run: pytest tests/test_export_augment.py -v
```

- [ ] **Step 3: Run both pytest files locally to confirm green**

Run: `pytest tests/test_build.py tests/test_export_augment.py -v`
Expected: PASS (all)

- [ ] **Step 4: Document in `CLAUDE.md`**

Add a new section after "GEDCOM update (≈twice a year)":

```markdown
## Augment export (augment.json → GEDCOM)

`augment.json` (email/mobile/socials/bio entered via the web app) can be
merged into the GEDCOM as one marker-fenced `NOTE` per person:

```bash
python3 tools/export_augment.py jottenbourg.ged --augment augment.json
# writes jottenbourg_augmented.ged (input untouched); --in-place to overwrite
```

`augment.json` is authoritative — the export always overwrites/removes its
own `-- stamboom-augment begin/end --` block; genealogical notes are left
intact; re-runs are idempotent (byte-identical output).

**Manual GeneWeb round-trip check** (Geneanet runs GeneWeb 7.0, which keeps
notes but drops EMAIL/WWW tags): import `jottenbourg_augmented.ged` into a
local GeneWeb (`ged2gwb`) and re-export (`gwb2ged`); confirm the stamboom
NOTE block survives intact. Not part of CI.
```

Then in "Open follow-ups (v2 sub-projects)", change the
`stamboom-augment-export` bullet to:

```markdown
- ~~**`stamboom-augment-export`**~~ — DONE 2026-05-30. `tools/export_augment.py`
  merges augment.json into the GEDCOM as a marker-fenced NOTE per person.
```

- [ ] **Step 5: Commit**

```bash
git add .gitignore .github/workflows/ci.yml CLAUDE.md
git commit -m "chore(augment-export): gitignore output, CI step, docs"
```

---

## Self-Review

**Spec coverage:**
- Architecture & I/O (CLI, default `_augmented.ged`, `--in-place`+`.bak`, atomic write, UTF-8/CP1252) → Task 8. ✓
- Block format (markers, Dutch labels, non-empty only, deterministic order) → Task 4. ✓
- Encoding (CONT/CONC, 200-byte split, `@@`, multi-line bio) → Tasks 2, 3, 4. ✓
- Idempotent merge (add/update/remove/noop, dedicated NOTE detection, genealogical notes untouched, not-found warn, byte-identical re-run) → Tasks 6, 7, 8, 9. ✓
- CLI output/exit codes → Task 8. ✓
- Testing (all 8 spec cases) → Tasks 1-9. ✓
- Privacy (`*_augmented.ged` gitignored) → Task 10. ✓
- Docs (CLAUDE.md section + follow-up moved) → Task 10. ✓

**Placeholder scan:** none — every code/test step shows full content.

**Type consistency:** `merge_record` returns `(list[str], str)`; `find_stamboom_note_span` returns `(int,int)|None`; `record_header` returns `(str|None, str)`; `build_block_lines`/`encode_logical_lines`/`split_value` all `list[str]`; constants `BEGIN_MARKER`/`END_MARKER`/`MAX_VALUE_BYTES`/`FIELDS` defined in Task 1 and referenced consistently thereafter. Consistent.
