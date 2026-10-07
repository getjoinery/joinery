#!/bin/bash
# @joinery-test
# name: outbound_limits
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/install_tools/outbound_limits.sh, maintenance_scripts/install_tools/_site_run_spec.sh]
#
# outbound_limits.sh (node_outbound_and_transfer WP3, WP4, WP5) writes one
# nftables table limiting what each site opens toward the outside, and a speed
# ceiling, at figures root sets for the machine and each site, which a site's
# own setting can only lower.
# Driven unprivileged against a scratch root (JOINERY_LIMITS_ROOT) with nft,
# systemctl, ip and tc stubbed on PATH:
# the table names each site on its own network by its bridge and passes over
# one without one (Docker's default network, or a bridge that is not a
# jsnetN); the web server's user is limited only
# where the machine serves its sites itself, loopback apart; UDP goes first,
# then the open cap, then the rate; a run that would change nothing replaces
# nothing, and a replace carries the drop counters over; an outside resolver
# refuses the limits and removes the table; the host file turns them off and
# keeps what else it holds; install writes the unit and the timer once, keeps
# --web-user once given, and starts them. The speed ceiling: each site's
# bridge redirects to an ifb of its own at 200 Mbit/s, and with --web-user each
# public interface redirects the web server's marked packets to one; a run
# with it in force changes nothing, one after a rebuilt bridge puts its part
# back, a site gone takes its filter out before its ifb, tc refusing says so
# and leaves the connection limits on, and off or an outside resolver takes it
# all out. The callers (install.sh docker and
# server, install.sh site, the move script, remove_account.sh) are pinned by
# reading them.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/install_tools/outbound_limits.sh"
HELPER="$ROOT/maintenance_scripts/install_tools/_site_run_spec.sh"
INSTALL="$ROOT/maintenance_scripts/install_tools/install.sh"
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

R="$T/root"
mkdir -p "$T/bin" "$R/etc/joinery/sites" "$R/run/joinery"
echo "nameserver 127.0.0.53" > "$R/etc/resolv.conf"

# nft: a table that exists while $T/nft_table does; its counters are
# $T/nft_counters; every call is logged.
cat > "$T/bin/nft" <<STUB
#!/bin/bash
echo "nft \$*" >> "$T/nft_calls"
case "\$*" in
    "-c -f -") cat > "$T/nft_checked"; exit \${STUB_NFT_CHECK_RC:-0} ;;
    "-f -")    cat > "$T/nft_loaded"; [ "\${STUB_NFT_LOAD_RC:-0}" = 0 ] || exit 1; touch "$T/nft_table"; exit 0 ;;
    "list table inet joinery_limits") [ -f "$T/nft_table" ] ;;
    "list counters table inet joinery_limits") [ -f "$T/nft_table" ] && cat "$T/nft_counters" 2>/dev/null ;;
    "delete table inet joinery_limits") rm -f "$T/nft_table" ;;
    *) exit 2 ;;
esac
STUB
# systemctl: logged; starting the unit runs the installed copy's apply, as
# systemd would.
cat > "$T/bin/systemctl" <<STUB
#!/bin/bash
echo "systemctl \$*" >> "$T/systemctl_calls"
if [ "\$1" = start ] && [ "\$2" = joinery-limits.service ]; then
    exec bash "$R/usr/local/sbin/joinery-limits" apply \$(grep -q -- --web-user "$R/etc/systemd/system/joinery-limits.service" && echo --web-user)
fi
exit 0
STUB
# ip and tc: devices are files in $T/links (the sites' bridges and eth0 to
# begin with), each device's qdiscs lines in $T/tcq/DEV and its filter at our
# preference in $T/tcf/DEV.DIR, as the real commands print them; every call is
# logged. A device ip makes carries a generation, which a filter records, so a
# filter whose device was made again shows it as *, as the kernel's does.
# STUB_TC_RC fails the class's rate, STUB_TC_DEL_RC a filter's removal, and
# STUB_NO_ROUTE takes the default routes away.
mkdir -p "$T/links" "$T/tcq" "$T/tcf"
for d in jsnet1 jsnet2 jsnet3 jsnet4 eth0; do touch "$T/links/$d"; done
cat > "$T/bin/ip" <<STUB
#!/bin/bash
echo "ip \$*" | tee -a "$T/all_calls" >> "$T/ip_calls"
case "\$*" in
    "-4 route show default") [ -n "\${STUB_NO_ROUTE:-}" ] || echo "default via 192.0.2.1 dev eth0 proto static" ;;
    "-6 route show default") [ -n "\${STUB_NO_ROUTE:-}" ] || echo "default via fe80::1 dev eth0 proto ra metric 1024 pref medium" ;;
    "link show dev "*) [ -e "$T/links/\$4" ] ;;
    "link add "*" type ifb") echo "\$RANDOM\$RANDOM" > "$T/links/\$3" ;;
    "link set dev "*" up") [ -e "$T/links/\$4" ] ;;
    "link del dev "*) rm -f "$T/links/\$4" "$T/tcq/\$4" "$T/tcf/\$4".* ;;
    *) exit 2 ;;
esac
STUB
cat > "$T/bin/tc" <<STUB
#!/bin/bash
echo "tc \$*" | tee -a "$T/all_calls" >> "$T/tc_calls"
case "\$1 \$2" in
    "qdisc show") cat "$T/tcq/\$4" 2>/dev/null ;;
    "qdisc replace")
        case "\$*" in
            *"root handle 1: htb default 10") echo "qdisc htb 1: root refcnt 2 r2q 10 default 0x10" >> "$T/tcq/\$4" ;;
            *"parent 1:10 handle 10: fq_codel") echo "qdisc fq_codel 10: parent 1:10 limit 10240p" >> "$T/tcq/\$4" ;;
            *) exit 2 ;;
        esac ;;
    "qdisc add") [ -e "$T/links/\$4" ] && [ "\$5" = clsact ] && echo "qdisc clsact ffff: parent ffff:fff1" >> "$T/tcq/\$4" ;;
    "qdisc del") [ "\$5" = clsact ] && sed -i '/^qdisc clsact /d' "$T/tcq/\$4" 2>/dev/null ;;
    "class replace") exit \${STUB_TC_RC:-0} ;;
    "filter show") [ -f "$T/tcf/\$4.\$5" ] || exit 0
        i=\$(sed -n 's/.*to device \\(.*\\)) stolen\$/\\1/p' "$T/tcf/\$4.\$5")
        # A filter whose device is gone shows it as *, as iproute2 does.
        if [ -e "$T/links/\$i" ] && [ "\$(cat "$T/links/\$i")" = "\$(cat "$T/tcf/\$4.\$5.gen" 2>/dev/null)" ]; then cat "$T/tcf/\$4.\$5"; else sed "s/to device \$i)/to device *)/" "$T/tcf/\$4.\$5"; fi ;;
    "filter del") [ "\${STUB_TC_DEL_RC:-0}" = 0 ] || exit 1; [ -f "$T/tcf/\$4.\$5" ] && rm -f "$T/tcf/\$4.\$5" "$T/tcf/\$4.\$5.gen" ;;
    "filter add") cat "$T/links/\${@: -1}" > "$T/tcf/\$4.\$5.gen" 2>/dev/null; printf 'filter protocol all pref 47 %s\n\taction order 1: mirred (Egress Redirect to device %s) stolen\n' "\$*" "\${@: -1}" > "$T/tcf/\$4.\$5" ;;
    *) exit 2 ;;
esac
STUB
printf '#!/bin/bash\necho "modprobe $*" >> "%s/modprobe_calls"\n' "$T" > "$T/bin/modprobe"
chmod +x "$T/bin/nft" "$T/bin/systemctl" "$T/bin/ip" "$T/bin/tc" "$T/bin/modprobe"

lim() { JOINERY_LIMITS_ROOT="$R" PATH="$T/bin:$PATH" bash "$SCRIPT" "$@"; }
spec() {  # SITE LINES...
    mkdir -p "$R/etc/joinery/sites/$1"; local s="$1"; shift
    printf '%s\n' "$@" > "$R/etc/joinery/sites/$s/run_spec"
}
status_key() { sed -n "s/^$1=//p" "$R/run/joinery/outbound_limits.status"; }
calls() { grep -c -- "$1" "$T/nft_calls" 2>/dev/null || true; }

spec s1 spec_version=2 network=s1_net bridge=jsnet1 subnet=10.250.1.0/24
spec s2 spec_version=2 network=s2_net bridge=jsnet2 subnet=10.250.2.0/24
spec old spec_version=1 hostname=old
spec evil spec_version=2 'bridge=jsnet3" accept'
mkdir -p "$R/etc/joinery/sites/bad name" && echo bridge=jsnet9 > "$R/etc/joinery/sites/bad name/run_spec"
mkdir -p "$R/etc/joinery/sites/nospec"
spec plain spec_version=2 bridge=docker0
spec odd spec_version=2 bridge=br0

echo "=== The table ==="
RS="$(lim ruleset)"
chk "it replaces its own table whole: create, delete, define" "$(printf '%s\n' "$RS" | head -3 | tr '\n' '|')" "table inet joinery_limits|delete table inet joinery_limits|table inet joinery_limits {|"
chk "each site on its own network is matched by its bridge, on the forward path" \
    "$(grep -c -E 'iifname "jsnet1" jump site_s1|iifname "jsnet2" jump site_s2' <<< "$RS")" "2"
chk "the forward hook runs at priority -10, accepting by default" "$(grep -c 'type filter hook forward priority -10; policy accept;' <<< "$RS")" "1"
chk "a site on Docker's default network is not matched (docker0 is shared), even one whose spec names it" "$(grep -c -E 'docker0|site_old|site_plain' <<< "$RS")" "0"
chk "a bridge value that is not a name is passed over, never written" "$(grep -c -E 'jsnet3|site_evil' <<< "$RS")" "0"
chk "a bridge that is not a jsnetN (one run_spec gave the site) is passed over" "$(grep -c -E 'br0|site_odd' <<< "$RS")" "0"
chk "a directory that is not a site name is passed over" "$(grep -c 'jsnet9' <<< "$RS")" "0"
chk "without --web-user the host's own processes are not limited" "$(grep -c -E 'skuid|hook output' <<< "$RS")" "0"
chain_s1="$(sed -n '/chain site_s1 {/,/}/p' <<< "$RS")"
chk "in each site's chain: UDP dropped first, then the open cap, then the rate" \
    "$(grep -o -E 'l4proto udp|ct count over [0-9]+|limit rate over [0-9]+/second burst [0-9]+ packets' <<< "$chain_s1" | tr '\n' '|')" \
    "l4proto udp|ct count over 256|limit rate over 20/second burst 100 packets|"
chk "the cap and the rate count only new connections" "$(grep -c 'ct state new' <<< "$chain_s1")" "2"
chk "every drop counts in the site's own counter" "$(grep -c 'counter name "drops_site_s1" drop' <<< "$chain_s1")/$(grep -c '^	counter drops_site_s1 {' <<< "$RS")" "3/1"
RSW="$(lim ruleset --web-user)"
out="$(sed -n '/chain output {/,/}/p' <<< "$RSW")"
chk "--web-user: the web server's user on the output path, loopback accepted first" \
    "$(grep -o -E 'hook output priority -10|oifname "lo" accept|meta skuid "www-data" meta mark set meta mark \| 0x10000000 jump web_user' <<< "$out" | tr '\n' '|')" \
    "hook output priority -10|oifname \"lo\" accept|meta skuid \"www-data\" meta mark set meta mark | 0x10000000 jump web_user|"
chk "the web server's user gets the same three drops and its own counter" \
    "$(sed -n '/chain web_user {/,/}/p' <<< "$RSW" | grep -c 'counter name "drops_web_user" drop')" "3"

H="$T/hyphen"; mkdir -p "$H/etc/joinery/sites/my-site"; echo bridge=jsnet5 > "$H/etc/joinery/sites/my-site/run_spec"
RSH="$(JOINERY_LIMITS_ROOT="$H" bash "$SCRIPT" ruleset)"
chk "a site name with a hyphen is written as nft takes it, unquoted (nft 1.0.9 loads and lists it so)" \
    "$(grep -c -E '^	counter drops_site_my-site \{$|iifname "jsnet5" jump site_my-site$|^	chain site_my-site \{$|counter name "drops_site_my-site" drop' <<< "$RSH")" "6"

echo "=== Apply ==="
out="$(lim apply 2>&1)"; rc=$?
chk "apply: exit 0" "$rc" "0"
chk "the table is loaded in one transaction" "$(calls '^nft -f -$')" "1"
chk "the status says on, which sites, and which are not covered" \
    "$(status_key state)|$(status_key sites)|$(status_key uncovered)|$(status_key web_user)" "on|s1 s2|evil odd old plain|no"
chk "the counters' since is a time" "$([[ "$(status_key since)" =~ ^[0-9]{10}$ ]] && echo yes)" "yes"
chk "a site not on a network of its own is named, with the way to move one off Docker's default network" \
    "$(grep -c "not limited, not on a jsnetN network of their own: evil odd old plain (move_site_to_own_network.sh moves a site off Docker's default network)" <<< "$out")" "1"
since1="$(status_key since)"
out="$(lim apply 2>&1)"
chk "a run that would change nothing replaces nothing" "$(calls '^nft -f -$')|$out" "1|outbound limits: unchanged"
rm -f "$T/nft_table"
lim apply > /dev/null 2>&1
chk "a table gone from under it (flushed by hand) is put back" "$(calls '^nft -f -$')" "2"

printf 'table inet joinery_limits {\n\tcounter drops_site_s1 {\n\t\tpackets 57 bytes 3420\n\t}\n\tcounter drops_site_s2 {\n\t\tpackets 0 bytes 0\n\t}\n}\n' > "$T/nft_counters"
spec s3 spec_version=2 network=s3_net bridge=jsnet3 subnet=10.250.3.0/24
lim apply > /dev/null 2>&1
chk "a site added: the table is replaced" "$(calls '^nft -f -$')" "3"
chk "and the drop counters carry their values into it" "$(sed -n '/counter drops_site_s1 {/,/}/p' "$T/nft_loaded" | grep -c 'packets 57 bytes 3420')" "1"
chk "the new site's counter starts at zero" "$(sed -n '/counter drops_site_s3 {/,/}/p' "$T/nft_loaded" | grep -c 'packets 0 bytes 0')" "1"
chk "the counters' since does not move while they run on" "$(status_key since)" "$since1"
chk "the status names the new site" "$(status_key sites)" "s1 s2 s3"
printf 'counter drops_site_s1 {\n\t\tpackets 1; reboot bytes x\n\t}\n' > "$T/nft_counters"
rm -f "$R/etc/joinery/sites/s3/run_spec"
lim apply > /dev/null 2>&1
chk "a counter value that is not a number is written as zero" "$(sed -n '/counter drops_site_s1 {/,/}/p' "$T/nft_loaded" | grep -c 'packets 0 bytes 0')" "1"

since_before="$(status_key since)"
spec s4 spec_version=2 bridge=jsnet4
STUB_NFT_LOAD_RC=1 JOINERY_LIMITS_ROOT="$R" PATH="$T/bin:$PATH" bash "$SCRIPT" apply > /dev/null 2> "$T/err"; rc=$?
chk "nft refusing a change: exit 1, said why" "$rc|$(grep -c 'nft refused the limits' "$T/err")" "1|1"
chk "the table before it is still in force: on, nft_refused, its own sites and since" \
    "$(status_key state)|$(status_key reason)|$(status_key sites)|$(status_key since)" "on|nft_refused|s1 s2|$since_before"
chk "status says the last change was refused and the old table holds" "$(lim status 2>&1 | grep -c 'the last change was refused (nft_refused): the table before it is in force')" "1"
rm -f "$T/nft_table"
STUB_NFT_LOAD_RC=1 JOINERY_LIMITS_ROOT="$R" PATH="$T/bin:$PATH" bash "$SCRIPT" apply > /dev/null 2> "$T/err"; rc=$?
chk "nft refusing with no table in force: refused nft_refused, no since" \
    "$rc|$(status_key state)|$(status_key reason)|$(status_key since)" "1|refused|nft_refused|"
lim apply > /dev/null 2>&1
chk "the next run that nft takes puts it right" "$(status_key state)|$(status_key sites)" "on|s1 s2 s4"

echo "=== The speed ceiling ==="
ifbs() { ls "$T/links" | grep '^jifb' | tr '\n' ' '; }
chk "each site's bridge sends what the site sends to an ifb of its own (clsact ingress, matchall)" \
    "$(cat "$T/tcf/jsnet1.ingress" "$T/tcf/jsnet2.ingress" "$T/tcf/jsnet4.ingress" | grep -c -E 'filter add dev jsnet([124]) ingress protocol all pref 47 matchall action mirred egress redirect dev jifb\1$')" "3"
chk "each ifb holds its site to 200 Mbit/s, fq_codel under it" \
    "$(grep -c -E '^qdisc (htb 1: root|fq_codel 10: parent 1:10) ' "$T/tcq/jifb1")|$(grep -q 'tc class replace dev jifb1 parent 1: classid 1:10 htb rate 200mbit ceil 200mbit quantum 60000' "$T/tc_calls" && echo held)" "2|held"
chk "an ifb only for each site on its own network, none for the host without --web-user" "$(ifbs)" "jifb1 jifb2 jifb4 "
chk "status: the ceiling in force" "$(status_key ceiling_mbit)|$(lim status | grep -c 'sending at 200 Mbit/s at most')" "200|1"
: > "$T/tc_calls"; : > "$T/ip_calls"
lim apply > /dev/null 2>&1
chk "a run with it all in force adds and removes nothing, and holds each class to the figure" \
    "$(grep -c -E '^ip link (add|del)|^tc (qdisc (add|replace)|filter (add|del))' "$T/ip_calls" "$T/tc_calls" | awk -F: '{ s += $2 } END { print s }')|$(grep -c '^tc class replace' "$T/tc_calls")" "0|3"
rm -f "$T/tcq/jsnet1" "$T/tcf/jsnet1.ingress"; : > "$T/tc_calls"
lim apply > /dev/null 2>&1
chk "a bridge made again (a rebuild) gets its part back on the next run, and only it" \
    "$(grep -c 'redirect dev jifb1$' "$T/tcf/jsnet1.ingress")|$(grep -c -E '^tc (qdisc add|filter add)' "$T/tc_calls")" "1|2"
rm -f "$R/etc/joinery/sites/s2/run_spec" "$T/links/jsnet2" "$T/tcq/jsnet2" "$T/tcf/jsnet2.ingress"*
lim apply > /dev/null 2>&1
chk "a site removed with its network: its ifb is removed" "$(ifbs)|$(grep -c jsnet2 "$R/run/joinery/outbound_limits.shaped")" "jifb1 jifb4 |0"
: > "$T/all_calls"
rm -f "$R/etc/joinery/sites/s4/run_spec"
lim apply > /dev/null 2>&1
chk "a site no longer limited whose bridge stays: its filter is taken out before its ifb" \
    "$(grep -n -E '^tc filter del dev jsnet4 ingress pref 47$|^ip link del dev jifb4$' "$T/all_calls" | cut -d: -f2 | tr '\n' '|')|$(ifbs)" \
    "tc filter del dev jsnet4 ingress pref 47|ip link del dev jifb4||jifb1 "
spec s4 spec_version=2 bridge=jsnet4
out="$(lim apply --web-user 2>&1)"
chk "--web-user: each public interface sends the web server's marked packets to one ifb (clsact egress, fw)" \
    "$(grep -c 'filter add dev eth0 egress protocol all pref 47 handle 0x10000000/0x10000000 fw action mirred egress redirect dev jifbweb$' "$T/tcf/eth0.egress")|$(grep -c 'eth0 egress jifbweb' "$R/run/joinery/outbound_limits.shaped")" "1|1"
chk "one ifb for the web server's user, at the same figure" \
    "$(ifbs)|$(grep -c 'tc class replace dev jifbweb parent 1: classid 1:10 htb rate 200mbit ceil 200mbit' "$T/tc_calls")" "jifb1 jifb4 jifbweb |1"
lim apply > /dev/null 2>&1
chk "without it again: the interface's filter and the ifb are gone" "$([ -f "$T/tcf/eth0.egress" ] && echo filter)|$(ifbs)" "|jifb1 jifb4 "
STUB_TC_RC=1 JOINERY_LIMITS_ROOT="$R" PATH="$T/bin:$PATH" bash "$SCRIPT" apply > /dev/null 2> "$T/err"; rc=$?
chk "tc refusing the ceiling: exit 1, the connection limits stay on, the status says which part is missing" \
    "$rc|$(status_key state)|$(status_key reason)|$(status_key ceiling_mbit)|$(grep -c 'tc refused the speed ceiling on jsnet1' "$T/err")" "1|on|ceiling_failed||1"
chk "status says the ceiling is not in force everywhere" "$(lim status | grep -c 'the speed ceiling is not in force everywhere')" "1"
lim apply > /dev/null 2>&1
chk "the next run tc takes puts it right" "$(status_key reason)|$(status_key ceiling_mbit)" "|200"
rm -f "$T/links/jifb1" "$T/tcq/jifb1"
out="$(lim apply 2>&1)"
chk "s1's ifb lost (its filter now shows to device *): the next run makes it again and points the filter at it" \
    "$(ls "$T/links/jifb1" > /dev/null 2>&1 && echo ifb)|$(grep -c 'to device jifb1) stolen' "$T/tcf/jsnet1.ingress")|$(grep -c 'speed ceiling put in force on jsnet1' <<< "$out")|$(grep -c 'outbound limits: unchanged' <<< "$out")" "ifb|1|1|1"
rm -f "$T/links/jsnet1"
lim apply > /dev/null 2>&1; rc=$?
chk "a bridge missing at the moment of a run (Docker starting) is passed over, not a failure" "$rc|$(status_key reason)" "0|"
touch "$T/links/jsnet1"; lim apply > /dev/null 2>&1
chk "and gets its part when it is back" "$(grep -c 'to device jifb1) stolen' "$T/tcf/jsnet1.ingress")" "1"
rm -f "$R/etc/joinery/sites/s4/run_spec"
STUB_TC_DEL_RC=1 JOINERY_LIMITS_ROOT="$R" PATH="$T/bin:$PATH" bash "$SCRIPT" apply > /dev/null 2> "$T/err"; rc=$?
chk "a filter that will not come off keeps its ifb and its line, and the run fails saying so" \
    "$rc|$(ls "$T/links/jifb4" > /dev/null 2>&1 && echo ifb)|$(grep -c '^jsnet4 ingress jifb4 200$' "$R/run/joinery/outbound_limits.shaped")|$(grep -c 'would not take the speed ceiling off jsnet4' "$T/err")|$(status_key reason)" \
    "1|ifb|1|1|ceiling_failed"
lim apply > /dev/null 2>&1
chk "the next run takes it off: filter, then ifb, then the clsact left empty" \
    "$(ls "$T/links/jifb4" 2> /dev/null)|$(grep -c jsnet4 "$R/run/joinery/outbound_limits.shaped")|$(grep -c clsact "$T/tcq/jsnet4")|$(status_key reason)" "|0|0|"
spec s4 spec_version=2 bridge=jsnet4
lim apply --web-user > /dev/null 2>&1
STUB_NO_ROUTE=1 JOINERY_LIMITS_ROOT="$R" PATH="$T/bin:$PATH" bash "$SCRIPT" apply --web-user > /dev/null 2> "$T/err"; rc=$?
chk "--web-user with no default route: the web server's user's ceiling stays where it was, and the run fails saying why" \
    "$rc|$(grep -c 'redirect dev jifbweb$' "$T/tcf/eth0.egress")|$(ls "$T/links/jifbweb" > /dev/null 2>&1 && echo ifb)|$(status_key reason)|$(grep -c 'no default route' "$T/err")" \
    "1|1|ifb|ceiling_failed|1"
lim apply --web-user > /dev/null 2>&1
chk "the route back: on, the ceiling in force" "$(status_key reason)|$(status_key ceiling_mbit)" "|200"
lim apply > /dev/null 2>&1
chk "without --web-user again: eth0's filter and its now-empty clsact are gone" "$([ -f "$T/tcf/eth0.egress" ] && echo filter)|$(grep -c clsact "$T/tcq/eth0")|$(ls "$T/links/jifbweb" 2> /dev/null)" "|0|"

chk "no command's output is piped into grep -q (it stops at a match, the writer dies of SIGPIPE, and pipefail fails a check that held)" \
    "$(grep -c -E '\| *grep -q' "$SCRIPT")" "0"

echo "=== The resolver ==="
for good in "nameserver 127.0.0.53" "nameserver ::1" $'nameserver 127.0.0.1\nnameserver ::1'; do
    printf '%s\n' "$good" > "$R/etc/resolv.conf"
    lim apply > /dev/null 2>&1
    chk "a loopback resolver ($(echo $good | tr '\n' ' ')) is fine" "$(status_key state)" "on"
done
printf 'nameserver 127.0.0.53\nnameserver 8.8.8.8\n' > "$R/etc/resolv.conf"
lim apply > /dev/null 2> "$T/err"; rc=$?
chk "an outside resolver: refused, exit 1, the reason in the status" "$rc|$(status_key state)|$(status_key reason)" "1|refused|resolver_not_loopback"
chk "and the table is removed, so no site's lookups are dropped" "$([ -f "$T/nft_table" ] && echo present || echo gone)" "gone"
chk "and the ceiling with it" "$(ls "$T/links" | grep -c '^jifb')|$(ls "$T/tcf" | wc -l)|$([ -f "$R/run/joinery/outbound_limits.shaped" ] && echo listed)" "0|0|"
chk "the refusal names the resolver and the way out" "$(grep -c "8.8.8.8).*Point /etc/resolv.conf at the local resolver" "$T/err")" "1"
chk "a refused run has no since" "$(status_key since)" ""
printf 'nameserver 2001:4860:4860::8888\n' > "$R/etc/resolv.conf"
lim apply > /dev/null 2>&1
chk "an outside IPv6 resolver is refused too" "$(status_key reason)" "resolver_not_loopback"
: > "$R/etc/resolv.conf"
lim apply > /dev/null 2>&1
chk "no resolver at all is refused" "$(status_key reason)" "resolver_not_loopback"
echo "nameserver 127.0.0.53" > "$R/etc/resolv.conf"
lim apply > /dev/null 2>&1
chk "the resolver put back: on again, a fresh since" "$(status_key state)|$([[ "$(status_key since)" =~ ^[0-9]+$ ]] && echo time)" "on|time"

echo "=== On and off ==="
echo '{"ceiling_mbit": 300}' > "$R/etc/joinery/outbound_limits.json"
lim off > /dev/null 2>&1; rc=$?
chk "off: exit 0, status off, the table removed" "$rc|$(status_key state)|$([ -f "$T/nft_table" ] && echo present || echo gone)" "0|off|gone"
chk "off takes the ceiling out too" "$(ls "$T/links" | grep -c '^jifb')|$(ls "$T/tcf" | wc -l)" "0|0"
chk "off keeps what else the host file holds" "$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("enabled"), d.get("ceiling_mbit"))' "$R/etc/joinery/outbound_limits.json")" "False 300"
chk "off, the status keeps the figures turning them on brings back (the machine's, each site's) and says site_set_by" \
    "$(status_key ceiling_mbit)|$(status_key site_figures | tr ' ' '\n' | grep -c '^s1:300:')|$(grep -c '^site_set_by=' "$R/run/joinery/outbound_limits.status")|$(status_key since)" "300|1|1|"
out="$(lim show 2>&1)"
chk "show while off: the figures, marked not in force, and nothing called in force" \
    "$(grep -c 'not in force now' <<< "$out")|$(grep -c -E ', in force |ceiling in force' <<< "$out")|$(grep -c 's1: ceiling 300 Mbit/s' <<< "$out")" "1|0|1"
lim apply > /dev/null 2>&1
chk "a later run (the timer) leaves them off" "$(status_key state)|$([ -f "$T/nft_table" ] && echo present || echo gone)" "off|gone"
lim on > /dev/null 2>&1
chk "on: in force again" "$(status_key state)|$([ -f "$T/nft_table" ] && echo present || echo gone)" "on|present"
echo 'not json' > "$R/etc/joinery/outbound_limits.json"
lim apply > /dev/null 2>&1
chk "a host file that does not parse leaves them on" "$(status_key state)" "on"
rm -f "$R/etc/joinery/outbound_limits.json"

echo "=== Install ==="
: > "$T/systemctl_calls"
lim install --web-user > "$T/out" 2>&1; rc=$?
U="$R/etc/systemd/system/joinery-limits.service"
chk "install: exit 0" "$rc" "0"
chk "the script is copied to /usr/local/sbin/joinery-limits, executable" "$(cmp -s "$SCRIPT" "$R/usr/local/sbin/joinery-limits" && [ -x "$R/usr/local/sbin/joinery-limits" ] && echo same)" "same"
chk "the unit runs the copy, with --web-user, after Docker and the resolver" \
    "$(grep -c -E '^ExecStart=/usr/local/sbin/joinery-limits apply --web-user$|^After=docker.service systemd-resolved.service' "$U")" "2"
chk "the timer runs it every five minutes" "$(grep -c '^OnUnitActiveSec=5min$' "$R/etc/systemd/system/joinery-limits.timer")" "1"
chk "the ruleset is checked before anything is written" "$(calls '^nft -c -f -$')" "1"
chk "unit and timer enabled, the timer started, the unit run" \
    "$(grep -c -E '^systemctl (daemon-reload|enable joinery-limits.service joinery-limits.timer|start joinery-limits.timer|start joinery-limits.service)$' "$T/systemctl_calls")" "4"
chk "the run limits the web server's user, and status says so" "$(status_key web_user)|$(grep -c "the web server's user (www-data): limited" "$T/out")" "yes|1"
: > "$T/systemctl_calls"
lim install > /dev/null 2>&1
chk "run again without --web-user: the unit keeps it, nothing rewritten" \
    "$(grep -c -- '--web-user' "$U")|$(grep -c daemon-reload "$T/systemctl_calls")" "1|0"
STUB_NFT_CHECK_RC=1 JOINERY_LIMITS_ROOT="$R" PATH="$T/bin:$PATH" bash "$SCRIPT" install --off > /dev/null 2>&1; rc=$?
chk "a ruleset nft refuses: install stops before writing (no host file)" "$rc|$([ -f "$R/etc/joinery/outbound_limits.json" ] && echo written || echo none)" "1|none"
lim install --off > /dev/null 2>&1
chk "install --off: the host file says so and the run leaves them off" \
    "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["enabled"])' "$R/etc/joinery/outbound_limits.json")|$(status_key state)" "False|off"
lim install > /dev/null 2>&1
chk "a later install without --off does not turn them back on (the owner's choice stands)" "$(status_key state)" "off"
lim on > /dev/null 2>&1
chk "on, with the unit installed, runs it through systemd" "$(status_key state)|$(tail -1 "$T/systemctl_calls")" "on|systemctl start joinery-limits.service"
lim bogus > /dev/null 2>&1; rc=$?
chk "an unknown command is refused" "$rc" "1"
lim apply --everything > /dev/null 2>&1; rc=$?
chk "an unknown option is refused" "$rc" "1"

echo "=== The figures (WP5) ==="
# docker: a site's own ceiling comes from $T/own/SITE (what the site prints);
# what a site is told lands in $T/told/SITE. runuser: a bare-metal site's own
# ceiling from $T/bare_own/SITE.
mkdir -p "$T/own" "$T/told" "$T/bare_own"
cat > "$T/bin/docker" <<STUB
#!/bin/bash
echo "docker \$*" >> "$T/docker_calls"
if [ "\$1 \$2 \$3" = "exec -u www-data" ]; then cat "$T/own/\$4" 2>/dev/null; exit 0; fi
if [ "\$1 \$2 \$3 \$4" = "exec -i -u root" ]; then cat > "$T/told/\$5"; exit 0; fi
exit 1
STUB
cat > "$T/bin/runuser" <<STUB
#!/bin/bash
echo "runuser \$*" >> "$T/runuser_calls"
site="\$(sed -n 's|.*/var/www/html/\([^/]*\)/public_html/utils/outbound_site_ceiling.php.*|\1|p' <<< "\$*")"
cat "$T/bare_own/\$site" 2>/dev/null; exit 0
STUB
chmod +x "$T/bin/docker" "$T/bin/runuser"
cls() { grep "tc class replace dev $1 " "$T/tc_calls" | tail -1 | grep -o 'rate [0-9]*mbit' | head -1; }
chain_of() { sed -n "/chain $1 {/,/}/p" "$T/nft_loaded"; }
: > "$T/tc_calls"
lim apply > /dev/null 2>&1
chk "no host file and no site figures: the built-in ones (200 Mbit/s; 20 a second, bursts to 100; 256 open)" \
    "$(cls jifb1)|$(status_key conn_rate)/$(status_key conn_burst)/$(status_key open_conns)|$(status_key set_by)" "rate 200mbit|20/100/256|"
chk "each site is told root's figure and the one in force" "$(tr '\n' ' ' < "$T/told/s1")" "state=on host_ceiling_mbit=200 ceiling_mbit=200 set_by= "
chk "a site not on a network of its own is told the limits do not cover it" "$(head -1 "$T/told/old")" "state=uncovered"

echo '{"ceiling_mbit": 300, "conn_rate": 40, "conn_burst": 120, "open_conns": 512, "set_by": "plane"}' > "$R/etc/joinery/outbound_limits.json"
: > "$T/tc_calls"
lim apply > /dev/null 2>&1
chk "the host file's figures: each site's class, chain and the status" \
    "$(cls jifb1)|$(chain_of site_s1 | grep -o -E 'ct count over [0-9]+|limit rate over [0-9]+/second burst [0-9]+' | tr '\n' '|')$(status_key ceiling_mbit)|$(status_key set_by)" \
    "rate 300mbit|ct count over 512|limit rate over 40/second burst 120|300|plane"
chk "the web server's user takes the machine's figures" "$(chain_of web_user | grep -c 'limit rate over 40/second burst 120')|$(cls jifbweb)" "1|rate 300mbit"
chk "a site is told the management node set them" "$(grep -c '^set_by=plane$' "$T/told/s1")" "1"

echo '{"ceiling_mbit": "fast", "conn_rate": 0, "open_conns": true}' > "$R/etc/joinery/outbound_limits.json"
: > "$T/tc_calls"
lim apply > /dev/null 2> "$T/err"
chk "a figure that is not one: the built-in one stands, with a warning naming it" \
    "$(cls jifb1)|$(status_key conn_rate)|$(status_key open_conns)|$(grep -c -E 'has a (ceiling_mbit|conn_rate|open_conns) that is not a figure' "$T/err")" "rate 200mbit|20|256|3"

echo '{"ceiling_mbit": "off"}' > "$R/etc/joinery/outbound_limits.json"
lim apply > /dev/null 2>&1
chk "the machine's ceiling off: no ifb anywhere, no mark on the web server's user, the connection limits on" \
    "$(ls "$T/links" | grep -c '^jifb')|$(grep -c 'meta mark set' "$T/nft_loaded")|$(status_key state)|$(status_key ceiling_mbit)" "0|0|on|"
rm -f "$R/etc/joinery/outbound_limits.json"

spec s1 spec_version=2 network=s1_net bridge=jsnet1 subnet=10.250.1.0/24 outbound_ceiling=50 outbound_conn_rate=5
spec s4 spec_version=2 bridge=jsnet4 outbound_ceiling=off
: > "$T/tc_calls"
lim apply > /dev/null 2>&1
chk "a site's own figures in its run spec: its class and its chain; the rest keep the machine's" \
    "$(cls jifb1)|$(chain_of site_s1 | grep -c 'limit rate over 5/second burst 100')|$(cls jifbweb)" "rate 50mbit|1|rate 200mbit"
chk "a site whose own ceiling is off has no ifb, and is still limited" "$(ls "$T/links/jifb4" 2>/dev/null)|$(grep -c 'jump site_s4' "$T/nft_loaded")" "|1"
chk "the status lists each site's figures in force" "$(status_key site_figures)" "s1:50:5:100:256 s4:-:20:100:256"
spec s1 spec_version=2 network=s1_net bridge=jsnet1 subnet=10.250.1.0/24 outbound_ceiling=fast
lim apply > /dev/null 2> "$T/err"
chk "a run spec figure that is not one: the machine's stands, with a warning" "$(status_key site_figures | cut -d' ' -f1)|$(grep -c "s1's run spec has outbound_ceiling=fast" "$T/err")" "s1:200:20:100:256|1"
spec s1 spec_version=2 network=s1_net bridge=jsnet1 subnet=10.250.1.0/24
spec s4 spec_version=2 bridge=jsnet4

echo "30" > "$T/own/s1"
: > "$T/tc_calls"
lim apply > /dev/null 2>&1
chk "a site's own lower ceiling (read inside it as the web server's user) holds it lower" \
    "$(cls jifb1)|$(grep -c 'docker exec -u www-data s1 timeout 20 php /var/www/html/s1/public_html/utils/outbound_site_ceiling.php' "$T/docker_calls")|$(tr '\n' ' ' < "$T/told/s1")" \
    "rate 30mbit|$(grep -c 'exec -u www-data s1 ' "$T/docker_calls")|state=on host_ceiling_mbit=200 ceiling_mbit=30 set_by= "
echo "900" > "$T/own/s1"
: > "$T/tc_calls"
lim apply > /dev/null 2>&1
chk "a site asking for more than root's figure gets root's: it can tighten, never loosen" "$(cls jifb1)|$(grep -c '^ceiling_mbit=200$' "$T/told/s1")" "rate 200mbit|1"
echo "40" > "$T/own/s1"
out="$(lim apply 2>&1)"
chk "a run that moves only a ceiling (the table as it was) says what is in force, never unchanged" \
    "$(grep -c 'outbound limits: speed ceiling now in force: s1 40 Mbit/s, s4 200 Mbit/s' <<< "$out")|$(grep -c 'outbound limits: unchanged' <<< "$out")" "1|0"
out="$(lim apply 2>&1)"
chk "and the run after it, changing nothing, says unchanged" "$(grep -c 'outbound limits: unchanged' <<< "$out")" "1"
for junk in '30; reboot' '0' '-5' '2000000' 'thirty'; do
    printf '%s\n' "$junk" > "$T/own/s1"; : > "$T/tc_calls"
    lim apply > /dev/null 2>&1
    chk "what a site prints is untrusted: '$junk' is nothing" "$(cls jifb1)" "rate 200mbit"
done
rm -f "$T/own/s1"

B="$R/var/www/html"
mkdir -p "$B/bm1/config" "$B/bm1/public_html/utils" "$B/bm2/config" "$B/bm2/public_html/utils" "$B/notasite"
touch "$B/bm1/config/Globalvars_site.php" "$B/bm1/public_html/utils/outbound_site_ceiling.php" "$B/bm2/config/Globalvars_site.php" "$B/bm2/public_html/utils/outbound_site_ceiling.php"
echo 60 > "$T/bare_own/bm1"; echo 25 > "$T/bare_own/bm2"
: > "$T/tc_calls"
lim apply --web-user > /dev/null 2>&1
chk "bare metal: the lowest of the machine's sites' own ceilings holds the web server's user, run as that user" \
    "$(cls jifbweb)|$(status_key web_ceiling_mbit)|$(grep -c 'runuser -u www-data -- timeout 20 php' "$T/runuser_calls")" "rate 25mbit|25|2"
chk "the bare-metal sites are told, on the host" "$(tr '\n' ' ' < "$R/run/joinery/outbound_limits.site")" "state=on host_ceiling_mbit=200 ceiling_mbit=25 set_by= "
chk "status says a site asked for less" "$(lim status | grep -c "sending at 25 Mbit/s at most (a site asked for less)")" "1"
rm -rf "$B"
lim apply --web-user > /dev/null 2>&1

# Every outcome is told, so a site never shows a figure left from an earlier run.
echo '{"enabled": false}' > "$R/etc/joinery/outbound_limits.json"
lim apply --web-user > /dev/null 2>&1
chk "the limits off: every site is told so, with no figure in force, and the bare-metal sites too" \
    "$(tr '\n' ' ' < "$T/told/s1")|$(head -1 "$T/told/old")|$(head -1 "$R/run/joinery/outbound_limits.site")" "state=off host_ceiling_mbit= ceiling_mbit= set_by= |state=off|state=off"
rm -f "$R/etc/joinery/outbound_limits.json"
printf 'nameserver 8.8.8.8\n' > "$R/etc/resolv.conf"
lim apply --web-user > /dev/null 2>&1
chk "the limits refused (an outside resolver): every site is told so" "$(head -1 "$T/told/s1")|$(head -1 "$R/run/joinery/outbound_limits.site")" "state=refused|state=refused"
echo "nameserver 127.0.0.53" > "$R/etc/resolv.conf"
STUB_TC_RC=1 JOINERY_LIMITS_ROOT="$R" PATH="$T/bin:$PATH" bash "$SCRIPT" apply --web-user > /dev/null 2>&1
chk "tc refusing the ceiling: each site is told, after the attempt, with root's figure and none in force" "$(tr '\n' ' ' < "$T/told/s1")" "state=ceiling_failed host_ceiling_mbit=200 ceiling_mbit= set_by= "
lim apply --web-user > /dev/null 2>&1
chk "and on again once tc takes it" "$(head -1 "$T/told/s1")|$(sed -n 's/^ceiling_mbit=//p' "$T/told/s1")" "state=on|200"

echo "=== Setting the figures ==="
: > "$T/systemctl_calls"
out="$(lim set --ceiling=500 --conn-rate=30 2>&1)"; rc=$?
chk "set: the machine's figures in the host file, the unit run, and shown" \
    "$rc|$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("ceiling_mbit"), d.get("conn_rate"), d.get("set_by"))' "$R/etc/joinery/outbound_limits.json")|$(grep -c 'start joinery-limits.service' "$T/systemctl_calls")|$(status_key ceiling_mbit)|$(grep -c 'speed ceiling: 500 Mbit/s' <<< "$out")" \
    "0|500 30 None|1|500|1"
lim set --ceiling=400 --by=plane > /dev/null 2>&1
chk "set --by=plane: the management node set them, and the other figures stay" \
    "$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("ceiling_mbit"), d.get("conn_rate"), d.get("set_by"))' "$R/etc/joinery/outbound_limits.json")" "400 30 plane"
lim conn-rate default > /dev/null 2>&1
chk "a later change by hand clears 'set by the management node'; default takes a figure back to the built-in one" \
    "$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d.get("ceiling_mbit"), d.get("conn_rate"), d.get("set_by"))' "$R/etc/joinery/outbound_limits.json")|$(status_key conn_rate)" "400 None None|20"
lim ceiling 80 --site=s1 > /dev/null 2>&1; rc=$?
chk "ceiling --site: the site's run spec carries it, through the run spec helper, and it is in force" \
    "$rc|$(grep -c '^outbound_ceiling=80$' "$R/etc/joinery/sites/s1/run_spec")|$(grep -c '^bridge=jsnet1$' "$R/etc/joinery/sites/s1/run_spec")|$(status_key site_figures | cut -d' ' -f1)" "0|1|1|s1:80:20:100:256"
lim ceiling default --site=s1 > /dev/null 2>&1
chk "default --site: the line is gone and the machine's figure holds" "$(grep -c '^outbound_' "$R/etc/joinery/sites/s1/run_spec")|$(status_key site_figures | cut -d' ' -f1)" "0|s1:400:20:100:256"
out="$(lim ceiling 80 --site=s1 --by=plane 2>&1)"
chk "a site's own set --by=plane: its run spec says so, the status names it, it alone is told the management node set them, and show says so" \
    "$(grep -c '^outbound_set_by=plane$' "$R/etc/joinery/sites/s1/run_spec")|$(status_key site_set_by)|$(grep -c '^set_by=plane$' "$T/told/s1")|$(grep -c '^set_by=$' "$T/told/s4")|$(grep -c 's1: ceiling 80 Mbit/s.*(its own figures, set by the management node)' <<< "$out")" \
    "1|s1|1|1|1"
lim ceiling 90 --site=s1 > /dev/null 2>&1
chk "the same site's own set by hand on the machine: no longer the management node's" \
    "$(grep -c '^outbound_set_by=' "$R/etc/joinery/sites/s1/run_spec")|$(status_key site_set_by)|$(grep -c '^set_by=$' "$T/told/s1")" "0||1"
lim ceiling default --site=s1 > /dev/null 2>&1
for bad in "set" "set --ceiling=0" "set --ceiling=fast" "set --conn-rate=off" "set --open-conns=-3" "ceiling 50 --site=nosuch" "ceiling 50 --site=../etc" "apply --ceiling=50" "status --site=s1"; do
    lim $bad > /dev/null 2>&1; rc=$?
    chk "refused: joinery-limits $bad" "$rc" "1"
done
chk "a refused set wrote nothing" "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("ceiling_mbit"))' "$R/etc/joinery/outbound_limits.json")" "400"
out="$(lim show 2>&1)"
chk "show: the machine's figures and each site's" "$(grep -c -E '^  speed ceiling: 400 Mbit/s$|^  new connections: 20 a second, bursts to 100; 256 open at once$|^  s1: ceiling 400 Mbit/s' <<< "$out")" "3"
rm -f "$R/etc/joinery/outbound_limits.json"

echo "=== Refresh (host housekeeping) ==="
: > "$T/systemctl_calls"
out="$(lim refresh 2>&1)"
chk "the installed copy current: nothing written, nothing run" "$out|$(wc -l < "$T/systemctl_calls")" "outbound limits: installed copy current|0"
sed -i 's/^# Version: .*/# Version: 1.1/' "$R/usr/local/sbin/joinery-limits"
out="$(lim refresh 2>&1)"
chk "an older installed copy: brought up to this one, and the unit run" \
    "$(cmp -s "$SCRIPT" "$R/usr/local/sbin/joinery-limits" && echo same)|$(grep -c 'brought up to this release' <<< "$out")|$(grep -c -E '^systemctl (daemon-reload|start joinery-limits.service)$' "$T/systemctl_calls")" "same|1|2"
chk "the run spec helper is installed beside it" "$(cmp -s "$HELPER" "$R/usr/local/lib/joinery-limits/_site_run_spec.sh" && echo same)" "same"
sed -i 's/^# Version: .*/# Version: 9.9/' "$R/usr/local/sbin/joinery-limits"; : > "$T/systemctl_calls"
out="$(lim refresh 2>&1)"; lim ceiling 300 > /dev/null 2>&1
chk "a newer installed copy (another site's tree, a newer bundle) is never taken back, by refresh or by a figure set" \
    "$(script_v="$(sed -n 's/^# Version: //p' "$R/usr/local/sbin/joinery-limits")"; echo "$script_v")|$out|$(grep -c daemon-reload "$T/systemctl_calls")" "9.9|outbound limits: installed copy current|0"
cp "$SCRIPT" "$R/usr/local/sbin/joinery-limits"
echo "# a hand edit at the same version" >> "$R/usr/local/sbin/joinery-limits"
chk "a copy at the same version is current, so two trees at one release never flap" "$(lim refresh 2>&1)" "outbound limits: installed copy current"
cp "$SCRIPT" "$R/usr/local/sbin/joinery-limits"
sed -i 's/^# Version: [0-9.]*/# Version: 1.0/' "$R/usr/local/lib/joinery-limits/_site_run_spec.sh"
out="$(lim refresh 2>&1)"
chk "a release that changes only the run spec helper brings the helper's copy up to it" \
    "$(cmp -s "$HELPER" "$R/usr/local/lib/joinery-limits/_site_run_spec.sh" && echo same)|$(grep -c 'brought up to this release' <<< "$out")" "same|1"
rm -f "$R/etc/joinery/outbound_limits.json"
lim install --by=plane > /dev/null 2>&1
chk "install --by=plane with no figure records that the management node installed it" \
    "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("set_by"))' "$R/etc/joinery/outbound_limits.json")|$(status_key set_by)" "plane|plane"
lim install > /dev/null 2>&1
chk "a reinstall without it leaves that as it was" "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("set_by"))' "$R/etc/joinery/outbound_limits.json")" "plane"
rm -f "$R/etc/joinery/outbound_limits.json"; lim apply > /dev/null 2>&1
NR="$T/noroot"; mkdir -p "$NR"
chk "a machine without the unit is left alone" "$(JOINERY_LIMITS_ROOT="$NR" PATH="$T/bin:$PATH" bash "$SCRIPT" refresh 2>&1)|$(ls "$NR")" "outbound limits: not installed on this machine|"
HK="$ROOT/maintenance_scripts/install_tools/host_housekeeping.sh"
chk "host housekeeping runs refresh where the unit is installed, and only outside a container" \
    "$(awk '/^# --- 10\. The outbound limits run this release/,/^fi$/' "$HK" | grep -o -E 'IN_CONTAINER.. == 0|joinery-limits.service|outbound_limits.sh" refresh' | wc -l)" "3"
chk "the host's support bundle carries the script and the helper it sources" \
    "$(grep -c -E "'maintenance_scripts/install_tools/(outbound_limits|_site_run_spec)\.sh'," "$ROOT/public_html/plugins/server_manager/includes/SupportBundlePublisher.php")" "2"

echo "=== install.sh's figures ==="
chk "docker and server take the figure flags, and pass them to the install" \
    "$(awk '/^do_docker_install\(\) \{/,/^}$/' "$INSTALL" | grep -c 'consume_outbound_flag')|$(awk '/^do_server\(\) \{/,/^}$/' "$INSTALL" | grep -c 'consume_outbound_flag')|$(awk '/^outbound_limits_install\(\) \{/,/^}$/' "$INSTALL" | grep -c 'OUTBOUND_FIGURE_ARGS')" "1|1|1"
( set +u
  print_error() { echo "ERR $*"; }
  eval "$(awk '/^consume_outbound_flag\(\) \{/,/^}$/' "$INSTALL")"
  OUTBOUND_FIGURE_ARGS=()
  for a in --outbound-ceiling=150 --outbound-ceiling=off --outbound-conn-rate=default --outbound-open-conns=300 --outbound-set-by=plane; do consume_outbound_flag "$a"; done
  echo "${OUTBOUND_FIGURE_ARGS[*]}" > "$T/obf"
  ( consume_outbound_flag --outbound-ceiling=fast ) > "$T/obf_bad" 2>&1; echo "rc=$?" >> "$T/obf_bad"
  consume_outbound_flag --memory=1g; echo "notours=$?" >> "$T/obf_bad" )
chk "install.sh turns its flags into the script's, the management node's mark too" "$(cat "$T/obf")" "--ceiling=150 --ceiling=off --conn-rate=default --open-conns=300 --by=plane"
chk "and refuses one that is not a figure, in plain words; another flag is not its" "$(grep -c 'is not a speed ceiling' "$T/obf_bad")|$(grep -c 'rc=1' "$T/obf_bad")|$(grep -c 'notours=1' "$T/obf_bad")" "1|1|1"
chk "a container site keeps its own figures across a rebuild, and a given one replaces its line" \
    "$(grep -c 'SPEC_OUTBOUND="$(run_spec_outbound_lines "$SITENAME")"' "$INSTALL")|$(grep -c '\[ -z "$SPEC_OUTBOUND" \] || printf' "$INSTALL")" "1|1"
chk "a bare-metal site's figures are refused: they are the machine's" "$(grep -c 'a bare-metal site shares the machine' "$INSTALL")" "1"
chk "--outbound-notice-gb reaches the site's first start: exported, carried into a container, and written as the setting" \
    "$(grep -c -E '^    JOINERY_OUTBOUND_NOTICE_GB$|export JOINERY_OUTBOUND_NOTICE_GB=' "$INSTALL")|$(grep -c -E "UPDATE stg_settings SET stg_value = .\\$\{JOINERY_OUTBOUND_NOTICE_GB\}. WHERE stg_name = 'outbound_monthly_notice_gb'" "$ROOT/maintenance_scripts/install_tools/_site_init.sh")" "2|1"
(
  export JOINERY_SITE_STATE_ROOT="$T/rs"; mkdir -p "$T/rs/etc/joinery/sites/x"
  . "$HELPER"
  printf 'spec_version=2\nhostname=x\noutbound_ceiling=off\noutbound_conn_rate=40\noutbound_set_by=plane\n' | run_spec_write x && echo written
  printf 'spec_version=2\nhostname=x\noutbound_ceiling=0\n' | run_spec_write x 2>/dev/null || echo refused
  printf 'spec_version=2\nhostname=x\noutbound_open_conns=lots\n' | run_spec_write x 2>/dev/null || echo refused
  printf 'spec_version=2\nhostname=x\noutbound_set_by=someone\n' | run_spec_write x 2>/dev/null || echo refused
  run_spec_outbound_lines x | tr '\n' ' '
) > "$T/rs_out"
chk "the run spec takes a site's own figures and who set them, refuses one that is not, and keeps all of them for a rebuild" \
    "$(tr '\n' '|' < "$T/rs_out")" "written|refused|refused|refused|outbound_ceiling=off outbound_conn_rate=40 outbound_set_by=plane "
# install.sh site's own lines for the spec: the block that folds the figure flags in, run as written.
ob_fold() {  # SPEC_OUTBOUND FLAGS...
    ( set +u; SPEC_OUTBOUND="$1"; shift; OUTBOUND_FIGURE_ARGS=("$@")
      eval "$(awk '/# The site.s own outbound figures \(outbound_limits.sh, WP5\)/,/# \(end of the limits check\)/' "$INSTALL" | sed 's/^    local .*//')"
      printf '%s' "$SPEC_OUTBOUND" | sort | tr '\n' ' ' )
}
chk "install.sh site takes --outbound-set-by=plane on its command line" \
    "$(grep -A1 -- '--outbound-ceiling=\*|--outbound-conn-rate=\*|--outbound-conn-burst=\*|--outbound-open-conns=\*|--outbound-set-by=plane)$' "$INSTALL" | grep -c 'consume_outbound_flag "\$1"')" "1"
chk "install.sh site --outbound-set-by=plane writes outbound_set_by=plane, a line the run spec takes" \
    "$(ob_fold 'outbound_conn_rate=40' --ceiling=50 --by=plane)" "outbound_ceiling=50 outbound_conn_rate=40 outbound_set_by=plane "
chk "a figure given by hand drops the management node's mark; a rebuild giving none keeps it" \
    "$(ob_fold $'outbound_ceiling=50\noutbound_set_by=plane' --ceiling=60)|$(ob_fold $'outbound_ceiling=50\noutbound_set_by=plane')" \
    "outbound_ceiling=60 |outbound_ceiling=50 outbound_set_by=plane "

echo "=== The run spec helper's refresh ==="
: > "$T/systemctl_calls"
SR="$T/state"; mkdir -p "$SR"
( export JOINERY_SITE_STATE_ROOT="$SR" PATH="$T/bin:$PATH"; . "$HELPER"; run_spec_limits_refresh )
chk "no joinery-limits unit on the host: nothing is run" "$(wc -l < "$T/systemctl_calls")" "0"
mkdir -p "$SR/etc/systemd/system" && touch "$SR/etc/systemd/system/joinery-limits.service"
cat > "$T/bin/systemctl" <<STUB
#!/bin/bash
echo "systemctl \$*" >> "$T/systemctl_calls"; exit \${STUB_SYSTEMCTL_RC:-0}
STUB
( export JOINERY_SITE_STATE_ROOT="$SR" PATH="$T/bin:$PATH"; . "$HELPER"; run_spec_limits_refresh )
chk "with the unit: it is started, once" "$(cat "$T/systemctl_calls")" "systemctl start joinery-limits.service"
out="$( export JOINERY_SITE_STATE_ROOT="$SR" PATH="$T/bin:$PATH" STUB_SYSTEMCTL_RC=1; . "$HELPER"; run_spec_limits_refresh 2>&1; echo "rc=$?")"
chk "a unit that fails warns and never fails its caller" "$(grep -c 'WARNING - the outbound limits did not follow' <<< "$out")|$(tail -1 <<< "$out")" "1|rc=0"

echo "=== Its callers ==="
dock="$(awk '/^do_docker_install\(\) \{/,/^}$/' "$INSTALL")"
chk "install.sh docker takes --no-outbound-limits" "$(grep -c -- '--no-outbound-limits) NO_OUTBOUND_LIMITS=1 ;;' <<< "$dock")" "1"
chk "install.sh docker installs the limits on both its paths, before housekeeping" \
    "$(grep -A1 'outbound_limits_install 0 "$NO_OUTBOUND_LIMITS"' <<< "$dock" | grep -c 'host_housekeeping$')" "2"
chk "install.sh says what the unit did (an owner's earlier off stands), not what was asked" \
    "$(awk '/^outbound_limits_install\(\) \{/,/^}$/' "$INSTALL" | grep -c "grep -qx 'state=off' /run/joinery/outbound_limits.status")" "1"
chk "install.sh server runs do_server, which limits the web server's user outside an image build" \
    "$(grep -c '^        do_server "\$@"$' "$INSTALL")|$(awk '/^do_server\(\) \{/,/^}$/' "$INSTALL" | grep -c 'is_docker || outbound_limits_install 1 "\$NO_OUTBOUND_LIMITS"')" "1|1"
chk "do_server_setup, which the base image hashes, is untouched by the limits" \
    "$(awk '/^do_server_setup\(\) \{/,/^}$/' "$INSTALL" | grep -c -i 'outbound')" "0"
chk "install.sh site brings a new container under the limits once it starts" \
    "$(grep -A7 'print_success "Container started"' "$INSTALL" | grep -c 'run_spec_limits_refresh')" "1"
chk "the move script refreshes once every move is done" \
    "$(grep -B3 '^exit "\$status"$' "$ROOT/maintenance_scripts/sysadmin_tools/move_site_to_own_network.sh" | grep -c '^run_spec_limits_refresh$')" "1"
chk "remove_account.sh refreshes after the run spec is gone" \
    "$(awk '/for mark in held suspended run_spec[ ;]/ { m = 1 } /=== Removing Docker site ===/ { d = 1 } d && /^    clear_site_marks$/ { f = 1 } m && f && /systemctl start joinery-limits.service/ { print "after"; exit }' "$ROOT/maintenance_scripts/sysadmin_tools/remove_account.sh")" "after"

echo
echo "outbound_limits gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
