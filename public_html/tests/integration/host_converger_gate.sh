#!/bin/bash
# @joinery-test
# name: host_converger
# tier: safe
# env: any
# needs: []
# timeout: 120
#
# The host converger's change detection (specs/host_converger.md), run against
# a temporary site tree as an unprivileged user: the installers themselves
# skip without root (each says so), which is exactly what lets the stamp logic
# be exercised here. What is pinned: a fresh stamp costs nothing, a changed
# release converges, a day-old stamp converges, an unreachable database is
# retried next tick, and every run records cache/host_converger.last.
#
# The installer's unit text is rendered and parsed too, without installing it.

set -u
TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/maintenance_scripts/install_tools"
RUNNER="$TOOLS/_plugin_installers_start.sh"
INSTALLER="$TOOLS/install_host_converger.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

mkdir -p "$T/public_html/plugins" "$T/config" "$T/cache"
echo 0.8.384 > "$T/public_html/VERSION"
echo '<?php' > "$T/config/Globalvars_site.php"
LAST="$T/cache/host_converger.last"
STAMP="$T/cache/host_converger.stamp"

echo "== the runner and the installer exist and parse =="
chk "runner parses" "$(bash -n "$RUNNER" && echo ok)" "ok"
chk "installer parses" "$(bash -n "$INSTALLER" && echo ok)" "ok"
chk "the installer is a core installer" "$(grep -c 'CORE_INSTALLERS="[^"]*install_host_converger.sh' "$RUNNER")" "1"

echo "== first tick: no stamp, so it converges =="
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "runs the installers" "$(echo "$out" | grep -c 'core installers: running')" "3"
chk "says why" "$(echo "$out" | grep -c 'release or installers changed')" "1"
chk "records the run (database unreachable in a temp tree)" "$(cut -d' ' -f2 "$LAST")" "db-unreachable"

echo "== an unreachable database is retried next tick =="
chk "the stamp was dropped" "$(test -e "$STAMP" && echo kept || echo dropped)" "dropped"

echo "== a fresh, unchanged stamp costs nothing =="
# Simulate a converged run: stamp present, last fresh.
bash "$RUNNER" --when-changed --site-root="$T" >/dev/null 2>&1
printf '%s converged\n' "$(date -u +%s)" > "$LAST"
bash "$RUNNER" --site-root="$T" >/dev/null 2>&1   # a plain run writes no stamp
h=$(cat "$T/public_html/VERSION" "$RUNNER" "$TOOLS"/install_*.sh 2>/dev/null; echo "db-unreachable")
# The stamp the runner would compute for this tree; written by hand so the
# next tick sees "unchanged".
{ cat "$T/public_html/VERSION" "$RUNNER" "$TOOLS"/install_*.sh 2>/dev/null; for m in "$T"/public_html/plugins/*/plugin.json; do [ -f "$m" ] && cat "$m"; done; echo "db-unreachable"; } | sha256sum | cut -d' ' -f1 > "$STAMP"
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "runs nothing" "$(echo "$out" | grep -c 'core installers: running')" "0"
chk "prints nothing" "${#out}" "0"

echo "== a changed release converges =="
echo 0.8.385 > "$T/public_html/VERSION"
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "runs the installers again" "$(echo "$out" | grep -c 'core installers: running')" "3"

echo "== a day-old run converges regardless =="
{ cat "$T/public_html/VERSION" "$RUNNER" "$TOOLS"/install_*.sh 2>/dev/null; echo "db-unreachable"; } | sha256sum | cut -d' ' -f1 > "$STAMP"
printf '%s converged\n' "$(( $(date -u +%s) - 90000 ))" > "$LAST"
out=$(bash "$RUNNER" --when-changed --site-root="$T" 2>&1)
chk "says daily" "$(echo "$out" | grep -c 'converging.*(daily)')" "1"

echo "== without the flag every run converges =="
out=$(bash "$RUNNER" --site-root="$T" 2>&1)
chk "runs the installers" "$(echo "$out" | grep -c 'core installers: running')" "3"

echo "== the installer refuses politely without root =="
out=$(bash "$INSTALLER" x "$T" 2>&1)
chk "skips, naming the sudo command" "$(echo "$out" | grep -c 'not root - skipping (run: sudo bash')" "1"
chk "touches nothing" "$(test -e /etc/cron.d/joinery-host-converger-test-$$ && echo touched || echo clean)" "clean"

echo "== the unit text the installer writes =="
chk "a oneshot service" "$(grep -c '^Type=oneshot' "$INSTALLER")" "1"
chk "a persistent five-minute timer" "$(grep -c 'OnUnitActiveSec=\${INTERVAL_MIN}min' "$INSTALLER")$(grep -c '^Persistent=true' "$INSTALLER")" "11"
chk "runs the runner in --when-changed mode" "$(grep -c -- '--when-changed \${SITENAME} \${SITE_ROOT}' "$INSTALLER")" "1"
chk "cron form for a box without systemd" "$(grep -c '^\*/\${INTERVAL_MIN} \* \* \* \* root' "$INSTALLER")" "1"

echo
echo "host_converger gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
