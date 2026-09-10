#!/usr/bin/env bash
#
# install_parser_jail.sh - install or converge the parser jail's launcher on
# this machine (specs/parser_jail.md, docs/document_text.md).
#
# Version: 1.0
#
# What it leaves behind, every time it runs:
#   - a system user `joinery-jail` with no shell, no home and no groups;
#   - /usr/local/sbin/joinery-jail, mode 4755 root:root, byte-identical to the
#     prebuilt joinery_jail/bin/joinery-jail-<uname -m> shipped beside this
#     script (nothing is ever compiled on a node);
#   - read access for that user to the code tree, the vendor directory and the
#     cache — as an ACL, and only where the user cannot already read them (a
#     container's 755/644 tree needs none). The config directory, uploads and
#     everything else stay exactly as unreadable as they were.
#
# Runs at the platform's root moments — container start, site build, node
# upgrade, the Run Plugin Installers action — through _plugin_installers_start.sh,
# and by hand on a box those never reached:
#
#     sudo bash /var/www/html/SITE/maintenance_scripts/install_tools/install_parser_jail.sh
#
# Contract (docs/plugin_developer_guide.md): idempotent, root, non-interactive,
# exit 0 when not applicable. When the read grant cannot be made, the launcher
# is NOT installed: DocumentText keeps its advisory fallback (parsing as the web
# user, reported in VaultHealth and the admin notice) rather than gaining a jail
# whose every extraction fails.
#
# Usage:  install_parser_jail.sh [SITENAME] [SITE_ROOT]

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
JAIL_USER="joinery-jail"
DEST="/usr/local/sbin/joinery-jail"

# The site is where this copy of the script ships, unless the caller says
# otherwise (install.sh runs from the source tree it is installing FROM).
SITE_ROOT="${2:-}"
if [[ -z "${SITE_ROOT}" ]]; then
    SITENAME="${1:-}"
    if [[ -n "${SITENAME}" && -d "/var/www/html/${SITENAME}" ]]; then
        SITE_ROOT="/var/www/html/${SITENAME}"
    else
        SITE_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
    fi
fi

if [[ "$(id -u)" != "0" ]]; then
    echo "parser jail: not root - skipping (run: sudo bash ${SCRIPT_DIR}/install_parser_jail.sh)"
    exit 0
fi

MACHINE="$(uname -m)"
SRC="${SCRIPT_DIR}/joinery_jail/bin/joinery-jail-${MACHINE}"
if [[ ! -f "${SRC}" ]]; then
    echo "parser jail: no prebuilt launcher for ${MACHINE} at ${SRC} - skipping" >&2
    exit 0
fi
if ! head -c 4 "${SRC}" | grep -q 'ELF'; then
    echo "parser jail: ${SRC} is not an executable - skipping" >&2
    exit 0
fi

# --- 1. the user -------------------------------------------------------------
if ! id -u "${JAIL_USER}" >/dev/null 2>&1; then
    if useradd --system --no-create-home --home-dir /nonexistent \
            --shell /usr/sbin/nologin --user-group "${JAIL_USER}" 2>/dev/null; then
        echo "parser jail: created user ${JAIL_USER}"
    else
        echo "parser jail: WARNING - could not create user ${JAIL_USER} - not installed" >&2
        exit 0
    fi
fi

# --- 2. read access to the tree ---------------------------------------------
# Only what the extraction subprocess needs: the code, the vendor directory,
# and the class-map cache. Granted as an ACL, and only when the probe says the
# user cannot already read it — a container's tree is world-readable and needs
# nothing; a node's 770 tree needs the grant, re-checked at every root moment
# because an upgrade swaps a fresh public_html into place.
can_read() {
    runuser -u "${JAIL_USER}" -- /usr/bin/test -r "$1" 2>/dev/null
}

PUBLIC_HTML="${SITE_ROOT}/public_html"
PROBE_CODE="${PUBLIC_HTML}/utils/extract_document_text.php"
if [[ ! -f "${PROBE_CODE}" ]]; then
    echo "parser jail: ${PROBE_CODE} not found under ${SITE_ROOT} - skipping" >&2
    exit 0
fi

grant_needed=0
for probe in "${PROBE_CODE}" "${SITE_ROOT}/vendor/autoload.php"; do
    [[ -e "${probe}" ]] || continue
    can_read "${probe}" || grant_needed=1
done

if [[ "${grant_needed}" == "1" ]]; then
    if ! command -v setfacl >/dev/null 2>&1; then
        echo "parser jail: installing acl for setfacl"
        (apt-get update -qq >/dev/null 2>&1 || true; apt-get install -y acl >/dev/null 2>&1) || true
    fi
    if ! command -v setfacl >/dev/null 2>&1; then
        echo "parser jail: WARNING - setfacl unavailable and the tree is not readable by ${JAIL_USER}; launcher NOT installed" >&2
        exit 0
    fi
    # Traverse the site root, read the three directories (and, by default ACL,
    # whatever an upgrade adds to them later).
    setfacl -m "u:${JAIL_USER}:x" "${SITE_ROOT}" 2>/dev/null || true
    for d in public_html vendor cache; do
        [[ -d "${SITE_ROOT}/${d}" ]] || continue
        setfacl -R -m "u:${JAIL_USER}:rX" -m "d:u:${JAIL_USER}:rX" "${SITE_ROOT}/${d}" 2>/dev/null \
            || echo "parser jail: WARNING - setfacl failed on ${SITE_ROOT}/${d}" >&2
    done
    echo "parser jail: granted ${JAIL_USER} read access under ${SITE_ROOT}"
    for probe in "${PROBE_CODE}" "${SITE_ROOT}/vendor/autoload.php"; do
        [[ -e "${probe}" ]] || continue
        if ! can_read "${probe}"; then
            echo "parser jail: WARNING - ${JAIL_USER} still cannot read ${probe}; launcher NOT installed" >&2
            exit 0
        fi
    done
fi

# --- 3. the launcher ---------------------------------------------------------
if [[ -f "${DEST}" ]] && cmp -s "${SRC}" "${DEST}"; then
    # Same bytes; make sure the mode is what a jail needs and nothing more.
    chown root:root "${DEST}"
    chmod 4755 "${DEST}"
    echo "parser jail: ${DEST} already current"
else
    if install -o root -g root -m 4755 "${SRC}" "${DEST}.tmp" && mv -f "${DEST}.tmp" "${DEST}"; then
        echo "parser jail: installed ${DEST}"
    else
        rm -f "${DEST}.tmp"
        echo "parser jail: WARNING - could not install ${DEST}" >&2
        exit 0
    fi
fi

# --- 4. one run through the fence ---------------------------------------------
want="$(id -u "${JAIL_USER}")"
got="$("${DEST}" --timeout=5 -- /usr/bin/id -u 2>/dev/null || true)"
if [[ "${got}" == "${want}" ]]; then
    echo "parser jail: ok - commands run as ${JAIL_USER} (uid ${want})"
else
    echo "parser jail: WARNING - a jailed command reported uid '${got}', expected ${want}" >&2
fi

exit 0
