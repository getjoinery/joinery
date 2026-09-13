#!/bin/bash
# @joinery-test
# name: host_runner_lock
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/install_tools/_plugin_installers_start.sh, maintenance_scripts/install_tools/install_host_converger.sh]
#
# The host installers runner holds one kernel lock for the whole of every
# run, taken before anything changes the host (specs/agent_tier1_recipes.md,
# the runner lock). Driven against a temporary site tree as an unprivileged
# user, the way host_converger_gate.sh drives it: the real installers skip
# without root, and two of them are replaced in the fixture by stubs that
# sleep or echo, so a run's timing and transcript are deterministic. Pinned:
# two runners started together, the second waits and runs after the first,
# its record replacing the first's; a holder that outlives the wait is named
# (the wait is shortened by JOINERY_LOCK_WAIT_SECONDS, which root ignores);
# the lock file carries the holder's pid and start time; a record the runner
# cannot read is reported as unknown; --only=bogus is refused with exit 2
# and runs nothing; --only=<core installer> runs exactly that one, prints its
# transcript, and touches neither the converge stamp nor the last-run file;
# a full run still carries out queued root requests under the one lock; and
# the timer's oneshot service bounds a hung run with TimeoutStartSec.

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

# The same fixture host_converger_gate.sh builds: a site tree with its own
# install_tools, because that is where the runner resolves its installers.
mkdir -p "$T/public_html/plugins" "$T/public_html/utils" "$T/config" "$T/cache" "$T/maintenance_scripts/install_tools"
cp "$TOOLS"/*.sh "$T/maintenance_scripts/install_tools/" 2>/dev/null || true
echo 0.8.391 > "$T/public_html/VERSION"
echo '<?php' > "$T/config/Globalvars_site.php"
FT="$T/maintenance_scripts/install_tools"
LOCK="$T/cache/host_installers.lock"          # where an unprivileged run locks
STAMP="$T/cache/host_converger.stamp"
LAST="$T/cache/host_converger.last"

# Two core installers replaced by stubs. The first in the list sleeps, so a
# second runner started beside the first finds the lock held; another echoes
# its arguments, so --only's transcript can be read exactly.
cat > "$FT/install_agent.sh" <<STUB
#!/usr/bin/env bash
date +%s%N >> "$T/agent.starts"
sleep 3
date +%s%N >> "$T/agent.ends"
echo "stub-agent \$1"
STUB
cat > "$FT/install_parser_jail.sh" <<'STUB'
#!/usr/bin/env bash
echo "stub-parser-jail $1 $2"
STUB
chmod 755 "$FT/install_agent.sh" "$FT/install_parser_jail.sh"
SITENAME="$(basename "$T")"

echo "== the runner parses and the lock sits above the first change to the host =="
chk "runner parses" "$(bash -n "$RUNNER" && echo ok)" "ok"
LOCK_AT="$(grep -n '^if ! flock -w "\${LOCK_WAIT_SECONDS}" 9; then' "$RUNNER" | head -1 | cut -d: -f1)"
ASSERT_AT="$(grep -n '^assert_tree_ownership$' "$RUNNER" | cut -d: -f1)"
REFRESH_AT="$(grep -n '^refresh_converger_entry$' "$RUNNER" | cut -d: -f1)"
PERMS_AT="$(grep -n '^apply_tree_permissions$' "$RUNNER" | cut -d: -f1)"
ONLY_AT="$(grep -n '^if \[\[ -n "\${ONLY_INSTALLER}" \]\]; then' "$RUNNER" | cut -d: -f1)"
chk "the lock is taken before the ownership assertion" "$( [ -n "$LOCK_AT" ] && [ "$LOCK_AT" -lt "$ASSERT_AT" ] && echo yes )" "yes"
chk "before the entry-point refresh" "$( [ "$LOCK_AT" -lt "$REFRESH_AT" ] && echo yes )" "yes"
chk "and before the permissions sweep" "$( [ "$LOCK_AT" -lt "$PERMS_AT" ] && echo yes )" "yes"
chk "--only runs after the ownership assertion" "$( [ -n "$ONLY_AT" ] && [ "$ONLY_AT" -gt "$ASSERT_AT" ] && echo yes )" "yes"
chk "and before the entry-point refresh, which it must not do" "$( [ "$ONLY_AT" -lt "$REFRESH_AT" ] && echo yes )" "yes"
# One flock in the file: the queue's own is gone, descriptor 9 is already held.
chk "exactly one flock in the runner" "$(grep -c '^[^#]*flock ' "$RUNNER")" "1"
chk "and it waits, bounded" "$(grep -c '^if ! flock -w "\${LOCK_WAIT_SECONDS}" 9; then$' "$RUNNER")" "1"
# The wait is compiled: minutes, long enough for a full converge or an
# upgrade's installer run, and far under the service's hour.
WAIT="$(sed -n 's/^LOCK_WAIT_SECONDS=\([0-9]*\)$/\1/p' "$RUNNER")"
chk "the compiled wait is minutes, under the service's hour" "$( [ -n "$WAIT" ] && [ "$WAIT" -ge 120 ] && [ "$WAIT" -lt 3600 ] && echo yes )" "yes"
chk "JOINERY_LOCK_WAIT_SECONDS is ignored by root, and root says so" "$(grep -c 'JOINERY_LOCK_WAIT_SECONDS is set but this is root - hook ignored' "$RUNNER")" "1"
chk "the queue phase takes no lock of its own" "$(sed -n '/^run_root_requests() {/,/^}$/p' "$RUNNER" | grep -c 'flock\|exec 9\|\.runner\.lock')" "0"
# A fixed descriptor below 10, opened for APPEND (write mode would truncate
# the holder's record before a busy run could read it), and no redirection on
# the exec (`exec 9>>f 2>/dev/null` silences the whole shell for good).
chk "descriptor 9, append mode, no redirection on the exec" "$(grep -c '^if ! exec 9>>"\${LOCK_FILE}"; then$' "$RUNNER")" "1"
chk "root locks outside the tree, where only root can create" "$(grep -c '^    LOCK_DIR="/run/joinery"$' "$RUNNER")" "1"
chk "per site, by name" "$(grep -c '^    LOCK_FILE="\${LOCK_DIR}/host-installers.\${SITENAME}.lock"$' "$RUNNER")" "1"
chk "an unprivileged run locks in the site's cache" "$(grep -c '^    LOCK_FILE="\${SITE_ROOT}/cache/host_installers.lock"$' "$RUNNER")" "1"
# The record is written in place: a temp-and-rename would leave the flock on
# an inode nobody opens again.
chk "the holder record is written in place, never renamed in" "$(sed -n '/^if ! flock -w/,/^# --- The executable set/p' "$RUNNER" | grep -c 'mv ')" "0"

echo "== two runners started together: the second waits and runs after the first =="
# The compiled wait, not the hook: this is what root does. The first holds the
# lock for the three seconds its stub sleeps; the second waits that out and
# then does a full run of its own.
rm -f "$LOCK" "$T/agent.starts" "$T/agent.ends"
bash "$RUNNER" --site-root="$T" > "$T/first.out" 2>&1 &
FIRST=$!
# Wait for the first to have taken the lock and written its record.
for _ in $(seq 1 40); do
    [ -f "$LOCK" ] && [ "$(wc -l < "$LOCK")" -ge 2 ] && break
    sleep 0.05
done
chk "the first's record is in place while it runs" "$(sed -n 1p "$LOCK")" "$FIRST"
bash "$RUNNER" --site-root="$T" > "$T/second.out" 2>&1 &
SECOND=$!
wait "$SECOND"; rc=$?
wait "$FIRST"
chk "the second exits 0" "$rc" "0"
chk "and never called the lock busy" "$(grep -c 'holds the lock' "$T/second.out")" "0"
CORE_COUNT="$(sed -n 's/^CORE_INSTALLERS="\([^"]*\)".*/\1/p' "$RUNNER" | wc -w)"
chk "the first ran them all" "$(grep -c 'core installers: running' "$T/first.out")" "$CORE_COUNT"
chk "and so did the second, after waiting" "$(grep -c 'core installers: running' "$T/second.out")" "$CORE_COUNT"
chk "including the one that slept, in each" "$(cat "$T/first.out" "$T/second.out" | grep -c "^stub-agent ${SITENAME}$")" "2"
chk "the second's installer started only after the first's had ended" "$( [ "$(sed -n 2p "$T/agent.starts")" -gt "$(sed -n 1p "$T/agent.ends")" ] && echo yes )" "yes"
chk "two runs, two starts" "$(wc -l < "$T/agent.starts")" "2"

echo "== the lock file carries the holder's pid and start time =="
chk "two lines" "$(wc -l < "$LOCK")" "2"
chk "the first is the pid of the run that held it last: the second's, replacing the first's" "$(sed -n 1p "$LOCK")" "$SECOND"
SINCE="$(sed -n 2p "$LOCK")"
chk "the second is a unix time" "$( [[ "$SINCE" =~ ^[0-9]+$ ]] && echo yes )" "yes"
chk "from the last two minutes" "$( [ $(( $(date -u +%s) - SINCE )) -lt 120 ] && echo yes )" "yes"

echo "== a holder that outlives the wait is named =="
# The hook shortens the wait to a second; the first's stub sleeps three. Only
# when the wait runs out does the second print who holds the lock, and it
# records nothing.
rm -f "$LAST"
bash "$RUNNER" --site-root="$T" > "$T/first.out" 2>&1 &
FIRST=$!
for _ in $(seq 1 40); do
    [ "$(sed -n 1p "$LOCK" 2>/dev/null)" = "$FIRST" ] && break
    sleep 0.05
done
second=$(JOINERY_LOCK_WAIT_SECONDS=1 bash "$RUNNER" --site-root="$T" 2>&1); rc=$?
chk "the second exits 0" "$rc" "0"
chk "and says another run holds the lock" "$(echo "$second" | grep -c 'host installers: another run holds the lock (pid')" "1"
chk "naming the first's pid" "$(echo "$second" | grep -c "(pid ${FIRST} since ")" "1"
chk "with its start time in UTC, and how long it waited" "$(echo "$second" | grep -Ec "since [0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} UTC\) - waited 1s, leaving it to that one$")" "1"
chk "and that is its only line" "$(echo "$second" | wc -l)" "1"
chk "it ran no installer" "$(echo "$second" | grep -c 'core installers: running')" "0"
# The second recorded nothing: no last-run file from a run that did not run.
chk "the second recorded no last run" "$(test -e "$LAST" && echo recorded || echo none)" "none"
chk "the first's record is untouched" "$(sed -n 1p "$LOCK")" "$FIRST"
wait "$FIRST"
chk "the first ran them all regardless" "$(grep -c 'core installers: running' "$T/first.out")" "$CORE_COUNT"

echo "== a record the runner cannot read is reported as unknown, never guessed =="
# Something else holds the file with garbage in it (or the holder is between
# truncating and writing): the message says unknown rather than parsing it.
( exec 9>>"$LOCK"; flock 9; printf 'not a pid\n' > "$LOCK"; sleep 2 ) &
HOLDER=$!
sleep 0.3
second=$(JOINERY_LOCK_WAIT_SECONDS=1 bash "$RUNNER" --site-root="$T" 2>&1); rc=$?
chk "exits 0" "$rc" "0"
chk "and says holder unknown" "$(echo "$second" | grep -c 'another run holds the lock (holder unknown) - waited 1s, leaving it to that one')" "1"
wait "$HOLDER"

echo "== --only=bogus is refused with exit 2 and runs nothing =="
rm -f "$LOCK" "$STAMP" "$LAST"
out=$(bash "$RUNNER" --only=bogus.sh --site-root="$T" 2>&1); rc=$?
chk "exit 2" "$rc" "2"
chk "says it is not a core installer, naming the list" "$(echo "$out" | grep -c 'host installers: --only=bogus.sh is not a core installer (one of: install_agent.sh')" "1"
chk "runs nothing" "$(echo "$out" | grep -c 'core installers:\|stub-')" "0"
chk "and never took the lock" "$(test -e "$LOCK" && echo taken || echo untouched)" "untouched"
# A path, a name with the right suffix but outside the list, an empty name.
out=$(bash "$RUNNER" "--only=../install_agent.sh" --site-root="$T" 2>&1); rc=$?
chk "a path is refused" "$rc" "2"
out=$(bash "$RUNNER" --only=fix_permissions.sh --site-root="$T" 2>&1); rc=$?
chk "a real script that is not a core installer is refused" "$rc" "2"
out=$(bash "$RUNNER" --only= --site-root="$T" 2>&1); rc=$?
chk "an empty name is refused" "$rc" "2"
out=$(bash "$RUNNER" --only=install_agent.sh --when-changed --site-root="$T" 2>&1); rc=$?
chk "--only with --when-changed is refused: two modes" "$rc" "2"
chk "and says so" "$(echo "$out" | grep -c 'different modes - refused')" "1"
chk "none of those touched the lock" "$(test -e "$LOCK" && echo taken || echo untouched)" "untouched"

echo "== --only=<core installer> runs exactly that one and nothing else =="
printf 'stamp-before\n' > "$STAMP"
printf '1700000000 converged\n' > "$LAST"
mkdir -p "$T/cache/root_requests"
REQ="$T/cache/root_requests/$(date -u +%s)-aaaaaaaa.json"
printf '{"kind":"write_agent_files","args":{},"requested_at":%s}\n' "$(date -u +%s)" > "$REQ"
out=$(bash "$RUNNER" --only=install_parser_jail.sh --site-root="$T" 2>&1); rc=$?
chk "exit 0" "$rc" "0"
chk "one installer ran" "$(echo "$out" | grep -c 'core installers: running')" "1"
chk "the one asked for" "$(echo "$out" | grep -c 'core installers: running install_parser_jail.sh')" "1"
chk "with the same two arguments the full run passes" "$(echo "$out" | grep -c "^stub-parser-jail ${SITENAME} ${T}$")" "1"
chk "and the same ok line" "$(echo "$out" | grep -c 'core installers: install_parser_jail.sh: ok')" "1"
chk "the transcript is those three lines" "$(echo "$out" | wc -l)" "3"
chk "the converge stamp is untouched" "$(cat "$STAMP")" "stamp-before"
chk "the last-run file is untouched" "$(cat "$LAST")" "1700000000 converged"
chk "no plugin installers, no certificate summary, no key file" "$(echo "$out" | grep -c 'plugin installers:\|certificates:\|release key:')" "0"
chk "a queued root request is left where it was" "$(test -f "$REQ" && echo queued || echo gone)" "queued"
chk "the lock was taken and records this run" "$( [[ "$(sed -n 1p "$LOCK")" =~ ^[0-9]+$ ]] && echo yes )" "yes"
rm -f "$REQ"

# An installer that fails under --only is reported, and the exit stays 0: the
# caller reads the transcript and verifies the aspect, never this code.
cat > "$FT/render_vhost.sh" <<'STUB'
#!/usr/bin/env bash
echo "stub-vhost failing"
exit 1
STUB
chmod 755 "$FT/render_vhost.sh"
out=$(bash "$RUNNER" --only=render_vhost.sh --site-root="$T" 2>&1); rc=$?
chk "a failing installer still exits 0 under --only" "$rc" "0"
chk "and is reported as failed" "$(echo "$out" | grep -c 'core installers: WARNING - render_vhost.sh failed')" "1"

# --only holds the same lock: a full run started beside it, with a wait
# shorter than the --only run, says busy and names it.
cat > "$FT/render_vhost.sh" <<'STUB'
#!/usr/bin/env bash
sleep 3
echo "stub-vhost"
STUB
bash "$RUNNER" --only=render_vhost.sh --site-root="$T" > "$T/only.out" 2>&1 &
ONLY_PID=$!
for _ in $(seq 1 40); do
    [ "$(sed -n 1p "$LOCK" 2>/dev/null)" = "$ONLY_PID" ] && break
    sleep 0.05
done
second=$(JOINERY_LOCK_WAIT_SECONDS=1 bash "$RUNNER" --site-root="$T" 2>&1); rc=$?
chk "a full run beside an --only run exits 0" "$rc" "0"
chk "naming the --only run as the holder" "$(echo "$second" | grep -c "(pid ${ONLY_PID} since ")" "1"
wait "$ONLY_PID"

echo "== the core loop and --only run one body =="
chk "one function runs a core installer" "$(grep -c '^run_core_installer() {' "$RUNNER")" "1"
chk "the loop calls it" "$(sed -n '/^for CORE_INSTALLER in \${CORE_INSTALLERS}; do/,/^done$/p' "$RUNNER" | grep -c 'run_core_installer "\${CORE_INSTALLER}"')" "1"
chk "and so does --only" "$(sed -n '/^if \[\[ -n "\${ONLY_INSTALLER}" \]\]; then/,/^fi$/p' "$RUNNER" | grep -c 'run_core_installer "\${ONLY_INSTALLER}"')" "1"
chk "the trust check lives in that one body" "$(sed -n '/^run_core_installer() {/,/^}$/p' "$RUNNER" | grep -c 'installer_is_trusted "\${path}"')" "1"
chk "and nowhere else runs a core installer" "$(grep -c 'bash "\${path}" "\${SITENAME}" "\${SITE_ROOT}"\|bash "\${CORE_PATH}"' "$RUNNER")" "1"

echo "== a full run still carries out root requests, under the one lock =="
# run_root_requests is root-only; the root gates are stripped the way
# host_converger_gate.sh strips them, and the entry-point refresh is pointed
# at nothing.
sed 's/\[\[ "$(id -u)" == "0" \]\] || return 0//' "$RUNNER" > "$T/nogate.sh"
printf '<?php\necho "kind=" . ($argv[1] ?? "") . "\\n";\nexit(0);\n' > "$T/public_html/utils/root_request.php"
mkdir -p "$T/letsencrypt" "$T/sites"
REQ="$T/cache/root_requests/$(date -u +%s)-bbbbbbbb.json"
printf '{"kind":"write_agent_files","args":{},"requested_at":%s}\n' "$(date -u +%s)" > "$REQ"
out=$(JOINERY_CONVERGER_ENTRY=/dev/null JOINERY_LETSENCRYPT_DIR="$T/letsencrypt" JOINERY_APACHE_SITES_DIR="$T/sites" \
    bash "$T/nogate.sh" --site-root="$T" 2>&1); rc=$?
chk "exit 0" "$rc" "0"
chk "the request was carried out" "$(echo "$out" | grep -c 'root request: .*-bbbbbbbb done')" "1"
chk "and moved to done/" "$(ls "$T/cache/root_requests/done/" | grep -c 'bbbbbbbb.json')" "1"
chk "the transcript records the dispatcher's run" "$(grep -c 'kind=write_agent_files' "$T"/logs/root_requests/*-bbbbbbbb.log)" "1"
chk "no second lock was taken" "$(echo "$out" | grep -c 'holds the queue\|holds the lock')" "0"
chk "and no lock file was left in the queue directory" "$(test -e "$T/cache/root_requests/.runner.lock" && echo left || echo none)" "none"

echo "== the timer's oneshot service bounds a hung run =="
# A hung installer holds the lock while it lives. After an hour systemd kills
# the service; the default KillMode, control-group, takes the installer and
# its children together, and the lock releases with them.
chk "installer parses" "$(bash -n "$INSTALLER" && echo ok)" "ok"
chk "TimeoutStartSec=1h in the service text" "$(sed -n '/SERVICE_TEXT="\[Unit\]/,/^Nice=10/!b;p' "$INSTALLER" | grep -c '^TimeoutStartSec=1h')$(grep -c '^TimeoutStartSec=1h"$' "$INSTALLER")" "01"
chk "in the [Service] section" "$( [ "$(grep -n '^TimeoutStartSec=1h' "$INSTALLER" | cut -d: -f1)" -gt "$(grep -n '^\[Service\]$' "$INSTALLER" | head -1 | cut -d: -f1)" ] && echo yes )" "yes"
chk "KillMode is left at its default" "$(grep -c '^KillMode=' "$INSTALLER")" "0"
chk "and the text says the default is what is relied on" "$(grep -c 'control-group' "$INSTALLER")" "2"
# The unit is rewritten whenever its text differs, so a node on the previous
# text takes this one at its next converge.
chk "the unit is rewritten when its text differs" "$(grep -c 'write_if_changed "\${SERVICE_FILE}" "\${SERVICE_TEXT}" 644' "$INSTALLER")" "1"
chk "followed by a daemon-reload" "$(sed -n '/write_if_changed "\${SERVICE_FILE}"/,/systemctl daemon-reload/p' "$INSTALLER" | grep -c 'systemctl daemon-reload')" "1"

echo
echo "host_runner_lock gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
