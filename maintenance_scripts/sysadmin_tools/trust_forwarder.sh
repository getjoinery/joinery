#!/usr/bin/env bash
#
# trust_forwarder.sh - believe X-Forwarded-For from one address, for a while
# (specs/site_copy_anything_to_anything.md D1). The agent's trust_forwarder
# word runs this file.
#
# Version: 1.1 - expire: drop every address past its end; housekeeping runs it on every tick
# Version: 1.0
#
# While a site's address moves by DNS, its old server forwards every visitor
# whose resolver still names it to the copy, and Apache there adds
# X-Forwarded-For. This machine (the copy's server, or the host whose Apache
# fronts a copy in a container) believes that header from the old server's
# address alone, so the site's own per-address checks see the visitor, not the
# old server.
#
#   trust_forwarder.sh trust ADDRESS HOURS   believe ADDRESS for HOURS (1-168)
#   trust_forwarder.sh untrust ADDRESS       stop believing it
#   trust_forwarder.sh expire                drop every address past its end (housekeeping
#                                            runs this on every converge tick)
#   trust_forwarder.sh show                  each address believed, with when it ends
#
# The record is /etc/joinery/forwarders (one line: ADDRESS END_EPOCH); the
# Apache file /etc/apache2/conf-available/joinery-forwarders.conf is rendered
# from it on every run, with any address past its end left out, so a trust
# nobody took back ends anyway the next time this runs. An address that
# returns to its provider's pool when its server is deleted must never be
# believed after the move: whoever holds it next could name any visitor.
#
# Exit: 0 done; 1 Apache did not take it (said why); 2 refused, nothing changed.

set -u
export LC_ALL=C

ETC="/etc"
if [[ "$(id -u)" != "0" ]]; then
    [[ -n "${TRUST_FORWARDER_ETC:-}" ]] || { echo "trust_forwarder: only root changes what Apache believes - refused" >&2; exit 2; }
    ETC="${TRUST_FORWARDER_ETC%/}"
fi
RECORD="${ETC}/joinery/forwarders"
CONF_AVAILABLE="${ETC}/apache2/conf-available/joinery-forwarders.conf"
CONF_ENABLED="${ETC}/apache2/conf-enabled/joinery-forwarders.conf"
NOW="${TRUST_FORWARDER_NOW:-$(date +%s)}"

refuse() { echo "trust_forwarder: $1 - refused" >&2; exit 2; }

addr_ok() {
    local a="$1" o
    if [[ "$a" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]]; then
        for o in "${BASH_REMATCH[@]:1}"; do (( 10#$o <= 255 )) || return 1; done
        return 0
    fi
    [[ "$a" =~ ^[0-9a-fA-F:]{2,39}$ && "$a" == *:* ]]
}

# The record's live lines: well formed and not past their end.
live_lines() {
    [[ -f "${RECORD}" && ! -L "${RECORD}" ]] || return 0
    local a end
    while read -r a end _; do
        addr_ok "${a:-}" || continue
        [[ "${end:-}" =~ ^[0-9]{1,12}$ ]] || continue
        (( end > NOW )) || continue
        echo "${a} ${end}"
    done < "${RECORD}"
}

render_conf() {
    echo "# Written by trust_forwarder.sh (Joinery) from ${RECORD} on every run - edits here do not survive."
    echo "#"
    echo "# Each address here is a site's old server, forwarding visitors to this one while"
    echo "# the site's DNS moves; X-Forwarded-For is believed from it, and from nothing else"
    echo "# this file names, until its end."
    echo "<IfModule remoteip_module>"
    local a end
    while read -r a end; do
        [[ -n "${a}" ]] || continue
        echo "# until $(date -u -d "@${end}" '+%Y-%m-%d %H:%M UTC' 2>/dev/null || echo "${end}")"
        echo "RemoteIPTrustedProxy ${a}"
    done
    echo "</IfModule>"
}

apply() {
    local lines tmp changed=0
    lines="$(live_lines)"
    mkdir -p "$(dirname "${RECORD}")" "$(dirname "${CONF_AVAILABLE}")" "$(dirname "${CONF_ENABLED}")" || return 1
    tmp="$(mktemp "${RECORD}.XXXXXX")" || return 1
    [[ -n "${lines}" ]] && printf '%s\n' "${lines}" > "${tmp}"
    chmod 644 "${tmp}" && mv -f "${tmp}" "${RECORD}" || return 1
    if [[ -z "${lines}" ]]; then
        if [[ -e "${CONF_AVAILABLE}" || -L "${CONF_ENABLED}" || -e "${CONF_ENABLED}" ]]; then
            rm -f "${CONF_ENABLED}" "${CONF_AVAILABLE}"
            changed=1
        fi
    else
        tmp="$(mktemp "${CONF_AVAILABLE}.XXXXXX")" || return 1
        printf '%s\n' "${lines}" | render_conf > "${tmp}"
        if [[ -f "${CONF_AVAILABLE}" ]] && cmp -s "${tmp}" "${CONF_AVAILABLE}"; then
            rm -f "${tmp}"
        else
            chmod 644 "${tmp}" && mv -f "${tmp}" "${CONF_AVAILABLE}" || return 1
            changed=1
        fi
        if [[ ! -L "${CONF_ENABLED}" ]]; then
            rm -f "${CONF_ENABLED}"
            ln -s "../conf-available/joinery-forwarders.conf" "${CONF_ENABLED}" || return 1
            changed=1
        fi
    fi
    [[ "${changed}" == "1" && -z "${TRUST_FORWARDER_ETC:-}" ]] || return 0
    if ! apache2ctl configtest >/dev/null 2>&1; then
        echo "trust_forwarder: apache2ctl configtest fails - Apache not reloaded: $(apache2ctl configtest 2>&1 | tail -1)" >&2
        return 1
    fi
    systemctl reload apache2 2>/dev/null || { echo "trust_forwarder: Apache did not reload" >&2; return 1; }
}

case "${1:-}" in
    trust)
        addr="${2:-}"; hours="${3:-}"
        addr_ok "${addr}" || refuse "'${addr:0:64}' is not an address"
        [[ "${hours}" =~ ^[0-9]{1,3}$ ]] && (( hours >= 1 && hours <= 168 )) || refuse "hours is 1 to 168"
        others="$(live_lines | awk -v a="${addr}" '$1 != a')"
        mkdir -p "$(dirname "${RECORD}")" || exit 1
        { [[ -n "${others}" ]] && printf '%s\n' "${others}"; echo "${addr} $((NOW + hours * 3600))"; } > "${RECORD}.new" \
            && mv -f "${RECORD}.new" "${RECORD}" || { echo "trust_forwarder: cannot write ${RECORD}" >&2; exit 1; }
        apply || exit 1
        echo "trust_forwarder: X-Forwarded-For from ${addr} is believed for ${hours} hour(s)"
        ;;
    untrust)
        addr="${2:-}"
        addr_ok "${addr}" || refuse "'${addr:0:64}' is not an address"
        others="$(live_lines | awk -v a="${addr}" '$1 != a')"
        mkdir -p "$(dirname "${RECORD}")" || exit 1
        { [[ -n "${others}" ]] && printf '%s\n' "${others}"; true; } > "${RECORD}.new" \
            && mv -f "${RECORD}.new" "${RECORD}" || { echo "trust_forwarder: cannot write ${RECORD}" >&2; exit 1; }
        apply || exit 1
        echo "trust_forwarder: X-Forwarded-For from ${addr} is not believed"
        ;;
    expire)
        apply || exit 1
        ;;
    show)
        live_lines | while read -r a end; do
            echo "${a} until $(date -u -d "@${end}" '+%Y-%m-%d %H:%M UTC' 2>/dev/null || echo "${end}")"
        done
        ;;
    *) echo "usage: trust_forwarder.sh trust ADDRESS HOURS | untrust ADDRESS | expire | show" >&2; exit 2 ;;
esac
exit 0
