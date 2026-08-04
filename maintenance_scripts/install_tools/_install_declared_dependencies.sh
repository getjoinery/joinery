#!/usr/bin/env bash
# _install_declared_dependencies.sh - install every PHP extension the deployed
# source declares (root composer.json ext-* plus plugin requires.extensions),
# resolved by utils/list_dependencies.php.
#
# VERSION: 1.0
#
# Called by install.sh (bare metal, at install time) and by the Dockerfile CMD
# (every container start).
#
# Why every container start: in Docker these extensions are apt packages living
# in the container's writable layer, because that is where utils/upgrade.php
# installs them when a release declares a new one. `docker rm` destroys that
# layer, so a rebuilt container comes back without the extensions its own code
# declares. Composer validation then fails, update_database is skipped, and the
# only symptom is a site quietly running an unmigrated schema. Re-asserting the
# declared set at start puts them back before anything depends on them.
#
# Cost when nothing is missing: one php invocation and one dpkg query per
# package. apt is only touched when something actually has to be installed.
#
# Never fatal — a missing extension is reported and left. The plugin activation
# gate is the runtime backstop, and a site that cannot reach apt must still
# start.
#
# Usage: _install_declared_dependencies.sh /var/www/html/SITENAME/public_html

set -u

PUBLIC_HTML="${1:-}"
RESOLVER="${PUBLIC_HTML}/utils/list_dependencies.php"

say() { echo "declared-deps: $*"; }

if [ -z "$PUBLIC_HTML" ] || [ ! -d "$PUBLIC_HTML" ]; then
    say "no public_html given - skipping"
    exit 0
fi

if [ "$(id -u)" != "0" ]; then
    say "not running as root - skipping"
    exit 0
fi

if [ ! -f "$RESOLVER" ]; then
    say "resolver not found at ${RESOLVER} - skipping"
    exit 0
fi

command -v php >/dev/null 2>&1 || { say "php-cli not available - skipping"; exit 0; }
command -v apt-get >/dev/null 2>&1 || { say "apt-get not available - skipping"; exit 0; }

# The resolver emits one "primary|fallback" apt package pair per line.
SPECS="$(php "$RESOLVER" --apt 2>/dev/null || true)"
if [ -z "$SPECS" ]; then
    say "nothing declared"
    exit 0
fi

# Work out what is missing before touching apt, so the common case (everything
# already present) costs no network and no apt-get update.
MISSING=""
for spec in $SPECS; do
    primary="${spec%%|*}"
    fallback="${spec##*|}"
    if dpkg -s "$primary" > /dev/null 2>&1 || dpkg -s "$fallback" > /dev/null 2>&1; then
        continue
    fi
    MISSING="${MISSING} ${spec}"
done

if [ -z "$MISSING" ]; then
    say "all declared extensions present"
    exit 0
fi

say "missing:${MISSING}"
apt-get update -qq 2>/dev/null || say "WARNING - apt-get update failed; trying the installs anyway"

for spec in $MISSING; do
    primary="${spec%%|*}"
    fallback="${spec##*|}"
    if apt-get install -y "$primary" > /dev/null 2>&1; then
        say "installed ${primary}"
    elif apt-get install -y "$fallback" > /dev/null 2>&1; then
        say "installed ${fallback}"
    else
        say "WARNING - could not install ${primary} (or ${fallback}); a plugin requiring it will refuse activation"
    fi
done

exit 0
