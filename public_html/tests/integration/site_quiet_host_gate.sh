#!/bin/bash
# @joinery-test
# name: site_quiet_host
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/sysadmin_tools/site_quiet_host.sh, maintenance_scripts/sysadmin_tools/discard_copy.sh]
#
# The host's half of a site container's quiet state
# (specs/site_container_engine.md D9): a table in the site's own network
# namespace, every account but the container's root refused. Driven
# unprivileged against a fixture /etc and /proc (SITE_QUIET_HOST_ETC,
# SITE_QUIET_HOST_PROC) with the engine, nsenter and nft stubbed on PATH. What
# the kernel does with the table is the box proof's (se1, 2026-10-14).

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/site_quiet_host.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; echo "RESULT: PASS 0 0"; exit 0; fi

# --- Stubs ---------------------------------------------------------------
# The engine: a site runs when $ST/run.SITE holds its pid; exec test -e answers
# from $ST/exec.SITE (an exit code). nsenter passes the namespace's pid to nft,
# which keeps one table file per pid.
B="$T/bin"; ST="$T/stub"; mkdir -p "$B" "$ST"
cat > "$B/podman" <<EOF
#!/bin/bash
echo "podman \$*" >> "$ST/calls"
case "\$1" in
  ps) cat "$ST/containers" ;;
  inspect)
    site="\${@: -1}"
    if [ "\$3" = '{{.State.Running}}' ]; then [ -f "$ST/run.\$site" ] && echo true || echo false
    elif [ "\$3" = '{{.State.Pid}}' ]; then cat "$ST/run.\$site" 2>/dev/null
    elif [ "\$3" = '{{range .Config.Env}}{{println .}}{{end}}' ]; then echo "PATH=/usr/bin"; [ "\$site" = mailgw ] && echo SITENAME=other || echo "SITENAME=\$site"; fi ;;
  exec) site="\$2"; exit "\$(cat "$ST/exec.\$site" 2>/dev/null || echo 1)" ;;
  cp)
    # cp SITE:PATH - : the site's own state file as a tar stream, from
    # \$ST/own.SITE (a file), \$ST/ownlink.SITE (a link), or nothing.
    site="\${2%%:*}"; d="\$(mktemp -d)"
    if [ -f "$ST/own.\$site" ]; then cp "$ST/own.\$site" "\$d/state"
    elif [ -f "$ST/ownlink.\$site" ]; then ln -s /etc/shadow "\$d/state"
    else rm -rf "\$d"; exit 125; fi
    tar -cf - -C "\$d" state; rm -rf "\$d" ;;
esac
EOF
cat > "$B/nsenter" <<EOF
#!/bin/bash
echo "nsenter \$*" >> "$ST/calls"
[ -f "$ST/nsenter_fails" ] && exit 1
ns="\${1#--net=}"; pid="\$(basename "\$(dirname "\$(dirname "\$ns")")")"
shift
NETNS_PID="\$pid" "\$@"
EOF
cat > "$B/nft" <<EOF
#!/bin/bash
echo "nft[\$NETNS_PID] \$*" >> "$ST/calls"
case "\$1" in
  -f) cp "\$2" "$ST/table.\$NETNS_PID" ;;
  list) [ -f "$ST/table.\$NETNS_PID" ] ;;
  delete) [ -f "$ST/nft_delete_fails" ] && exit 1; rm -f "$ST/table.\$NETNS_PID" ;;
esac
EOF
chmod +x "$B"/*
export PATH="$B:$PATH" JOINERY_SITE_ENGINE_KIND=podman
E="$T/etc"; P="$T/proc"; mkdir -p "$E/joinery/sites" "$P"
export SITE_QUIET_HOST_ETC="$E" SITE_QUIET_HOST_PROC="$P"
proc() { mkdir -p "$P/$1/ns"; printf '%10s %10s %10s\n' 0 "$2" 65536 > "$P/$1/uid_map"; }
printf '%s\n' sa sb sd mailgw > "$ST/containers"
proc 4101 2147483648
proc 4102 0
q() { bash "$SCRIPT" "$@"; }

echo "=== on: the record, and the table in the running container ==="
echo 4101 > "$ST/run.sa"
out="$(q on sa copy 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "the host records the reason" "$(cat "$E/joinery/sites/sa/quiet")" "copy"
chk "the table went into the site's own namespace" "$(grep -c "nsenter --net=$P/4101/ns/net nft -f" "$ST/calls")" "1"
chk "every account but the container's root is refused" "$(grep -c 'meta skuid != { 0, 2147483648 }' "$ST/table.4101")" "2"
chk "loopback first, then replies, then the refusal" "$(grep -n -E 'oifname "lo" accept|ct direction reply accept|meta skuid' "$ST/table.4101" | cut -d: -f1 | tr '\n' ' ')" \
    "$(grep -n 'oifname "lo" accept' "$ST/table.4101" | cut -d: -f1) $(( $(grep -n 'oifname "lo" accept' "$ST/table.4101" | cut -d: -f1) + 1 )) $(( $(grep -n 'oifname "lo" accept' "$ST/table.4101" | cut -d: -f1) + 2 )) $(( $(grep -n 'oifname "lo" accept' "$ST/table.4101" | cut -d: -f1) + 3 )) "
chk "the file replaces the table whole" "$(grep -c '^delete table inet joinery_site_state' "$ST/table.4101")" "1"
chk "and it says so" "$(printf '%s' "$out" | grep -c 'reaches nothing past loopback')" "1"

echo "=== a site whose root is the host's root (Docker without a remap) ==="
echo 4102 > "$ST/run.sd"
q on sd switchover >/dev/null 2>&1; rc=$?
chk "exit 0" "$rc" "0"
chk "the set is root alone" "$(grep -c 'meta skuid != { 0 }' "$ST/table.4102")" "2"

echo "=== on with the container stopped: the record alone ==="
out="$(q on sb copy 2>&1)"; rc=$?
chk "exit 0, recorded, nothing entered" "$rc:$(cat "$E/joinery/sites/sb/quiet"):$(grep -c 'nsenter' <(grep -v -e 4101 -e 4102 "$ST/calls"))" "0:copy:0"
chk "and it says when the table comes" "$(printf '%s' "$out" | grep -c 'gets its table when it starts')" "1"

echo "=== refusals ==="
q on 'Sa;rm' copy >/dev/null 2>&1; chk "a name that is not a site's: 2" "$?" "2"
q on sa live >/dev/null 2>&1; chk "a reason that is neither: 2" "$?" "2"
q on mailgw copy >/dev/null 2>&1; chk "a container that is not its own site: 2" "$?" "2"
q on sz copy >/dev/null 2>&1; chk "a name with no container: 2" "$?" "2"
chk "nothing recorded for either" "$(ls "$E/joinery/sites" | tr '\n' ' ')" "sa sb sd "

echo "=== status ==="
chk "each recorded site, its container, its table" "$(q status | tr '\n' '|')" "sa quiet=copy running=yes table=yes|sb quiet=copy running=no table=no|sd quiet=switchover running=yes table=yes|"
chk "one site" "$(q status sb)" "sb quiet=copy running=no table=no"

echo "=== forward: a frozen site may reach its copy, and nothing else ==="
out="$(q forward sa 198.51.100.9 2>&1)"; rc=$?
chk "a dormant copy does not forward: refused" "$rc:$([ -e "$E/joinery/sites/sa/forward" ] && echo file || echo none)" "2:none"
: > "$ST/calls"
out="$(q forward sd 198.51.100.9 2>&1)"; rc=$?
chk "a site frozen for a switch-over forwards: exit 0, recorded" "$rc:$(cat "$E/joinery/sites/sd/forward")" "0:198.51.100.9"
chk "its table lets every account reach that one address on 80 and 443, before the refusal" "$(grep -c 'ip daddr 198.51.100.9 tcp dport { 80, 443 } accept' "$ST/table.4102"):$(( $(grep -n -m1 'daddr' "$ST/table.4102" | cut -d: -f1) < $(grep -n -m1 'reject' "$ST/table.4102" | cut -d: -f1) ))" "1:1"
chk "status names the forward" "$(q status sd)" "sd quiet=switchover running=yes table=yes forward=198.51.100.9"
q forward sd '198.51.100.9 tcp dport 22' >/dev/null 2>&1
chk "anything but an address: 2, the forward unchanged" "$?:$(cat "$E/joinery/sites/sd/forward")" "2:198.51.100.9"
rm -f "$ST/table.4102"; q assert >/dev/null 2>&1
chk "a table loaded again keeps the forward" "$(grep -c 'ip daddr 198.51.100.9' "$ST/table.4102")" "1"
q forward sd 2001:db8::9 >/dev/null 2>&1
chk "an IPv6 copy" "$(grep -c 'ip6 daddr 2001:db8::9 tcp dport' "$ST/table.4102")" "1"

echo "=== assert: a table removed by hand is back, a loaded one is left ==="
rm -f "$ST/table.4101"; : > "$ST/calls"
out="$(q assert 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "sa's table loaded again" "$([ -f "$ST/table.4101" ] && echo yes):$(printf '%s' "$out" | grep -c 'sa: table loaded again')" "yes:1"
chk "sd's, still loaded, not written again" "$(grep -c "nsenter --net=$P/4102/ns/net nft -f" "$ST/calls")" "0"
touch "$ST/nsenter_fails"; rm -f "$ST/table.4101"
q assert >/dev/null 2>&1; chk "a table that cannot load: 1" "$?" "1"
rm -f "$ST/nsenter_fails"; q assert >/dev/null 2>&1

echo "=== off: refused until the site has left its own quiet state ==="
echo 0 > "$ST/exec.sa"
out="$(q off sa 2>&1)"; rc=$?
chk "the site still says it is quiet: 2" "$rc" "2"
chk "the table and the record stay" "$([ -f "$ST/table.4101" ] && echo t):$(cat "$E/joinery/sites/sa/quiet")" "t:copy"
chk "it asked inside the container, for the site's own state file" "$(grep -c 'podman exec sa test -e /etc/joinery/sites/sa/state' "$ST/calls")" "1"
echo 125 > "$ST/exec.sa"
q off sa >/dev/null 2>&1; chk "an answer that is neither yes nor no: 2, kept" "$?:$([ -f "$ST/table.4101" ] && echo t)" "2:t"
q off sb >/dev/null 2>&1; chk "a stopped container cannot be asked: 2, kept" "$?:$(cat "$E/joinery/sites/sb/quiet")" "2:copy"
echo 1 > "$ST/exec.sa"
touch "$ST/nft_delete_fails"
q off sa >/dev/null 2>&1; chk "a table that does not come off: 1, the record kept" "$?:$(cat "$E/joinery/sites/sa/quiet")" "1:copy"
rm -f "$ST/nft_delete_fails"
out="$(q off sa 2>&1)"; rc=$?
chk "left: exit 0, the table off, the record gone" "$rc:$([ -f "$ST/table.4101" ] && echo t || echo none):$([ -e "$E/joinery/sites/sa/quiet" ] && echo kept || echo gone)" "0:none:gone"
q off sa >/dev/null 2>&1; chk "off again: nothing to do, 0" "$?" "0"

echo "=== the hook: a recorded site gets its table before its first process ==="
proc 4201 2147549184
: > "$ST/calls"
state='{"ociVersion":"1.0.2","id":"abc","status":"created","pid":4201,"bundle":"/x","annotations":{"io.container.manager":"libpod","io.joinery.site":"sb"}}'
printf '%s' "$state" | q hook; rc=$?
chk "exit 0, the table in the new namespace" "$rc:$(grep -c 'meta skuid != { 0, 2147549184 }' "$ST/table.4201")" "0:2"
chk "the engine was never asked" "$(grep -c '^podman' "$ST/calls")" "0"
before="$(grep -c nsenter "$ST/calls")"
printf '%s' "${state/\"sb\"/\"sc\"}" | q hook; chk "a site with no record: 0, nothing entered" "$?:$(grep -c nsenter "$ST/calls")" "0:${before}"
printf '%s' '{"pid":4201,"annotations":{"io.container.manager":"libpod"}}' | q hook; chk "a container that is no site: 0" "$?" "0"
rm -f "$ST/table.4201"; touch "$ST/nsenter_fails"
printf '%s' "$state" | q hook 2>/dev/null; chk "a table that cannot load stops the start: 1" "$?" "1"
rm -f "$ST/nsenter_fails"
printf '%s' "${state/\"pid\":4201,/}" | q hook 2>/dev/null; chk "a recorded site with no pid stops the start: 1" "$?" "1"

echo "=== the record is read as the host's own file ==="
echo "go live" > "$T/elsewhere"; rm -f "$E/joinery/sites/sb/quiet"; ln -s "$T/elsewhere" "$E/joinery/sites/sb/quiet"
chk "a link is not a record" "$(q status sb)" ""
rm -f "$E/joinery/sites/sb/quiet"; echo "nonsense" > "$E/joinery/sites/sb/quiet"
chk "a record that says anything else holds as switchover" "$(q status sb | cut -d' ' -f2)" "quiet=switchover"

echo "=== a dormant copy's record stays a copy's ==="
echo 4101 > "$ST/run.sa"; echo copy > "$E/joinery/sites/sa/quiet"
q on sa switchover >/dev/null 2>&1; chk "on switchover over a copy: refused, the record kept" "$?:$(cat "$E/joinery/sites/sa/quiet")" "2:copy"
q on sa copy >/dev/null 2>&1; chk "on copy over any record: refused, a copy's is written once" "$?:$(cat "$E/joinery/sites/sa/quiet")" "2:copy"
echo switchover > "$E/joinery/sites/sa/quiet"
q on sa copy >/dev/null 2>&1; chk "on copy over a switch-over's record: refused too" "$?:$(cat "$E/joinery/sites/sa/quiet")" "2:switchover"
rm -f "$E/joinery/sites/sa/quiet"
q on sa copy >/dev/null 2>&1; chk "on copy where no record is: 0" "$?:$(cat "$E/joinery/sites/sa/quiet")" "0:copy"
rm -f "$E/joinery/sites/sa/quiet"

echo "=== off: a switch-over's lifts first, without asking the site ==="
echo 4101 > "$ST/run.sa"; echo switchover > "$E/joinery/sites/sa/quiet"; echo 0 > "$ST/exec.sa"
echo 198.51.100.9 > "$E/joinery/sites/sa/forward"
q assert >/dev/null 2>&1; : > "$ST/calls"
q off sa >/dev/null 2>&1; rc=$?
chk "  its forward goes with it" "$([ -e "$E/joinery/sites/sa/forward" ] && echo kept || echo gone)" "gone"
chk "lifted while the site still shows its 503 (its state file there)" "$rc:$([ -e "$E/joinery/sites/sa/quiet" ] && echo kept || echo gone):$([ -f "$ST/table.4101" ] && echo t || echo none)" "0:gone:none"
chk "  and the site was not asked" "$(grep -c 'podman exec' "$ST/calls")" "0"
echo 1 > "$ST/exec.sa"

echo "=== discard_copy: only a site its host records as a copy, and that says it is one ==="
DC="$T/dc"; mkdir -p "$DC/sysadmin_tools" "$DC/install_tools"
cp "$ROOT/maintenance_scripts/sysadmin_tools/discard_copy.sh" "$DC/sysadmin_tools/"
cp "$ROOT/maintenance_scripts/install_tools/_site_engine.sh" "$DC/install_tools/"
printf '#!/bin/bash\necho "remove_account $*" >> "%s/calls"\necho DECOMMISSION_VERIFIED "$1"\n' "$ST" > "$DC/sysadmin_tools/remove_account.sh"
dq() { DISCARD_COPY_ETC="$E" bash "$DC/sysadmin_tools/discard_copy.sh" "$@"; }
ran() { grep -c '^remove_account' "$ST/calls"; }
: > "$ST/calls"
echo copy > "$E/joinery/sites/sb/quiet"; echo "quiet copy" > "$ST/own.sb"
out="$(dq sb 2>&1)"; rc=$?
chk "a copy: removed through remove_account.sh, -y" "$rc:$(grep -c '^remove_account sb -y$' "$ST/calls"):$(printf '%s' "$out" | grep -c 'DECOMMISSION_VERIFIED sb')" "0:1:1"
chk "  its own state read out of its container" "$(grep -c 'podman cp sb:/etc/joinery/sites/sb/state -' "$ST/calls")" "1"
: > "$ST/calls"
echo "quiet switchover" > "$ST/own.sb"
dq sb >/dev/null 2>&1; chk "a copy whose own state is not quiet copy: refused (R2)" "$?:$(ran)" "2:0"
rm -f "$ST/own.sb"
dq sb >/dev/null 2>&1; chk "a copy that has left its quiet (no state file): refused" "$?:$(ran)" "2:0"
touch "$ST/ownlink.sb"
dq sb >/dev/null 2>&1; chk "a state file that is a link: refused" "$?:$(ran)" "2:0"
rm -f "$ST/ownlink.sb"; echo "quiet copy" > "$ST/own.sb"
echo switchover > "$E/joinery/sites/sb/quiet"; : > "$ST/calls"
dq sb >/dev/null 2>&1; chk "a site quiet for a switch-over: refused, nothing run" "$?:$(wc -l < "$ST/calls")" "2:0"
rm -f "$E/joinery/sites/sb/quiet"
dq sb >/dev/null 2>&1; chk "a site with no record (a live site): refused" "$?:$(wc -l < "$ST/calls")" "2:0"
echo copy > "$T/elsewhere"; ln -s "$T/elsewhere" "$E/joinery/sites/sb/quiet"
dq sb >/dev/null 2>&1; chk "a record that is a link: refused" "$?:$(wc -l < "$ST/calls")" "2:0"
rm -f "$E/joinery/sites/sb/quiet"
for bad in '' 'a' 'Sb' '../x' '-y'; do
    dq "$bad" >/dev/null 2>&1; chk "a name that is not a site's (${bad:-empty}): refused" "$?" "2"
done
rm -f "$DC/sysadmin_tools/remove_account.sh"; echo copy > "$E/joinery/sites/sb/quiet"
dq sb >/dev/null 2>&1; chk "no remove_account.sh beside it: refused" "$?" "2"
rm -f "$E/joinery/sites/sb/quiet"

echo "=== usage ==="
q 2>/dev/null; chk "no action: 2" "$?" "2"
q install >/dev/null 2>&1; chk "install is root's: 2" "$?" "2"

echo "RESULT: $([ "$failed" = 0 ] && echo PASS || echo FAIL) $passed $failed"
[ "$failed" = 0 ]
