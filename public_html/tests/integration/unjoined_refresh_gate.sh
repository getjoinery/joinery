#!/bin/bash
# @joinery-test
# name: unjoined_refresh
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/sysadmin_tools/unjoined_refresh.sh]
#
# An unjoined machine keeps its agent and support bundle at the current
# release (specs/security_fixes_on_relay_and_dns.md WP5): the daily run asks
# which release is current, takes a newer one's agent_dist by rename into the
# folder the agent watches, and installs its bundle; never an older release,
# never on the mail relay, and all of it undone once the machine joins.
# Driven unprivileged under UNJOINED_REFRESH_ROOT with stand-ins for curl,
# systemctl and joinery-agent.
#
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/unjoined_refresh.sh"
if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged; as root the fixture root is ignored"
    echo "RESULT: 0 passed, 0 failed"; exit 0
fi
T="$(mktemp -d /var/tmp/unjoined-refresh-gate.XXXXXX)"
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}

R="$T/root"; S="$T/s"; mkdir -p "$R" "$S/rel" "$T/bin"
export UNJOINED_REFRESH_ROOT="$R" S UPGRADE_SERVER=https://upgrade.invalid

# A release archive with an agent_dist, by version.
mkrel() {  # VERSION
    local d="$T/build/$1/public_html/agent_dist"
    mkdir -p "$d"
    printf '{"version":"agent-of-%s"}\n' "$1" > "$d/manifest.json"
    printf 'bundle %s\n' "$1" > "$d/support_bundle.tar.gz"
    printf 'statement %s\n' "$1" > "$d/RELEASE_STATEMENT"
    tar -C "$T/build/$1" -czf "$S/rel/joinery-core-$1.tar.gz" ./public_html
}
# curl: the header ask answers with $S/current; a download copies the archive by name.
cat > "$T/bin/curl" <<'EOF'
#!/bin/bash
echo "$*" >> "$S/curl_calls"
out=""; url="${@: -1}"; head=0
while [ $# -gt 0 ]; do case "$1" in -o) out="$2"; shift ;; -D) head=1; shift ;; esac; shift; done
if [ "$head" = 1 ]; then
    [ -f "$S/current" ] || exit 7
    printf 'HTTP/2 302\r\nx-joinery-version: %s\r\n\r\n' "$(cat "$S/current")"; exit 0
fi
f="$S/rel/$(basename "$url")"
[ -f "$f" ] || exit 22
cp "$f" "$out"
EOF
cat > "$T/bin/systemctl" <<'EOF'
#!/bin/bash
echo "$*" >> "$S/systemctl_calls"
case "$1" in is-enabled|is-active) [ -f "$S/enabled" ]; exit $? ;; enable) touch "$S/enabled" ;; disable) rm -f "$S/enabled" ;; esac
exit 0
EOF
cat > "$T/bin/joinery-agent" <<'EOF'
#!/bin/bash
echo "$*" >> "$S/agent_calls"
[ -f "$S/bundle_fails" ] && { echo "refused: older"; exit 1; }
exit 0
EOF
chmod 755 "$T/bin/"*
export PATH="$T/bin:$PATH"
run() { bash "$SCRIPT" "$@" > "$T/out" 2>&1; echo $?; }
DIST="$R/var/lib/joinery-agent/dist"
ENVF="$R/etc/joinery-agent/joinery-agent.env"

echo "install"
mkdir -p "$R/etc/joinery-agent"; printf 'JOINERY_CONFIG=\nAGENT_DIST_DIR=/somewhere/else\n' > "$ENVF"
chk "install succeeds" "$(run install)" "0"
chk "the service and the daily timer are written" "$(ls "$R/etc/systemd/system" | paste -sd ' ')" "joinery-unjoined-refresh.service joinery-unjoined-refresh.timer"
chk "the timer is daily" "$(grep -c '^OnCalendar=daily$' "$R/etc/systemd/system/joinery-unjoined-refresh.timer")" "1"
chk "the agent is pointed at the folder, once, replacing another" "$(grep -c '^AGENT_DIST_DIR=' "$ENVF")|$(grep -c '^AGENT_DIST_DIR=/var/lib/joinery-agent/dist$' "$ENVF")|$(grep -c '^JOINERY_CONFIG=' "$ENVF")" "1|1|1"
chk "and restarted to read it" "$(grep -c '^restart joinery-agent' "$S/systemctl_calls")" "1"
chk "a second install changes nothing" "$( { run install; grep -c '^restart joinery-agent' "$S/systemctl_calls"; grep -c 'is current' "$T/out"; } | tr -d '\n')" "011"

echo "run: a newer release"
mkrel 0.8.675; echo 0.8.675 > "$S/current"
chk "run succeeds" "$(run run)" "0"
chk "its agent_dist is in place" "$(cat "$DIST/manifest.json")" '{"version":"agent-of-0.8.675"}'
chk "the release is recorded" "$(cat "$DIST/RELEASE")" "0.8.675"
chk "downloaded by its own name" "$(grep -c 'static_files/joinery-core-0.8.675.tar.gz' "$S/curl_calls")" "1"
chk "the bundle is installed from it with its statement" "$(tail -n 1 "$S/agent_calls")" "bundle install $DIST/support_bundle.tar.gz --statement=$DIST/RELEASE_STATEMENT"
chk "no staging folder is left" "$(ls "$R/var/lib/joinery-agent" | paste -sd ' ')" "dist"

echo "run: the same release"
: > "$S/curl_calls"
chk "run succeeds" "$(run run)" "0"
chk "nothing is downloaded" "$(grep -c static_files "$S/curl_calls")" "0"
chk "says so" "$(grep -c 'release 0.8.675 is in place' "$T/out")" "1"

echo "run: an older release is never put back"
mkrel 0.8.600; echo 0.8.600 > "$S/current"
chk "run refuses" "$(run run)" "1"
chk "the folder is untouched" "$(cat "$DIST/RELEASE")" "0.8.675"
chk "says why" "$(grep -c 'older than 0.8.675' "$T/out")" "1"

echo "run: an archive with no agent_dist"
mkdir -p "$T/build/bad/x"; echo x > "$T/build/bad/x/y"; tar -C "$T/build/bad" -czf "$S/rel/joinery-core-0.8.700.tar.gz" ./x
echo 0.8.700 > "$S/current"
chk "run refuses" "$(run run)" "1"
chk "the folder is untouched" "$(cat "$DIST/RELEASE")" "0.8.675"

echo "run: an agent_dist holding a link"
mkdir -p "$T/build/0.8.701/public_html/agent_dist"
for f in manifest.json support_bundle.tar.gz RELEASE_STATEMENT; do echo x > "$T/build/0.8.701/public_html/agent_dist/$f"; done
ln -s /etc/passwd "$T/build/0.8.701/public_html/agent_dist/evil"
tar -C "$T/build/0.8.701" -czf "$S/rel/joinery-core-0.8.701.tar.gz" ./public_html
echo 0.8.701 > "$S/current"
chk "run refuses" "$(run run)" "1"
chk "the folder is untouched" "$(cat "$DIST/RELEASE")" "0.8.675"

echo "run: a server that does not say"
rm -f "$S/current"
chk "run refuses" "$(run run)" "1"

echo "the mail relay"
mkdir -p "$R/opt/joinery-relay"
mkrel 0.8.680; echo 0.8.680 > "$S/current"
chk "run refuses on the relay" "$(run run)" "1"
chk "the folder is untouched" "$(cat "$DIST/RELEASE")" "0.8.675"
chk "install refuses on the relay" "$(run install)" "1"
rmdir "$R/opt/joinery-relay"

echo "joined since"
mkdir -p "$R/etc/joinery-agent"; echo '{}' > "$R/etc/joinery-agent/node_identity.json"
: > "$S/systemctl_calls"
chk "run succeeds" "$(run run)" "0"
chk "the timer is removed" "$(ls "$R/etc/systemd/system" | grep -c unjoined)" "0"
chk "the agent is no longer pointed at the folder" "$(grep -c '^AGENT_DIST_DIR=' "$ENVF")|$(grep -c '^JOINERY_CONFIG=' "$ENVF")" "0|1"
chk "and restarted" "$(grep -c '^restart joinery-agent' "$S/systemctl_calls")" "1"
chk "nothing is downloaded" "$(grep -c 'joinery-core-0.8.680' "$S/curl_calls" 2>/dev/null || true)" "0"
chk "remove a second time changes nothing" "$(run remove | tr -d "\n"; grep -c '^restart joinery-agent' "$S/systemctl_calls")" "01"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
