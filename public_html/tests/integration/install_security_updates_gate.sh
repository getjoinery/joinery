#!/bin/bash
# @joinery-test
# name: install_security_updates
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/install_security_updates.sh]
#
# install_security_updates.sh is what the agent's install_security_updates
# operate word runs as root. This gate pins its contract: no arguments; what
# it installs is unattended-upgrade's choice, never a general apt upgrade; it
# never reboots; the object always has every key, lists each package that
# changed with its old and new version, and names the outcome. Every package
# tool here is a stub: the gate installs nothing.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/install_security_updates.sh"
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

jv() {
    php -r '
        $o = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($o)) { echo "NOTJSON"; exit; }
        $path = $argv[2]; $v = $o;
        if ($path !== "") { foreach (explode(".", $path) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "ABSENT"; exit; } $v = $v[$k]; } }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

# Stubs. id says root; pgrep reads a process table, $T/ps, one name<TAB>command
# line per row, matching -x against the name and -f against the command line as
# procps does; the table holds what every Ubuntu machine runs, the shutdown
# helper, whose name is cut to unattended-upgr; dpkg-query lists the packages
# before, and after once the updater stub has run; the updater stub records its
# argv and exits with STUB_UU_RC, printing STUB_UU_OUT.
mkdir -p "$T/bin"
cat > "$T/bin/id" <<'STUB'
#!/bin/bash
echo 0
STUB
SHUTDOWN_HELPER=$'unattended-upgr\t/usr/bin/python3 /usr/share/unattended-upgrades/unattended-upgrade-shutdown --wait-for-signal'
printf '%s\n' "$SHUTDOWN_HELPER" > "$T/ps"
cat > "$T/bin/pgrep" <<STUB
#!/bin/bash
mode=x
for a in "\$@"; do [ "\$a" = "-f" ] && mode=f; done
pat="\${@: -1}"
while IFS=\$'\t' read -r name cmd; do
    if [ "\$mode" = x ]; then [[ "\$name" =~ ^(\$pat)\$ ]] && exit 0
    else [[ "\$cmd" =~ \$pat ]] && exit 0; fi
done < "$T/ps"
exit 1
STUB
cat > "$T/bin/apt-get" <<STUB
#!/bin/bash
echo "apt-get \$*" >> "$T/calls"
case "\$*" in *" -f "*install*) [ -z "\${STUB_REPAIR_FAILS:-}" ] && rm -f "$T/broken"; exit 0 ;; esac
exit \${STUB_UPDATE_RC:-0}
STUB
cat > "$T/bin/unattended-upgrade" <<STUB
#!/bin/bash
echo "unattended-upgrade \$*" >> "$T/calls"
[ "\${STUB_UU_RC:-0}" = 0 ] && [ -z "\${STUB_UU_NOCHANGE:-}" ] && touch "$T/upgraded"
printf '%s\n' "\${STUB_UU_OUT:-}"
exit \${STUB_UU_RC:-0}
STUB
cat > "$T/bin/dpkg-query" <<STUB
#!/bin/bash
if [ -f "$T/upgraded" ]; then
    printf 'ii \tphp8.3-fpm:amd64\t8.3.36-1\n'
    printf 'ii \tphp8.3-cli:amd64\t8.3.36-1\n'
    printf 'ii \tlibnew1:amd64\t1.0-1\n'
else
    printf 'ii \tphp8.3-fpm:amd64\t8.3.35-1\n'
    printf 'ii \tphp8.3-cli:amd64\t8.3.35-1\n'
fi
printf 'ii \tcurl:amd64\t8.5.0-2\n'
printf 'rc \tgone:amd64\t1.0\n'
STUB
cat > "$T/bin/dpkg" <<STUB
#!/bin/bash
# dpkg --audit: a half-done run while $T/broken exists; apt-get -f install
# (the stub below) finishes it unless STUB_REPAIR_FAILS.
[ "\$1" = "--audit" ] && [ -f "$T/broken" ] && echo "The following packages are only half configured: php8.3-common"
exit 0
STUB
cat > "$T/bin/needrestart" <<'STUB'
#!/bin/bash
echo "NEEDRESTART-VER: 3.6"
echo "NEEDRESTART-SVC: php8.3-fpm.service"
STUB
chmod +x "$T/bin/"*

mkdir -p "$T/proc"
run_script() {
    rm -f "$T/calls" "$T/upgraded"
    PATH="$T/bin:$PATH" INSTALL_SECURITY_UPDATES_PROC="$T/proc" bash "$SCRIPT" "$@" > "$T/out.json" 2> "$T/err"
}

echo "=== Arguments are refused, and nothing runs ==="
for bad in "--yes" "php8.3-fpm" "-o" ""; do
    rm -f "$T/calls"
    out="$(PATH="$T/bin:$PATH" bash "$SCRIPT" "$bad" 2>"$T/err")"; rc=$?
    chk "refused: '$bad' (exit 2)" "$rc" "2"
    chk "refused: '$bad' printed no object" "$(printf '%s' "$out" | wc -c)" "0"
    chk "refused: '$bad' ran no package tool" "$([ -f "$T/calls" ] && echo ran || echo none)" "none"
done

echo "=== A run that installs fixes says which ==="
run_script; rc=$?
chk "exit 0" "$rc" "0"
chk "nothing on stderr" "$(wc -c < "$T/err")" "0"
chk "every key present, in order" "$(jv "$T/out.json" "" keys)" \
    "outcome,reason,apt_update,upgrade_exit,upgraded,upgraded_count,truncated,reboot_required,services_needing_restart,repaired,reloaded,still_on_old_code"
chk "outcome installed" "$(jv "$T/out.json" outcome)" "installed"
chk "lists refreshed" "$(jv "$T/out.json" apt_update)" "ok"
chk "three packages changed (two upgraded, one new)" "$(jv "$T/out.json" upgraded_count)" "3"
chk "an upgrade carries both versions" "$(jv "$T/out.json" upgraded.2.package)/$(jv "$T/out.json" upgraded.2.from)/$(jv "$T/out.json" upgraded.2.to)" \
    "php8.3-fpm:amd64/8.3.35-1/8.3.36-1"
chk "a new package has no old version" "$(jv "$T/out.json" upgraded.0.package)/$(jv "$T/out.json" upgraded.0.from)" "libnew1:amd64/"
chk "an unchanged package is not listed" "$(grep -c 'curl' "$T/out.json")" "0"
chk "reboot_required is a boolean" "$(jv "$T/out.json" reboot_required | grep -c -x -E 'true|false')" "1"
chk "services still on old code are named" "$(jv "$T/out.json" services_needing_restart)" '["php8.3-fpm.service"]'
chk "the updater ran once, with no arguments" "$(grep -c -x 'unattended-upgrade ' "$T/calls")" "1"
chk "apt-get ran update, then clean once the install changed something, and nothing else" \
    "$(grep '^apt-get' "$T/calls" | tr '\n' '|')" "apt-get -q update|apt-get -q -o DPkg::Lock::Timeout=0 clean|"

chk "nothing half done: no repair, nothing reloaded" "$(jv "$T/out.json" repaired)|$(jv "$T/out.json" reloaded)|$(jv "$T/out.json" still_on_old_code)|$(grep -c -- ' -f ' "$T/calls")" "false|[]|[]|0"

echo "=== A run left half done is finished first, naming no package ==="
touch "$T/broken"; run_script
chk "repaired, before the updater ran" "$(jv "$T/out.json" repaired)|$(grep -n -e '-f -o' -e '^unattended' "$T/calls" | cut -d: -f1 | tr '\n' ' ')" "true|1 3 "
chk "  the repair is apt-get -f install with options only" "$(grep -c -x 'apt-get -q -y -f -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold install' "$T/calls")" "1"
touch "$T/broken"; STUB_REPAIR_FAILS=1 run_script
chk "a repair that leaves it half done: repaired false, the updater still runs" "$(jv "$T/out.json" repaired)|$(grep -c '^unattended-upgrade' "$T/calls")" "false|1"
rm -f "$T/broken"

echo "=== A php-fpm on a replaced binary is reloaded (WP3) ==="
# Two masters on a replaced binary: one a real process that, as php-fpm does on
# USR2, re-executes (here: its stand-in exe stops saying deleted); one whose
# PID is gone, so it cannot be signalled. And a worker, and a current master.
mkdir -p "$T/proc/999999"
printf 'php-fpm: master process (/etc/php/8.3/fpm/php-fpm.conf)\0' > "$T/proc/999999/cmdline"
ln -s "/usr/sbin/php-fpm8.3 (deleted)" "$T/proc/999999/exe"
bash -c 'trap "ln -sfn /usr/sbin/php-fpm8.5 $1/\$\$/exe" USR2; for i in $(seq 1 600); do sleep 0.1; done' _ "$T/proc" &
MPID=$!
mkdir -p "$T/proc/$MPID"
printf 'php-fpm: master process (/etc/php/8.5/fpm/php-fpm.conf)\0' > "$T/proc/$MPID/cmdline"
ln -sfn "/usr/sbin/php-fpm8.5 (deleted)" "$T/proc/$MPID/exe"
mkdir -p "$T/proc/12345"; printf 'php-fpm: pool www\0' > "$T/proc/12345/cmdline"; ln -s "/usr/sbin/php-fpm8.5 (deleted)" "$T/proc/12345/exe"
mkdir -p "$T/proc/23456"; printf 'php-fpm: master process (/etc/php/8.4/fpm/php-fpm.conf)\0' > "$T/proc/23456/cmdline"; ln -s "/usr/sbin/php-fpm8.4" "$T/proc/23456/exe"
run_script
chk "the master that re-executed is reloaded, and is current after" "$(jv "$T/out.json" reloaded)" '["php-fpm8.5"]'
chk "  one that could not be signalled is still on old code; a worker and a current master are not touched" "$(jv "$T/out.json" still_on_old_code)" '["php-fpm8.3"]'
kill "$MPID" 2>/dev/null; wait "$MPID" 2>/dev/null
rm -rf "$T/proc"; mkdir -p "$T/proc"

echo "=== The busy check: the shutdown helper is no package run, unattended-upgrade is ==="
eval "$(sed -n '/^apt_busy() {/,/^}/p' "$SCRIPT")"
PATH="$T/bin:$PATH" apt_busy; chk "the shutdown helper alone is not busy" "$?" "1"
printf '%s\n' "$SHUTDOWN_HELPER" $'unattended-upgr\t/usr/bin/python3 /usr/bin/unattended-upgrade' > "$T/ps"
PATH="$T/bin:$PATH" apt_busy; chk "a running unattended-upgrade is busy" "$?" "0"
printf '%s\n' "$SHUTDOWN_HELPER" $'dpkg\t/usr/bin/dpkg --configure -a' > "$T/ps"
PATH="$T/bin:$PATH" apt_busy; chk "a running dpkg is busy" "$?" "0"
printf '%s\n' "$SHUTDOWN_HELPER" > "$T/ps"

echo "=== Nothing pending is its own outcome ==="
STUB_UU_NOCHANGE=1 run_script
chk "nothing_to_install" "$(jv "$T/out.json" outcome)/$(jv "$T/out.json" upgraded_count)/$(jv "$T/out.json" upgraded)" "nothing_to_install/0/[]"
chk "  and nothing to clear" "$(grep -c ' clean$' "$T/calls")" "0"

echo "=== A failed updater is reported, not hidden ==="
STUB_UU_RC=1 STUB_UU_OUT="E: something broke" run_script; rc=$?
chk "still exit 0 with an object" "$rc/$(jv "$T/out.json" outcome)" "0/failed"
chk "its exit code travels" "$(jv "$T/out.json" upgrade_exit)" "1"
chk "the reason names the log" "$(jv "$T/out.json" reason | grep -c 'unattended-upgrades.log')" "1"

echo "=== A held lock reads as busy ==="
STUB_UU_RC=1 STUB_UU_OUT="Lock could not be acquired (another package manager running?)" run_script
chk "busy" "$(jv "$T/out.json" outcome)" "busy"
chk "the updater was tried again once" "$(grep -c -x 'unattended-upgrade ' "$T/calls")" "2"

echo "=== Stale lists still run, and say so ==="
STUB_UPDATE_RC=100 run_script
chk "apt_update failed, outcome still installed" "$(jv "$T/out.json" apt_update)/$(jv "$T/out.json" outcome)" "failed/installed"

echo "=== Not root: unavailable, nothing runs ==="
printf '#!/bin/bash\necho 1000\n' > "$T/bin/id"
run_script
chk "unavailable" "$(jv "$T/out.json" outcome)" "unavailable"
chk "no package tool ran" "$([ -f "$T/calls" ] && echo ran || echo none)" "none"
printf '#!/bin/bash\necho 0\n' > "$T/bin/id"

echo "=== Static pins ==="
CODE="$(grep -v -E '^\s*#' "$SCRIPT")"
chk "never a general apt upgrade" "$(printf '%s\n' "$CODE" | grep -c -E 'apt(-get)? +(-[a-z]+ +)*(dist-|full-)?upgrade')" "0"
chk "never installs or removes by name (the repair, apt-get -f install, names none)" \
    "$(printf '%s\n' "$CODE" | grep -E 'apt(-get)? +(-[a-z]+ +)*(install|remove|purge)|apt-get .* install' | grep -v -c -E 'apt-get -q -y -f( -o [A-Za-z:=-]+)* install >/dev/null')" "0"
chk "never reboots" "$(printf '%s\n' "$CODE" | grep -c -E '(^|[;&|(][[:space:]]*|^[[:space:]]+)(reboot|shutdown|poweroff)([[:space:]]|$)|systemctl +(reboot|poweroff|kexec)')" "0"
chk "needrestart only lists" "$(printf '%s\n' "$CODE" | grep 'needrestart -' | grep -c -v -- '-r l')" "0"
chk "exit 0 is how the object ends" "$(printf '%s\n' "$CODE" | grep -A2 'printf .}\\n.' | grep -c 'exit 0')" "1"

echo
echo "install_security_updates gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
