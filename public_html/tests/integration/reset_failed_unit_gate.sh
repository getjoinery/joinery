#!/bin/bash
# @joinery-test
# name: reset_failed_unit
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/reset_failed_unit.sh]
#
# reset_failed_unit.sh is what the agent's reset_failed_unit operate word runs
# as root (specs/disk_headroom_and_unit_diagnosis.md § 9). This gate pins its
# contract: a unit outside the compiled list is refused and nothing runs;
# `systemctl reset-failed` is only ever run with ONE named unit (with none it
# clears every unit on the machine); the object always has every key and
# reports the state before and after; and the list is the same as
# unit_journal.sh's. Every systemctl call here goes to a stub: the gate never
# resets a real unit.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/reset_failed_unit.sh"
JOURNAL="$ROOT/maintenance_scripts/sysadmin_tools/unit_journal.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
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

# A stub systemctl that records every call and flips the unit from failed to
# inactive when reset-failed names it.
mkdir -p "$T/bin"
cat > "$T/bin/systemctl" <<STUB
#!/bin/bash
echo "\$*" >> "$T/calls"
if [ "\$1" = "reset-failed" ]; then
    rc=\${STUB_RESET_RC:-0}
    [ "\$rc" = 0 ] && [ -n "\${2:-}" ] && touch "$T/was_reset"
    exit \$rc
fi
if [ -f "$T/was_reset" ]; then
    case "\$*" in *ActiveState*) echo inactive ;; *SubState*) echo dead ;; *Result*) echo success ;; esac
else
    case "\$*" in *ActiveState*) echo failed ;; *SubState*) echo failed ;; *Result*) echo exit-code ;; esac
fi
STUB
chmod +x "$T/bin/systemctl"

echo "=== A unit outside the list is refused, and nothing runs ==="
for bad in "sshd" "mysql" "man-db.service" "man-db ; reboot" "../../etc/shadow" "*" ""; do
    rm -f "$T/calls"
    out="$(PATH="$T/bin:$PATH" bash "$SCRIPT" "$bad" 2>"$T/err")"; rc=$?
    chk "refused: '$bad' (exit 2)" "$rc" "2"
    chk "refused: '$bad' printed no object" "$(printf '%s' "$out" | wc -c)" "0"
    chk "refused: '$bad' ran no systemctl" "$([ -f "$T/calls" ] && echo ran || echo none)" "none"
    chk "refused: '$bad' said why" "$(grep -c 'is not a unit this node will clear' "$T/err")" "1"
done

echo "=== A listed unit is cleared, and the object says before and after ==="
rm -f "$T/calls" "$T/was_reset"
PATH="$T/bin:$PATH" bash "$SCRIPT" man-db > "$T/out.json" 2> "$T/err"; rc=$?
chk "exit 0" "$rc" "0"
chk "nothing on stderr" "$(wc -c < "$T/err")" "0"
chk "every key present, in order" "$(jv "$T/out.json" "" keys)" "unit,before,reset,after"
chk "the unit is named as a unit file" "$(jv "$T/out.json" unit)" "man-db.service"
chk "before: failed" "$(jv "$T/out.json" before.active_state)/$(jv "$T/out.json" before.result)" "failed/exit-code"
chk "reset accepted" "$(jv "$T/out.json" reset)" "true"
chk "after: inactive, success" "$(jv "$T/out.json" after.active_state)/$(jv "$T/out.json" after.result)" "inactive/success"
chk "reset-failed ran once, naming the unit" "$(grep -c '^reset-failed man-db.service$' "$T/calls")" "1"
chk "no other verb but show ran" "$(grep -v -E '^(show |reset-failed man-db.service$)' "$T/calls" | wc -l)" "0"

echo "=== A refused reset is reported, not hidden ==="
rm -f "$T/calls" "$T/was_reset"
STUB_RESET_RC=1 PATH="$T/bin:$PATH" bash "$SCRIPT" cron > "$T/refused.json" 2>/dev/null; rc=$?
chk "still exit 0 with an object" "$rc/$(jv "$T/refused.json" unit)" "0/cron.service"
chk "reset false" "$(jv "$T/refused.json" reset)" "false"
chk "after is still failed" "$(jv "$T/refused.json" after.active_state)" "failed"

echo "=== Static pins ==="
chk "the unit list is unit_journal.sh's, name for name" \
    "$(sed -n '/^UNITS=(/,/^)/p' "$SCRIPT" | tr -s ' \n' '\n' | sort | tr '\n' ' ')" \
    "$(sed -n '/^UNITS=(/,/^)/p' "$JOURNAL" | tr -s ' \n' '\n' | sort | tr '\n' ' ')"
chk "sshd is not one of them" "$(sed -n '/^UNITS=(/,/^)/p' "$SCRIPT" | grep -c -w -E 'sshd|ssh')" "0"
CODE="$(grep -v -E '^\s*#' "$SCRIPT")"
chk "reset-failed is run once, only with the one validated unit" \
    "$(printf '%s\n' "$CODE" | grep -c 'reset-failed')/$(printf '%s\n' "$CODE" | grep -c 'systemctl reset-failed "\$SERVICE"')" "1/1"
chk "no systemctl verb but show and reset-failed" "$(printf '%s\n' "$CODE" | grep -o 'systemctl [a-z-]*' | sort -u | tr '\n' ' ')" "systemctl reset-failed systemctl show "
chk "no start, stop, restart, kill or daemon-reload" "$(printf '%s\n' "$CODE" | grep -c -E 'systemctl (start|stop|restart|kill|daemon-reload|enable|disable|mask)')" "0"
chk "exit 0 is the last thing it does" "$(tail -n 1 "$SCRIPT")" "exit 0"

echo
echo "reset_failed_unit gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
