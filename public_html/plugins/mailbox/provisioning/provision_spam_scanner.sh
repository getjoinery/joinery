#!/usr/bin/env bash
#
# provision_spam_scanner.sh - install, remove or inspect this box's own spam
# scanner (specs/mailbox_spam_filtering_simplification.md D6).
#
# Version: 1.0 - Extracted from install_email.sh section 5b as a standalone,
#                verb-driven provisioner.
#
# WHY THIS EXISTS SEPARATELY
#   The scanner SHIPS with the mail stack: install_email.sh calls `install`
#   unconditionally, so every box that hosts its own mail has rspamd from
#   birth and enabling spam learning later is a pure settings toggle — no
#   day-2 command for the owner. This script stands alone so it can also be
#   run directly: to repair config or milter-wiring drift (install is the
#   repair — it is idempotent), to add a scanner to a box that never ran the
#   mail installer (a webhook-only deployment opting into learning), or to
#   push the scanner onto older boxes provisioned before it shipped with the
#   stack (Server Manager node exec, fleet-wide).
#
# WHAT install DOES
#   - Installs rspamd + redis-server.
#   - Writes the joinery-managed /etc/rspamd/local.d config: the X-Spam header
#     contract InboundEmailRouter::readSpamHeader() parses, add_header-only
#     actions (NEVER reject - the reviewable-verdict model), the Bayes
#     classifier on redis with autolearn, the loopback controller on 11334
#     (trusted by origin, no password), and the milter worker on 11332.
#   - Wires the milter into Postfix ONLY when Postfix is present. On a
#     relay-fronted or webhook box there is no local Postfix to wire, and the
#     scanner is used over HTTP at ingest instead; the milter worker just idles.
#   - Fully idempotent: safe to re-run any time, and re-running is the repair
#     for config or milter-wiring drift.
#
# WHAT remove DOES
#   Operator escape hatch only — the platform never runs or surfaces it (the
#   scanner is a permanent part of the mail stack). Purges rspamd and
#   redis-server, deletes the joinery-managed local.d files, and strips the
#   milter entry from smtpd_milters when Postfix is present. The Bayes corpus
#   dies with redis, deliberately: it is the tenant's private model and must
#   not linger on a reclaimed box. It is also disposable - Postgres
#   (iem_spam_verdict) is the durable truth, and the corpus self-heals from
#   stored corrections if the scanner is ever reinstalled, because the learn
#   task re-teaches every unreconciled row.
#
#   ASSUMPTION: on a joinery-provisioned box redis exists solely for this
#   scanner. The platform installs it nowhere else. If you added redis for
#   something of your own, run `remove` by hand instead.
#
# WHAT status DOES
#   Prints machine-readable key=value markers (packages, services, milter
#   wiring, controller reachability) for tests and the health probe.
#
# NOT the relay's scanner: provision_relay.sh installs a deliberately STATELESS
# rspamd on the relay (Bayes off, no redis) because one model trained across
# every tenant's mail would be both a privacy leak and a poisoning vector. This
# script is only ever for a deployment's own box.
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
RSPAMD_LOCAL_D="/etc/rspamd/local.d"
MILTER_ENTRY="inet:localhost:11332"

# The local.d files this script owns. remove deletes exactly these and nothing
# else, so a hand-written override elsewhere in local.d survives.
MANAGED_CONFIGS=(
    "milter_headers.conf"
    "actions.conf"
    "classifier-bayes.conf"
    "redis.conf"
    "worker-controller.inc"
    "worker-proxy.inc"
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

# Restart a service under systemd, falling back to sysv `service`, and finally
# to a warning. In a container there is usually no init at all: the CMD restarts
# services on boot and this script re-asserts config idempotently to match
# (spec mail_stack_container_persistence).
restart_service() {
    local svc="$1"
    systemctl enable "${svc}" >/dev/null 2>&1 || true
    if command -v systemctl >/dev/null 2>&1 && systemctl restart "${svc}" 2>/dev/null; then
        echo "${svc}: restarted (systemd)."
    elif command -v service >/dev/null 2>&1 && service "${svc}" restart >/dev/null 2>&1; then
        echo "${svc}: restarted (service)."
    else
        echo "WARNING: could not restart ${svc} automatically - start it manually." >&2
    fi
}

# --- install -----------------------------------------------------------------

do_install() {
    need_root
    if ! command -v apt-get >/dev/null 2>&1; then
        echo "This installer supports apt-based systems (Debian/Ubuntu) only." >&2
        exit 1
    fi

    echo "spam-scanner: installing rspamd + redis"

    local packages=(rspamd redis-server)
    local missing=()
    local pkg
    for pkg in "${packages[@]}"; do
        if dpkg -s "${pkg}" >/dev/null 2>&1; then
            echo "Already installed: ${pkg}"
        else
            missing+=("${pkg}")
        fi
    done
    if [[ ${#missing[@]} -gt 0 ]]; then
        echo "Installing: ${missing[*]}"
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y "${missing[@]}"
    fi

    mkdir -p "${RSPAMD_LOCAL_D}"

    # milter_headers: stamp X-Spam (binary flag) + X-Spam-Status (carries the score).
    # The header NAMES are the contract InboundEmailRouter::readSpamHeader() parses;
    # keep them in step with that class's SPAM_*_HEADER constants.
    cat > "${RSPAMD_LOCAL_D}/milter_headers.conf" <<'RSPAMDHDR'
# joinery-managed - content spam header contract (InboundEmailRouter::readSpamHeader).
extended_spam_headers = true;
use = ["spam-header", "x-spam-status", "authentication-results"];
# spam-header adds 'X-Spam: Yes' on a spam verdict; x-spam-status adds
# 'X-Spam-Status: Yes, score=...'. The app reads the flag + score from these.
RSPAMDHDR

    # actions: stamp headers only, NEVER reject/greylist (reviewable-verdict model).
    cat > "${RSPAMD_LOCAL_D}/actions.conf" <<'RSPAMDACT'
# joinery-managed - header-stamping only; rejection disabled (out of scope).
reject = null;
greylist = null;
add_header = 6;
RSPAMDACT

    # Bayes classifier on redis (the override just pins the backend + autolearn).
    # THIS is the capability a relay cannot provide: a corpus of this
    # deployment's own mail, taught by its own users' corrections.
    cat > "${RSPAMD_LOCAL_D}/classifier-bayes.conf" <<'RSPAMDBAYES'
# joinery-managed - Bayes tokens persist in redis (disposable; Postgres is truth).
backend = "redis";
servers = "127.0.0.1:6379";
autolearn = true;
RSPAMDBAYES

    cat > "${RSPAMD_LOCAL_D}/redis.conf" <<'RSPAMDREDIS'
# joinery-managed - local redis for Bayes/statistics.
servers = "127.0.0.1:6379";
RSPAMDREDIS

    # controller worker: loopback bind + loopback-trusted, so the privileged
    # learn command needs NO password. This is the endpoint LearnSpamFeedback
    # POSTs learn requests to and the ingest scan POSTs /checkv2 to.
    cat > "${RSPAMD_LOCAL_D}/worker-controller.inc" <<'RSPAMDCTRL'
# joinery-managed - controller on loopback; learn authorized by origin (no password).
bind_socket = "127.0.0.1:11334";
secure_ip = "127.0.0.1";
secure_ip = "::1";
RSPAMDCTRL

    # proxy (milter) worker: self-scan milter mode on 11332 (rspamd's default,
    # re-asserted so a non-default base image is corrected).
    cat > "${RSPAMD_LOCAL_D}/worker-proxy.inc" <<'RSPAMDPROXY'
# joinery-managed - Postfix milter (self-scan) on 11332.
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
bind_socket = "*:11332";
RSPAMDPROXY

    echo "spam-scanner: wrote ${#MANAGED_CONFIGS[@]} joinery-managed config file(s) to ${RSPAMD_LOCAL_D}"

    # Wire rspamd into Postfix AFTER opendkim+opendmarc so it scores on auth
    # results. Only meaningful where Postfix actually receives mail: on a
    # relay-fronted or webhook box the scanner is reached over HTTP at ingest.
    if postfix_present; then
        local current
        current="$(postconf -h smtpd_milters 2>/dev/null || true)"
        if [[ "${current}" == *"${MILTER_ENTRY}"* ]]; then
            echo "main.cf: rspamd milter already wired (${MILTER_ENTRY})"
        elif [[ -z "${current}" ]]; then
            postconf -e "smtpd_milters = ${MILTER_ENTRY}"
            echo "main.cf: rspamd milter set (${MILTER_ENTRY})"
        else
            postconf -e "smtpd_milters = ${current}, ${MILTER_ENTRY}"
            echo "main.cf: rspamd milter appended (${MILTER_ENTRY}, after ${current})"
        fi
        if command -v systemctl >/dev/null 2>&1 && systemctl reload postfix 2>/dev/null; then
            echo "postfix: reloaded (systemd)."
        elif command -v postfix >/dev/null 2>&1; then
            postfix reload >/dev/null 2>&1 || true
            echo "postfix: reloaded."
        fi
    else
        echo "spam-scanner: no local Postfix - scanner is HTTP-only (milter worker idles)."
    fi

    restart_service redis-server
    restart_service rspamd

    echo "spam-scanner: rspamd milter on 11332, controller on 127.0.0.1:11334 (loopback, no password)."
    echo "  NOTE: rspamd queries DNS RBLs while scanning - ensure outbound DNS egress or scoring degrades."
}

# --- remove ------------------------------------------------------------------

do_remove() {
    need_root

    # Unwire first, so Postfix never points at a milter that is going away.
    if postfix_present; then
        local current stripped
        current="$(postconf -h smtpd_milters 2>/dev/null || true)"
        if [[ "${current}" == *"${MILTER_ENTRY}"* ]]; then
            # Drop our entry and tidy the separators left behind.
            stripped="$(echo "${current}" \
                | sed "s#${MILTER_ENTRY}##g" \
                | sed 's/,[[:space:]]*,/,/g' \
                | sed 's/^[[:space:]]*,[[:space:]]*//' \
                | sed 's/[[:space:]]*,[[:space:]]*$//' \
                | sed 's/^[[:space:]]*//; s/[[:space:]]*$//')"
            postconf -e "smtpd_milters = ${stripped}"
            echo "main.cf: rspamd milter removed (smtpd_milters = ${stripped:-<empty>})"
            if command -v systemctl >/dev/null 2>&1 && systemctl reload postfix 2>/dev/null; then
                echo "postfix: reloaded (systemd)."
            elif command -v postfix >/dev/null 2>&1; then
                postfix reload >/dev/null 2>&1 || true
                echo "postfix: reloaded."
            fi
        else
            echo "main.cf: rspamd milter not wired - nothing to strip."
        fi
    fi

    local f
    for f in "${MANAGED_CONFIGS[@]}"; do
        if [[ -f "${RSPAMD_LOCAL_D}/${f}" ]]; then
            rm -f "${RSPAMD_LOCAL_D}/${f}"
            echo "removed ${RSPAMD_LOCAL_D}/${f}"
        fi
    done

    # Stop before purge so a systemd unit does not fight the package removal.
    local svc
    for svc in rspamd redis-server; do
        systemctl disable "${svc}" >/dev/null 2>&1 || true
        systemctl stop "${svc}" >/dev/null 2>&1 || service "${svc}" stop >/dev/null 2>&1 || true
    done

    if command -v apt-get >/dev/null 2>&1; then
        export DEBIAN_FRONTEND=noninteractive
        # The Bayes corpus goes with redis. Deliberate: it is the tenant's
        # private model, and Postgres holds the durable verdicts it rebuilds from.
        apt-get purge -y rspamd redis-server >/dev/null 2>&1 || true
        apt-get autoremove -y >/dev/null 2>&1 || true
        echo "spam-scanner: rspamd + redis purged (Bayes corpus discarded with redis)."
    else
        echo "WARNING: no apt-get - stop and uninstall rspamd/redis with your package manager." >&2
    fi
}

# --- status ------------------------------------------------------------------

do_status() {
    local pkg svc
    for pkg in rspamd redis-server; do
        if dpkg -s "${pkg}" >/dev/null 2>&1; then
            echo "package_${pkg//-/_}=installed"
        else
            echo "package_${pkg//-/_}=absent"
        fi
    done

    for svc in rspamd redis-server; do
        if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "${svc}" 2>/dev/null; then
            echo "service_${svc//-/_}=active"
        elif pgrep -x "${svc%%-*}" >/dev/null 2>&1; then
            echo "service_${svc//-/_}=running"
        else
            echo "service_${svc//-/_}=inactive"
        fi
    done

    local managed=0 f
    for f in "${MANAGED_CONFIGS[@]}"; do
        [[ -f "${RSPAMD_LOCAL_D}/${f}" ]] && managed=$((managed + 1))
    done
    echo "managed_configs=${managed}/${#MANAGED_CONFIGS[@]}"

    if postfix_present; then
        if postconf -h smtpd_milters 2>/dev/null | grep -q "${MILTER_ENTRY}"; then
            echo "milter_wired=yes"
        else
            echo "milter_wired=no"
        fi
    else
        echo "milter_wired=n/a"
    fi

    # The controller is what the app actually talks to - learn requests and the
    # ingest-time scan both go through it, so reachability is the real signal.
    if command -v curl >/dev/null 2>&1 && curl -sf -m 3 http://127.0.0.1:11334/ping >/dev/null 2>&1; then
        echo "controller=reachable"
    elif (echo >/dev/tcp/127.0.0.1/11334) >/dev/null 2>&1; then
        echo "controller=listening"
    else
        echo "controller=unreachable"
    fi
}

case "${VERB}" in
    install) do_install ;;
    remove)  do_remove ;;
    status)  do_status ;;
esac
