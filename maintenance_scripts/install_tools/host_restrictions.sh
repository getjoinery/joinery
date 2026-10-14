#!/usr/bin/env bash
#
# host_restrictions.sh - write what this machine refuses to let its sites do
# (specs/host_restrictions.md D7).
#
# Version: 1.1 - init: the directory and a file refusing nothing, where there is no file yet. Every
#                new Docker host remaps user ids (multi_tenant_host_hardening D11), and a site on a
#                remapping host runs only where the restrictions directory is
#                (run_spec_restrictions_present), so a single-site host carries one that says
#                nothing is refused; --multi-tenant then adds its set.
# Version: 1.0
#
#   host_restrictions.sh init           Root. Makes the directory and a file that
#                                       refuses nothing, where there is no file;
#                                       a file already there is kept as it is.
#                                       install.sh docker runs it on every new host.
#   host_restrictions.sh multi-tenant   Root. Adds the multi-tenant set
#                                       (unsigned_packages=refuse) to the file,
#                                       keeping any other known restriction already
#                                       there. install.sh docker --multi-tenant runs it.
#   host_restrictions.sh show           What the file says (the PHP reader decides
#                                       what is in force; this prints the file).
#   host_restrictions.sh known          The name=value pairs this release may write.
#
# The file, /etc/joinery/host_restrictions/restrictions, is the HOST's: the
# directory is 755 and the file 644, both root's, and every site container
# mounts the directory read-only (_site_run_spec.sh). Mounting the directory
# rather than the file is what lets a rewrite here - a new file renamed over
# the old - reach running containers without a rebuild. A site reads it through
# HostRestrictions; nothing on the site side writes it.
#
# Only a pair in HOST_RESTRICTIONS_KNOWN (_host_restrictions.sh) is ever
# written, so the file never holds a name the reader does not know.

set -u
TOOLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_host_restrictions.sh
. "${TOOLS_DIR}/_host_restrictions.sh"

FILE="$(host_restrictions_file)"
DIR="$(dirname "${FILE}")"

known_pair() {  # NAME=VALUE
    local k
    for k in "${HOST_RESTRICTIONS_KNOWN[@]}"; do
        [[ "$k" == "$1" ]] && return 0
    done
    return 1
}

# Write the file holding exactly these pairs, atomically.
write_pairs() {  # NAME=VALUE...
    local pair tmp
    for pair in "$@"; do
        if ! known_pair "${pair}"; then
            echo "host_restrictions.sh: ${pair} is not a restriction this release knows; nothing was written" >&2
            return 1
        fi
    done
    mkdir -p "${DIR}" || return 1
    if [[ "${EUID}" -eq 0 ]]; then
        chown root:root "${DIR}" || return 1
    fi
    chmod 755 "${DIR}" || return 1
    tmp="$(mktemp "${DIR}/.restrictions.XXXXXX")" || return 1
    {
        echo "# joinery-managed: written by host_restrictions.sh, read by every site on this host"
        echo "# (specs/host_restrictions.md). Sites mount this directory read-only."
        if (( $# )); then printf '%s\n' "$@" | sort -u; fi
    } > "${tmp}" || { rm -f "${tmp}"; return 1; }
    chmod 644 "${tmp}" || { rm -f "${tmp}"; return 1; }
    mv -f "${tmp}" "${FILE}" || { rm -f "${tmp}"; return 1; }
}

# The known pairs the file holds now.
current_pairs() {
    local line
    [[ -f "${FILE}" ]] || return 0
    while IFS= read -r line || [[ -n "${line}" ]]; do
        line="${line//[[:space:]]/}"
        [[ -z "${line}" || "${line}" == \#* ]] && continue
        known_pair "${line}" && printf '%s\n' "${line}"
    done < "${FILE}"
}

case "${1:-}" in
    init)
        if [[ -f "${FILE}" ]]; then
            echo "host restrictions: ${FILE} kept as it is ($(current_pairs | sort -u | paste -sd' ' | sed 's/^$/nothing refused/'))"
        elif write_pairs; then
            echo "host restrictions: ${FILE} refuses nothing"
        else
            echo "host_restrictions.sh: could not write ${FILE}" >&2
            exit 1
        fi
        ;;
    multi-tenant)
        mapfile -t pairs < <(current_pairs)
        pairs+=("${HOST_RESTRICTIONS_MULTI_TENANT[@]}")
        if write_pairs "${pairs[@]}"; then
            echo "host restrictions: ${FILE} holds $(current_pairs | sort -u | paste -sd' ')"
        else
            echo "host_restrictions.sh: could not write ${FILE}" >&2
            exit 1
        fi
        ;;
    show)
        [[ -f "${FILE}" ]] && cat "${FILE}" || echo "no restrictions file at ${FILE}"
        ;;
    known)
        printf '%s\n' "${HOST_RESTRICTIONS_KNOWN[@]}"
        ;;
    *)
        echo "Usage: host_restrictions.sh init | multi-tenant | show | known" >&2
        exit 2
        ;;
esac
