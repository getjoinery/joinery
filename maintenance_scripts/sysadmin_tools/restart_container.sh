#!/usr/bin/env bash
#
# restart_container.sh - restart one of this host's Joinery containers, and say
# what its state was before and after, as ONE JSON object on stdout.
#
# Version: 1.0 - the restart_container operate word of
#                specs/agent_recipes_and_vocabulary.md (First words). A
#                container is not a systemd unit, and service_health needs a
#                repair for one. The agent runs this file with one argv element
#                it has already validated against the site-name pattern.
#
# THE CONTRACT, which tests/integration/restart_container_gate.sh pins:
#
#   - ONE argument, and it must name a container on THIS host's own list: a
#     container whose name is its SITENAME, the shape install.sh creates
#     (docker run --name "$SITENAME" -e SITENAME=...). Any other container on
#     the machine — a database, a proxy, something the operator runs — is
#     refused, whatever it is called.
#   - It restarts, and only restarts: no stop without a start, no rm, no
#     exec, no pull. docker restart keeps the container, its writable layer
#     and its volumes.
#   - Every key is ALWAYS present. A state this run cannot read is the string
#     unknown; the exit code is 0 whenever the object was printed.
#   - Only compiled facts are printed, reduced to a safe character set.
#
# Runs on: a Docker host.

set -u
export LC_ALL=C

CMD_TIMEOUT=20          # seconds per inspect
RESTART_TIMEOUT=120     # docker restart waits up to its own stop timeout first

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }
json_safe() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '"%s"' "${s:0:64}"
}

NAME="${1:-}"
if [[ ! "$NAME" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]]; then
    printf 'restart_container: %s is not a container this node will restart\n' "${NAME:0:64}" >&2
    exit 2
fi
command -v docker >/dev/null 2>&1 || {
    printf 'restart_container: this machine has no docker\n' >&2
    exit 2
}

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

if ! own_container "$NAME"; then
    printf 'restart_container: %s is not a container this node will restart\n' "$NAME" >&2
    exit 2
fi

state_object() {
    local state health
    state="$(run docker inspect -f '{{.State.Status}}' "$NAME")"
    health="$(run docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$NAME")"
    printf '{"state":%s,"health":%s}' "$(json_safe "${state:-unknown}")" "$(json_safe "${health:-unknown}")"
}

BEFORE="$(state_object)"
RESTARTED=false
if timeout "$RESTART_TIMEOUT" docker restart "$NAME" >/dev/null 2>&1; then
    RESTARTED=true
fi
AFTER="$(state_object)"

printf '{'
printf '"container":%s,' "$(json_safe "$NAME")"
printf '"before":%s,' "$BEFORE"
printf '"restarted":%s,' "$RESTARTED"
printf '"after":%s' "$AFTER"
printf '}\n'
exit 0
