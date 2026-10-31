#!/bin/bash
# @joinery-test
# name: trust_forwarder
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/trust_forwarder.sh]
#
# Believing X-Forwarded-For from a site's old server while its DNS moves
# (specs/site_copy_anything_to_anything.md D1): one address at a time, for a
# bounded while, and never past its end. Driven unprivileged against a fixture
# /etc (TRUST_FORWARDER_ETC) with a fixed clock (TRUST_FORWARDER_NOW); with the
# fixture, Apache is never reloaded.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/trust_forwarder.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; echo "RESULT: PASS 0 0"; exit 0; fi
export TRUST_FORWARDER_ETC="$T/etc"
CONF="$T/etc/apache2/conf-available/joinery-forwarders.conf"
LINK="$T/etc/apache2/conf-enabled/joinery-forwarders.conf"
tf() { TRUST_FORWARDER_NOW="$1" bash "$SCRIPT" "${@:2}"; }

echo "=== trust ==="
out="$(tf 1000000 trust 198.51.100.7 48 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "recorded with its end" "$(cat "$T/etc/joinery/forwarders")" "198.51.100.7 $((1000000 + 48 * 3600))"
chk "Apache believes it, inside remoteip's block" "$(grep -c '^RemoteIPTrustedProxy 198.51.100.7$' "$CONF"):$(grep -c '<IfModule remoteip_module>' "$CONF")" "1:1"
chk "the file is enabled" "$(readlink "$LINK")" "../conf-available/joinery-forwarders.conf"
tf 1000000 trust 2001:db8::7 1 >/dev/null 2>&1
chk "a second address beside it" "$(grep -c '^RemoteIPTrustedProxy' "$CONF")" "2"
tf 1000000 trust 198.51.100.7 2 >/dev/null 2>&1
chk "trusting an address again replaces its end, never doubles it" "$(grep -c '^198.51.100.7 ' "$T/etc/joinery/forwarders"):$(awk '$1=="198.51.100.7"{print $2}' "$T/etc/joinery/forwarders")" "1:$((1000000 + 2 * 3600))"

echo "=== an end passes ==="
out="$(tf $((1000000 + 2 * 3600 + 1)) show 2>&1)"
chk "show leaves out an address past its end" "$(printf '%s' "$out" | grep -c '198.51.100.7'):$(printf '%s' "$out" | grep -c '2001:db8::7')" "0:0"
tf $((1000000 + 2 * 3600 + 1)) trust 203.0.113.9 1 >/dev/null 2>&1
chk "the next run drops what has ended, from the record and from Apache" "$(grep -c '198.51.100.7' "$CONF"):$(grep -c '2001:db8::7' "$CONF"):$(grep -c '203.0.113.9' "$CONF"):$(wc -l < "$T/etc/joinery/forwarders")" "0:0:1:1"

echo "=== untrust ==="
out="$(tf $((1000000 + 2 * 3600 + 2)) untrust 203.0.113.9 2>&1)"; rc=$?
chk "exit 0, and nothing left believed: the file and its link are gone" "$rc:$([ -e "$CONF" ] && echo conf || echo none):$([ -L "$LINK" ] && echo link || echo none)" "0:none:none"

echo "=== expire: what housekeeping runs every tick ==="
tf 2000000 trust 198.51.100.8 1 >/dev/null 2>&1
out="$(tf 2000100 expire 2>&1)"; rc=$?
chk "before its end: kept" "$rc:$(grep -c '198.51.100.8' "$CONF")" "0:1"
out="$(tf $((2000000 + 3601)) expire 2>&1)"; rc=$?
chk "past its end: gone, the file and its link with it, though nobody untrusted it" "$rc:$([ -e "$CONF" ] && echo conf || echo none):$([ -L "$LINK" ] && echo link || echo none):$([ -s "$T/etc/joinery/forwarders" ] && echo some || echo none)" "0:none:none:none"
out="$(tf $((2000000 + 3602)) expire 2>&1)"; rc=$?
chk "with nothing believed, expire does nothing and passes" "$rc:$([ -e "$CONF" ] && echo conf || echo none)" "0:none"

echo "=== refusals ==="
tf 1 trust '203.0.113.0/24' 2 >/dev/null 2>&1; chk "a range: 2" "$?" "2"
tf 1 trust '1.2.3.4;reboot' 2 >/dev/null 2>&1; chk "anything but an address: 2" "$?" "2"
tf 1 trust 999.1.1.1 2 >/dev/null 2>&1; chk "an octet past 255: 2" "$?" "2"
tf 1 trust 198.51.100.7 169 >/dev/null 2>&1; chk "more than a week: 2" "$?" "2"
tf 1 trust 198.51.100.7 0 >/dev/null 2>&1; chk "no time at all: 2" "$?" "2"
tf 1 untrust 'x' >/dev/null 2>&1; chk "untrust of anything but an address: 2" "$?" "2"
chk "nothing was written by a refusal" "$([ -s "$T/etc/joinery/forwarders" ] && echo some || echo none)" "none"
( unset TRUST_FORWARDER_ETC; bash "$SCRIPT" show >/dev/null 2>&1 ); chk "without the fixture, an unprivileged run is refused" "$?" "2"

[ "$failed" = 0 ] && echo "RESULT: PASS $passed $failed" || echo "RESULT: FAIL $passed $failed"
[ "$failed" = 0 ]
