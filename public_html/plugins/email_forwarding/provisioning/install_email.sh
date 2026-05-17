#!/usr/bin/env bash
#
# install_email.sh - host installer + base configurator for Email Forwarding.
#
# Version: 2.0 - Option C (spec email_forwarding_install_unification): Postfix
#                resolves the forwarding-domain list live from the database via
#                a pgsql map, and opendkim static config + the milter are now
#                part of this fixed base install.
#
# Installs the mail software the plugin needs and applies the FIXED Postfix and
# opendkim configuration so inbound mail is piped to the forwarder. Fully
# idempotent: re-running adds nothing twice and is safe.
#
# What it configures (fixed, deployment-independent):
#   - Installs postfix, postfix-pgsql, opendkim, opendkim-tools.
#   - master.cf : the `joinery` pipe transport (appended once).
#   - main.cf   : virtual_transport = joinery
#   - main.cf   : inet_interfaces = all - with one site per host, this site's
#                 Postfix IS the host's mail server.
#   - main.cf   : mydestination = localhost, localhost.localdomain
#                 (forwarding domains must NOT appear here, or Postfix rejects
#                  them with "User unknown in local recipient table").
#   - main.cf   : smtpd_recipient_restrictions with RBL clients.
#   - main.cf   : virtual_mailbox_domains = pgsql:/etc/postfix/joinery-domains.cf
#                 Postfix asks the database whether a recipient domain is an
#                 active forwarding domain, so adding or removing a domain in
#                 the admin UI takes effect immediately - no host action, no
#                 drift. /etc/postfix/joinery-domains.cf is the pgsql map.
#   - opendkim  : inet socket localhost:8891, empty key/signing tables, and the
#                 Postfix milter (milter_default_action = accept, so a keyless
#                 or down opendkim never blocks or defers mail).
#   - Opens port 25 if ufw is active.
#
# What it does NOT do (genuinely per-deployment - handled elsewhere):
#   - The forwarding-domain list: NOT installed at all - Postfix reads it live
#     from the database (see above). Manage domains under
#     Admin > Emails > Incoming > Domains.
#   - DNS records (MX, SPF, DKIM).
#   - Per-domain opendkim DKIM keys: opendkim-genkey, two lines into
#     key.table / signing.table, and a DNS TXT record. opendkim runs keyless
#     (signing nothing) until then. See plugins/email_forwarding/docs/overview.md.
#
# Docker: run this INSIDE the same container as the app - Postfix must be
# co-located with the PHP forwarder it pipes to, and reads the app's own
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
PIPE_SCRIPT="${PLUGIN_DIR}/utils/email_forwarder.php"
RENDER_SCRIPT="${SCRIPT_DIR}/render_pgsql_map.php"
MAP_FILE="/etc/postfix/joinery-domains.cf"

if [[ ! -f "${PIPE_SCRIPT}" ]]; then
    echo "ERROR: forwarder script not found at ${PIPE_SCRIPT}" >&2
    echo "Run this script from inside the email_forwarding plugin directory." >&2
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

# --- 1. install packages -----------------------------------------------------
PACKAGES=(postfix postfix-pgsql opendkim opendkim-tools)
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

# --- 2. master.cf: joinery pipe transport (append once) ----------------------
# Append-once guard: only add the service if no `joinery` service already
# exists, so repeated runs never accumulate duplicate blocks.
if postconf -M 2>/dev/null | grep -q '^joinery'; then
    # An entry exists. Make sure it still points at a usable php binary — a
    # transport baked with the wrong path (e.g. after moving between a bare-
    # metal host and a container) fails silently for every inbound message.
    existing_php="$(postconf -M 2>/dev/null | grep '^joinery' | grep -oE 'argv=[^ ]+' | head -1 | cut -d= -f2)"
    if [[ -n "${existing_php}" && ! -x "${existing_php}" ]]; then
        echo "WARNING: existing joinery transport runs '${existing_php}', not executable here." >&2
        echo "         Edit the joinery service in /etc/postfix/master.cf to use '${PHP_BIN}'," >&2
        echo "         then run 'postfix reload'." >&2
    else
        echo "master.cf: joinery transport already defined - leaving it."
    fi
else
    printf '\njoinery   unix  -       n       n       -       5       pipe\n  flags=DRhu user=www-data\n  argv=%s %s ${recipient}\n' \
        "${PHP_BIN}" "${PIPE_SCRIPT}" >> /etc/postfix/master.cf
    echo "master.cf: added joinery pipe transport -> ${PHP_BIN} ${PIPE_SCRIPT}"
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
postconf -e "smtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination, reject_rbl_client zen.spamhaus.org, reject_rbl_client bl.spamcop.net, reject_rbl_client b.barracudacentral.org, reject_rhsbl_helo dbl.spamhaus.org, reject_rhsbl_sender dbl.spamhaus.org, permit"
echo "main.cf: smtpd_recipient_restrictions set (RBL clients)"

# --- 4. pgsql domain map: Postfix reads the live domain list -----------------
# Render the map from the site's own DB credentials, then install it with
# locked-down permissions. The map necessarily holds the DB password; it is
# never printed to the terminal.
MAP_TMP="$(mktemp)"
trap 'rm -f "${MAP_TMP}"' EXIT
if "${PHP_BIN}" "${RENDER_SCRIPT}" > "${MAP_TMP}"; then
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

# --- 5. opendkim: static config + Postfix milter -----------------------------
# opendkim runs keyless until per-domain keys are added. The static parts are
# deployment-independent and installed once here.
mkdir -p /run/opendkim
chown opendkim:opendkim /run/opendkim 2>/dev/null || true

# key.table / signing.table / trusted.hosts: create only if absent — a re-run
# must never wipe per-domain key entries an operator has since added.
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

# opendkim.conf: write our managed config only if the inet socket marker is
# absent, so operator edits on an already-configured host are left alone.
if ! grep -q 'inet:8891@localhost' /etc/opendkim.conf 2>/dev/null; then
    [[ -f /etc/opendkim.conf && ! -f /etc/opendkim.conf.pre-joinery ]] && \
        cp /etc/opendkim.conf /etc/opendkim.conf.pre-joinery
    cat > /etc/opendkim.conf <<'OPENDKIMCONF'
# Managed by email_forwarding/provisioning/install_email.sh — DKIM for
# outbound forwarding. Per-domain keys are added to the tables below by hand.
Syslog                  yes
SyslogSuccess           yes
UMask                   007
Mode                    sv
Canonicalization        relaxed/simple
Socket                  inet:8891@localhost
PidFile                 /run/opendkim/opendkim.pid
OversignHeaders         From
UserID                  opendkim
KeyTable                /etc/opendkim/key.table
SigningTable            refile:/etc/opendkim/signing.table
ExternalIgnoreList      /etc/opendkim/trusted.hosts
InternalHosts           /etc/opendkim/trusted.hosts
OPENDKIMCONF
    echo "opendkim: wrote /etc/opendkim.conf (inet socket localhost:8891)"
else
    echo "opendkim: /etc/opendkim.conf already wired to the inet socket - leaving it."
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

# Postfix milter wiring. milter_default_action = accept guarantees a keyless or
# down opendkim never blocks or defers mail.
postconf -e "milter_default_action = accept"
postconf -e "smtpd_milters = inet:localhost:8891"
postconf -e "non_smtpd_milters = inet:localhost:8891"
echo "main.cf: opendkim milter wired (inet:localhost:8891, default action accept)"

systemctl enable opendkim >/dev/null 2>&1 || true
if command -v systemctl >/dev/null 2>&1 && systemctl restart opendkim 2>/dev/null; then
    echo "opendkim: restarted (systemd)."
elif command -v service >/dev/null 2>&1 && service opendkim restart >/dev/null 2>&1; then
    echo "opendkim: restarted (service)."
else
    echo "WARNING: could not restart opendkim automatically - restart it manually." >&2
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

# --- summary -----------------------------------------------------------------
echo
echo "Base mail setup complete."
echo "  - Forwarding domains are read live from the database; add them under"
echo "    Admin > Emails > Incoming > Domains. No host action is needed per domain."
echo "  - Publish DNS per domain: MX -> this server, plus SPF and DKIM TXT records."
echo "  - For outbound DKIM signing, generate a per-domain key:"
echo "    opendkim-genkey, add two lines to /etc/opendkim/{key,signing}.table,"
echo "    and publish the DKIM TXT record. See plugins/email_forwarding/docs/overview.md"
