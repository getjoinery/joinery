#!/bin/bash
# @joinery-test
# name: disable_root_login
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/disable_root_login.sh]
#
# disable_root_login.sh is what the agent's disable_root_login operate word
# runs as root (specs/implemented/beta_break_glass_root_access.md 5.3). This gate pins its
# contract against a fixture /etc/ssh, a stand-in sshd whose -T reads that
# fixture the way sshd does (first value wins, sshd_config.d in name order
# where the main file includes it) and a stand-in systemctl:
#
#   - PermitRootLogin no in its own drop-in, whose name sorts first under C and
#     en_US collation; with no Include, first in the main file
#   - a configuration sshd -t refuses is put back as it was, and refused
#   - the reload happens only when sshd's unit is active, by the unit name
#     systemctl reports (ssh or sshd); a failed reload fails the run
#   - the run is done only when sshd -T reads no: another drop-in that wins
#     fails it
#   - Match blocks are reported; nothing else in the files changes
#   - a site container is refused, with nothing changed
#   - every key always present

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/disable_root_login.sh"
T=$(mktemp -d) || { echo "mktemp failed"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] && [ "$T" != "/" ] || { echo "no scratch dir"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
field() { php -r '$o=json_decode(stream_get_contents(STDIN),true); if(!is_array($o)){echo "NOTJSON";exit;} $v=$o[$argv[1]] ?? "ABSENT"; echo is_bool($v)?($v?"true":"false"):$v;' "$1" < "$T/out"; }
keys() { php -r '$o=json_decode(stream_get_contents(STDIN),true); echo is_array($o)?implode(",",array_keys($o)):"NOTJSON";' < "$T/out"; }

mkdir -p "$T/bin"
# sshd: -t answers GATE_SSHD_T_RC; -T prints the first PermitRootLogin it
# reads, in sshd's order, from the fixture.
cat > "$T/bin/sshd" <<'STUB'
#!/bin/bash
if [ "${1:-}" = "-t" ]; then exit "${GATE_SSHD_T_RC:-0}"; fi
etc="$DISABLE_ROOT_LOGIN_ETC"
files=()
if grep -qE '^[[:space:]]*Include[[:space:]]+/etc/ssh/sshd_config\.d/\*\.conf' "$etc/sshd_config"; then
    while IFS= read -r f; do files+=("$f"); done < <(LC_ALL=C ls "$etc"/sshd_config.d/*.conf 2>/dev/null)
fi
files+=("$etc/sshd_config")
v=$(awk 'tolower($1)=="match" {exit} tolower($1)=="permitrootlogin" {print tolower($2); exit}' "${files[@]}" 2>/dev/null)
echo "permitrootlogin ${v:-prohibit-password}"
STUB
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
echo "systemctl $*" >> "$GATE_LOG"
case "$1" in
    show) [ "${!#}" = "${GATE_UNIT:-ssh}.service" ] && echo loaded || echo not-found; exit 0 ;;
    is-active) [ -n "${GATE_ACTIVE:-}" ] && exit 0; exit 3 ;;
    reload) exit "${GATE_RELOAD_RC:-0}" ;;
esac
exit 0
STUB
cat > "$T/bin/systemd-detect-virt" <<'STUB'
#!/bin/bash
[ -n "${GATE_IN_CONTAINER:-}" ] && { echo docker; exit 0; }
echo none; exit 1
STUB
chmod +x "$T/bin/"*

# One run against a fresh fixture. Args: include|noinclude, then VAR=VALUE.
run() {
    local include="$1"; shift
    rm -rf "$T/etc"; mkdir -p "$T/etc/sshd_config.d"
    {
        [ "$include" = include ] && echo 'Include /etc/ssh/sshd_config.d/*.conf'
        echo '#PermitRootLogin prohibit-password'
        echo 'PasswordAuthentication yes'
    } > "$T/etc/sshd_config"
    printf 'PermitRootLogin prohibit-password\nMaxAuthTries 3\n' > "$T/etc/sshd_config.d/00-joinery-agent-managed.conf"
    printf 'PermitRootLogin yes\n' > "$T/etc/sshd_config.d/50-cloud-init.conf"
    : > "$T/log"
    for kv in "$@"; do [ "${kv%%=*}" = SETUP ] && eval "${kv#SETUP=}"; done
    (
        for kv in "$@"; do [ "${kv%%=*}" = SETUP ] || export "$kv"; done
        export DISABLE_ROOT_LOGIN_ETC="$T/etc" GATE_LOG="$T/log" PATH="$T/bin:$PATH"
        bash "$SCRIPT" < /dev/null > "$T/out" 2> "$T/err"
        echo $? > "$T/rc"
    )
}
DROPIN="$T/etc/sshd_config.d/00-joinery-0-no-root-login.conf"
KEYS="changed,written,permitrootlogin_before,permitrootlogin_after,reload,match_blocks,refused,reason"

echo "=== Its own drop-in, first in sshd's order ==="
run include
chk "exit 0, and every key present" "$(cat "$T/rc")|$(keys)" "0|$KEYS"
chk "the drop-in says PermitRootLogin no" "$(grep -c '^PermitRootLogin no$' "$DROPIN")" "1"
chk "before was the first drop-in's prohibit-password, after is no" "$(field permitrootlogin_before)|$(field permitrootlogin_after)|$(field written)" "prohibit-password|no|dropin"
for loc in C en_US.UTF-8; do
    first=$(printf '%s\n' 00-joinery-agent-managed.conf 00-joinery-root-login.conf 50-cloud-init.conf 60-cloudimg-settings.conf 00-joinery-0-no-root-login.conf | LC_ALL=$loc sort | head -1)
    chk "  its name sorts first under $loc" "$first" "00-joinery-0-no-root-login.conf"
done
chk "  nothing else in the files changed" "$(grep -c '^PasswordAuthentication yes$' "$T/etc/sshd_config")|$(grep -c 'prohibit-password' "$T/etc/sshd_config.d/00-joinery-agent-managed.conf")|$(grep -c yes "$T/etc/sshd_config.d/50-cloud-init.conf")" "1|1|1"
chk "  sshd -t ran before any reload, and an inactive sshd is not reloaded" "$(grep -c 'reload' "$T/log")|$(field reload)" "0|not_running"
run include GATE_ACTIVE=1
chk "an active sshd is reloaded, not restarted" "$(grep -c 'systemctl reload ssh.service' "$T/log")|$(grep -c ' restart' "$T/log")|$(field reload)" "1|0|reloaded"
run include GATE_ACTIVE=1 GATE_UNIT=sshd
chk "by the unit name systemctl reports (sshd)" "$(grep -c 'systemctl reload sshd.service' "$T/log")" "1"
run include GATE_ACTIVE=1 GATE_RELOAD_RC=1
chk "a failed reload fails the run" "$(cat "$T/rc")|$(field refused)|$(field changed)" "1|reload_failed|true"
run include
first=$(cat "$DROPIN")
( export DISABLE_ROOT_LOGIN_ETC="$T/etc" GATE_LOG="$T/log" PATH="$T/bin:$PATH"; bash "$SCRIPT" < /dev/null > "$T/out" 2>/dev/null; echo $? > "$T/rc" )
chk "a second run on the same machine: done, one drop-in, the same" "$(cat "$T/rc")|$(ls "$T/etc/sshd_config.d" | grep -c 'no-root-login')|$([ "$(cat "$DROPIN")" = "$first" ] && echo same)" "0|1|same"

echo "=== No Include: first in the main file ==="
run noinclude
chk "the line is the main file's first, and done" "$(head -n 1 "$T/etc/sshd_config")|$(cat "$T/rc")|$(field written)|$(field permitrootlogin_after)" "PermitRootLogin no|0|main|no"
chk "  and no drop-in is written" "$([ -f "$DROPIN" ] && echo yes || echo no)" "no"

echo "=== A configuration sshd refuses is put back ==="
run include GATE_SSHD_T_RC=1
chk "refused, exit 2, nothing changed" "$(cat "$T/rc")|$(field refused)|$(field changed)|$([ -f "$DROPIN" ] && echo yes || echo no)" "2|sshd_refused|false|no"
run noinclude GATE_SSHD_T_RC=1
chk "the main file is put back as it was" "$(head -n 1 "$T/etc/sshd_config")" "#PermitRootLogin prohibit-password"

echo "=== Done only when sshd reads no ==="
run include SETUP="printf 'PermitRootLogin yes\n' > '$T/etc/sshd_config.d/00-aaa-provider.conf'"
chk "another drop-in that wins fails the run" "$(cat "$T/rc")|$(field refused)|$(field permitrootlogin_after)" "1|not_effective|yes"

echo "=== Match blocks are reported ==="
run include
chk "none here" "$(field match_blocks)" "false"
run include SETUP="printf 'Match Address 10.0.0.0/8\n    PermitRootLogin yes\n' >> '$T/etc/sshd_config'"
chk "one in sshd_config is named" "$(field match_blocks)|$(cat "$T/rc")" "true|0"

echo "=== A site container is refused ==="
run include GATE_IN_CONTAINER=1
chk "refused, exit 2, nothing written" "$(cat "$T/rc")|$(field refused)|$([ -f "$DROPIN" ] && echo yes || echo no)" "2|container|no"

echo "=== It takes nothing ==="
# The same run given an argument and stdin answers and writes exactly what a
# plain run does: nothing it is handed is read.
run include
plain="$(cat "$T/out")|$(cat "$T/rc")|$(cat "$DROPIN")"
rm -rf "$T/etc"; mkdir -p "$T/etc/sshd_config.d"
printf 'Include /etc/ssh/sshd_config.d/*.conf\n#PermitRootLogin prohibit-password\nPasswordAuthentication yes\n' > "$T/etc/sshd_config"
printf 'PermitRootLogin prohibit-password\nMaxAuthTries 3\n' > "$T/etc/sshd_config.d/00-joinery-agent-managed.conf"
printf 'PermitRootLogin yes\n' > "$T/etc/sshd_config.d/50-cloud-init.conf"
( export DISABLE_ROOT_LOGIN_ETC="$T/etc" GATE_LOG="$T/log" PATH="$T/bin:$PATH"
  printf 'PermitRootLogin yes\n' | bash "$SCRIPT" yes 'PermitRootLogin yes' > "$T/out" 2>/dev/null; echo $? > "$T/rc" )
chk "an argument and stdin change nothing: same answer, same exit, same file" "$(cat "$T/out")|$(cat "$T/rc")|$(cat "$DROPIN")" "$plain"

echo
echo "disable_root_login gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
