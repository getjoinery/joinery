#!/usr/bin/env bash
#
# _site_engine.sh - which container engine this host's sites run on, and the one
# way the install tools call it (specs/site_container_engine.md D7). Sourced,
# not run.
#
# Version: 1.0
#
# A host whose site container engine is installed (install_site_engine.sh: our
# own build of Podman, current under /usr/local/lib/joinery-site-engine) runs
# its sites on it; any other host runs them on Docker, until it moves
# (site_container_engine WP5). Nothing else decides: there is no setting to
# disagree with what the machine has, and no podman-docker shim, whose
# /usr/bin/docker would hide which engine answered.
#
#   site_engine ARGS...        the engine's command line (podman or docker)
#   site_engine_kind           prints podman or docker
#   site_engine_is_podman      exit 0 on a host whose sites run on the site engine
#   site_engine_idblock_base N the first host id of id block N (D4)
#   SITE_ENGINE_IDBLOCK_SIZE   the ids in a block: 65536, so 65534 (nobody) maps
#
# JOINERY_SITE_ENGINE_KIND (podman or docker) names the engine instead, for
# tests running against a stand-in on PATH.

SITE_ENGINE_ROOT="${SITE_ENGINE_ROOT:-${JOINERY_SITE_ENGINE_ROOT:-}}"
SITE_ENGINE_CURRENT="${SITE_ENGINE_ROOT}/usr/local/lib/joinery-site-engine/current"
SITE_ENGINE_IDBLOCK_FIRST=2147483648
SITE_ENGINE_IDBLOCK_SIZE=65536
SITE_ENGINE_IDBLOCKS=32767

site_engine_kind() {
    case "${JOINERY_SITE_ENGINE_KIND:-}" in
        podman|docker) echo "$JOINERY_SITE_ENGINE_KIND"; return 0 ;;
        "") ;;
        *) echo "site engine: JOINERY_SITE_ENGINE_KIND is '${JOINERY_SITE_ENGINE_KIND}', not podman or docker" >&2; return 1 ;;
    esac
    if [[ -L "$SITE_ENGINE_CURRENT" && -x "${SITE_ENGINE_ROOT}/usr/local/bin/podman" ]]; then
        echo podman
    else
        echo docker
    fi
}

site_engine_is_podman() { [[ "$(site_engine_kind)" == "podman" ]]; }

site_engine() {
    local kind
    kind="$(site_engine_kind)" || return 1
    command "$kind" "$@"
}

site_engine_idblock_base() {  # N
    [[ "$1" =~ ^[0-9]+$ ]] && (( $1 < SITE_ENGINE_IDBLOCKS )) || { echo "site engine: id block '$1' is not 0 to $(( SITE_ENGINE_IDBLOCKS - 1 ))" >&2; return 1; }
    echo $(( SITE_ENGINE_IDBLOCK_FIRST + $1 * SITE_ENGINE_IDBLOCK_SIZE ))
}
