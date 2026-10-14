#!/usr/bin/env bash
# install_mail_gateway.sh - host installer for the mail_apps plugin.
# Version: 2.4 - a release that changes only the helper re-applies the firewall and proxy_from (its new access
#                verb), so a new rule (such as 10465 for relays) reaches a site already on without a restart.
# Version: 2.3 - the site's own www. name is refused saying it is its own front door's, not another site's.
# Version: 2.2 - recheck fixes (reviewer2): one pending name per site (a second failed name drops the first); a
#                switch whose gateway did not come up drops its old name on the next pass (<site>.previous); a
#                site already serving a name keeps it and answers on, even if another site claims it later; a
#                wildcard ServerAlias counts.
# Version: 2.1 - review fixes (reviewer2, 2026-10-13): an old mail name is dropped only after the new one serves; a
#                name any other Apache site on the machine answers to is refused (another site's web or www. name,
#                a bare-metal site, a web name equal to another site's mail name, the site's own www. name); only
#                certificates this helper ordered are deleted; a name whose certificate never issued is dropped too.
# Version: 2.0 - a container site's own mail name (A4 step 6): a name under its web name that no other site on the
#                host owns; its certificate comes through a permanent port-80 site that answers only ACME checks
#                (certbot --webroot, so renewal works the same way), dropped with the name.
# Version: 1.9 - --machine installs on any Docker host, with or without sites yet: on a host built before its
#                sites (install.sh docker, then install.sh site) the gateway arrived only with the next daily run.
#                converge forgets a container site with no request at all (its plugin uninstalled), as it
#                does one whose container is gone: entry and certificate, not only switched off.
# Version: 1.8 - refresh (run after every update that changes something) re-applies the firewall and proxy_from,
#                so a site already on through a relay gets 10465 opened to it by the update itself.
# Version: 1.7 - a relay reaches submission on 10465 (RELAY_SUBMISSION_PORT), opened to the relays only: cloud
#                providers block a relay's outbound 465, so programs still use 465 at the relay and the relay
#                dials 10465 onward (the gateway's -relay-smtp-listen, 1.0.2). Never opened to everyone.
# Version: 1.6 - a Docker host (--machine ROOT, from the support bundle): the gateway installed switched off, no
#                sudoers rule, and a timer that runs the helper's new converge verb every minute, serving each
#                container site that asks in its own storage (mail_apps/request.json) and answering beside it;
#                the helper uses python3, not php, for its JSON (a Docker host has no PHP); refresh takes no site.
# Version: 1.5 - through a relay (specs/mail_apps_plugin.md § Where programs connect): the helper's csr and
#                install-cert verbs make the key here and install the chain the relay issued for its name;
#                on SITE MAILHOST RELAY_IPS serves the site via_relay, opens 993/465 to the relays only and
#                lists them in proxy_from; converge_access keeps the firewall and proxy_from in step with
#                every served site on on, off and remove.
# Version: 1.4 - the helper's remove verb: the plugin's uninstall takes the gateway off the machine once no
#                site on it has the plugin (unit, binary, user, renewal hook, sudoers rule, the helper).
# Version: 1.3 - the certificate is issued with the system's certbot, never a script from the site tree run as
#                root; only for a name that points at this machine; at most one attempt an hour; `on`
#                restarts only when credentials change (review S1, S3, S4, S7).
# Version: 1.2 - programs connect to the mail host name: `on SITE MAILHOST` issues its certificate through
#                setup_ssl.sh and records it; the site entry carries mail_hostname and site_root.
# Version: 1.1 - off for one site of several reloads the gateway instead of restarting it (review R14).
# Version: 1.0 - first version (specs/mail_apps_plugin.md § Network and install).
#
# Puts the mail gateway on this host, switched OFF: it answers nothing until an
# administrator presses Allow mail apps, which runs the root helper this
# installs. Contract of a host installer: root, idempotent (runs on every
# converge), non-interactive, exit 0 when there is nothing to do here.
#
# What it installs:
#   - the joinery-mailgw system user the gateway runs as;
#   - /usr/local/lib/joinery/mail-gateway, the prebuilt static binary for this
#     machine (provisioning/bin/mail-gateway-<uname -m>);
#   - /etc/joinery/mail-gateway/sites/<site>.json: this site's host name, how
#     the gateway reaches the site (loopback), and its certificate files;
#   - /usr/local/sbin/joinery-mail-gateway on|off|status|refresh|remove SITE, the one
#     thing the web user may run as root for it (sudoers rule), which turns the
#     gateway on or off for a site, opens or closes 993/tcp and 465/tcp (IPv4
#     and IPv6, through ufw when it is active), and hands the certificate to the
#     service through systemd's LoadCredential, so the unprivileged gateway
#     never reads a private key on disk;
#   - the joinery-mail-gateway systemd unit (not enabled), and a certificate
#     renewal hook that restarts it.
#
# A site container has no host of its own: its host's management node runs the
# gateway. Here that is said, and nothing is installed.

set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "ERROR: run as root (sudo bash $0)." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --machine ROOT: a Docker host, run from the agent's support bundle by the
# host's machine-mode installers. It has no site of its own and no PHP; its
# container sites ask for mail apps in their own storage, and the converge
# timer installed below acts on it (specs/mail_apps_plugin.md § A4).
MACHINE=0
if [[ "${1:-}" == "--machine" ]]; then
    MACHINE=1
fi

if [[ "${MACHINE}" == "1" ]]; then
    # Docker is the test, not a site: a host is built first (install.sh docker,
    # which runs this from the bundle) and its sites come later, and the host's
    # installers run again only when a bundle arrives or once a day. Installed
    # here, switched off, the converge timer serves a site within a minute of
    # it asking, however soon after the host was built.
    if ! command -v docker >/dev/null 2>&1; then
        echo "mail gateway: not a Docker host (no docker) - nothing to install."
        exit 0
    fi
    if ! command -v python3 >/dev/null 2>&1; then
        echo "mail gateway: no python3 on this host; the helper needs it - nothing installed." >&2
        exit 0
    fi
    SITE_NAME=""
    HOST=""
else
SITE_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
SITE_NAME="$(basename "${SITE_ROOT}")"
CONFIG_FILE="${SITE_ROOT}/config/Globalvars_site.php"
PHP_BIN="$(command -v php || true)"

if [[ ! -f "${CONFIG_FILE}" || -z "${PHP_BIN}" ]]; then
    echo "mail gateway: no site config or no php here - nothing to install."
    exit 0
fi
if [[ ! "${SITE_NAME}" =~ ^[a-z0-9_-]+$ ]]; then
    echo "mail gateway: the site name '${SITE_NAME}' is not one the helper accepts - nothing installed." >&2
    exit 0
fi

read_site_setting() {
    "${PHP_BIN}" -r '
        $config = file_get_contents($argv[1]);
        echo preg_match("/settings\[.".$argv[2].".\]\s*=\s*.([^\x27\"]*)/", $config, $m) ? $m[1] : "";
    ' "${CONFIG_FILE}" "$1"
}

if [[ "$(read_site_setting deployment_environment)" == "docker" ]]; then
    echo "mail gateway: this site runs in a container; its host's management node runs the gateway. Nothing to install here."
    exit 0
fi

HOST="$(read_site_setting webDir)"
HOST="${HOST#http://}"
HOST="${HOST#https://}"
HOST="${HOST%%/*}"
HOST="${HOST%%:*}"
if [[ -z "${HOST}" ]]; then
    echo "mail gateway: the site has no webDir (host name) yet - nothing installed."
    exit 0
fi
fi

ARCH="$(uname -m)"
BIN_SRC="${SCRIPT_DIR}/bin/mail-gateway-${ARCH}"
if [[ ! -f "${BIN_SRC}" ]]; then
    echo "mail gateway: no prebuilt gateway for ${ARCH} in this release - nothing installed."
    exit 0
fi

CHANGED=0

# --- 1. user ------------------------------------------------------------------
if ! id joinery-mailgw >/dev/null 2>&1; then
    useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin joinery-mailgw
fi

# --- 2. binary ----------------------------------------------------------------
install -d -m 755 /usr/local/lib/joinery
if ! cmp -s "${BIN_SRC}" /usr/local/lib/joinery/mail-gateway; then
    install -m 755 "${BIN_SRC}" /usr/local/lib/joinery/mail-gateway.new
    mv -f /usr/local/lib/joinery/mail-gateway.new /usr/local/lib/joinery/mail-gateway
    CHANGED=1
fi

# --- 3. this site's entry (a site machine; a Docker host's entries are the converge's) ---
if [[ "${MACHINE}" == "0" ]]; then
# Apache serves the site itself on 443 here, with the certificate for its name:
# the gateway calls it over loopback and checks that certificate. A site whose
# certificate is not a public one (no Let's Encrypt lineage) is called without
# the check — it is this machine talking to itself.
if [[ -f "/etc/letsencrypt/live/${HOST}/fullchain.pem" ]]; then
    CERT_DIR="/etc/letsencrypt/live/${HOST}"
    INSECURE=false
else
    CERT_DIR="/etc/ssl/joinery/${HOST}"
    INSECURE=true
fi
install -d -m 755 /etc/joinery/mail-gateway /etc/joinery/mail-gateway/sites
SITE_JSON="/etc/joinery/mail-gateway/sites/${SITE_NAME}.json"
# The name programs connect to: the mail host name the helper recorded when the
# switch was turned on (sites/<site>.mailhost), whose certificate is its own
# lineage. Without one, the web name and the site's certificate.
MAIL_HOST=""
if [[ -f "/etc/joinery/mail-gateway/sites/${SITE_NAME}.mailhost" ]]; then
    MAIL_HOST="$(tr -d '[:space:]' < "/etc/joinery/mail-gateway/sites/${SITE_NAME}.mailhost")"
fi
SERVE_CERT_DIR="${CERT_DIR}"
VIA_RELAY=false
if [[ -n "${MAIL_HOST}" ]]; then
    SERVE_CERT_DIR="/etc/letsencrypt/live/${MAIL_HOST}"
fi
# Through a relay (the helper's on SITE MAILHOST RELAY_IPS): the certificate the
# relay issued for its name, kept here, and the site answers the relay only.
if [[ -n "${MAIL_HOST}" && -f "/etc/joinery/mail-gateway/sites/${SITE_NAME}.relay" \
        && -f "/etc/joinery/mail-gateway/certs/${SITE_NAME}/fullchain.pem" ]]; then
    SERVE_CERT_DIR="/etc/joinery/mail-gateway/certs/${SITE_NAME}"
    VIA_RELAY=true
fi
CANDIDATE="$(mktemp)"
cat > "${CANDIDATE}" <<JSON
{
    "site": "${SITE_NAME}",
    "hostname": "${HOST}",
    "mail_hostname": "${MAIL_HOST}",
    "upstream": "https://127.0.0.1:443",
    "upstream_insecure_tls": ${INSECURE},
    "cert_file": "${SERVE_CERT_DIR}/fullchain.pem",
    "key_file": "${SERVE_CERT_DIR}/privkey.pem",
    "via_relay": ${VIA_RELAY}
}
JSON
if ! cmp -s "${CANDIDATE}" "${SITE_JSON}"; then
    install -m 644 "${CANDIDATE}" "${SITE_JSON}"
    CHANGED=1
fi
rm -f "${CANDIDATE}"
else
    install -d -m 755 /etc/joinery/mail-gateway /etc/joinery/mail-gateway/sites
fi

# --- 4. the root helper -----------------------------------------------------------
HELPER="/usr/local/sbin/joinery-mail-gateway"
CANDIDATE="$(mktemp)"
cat > "${CANDIDATE}" <<'HELPERSCRIPT'
#!/usr/bin/env bash
# joinery-mail-gateway on|off|status|refresh|remove|csr|install-cert SITE [MAILHOST [RELAY_IPS]]
# joinery-mail-gateway converge
# on:      serve SITE (sites/SITE.enabled) by MAILHOST — the deployment's mail
#          host name, which reaches this server even when the web name sits
#          behind a CDN. Issues MAILHOST's certificate with certbot when there
#          is none yet, if the name points at this machine. Starts the gateway and
#          opens 993+465                                                   -> GATEWAY_ON
# off:     stop serving SITE; stop the gateway and close the ports when no
#          site is left                                                    -> GATEWAY_OFF
# status:  the unit, the ports, the sites served                           -> GATEWAY_STATUS
# refresh: re-apply the firewall and proxy_from, then re-hand the
#          certificates and restart, when any site is served             -> GATEWAY_REFRESHED
# on SITE MAILHOST RELAY_IPS: the deployment's mail arrives through a relay,
#          and programs reach the relay's name (MAILHOST); the relay splices
#          their TLS here (specs/mail_apps_plugin.md § Where programs connect).
#          The certificate must be installed first (csr, install-cert); 993,
#          465 and RELAY_SUBMISSION_PORT (where the relay dials submission) open
#          to RELAY_IPS (comma-separated) only, whose PROXY line the gateway
#          then believes (proxy_from), and the site answers nobody else.
# csr:     a new key for SITE's relay name (kept here, root only) and its
#          certificate request, printed                                    -> GATEWAY_CSR
# install-cert SITE MAILHOST: the chain the relay issued, on stdin; it must
#          match the key and name MAILHOST                                 -> GATEWAY_CERT_INSTALLED
# remove:  the plugin is uninstalled on SITE: forget the site, and with no
#          site left take the gateway off this machine - unit, binary, user,
#          renewal hook, sudoers rule, this helper                         -> GATEWAY_REMOVED
# converge: a Docker host (every minute, joinery-mail-gateway-converge.timer):
#          each container site with a run spec asks for mail apps in its own
#          storage (mail_apps/request.json: {"on", "name"}); serve it, or stop,
#          to match, and write mail_apps/answer.json beside the request. An
#          entry whose container is gone is forgotten                      -> GATEWAY_CONVERGED
# Installed by plugins/mail_apps/provisioning/install_mail_gateway.sh; on a
# site machine run through sudo by the web user, on a Docker host by root's
# timer. Takes a site name, never a path.
set -euo pipefail
VERB="${1:-}"
SITE="${2:-}"
MAILHOST="$(echo "${3:-}" | tr 'A-Z' 'a-z')"
RELAY_IPS="${4:-}"
DIR=/etc/joinery/mail-gateway
CERTS="${DIR}/certs"
UNIT=joinery-mail-gateway
DROPIN_DIR=/etc/systemd/system/${UNIT}.service.d
# Where a relay reaches submission: the relay cannot dial 465 onward (cloud
# providers block outbound 25, 465 and 587), so the gateway also listens here
# and only the relays may connect. A mirror of RelaySubmissionPort (mail-gateway
# main.go) and mailAppsSubmissionTarget (relay-sealer relay_serve.go).
RELAY_SUBMISSION_PORT=10465

# remove may find its site already forgotten (a second run): that is done, not an error.
# converge takes no site; refresh without one re-hands every served site's.
if [[ "${VERB}" == "converge" || "${VERB}" == "access" || ( "${VERB}" == "refresh" && -z "${SITE}" ) ]]; then
    :
elif [[ ! "${SITE}" =~ ^[a-z0-9_-]+$ ]] || { [[ "${VERB}" != "remove" ]] && [[ ! -f "${DIR}/sites/${SITE}.json" ]]; }; then
    echo "usage: joinery-mail-gateway on|off|status|refresh|remove|csr|install-cert SITE (an installed site)" >&2
    exit 2
fi

ufw_active() {
    command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q "^Status: active"
}

served_sites() {
    local f
    for f in "${DIR}"/sites/*.enabled; do
        [[ -e "${f}" ]] || continue
        basename "${f}" .enabled
    done
}

json_field() {
    python3 -c 'import json, sys
try:
    j = json.load(open(sys.argv[1]))
except Exception:
    j = None
v = j.get(sys.argv[2], "") if isinstance(j, dict) else ""
print("" if v is None else (str(v).lower() if isinstance(v, bool) else v))' "$1" "$2"
}

# Each served site's certificate and key, handed to the service by systemd:
# the unprivileged gateway reads them from $CREDENTIALS_DIRECTORY.
write_credentials() {
    local s cert key out
    install -d -m 755 "${DROPIN_DIR}"
    out="$(mktemp)"
    echo "[Service]" > "${out}"
    for s in $(served_sites); do
        cert="$(json_field "${DIR}/sites/${s}.json" cert_file)"
        key="$(json_field "${DIR}/sites/${s}.json" key_file)"
        if [[ -f "${cert}" && -f "${key}" ]]; then
            echo "LoadCredential=${s}.crt:${cert}" >> "${out}"
            echo "LoadCredential=${s}.key:${key}" >> "${out}"
        else
            echo "joinery-mail-gateway: ${s}: certificate files missing (${cert}) - that site cannot be served" >&2
        fi
    done
    CREDENTIALS_CHANGED=0
    if ! cmp -s "${out}" "${DROPIN_DIR}/credentials.conf"; then
        install -m 644 "${out}" "${DROPIN_DIR}/credentials.conf"
        CREDENTIALS_CHANGED=1
        systemctl daemon-reload
    fi
    rm -f "${out}"
}

listening() {
    (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null && { exec 3>&- 3<&- 2>/dev/null || true; return 0; }
    return 1
}

# This machine's own addresses (IPv4 and IPv6, global scope).
own_addresses() {
    ip -o addr show scope global 2>/dev/null | awk '{print $4}' | cut -d/ -f1
}

# True when HOST's A or AAAA records include one of this machine's addresses:
# the only names a certificate is ever ordered for (an ACME check for any other
# name would fail anyway, and spend the rate limit the operator's other names
# share).
reaches_here() {
    local host="$1" addr own
    own="$(own_addresses)"
    for addr in $(getent ahosts "${host}" 2>/dev/null | awk '{print $1}' | sort -u); do
        if printf '%s\n' "${own}" | grep -qxF "${addr}"; then
            return 0
        fi
    done
    return 1
}

# Issue HOST's certificate with certbot, run from the system's own install — no
# script from the site's tree runs as root here. Apache answers the HTTP-01
# check: certbot's Apache method adds its challenge to the port-80 sites; if
# that fails, the folder Apache serves for names no site claims is the webroot.
issue_certificate() {
    local host="$1" marker="${DIR}/sites/${SITE}.certattempt" last now docroot
    if ! command -v certbot >/dev/null 2>&1; then
        echo "joinery-mail-gateway: certbot is not installed on this server, so no certificate can be issued for ${host}." >&2
        exit 5
    fi
    now="$(date +%s)"
    last="$(cat "${marker}" 2>/dev/null || echo 0)"
    if [[ "${last}" =~ ^[0-9]+$ ]] && (( now - last < 3600 )); then
        echo "joinery-mail-gateway: a certificate was tried for this site less than an hour ago (one attempt an hour, to stay within the certificate authority's limits); try again later." >&2
        exit 5
    fi
    echo "${now}" > "${marker}"
    if [[ "$(json_field "${DIR}/sites/${SITE}.json" container 2>/dev/null)" == "true" ]]; then
        # One pending name per site: an earlier name that never issued goes
        # (drop_mail_name keeps anything a site still serves).
        local pending
        pending="$(cat "${DIR}/sites/${SITE}.challenge" 2>/dev/null || true)"
        echo "${host}" > "${DIR}/sites/${SITE}.challenge"
        if [[ -n "${pending}" && "${pending}" != "${host}" ]]; then
            drop_mail_name "${pending}"
        fi
        challenge_site "${host}" || exit 5
        certbot certonly --webroot -w "${CHALLENGE_ROOT}" -d "${host}" --non-interactive --agree-tos \
            --register-unsafely-without-email --keep-until-expiring >&2 || true
        return 0
    fi
    if certbot certonly --apache -d "${host}" --non-interactive --agree-tos --register-unsafely-without-email \
            --keep-until-expiring >&2; then
        return 0
    fi
    # The port-80 default site: the first "default server" line under *:80.
    local conf
    conf="$(apache2ctl -S 2>/dev/null | awk '/^\*:80 / {f=1} f && /default server/ {sub(/.*\(/, ""); sub(/:[0-9]+\).*/, ""); print; exit}')"
    docroot="$(grep -h -m1 -i '^[[:space:]]*DocumentRoot' "${conf:-/etc/apache2/sites-enabled/000-default.conf}" 2>/dev/null | awk '{print $2}' | tr -d '"')"
    if [[ -n "${docroot}" && -d "${docroot}" ]]; then
        certbot certonly --webroot -w "${docroot}" -d "${host}" --non-interactive --agree-tos \
            --register-unsafely-without-email --keep-until-expiring >&2 || true
    fi
}

# Set fields of the site's entry: name=value pairs, a value of true/false a boolean.
set_entry() {
    entry_write "${DIR}/sites/${SITE}.json" "$@"
}

# Write fields of a site entry (path, then name=value pairs; true/false a
# boolean), creating it when absent: written whole beside it, then renamed.
entry_write() {
    python3 -c 'import json, os, sys
p = sys.argv[1]
j = {}
if os.path.exists(p):
    try:
        j = json.load(open(p))
    except Exception:
        sys.stderr.write("unreadable site entry\n")
        sys.exit(1)
    if not isinstance(j, dict):
        sys.stderr.write("unreadable site entry\n")
        sys.exit(1)
for kv in sys.argv[2:]:
    k, v = kv.split("=", 1)
    j[k] = True if v == "true" else (False if v == "false" else v)
tmp = p + ".new"
with open(tmp, "w") as f:
    f.write(json.dumps(j, indent=4, sort_keys=False) + "\n")
os.chmod(tmp, 0o644)
os.rename(tmp, p)' "$@"
}

valid_host() {
    [[ "$1" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]]
}

valid_ip() {
    python3 -c 'import ipaddress, sys
try:
    ipaddress.ip_address(sys.argv[1])
except ValueError:
    sys.exit(1)' "$1"
}

# The relays every site turned on through one is reached from, one a line.
relay_addresses() {
    local s
    for s in $(served_sites); do
        if [[ -f "${DIR}/sites/${s}.relay" ]]; then
            cat "${DIR}/sites/${s}.relay"
        fi
    done | sort -u
}

# 993 and 465 as the served sites need them: open to everyone while a site is
# reached directly; open to each relay's address while a site is reached
# through it (with RELAY_SUBMISSION_PORT, which is never open to everyone);
# closed otherwise. The relay rules this helper added are recorded
# (ufw_relays) so a relay no longer used has its rules removed. The relays are
# also the gateway's proxy_from: the addresses whose PROXY line it believes.
converge_access() {
    local s direct=0 relays ip port
    for s in $(served_sites); do
        [[ -f "${DIR}/sites/${s}.relay" ]] || direct=1
    done
    relays="$(relay_addresses)"
    printf '%s\n' "${relays}" | sed '/^$/d' > "${DIR}/proxy_from.new"
    chmod 644 "${DIR}/proxy_from.new"
    mv "${DIR}/proxy_from.new" "${DIR}/proxy_from"
    ufw_active || return 0
    for port in 993 465; do
        if (( direct )); then
            ufw allow "${port}/tcp" >/dev/null 2>&1 || true
        else
            ufw delete allow "${port}/tcp" >/dev/null 2>&1 || true
        fi
    done
    for ip in $(cat "${DIR}/ufw_relays" 2>/dev/null); do
        if ! printf '%s\n' "${relays}" | grep -qxF "${ip}"; then
            for port in 993 465 "${RELAY_SUBMISSION_PORT}"; do
                ufw delete allow proto tcp from "${ip}" to any port "${port}" >/dev/null 2>&1 || true
            done
        fi
    done
    for ip in ${relays}; do
        for port in 993 465 "${RELAY_SUBMISSION_PORT}"; do
            ufw allow proto tcp from "${ip}" to any port "${port}" >/dev/null 2>&1 || true
        done
    done
    printf '%s\n' "${relays}" | sed '/^$/d' > "${DIR}/ufw_relays"
}

# Record the name programs connect to, with its certificate, in the site's entry.
set_mail_host() {
    local host="$1" cert
    if [[ ! "${host}" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]]; then
        echo "joinery-mail-gateway: '${host}' is not a host name" >&2
        exit 2
    fi
    cert="/etc/letsencrypt/live/${host}"
    if [[ ! -f "${cert}/fullchain.pem" ]]; then
        if ! reaches_here "${host}"; then
            echo "joinery-mail-gateway: ${host} does not point at this server's own address ($(own_addresses | tr '\n' ' ')). Point its A/AAAA record here (directly, not through a CDN), then try again." >&2
            exit 5
        fi
        issue_certificate "${host}"
    fi
    if [[ ! -f "${cert}/fullchain.pem" ]]; then
        echo "joinery-mail-gateway: ${host} points here, but the certificate could not be issued; certbot's output above says why." >&2
        exit 5
    fi
    echo "${host}" > "${DIR}/sites/${SITE}.mailhost"
    rm -f "${DIR}/sites/${SITE}.relay"
    set_entry "mail_hostname=${host}" "cert_file=${cert}/fullchain.pem" "key_file=${cert}/privkey.pem" "via_relay=false"
}

# Through a relay: the certificate came from the relay (install-cert) and lives
# here; programs reach the relay's name; only the relay's addresses may connect.
set_relay_host() {
    local host="$1" list="$2" ip out=""
    valid_host "${host}" || { echo "joinery-mail-gateway: '${host}' is not a host name" >&2; exit 2; }
    for ip in ${list//,/ }; do
        valid_ip "${ip}" || { echo "joinery-mail-gateway: '${ip}' is not an address" >&2; exit 2; }
        out+="${ip}"$'\n'
    done
    [[ -n "${out}" ]] || { echo "joinery-mail-gateway: no relay address given" >&2; exit 2; }
    if [[ ! -f "${CERTS}/${SITE}/fullchain.pem" || ! -f "${CERTS}/${SITE}/privkey.pem" ]]; then
        echo "joinery-mail-gateway: no certificate for ${host} from the relay yet (csr, then install-cert)." >&2
        exit 5
    fi
    echo "${host}" > "${DIR}/sites/${SITE}.mailhost"
    printf '%s' "${out}" > "${DIR}/sites/${SITE}.relay"
    set_entry "mail_hostname=${host}" "cert_file=${CERTS}/${SITE}/fullchain.pem" \
        "key_file=${CERTS}/${SITE}/privkey.pem" "via_relay=true"
}

# --- A Docker host's container sites (converge) -------------------------------
# A container site asks in its own storage volume, which the container writes
# and this root process reads: every read and write goes through a directory
# opened without following links (O_NOFOLLOW at each step), so nothing the
# container plants - a link to a host file, a FIFO, a giant file - is read or
# written outside its own folder. Answers: none | off | on NAME | bad REASON.
docker_root() {
    local r
    r="$(docker info -f '{{.DockerRootDir}}' 2>/dev/null || true)"
    [[ "${r}" == /* && "${r}" != *..* && -d "${r}" ]] || return 1
    echo "${r%/}"
}

storage_io() {
    python3 -c 'import json, os, re, stat, sys
root, site, mode = sys.argv[1], sys.argv[2], sys.argv[3]
D = os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW
try:
    top = os.open(os.path.join(root, "volumes", site + "_storage", "_data"), D)
    d = os.open("mail_apps", D, dir_fd=top)
except OSError:
    if mode == "read":
        print("none"); sys.exit(0)
    sys.exit(3)
if mode == "read":
    try:
        fd = os.open("request.json", os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=d)
    except FileNotFoundError:
        print("none"); sys.exit(0)
    except OSError:
        print("bad the request cannot be read"); sys.exit(0)
    st = os.fstat(fd)
    if not stat.S_ISREG(st.st_mode) or st.st_size > 4096:
        print("bad the request is not a small file"); sys.exit(0)
    try:
        j = json.loads(os.read(fd, 4097).decode("utf-8"))
    except Exception:
        print("bad the request is not JSON"); sys.exit(0)
    if not isinstance(j, dict) or not isinstance(j.get("on"), bool):
        print("bad the request has no on: true or false"); sys.exit(0)
    if not j["on"]:
        print("off"); sys.exit(0)
    name = str(j.get("name", "")).strip().lower()
    if not re.fullmatch(r"[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+", name):
        print("bad the request names no host name"); sys.exit(0)
    print("on " + name)
else:
    body = sys.stdin.buffer.read(65536)
    tmp = ".answer.json.new"
    try:
        os.unlink(tmp, dir_fd=d)
    except FileNotFoundError:
        pass
    fd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o644, dir_fd=d)
    os.write(fd, body)
    os.fchmod(fd, 0o644)
    os.close(fd)
    os.rename(tmp, "answer.json", src_dir_fd=d, dst_dir_fd=d)' "$@"
}

# The answer the site's admin page reads: written only when it changes.
answer() {
    local root="$1" site="$2" state="$3" name="$4" error="$5" expires="" body
    if [[ -n "${name}" && -f "/etc/letsencrypt/live/${name}/fullchain.pem" ]]; then
        expires="$(date -u -d "$(openssl x509 -in "/etc/letsencrypt/live/${name}/fullchain.pem" -noout -enddate 2>/dev/null | cut -d= -f2)" +%s 2>/dev/null || true)"
    fi
    body="$(python3 -c 'import json, sys
s, n, e, x = sys.argv[1:5]
print(json.dumps({"state": s, "name": n or None, "cert_expires": int(x) if x.isdigit() else None, "error": e or None}))' \
        "${state}" "${name}" "${error}" "${expires}")"
    if [[ "$(cat "${DIR}/sites/${site}.answer" 2>/dev/null || true)" == "${body}" ]]; then
        return 0
    fi
    if printf '%s' "${body}" | python3 -c 'import json, sys, time
j = json.loads(sys.stdin.read()); j["time"] = int(time.time()); sys.stdout.write(json.dumps(j))' \
            | storage_io "${root}" "${site}" write; then
        printf '%s' "${body}" > "${DIR}/sites/${site}.answer"
    fi
}

# The name the host's front door serves the site under (its vhost's ServerName).
front_door_name() {
    awk 'tolower($1) == "servername" { print tolower($2); exit }' "/etc/apache2/sites-available/$1.conf" 2>/dev/null
}

# A container site's mail name: its web name, or a name under it (www.
# dropped) that no other site on this host owns. Another site owns a name that
# is its web name, or that lies under its web name when that is more specific
# than this site's; a name already serving another site is refused too. So no
# site can take mail programs (and their app passwords) meant for another.
# Prints why not, or nothing when the name is the site's to use.
mail_name_problem() {
    local site="$1" name="$2" web="$3" base other ow ob entry conf c
    # A name already serving another site, its web name included.
    for entry in "${DIR}"/sites/*.mailhost; do
        [[ -f "${entry}" ]] || continue
        [[ "$(basename "${entry}" .mailhost)" == "${site}" ]] && continue
        if [[ "$(cat "${entry}")" == "${name}" ]]; then
            echo "${name} already serves another site on this server."
            return 0
        fi
    done
    # A name another Apache site on this machine answers to: another site's
    # front door (its web name, its www. name), a bare-metal site, another
    # site's check site. This site's own front door counts only for its web
    # name: its www. name is the front door's redirect, which would swallow the
    # certificate check. Its own check site for this name is its own.
    for conf in /etc/apache2/sites-available/*.conf; do
        [[ -f "${conf}" ]] || continue
        c="$(basename "${conf}" .conf)"
        [[ "${c}" == "${site}" && "${name}" == "${web}" ]] && continue
        if [[ "${c}" == "joinery-mail-${name}" ]] && { [[ "$(cat "${DIR}/sites/${site}.mailhost" 2>/dev/null)" == "${name}" ]] \
                || [[ "$(cat "${DIR}/sites/${site}.challenge" 2>/dev/null)" == "${name}" ]]; }; then
            continue
        fi
        if awk -v n="${name}" 'tolower($1) == "servername" || tolower($1) == "serveralias" {
                for (i = 2; i <= NF; i++) { v = tolower($i)
                    if (v == n || (substr(v, 1, 2) == "*." && length(n) > length(v) - 1 && substr(n, length(n) - length(v) + 2) == substr(v, 2))) found = 1 } }
                END { exit !found }' "${conf}"; then
            if [[ "${c}" == "${site}" ]]; then
                echo "${name} is answered by the site's own front door (its www. redirect); use a name of its own, such as mail.${web#www.}."
            else
                echo "${name} belongs to another site on this server."
            fi
            return 0
        fi
    done
    [[ "${name}" == "${web}" ]] && return 0
    base="${web#www.}"
    if [[ "${name}" != *".${base}" ]]; then
        echo "The mail name must be a name under the site's web name (such as mail.${base}), not ${name}."
        return 0
    fi
    for other in /etc/joinery/sites/*/run_spec; do
        [[ -f "${other}" ]] || continue
        other="$(basename "$(dirname "${other}")")"
        [[ "${other}" == "${site}" ]] && continue
        ow="$(front_door_name "${other}")"
        [[ -n "${ow}" ]] || continue
        ob="${ow#www.}"
        if [[ "${name}" == "${ow}" || "${name}" == "${ob}" ]] \
                || { [[ "${name}" == *".${ob}" ]] && (( ${#ob} > ${#base} )); }; then
            echo "${name} belongs to another site on this server."
            return 0
        fi
    done
}

# A container site's own mail name needs a certificate the front door does not
# hold. Port 80 on a Docker host answers names no site claims with a refusal
# (000-joinery-no-site.conf), so the name gets a permanent port-80 site of its
# own that serves only ACME challenges, from one webroot; certbot --webroot
# records it, so renewal answers the same way.
CHALLENGE_ROOT=/var/lib/joinery/mail-challenge
challenge_site() {
    local host="$1" conf="/etc/apache2/sites-available/joinery-mail-${1}.conf" candidate
    install -d -m 755 "${CHALLENGE_ROOT}"
    candidate="$(mktemp)"
    cat > "${candidate}" <<VHOST
# Written by joinery-mail-gateway: ${host} is a container site's mail name.
# Only the certificate checks are answered here; mail programs use 993 and 465.
<VirtualHost *:80>
    ServerName ${host}
    DocumentRoot ${CHALLENGE_ROOT}
    <Directory ${CHALLENGE_ROOT}>
        Options None
        AllowOverride None
        Require all granted
    </Directory>
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ - [R=404,L]
</VirtualHost>
VHOST
    if ! cmp -s "${candidate}" "${conf}"; then
        install -m 644 "${candidate}" "${conf}"
    fi
    rm -f "${candidate}"
    a2ensite -q "joinery-mail-${host}" >/dev/null 2>&1 || ln -sf "${conf}" "/etc/apache2/sites-enabled/joinery-mail-${host}.conf"
    if ! apache2ctl configtest >/dev/null 2>&1; then
        rm -f "/etc/apache2/sites-enabled/joinery-mail-${host}.conf" "${conf}"
        echo "joinery-mail-gateway: Apache refused the certificate-check site for ${host}; nothing was changed." >&2
        return 1
    fi
    systemctl reload apache2 >/dev/null 2>&1 || true
}

# A mail name no site uses any more: its challenge site and its certificate.
drop_mail_name() {
    local host="$1" entry
    valid_host "${host}" || return 0
    # Never one a site still uses: the gateway loads its files at every start,
    # and a unit whose credential file is gone does not start at all.
    for entry in "${DIR}"/sites/*.json; do
        [[ -f "${entry}" ]] || continue
        [[ "$(json_field "${entry}" cert_file)" == */live/"${host}"/* ]] && return 0
    done
    for entry in "${DIR}"/sites/*.mailhost; do
        [[ -f "${entry}" && "$(cat "${entry}")" == "${host}" ]] && return 0
    done
    if [[ -e "/etc/apache2/sites-available/joinery-mail-${host}.conf" ]]; then
        rm -f "/etc/apache2/sites-enabled/joinery-mail-${host}.conf" "/etc/apache2/sites-available/joinery-mail-${host}.conf"
        systemctl reload apache2 >/dev/null 2>&1 || true
    fi
    # Only a certificate this helper ordered (certbot recorded our webroot):
    # a lineage the operator holds for the name for anything else is theirs.
    if grep -qF "${CHALLENGE_ROOT}" "/etc/letsencrypt/renewal/${host}.conf" 2>/dev/null && command -v certbot >/dev/null 2>&1; then
        certbot delete --cert-name "${host}" --non-interactive >/dev/null 2>&1 || true
    fi
}

# A container site's entry: the gateway reaches it through the host's front
# door, under its web name, as on a site machine. Its mail name and
# certificate are the on verb's (set_mail_host).
container_entry() {
    local site="$1" web="$2" cert_dir insecure
    if [[ -f "/etc/letsencrypt/live/${web}/fullchain.pem" ]]; then
        cert_dir="/etc/letsencrypt/live/${web}"; insecure=false
    else
        cert_dir="/etc/ssl/joinery/${web}"; insecure=true
    fi
    if [[ ! -f "${DIR}/sites/${site}.json" ]]; then
        entry_write "${DIR}/sites/${site}.json" "site=${site}" "hostname=${web}" "mail_hostname=" \
            "upstream=https://127.0.0.1:443" "upstream_insecure_tls=${insecure}" \
            "cert_file=${cert_dir}/fullchain.pem" "key_file=${cert_dir}/privkey.pem" "via_relay=false" "container=true"
    elif [[ "$(json_field "${DIR}/sites/${site}.json" hostname)" != "${web}" ]]; then
        entry_write "${DIR}/sites/${site}.json" "hostname=${web}" "upstream_insecure_tls=${insecure}"
    fi
}

converge() {
    local root spec site want name web out rc key last now
    install -d -m 755 "${DIR}" "${DIR}/sites"
    root="$(docker_root)" || { echo "GATEWAY_CONVERGED"; return 0; }
    for spec in /etc/joinery/sites/*/run_spec; do
        [[ -f "${spec}" ]] || continue
        site="$(basename "$(dirname "${spec}")")"
        [[ "${site}" =~ ^[a-z0-9_-]{1,50}$ ]] || continue
        want="$(storage_io "${root}" "${site}" read 2>/dev/null || echo "bad the request could not be read")"
        if [[ "${want}" == on\ * ]]; then
            name="${want#on }"
            web="$(front_door_name "${site}")"
            if [[ -z "${web}" ]]; then
                answer "${root}" "${site}" failed "${name}" "This host has no front door (vhost) for the site."
                continue
            fi
            # Served under this name already: it stays (the first site to hold a
            # name keeps it, even if another site claims it later). A switch
            # whose gateway did not come up left its old names: dropped now.
            if [[ -e "${DIR}/sites/${site}.enabled" && "$(cat "${DIR}/sites/${site}.mailhost" 2>/dev/null)" == "${name}" ]]; then
                drop_left_names "${site}" "${name}" "${web}"
                rm -f "${DIR}/sites/${site}.failed"
                answer "${root}" "${site}" on "${name}" ""
                continue
            fi
            out="$(mail_name_problem "${site}" "${name}" "${web}")"
            if [[ -n "${out}" ]]; then
                answer "${root}" "${site}" failed "${name}" "${out}"
                continue
            fi
            # A request that failed is tried again after an hour, not every minute.
            key="${name}"
            now="$(date +%s)"
            last="$(cat "${DIR}/sites/${site}.failed" 2>/dev/null || true)"
            if [[ "${last%% *}" == "${key}" && "${last##* }" =~ ^[0-9]+$ ]] && (( now - ${last##* } < 3600 )); then
                continue
            fi
            container_entry "${site}" "${web}"
            # The name served until now is kept aside, to be dropped once the
            # new one serves (a failed switch keeps it serving).
            last="$(cat "${DIR}/sites/${site}.mailhost" 2>/dev/null || true)"
            if [[ -n "${last}" && "${last}" != "${name}" && "${last}" != "${web}" ]]; then
                echo "${last}" > "${DIR}/sites/${site}.previous"
            fi
            rc=0
            out="$("$0" on "${site}" "${name}" 2>&1)" || rc=$?
            if [[ "${rc}" == 0 ]]; then
                drop_left_names "${site}" "${name}" "${web}"
                rm -f "${DIR}/sites/${site}.failed"
                answer "${root}" "${site}" on "${name}" ""
            else
                echo "${key} ${now}" > "${DIR}/sites/${site}.failed"
                answer "${root}" "${site}" failed "${name}" "$(printf '%s' "${out}" | grep -v '^[[:space:]]*$' | grep -iv 'GATEWAY_' | head -3 | tr '\n' ' ' | cut -c1-500)"
            fi
        else
            if [[ -e "${DIR}/sites/${site}.enabled" && "$(json_field "${DIR}/sites/${site}.json" container)" == "true" ]]; then
                "$0" off "${site}" >/dev/null 2>&1 || true
            fi
            rm -f "${DIR}/sites/${site}.failed"
            case "${want}" in
                # No request at all: the site does not have mail apps (never
                # had, or uninstalled them), so a container entry is forgotten.
                none) [[ "$(json_field "${DIR}/sites/${site}.json" container 2>/dev/null)" == "true" ]] && forget_container_entry "${site}" ;;
                off)  answer "${root}" "${site}" off "" "" ;;
                *)    answer "${root}" "${site}" failed "" "${want#bad }" ;;
            esac
        fi
    done
    # A container that is gone takes its entry with it.
    local entry s
    for entry in "${DIR}"/sites/*.json; do
        [[ -f "${entry}" ]] || continue
        s="$(basename "${entry}" .json)"
        [[ "$(json_field "${entry}" container)" == "true" && ! -f "/etc/joinery/sites/${s}/run_spec" ]] || continue
        forget_container_entry "${s}"
    done
    echo "GATEWAY_CONVERGED"
}

# A container site's entry and certificate, gone: off first if it was served.
# Served under NAME now: the name served before (<site>.previous) and a name
# whose certificate never issued (<site>.challenge) lose their check site and
# certificate (the web name's are the front door's).
drop_left_names() {
    local site="$1" name="$2" web="$3" left f
    for f in previous challenge; do
        left="$(cat "${DIR}/sites/${site}.${f}" 2>/dev/null || true)"
        if [[ -n "${left}" && "${left}" != "${name}" && "${left}" != "${web}" ]]; then
            drop_mail_name "${left}"
        fi
        [[ "${left}" == "${name}" && "${f}" == challenge ]] || rm -f "${DIR}/sites/${site}.${f}"
    done
}

forget_container_entry() {
    local s="$1" own tried web
    own="$(cat "${DIR}/sites/${s}.mailhost" 2>/dev/null || true)"
    tried="$(cat "${DIR}/sites/${s}.challenge" 2>/dev/null || true) $(cat "${DIR}/sites/${s}.previous" 2>/dev/null || true)"
    web="$(json_field "${DIR}/sites/${s}.json" hostname 2>/dev/null || true)"
    if [[ -e "${DIR}/sites/${s}.enabled" ]]; then
        "$0" off "${s}" >/dev/null 2>&1 || true
    fi
    rm -f "${DIR}/sites/${s}".{json,mailhost,relay,certattempt,failed,answer,challenge,previous}
    rm -rf "${CERTS:?}/${s}"
    for own in ${own} ${tried}; do
        [[ -n "${own}" && "${own}" != "${web}" ]] && drop_mail_name "${own}"
    done
    return 0
}

case "${VERB}" in
    on)
        if [[ -n "${MAILHOST}" && -n "${RELAY_IPS}" ]]; then
            set_relay_host "${MAILHOST}" "${RELAY_IPS}"
        elif [[ -n "${MAILHOST}" ]]; then
            set_mail_host "${MAILHOST}"
        fi
        touch "${DIR}/sites/${SITE}.enabled"
        write_credentials
        systemctl enable "${UNIT}" >/dev/null 2>&1
        # A certificate handed over by systemd (LoadCredential) is read only at
        # start, so new credentials need a restart; anything else is a reload,
        # which keeps every other site's connections.
        if [[ "${CREDENTIALS_CHANGED}" == "1" ]] || ! systemctl is-active --quiet "${UNIT}"; then
            systemctl restart "${UNIT}"
        else
            systemctl reload "${UNIT}"
        fi
        converge_access
        systemctl reload "${UNIT}" >/dev/null 2>&1 || true
        for _ in $(seq 1 20); do
            if listening 993 && listening 465; then
                echo "GATEWAY_ON"
                exit 0
            fi
            sleep 0.5
        done
        echo "joinery-mail-gateway: the gateway did not start listening on 993 and 465" >&2
        systemctl status "${UNIT}" --no-pager -n 20 >&2 || true
        exit 4
        ;;
    off)
        rm -f "${DIR}/sites/${SITE}.enabled"
        if [[ -z "$(served_sites)" ]]; then
            systemctl disable --now "${UNIT}" >/dev/null 2>&1 || true
            write_credentials
            converge_access
        else
            # Other sites are still served: a reload (SIGHUP) drops this one
            # from the registry without cutting the others' connections. Its
            # credential stays loaded, unused, until the next restart.
            write_credentials
            converge_access
            systemctl reload "${UNIT}"
        fi
        echo "GATEWAY_OFF"
        ;;
    converge)
        converge
        ;;
    access)
        # The firewall and proxy_from, from the served sites: run after the
        # installer replaces this helper, whose rules may have changed.
        converge_access
        echo "GATEWAY_ACCESS"
        ;;
    refresh)
        # The firewall and proxy_from are derived from the served sites, so an
        # update that changes which ports a route needs (10465 for relays, 1.7)
        # takes effect here, with no Stop and Allow.
        converge_access
        if [[ -n "$(served_sites)" ]]; then
            write_credentials
            systemctl restart "${UNIT}"
        fi
        echo "GATEWAY_REFRESHED"
        ;;
    remove)
        rm -f "${DIR}/sites/${SITE}.enabled" "${DIR}/sites/${SITE}.json" "${DIR}/sites/${SITE}.relay" \
            "${DIR}/sites/${SITE}.mailhost" "${DIR}/sites/${SITE}.certattempt"
        rm -rf "${CERTS:?}/${SITE}"
        if compgen -G "${DIR}/sites/*.json" >/dev/null; then
            # Another site on this machine still has the plugin: the gateway
            # stays for it, and only this site's credential is dropped.
            write_credentials
            converge_access
            if [[ -n "$(served_sites)" ]]; then
                systemctl reload "${UNIT}"
            fi
            echo "GATEWAY_REMOVED"
            exit 0
        fi
        systemctl disable --now "${UNIT}" >/dev/null 2>&1 || true
        converge_access
        rm -f "${DIR}/proxy_from" "${DIR}/ufw_relays"
        rmdir "${CERTS}" 2>/dev/null || true
        rm -rf "${DROPIN_DIR}"
        rm -f "/etc/systemd/system/${UNIT}.service"
        systemctl daemon-reload
        systemctl reset-failed "${UNIT}" >/dev/null 2>&1 || true
        # The mail host's certificate stays: that name is the mailbox plugin's
        # too, and certbot owns its lineage.
        rm -f /usr/local/lib/joinery/mail-gateway /etc/letsencrypt/renewal-hooks/deploy/joinery-mail-gateway
        rmdir /usr/local/lib/joinery 2>/dev/null || true
        rmdir "${DIR}/sites" "${DIR}" 2>/dev/null || true
        if id joinery-mailgw >/dev/null 2>&1; then
            userdel joinery-mailgw >/dev/null 2>&1 || true
        fi
        echo "GATEWAY_REMOVED"
        # Last: what lets the web user run this, and this helper itself.
        rm -f /etc/sudoers.d/joinery-mail-gateway /usr/local/sbin/joinery-mail-gateway
        exit 0
        ;;
    csr)
        valid_host "${MAILHOST}" || { echo "usage: joinery-mail-gateway csr SITE MAILHOST" >&2; exit 2; }
        install -d -m 700 "${CERTS}" "${CERTS}/${SITE}"
        ( umask 077 && openssl ecparam -name prime256v1 -genkey -noout -out "${CERTS}/${SITE}/privkey.pem.new" ) \
            || { echo "joinery-mail-gateway: the key could not be made" >&2; exit 5; }
        openssl req -new -key "${CERTS}/${SITE}/privkey.pem.new" -subj "/" \
            -addext "subjectAltName=DNS:${MAILHOST}" 2>/dev/null \
            || { echo "joinery-mail-gateway: the certificate request could not be made" >&2; exit 5; }
        echo "GATEWAY_CSR"
        ;;
    install-cert)
        valid_host "${MAILHOST}" || { echo "usage: joinery-mail-gateway install-cert SITE MAILHOST < chain.pem" >&2; exit 2; }
        if [[ ! -f "${CERTS}/${SITE}/privkey.pem.new" ]]; then
            echo "joinery-mail-gateway: no pending key for ${SITE} (run csr first)" >&2
            exit 5
        fi
        chain="$(mktemp)"
        head -c 65536 > "${chain}"
        if ! openssl x509 -in "${chain}" -noout -checkhost "${MAILHOST}" 2>/dev/null | grep -q "does match"; then
            rm -f "${chain}"
            echo "joinery-mail-gateway: the certificate does not name ${MAILHOST}" >&2
            exit 5
        fi
        if ! openssl x509 -in "${chain}" -noout -checkend 0 >/dev/null 2>&1; then
            rm -f "${chain}"
            echo "joinery-mail-gateway: the certificate has expired" >&2
            exit 5
        fi
        if [[ "$(openssl x509 -in "${chain}" -noout -pubkey 2>/dev/null)" != "$(openssl pkey -in "${CERTS}/${SITE}/privkey.pem.new" -pubout 2>/dev/null)" ]]; then
            rm -f "${chain}"
            echo "joinery-mail-gateway: the certificate is not for the key made here" >&2
            exit 5
        fi
        install -m 644 "${chain}" "${CERTS}/${SITE}/fullchain.pem"
        rm -f "${chain}"
        mv "${CERTS}/${SITE}/privkey.pem.new" "${CERTS}/${SITE}/privkey.pem"
        chmod 600 "${CERTS}/${SITE}/privkey.pem"
        # A renewal while the site is served: the gateway reads its certificate
        # at start (LoadCredential), so it restarts.
        if [[ -e "${DIR}/sites/${SITE}.enabled" && -f "${DIR}/sites/${SITE}.relay" ]]; then
            write_credentials
            systemctl restart "${UNIT}"
        fi
        echo "GATEWAY_CERT_INSTALLED"
        ;;
    status)
        echo "unit=$(systemctl is-active "${UNIT}" 2>/dev/null || true)"
        echo "sites=$(served_sites | tr '\n' ' ')"
        if [[ -e "${DIR}/sites/${SITE}.enabled" ]]; then echo "this_site=on"; else echo "this_site=off"; fi
        if [[ -f "${DIR}/sites/${SITE}.relay" ]]; then echo "relays=$(tr '\n' ' ' < "${DIR}/sites/${SITE}.relay")"; fi
        if [[ -f "${CERTS}/${SITE}/fullchain.pem" ]]; then
            echo "relay_cert_expires=$(date -u -d "$(openssl x509 -in "${CERTS}/${SITE}/fullchain.pem" -noout -enddate 2>/dev/null | cut -d= -f2)" +%s 2>/dev/null)"
        fi
        if listening 993; then echo "port993=listening"; else echo "port993=closed"; fi
        if listening 465; then echo "port465=listening"; else echo "port465=closed"; fi
        echo "GATEWAY_STATUS"
        ;;
    *)
        echo "usage: joinery-mail-gateway on|off|status|refresh|remove|csr|install-cert SITE, or converge | access" >&2
        exit 2
        ;;
esac
HELPERSCRIPT
HELPER_CHANGED=0
if ! cmp -s "${CANDIDATE}" "${HELPER}"; then
    install -m 755 "${CANDIDATE}" "${HELPER}"
    HELPER_CHANGED=1
fi
rm -f "${CANDIDATE}"

# --- 5. sudoers (a site machine: the web user runs the helper) ----------------------
# A Docker host has no web user of its own; only root's converge timer runs it.
if [[ "${MACHINE}" == "0" ]]; then
WEB_USER="www-data"
if ! id "${WEB_USER}" >/dev/null 2>&1; then
    WEB_USER="apache"
fi
SUDOERS="/etc/sudoers.d/joinery-mail-gateway"
CANDIDATE="$(mktemp)"
echo "${WEB_USER} ALL=(root) NOPASSWD: ${HELPER}" > "${CANDIDATE}"
if visudo -cf "${CANDIDATE}" >/dev/null 2>&1; then
    if ! cmp -s "${CANDIDATE}" "${SUDOERS}"; then
        install -m 440 "${CANDIDATE}" "${SUDOERS}"
    fi
else
    echo "mail gateway: the sudoers rule did not validate - not installed" >&2
fi
rm -f "${CANDIDATE}"
else
    # Every minute, the container sites' requests (the helper's converge).
    CONVERGE_UNIT=/etc/systemd/system/joinery-mail-gateway-converge.service
    CONVERGE_TIMER=/etc/systemd/system/joinery-mail-gateway-converge.timer
    CANDIDATE="$(mktemp)"
    printf '%s\n' '[Unit]' 'Description=Joinery mail gateway: serve the container sites that ask for mail apps' '' \
        '[Service]' 'Type=oneshot' "ExecStart=${HELPER} converge" > "${CANDIDATE}"
    if ! cmp -s "${CANDIDATE}" "${CONVERGE_UNIT}"; then
        install -m 644 "${CANDIDATE}" "${CONVERGE_UNIT}"
        CHANGED=1
    fi
    printf '%s\n' '[Unit]' 'Description=Joinery mail gateway: container sites, every minute' '' \
        '[Timer]' 'OnBootSec=1min' 'OnUnitActiveSec=1min' '' '[Install]' 'WantedBy=timers.target' > "${CANDIDATE}"
    if ! cmp -s "${CANDIDATE}" "${CONVERGE_TIMER}"; then
        install -m 644 "${CANDIDATE}" "${CONVERGE_TIMER}"
        CHANGED=1
    fi
    rm -f "${CANDIDATE}"
    systemctl daemon-reload
    systemctl enable --now joinery-mail-gateway-converge.timer >/dev/null 2>&1 || true
fi

# --- 6. systemd unit ---------------------------------------------------------------
UNIT_FILE="/etc/systemd/system/joinery-mail-gateway.service"
CANDIDATE="$(mktemp)"
cat > "${CANDIDATE}" <<'UNITFILE'
[Unit]
Description=Joinery mail gateway (IMAP 993 and SMTP submission 465 for mail programs)
After=network-online.target
Wants=network-online.target

[Service]
User=joinery-mailgw
Group=joinery-mailgw
ExecStart=/usr/local/lib/joinery/mail-gateway -config-dir /etc/joinery/mail-gateway
ExecReload=/bin/kill -HUP $MAINPID
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
MemoryMax=512M
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNITFILE
if ! cmp -s "${CANDIDATE}" "${UNIT_FILE}"; then
    install -m 644 "${CANDIDATE}" "${UNIT_FILE}"
    systemctl daemon-reload
    CHANGED=1
fi
rm -f "${CANDIDATE}"

# --- 7. certificate renewal --------------------------------------------------------
if [[ -d /etc/letsencrypt/renewal-hooks/deploy ]]; then
    HOOK=/etc/letsencrypt/renewal-hooks/deploy/joinery-mail-gateway
    CANDIDATE="$(mktemp)"
    printf '#!/bin/sh\n# A renewed certificate reaches the mail gateway through a restart (LoadCredential).\nsystemctl try-restart joinery-mail-gateway >/dev/null 2>&1 || true\n' > "${CANDIDATE}"
    if ! cmp -s "${CANDIDATE}" "${HOOK}"; then
        install -m 755 "${CANDIDATE}" "${HOOK}"
    fi
    rm -f "${CANDIDATE}"
fi

# --- 8. a served site picks up a new binary or entry --------------------------------
if [[ "${CHANGED}" == "1" ]]; then
    "${HELPER}" refresh ${SITE_NAME:+"${SITE_NAME}"} >/dev/null || true
elif [[ "${HELPER_CHANGED}" == "1" ]]; then
    # A new helper alone: its firewall rules apply now, with no restart.
    "${HELPER}" access >/dev/null || true
fi

if [[ "${MACHINE}" == "1" ]]; then
    echo "mail gateway: installed on this Docker host; serving $(ls /etc/joinery/mail-gateway/sites/*.enabled 2>/dev/null | wc -l) container site(s) that asked."
else
    echo "mail gateway: installed for ${SITE_NAME} (${HOST}); $(if [[ -e "/etc/joinery/mail-gateway/sites/${SITE_NAME}.enabled" ]]; then echo serving; else echo 'off until Allow mail apps'; fi)."
fi
