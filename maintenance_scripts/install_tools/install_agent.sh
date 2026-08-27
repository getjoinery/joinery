#!/usr/bin/env bash
#
# install_agent.sh - install or converge the joinery-agent on this machine from
# the shipped agent_dist artifact, or stop it where it is switched off.
#
# Version: 2.1 - The binary lands on every deployment; agent_enabled decides only
#                whether it RUNS. Installing is not running, and a machine that
#                already has the agent present can be switched on without
#                fetching anything.
#                2.0 - Core installer, gated on the agent_enabled setting. Runs on
#                every Joinery instance rather than only where the
#                server_manager plugin is active (specs/agent_on_node_architecture.md).
#                1.1 - forward-only install; 1.0 - initial.
#
# Runs at the platform's root moments (container start, site install, code
# upgrade, and the Run Plugin Installers action) on every Joinery instance.
# This is core work, not a plugin's: the agent is how a machine does its own
# root-level maintenance, and eventually how it is managed at all. The
# server_manager plugin builds and signs the artifact this installs, which is
# why the artifact still lives in that plugin's tree - the plugin ships to every
# node whether or not it is active there.
#
# The binary is installed unconditionally. Whether it RUNS is one setting,
# agent_enabled, which ships off: on starts it and sets up supervision, off
# stops it and takes the supervision away. Nothing about that setting connects
# this machine to a management node - that is a separate, deliberate act on the
# Management Node admin page. `install.sh --enable-agent` sets it at install
# time, which is how a node provisioned from a management node comes up running.
#
# First install is handled here; every later version change is handled by the
# agent itself (self-update with signature verification against its embedded
# public key).
#
# Trust note: this script checks artifact integrity (sha256) but does not
# verify the publisher signature - a fresh box has no trust anchor besides
# the tree this script itself was delivered in, and it is already running as
# root from that tree. Unattended signature enforcement lives in the agent,
# which carries the publisher's public key embedded in its binary.
#
# Contract (docs/plugin_developer_guide.md): idempotent, root,
# non-interactive, exit 0 when not applicable.
#
# Usage:  install_agent.sh SITENAME

set -u

SITENAME="${1:-}"
if [ -z "$SITENAME" ]; then
    echo "agent installer: no SITENAME given - skipping" >&2
    exit 0
fi

SITE_ROOT="/var/www/html/${SITENAME}"
PUBLIC_HTML="${SITE_ROOT}/public_html"
SITE_CONFIG="${SITE_ROOT}/config/Globalvars_site.php"
DIST_DIR="${PUBLIC_HTML}/plugins/server_manager/agent_dist"

BINARY_PATH="/usr/local/bin/joinery-agent"
SUPERVISE_PATH="/usr/local/bin/joinery-agent-supervise"
SERVICE_NAME="joinery-agent"
SERVICE_FILE="/etc/systemd/system/joinery-agent.service"
CRON_FILE="/etc/cron.d/joinery-agent"
LOG_FILE="/var/log/joinery-agent.log"
ENV_DIR="/etc/joinery-agent"
ENV_FILE="${ENV_DIR}/joinery-agent.env"
# The projected switch. The setting lives in the site database; this file is its
# one-way shadow, so the keepalive and the agent can honour it without one.
MARKER_FILE="${ENV_DIR}/enabled"

say() { echo "agent installer: $*"; }

[ "$(id -u)" = "0" ] || { say "not running as root - skipping"; exit 0; }
[ -f "$SITE_CONFIG" ] || { say "site not initialised yet - skipping"; exit 0; }
command -v php >/dev/null 2>&1 || { say "php-cli not available - skipping"; exit 0; }

# --- The switch --------------------------------------------------------------
# One setting decides whether this machine runs an agent at all. Read it from
# the site database, which is the only place that survives a container rebuild
# (/etc comes back carrying image defaults; the database is on the config
# volume). The value is deliberately read fresh at every root moment, so
# flipping it and restarting the container is a complete operation.
#
# A database we cannot reach is not a decision: skip, changing nothing, rather
# than reading silence as "off" and stopping a working agent.
read_setting() {
    php -r '
        $config = file_get_contents($argv[1]);
        $val = function ($key) use ($config) {
            return preg_match("/settings\\[.".$key.".\\]\\s*=\\s*.([^\x27\"]*)/", $config, $m) ? $m[1] : "";
        };
        $name = $val("dbname");
        $user = $val("dbusername");
        $pass = $val("dbpassword");
        $host = $val("dbhost") ?: "localhost";
        if ($name === "" || $user === "") { fwrite(STDERR, "no-db-config\n"); exit(3); }
        try {
            $pdo = new PDO("pgsql:host={$host};dbname={$name}", $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $q = $pdo->prepare("SELECT stg_value FROM stg_settings WHERE stg_name = ?");
            $q->execute([$argv[2]]);
            $row = $q->fetch(PDO::FETCH_NUM);
            echo $row === false ? "" : (string)$row[0];
        } catch (Exception $e) {
            fwrite(STDERR, "db-unreachable\n");
            exit(3);
        }
    ' "$SITE_CONFIG" "$1" 2>/dev/null
}

if ! AGENT_ENABLED="$(read_setting agent_enabled)"; then
    say "could not read the agent_enabled setting - leaving this machine as it is"
    exit 0
fi

# Read exactly as the PHP side reads it (admin_management_node_agent_switch_on):
# lowercase, trim the ends only, then match the same four spellings. Two
# readings of one setting is how a machine ends up disagreeing with the page
# that configured it, so this is pinned by installer_contract_test.
AGENT_ENABLED="$(printf '%s' "$AGENT_ENABLED" | tr '[:upper:]' '[:lower:]')"
AGENT_ENABLED="${AGENT_ENABLED#"${AGENT_ENABLED%%[![:space:]]*}"}"
AGENT_ENABLED="${AGENT_ENABLED%"${AGENT_ENABLED##*[![:space:]]}"}"

case "$AGENT_ENABLED" in
    1|true|yes|on) AGENT_ENABLED=1 ;;
    *)             AGENT_ENABLED=0 ;;
esac

# Project it. Root moments are the cover for a machine whose agent is not running
# to project for itself - a container that starts with the switch off must not
# have a keepalive that starts the agent anyway.
mkdir -p "$ENV_DIR"
printf '%s\n' "$AGENT_ENABLED" > "$MARKER_FILE"
chmod 644 "$MARKER_FILE"

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
            echo "# joinery-agent environment (written by install_agent.sh)"
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
# joinery-agent cron keepalive: start the agent if it is not running AND this
# machine is switched on. The marker is read, never the database - the keepalive
# has to be right on a machine whose database is down, which is exactly when a
# wrong answer would matter.
#
# A missing marker reads as on: an agent installed before the marker existed is
# running legitimately, and a keepalive that refused to start it would switch off
# working agents at upgrade.
if [ -f /etc/joinery-agent/enabled ] && [ "$(cat /etc/joinery-agent/enabled 2>/dev/null)" != "1" ]; then
    exit 0
fi
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

# Off means stopped and left stopped. The supervision has to go with it - a
# cron keepalive would restart the agent within the minute, which would read as
# the switch not working. The binary and the agent's identity stay: this is a
# switch, not an uninstall, and deleting the identity would silently end a
# pairing that turning the switch back on is expected to restore.
stop_and_disable_agent() {
    if [ "$INIT_MODE" = "systemd" ]; then
        systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true
        systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
    else
        rm -f "$CRON_FILE"
        pkill -x joinery-agent 2>/dev/null || true
    fi
}

# --- Converge the binary -----------------------------------------------------
# The binary lands on every deployment whether or not the agent is switched on.
# Installing is not running: a machine that ships with the agent present can be
# switched on later without needing the artifact fetched, decompressed and
# verified at that moment, and an operator turning it on gets a service that
# starts rather than one that has to be installed first.
converge_binary() {
    case "$(uname -m)" in
        x86_64)  ARCH="linux-amd64" ;;
        aarch64) ARCH="linux-arm64" ;;
        *) say "unsupported architecture $(uname -m) - no agent for this machine"; return 1 ;;
    esac

    [ -f "${DIST_DIR}/manifest.json" ] || {
        say "this tree ships no agent artifact"
        return 1
    }

    read -r DIST_VERSION DIST_FILE DIST_SHA256 <<EOF
$(php -r '
    $m = json_decode(file_get_contents($argv[1]), true);
    $e = $m["binaries"][$argv[2]] ?? null;
    if (!$m || !$e) { exit(0); }
    echo $m["version"] . " " . $e["file"] . " " . $e["sha256"];
' "${DIST_DIR}/manifest.json" "$ARCH" 2>/dev/null)
EOF

    if [ -z "${DIST_VERSION:-}" ] || [ -z "${DIST_FILE:-}" ]; then
        say "manifest has no usable ${ARCH} entry"
        return 1
    fi

    # Ordering, not equality. This script does first install only — every later
    # version change comes from the agent's own signed self-update — so a node
    # routinely runs a version NEWER than the artifact shipped in this tree.
    # Testing equality treats "newer" and "older" alike and reinstalls the
    # shipped one over a self-updated agent, rolling it back with nothing to say
    # so. Anything at or above the shipped version keeps its binary.
    #
    # JOINERY_AGENT_ALLOW_DOWNGRADE=1 forces the shipped artifact on regardless,
    # for a deliberate rollback.
    CURRENT="$(installed_version)"
    if [ -n "$CURRENT" ] && [ "${JOINERY_AGENT_ALLOW_DOWNGRADE:-0}" != "1" ] \
        && ! version_is_older "$CURRENT" "$DIST_VERSION"; then
        if [ "$CURRENT" = "$DIST_VERSION" ]; then
            say "v${DIST_VERSION} already installed"
        else
            say "v${CURRENT} is newer than the shipped v${DIST_VERSION} - keeping it"
        fi
        return 0
    fi

    say "installing joinery-agent v${DIST_VERSION} (was ${CURRENT:-none}) from shipped artifact"

    STAGE="$(mktemp -d)"
    trap 'rm -rf "$STAGE"' EXIT

    if ! gunzip -c "${DIST_DIR}/${DIST_FILE}" > "${STAGE}/joinery-agent" 2>/dev/null; then
        say "WARNING - could not decompress ${DIST_FILE}; leaving current agent in place"
        return 1
    fi

    ACTUAL_SHA="$(sha256sum "${STAGE}/joinery-agent" | awk '{print $1}')"
    if [ "$ACTUAL_SHA" != "$DIST_SHA256" ]; then
        say "WARNING - sha256 mismatch on ${DIST_FILE}; refusing to install"
        return 1
    fi

    install -m 755 "${STAGE}/joinery-agent" "$BINARY_PATH"
    return 0
}

converge_binary || true
write_env_file

# --- Apply the switch --------------------------------------------------------

if [ "$AGENT_ENABLED" != "1" ]; then
    if agent_running; then
        say "agent_enabled is off - stopping the agent and disabling its supervision"
    else
        # Already down. Converge supervision anyway: a stopped agent with a live
        # cron entry is one minute from being a running one.
        say "agent_enabled is off - agent not running"
    fi
    stop_and_disable_agent
    exit 0
fi

if [ ! -x "$BINARY_PATH" ]; then
    say "WARNING - agent_enabled is on but no agent binary is installed; nothing to start"
    exit 0
fi

ensure_supervision

if agent_running; then
    say "agent_enabled is on - v$(installed_version) already running"
    exit 0
fi

start_agent
sleep 2

if agent_running; then
    say "joinery-agent v$(installed_version) running (${INIT_MODE} supervision)"
else
    say "WARNING - agent installed but not running; check ${LOG_FILE} / journalctl -u ${SERVICE_NAME}"
fi

exit 0
