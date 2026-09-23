#!/bin/bash
# @joinery-test
# name: restart_container
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/restart_container.sh]
#
# restart_container.sh is what the agent's restart_container operate word runs
# as root on a Docker host (specs/agent_recipes_and_vocabulary.md, First
# words), and what service_health repairs a container with. This gate pins its
# contract: only a container on the host's own list (its name is its SITENAME)
# is restarted; a name that fails the pattern, a container the operator runs,
# or one planted with another site's SITENAME is refused and nothing changes;
# the only verb that changes anything is restart; and the object always has
# every key. Every docker call goes to a stub.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/restart_container.sh"
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

mkdir -p "$T/bin"
cat > "$T/bin/docker" <<STUB
#!/bin/bash
echo "\$*" >> "$T/calls"
case "\$1" in
    ps) printf 'siteone\nimpostor\npostgres\n' ;;
    restart)
        rc=\${STUB_RESTART_RC:-0}
        [ "\$rc" = 0 ] && touch "$T/was_restarted"
        exit \$rc ;;
    inspect)
        case "\$3" in
            *Config.Env*) case "\$4" in siteone) echo SITENAME=siteone ;; impostor) echo SITENAME=siteone ;; *) echo PATH=/bin ;; esac ;;
            *State.Status*) [ -f "$T/was_restarted" ] && echo running || echo exited ;;
            *State.Health*) echo none ;;
        esac ;;
esac
STUB
chmod +x "$T/bin/docker"

echo "=== A container off the host's own list is refused, and nothing is restarted ==="
for bad in "postgres" "impostor" "absent" "Siteone" "siteone;reboot" "../x" ""; do
    rm -f "$T/calls"
    out="$(PATH="$T/bin:$PATH" bash "$SCRIPT" "$bad" 2>"$T/err")"; rc=$?
    chk "refused: '$bad' (exit 2)" "$rc" "2"
    chk "refused: '$bad' printed no object" "$(printf '%s' "$out" | wc -c)" "0"
    chk "refused: '$bad' restarted nothing" "$(cat "$T/calls" 2>/dev/null | grep -c '^restart')" "0"
    chk "refused: '$bad' said why" "$(grep -c 'is not a container this node will restart' "$T/err")" "1"
done

echo "=== One of ours is restarted, and the object says before and after ==="
rm -f "$T/calls" "$T/was_restarted"
PATH="$T/bin:$PATH" bash "$SCRIPT" siteone > "$T/out.json" 2> "$T/err"; rc=$?
chk "exit 0" "$rc" "0"
chk "nothing on stderr" "$(wc -c < "$T/err")" "0"
chk "every key present, in order" "$(jv "$T/out.json" "" keys)" "container,before,restarted,after"
chk "before exited, after running" "$(jv "$T/out.json" before.state)/$(jv "$T/out.json" after.state)" "exited/running"
chk "restarted true" "$(jv "$T/out.json" restarted)" "true"
chk "restart ran once, naming the container" "$(grep -c '^restart siteone$' "$T/calls")" "1"
chk "no other verb but ps, inspect and restart" "$(grep -v -E '^(ps |inspect |restart siteone$)' "$T/calls" | wc -l)" "0"

echo "=== A refused restart is reported, not hidden ==="
rm -f "$T/calls" "$T/was_restarted"
STUB_RESTART_RC=1 PATH="$T/bin:$PATH" bash "$SCRIPT" siteone > "$T/refused.json" 2>/dev/null; rc=$?
chk "still exit 0 with an object" "$rc/$(jv "$T/refused.json" container)" "0/siteone"
chk "restarted false" "$(jv "$T/refused.json" restarted)" "false"

echo "=== Static pins ==="
CODE="$(grep -v -E '^\s*#' "$SCRIPT")"
chk "the name pattern is the agent's site pattern" "$(grep -c '\^\[a-z0-9\]\[a-z0-9_-\]{0,49}\$' "$SCRIPT")" "1"
chk "no docker verb but ps, inspect and restart" "$(printf '%s\n' "$CODE" | grep -o 'docker [a-z]\+' | sort -u | tr '\n' ' ')" "docker inspect docker ps docker restart "
chk "no rm, stop, kill, exec, pull or run" "$(printf '%s\n' "$CODE" | grep -c -E 'docker (rm|stop|kill|exec|pull|run|volume|system)')" "0"
chk "exit 0 is the last thing it does" "$(tail -n 1 "$SCRIPT")" "exit 0"

echo
echo "restart_container gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
