#!/bin/bash
# @joinery-test
# name: unit_journal
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/sysadmin_tools/unit_journal.sh]
#
# unit_journal.sh is what the agent's unit_journal observe word runs as root
# (specs/disk_headroom_and_unit_diagnosis.md § 8). This gate pins its
# contract: a unit outside the compiled list is refused and nothing is read;
# the line count is clamped here rather than trusted; the object always has
# every key; the journal — the one thing this script prints that it did not
# compose — survives quotes, backslashes and control characters as valid JSON;
# stdin changes nothing; and the script never runs a write.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/unit_journal.sh"
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
        if ($argv[3] === "type") { echo is_array($v) ? (array_is_list($v) ? "list" : "object") : gettype($v); exit; }
        if ($argv[3] === "count") { echo is_array($v) ? count($v) : "NOTLIST"; exit; }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

KEYS="unit,load_state,active_state,sub_state,result,exit_status,last_run_unix,lines_requested,lines_returned,journal"

echo "=== A unit outside the list is refused, and nothing is read ==="
# The list is the whole vocabulary. Each of these is something an operator
# might plausibly type or an attacker might plausibly send.
for bad in "sshd" "ssh" "mysql" "man-db.service" "man-db ; rm -rf /" "../../etc/shadow" "*" ""; do
    out="$(bash "$SCRIPT" "$bad" 10 2>"$T/err")"; rc=$?
    chk "refused: '$bad' (exit 2)" "$rc" "2"
    chk "refused: '$bad' printed no object" "$(printf '%s' "$out" | wc -c)" "0"
    chk "refused: '$bad' said why" "$(grep -c 'is not a unit this node will read' "$T/err")" "1"
done

echo "=== A unit on the list reads, and the object is whole ==="
bash "$SCRIPT" cron 5 > "$T/cron.json" 2> "$T/cron.err"; rc=$?
chk "exit 0" "$rc" "0"
chk "one line" "$(wc -l < "$T/cron.json")" "1"
chk "nothing on stderr" "$(wc -c < "$T/cron.err")" "0"
chk "a JSON object" "$(jv "$T/cron.json" "" type)" "object"
chk "every key present, in order" "$(jv "$T/cron.json" "" keys)" "$KEYS"
chk "the unit is named as a unit file" "$(jv "$T/cron.json" unit)" "cron.service"
chk "the journal is a list" "$(jv "$T/cron.json" journal type)" "list"
chk "lines_returned matches the list" "$(jv "$T/cron.json" lines_returned)" "$(jv "$T/cron.json" journal count)"

echo "=== The line count is clamped here, not trusted ==="
chk "over the cap clamps to 200" "$(bash "$SCRIPT" man-db 9999 | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["lines_requested"];')" "200"
chk "zero clamps to 1" "$(bash "$SCRIPT" man-db 0 | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["lines_requested"];')" "1"
chk "nonsense falls back to the default" "$(bash "$SCRIPT" man-db 'abc; reboot' | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["lines_requested"];')" "100"
chk "a missing count falls back to the default" "$(bash "$SCRIPT" man-db | php -r 'echo json_decode(file_get_contents("php://stdin"), true)["lines_requested"];')" "100"

echo "=== Journal text is hostile, and stays inside its JSON string ==="
mkdir -p "$T/bin"
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
case "$*" in
    *LoadState*)    echo loaded ;;
    *ActiveState*)  echo failed ;;
    *SubState*)     echo failed ;;
    *Result*)       echo exit-code ;;
    *ExecMainStatus*) echo 1 ;;
    *ExecMainExitTimestamp*) echo "Tue 2026-09-22 04:15:43 UTC" ;;
    *) echo "" ;;
esac
STUB
cat > "$T/bin/journalctl" <<'STUB'
#!/bin/bash
printf '%s\n' 'a line with "double quotes" and a \backslash\'
printf '%s\n' 'a line with a tab	and a bell'
printf '%s\n' 'mandb: can'"'"'t write to /var/cache/man: No space left on device'
STUB
chmod +x "$T/bin/systemctl" "$T/bin/journalctl" 2>/dev/null || true
PATH="$T/bin:$PATH" bash "$SCRIPT" man-db 10 > "$T/stub.json" 2>/dev/null
chk "stubbed run: still one JSON object" "$(jv "$T/stub.json" "" type)" "object"
chk "stubbed run: the verdict is read" "$(jv "$T/stub.json" result)/$(jv "$T/stub.json" exit_status)" "exit-code/1"
chk "stubbed run: the timestamp became seconds" "$(jv "$T/stub.json" last_run_unix)" "1790050543"
chk "stubbed run: three lines" "$(jv "$T/stub.json" journal count)" "3"
chk "quotes and backslashes survive as text, not as syntax" "$(jv "$T/stub.json" journal.0)" 'a line with "double quotes" and a \backslash\'
chk "the ENOSPC line is readable" "$(jv "$T/stub.json" journal.2 | grep -c 'No space left on device')" "1"

echo "=== An argument it did not ask for, and stdin, change nothing ==="
echo "some stdin the script must ignore" | bash "$SCRIPT" cron 5 /etc/passwd --follow > "$T/extra.json" 2>/dev/null
chk "the third argument is ignored" "$(jv "$T/extra.json" "" keys)" "$KEYS"
chk "the unit is still the one named" "$(jv "$T/extra.json" unit)" "cron.service"

echo "=== Static pins ==="
chk "the unit list holds twelve names" "$(sed -n '/^UNITS=(/,/^)/p' "$SCRIPT" | tr ' ' '\n' | grep -c -E '^[a-z0-9][a-z0-9._-]*$')" "12"
chk "sshd is not one of them" "$(sed -n '/^UNITS=(/,/^)/p' "$SCRIPT" | grep -c -w -E 'sshd|ssh')" "0"
chk "the line cap is 200" "$(grep -c '^MAX_LINES=200' "$SCRIPT")" "1"
chk "every command runs under the per-command timeout" "$(grep -c '^run() { timeout "\$CMD_TIMEOUT"' "$SCRIPT")" "1"
chk "journalctl is read-only and bounded" "$(grep -c 'run journalctl -u "\$SERVICE" -n "\$LINES"' "$SCRIPT")" "1"
chk "no systemctl verb but show" "$(grep -o 'systemctl [a-z-]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "systemctl show "
chk "nothing writes: no tee, no redirect into /etc or /var, no rm, no mv" "$(grep -c -E '\btee\b|> */(etc|var)|\brm |\bmv |\bcp ' "$SCRIPT")" "0"
chk "no reset-failed here: clearing a unit is a different word" "$(grep -c 'reset-failed' "$SCRIPT")" "0"
chk "exit 0 is the last thing it does" "$(tail -n 1 "$SCRIPT")" "exit 0"

echo
echo "unit_journal gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
