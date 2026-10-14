#!/usr/bin/env bash
#
# site_quiet_host.sh - the host's half of a site container's quiet state
# (specs/site_container_engine.md D9): a table inside the site's own network
# namespace that refuses every account but the container's root, loopback and
# replies apart, so a dormant copy or a frozen source sends nothing while its
# agent, as root, still reaches the management node and backup storage.
#
# Version: 1.0
#
#   site_quiet_host.sh on SITE copy|switchover
#                         Root. Records the host's state for SITE
#                         (/etc/joinery/sites/SITE/quiet) and loads the table
#                         into its running container. A container that is not
#                         running gets it when it starts (the hook) or at the
#                         next assert. `copy` is install.sh's, at a dormant
#                         install (the agent's word sets switchover only): it is
#                         written only where no record is, and a copy is never
#                         turned into a switch-over. The record is half of what
#                         lets discard_copy.sh remove it.
#   site_quiet_host.sh off SITE
#                         Root. A copy's: refused while the site still says it
#                         is quiet (its own state file, asked inside the running
#                         container), so the host lifts a copy's table only
#                         after the copy has left its quiet state and a plane
#                         that asks in the wrong order changes nothing. A
#                         switch-over's is lifted first, while the site still
#                         shows its 503, so it never serves with no network.
#                         Removes the table from the running container, then
#                         the record.
#   site_quiet_host.sh assert
#                         Root. Every recorded site whose container runs has
#                         its table (host housekeeping, every converge).
#   site_quiet_host.sh hook
#                         The site container engine's OCI hook at createRuntime,
#                         with the container's state on stdin: a site the host
#                         records as quiet gets its table before its first
#                         process runs, and a table that cannot be loaded stops
#                         the start. The site is named by the io.joinery.site
#                         annotation its run spec gives it; any other container
#                         is passed over.
#   site_quiet_host.sh status [SITE]
#                         Prints each recorded site: its reason, whether its
#                         container runs, and whether the table is loaded.
#   site_quiet_host.sh install
#                         Root. Copies this script to /usr/local/sbin/joinery-site-quiet
#                         and the engine helper to /usr/local/lib/joinery-site-quiet/,
#                         and on the site container engine writes the hook
#                         (/etc/containers/oci/hooks.d/joinery-site-quiet.json).
#                         Run again, it rewrites only what differs.
#
# THE RULE. `meta skuid != { 0, BASE }`, BASE the host id the site's root maps
# to (/proc/PID/uid_map). A namespace owned by the site's own user namespace
# (the site container engine) sees its root as 0; one owned by the host's (a
# Docker host that remaps) sees BASE; a Docker host that does not remap sees 0
# for both. Every other account is refused, the web user and Postfix's client
# among them, and its name lookups with it, at once. The site holds no
# CAP_NET_ADMIN and cannot make a user namespace, so it cannot lift the table;
# nothing here reads anything the site wrote except the one yes or no of off,
# which only ever keeps the table in place.
#
# A fixture: SITE_QUIET_HOST_ETC and SITE_QUIET_HOST_PROC stand for /etc and
# /proc, and only an unprivileged run may set them.
#
# Exit: 0 done; 1 a table did not load or come off (said why); 2 refused,
# nothing changed.

set -u
export LC_ALL=C

INSTALLED="/usr/local/sbin/joinery-site-quiet"
INSTALLED_LIB="/usr/local/lib/joinery-site-quiet"
HOOK_FILE="/etc/containers/oci/hooks.d/joinery-site-quiet.json"
TABLE="joinery_site_state"
CMD_TIMEOUT=20

ETC="/etc"; PROC="/proc"
if [[ "$(id -u)" != "0" ]]; then
    [[ -n "${SITE_QUIET_HOST_ETC:-}" ]] && ETC="${SITE_QUIET_HOST_ETC%/}"
    [[ -n "${SITE_QUIET_HOST_PROC:-}" ]] && PROC="${SITE_QUIET_HOST_PROC%/}"
fi
SITES_DIR="${ETC}/joinery/sites"

say()    { echo "site_quiet_host: $*"; }
warn()   { echo "site_quiet_host: $*" >&2; }
refuse() { warn "$1"; exit 2; }
run()    { timeout "$CMD_TIMEOUT" "$@"; }

site_ok() { [[ "$1" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]]; }

# The engine helper: beside this script in a tree, or the installed copy's.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "${HERE}/../install_tools/_site_engine.sh" ]]; then
    HELPER="${HERE}/../install_tools/_site_engine.sh"
else
    HELPER="${INSTALLED_LIB}/_site_engine.sh"
fi
engine_ready() {
    [[ -n "${ENGINE:-}" ]] && return 0
    [[ -f "${HELPER}" ]] || { warn "no engine helper at ${HELPER}"; return 1; }
    # shellcheck source=../install_tools/_site_engine.sh
    . "${HELPER}"
    ENGINE="$(site_engine_bin)" || { warn "this machine's container engine could not be named"; return 1; }
}

record_of() { echo "${SITES_DIR}/$1/quiet"; }

# The reason the host records for SITE, or nothing. A record that says
# anything else is held as switchover, the stricter page.
reason_of() {
    local f line
    f="$(record_of "$1")"
    [[ -f "$f" && ! -L "$f" ]] || return 0
    line="$(head -c 64 "$f" 2>/dev/null | head -1 | tr -d '\r')"
    case "$line" in
        copy|switchover) echo "$line" ;;
        *) echo "switchover" ;;
    esac
}

# Whether NAME is one of this host's own site containers, running or not: one
# whose name is its SITENAME, the shape install.sh creates (hold_container.sh's
# list).
own_container() {
    local c site
    engine_ready || return 1
    for c in $(run "$ENGINE" ps -a --format '{{.Names}}' 2>/dev/null); do
        [[ "$c" == "$1" ]] || continue
        site="$(run "$ENGINE" inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$c" 2>/dev/null | awk -F= '$1=="SITENAME" {print $2; exit}')"
        [[ "$site" == "$c" ]] && return 0
    done
    return 1
}

# The running container's pid, or nothing.
pid_of() {
    local pid
    engine_ready || return 0
    [[ "$(run "$ENGINE" inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" == "true" ]] || return 0
    pid="$(run "$ENGINE" inspect -f '{{.State.Pid}}' "$1" 2>/dev/null)"
    [[ "$pid" =~ ^[1-9][0-9]*$ ]] && echo "$pid"
}

# BASE: the host id the container's root maps to.
base_of() {
    local base
    base="$(awk 'NR == 1 && $1 == 0 { print $2 }' "${PROC}/$1/uid_map" 2>/dev/null)"
    [[ "$base" =~ ^[0-9]+$ ]] || return 1
    echo "$base"
}

render_table() {  # SITE REASON BASE
    local ids="0"
    [[ "$3" != "0" ]] && ids="0, $3"
    cat <<EOF
# The quiet state of $1: $2 (specs/site_container_engine.md D9), loaded into
# the site's own network namespace by site_quiet_host.sh. Every account but the
# container's root reaches nothing past loopback; replies to a connection
# someone else opened pass, so a visitor sees the 503 and the owner the look.
table inet ${TABLE}
delete table inet ${TABLE}
table inet ${TABLE} {
    chain output {
        type filter hook output priority 0; policy accept;
        oifname "lo" accept
        ct direction reply accept
        meta skuid != { ${ids} } meta l4proto tcp reject with tcp reset
        meta skuid != { ${ids} } reject with icmpx type admin-prohibited
    }
}
EOF
}

in_netns() {  # PID CMD...
    local pid="$1"; shift
    run nsenter --net="${PROC}/${pid}/ns/net" "$@"
}

table_loaded() { in_netns "$1" nft list table inet "${TABLE}" >/dev/null 2>&1; }

# load SITE REASON PID -> 0 the table is in the container's namespace.
load() {
    local site="$1" reason="$2" pid="$3" base tmp rc
    base="$(base_of "$pid")" || { warn "${site}: cannot read its id map (${PROC}/${pid}/uid_map)"; return 1; }
    tmp="$(mktemp "${TMPDIR:-/tmp}/site_quiet_host.XXXXXX")" || return 1
    render_table "$site" "$reason" "$base" > "$tmp"
    in_netns "$pid" nft -f "$tmp" 2>/dev/null
    rc=$?
    rm -f "$tmp"
    if [[ "$rc" != "0" ]] || ! table_loaded "$pid"; then
        warn "${site}: the table did not load into its network namespace; its web user can still reach the network"
        return 1
    fi
    return 0
}

cmd_on() {
    local site="${1:-}" reason="${2:-}" pid f
    site_ok "$site" || refuse "${site:0:64} is not a site name"
    [[ "$reason" == "copy" || "$reason" == "switchover" ]] || refuse "the reason is copy or switchover"
    own_container "$site" || refuse "${site} is not a site container on this host; nothing was changed"
    if [[ "$(reason_of "$site")" == "copy" && "$reason" != "copy" ]]; then
        refuse "${site} is a dormant copy on this host, and a copy stays one until it takes over its source; nothing was changed"
    fi
    # A copy's record is written once, by its install, on a name with none.
    if [[ "$reason" == "copy" && ( -e "$(record_of "$site")" || -L "$(record_of "$site")" ) ]]; then
        refuse "${site} already has a record on this host; a copy's is written once, at its install. Nothing was changed"
    fi
    mkdir -p "${SITES_DIR}/${site}" 2>/dev/null || refuse "cannot write ${SITES_DIR}/${site}"
    f="$(record_of "$site")"
    [[ -L "$f" ]] && rm -f "$f"
    printf '%s\n' "$reason" > "${f}.tmp" && chmod 644 "${f}.tmp" && mv -f "${f}.tmp" "$f" \
        || refuse "cannot write ${f}"
    say "${site} is quiet on this host (${reason})"
    pid="$(pid_of "$site")"
    if [[ -z "$pid" ]]; then
        say "${site}'s container is not running; it gets its table when it starts"
        return 0
    fi
    load "$site" "$reason" "$pid" || return 1
    say "${site}: its web user reaches nothing past loopback"
}

cmd_off() {
    local site="${1:-}" pid
    site_ok "$site" || refuse "${site:0:64} is not a site name"
    if [[ -z "$(reason_of "$site")" ]]; then
        say "${site} is not quiet on this host"
        return 0
    fi
    own_container "$site" || refuse "${site} is not a site container on this host; nothing was changed"
    pid="$(pid_of "$site")"
    [[ -n "$pid" ]] || refuse "${site}'s container is not running, so it cannot say whether it has left its quiet state; start it first. Nothing was changed"
    # A copy's: the one question asked of the site. A yes only keeps the
    # table, and so does an answer that is neither yes nor no.
    if [[ "$(reason_of "$site")" == "copy" ]]; then
        run "$ENGINE" exec "$site" test -e "/etc/joinery/sites/${site}/state" 2>/dev/null
        case $? in
            0) refuse "${site} still says it is a quiet copy (its own state file); it leaves that state first (site_quiet off --copy-promoted), then the host lifts its table. Nothing was changed" ;;
            1) : ;;
            *) refuse "${site} could not be asked whether it has left its quiet state; nothing was changed" ;;
        esac
    fi
    if table_loaded "$pid"; then
        in_netns "$pid" nft delete table inet "${TABLE}" 2>/dev/null
        if table_loaded "$pid"; then
            warn "${site}: the table did not come off; the record is kept"
            return 1
        fi
    fi
    rm -f "$(record_of "$site")" || { warn "cannot remove $(record_of "$site")"; return 1; }
    say "${site} is live on this host: its web user reaches the network"
}

cmd_assert() {
    local f site reason pid rc=0
    for f in "${SITES_DIR}"/*/quiet; do
        [[ -f "$f" && ! -L "$f" ]] || continue
        engine_ready || return 1
        site="$(basename "$(dirname "$f")")"
        site_ok "$site" || continue
        reason="$(reason_of "$site")"
        [[ -n "$reason" ]] || continue
        pid="$(pid_of "$site")"
        [[ -n "$pid" ]] || continue
        table_loaded "$pid" && continue
        if load "$site" "$reason" "$pid"; then
            say "${site}: table loaded again"
        else
            rc=1
        fi
    done
    return "$rc"
}

# The OCI state on stdin: compact JSON from the runtime, read for two fields.
cmd_hook() {
    local state site pid reason
    state="$(head -c 1048576)"
    [[ "$state" =~ \"io\.joinery\.site\"[[:space:]]*:[[:space:]]*\"([a-z0-9][a-z0-9_-]{0,49})\" ]] || return 0
    site="${BASH_REMATCH[1]}"
    reason="$(reason_of "$site")"
    [[ -n "$reason" ]] || return 0
    [[ "$state" =~ \"pid\"[[:space:]]*:[[:space:]]*([1-9][0-9]*) ]] || { warn "${site} is quiet and the runtime gave no pid; the start is stopped"; return 1; }
    pid="${BASH_REMATCH[1]}"
    load "$site" "$reason" "$pid" || { warn "${site} is quiet and its table did not load; the start is stopped"; return 1; }
}

cmd_status() {
    local want="${1:-}" f site reason pid loaded
    for f in "${SITES_DIR}"/*/quiet; do
        [[ -f "$f" && ! -L "$f" ]] || continue
        site="$(basename "$(dirname "$f")")"
        [[ -z "$want" || "$want" == "$site" ]] || continue
        reason="$(reason_of "$site")"
        pid="$(pid_of "$site")"
        loaded="no"
        [[ -n "$pid" ]] && table_loaded "$pid" && loaded="yes"
        echo "${site} quiet=${reason} running=$([[ -n "$pid" ]] && echo yes || echo no) table=${loaded}"
    done
}

write_if_differs() {  # FILE MODE (content on stdin)
    local file="$1" mode="$2" tmp
    tmp="$(mktemp "${file}.XXXXXX")" || return 1
    cat > "$tmp"
    if [[ -f "$file" ]] && cmp -s "$tmp" "$file"; then
        rm -f "$tmp"
        return 0
    fi
    chmod "$mode" "$tmp" && mv -f "$tmp" "$file"
}

hook_text() {
    cat <<EOF
{
  "version": "1.0.0",
  "hook": {
    "path": "${INSTALLED}",
    "args": ["joinery-site-quiet", "hook"],
    "env": ["PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"]
  },
  "when": { "always": true },
  "stages": ["createRuntime"]
}
EOF
}

cmd_install() {
    [[ "$(id -u)" == "0" ]] || refuse "install is root's"
    [[ -f "${HERE}/../install_tools/_site_engine.sh" ]] || refuse "there is no install_tools/_site_engine.sh beside this script's tree"
    mkdir -p "$(dirname "$INSTALLED")" "$INSTALLED_LIB" || return 1
    write_if_differs "$INSTALLED" 0755 < "${BASH_SOURCE[0]}" || return 1
    write_if_differs "${INSTALLED_LIB}/_site_engine.sh" 0644 < "${HERE}/../install_tools/_site_engine.sh" || return 1
    engine_ready || return 1
    if [[ "$(site_engine_kind)" == "podman" ]]; then
        mkdir -p "$(dirname "$HOOK_FILE")" || return 1
        hook_text | write_if_differs "$HOOK_FILE" 0644 || return 1
    fi
    return 0
}

case "${1:-}" in
    on)      engine_ready || exit 2; cmd_on "${2:-}" "${3:-}" ;;
    off)     engine_ready || exit 2; cmd_off "${2:-}" ;;
    assert)  cmd_assert ;;
    hook)    cmd_hook ;;
    status)  cmd_status "${2:-}" ;;
    install) cmd_install ;;
    *) echo "usage: site_quiet_host.sh on SITE copy|switchover | off SITE | assert | hook | status [SITE] | install" >&2; exit 2 ;;
esac
