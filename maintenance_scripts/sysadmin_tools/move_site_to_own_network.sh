#!/usr/bin/env bash
# move_site_to_own_network.sh - move running container sites off Docker's
# default network onto a network of their own, without stopping them
# (specs/node_outbound_and_transfer.md WP2).
#
# Version: 1.1 - The host's outbound limits follow the moved sites (joinery-limits unit, WP3).
# Version: 1.0
#
# Usage:  move_site_to_own_network.sh SITE [SITE...]
#         move_site_to_own_network.sh --all     every site container not yet on its own network
#
# A site rebuilt by install.sh site gets its network then; this is for the ones
# running now. For each site: its run spec is recorded from the container if it
# has none; its network is chosen, created and written into the spec (the same
# _site_run_spec.sh functions install.sh uses, so a later rebuild keeps it); the
# container is connected to it and disconnected from Docker's default network;
# then host_housekeeping.sh runs inside it, so Apache trusts the new gateway and
# PostgreSQL admits the host from it. Published ports follow the container. The
# host's own logins to the site's database wait for the housekeeping run.
# Apache, which bound port 80 before the container had IPv6, starts again once
# so the published port answers over IPv6 too: about a second without it.
# The container's /etc/gai.conf (IPv6 first) is read once by each process:
# PHP-FPM's workers, cron's jobs and the rest pick it up as they start again.
#
# A site whose code predates the gateway fix (host_housekeeping.sh 1.13) is
# refused by name, before anything changes: on the new network it would log
# every visitor as the gateway. Apply the site's update first.
#
# The host's outbound limits (outbound_limits.sh, WP3) take in the moved sites
# once every move is done.
#
# Exit status: 0 when every named site is on its own network, 1 otherwise.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HELPER="${SCRIPT_DIR}/../install_tools/_site_run_spec.sh"
[[ -f "$HELPER" ]] || { echo "move: ${HELPER} is not beside this script" >&2; exit 1; }
. "$HELPER"

say()  { echo "move: $*"; }
fail() { echo "move: ${SITE}: $*" >&2; return 1; }

if [[ "$(id -u)" != "0" && -z "$(run_spec_root)" ]]; then
    echo "move: run as root" >&2; exit 1
fi

# The networks a container is on, space-separated.
networks_of() {  # SITE
    local n
    n="$(docker inspect -f '{{range $n, $c := .NetworkSettings.Networks}}{{$n}} {{end}}' "$1" 2>/dev/null)" || return 1
    echo "${n% }"
}

# Every running container that is a Joinery site: one with a run spec, or one
# run from the image install.sh builds for it (joinery-SITE).
all_sites() {
    local name image
    docker ps --format '{{.Names}}|{{.Image}}' 2>/dev/null | while IFS='|' read -r name image; do
        [[ -n "$name" ]] || continue
        if run_spec_exists "$name" 2>/dev/null || [[ "${image%:latest}" == "joinery-${name}" ]]; then
            echo "$name"
        fi
    done
}

move_one() {
    SITE="$1"
    local nets name lines kept hk n
    [[ "$(docker inspect -f '{{.State.Running}}' "$SITE" 2>/dev/null)" == "true" ]] \
        || { fail "no running container by that name; nothing was changed"; return 1; }
    nets="$(networks_of "$SITE")" || { fail "could not read its networks from Docker; nothing was changed"; return 1; }
    name="$(run_spec_get "$SITE" network 2>/dev/null)"
    hk="/var/www/html/${SITE}/maintenance_scripts/install_tools/host_housekeeping.sh"
    # A site on its own network already is settled again: a run stopped after
    # the move left the steps that follow it to do.
    if [[ -n "$name" && "$nets" == "$name" ]]; then
        say "${SITE} is on its own network already (${name})"
        settle "$hk"
        return
    fi

    # Its code must name its own gateway before it moves (host_housekeeping.sh 1.13).
    docker exec "$SITE" grep -q 'container_gateway6' "$hk" 2>/dev/null \
        || { fail "its code predates the gateway fix (host_housekeeping.sh 1.13), so on its own network every visitor would be logged as the gateway. Apply its update, then run this again; nothing was changed"; return 1; }

    if ! run_spec_exists "$SITE"; then
        run_spec_adopt "$SITE" || { fail "could not record how its container is run; nothing was changed"; return 1; }
        say "recorded ${SITE}'s run spec from its container: $(run_spec_path "$SITE")"
    fi
    kept="$(grep -v '^#' "$(run_spec_path "$SITE")")"
    put_back() { printf '%s\n' "$kept" | run_spec_write "$SITE" || echo "move: ${SITE}: could not put its run spec back; it was:"$'\n'"${kept}" >&2; }

    lines="$(run_spec_network_lines "$SITE")" || { fail "could not give it a network of its own; nothing was changed"; return 1; }
    name="$(sed -n 's/^network=//p' <<< "$lines")"
    printf '%s\n' "$lines" | run_spec_set_network "$SITE" || { fail "could not write its network into its run spec; nothing was changed"; return 1; }
    if ! run_spec_ensure_network "$SITE"; then
        put_back; fail "its network ${name} could not be made as its run spec says; the spec is as it was"; return 1
    fi
    if [[ " ${nets} " != *" ${name} "* ]] && ! docker network connect "$name" "$SITE" > /dev/null; then
        put_back; fail "Docker would not connect it to ${name}; it is still on ${nets}, and its run spec is as it was"; return 1
    fi
    for n in $nets; do
        [[ "$n" == "$name" ]] && continue
        if ! docker network disconnect "$n" "$SITE" > /dev/null; then
            docker network disconnect "$name" "$SITE" > /dev/null 2>&1
            put_back; fail "Docker would not disconnect it from ${n}; it is back on ${nets} alone, and its run spec is as it was"; return 1
        fi
    done
    say "${SITE} moved to ${name} ($(sed -n 's/^subnet6\?=\(..*\)$/\1/p' <<< "$lines" | paste -sd ' '))"
    settle "$hk"
}

# After the move: the container's own configuration follows its new network,
# and the site answers on its port.
settle() {  # HOUSEKEEPING_PATH
    local hk="$1" port code
    # Apache trusts the new gateway and PostgreSQL admits the host from it.
    if ! docker exec "$SITE" bash "$hk" "$SITE" > /dev/null 2>&1; then
        echo "move: ${SITE}: WARNING - host_housekeeping.sh failed inside it; its next start runs it again. Run: docker exec ${SITE} bash ${hk} ${SITE}" >&2
    fi
    # Apache bound port 80 when the container had no IPv6, and a reload keeps
    # that socket. Docker carries a visitor reaching a published port over IPv6
    # to the container's IPv6 address, where nothing would listen, so Apache
    # starts again once, bound on both. The host's proxy reaches the site over
    # IPv4, so a site with a domain only misses that second's requests.
    if [[ -n "$(run_spec_get "$SITE" subnet6)" ]] \
        && ! docker exec "$SITE" awk '$4 == "0A" && $2 ~ /:0050$/ { f = 1 } END { exit !f }' /proc/net/tcp6 2>/dev/null; then
        if ! docker exec "$SITE" grep -q _site_supervisor /proc/1/cmdline 2>/dev/null; then
            echo "move: ${SITE}: WARNING - its Apache listens on IPv4 only and runs as the container's main process; restart the container (docker restart ${SITE}) to answer its published port over IPv6" >&2
        elif docker exec "$SITE" sh -c '
                mkdir -p /run/joinery && echo "move_site_to_own_network.sh: Apache binds IPv6" > /run/joinery/supervisor.hold
                apache2ctl stop > /dev/null 2>&1
                for i in $(seq 1 100); do
                    pid="$(cat /var/run/apache2/apache2.pid 2>/dev/null)"
                    [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null || break
                    sleep 0.1
                done
                apache2ctl start > /dev/null 2>&1; rc=$?
                rm -f /run/joinery/supervisor.hold
                exit $rc'; then
            say "${SITE}'s Apache started again, now listening on IPv6 too"
        else
            echo "move: ${SITE}: WARNING - Apache did not start again; its supervisor starts it within 15 seconds" >&2
        fi
    fi
    port="$(run_spec_list "$SITE" publish | sed -n 's/^\(.*:\)\{0,1\}\([0-9]\{1,5\}\):80$/\2/p' | head -1)"
    if [[ -n "$port" ]]; then
        code="$(curl -s -o /dev/null -m 10 -w '%{http_code}' "http://127.0.0.1:${port}/" 2>/dev/null)"
        say "${SITE} answers on its port ${port}: HTTP ${code:-none}"
        [[ -n "$code" && "$code" != 000 ]] || { fail "it does not answer on its port after the move"; return 1; }
    fi
    return 0
}

[[ $# -gt 0 ]] || { echo "usage: $(basename "$0") SITE [SITE...] | --all" >&2; exit 1; }
SITES=()
if [[ "$1" == "--all" ]]; then
    mapfile -t SITES < <(all_sites)
    [[ ${#SITES[@]} -gt 0 ]] || { say "no running site container on this host"; exit 0; }
else
    SITES=("$@")
fi

status=0
for s in "${SITES[@]}"; do
    move_one "$s" || status=1
done
# A site on its own network is one the outbound limits can name (WP3).
run_spec_limits_refresh
exit "$status"
