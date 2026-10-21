#!/bin/bash
# @joinery-test
# name: agent_keepalive_start_guard
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/install_agent.sh]
#
# The cron keepalive install_agent.sh writes for a machine without systemd (a
# site container) starts the agent when it is not running. Its start guard
# (restart audit H2): while a self-update is on trial (.pending) with the
# previous binary kept (.bak), a new binary that cannot answer --version is
# replaced by the previous one before the launch, and its version is marked
# rejected. The gate runs the keepalive exactly as written, with its paths
# pointed at a temporary directory and pgrep answering "not running".

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
INSTALLER="$ROOT/maintenance_scripts/install_tools/install_agent.sh"
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

# The keepalive, as install_agent.sh writes it, with every path moved under $T.
sed -n "/cat > \"\$SUPERVISE_PATH\" <<'SUPERVISE'/,/^SUPERVISE\$/p" "$INSTALLER" | sed '1d;$d' \
    | sed -e "s#/usr/local/bin/joinery-agent#$T/bin/joinery-agent#g" \
          -e "s#/etc/joinery-agent/#$T/etc/#g" \
          -e "s#/var/log/joinery-agent.log#$T/agent.log#g" > "$T/keepalive.sh"
chk "the keepalive was found in the installer, guard and all" "$(grep -c "start guard" "$T/keepalive.sh" | grep -c -v "^0$")" "1"

mkdir -p "$T/bin" "$T/etc" "$T/stub"
printf '#!/bin/sh\nexit 1\n' > "$T/stub/pgrep"
chmod +x "$T/stub/pgrep"

# A launched agent records which binary ran, then exits.
setup() {
    rm -f "$T/bin/"* "$T/launched"
    printf '#!/bin/sh\n[ "${1:-}" = --version ] && echo "joinery-agent 1.0.0" && exit 0\necho old >> %s/launched\n' "$T" > "$T/bin/joinery-agent.bak"
    printf '%b' "$1" > "$T/bin/joinery-agent"
    chmod 755 "$T/bin/joinery-agent" "$T/bin/joinery-agent.bak"
}
run_keepalive() {
    PATH="$T/stub:$PATH" sh "$T/keepalive.sh"
    for _ in $(seq 1 50); do [ -f "$T/launched" ] && break; sleep 0.1; done
}

echo "=== A new binary that cannot start is replaced before the launch ==="
setup '#!/bin/sh\nexit 2\n'
printf '2.0.0\n1\n' > "$T/bin/joinery-agent.pending"
run_keepalive
chk "the previous binary was launched" "$(cat "$T/launched" 2>/dev/null)" "old"
chk "the new version is marked rejected" "$(cat "$T/bin/joinery-agent.rejected" 2>/dev/null)" "2.0.0"
chk "the trial marker is gone" "$([ -f "$T/bin/joinery-agent.pending" ] && echo present || echo gone)" "gone"
chk "the log says why" "$(grep -c 'start guard' "$T/agent.log" 2>/dev/null)" "1"

echo "=== A working new binary on trial is launched as it is ==="
setup '#!/bin/sh\n[ "${1:-}" = --version ] && echo "joinery-agent 2.0.0" && exit 0\necho new >> '"$T"'/launched\n'
printf '2.0.0\n1\n' > "$T/bin/joinery-agent.pending"
run_keepalive
chk "the new binary was launched" "$(cat "$T/launched" 2>/dev/null)" "new"
chk "the trial marker is left for the agent" "$([ -f "$T/bin/joinery-agent.pending" ] && echo present || echo gone)" "present"
chk "nothing is rejected" "$([ -f "$T/bin/joinery-agent.rejected" ] && echo present || echo none)" "none"

echo "=== With no trial, nothing is replaced ==="
setup '#!/bin/sh\necho new >> '"$T"'/launched\n'
run_keepalive
chk "the installed binary was launched" "$(cat "$T/launched" 2>/dev/null)" "new"
chk "the backup is untouched" "$([ -f "$T/bin/joinery-agent.bak" ] && echo present || echo gone)" "present"

echo
echo "agent_keepalive_start_guard gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
