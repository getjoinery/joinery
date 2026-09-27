# host_runner_lock.sh - sourced, not run. Holds a site's host runner lock for the
# life of the calling script.
# Version: 1.2 - root's lock file is tightened to 0600 when found wider, as the converger's is
# Version: 1.1 - A third argument ends the refusal in the caller's own words: site_quiet.sh holds it too
# Version: 1.0 - restore_chain.sh and restore_project.sh hold the lock the host converger takes
#
# A restore writes the whole site tree, and the host converger
# (_plugin_installers_start.sh) runs the plugin installers and
# site_housekeeping.sh against that tree whenever the release changes - every
# minute, on a timer. The two must never overlap: an installer run against a
# half-restored tree acts on a mixture of two releases. So a restore takes the
# converger's own lock, the same file by the same rule, and holds it until it
# exits. One lock, two kinds of holder; neither knows anything else about the
# other.
#
# The file is the converger's (_plugin_installers_start.sh, "One runner at a
# time"): root's, in /run/joinery, when this is root; the site's cache when it
# is not - an unprivileged run changes nothing a root converger converges.
# Keep the two rules identical.
#
#   hold_host_runner_lock SITENAME PROJECT_DIR [NOTHING_DONE]   -> 0 held, 1 not held (says why)
#
# NOTHING_DONE is the sentence that ends a refusal ("Nothing was restored."
# unless the caller says otherwise).
#
# Descriptor 9, as the converger uses: children inherit it, so the lock is held
# while any part of the restore is alive and can never go stale - a kernel
# flock dies with its holders. JOINERY_LOCK_WAIT_SECONDS shortens the ten-minute
# wait for a test, and only when this is not root.

hold_host_runner_lock() {
    local site="$1" project="$2" nothing_done="${3:-Nothing was restored.}" lock_file wait=600
    if [ "$(id -u)" = "0" ]; then
        mkdir -p /run/joinery 2>/dev/null && chmod 755 /run/joinery 2>/dev/null
        lock_file="/run/joinery/host-installers.${site}.lock"
    else
        # No tree yet, no converger converging it: nothing to hold off.
        [ -d "$project" ] || return 0
        mkdir -p "${project}/cache" 2>/dev/null || true
        lock_file="${project}/cache/host_installers.lock"
        if [ -e "/run/joinery/host-installers.${site}.lock" ]; then
            echo "WARNING: the host converger runs as root on this machine, and this run is not root," >&2
            echo "WARNING: so it cannot hold the converger off. Run it as root." >&2
        fi
        if [[ "${JOINERY_LOCK_WAIT_SECONDS:-}" =~ ^[0-9]+$ ]]; then
            wait="${JOINERY_LOCK_WAIT_SECONDS}"
        fi
    fi
    # Created 0600 when absent: a lock another account can open is a lock it can
    # hold, and the run it would block is the one that takes the tree back.
    [ -e "$lock_file" ] || ( umask 077; : >> "$lock_file" ) 2>/dev/null
    # And tightened when found wider, as the converger does for root's file.
    [ "$(id -u)" = "0" ] && chmod 600 "$lock_file" 2>/dev/null
    if ! exec 9>>"$lock_file"; then
        echo "ERROR: cannot open the host runner lock ${lock_file}; refusing to run unlocked. ${nothing_done}" >&2
        return 1
    fi
    if ! flock -w "$wait" 9; then
        local holder="holder unknown" _pid _since
        { read -r _pid; read -r _since; } < "$lock_file" 2>/dev/null || true
        if [[ "${_pid:-}" =~ ^[0-9]+$ && "${_since:-}" =~ ^[0-9]+$ ]]; then
            holder="pid ${_pid} since $(date -u -d "@${_since}" '+%Y-%m-%d %H:%M:%S UTC' 2>/dev/null || echo "${_since}")"
        fi
        echo "ERROR: the host runner lock is held (${holder}); waited ${wait}s. ${nothing_done}" >&2
        exec 9>&-
        return 1
    fi
    # The holder's record, in place, as the converger writes it: never via a
    # temp file and mv, which would leave the flock on an inode nobody opens.
    printf '%s\n%s\n' "$$" "$(date -u +%s)" > "$lock_file" 2>/dev/null || true
    return 0
}
