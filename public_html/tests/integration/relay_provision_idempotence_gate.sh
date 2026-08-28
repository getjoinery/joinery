#!/bin/bash
# @joinery-test
# name: relay_provision_idempotence
# tier: safe
# env: any
# needs: []
# timeout: 90
#
# provision_relay.sh is the reconciler behind relay_converge
# (specs/agent_machine_posture_and_relay_converge.md §5). A converge runs on a
# schedule, so a run that finds the relay already in its intended state must
# change nothing and restart nothing. Three of §5.4's defects are the kind that
# come back quietly, and this gate pins them:
#
#   1. RESTART-ONLY-ON-CHANGE. Every service action used to be unconditional, so
#      every converge dropped mail acceptance, DKIM/DMARC verification, content
#      scanning and every tenant's tunnel — for nothing. A returning `systemctl
#      restart postfix` would not fail any test that only checks the end state,
#      because the end state is identical. It has to be caught here.
#
#   2. PEER CONVERGE. wg0.conf's [Peer] list is derived from the tenant
#      registry, never appended to. Appending could only add: a tenant that
#      rotated its key left its old stanza behind for ever, and two stanzas
#      claiming one tunnel address is a routing coin toss.
#
#   3. THE FIREWALL IS CONVERGED, NOT RESET. `ufw --force reset` wiped every
#      rule each run, including the rebuild flow's own `ufw deny 25/tcp` — so
#      re-running provisioning mid-rebuild re-opened port 25 while the spool was
#      carried aside and Postfix was stopped.
#
# The functions are EXTRACTED FROM the shipped script rather than copied, so
# this exercises the code that ships. `ufw`, `systemctl`, `wg` and `wg-quick`
# are stubbed onto PATH: nothing here touches this machine's firewall, services
# or tunnels, and the real script is never executed.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT}/plugins/mailbox/provisioning/provision_relay.sh"

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

# Pull one shell function out of the script by name, from its `name() {` line to
# the first column-zero `}`.
extract() {
    awk -v fn="$1" 'index($0, fn "() {") == 1 {f=1} f{print} f && /^\}$/{exit}' "$SCRIPT"
}

echo "== the script is where we think it is, and parses =="
chk "provision_relay.sh found" "$([ -f "$SCRIPT" ] && echo yes || echo no)" "yes"
bash -n "$SCRIPT" >/dev/null 2>&1
chk "provision_relay.sh parses" "$?" "0"

# ---------------------------------------------------------------------------
echo "== the six defects are gone from the source =="
# Text assertions, because each of these is a single line that would silently
# restore the old behaviour if it came back in a merge.
chk "no 'ufw --force reset'" \
    "$(grep -c "^ufw --force reset" "$SCRIPT")" "0"
chk "no 'go build' on the relay" \
    "$(grep -c "go build" "$SCRIPT")" "0"
chk "no golang-go package" \
    "$(grep -c "golang-go" "$SCRIPT")" "0"
chk "no unconditional systemctl restart at column 0" \
    "$(grep -cE "^systemctl (restart|reload) " "$SCRIPT")" "0"
chk "dpkg-reconfigure is guarded (not at column 0)" \
    "$(grep -cE "^dpkg-reconfigure " "$SCRIPT")" "0"
chk "no in-place sed of /etc/default files" \
    "$(grep -c "sed -i" "$SCRIPT")" "0"
chk "the prebuilt sealer is named by uname -m" \
    "$(grep -c 'SEALER_CANDIDATES+=("\${SCRIPT_DIR}/bin/relay-sealer-\${SEALER_MACHINE}")' "$SCRIPT")" "1"
chk "an absent sealer is a hard failure" \
    "$(grep -c 'no prebuilt relay-sealer binary was delivered' "$SCRIPT")" "1"

# ---------------------------------------------------------------------------
# Stubs. Each records its argv so a test can assert on what WOULD have been done
# to a real relay.
mkdir -p "$T/bin"
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
echo "systemctl $*" >> "$STUB_LOG"
case "$1" in
    is-active) echo "${FAKE_ACTIVE:-active}"; exit 0;;
esac
exit 0
STUB
cat > "$T/bin/ufw" <<'STUB'
#!/bin/bash
echo "ufw $*" >> "$STUB_LOG"
case "$1 ${2:-}" in
    "status verbose") cat "$FAKE_UFW_VERBOSE"; exit 0;;
    "status "*|"status") cat "$FAKE_UFW_RULES"; exit 0;;
esac
exit 0
STUB
cat > "$T/bin/wg" <<'STUB'
#!/bin/bash
echo "wg $*" >> "$STUB_LOG"
[ "$1" = "show" ] && exit "${FAKE_WG_UP:-0}"
exit 0
STUB
cat > "$T/bin/wg-quick" <<'STUB'
#!/bin/bash
echo "wg-quick $*" >> "$STUB_LOG"
exit 0
STUB
chmod 755 "$T/bin"/*

# ---------------------------------------------------------------------------
echo "== restart-only-on-change =="
{
    echo '#!/bin/bash'
    echo 'set -u'
    echo 'CHANGED_UNITS=""'
    extract mark_changed
    extract changed
    extract sync_service
    echo 'for u in $UNITS_TO_MARK; do mark_changed "$u"; done'
    echo 'sync_service "$1" "${2:-restart}"'
} > "$T/svc.sh"
chk "sync_service extracted" "$(grep -c '^sync_service() {' "$T/svc.sh")" "1"
bash -n "$T/svc.sh" >/dev/null 2>&1
chk "  and parses on its own" "$?" "0"

run_svc() { # <units-marked> <unit> <mode> <fake-active>
    : > "$T/log"
    STUB_LOG="$T/log" UNITS_TO_MARK="$1" FAKE_ACTIVE="$4" \
        PATH="$T/bin:$PATH" bash "$T/svc.sh" "$2" "$3" >/dev/null 2>&1
    grep -cE "systemctl (restart|reload|start) $2" "$T/log"
}

chk "an unchanged, running unit is left alone"      "$(run_svc ''        postfix reload  active)"   "0"
chk "a changed, running unit is acted on once"      "$(run_svc 'postfix' postfix reload  active)"   "1"
chk "an unrelated change does not touch this unit"  "$(run_svc 'rspamd'  postfix reload  active)"   "0"
chk "a dead unit is started even with no change"    "$(run_svc ''        postfix restart inactive)" "1"

: > "$T/log"
STUB_LOG="$T/log" UNITS_TO_MARK="rspamd" FAKE_ACTIVE=active \
    PATH="$T/bin:$PATH" bash "$T/svc.sh" rspamd reload >/dev/null 2>&1
chk "a reload-capable unit is reloaded, not restarted" \
    "$(grep -c 'systemctl reload rspamd' "$T/log")" "1"
chk "  and is not also restarted" \
    "$(grep -c 'systemctl restart rspamd' "$T/log")" "0"

# The whole point: six services, nothing changed, nothing touched.
: > "$T/log"
for u in postfix opendkim opendmarc rspamd "wg-quick@wg0" joinery-direct; do
    STUB_LOG="$T/log" UNITS_TO_MARK="" FAKE_ACTIVE=active \
        PATH="$T/bin:$PATH" bash "$T/svc.sh" "$u" restart >/dev/null 2>&1
done
chk "a no-change converge restarts NOTHING" \
    "$(grep -cE 'systemctl (restart|reload|start)' "$T/log")" "0"

# ---------------------------------------------------------------------------
echo "== the WireGuard peer list is converged, not appended =="
{
    echo '#!/bin/bash'
    echo 'set -u'
    echo 'WG_IF=wg0'
    echo "SLUG_RE='^[a-z0-9][a-z0-9-]{0,27}\$'"
    echo 'TENANTS_DIR="$T_REG"'
    echo 'CHANGED_UNITS=""'
    extract mark_changed
    extract changed
    extract converge_wg_peers
    # /etc/wireguard is not writable here, so the function is pointed at a
    # temporary tree by overriding the one path it composes.
    echo 'converge_wg_peers'
    echo 'echo "RC=$?"'
} > "$T/peers.sh"
# The function composes /etc/wireguard/${WG_IF}.conf; redirect it by making
# WG_IF an absolute-ish path is not possible, so rewrite that one literal.
sed -i "s#/etc/wireguard/\${WG_IF}.conf#\${T_CONF}#" "$T/peers.sh"
chk "converge_wg_peers extracted" "$(grep -c '^converge_wg_peers() {' "$T/peers.sh")" "1"
bash -n "$T/peers.sh" >/dev/null 2>&1
chk "  and parses on its own" "$?" "0"

mkdir -p "$T/reg"
INTERFACE='# joinery-managed
[Interface]
Address = 10.99.0.1/24
ListenPort = 51820
PrivateKey = SECRETKEYSECRETKEYSECRETKEYSECRETKEYSECRET='
tenant() { mkdir -p "$T/reg/$1"; printf '%s\n' "$2" > "$T/reg/$1/wg_pubkey"; printf '%s\n' "$3" > "$T/reg/$1/tunnel_ip"; }
run_peers() { T_REG="$T/reg" T_CONF="$T/wg0.conf" PATH="$T/bin:$PATH" bash "$T/peers.sh" 2>/dev/null | tail -1; }

printf '%s\n' "$INTERFACE" > "$T/wg0.conf"
tenant main KEY_MAIN_AAAA 10.99.0.2
tenant other KEY_OTHER_BBB 10.99.0.3
rc=$(run_peers)
chk "first converge writes the peers" "$rc" "RC=0"
chk "  two [Peer] stanzas" "$(grep -c '^\[Peer\]' "$T/wg0.conf")" "2"
chk "  the private key survived" "$(grep -c 'PrivateKey = SECRETKEY' "$T/wg0.conf")" "1"

rc=$(run_peers)
chk "a second converge changes nothing" "$rc" "RC=1"
chk "  still two stanzas" "$(grep -c '^\[Peer\]' "$T/wg0.conf")" "2"

# THE ROTATION CASE. The old append-if-absent left the previous key behind, so
# two peers claimed 10.99.0.2 and the tunnel picked one.
tenant main KEY_MAIN_ROTATED 10.99.0.2
rc=$(run_peers)
chk "a rotated key is a change" "$rc" "RC=0"
chk "  the new key is present" "$(grep -c 'PublicKey = KEY_MAIN_ROTATED' "$T/wg0.conf")" "1"
chk "  THE OLD KEY IS GONE" "$(grep -c 'KEY_MAIN_AAAA' "$T/wg0.conf")" "0"
chk "  still exactly two stanzas" "$(grep -c '^\[Peer\]' "$T/wg0.conf")" "2"
chk "  and one claim on 10.99.0.2" "$(grep -c 'AllowedIPs = 10.99.0.2/32' "$T/wg0.conf")" "1"

# Removal.
rm -rf "$T/reg/other"
rc=$(run_peers)
chk "a removed tenant is a change" "$rc" "RC=0"
chk "  its stanza is gone" "$(grep -c 'KEY_OTHER_BBB' "$T/wg0.conf")" "0"
chk "  one stanza remains" "$(grep -c '^\[Peer\]' "$T/wg0.conf")" "1"

# A tenant with no WireGuard key at all is not a peer, and must not become one
# with an empty PublicKey line.
mkdir -p "$T/reg/nokey"; printf '10.99.0.9\n' > "$T/reg/nokey/tunnel_ip"
run_peers >/dev/null
chk "a tenant with no wg key yields no stanza" "$(grep -c '^\[Peer\]' "$T/wg0.conf")" "1"
chk "  and no empty PublicKey line" "$(grep -c 'PublicKey = *$' "$T/wg0.conf")" "0"

# ---------------------------------------------------------------------------
echo "== the firewall is converged, never reset =="
{
    echo '#!/bin/bash'
    echo 'set -u'
    echo 'WG_IF=wg0'
    echo 'WG_PORT=51820'
    extract ufw_allow_once
    extract converge_firewall
    echo 'converge_firewall'
} > "$T/fw.sh"
chk "converge_firewall extracted" "$(grep -c '^converge_firewall() {' "$T/fw.sh")" "1"
bash -n "$T/fw.sh" >/dev/null 2>&1
chk "  and parses on its own" "$?" "0"

run_fw() { # <verbose-file> <rules-file>
    : > "$T/log"
    STUB_LOG="$T/log" FAKE_UFW_VERBOSE="$1" FAKE_UFW_RULES="$2" \
        PATH="$T/bin:$PATH" bash "$T/fw.sh" >/dev/null 2>&1
}

# A fresh box: inactive, no rules.
cat > "$T/v_fresh" <<'EOF'
Status: inactive
EOF
: > "$T/r_fresh"
run_fw "$T/v_fresh" "$T/r_fresh"
chk "fresh box: never resets"        "$(grep -c 'ufw --force reset' "$T/log")" "0"
chk "fresh box: opens 25/tcp"        "$(grep -c 'ufw allow 25/tcp' "$T/log")" "1"
chk "fresh box: opens 443/tcp"       "$(grep -c 'ufw allow 443/tcp' "$T/log")" "1"
chk "fresh box: opens 51820/udp"     "$(grep -c 'ufw allow 51820/udp' "$T/log")" "1"
chk "fresh box: opens 22/tcp"        "$(grep -c 'ufw allow 22/tcp' "$T/log")" "1"
chk "fresh box: tunnel egress rule"  "$(grep -c 'ufw allow in on wg0 to any port 8442 proto tcp' "$T/log")" "1"
chk "fresh box: enables the firewall" "$(grep -c 'ufw --force enable' "$T/log")" "1"

# A converged box: everything already in place. The whole run must be reads.
cat > "$T/v_ok" <<'EOF'
Status: active
Default: deny (incoming), allow (outgoing), disabled (routed)
EOF
cat > "$T/r_ok" <<'EOF'
Status: active

To                         Action      From
--                         ------      ----
25/tcp                     ALLOW       Anywhere
51820/udp                  ALLOW       Anywhere
22/tcp                     ALLOW       Anywhere
443/tcp                    ALLOW       Anywhere
8442/tcp on wg0            ALLOW IN    Anywhere
EOF
run_fw "$T/v_ok" "$T/r_ok"
chk "converged box: no rule is re-added" \
    "$(grep -cE 'ufw (allow|default)' "$T/log")" "0"
chk "converged box: not re-enabled" \
    "$(grep -c 'ufw --force enable' "$T/log")" "0"
chk "converged box: only status reads" \
    "$(grep -cv 'ufw status' "$T/log")" "0"

# THE REBUILD WINDOW. build_rebuild_relay closes 25, carries the spool aside and
# re-runs provisioning. Re-opening 25 here would let mail in while Postfix is
# stopped and the spool is somewhere else.
cat > "$T/r_deny25" <<'EOF'
Status: active

To                         Action      From
--                         ------      ----
25/tcp                     DENY        Anywhere
51820/udp                  ALLOW       Anywhere
22/tcp                     ALLOW       Anywhere
443/tcp                    ALLOW       Anywhere
8442/tcp on wg0            ALLOW IN    Anywhere
EOF
run_fw "$T/v_ok" "$T/r_deny25"
chk "rebuild window: 25/tcp is NOT re-opened" \
    "$(grep -c 'ufw allow 25/tcp' "$T/log")" "0"
chk "rebuild window: the deny is not wiped by a reset" \
    "$(grep -c 'ufw --force reset' "$T/log")" "0"
chk "rebuild window: the other rules are still left alone" \
    "$(grep -cE 'ufw allow' "$T/log")" "0"

# A partially-configured box adds only what is missing.
cat > "$T/r_partial" <<'EOF'
Status: active

To                         Action      From
--                         ------      ----
25/tcp                     ALLOW       Anywhere
22/tcp                     ALLOW       Anywhere
EOF
run_fw "$T/v_ok" "$T/r_partial"
chk "partial box: 25/tcp not re-added"  "$(grep -c 'ufw allow 25/tcp' "$T/log")" "0"
chk "partial box: 443/tcp added"        "$(grep -c 'ufw allow 443/tcp' "$T/log")" "1"
chk "partial box: 51820/udp added"      "$(grep -c 'ufw allow 51820/udp' "$T/log")" "1"
chk "partial box: 8442 rule added"      "$(grep -c 'port 8442' "$T/log")" "1"

# A box whose defaults drifted open.
cat > "$T/v_open" <<'EOF'
Status: active
Default: allow (incoming), allow (outgoing), disabled (routed)
EOF
run_fw "$T/v_open" "$T/r_ok"
chk "drifted default: incoming set back to deny" \
    "$(grep -c 'ufw default deny incoming' "$T/log")" "1"

echo
echo "passed=$passed failed=$failed"
[ "$failed" -eq 0 ] || exit 1
