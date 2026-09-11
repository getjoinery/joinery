#!/usr/bin/env bash
#
# _tree_trust.sh - who owns this tree, and is this file one root should run?
#
# Version: 1.0
#
# Two questions, asked from two places, which is why they live in a file of
# their own rather than inside either caller (specs/read_only_tree.md):
#
#   * the runner (_plugin_installers_start.sh) asks before it executes any
#     installer as root, and before it refreshes the converger's entry point;
#   * the converger's installer (install_host_converger.sh) asks before it
#     copies the tree's runner over /usr/local/sbin/joinery-host-converger.
#
# Both answers have to be the same answer. Two copies of this logic would drift,
# and the copy that drifted looser would be the one that mattered.
#
# Sourced, never executed:  . "${TOOLS_DIR}/_tree_trust.sh"

# Who owns the executable set, per {site}/config/tree_owner.
#
# The record is written by fix_permissions.sh, root:root 0644, and by nothing
# else - never by anything on the web side. It exists because the one moment the
# answer matters, public_html owned by www-data, is the moment the tree itself
# cannot give it.
#
# Everything that is not a record root could have written is root. An unwritten
# record on a node is root; a record we cannot attribute is also root. Root is
# the safe answer because it is the strictest one - it re-owns a tree away from
# the pool. The unsafe answer would be to believe a record naming www-data.
#
# Returns 0 when the record was trustworthy and its name is what was echoed, 1
# when it was not and "root" was echoed as the safe default. A caller that only
# wants an owner ignores the code; a caller that has to tell "no record" from
# "the record says root" - fix_permissions.sh, deciding whether --dev or
# --production gets to choose - reads it.
#
# Usage:  owner="$(joinery_tree_owner_record "${SITE_ROOT}")"
joinery_tree_owner_record() {
    local site_root="$1"
    local file="${site_root}/config/tree_owner"
    local info owner mode norm name

    [[ -f "${file}" ]] || { echo "root"; return 1; }

    info="$(stat -c '%U %a' "${file}" 2>/dev/null || true)"
    [[ -n "${info}" ]] || { echo "root"; return 1; }
    owner="${info%% *}"
    mode="${info##* }"
    norm="0000${mode}"
    norm="${norm: -4}"

    name="$(head -n1 "${file}" 2>/dev/null | tr -d '[:space:]')"

    # Guard 1: root wrote it. fix_permissions.sh writes this file root:root and
    # nothing else writes it at all, so root is the only owner it can honestly
    # have - and every shape a forged one could take is one condition away.
    if [[ "${owner}" != "root" ]] \
       || (( ${norm:2:1} & 2 )) || (( ${norm:3:1} & 2 )); then
        echo "tree owner: ignoring ${file} (owned by ${owner} mode ${mode}); using root" >&2
        echo "root"; return 1
    fi

    # Guard 2: a real account, and never the web user. A record naming www-data
    # would make the ownership assertion a no-op on a tree the pool owns, which
    # is the whole thing it is there to stop.
    if [[ -z "${name}" ]] || [[ "${name}" == "www-data" ]] || ! id "${name}" >/dev/null 2>&1; then
        echo "tree owner: ignoring ${file} (names ${name:-<empty>}); using root" >&2
        echo "root"; return 1
    fi

    echo "${name}"
}

# Is this a file root is willing to run?
#
# Every installer the runner executes runs AS ROOT, and they live in
# maintenance_scripts/ and plugins/. Without this, a process running as the web
# user could put ten lines in one of them and be root at the next tick.
#
# Trusted means: owned by the tree owner, and writable by nobody else - no group
# write bit, no other write bit. Refusing is loud, because a box whose ownership
# has drifted should report it rather than quietly run whatever it finds.
#
# Usage:  joinery_file_is_trusted "${path}" "${owner}" || return 0
joinery_file_is_trusted() {
    local path="$1" owner_expected="$2" info owner mode norm
    info="$(stat -c '%U %a' "${path}" 2>/dev/null || true)"
    if [[ -z "${info}" ]]; then
        echo "installer refused: ${path} owned by ? mode ?" >&2
        return 1
    fi
    owner="${info%% *}"
    mode="${info##* }"
    norm="0000${mode}"
    norm="${norm: -4}"
    if [[ "${owner}" != "${owner_expected}" ]] \
       || (( ${norm:2:1} & 2 )) || (( ${norm:3:1} & 2 )); then
        echo "installer refused: ${path} owned by ${owner} mode ${mode}" >&2
        return 1
    fi
    return 0
}
