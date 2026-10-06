#!/usr/bin/env bash
#
# hold_container.sh - stop one of this host's Joinery containers and keep it
# stopped, or start it again, and say what became of it, as ONE JSON object on
# stdout.
#
# Version: 1.0 - the hold_container operate word (specs/site_copy.md WP14): a
#                switch-over from backups stops the old container once its copy
#                has taken the site over, and keeps it stopped until it is
#                removed. The agent runs this file with two argv elements it has
#                already validated: stop|start, and the site name.
#
# THE CONTRACT, which tests/integration/hold_container_gate.sh pins:
#
#   - The name must be a container on THIS host's own list: one whose name is
#     its SITENAME, the shape install.sh creates. Any other container is
#     refused, whatever it is called (the same list restart_container keeps).
#   - stop writes the hold FIRST, then turns off the container's restart and
#     stops it. The hold is /etc/joinery/sites/{site}/held, holding the restart
#     policy the container had; while it exists, container_health does not
#     restart the container and restart_container refuses it, and with the
#     restart off, neither does Docker at boot. A second stop keeps the policy
#     the first one recorded.
#   - start puts the recorded restart policy back, starts the container, and
#     removes the hold once it runs. A start that did not take keeps the hold.
#   - It stops and starts, and only that: no rm, no exec, no pull. The
#     container, its writable layer and its volumes stay.
#   - Every key is ALWAYS present; the exit code is 0 whenever the object was
#     printed. Only compiled facts are printed, reduced to a safe character set.
#
# Runs on: a Docker host.

set -u
export LC_ALL=C

CMD_TIMEOUT=20          # seconds per inspect or update
STOP_TIMEOUT=120        # docker stop waits up to the container's own stop timeout
ETC="${HOLD_CONTAINER_ETC:-/etc}"

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }
json_safe() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '"%s"' "${s:0:64}"
}
refuse() {
    printf 'hold_container: %s\n' "$1" >&2
    exit 2
}

ACTION="${1:-}"
NAME="${2:-}"
[[ "$ACTION" == "stop" || "$ACTION" == "start" ]] || refuse "the action is stop or start"
[[ "$NAME" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || refuse "${NAME:0:64} is not a container this node will hold"
command -v docker >/dev/null 2>&1 || refuse "this machine has no docker"

# The host's own list: every container, running or not, whose name is its
# SITENAME. The name alone proves nothing; the environment install.sh gave it
# is what makes it ours.
own_container() {
    local c site
    for c in $(run docker ps -a --format '{{.Names}}'); do
        [[ "$c" == "$1" ]] || continue
        site="$(run docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$c" | awk -F= '$1=="SITENAME" {print $2; exit}')"
        [[ "$site" == "$c" ]] && return 0
    done
    return 1
}
own_container "$NAME" || refuse "$NAME is not a container this node will hold"

HELD="${ETC}/joinery/sites/${NAME}/held"
POLICY_RE='^(no|always|unless-stopped|on-failure(:[0-9]+)?)$'

state_of()  { run docker inspect -f '{{.State.Status}}' "$NAME"; }
policy_of() {
    local p
    p="$(run docker inspect -f '{{.HostConfig.RestartPolicy.Name}}' "$NAME")"
    [[ "$p" == "on-failure" ]] && p="on-failure:$(run docker inspect -f '{{.HostConfig.RestartPolicy.MaximumRetryCount}}' "$NAME")"
    printf '%s' "$p"
}
held_policy() {
    local p
    p="$(sed -n 's/^restart=//p' "$HELD" 2>/dev/null | head -1)"
    [[ "$p" =~ $POLICY_RE ]] && printf '%s' "$p"
}

DONE=false
if [[ "$ACTION" == "stop" ]]; then
    if [[ ! -f "$HELD" ]]; then
        p="$(policy_of)"
        [[ "$p" =~ $POLICY_RE ]] || p="unless-stopped"
        mkdir -p "$(dirname "$HELD")" \
            && printf 'restart=%s\n' "$p" > "${HELD}.tmp" && mv -f "${HELD}.tmp" "$HELD" \
            || refuse "the hold for $NAME could not be written; nothing was stopped"
    fi
    run docker update --restart=no "$NAME" >/dev/null
    timeout "$STOP_TIMEOUT" docker stop "$NAME" >/dev/null 2>&1
    case "$(state_of)" in exited|created) DONE=true ;; esac
else
    p="$(held_policy)"
    [[ -n "$p" && "$p" != "no" ]] && run docker update --restart="$p" "$NAME" >/dev/null
    timeout "$STOP_TIMEOUT" docker start "$NAME" >/dev/null 2>&1
    if [[ "$(state_of)" == "running" ]]; then
        rm -f "$HELD"
        DONE=true
    fi
fi

STATE="$(state_of)"
printf '{'
printf '"container":%s,' "$(json_safe "$NAME")"
printf '"action":%s,' "$(json_safe "$ACTION")"
printf '"done":%s,' "$DONE"
printf '"held":%s,' "$([[ -f "$HELD" ]] && echo true || echo false)"
printf '"state":%s,' "$(json_safe "${STATE:-unknown}")"
printf '"restart":%s' "$(json_safe "$(policy_of || true)")"
printf '}\n'
exit 0
