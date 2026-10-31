#!/bin/bash
# @joinery-test
# name: cron_d_settle
# tier: safe
# env: any
# needs: []
# timeout: 90
# covers: [maintenance_scripts/install_tools/_plugin_installers_start.sh]
#
# cron notices a change to /etc/cron.d only by the folder's whole-second
# mtime, so an entry an installer writes in the second cron last loaded is
# never read. The host installers runner, after a run that changed the
# folder, leaves it with an mtime in a later second than every change the
# run made; a run that changed nothing leaves it alone. Driven as an
# unprivileged user against a temporary site tree (the fixture
# host_runner_lock_gate.sh builds) with JOINERY_CRON_D_DIR naming a
# temporary folder, and an install_agent.sh stub that writes an entry there.

set -u
TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/maintenance_scripts/install_tools"
RUNNER="$TOOLS/_plugin_installers_start.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

mkdir -p "$T/public_html/plugins" "$T/public_html/utils" "$T/config" "$T/cache" "$T/maintenance_scripts/install_tools" "$T/cron.d"
cp "$TOOLS"/*.sh "$T/maintenance_scripts/install_tools/" 2>/dev/null || true
echo 0.8.391 > "$T/public_html/VERSION"
echo '<?php' > "$T/config/Globalvars_site.php"
FT="$T/maintenance_scripts/install_tools"
CD="$T/cron.d"

# The stub writes an entry when told to, as an installer does on its first run.
cat > "$FT/install_agent.sh" <<STUB
#!/usr/bin/env bash
if [ -f "$T/write" ]; then
    printf '* * * * * root true\n' > "$CD/joinery-stub.tmp" && mv -f "$CD/joinery-stub.tmp" "$CD/joinery-stub"
fi
echo "stub-agent"
STUB
chmod 755 "$FT/install_agent.sh"

run() { JOINERY_CRON_D_DIR="$CD" bash "$RUNNER" --site-root="$T" > "$T/run.out" 2>&1; }

echo "== a run that writes an entry leaves the folder in a later second =="
# Start just after a second turns, so the write and the run's end share it.
sleep "0.$(printf '%03d' $(( 1000 - 10#$(date +%N | cut -c1-3) )))"
touch "$T/write"
run
file_s="$(stat -c %Y "$CD/joinery-stub" 2>/dev/null || echo 0)"
dir_s="$(stat -c %Y "$CD")"
chk "the stub wrote its entry" "$( [ -f "$CD/joinery-stub" ] && echo yes )" "yes"
chk "the folder's mtime is a later second than the entry's" "$( [ "$dir_s" -gt "$file_s" ] && echo yes || echo "no ($dir_s vs $file_s)")" "yes"

echo "== a run that writes nothing touches nothing =="
rm -f "$T/write"
touch -d '2026-01-01 00:00:00' "$CD/joinery-stub" "$CD"
before="$(stat -c %Y "$CD")"
run
chk "the folder's mtime is unchanged" "$(stat -c %Y "$CD")" "$before"

echo "== a --when-changed tick after a run touches nothing =="
JOINERY_CRON_D_DIR="$CD" bash "$RUNNER" --when-changed --site-root="$T" > "$T/tick.out" 2>&1
chk "the folder's mtime is unchanged after a tick" "$(stat -c %Y "$CD")" "$before"

echo "== the settle is wired to every exit =="
chk "set as the run's exit trap after the lock" "$(grep -c "^trap 'cron_d_settle' EXIT$" "$RUNNER")" "1"
chk "and kept when the converge sets its own" "$(grep -c "trap 'record_last \"\${CONVERGE_OUTCOME}\"; cron_d_settle' EXIT" "$RUNNER")" "1"
chk "no other EXIT trap replaces it" "$(grep -E "^\s*trap .* EXIT" "$RUNNER" | grep -vc cron_d_settle)" "0"

echo
echo "cron_d_settle gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
