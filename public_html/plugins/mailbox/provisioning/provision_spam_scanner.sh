#!/usr/bin/env bash
#
# provision_spam_scanner.sh - install, remove or inspect this box's own rspamd:
# the one program that checks arriving mail and signs outgoing mail
# (spam_learning_in_core.md § One rspamd configuration,
# mail_checking_in_rspamd.md).
#
# Version: 2.3 - Writes the override.d file that makes "local" mean this box alone, and
#                restarts rspamd when its milter's listening address changed (a reload
#                keeps the old socket open). Postfix is told rspamd is its only milter
#                only once rspamd is answering on the new configuration.
# Version: 2.2 - rspamd is the only milter. install writes the signing
#                configuration and the list of domains that have a key, and sets
#                both of Postfix's milter lists to rspamd alone (smtpd_milters for
#                arriving mail, non_smtpd_milters for what this box sends).
# Version: 2.1 - rspamd is reloaded, not restarted, when it is running (the milter on
#                11332 stays up, so Postfix never tempfails mail mid-converge), and
#                redis is purged only once rspamd is confirmed on the new configuration.
# Version: 2.0 - STATELESS: writes the one rspamd configuration in
#                rspamd_stateless.sh (shared with the relay): Bayes off, no
#                redis, no controller. install deletes redis.conf and
#                worker-controller.inc and purges redis-server; spam learning
#                lives in the application.
# Version: 1.1 - rbl.conf: DNS lists that cannot answer this box off (NiX Spam timed
#                out every lookup and stalled a scan 5-8s; SURBL/URIBL refuse shared resolvers).
# Version: 1.0 - Extracted from install_email.sh section 5b as a standalone,
#                verb-driven provisioner.
#
# WHY THIS EXISTS SEPARATELY
#   The scanner SHIPS with the mail stack: install_email.sh calls `install`
#   unconditionally, so every box that hosts its own mail has rspamd from
#   birth. This script stands alone so it can also be run directly: to repair
#   config or milter-wiring drift (install is the repair — it is idempotent).
#
# HOW AN EXISTING BOX GETS THIS VERSION
#   With no hand step. The host converger re-runs install_email.sh (the mailbox
#   plugin's host_installer) when the deployed release changes, and
#   install_email.sh calls `install` here. 0.8.450's rbl.conf reached
#   jeremytunnell that way on 2026-09-30.
#
# WHAT install DOES
#   - Installs rspamd (without its recommended redis-server).
#   - Writes the joinery-managed /etc/rspamd/local.d files from
#     rspamd_stateless.sh: the X-Spam header contract
#     InboundEmailRouter::readSpamHeader() parses, the Authentication-Results
#     line AuthenticationResults reads, add_header-only actions (NEVER reject -
#     the reviewable-verdict model), dead DNS lists off, Bayes off, the milter
#     worker on 11332, and outgoing signing for domains that have a key.
#   - Creates the key directory and writes the list of domains with a key.
#   - Deletes the files an earlier version wrote for learning (redis.conf,
#     worker-controller.inc), then stops and purges redis-server. On a
#     joinery-provisioned box redis existed only for this scanner's Bayes
#     corpus; the corpus now lives in the application's database.
#   - Makes rspamd Postfix's only milter, for arriving and outgoing mail,
#     ONLY when Postfix is present. On a relay-fronted or webhook box there is
#     no local Postfix to wire and the milter worker idles.
#   - Fully idempotent: safe to re-run any time, including on a box whose
#     redis is already gone, and re-running is the repair for drift.
#
# WHAT remove DOES
#   Operator escape hatch only — the platform never runs or surfaces it.
#   Purges rspamd, and redis-server for a box that never ran this version,
#   deletes the joinery-managed local.d files, and strips the milter entry from
#   both of Postfix's milter lists when Postfix is present. Signing keys are
#   left where they are.
#
# WHAT status DOES
#   Prints machine-readable key=value markers (packages, services, milter
#   wiring and listener) for tests and the health probe.
#
# Usage:  sudo bash provision_spam_scanner.sh install|remove|status
#
set -euo pipefail

VERB="${1:-}"
if [[ "${VERB}" != "install" && "${VERB}" != "remove" && "${VERB}" != "status" ]]; then
    echo "Usage: sudo bash $0 install|remove|status" >&2
    exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RSPAMD_LOCAL_D="${RSPAMD_LOCAL_D:-/etc/rspamd/local.d}"
RSPAMD_OVERRIDE_D="${RSPAMD_OVERRIDE_D:-$(dirname "${RSPAMD_LOCAL_D}")/override.d}"
MILTER_ENTRY="inet:localhost:11332"

# The one configuration: RSPAMD_STATELESS_FILES and rspamd_stateless_render.
# shellcheck source=rspamd_stateless.sh
source "${SCRIPT_DIR}/rspamd_stateless.sh"

# The local.d files this script owns. remove deletes exactly these and nothing
# else, so a hand-written override elsewhere in local.d survives.
MANAGED_CONFIGS=("${RSPAMD_STATELESS_FILES[@]}" "${RSPAMD_SIGNING_FILES[@]}")

# Files an earlier version wrote for the learning loop (the redis backend and
# the controller the app used to post learn requests to). install deletes them.
RETIRED_CONFIGS=(
    "redis.conf"
    "worker-controller.inc"
)

# --- helpers -----------------------------------------------------------------

need_root() {
    if [[ "${EUID}" -ne 0 ]]; then
        echo "This script must run as root (installs packages, edits /etc/rspamd)." >&2
        echo "Re-run with: sudo bash $0 ${VERB}" >&2
        exit 1
    fi
}

postfix_present() {
    command -v postconf >/dev/null 2>&1 && [[ -f /etc/postfix/main.cf ]]
}

# Installed, not merely known to dpkg: `dpkg -s` exits 0 for a removed-but-not-
# purged package whose files are gone.
pkg_installed() {
    dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q '^install ok installed$'
}

# write_if_changed <dest> - content on stdin. Returns 0 when it WROTE and 1 when
# the file already matched. USE ONLY AS AN `if` CONDITION.
write_if_changed() {
    local dest="$1"
    local tmp; tmp="$(mktemp "${dest}.joinery-XXXXXX")"
    cat > "${tmp}"
    chmod 644 "${tmp}"
    if [[ -f "${dest}" ]] && cmp -s "${tmp}" "${dest}"; then
        rm -f "${tmp:?}"
        return 1
    fi
    mv -f "${tmp}" "${dest}"
    return 0
}

# Restart a service under systemd, falling back to sysv `service`, and finally
# to a warning. In a container there is usually no init at all: the CMD restarts
# services on boot and this script re-asserts config idempotently to match
# (spec mail_stack_container_persistence).
# Returns 1 when nothing could restart it.
restart_service() {
    local svc="$1"
    systemctl enable "${svc}" >/dev/null 2>&1 || true
    if command -v systemctl >/dev/null 2>&1 && systemctl restart "${svc}" 2>/dev/null; then
        echo "${svc}: restarted (systemd)."
    elif command -v service >/dev/null 2>&1 && service "${svc}" restart >/dev/null 2>&1; then
        echo "${svc}: restarted (service)."
    else
        echo "WARNING: could not restart ${svc} automatically - start it manually." >&2
        return 1
    fi
}

# rspamd re-reads its configuration on reload, so a running one picks up new
# local.d files with its milter still listening. Returns 1 when it could be
# neither reloaded nor started.
reload_or_start_rspamd() {
    if service_running rspamd; then
        if command -v systemctl >/dev/null 2>&1 && systemctl reload rspamd 2>/dev/null; then
            echo "rspamd: reloaded (systemd)."
            return 0
        elif command -v service >/dev/null 2>&1 && service rspamd reload >/dev/null 2>&1; then
            echo "rspamd: reloaded (service)."
            return 0
        fi
    fi
    restart_service rspamd
}

service_running() {
    local svc="$1"
    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "${svc}" 2>/dev/null; then
        return 0
    fi
    pgrep -x "${svc}" >/dev/null 2>&1
}

# Stop redis and purge its package. Only ever redis-server: no autoremove,
# which could take packages this script never installed.
purge_redis() {
    if ! pkg_installed redis-server; then
        echo "redis-server: not installed - nothing to remove."
        return 0
    fi
    systemctl disable redis-server >/dev/null 2>&1 || true
    systemctl stop redis-server >/dev/null 2>&1 || service redis-server stop >/dev/null 2>&1 || true
    export DEBIAN_FRONTEND=noninteractive
    if apt-get purge -y redis-server >/dev/null 2>&1; then
        echo "redis-server: purged (spam learning lives in the application's database)."
    else
        echo "WARNING: could not purge redis-server - remove it by hand (apt-get purge redis-server)." >&2
    fi
}

# --- install -----------------------------------------------------------------

do_install() {
    need_root
    if ! command -v apt-get >/dev/null 2>&1; then
        echo "This installer supports apt-based systems (Debian/Ubuntu) only." >&2
        exit 1
    fi

    echo "spam-scanner: installing rspamd (stateless)"

    if pkg_installed rspamd; then
        echo "Already installed: rspamd"
    else
        echo "Installing: rspamd"
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        # --no-install-recommends: rspamd RECOMMENDS redis-server, which a
        # stateless scanner has no use for.
        apt-get install -y --no-install-recommends rspamd
    fi

    mkdir -p "${RSPAMD_LOCAL_D}" "${RSPAMD_OVERRIDE_D}"

    # rebind: the milter's listening address changed. rspamd opens the new
    # socket on a reload but keeps the old one, so that change needs a restart.
    local changed=0 rebind=0 f
    for f in "${MANAGED_CONFIGS[@]}"; do
        if rspamd_stateless_render "${f}" | write_if_changed "${RSPAMD_LOCAL_D}/${f}"; then
            echo "wrote ${RSPAMD_LOCAL_D}/${f}"
            changed=1
            [[ "${f}" == "worker-proxy.inc" ]] && rebind=1
        fi
    done
    for f in "${RSPAMD_STATELESS_OVERRIDE_FILES[@]}"; do
        if rspamd_stateless_render "${f}" | write_if_changed "${RSPAMD_OVERRIDE_D}/${f}"; then
            echo "wrote ${RSPAMD_OVERRIDE_D}/${f}"
            changed=1
        fi
    done
    for f in "${RETIRED_CONFIGS[@]}"; do
        if [[ -f "${RSPAMD_LOCAL_D}/${f}" ]]; then
            rm -f "${RSPAMD_LOCAL_D:?}/${f:?}"
            echo "removed ${RSPAMD_LOCAL_D}/${f} (the learning loop moved into the application)"
            changed=1
        fi
    done
    if [[ "${changed}" -eq 0 ]]; then
        echo "spam-scanner: the ${#MANAGED_CONFIGS[@]} joinery-managed config files are current"
    fi

    # The signing keys (copied here from opendkim on a box that had it), who may
    # read them, and which domains have one. Written before rspamd is reloaded
    # so the signing configuration never names a missing map.
    rspamd_dkim_adopt_old_keys
    rspamd_dkim_secure_keys
    if rspamd_dkim_render_map | write_if_changed "${RSPAMD_DKIM_MAP}"; then
        echo "wrote ${RSPAMD_DKIM_MAP} ($(wc -l < "${RSPAMD_DKIM_MAP}") domain(s) sign)"
    fi

    # rspamd first, so it is running on the new configuration (and no longer
    # reaching for redis) before redis goes, and before Postfix is told it is
    # the only milter. If it could not be reloaded or started (no init in a
    # container), redis stays until a run that can: an rspamd still holding the
    # old configuration would lose its backend.
    local rspamd_current=1
    # A configuration rspamd cannot load must not be handed to it: a reload
    # would leave it running on the old one, looking healthy.
    if command -v rspamadm >/dev/null 2>&1 && ! rspamadm configtest >/dev/null 2>&1; then
        echo "ERROR: rspamd does not accept the configuration just written (rspamadm configtest) - rspamd left as it is." >&2
        rspamd_current=0
    elif [[ "${rebind}" -eq 1 ]]; then
        restart_service rspamd || rspamd_current=0
    elif [[ "${changed}" -eq 1 ]] || ! service_running rspamd; then
        reload_or_start_rspamd || rspamd_current=0
    else
        echo "rspamd: configuration unchanged and running - left alone."
    fi
    if [[ "${rspamd_current}" -eq 1 ]]; then
        purge_redis
    else
        echo "spam-scanner: rspamd is not on the new configuration yet - redis-server left in place; re-run install once rspamd can start." >&2
    fi

    # rspamd is Postfix's only milter: it checks what arrives (smtpd_milters)
    # and signs what this box sends (non_smtpd_milters). Both lists are set
    # whole, so an entry for a program that is no longer here cannot survive.
    # Only meaningful where Postfix actually receives mail, and only once
    # rspamd is answering on the configuration just written: until then Postfix
    # keeps whatever it had, so nothing that still works is taken away.
    if ! postfix_present; then
        echo "spam-scanner: no local Postfix - the milter worker idles."
    elif [[ "${rspamd_current}" -ne 1 ]] || ! rspamd_milter_answers; then
        echo "WARNING: rspamd is not answering on the new configuration - Postfix's milter lists are left as they are; re-run install once rspamd is up." >&2
    else
        local list wired=0
        for list in smtpd_milters non_smtpd_milters; do
            if [[ "$(postconf -h "${list}" 2>/dev/null || true)" != "${MILTER_ENTRY}" ]]; then
                postconf -e "${list} = ${MILTER_ENTRY}"
                echo "main.cf: ${list} = ${MILTER_ENTRY}"
                wired=1
            fi
        done
        if [[ "${wired}" -eq 0 ]]; then
            echo "main.cf: rspamd is already the only milter (${MILTER_ENTRY})"
        elif command -v systemctl >/dev/null 2>&1 && systemctl reload postfix 2>/dev/null; then
            echo "postfix: reloaded (systemd)."
        elif command -v postfix >/dev/null 2>&1; then
            postfix reload >/dev/null 2>&1 || true
            echo "postfix: reloaded."
        fi
    fi

    echo "spam-scanner: rspamd milter on 11332, stateless (no Bayes, no redis, no controller); it signs for the domains in ${RSPAMD_DKIM_MAP}."
    echo "  NOTE: rspamd queries DNS RBLs while scanning - ensure outbound DNS egress or scoring degrades."
}

# --- remove ------------------------------------------------------------------

do_remove() {
    need_root

    # Unwire first, so Postfix never points at a milter that is going away.
    if postfix_present; then
        local list current stripped unwired=0
        for list in smtpd_milters non_smtpd_milters; do
            current="$(postconf -h "${list}" 2>/dev/null || true)"
            [[ "${current}" == *"${MILTER_ENTRY}"* ]] || continue
            # Drop our entry and tidy the separators left behind.
            stripped="$(echo "${current}" \
                | sed "s#${MILTER_ENTRY}##g" \
                | sed 's/,[[:space:]]*,/,/g' \
                | sed 's/^[[:space:]]*,[[:space:]]*//' \
                | sed 's/[[:space:]]*,[[:space:]]*$//' \
                | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
            postconf -e "${list} = ${stripped}"
            echo "main.cf: rspamd milter removed (${list} = ${stripped:-<empty>})"
            unwired=1
        done
        if [[ "${unwired}" -eq 0 ]]; then
            echo "main.cf: rspamd milter not wired - nothing to strip."
        elif command -v systemctl >/dev/null 2>&1 && systemctl reload postfix 2>/dev/null; then
            echo "postfix: reloaded (systemd)."
        elif command -v postfix >/dev/null 2>&1; then
            postfix reload >/dev/null 2>&1 || true
            echo "postfix: reloaded."
        fi
    fi

    local f
    for f in "${MANAGED_CONFIGS[@]}" "${RETIRED_CONFIGS[@]}"; do
        if [[ -f "${RSPAMD_LOCAL_D}/${f}" ]]; then
            rm -f "${RSPAMD_LOCAL_D:?}/${f:?}"
            echo "removed ${RSPAMD_LOCAL_D}/${f}"
        fi
    done
    for f in "${RSPAMD_STATELESS_OVERRIDE_FILES[@]}"; do
        if [[ -f "${RSPAMD_OVERRIDE_D}/${f}" ]]; then
            rm -f "${RSPAMD_OVERRIDE_D:?}/${f:?}"
            echo "removed ${RSPAMD_OVERRIDE_D}/${f}"
        fi
    done

    # Stop before purge so a systemd unit does not fight the package removal.
    # redis-server is included for a box that never ran install 2.0.
    local svc
    for svc in rspamd redis-server; do
        systemctl disable "${svc}" >/dev/null 2>&1 || true
        systemctl stop "${svc}" >/dev/null 2>&1 || service "${svc}" stop >/dev/null 2>&1 || true
    done

    if command -v apt-get >/dev/null 2>&1; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get purge -y rspamd redis-server >/dev/null 2>&1 || true
        apt-get autoremove -y >/dev/null 2>&1 || true
        echo "spam-scanner: rspamd (and any redis-server) purged."
    else
        echo "WARNING: no apt-get - stop and uninstall rspamd/redis with your package manager." >&2
    fi
}

# --- status ------------------------------------------------------------------

do_status() {
    local pkg
    for pkg in rspamd redis-server; do
        if pkg_installed "${pkg}"; then
            echo "package_${pkg//-/_}=installed"
        else
            echo "package_${pkg//-/_}=absent"
        fi
    done

    if service_running rspamd; then
        echo "service_rspamd=running"
    else
        echo "service_rspamd=inactive"
    fi

    local managed=0 retired=0 f
    for f in "${MANAGED_CONFIGS[@]}"; do
        [[ -f "${RSPAMD_LOCAL_D}/${f}" ]] && managed=$((managed + 1))
    done
    for f in "${RETIRED_CONFIGS[@]}"; do
        [[ -f "${RSPAMD_LOCAL_D}/${f}" ]] && retired=$((retired + 1))
    done
    echo "managed_configs=${managed}/${#MANAGED_CONFIGS[@]}"
    echo "retired_configs=${retired}"

    if postfix_present; then
        if postconf -h smtpd_milters 2>/dev/null | grep -q "${MILTER_ENTRY}"; then
            echo "milter_wired=yes"
        else
            echo "milter_wired=no"
        fi
        if postconf -h non_smtpd_milters 2>/dev/null | grep -q "${MILTER_ENTRY}"; then
            echo "signing_wired=yes"
        else
            echo "signing_wired=no"
        fi
    else
        echo "milter_wired=n/a"
        echo "signing_wired=n/a"
    fi
    if [[ -f "${RSPAMD_DKIM_MAP}" ]]; then
        echo "signing_domains=$(wc -l < "${RSPAMD_DKIM_MAP}")"
    else
        echo "signing_domains=0"
    fi

    # The milter is the scanner's only interface: Postfix hands it each message
    # and it stamps headers the app reads.
    if (echo >/dev/tcp/127.0.0.1/11332) >/dev/null 2>&1; then
        echo "milter=listening"
    else
        echo "milter=unreachable"
    fi
}

case "${VERB}" in
    install) do_install ;;
    remove)  do_remove ;;
    status)  do_status ;;
esac
