#!/usr/bin/env bash
#
# _mail_stack_start.sh - re-assert the Mailbox mail stack on container start.
#
# Joinery site containers have no systemd, so the container CMD is the only
# thing that brings services back after a `docker stop`/`start` or a rebuild.
# This script is the mail-stack equivalent of the CMD's `service postgresql
# start`: when the mailbox plugin is active for the site, it runs the
# idempotent install_email.sh, which reconfigures and starts postfix + opendkim.
#
# It is deliberately fail-safe - plugin absent, plugin inactive, database
# unreachable, or install_email.sh failure all exit 0, so the mail stack can
# never block the container from starting.
#
# Usage:  _mail_stack_start.sh SITENAME
#         (PGPASSWORD is expected in the environment - the container CMD
#          exports it before calling this script.)
#
# See spec mail_stack_container_persistence.

set -u

SITENAME="${1:-}"
if [[ -z "${SITENAME}" ]]; then
    echo "mail stack: no SITENAME given - skipping" >&2
    exit 0
fi

SITE_ROOT="/var/www/html/${SITENAME}"
INSTALL_EMAIL="${SITE_ROOT}/public_html/plugins/mailbox/provisioning/install_email.sh"
CONFIG_FILE="${SITE_ROOT}/config/Globalvars_site.php"

if [[ ! -f "${INSTALL_EMAIL}" ]]; then
    echo "mail stack: mailbox plugin not present - skipping"
    exit 0
fi
if [[ ! -f "${CONFIG_FILE}" ]]; then
    echo "mail stack: site not initialised yet - skipping"
    exit 0
fi

# The database is the only persistent signal of whether this site uses inbound
# email: after a rebuild /etc carries base defaults, but the database (on the
# config volume) still knows. Read the db name the way install_email.sh does.
DBNAME="$(grep -oP "settings\['dbname'\]\s*=\s*'\K[^']+" "${CONFIG_FILE}" 2>/dev/null | head -1 || true)"
if [[ -z "${DBNAME}" ]]; then
    echo "mail stack: could not read dbname from site config - skipping"
    exit 0
fi

# PGPASSWORD is exported by the container CMD before this script is called.
ACTIVE="$(psql -U postgres -d "${DBNAME}" -tAqc \
    "SELECT plg_active FROM plg_plugins WHERE plg_name = 'mailbox'" 2>/dev/null || true)"
ACTIVE="$(printf '%s' "${ACTIVE}" | tr -d '[:space:]')"

if [[ "${ACTIVE}" != "1" ]]; then
    echo "mail stack: mailbox plugin not active for ${SITENAME} - skipping"
    exit 0
fi

echo "mail stack: mailbox active - asserting postfix/opendkim via install_email.sh"
if bash "${INSTALL_EMAIL}"; then
    echo "mail stack: postfix/opendkim asserted."
else
    echo "mail stack: WARNING - install_email.sh failed; inbound mail may be down." >&2
fi
exit 0
