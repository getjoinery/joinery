#!/usr/bin/env bash
# install_mail_gateway.sh - host installer for the mail_apps plugin.
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

# --- 3. this site's entry -------------------------------------------------------
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

# --- 4. the root helper -----------------------------------------------------------
HELPER="/usr/local/sbin/joinery-mail-gateway"
CANDIDATE="$(mktemp)"
cat > "${CANDIDATE}" <<'HELPERSCRIPT'
#!/usr/bin/env bash
# joinery-mail-gateway on|off|status|refresh|remove|csr|install-cert SITE [MAILHOST [RELAY_IPS]]
# on:      serve SITE (sites/SITE.enabled) by MAILHOST — the deployment's mail
#          host name, which reaches this server even when the web name sits
#          behind a CDN. Issues MAILHOST's certificate with certbot when there
#          is none yet, if the name points at this machine. Starts the gateway and
#          opens 993+465                                                   -> GATEWAY_ON
# off:     stop serving SITE; stop the gateway and close the ports when no
#          site is left                                                    -> GATEWAY_OFF
# status:  the unit, the ports, the sites served                           -> GATEWAY_STATUS
# refresh: re-hand the certificates and restart, when any site is served   -> GATEWAY_REFRESHED
# on SITE MAILHOST RELAY_IPS: the deployment's mail arrives through a relay,
#          and programs reach the relay's name (MAILHOST); the relay splices
#          their TLS here (specs/mail_apps_plugin.md § Where programs connect).
#          The certificate must be installed first (csr, install-cert); 993 and
#          465 open to RELAY_IPS (comma-separated) only, whose PROXY line the
#          gateway then believes (proxy_from), and the site answers nobody else.
# csr:     a new key for SITE's relay name (kept here, root only) and its
#          certificate request, printed                                    -> GATEWAY_CSR
# install-cert SITE MAILHOST: the chain the relay issued, on stdin; it must
#          match the key and name MAILHOST                                 -> GATEWAY_CERT_INSTALLED
# remove:  the plugin is uninstalled on SITE: forget the site, and with no
#          site left take the gateway off this machine - unit, binary, user,
#          renewal hook, sudoers rule, this helper                         -> GATEWAY_REMOVED
# Installed by plugins/mail_apps/provisioning/install_mail_gateway.sh; run
# through sudo by the web user. Takes a site name, never a path.
set -euo pipefail
VERB="${1:-}"
SITE="${2:-}"
MAILHOST="$(echo "${3:-}" | tr 'A-Z' 'a-z')"
RELAY_IPS="${4:-}"
DIR=/etc/joinery/mail-gateway
CERTS="${DIR}/certs"
UNIT=joinery-mail-gateway
DROPIN_DIR=/etc/systemd/system/${UNIT}.service.d

# remove may find its site already forgotten (a second run): that is done, not an error.
if [[ ! "${SITE}" =~ ^[a-z0-9_-]+$ ]] || { [[ "${VERB}" != "remove" ]] && [[ ! -f "${DIR}/sites/${SITE}.json" ]]; }; then
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
    php -r '$j = json_decode(file_get_contents($argv[1]), true); echo is_array($j) && isset($j[$argv[2]]) ? $j[$argv[2]] : "";' "$1" "$2"
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
        echo "joinery-mail-gateway: a certificate for ${host} was tried less than an hour ago; try again later." >&2
        exit 5
    fi
    echo "${now}" > "${marker}"
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
    php -r '
        $p = $argv[1]; $j = json_decode(file_get_contents($p), true);
        if (!is_array($j)) { fwrite(STDERR, "unreadable site entry\n"); exit(1); }
        foreach (array_slice($argv, 2) as $kv) {
            list($k, $v) = explode("=", $kv, 2);
            $j[$k] = $v === "true" ? true : ($v === "false" ? false : $v);
        }
        $tmp = $p . ".new"; file_put_contents($tmp, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        chmod($tmp, 0644); rename($tmp, $p);
    ' "${DIR}/sites/${SITE}.json" "$@"
}

valid_host() {
    [[ "$1" =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]]
}

valid_ip() {
    php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP) === false ? 1 : 0);' "$1"
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
# through it; closed otherwise. The relay rules this helper added are recorded
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
            for port in 993 465; do
                ufw delete allow proto tcp from "${ip}" to any port "${port}" >/dev/null 2>&1 || true
            done
        fi
    done
    for ip in ${relays}; do
        for port in 993 465; do
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
    refresh)
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
        echo "usage: joinery-mail-gateway on|off|status|refresh|remove|csr|install-cert SITE" >&2
        exit 2
        ;;
esac
HELPERSCRIPT
if ! cmp -s "${CANDIDATE}" "${HELPER}"; then
    install -m 755 "${CANDIDATE}" "${HELPER}"
fi
rm -f "${CANDIDATE}"

# --- 5. sudoers ---------------------------------------------------------------------
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
    "${HELPER}" refresh "${SITE_NAME}" >/dev/null || true
fi

echo "mail gateway: installed for ${SITE_NAME} (${HOST}); $(if [[ -e "/etc/joinery/mail-gateway/sites/${SITE_NAME}.enabled" ]]; then echo serving; else echo 'off until Allow mail apps'; fi)."
