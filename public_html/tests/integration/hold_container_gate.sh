#!/bin/bash
# @joinery-test
# name: hold_container
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/hold_container.sh, maintenance_scripts/sysadmin_tools/restart_container.sh]
#
# hold_container.sh is what the agent's hold_container operate word runs as
# root on a Docker host (specs/site_copy.md WP14): a switch-over from backups
# stops the old container once the copy has taken the site, and keeps it
# stopped until it is removed. This gate pins its contract: only a container
# on the host's own list is held; the hold is written before the stop, carries
# the restart policy the container had, and survives a second stop; start puts
# that policy back and lifts the hold only once the container runs; nothing
# but update, stop and start is asked of docker; and restart_container refuses
# a held container. Every docker call goes to a stub.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/hold_container.sh"
RESTART="$ROOT/maintenance_scripts/sysadmin_tools/restart_container.sh"
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
        if ($argv[2] === "") { echo implode(",", array_keys($o)); exit; }
        $v = $o[$argv[2]] ?? "ABSENT";
        echo is_bool($v) ? ($v ? "true" : "false") : $v;
    ' "$1" "$2"
}

# The stub keeps one container's state and restart policy in files. The hold's
# presence is recorded at the moment of each stop, so the order is checked.
mkdir -p "$T/bin" "$T/etc"
ETC="$T/etc"
HELD="$ETC/joinery/sites/siteone/held"
cat > "$T/bin/docker" <<STUB
#!/bin/bash
echo "\$*" >> "$T/calls"
case "\$1" in
    ps) printf 'siteone\nimpostor\npostgres\n' ;;
    update)
        p="\${2#--restart=}"; echo "\$p" > "$T/policy" ;;
    stop)
        [ -f "$HELD" ] && echo held > "$T/held_at_stop" || echo unheld > "$T/held_at_stop"
        [ "\${STUB_STOP_RC:-0}" = 0 ] && echo exited > "$T/state"
        exit \${STUB_STOP_RC:-0} ;;
    start)
        [ "\${STUB_START_RC:-0}" = 0 ] && echo running > "$T/state"
        exit \${STUB_START_RC:-0} ;;
    inspect)
        case "\$3" in
            *Config.Env*) case "\$4" in siteone) echo SITENAME=siteone ;; impostor) echo SITENAME=siteone ;; *) echo PATH=/bin ;; esac ;;
            *State.Status*) cat "$T/state" ;;
            *State.Health*) echo none ;;
            *RestartPolicy.Name*) p="\$(cat "$T/policy")"; echo "\${p%%:*}" ;;
            *MaximumRetryCount*) p="\$(cat "$T/policy")"; [ "\${p#*:}" != "\$p" ] && echo "\${p#*:}" || echo 0 ;;
        esac ;;
esac
STUB
chmod +x "$T/bin/docker"
hold() { PATH="$T/bin:$PATH" HOLD_CONTAINER_ETC="$ETC" bash "$SCRIPT" "$@"; }
reset() { rm -rf "$T/calls" "$ETC/joinery" "$T/held_at_stop"; echo running > "$T/state"; echo "${1:-unless-stopped}" > "$T/policy"; }

echo "=== Anything but one of the host's own containers, or stop|start, is refused ==="
for bad in "stop postgres" "stop impostor" "stop absent" "stop Siteone" "stop siteone;reboot" "stop ../x" "stop" \
           "rm siteone" "restart siteone" "" "start postgres"; do
    reset
    # shellcheck disable=SC2086
    out="$(hold $bad 2>"$T/err")"; rc=$?
    chk "refused: '$bad' (exit 2)" "$rc" "2"
    chk "refused: '$bad' printed no object" "$(printf '%s' "$out" | wc -c)" "0"
    chk "refused: '$bad' changed nothing" "$(cat "$T/calls" 2>/dev/null | grep -cE '^(update|stop|start)'):$([ -e "$ETC/joinery" ] && echo held || echo none)" "0:none"
    chk "refused: '$bad' said why" "$(grep -c '^hold_container: ' "$T/err")" "1"
done

echo "=== stop: the hold first, then the restart off, then the stop ==="
reset unless-stopped
hold stop siteone > "$T/out.json" 2> "$T/err"; rc=$?
chk "exit 0" "$rc" "0"
chk "nothing on stderr" "$(wc -c < "$T/err")" "0"
chk "every key present, in order" "$(jv "$T/out.json" "")" "container,action,done,held,state,restart"
chk "it says done, held, exited, restart no" "$(jv "$T/out.json" done):$(jv "$T/out.json" held):$(jv "$T/out.json" state):$(jv "$T/out.json" restart)" "true:true:exited:no"
chk "the hold records the policy it had" "$(cat "$HELD")" "restart=unless-stopped"
chk "the hold was written before the stop" "$(cat "$T/held_at_stop")" "held"
chk "docker was asked update, then stop, and nothing else" "$(grep -vE '^(ps|inspect)' "$T/calls" | cut -d' ' -f1 | tr '\n' ' ')" "update stop "
chk "the restart was turned off" "$(grep -c '^update --restart=no siteone$' "$T/calls")" "1"

echo "=== a second stop keeps the policy the first recorded ==="
hold stop siteone > "$T/out.json" 2>/dev/null
chk "still restart=unless-stopped, not no" "$(cat "$HELD")" "restart=unless-stopped"

echo "=== restart_container refuses a held container ==="
rm -f "$T/calls"
PATH="$T/bin:$PATH" RESTART_CONTAINER_ETC="$ETC" bash "$RESTART" siteone > "$T/rout" 2> "$T/rerr"; rc=$?
chk "exit 2, no object" "$rc:$(wc -c < "$T/rout")" "2:0"
chk "it says it is held" "$(grep -c 'is held stopped' "$T/rerr")" "1"
chk "nothing was restarted" "$(grep -c '^restart' "$T/calls")" "0"

echo "=== start: the policy back, the container started, the hold lifted ==="
rm -f "$T/calls"
hold start siteone > "$T/out.json" 2> "$T/err"; rc=$?
chk "exit 0, nothing on stderr" "$rc:$(wc -c < "$T/err")" "0:0"
chk "it says done, not held, running, unless-stopped" "$(jv "$T/out.json" done):$(jv "$T/out.json" held):$(jv "$T/out.json" state):$(jv "$T/out.json" restart)" "true:false:running:unless-stopped"
chk "the hold is gone" "$([ -e "$HELD" ] && echo present || echo gone)" "gone"
chk "docker was asked update, then start" "$(grep -vE '^(ps|inspect)' "$T/calls" | cut -d' ' -f1 | tr '\n' ' ')" "update start "
chk "restart_container takes it again" "$(PATH="$T/bin:$PATH" RESTART_CONTAINER_ETC="$ETC" bash "$RESTART" siteone >/dev/null 2>&1; echo $?)" "0"

echo "=== on-failure keeps its retry count through a hold ==="
reset on-failure:5
hold stop siteone > /dev/null 2>&1
chk "the hold records on-failure:5" "$(cat "$HELD")" "restart=on-failure:5"
hold start siteone > /dev/null 2>&1
chk "and puts it back" "$(cat "$T/policy")" "on-failure:5"

echo "=== a start that does not take keeps the hold ==="
reset unless-stopped
hold stop siteone > /dev/null 2>&1
STUB_START_RC=1 PATH="$T/bin:$PATH" HOLD_CONTAINER_ETC="$ETC" bash "$SCRIPT" start siteone > "$T/out.json" 2>/dev/null; rc=$?
chk "exit 0 with the object" "$rc:$(jv "$T/out.json" done)" "0:false"
chk "still held, still exited" "$(jv "$T/out.json" held):$(jv "$T/out.json" state)" "true:exited"

echo "=== a stop that does not take says so, and the hold stays ==="
reset unless-stopped
STUB_STOP_RC=1 PATH="$T/bin:$PATH" HOLD_CONTAINER_ETC="$ETC" bash "$SCRIPT" stop siteone > "$T/out.json" 2>/dev/null; rc=$?
chk "exit 0, not done, still running" "$rc:$(jv "$T/out.json" done):$(jv "$T/out.json" state)" "0:false:running"
chk "held, so the health check leaves it for the next try" "$(jv "$T/out.json" held)" "true"

echo
echo "RESULT: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
