# Design — stamboom GEDCOM export UI (admin button)

**Date:** 2026-05-30
**Component:** `lib/gedcom_export.php`, `admin/export_gedcom.php`, button in `admin.php`
**Status:** Approved (design); pending implementation plan
**Depends on:** `tools/export_augment.py` (shipped on `main` 2026-05-30; see
`docs/superpowers/specs/2026-05-30-stamboom-augment-export-design.md`).

## Goal

Give an admin a one-click way, from the **Beheer → Stamboom-data** tab, to
download a GEDCOM with the augmentation data merged in (the same output the
`tools/export_augment.py` CLI produces). No more shelling into the box to run
the tool by hand.

## Decisions taken during brainstorming

- **Reuse the Python tool, don't reimplement** (Approach A). The merge logic
  (CONT/CONC splitting, `@@` escaping, marker-fenced NOTE, idempotency) lives
  only in `tools/export_augment.py`. The PHP layer shells out to it. Verified
  feasible on the box: `python3` 3.10.12 present; PHP 8.1-FPM
  `disable_functions` is empty (so `proc_open` is available).
- **Admin-only.** Output contains everyone's contact details, so the action is
  gated by `requireAdmin()` and the button only appears on the admin page.
- **Augmented export only.** The plain GEDCOM already lives on the box; a
  non-augmented download is out of scope (YAGNI).
- **Stream, never persist in the webroot.** Output is written to
  `sys_get_temp_dir()`, streamed to the browser, then deleted. Nothing
  downloadable-by-URL is ever placed under the web root.

## Reference facts (verified on the box)

- `python3` → `/usr/bin/python3` (3.10.12). The tool needs only Python ≥3.8.
- PHP 8.1-FPM, `disable_functions = ` (empty) → `proc_open`/`exec` available.
- Admin actions follow a fixed pattern: separate POST endpoints under
  `admin/` (e.g. `admin/role.php`), each doing `requireAuth()` +
  `requireAdmin()` + `csrfCheck($_POST['_csrf'])`, then redirecting back to
  `admin.php?...&status=<x>` which renders a `.toast`.
- CSRF helpers: `csrfToken()` (in forms as hidden `_csrf`) and
  `csrfCheck(?string)` (in `auth.php`).
- `admin/export_gedcom.php` lives in the `admin/` subdir, so the web root is
  `dirname(__DIR__)`; data files sit at `<root>/jottenbourg.ged` and
  `<root>/augment.json` (`640 www-data`, readable by the FPM user); the tool
  at `<root>/tools/export_augment.py`.

## Components

### `lib/gedcom_export.php` (testable core)

```
stam_export_augmented_to_tmp(string $gedPath, string $augmentPath,
                             string $toolPath): array
```

- Validates inputs exist/readable; returns `[false, $dutchError]` if not.
- Creates a temp output path via `tempnam(sys_get_temp_dir(), 'stamboom_ged_')`.
- Runs the tool with **`proc_open` using an argument array** (PHP 7.4+ array
  form runs WITHOUT `/bin/sh`, so there is no shell to inject into; there is
  no user-controlled input in the command regardless):
  `['python3', $toolPath, $gedPath, '--augment', $augmentPath, '--out', $tmp]`.
  Resolve the interpreter robustly: use `/usr/bin/python3` if it exists (the
  box has it), else fall back to the bare `python3` from PATH — so the same
  code works on the box and in CI.
- Captures exit code + stderr. On non-zero exit or empty/zero-length output:
  delete the temp file, return `[false, $dutchError]` (with stderr logged by
  the caller).
- On success: return `[true, $tmpPath]`. Caller owns streaming + cleanup.

This function is the single unit-tested seam. It does no HTTP, no auth, no
output — just "run the tool, give me a temp file or an error".

### `admin/export_gedcom.php` (endpoint, thin glue)

Mirrors `admin/role.php`:

1. `require auth.php`; `requireAuth()`; `requireAdmin()`.
2. `csrfCheck($_POST['_csrf'] ?? null)`.
3. Accept POST only (reject GET → redirect to `admin.php?tab=tree`).
4. Call `stam_export_augmented_to_tmp(<root>/jottenbourg.ged,
   <root>/augment.json, <root>/tools/export_augment.py)`.
5. **On failure:** `error_log()` the detail, redirect to
   `admin.php?tab=tree&status=export_error`, exit.
6. **On success:** send download headers and stream:
   - `Content-Type: application/octet-stream`
   - `Content-Disposition: attachment; filename="jottenbourg_augmented_<YYYY-MM-DD>.ged"`
     (date from `gmdate('Y-m-d')`)
   - `Content-Length: <filesize>`
   - `readfile($tmp)`, then `unlink($tmp)`, then `exit`.

### `admin.php` — Stamboom-data (tree) tab

- Add a toast for the error case, alongside the existing ones:
  `if ($status === 'export_error'): <div class="toast">Export mislukt — zie serverlog.</div>`
- In the `tab === 'tree'` section, under "Stamboom verversen", add a new
  subsection:

  ```html
  <h3 style="margin-top:24px">Verrijkte GEDCOM exporteren</h3>
  <p>Download een GEDCOM met de aanvullende gegevens (e-mail, telefoon,
     sociale links, bio) samengevoegd als notitie per persoon. <strong>Let
     op:</strong> dit bestand bevat contactgegevens — deel het niet publiek.</p>
  <form method="post" action="admin/export_gedcom.php">
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
    <button type="submit">Exporteer verrijkte GEDCOM</button>
  </form>
  ```

### `style.css`

Reuse existing admin button styling; no new classes anticipated. (If the bare
`<button>` looks unstyled, add a minimal monochrome rule consistent with the
site — to be decided during implementation, not a new design surface.)

## Security

- Gated at **both** layers: the button only renders inside the already
  `requireAdmin()`-gated `admin.php`; the endpoint independently enforces
  `requireAuth()` + `requireAdmin()`.
- CSRF: `csrfCheck` on the POST, same as every other admin action.
- No shell: `proc_open` array form. No user input reaches the command.
- The generated PII file is written only to the system temp dir, streamed,
  and `unlink`ed immediately. Nothing is placed under the web root.
- POST-only; GET is bounced back to the tab.

## Errors

- Missing/unreadable `jottenbourg.ged`, `augment.json`, or the tool; tool
  non-zero exit; empty output → Dutch toast `Export mislukt — zie serverlog.`
  on the tree tab, with specifics sent to `error_log()`. No partial file is
  ever streamed (headers are only sent after the lib function returns ok).

## Testing

- **`tests/test_gedcom_export.php`** (PHP, in the style of
  `tests/test_can_edit.php`; CI has both php 8.2 and python3):
  - Build/locate a fixture: copy `sample.ged`; write a temp `augment.json`
    with `{"augmentations": {"I7": {"email": "jan@ottenbourg.com",
    "facebook": "https://facebook.com/x"}}}`.
  - Call `stam_export_augmented_to_tmp(...)` pointing `$toolPath` at the real
    `tools/export_augment.py`.
  - Assert `[0] === true`; the returned temp file exists; its contents contain
    `-- stamboom-augment begin --` and `E-mail: jan@@ottenbourg.com`.
  - Negative case: a non-existent ged path → `[0] === false` and a non-empty
    Dutch error string, no temp file leaked.
  - Clean up temp files.
- **Smoke** (extend `tests/smoke_v2.sh`): an unauthenticated `POST` to
  `admin/export_gedcom.php` must NOT return 200 (expect 302 redirect to login
  or 403) and must NOT emit GEDCOM bytes.
- Wire `tests/test_gedcom_export.php` into `.github/workflows/ci.yml` as a new
  step after the existing PHP unit steps.

## Documentation

- `CLAUDE.md`: add a short note under the augment-export material —
  "Admins can also export from the web: **Beheer → Stamboom-data → Exporteer
  verrijkte GEDCOM** (runs `tools/export_augment.py` server-side, streams the
  result, never stores it in the webroot)."

## Deploy

Shipping requires `bash deploy-to-lightsail.sh` so the box receives
`admin/export_gedcom.php`, `lib/gedcom_export.php`, **and**
`tools/export_augment.py` (the latter is on `main` but not yet deployed).
After deploy, smoke-test the button as an admin and confirm the downloaded
file contains the stamboom NOTE block.

## Out of scope (YAGNI)

- Non-augmented / plain GEDCOM download (already on the box).
- Choosing which fields/people to include (the CLI's behavior is fixed:
  augment.json is authoritative).
- Progress UI / async jobs (export of ~500 individuals is sub-second).
- Any change to `tools/export_augment.py` itself or to the site's data.
