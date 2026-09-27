#!/bin/bash
# @joinery-test
# name: restore_runner_lock
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/host_runner_lock.sh, maintenance_scripts/sysadmin_tools/restore_chain.sh, maintenance_scripts/sysadmin_tools/restore_project.sh, maintenance_scripts/install_tools/_plugin_installers_start.sh]
#
# A restore and the host converger never run at once. The converger runs the
# plugin installers and site_housekeeping.sh against the site tree whenever the
# release changes; a restore rewrites that tree. So a restore holds the
# converger's own lock - the same file, by the same rule - from before its first
# write until it exits.
#
# Unprivileged, on a fixture tree: the unprivileged rule is the site's cache,
# which is where a non-root converger locks too. The root rule is checked by
# reading both scripts, which must name the same file.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
CONVERGER="$ROOT/maintenance_scripts/install_tools/_plugin_installers_start.sh"
passed=0; failed=0
pass() { echo "  PASS: $1"; passed=$((passed+1)); }
fail() { echo "  FAIL: $1"; [ -n "${2:-}" ] && echo "        $2"; failed=$((failed+1)); }

if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged - as root this would take the real site's lock"
    echo; echo "RESULT: PASS 0 0"; exit 0
fi

WORK="$(mktemp -d)"
HOLDER=""
trap '[ -n "$HOLDER" ] && kill "$HOLDER" 2>/dev/null; rm -rf "$WORK"' EXIT
SITE="rlock_$$"
PROJECT="$WORK/$SITE"
mkdir -p "$PROJECT"

# Each case runs in its own shell, as each restore does.
try_lock() {
    JOINERY_LOCK_WAIT_SECONDS=1 bash -c "source '$TOOLS/host_runner_lock.sh'; hold_host_runner_lock '$SITE' '$PROJECT'" 2>"$WORK/err"
}

echo "== A free lock is taken, and says who holds it =="
if try_lock; then pass "a restore takes a free lock"; else fail "a free lock was refused" "$(cat "$WORK/err")"; fi
LOCK="$PROJECT/cache/host_installers.lock"
if [ -f "$LOCK" ]; then pass "in the site's cache, where an unprivileged converger locks"; else fail "no lock file at $LOCK"; fi
mode=$(stat -c %a "$LOCK" 2>/dev/null)
if [ "$mode" = "600" ]; then pass "created 0600 - no other account can open it to hold it"; else fail "lock file mode is $mode, not 600"; fi
if head -1 "$LOCK" | grep -Eq '^[0-9]+$'; then pass "the holder's pid is recorded"; else fail "no holder record in the lock file"; fi

echo "== A held lock refuses the restore, and names the holder =="
# Hold it the way the converger does: flock on descriptor 9, a record written in place.
( exec 9>>"$LOCK"; flock 9; printf '4242\n%s\n' "$(date -u +%s)" > "$LOCK"; sleep 30 ) &
HOLDER=$!
sleep 0.5
if try_lock; then
    fail "a restore took a lock the converger holds"
else
    pass "a restore waits, then refuses, while the converger holds the lock"
fi
if grep -q 'pid 4242' "$WORK/err"; then pass "and names who holds it"; else fail "the refusal does not name the holder" "$(cat "$WORK/err")"; fi
if grep -q 'Nothing was restored' "$WORK/err"; then pass "and says nothing was restored"; else fail "the refusal does not say nothing was restored"; fi
kill "$HOLDER" 2>/dev/null; wait "$HOLDER" 2>/dev/null; HOLDER=""
if try_lock; then pass "once the holder is gone, the lock is free again (a kernel flock never goes stale)"; else fail "the lock stayed held after its holder died"; fi

echo "== No tree, nothing to hold off =="
if JOINERY_LOCK_WAIT_SECONDS=1 bash -c "source '$TOOLS/host_runner_lock.sh'; hold_host_runner_lock nosuch '$WORK/nosuch'" 2>/dev/null \
   && [ ! -e "$WORK/nosuch" ]; then
    pass "a restore into a directory that does not exist yet proceeds, and creates nothing"
else
    fail "a restore into a new directory was refused, or the helper created it"
fi

echo "== The converger and the restore name the same file =="
if grep -q 'LOCK_DIR="/run/joinery"' "$CONVERGER" \
   && grep -q '${LOCK_DIR}/host-installers.${SITENAME}.lock' "$CONVERGER" \
   && grep -q '/run/joinery/host-installers.${site}.lock' "$TOOLS/host_runner_lock.sh"; then
    pass "as root, both lock /run/joinery/host-installers.<site>.lock"
else
    fail "the root lock paths differ between the converger and the restore"
fi
if grep -q '${SITE_ROOT}/cache/host_installers.lock' "$CONVERGER" \
   && grep -q '${project}/cache/host_installers.lock' "$TOOLS/host_runner_lock.sh"; then
    pass "unprivileged, both lock <site>/cache/host_installers.lock"
else
    fail "the unprivileged lock paths differ between the converger and the restore"
fi

echo "== As root, the converger's lock file is 0600 =="
# A lock file another account can open is one it can hold (flock needs only a
# descriptor), and the converger then waits out every run doing nothing.
if grep -q '( umask 077; : >> "${LOCK_FILE}" )' "$CONVERGER" && grep -q 'chmod 600 "${LOCK_FILE}"' "$CONVERGER"; then
    pass "the converger creates its root lock file 0600 and tightens a wider one"
else
    fail "the converger's root lock file can be opened by other accounts"
fi

echo "== Every restore holds it before its first write =="
first_line() { grep -n -m1 -F -- "$2" "$1" | cut -d: -f1; }
lock_at=$(first_line "$TOOLS/restore_chain.sh" 'hold_host_runner_lock ')
write_at=$(first_line "$TOOLS/restore_chain.sh" 'mkdir -p "$PARENT"')
if [ -n "$lock_at" ] && [ -n "$write_at" ] && [ "$lock_at" -lt "$write_at" ]; then
    pass "restore_chain.sh takes the lock (line $lock_at) before its first write (line $write_at)"
else
    fail "restore_chain.sh does not take the lock before its first write" "lock at '${lock_at}', write at '${write_at}'"
fi
lock_at=$(first_line "$TOOLS/restore_project.sh" 'hold_host_runner_lock ')
write_at=$(first_line "$TOOLS/restore_project.sh" 'TEMP_DIR=$(mktemp -d')
if [ -n "$lock_at" ] && [ -n "$write_at" ] && [ "$lock_at" -lt "$write_at" ]; then
    pass "restore_project.sh takes the lock (line $lock_at) before its first write (line $write_at)"
else
    fail "restore_project.sh does not take the lock before its first write" "lock at '${lock_at}', write at '${write_at}'"
fi

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
