#!/bin/bash
#
# setup_release_staging.sh - make this site's release staging site.
#
# A publish builds its release from the commit, not the working tree (spec
# parallel_sessions_release_flow, I4). Before it writes anything, it puts
# that commit into the staging site and runs the deploy test tier THERE: the
# code that ships compiles, starts, and serves a page through Apache, whatever
# another session has left half-edited in the working tree.
#
# The staging site sits beside this one, at <site root>_test, which is the
# directory the rendered vhost already serves as test.<domain>
# (default_virtualhost.conf's test-site block). It uses THIS site's database:
# its config requires this site's config and overrides only the paths, the
# address and the protocol. It answers this machine only (127.0.0.1 and ::1):
# the vhost is plain HTTP on every address, and the database is real.
#
# Publish fills public_html/ and maintenance_scripts/ on every run; this script
# makes the rest once. Run as root:
#
#   sudo bash /var/www/html/joinerytest/maintenance_scripts/dev_tools/setup_release_staging.sh
#
# Safe to run again. Refuses a directory that holds a site of its own.
#
# @version 1.0

set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
    echo "Run as root: sudo bash $0" >&2
    exit 1
fi

SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE_NAME="$(basename "$SITE_ROOT")"
STAGING="${SITE_ROOT}_test"
LIVE_CONFIG="${SITE_ROOT}/config/Globalvars_site.php"
MARKER="${STAGING}/config/release_staging"
OWNER="$(stat -c '%U' "${SITE_ROOT}/public_html")"
WEB_GROUP="www-data"

[[ -f "$LIVE_CONFIG" ]] || { echo "No site config at $LIVE_CONFIG" >&2; exit 1; }
if [[ -f "${STAGING}/config/Globalvars_site.php" && ! -f "$MARKER" ]]; then
    echo "$STAGING holds a site of its own (a config with no release_staging marker). Not touching it." >&2
    exit 1
fi

DOMAIN="$(sed -n "s/^[[:space:]]*\$this->settings\['webDir'\][[:space:]]*=[[:space:]]*'\([^']*\)'.*/\1/p" "$LIVE_CONFIG" | head -1)"
DOMAIN="${DOMAIN#*://}"; DOMAIN="${DOMAIN%%/*}"
[[ -n "$DOMAIN" ]] || { echo "Could not read webDir from $LIVE_CONFIG" >&2; exit 1; }
STAGING_HOST="test.${DOMAIN}"

install -d -o "$OWNER" -g "$OWNER" -m 755 "$STAGING" "${STAGING}/public_html" "${STAGING}/maintenance_scripts"
install -d -o "$OWNER" -g "$WEB_GROUP" -m 750 "${STAGING}/config"
install -d -o "$WEB_GROUP" -g "$WEB_GROUP" -m 770 "${STAGING}/cache" "${STAGING}/logs" "${STAGING}/uploads" "${STAGING}/static_files"
ln -sfn "${SITE_ROOT}/vendor" "${STAGING}/vendor"

CONFIG_TMP="$(mktemp "${STAGING}/config/.Globalvars_site.php.XXXXXX")"
cat > "$CONFIG_TMP" <<PHP
<?php
// Release staging for ${SITE_NAME} (maintenance_scripts/dev_tools/setup_release_staging.sh).
// Publish puts each release's commit here and runs the deploy test tier against
// it. Written by that script; changes here are overwritten when it runs again.

// This machine only: the vhost is plain HTTP on every address, and the
// database below is ${SITE_NAME}'s own. Every request reaches PHP through
// serve.php, which loads this file first.
if (PHP_SAPI !== 'cli' && !in_array(\$_SERVER['REMOTE_ADDR'] ?? '', array('127.0.0.1', '::1'), true)) {
	http_response_code(403);
	header('Content-Type: text/plain');
	exit("Release staging answers this machine only.\n");
}

// ${SITE_NAME}'s settings - its database among them - then this site's own paths.
require '${LIVE_CONFIG}';
\$this->settings['site_template']    = '$(basename "$STAGING")';
\$this->settings['siteDir']          = '${STAGING}/public_html';
\$this->settings['upload_dir']       = '${STAGING}/uploads';
\$this->settings['static_files_dir'] = '${STAGING}/static_files';
\$this->settings['webDir']           = 'http://${STAGING_HOST}';
\$this->settings['protocol_mode']    = 'http';
PHP
chown "$OWNER:$WEB_GROUP" "$CONFIG_TMP"
chmod 640 "$CONFIG_TMP"
mv -f "$CONFIG_TMP" "${STAGING}/config/Globalvars_site.php"

cat > "$MARKER" <<TXT
This directory is ${SITE_NAME}'s release staging site, not a site of its own.
Publish fills public_html/ and maintenance_scripts/ with each release's commit
and runs the deploy test tier here. Made by
maintenance_scripts/dev_tools/setup_release_staging.sh.
TXT
chown "$OWNER:$WEB_GROUP" "$MARKER"
chmod 640 "$MARKER"

echo "Release staging ready at ${STAGING}, served as http://${STAGING_HOST} to this machine only."
echo "It uses ${SITE_NAME}'s database. The next publish fills it and runs the deploy tier there."
