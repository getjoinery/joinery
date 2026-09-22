#!/usr/bin/env bash
#
# reset_failed_unit.sh - clear systemd's record that one unit failed, and say
# what the unit's state was before and after, as ONE JSON object on stdout.
#
# Version: 1.0 - the reset_failed_unit operate word of
#                specs/disk_headroom_and_unit_diagnosis.md § 9. The agent runs
#                this file, verified against the release manifest, with one
#                argv element it has already validated.
#
# THE CONTRACT, which tests/integration/reset_failed_unit_gate.sh pins:
#
#   - ONE argument, closed. The unit must be a member of the compiled list
#     below — the SAME list as unit_journal.sh, a mirror of the enum in the
#     agent's observe_unit_journal.go — and either alone refuses. There is no
#     path, no pattern, no "all units": `systemctl reset-failed` with no unit
#     clears every unit on the machine, and this script can never run it so.
#   - It clears a RECORD and changes nothing running. reset-failed starts,
#     stops and restarts nothing; a unit that is still broken fails again on
#     its next start, which is the honest outcome.
#   - Every key is ALWAYS present. A state this run cannot read is the string
#     unknown; the exit code is 0 whenever the object was printed, and
#     `reset` says whether systemctl accepted the reset.
#   - Only compiled facts are printed (a state, a result), reduced to a safe
#     character set, so there is nothing to redact.
#
# Runs on: any systemd host. A machine without systemd prints the object with
# unknowns and reset false.

set -u
export LC_ALL=C

CMD_TIMEOUT=20          # seconds per external command

# THE CLOSED LIST. The same twelve as unit_journal.sh, and a mirror of
# unitJournalUnits in the agent. The unit that can be asked why is the unit
# that can be cleared once the answer is known.
UNITS=(
    fail2ban apache2 cron postgresql
    joinery-agent
    man-db unattended-upgrades logrotate
    apt-daily apt-daily-upgrade
    fstrim e2scrub_all
)

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }
json_safe() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '"%s"' "${s:0:64}"
}

UNIT="${1:-}"
ok=0
for u in "${UNITS[@]}"; do [[ "$UNIT" == "$u" ]] && { ok=1; break; }; done
if (( ! ok )); then
    printf 'reset_failed_unit: %s is not a unit this node will clear\n' "${UNIT:0:64}" >&2
    exit 2
fi

SERVICE="${UNIT}.service"

show_value() {
    local prop="$1" v
    v="$(run systemctl show -p "$prop" --value "$SERVICE")" || { printf 'unknown'; return; }
    [[ -n "$v" ]] && printf '%s' "$v" || printf 'unknown'
}

state_object() {
    printf '{"active_state":%s,"sub_state":%s,"result":%s}' \
        "$(json_safe "$(show_value ActiveState)")" \
        "$(json_safe "$(show_value SubState)")" \
        "$(json_safe "$(show_value Result)")"
}

BEFORE="$(state_object)"
RESET=false
if run systemctl reset-failed "$SERVICE"; then
    RESET=true
fi
AFTER="$(state_object)"

printf '{'
printf '"unit":%s,' "$(json_safe "$SERVICE")"
printf '"before":%s,' "$BEFORE"
printf '"reset":%s,' "$RESET"
printf '"after":%s' "$AFTER"
printf '}\n'
exit 0
