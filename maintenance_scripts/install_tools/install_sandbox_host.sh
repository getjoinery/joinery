#!/usr/bin/env bash
#
# install_sandbox_host.sh - install or converge the sandboxed plugin host for
# one site (specs/plugin_tiers.md WP7, docs/sandboxed_plugins.md).
#
# Version: 1.1 - the unit masks the web root, filters system calls, refuses internal addresses, yields CPU
# Version: 1.0
#
# A site gets a plugin host only while it has a sandboxed plugin on disk (a
# plugins/ folder holding exactly manifest.json and one .wasm). While it has
# one, this leaves behind, every time it runs:
#   - a system user `joinery-sbx` with no shell, no home and no groups: the
#     host runs as it, and it can read the plugins (world-readable, like the
#     rest of the code tree) and nothing of the site's that is not (config/,
#     uploads/, backups/ ...);
#   - /usr/local/bin/sandbox-host, byte-identical to the prebuilt
#     sandbox_host/bin/sandbox-host-<uname -m> shipped beside this script
#     (nothing is ever compiled on a node);
#   - SITE_ROOT/run/sandbox-host/, owned by joinery-sbx, group www-data, mode
#     2750: the host's socket is made there 0660, so the web server (and so
#     core) can call it and nobody else can;
#   - supervision the way install_agent.sh supervises the agent: a systemd unit
#     where systemd is PID 1 (sandboxed: no new privileges, no write-execute
#     memory, a system-service syscall filter, a read-only system with the web
#     root masked but for the plugins and the socket, no reach to link-local,
#     private or unique-local addresses, a low CPU weight), a cron keepalive
#     otherwise (a site container, which gets none of the unit's limits: the
#     container's own are what bound it there);
#   - a health check: the host answers /health on its socket.
# With no sandboxed plugin on disk it stops the site's host and removes its
# supervision, and leaves the binary and the user for the next one.
#
# Runs at the platform's root moments through _plugin_installers_start.sh (a
# core installer; activating or installing a plugin changes what it converges
# on, so the next tick runs it), and by hand:
#
#     sudo bash /var/www/html/SITE/maintenance_scripts/install_tools/install_sandbox_host.sh
#
# Contract (docs/plugin_developer_guide.md): idempotent, root, non-interactive,
# exit 0 when not applicable.
#
# Usage:  install_sandbox_host.sh [SITENAME] [SITE_ROOT]

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST_USER="joinery-sbx"
WEB_GROUP="www-data"
DEST="/usr/local/bin/sandbox-host"

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
[[ -n "${SITENAME}" ]] || SITENAME="$(basename "${SITE_ROOT}")"
# A name for files outside the site: letters, digits, dot, dash, underscore.
UNIT_SITE="$(printf '%s' "${SITENAME}" | tr -c 'A-Za-z0-9._-' '_')"

PLUGINS_DIR="${SITE_ROOT}/public_html/plugins"
RUN_DIR="${SITE_ROOT}/run/sandbox-host"
SOCKET="${RUN_DIR}/host.sock"
SERVICE_NAME="joinery-sandbox-host-${UNIT_SITE}"
SERVICE_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
CRON_FILE="/etc/cron.d/joinery-sandbox-host-${UNIT_SITE}"
SUPERVISE_PATH="/usr/local/bin/joinery-sandbox-host-supervise-${UNIT_SITE}"
LOG_FILE="/var/log/joinery-sandbox-host-${UNIT_SITE}.log"

say() { echo "sandbox host: $*"; }

if [[ "$(id -u)" != "0" ]]; then
    say "not root - skipping (run: sudo bash ${SCRIPT_DIR}/install_sandbox_host.sh)"
    exit 0
fi

if command -v systemctl >/dev/null 2>&1 && [[ -d /run/systemd/system ]]; then
    INIT_MODE="systemd"
else
    INIT_MODE="cron"
fi

# Is a sandboxed plugin on disk? Judged by its contents, the way core judges it
# (SandboxPlugins::isSandboxedDir): exactly manifest.json and one .wasm.
has_sandboxed_plugin() {
    local d entries wasm
    [[ -d "${PLUGINS_DIR}" ]] || return 1
    for d in "${PLUGINS_DIR}"/*/; do
        [[ -d "${d}" && ! -L "${d%/}" ]] || continue
        entries="$(find "${d}" -mindepth 1 -maxdepth 1 2>/dev/null | wc -l)"
        [[ "${entries}" == "2" ]] || continue
        [[ -f "${d}manifest.json" && ! -L "${d}manifest.json" ]] || continue
        wasm="$(find "${d}" -mindepth 1 -maxdepth 1 -type f -name '*.wasm' 2>/dev/null | wc -l)"
        [[ "${wasm}" == "1" ]] && return 0
    done
    return 1
}

stop_host() {
    if [[ "${INIT_MODE}" == "systemd" && -f "${SERVICE_FILE}" ]]; then
        systemctl disable --now "${SERVICE_NAME}" >/dev/null 2>&1 || true
        rm -f "${SERVICE_FILE}"
        systemctl daemon-reload
        say "stopped ${SERVICE_NAME} (no sandboxed plugin on this site)"
    fi
    if [[ -f "${CRON_FILE}" ]]; then
        rm -f "${CRON_FILE}" "${SUPERVISE_PATH}"
        pkill -u "${HOST_USER}" -f -- "-socket ${SOCKET}" >/dev/null 2>&1 || true
        say "stopped the keepalive (no sandboxed plugin on this site)"
    fi
    rm -f "${SOCKET}" 2>/dev/null || true
}

if ! has_sandboxed_plugin; then
    stop_host
    exit 0
fi

ARCH="$(uname -m)"
SRC="${SCRIPT_DIR}/sandbox_host/bin/sandbox-host-${ARCH}"
if [[ ! -f "${SRC}" ]]; then
    say "WARNING - no prebuilt host for ${ARCH} at ${SRC}; sandboxed plugins will not run" >&2
    exit 0
fi
if ! head -c 4 "${SRC}" | grep -q 'ELF'; then
    say "WARNING - ${SRC} is not an executable; sandboxed plugins will not run" >&2
    exit 0
fi

# --- 1. the user -------------------------------------------------------------
if ! id -u "${HOST_USER}" >/dev/null 2>&1; then
    if useradd --system --no-create-home --home-dir /nonexistent \
            --shell /usr/sbin/nologin --user-group "${HOST_USER}" 2>/dev/null; then
        say "created user ${HOST_USER}"
    else
        say "WARNING - could not create user ${HOST_USER}; not installed" >&2
        exit 1
    fi
fi
if ! getent group "${WEB_GROUP}" >/dev/null 2>&1; then
    say "WARNING - no ${WEB_GROUP} group for the socket; not installed" >&2
    exit 1
fi

# --- 2. the binary -----------------------------------------------------------
CHANGED=0
if ! cmp -s "${SRC}" "${DEST}" 2>/dev/null; then
    TMP_DEST="${DEST}.new.$$"
    if cp "${SRC}" "${TMP_DEST}" && chown root:root "${TMP_DEST}" && chmod 755 "${TMP_DEST}" \
            && mv -f "${TMP_DEST}" "${DEST}"; then
        say "installed ${DEST} ($("${DEST}" -version 2>/dev/null || echo '?'))"
        CHANGED=1
    else
        rm -f "${TMP_DEST}"
        say "WARNING - could not install ${DEST}" >&2
        exit 1
    fi
fi

# --- 3. the socket's directory -------------------------------------------------
mkdir -p "${SITE_ROOT}/run"
chown root:root "${SITE_ROOT}/run"
chmod 755 "${SITE_ROOT}/run"
mkdir -p "${RUN_DIR}"
chown "${HOST_USER}:${WEB_GROUP}" "${RUN_DIR}"
chmod 2750 "${RUN_DIR}"

ARGS="-socket ${SOCKET} -plugins-dir ${PLUGINS_DIR}"

# --- 4. supervision ------------------------------------------------------------
if [[ "${INIT_MODE}" == "systemd" ]]; then
    # What the host sees of the files: the web root masked, its plugins (read
    # only) and its socket directory put back. Everything else under it
    # (other sites, this site's config/, uploads/ ...) is not there at all.
    case "${SITE_ROOT}" in
        /var/www/*) MASK="/var/www" ;;
        *)          MASK="${SITE_ROOT}" ;;
    esac
    # A second line under the Go guard: the host's own process cannot reach
    # link-local (cloud metadata), private, CGNAT or unique-local addresses,
    # whatever a module asks. Loopback stays (door 1), and so does each
    # resolver this machine names, wherever it is.
    RESOLVERS="$(awk '/^nameserver[ \t]/ {print $2}' /etc/resolv.conf 2>/dev/null | tr '\n' ' ')"
    UNIT_TMP="$(mktemp)"
    cat > "${UNIT_TMP}" <<UNIT
# Written by install_sandbox_host.sh. Runs ${SITENAME}'s sandboxed plugins.
[Unit]
Description=Joinery sandboxed plugin host (${SITENAME})
After=network.target

[Service]
User=${HOST_USER}
Group=${HOST_USER}
ExecStart=${DEST} ${ARGS}
Restart=always
RestartSec=2
UMask=0007
# Modules run in wazero's interpreter: no generated code, so no memory may be
# both writable and executable.
MemoryDenyWriteExecute=yes
NoNewPrivileges=yes
CapabilityBoundingSet=
AmbientCapabilities=
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
RestrictNamespaces=yes
RestrictRealtime=yes
RestrictSUIDSGID=yes
LockPersonality=yes
SystemCallArchitectures=native
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
SystemCallFilter=@system-service
SystemCallErrorNumber=EPERM
TemporaryFileSystem=${MASK}:ro
BindReadOnlyPaths=${PLUGINS_DIR}
BindPaths=${RUN_DIR}
ReadWritePaths=${RUN_DIR}
IPAddressDeny=169.254.0.0/16 fe80::/10 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10 fc00::/7
IPAddressAllow=localhost ${RESOLVERS}
# Below PHP-FPM when both want the CPU; a module is bounded by its deadline.
CPUWeight=50
MemoryMax=512M
TasksMax=256

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
        systemctl start "${SERVICE_NAME}"
    fi
else
    cat > "${SUPERVISE_PATH}" <<SUPERVISE
#!/bin/sh
# ${SITENAME}'s sandboxed plugin host keepalive (install_sandbox_host.sh): start
# the host if it is not running. Every descriptor above stdio is closed before
# it starts, in a bash -c, for the reasons install_agent.sh's keepalive gives
# (an inherited lock, a shell that cannot close fd 10).
if ! pgrep -u ${HOST_USER} -f -- "-socket ${SOCKET}" >/dev/null 2>&1; then
    nohup runuser -u ${HOST_USER} -- bash -c 'for f in /proc/\$\$/fd/*; do n=\${f##*/}; case "\$n" in 0|1|2) continue;; esac; eval "exec \$n>&-" 2>/dev/null || true; done; umask 0007; exec ${DEST} ${ARGS}' >> ${LOG_FILE} 2>&1 < /dev/null &
fi
SUPERVISE
    chmod 755 "${SUPERVISE_PATH}"
    cat > "${CRON_FILE}" <<CRON
# ${SITENAME}'s sandboxed plugin host (no systemd here). Logs: ${LOG_FILE}.
@reboot root ${SUPERVISE_PATH}
* * * * * root ${SUPERVISE_PATH}
CRON
    chmod 644 "${CRON_FILE}"
    if [[ "${CHANGED}" == "1" ]]; then
        pkill -u "${HOST_USER}" -f -- "-socket ${SOCKET}" >/dev/null 2>&1 || true
        sleep 1
    fi
    "${SUPERVISE_PATH}"
fi

# --- 5. health ------------------------------------------------------------------
for _ in 1 2 3 4 5 6 7 8 9 10; do
    [[ -S "${SOCKET}" ]] && break
    sleep 0.5
done
if command -v curl >/dev/null 2>&1 && [[ -S "${SOCKET}" ]] \
        && curl -fsS --max-time 3 --unix-socket "${SOCKET}" http://sandbox-host/health 2>/dev/null | grep -q '"ok":true'; then
    say "running for ${SITENAME} (${INIT_MODE}), socket ${SOCKET}"
    exit 0
fi
say "WARNING - the host did not answer on ${SOCKET}" >&2
exit 1
