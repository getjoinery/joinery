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
# what is missing. Docker refuses to start without the walls, a reload keeps
# their counts unless something changed, and a missed night is not made up.
# nft, systemctl, apt-config and apt-get are stubs; what the rules do to real
# packets is a live check.

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
has "metadata over IPv6, Linode's and AWS's: dropped" "$S3" '^ip6 daddr \{ fd00:a9fe:a9fe::1, fd00:ec2::254 \} counter drop$'
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
# apt-config answers as apt would with the reboot drop-in read, unless
# STUB_APT_OFF says something later in apt.conf.d turned it off.
cat > "$T/bin/apt-config" <<'STUB'
#!/bin/bash
if [ -f "$JOINERY_MT_ROOT/etc/apt/apt.conf.d/52joinery-multi-tenant-reboot" ] && [ -z "${STUB_APT_OFF:-}" ]; then
    echo "UU='1'"; echo "AR='true'"
fi
STUB
cat > "$T/bin/apt-get" <<'STUB'
#!/bin/bash
echo "apt-get $*" >> "$STUB_T/calls"
STUB
printf '#!/bin/bash\n' > "$T/bin/unattended-upgrade"
chmod +x "$T/bin/nft" "$T/bin/systemctl" "$T/bin/apt-config" "$T/bin/apt-get" "$T/bin/unattended-upgrade"
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
hasnt "unit: no Requires or BindsTo (a walls restart must not restart Docker)" "$UNIT" '^(Requires|BindsTo|PartOf)='
DD="$(cat "$T/root/etc/systemd/system/docker.service.d/joinery-site-walls.conf" 2>/dev/null)"
chk "Docker's start asserts the walls first" "$(printf '%s\n' "$DD" | grep -v '^#' | tr '\n' '|')" \
    "[Service]|ExecStartPre=/usr/local/sbin/joinery-site-walls assert|"
ND="$(cat "$T/root/etc/systemd/system/nftables.service.d/joinery-site-walls.conf" 2>/dev/null)"
chk "nftables.service's flush is followed by the walls, at start, reload and stop" "$(printf '%s\n' "$ND" | grep -v '^#' | tr '\n' '|')" \
    "[Service]|ExecStartPost=/usr/local/sbin/joinery-site-walls walls|ExecReload=/usr/local/sbin/joinery-site-walls walls|ExecStopPost=/usr/local/sbin/joinery-site-walls walls|"
REB="$(cat "$T/root/etc/apt/apt.conf.d/52joinery-multi-tenant-reboot" 2>/dev/null)"
has "updates: run every night" "$REB" '^APT::Periodic::Unattended-Upgrade "1";$'
has "updates: lists refreshed" "$REB" '^APT::Periodic::Update-Package-Lists "1";$'
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
has "timer: a missed night is not made up at boot" "$TIM" '^Persistent=false$'
chk "the window opens after the backup window ends (05:00 UTC)" "$([ "${start:-0}" -ge 300 ] && echo yes)" "yes"
chk "and closes before the next one opens (03:00 UTC)" "$([ $(( ${start:-0} + ${spread:-1440} )) -le 1620 ] && echo yes)" "yes"

echo "=== install again: nothing differs ==="
: > "$T/calls"
out="$(bash "$MTH" install 2>&1)"
chk "no daemon-reload, no restarts: the walls and their drop counts are kept" "$(calls)" \
    "nft -c -f -|systemctl enable joinery-site-walls.service|nft list table inet joinery_site_walls|nft list table bridge joinery_site_walls|"
has "and says so" "$out" '^site walls: unchanged and loaded'
: > "$T/calls"
STUB_NFT_LIST_RC=1 bash "$MTH" install > /dev/null 2>&1
chk "nothing differs but the walls are gone (flushed): they are loaded again" "$(calls)" \
    "nft -c -f -|systemctl enable joinery-site-walls.service|nft list table inet joinery_site_walls|systemctl restart joinery-site-walls.service|"

rm -f "$T/root/etc/systemd/system/docker.service.d/joinery-site-walls.conf"; : > "$T/calls"
bash "$MTH" install > /dev/null 2>&1
chk "only Docker's drop-in differs: systemd re-reads it, the walls are not reloaded" "$(calls)" \
    "nft -c -f -|systemctl daemon-reload|systemctl enable joinery-site-walls.service|nft list table inet joinery_site_walls|nft list table bridge joinery_site_walls|"

echo "=== apt that will not reboot is refused ==="
out="$(STUB_APT_OFF=1 bash "$MTH" install 2>&1)"; rc=$?
chk "exit non-zero" "$([ "$rc" -ne 0 ] && echo yes)" "yes"
has "names apt-config" "$out" 'apt does not run unattended upgrades with a reboot'

echo "=== assert: what Docker runs before it starts ==="
bash "$MTH" assert > /dev/null 2>&1; rc=$?
chk "walls loaded: exit 0" "$rc" "0"
out="$(STUB_NFT_LIST_RC=1 bash "$MTH" assert 2>&1)"; rc=$?
chk "walls missing: exit 1, so Docker does not start" "$rc" "1"
has "and says why" "$out" 'Docker does not start'
: > "$T/calls"; STUB_NFT_LIST_RC=1 bash "$MTH" assert > /dev/null 2>&1
chk "assert only reads" "$(calls | tr '|' '\n' | grep -vc '^nft list table')" "0"

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
mv "$T/root/etc/systemd/system/docker.service.d/joinery-site-walls.conf" "$T/dd"
out="$(bash "$MTH" check)"; rc=$?
chk "Docker would start without the walls: exit 1" "$rc" "1"
has "names it" "$out" '^walls at boot: NOT kept'
mv "$T/dd" "$T/root/etc/systemd/system/docker.service.d/joinery-site-walls.conf"
out="$(STUB_APT_OFF=1 bash "$MTH" check)"; rc=$?
chk "apt will not run or reboot: exit 1" "$rc:$(printf '%s\n' "$out" | grep -c '^reboot after updates: NOT set$')" "1:1"
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
