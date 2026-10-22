#!/bin/bash
# @joinery-test
# name: agent_keepalive_liveness
# tier: safe
# env: any
# needs: []
# timeout: 90
# covers: [maintenance_scripts/install_tools/install_agent.sh]
#
# The cron keepalive install_agent.sh writes for a machine without systemd (a
# site container) restarts a HUNG agent, not only a dead one (restart audit
# H1). A running agent writes the time to /run/joinery-agent/alive every half
# minute while it is alive (agent 1.89.0, liveness.go). One running for more
# than three minutes whose file is older than three minutes is aborted and
# started again; a fresh file, a just-started agent and a missing file (an
# agent too old to write one) are left alone. The gate runs the keepalive
# exactly as written, with its paths under a temporary directory, a sleeping
# process standing in for the agent, and pgrep and ps answering for it.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
INSTALLER="$ROOT/maintenance_scripts/install_tools/install_agent.sh"
T=$(mktemp -d)
AGENT_PID=""
trap '[ -n "$AGENT_PID" ] && kill -9 "$AGENT_PID" 2>/dev/null; rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

sed -n "/cat > \"\$SUPERVISE_PATH\" <<'SUPERVISE'/,/^SUPERVISE\$/p" "$INSTALLER" | sed '1d;$d' \
    | sed -e "s#/usr/local/bin/joinery-agent#$T/bin/joinery-agent#g" \
          -e "s#/etc/joinery-agent/#$T/etc/#g" \
          -e "s#/run/joinery-agent/alive#$T/run/alive#g" \
          -e "s#/var/log/joinery-agent.log#$T/agent.log#g" > "$T/keepalive.sh"
chk "the keepalive carries the liveness check" "$(grep -c "$T/run/alive" "$T/keepalive.sh" | grep -c -v '^0$')" "1"

mkdir -p "$T/bin" "$T/etc" "$T/stub" "$T/run"
# pgrep answers for the stand-in agent while it lives; ps gives its uptime.
cat > "$T/stub/pgrep" <<STUB
#!/bin/sh
p="\$(cat $T/agent.pid 2>/dev/null)"
[ -n "\$p" ] && kill -0 "\$p" 2>/dev/null || exit 1
echo "\$p"
STUB
cat > "$T/stub/ps" <<STUB
#!/bin/sh
cat $T/uptime
STUB
chmod +x "$T/stub/pgrep" "$T/stub/ps"
printf '#!/bin/sh\necho launched >> %s/launched\n' "$T" > "$T/bin/joinery-agent"
chmod 755 "$T/bin/joinery-agent"

# $1 the alive file's age in seconds ("" for no file), $2 the agent's uptime.
run_case() {
    rm -f "$T/launched" "$T/agent.log" "$T/run/alive"
    # Not a child of this shell: an aborted child would linger as a zombie,
    # and a zombie still answers kill -0. The subshell exits and init reaps it.
    ( sleep 300 >/dev/null 2>&1 & echo $! > "$T/agent.pid" )
    AGENT_PID="$(cat "$T/agent.pid")"
    echo "$2" > "$T/uptime"
    [ -n "$1" ] && touch -d "@$(( $(date +%s) - $1 ))" "$T/run/alive"
    PATH="$T/stub:$PATH" sh "$T/keepalive.sh"
    for _ in $(seq 1 20); do [ -f "$T/launched" ] && break; sleep 0.1; done
}
alive() { kill -0 "$AGENT_PID" 2>/dev/null && echo running || echo stopped; }

echo "=== A hung agent: its file stale for ten minutes, up for an hour ==="
run_case 600 3600
chk "it was stopped" "$(alive)" "stopped"
chk "a new agent was launched" "$(cat "$T/launched" 2>/dev/null)" "launched"
chk "the log says why" "$(grep -c 'has not said it is alive for' "$T/agent.log" 2>/dev/null)" "1"

echo "=== A live agent: its file written a few seconds ago ==="
run_case 20 3600
chk "it is left running" "$(alive)" "running"
chk "nothing was launched" "$([ -f "$T/launched" ] && echo launched || echo none)" "none"
kill -9 "$AGENT_PID" 2>/dev/null

echo "=== An agent that has only just started, with a stale file from before ==="
run_case 600 60
chk "it is left running" "$(alive)" "running"
kill -9 "$AGENT_PID" 2>/dev/null

echo "=== An agent that writes no file at all (older than the watch) ==="
run_case "" 3600
chk "it is left running" "$(alive)" "running"
chk "nothing was launched" "$([ -f "$T/launched" ] && echo launched || echo none)" "none"
kill -9 "$AGENT_PID" 2>/dev/null

echo
echo "agent_keepalive_liveness gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
