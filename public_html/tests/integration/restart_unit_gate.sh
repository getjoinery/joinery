#!/bin/bash
# @joinery-test
# name: restart_unit
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/restart_unit.sh]
#
# restart_unit.sh is what the agent's restart_unit operate word runs as root
# (specs/agent_recipes_and_vocabulary.md, First words), and what the
# service_health recipe repairs with. This gate pins its contract: a unit
# outside host_report's expected units is refused and nothing runs; the agent
# is never one of them; php-fpm resolves to the versioned unit file; the only
# verb that changes anything is restart, with the one named unit; an absent
# unit is reported and nothing runs; and the object always has every key.
# Every systemctl call here goes to a stub: the gate never restarts a real unit.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/restart_unit.sh"
REPORT="$ROOT/maintenance_scripts/sysadmin_tools/host_report.sh"
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

# A stub systemctl that records every call. A unit is failed until restart
# names it; STUB_ABSENT makes every unit not-found; STUB_RESTART_RC refuses.
mkdir -p "$T/bin"
cat > "$T/bin/systemctl" <<STUB
#!/bin/bash
echo "\$*" >> "$T/calls"
case "\$1" in
    list-units) [ -n "\${STUB_ACTIVE_FPM:-}" ] && echo "\$STUB_ACTIVE_FPM loaded active running PHP"; exit 0 ;;
    list-unit-files) printf 'php8.1-fpm.service enabled enabled\nphp8.3-fpm.service enabled enabled\n'; exit 0 ;;
    restart)
        rc=\${STUB_RESTART_RC:-0}
        [ "\$rc" = 0 ] && [ -n "\${2:-}" ] && touch "$T/was_restarted"
        exit \$rc ;;
esac
if [ -n "\${STUB_ABSENT:-}" ]; then
    case "\$*" in *LoadState*) echo not-found ;; *ActiveState*) echo inactive ;; *SubState*) echo dead ;; *Result*) echo success ;; esac
    exit 0
fi
if [ -f "$T/was_restarted" ]; then
    case "\$*" in *LoadState*) echo loaded ;; *ActiveState*) echo active ;; *SubState*) echo running ;; *Result*) echo success ;; esac
else
    case "\$*" in *LoadState*) echo loaded ;; *ActiveState*) echo failed ;; *SubState*) echo failed ;; *Result*) echo exit-code ;; esac
fi
STUB
chmod +x "$T/bin/systemctl"

echo "=== A unit outside the list is refused, and nothing runs ==="
for bad in "joinery-agent" "sshd" "mysql" "apache2.service" "apache2 ; reboot" "../../etc/shadow" "*" ""; do
    rm -f "$T/calls"
    out="$(PATH="$T/bin:$PATH" bash "$SCRIPT" "$bad" 2>"$T/err")"; rc=$?
    chk "refused: '$bad' (exit 2)" "$rc" "2"
    chk "refused: '$bad' printed no object" "$(printf '%s' "$out" | wc -c)" "0"
    chk "refused: '$bad' ran no systemctl" "$([ -f "$T/calls" ] && echo ran || echo none)" "none"
    chk "refused: '$bad' said why" "$(grep -c 'is not a unit this node will restart' "$T/err")" "1"
done

echo "=== A listed unit is restarted, and the object says before and after ==="
rm -f "$T/calls" "$T/was_restarted"
PATH="$T/bin:$PATH" bash "$SCRIPT" apache2 > "$T/out.json" 2> "$T/err"; rc=$?
chk "exit 0" "$rc" "0"
chk "nothing on stderr" "$(wc -c < "$T/err")" "0"
chk "every key present, in order" "$(jv "$T/out.json" "" keys)" "unit,absent,before,restarted,after"
chk "the unit is named as a unit file" "$(jv "$T/out.json" unit)" "apache2.service"
chk "before: failed" "$(jv "$T/out.json" before.active_state)" "failed"
chk "restart accepted" "$(jv "$T/out.json" restarted)" "true"
chk "after: active" "$(jv "$T/out.json" after.active_state)/$(jv "$T/out.json" after.sub_state)" "active/running"
chk "restart ran once, naming the unit" "$(grep -c '^restart apache2.service$' "$T/calls")" "1"
chk "no other verb but show and restart ran" "$(grep -v -E '^(show |restart apache2.service$)' "$T/calls" | wc -l)" "0"

echo "=== php-fpm is the versioned unit the host runs ==="
rm -f "$T/calls" "$T/was_restarted"
PATH="$T/bin:$PATH" bash "$SCRIPT" php-fpm > "$T/fpm.json" 2>/dev/null
chk "with none active, php-fpm resolves to the newest installed, not the first listed" "$(jv "$T/fpm.json" unit)" "php8.3-fpm.service"
chk "the restart named it" "$(grep -c '^restart php8.3-fpm.service$' "$T/calls")" "1"
rm -f "$T/calls" "$T/was_restarted"
STUB_ACTIVE_FPM=php8.1-fpm.service PATH="$T/bin:$PATH" bash "$SCRIPT" php-fpm > "$T/fpm2.json" 2>/dev/null
chk "the active unit wins over the newest" "$(jv "$T/fpm2.json" unit)" "php8.1-fpm.service"

echo "=== An absent unit is reported, and nothing is restarted ==="
rm -f "$T/calls" "$T/was_restarted"
STUB_ABSENT=1 PATH="$T/bin:$PATH" bash "$SCRIPT" postgresql > "$T/absent.json" 2>/dev/null; rc=$?
chk "exit 0 with an object" "$rc/$(jv "$T/absent.json" unit)" "0/postgresql.service"
chk "absent true, restarted false" "$(jv "$T/absent.json" absent)/$(jv "$T/absent.json" restarted)" "true/false"
chk "no restart ran" "$(grep -c '^restart' "$T/calls")" "0"

echo "=== A refused restart is reported, not hidden ==="
rm -f "$T/calls" "$T/was_restarted"
STUB_RESTART_RC=1 PATH="$T/bin:$PATH" bash "$SCRIPT" cron > "$T/refused.json" 2>/dev/null; rc=$?
chk "still exit 0 with an object" "$rc/$(jv "$T/refused.json" unit)" "0/cron.service"
chk "restarted false, after still failed" "$(jv "$T/refused.json" restarted)/$(jv "$T/refused.json" after.active_state)" "false/failed"

echo "=== Static pins ==="
chk "the list is host_report's expected units" \
    "$(sed -n 's/^UNITS=(\(.*\))$/\1/p' "$SCRIPT")" \
    "$(grep -o '"[a-z0-9-]*":"%s"' "$REPORT" | sed -n '1,5p' | sed 's/":"%s"//; s/"//' | tr '\n' ' ' | sed 's/ $//')"
chk "the agent is not one of them" "$(grep -c -w 'joinery-agent' <<< "$(grep '^UNITS=' "$SCRIPT")")" "0"
CODE="$(grep -v -E '^\s*#' "$SCRIPT")"
chk "restart is run once, only with the resolved unit" \
    "$(printf '%s\n' "$CODE" | grep -c 'systemctl restart')/$(printf '%s\n' "$CODE" | grep -c 'systemctl restart "\$SERVICE"')" "1/1"
chk "no systemctl verb but show, list-unit-files and restart" "$(printf '%s\n' "$CODE" | grep -o 'systemctl [a-z-]*' | sort -u | tr '\n' ' ')" "systemctl list-unit-files systemctl list-units systemctl restart systemctl show "
chk "no stop, kill, enable, disable, mask or daemon-reload" "$(printf '%s\n' "$CODE" | grep -c -E 'systemctl (stop|kill|daemon-reload|enable|disable|mask|isolate)')" "0"
chk "exit 0 is the last thing it does" "$(tail -n 1 "$SCRIPT")" "exit 0"

echo
echo "restart_unit gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
