#!/usr/bin/env bash
#
# install_email.sh - host installer + base configurator for Mailbox.
#
# Version: 2.27 - In a site container this installs and starts nothing: a container has no mail
#                server of its own and receives mail through a relay
#                (specs/multi_tenant_docker_hosts.md WP9). It says so and exits 0.
# Version: 2.26 - The two old programs are removed only once Postfix names rspamd alone and
#                rspamd is answering; rspamd's milter listens on the loopback only.
# Version: 2.25 - rspamd is the only program that checks mail (mail_checking_in_rspamd.md):
#                it writes the SPF, DKIM and DMARC verdicts the app reads and signs
#                outgoing mail for a domain that has a key. opendkim and opendmarc are
#                no longer installed; a box that has them gets its signing keys copied
#                to /etc/rspamd/dkim and both programs removed, after Postfix has been
#                told rspamd is its only milter.
# Version: 2.24 - A sender is refused only on Spamhaus's real "listed" answers. The bare list
#                names refused on any answer, including the error code Spamhaus gives a
#                resolver it will not serve, which would have refused all mail.
# Version: 2.23 - A message may be 25 MiB, the size the router stores (Postfix's own default
#                refused anything over 10 MB), and a bare line feed can no longer end a
#                message (smtpd_forbid_bare_newline, the SMTP smuggling guard).
# Version: 2.22 - The recipient lookup is asked from the freshly rendered copy before it replaces
#                the installed one, so a query that cannot run never reaches a Postfix
#                already pointed at the map. A probe refused by the map role's connection
#                limit (Postfix holds them all) leaves the proven lookup in place and
#                carries on, instead of failing the whole run.
# Version: 2.21 - The recipient lookup is always read through proxymap (proxy:pgsql:), so every
#                smtpd process shares proxymap's connections instead of opening its own,
#                and proxy_read_maps is asserted to allow it; the map role may hold at
#                most 10 connections, so a flood answers "try again later" instead of
#                exhausting the site's database.
# Version: 2.20 - Unknown recipients are refused during the SMTP conversation: section 4
#                renders a second pgsql map (joinery-recipient-access.cf) that answers
#                each recipient the way InboundEmailRouter would, grants the map role the
#                four alias columns it reads, proves the lookup as that role, and only
#                then adds check_recipient_access to smtpd_recipient_restrictions. The
#                pipe passes the queue id, so the handler can drop a deferral that would
#                otherwise expire into a bounce.
# Version: 2.19 - Section 5's milter list ends with rspamd (11332) whenever the scanner
#                provisioner is here, so a re-run no longer drops the scanner from
#                Postfix until section 5b appends it again.
# Version: 2.18 - Section 5b's scanner is stateless rspamd (provision_spam_scanner.sh 2.0):
#                no redis, no ingest re-scan, no learning loop. Section 5b also checks
#                for rspamd_stateless.sh, which the scanner provisioner sources.
# Version: 2.17 - Reads the database user and password from the site's own config instead
#                of connecting as `psql -U postgres` on an inherited PGPASSWORD. That
#                environment exists under the container CMD and under nothing else, so
#                the upgrade's installer dispatch applied the main.cf edits and then died
#                at section 4, leaving a mail host routing through a pgsql map whose role
#                and grants had not been re-asserted. Same fix, same reason, as
#                _plugin_installers_start.sh v1.3
# Version: 2.16 - dbconfig-no-thanks is installed alongside opendmarc, which
#                depends on `dbconfig-mysql | dbconfig-no-thanks`. Left to
#                choose, apt takes the first: a MySQL client stack lands on a
#                PostgreSQL-only box and dbconfig-common fails trying to reach a
#                MySQL server that was never there, printing two ERROR lines into
#                every install log. Only the report tooling wants that database
#                and nothing here runs it
# Version: 2.16 - Honour the recorded listener decommission. This script is the
#                declared host_installer and runs on EVERY deploy, so its
#                unconditional enable/start of postfix, opendkim and opendmarc
#                (and its ufw allow 25/tcp) undid the decommission on every
#                upgrade - the box came back with the whole local mail stack
#                running and re-armed for boot. Configuration is still converged;
#                only service arming and the port-25 rule are skipped, and the
#                skip re-asserts the decommission so a deploy heals a drifted box.
#                All postfix arming moved into section 8 so there is one gate.
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
# rspamd configuration so inbound mail is checked and piped to the handler.
# Fully idempotent: re-running adds nothing twice and is safe.
#
# What it configures (fixed, deployment-independent):
#   - Installs postfix, postfix-pgsql and rspamd.
#   - master.cf : the `joinery` pipe transport (appended once).
#   - main.cf   : virtual_transport = joinery
#   - main.cf   : inet_interfaces = all - with one site per host, this site's
#                 Postfix IS the host's mail server.
#   - main.cf   : mydestination = localhost, localhost.localdomain
#                 (inbound domains must NOT appear here, or Postfix rejects
#                  them with "User unknown in local recipient table").
#   - main.cf   : message_size_limit = 26214400 (the 25 MiB the router stores)
#                 and smtpd_forbid_bare_newline = yes (a bare line feed cannot
#                 end a message: the SMTP smuggling guard).
#   - main.cf   : smtpd_recipient_restrictions with RBL clients and a
#                 check_recipient_access lookup against
#                 /etc/postfix/joinery-recipient-access.cf, so an address the
#                 site does not have is refused while the sender is still
#                 connected (5.1.1), never bounced afterwards to a sender
#                 address spam has forged.
#   - main.cf   : virtual_mailbox_domains = pgsql:/etc/postfix/joinery-domains.cf
#                 Postfix asks the database whether a recipient domain is an
#                 active inbound domain, so adding or removing a domain in
#                 the admin UI takes effect immediately - no host action, no
#                 drift. /etc/postfix/joinery-domains.cf is the pgsql map.
#   - postgres  : a dedicated least-privilege role the pgsql maps authenticate
#                 as - SELECT on the inbound-domains table and on the four alias
#                 columns the recipient lookup reads, never the application's
#                 superuser, holding at most 10 connections. Its password
#                 lives only in the map files and is kept while they are
#                 intact; delete them and re-run this script to rotate it.
#   - rspamd    : the one checker (provision_spam_scanner.sh). As a milter on
#                 localhost:11332 it stamps the Authentication-Results line the
#                 app reads for its SPF/DKIM/DMARC verdicts (the app never
#                 computes them itself) and the X-Spam headers, and it signs
#                 what this box sends for a domain that has a key. It never
#                 refuses or delays mail.
#   - main.cf   : smtpd_milters = non_smtpd_milters = inet:localhost:11332,
#                 milter_default_action = accept (a stopped rspamd must never
#                 block or defer mail), and myhostname held equal to the
#                 configured mail hostname: rspamd stamps its verdicts under
#                 the name Postfix gives it, and that is the name the app
#                 trusts.
#   - Opens port 25 if ufw is active.
#
# What it does NOT do (genuinely per-deployment - handled elsewhere):
#   - The inbound-domain list: NOT installed at all - Postfix reads it live
#     from the database (see above). Manage domains under
#     Admin > Emails > Incoming > Domains.
#   - DNS records (MX, SPF, DKIM).
#   - Per-domain DKIM signing keys and their DNS TXT record. rspamd signs
#     nothing for a domain until it has a key; run provision_dkim.sh <domain>
#     for each domain. See plugins/mailbox/docs/overview.md.
#
# A site container has no mail server: this script installs and starts
# nothing there and exits 0. Its mail arrives through a relay, which checks it,
# and every sending provider is an API or an outside server.
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
    echo "Install postfix, postfix-pgsql and rspamd with your platform's package manager instead." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
PIPE_SCRIPT="${PLUGIN_DIR}/utils/inbound_email_handler.php"
RENDER_SCRIPT="${SCRIPT_DIR}/render_pgsql_map.php"
MAP_FILE="/etc/postfix/joinery-domains.cf"
RCPT_MAP_FILE="/etc/postfix/joinery-recipient-access.cf"

if [[ ! -f "${PIPE_SCRIPT}" ]]; then
    echo "ERROR: inbound email handler not found at ${PIPE_SCRIPT}" >&2
    echo "Run this script from inside the mailbox plugin directory." >&2
    exit 1
fi
if [[ ! -f "${RENDER_SCRIPT}" ]]; then
    echo "ERROR: pgsql map renderer not found at ${RENDER_SCRIPT}" >&2
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

# The site config sits alongside public_html, four levels up from provisioning/.
# It names the database, and it holds the credentials to reach it.
SITE_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
CONFIG_FILE="${SITE_ROOT}/config/Globalvars_site.php"
if [[ ! -f "${CONFIG_FILE}" ]]; then
    echo "ERROR: site config not found at ${CONFIG_FILE}" >&2
    exit 1
fi

# One setting out of the site config, by name.
#
# This script inherits NOTHING about the database. It used to connect as
# `psql -U postgres` on whatever PGPASSWORD happened to be in the environment:
# true under the container CMD, which exports it, and false under every other
# caller. Under the upgrade's installer dispatch it therefore half-ran — the
# main.cf edits landed, then section 4 died on the first psql, leaving a mail
# host configured to route mail through a pgsql map whose role and grants had
# never been re-asserted. Reading the credentials from the site's own config is
# the same fix _plugin_installers_start.sh v1.3 made, for the same reason.
read_site_setting() {
    "${PHP_BIN}" -r '
        $config = file_get_contents($argv[1]);
        echo preg_match("/settings\[.".$argv[2].".\]\s*=\s*.([^\x27\"]*)/", $config, $m) ? $m[1] : "";
    ' "${CONFIG_FILE}" "$1"
}

# A site container has no mail server of its own (the installer records where
# the site runs as deployment_environment). Exit 0: this runs at every container
# start, and the start continues.
if [[ "$(read_site_setting deployment_environment)" == "docker" ]]; then
    echo "This site runs in a container: it has no mail server of its own and receives mail"
    echo "through a relay, which checks it. Nothing to install or start here."
    exit 0
fi

DBNAME="$(read_site_setting dbname)"
if [[ -z "${DBNAME}" ]]; then
    echo "ERROR: could not read dbname from ${CONFIG_FILE}" >&2
    exit 1
fi
DBUSER="$(read_site_setting dbusername)"
if [[ -z "${DBUSER}" ]]; then
    echo "ERROR: could not read dbusername from ${CONFIG_FILE}" >&2
    exit 1
fi
DBHOST="$(read_site_setting dbhost)"
# Never echoed, never passed in argv: it reaches psql through PGPASSWORD in the
# environment of that one command, which is where psql looks for it anyway.
DBPASS="$(read_site_setting dbpassword)"

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
# rspamd is not in this list: provision_spam_scanner.sh installs it (section 5),
# without the redis-server apt would otherwise bring along.
PACKAGES=(postfix postfix-pgsql "php${PHP_VERSION}-sqlite3")
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

# Postfix is NOT armed here. All of its service arming lives in section 8, in
# one place, behind the decommission guard - a box whose local listener is
# decommissioned must come out of this script still decommissioned.

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
# \${queue_id} lets the handler find when this box accepted the message, so a
# deferral about to outlive the queue is dropped and logged instead of expiring
# into a bounce to the sender.
JOINERY_ARGV="argv=${PHP_BIN} ${PIPE_SCRIPT} \${recipient} \${queue_id}"
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

# A message may be as large as InboundEmailRouter stores (25 MiB). Postfix counts
# its own envelope records in the limit, so a message it lets through is always
# under the router's cap and an oversized one is refused during the SMTP
# conversation, never accepted and then bounced.
postconf -e "message_size_limit = 26214400"
echo "main.cf: message_size_limit = 26214400"

# SMTP smuggling (CVE-2023-51764): a line ending in a bare line feed must never
# end a message, or one message can carry a second with a forged sender. "yes"
# is "normalize" on every Postfix that has it and the stricter refusal on the
# first releases that carried the parameter. A Postfix too old to know the
# parameter is left alone, since an unknown parameter is only noise there.
if [[ -n "$(postconf -dh smtpd_forbid_bare_newline 2>/dev/null)" ]]; then
    postconf -e "smtpd_forbid_bare_newline = yes"
    echo "main.cf: smtpd_forbid_bare_newline = yes"
else
    echo "WARNING: this Postfix has no smtpd_forbid_bare_newline; upgrade Postfix to close SMTP smuggling." >&2
fi

# smtpd_recipient_restrictions is set in section 4, once the recipient lookup
# it ends with exists and has answered.

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
#
# Connects as the site's own database user with the site's own password, both
# read from the config above. That user owns this database and is the account
# the site itself uses, so it can do everything below; nothing here depends on
# the environment the caller happened to have.
DB_PSQL_ARGS=(-U "${DBUSER}" -d "${DBNAME}" -v ON_ERROR_STOP=1 -tAq)
if [[ -n "${DBHOST}" && "${DBHOST}" != "localhost" ]]; then
    DB_PSQL_ARGS+=(-h "${DBHOST}")
fi
# A prefix assignment, so the password is in psql's environment and not in
# anyone's argv — the same rule the role password below is set under.
db_psql() {
    PGPASSWORD="${DBPASS}" psql "${DB_PSQL_ARGS[@]}" "$@"
}

# The role's GRANT needs the domains table, which update_database creates after
# the plugin is activated. Fail clearly rather than half-configure.
TABLE_CHECK="$(db_psql -c "SELECT to_regclass('public.ied_inbound_email_domains') IS NOT NULL" 2>&1)" || {
    echo "ERROR: could not query PostgreSQL database '${DBNAME}' as '${DBUSER}': ${TABLE_CHECK}" >&2
    exit 1
}
if [[ "${TABLE_CHECK}" == "t" ]]; then
    TABLE_CHECK="$(db_psql -c "SELECT to_regclass('public.iea_inbound_email_aliases') IS NOT NULL" 2>&1)" || {
        echo "ERROR: could not query PostgreSQL database '${DBNAME}' as '${DBUSER}': ${TABLE_CHECK}" >&2
        exit 1
    }
fi
if [[ "${TABLE_CHECK}" != "t" ]]; then
    echo "ERROR: the mailbox tables (ied_inbound_email_domains, iea_inbound_email_aliases) do not exist in database '${DBNAME}'." >&2
    echo "       Activate the Mailbox plugin and run update_database, then re-run this script." >&2
    exit 1
fi

# --- the decommission guard --------------------------------------------------
# A relay-fronted box can have its own mail listener deliberately removed from
# the Setup tab (specs/mailbox_listener_decommission.md), which stops and
# disables postfix and shuts port 25. This script is the
# mailbox plugin's declared host_installer, so it RUNS ON EVERY DEPLOY - and
# without this guard each deploy silently re-enabled and restarted it and
# reopened the firewall, undoing the decommission and leaving the setup check
# reporting "recorded as decommissioned, but ... is running".
#
# Configuration is still written on a decommissioned box: main.cf, master.cf,
# the maps and the rspamd configuration must stay converged so Restore
# brings back a correct listener rather than a stale one. Only the ARMING -
# enable, start, restart, and the port-25 firewall rule - is skipped.
#
# The recorded setting is the intent and is what we read. db_psql authenticates
# from the site's own config, not the caller's environment, so nothing about how
# this script was invoked can break the read.
#
# READ FAILURE MUST NOT FAIL OPEN. The setting is seeded into stg_settings from
# plugin.json, so it is normally present. If the query itself fails, swallowing
# the error would leave LISTENER_RECORDED empty, the guard would read that as
# "not decommissioned", and this script would re-arm the very box it is meant to
# leave alone - the exact bug it exists to prevent. So the query is checked the
# same way TABLE_CHECK above is: a failure stops the script. A SUCCESSFUL query
# returning no row is a different thing (a box whose seed has not run yet) and
# is the factory state, 'active'.
LISTENER_RECORDED="$(db_psql -c "SELECT stg_value FROM stg_settings WHERE stg_name = 'mailbox_local_listener'" 2>&1)" || {
    echo "ERROR: could not read the recorded mail listener state from '${DBNAME}': ${LISTENER_RECORDED}" >&2
    echo "       Refusing to continue: this script arms postfix, and" >&2
    echo "       without that setting it cannot tell whether this box's listener was" >&2
    echo "       deliberately decommissioned. Fix database access and re-run." >&2
    exit 1
}
LISTENER_RECORDED="$(printf '%s' "${LISTENER_RECORDED}" | head -1 | tr -d '[:space:]')"
if [[ "${LISTENER_RECORDED,,}" == "decommissioned" ]]; then
    LISTENER_DECOMMISSIONED=1
    echo
    echo "listener: recorded as DECOMMISSIONED - configuration will be converged, but"
    echo "          postfix will NOT be enabled or started and"
    echo "          port 25 will NOT be reopened. Restore from the Setup tab's Relay"
    echo "          section to put local mail back."
    echo
else
    LISTENER_DECOMMISSIONED=0
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
# run. CONNECTION LIMIT bounds what Postfix can take from the site's database:
# past it a lookup fails, and Postfix answers "try again later".

if [[ "$(db_psql -c "SELECT 1 FROM pg_roles WHERE rolname = '${DB_ROLE}'")" != "1" ]]; then
    db_psql -c "CREATE ROLE \"${DB_ROLE}\" LOGIN"
    echo "postgres: created role ${DB_ROLE}"
fi
# The password is set over stdin, never argv, so it stays out of the process list.
db_psql <<SQL
ALTER ROLE "${DB_ROLE}" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT CONNECTION LIMIT 10 PASSWORD '${ROLE_PW}';
GRANT CONNECT ON DATABASE "${DBNAME}" TO "${DB_ROLE}";
GRANT USAGE ON SCHEMA public TO "${DB_ROLE}";
GRANT SELECT ON ied_inbound_email_domains TO "${DB_ROLE}";
GRANT SELECT (iea_ied_inbound_email_domain_id, iea_alias, iea_is_enabled, iea_delete_time) ON iea_inbound_email_aliases TO "${DB_ROLE}";
SQL
echo "postgres: role ${DB_ROLE} configured (SELECT on ied_inbound_email_domains, and on the alias columns the recipient lookup reads)"

# Render the map and install it locked down. The password reaches the renderer
# through the environment, never argv or the terminal.
MAP_TMP="$(mktemp)"
trap 'rm -f "${MAP_TMP}"' EXIT
if IEMAP_PASSWORD="${ROLE_PW}" "${PHP_BIN}" "${RENDER_SCRIPT}" "${DB_ROLE}" "${CONFIG_FILE}" domains > "${MAP_TMP}"; then
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

# The recipient lookup: the same role, the same tables the router reads, asked
# during the SMTP conversation. See render_pgsql_map.php recipient_access_query()
# for what it answers.
if ! IEMAP_PASSWORD="${ROLE_PW}" "${PHP_BIN}" "${RENDER_SCRIPT}" "${DB_ROLE}" "${CONFIG_FILE}" recipient_access > "${MAP_TMP}"; then
    echo "ERROR: failed to render the pgsql recipient lookup; smtpd_recipient_restrictions NOT changed." >&2
    exit 1
fi

# Ask it once, as the map role, before Postfix reads it. A lookup that cannot
# run (a missing grant, a query the database refuses) would answer every
# recipient with "try again later" - no mail lost, but none arriving either.
# The freshly rendered copy is asked, not the installed file: an earlier run
# may already have pointed Postfix at the installed one, so a new query
# replaces it only once it has answered here. The probe domain is reserved
# (.invalid), so the answer itself is empty; what is proved is that it ran.
#
# The probe logs in as the map role, so it counts against the role's
# connection limit. When Postfix already holds them all (a flood), the probe
# cannot be asked: the installed lookup, proven on an earlier run, is left in
# place, and the rest of this script carries on.
RCPT_QUERY="$(grep -oP '^query = \K.*' "${MAP_TMP}" | head -1)"
RCPT_PROBE="${RCPT_QUERY//%u/postmaster}"
RCPT_PROBE="${RCPT_PROBE//%d/probe.invalid}"
RCPT_PROBE="${RCPT_PROBE//%%/%}"
RCPT_PSQL_ARGS=(-h localhost -U "${DB_ROLE}" -d "${DBNAME}" -v ON_ERROR_STOP=1 -tAq)
RCPT_WIRE=1
if ! RCPT_PROBE_OUT="$(PGPASSWORD="${ROLE_PW}" psql "${RCPT_PSQL_ARGS[@]}" <<<"${RCPT_PROBE};" 2>&1)"; then
    if [[ "${RCPT_PROBE_OUT}" == *"too many connections for role"* ]]; then
        echo "WARNING: the recipient lookup could not be asked: ${DB_ROLE} is at its connection limit (Postfix is busy)." >&2
        echo "         The lookup Postfix already reads is left as it is; re-run this script to update it." >&2
        RCPT_WIRE=0
    else
        echo "ERROR: the recipient lookup did not run as ${DB_ROLE}: ${RCPT_PROBE_OUT}" >&2
        echo "       smtpd_recipient_restrictions NOT changed." >&2
        exit 1
    fi
fi
if [[ "${RCPT_WIRE}" -eq 1 ]]; then
    install -m 640 -o root -g postfix "${MAP_TMP}" "${RCPT_MAP_FILE}"
    echo "postfix: wrote pgsql recipient lookup ${RCPT_MAP_FILE} (640 root:postfix)"
    echo "postfix: recipient lookup answers as ${DB_ROLE}"
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
#
# Each list is followed by the answers that mean "listed" (zen 127.0.0.2-11,
# dbl 127.0.1.2-99). A bare list name refuses on ANY answer, and Spamhaus
# answers 127.255.255.x to a resolver it will not serve (a public resolver, or
# one over its quota): every sender would then be refused. With the filter such
# an answer refuses nobody, and the Setup tab reports that Spamhaus is not
# answering this box.
#
# The recipient lookup comes last, after the RBL checks, so a listed sender is
# refused on the list and never costs a database query. Every smtpd process
# asks it, so it is always read through proxymap (proxy:), chrooted or not:
# the smtpd processes share proxymap's few connections instead of each opening
# one. proxymap reads only maps proxy_read_maps names; its default covers
# $smtpd_recipient_restrictions, and a box whose setting does not gets the map
# added rather than a lookup that fails on every recipient.
RCPT_MAP="proxy:pgsql:${RCPT_MAP_FILE}"
PROXY_READ_MAPS="$(postconf -h proxy_read_maps 2>/dev/null || true)"
if [[ "${PROXY_READ_MAPS}" != *'$smtpd_recipient_restrictions'* && "${PROXY_READ_MAPS}" != *"${RCPT_MAP}"* ]]; then
    postconf -e "proxy_read_maps = ${PROXY_READ_MAPS} ${RCPT_MAP}"
    echo "main.cf: proxy_read_maps += ${RCPT_MAP}"
fi
if [[ "${RCPT_WIRE}" -eq 1 ]]; then
    postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination, reject_rbl_client zen.spamhaus.org=127.0.0.[2..11], reject_rhsbl_helo dbl.spamhaus.org=127.0.1.[2..99], reject_rhsbl_sender dbl.spamhaus.org=127.0.1.[2..99], check_recipient_access ${RCPT_MAP}, permit"
    echo "main.cf: smtpd_recipient_restrictions set (RBL clients, recipient lookup ${RCPT_MAP})"
else
    echo "main.cf: smtpd_recipient_restrictions left as they are (recipient lookup not re-asked)"
fi

# --- 5. rspamd: the one program that checks mail -------------------------------
# rspamd is Postfix's only milter (mail_checking_in_rspamd.md). On arriving
# mail it strips every Authentication-Results line the sender supplied, writes
# its own SPF, DKIM and DMARC verdicts and stamps the X-Spam headers; on what
# this box sends it adds the DKIM signature for a domain that has a key. It
# never refuses or delays mail, has no memory, and the app makes the final
# decision: there is no redis, no ingest re-scan and no learning loop in rspamd
# (spam_learning_in_core.md).

# rspamd stamps its verdicts under the name Postfix gives it, which is
# myhostname, and the app's AuthenticationResults parser trusts only lines
# under mailbox_mail_hostname. So the two are held equal here: if they
# disagree every message reads "unverified". Read the setting from the DB (the
# Setup tab writes it); with none set, whatever myhostname already is stands.
AUTHSERV_ID="$(db_psql -c "SELECT stg_value FROM stg_settings WHERE stg_name = 'mailbox_mail_hostname'" 2>/dev/null | head -1 | tr -d '[:space:]' || true)"
if [[ -z "${AUTHSERV_ID}" ]]; then
    AUTHSERV_ID="$(postconf -h myhostname 2>/dev/null | tr -d '[:space:]' || true)"
    echo "rspamd: mailbox_mail_hostname is unset — verdicts are stamped under myhostname '${AUTHSERV_ID}'." >&2
    echo "        Set the mail hostname on the Mailbox Setup tab to match, or verdicts are ignored." >&2
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
echo "rspamd: verdicts are stamped under ${AUTHSERV_ID}"

# A stopped rspamd must never block or defer mail: it arrives unchecked, and
# the health check reports the scanner down.
postconf -e "milter_default_action = accept"

# The install itself lives in provision_spam_scanner.sh, which writes the one
# scanning configuration a relay writes too (rspamd_stateless.sh) plus the
# signing configuration, copies any key opendkim signed with into rspamd's key
# directory, and sets both of Postfix's milter lists to rspamd alone. It is
# idempotent and also the repair for config or milter-wiring drift. The
# platform never removes rspamd - `provision_spam_scanner.sh remove` exists
# for operators reclaiming a box by hand.
SPAM_SCANNER_SCRIPT="${SCRIPT_DIR}/provision_spam_scanner.sh"
if [[ ! -f "${SPAM_SCANNER_SCRIPT}" || ! -f "${SCRIPT_DIR}/rspamd_stateless.sh" ]]; then
    echo "ERROR: provision_spam_scanner.sh or rspamd_stateless.sh is missing from ${SCRIPT_DIR}." >&2
    echo "       rspamd is the only program that checks mail; refusing to leave Postfix without it." >&2
    exit 1
fi
echo "rspamd: installing (ships with the mail stack)"
bash "${SPAM_SCANNER_SCRIPT}" install

# Postfix now names rspamd alone and has been told so, which is what makes it
# safe to remove the two programs rspamd replaced from a box that has them. If
# the scanner could not get there (rspamd would not start), they stay, and the
# next run tries again.
# shellcheck source=rspamd_stateless.sh
source "${SCRIPT_DIR}/rspamd_stateless.sh"
if [[ "$(postconf -h smtpd_milters 2>/dev/null || true)" == "inet:localhost:11332" ]]; then
    mail_checkers_retire_old
else
    echo "WARNING: Postfix does not name rspamd as its only milter yet - the programs it replaces are left as they are." >&2
fi

# --- 6. firewall -------------------------------------------------------------
# Never reopen 25/tcp on a decommissioned box: closing it is half of what the
# decommission did, and nothing would be listening behind it anyway.
if [[ "${LISTENER_DECOMMISSIONED}" -eq 1 ]]; then
    if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
        ufw delete allow 25/tcp >/dev/null 2>&1 || true
        ufw deny 25/tcp >/dev/null 2>&1 || true
    fi
    echo "firewall: 25/tcp kept closed (listener decommissioned)."
elif command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "Status: active"; then
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
# inet_interfaces changes need a full restart, not a reload. Prefer
# systemd when present; fall back to the `postfix` command for containers.
if postfix check; then
    if [[ "${LISTENER_DECOMMISSIONED}" -eq 1 ]]; then
        # Validated, converged, and deliberately left down. `postfix check` is
        # still worth running: it proves the config a Restore would bring up.
        systemctl disable --now postfix >/dev/null 2>&1 || true
        echo "Postfix configuration validated; stopped and disabled (listener decommissioned)."
    else
        systemctl enable postfix >/dev/null 2>&1 || true
        if command -v systemctl >/dev/null 2>&1 && systemctl restart postfix 2>/dev/null; then
            echo "Postfix configuration validated and restarted (systemd)."
        else
            postfix stop 2>/dev/null || true
            postfix start
            echo "Postfix configuration validated and (re)started."
        fi
    fi
else
    echo "WARNING: 'postfix check' reported problems - NOT restarting. Review above." >&2
    exit 1
fi

# --- 9. relay helpers: converge them if this box fronts a relay -----------------
# provision_relay_main.sh writes two root helpers (joinery-mail-listener,
# joinery-dkim-remove) into /usr/local/sbin. Those are INSTALLED COPIES, so
# shipping a corrected script does not correct a helper already sitting on disk:
# without this step a fix can deploy and still never take effect. This installer
# IS the declared host_installer and runs on deploys, so converge the helpers
# here - on a box that already carries them. Converge what exists; never conjure it.
RELAY_MAIN="${SCRIPT_DIR}/provision_relay_main.sh"
if [[ -x "/usr/local/sbin/joinery-mail-listener" || -x "/usr/local/sbin/joinery-dkim-remove" ]]; then
    if [[ ! -f "${RELAY_MAIN}" ]]; then
        echo "WARNING: relay identity present but ${RELAY_MAIN} is missing - helpers NOT converged." >&2
    elif bash "${RELAY_MAIN}"; then
        echo "Relay helpers converged."
    else
        # Never fail the mail install over this: the mail stack is configured and
        # running by now, and the Setup tab surfaces a missing helper on its own.
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
echo "  - Inbound authentication: rspamd stamps an Authentication-Results"
echo "    header the app reads for SPF/DKIM/DMARC."
echo "    CONFIRM IT WORKS: send a test message and check the stored copy carries"
echo "    'Authentication-Results: ${AUTHSERV_ID}; dkim=... spf=... dmarc=...'."
echo "    Config edits alone don't prove it — the Setup tab's 'Inbound"
echo "    authentication verified' check goes PASS once stamped mail arrives."
