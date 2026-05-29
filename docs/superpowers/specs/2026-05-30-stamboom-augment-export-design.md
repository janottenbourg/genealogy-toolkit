# Design — stamboom augment-export to GEDCOM

**Date:** 2026-05-30
**Component:** `tools/export_augment.py`
**Status:** Approved (design); pending implementation plan

## Goal

Round-trip the v2 augmentation data (`email`, `mobile`, `facebook`,
`linkedin`, `instagram`, `bio`) out of `augment.json` and **into** the
GEDCOM, so the data is:

1. **Self-contained** — embedded in the canonical `.ged` on the workstation,
   not siloed in `augment.json` (survives if `augment.json` is lost or if
   Geneanet INDI ids drift).
2. **Geneanet-safe** — structured so it survives a re-import into Geneanet.

Both goals at once ("safe either way").

## Decisions taken during brainstorming

- **Source of truth:** `augment.json` is authoritative for these 6 fields —
  they are entered *only* via the stamboom web app, never inside Geneanet.
  Therefore the export **always overwrites** its own managed content; no
  conflict policy is needed.
- **Representation:** Approach **B** — one dedicated `NOTE` per person
  holding a marker-fenced, human-readable block. Chosen because Geneanet runs
  **GeneWeb 7.0**, whose importer (`ged2gwb`) only keeps unrecognized tags
  (`EMAIL`/`PHON`/`WWW`) when run with `-uin` — a flag we do not control on
  hosted geneanet.org. GeneWeb *does* fully model per-person **notes** (the
  real export already carries `1 NOTE`), so `NOTE` is the only channel that
  reliably round-trips. Structured `EMAIL`/`WWW`/`PHON` tags (Approach A/C)
  were rejected as YAGNI: they buy nothing for Geneanet and only help *other*
  genealogy software, which is not part of the workflow.
- **Output file:** writes a **separate** `<stem>_augmented.ged` by default;
  never overwrites the input unless `--in-place` is passed (which first
  backs up to `<input>.bak`).

## Reference facts (verified)

- Real `jottenbourg.ged` header: `1 SOUR Geneanet` / `2 NAME GeneWeb` /
  `2 VERS 7.0.0`, GEDCOM `5.5.1`, `CHAR UTF-8`.
- The real export uses INDI-level `NOTE` (confirmed present) and `OBJE`;
  no `EMAIL`/`WWW`/`PHON`/`RESI` tags, no underscore custom tags.
- Augmentation schema (`lib/augment.php`): keys `email, mobile, facebook,
  linkedin, instagram, bio`, plus metadata `updated_at`, `updated_by`.
  Metadata is **not** exported (internal bookkeeping only).
- `bio` validator allows up to 4096 chars and may contain newlines/tabs
  (`lib/validate.php` `stam_v_bio`).

## Architecture & I/O

Standalone Python 3.12 CLI, **stdlib only** (mirrors `build.py`). Stateless,
no network.

```
python3 tools/export_augment.py <input.ged> [--augment augment.json]
                                 [--out FILE] [--in-place] [-v]
```

- `<input.ged>` — positional, required. The canonical GEDCOM to augment.
- `--augment` — path to `augment.json`. Default: `augment.json` in CWD/repo
  root.
- `--out FILE` — output path. Default: `<input_stem>_augmented.ged` in the
  input's directory.
- `--in-place` — overwrite `<input.ged>` after writing `<input.ged>.bak`.
  Mutually exclusive with an explicit `--out`.
- `-v` / `--verbose` — list per-id actions (added / updated / removed /
  not-found).

Runs on the workstation (or the box) against the local `jottenbourg.ged` +
`augment.json`. `augment.json` is already pulled back locally after each
deploy per the `feedback_deploy_download_json` memory rule, so the local copy
is current.

Output is written atomically: temp file + `os.replace` (same pattern as
`build.py`).

## The augmentation block

Each augmented person gets **one dedicated `1 NOTE`**, distinct from any
genealogical notes (which are never read or modified). The block:

```
1 NOTE -- stamboom-augment begin --
2 CONT E-mail: jan@@ottenbourg.com
2 CONT Mobiel: +32 ...
2 CONT Facebook: https://facebook.com/janottenbourg
2 CONT LinkedIn: https://linkedin.com/in/janottenbourg/
2 CONT Instagram: https://instagram.com/...
2 CONT Bio: <eerste regel van de bio>
2 CONT <tweede regel>
2 CONT -- stamboom-augment end --
```

Rules:

- **Markers** `-- stamboom-augment begin --` and `-- stamboom-augment end --`
  are plain ASCII (only letters, spaces, hyphens) — no GeneWeb wiki-markup
  characters (`[`, `]`, `=`, `*`) — so they render cleanly in the Geneanet UI
  and survive a GeneWeb round-trip unmangled. The begin marker is also the
  detection key for idempotent replace/remove.
- **Field lines**: emitted **only for non-empty fields**, in fixed order
  (email, mobile, facebook, linkedin, instagram, bio). Dutch labels matching
  the site (`E-mail`, `Mobiel`, `Facebook`, `LinkedIn`, `Instagram`, `Bio`).
- **Field order** is deterministic so output is stable across runs.

### Encoding details (handled + tested)

- **Line structure**: first physical line is `1 NOTE <text>`; every
  subsequent logical line is `2 CONT <text>` (GeneWeb's own convention).
- **Long lines**: any logical line whose `<text>` exceeds 255 **bytes** in
  UTF-8 is split with `2 CONC` continuations. Splits never fall inside a
  multibyte UTF-8 sequence.
- **`@` escaping**: a literal `@` in a value is doubled to `@@` per GEDCOM
  5.5.1 (matters for email addresses). The round-trip test verifies GeneWeb
  reads it back as a single `@`.
- **`bio` newlines**: each newline in the bio becomes a new `2 CONT` line.
  Control characters other than the bio's own newlines are already stripped
  upstream by `stam_v_bio`, but the exporter defensively strips any remaining
  CR and control chars.

## Idempotent merge algorithm

1. Read input bytes; decode UTF-8 with CP1252 fallback (same as `build.py`).
2. Tokenize into lines, tracking record boundaries (level-0 lines). Preserve
   **every line we do not explicitly rewrite**, including HEAD, TRLR, FAM
   records, and all non-stamboom INDI subrecords — byte-for-byte.
3. For each `INDI` record whose id is a key in `augment.json`:
   - Compute the set of non-empty fields.
   - **Locate** the record's existing dedicated stamboom `NOTE`: a level-1
     `NOTE` whose value (line + following `CONT`/`CONC`) **begins with** the
     begin marker.
   - **If ≥1 non-empty field:**
     - If a stamboom `NOTE` exists → replace its lines in place with the
       freshly built block.
     - Else → insert a new `1 NOTE …` block at the **end of the INDI's
       level-1 lines** (immediately before the next level-0 line).
   - **If 0 non-empty fields (all cleared):**
     - If a stamboom `NOTE` exists → remove it (augment.json is
       authoritative; cleared means gone).
     - Else → no-op.
   - Genealogical `NOTE`s (those not starting with the begin marker) are
     never touched.
4. INDI ids present in `augment.json` but **absent** from the GEDCOM → emit a
   warning to stderr, increment the "not found" counter, skip.
5. Write output atomically.

**Idempotency guarantee:** running the tool twice on the same inputs produces
**byte-identical** output. Blocks are never duplicated; the begin-marker
detection makes replace and remove deterministic.

## CLI output

- Summary line to stdout: `added A · updated U · removed K · not-found M`,
  where **added** = persons that gained a new stamboom block, **updated** =
  persons whose existing block was replaced, **removed** = persons whose
  block was deleted because all fields were cleared, **not-found** = augment
  ids absent from the GEDCOM.
- `-v`: one line per affected id, e.g. `I7: updated`, `I12: not found`.
- Exit codes: `0` success; `2` on input error (file missing, bad JSON,
  `--in-place` combined with `--out`, output path equals input without
  `--in-place`).

## Testing

Pytest, alongside `tests/test_build.py`, wired into the existing `.github`
CI workflow. Fixtures: `tests/fixtures/` (reuse `sample.ged`; add a small
`augment_fixture.json`).

Cases:

1. **Block formatting** — fields → expected lines; empty fields omitted;
   Dutch labels; deterministic order.
2. **Encoding** — multi-line bio → multiple `CONT`; a >255-byte line →
   `CONC` split at a UTF-8 boundary; `@` → `@@`.
3. **Idempotency** — run twice → byte-identical; exactly one block per
   augmented INDI on re-parse.
4. **Update-in-place** — changing a field updates the block; a pre-existing
   genealogical `NOTE` on the same INDI is left intact.
5. **Removal** — clearing all fields removes the stamboom `NOTE`; other
   content intact.
6. **Not-found** — augment id absent from GEDCOM → warning, no crash, counted.
7. **Round-trip parse** — feed output back through the tool's own tokenizer
   (and `build.py`'s parser) → still valid, markers present, one block each.
8. **Preservation** — diff output vs input for an INDI with no augmentation →
   no changes anywhere outside augmented records.

A true GeneWeb round-trip (`ged2gwb` → `gwb2ged`) is the ultimate check but
requires GeneWeb installed; documented as a **manual** verification step in
`CLAUDE.md`, not in CI.

## Privacy

- Output `<stem>_augmented.ged` contains full PII (emails, phone, bio).
  Add `*_augmented.ged` to `.gitignore`.
- The tool runs only on the workstation/box; output is never committed.
- `augment.json` and the real `jottenbourg.ged` remain gitignored as today.

## Documentation

Add to `stamboom/CLAUDE.md`:

- An "Augment export" section: the command, the separate-output default, and
  the manual GeneWeb round-trip verification step.
- Move the `stamboom-augment-export` follow-up from "Open follow-ups" to done
  once implemented.

## Out of scope (YAGNI)

- Structured `EMAIL`/`PHON`/`WWW` tags (Approach A/C).
- Exporting `updated_at`/`updated_by` metadata.
- Importing/parsing augmentation *back out* of a GEDCOM into `augment.json`
  (reverse direction) — not needed; `augment.json` is the source of truth.
- Any change to `build.py` or the running site.
```
