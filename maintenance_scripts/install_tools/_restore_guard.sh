#!/usr/bin/env bash
#
# _restore_guard.sh - what a restore never takes from an archive, and what a
# host that refuses unsigned code refuses to let one bring in at all
# (specs/host_restrictions.md D5). Sourced by restore_project.sh and
# restore_chain.sh, which is what a site copy runs.
#
# Version: 1.2 - config/release_trust is the machine's (specs/release_key_rotation.md R1)
# Version: 1.1 - the refusal names the site the backup was taken of, which is this
#                site itself for a restore of its own backup, not only a copy's source
# Version: 1.0
#
# MACHINE_CONFIG_FILES are the config/ files that belong to the MACHINE, not to
# the site a backup was taken of: this machine's database password and secret
# key, its identity as a recipient of its own backups, and the files that
# decide what it trusts (whose signatures count, whether the public log is
# required, who owns the code tree, whether it is the publishing box) or that
# its host writes. A restore keeps this machine's copy of each whenever it has
# one; each script says what it does when it has none. On a host that refuses
# unsigned code the archive's copy of a trust file is never used: the host
# converger writes what belongs there.
#
# On such a host a restore also refuses, before anything is written or loaded:
#   - any top-level entry an archive of a site has no reason to carry
#     (RESTORE_ALLOWED_TOP): a vendor/, a deploy/, a public_html_last holds code
#     no signature covers;
#   - any code that is not exactly what we shipped: the staged copy's core,
#     every plugin and every theme, checked by the MACHINE's own verifier
#     (utils/verify_package.php --tree from the live tree, with the live tree's
#     keys) - never by anything inside the archive.
#
# Sourced, never executed:  . "${TOOLS_DIR}/_restore_guard.sh"

MACHINE_CONFIG_FILES=(
    config/Globalvars_site.php
    config/backup_site_key
    config/release_verify_keys
    config/release_statement_keys
    config/release_trust
    config/transparency_log_keys
    config/release_log_required
    config/release_log_optional
    config/tree_owner
    config/agent_signing_key
    config/release_statement_key
    config/disk_allowance
    config/code_check
)

# The two that hold this machine's database password and secret key, or its
# backup identity: never the archive's when this machine has its own, whatever
# the host. Each script handles the case where it has none.
MACHINE_CONFIG_IDENTITY=(config/Globalvars_site.php config/backup_site_key)

RESTORE_ALLOWED_TOP=(public_html maintenance_scripts config uploads static_files storage RELEASE_MANIFEST RELEASE_MANIFEST.sig)

# An upgrade's own trees at the site root: its rollback and failed trees, its
# staging and downloads, and a container's deploy volume that holds them. They
# are the machine's, on every host: no restore takes them (backup_project.sh
# and the chain's data part leave them out; an older archive may still carry
# them).
RESTORE_UPGRADE_TOP=(deploy upgrades upgrade_archives 'public_html_*')
RESTORE_CODE_TOP=(public_html maintenance_scripts RELEASE_MANIFEST RELEASE_MANIFEST.sig)

_restore_guard_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_host_restrictions.sh
. "${_restore_guard_dir}/_host_restrictions.sh"

# Remove an upgrade's own trees from a staged site root, saying which. Exit 1
# if one could not be removed.
restore_guard_drop_upgrade_trees() {  # STAGED_SITE_ROOT
    local staged="${1%/}" pat entry rc=0
    for pat in "${RESTORE_UPGRADE_TOP[@]}"; do
        for entry in "$staged"/$pat; do
            [[ -e "$entry" || -L "$entry" ]] || continue
            if rm -rf "${entry:?}"; then
                echo "restore: not restoring $(basename "$entry"): an upgrade's own tree belongs to the machine" >&2
            else
                rc=1
            fi
        done
    done
    return "$rc"
}

# Is this a machine file only the machine may supply (a trust file, as opposed
# to the identity pair)? Exit 0 for yes.
restore_guard_trust_file() {  # REL
    local f
    for f in "${MACHINE_CONFIG_IDENTITY[@]}"; do [[ "$1" == "$f" ]] && return 1; done
    for f in "${MACHINE_CONFIG_FILES[@]}"; do [[ "$1" == "$f" ]] && return 0; done
    return 1
}

# Does this host refuse unsigned code? Asked of the live tree's reader.
restore_guard_restricted() {  # LIVE_PUBLIC_HTML
    host_restriction_on "$1" unsigned_packages
}

# The checks, against a staged site root, on a restricted host. Prints each
# reason on stderr and returns 1 when the restore must not go ahead. Returns 0
# at once on a host that does not refuse unsigned code.
restore_guard_check() {  # STAGED_SITE_ROOT LIVE_PUBLIC_HTML
    local staged="${1%/}" live="${2%/}" entry name ok has_code=false refused=false out rc
    restore_guard_restricted "$live" || return 0
    [[ -d "$staged" ]] || { echo "restore: nothing staged at ${staged} to check" >&2; return 1; }

    while IFS= read -r -d '' entry; do
        name="$(basename "$entry")"
        ok=false
        for a in "${RESTORE_ALLOWED_TOP[@]}"; do [[ "$name" == "$a" ]] && ok=true; done
        if [[ "$ok" == false ]]; then
            echo "restore: this host runs only signed code, and the archive carries ${name} at the site root, which no signature covers" >&2
            refused=true
        fi
        for a in "${RESTORE_CODE_TOP[@]}"; do [[ "$name" == "$a" ]] && has_code=true; done
    done < <(find "$staged" -mindepth 1 -maxdepth 1 -print0)

    if [[ "$has_code" == true ]]; then
        if [[ ! -f "${live}/utils/verify_package.php" ]] || ! command -v php > /dev/null 2>&1; then
            echo "restore: this host runs only signed code, and there is no verifier at ${live}/utils/verify_package.php to check the archive's code with" >&2
            return 1
        fi
        out="$(php "${live}/utils/verify_package.php" --tree "$staged" 2>&1)"
        rc=$?
        if [[ "$rc" -ne 0 ]]; then
            echo "restore: this host runs only signed code, and the archive's code is not what was shipped:" >&2
            printf '%s\n' "$out" | grep -v '^style theme: ' | sed 's/^/  /' >&2
            refused=true
        fi
    fi

    if [[ "$refused" == true ]]; then
        echo "restore: nothing was restored. Remove what is named above from the site the backup was taken of (or upgrade it to a current release), take a new backup, and restore that." >&2
        return 1
    fi
    return 0
}
