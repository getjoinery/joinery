#!/usr/bin/env bash
#
# _plugin_installers_start.sh - run the platform's host installers: core's
# first, then every active plugin's.
#
# Version: 1.5 - The host converger (specs/host_converger.md): --when-changed runs
#                the installers only when the deployed release, the runner or
#                the set of installers changed since the stamp in cache/, or
#                once a day; every run records cache/host_converger.last for
#                the admin notice. --site-root=DIR names the site for a test.
#                install_host_converger.sh is the third core installer.
#          1.4 - The parser jail's installer is the second core installer: the
#                launcher belongs on every Joinery instance, and this is the one
#                root moment a node has (specs/parser_jail.md).
#          1.3 - Derives its own site root and reads its own database
#                credentials. It did neither, and under the run_plugin_installers
#                primitive - which passes no argument and inherits no environment -
#                each gap alone produced a clean-looking exit 0: with no SITENAME
#                it skipped outright, and with no PGPASSWORD in the environment the
#                plugin query failed and its empty output was indistinguishable
#                from a site that simply has no active plugins. The site root is
#                two levels above this file, and the credentials are in the site
#                config, read the way install_agent.sh already reads it. SITENAME
#                stays an optional argument, so the Dockerfile CMD, install.sh and
#                upgrade.php callers are unchanged.
#          1.2 - Runs core's own host installers before the plugin loop. The
#                joinery-agent is the first of them: it belongs on every
#                Joinery instance, so gating it on a plugin being active meant
#                it never reached a managed node at all — only management nodes,
#                where server_manager happens to be turned on. What the agent
#                does on a given machine is decided by the agent_enabled
#                setting, which the installer reads (specs/agent_on_node_architecture.md).
#                1.1 - Also installs declared PHP extensions when running as root.
#                A plugin uploaded/installed AFTER the site image was built can
#                declare requires.extensions the image never resolved; without
#                this step its only install moment would be the next code
#                upgrade. Container start is a root moment that precedes
#                apache, so newly installed extensions are picked up.
#                1.0 - Generalized from _mail_stack_start.sh (spec
#                plugin_dependency_installation). Any plugin may declare a
#                `host_installer` path in its plugin.json; this script runs
#                each active plugin's installer at the root moments that have
#                no systemd: container start (Dockerfile CMD), site build
#                (install.sh), and node upgrade (upgrade.php).
#
# Contract for host_installer scripts (see docs/plugin_developer_guide.md):
# idempotent (this runs on EVERY container start), root, non-interactive,
# exit 0 when not-applicable.
#
# It is deliberately fail-safe - plugin absent, plugin inactive, database
# unreachable, or an installer failure all exit 0, so a broken installer can
# never block the container from starting.
#
# Usage:  _plugin_installers_start.sh [--when-changed] [--site-root=DIR] [SITENAME] [SITE_ROOT]
#         All optional. SITENAME names a site OTHER than the one this copy
#         of the script was delivered in; with no argument the script works on
#         its own site. Nothing is required in the environment - the database
#         credentials are read from the site config, not inherited.
#         --when-changed is the host converger's mode: run nothing unless the
#         stamp says something changed (or is a day old). --site-root=DIR is
#         the explicit site, for a gate that runs this against a temp tree.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

WHEN_CHANGED=0
EXPLICIT_ROOT=""
POSITIONAL=()
for arg in "$@"; do
    case "${arg}" in
        --when-changed) WHEN_CHANGED=1 ;;
        --site-root=*)  EXPLICIT_ROOT="${arg#--site-root=}" ;;
        *)              POSITIONAL+=("${arg}") ;;
    esac
done
set -- "${POSITIONAL[@]+"${POSITIONAL[@]}"}"

# Where this file LIVES is the site it belongs to: it ships inside the tree, at
# {site root}/maintenance_scripts/install_tools/. Deriving the root instead of
# being told it is what lets the script run with no arguments at all - which is
# how the run_plugin_installers primitive invokes it - and it retires the
# hardcoded /var/www/html, which is wrong on any node installed elsewhere.
DERIVED_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

# An explicit SITENAME keeps its historical meaning wherever that meaning still
# resolves, so no existing invocation changes on a standard box. It matters that
# the argument wins there: install.sh runs this from the SOURCE tree it is
# installing FROM, which can carry the same basename as the site it is building,
# and deriving would then converge the installers on the build directory instead
# of the installed site. The derived root is for the two cases the convention
# does not cover - no argument at all (how the run_plugin_installers primitive
# invokes it), and a node installed somewhere other than /var/www/html.
SITENAME="${1:-}"
if [[ -n "${EXPLICIT_ROOT}" ]]; then
    SITE_ROOT="${EXPLICIT_ROOT}"
elif [[ -n "${2:-}" && -d "${2}" ]]; then
    # The resolved root, from a caller that already knows it (the converger's
    # unit, install.sh on an off-convention site).
    SITE_ROOT="${2}"
elif [[ -z "${SITENAME}" ]]; then
    SITE_ROOT="${DERIVED_ROOT}"
elif [[ -d "/var/www/html/${SITENAME}" ]]; then
    SITE_ROOT="/var/www/html/${SITENAME}"
elif [[ "$(basename "${DERIVED_ROOT}")" == "${SITENAME}" ]]; then
    # The named site is the tree this copy ships in, installed off the
    # convention. Answering "site not initialised" here would be a lie about a
    # site that is sitting around this very script.
    SITE_ROOT="${DERIVED_ROOT}"
else
    # Nothing to work with but the convention. Keep the old answer, and with it
    # the old, legible "site not initialised yet" skip.
    SITE_ROOT="/var/www/html/${SITENAME}"
fi
SITENAME="$(basename "${SITE_ROOT}")"

PUBLIC_HTML="${SITE_ROOT}/public_html"
CONFIG_FILE="${SITE_ROOT}/config/Globalvars_site.php"

if [[ ! -d "${PUBLIC_HTML}/plugins" ]]; then
    echo "plugin installers: no plugins directory - skipping"
    exit 0
fi
if [[ ! -f "${CONFIG_FILE}" ]]; then
    echo "plugin installers: site not initialised yet - skipping"
    exit 0
fi

# --- The host converger's stamp (--when-changed) ------------------------------
# What a run converges is a function of the deployed release, this runner and
# the installers it ships, and which plugins are active. Hash those; when the
# hash matches the last run's and that run is under a day old, there is
# nothing to do and the tick costs a few file reads. The stamp and the record
# of the last run live in cache/, root-owned, readable by the site so the
# admin notice and the health check can say when the converger last ran.
STAMP_FILE="${SITE_ROOT}/cache/host_converger.stamp"
LAST_FILE="${SITE_ROOT}/cache/host_converger.last"
CONVERGE_MAX_AGE=86400

converge_hash() {
    {
        cat "${PUBLIC_HTML}/VERSION" 2>/dev/null
        cat "${SCRIPT_DIR}/_plugin_installers_start.sh" "${SCRIPT_DIR}"/install_*.sh 2>/dev/null
        for m in "${PUBLIC_HTML}"/plugins/*/plugin.json; do [[ -f "${m}" ]] && cat "${m}"; done
        echo "${ACTIVE_PLUGINS_FOR_HASH:-}"
    } | sha256sum | cut -d' ' -f1
}

record_last() {
    mkdir -p "${SITE_ROOT}/cache" 2>/dev/null || true
    printf '%s %s\n' "$(date -u +%s)" "$1" > "${LAST_FILE}.tmp" 2>/dev/null && chmod 644 "${LAST_FILE}.tmp" 2>/dev/null && mv -f "${LAST_FILE}.tmp" "${LAST_FILE}" 2>/dev/null || true
}

# --- Declared PHP extensions (root only) -------------------------------------
# Covers plugins installed after the site image was built, whose
# requires.extensions the image-build resolver never saw. Cheap when nothing
# is missing: dpkg checks only, no apt update.
#
# The check is the package's Status, not whether dpkg has heard of it: `dpkg -s`
# exits 0 for a removed-but-not-purged package whose files are gone, so a
# name-only test silently skips reinstalling an extension that is not there.
pkg_installed() {
    [[ -n "${1:-}" ]] || return 1
    dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q '^install ok installed$'
}

RESOLVER="${PUBLIC_HTML}/utils/list_dependencies.php"
if [[ -f "${RESOLVER}" ]] && [[ "$(id -u)" == "0" ]] && command -v php >/dev/null 2>&1; then
    APT_UPDATED=0
    while read -r SPEC; do
        [[ -n "${SPEC}" ]] || continue
        PRIMARY="${SPEC%%|*}"
        FALLBACK="${SPEC##*|}"
        if pkg_installed "${PRIMARY}" || pkg_installed "${FALLBACK}"; then
            continue
        fi
        if [[ "${APT_UPDATED}" == "0" ]]; then
            apt-get update -qq >/dev/null 2>&1 || true
            APT_UPDATED=1
        fi
        if apt-get install -y "${PRIMARY}" >/dev/null 2>&1 || apt-get install -y "${FALLBACK}" >/dev/null 2>&1; then
            echo "plugin installers: installed declared extension package ${PRIMARY}"
        else
            echo "plugin installers: WARNING - could not install ${PRIMARY} (or ${FALLBACK})" >&2
        fi
    done < <(php "${RESOLVER}" --apt 2>/dev/null || true)
fi

read_active_plugins() {
    php -r '
        $config = file_get_contents($argv[1]);
        $val = function ($key) use ($config) {
            return preg_match("/settings\[.".$key.".\]\s*=\s*.([^\x27\"]*)/", $config, $m) ? $m[1] : "";
        };
        $name = $val("dbname");
        $user = $val("dbusername");
        $pass = $val("dbpassword");
        $host = $val("dbhost") ?: "localhost";
        if ($name === "" || $user === "") { fwrite(STDERR, "no-db-config\n"); exit(3); }
        try {
            $pdo = new PDO("pgsql:host={$host};dbname={$name}", $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10]);
            $q = $pdo->prepare("SELECT plg_name FROM plg_plugins WHERE plg_status = ?");
            $q->execute(["active"]);
            foreach ($q->fetchAll(PDO::FETCH_NUM) as $row) {
                echo $row[0] . "\n";
            }
        } catch (Exception $e) {
            fwrite(STDERR, "db-unreachable\n");
            exit(3);
        }
    ' "${CONFIG_FILE}" 2>/dev/null
}

# --- Converge only when something changed (--when-changed) -------------------
if [[ "${WHEN_CHANGED}" == "1" ]]; then
    ACTIVE_PLUGINS_FOR_HASH="$(read_active_plugins 2>/dev/null || echo "db-unreachable")"
    CONVERGE_NOW_HASH="$(converge_hash)"
    last_hash="$(cat "${STAMP_FILE}" 2>/dev/null || true)"
    last_time="$(cut -d' ' -f1 "${LAST_FILE}" 2>/dev/null || echo 0)"
    now="$(date -u +%s)"
    if [[ "${last_hash}" == "${CONVERGE_NOW_HASH}" ]] && (( now - ${last_time:-0} < CONVERGE_MAX_AGE )); then
        # Nothing changed and the last run is fresh: this tick costs nothing.
        exit 0
    fi
    echo "host converger: $(date -u '+%Y-%m-%d %H:%M:%S') converging ${SITENAME} ($([[ "${last_hash}" == "${CONVERGE_NOW_HASH}" ]] && echo 'daily' || echo 'release or installers changed'))"
    # Written up front so a run that dies mid-way is not retried every tick
    # for a day; the record on exit says what happened.
    printf '%s\n' "${CONVERGE_NOW_HASH}" > "${STAMP_FILE}" 2>/dev/null || true
    record_last "running"
    CONVERGE_OUTCOME="converged"
    trap 'record_last "${CONVERGE_OUTCOME}"' EXIT
fi

# --- Core host installers ----------------------------------------------------
# Core's own installers run before any plugin's, and unconditionally: nothing
# about them is a plugin's business. Each is idempotent and decides for itself
# whether it applies here, the same contract plugin installers work under.
CORE_INSTALLERS="install_agent.sh install_parser_jail.sh install_host_converger.sh"

for CORE_INSTALLER in ${CORE_INSTALLERS}; do
    CORE_PATH="${SCRIPT_DIR}/${CORE_INSTALLER}"
    if [[ ! -f "${CORE_PATH}" ]]; then
        echo "core installers: ${CORE_INSTALLER} missing - skipping" >&2
        continue
    fi
    echo "core installers: running ${CORE_INSTALLER}"
    # Both: the name for an older core installer that only reads argument one,
    # the resolved root for one that can use it. An off-convention site is only
    # correct if this second value survives the call.
    if bash "${CORE_PATH}" "${SITENAME}" "${SITE_ROOT}"; then
        echo "core installers: ${CORE_INSTALLER}: ok"
    else
        echo "core installers: WARNING - ${CORE_INSTALLER} failed" >&2
        CONVERGE_OUTCOME="installer-failed"
    fi
done

# The database is the only persistent signal of which plugins are active:
# after a rebuild /etc carries base defaults, but the database (on the config
# volume) still knows.
#
# Its credentials come out of the site config, read exactly the way
# install_agent.sh reads it, and the connection is PDO rather than psql. This
# script inherits NOTHING. It used to grep out the database name and then run
# `psql -U postgres` on a PGPASSWORD it hoped was in the environment: true under
# the container CMD, false under every other caller, and the failure was silent
# because an authentication error and a site with no active plugins both produce
# no output. PDO also drops the assumption that a psql client is installed at
# all, which on a node whose database is elsewhere it need not be.
#
# The two outcomes are now reported separately. "Could not reach the database"
# and "there is nothing to run" are different facts about a machine, and reading
# the first as the second is how a partial run looks like a clean one.

if ! command -v php >/dev/null 2>&1; then
    echo "plugin installers: php-cli not available - skipping" >&2
    CONVERGE_OUTCOME="no-php"
    exit 0
fi

if ! ACTIVE_PLUGINS="$(read_active_plugins)"; then
    echo "plugin installers: could not read the site database - skipping" >&2
    CONVERGE_OUTCOME="db-unreachable"
    # Transient: drop the stamp so the next tick tries again rather than
    # waiting a day with the plugin installers unrun.
    [[ "${WHEN_CHANGED}" == "1" ]] && rm -f "${STAMP_FILE}"
    exit 0
fi
if [[ -z "${ACTIVE_PLUGINS}" ]]; then
    echo "plugin installers: no active plugins - nothing to run"
    exit 0
fi

for PLUGIN in ${ACTIVE_PLUGINS}; do
    MANIFEST="${PUBLIC_HTML}/plugins/${PLUGIN}/plugin.json"
    [[ -f "${MANIFEST}" ]] || continue

    # Extract the host_installer path; php-cli is always present on a
    # Joinery host and is the only reliable JSON parser we can assume.
    INSTALLER_REL="$(php -r '
        $m = json_decode(file_get_contents($argv[1]), true);
        echo isset($m["host_installer"]) && is_string($m["host_installer"]) ? $m["host_installer"] : "";
    ' "${MANIFEST}" 2>/dev/null || true)"
    [[ -n "${INSTALLER_REL}" ]] || continue

    INSTALLER="${PUBLIC_HTML}/plugins/${PLUGIN}/${INSTALLER_REL}"

    # Refuse path escapes (host_installer must stay inside the plugin dir).
    case "$(realpath -m "${INSTALLER}")" in
        "$(realpath -m "${PUBLIC_HTML}/plugins/${PLUGIN}")"/*) : ;;
        *)
            echo "plugin installers: ${PLUGIN}: host_installer escapes plugin directory - refused" >&2
            continue
            ;;
    esac

    if [[ ! -f "${INSTALLER}" ]]; then
        echo "plugin installers: ${PLUGIN}: declared installer missing (${INSTALLER_REL}) - skipping" >&2
        continue
    fi

    echo "plugin installers: ${PLUGIN}: running ${INSTALLER_REL}"
    if bash "${INSTALLER}"; then
        echo "plugin installers: ${PLUGIN}: ok"
    else
        echo "plugin installers: WARNING - ${PLUGIN} installer failed; its services may be down." >&2
        CONVERGE_OUTCOME="installer-failed"
    fi
done

exit 0
