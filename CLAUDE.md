# /stamboom/ — stamboom.ottenbourg.com

Private, password-gated, Dutch read-only viewer of the Ottenbourg family
GEDCOM. **Live since 2026-05-28** at <https://stamboom.ottenbourg.com/>.
Public code repo: `janottenbourg/genealogy-toolkit`.

- Live: <https://stamboom.ottenbourg.com/>
- Local: `C:\inetpub\wwwroot\ottenbourg.com\stamboom\`
- GitHub: `janottenbourg/genealogy-toolkit` (branch `main`)
- Webroot on box: `/var/www/stamboom.ottenbourg.com/` (owner `www-data:www-data`)

## Architecture
- PHP 8 + Python 3.12. No framework, no DB.
- `build.py` parses `jottenbourg.ged` → `tree.json`. Run on demand
  after each Geneanet export.
- PHP pages read `tree.json` into memory per request. ~500 individuals,
  ~5–15 ms.
- Auth: copy of `/fin/` pattern. Bcrypt hash in gitignored `.password`.

## Privacy
- Real `jottenbourg.ged` is never committed. Lives only on workstation
  + Lightsail webroot.
- Public repo ships `sample.ged` (anonymized 15-person fixture).
- Inside the site, full unredacted data is visible — the password gate
  IS the privacy boundary.

## Routes (Dutch)
- `/` → login form
- `home.php` → landing after login
- `persoon.php?id=I123`
- `boom.php?id=I123` (default `?id=meta.root_id`)
- `lijst.php`
- `zoek.php?q=...`

## Deploy (single file, `/jan/` style)

```bash
scp <file> ubuntu@tienen.rip:/tmp/stamboom-<file>
ssh ubuntu@tienen.rip "sudo install -o www-data -g www-data -m 644 \
    /tmp/stamboom-<file> /var/www/stamboom.ottenbourg.com/<file> \
    && rm /tmp/stamboom-<file>"
```

## GEDCOM update (≈twice a year)
1. Export from Geneanet
2. scp jottenbourg_*.ged → box (mode 640, owner www-data)
3. ssh "cd /var/www/stamboom.ottenbourg.com && sudo -u www-data python3 build.py jottenbourg.ged" on the box
4. Verify footer date updated on home page

## Pre-deploy checklist
- [ ] Login with wrong then right password
- [ ] Click 3 generations up and 3 down from yourself
- [ ] Search "Ottenbourg" → results render
- [ ] `lijst.php` on phone, JS disabled
- [ ] `?id=I999` → Dutch 404
- [ ] Footer shows correct .ged filename + build date

## Open follow-ups (v2 sub-projects)

- **`stamboom-auth` (per-user accounts)** — replace the single shared password
  with per-user accounts. Admin role = Jan (full access). User role = family
  members with limited access (definition of "limited" TBD: photo-only? hide
  birthdates of living relatives? read-only-no-search?). Needs its own
  brainstorm/spec session — see `docs/superpowers/specs/2026-05-28-stamboom-design.md`
  §10 for the deferred sub-project list.
- **`stamboom-augment`** — `_LINKEDIN`/`_FACEBOOK`/`_EMAIL` annotations on
  individuals, with .ged roundtrip.
- **`stamboom-contributions`** — photo and PDF uploads.
- **`stamboom-analytics`** — KPI dashboards à la `benardt/genealogyKPI`.
- **`stamboom-ai-checks`** — AI-assisted issue detection.

Also: also-deprecated note that the GEDCOM-refresh recipe in this file's
"GEDCOM update" section above uses `python3 build.py jottenbourg.ged` (with
the filename arg). An earlier draft of the recipe omitted the filename —
do not copy/paste that version.
