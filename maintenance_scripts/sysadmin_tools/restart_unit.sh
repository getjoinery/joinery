#!/usr/bin/env bash
#
# restart_unit.sh - restart one of the host's expected units, and say what its
# state was before and after, as ONE JSON object on stdout.
#
# Version: 1.1 - php-fpm resolves to the ACTIVE unit, else the newest installed
#                (review B13): the first unit file alphabetically could be a
#                leftover 8.1 beside the 8.3 the host serves.
# Version: 1.0 - the restart_unit operate word of
#                specs/agent_recipes_and_vocabulary.md (First words). The agent
#                runs this file, verified against the release manifest, with one
#                argv element it has already validated; the service_health
#                recipe runs it with a unit from the same list.
#
# THE CONTRACT, which tests/integration/restart_unit_gate.sh pins:
#
#   - ONE argument, closed. The unit must be one of host_report.sh's expected
#     units (a mirror of restartUnitUnits in the agent's
#     operate_restart_unit.go). joinery-agent is not among them and never is:
#     the agent is restarted only through restart_agent and its proof.
#   - php-fpm is the one name that is not a unit file: the host runs a
#     versioned php8.x-fpm.service; the active one is restarted, else the
#     newest installed, by the same rule host_report.sh reads it with.
#   - It restarts, and only restarts: no stop without a start, no enable,
#     disable or mask. A unit absent from the machine is reported absent and
#     nothing runs.
#   - Every key is ALWAYS present. A state this run cannot read is the string
#     unknown; the exit code is 0 whenever the object was printed, and
#     `restarted` says whether systemctl accepted the restart.
#   - Only compiled facts are printed, reduced to a safe character set.
#
# Runs on: any systemd host.

set -u
export LC_ALL=C

CMD_TIMEOUT=20          # seconds per external command
RESTART_TIMEOUT=90      # a PostgreSQL restart flushes; give it room
SETTLE_SECONDS=10       # how long "activating" may last before after is read

# THE CLOSED LIST: host_report.sh's expected units, less nothing, because the
# agent is not one of them.
UNITS=(fail2ban apache2 php-fpm cron postgresql)

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }
json_safe() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '"%s"' "${s:0:64}"
}

UNIT="${1:-}"
ok=0
for u in "${UNITS[@]}"; do [[ "$UNIT" == "$u" ]] && { ok=1; break; }; done
if (( ! ok )); then
    printf 'restart_unit: %s is not a unit this node will restart\n' "${UNIT:0:64}" >&2
    exit 2
fi

# php-fpm: the ACTIVE versioned unit, else the newest installed — the same rule
# as host_report.sh, so the unit restarted is the one the report judged.
if [[ "$UNIT" == "php-fpm" ]]; then
    SERVICE="$(run systemctl list-units 'php*-fpm.service' --state=active --plain --no-legend --no-pager | awk 'NR==1 {print $1}')"
    if [[ -z "$SERVICE" ]]; then
        SERVICE="$(run systemctl list-unit-files 'php*-fpm.service' --plain --no-legend --no-pager | awk '{print $1}' | sort -V | tail -n 1)"
    fi
else
    SERVICE="${UNIT}.service"
fi

show_value() {
    local prop="$1" v
    [[ -n "$SERVICE" ]] || { printf 'absent'; return; }
    v="$(run systemctl show -p "$prop" --value "$SERVICE")" || { printf 'unknown'; return; }
    [[ -n "$v" ]] && printf '%s' "$v" || printf 'unknown'
}

state_object() {
    printf '{"active_state":%s,"sub_state":%s,"result":%s}' \
        "$(json_safe "$(show_value ActiveState)")" \
        "$(json_safe "$(show_value SubState)")" \
        "$(json_safe "$(show_value Result)")"
}

LOAD="$(show_value LoadState)"
BEFORE="$(state_object)"
RESTARTED=false
ABSENT=false
case "$LOAD" in
    absent|not-found|masked) ABSENT=true ;;
    *)
        if timeout "$RESTART_TIMEOUT" systemctl restart "$SERVICE" 2>/dev/null; then
            RESTARTED=true
        fi
        for _ in $(seq 1 "$SETTLE_SECONDS"); do
            [[ "$(show_value ActiveState)" == "activating" ]] || break
            sleep 1
        done
        ;;
esac
AFTER="$(state_object)"

printf '{'
printf '"unit":%s,' "$(json_safe "${SERVICE:-$UNIT}")"
printf '"absent":%s,' "$ABSENT"
printf '"before":%s,' "$BEFORE"
printf '"restarted":%s,' "$RESTARTED"
printf '"after":%s' "$AFTER"
printf '}\n'
exit 0
