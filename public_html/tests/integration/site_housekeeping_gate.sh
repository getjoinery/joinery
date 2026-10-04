#!/bin/bash
# @joinery-test
# name: site_housekeeping
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/site_housekeeping.sh]
#
# site_housekeeping.sh writes the site's logrotate file and, on bare metal, its
# cron entry - each only when ABSENT, so an owner's edit survives every
# converge and moving a file aside is the reset. The one exception is the
# platform's own earlier logrotate rendering, which ran as group user1 (a group
# no new server has): it is rewritten from the template
# (specs/agent_recipes_and_vocabulary.md, Host files). In a container it keeps
# the release manifest at the site root the one the code matches, with a copy on
# the config volume (B21). Driven unprivileged against a fixture /etc through
# JOINERY_SITE_HOUSEKEEPING_ROOT.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/install_tools/site_housekeeping.sh"
RUNNER="$ROOT/maintenance_scripts/install_tools/_plugin_installers_start.sh"
INIT="$ROOT/maintenance_scripts/install_tools/_site_init.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; exit 0; fi

R="$T/fs"; mkdir -p "$R/etc/logrotate.d" "$R/etc/cron.d"
SITE="$T/html/mysite"; mkdir -p "$SITE"

echo "=== Absent: both written ==="
out="$(JOINERY_SITE_HOUSEKEEPING_ROOT="$R" bash "$SCRIPT" mysite "$SITE" 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "logrotate rendered for this site's root" "$(grep -c "^${SITE}/logs/error.log {" "$R/etc/logrotate.d/joinery-mysite")" "1"
chk "no placeholder left" "$(grep -c '{{SITE_ROOT}}' "$R/etc/logrotate.d/joinery-mysite")" "0"
chk "the cron entry runs the task runner every minute as www-data" "$(grep -c "^\* \* \* \* \* www-data php ${SITE}/public_html/utils/process_scheduled_tasks.php" "$R/etc/cron.d/joinery-mysite")" "1"

echo "=== Present: never touched ==="
echo "# mine" > "$R/etc/logrotate.d/joinery-mysite"
echo "# mine too" > "$R/etc/cron.d/joinery-mysite"
JOINERY_SITE_HOUSEKEEPING_ROOT="$R" bash "$SCRIPT" mysite "$SITE" >/dev/null 2>&1
chk "an owner's logrotate file survives" "$(cat "$R/etc/logrotate.d/joinery-mysite")" "# mine"
chk "an owner's cron file survives" "$(cat "$R/etc/cron.d/joinery-mysite")" "# mine too"

echo "=== The earlier rendering (group user1) is rewritten; nothing else is ==="
# The file every server installed from 09-23 has, byte for byte.
old_rendering() {
    cat <<OLD
# Joinery log rotation configuration
# Installed by site_housekeeping.sh — rotates Apache error logs weekly
# The $1 placeholder is replaced during installation

$1/logs/error.log {
    su www-data user1
    weekly
    rotate 2
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    size 20M
}

$1/logs/cron_scheduled_tasks.log {
    su www-data user1
    weekly
    rotate 4
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    size 10M
}

$1/logs/host_converger.log {
    weekly
    rotate 4
    compress
    delaycompress
    missingok
    notifempty
    copytruncate
    size 5M
}
OLD
}
R7="$T/fs7"; mkdir -p "$R7/etc/logrotate.d" "$R7/etc/cron.d"
old_rendering "$SITE" > "$R7/etc/logrotate.d/joinery-mysite"
out="$(JOINERY_SITE_HOUSEKEEPING_ROOT="$R7" bash "$SCRIPT" mysite "$SITE" 2>&1)"; rc=$?
chk "it is rewritten from the template, saying so" "$rc:$(printf '%s' "$out" | grep -c 'earlier rendering')" "0:1"
chk "and runs as www-data's own group" "$(grep -c 'su www-data www-data' "$R7/etc/logrotate.d/joinery-mysite")/$(grep -c 'user1' "$R7/etc/logrotate.d/joinery-mysite")" "2/0"
out="$(JOINERY_SITE_HOUSEKEEPING_ROOT="$R7" bash "$SCRIPT" mysite "$SITE" 2>&1)"
chk "the next converge leaves it alone" "$(printf '%s' "$out" | grep -c 'rewrote')" "0"
old_rendering "$SITE" | sed 's/rotate 2$/rotate 9/' > "$R7/etc/logrotate.d/joinery-mysite"
JOINERY_SITE_HOUSEKEEPING_ROOT="$R7" bash "$SCRIPT" mysite "$SITE" >/dev/null 2>&1
chk "one directive changed by an owner: the file is theirs and survives" "$(grep -c 'rotate 9' "$R7/etc/logrotate.d/joinery-mysite")/$(grep -c 'user1' "$R7/etc/logrotate.d/joinery-mysite")" "1/2"
old_rendering "$T/html/othersite" > "$R7/etc/logrotate.d/joinery-mysite"
JOINERY_SITE_HOUSEKEEPING_ROOT="$R7" bash "$SCRIPT" mysite "$SITE" >/dev/null 2>&1
chk "the earlier rendering for another site's root is not this site's, and survives" "$(grep -c 'othersite' "$R7/etc/logrotate.d/joinery-mysite")" "4"
chk "the template runs as www-data's own group, never user1" "$(grep -c '^[[:space:]]*su www-data www-data$' "$ROOT/maintenance_scripts/install_tools/logrotate_joinery.conf")/$(grep -c 'user1' "$ROOT/maintenance_scripts/install_tools/logrotate_joinery.conf")" "2/0"

echo "=== Another file already rotating the site's logs is left to do so ==="
R4="$T/fs4"; mkdir -p "$R4/etc/logrotate.d" "$R4/etc/cron.d"
printf '%s/logs/*.log {\n  weekly\n}\n' "$SITE" > "$R4/etc/logrotate.d/mysite-legacy"
out="$(JOINERY_SITE_HOUSEKEEPING_ROOT="$R4" bash "$SCRIPT" mysite "$SITE" 2>&1)"
chk "no second rotation for the same logs" "$([ -e "$R4/etc/logrotate.d/joinery-mysite" ] && echo written || echo absent)" "absent"
chk "and it names the file that rotates them" "$(printf '%s' "$out" | grep -c 'already rotated by .*mysite-legacy')" "1"

echo "=== A container, or --no-cron: no cron entry ==="
R2="$T/fs2"; mkdir -p "$R2/etc/logrotate.d" "$R2/etc/cron.d"; touch "$R2/.dockerenv"
JOINERY_SITE_HOUSEKEEPING_ROOT="$R2" bash "$SCRIPT" mysite "$SITE" >/dev/null 2>&1
chk "in a container the start command owns cron" "$([ -e "$R2/etc/cron.d/joinery-mysite" ] && echo written || echo absent)/$([ -e "$R2/etc/logrotate.d/joinery-mysite" ] && echo written || echo absent)" "absent/written"
R3="$T/fs3"; mkdir -p "$R3/etc/logrotate.d" "$R3/etc/cron.d"
JOINERY_SITE_HOUSEKEEPING_ROOT="$R3" bash "$SCRIPT" --no-cron mysite "$SITE" >/dev/null 2>&1
chk "--no-cron writes no cron entry" "$([ -e "$R3/etc/cron.d/joinery-mysite" ] && echo written || echo absent)" "absent"

echo "=== A container: the release manifest the code matches is the one kept ==="
UPG="$ROOT/public_html/utils/upgrade.php"
R5="$T/fs5"; mkdir -p "$R5/etc/logrotate.d" "$R5/etc/cron.d"; touch "$R5/.dockerenv"
S5="$T/html/mfsite"; mkdir -p "$S5/public_html" "$S5/maintenance_scripts" "$S5/config"
H5="$S5/config/release_manifest"
# A release: its code in the site's volumes, its signed manifest in $2.
mf_release() {
    local v="$1" dir="$2"; mkdir -p "$dir"
    printf '%s\n' "$v" > "$S5/public_html/VERSION"; printf 'code %s\n' "$v" > "$S5/maintenance_scripts/run.sh"
    (cd "$S5" && sha256sum public_html/VERSION maintenance_scripts/run.sh) > "$dir/RELEASE_MANIFEST"
    printf 'sig %s\n' "$v" > "$dir/RELEASE_MANIFEST.sig"
}
hk5() { JOINERY_SITE_HOUSEKEEPING_ROOT="$R5" bash "$SCRIPT" mfsite "$S5" 2>&1; }
mf_release A "$T/relA"; mf_release B "$T/relB"; cp "$T/relB/RELEASE_MANIFEST"* "$S5/"
out="$(hk5)"; rc=$?
chk "first converge keeps a copy of the manifest the code matches" "$rc:$(cmp -s "$S5/RELEASE_MANIFEST" "$H5/RELEASE_MANIFEST" && cmp -s "$S5/RELEASE_MANIFEST.sig" "$H5/RELEASE_MANIFEST.sig" && echo same)" "0:same"
cp "$T/relA/RELEASE_MANIFEST"* "$S5/"   # recreated from an image built at release A
out="$(hk5)"; rc=$?
chk "a rebuilt container's older manifest is replaced by the kept one the code matches" "$rc:$(cat "$S5/RELEASE_MANIFEST.sig")" "0:sig B"
chk "and it says so" "$(printf '%s' "$out" | grep -c 'put back the release manifest the code matches')" "1"
mf_release C "$T/relC"; cp "$T/relC/RELEASE_MANIFEST"* "$S5/"   # an upgrade to C before this converge
hk5 >/dev/null; chk "after an upgrade the kept copy follows the site root" "$(cat "$H5/RELEASE_MANIFEST.sig")" "sig C"
out="$(hk5)"; chk "when the two agree nothing is said or hashed" "$(printf '%s' "$out" | grep -c 'manifest')" "0"
printf 'D\n' > "$S5/public_html/VERSION"; cp "$T/relA/RELEASE_MANIFEST"* "$S5/"   # code no release describes
out="$(hk5)"; rc=$?
chk "a pair the code matches neither of is named, and nothing is swapped in" "$rc:$(printf '%s' "$out" | grep -c 'matches neither'):$(cat "$S5/RELEASE_MANIFEST.sig"):$(cat "$H5/RELEASE_MANIFEST.sig")" "0:1:sig A:sig C"
out="$(hk5)"; chk "and it is named once, not on every converge" "$(printf '%s' "$out" | grep -c 'matches neither')" "0"
R6="$T/fs6"; mkdir -p "$R6/etc/logrotate.d" "$R6/etc/cron.d"
S6="$T/html/diskmf"; mkdir -p "$S6/public_html" "$S6/config"; cp "$T/relA/RELEASE_MANIFEST"* "$S6/"
JOINERY_SITE_HOUSEKEEPING_ROOT="$R6" bash "$SCRIPT" diskmf "$S6" >/dev/null 2>&1
chk "on bare metal the manifest is left alone and no copy is kept" "$([ -e "$S6/config/release_manifest" ] && echo kept || echo none)" "none"
kept_at="$(grep -n "held_manifest_dir = " "$UPG" | head -1 | cut -d: -f1)"
tier_at="$(grep -n "apply_result_rolled_back('deploy_tier'" "$UPG" | head -1 | cut -d: -f1)"
chk "upgrade.php keeps its copy only after the deploy tier passed" "$([ -n "$kept_at" ] && [ -n "$tier_at" ] && [ "$kept_at" -gt "$tier_at" ] && echo after || echo "kept:${kept_at:-none} tier:${tier_at:-none}")" "after"

echo "=== Refusals ==="
out="$(bash "$SCRIPT" mysite "$SITE" 2>&1)"; rc=$?
chk "not root and no fixture: skips, exit 0" "$rc:$(printf '%s' "$out" | grep -c 'not root - skipping')" "0:1"
out="$(JOINERY_SITE_HOUSEKEEPING_ROOT="$R" bash "$SCRIPT" x "$T/html/bad name" 2>&1)"; rc=$?
chk "a site name outside the pattern is refused" "$rc:$(printf '%s' "$out" | grep -c 'is not a site name')" "0:1"

echo "=== Wiring ==="
chk "it is a core installer the runner runs" "$(grep -c '^CORE_INSTALLERS=.*site_housekeeping.sh' "$RUNNER")" "1"
chk "_site_init.sh delegates to it and writes neither file itself" "$(grep -c 'bash "${SCRIPT_DIR}/site_housekeeping.sh"' "$INIT")/$(grep -c 'logrotate_joinery.conf\|CRON_LINE=' "$INIT")" "1/0"

echo
echo "site_housekeeping gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
