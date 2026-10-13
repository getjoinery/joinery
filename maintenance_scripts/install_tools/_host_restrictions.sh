#!/usr/bin/env bash
#
# _host_restrictions.sh - the machine's restrictions, for root's shell scripts
# (specs/host_restrictions.md).
#
# Version: 1.0
#
# The file is /etc/joinery/host_restrictions/restrictions, one name=value per
# line, written by host_restrictions.sh and by nothing else. On a Docker host
# the directory is mounted read-only into every site container at the same path.
#
# A script asks one question, host_restriction_on, and the answer comes from
# the PHP reader (utils/host_restrictions.php over HostRestrictions) so the
# shell and the PHP side cannot read the file two ways. Only the cheap case is
# answered here: no file and no directory means nothing is restricted. Anything
# else the PHP answers, and anything but its "off" (exit 1) counts as on: a
# missing or broken reader fails closed.
#
# HOST_RESTRICTIONS_KNOWN is the shell copy of HostRestrictions::KNOWN, as the
# name=value pairs host_restrictions.sh may write. A test compares the two.
#
# A test points the file elsewhere with JOINERY_HOST_RESTRICTIONS_FILE; only an
# unprivileged run may, the same rule as the PHP reader.
#
# Sourced, never executed:  . "${TOOLS_DIR}/_host_restrictions.sh"

HOST_RESTRICTIONS_PATH="/etc/joinery/host_restrictions/restrictions"
HOST_RESTRICTIONS_KNOWN=("unsigned_packages=refuse")
HOST_RESTRICTIONS_MULTI_TENANT=("unsigned_packages=refuse")

# The file this process reads.
host_restrictions_file() {
    if [[ "${EUID}" -ne 0 && -n "${JOINERY_HOST_RESTRICTIONS_FILE:-}" ]]; then
        printf '%s\n' "${JOINERY_HOST_RESTRICTIONS_FILE}"
    else
        printf '%s\n' "${HOST_RESTRICTIONS_PATH}"
    fi
}

# Is restriction NAME on? Exit 0 when on, 1 when off. PUBLIC_HTML is the tree
# whose utils/host_restrictions.php answers: the machine's own live tree, never
# one being restored.
host_restriction_on() {  # PUBLIC_HTML NAME
    local public_html="$1" name="$2" file rc
    file="$(host_restrictions_file)"
    if [[ ! -e "${file}" && ! -L "${file}" && ! -d "$(dirname "${file}")" ]]; then
        return 1
    fi
    if [[ ! -f "${public_html}/utils/host_restrictions.php" ]] || ! command -v php > /dev/null 2>&1; then
        echo "host restrictions: no reader at ${public_html}/utils/host_restrictions.php; every restriction counts as on" >&2
        return 0
    fi
    php "${public_html}/utils/host_restrictions.php" has "${name}"
    rc=$?
    [[ "${rc}" -ne 1 ]]
}
