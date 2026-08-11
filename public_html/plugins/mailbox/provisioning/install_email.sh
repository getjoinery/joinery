#!/usr/bin/env bash
#
# install_email.sh - host installer + base configurator for Mailbox.
#
# Version: 2.16 - dbconfig-no-thanks is installed alongside opendmarc, which
#                depends on `dbconfig-mysql | dbconfig-no-thanks`. Left to
#                choose, apt takes the first: a MySQL client stack lands on a
#                PostgreSQL-only box and dbconfig-common fails trying to reach a
#                MySQL server that was never there, printing two ERROR lines into
#                every install log. Only the report tooling wants that database
#                and nothing here runs it
# Version: 2.15 - The sqlite3 package is named for the PHP actually on the box,
#                not pinned to 8.3, so provisioning on any other PHP stops asking
#                apt for a package that does not exist there
# Version: 2.14 - Only Spamhaus (zen + dbl) is rejected on at RCPT time; SpamCop
#                and Barracuda list shared ESP outbound IPs on brief triggers, so
#                rejecting on them permanently bounced ordinary Mailgun/SendGrid mail
# Version: 2.13 - The local spam scanner ships with the mail stack: provision_
#                spam_scanner.sh is installed unconditionally, so enabling spam
#                learning later is a pure settings toggle (nothing to install)
# Version: 2.12 - Converge the relay tunnel helpers on every run (relay-fronted boxes
#                only): they are installed copies, so a corrected provisioner that
#                merely deploys would never replace a stale helper on disk
# Version: 2.11 - Converge myhostname and milter AuthservID to mailbox_mail_hostname on every run
# Version: 2.10 - Create /etc/opendkim on fresh boxes (package ships only opendkim.conf)
# Version: 2.9 - Renamed for the Mailbox plugin (spec
#                plugin_rename_inbound_email_to_mailbox).
#                2.8 - Optional content spam scanner (spec
#                inbound_email_content_spam_filtering). When
#                mailbox_content_spam_filtering_enabled is on, installs rspamd
#                + redis, wires rspamd as a Postfix milter AFTER opendkim+opendmarc
#                (header-stamping only, never reject), pins its X-Spam header contract,
#                puts the Bayes classifier on redis, and exposes the controller on
#                loopback 11334 (no password — loopback-trusted) for the spam/ham
#                feedback loop. Disabled deployments install none of it. redis is
#                disposable plugin-local state; Postgres (iem_spam_verdict) is the
#                durable signal, so no volume mount is required.
#                2.7 - Inbound authentication verification. opendkim already runs in
#                Mode sv; this adds the opendmarc milter and an AuthservID on
#                both milters (sourced from mailbox_mail_hostname, the
#                value the app's AuthenticationResults parser trusts), then wires
#                BOTH milters into smtpd_milters in order (opendkim then
#                opendmarc) so received mail is stamped with an
#                Authentication-Results header the app reads for SPF/DKIM/DMARC.
#                The opendkim.conf rewrite is re-keyed on a managed marker so an
#                already-wired host still picks up the new AuthservID line.
#                2.6 - The joinery pipe transport is now asserted with `postconf -Me`
#                every run instead of an append-once guard. The old guard only
#                checked the php binary, so a stale handler PATH (e.g. after a
#                plugin rename) survived re-runs and bounced every inbound
#                message; the assert is self-repairing.
#                2.5 - Per-domain DKIM keys now have a one-command helper
#                (provision_dkim.sh); the summary and notes below point at it
#                instead of spelling out the manual opendkim-genkey steps.
#                2.4 - Reuse the existing pgsql-map role password when the map file is
#                intact instead of rotating it every run (spec
#                mail_stack_container_persistence) - the container CMD calls
#                this script on every start.
#                2.3 - Sets a fallback Postfix myhostname (spec inbound_email_guided_setup)
#                when it is unset/localhost, so the mail server has a FQDN HELO
#                name; the Setup tab verifies and refines it.
#                2.2 - Renamed for the Inbound Email plugin (spec inbound_email_rename):
#                the pipe transport runs utils/inbound_email_handler.php and the
#                pgsql map reads ied_inbound_email_domains.
#                2.1 - The pgsql map authenticates as a dedicated least-privilege
#                PostgreSQL role, not the application's superuser account
#                (spec email_forwarding_pgsql_credential).
#                2.0 - Option C (spec email_forwarding_install_unification):
#                Postfix resolves the inbound-domain list live from the
#                database via a pgsql map; opendkim static config and the
#                milter became part of this fixed base install.
#
# Installs the mail software the plugin needs and applies the FIXED Postfix and
# opendkim configuration so inbound mail is piped to the handler. Fully
# idempotent: re-running adds nothing twice and is safe.
#
# What it configures (fixed, deployment-independent):
#   - Installs postfix, postfix-pgsql, opendkim, opendkim-tools.
#   - master.cf : the `joinery` pipe transport (appended once).
#   - main.cf   : virtual_transport = joinery
#   - main.cf   : inet_interfaces = all - with one site per host, this site's
#                 Postfix IS the host's mail server.
#   - main.cf   : mydestination = localhost, localhost.localdomain
#                 (inbound domains must NOT appear here, or Postfix rejects
#                  them with "User unknown in local recipient table").
#   - main.cf   : smtpd_recipient_restrictions with RBL clients.
#   - main.cf   : virtual_mailbox_domains = pgsql:/etc/postfix/joinery-domains.cf
#                 Postfix asks the database whether a recipient domain is an
#                 active inbound domain, so adding or removing a domain in
#                 the admin UI takes effect immediately - no host action, no
#                 drift. /etc/postfix/joinery-domains.cf is the pgsql map.
#   - postgres  : a dedicated least-privilege role the pgsql map authenticates
#                 as - SELECT on the inbound-domains table only, never the
#                 application's superuser. Its password lives only in the map
#                 file; re-running this script rotates it.
#   - opendkim  : inet socket localhost:8891, Mode sv (sign + VERIFY), empty
#                 key/signing tables, and an AuthservID matching the configured
#                 mail hostname so stamped Authentication-Results lines are
#                 attributable to us.
#   - opendmarc : inet socket localhost:8893, SPFSelfValidate (computes SPF from
#                 the connecting IP it sees at the milter stage — the IP the PHP
#                 pipe never gets), RejectFailures false (stamp only, never
#                 block; enforcement is out of scope).
#   - main.cf   : smtpd_milters = inet:localhost:8891, inet:localhost:8893
#                 (opendkim first so opendmarc can consume its DKIM result),
#                 milter_default_action = accept (a down/keyless milter must
#                 never block or defer mail). Received mail is thereby stamped
#                 with an Authentication-Results header the app reads for its
#                 SPF/DKIM/DMARC verdicts (it never computes them itself).
#   - Opens port 25 if ufw is active.
#
# What it does NOT do (genuinely per-deployment - handled elsewhere):
#   - The inbound-domain list: NOT installed at all - Postfix reads it live
#     from the database (see above). Manage domains under
#     Admin > Emails > Incoming > Domains.
#   - DNS records (MX, SPF, DKIM).
#   - Per-domain opendkim DKIM keys and their DNS TXT record. opendkim runs
#     keyless (signing nothing) until a key is added; run provision_dkim.sh
#     <domain> for each domain. See plugins/mailbox/docs/overview.md.
#
# Docker: run this INSIDE the same container as the app - Postfix must be
# co-located with the PHP handler it pipes to, and reads the app's own
# database. The container also has to publish port 25 (e.g. docker run -p 25:25)
# and (re)start Postfix on boot, since a container usually has no systemd.
#
# Usage:  sudo bash install_email.sh
#
set -euo pipefail

# --- preconditions -----------------------------------------------------------
if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must run as root (installs packages, edits /etc/postfix)." >&2
    echo "Re-run with: sudo bash $0" >&2
    exit 1
fi

if ! command -v apt-get >/dev/null 2>&1; then
    echo "This installer supports apt-based systems (Debian/Ubuntu) only." >&2
    echo "Install postfix, postfix-pgsql and opendkim with your platform's package manager instead." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PIPE_SCRIPT="${PLUGIN_DIR}/utils/inbound_email_handler.php"
RENDER_SCRIPT="${SCRIPT_DIR}/render_pgsql_map.php"
MAP_FILE="/etc/postfix/joinery-domains.cf"

if [[ ! -f "${PIPE_SCRIPT}" ]]; then
    echo "ERROR: inbound email handler not found at ${PIPE_SCRIPT}" >&2
    echo "Run this script from inside the mailbox plugin directory." >&2
    exit 1
fi
if [[ ! -f "${RENDER_SCRIPT}" ]]; then
    echo "ERROR: pgsql map renderer not found at ${RENDER_SCRIPT}" >&2
    exit 1
fi

# The site config sits alongside public_html, four levels up from provisioning/.
# Only the database name is read from it here — it names the dedicated role and
# the database to grant in.
SITE_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
CONFIG_FILE="${SITE_ROOT}/config/Globalvars_site.php"
if [[ ! -f "${CONFIG_FILE}" ]]; then
    echo "ERROR: site config not found at ${CONFIG_FILE}" >&2
    exit 1
fi
DBNAME="$(grep -oP "settings\['dbname'\]\s*=\s*'\K[^']+" "${CONFIG_FILE}" | head -1)"
if [[ -z "${DBNAME}" ]]; then
    echo "ERROR: could not read dbname from ${CONFIG_FILE}" >&2
    exit 1
fi

# Resolve the PHP CLI binary. The official php Docker images ship it at
# /usr/local/bin/php, not /usr/bin/php — hard-coding the path bakes a broken
# pipe transport into master.cf, and inbound mail then fails silently.
PHP_BIN="$(command -v php || true)"
if [[ -z "${PHP_BIN}" ]]; then
    echo "ERROR: no 'php' executable found on PATH; cannot wire the Postfix pipe transport." >&2
    exit 1
fi
echo "PHP CLI: ${PHP_BIN}"

# --- 1. install packages -----------------------------------------------------
# ext-sqlite3 (FTS5 compiled in) backs MailboxIndex, the sealed mailbox search
# index (specs/implemented/inbound_email_encryption_at_rest.md § 6). The package
# is named for the PHP that loads it, so it is derived from the interpreter
# resolved above rather than written out: a pinned name either does not exist on
# the box, or installs the extension into a PHP nothing here runs — and then the
# extension is present, apt is satisfied, and the index fails at first use.
# Derived from PHP_BIN because that is the interpreter the Postfix pipe
# transport calls. A box whose web PHP is a different version needs that one's
# sqlite3 package too, which is not something this script provisions.
# ext-apcu (the unlock window's key store) ships with the base image.
PHP_VERSION="$("${PHP_BIN}" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)"
if [[ -z "${PHP_VERSION}" ]]; then
    echo "ERROR: could not read a version from ${PHP_BIN}; cannot name the sqlite3 package." >&2
    exit 1
fi
# dbconfig-no-thanks is listed ahead of opendmarc on purpose. opendmarc depends
# on `dbconfig-mysql | dbconfig-no-thanks`, and an unresolved alternative is
# satisfied by the first option — so apt installs MySQL client packages onto a
# PostgreSQL-only box, then dbconfig-common tries to provision a database
# against a MySQL server that is not there and fails:
#
#   ERROR 2002 (HY000): Can't connect to local MySQL server through socket ...
#   dbconfig-common: opendmarc configure: noninteractive fail.
#
# Nothing breaks — that database only feeds opendmarc-import/opendmarc-reports,
# which nothing here runs, and the milter stamps Authentication-Results without
# it — but every install ends up with two ERROR lines in a log whose whole
# contract is that errors mean something, plus a MySQL client stack it will
# never use. Naming the other alternative resolves the dependency honestly.
PACKAGES=(postfix postfix-pgsql dbconfig-no-thanks opendkim opendkim-tools opendmarc "php${PHP_VERSION}-sqlite3")
MISSING=()
for pkg in "${PACKAGES[@]}"; do
    if dpkg -s "${pkg}" >/dev/null 2>&1; then
        echo "Already installed: ${pkg}"
    else
        MISSING+=("${pkg}")
    fi
done

if [[ ${#MISSING[@]} -gt 0 ]]; then
    echo "Installing: ${MISSING[*]}"
    # Non-interactive so the Postfix configuration prompt does not block.
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y "${MISSING[@]}"
else
    echo "All mail packages already installed."
fi

systemctl enable postfix >/dev/null 2>&1 || true
systemctl start postfix  >/dev/null 2>&1 || true

# --- 2. master.cf: joinery pipe transport (assert, self-repairing) -----------
# The transport must run the CURRENT handler with a usable php binary. Asserting
# the whole service definition with `postconf -Me` every run is idempotent (it
# replaces the one service, never accumulates duplicates) AND self-repairing: a
# stale entry — an old handler path left behind by a plugin rename, or a php
# path baked on a different host — is corrected in place instead of silently
# bouncing every inbound message. \${recipient} stays literal for Postfix to
# expand at delivery time.
# flags=DRh — deliberately NOT 'u' (fold localpart to lowercase): SRS bounce
# addresses carry a case-sensitive hash in the local part; folding it would make
# SRSRewriter::validate() reject every bounce. Alias lookup lowercases
# internally, so normal recipients are unaffected.
JOINERY_ARGV="argv=${PHP_BIN} ${PIPE_SCRIPT} \${recipient}"
JOINERY_DEF="joinery unix - n n - 5 pipe flags=DRh user=www-data ${JOINERY_ARGV}"
existing_joinery="$(postconf -M joinery/unix 2>/dev/null | tr -s ' \t' ' ' | tr -d '\n' || true)"
if [[ -z "${existing_joinery}" ]]; then
    postconf -Me "joinery/unix=${JOINERY_DEF}"
    echo "master.cf: added joinery pipe transport -> ${PHP_BIN} ${PIPE_SCRIPT}"
elif [[ ( "${existing_joinery}" == *"${JOINERY_ARGV} "* || "${existing_joinery}" == *"${JOINERY_ARGV}" ) && "${existing_joinery}" == *"flags=DRh "* ]]; then
    echo "master.cf: joinery transport already correct - leaving it."
else
    postconf -Me "joinery/unix=${JOINERY_DEF}"
    echo "master.cf: repaired stale joinery pipe transport -> ${PHP_BIN} ${PIPE_SCRIPT}"
fi

# --- 3. main.cf: fixed settings (postconf -e is idempotent) ------------------
postconf -e "virtual_transport = joinery"
echo "main.cf: virtual_transport = joinery"

# One site per host: this site's Postfix is the host's mail server, so it
# listens on every interface (spec §6.1 / decision 2).
postconf -e "inet_interfaces = all"
echo "main.cf: inet_interfaces = all"

SAFE_MYDEST="localhost, localhost.localdomain"
CURRENT_MYDEST="$(postconf -h mydestination 2>/dev/null || true)"
if [[ "${CURRENT_MYDEST}" == "${SAFE_MYDEST}" ]]; then
    echo "main.cf: mydestination already safe."
else
    echo "main.cf: mydestination was '${CURRENT_MYDEST}'"
    postconf -e "mydestination = ${SAFE_MYDEST}"
    echo "main.cf: mydestination = ${SAFE_MYDEST}"
fi

# RBL spam filtering at RCPT time (fixed config).
#
# Only Spamhaus rejects. Zen and DBL are built to be rejected on: low false
# positive, and Zen deliberately excludes the shared outbound ranges every ESP
# sends from. SpamCop and Barracuda list those shared IPs on brief automated
# triggers and de-list hours later, so rejecting on them bounces ordinary mail
# from Mailgun, SendGrid or Google at random — permanently, since a 5xx stops
# the sender retrying. SpamCop says as much itself: use it to score, not to
# refuse. Content scoring is where a weaker signal belongs.
postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination, reject_rbl_client zen.spamhaus.org, reject_rhsbl_helo dbl.spamhaus.org, reject_rhsbl_sender dbl.spamhaus.org, permit"
echo "main.cf: smtpd_recipient_restrictions set (RBL clients)"

# myhostname: a mail server needs a fully-qualified HELO name. If Postfix has
# only a bare or localhost name, fall back to the system FQDN as a sane
# default. The Mailbox Setup tab verifies this and offers an exact
# command to set a specific name (e.g. mail.example.com).
CURRENT_MYHOSTNAME="$(postconf -h myhostname 2>/dev/null || true)"
case "${CURRENT_MYHOSTNAME}" in
    ""|localhost|localhost.localdomain)
        SYS_FQDN="$(hostname -f 2>/dev/null || true)"
        if [[ "${SYS_FQDN}" == *.* && "${SYS_FQDN}" != localhost* ]]; then
            postconf -e "myhostname = ${SYS_FQDN}"
            echo "main.cf: myhostname = ${SYS_FQDN} (was '${CURRENT_MYHOSTNAME:-unset}')"
        else
            echo "main.cf: myhostname is '${CURRENT_MYHOSTNAME:-unset}' and no system FQDN is available -" >&2
            echo "         set it on the Mailbox Setup tab." >&2
        fi
        ;;
    *.*)
        echo "main.cf: myhostname already a FQDN (${CURRENT_MYHOSTNAME})."
        ;;
    *)
        echo "main.cf: myhostname is '${CURRENT_MYHOSTNAME}' (not a FQDN) -" >&2
        echo "         set it on the Mailbox Setup tab." >&2
        ;;
esac

# --- 4. dedicated DB role + pgsql domain map ---------------------------------
# Postfix authenticates to PostgreSQL as a dedicated least-privilege role, not
# the application's superuser account. The role can do exactly one thing: read
# the inbound-domain list. Its password lives only in the map file written
# below; re-running this script preserves it (a fresh one is generated only
# when the map file is missing or unreadable).
DB_PSQL=(psql -U postgres -d "${DBNAME}" -v ON_ERROR_STOP=1 -tAq)

# The role's GRANT needs the domains table, which update_database creates after
# the plugin is activated. Fail clearly rather than half-configure.
TABLE_CHECK="$("${DB_PSQL[@]}" -c "SELECT to_regclass('public.ied_inbound_email_domains') IS NOT NULL" 2>&1)" || {
    echo "ERROR: could not query PostgreSQL database '${DBNAME}': ${TABLE_CHECK}" >&2
    exit 1
}
if [[ "${TABLE_CHECK}" != "t" ]]; then
    echo "ERROR: table ied_inbound_email_domains does not exist in database '${DBNAME}'." >&2
    echo "       Activate the Mailbox plugin and run update_database, then re-run this script." >&2
    exit 1
fi

# Role name carries the database name so multiple sites on one PostgreSQL
# cluster never collide on a shared role.
DB_ROLE="iemap_$(printf '%s' "${DBNAME}" | tr -cd 'a-z0-9_')"

# Reuse the existing map password when the map file is intact, so the
# every-container-start re-assert (spec mail_stack_container_persistence) is
# not a needless credential rotation. A fresh 48-hex-char password is generated
# only when the map is missing or unreadable. od reads a fixed count and exits
# cleanly, so the pipe raises no SIGPIPE under `set -o pipefail`.
ROLE_PW=""
if [[ -r "${MAP_FILE}" ]]; then
    ROLE_PW="$(grep -oP '^password = \K.*' "${MAP_FILE}" 2>/dev/null | head -1 || true)"
fi
if [[ -z "${ROLE_PW}" ]]; then
    ROLE_PW="$(od -An -tx1 -N24 /dev/urandom | tr -dc 'a-f0-9')"
    if [[ ${#ROLE_PW} -ne 48 ]]; then
        echo "ERROR: failed to generate a role password." >&2
        exit 1
    fi
    echo "postfix: generated a new pgsql-map role password"
else
    echo "postfix: reusing the existing pgsql-map role password"
fi

# Create the role once; (re)assert its attributes, password and grants every
# run, so a re-run is a clean rotation.
if [[ "$("${DB_PSQL[@]}" -c "SELECT 1 FROM pg_roles WHERE rolname = '${DB_ROLE}'")" != "1" ]]; then
    "${DB_PSQL[@]}" -c "CREATE ROLE \"${DB_ROLE}\" LOGIN"
    echo "postgres: created role ${DB_ROLE}"
fi
# The password is set over stdin, never argv, so it stays out of the process list.
"${DB_PSQL[@]}" <<SQL
ALTER ROLE "${DB_ROLE}" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT PASSWORD '${ROLE_PW}';
GRANT CONNECT ON DATABASE "${DBNAME}" TO "${DB_ROLE}";
GRANT USAGE ON SCHEMA public TO "${DB_ROLE}";
GRANT SELECT ON ied_inbound_email_domains TO "${DB_ROLE}";
SQL
echo "postgres: role ${DB_ROLE} configured (SELECT on ied_inbound_email_domains only)"

# Render the map and install it locked down. The password reaches the renderer
# through the environment, never argv or the terminal.
MAP_TMP="$(mktemp)"
trap 'rm -f "${MAP_TMP}"' EXIT
if IEMAP_PASSWORD="${ROLE_PW}" "${PHP_BIN}" "${RENDER_SCRIPT}" "${DB_ROLE}" "${CONFIG_FILE}" > "${MAP_TMP}"; then
    install -m 640 -o root -g postfix "${MAP_TMP}" "${MAP_FILE}"
    echo "postfix: wrote pgsql domain map ${MAP_FILE} (640 root:postfix)"
else
    echo "ERROR: failed to render the pgsql domain map; virtual_mailbox_domains NOT changed." >&2
    exit 1
fi

# Postfix resolves virtual_mailbox_domains in smtpd / trivial-rewrite. If those
# services run chrooted, a bare file path is interpreted relative to
# /var/spool/postfix and the lookup fails — wire the map through proxymap
# (un-chrooted) instead. Modern Debian/Ubuntu ship these services un-chrooted.
postfix_chrooted() {
    local svc chroot_col
    for svc in smtp/inet rewrite/unix; do
        chroot_col="$(postconf -M "${svc}" 2>/dev/null | awk '{print $5}')"
        if [[ "${chroot_col}" == "y" ]]; then
            return 0
        fi
    done
    return 1
}
if postfix_chrooted; then
    VMD_MAP="proxy:pgsql:${MAP_FILE}"
    echo "postfix: chrooted smtpd/trivial-rewrite detected - wiring map via proxymap"
else
    VMD_MAP="pgsql:${MAP_FILE}"
fi
postconf -e "virtual_mailbox_domains = ${VMD_MAP}"
echo "main.cf: virtual_mailbox_domains = ${VMD_MAP}"

# --- 5. opendkim + opendmarc: verify-mode config + Postfix milters -----------
# opendkim signs outbound AND verifies inbound (Mode sv); opendmarc adds SPF +
# DMARC verdicts. Both stamp an Authentication-Results header the app reads.
# The static parts are deployment-independent and installed once here.

# AuthservID must equal mailbox_mail_hostname — the value the app's
# AuthenticationResults parser trusts. If they disagree the stamped AR lines are
# ignored and every message reads "unverified". Read it from the DB (the Setup
# tab writes it); fall back to myhostname with a loud warning.
AUTHSERV_ID="$("${DB_PSQL[@]}" -c "SELECT stg_value FROM stg_settings WHERE stg_name = 'mailbox_mail_hostname'" 2>/dev/null | head -1 | tr -d '[:space:]' || true)"
if [[ -z "${AUTHSERV_ID}" ]]; then
    AUTHSERV_ID="$(postconf -h myhostname 2>/dev/null | tr -d '[:space:]' || true)"
    echo "opendkim/opendmarc: mailbox_mail_hostname is unset — using myhostname '${AUTHSERV_ID}' as AuthservID." >&2
    echo "                    Set the mail hostname on the Mailbox Setup tab to match, or verdicts are ignored." >&2
else
    # The configured mail hostname IS this box's mail identity — align Postfix
    # myhostname (the HELO name) with it. The earlier myhostname block only
    # rescues localhost-ish defaults; this is the converge step once the
    # operator has chosen a hostname on the Setup tab.
    ALIGN_CURRENT="$(postconf -h myhostname 2>/dev/null | tr -d '[:space:]' || true)"
    if [[ "${ALIGN_CURRENT}" != "${AUTHSERV_ID}" ]]; then
        postconf -e "myhostname = ${AUTHSERV_ID}"
        echo "main.cf: myhostname = ${AUTHSERV_ID} (was '${ALIGN_CURRENT:-unset}'; aligned to mailbox_mail_hostname)"
    fi
fi
echo "opendkim/opendmarc: AuthservID = ${AUTHSERV_ID}"

mkdir -p /run/opendkim
chown opendkim:opendkim /run/opendkim 2>/dev/null || true

# key.table / signing.table / trusted.hosts: create only if absent — a re-run
# must never wipe per-domain key entries an operator has since added. The
# package ships only /etc/opendkim.conf, so the directory itself must be
# created on a fresh box.
mkdir -p /etc/opendkim
chown opendkim:opendkim /etc/opendkim 2>/dev/null || true
if [[ ! -f /etc/opendkim/key.table ]]; then
    : > /etc/opendkim/key.table
    echo "opendkim: created empty /etc/opendkim/key.table"
fi
if [[ ! -f /etc/opendkim/signing.table ]]; then
    : > /etc/opendkim/signing.table
    echo "opendkim: created empty /etc/opendkim/signing.table"
fi
if [[ ! -f /etc/opendkim/trusted.hosts ]]; then
    printf '127.0.0.1\n::1\nlocalhost\n' > /etc/opendkim/trusted.hosts
    echo "opendkim: created /etc/opendkim/trusted.hosts"
fi

# opendkim.conf: write our managed config only if our managed marker is absent.
# Keying on the marker (not the socket) means an already-wired host running the
# OLD managed conf — which lacked AuthservID — is upgraded in place, while an
# operator who kept our marker and edited around it is left alone. The live box
# was found running Debian-stock opendkim.conf (no Mode/AuthservID/tables),
# which this rewrite corrects, restoring both inbound verify and outbound sign.
OPENDKIM_MARKER='joinery-managed opendkim.conf'
if ! grep -qF "${OPENDKIM_MARKER}" /etc/opendkim.conf 2>/dev/null; then
    [[ -f /etc/opendkim.conf && ! -f /etc/opendkim.conf.pre-joinery ]] && \
        cp /etc/opendkim.conf /etc/opendkim.conf.pre-joinery
    cat > /etc/opendkim.conf <<OPENDKIMCONF
# ${OPENDKIM_MARKER} — managed by mailbox/provisioning/install_email.sh.
# Mode sv = sign outbound + VERIFY inbound. Per-domain keys live in the tables
# below (added by provision_dkim.sh). AuthservID attributes the stamped
# Authentication-Results line to us so the app trusts only our own verdicts.
Syslog                  yes
SyslogSuccess           yes
UMask                   007
Mode                    sv
Canonicalization        relaxed/simple
Socket                  inet:8891@localhost
PidFile                 /run/opendkim/opendkim.pid
OversignHeaders         From
UserID                  opendkim
AuthservID              ${AUTHSERV_ID}
KeyTable                /etc/opendkim/key.table
SigningTable            refile:/etc/opendkim/signing.table
ExternalIgnoreList      /etc/opendkim/trusted.hosts
InternalHosts           /etc/opendkim/trusted.hosts
OPENDKIMCONF
    echo "opendkim: wrote /etc/opendkim.conf (inet socket localhost:8891, Mode sv, AuthservID ${AUTHSERV_ID})"
else
    # Managed conf stays in place, but AuthservID must converge — the operator
    # may have set or changed the mail hostname since the conf was written.
    CUR_DKIM_AUTHSERV="$(awk '/^AuthservID/{print $2; exit}' /etc/opendkim.conf 2>/dev/null || true)"
    if [[ "${CUR_DKIM_AUTHSERV}" != "${AUTHSERV_ID}" ]]; then
        sed -i "s|^AuthservID.*|AuthservID              ${AUTHSERV_ID}|" /etc/opendkim.conf
        echo "opendkim: AuthservID converged to ${AUTHSERV_ID} (was '${CUR_DKIM_AUTHSERV:-unset}')"
    else
        echo "opendkim: /etc/opendkim.conf already managed by us - leaving it."
    fi
fi

# Debian's opendkim systemd integration can override the socket from
# /etc/default/opendkim — keep it in step with opendkim.conf.
if [[ -f /etc/default/opendkim ]]; then
    if grep -qE '^[[:space:]]*SOCKET=' /etc/default/opendkim; then
        sed -i 's#^[[:space:]]*SOCKET=.*#SOCKET="inet:8891@localhost"#' /etc/default/opendkim
    else
        echo 'SOCKET="inet:8891@localhost"' >> /etc/default/opendkim
    fi
fi

# opendmarc.conf: SPFSelfValidate makes opendmarc compute SPF itself from the
# envelope + connecting IP it sees at the milter stage (the IP the PHP pipe
# never receives), so no separate policyd-spf milter is needed. RejectFailures
# false / SoftwareHeader true = stamp results only, never reject (enforcement is
# out of scope; a DMARC failure still delivers and is recorded as a verdict).
mkdir -p /run/opendmarc
chown opendmarc:opendmarc /run/opendmarc 2>/dev/null || true

OPENDMARC_MARKER='joinery-managed opendmarc.conf'
if ! grep -qF "${OPENDMARC_MARKER}" /etc/opendmarc.conf 2>/dev/null; then
    [[ -f /etc/opendmarc.conf && ! -f /etc/opendmarc.conf.pre-joinery ]] && \
        cp /etc/opendmarc.conf /etc/opendmarc.conf.pre-joinery
    cat > /etc/opendmarc.conf <<OPENDMARCCONF
# ${OPENDMARC_MARKER} — managed by mailbox/provisioning/install_email.sh.
# Stamps SPF + DMARC into Authentication-Results; never rejects (stamp-only).
AuthservID              ${AUTHSERV_ID}
Socket                  inet:8893@localhost
PidFile                 /run/opendmarc/opendmarc.pid
UserID                  opendmarc
UMask                   0002
Syslog                  true
SoftwareHeader          true
SPFSelfValidate         true
RejectFailures          false
OPENDMARCCONF
    echo "opendmarc: wrote /etc/opendmarc.conf (inet socket localhost:8893, AuthservID ${AUTHSERV_ID})"
else
    # Same converge as opendkim: AuthservID must track the configured hostname.
    CUR_DMARC_AUTHSERV="$(awk '/^AuthservID/{print $2; exit}' /etc/opendmarc.conf 2>/dev/null || true)"
    if [[ "${CUR_DMARC_AUTHSERV}" != "${AUTHSERV_ID}" ]]; then
        sed -i "s|^AuthservID.*|AuthservID              ${AUTHSERV_ID}|" /etc/opendmarc.conf
        echo "opendmarc: AuthservID converged to ${AUTHSERV_ID} (was '${CUR_DMARC_AUTHSERV:-unset}')"
    else
        echo "opendmarc: /etc/opendmarc.conf already managed by us - leaving it."
    fi
fi

# Keep /etc/default/opendmarc SOCKET in step with the conf (mirrors opendkim).
if [[ -f /etc/default/opendmarc ]]; then
    if grep -qE '^[[:space:]]*SOCKET=' /etc/default/opendmarc; then
        sed -i 's#^[[:space:]]*SOCKET=.*#SOCKET="inet:8893@localhost"#' /etc/default/opendmarc
    else
        echo 'SOCKET="inet:8893@localhost"' >> /etc/default/opendmarc
    fi
fi

# Postfix milter wiring. Order matters: opendkim FIRST so opendmarc can consume
# its DKIM result (plus opendmarc's own SPF) to reach a DMARC verdict.
# milter_default_action = accept guarantees a down/keyless milter never blocks
# or defers mail. non_smtpd_milters keeps only opendkim (it signs locally
# submitted outbound; opendmarc applies to inbound, not local submission).
postconf -e "milter_default_action = accept"
postconf -e "smtpd_milters = inet:localhost:8891, inet:localhost:8893"
postconf -e "non_smtpd_milters = inet:localhost:8891"
echo "main.cf: milters wired (opendkim:8891 then opendmarc:8893; default action accept)"

systemctl enable opendkim >/dev/null 2>&1 || true
if command -v systemctl >/dev/null 2>&1 && systemctl restart opendkim 2>/dev/null; then
    echo "opendkim: restarted (systemd)."
elif command -v service >/dev/null 2>&1 && service opendkim restart >/dev/null 2>&1; then
    echo "opendkim: restarted (service)."
else
    echo "WARNING: could not restart opendkim automatically - restart it manually." >&2
fi

systemctl enable opendmarc >/dev/null 2>&1 || true
if command -v systemctl >/dev/null 2>&1 && systemctl restart opendmarc 2>/dev/null; then
    echo "opendmarc: restarted (systemd)."
elif command -v service >/dev/null 2>&1 && service opendmarc restart >/dev/null 2>&1; then
    echo "opendmarc: restarted (service)."
else
    echo "WARNING: could not restart opendmarc automatically - restart it manually." >&2
fi

# --- 5b. local spam scanner (ships with the mail stack) -----------------------
# The scanner is part of the mail stack, unconditionally: every box this script
# provisions gets rspamd + redis, so turning spam learning on later is a pure
# settings toggle - nothing to install on day 2, no command for the owner to
# paste. Whether and how the scanner is USED (milter scoring, ingest re-scoring,
# the learning loop) is decided in software by MailboxSpamPolicy; idle, it costs
# a dormant service. The install itself lives in provision_spam_scanner.sh
# (idempotent; also the repair for config or milter-wiring drift). The platform
# never removes it - `provision_spam_scanner.sh remove` exists for operators
# reclaiming a box by hand.
SPAM_SCANNER_SCRIPT="${SCRIPT_DIR}/provision_spam_scanner.sh"
if [[ ! -f "${SPAM_SCANNER_SCRIPT}" ]]; then
    echo "WARNING: spam scanner provisioner missing - skipping (expected ${SPAM_SCANNER_SCRIPT})." >&2
else
    echo "spam-scanner: installing (ships with the mail stack)"
    bash "${SPAM_SCANNER_SCRIPT}" install
fi

# --- 6. firewall -------------------------------------------------------------
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
    ufw allow 25/tcp >/dev/null 2>&1 || true
    echo "firewall: ufw allow 25/tcp"
fi

# --- 7. retire the old generated setup script --------------------------------
# The Domains page used to regenerate setup_email_forwarding.sh on every load.
# Option C removed that; delete any stale copy so no bug-carrying script lingers.
STALE_SCRIPT="${PLUGIN_DIR}/setup_email_forwarding.sh"
if [[ -f "${STALE_SCRIPT}" ]]; then
    rm -f "${STALE_SCRIPT}"
    echo "cleanup: removed stale ${STALE_SCRIPT}"
fi

# --- 8. validate + restart ---------------------------------------------------
# inet_interfaces and milter changes need a full restart, not a reload. Prefer
# systemd when present; fall back to the `postfix` command for containers.
if postfix check; then
    if command -v systemctl >/dev/null 2>&1 && systemctl restart postfix 2>/dev/null; then
        echo "Postfix configuration validated and restarted (systemd)."
    else
        postfix stop 2>/dev/null || true
        postfix start
        echo "Postfix configuration validated and (re)started."
    fi
else
    echo "WARNING: 'postfix check' reported problems - NOT restarting. Review above." >&2
    exit 1
fi

# --- 9. relay tunnel helpers: converge them if this box fronts a relay --------
# provision_relay_main.sh writes root helpers (joinery-relay-peer and its
# siblings) into /usr/local/sbin. Those are INSTALLED COPIES, so shipping a
# corrected script does not correct a helper already sitting on disk: without
# this step a fix can deploy and still never take effect, and the box keeps
# running whatever version happened to be installed the day someone last ran the
# provisioner by hand. This installer IS the declared host_installer and runs on
# deploys, so converge the helpers here.
#
# Only on a box that already carries relay identity, though — running the
# provisioner unprompted would mint tunnel keys and register a WireGuard public
# key on hosts that front no relay at all. Converge what exists; never conjure it.
RELAY_MAIN="${SCRIPT_DIR}/provision_relay_main.sh"
if [[ -f "/etc/wireguard/jyrelay0.conf" || -x "/usr/local/sbin/joinery-relay-peer" ]]; then
    if [[ ! -f "${RELAY_MAIN}" ]]; then
        echo "WARNING: relay identity present but ${RELAY_MAIN} is missing - helpers NOT converged." >&2
    elif bash "${RELAY_MAIN}"; then
        echo "Relay tunnel helpers converged."
    else
        # Never fail the mail install over this: the mail stack is configured and
        # running by now, and the Setup tab surfaces a broken tunnel on its own.
        echo "WARNING: relay helper convergence failed - run 'sudo bash ${RELAY_MAIN}' manually." >&2
    fi
else
    echo "No relay identity on this box - skipping relay helper convergence."
fi

# --- summary -----------------------------------------------------------------
echo
echo "Base mail setup complete."
echo "  - Inbound domains are read live from the database; add them under"
echo "    Admin > Emails > Incoming > Domains. No host action is needed per domain."
echo "  - Publish DNS per domain: MX -> this server, plus SPF and DKIM TXT records."
echo "  - For outbound DKIM signing, generate a per-domain key with:"
echo "    sudo bash plugins/mailbox/provisioning/provision_dkim.sh <domain>"
echo "    then publish the DKIM TXT record it prints. See the Setup tab."
echo "  - Inbound authentication: opendkim (verify) + opendmarc now stamp an"
echo "    Authentication-Results header the app reads for SPF/DKIM/DMARC."
echo "    CONFIRM IT WORKS: send a test message and check the stored copy carries"
echo "    'Authentication-Results: ${AUTHSERV_ID}; dkim=... spf=... dmarc=...'."
echo "    Config edits alone don't prove it — the Setup tab's 'Inbound"
echo "    authentication verified' check goes PASS once milter-stamped mail arrives."
