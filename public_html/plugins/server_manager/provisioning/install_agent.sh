#!/usr/bin/env bash
#
# install_agent.sh - server_manager host_installer: install or converge the
# joinery-agent from the shipped agent_dist artifact.
#
# Version: 1.1 (specs/implemented/agent_release_channel.md)
#
# Runs at the platform's root moments (container start, site install, code
# upgrade, and the Run Plugin Installers action) on any deployment where the
# server_manager plugin is active - i.e. on control planes. First install is
# handled here; every later version change is handled by the agent itself
# (self-update with signature verification against its embedded public key).
#
# Trust note: this script checks artifact integrity (sha256) but does not
# verify the publisher signature - a fresh box has no trust anchor besides
# the tree this script itself was delivered in, and it is already running as
# root from that tree. Unattended signature enforcement lives in the agent,
# which carries the publisher's public key embedded in its binary.
#
# Contract (docs/plugin_developer_guide.md): idempotent, root,
# non-interactive, exit 0 when not applicable.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
DIST_DIR="${PLUGIN_DIR}/agent_dist"
PUBLIC_HTML="$(cd "${PLUGIN_DIR}/../.." && pwd)"
SITE_ROOT="$(dirname "$PUBLIC_HTML")"
SITE_CONFIG="${SITE_ROOT}/config/Globalvars_site.php"

BINARY_PATH="/usr/local/bin/joinery-agent"
SUPERVISE_PATH="/usr/local/bin/joinery-agent-supervise"
SERVICE_NAME="joinery-agent"
SERVICE_FILE="/etc/systemd/system/joinery-agent.service"
CRON_FILE="/etc/cron.d/joinery-agent"
LOG_FILE="/var/log/joinery-agent.log"
ENV_DIR="/etc/joinery-agent"
ENV_FILE="${ENV_DIR}/joinery-agent.env"

say() { echo "agent installer: $*"; }

[ "$(id -u)" = "0" ] || { say "not running as root - skipping"; exit 0; }
[ -f "${DIST_DIR}/manifest.json" ] || { say "no shipped agent artifact - skipping"; exit 0; }
[ -f "$SITE_CONFIG" ] || { say "site not initialised yet - skipping"; exit 0; }
command -v php >/dev/null 2>&1 || { say "php-cli not available - skipping"; exit 0; }

case "$(uname -m)" in
    x86_64)  ARCH="linux-amd64" ;;
    aarch64) ARCH="linux-arm64" ;;
    *) say "unsupported architecture $(uname -m) - skipping"; exit 0 ;;
esac

read -r DIST_VERSION DIST_FILE DIST_SHA256 <<EOF
$(php -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    $e = $m["binaries"][$argv[2]] ?? null;
    if (!$m || !$e) { exit(0); }
    echo $m["version"] . " " . $e["file"] . " " . $e["sha256"];
' "${DIST_DIR}/manifest.json" "$ARCH" 2>/dev/null)
EOF

if [ -z "${DIST_VERSION:-}" ] || [ -z "${DIST_FILE:-}" ]; then
    say "manifest has no usable ${ARCH} entry - skipping"
    exit 0
fi

# Supervision mode: systemd where it is actually running (PID 1), cron
# otherwise (Docker containers, minimal hosts).
if command -v systemctl >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
    INIT_MODE="systemd"
else
    INIT_MODE="cron"
fi

installed_version() {
    [ -x "$BINARY_PATH" ] || { echo ""; return; }
    "$BINARY_PATH" --version 2>/dev/null | awk '{print $2}'
}

# True when $1 sorts strictly before $2. sort -V, never string comparison:
# 1.1.0 against 0.4.0 is fine either way, but 0.10.0 against 0.9.0 is not.
version_is_older() {
    [ "$1" != "$2" ] && \
        [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$1" ]
}

write_env_file() {
    mkdir -p "$ENV_DIR"
    if [ ! -f "$ENV_FILE" ]; then
        {
            echo "# joinery-agent environment (written by server_manager install_agent.sh)"
            echo "JOINERY_CONFIG=${SITE_CONFIG}"
        } > "$ENV_FILE"
        chmod 640 "$ENV_FILE"
    elif ! grep -q '^JOINERY_CONFIG=' "$ENV_FILE"; then
        echo "JOINERY_CONFIG=${SITE_CONFIG}" >> "$ENV_FILE"
    fi
}

write_supervise_script() {
    cat > "$SUPERVISE_PATH" <<'SUPERVISE'
#!/bin/sh
# joinery-agent cron keepalive: start the agent if it is not running.
if ! pgrep -x joinery-agent >/dev/null 2>&1; then
    set -a
    [ -f /etc/joinery-agent/joinery-agent.env ] && . /etc/joinery-agent/joinery-agent.env
    set +a
    nohup /usr/local/bin/joinery-agent >> /var/log/joinery-agent.log 2>&1 &
fi
SUPERVISE
    chmod 755 "$SUPERVISE_PATH"
}

write_cron_file() {
    cat > "$CRON_FILE" <<CRON
# joinery-agent supervision (no systemd on this host). The keepalive script
# starts the agent if it is not running; logs go to ${LOG_FILE}.
@reboot root ${SUPERVISE_PATH}
* * * * * root ${SUPERVISE_PATH}
CRON
    chmod 644 "$CRON_FILE"
}

ensure_supervision() {
    write_env_file
    if [ "$INIT_MODE" = "systemd" ]; then
        if [ -f "${DIST_DIR}/joinery-agent.service" ]; then
            if ! cmp -s "${DIST_DIR}/joinery-agent.service" "$SERVICE_FILE" 2>/dev/null; then
                cp "${DIST_DIR}/joinery-agent.service" "$SERVICE_FILE"
                systemctl daemon-reload
            fi
        fi
        systemctl enable "$SERVICE_NAME" >/dev/null 2>&1 || true
    else
        write_supervise_script
        write_cron_file
    fi
}

agent_running() {
    if [ "$INIT_MODE" = "systemd" ]; then
        systemctl is-active --quiet "$SERVICE_NAME"
    else
        pgrep -x joinery-agent >/dev/null 2>&1
    fi
}

start_agent() {
    if [ "$INIT_MODE" = "systemd" ]; then
        systemctl restart "$SERVICE_NAME"
    else
        pkill -x joinery-agent 2>/dev/null || true
        sleep 1
        "$SUPERVISE_PATH"
    fi
}

# Ordering, not equality. This script does first install only — every later
# version change comes from the agent's own signed self-update — so a node
# routinely runs a version NEWER than the artifact shipped in this tree. Testing
# equality treats "newer" and "older" alike and reinstalls the shipped one over
# a self-updated agent, rolling it back with nothing to say so. Anything at or
# above the shipped version keeps its binary; only supervision converges.
#
# JOINERY_AGENT_ALLOW_DOWNGRADE=1 forces the shipped artifact on regardless, for
# a deliberate rollback.
CURRENT="$(installed_version)"
if [ -n "$CURRENT" ] && [ "${JOINERY_AGENT_ALLOW_DOWNGRADE:-0}" != "1" ] \
    && ! version_is_older "$CURRENT" "$DIST_VERSION"; then
    ensure_supervision
    if [ "$CURRENT" = "$DIST_VERSION" ]; then
        STATE="v${DIST_VERSION} already installed"
    else
        STATE="v${CURRENT} is newer than the shipped v${DIST_VERSION} - keeping it"
    fi
    if agent_running; then
        say "${STATE} and running - nothing to do"
    else
        say "${STATE}, but not running - starting"
        start_agent
    fi
    exit 0
fi

say "installing joinery-agent v${DIST_VERSION} (was ${CURRENT:-none}) from shipped artifact"

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

if ! gunzip -c "${DIST_DIR}/${DIST_FILE}" > "${STAGE}/joinery-agent" 2>/dev/null; then
    say "WARNING - could not decompress ${DIST_FILE}; leaving current agent in place"
    exit 0
fi

ACTUAL_SHA="$(sha256sum "${STAGE}/joinery-agent" | awk '{print $1}')"
if [ "$ACTUAL_SHA" != "$DIST_SHA256" ]; then
    say "WARNING - sha256 mismatch on ${DIST_FILE}; refusing to install"
    exit 0
fi

install -m 755 "${STAGE}/joinery-agent" "$BINARY_PATH"
ensure_supervision
start_agent
sleep 2

if agent_running; then
    say "joinery-agent v${DIST_VERSION} running (${INIT_MODE} supervision)"
else
    say "WARNING - agent installed but not running; check ${LOG_FILE} / journalctl -u ${SERVICE_NAME}"
fi

exit 0
