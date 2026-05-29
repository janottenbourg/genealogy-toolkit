# stamboom GEDCOM export UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin-only "Exporteer verrijkte GEDCOM" button to **Beheer → Stamboom-data** that runs `tools/export_augment.py` server-side and downloads the augmented GEDCOM, without persisting it in the web root.

**Architecture:** A thin POST endpoint (`admin/export_gedcom.php`) calls a testable library function (`lib/gedcom_export.php::stam_export_augmented_to_tmp`) which shells out to the existing Python tool via `proc_open` (array form, no shell), writes to the system temp dir, and returns a path; the endpoint streams that file as a download and deletes it. Reuses the CLI tool as the single source of truth for the merge logic.

**Tech Stack:** PHP 8.1 (prod) / 8.2 (CI), Python 3 (the tool), existing auth/CSRF helpers, bash smoke harness.

**Spec:** `docs/superpowers/specs/2026-05-30-stamboom-gedcom-export-ui-design.md`

---

## Environment notes for the implementer (READ FIRST)

- **PHP is not on PATH on this Windows workstation.** Use `/c/php/php.exe` to run PHP locally. (CI uses bare `php`.)
- **`python3` on this workstation is the broken Windows Store alias** (prints "Python was not found", non-matching version). Only `python` / `py` work locally. The library's interpreter resolver therefore validates the `--version` output. On the Lightsail box and in CI, `/usr/bin/python3` / `python3` work normally.
- Run the Bash tool for git, `/c/php/php.exe`, and `bash tests/smoke_v2.sh`.
- You are on branch `feat/gedcom-export-ui`. Stay on it. Commit per task.

## File Structure

- **Create** `lib/gedcom_export.php` — `stam_python_bin()` (validated interpreter discovery) + `stam_export_augmented_to_tmp($ged, $augment, $tool, $python=null)`. The single testable seam. No HTTP, no auth, no output.
- **Create** `admin/export_gedcom.php` — POST endpoint: auth + admin + CSRF, calls the lib, streams or error-redirects.
- **Create** `tests/test_gedcom_export.php` — standalone PHP test of the lib function (happy + missing-input paths).
- **Modify** `admin.php` — add the export subsection + button in the `tree` tab, and an `export_error` toast.
- **Modify** `tests/smoke_v2.sh` — endpoint auth/CSRF/happy-path checks + button-presence check.
- **Modify** `.github/workflows/ci.yml` — add a step running `tests/test_gedcom_export.php`.
- **Modify** `CLAUDE.md` — document the admin export button.

---

### Task 1: `lib/gedcom_export.php` + unit test

**Files:**
- Create: `lib/gedcom_export.php`
- Create: `tests/test_gedcom_export.php`

- [ ] **Step 1: Write the failing test**

Create `tests/test_gedcom_export.php`:

```php
<?php
/*
 * Unit tests for lib/gedcom_export.php::stam_export_augmented_to_tmp().
 * Runs the real tools/export_augment.py against sample.ged + a temp augment.
 * Run:  php tests/test_gedcom_export.php   (workstation: /c/php/php.exe ...)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
require __DIR__ . '/../lib/gedcom_export.php';

$pass = 0; $fail = 0;
function check(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) { echo "  PASS  $label\n"; $pass++; }
    else       { echo "  FAIL  $label\n"; $fail++; }
}

$tool = $root . '/tools/export_augment.py';
$ged  = $root . '/sample.ged';

$aug = tempnam(sys_get_temp_dir(), 'aug_');
file_put_contents($aug, json_encode(['augmentations' => [
    'I7' => ['email' => 'jan@ottenbourg.com', 'facebook' => 'https://facebook.com/x'],
]]));

echo "==> happy path\n";
[$ok, $res] = stam_export_augmented_to_tmp($ged, $aug, $tool);
check($ok === true, 'returns ok');
check($ok && is_string($res) && is_file($res), 'temp output file exists');
if ($ok && is_file($res)) {
    $out = file_get_contents($res);
    check(strpos($out, '-- stamboom-augment begin --') !== false, 'contains begin marker');
    check(strpos($out, 'E-mail: jan@@ottenbourg.com') !== false, 'contains @@-escaped email');
    check(strpos($out, '0 HEAD') !== false, 'still a GEDCOM (0 HEAD present)');
    @unlink($res);
}

echo "==> error path: missing GEDCOM\n";
[$ok2, $err2] = stam_export_augmented_to_tmp($root . '/does_not_exist.ged', $aug, $tool);
check($ok2 === false, 'missing ged → not ok');
check(is_string($err2) && $err2 !== '', 'missing ged → non-empty Dutch error');

@unlink($aug);
echo "\nSummary: $pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `/c/php/php.exe tests/test_gedcom_export.php`
Expected: FATAL — `require(...lib/gedcom_export.php): Failed to open stream` (file does not exist yet).

- [ ] **Step 3: Write minimal implementation**

Create `lib/gedcom_export.php`:

```php
<?php
/*
 * Server-side bridge to tools/export_augment.py. Produces an augmented GEDCOM
 * in the system temp dir; the caller streams it and deletes it. No HTTP, no
 * auth, no output here — that is the endpoint's job.
 */

/**
 * Find a working Python 3 interpreter. Validates that `<cand> --version`
 * actually prints "Python 3." — this rejects the Windows Store stub alias
 * (which exits but prints "Python was not found"). Result is cached.
 */
function stam_python_bin(): string {
    static $bin = null;
    if ($bin !== null) return $bin;
    foreach (['/usr/bin/python3', 'python3', 'python', 'py'] as $cand) {
        $descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $p = @proc_open([$cand, '--version'], $descr, $pipes);
        if (!is_resource($p)) continue;
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        if ($code === 0 && preg_match('/^Python 3\./', ltrim($out))) {
            $bin = $cand;
            return $bin;
        }
    }
    $bin = '/usr/bin/python3';  // sensible production fallback
    return $bin;
}

/**
 * Run the export tool. Returns [true, $tmpPath] on success (caller must
 * unlink) or [false, $dutchError] on failure (no temp file leaked).
 */
function stam_export_augmented_to_tmp(string $gedPath, string $augmentPath,
                                      string $toolPath, ?string $python = null): array {
    foreach (['GEDCOM' => $gedPath, 'augmentatie' => $augmentPath, 'export-tool' => $toolPath] as $what => $path) {
        if (!is_file($path) || !is_readable($path)) {
            return [false, "Exportbron niet gevonden of onleesbaar ($what)."];
        }
    }
    $python = $python ?? stam_python_bin();
    $tmp = tempnam(sys_get_temp_dir(), 'stamboom_ged_');
    if ($tmp === false) {
        return [false, 'Kon geen tijdelijk bestand aanmaken.'];
    }

    $cmd = [$python, $toolPath, $gedPath, '--augment', $augmentPath, '--out', $tmp];
    $descr = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = proc_open($cmd, $descr, $pipes);
    if (!is_resource($proc)) {
        @unlink($tmp);
        return [false, 'Kon het export-proces niet starten.'];
    }
    stream_get_contents($pipes[1]); fclose($pipes[1]);   // tool prints a summary; ignore
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0 || !is_file($tmp) || filesize($tmp) === 0) {
        @unlink($tmp);
        return [false, 'Export mislukt (exitcode ' . $code . '): ' . trim($stderr)];
    }
    return [true, $tmp];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `/c/php/php.exe tests/test_gedcom_export.php`
Expected: `Summary: 6 passed, 0 failed` (exit 0). The resolver picks `python` locally; the tool runs and produces the augmented file.

- [ ] **Step 5: Commit**

```bash
git add lib/gedcom_export.php tests/test_gedcom_export.php
git commit -m "feat(gedcom-export-ui): lib/gedcom_export.php + unit test"
```

---

### Task 2: `admin/export_gedcom.php` endpoint + smoke checks

**Files:**
- Create: `admin/export_gedcom.php`
- Modify: `tests/smoke_v2.sh`

- [ ] **Step 1: Write the failing smoke checks**

In `tests/smoke_v2.sh`, find the section header line:

```bash
echo "==> PHP error log scan"
```

Insert this block IMMEDIATELY BEFORE that line:

```bash
echo "==> GEDCOM export (admin)"
# Unauthenticated POST must redirect to login, not return the file.
code=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/admin/export_gedcom.php")
[[ "$code" == "302" ]] && { echo "  PASS  unauth export → 302"; ((pass++)); } || { echo "  FAIL  unauth export → $code"; ((fail++)); }
# Admin POST without CSRF token → 403.
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIES_ADMIN" -X POST "$BASE/admin/export_gedcom.php")
[[ "$code" == "403" ]] && { echo "  PASS  export without CSRF → 403"; ((pass++)); } || { echo "  FAIL  export no-csrf → $code"; ((fail++)); }
# Admin POST with CSRF → 200 + a GEDCOM that includes the augment block.
csrf=$(curl -s -b "$COOKIES_ADMIN" "$BASE/admin.php?tab=tree" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
code=$(curl -s -o /tmp/exp.ged -w "%{http_code}" -b "$COOKIES_ADMIN" -d "_csrf=$csrf" "$BASE/admin/export_gedcom.php")
[[ "$code" == "200" ]] && { echo "  PASS  admin export → 200"; ((pass++)); } || { echo "  FAIL  admin export → $code"; ((fail++)); }
grep -q "0 HEAD" /tmp/exp.ged && { echo "  PASS  export is a GEDCOM"; ((pass++)); } || { echo "  FAIL  export not a GEDCOM"; ((fail++)); }
grep -q -- "-- stamboom-augment begin --" /tmp/exp.ged && { echo "  PASS  export has augment block"; ((pass++)); } || { echo "  FAIL  export missing augment block"; ((fail++)); }
rm -f /tmp/exp.ged
```

- [ ] **Step 2: Run smoke to verify the new checks fail**

Run: `bash tests/smoke_v2.sh`
Expected: the three export checks FAIL — the endpoint does not exist yet, so the unauth/CSRF POSTs return `404` (not `302`/`403`) and the happy-path returns `404` with no GEDCOM body. (Earlier checks still pass.)

- [ ] **Step 3: Write minimal implementation**

Create `admin/export_gedcom.php`:

```php
<?php
/*
 * Admin-only: stream a GEDCOM with augmentation merged in. POST + CSRF.
 * Runs tools/export_augment.py against the current source GEDCOM (the one
 * tree.json was built from) + augment.json, streams the result, deletes it.
 */
require_once __DIR__ . '/../auth.php';
requireAuth();
require_once __DIR__ . '/../lib/users.php';
requireAdmin();
require_once __DIR__ . '/../lib/tree.php';
require_once __DIR__ . '/../lib/gedcom_export.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: admin.php?tab=tree');
    exit;
}
csrfCheck($_POST['_csrf'] ?? null);

$root    = dirname(__DIR__);
$src     = basename((string)(stam_meta()['source'] ?? 'jottenbourg.ged'));
$ged     = $root . '/' . $src;
$augment = $root . '/augment.json';
$tool    = $root . '/tools/export_augment.py';

[$ok, $res] = stam_export_augmented_to_tmp($ged, $augment, $tool);
if (!$ok) {
    error_log('stamboom GEDCOM export failed: ' . $res);
    header('Location: admin.php?tab=tree&status=export_error');
    exit;
}

$fname = 'jottenbourg_augmented_' . gmdate('Y-m-d') . '.ged';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $fname . '"');
header('Content-Length: ' . filesize($res));
readfile($res);
@unlink($res);
exit;
```

- [ ] **Step 4: Run smoke to verify the export checks pass**

Run: `bash tests/smoke_v2.sh`
Expected: the export block now prints PASS for `unauth export → 302`, `export without CSRF → 403`, `admin export → 200`, `export is a GEDCOM`, `export has augment block`. Summary shows 0 failed.

- [ ] **Step 5: Commit**

```bash
git add admin/export_gedcom.php tests/smoke_v2.sh
git commit -m "feat(gedcom-export-ui): admin/export_gedcom.php endpoint + smoke"
```

---

### Task 3: Button + error toast in `admin.php`

**Files:**
- Modify: `admin.php`
- Modify: `tests/smoke_v2.sh`

- [ ] **Step 1: Add a button-presence smoke check**

In `tests/smoke_v2.sh`, in the `==> GEDCOM export (admin)` block added in Task 2, add this line at the END of that block (after the `rm -f /tmp/exp.ged` line):

```bash
curl -s -b "$COOKIES_ADMIN" "$BASE/admin.php?tab=tree" | grep -q 'admin/export_gedcom.php' && { echo "  PASS  export button on tree tab"; ((pass++)); } || { echo "  FAIL  no export button"; ((fail++)); }
```

- [ ] **Step 2: Run smoke to verify the new check fails**

Run: `bash tests/smoke_v2.sh`
Expected: `FAIL  no export button` (the button isn't in admin.php yet). The Task 2 export checks still PASS.

- [ ] **Step 3: Add the button and error toast to `admin.php`**

(3a) In `admin.php`, find the existing toast line:

```php
  <?php if ($status === 'reset'):   ?><div class="toast">Reset-link aangemaakt.</div><?php endif; ?>
```

Add immediately after it:

```php
  <?php if ($status === 'export_error'): ?><div class="toast">Export mislukt — zie serverlog.</div><?php endif; ?>
```

(3b) In the `tree` tab, find the existing paragraph that ends the "Stamboom verversen" subsection:

```php
      <p>Twee keer per jaar (of na grote wijzigingen) exporteer je een verse GEDCOM uit Geneanet en upload je die naar de server. Recept staat in <code>CLAUDE.md</code> sectie "GEDCOM update".</p>
```

Add immediately after that `</p>` (still inside the `tree` section, before `</section>`):

```php
      <h3 style="margin-top:24px">Verrijkte GEDCOM exporteren</h3>
      <p>Download een GEDCOM met de aanvullende gegevens (e-mail, telefoon,
         sociale links, bio) samengevoegd als notitie per persoon.
         <strong>Let op:</strong> dit bestand bevat contactgegevens — deel het niet publiek.</p>
      <form method="post" action="admin/export_gedcom.php">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf) ?>">
        <button type="submit">Exporteer verrijkte GEDCOM</button>
      </form>
```

- [ ] **Step 4: Run smoke to verify it passes**

Run: `bash tests/smoke_v2.sh`
Expected: `PASS  export button on tree tab`, all export checks PASS, and `PASS  no PHP errors`. Summary: 0 failed.

- [ ] **Step 5: Commit**

```bash
git add admin.php tests/smoke_v2.sh
git commit -m "feat(gedcom-export-ui): export button + error toast on Beheer"
```

---

### Task 4: Wire-up — CI step + docs

**Files:**
- Modify: `.github/workflows/ci.yml`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Add a CI step for the new PHP unit test**

In `.github/workflows/ci.yml`, find the existing step:

```yaml
      - name: canEdit unit
        run: |
          python3 build.py sample.ged > /dev/null
          php tests/test_can_edit.php
```

Add a new step immediately after it:

```yaml
      - name: gedcom export unit
        run: php tests/test_gedcom_export.php
```

(No `build.py` prerequisite needed — the lib test takes explicit paths and does not read `tree.json`.)

- [ ] **Step 2: Document the feature in `CLAUDE.md`**

In `CLAUDE.md`, find the "Augment export" section added earlier. At the END of that section (after the "Manual GeneWeb round-trip check" paragraph, before the next `##` heading), add:

```markdown

Admins can also export from the web UI: **Beheer → Stamboom-data → Exporteer
verrijkte GEDCOM**. The endpoint `admin/export_gedcom.php` runs
`tools/export_augment.py` server-side (via `lib/gedcom_export.php`), streams the
result as a download, and never stores it under the web root. Admin-only +
CSRF-protected.
```

- [ ] **Step 3: Confirm the PHP unit test and smoke still pass locally**

Run: `/c/php/php.exe tests/test_gedcom_export.php`
Expected: `Summary: 6 passed, 0 failed`.

Run: `bash tests/smoke_v2.sh`
Expected: `Summary: <N> passed, 0 failed` (includes the export + button checks).

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml CLAUDE.md
git commit -m "chore(gedcom-export-ui): CI unit step + CLAUDE.md docs"
```

---

## Self-Review

**Spec coverage:**
- `lib/gedcom_export.php` core (proc_open array form, temp output, error handling) → Task 1. ✓
- Interpreter resolution robust across box/CI/Windows (validates "Python 3.") → Task 1 `stam_python_bin`. ✓
- Endpoint: auth + admin + CSRF, POST-only, source from `stam_meta()['source']` (basename, no traversal), stream + unlink, error redirect → Task 2. ✓
- Button + error toast on the tree tab → Task 3. ✓
- Security: admin-gated both layers, CSRF, no shell, PII streamed-then-deleted, never in webroot → Tasks 2 & 3 (verified by smoke unauth/CSRF checks). ✓
- Testing: lib unit test (happy + error) + smoke (auth/CSRF/happy/button) → Tasks 1–3; CI wired → Task 4. ✓
- Docs → Task 4. ✓
- Deploy note (ship requires deploy bringing `tools/export_augment.py`) → covered in spec; not a code task. ✓

**Placeholder scan:** none — every step has full code/commands.

**Type/identifier consistency:** `stam_export_augmented_to_tmp($ged,$augment,$tool,$python=null)` returns `[bool, string]` in Task 1 and is called exactly that way in the endpoint (Task 2) and test (Task 1). `stam_python_bin()` defined and used in Task 1. Endpoint requires `auth.php`/`lib/users.php` (for `requireAdmin`)/`lib/tree.php` (for `stam_meta`)/`lib/gedcom_export.php` — all consistent with existing admin endpoints. Smoke variables (`$COOKIES_ADMIN`, `$pass`, `$fail`, `$BASE`) match `smoke_v2.sh`'s existing names. Consistent.

**Note on the missing-`augment.json` case:** in production `augment.json` always exists (created on first web edit) and the smoke creates it, so the happy path is covered. If it were ever absent, the lib returns a graceful Dutch error → `export_error` toast (no crash). Acceptable per spec.
