#!/bin/bash
# @joinery-test
# name: multi_tenant_host
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/multi_tenant_host.sh]
#
# multi_tenant_host.sh walls a multi-tenant host's sites off from the host,
# from each other and from the outside services nothing on a site should use,
# and reboots the host after a kernel update
# (specs/multi_tenant_docker_hosts.md WP5 items 1, 4, 5, 6 and 7). This gate
# pins the ruleset rule by rule, the boot unit, the reboot policy and its
# window, that a ruleset nft refuses installs nothing, and that `check` says
# what is missing. nft, systemctl and apt-get are stubs; what the rules do to
# real packets is a live check.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
MTH="$ROOT/maintenance_scripts/install_tools/multi_tenant_host.sh"
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
has() {  # LABEL TEXT REGEX
    if printf '%s\n' "$2" | grep -Eq -- "$3"; then chk "$1" yes yes; else chk "$1" no yes; fi
}
hasnt() {
    if printf '%s\n' "$2" | grep -Eq -- "$3"; then chk "$1" yes no; else chk "$1" no no; fi
}

echo "=== the script parses ==="
chk "bash -n" "$(bash -n "$MTH" 2>&1; echo $?)" "0"

RS="$(bash "$MTH" ruleset)"
chain() {  # NAME: the chain's body, from its line to its closing brace
    printf '%s\n' "$RS" | awk -v n="$1" '$0 ~ "chain " n " \\{" {on=1; next} on && /^\t\}/ {exit} on {sub(/^\t+/, ""); print}'
}

echo "=== each table is replaced whole, in both families ==="
for fam in inet bridge; do
    chk "$fam: add, delete, define, in that order" \
        "$(printf '%s\n' "$RS" | grep -E "^(table|delete table) $fam joinery_site_walls" | sed 's/ {$//' | tr '\n' '|')" \
        "table $fam joinery_site_walls|delete table $fam joinery_site_walls|table $fam joinery_site_walls|"
done

echo "=== a site's packets to the host itself ==="
IN="$(chain input)"
has "input is a filter hook" "$IN" '^type filter hook input priority filter; policy accept;$'
has "docker0 goes to site_to_host" "$IN" '^iifname "docker0" jump site_to_host$'
has "jsnet* goes to site_to_host" "$IN" '^iifname "jsnet\*" jump site_to_host$'
hasnt "no br-* (other networks are not sites)" "$RS" 'br-\*'
S2H="$(chain site_to_host)"
chk "site_to_host, in order" "$(printf '%s\n' "$S2H" | tr '\n' '|')" \
    'ct state established,related accept|icmpv6 type { nd-neighbor-solicit, nd-neighbor-advert, nd-router-solicit } accept|tcp dport { 80, 443 } accept|counter drop|'

echo "=== a site's packets through the host ==="
FW="$(chain forward)"
has "docker0 goes to site_through_host" "$FW" '^iifname "docker0" jump site_through_host$'
has "jsnet* goes to site_through_host" "$FW" '^iifname "jsnet\*" jump site_through_host$'
S3="$(chain site_through_host)"
has "to another site on docker0: dropped" "$S3" '^oifname "docker0" counter drop$'
has "to another site's own network: dropped" "$S3" '^oifname "jsnet\*" counter drop$'
has "port 25 anywhere: dropped" "$S3" '^tcp dport 25 counter drop$'
has "metadata over IPv4: dropped" "$S3" '^ip daddr 169\.254\.169\.254 counter drop$'
has "metadata over IPv6: dropped" "$S3" '^ip6 daddr fd00:a9fe:a9fe::1 counter drop$'
hasnt "nothing here accepts" "$S3" 'accept'

echo "=== across one bridge ==="
BR="$(printf '%s\n' "$RS" | awk '/^table bridge joinery_site_walls \{/,0')"
has "bridge forward hook" "$BR" 'type filter hook forward priority filter; policy accept;'
has "port to port on docker0: dropped" "$BR" 'meta ibrname "docker0" counter drop'
has "port to port on jsnet*: dropped" "$BR" 'meta ibrname "jsnet\*" counter drop'

echo "=== the host's own port 25 ==="
chk "output: loopback, then 25 dropped" "$(chain output | sed 1d | tr '\n' '|')" 'oifname "lo" accept|tcp dport 25 counter drop|'

# --- install, against stubs and a scratch root ---
mkdir -p "$T/bin"
cat > "$T/bin/nft" <<'STUB'
#!/bin/bash
echo "nft $*" >> "$STUB_T/calls"
[ "$1" = "-c" ] && { cat > /dev/null; exit "${STUB_NFT_CHECK_RC:-0}"; }
[ "$1" = "list" ] && exit "${STUB_NFT_LIST_RC:-0}"
cat > /dev/null
STUB
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
echo "systemctl $*" >> "$STUB_T/calls"
[ "$1" = "is-enabled" ] && exit "${STUB_ENABLED_RC:-0}"
exit 0
STUB
chmod +x "$T/bin/nft" "$T/bin/systemctl"
export PATH="$T/bin:$PATH" STUB_T="$T" JOINERY_MT_ROOT="$T/root"
calls() { cat "$T/calls" 2>/dev/null | tr '\n' '|'; }

echo "=== install on a fresh host ==="
: > "$T/calls"
out="$(bash "$MTH" install 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "the ruleset is checked first, then units are reloaded, enabled and started" "$(calls)" \
    "nft -c -f -|systemctl daemon-reload|systemctl enable joinery-site-walls.service|systemctl restart joinery-site-walls.service|systemctl restart apt-daily-upgrade.timer|"
chk "the unit runs a root-owned copy, not the tree's" "$(cmp -s "$MTH" "$T/root/usr/local/sbin/joinery-site-walls" && echo same)" "same"
UNIT="$(cat "$T/root/etc/systemd/system/joinery-site-walls.service" 2>/dev/null)"
has "unit: loads the walls" "$UNIT" '^ExecStart=/usr/local/sbin/joinery-site-walls walls$'
has "unit: before Docker starts" "$UNIT" '^Before=.*docker\.service'
has "unit: stays active" "$UNIT" '^RemainAfterExit=yes$'
has "unit: at every boot" "$UNIT" '^WantedBy=multi-user\.target$'
REB="$(cat "$T/root/etc/apt/apt.conf.d/52joinery-multi-tenant-reboot" 2>/dev/null)"
has "reboot: on" "$REB" '^Unattended-Upgrade::Automatic-Reboot "true";$'
has "reboot: even with someone logged in" "$REB" '^Unattended-Upgrade::Automatic-Reboot-WithUsers "true";$'
has "reboot: as soon as the update needs it" "$REB" '^Unattended-Upgrade::Automatic-Reboot-Time "now";$'
chk "the drop-in sorts after Ubuntu's 50unattended-upgrades" \
    "$(printf '%s\n' 50unattended-upgrades 52joinery-multi-tenant-reboot | sort | tail -1)" "52joinery-multi-tenant-reboot"
TIM="$(cat "$T/root/etc/systemd/system/apt-daily-upgrade.timer.d/joinery-multi-tenant.conf" 2>/dev/null)"
chk "timer: the stock time is cleared, then one time in UTC" \
    "$(printf '%s\n' "$TIM" | grep '^OnCalendar=' | tr '\n' '|')" "OnCalendar=|OnCalendar=*-*-* 05:30 UTC|"
start=$(printf '%s\n' "$TIM" | sed -n 's/^OnCalendar=\*-\*-\* \([0-9][0-9]\):\([0-9][0-9]\) UTC$/\1 \2/p' | awk '{print $1*60+$2}')
spread=$(printf '%s\n' "$TIM" | sed -n 's/^RandomizedDelaySec=\([0-9]*\)m$/\1/p')
chk "the window opens after the backup window ends (05:00 UTC)" "$([ "${start:-0}" -ge 300 ] && echo yes)" "yes"
chk "and closes before the next one opens (03:00 UTC)" "$([ $(( ${start:-0} + ${spread:-1440} )) -le 1620 ] && echo yes)" "yes"

echo "=== install again: nothing differs ==="
: > "$T/calls"
bash "$MTH" install > /dev/null 2>&1
chk "no reload, no timer restart; the walls are reloaded" "$(calls)" \
    "nft -c -f -|systemctl enable joinery-site-walls.service|systemctl restart joinery-site-walls.service|"

echo "=== a ruleset nft refuses installs nothing ==="
rm -rf "$T/root"; : > "$T/calls"
out="$(STUB_NFT_CHECK_RC=1 bash "$MTH" install 2>&1)"; rc=$?
chk "exit non-zero" "$([ "$rc" -ne 0 ] && echo yes)" "yes"
has "says so" "$out" 'nft refused the walls; nothing was changed'
chk "nothing written" "$(find "$T/root" -type f 2>/dev/null | wc -l)" "0"
chk "nothing started" "$(calls)" "nft -c -f -|"

echo "=== check says what is missing ==="
bash "$MTH" install > /dev/null 2>&1
out="$(bash "$MTH" check)"; rc=$?
chk "all in place: exit 0" "$rc" "0"
out="$(STUB_NFT_LIST_RC=1 bash "$MTH" check)"; rc=$?
chk "walls not loaded: exit 1" "$rc" "1"
has "names it" "$out" '^walls: NOT loaded$'
out="$(STUB_ENABLED_RC=1 bash "$MTH" check)"; rc=$?
chk "unit not enabled: exit 1" "$rc" "1"
rm -f "$T/root/etc/apt/apt.conf.d/52joinery-multi-tenant-reboot"
out="$(bash "$MTH" check)"; rc=$?
chk "reboot policy missing: exit 1" "$rc" "1"
has "names it" "$out" '^reboot after updates: NOT set$'

echo "=== anything else is refused ==="
out="$(bash "$MTH" frobnicate 2>&1)"; rc=$?
chk "exit 1 with the usage" "$rc:$(printf '%s' "$out" | grep -c usage)" "1:1"

echo ""
echo "multi_tenant_host gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
