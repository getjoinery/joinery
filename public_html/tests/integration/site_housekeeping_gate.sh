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
# converge and moving a file aside is the reset
# (specs/agent_recipes_and_vocabulary.md, Host files). Driven unprivileged
# against a fixture /etc through JOINERY_SITE_HOUSEKEEPING_ROOT.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/install_tools/site_housekeeping.sh"
RUNNER="$ROOT/maintenance_scripts/install_tools/_plugin_installers_start.sh"
INIT="$ROOT/maintenance_scripts/install_tools/_site_init.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
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
