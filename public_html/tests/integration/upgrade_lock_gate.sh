#!/bin/bash
# @joinery-test
# name: upgrade_lock
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# utils/upgrade.php must run one upgrade at a time: staging (uploads/upgrades/)
# is shared state, so a second run's staging-clear wipes the first run's
# extraction mid-flight and whichever run swaps first deploys a broken tree.
# The guard is a kernel-held flock — a killed run releases it automatically and
# can never wedge the next one.
#
# The lock function is EXTRACTED FROM utils/upgrade.php rather than copied
# here, so this exercises the code that actually ships. upgrade.php itself is
# NEVER executed by this gate (running it starts a real upgrade).

set -u
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/utils/upgrade.php"
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

echo "== the shipped function extracts and parses =="
{
    echo '<?php'
    awk '/function acquire_upgrade_lock/{f=1} f{print} f&&/^\t}$/{exit}' "$SRC"
} > "$T/lock_fn.php"
chk "function found in upgrade.php" "$(grep -c 'function acquire_upgrade_lock' "$T/lock_fn.php")" "1"
php -l "$T/lock_fn.php" >/dev/null 2>&1
chk "extracted function parses" "$?" "0"

cat > "$T/try.php" <<'PHP'
<?php
require $argv[1] . '/lock_fn.php';
$h = acquire_upgrade_lock($argv[2]);
echo ($h === false) ? "BUSY\n" : "OK\n";
PHP
cat > "$T/hold.php" <<'PHP'
<?php
require $argv[1] . '/lock_fn.php';
$h = acquire_upgrade_lock($argv[2]);
if ($h === false) { file_put_contents($argv[3], "BUSY"); exit(1); }
file_put_contents($argv[3], "ACQUIRED");
sleep(30);
PHP

LOCK="$T/.upgrade.lock"

echo "== an uncontended lock is granted =="
chk "fresh path acquires" "$(php "$T/try.php" "$T" "$LOCK")" "OK"
chk "lock file created"   "$([ -f "$LOCK" ] && echo yes)" "yes"
chk "world-writable so web and CLI users share it" "$(stat -c '%a' "$LOCK")" "666"

echo "== a second run is refused while the first holds =="
MARKER="$T/marker"
php "$T/hold.php" "$T" "$LOCK" "$MARKER" &
HOLD_PID=$!
for i in $(seq 1 50); do [ -s "$MARKER" ] && break; sleep 0.1; done
chk "holder got the lock"          "$(cat "$MARKER")" "ACQUIRED"
chk "contender is turned away"     "$(php "$T/try.php" "$T" "$LOCK")" "BUSY"

echo "== a killed run never wedges the next one =="
kill -9 "$HOLD_PID" 2>/dev/null; wait "$HOLD_PID" 2>/dev/null
chk "lock freed by process death"  "$(php "$T/try.php" "$T" "$LOCK")" "OK"

echo "== an unopenable lock path reads as held, not as a crash =="
mkdir -p "$T/ro"; chmod 500 "$T/ro"
chk "unwritable dir returns false" "$(php "$T/try.php" "$T" "$T/ro/.upgrade.lock")" "BUSY"
chmod 700 "$T/ro"

echo "== the guard sits where it protects =="
call_line=$(grep -n 'upgrade_lock_handle = acquire_upgrade_lock' "$SRC" | head -1 | cut -d: -f1)
clear_line=$(grep -n 'Clearing staging area' "$SRC" | head -1 | cut -d: -f1)
chk "lock acquired in the action path"      "$([ -n "$call_line" ] && echo yes)" "yes"
chk "acquired before staging is cleared"    "$([ -n "$call_line" ] && [ -n "$clear_line" ] && [ "$call_line" -lt "$clear_line" ] && echo yes)" "yes"
chk "refusal aborts WITHOUT clearing the other run's staging" \
    "$(sed -n "${call_line},$((call_line+8))p" "$SRC" | grep -c 'false);')" "1"
chk "lock lives outside the staging dir it guards" \
    "$(grep -c "uploads/.upgrade.lock" "$SRC")" "1"

unlock_line=$(grep -n 'LOCK_UN' "$SRC" | head -1 | cut -d: -f1)
passthru_line=$(grep -n 'passthru($cmd' "$SRC" | head -1 | cut -d: -f1)
chk "self-update re-exec releases the lock first" \
    "$([ -n "$unlock_line" ] && [ -n "$passthru_line" ] && [ "$unlock_line" -lt "$passthru_line" ] && echo yes)" "yes"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
