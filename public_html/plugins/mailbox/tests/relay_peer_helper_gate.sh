#!/bin/bash
# @joinery-test
# name: relay_peer_helper
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# The relay tunnel carries exactly ONE peer, pinned at 10.99.0.1/32. Rebuilding
# a relay hands the main box a NEW WireGuard key for that same tunnel address,
# so joinery-relay-peer must REPLACE the peer it finds, never add beside it:
# two peers claiming one AllowedIPs address leaves WireGuard with no
# deterministic route to the relay, and an add-only helper strands a dead peer
# on every rotation.
#
# The helper under test is EXTRACTED FROM provision_relay_main.sh rather than
# copied here, so this exercises the code that actually ships to the main box.
# The only edit is the /etc/wireguard prefix, redirected into a sandbox so the
# gate needs no root.

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../provisioning" && pwd)/provision_relay_main.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/etc/wireguard" "$T/bin"

awk "/<<'HELPER'/{f=1;next} /^HELPER\$/{f=0} f" "$SRC" \
  | sed "s#/etc/wireguard#$T/etc/wireguard#g" > "$T/bin/joinery-relay-peer"
chmod 755 "$T/bin/joinery-relay-peer"
if [ ! -s "$T/bin/joinery-relay-peer" ]; then
    echo "FAIL: could not extract joinery-relay-peer from $SRC"
    echo "RESULT: FAIL 0 1"
    exit 1
fi

# Stub the interface tools: this gate is about config content, not kernel state.
for c in wg wg-quick systemctl; do
    printf '#!/bin/bash\nexit 0\n' > "$T/bin/$c"; chmod 755 "$T/bin/$c"
done
export PATH="$T/bin:$PATH"

CONF="$T/etc/wireguard/jyrelay0.conf"
K1="AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="
K2="BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB="
K3="CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC="
NEW="ZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZZ="
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
iface() { printf '[Interface]\nPrivateKey = SECRETKEYVALUE\nAddress = 10.99.0.2/24\n' > "$CONF"; chmod 600 "$CONF"; }
peer()  { printf '\n[Peer]\nPublicKey = %s\nEndpoint = %s:51820\nAllowedIPs = 10.99.0.1/32\nPersistentKeepalive = 25\n' "$1" "$2" >> "$CONF"; }
npeers(){ grep -c '^\[Peer\]' "$CONF"; }

echo "== a peerless interface gains exactly one peer =="
iface
joinery-relay-peer "$NEW" "1.2.3.4:51820" >/dev/null
chk "one peer block"       "$(npeers)" "1"
chk "it is the new key"    "$(grep -c "PublicKey = $NEW" "$CONF")" "1"
chk "interface preserved"  "$(grep -c 'PrivateKey = SECRETKEYVALUE' "$CONF")" "1"

echo "== the rotation case: a stale peer is replaced, not joined =="
iface; peer "$K1" "66.175.210.20"
joinery-relay-peer "$NEW" "5.6.7.8:51820" >/dev/null
chk "still exactly one peer" "$(npeers)" "1"
chk "stale key gone"         "$(grep -c "$K1" "$CONF")" "0"
chk "new key present"        "$(grep -c "PublicKey = $NEW" "$CONF")" "1"
chk "new endpoint present"   "$(grep -c 'Endpoint = 5.6.7.8:51820' "$CONF")" "1"
chk "interface preserved"    "$(grep -c 'Address = 10.99.0.2/24' "$CONF")" "1"

echo "== peers accumulated by an older add-only helper collapse to one =="
iface; peer "$K1" "1.1.1.1"; peer "$K2" "2.2.2.2"; peer "$K3" "3.3.3.3"
chk "fixture really had three" "$(npeers)" "3"
joinery-relay-peer "$NEW" "9.9.9.9:51820" >/dev/null
chk "collapsed to one"         "$(npeers)" "1"
chk "no stale keys remain"     "$(grep -cE "$K1|$K2|$K3" "$CONF")" "0"

echo "== re-peering the same relay is idempotent =="
iface
joinery-relay-peer "$NEW" "1.2.3.4:51820" >/dev/null
joinery-relay-peer "$NEW" "1.2.3.4:51820" >/dev/null
chk "still one peer" "$(npeers)" "1"

echo "== a moved relay keeps its key but takes the new endpoint =="
iface; peer "$NEW" "1.1.1.1"
joinery-relay-peer "$NEW" "4.4.4.4:51820" >/dev/null
chk "one peer"          "$(npeers)" "1"
chk "endpoint updated"  "$(grep -c 'Endpoint = 4.4.4.4:51820' "$CONF")" "1"
chk "old endpoint gone" "$(grep -c 'Endpoint = 1.1.1.1' "$CONF")" "0"

echo "== the config still holds a private key, so mode stays 600 =="
iface; peer "$K1" "1.1.1.1"
joinery-relay-peer "$NEW" "1.2.3.4:51820" >/dev/null
chk "mode 600" "$(stat -c '%a' "$CONF")" "600"

echo "== argument validation is unchanged =="
iface
joinery-relay-peer "not-a-key" "1.2.3.4:51820" >/dev/null 2>&1
chk "bad key rejected"      "$?" "2"
joinery-relay-peer "$NEW" "nonsense" >/dev/null 2>&1
chk "bad endpoint rejected" "$?" "2"
rm -f "$CONF"
joinery-relay-peer "$NEW" "1.2.3.4:51820" >/dev/null 2>&1
chk "missing config rejected" "$?" "3"

echo "== the installer converges helpers only where relay identity exists =="
# The helpers are installed COPIES, so a corrected provisioner that merely
# deploys leaves a stale helper on disk. install_email.sh (the declared
# host_installer, which runs on deploys) closes that gap — but must not mint
# tunnel identity on boxes that front no relay. Section 9 is extracted and
# exercised here against sandboxes, with the provisioner stubbed.
INST="$(cd "$(dirname "${BASH_SOURCE[0]}")/../provisioning" && pwd)/install_email.sh"
SEC="$T/section9.sh"
awk '/^# --- 9\. relay tunnel helpers/{f=1} /^# --- summary/{f=0} f' "$INST" > "$SEC"
if [ ! -s "$SEC" ]; then
    echo "  FAIL: could not extract section 9 from install_email.sh"; failed=$((failed+1))
fi

# $1 sandbox dir; stub provisioner records that it ran and exits with $2.
sandbox() {
    local d="$T/$1"; rm -rf "$d"; mkdir -p "$d"
    printf '#!/bin/bash\ntouch "$(dirname "$0")/RAN"\nexit %s\n' "${2:-0}" > "$d/provision_relay_main.sh"
    chmod 755 "$d/provision_relay_main.sh"
    sed "s#/etc/wireguard/jyrelay0.conf#$d/jyrelay0.conf#g; s#/usr/local/sbin/joinery-relay-peer#$d/joinery-relay-peer#g" \
        "$SEC" > "$d/run.sh"
    echo "$d"
}
run_sec() { ( set -euo pipefail; SCRIPT_DIR="$1"; . "$1/run.sh" ) 2>&1; echo "EXIT:$?"; }

d=$(sandbox nore1)
out=$(run_sec "$d")
chk "no identity -> provisioner not run"  "$([ -f "$d/RAN" ] && echo ran || echo skipped)" "skipped"
chk "no identity -> says skipping"        "$(echo "$out" | grep -c 'skipping relay helper')" "1"
chk "no identity -> succeeds"             "$(echo "$out" | grep -c 'EXIT:0')" "1"

d=$(sandbox conf1); : > "$d/jyrelay0.conf"
out=$(run_sec "$d")
chk "tunnel config present -> converged"  "$([ -f "$d/RAN" ] && echo ran || echo skipped)" "ran"
chk "converge reported"                   "$(echo "$out" | grep -c 'converged')" "1"

d=$(sandbox help1); : > "$d/joinery-relay-peer"; chmod 755 "$d/joinery-relay-peer"
out=$(run_sec "$d")
chk "existing helper alone -> converged"  "$([ -f "$d/RAN" ] && echo ran || echo skipped)" "ran"

d=$(sandbox miss1); : > "$d/jyrelay0.conf"; rm -f "$d/provision_relay_main.sh"
out=$(run_sec "$d")
chk "provisioner missing -> warns"        "$(echo "$out" | grep -c 'NOT converged')" "1"
chk "provisioner missing -> not fatal"    "$(echo "$out" | grep -c 'EXIT:0')" "1"

d=$(sandbox fail1 1); : > "$d/jyrelay0.conf"
out=$(run_sec "$d")
chk "convergence failure warns"           "$(echo "$out" | grep -c 'convergence failed')" "1"
chk "convergence failure is not fatal"    "$(echo "$out" | grep -c 'EXIT:0')" "1"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
