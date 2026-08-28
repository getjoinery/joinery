#!/bin/bash
# @joinery-test
# name: agent_job_marker
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# The agent and install_agent.sh have to agree about one file, and neither can
# see the other's source.
#
# When a management node dispatches an upgrade to a node's agent, the agent runs
# upgrade.php, upgrade.php runs the host installers, and the host installers run
# install_agent.sh — whose job is to converge the agent binary and restart the
# agent onto it. That restart kills the process running the job before it can
# report, so the plane sees a claim that never came back, requeues it, and the
# node upgrades a second time having already succeeded.
#
# The agent therefore writes /etc/joinery-agent/job-running for exactly as long
# as it holds the job lock, and install_agent.sh defers its swap and its restart
# while that file names a live agent. This gate proves the three things that
# contract depends on:
#
#   1. Both sides name the same path. Nothing else connects them.
#   2. The reader needs NO ENVIRONMENT. upgrade.php runs the host installers
#      through `sudo -n` on any node whose deploy user is not root, and sudo
#      strips the environment — which is exactly how that call site already
#      loses PGPASSWORD. An env var would have been the natural signal here and
#      would have failed silently on every bare-metal node.
#   3. A stale marker cannot wedge a node. An agent killed mid-job leaves one
#      naming a pid that is gone; that must read as "no job", clear itself, and
#      let the installer converge normally.
#
# The reader function is EXTRACTED FROM install_agent.sh rather than copied
# here, so this exercises the code that ships. install_agent.sh itself is NEVER
# executed by this gate — it writes to /usr/local/bin, /etc/cron.d and
# /etc/systemd on the machine running it.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE="$(dirname "$ROOT")"
INSTALLER="${SITE}/maintenance_scripts/install_tools/install_agent.sh"
AGENT_SRC="${HOME}/joinery-agent/jobmarker.go"

T=$(mktemp -d)
trap 'rm -rf "$T"; [ -n "${FAKE_PID:-}" ] && kill "$FAKE_PID" 2>/dev/null' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "== the installer is where we think it is =="
chk "install_agent.sh found" "$([ -f "$INSTALLER" ] && echo yes || echo no)" "yes"

# ---------------------------------------------------------------------------
echo "== both sides name the same marker file =="
# The only thing tying the agent to this script. A rename on one side alone
# means the installer defers to a marker nobody writes, or restarts the agent
# mid-job forever — and both fail silently.
SH_PATH="$(grep -E '^JOB_MARKER_FILE=' "$INSTALLER" | head -1 | sed 's/.*="//; s/"$//; s|\${ENV_DIR}|/etc/joinery-agent|')"
chk "install_agent.sh names /etc/joinery-agent/job-running" "$SH_PATH" "/etc/joinery-agent/job-running"

if [ -f "$AGENT_SRC" ]; then
    GO_PATH="$(grep -E '^var jobMarkerPath' "$AGENT_SRC" | head -1 | sed 's/.*"\(.*\)".*/\1/')"
    chk "the agent writes the same path" "$GO_PATH" "$SH_PATH"
else
    echo "  SKIP: agent source not on this machine (${AGENT_SRC})"
fi

# ---------------------------------------------------------------------------
echo "== the shipped reader extracts and parses =="
{
    echo '#!/bin/bash'
    echo 'set -u'
    echo 'say() { echo "agent installer: $*"; }'
    echo 'JOB_MARKER_FILE="$1"'
    awk '/^agent_job_in_progress\(\) \{/{f=1} f{print} f&&/^\}$/{exit}' "$INSTALLER"
    echo 'if agent_job_in_progress; then echo "BUSY job=${AGENT_JOB_ID}"; else echo "FREE"; fi'
} > "$T/reader.sh"
chk "reader function found in install_agent.sh" \
    "$(grep -c 'agent_job_in_progress()' "$T/reader.sh")" "1"
bash -n "$T/reader.sh" >/dev/null 2>&1
chk "extracted reader parses" "$?" "0"
chmod +x "$T/reader.sh"

MARKER="$T/job-running"

# ---------------------------------------------------------------------------
echo "== no marker means no job =="
chk "absent marker reads FREE" "$("$T/reader.sh" "$MARKER")" "FREE"

# ---------------------------------------------------------------------------
echo "== a marker naming a live agent defers the restart =="
# A real process whose /proc/PID/comm is exactly "joinery-agent" — the check the
# reader makes. comm is the executable's basename, so a copy under that name is
# indistinguishable from the real thing to this test's purpose.
cp "$(command -v sleep)" "$T/joinery-agent"
"$T/joinery-agent" 60 &
FAKE_PID=$!
sleep 0.2
chk "the stand-in reports as joinery-agent" "$(cat /proc/$FAKE_PID/comm 2>/dev/null)" "joinery-agent"

printf '%s\n%s\n%s\n' "$FAKE_PID" "7777" "2026-08-28T12:00:00Z" > "$MARKER"
chk "live marker reads BUSY with its job id" "$("$T/reader.sh" "$MARKER")" "BUSY job=7777"
chk "a live marker is left alone" "$([ -f "$MARKER" ] && echo kept || echo removed)" "kept"

# ---------------------------------------------------------------------------
echo "== the signal survives an empty environment =="
# THE POINT OF USING A FILE. upgrade.php runs the host installers through
# `sudo -n`, which strips the environment; an env var would arrive on container
# nodes (already root, no sudo) and vanish on bare metal, which is the worst
# possible split — it would work everywhere it was tested.
chk "env -i still reads BUSY" "$(env -i /bin/bash "$T/reader.sh" "$MARKER")" "BUSY job=7777"

# And through a sudo-shaped invocation, if this box can sudo without a password.
if sudo -n true 2>/dev/null; then
    chk "through sudo -n still reads BUSY" \
        "$(sudo -n /bin/bash "$T/reader.sh" "$MARKER")" "BUSY job=7777"
else
    echo "  SKIP: no passwordless sudo on this box; env -i covers the same stripping"
fi

# ---------------------------------------------------------------------------
echo "== a stale marker cannot wedge the node =="
kill "$FAKE_PID" 2>/dev/null
wait "$FAKE_PID" 2>/dev/null
FAKE_PID=""

printf '%s\n%s\n%s\n' "999999" "7777" "2026-08-28T12:00:00Z" > "$MARKER"
OUT="$("$T/reader.sh" "$MARKER")"
chk "a marker naming a dead pid reads FREE" "$(echo "$OUT" | tail -1)" "FREE"
chk "and clears itself" "$([ -f "$MARKER" ] && echo kept || echo removed)" "removed"

echo "== a recycled pid running something else reads FREE =="
# The pid is alive but is not an agent. Trusting liveness alone would let any
# process inheriting that number suppress agent restarts indefinitely.
printf '%s\n%s\n%s\n' "$$" "7777" "2026-08-28T12:00:00Z" > "$MARKER"
chk "a live non-agent pid reads FREE" "$("$T/reader.sh" "$MARKER" | tail -1)" "FREE"
chk "and clears itself" "$([ -f "$MARKER" ] && echo kept || echo removed)" "removed"

echo "== a garbage marker reads FREE rather than failing =="
printf 'not-a-pid\n' > "$MARKER"
chk "unparseable marker reads FREE" "$("$T/reader.sh" "$MARKER" | tail -1)" "FREE"

# ---------------------------------------------------------------------------
echo "== the deferral covers the binary swap as well as the restart =="
# Not a style point. The agent's updater takes its rollback backup by copying
# whatever is at the install path; if install_agent.sh had already written the
# new binary there, the .bak would be a copy of the NEW version and the
# watchdog's rollback would restore the thing it was rolling back from.
chk "converge_binary is guarded by the deferral" \
    "$(awk '/^DEFER_TO_AGENT=0/{f=1} f&&/converge_binary \|\| true/{print "guarded"; exit}' "$INSTALLER")" \
    "guarded"
chk "the deferral branch skips converge_binary" \
    "$(awk '/^if \[ "\$DEFER_TO_AGENT" = "1" \]; then/{f=1} f&&/converge_binary/{print "leaked"; exit} f&&/^else$/{print "skipped"; exit}' "$INSTALLER")" \
    "skipped"

echo "== the deferral exits before start_agent, and says so =="
DEFER_LINE="$(grep -n 'restart deferred to agent' "$INSTALLER" | head -1 | cut -d: -f1)"
START_LINE="$(grep -n '^start_agent$' "$INSTALLER" | head -1 | cut -d: -f1)"
chk "the deferral message exists" "$([ -n "$DEFER_LINE" ] && echo yes || echo no)" "yes"
chk "it comes before the unconditional start" \
    "$([ -n "$DEFER_LINE" ] && [ -n "$START_LINE" ] && [ "$DEFER_LINE" -lt "$START_LINE" ] && echo yes || echo no)" "yes"

echo
echo "passed=$passed failed=$failed"
[ "$failed" -eq 0 ] || exit 1
