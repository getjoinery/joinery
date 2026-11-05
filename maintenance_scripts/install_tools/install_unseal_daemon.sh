#!/usr/bin/env bash
#
# install_unseal_daemon.sh - install or converge one site's unseal daemon
# (specs/unseal_daemon.md § Installing and supervising, docs/sealed_vault.md).
#
# Version: 1.1 - every site keeps its own builds and launcher under /var/lib/joinery-unseal/KEY/, so two
#               sites on one host at different releases never swap a shared binary (reviewer2 B1);
#               TimeoutStartSec=15 (B3); the health ping runs the build the site runs (B4); the
#               selftest runs as the daemon's user (B6); a container keepalive is locked and an
#               intended restart is not a crash (B7); a refusal leaves its reason (B8); the unit
#               names the key (B9); without systemd the daemon is stopped before its build is
#               replaced (B11)
# Version: 1.0
#
# The daemon holds the site's open vault windows outside the PHP pool, so a
# break-in during a window can use a key but never carry it away. Every time
# it runs this leaves behind:
#   - a system user `joinery-unseal` with no shell, no home and no groups;
#   - the site's own builds, in /var/lib/joinery-unseal/KEY/ (root's, 0755):
#     joinery-unseal, byte-identical to the prebuilt
#     joinery_unseal/bin/joinery-unseal-<uname -m> shipped beside this script
#     (nothing is compiled on a node), and the build it replaced kept as
#     joinery-unseal.previous, the one the launcher falls back to when the
#     current build does not stay up. A new build is installed only when its
#     bytes changed and its --selftest passes (run as the daemon's user), by
#     rename. Per site, because two sites on one host can be on different
#     releases;
#   - the site's launcher beside them, launch (joinery_unseal/launch.sh:
#     root's side of running it, the socket directory, the fallback rule, the
#     crash record the agent reads, the hold);
#   - supervision by the install_agent.sh rule: a systemd unit where systemd is
#     PID 1 (Type=notify with a 30 s watchdog, restart in 5 s, its memory
#     locked and never dumped, no network, a read-only system, a system-call
#     filter, OOMScoreAdjust=-900), a cron keepalive otherwise (a site
#     container), which pings the daemon every minute and restarts it on a
#     hang;
#   - the install record /etc/joinery-unseal/KEY/installed (socket, pool uid,
#     how it is supervised), written before the first start because the
#     launcher reads it. It is the fact the site reads to know the daemon is
#     installed here; the installer ends by asking the daemon for its status
#     as the pool user, and says so when it does not answer.
#
# KEY is the basename of the site root made safe for a path, the same name the
# site derives from its own root (UnsealDaemon::key()).
#
# Not applicable (exit 0, the site keeps its vault on the pool path and its
# health page says so): not root; no prebuilt for this machine; a memory-lock
# limit that cannot be raised here (a container started without
# --ulimit memlock=-1), which leaves /etc/joinery-unseal/KEY/refused naming the
# reason for the site's health row. A restart ends every window, so the daemon is restarted
# only when its binary or unit changed.
#
# Runs at the platform's root moments through _plugin_installers_start.sh (a
# core installer), and by hand:
#
#     sudo bash /var/www/html/SITE/maintenance_scripts/install_tools/install_unseal_daemon.sh
#
# Contract (docs/plugin_developer_guide.md): idempotent, root, non-interactive,
# exit 0 when not applicable.
#
# Usage:  install_unseal_daemon.sh [SITENAME] [SITE_ROOT]

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DAEMON_USER="joinery-unseal"
POOL_USER="www-data"

SITE_ROOT="${2:-}"
SITENAME="${1:-}"
if [[ -z "${SITE_ROOT}" ]]; then
    if [[ -n "${SITENAME}" && -d "/var/www/html/${SITENAME}" ]]; then
        SITE_ROOT="/var/www/html/${SITENAME}"
    else
        SITE_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
    fi
fi
SITE_ROOT="${SITE_ROOT%/}"
KEY="$(printf '%s' "$(basename "${SITE_ROOT}")" | tr -c 'A-Za-z0-9._-' '_' | cut -c1-64)"
[[ -n "${SITENAME}" ]] || SITENAME="${KEY}"

CONF_DIR="/etc/joinery-unseal/${KEY}"
CONF="${CONF_DIR}/installed"
SOCKET="/run/joinery-unseal/${KEY}/unseal.sock"
STATE_DIR="/var/lib/joinery-unseal/${KEY}"
DEST="${STATE_DIR}/joinery-unseal"
PREVIOUS="${STATE_DIR}/joinery-unseal.previous"
LAUNCH="${STATE_DIR}/launch"
REFUSED="${CONF_DIR}/refused"
SERVICE_NAME="joinery-unseal-${KEY}"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
CRON_FILE="/etc/cron.d/joinery-unseal-${KEY}"

say() { echo "unseal daemon: $*"; }

if [[ "$(id -u)" != "0" ]]; then
    say "not root - skipping (run: sudo bash ${SCRIPT_DIR}/install_unseal_daemon.sh)"
    exit 0
fi
if [[ ! -d "${SITE_ROOT}/public_html" ]]; then
    say "no site at ${SITE_ROOT} - skipping"
    exit 0
fi

MACHINE="$(uname -m)"
SRC="${SCRIPT_DIR}/joinery_unseal/bin/joinery-unseal-${MACHINE}"
LAUNCH_SRC="${SCRIPT_DIR}/joinery_unseal/launch.sh"
if [[ ! -f "${SRC}" ]] || ! head -c 4 "${SRC}" | grep -q 'ELF'; then
    say "no prebuilt daemon for ${MACHINE} at ${SRC} - skipping; the vault stays on the pool path"
    exit 0
fi
if [[ ! -f "${LAUNCH_SRC}" ]]; then
    say "no launcher at ${LAUNCH_SRC} - skipping"
    exit 0
fi
if ! POOL_UID="$(id -u "${POOL_USER}" 2>/dev/null)" || ! getent group "${POOL_USER}" >/dev/null 2>&1; then
    say "no ${POOL_USER} user and group for the socket - skipping"
    exit 0
fi

if command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; then
    INIT_MODE="systemd"
else
    INIT_MODE="cron"
fi

# The daemon locks every page and refuses to start under a bounded memory-lock
# limit. A unit sets the limit itself; a container needs one it may raise.
if [[ "${INIT_MODE}" == "cron" ]] && ! prlimit --memlock=unlimited:unlimited true 2>/dev/null; then
    say "the memory-lock limit cannot be raised here (a site container gets --ulimit memlock=-1:-1 when rebuilt with a memory budget) - not installed; the vault stays on the pool path"
    install -d -o root -g root -m 0755 /etc/joinery-unseal "${CONF_DIR}"
    printf 'reason=memlock\n' > "${REFUSED}.new" && chmod 644 "${REFUSED}.new" && mv -f "${REFUSED}.new" "${REFUSED}"
    exit 0
fi
rm -f "${REFUSED}"

# --- 1. the user -------------------------------------------------------------
if ! id -u "${DAEMON_USER}" >/dev/null 2>&1; then
    if useradd --system --no-create-home --home-dir /nonexistent \
            --shell /usr/sbin/nologin --user-group "${DAEMON_USER}" 2>/dev/null; then
        say "created user ${DAEMON_USER}"
    else
        say "WARNING - could not create user ${DAEMON_USER}; not installed" >&2
        exit 1
    fi
fi

# --- 2. the binary, the previous build, the launcher ---------------------------
install -d -o root -g root -m 0755 /etc/joinery-unseal "${CONF_DIR}" /var/lib/joinery-unseal "${STATE_DIR}"
CHANGED=0
if ! cmp -s "${SRC}" "${DEST}" 2>/dev/null; then
    TMP_DEST="${DEST}.new.$$"
    if ! { cp "${SRC}" "${TMP_DEST}" && chown root:root "${TMP_DEST}" && chmod 755 "${TMP_DEST}"; }; then
        rm -f "${TMP_DEST}"
        say "WARNING - could not stage ${DEST}" >&2
        exit 1
    fi
    if ! runuser -u "${DAEMON_USER}" -- "${TMP_DEST}" --selftest >/dev/null 2>&1; then
        rm -f "${TMP_DEST}"
        say "WARNING - the shipped build fails its selftest on this machine; keeping the installed one" >&2
        exit 1
    fi
    # Without systemd, the running daemon is stopped before its build is
    # replaced: a stop recorded as one, with nothing left holding the socket.
    if [[ "${INIT_MODE}" == "cron" && -x "${LAUNCH}" && -f "${CONF}" ]]; then
        "${LAUNCH}" stop "${KEY}" || true
    fi
    # The build being replaced becomes the previous one, unless the site is
    # running the previous build now: then the current one is the bad build,
    # and the previous is the last good one, kept as it is.
    if [[ -f "${DEST}" ]] && ! grep -qsx previous "${STATE_DIR}/binary"; then
        cp -p "${DEST}" "${PREVIOUS}.new.$$" && mv -f "${PREVIOUS}.new.$$" "${PREVIOUS}"
    fi
    mv -f "${TMP_DEST}" "${DEST}"
    say "installed ${DEST} ($(runuser -u "${DAEMON_USER}" -- "${DEST}" --version 2>/dev/null || echo "?"))"
    CHANGED=1
fi
if ! cmp -s "${LAUNCH_SRC}" "${LAUNCH}" 2>/dev/null; then
    install -o root -g root -m 0755 "${LAUNCH_SRC}" "${LAUNCH}.new.$$" && mv -f "${LAUNCH}.new.$$" "${LAUNCH}"
    say "installed ${LAUNCH}"
    CHANGED=1
fi
# The host-wide builds and launcher of installer 1.0, once nothing uses them.
for old in /usr/local/sbin/joinery-unseal /usr/local/sbin/joinery-unseal.previous /usr/local/sbin/joinery-unseal-launch; do
    [[ -e "${old}" ]] || continue
    in_use=0
    for link in /var/lib/joinery-unseal/*/active; do
        [[ "$(readlink "${link}" 2>/dev/null)" == "${old}" ]] && in_use=1
    done
    grep -qsF "${old} " /etc/systemd/system/joinery-unseal-*.service /etc/cron.d/joinery-unseal-* && in_use=1
    [[ "${in_use}" == "1" ]] || rm -f "${old}"
done

# --- 3. the install record and state -------------------------------------------
# Written before the first start, since the launcher reads it; the site treats
# the daemon as installed once this exists.
CONF_BODY="$(printf 'socket=%s\nallow_uid=%s\npool_user=%s\ninit=%s\n' "${SOCKET}" "${POOL_UID}" "${POOL_USER}" "${INIT_MODE}")"
if [[ "$(cat "${CONF}" 2>/dev/null)" != "${CONF_BODY}" ]]; then
    printf '%s\n' "${CONF_BODY}" > "${CONF}.new" && chmod 644 "${CONF}.new" && mv -f "${CONF}.new" "${CONF}"
    CHANGED=1
fi
if [[ "${CHANGED}" == "1" ]]; then
    # A changed build starts on itself, with a clean crash count.
    echo current > "${STATE_DIR}/binary"
    : > "${STATE_DIR}/starts"
    chmod 644 "${STATE_DIR}/binary" "${STATE_DIR}/starts"
fi

# --- 4. supervision ------------------------------------------------------------
if [[ "${INIT_MODE}" == "systemd" ]]; then
    rm -f "${CRON_FILE}"
    # The socket's directory exists before the unit's sandbox is set up (the
    # sandbox names it): made at every boot by tmpfiles, and now.
    TMPFILES="/etc/tmpfiles.d/joinery-unseal-${KEY}.conf"
    printf 'd /run/joinery-unseal 0755 root root -\nd /run/joinery-unseal/%s 0750 %s %s -\n' \
        "${KEY}" "${DAEMON_USER}" "${POOL_USER}" > "${TMPFILES}.new"
    chmod 644 "${TMPFILES}.new"
    mv -f "${TMPFILES}.new" "${TMPFILES}"
    systemd-tmpfiles --create "${TMPFILES}" >/dev/null 2>&1 || true
    UNIT_TMP="$(mktemp)"
    cat > "${UNIT_TMP}" <<UNIT
# Written by install_unseal_daemon.sh. Holds the open vault windows of the site ${KEY}.
[Unit]
Description=Joinery unseal daemon (${KEY})
StartLimitIntervalSec=0

[Service]
Type=notify
TimeoutStartSec=15
NotifyAccess=main
User=${DAEMON_USER}
Group=${DAEMON_USER}
ExecStartPre=+${LAUNCH} prepare ${KEY}
ExecStart=${STATE_DIR}/active --socket ${SOCKET} --allow-uid ${POOL_UID} --hold-file /run/joinery-unseal/${KEY}.hold --socket-mode 0666
ExecStopPost=+${LAUNCH} stopped ${KEY}
Restart=always
RestartSec=5
WatchdogSec=30
TimeoutStopSec=10
OOMScoreAdjust=-900
LimitMEMLOCK=infinity
LimitCORE=0
UMask=0077
MemoryDenyWriteExecute=yes
NoNewPrivileges=yes
CapabilityBoundingSet=
AmbientCapabilities=
PrivateNetwork=yes
IPAddressDeny=any
RestrictAddressFamilies=AF_UNIX
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
ProtectHostname=yes
ProtectProc=invisible
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=yes
LockPersonality=yes
RemoveIPC=yes
SystemCallArchitectures=native
SystemCallFilter=@system-service @memlock
SystemCallFilter=~@privileged
SystemCallErrorNumber=EPERM
ReadWritePaths=-/run/joinery-unseal/${KEY}

[Install]
WantedBy=multi-user.target
UNIT
    if ! cmp -s "${UNIT_TMP}" "${SERVICE_FILE}" 2>/dev/null; then
        mv -f "${UNIT_TMP}" "${SERVICE_FILE}"
        chmod 644 "${SERVICE_FILE}"
        systemctl daemon-reload
        CHANGED=1
        say "wrote ${SERVICE_FILE}"
    else
        rm -f "${UNIT_TMP}"
    fi
    systemctl enable "${SERVICE_NAME}" >/dev/null 2>&1 || true
    if [[ "${CHANGED}" == "1" ]]; then
        systemctl restart "${SERVICE_NAME}"
    elif ! systemctl is-active --quiet "${SERVICE_NAME}"; then
        systemctl reset-failed "${SERVICE_NAME}" >/dev/null 2>&1 || true
        systemctl start "${SERVICE_NAME}"
    fi
else
    cat > "${CRON_FILE}.new" <<CRON
# The unseal daemon of the site ${KEY} (no systemd here): start it, restart it when it
# died or stopped answering. Logs: /var/log/joinery-unseal-${KEY}.log.
@reboot root ${LAUNCH} keepalive ${KEY} >/dev/null 2>&1
* * * * * root ${LAUNCH} keepalive ${KEY} >/dev/null 2>&1
CRON
    chmod 644 "${CRON_FILE}.new"
    mv -f "${CRON_FILE}.new" "${CRON_FILE}"
    if [[ "${CHANGED}" == "1" ]]; then
        # An intended stop: the keepalive must not count it as a crash.
        "${LAUNCH}" stop "${KEY}" || true
    fi
    "${LAUNCH}" keepalive "${KEY}" || true
fi

# --- 5. health: status, as the pool user ------------------------------------------
for _ in $(seq 1 20); do
    [[ -S "${SOCKET}" ]] && break
    sleep 0.5
done
# The build the site runs answers its own ping (current, or previous after a fallback).
if out="$(timeout 5 runuser -u "${POOL_USER}" -- "$(readlink -f "${STATE_DIR}/active")" ping --socket "${SOCKET}" --timeout 3 2>&1)"; then
    say "running for ${KEY} (${INIT_MODE}): ${out}"
    exit 0
fi
say "WARNING - the daemon did not answer on ${SOCKET}: ${out}" >&2
exit 1
