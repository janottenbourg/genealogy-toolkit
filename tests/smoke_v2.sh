#!/usr/bin/env bash
# v2 end-to-end smoke. Boots php -S, exercises auth + admin + edit flows.

set -u
cd "$(dirname "$0")/.."

if command -v php > /dev/null 2>&1; then PHP=php
elif [ -x /c/php/php.exe ]; then PHP=/c/php/php.exe
else echo "no PHP"; exit 1; fi

pick_python() {
    local c="$1"; command -v "$c" > /dev/null 2>&1 || return 1
    local v; v=$("$c" --version 2>&1 || true)
    case "$v" in Python\ 3.*) return 0;; *) return 1;; esac
}
if pick_python python3; then PY=python3
elif pick_python python; then PY=python
else echo "no Python"; exit 1; fi

PORT=${PORT:-58902}
BASE="http://127.0.0.1:$PORT"
COOKIES=$(mktemp); COOKIES_PIETER=$(mktemp); COOKIES_ADMIN=$(mktemp)
PHP_LOG=$(mktemp)

cleanup() {
    [[ -n "${SERVER_PID:-}" ]] && kill "$SERVER_PID" 2>/dev/null || true
    rm -f "$COOKIES" "$COOKIES_PIETER" "$COOKIES_ADMIN" "$PHP_LOG"
}
trap cleanup EXIT

echo "==> Reset state and rebuild tree.json"
rm -f users.json augment.json invites.json
"$PY" build.py sample.ged > /dev/null

echo "==> Seed admin"
"$PHP" tools/seed_admin.php --email=admin@test.local --password=adminpw1 --indi=I1
grep -q "admin@test.local" users.json && echo "  PASS  admin@test.local in users.json" || { echo "  FAIL"; exit 1; }

echo "==> canEdit unit test"
"$PHP" tests/test_can_edit.php > /dev/null 2>&1 && echo "  PASS  canEdit tests" || { echo "  FAIL"; exit 1; }

echo "==> Start server"
"$PHP" -S 127.0.0.1:$PORT 2>"$PHP_LOG" &
SERVER_PID=$!
sleep 1

pass=0; fail=0
check() { if eval "$1" > /dev/null; then echo "  PASS  $2"; ((pass++)); else echo "  FAIL  $2"; ((fail++)); fi; }
expect_status() {
    local url="$1" exp="$2" label="$3" cookies="${4:-$COOKIES}"
    local code; code=$(curl -s -o /dev/null -w "%{http_code}" -b "$cookies" "$BASE$url")
    if [[ "$code" == "$exp" ]]; then echo "  PASS  $label → $code"; ((pass++)); else echo "  FAIL  $label → $code (want $exp)"; ((fail++)); fi
}

# Helper: log in user via CSRF + email + password, save cookies to $1
login_as() {
    local cookies="$1" email="$2" pw="$3"
    local csrf; csrf=$(curl -s -c "$cookies" "$BASE/" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
    curl -s -o /dev/null -b "$cookies" -c "$cookies" \
        -d "email=$email&password=$pw&_csrf=$csrf" "$BASE/login.php"
}

echo "==> Wrong password rejected"
csrf=$(curl -s -c "$COOKIES_ADMIN" "$BASE/" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIES_ADMIN" -c "$COOKIES_ADMIN" \
    -d "email=admin@test.local&password=wrong&_csrf=$csrf" "$BASE/login.php")
if [[ "$code" == "302" ]]; then echo "  PASS  wrong pwd → 302 to login"; pass=$((pass+1)); else echo "  FAIL  got $code"; fail=$((fail+1)); fi

echo "==> Login admin"
# Fresh cookies for the real login
rm -f "$COOKIES_ADMIN"; COOKIES_ADMIN=$(mktemp)
login_as "$COOKIES_ADMIN" admin@test.local adminpw1
expect_status "/home.php" 200 "Admin home" "$COOKIES_ADMIN"

echo "==> Admin invites I3"
csrf=$(curl -s -b "$COOKIES_ADMIN" "$BASE/admin.php" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /tmp/admin_invite_resp -b "$COOKIES_ADMIN" -c "$COOKIES_ADMIN" -L \
    -d "_csrf=$csrf&indi_id=I3" "$BASE/admin/invite.php"
grep -q "Stuur deze link" /tmp/admin_invite_resp && echo "  PASS  invite link surfaced" && ((pass++)) || { echo "  FAIL"; ((fail++)); }
TOKEN=$(grep -oP 'signup.php\?token=\K[^<&"]+' /tmp/admin_invite_resp | head -1)
[ -n "$TOKEN" ] && echo "  PASS  token extracted: ${TOKEN:0:12}…" && ((pass++)) || { echo "  FAIL  no token"; ((fail++)); }

echo "==> Pieter redeems invite"
csrf=$(curl -s -c "$COOKIES_PIETER" "$BASE/signup.php?token=$TOKEN" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b "$COOKIES_PIETER" -c "$COOKIES_PIETER" \
    -d "token=$TOKEN&_csrf=$csrf&email=pieter@test.local&password=pieterpw&password2=pieterpw" "$BASE/signup.php"
grep -q "pieter@test.local" users.json && echo "  PASS  pieter in users.json" && ((pass++)) || { echo "  FAIL"; ((fail++)); }
! grep -q "$TOKEN" invites.json && echo "  PASS  token consumed" && ((pass++)) || { echo "  FAIL"; ((fail++)); }

echo "==> Re-redeem same token rejected"
csrf=$(curl -s -c /tmp/c2 "$BASE/signup.php?token=$TOKEN" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
out=$(curl -s -o /tmp/sig2 -w "%{http_code}" -c /tmp/c2 -b /tmp/c2 \
    -d "token=$TOKEN&_csrf=$csrf&email=hacker@test.local&password=hackerpw&password2=hackerpw" "$BASE/signup.php")
grep -q "ongeldig\|verlopen" /tmp/sig2 && echo "  PASS  reuse rejected" && ((pass++)) || { echo "  FAIL  $out"; ((fail++)); }

echo "==> Pieter self-edits"
csrf=$(curl -s -b "$COOKIES_PIETER" "$BASE/settings.php" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b "$COOKIES_PIETER" -c "$COOKIES_PIETER" \
    -d "_csrf=$csrf&email=p@new.test&facebook=https://facebook.com/p&linkedin=&instagram=&bio=hallo" \
    "$BASE/settings.php"
grep -q "p@new.test" augment.json && echo "  PASS  Pieter saved" && ((pass++)) || { echo "  FAIL"; ((fail++)); }
grep -q "pieter@test.local" augment.json && echo "  PASS  updated_by recorded" && ((pass++)) || { echo "  FAIL"; ((fail++)); }

echo "==> Pieter edits parent (Marcel I3)"
csrf=$(curl -s -b "$COOKIES_PIETER" "$BASE/persoon.php?id=I3&edit=1" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b "$COOKIES_PIETER" -c "$COOKIES_PIETER" \
    -d "_csrf=$csrf&email=marcel@new.test&facebook=&linkedin=&instagram=&bio=" \
    "$BASE/persoon.php?id=I3&edit=1"
grep -q "marcel@new.test" augment.json && echo "  PASS  Pieter wrote parent" && ((pass++)) || { echo "  FAIL"; ((fail++)); }

echo "==> Pieter cannot edit unrelated (I11)"
csrf=$(curl -s -b "$COOKIES_PIETER" "$BASE/persoon.php?id=I11" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIES_PIETER" -c "$COOKIES_PIETER" \
    -d "_csrf=$csrf&email=evil@x.test" "$BASE/persoon.php?id=I11&edit=1")
[[ "$code" == "403" ]] && echo "  PASS  POST I11 → 403" && ((pass++)) || { echo "  FAIL  got $code"; ((fail++)); }
! grep -q "evil@x.test" augment.json && echo "  PASS  I11 unchanged" && ((pass++)) || { echo "  FAIL"; ((fail++)); }

echo "==> Admin can edit anyone (I11)"
csrf=$(curl -s -b "$COOKIES_ADMIN" "$BASE/persoon.php?id=I11&edit=1" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b "$COOKIES_ADMIN" -c "$COOKIES_ADMIN" \
    -d "_csrf=$csrf&email=admin-edited@x.test&facebook=&linkedin=&instagram=&bio=" \
    "$BASE/persoon.php?id=I11&edit=1"
grep -q "admin-edited@x.test" augment.json && echo "  PASS  admin wrote I11" && ((pass++)) || { echo "  FAIL"; ((fail++)); }

echo "==> CSRF rejection on invite without token"
code=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIES_ADMIN" -d "indi_id=I4" "$BASE/admin/invite.php")
[[ "$code" == "403" ]] && echo "  PASS  CSRF blocked → 403" && ((pass++)) || { echo "  FAIL  got $code"; ((fail++)); }

echo "==> Validation: invalid facebook URL rejected"
csrf=$(curl -s -b "$COOKIES_PIETER" "$BASE/settings.php" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /tmp/setresp -b "$COOKIES_PIETER" -c "$COOKIES_PIETER" \
    -d "_csrf=$csrf&facebook=http://evil.com/x&email=&linkedin=&instagram=&bio=" "$BASE/settings.php"
grep -q "evil.com" augment.json && { echo "  FAIL  evil URL accepted"; ((fail++)); } || { echo "  PASS  evil URL rejected"; ((pass++)); }

echo "==> Admin-triggered password reset"
csrf=$(curl -s -b "$COOKIES_ADMIN" "$BASE/admin.php" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /tmp/reset_resp -L -b "$COOKIES_ADMIN" -c "$COOKIES_ADMIN" \
    -d "_csrf=$csrf&email=pieter@test.local" "$BASE/admin/reset.php"
RTOKEN=$(grep -oP 'reset.php\?token=\K[^<&"]+' /tmp/reset_resp | head -1)
[ -n "$RTOKEN" ] && echo "  PASS  reset token issued" && ((pass++)) || { echo "  FAIL"; ((fail++)); }
csrf=$(curl -s -c /tmp/c3 "$BASE/reset.php?token=$RTOKEN" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b /tmp/c3 -c /tmp/c3 \
    -d "token=$RTOKEN&_csrf=$csrf&password=newpieterpw&password2=newpieterpw" "$BASE/reset.php"
# Verify new password works
csrf=$(curl -s -c /tmp/c4 "$BASE/" | grep -oP 'name="_csrf" value="\K[^"]+' | head -1)
curl -s -o /dev/null -b /tmp/c4 -c /tmp/c4 \
    -d "email=pieter@test.local&password=newpieterpw&_csrf=$csrf" "$BASE/login.php"
code=$(curl -s -o /dev/null -w "%{http_code}" -b /tmp/c4 "$BASE/home.php")
[[ "$code" == "200" ]] && echo "  PASS  login with new password" && ((pass++)) || { echo "  FAIL"; ((fail++)); }

echo "==> boom.php chart shell + boom_data.php JSON + voorouders pedigree"
B=$(curl -s -b "$COOKIES_ADMIN" "$BASE/boom.php?id=I3")
echo "$B" | grep -q 'id="famtree"'      && { echo "  PASS  boom.php has chart container"; ((pass++)); } || { echo "  FAIL  no #famtree"; ((fail++)); }
echo "$B" | grep -q 'js/familytree.js'   && { echo "  PASS  boom.php loads familytree.js"; ((pass++)); } || { echo "  FAIL"; ((fail++)); }
echo "$B" | grep -q 'family-chart@0.9.0' && { echo "  PASS  boom.php loads family-chart CDN"; ((pass++)); } || { echo "  FAIL"; ((fail++)); }

BD=$(curl -s -b "$COOKIES_ADMIN" "$BASE/boom_data.php")
echo "$BD" | grep -q '"I3"' && echo "$BD" | grep -q 'Marcel' && { echo "  PASS  boom_data.php has I3/Marcel"; ((pass++)); } || { echo "  FAIL  boom_data missing I3/Marcel"; ((fail++)); }
printf '%s' "$BD" | "$PHP" -r '$d=json_decode(stream_get_contents(STDIN),true); exit(is_array($d)&&count($d)===15?0:1);' && { echo "  PASS  boom_data valid JSON (15)"; ((pass++)); } || { echo "  FAIL  boom_data bad JSON"; ((fail++)); }

curl -s -b "$COOKIES_ADMIN" "$BASE/voorouders.php?id=I3" | grep -q 'Désiré' && { echo "  PASS  voorouders shows ancestor"; ((pass++)); } || { echo "  FAIL  voorouders no ancestor"; ((fail++)); }
vcode=$(curl -s -o /dev/null -w "%{http_code}" -b "$COOKIES_ADMIN" "$BASE/voorouders.php?id=I999")
[ "$vcode" = "404" ] && { echo "  PASS  voorouders unknown → 404"; ((pass++)); } || { echo "  FAIL  voorouders unknown → $vcode"; ((fail++)); }

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

echo "==> PHP error log scan"
if grep -E "PHP (Fatal|Warning|Notice|Parse)" "$PHP_LOG"; then
    echo "  FAIL  PHP errors:"
    grep -E "PHP (Fatal|Warning|Notice|Parse)" "$PHP_LOG"
    ((fail++))
else
    echo "  PASS  no PHP errors"
    ((pass++))
fi

rm -f /tmp/admin_invite_resp /tmp/sig2 /tmp/c2 /tmp/c3 /tmp/c4 /tmp/setresp /tmp/reset_resp

echo
echo "Summary: $pass passed, $fail failed"
exit $((fail > 0 ? 1 : 0))
