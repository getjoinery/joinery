#!/bin/bash
# @joinery-test
# name: outbound_limits
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/install_tools/outbound_limits.sh, maintenance_scripts/install_tools/_site_run_spec.sh]
#
# outbound_limits.sh (node_outbound_and_transfer WP3, WP4) writes one nftables
# table limiting what each site opens toward the outside, and a speed ceiling.
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
    "$rc|$(ls "$T/links/jifb4" > /dev/null 2>&1 && echo ifb)|$(grep -c '^jsnet4 ingress jifb4$' "$R/run/joinery/outbound_limits.shaped")|$(grep -c 'would not take the speed ceiling off jsnet4' "$T/err")|$(status_key reason)" \
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
    "$(awk '/for mark in held suspended run_spec; do/ { m = 1 } /=== Removing Docker site ===/ { d = 1 } d && /^    clear_site_marks$/ { f = 1 } m && f && /systemctl start joinery-limits.service/ { print "after"; exit }' "$ROOT/maintenance_scripts/sysadmin_tools/remove_account.sh")" "after"

echo
echo "outbound_limits gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
