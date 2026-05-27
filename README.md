# genealogy-toolkit

A small PHP + Python toolkit for browsing a GEDCOM family tree on the web.
Designed to be cloned, pointed at your own `.ged`, and served behind a
single password.

The first (and so far only) tool in the kit is a **read-only viewer**:

- Person pages with dates, places, parents, partners, children, siblings
- Hourglass tree view centered on any person (CSS Grid, no JS lib)
- Collapsible-list fallback view for mobile / screen readers
- Substring name search
- Alphabetical index

Future tools (separate sub-projects, not yet built): augmenter, family-member
contributions, KPI analytics, AI quality checks.

## Quick start

```
git clone https://github.com/janottenbourg/genealogy-toolkit.git stamboom
cd stamboom
cp sample.ged yourtree.ged          # or drop in your real .ged
python build.py yourtree.ged        # produces tree.json
php -r 'echo password_hash("changeme", PASSWORD_BCRYPT);' > .password
php -S 127.0.0.1:8000               # open http://127.0.0.1:8000/
```

Default password is `changeme` — replace with your own before deploying.

## Layout

```
stamboom/
├── build.py                 # GEDCOM → tree.json (Python 3.12)
├── sample.ged               # anonymized 15-person fixture
├── jottenbourg.ged          # (gitignored) your real GEDCOM
├── tree.json                # (gitignored) build artifact
├── .password                # (gitignored) bcrypt hash
├── index.php                # login form
├── login.php · logout.php · auth.php
├── menu.php · style.css
├── home.php · persoon.php · boom.php · lijst.php · zoek.php · 404.php
├── lib/{tree.php, render_hourglass.php, render_list.php}
└── tests/{test_build.py, smoke.sh}
```

## Contributing

PRs welcome. Run `pytest tests/` and `bash tests/smoke.sh` before pushing.

---

## Nederlands

`genealogy-toolkit` is een kleine PHP + Python-toolkit om een GEDCOM-stamboom
op het web te bekijken. Kloon het, wijs het naar je eigen `.ged`-bestand en
zet er één gedeeld wachtwoord op. Werkt prima op een goedkope VPS.

De live versie voor de familie Ottenbourg draait op
<https://stamboom.ottenbourg.com/> (privé, wachtwoord vereist).
