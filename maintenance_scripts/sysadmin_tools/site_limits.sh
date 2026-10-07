#!/usr/bin/env bash
#
# site_limits.sh - change one of this Docker host's sites' limits without
# rebuilding it: its memory, its CPU ceiling and its disk allowance, and say
# what is now in force, as ONE JSON object on stdout.
#
# Version: 1.0 - the site_limits operate word and install.sh site-limits
#                (specs/multi_tenant_docker_hosts.md WP6). The agent runs this
#                file with four argv elements it has already validated: the
#                site name, then the memory, CPU and disk values. Each value is
#                a size or figure, none to lift that limit, or keep to leave it.
#
# THE CONTRACT, which tests/integration/site_limits_gate.sh pins:
#
#   - The name must be a container on THIS host's own list (its name is its
#     SITENAME, the shape install.sh creates) with a run spec. Any other is
#     refused (exit 2), and so is a value install.sh site would refuse: a
#     memory size or CPU ceiling Docker would not take, a CPU ceiling above
#     the host's, a disk allowance on a host with no disk pool.
#   - Memory and CPU change on the running container (docker update), and the
#     run spec records them, so a rebuild keeps them. Neither can be lifted on
#     a running container: Docker keeps a CPU ceiling in the container's
#     configuration and puts it back at the next start, and refuses to lift a
#     memory limit at all. none for either is refused here; install.sh site
#     --memory=none or --cpus=none rebuilds the container without it.
#   - The container is restarted when its memory changed: PostgreSQL and
#     PHP-FPM size themselves from it when they start.
#   - The disk allowance changes on the disk pool (docker_disk_pool.sh allow;
#     release for none) and in the run spec. A site given its first
#     allowance here has its writable layer capped from its next rebuild.
#   - Every key is ALWAYS present; the exit code is 0 whenever the object was
#     printed. Only compiled facts are printed, reduced to a safe character set.
#
# Runs on: a Docker host.

set -u
export LC_ALL=C

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS="${SITE_LIMITS_TOOLS:-$HERE/../install_tools}"
CMD_TIMEOUT=30

run() { timeout "$CMD_TIMEOUT" "$@"; }
json_safe() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '"%s"' "${s:0:64}"
}
refuse() {
    printf 'site_limits: %s\n' "$1" >&2
    exit 2
}

NAME="${1:-}"; MEM_IN="${2:-keep}"; CPUS_IN="${3:-keep}"; DISK_IN="${4:-keep}"
[[ "$NAME" =~ ^[a-z0-9][a-z0-9_-]{1,49}$ ]] || refuse "${NAME:0:64} is not a site this host serves"
command -v docker >/dev/null 2>&1 || refuse "this machine has no docker"
[[ -f "$TOOLS/_site_run_spec.sh" ]] || refuse "this host's support bundle does not carry _site_run_spec.sh"
. "$TOOLS/_site_run_spec.sh"

# Ours: a container whose SITENAME is its name, with a run spec.
site_env="$(run docker inspect -f '{{range .Config.Env}}{{if eq (index (split . "=") 0) "SITENAME"}}{{.}}{{end}}{{end}}' "$NAME" 2>/dev/null)" \
    || refuse "${NAME} is not a container on this host"
[[ "$site_env" == "SITENAME=${NAME}" ]] || refuse "${NAME} is not a site container install.sh made"
run_spec_exists "$NAME" || refuse "${NAME} has no run spec on this host (install.sh site writes one)"

# Each value: keep is the spec's, none lifts it, anything else is checked the
# way install.sh site checks it.
pick() {  # KEY INPUT NORMALISER
    case "$2" in
        keep) run_spec_get "$NAME" "$1" ;;
        none) printf '' ;;
        *) "$3" "$2" ;;
    esac
}
OLD_MEM="$(run_spec_get "$NAME" memory)"; OLD_CPUS="$(run_spec_get "$NAME" cpus)"; OLD_DISK="$(run_spec_get "$NAME" disk)"
MEM="$(pick memory "$MEM_IN" run_spec_norm_memory)" || refuse "memory ${MEM_IN:0:32} is not a size Docker takes (512m, 1G; at least 6m)"
CPUS="$(pick cpus "$CPUS_IN" run_spec_norm_cpus)" || refuse "cpus ${CPUS_IN:0:32} is not a CPU ceiling Docker takes (1.0, 0.5; at least 0.01)"
DISK="$(pick disk "$DISK_IN" run_spec_norm_disk)" || refuse "disk ${DISK_IN:0:32} is not a disk size (4G, 500M; at least 100M)"
[[ -n "$MEM" || -z "$OLD_MEM" ]] \
    || refuse "a running container's memory limit cannot be lifted; rebuild it with install.sh site ${NAME} --memory=none"
[[ -n "$CPUS" || -z "$OLD_CPUS" ]] \
    || refuse "a running container's CPU ceiling cannot be lifted; rebuild it with install.sh site ${NAME} --cpus=none"
run_spec_cpus_fit "$CPUS" || refuse "a CPU ceiling of ${CPUS} is more than this host has ($(run_spec_host_cpus 2>/dev/null || echo unknown) CPUs)"
if [[ -n "$DISK" ]] && ! bash "$TOOLS/docker_disk_pool.sh" check; then
    refuse "this host has no disk pool, so ${NAME}'s disk cannot be capped here"
fi

DONE=true; REASON=""; RESTARTED=false

# Memory and CPU, live. Swap is held to the memory figure, as run_spec_args does.
args=()
if [[ "$MEM" != "$OLD_MEM" ]]; then args+=("--memory=${MEM}" "--memory-swap=${MEM}"); fi
if [[ "$CPUS" != "$OLD_CPUS" ]]; then args+=("--cpus=${CPUS}"); fi
if (( ${#args[@]} > 0 )) && ! run docker update "${args[@]}" "$NAME" >/dev/null 2>&1; then
    DONE=false; REASON="docker_update_refused"
fi
if [[ "$DONE" == true ]]; then
    run_spec_set "$NAME" memory "$MEM" && run_spec_set "$NAME" cpus "$CPUS" || { DONE=false; REASON="spec_not_written"; }
fi

# The disk allowance.
if [[ "$DONE" == true && "$DISK" != "$OLD_DISK" ]]; then
    if [[ -n "$DISK" ]]; then
        bash "$TOOLS/docker_disk_pool.sh" allow "$NAME" "$DISK" >/dev/null 2>&1 || { DONE=false; REASON="allowance_not_set"; }
    else
        bash "$TOOLS/docker_disk_pool.sh" release "$NAME" >/dev/null 2>&1 || true
    fi
    [[ "$DONE" == true ]] && { run_spec_set "$NAME" disk "$DISK" || { DONE=false; REASON="spec_not_written"; }; }
fi

# PostgreSQL and PHP-FPM size themselves from the memory limit as they start.
if [[ "$DONE" == true && "$MEM" != "$OLD_MEM" ]]; then
    if run docker restart "$NAME" >/dev/null 2>&1; then RESTARTED=true; else DONE=false; REASON="restart_failed"; fi
fi

in_force() { run_spec_get "$NAME" "$1"; }
printf '{'
printf '"site":%s,' "$(json_safe "$NAME")"
printf '"done":%s,' "$DONE"
printf '"memory":%s,' "$(json_safe "$(in_force memory)")"
printf '"cpus":%s,' "$(json_safe "$(in_force cpus)")"
printf '"disk":%s,' "$(json_safe "$(in_force disk)")"
printf '"restarted":%s,' "$RESTARTED"
printf '"reason":%s' "$(json_safe "$REASON")"
printf '}\n'
exit 0
