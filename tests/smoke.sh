#!/usr/bin/env bash
# End-to-end smoke test. Boots `php -S` against the project root with sample.ged,
# curls every public route, checks status codes + key strings, asserts no PHP errors.

set -u
cd "$(dirname "$0")/.."

# Resolve PHP — prefer system PHP (CI/Linux), fall back to Windows default
if command -v php > /dev/null 2>&1; then
    PHP=php
elif [ -x /c/php/php.exe ]; then
    PHP=/c/php/php.exe
else
    echo "ERROR: no PHP found (tried 'php' on PATH and /c/php/php.exe)"
    exit 1
fi

# Resolve Python — must respond to --version on stdout (catches Windows MS Store stub).
pick_python() {
    local candidate="$1"
    command -v "$candidate" > /dev/null 2>&1 || return 1
    local ver
    ver=$("$candidate" --version 2>&1 || true)
    case "$ver" in
        Python\ 3.*) return 0 ;;
        *) return 1 ;;
    esac
}

if pick_python python3; then
    PY=python3
elif pick_python python; then
    PY=python
else
    echo "ERROR: no working Python 3 found (tried 'python3' and 'python')"
    exit 1
fi

PORT=${PORT:-58901}
BASE="http://127.0.0.1:$PORT"
COOKIES=$(mktemp)
PHP_LOG=$(mktemp)

cleanup() {
    [[ -n "${SERVER_PID:-}" ]] && kill "$SERVER_PID" 2>/dev/null || true
    rm -f "$COOKIES" "$PHP_LOG"
}
trap cleanup EXIT

# --- 1. Build tree.json from sample.ged ---
echo "==> $PY build.py sample.ged"
"$PY" build.py sample.ged

# --- 2. Ensure admin test account (admin@test.local / changeme — for tests only) ---
if [[ ! -s users.json ]] || ! grep -q "admin@test.local" users.json; then
    rm -f users.json   # so seed_admin doesn't reject a stale account
    "$PHP" tools/seed_admin.php --email=admin@test.local --password=changeme --indi=I1 || true
fi

# --- 3. Boot server ---
"$PHP" -S 127.0.0.1:$PORT 2>"$PHP_LOG" &
SERVER_PID=$!
sleep 1

pass=0
fail=0

assert_contains() {
    local url="$1" needle="$2" label="$3"
    local body
    body=$(curl -s -b "$COOKIES" "$BASE$url")
    if echo "$body" | grep -q -- "$needle"; then
        echo "  PASS  $label  ($url)"
        ((pass++))
    else
        echo "  FAIL  $label  ($url) — did not find: $needle"
        ((fail++))
    fi
}

assert_status() {
    local url="$1" expected="$2" label="$3"
    local code
    code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIES" "$BASE$url")
    if [[ "$code" == "$expected" ]]; then
        echo "  PASS  $label  ($url) → $code"
        ((pass++))
    else
        echo "  FAIL  $label  ($url) → $code (expected $expected)"
        ((fail++))
    fi
}

# --- 4. Login flow ---
echo "==> Login flow"
assert_status "/" 200 "Login page reachable"
# Pull CSRF token from index.php first
CSRF=$(curl -s -c "$COOKIES" "$BASE/" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b "$COOKIES" -c "$COOKIES" \
    -d "email=admin@test.local&password=wrong&_csrf=$CSRF" "$BASE/login.php"
assert_status "/home.php" 302 "Unauthenticated home redirects"
curl -s -o /dev/null -b "$COOKIES" -c "$COOKIES" \
    -d "email=admin@test.local&password=changeme&_csrf=$CSRF" "$BASE/login.php"

# --- 5. Authenticated routes ---
echo "==> Authenticated routes"
assert_contains "/home.php" "Désiré" "Home shows root individual"
assert_contains "/persoon.php?id=I3" "Marcel" "Person page renders"
assert_contains "/boom.php?id=I3" "Marcel" "Tree view renders"
assert_contains "/boom.php?id=I3" "Onbekend" "Tree view shows unknown slots"
assert_contains "/lijst.php" "JANSSENS" "List view shows surnames"
assert_contains "/zoek.php?q=Janssens" "Marcel" "Search finds by surname"
assert_contains "/zoek.php?q=desire" "Désiré" "Search ignores accents"
assert_status   "/persoon.php?id=I999" 404 "Unknown ID returns 404"

# --- 6. PHP errors check ---
echo "==> PHP error log scan"
if grep -E "PHP (Fatal|Warning|Notice|Parse)" "$PHP_LOG"; then
    echo "  FAIL  PHP errors detected"
    cat "$PHP_LOG"
    ((fail++))
else
    echo "  PASS  No PHP errors"
    ((pass++))
fi

echo
echo "Summary: $pass passed, $fail failed"
exit $((fail > 0 ? 1 : 0))
