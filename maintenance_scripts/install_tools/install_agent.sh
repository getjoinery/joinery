#!/usr/bin/env bash
#
# install_agent.sh - install or converge the joinery-agent on this machine from
# the shipped agent_dist artifact, or stop it where it is switched off.
#
# Version: 2.8 - Installs on a machine with NO Joinery site (--siteless), for relays and Docker
#           hosts that the plane manages but that host no deployment: the artifact location
#           becomes an argument (--dist-dir), the run switch is written explicitly rather
#           than read from a database that is not there, and the manifest is read without
#           PHP, which such a machine does not have.
# Version: 2.7 - Defers the swap and the restart while the agent is running a job. An upgrade
#           dispatched to the agent runs THROUGH this script, so restarting the agent here
#           killed the process reporting that job; the agent converges itself afterwards
#           through its own signed self-update instead.
# Version: 2.6 - The keepalive closes descriptors inside sh -c, not inside itself. Closing them
#           from within the script file closed the shell's own copy of that file, so the
#           keepalive stopped before launching and no node whose agent exited came back.
# Version: 2.5 - Accepts an already-resolved site root as an optional second argument, so a
#           site outside /var/www/html is installed where it actually lives. Added as a
#           second argument so old and new copies of this script and its caller interoperate.
# Version: 2.4 - The keepalive closes inherited descriptors before launching the
#                agent. Started from inside an upgrade, it inherited the upgrade's
#                flock on .upgrade.lock and held it for its whole life, so every
#                later upgrade on that node was refused as already running.
#          2.3 - Also detect a process already running a REPLACED binary
#                (/proc/PID/exe reading as deleted) and restart it. 2.2 stopped the
#                state arising; this cures a machine already in it.
#          2.2 - A freshly installed binary forces a restart. "Already running" was
#                allowed to skip the start, which left the new binary on disk and the
#                OLD one in memory — permanently, whenever the running agent cannot
#                self-update (as during an artifact move, when its compiled-in path
#                no longer exists).
#          2.1 - The binary lands on every deployment; agent_enabled decides only
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
# Usage:  install_agent.sh SITENAME [SITE_ROOT]
#         install_agent.sh --siteless --dist-dir=DIR [--enable]
#
# The second form is for a machine the management node manages that hosts no
# Joinery deployment — a mail relay, a Docker host (spec A13). Three things
# differ, and each is an argument rather than an inference:
#
#   --siteless   is EXPLICIT and never guessed. A missing site config keeps its
#                existing meaning, "not my machine, exit 0" — the two DNS
#                resolvers rely on that, and so does a node whose config is
#                briefly absent mid-upgrade or mid-restore. Reading a momentary
#                absence as "this must be a relay" would install a root service
#                on a machine that never asked for one.
#   --dist-dir   because a machine with no site has no public_html/agent_dist
#                for a release to have delivered into. On a first install the
#                operator is the delivery; the artifact's sha256 is still
#                checked here, and publisher-signature enforcement begins at
#                the agent's first self-update, against the key baked into the
#                binary. Same trust model as any fresh box, stated above.
#   --enable     because the run switch lives in a settings table this machine
#                does not have. It is written here explicitly, OFF unless asked
#                for: a missing marker reads as ON everywhere else, which is
#                right for its one situation (an upgrade over an agent older
#                than the marker) and cannot arise on a machine being installed
#                for the first time.

set -u

SITELESS=0
DIST_DIR_ARG=""
SITELESS_ENABLE=0

# Flags are pulled out and the positionals left in order, so every existing
# caller — the Dockerfile CMD, install.sh, upgrade.php, _plugin_installers_start.sh —
# passes exactly what it always did and lands in exactly the same branch.
POSITIONAL_1=""
POSITIONAL_2=""
POSITIONAL_SEEN=0
for arg in "$@"; do
    case "$arg" in
        --siteless)   SITELESS=1 ;;
        --enable)     SITELESS_ENABLE=1 ;;
        --dist-dir=*) DIST_DIR_ARG="${arg#--dist-dir=}" ;;
        --*)
            echo "agent installer: unrecognised option $arg" >&2
            exit 1
            ;;
        *)
            POSITIONAL_SEEN=$((POSITIONAL_SEEN + 1))
            if [ "$POSITIONAL_SEEN" = "1" ]; then POSITIONAL_1="$arg"; fi
            if [ "$POSITIONAL_SEEN" = "2" ]; then POSITIONAL_2="$arg"; fi
            ;;
    esac
done

SITENAME="$POSITIONAL_1"
# Optional second argument: the site root the caller already resolved.
#
# Added as a SECOND argument rather than by reinterpreting the first, because
# this file and its caller update independently during an upgrade and both
# mixtures have to work. An old runner passing only a sitename still lands in
# the branch below; an old copy of THIS script, handed the new second argument,
# ignores it and behaves exactly as it did. Reinterpreting argument one as a
# path would have broken that second case — the old script would have built
# /var/www/html//opt/site and skipped.
SITE_ROOT_ARG="$POSITIONAL_2"

if [ "$SITELESS" = "1" ]; then
    if [ -z "$DIST_DIR_ARG" ]; then
        echo "agent installer: --siteless needs --dist-dir=DIR naming the unpacked agent artifact" >&2
        exit 1
    fi
    if [ ! -f "${DIST_DIR_ARG}/manifest.json" ]; then
        echo "agent installer: no manifest.json in ${DIST_DIR_ARG} - that is not an unpacked agent artifact" >&2
        exit 1
    fi
    # No site, so none of the site-derived paths exist. They are left EMPTY
    # rather than pointed somewhere plausible: every later use is guarded on
    # SITELESS, and a plausible-looking wrong path is worse than no path.
    SITE_ROOT=""
    PUBLIC_HTML=""
    SITE_CONFIG=""
    DIST_DIR="$DIST_DIR_ARG"
elif [ -z "$SITENAME" ] && [ -z "$SITE_ROOT_ARG" ]; then
    echo "agent installer: no SITENAME given - skipping" >&2
    exit 0
elif [ -n "$SITE_ROOT_ARG" ] && [ -d "$SITE_ROOT_ARG" ]; then
    # The caller derived it from its own location, so it is right even when the
    # site does not live under /var/www/html. Without this the runner resolves
    # an off-convention site correctly and then this script throws the answer
    # away and rebuilds the convention path.
    SITE_ROOT="${SITE_ROOT_ARG}"
    [ -n "$SITENAME" ] || SITENAME="$(basename "$SITE_ROOT")"
else
    SITE_ROOT="/var/www/html/${SITENAME}"
fi
if [ "$SITELESS" != "1" ]; then
    PUBLIC_HTML="${SITE_ROOT}/public_html"
    SITE_CONFIG="${SITE_ROOT}/config/Globalvars_site.php"
    DIST_DIR="${PUBLIC_HTML}/agent_dist"
fi

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
# Written by the agent for exactly as long as it is running a job, and read
# here to decide whether restarting it right now would destroy work. Pinned
# identically in the agent's jobmarker.go.
JOB_MARKER_FILE="${ENV_DIR}/job-running"

say() { echo "agent installer: $*"; }

[ "$(id -u)" = "0" ] || { say "not running as root - skipping"; exit 0; }
if [ "$SITELESS" != "1" ]; then
    [ -f "$SITE_CONFIG" ] || { say "site not initialised yet - skipping"; exit 0; }
    # Only the site path needs PHP: it reads the switch out of the site
    # database through PDO. A siteless machine reads no database and parses its
    # manifest without PHP, because a mail relay has none — provision_relay.sh
    # installs postfix, opendkim, opendmarc, wireguard, ufw, rspamd and
    # golang-go, and PHP is not among them. Requiring it here would have made
    # every siteless install exit 0 having done nothing, which reads as success.
    command -v php >/dev/null 2>&1 || { say "php-cli not available - skipping"; exit 0; }
fi

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

if [ "$SITELESS" = "1" ]; then
    # No settings table to read, so the switch is stated rather than projected.
    # OFF unless --enable: markerSaysRun() treats a MISSING marker as on, which
    # is correct for the single case it exists for — an upgrade over an agent
    # installed before the marker existed — and would be the wrong default to
    # inherit on a machine being installed for the first time, where it would
    # start a root service nobody asked to start (A9).
    AGENT_ENABLED="$SITELESS_ENABLE"
elif ! AGENT_ENABLED="$(read_setting agent_enabled)"; then
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
# machine is switched on.
#
# Every descriptor above stdio is closed before the agent is launched, and that
# is not hygiene, it is a bug fix. This script runs from inside an upgrade — the
# upgrade calls the host installers, which call this — and the upgrade process is
# holding an flock on the site's .upgrade.lock. A child inherits open descriptors,
# and an flock belongs to the open file description, so the agent inherits the
# LOCK. The agent then outlives the upgrade by design, and every later upgrade on
# that node is refused with "Another upgrade is already running" by a lock whose
# holder is the agent itself. Observed on two container nodes at 0.8.345; the
# nodes under systemd were unaffected because systemd starts the agent with its
# own descriptors. The marker is read, never the database - the keepalive
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
    # The descriptor closing happens in a shell that reads its commands from an
    # ARGUMENT, never from a file, and that detail is the whole fix. A shell
    # running a script FILE keeps that file open on a descriptor of its own;
    # closing every descriptor above stdio from inside the script therefore
    # closes the shell's own copy of the script, and it stops silently at that
    # line without ever reaching the launch below. Both restart paths ran
    # through here - the cron keepalive and the installer's own start_agent - so
    # a node whose agent exited was never restarted by anything. Observed on
    # joinerydemo at 0.8.347: the agent self-updated to 1.7.0, exited as
    # designed, and stayed down. sh -c has no script descriptor to lose.
    nohup sh -c 'for f in /proc/$$/fd/*; do n=${f##*/}; case "$n" in 0|1|2) continue;; esac; eval "exec $n>&-" 2>/dev/null || true; done; exec /usr/local/bin/joinery-agent' >> /var/log/joinery-agent.log 2>&1 < /dev/null &
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

# Is the agent in the middle of running a job right now?
#
# This exists because of one circular moment. The management node dispatches an
# upgrade to a node's agent; the agent runs upgrade.php; upgrade.php runs the
# host installers; the host installers run THIS SCRIPT, whose job is to converge
# the agent binary and restart the agent onto it. Restarting it here kills the
# process that is running the job, before it can report the outcome. The plane
# sees a claim that never came back, requeues it, and the node upgrades again —
# having already succeeded.
#
# So: while a job is running, this script converges everything except the agent
# itself, and leaves that to the agent's own self-update, which was built for
# this exact situation. It verifies the artifact's publisher signature against
# the key baked into the running binary, keeps the previous binary as a backup,
# refuses to swap while a job is in progress, and rolls back a version that never
# reaches a healthy start. It looks every 60 seconds, so the swap happens about a
# minute after the job that delivered it finishes.
#
# Leaving the BINARY alone as well as the restart is deliberate, not laziness.
# The agent's updater takes its rollback backup by copying whatever file is at
# the install path; if this script had already written the new binary there, the
# backup would be a copy of the new version and the rollback would restore the
# thing it was rolling back from.
#
# A FILE, not an environment variable, because the signal has to survive
# upgrade.php's shell-outs and upgrade.php runs the host installers through
# `sudo -n` on any node whose deploy user is not root. sudo strips the
# environment — the same reason that call site already loses PGPASSWORD.
#
# It cannot wedge a node. The marker names the pid that wrote it, so an agent
# killed mid-job leaves one pointing at a process that is gone; that reads as
# stale, is removed, and this script converges normally. There is no timeout to
# tune and nothing an operator has to remember to clean up.
AGENT_JOB_ID=""
agent_job_in_progress() {
    [ -f "$JOB_MARKER_FILE" ] || return 1

    JOB_PID="$(sed -n '1p' "$JOB_MARKER_FILE" 2>/dev/null | tr -dc '0-9')"
    AGENT_JOB_ID="$(sed -n '2p' "$JOB_MARKER_FILE" 2>/dev/null | tr -dc '0-9')"

    # No readable pid, a pid that no longer exists, or a pid that has been
    # recycled by some other program: in every case the marker is debris.
    if [ -n "$JOB_PID" ] \
        && [ -r "/proc/${JOB_PID}/comm" ] \
        && [ "$(cat "/proc/${JOB_PID}/comm" 2>/dev/null)" = "joinery-agent" ]; then
        return 0
    fi

    say "clearing a stale job marker (no joinery-agent running as pid ${JOB_PID:-?})"
    rm -f "$JOB_MARKER_FILE"
    AGENT_JOB_ID=""
    return 1
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

# Is the process that is running actually running the binary that is on disk?
#
# Replacing the file does not replace a live process: install(1) puts a new inode
# at the path and the running agent stays attached to the old, now-unlinked one,
# which /proc/PID/exe reports with a " (deleted)" suffix. That is the exact
# signature of "new binary on disk, old one in memory".
#
# BINARY_INSTALLED catches this within a single run. This catches it ACROSS runs
# — a machine left mismatched by an earlier install, or by a self-update that
# wrote the file and did not exit — which is the difference between preventing
# the state and curing it. The dev plane was in it, and a second installer run
# could not tell, because from the file's point of view everything agreed.
running_is_stale() {
    STALE_PID="$(pgrep -x joinery-agent 2>/dev/null | head -1)"
    [ -n "$STALE_PID" ] || return 1
    STALE_EXE="$(readlink "/proc/${STALE_PID}/exe" 2>/dev/null)" || return 1
    case "$STALE_EXE" in
        *"(deleted)") return 0 ;;
        *) return 1 ;;
    esac
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
# Set by converge_binary when it actually replaces the binary on disk. A fresh
# binary MUST be started, even though something is already running — what is
# running is the OLD one, and the file changing under a live process does not
# change the process.
BINARY_INSTALLED=0

# Read version, filename and sha256 for one architecture out of the shipped
# manifest, printed as three space-separated fields. Empty output means the
# manifest names nothing usable for this machine.
#
# PHP where there is PHP, awk where there is not. A mail relay has no PHP at
# all, and the alternative to reading the manifest without it would be dropping
# the sha256 check on exactly the machines being installed by hand — trading a
# real integrity guarantee for the convenience of an interpreter.
#
# THE AWK READER IS DELIBERATELY NOT LINE-BASED. A manifest is machine
# generated and its whitespace is not a contract: pretty-printed today, and one
# line if the generator ever changes. A line-based reader on a one-line manifest
# matches every architecture's "file" key in turn and keeps the LAST, so asking
# for amd64 returns the arm64 filename — a wrong answer that looks like an
# answer. Locating the architecture's object by index and cutting it at its own
# closing brace is independent of how the file is laid out. Verified to agree
# with the PHP reader field-for-field on both architectures, and to return
# nothing rather than a neighbouring block for an architecture the manifest does
# not carry.
read_manifest_entry() {
    if command -v php >/dev/null 2>&1; then
        php -r '
            $m = json_decode(file_get_contents($argv[1]), true);
            $e = $m["binaries"][$argv[2]] ?? null;
            if (!$m || !$e) { exit(0); }
            echo $m["version"] . " " . $e["file"] . " " . $e["sha256"];
        ' "$1" "$2" 2>/dev/null
        return
    fi

    awk -v arch="$2" '
        BEGIN { RS = "\004" }
        {
            doc = $0
            if (match(doc, /"version"[ \t\r\n]*:[ \t\r\n]*"[^"]*"/)) {
                v = substr(doc, RSTART, RLENGTH)
                sub(/^"version"[ \t\r\n]*:[ \t\r\n]*"/, "", v); sub(/"$/, "", v)
            }
            key = "\"" arch "\""
            at = index(doc, key)
            if (at == 0) { exit 0 }
            rest = substr(doc, at + length(key))
            end = index(rest, "}")
            if (end == 0) { exit 0 }
            blk = substr(rest, 1, end)
            if (match(blk, /"file"[ \t\r\n]*:[ \t\r\n]*"[^"]*"/)) {
                f = substr(blk, RSTART, RLENGTH)
                sub(/^"file"[ \t\r\n]*:[ \t\r\n]*"/, "", f); sub(/"$/, "", f)
            }
            if (match(blk, /"sha256"[ \t\r\n]*:[ \t\r\n]*"[^"]*"/)) {
                h = substr(blk, RSTART, RLENGTH)
                sub(/^"sha256"[ \t\r\n]*:[ \t\r\n]*"/, "", h); sub(/"$/, "", h)
            }
            if (v != "" && f != "" && h != "") { print v, f, h }
        }
    ' "$1" 2>/dev/null
}

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
$(read_manifest_entry "${DIST_DIR}/manifest.json" "$ARCH")
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
    BINARY_INSTALLED=1
    return 0
}

# Decided once, before anything acts on it, so the binary pass and the restart
# pass cannot disagree about whether a job was running.
DEFER_TO_AGENT=0
if agent_job_in_progress; then
    DEFER_TO_AGENT=1
fi

if [ "$DEFER_TO_AGENT" = "1" ]; then
    say "agent job #${AGENT_JOB_ID:-?} is running - not touching the agent binary; the agent's own signed self-update will take it"
else
    converge_binary || true
fi
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

# Supervision above is safe to converge mid-job: it writes a unit file or a cron
# entry and starts nothing. The restart below is not, so it stops here.
#
# Said out loud rather than falling through the "already running" branch below,
# which would be the correct action described by the wrong sentence — and an
# operator reading an upgrade transcript needs to see that the new agent is
# staged and pending, not that there was nothing to do.
if [ "$DEFER_TO_AGENT" = "1" ]; then
    say "new agent artifact staged in ${DIST_DIR}, restart deferred to agent - v$(installed_version) keeps running job #${AGENT_JOB_ID:-?} and will self-update within a minute of finishing it"
    exit 0
fi

# "Already running" is only a reason to stop if the running process is running
# the binary we just converged. When a new one went in, the live process is the
# previous version and has to be replaced.
#
# This is not a corner case, it is the ONLY path during an artifact move: the old
# agent's compiled-in artifact directory no longer exists, so it can never
# self-update out of the situation. Skipping the restart here would leave a
# machine with a new binary on disk and an old one in memory, indefinitely — and
# it did exactly that on this plane before this branch existed.
if agent_running && [ "$BINARY_INSTALLED" = "0" ] && ! running_is_stale; then
    say "agent_enabled is on - v$(installed_version) already running"
    exit 0
fi

if agent_running; then
    if [ "$BINARY_INSTALLED" = "1" ]; then
        say "restarting into the newly installed v$(installed_version)"
    else
        say "the running process is an older binary that was replaced on disk - restarting into v$(installed_version)"
    fi
fi

start_agent
sleep 2

if agent_running; then
    say "joinery-agent v$(installed_version) running (${INIT_MODE} supervision)"
else
    say "WARNING - agent installed but not running; check ${LOG_FILE} / journalctl -u ${SERVICE_NAME}"
fi

exit 0
