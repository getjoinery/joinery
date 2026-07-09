#!/usr/bin/env bash
#
# _plugin_installers_start.sh - run every active plugin's host installer.
#
# Version: 1.1 - Also installs declared PHP extensions when running as root.
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
# Usage:  _plugin_installers_start.sh SITENAME
#         (PGPASSWORD is expected in the environment when the database
#          requires it - the container CMD exports it before calling this.)

set -u

SITENAME="${1:-}"
if [[ -z "${SITENAME}" ]]; then
    echo "plugin installers: no SITENAME given - skipping" >&2
    exit 0
fi

SITE_ROOT="/var/www/html/${SITENAME}"
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

# --- Declared PHP extensions (root only) -------------------------------------
# Covers plugins installed after the site image was built, whose
# requires.extensions the image-build resolver never saw. Cheap when nothing
# is missing: dpkg checks only, no apt update.
RESOLVER="${PUBLIC_HTML}/utils/list_dependencies.php"
if [[ -f "${RESOLVER}" ]] && [[ "$(id -u)" == "0" ]] && command -v php >/dev/null 2>&1; then
    APT_UPDATED=0
    while read -r SPEC; do
        [[ -n "${SPEC}" ]] || continue
        PRIMARY="${SPEC%%|*}"
        FALLBACK="${SPEC##*|}"
        if dpkg -s "${PRIMARY}" >/dev/null 2>&1 || dpkg -s "${FALLBACK}" >/dev/null 2>&1; then
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

# The database is the only persistent signal of which plugins are active:
# after a rebuild /etc carries base defaults, but the database (on the config
# volume) still knows.
DBNAME="$(grep -oP "settings\['dbname'\]\s*=\s*'\K[^']+" "${CONFIG_FILE}" 2>/dev/null | head -1 || true)"
if [[ -z "${DBNAME}" ]]; then
    echo "plugin installers: could not read dbname from site config - skipping"
    exit 0
fi

ACTIVE_PLUGINS="$(psql -U postgres -d "${DBNAME}" -tAqc \
    "SELECT plg_name FROM plg_plugins WHERE plg_status = 'active'" 2>/dev/null || true)"
if [[ -z "${ACTIVE_PLUGINS}" ]]; then
    echo "plugin installers: no active plugins found (or database unreachable) - skipping"
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
    fi
done

exit 0
