#!/usr/bin/env bash
# stamboom deploy script. Pulls code from main on the box,
# rsyncs into the webroot excluding state JSON, then pulls
# the live JSON files down to the workstation.

set -euo pipefail

PEM=~/.ssh/lightsail.pem
HOST=ubuntu@tienen.rip
WEBROOT=/var/www/stamboom.ottenbourg.com
STAGE=/home/ubuntu/genealogy-toolkit
LOCAL=$(cd "$(dirname "$0")" && pwd)

echo "==> git pull on box"
ssh -i "$PEM" "$HOST" "cd $STAGE && git pull"

echo "==> rsync to webroot (excluding state JSON)"
ssh -i "$PEM" "$HOST" "sudo rsync -av --delete \
    --exclude='.git/' --exclude='.github/' --exclude='.superpowers/' \
    --exclude='tests/' --exclude='requirements-dev.txt' \
    --exclude='users.json' --exclude='augment.json' --exclude='invites.json' \
    --exclude='tree.json' --exclude='tree.json.new' \
    --exclude='jottenbourg.ged' \
    --chown=www-data:www-data --chmod=F644,D755 \
    $STAGE/ $WEBROOT/"

echo "==> Pull JSON state to local"
# Files are mode 640 owned by www-data — stage to /tmp via sudo before scp
ssh -i "$PEM" "$HOST" "sudo cp -p $WEBROOT/users.json $WEBROOT/augment.json $WEBROOT/invites.json /tmp/ 2>/dev/null && sudo chown ubuntu:ubuntu /tmp/users.json /tmp/augment.json /tmp/invites.json 2>/dev/null"
scp -i "$PEM" \
    "$HOST:/tmp/users.json" \
    "$HOST:/tmp/augment.json" \
    "$HOST:/tmp/invites.json" \
    "$LOCAL/" 2>/dev/null || echo "  (one or more JSON files not yet on the box — fine on first deploy)"
ssh -i "$PEM" "$HOST" "rm -f /tmp/users.json /tmp/augment.json /tmp/invites.json"

echo "==> Done"
