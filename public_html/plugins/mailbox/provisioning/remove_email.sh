#!/usr/bin/env bash
#
# remove_email.sh - the Mailbox plugin's host remover: takes off a machine what
# install_email.sh put on it for this site, and nothing else.
#
# Version: 1.1 - a postconf that fails counts as left, and the map files stay while any Postfix setting
#                still names them in any spelling; the postgres probe is made with no pair in hand too
#                (reviewer2 F1, F2, F3)
# Version: 1.0 - specs/plugin_host_removal.md WP3. After a site removal PostgreSQL still held the
#                Postfix lookup role iemap_<dbname>, and Postfix still queried the dropped database
#                through two lookup maps.
#
# Declared as the plugin's host_remover (plugin.json). The host runner's
# --remove-site mode runs it as root before remove_account.sh deletes the site's
# folder, and the run_plugin_remover root request runs it at a plugin uninstall
# that leaves the site in place (docs/plugin_developer_guide.md § host_remover).
# Told the site name and the site root as arguments 1 and 2; with neither it
# finds the site four levels above itself, as the installer does.
#
# The contract is the installer's, read the other way:
#   - root, non-interactive, idempotent: a second run finds nothing and exits 0;
#   - exit 0 when not applicable: in a site container (nothing was installed);
#   - removes what the installer put on the machine FOR THIS SITE, nothing else:
#       1. Postfix is told to stop looking the site up: virtual_mailbox_domains
#          and virtual_transport are unset when they are ours, the recipient
#          lookup leaves smtpd_recipient_restrictions and proxy_read_maps, the
#          joinery pipe service leaves master.cf, the two pgsql map files go,
#          and a running Postfix is reloaded;
#       2. the lookup roles go: every iemap_* role granted CONNECT on one of the
#          site's databases (its own, and its test database when the config
#          names test_<dbname>), plus iemap_<dbname> itself, has DROP OWNED BY
#          run inside each of those databases (which is what takes the grants
#          away) and is then dropped. Every iemap_* role granted here, not only
#          this site's: a restore of another site's dump recreates that site's
#          role on this cluster. A DROP ROLE that still fails is reported with
#          PostgreSQL's message, which names the database that still grants to
#          it; the expected case is a copy or restore of THIS site's dump on
#          the same cluster (createdb -T keeps the grants), and the role goes
#          when that database does;
#       3. the DKIM signing keys of the site's domains go, and the list rspamd
#          signs for is rewritten without them, when the domains table can be
#          read (a site removal). At a plugin uninstall the table is already
#          gone: the keys are left, said, for a reinstall to pick up again;
#       4. the relay helpers go when present: /usr/local/sbin/joinery-mail-listener,
#          /usr/local/sbin/joinery-dkim-remove and /etc/sudoers.d/joinery-relay,
#          which lets the web user run those two as root and must not outlive
#          the plugin that needed it;
#   - leaves the machine's own: the packages, Postfix itself (running, with its
#     hostname, TLS certificate, renewal hook and milter settings), the spam
#     scanner's configuration and the firewall's 25/tcp rule. A later site's
#     installer runs over them;
#   - prints no secret;
#   - a non-zero exit means something is left, said on stdout as "left: ...".
#
# REMOVE_EMAIL_ROOT puts every machine path under a scratch root for the gate,
# and is read only when this is not root (a root run uses the real machine).
#
# Usage:  sudo bash remove_email.sh [SITENAME] [SITE_ROOT]
#
set -uo pipefail

# Not root: the gate runs this under a scratch root with every command stubbed.
FS=""
if [[ "${EUID}" -ne 0 ]]; then
    FS="${REMOVE_EMAIL_ROOT:-}"
    if [[ -z "${FS}" ]]; then
        echo "This script must run as root (edits /etc/postfix, drops database roles)." >&2
        echo "Re-run with: sudo bash $0" >&2
        exit 1
    fi
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="${2:-}"
[[ -n "${SITE_ROOT}" ]] || SITE_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
SITE_NAME="${1:-$(basename "${SITE_ROOT}")}"
CONFIG_FILE="${SITE_ROOT}/config/Globalvars_site.php"
MAP_FILE="${FS}/etc/postfix/joinery-domains.cf"
RCPT_MAP_FILE="${FS}/etc/postfix/joinery-recipient-access.cf"
RCPT_MAP="proxy:pgsql:/etc/postfix/joinery-recipient-access.cf"
RELAY_HELPERS=("${FS}/usr/local/sbin/joinery-mail-listener" "${FS}/usr/local/sbin/joinery-dkim-remove" "${FS}/etc/sudoers.d/joinery-relay")

# What this run could not take off. Every step runs whatever an earlier one
# found; the exit code says whether anything is left.
LEFT=()
left() {
    LEFT+=("$1")
    echo "remove_email: left: $1"
}

PHP_BIN="$(command -v php || true)"
if [[ -z "${PHP_BIN}" ]]; then
    left "no php on PATH to read the site config with; nothing was taken off"
    exit 1
fi
if [[ ! -f "${CONFIG_FILE}" ]]; then
    left "no site config at ${CONFIG_FILE}; the remover reads the database names from it, so nothing was taken off"
    exit 1
fi

# One setting out of the site config, by name, as install_email.sh reads it.
read_site_setting() {
    "${PHP_BIN}" -r '
        $config = file_get_contents($argv[1]);
        echo preg_match("/settings\[.".$argv[2].".\]\s*=\s*.([^\x27\"]*)/", $config, $m) ? $m[1] : "";
    ' "${CONFIG_FILE}" "$1"
}

# A site container has no mail server of its own: the installer put nothing
# here, so there is nothing to take off.
if [[ "$(read_site_setting deployment_environment)" == "docker" ]]; then
    echo "remove_email: ${SITE_NAME} runs in a container, which has no mail server of its own: nothing to take off."
    exit 0
fi

DBNAME="$(read_site_setting dbname)"
DBUSER="$(read_site_setting dbusername)"
DBPASS="$(read_site_setting dbpassword)"
DBHOST="$(read_site_setting dbhost)"
if [[ ! "${DBNAME}" =~ ^[a-z0-9_]+$ ]]; then
    left "the site config names no database (dbname), so the lookup role cannot be named; Postfix and the helpers are still taken off"
fi
# The site's databases, as remove_account.sh names them: its own, and the test
# suite's only when the config names exactly test_<dbname>. A copied config may
# name another site's test database, and roles granted there are not ours.
SITE_DATABASES=()
[[ -n "${DBNAME}" ]] && SITE_DATABASES+=("${DBNAME}")
[[ -n "${DBNAME}" && "$(read_site_setting dbname_test)" == "test_${DBNAME}" ]] && SITE_DATABASES+=("test_${DBNAME}")
DB_ROLE=""
[[ -n "${DBNAME}" ]] && DB_ROLE="iemap_$(printf '%s' "${DBNAME}" | tr -cd 'a-z0-9_')"

echo "remove_email: taking the Mailbox plugin's machine state off for ${SITE_NAME} (database ${DBNAME:-unknown})"

# --- 1. Postfix stops looking the site up -------------------------------------
# Each setting is unset only while it is ours, so a hand-configured Postfix on
# the same machine is left as the operator left it. postconf -X removes the
# line, and Postfix falls back to its default.
POSTFIX_CHANGED=0
# A postconf that fails is said and counts as left; an unconditional "done"
# here once left Postfix pointing at a map file deleted below (reviewer2 F1).
postfix_apply() {   # DESCRIPTION postconf-arguments...
    local what="$1"; shift
    if postconf "$@"; then
        echo "postfix: ${what}"
        POSTFIX_CHANGED=1
    else
        left "Postfix: postconf $* failed, so ${what} did not happen"
    fi
}
# Whether any Postfix setting still names the site's lookups, in any spelling:
# asked after the rewrites, and the map files stay while one does, so a hand
# edit the exact matches below missed is said rather than passed over
# (reviewer2 F2).
postfix_still_names_site() {
    local setting value
    for setting in virtual_mailbox_domains virtual_transport smtpd_recipient_restrictions proxy_read_maps; do
        value="$(postconf -h "${setting}" 2>/dev/null || true)"
        case "${setting}:${value}" in
            *joinery-domains.cf*|*joinery-recipient-access.cf*|virtual_transport:joinery)
                echo "${setting} = ${value}"; return 0 ;;
        esac
    done
    if [[ -n "$(postconf -M joinery/unix 2>/dev/null || true)" ]]; then
        echo "master.cf: joinery/unix"; return 0
    fi
    return 1
}
MAPS_MAY_GO=1
if command -v postconf >/dev/null 2>&1; then
    current="$(postconf -h virtual_mailbox_domains 2>/dev/null || true)"
    if [[ "${current}" == *"pgsql:/etc/postfix/joinery-domains.cf"* ]]; then
        postfix_apply "virtual_mailbox_domains no longer names the site's domain map" -X virtual_mailbox_domains
    fi
    current="$(postconf -h virtual_transport 2>/dev/null || true)"
    if [[ "${current}" == "joinery" ]]; then
        postfix_apply "virtual_transport no longer names the joinery pipe" -X virtual_transport
    fi
    current="$(postconf -h smtpd_recipient_restrictions 2>/dev/null || true)"
    if [[ "${current}" == *"check_recipient_access ${RCPT_MAP}"* ]]; then
        # The lookup clause alone goes; the RBL checks beside it are the
        # machine's own and stay.
        rewritten="$(printf '%s' "${current}" | sed -E "s#,?[[:space:]]*check_recipient_access ${RCPT_MAP}##")"
        postfix_apply "the recipient lookup left smtpd_recipient_restrictions" -e "smtpd_recipient_restrictions = ${rewritten}"
    fi
    current="$(postconf -h proxy_read_maps 2>/dev/null || true)"
    if [[ "${current}" == *"${RCPT_MAP}"* ]]; then
        rewritten="$(printf '%s' "${current}" | sed -E "s#[[:space:]]*${RCPT_MAP}##")"
        postfix_apply "the recipient lookup left proxy_read_maps" -e "proxy_read_maps = ${rewritten}"
    fi
    if [[ -n "$(postconf -M joinery/unix 2>/dev/null || true)" ]]; then
        postfix_apply "the joinery pipe service left master.cf" -MX joinery/unix
    fi
    if still="$(postfix_still_names_site)"; then
        left "Postfix still names the site's lookups (${still}); the map files are kept until it does not"
        MAPS_MAY_GO=0
    fi
else
    echo "postfix: postconf is not installed here, so no Postfix setting names the site"
fi
if (( MAPS_MAY_GO )); then
    for map in "${MAP_FILE}" "${RCPT_MAP_FILE}"; do
        if [[ -e "${map}" ]]; then
            if rm -f "${map}"; then
                echo "postfix: removed ${map#"${FS}"}"
            else
                left "${map#"${FS}"} could not be removed"
            fi
        fi
    done
fi
if (( POSTFIX_CHANGED )) && command -v postfix >/dev/null 2>&1; then
    if postfix status >/dev/null 2>&1; then
        if postfix reload >/dev/null 2>&1; then
            echo "postfix: reloaded"
        else
            echo "postfix: WARNING - reload failed; Postfix reads the new settings at its next start" >&2
        fi
    else
        echo "postfix: not running; it reads the new settings when it starts"
    fi
fi

# --- 2. the lookup roles go ---------------------------------------------------
# As postgres, with the pair from the root-only config/db_admin.php (or PGPASSWORD,
# or a config that still connects as postgres), the way the installer creates
# the role: the site's own role may not manage roles.
ADMIN_PASS="${PGPASSWORD:-}"
if [[ -z "${ADMIN_PASS}" && -r "${SITE_ROOT}/config/db_admin.php" ]]; then
    ADMIN_PASS="$("${PHP_BIN}" "${SITE_ROOT}/maintenance_scripts/install_tools/_config_value.php" "${SITE_ROOT}/config/db_admin.php" dbadminpassword 2>/dev/null || true)"
fi
if [[ -z "${ADMIN_PASS}" && "${DBUSER}" == "postgres" ]]; then
    ADMIN_PASS="${DBPASS}"
fi
ADMIN_PSQL_ARGS=(-w -U postgres -v ON_ERROR_STOP=1 -tAXq)
if [[ -n "${DBHOST}" && "${DBHOST}" != "localhost" ]]; then
    ADMIN_PSQL_ARGS+=(-h "${DBHOST}")
fi
# DATABASE, then psql arguments; the statement on stdin, so a name reaches
# PostgreSQL through a psql variable and never through a shell word.
admin_psql() {
    local db="$1"; shift
    PGPASSWORD="${ADMIN_PASS}" psql "${ADMIN_PSQL_ARGS[@]}" -d "${db}" "$@"
}

# With no pair in hand the probe is still made: a cluster that trusts local
# connections answers, and one that does not says so in its own words
# (reviewer2 F3). remove_account.sh hands its pair down as PGPASSWORD.
if [[ -z "${DB_ROLE}" ]]; then
    : # said above
elif ! command -v psql >/dev/null 2>&1; then
    left "psql is not installed here, so the lookup role ${DB_ROLE} could not be dropped"
elif ! probe="$(admin_psql postgres <<<'SELECT 1;' 2>&1)"; then
    # The pair itself never reaches the transcript: a test on the variable,
    # never an expansion of it (reviewer2, second pass).
    why="$(printf '%s' "${probe}" | head -n 1)"
    [[ -n "${ADMIN_PASS}" ]] || why="${why}; no postgres pair was found (config/db_admin.php, or PGPASSWORD)"
    left "PostgreSQL could not be reached as postgres to drop the lookup roles: ${why}"
else
    # Which of the site's databases are still here.
    PRESENT_DATABASES=()
    for db in "${SITE_DATABASES[@]+"${SITE_DATABASES[@]}"}"; do
        if [[ "$(admin_psql postgres -v d="${db}" <<<"SELECT 1 FROM pg_database WHERE datname = :'d';" 2>/dev/null)" == "1" ]]; then
            PRESENT_DATABASES+=("${db}")
        fi
    done
    # The roles: every iemap_* role granted CONNECT on one of those databases
    # (the grants read from the database's ACL, never through PUBLIC), and
    # this site's own lookup role whether or not a grant is left.
    ROLES="${DB_ROLE}"
    for db in "${PRESENT_DATABASES[@]+"${PRESENT_DATABASES[@]}"}"; do
        granted="$(admin_psql postgres -v d="${db}" <<<"SELECT r.rolname FROM pg_database d, aclexplode(d.datacl) a JOIN pg_roles r ON r.oid = a.grantee WHERE d.datname = :'d' AND a.privilege_type = 'CONNECT' AND r.rolname LIKE 'iemap\\_%' ORDER BY 1;" 2>/dev/null || true)"
        for role in ${granted}; do
            [[ "${role}" =~ ^iemap_[a-z0-9_]+$ ]] || continue
            grep -qxF -- "${role}" <<<"${ROLES}" || ROLES="${ROLES}"$'\n'"${role}"
        done
    done
    while IFS= read -r role; do
        [[ -n "${role}" ]] || continue
        if [[ "$(admin_psql postgres -v r="${role}" <<<"SELECT 1 FROM pg_roles WHERE rolname = :'r';" 2>/dev/null)" != "1" ]]; then
            echo "postgres: role ${role} is already gone"
            continue
        fi
        # DROP OWNED BY inside each database the role is granted on takes the
        # grants away, CONNECT on the database included; nothing else is owned.
        for db in "${PRESENT_DATABASES[@]+"${PRESENT_DATABASES[@]}"}"; do
            if out="$(admin_psql "${db}" -v r="${role}" <<<'DROP OWNED BY :"r";' 2>&1)"; then
                echo "postgres: ${role}'s grants in ${db} revoked"
            else
                echo "postgres: WARNING - DROP OWNED BY ${role} in ${db}: $(printf '%s' "${out}" | head -n 1)" >&2
            fi
        done
        if out="$(admin_psql postgres -v r="${role}" <<<'DROP ROLE IF EXISTS :"r";' 2>&1)"; then
            echo "postgres: dropped role ${role}"
        else
            left "role ${role} was not dropped: $(printf '%s' "${out}" | head -n 1). Expected when a copy or restore of this site's database on this cluster still grants to it: the role goes when that database does."
        fi
    done <<<"${ROLES}"
fi

# --- 3. the DKIM signing keys of the site's domains --------------------------
# Where keys live and how the signing list is written: rspamd_stateless.sh,
# which the installer and provision_dkim.sh share. The directory is the truth,
# and the list is rendered from it.
export RSPAMD_DKIM_DIR="${RSPAMD_DKIM_DIR:-${FS}/etc/rspamd/dkim}"
export RSPAMD_OLD_DKIM_DIR="${RSPAMD_OLD_DKIM_DIR:-${FS}/etc/opendkim/keys}"
if [[ -f "${SCRIPT_DIR}/rspamd_stateless.sh" ]]; then
    # shellcheck source=rspamd_stateless.sh
    source "${SCRIPT_DIR}/rspamd_stateless.sh"
else
    RSPAMD_DKIM_MAP="${RSPAMD_DKIM_DIR}/signing.map"
    RSPAMD_DKIM_SELECTOR="mail"
    rspamd_dkim_render_map() {
        local key
        for key in "${RSPAMD_DKIM_DIR}"/*/"${RSPAMD_DKIM_SELECTOR}.private"; do
            [[ -f "${key}" ]] || continue
            printf '%s %s\n' "$(basename "$(dirname "${key}")")" "${key}"
        done | LC_ALL=C sort
    }
    rspamd_dkim_forget_old_key() {
        [[ -n "$1" && -d "${RSPAMD_OLD_DKIM_DIR}/$1" ]] && rm -rf "${RSPAMD_OLD_DKIM_DIR:?}/${1:?}"
    }
fi
write_signing_map() {
    local tmp
    [[ -d "${RSPAMD_DKIM_DIR}" ]] || return 0
    tmp="$(mktemp "${RSPAMD_DKIM_MAP}.joinery-XXXXXX")" || return 1
    rspamd_dkim_render_map > "${tmp}"
    chmod 644 "${tmp}"
    mv -f "${tmp}" "${RSPAMD_DKIM_MAP}"
}
DOMAINS=""
DOMAINS_READ=0
if [[ -n "${DBNAME}" && -n "${ADMIN_PASS}" ]] && command -v psql >/dev/null 2>&1 \
   && [[ "$(admin_psql postgres -v d="${DBNAME}" <<<"SELECT 1 FROM pg_database WHERE datname = :'d';" 2>/dev/null)" == "1" ]] \
   && [[ "$(admin_psql "${DBNAME}" <<<"SELECT to_regclass('public.ied_inbound_email_domains') IS NOT NULL;" 2>/dev/null)" == "t" ]]; then
    if DOMAINS="$(admin_psql "${DBNAME}" <<<"SELECT ied_domain FROM ied_inbound_email_domains ORDER BY 1;" 2>&1)"; then
        DOMAINS_READ=1
    else
        left "the site's domains could not be read for their DKIM keys: $(printf '%s' "${DOMAINS}" | head -n 1)"
        DOMAINS=""
    fi
fi
if (( DOMAINS_READ )); then
    DKIM_CHANGED=0
    for domain in ${DOMAINS}; do
        domain="$(printf '%s' "${domain}" | tr 'A-Z' 'a-z')"
        # The shape provision_dkim.sh accepts: the path below is built from it.
        if [[ ! "${domain}" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$ || "${domain}" != *.* ]]; then
            echo "dkim: '${domain:0:60}' is not a domain name - skipped" >&2
            continue
        fi
        key_dir="${RSPAMD_DKIM_DIR:?}/${domain:?}"
        if [[ -d "${key_dir}" ]]; then
            if rm -rf "${key_dir}"; then
                echo "dkim: destroyed the signing key for ${domain} (${key_dir#"${FS}"})"
                DKIM_CHANGED=1
            else
                left "the DKIM key directory ${key_dir#"${FS}"} could not be removed"
            fi
        fi
        rspamd_dkim_forget_old_key "${domain}" || true
    done
    if (( DKIM_CHANGED )); then
        if write_signing_map; then
            echo "dkim: the list of domains rspamd signs for was rewritten without them (rspamd re-reads it on its own)"
        else
            left "the signing list ${RSPAMD_DKIM_MAP#"${FS}"} could not be rewritten"
        fi
    else
        echo "dkim: none of the site's domains has a signing key here"
    fi
else
    echo "dkim: the site's domains table cannot be read here (the plugin's tables are gone, or its database is), so any signing keys under ${RSPAMD_DKIM_DIR#"${FS}"} are left for a reinstall to pick up again"
fi

# --- 4. the relay helpers -----------------------------------------------------
for helper in "${RELAY_HELPERS[@]}"; do
    if [[ -e "${helper}" ]]; then
        if rm -f "${helper}"; then
            echo "relay: removed ${helper#"${FS}"}"
        else
            left "${helper#"${FS}"} could not be removed"
        fi
    fi
done

# --- 5. what stays -------------------------------------------------------------
echo "remove_email: left in place as the machine's own: the mail packages, Postfix itself (its hostname, TLS certificate and renewal hook, milter settings), the spam scanner's configuration and the firewall's 25/tcp rule"

if (( ${#LEFT[@]} )); then
    echo "remove_email: ${#LEFT[@]} item(s) left behind (above)"
    exit 1
fi
echo "remove_email: the Mailbox plugin's machine state for ${SITE_NAME} is off"
exit 0
