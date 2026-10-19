#!/bin/bash
# @joinery-test
# name: copy_probes
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/copy_probes.sh]
#
# The copy_probes word's script (specs/site_copy_final_test.md, the rig's rows
# marked ‡): a row for each attempt the site must be refused, FAIL when one is
# let through, and nothing left behind either way. Driven unprivileged against
# a fixture site tree, /etc and engine list, with the web user's commands
# answered by stubs: walls that hold, then walls that do not.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/copy_probes.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; echo "RESULT: PASS 0 0"; exit 0; fi

mkdir -p "$T/bin" "$T/sites/demo/public_html" "$T/etc/apache2/conf-enabled" "$T/etc/apache2/sites-enabled"
echo '<?php' > "$T/sites/demo/public_html/serve.php"
# root, as far as the script can tell
printf '#!/bin/sh\necho 0\n' > "$T/bin/id"
# The web user's commands. GATE_WALLS=hold refuses every attempt; open lets them through.
cat > "$T/bin/runuser" <<'EOF'
#!/bin/bash
shift 3   # -u www-data --
case "$1 ${2:-}" in
    "unshare -Urm") [ "$GATE_WALLS" = open ] && { echo MOUNTED; exit 0; }
                    echo "unshare: write failed /proc/self/uid_map: Operation not permitted"; exit 1 ;;
    "mount -t")     [ "$GATE_WALLS" = open ] && exit 0; exit 32 ;;
    "perl -e")      [ "$3" = 1 ] && exit 0
                    [ "$GATE_WALLS" = open ] && { printf opened; exit 0; }; printf 'refused: Operation not permitted'; exit 0 ;;
    "php -r")       echo ok ;;
    "sh -c")        [ "$GATE_WALLS" = open ] && exit 0; exit 1 ;;
    *)              exit 1 ;;
esac
EOF
printf '#!/bin/sh\necho "$*" >> "%s/umount.log"\n' "$T" > "$T/bin/umount"
cat > "$T/bin/ss" <<'EOF'
#!/bin/sh
[ "$GATE_WALLS" = open ] && echo "LISTEN 0 244 0.0.0.0:5432 0.0.0.0:*" || echo "LISTEN 0 244 127.0.0.1:5432 0.0.0.0:*"
EOF
chmod 755 "$T/bin/"*
probes() { PATH="$T/bin:$PATH" COPY_PROBES_SITES="$T/sites" COPY_PROBES_ETC="$T/etc" COPY_PROBES_ENGINES="$T/no-engine" bash "$SCRIPT" "$@"; }
row() { printf '%s\n' "$1" | grep -F -- "$2" | awk '{print $1}'; }

echo "=== walls that hold ==="
out="$(GATE_WALLS=hold probes demo --old-address 203.0.113.9 2>&1)"; rc=$?
chk "exit 0, RESULT: PASS last" "$rc:$(printf '%s' "$out" | tail -1)" "0:RESULT: PASS"
for what in "A user namespace gives the web user no capability" "The web user cannot mount" "The web user cannot open a packet socket" \
        "The site's PHP runs as its web user" "The web user cannot write the site's code" "No trust left for the old address" \
        "The database answers on loopback alone"; do
    chk "$what: PASS" "$(row "$out" "$what")" "PASS"
done
chk "walls by id: SKIP, not a container" "$(row "$out" "Walls by id")" "SKIP"
chk "nothing was taken down, since nothing was mounted" "$([ -e "$T/umount.log" ] && echo some || echo none)" "none"

echo "=== walls that do not ==="
echo "RemoteIPTrustedProxy 203.0.113.9" > "$T/etc/apache2/conf-enabled/joinery-forwarders.conf"
out="$(GATE_WALLS=open probes demo --old-address 203.0.113.9 2>&1)"; rc=$?
chk "exit 0 still, RESULT: FAIL last: the job carries the rows" "$rc:$(printf '%s' "$out" | tail -1)" "0:RESULT: FAIL"
for what in "A user namespace gives the web user no capability" "The web user cannot mount" "The web user cannot open a packet socket" \
        "The web user cannot write the site's code" "No trust left for the old address" "The database answers on loopback alone"; do
    chk "$what: FAIL" "$(row "$out" "$what")" "FAIL"
done
chk "a mount let through is taken down again" "$(cat "$T/umount.log" 2>/dev/null)" "/mnt"
chk "the write let through appended nothing" "$(cat "$T/sites/demo/public_html/serve.php")" "<?php"

echo "=== usage ==="
for args in "" "-x" "--loosen" "Demo" "../demo"; do
    probes $args >/dev/null 2>&1; chk "'$args' is refused (2)" "$?" "2"
done
probes nosuch >/dev/null 2>&1; chk "a site not on this machine is refused (2)" "$?" "2"
probes demo --neighbour "-y" >/dev/null 2>&1; chk "a neighbour that reads as a flag is refused (2)" "$?" "2"
probes demo --old-address "1.2.3.4;x" >/dev/null 2>&1; chk "an old address that is not one is refused (2)" "$?" "2"
probes demo --loosen >/dev/null 2>&1; chk "there is no --loosen" "$?" "2"
chk "the script names no kernel setting: loosening is the test plane's own check, never a word's" \
    "$(grep -c -E 'sysctl|ptrace_scope' "$SCRIPT")" "0"
out="$(PATH="$T/bin:$PATH" bash -c 'printf "#!/bin/sh\necho 1000\n" > "'"$T"'/bin/id"; bash "'"$SCRIPT"'" demo' 2>&1)"; rc=$?
chk "not root: refused (2)" "$rc" "2"

echo "RESULT: $([ "$failed" = 0 ] && echo PASS || echo FAIL) $passed $failed"
[ "$failed" = 0 ]
