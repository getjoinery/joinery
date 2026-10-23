#!/usr/bin/env bash
# install_office_editor.sh - host installer for the office_editor plugin.
# Version: 0.1.1 - stops the editor the package starts on a fresh install; a container
#                site's refusal is recorded for the Plugins page like any other.
# Version: 0.1.0 - first version (specs/cloud_drive_office_suite.md § 3).
#
# Puts the document editor on this server, stopped: the site starts it when
# someone opens a document, and a cron job stops it after 30 minutes with no
# document open. Contract of a host installer: root, idempotent (runs on every
# converge), non-interactive, exit 0 when there is nothing to do here.
#
# What it installs:
#   - the editor (coolwsd and the office engine it depends on, all MPL 2.0) from
#     the vendor's own apt repository: this server downloads it, nothing is
#     hosted or redistributed by Joinery. The vendor's branding package, under
#     its own all-rights-reserved licence, is never installed;
#   - /etc/joinery/office_editor/coolwsd.xml, the packaged configuration with
#     every "fetch from this network" allowance removed (a document cannot make
#     the editor reach into the private network), and a systemd drop-in that
#     runs the editor with it and the settings below, and a memory cap;
#   - an Apache drop-in: /browser, /hosting/capabilities and /cool proxied to
#     the editor (websockets included), its conversion endpoints refused, and a
#     listener on 127.0.0.1:9981 only the editor can reach, which serves the
#     site's WOPI paths and nothing else;
#   - the editor's metrics on loopback (what it has open), which the site reads
#     to hold it under home mode's limits and the idle stop reads to know it is
#     idle;
#   - /usr/local/sbin/office-editor-ctl start|status|stop|idle-check, the first
#     two of which the web user may run as root (sudoers rule);
#   - /etc/cron.d/joinery-office-editor, the idle stop.
#
# A site container is not served in this version (spec § 1): said, and nothing
# installed. A server with under 2 GB of memory and no swap is refused, with
# the reason written where the Plugins page reads it.

set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "ERROR: run as root (sudo bash $0)." >&2
    exit 1
fi
export DEBIAN_FRONTEND=noninteractive

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SITE_ROOT="$(cd "${SCRIPT_DIR}/../../../.." && pwd)"
SITE_NAME="$(basename "${SITE_ROOT}")"
CONFIG_FILE="${SITE_ROOT}/config/Globalvars_site.php"
PHP_BIN="$(command -v php || true)"
STATE_DIR="/etc/joinery/office_editor"
MARK="${STATE_DIR}/installed"
REFUSED="${STATE_DIR}/refused"
CTL="/usr/local/sbin/office-editor-ctl"

say() { echo "office editor: $*"; }

refuse() {
    install -d -m 755 "${STATE_DIR}"
    echo "$1" > "${REFUSED}"
    chmod 644 "${REFUSED}"
    rm -f "${MARK}"
    say "$1 - nothing installed."
    exit 0
}

if [[ ! -f "${CONFIG_FILE}" || -z "${PHP_BIN}" ]]; then
    say "no site config or no php here - nothing to install."
    exit 0
fi
if [[ ! "${SITE_NAME}" =~ ^[a-z0-9_-]+$ ]]; then
    say "the site name '${SITE_NAME}' is not one this installer accepts - nothing installed." >&2
    exit 0
fi

read_site_setting() {
    "${PHP_BIN}" -r '
        $config = file_get_contents($argv[1]);
        echo preg_match("/settings\[.".$argv[2].".\]\s*=\s*.([^\x27\"]*)/", $config, $m) ? $m[1] : "";
    ' "${CONFIG_FILE}" "$1"
}

# Docker marks its containers with /.dockerenv, the site container engine with
# /run/.containerenv.
if [[ "$(read_site_setting deployment_environment)" == "docker" || -f /.dockerenv || -f /run/.containerenv ]]; then
    refuse "This site runs in a container; the editor is offered on servers of their own only"
fi
if ! command -v systemctl >/dev/null 2>&1 || ! command -v apt-get >/dev/null 2>&1; then
    refuse "This server has no systemd or apt; the editor needs both"
fi

ARCH="$(dpkg --print-architecture)"
if [[ "${ARCH}" != "amd64" && "${ARCH}" != "arm64" ]]; then
    refuse "The editor is published for amd64 and arm64 servers; this one is ${ARCH}"
fi

# The memory floor (spec § 6.2): 2 GB, or 1 GB with swap. A "2 GB" server
# reports a little under 2048 MB to the kernel, so the line is drawn at 1800.
MEM_MB=$(( $(awk '/^MemTotal:/ {print $2}' /proc/meminfo) / 1024 ))
SWAP_MB=$(( $(awk '/^SwapTotal:/ {print $2}' /proc/meminfo) / 1024 ))
if (( MEM_MB < 1800 && SWAP_MB < 512 )); then
    refuse "This server has ${MEM_MB} MB of memory and no swap; the editor needs 2 GB, or 1 GB with swap"
fi

# ---------------------------------------------------------------- packages
KEYRING="/usr/share/keyrings/collaboraonline-release-keyring.gpg"
SOURCES="/etc/apt/sources.list.d/collaboraonline.sources"
REPO_URL="https://www.collaboraoffice.com/repos/CollaboraOnline/CODE-deb"
if [[ ! -s "${KEYRING}" ]]; then
    curl -fsSL --max-time 60 https://collaboraoffice.com/downloads/gpg/collaboraonline-release-keyring.gpg -o "${KEYRING}.new" \
        || refuse "The editor's package signing key could not be downloaded; try again later"
    mv "${KEYRING}.new" "${KEYRING}"
    chmod 644 "${KEYRING}"
fi
WANT_SOURCES="Types: deb
URIs: ${REPO_URL}
Suites: ./
Signed-By: ${KEYRING}"
if [[ ! -f "${SOURCES}" ]] || [[ "$(cat "${SOURCES}")" != "${WANT_SOURCES}" ]]; then
    printf '%s\n' "${WANT_SOURCES}" > "${SOURCES}"
    chmod 644 "${SOURCES}"
    APT_UPDATE=1
fi
if ! dpkg-query -W -f='${Status}' coolwsd 2>/dev/null | grep -q "install ok installed"; then
    APT_UPDATE=1
fi
if [[ "${APT_UPDATE:-0}" == "1" ]]; then
    apt-get update -qq -o Dir::Etc::sourcelist="${SOURCES}" -o Dir::Etc::sourceparts="-" -o APT::Get::List-Cleanup="0" \
        || refuse "The editor's package list could not be read; try again later"
    # Recommends left out: the vendor's branding package is recommended and is
    # not ours to install (spec § 1, licensing).
    apt-get install -y -qq --no-install-recommends coolwsd \
        || refuse "The editor's packages could not be installed; see the installer log"
    FRESH_INSTALL=1
fi
if dpkg-query -W -f='${Status}' code-brand 2>/dev/null | grep -q "install ok installed"; then
    apt-get remove -y -qq code-brand || true
fi
# The site starts it when someone opens a document; nothing starts it at boot.
systemctl disable coolwsd >/dev/null 2>&1 || true

# ---------------------------------------------------------- configuration
install -d -m 755 "${STATE_DIR}"
PACKAGED_CONF="/etc/coolwsd/coolwsd.xml"
CONF="${STATE_DIR}/coolwsd.xml"
[[ -f "${PACKAGED_CONF}" ]] || refuse "The editor's packaged configuration is missing"
# The packaged file with the inside of lok_allow and post_allow taken out: they
# let a document pull data from (and REST callers reach) loopback and every
# private range, which on a shared cloud network is other people's machines.
# The WOPI host below is still allowed - storage entries always are.
CANDIDATE="$(mktemp)"
sed -e '/<lok_allow\b/,/<\/lok_allow>/{/<lok_allow\b/!{/<\/lok_allow>/!d}}' \
    -e '/<post_allow\b/,/<\/post_allow>/{/<post_allow\b/!{/<\/post_allow>/!d}}' \
    "${PACKAGED_CONF}" > "${CANDIDATE}"
if ! cmp -s "${CANDIDATE}" "${CONF}"; then
    install -m 640 -o root -g cool "${CANDIDATE}" "${CONF}"
    RESTART=1
fi
rm -f "${CANDIDATE}"

# The packaged unit's command line, with this server's settings after it.
# Read from the package's own unit file, never `systemctl cat`, which also
# prints this installer's drop-in. A package upgrade that changes the line is
# picked up at the next converge.
UNIT_FILE="$(systemctl show -p FragmentPath --value coolwsd 2>/dev/null || true)"
PACKAGED_EXEC=""
if [[ -n "${UNIT_FILE}" && -f "${UNIT_FILE}" ]]; then
    PACKAGED_EXEC="$(sed -n 's/^ExecStart=\(\/.*coolwsd .*\)$/\1/p' "${UNIT_FILE}" | head -n 1)"
fi
[[ -n "${PACKAGED_EXEC}" ]] || refuse "The editor's service unit has no command line this installer understands"
# A memory cap: past it the editor's overflow goes to swap and opens slow
# down; the database and web server are never the ones squeezed (spec § 6.2).
MEM_CAP_MB=$(( MEM_MB / 2 ))
(( MEM_CAP_MB < 400 )) && MEM_CAP_MB=400
DROPIN_DIR="/etc/systemd/system/coolwsd.service.d"
DROPIN="${DROPIN_DIR}/joinery.conf"
WANT_DROPIN="# Written by ${SCRIPT_DIR}/install_office_editor.sh - do not edit.
[Service]
ExecStart=
ExecStart=${PACKAGED_EXEC} --config-file=${CONF} --o:net.listen=loopback --o:net.proto=IPv4 --o:ssl.enable=false --o:ssl.termination=true --o:storage.wopi.alias_groups[@mode]=groups --o:storage.wopi.alias_groups.group[0].host[0]=http://127.0.0.1:9981 --o:storage.wopi.alias_groups.group[0].host[0][@allow]=true --o:num_prespawn_children=1 --o:home_mode.enable=true --o:admin_console.enable=true --o:security.enable_metrics_unauthenticated=true --o:logging.level=warning
Restart=on-failure
MemoryMax=${MEM_CAP_MB}M"
install -d -m 755 "${DROPIN_DIR}"
if [[ ! -f "${DROPIN}" ]] || [[ "$(cat "${DROPIN}")" != "${WANT_DROPIN}" ]]; then
    printf '%s\n' "${WANT_DROPIN}" > "${DROPIN}"
    chmod 644 "${DROPIN}"
    systemctl daemon-reload
    RESTART=1
fi
if [[ "${FRESH_INSTALL:-0}" == "1" ]]; then
    # The package starts the editor as it installs; nobody has opened a
    # document yet, so it stops until someone does.
    systemctl stop coolwsd || true
elif [[ "${RESTART:-0}" == "1" ]] && systemctl is-active --quiet coolwsd; then
    systemctl restart coolwsd || true
fi

# ------------------------------------------------------------------ apache
APACHE_CONF="/etc/apache2/conf-available/joinery-office-editor.conf"
CANDIDATE="$(mktemp)"
cat > "${CANDIDATE}" <<APACHE
# Written by ${SCRIPT_DIR}/install_office_editor.sh - do not edit.
#
# The browser reaches the document editor through this site's own address:
# its pages, its websocket and the capability check. retry=0 because the
# editor is started on demand: without it, one refused connection while it was
# stopped would keep Apache from trying again for a minute. disablereuse=On so
# no idle connection to the editor outlives the browser that made it - the
# idle stop counts open connections.
<Location /browser>
    ProxyPreserveHost On
    ProxyPass http://127.0.0.1:9980/browser retry=0 disablereuse=On
    ProxyPassReverse http://127.0.0.1:9980/browser
</Location>
<Location /hosting/capabilities>
    ProxyPreserveHost On
    ProxyPass http://127.0.0.1:9980/hosting/capabilities retry=0 disablereuse=On
    ProxyPassReverse http://127.0.0.1:9980/hosting/capabilities
</Location>
<Location /cool>
    ProxyPreserveHost On
    ProxyPass http://127.0.0.1:9980/cool upgrade=websocket retry=0 disablereuse=On
    ProxyPassReverse http://127.0.0.1:9980/cool
</Location>
# The editor's conversion endpoints, its metrics and its admin console are
# not offered to anyone. (The console is on only because the metrics need it;
# with no admin user configured it admits nobody, even on loopback.)
<LocationMatch "^/cool/(convert-to|extract-|get-thumbnail|render-search-result|getMetrics|adminws)">
    Require all denied
</LocationMatch>
<LocationMatch "^/browser/[^/]+/admin/|^/browser/dist/admin/">
    Require all denied
</LocationMatch>

# Where the editor reaches the site (OfficeEditorWopi): on loopback only, and
# the WOPI paths only. The variable is how the site knows a request came in
# here; nothing else sets it.
# Paths outside /office_editor/wopi/ are refused here; the ones that pass reach
# serve.php through the site's <Directory .../public_html> rewrite, which every
# vhost template keeps at server level, outside its VirtualHosts.
Listen 127.0.0.1:9981
<VirtualHost 127.0.0.1:9981>
    ServerName office-editor-wopi.invalid
    DocumentRoot ${SITE_ROOT}/public_html
    SetEnv JOINERY_OFFICE_WOPI 1
    ErrorLog ${SITE_ROOT}/logs/error.log
    # The request line as sent: the site's rewrite hands the request on to
    # serve.php internally, and this rule runs again for that, so it must not
    # read the rewritten path. A request for /serve.php itself is refused too.
    RewriteEngine On
    RewriteCond %{THE_REQUEST} !^(GET|POST)\ /office_editor/wopi/files/[0-9]+(/contents)?(\?[^\ ]*)?\ HTTP/
    RewriteRule ^ - [F]
</VirtualHost>
APACHE
APACHE_CHANGED=0
if ! cmp -s "${CANDIDATE}" "${APACHE_CONF}"; then
    install -m 644 "${CANDIDATE}" "${APACHE_CONF}"
    APACHE_CHANGED=1
fi
rm -f "${CANDIDATE}"
for mod in proxy proxy_http rewrite headers; do
    if [[ ! -e "/etc/apache2/mods-enabled/${mod}.load" ]]; then
        a2enmod -q "${mod}" >/dev/null
        APACHE_CHANGED=1
    fi
done
if [[ ! -e /etc/apache2/conf-enabled/joinery-office-editor.conf ]]; then
    a2enconf -q joinery-office-editor >/dev/null
    APACHE_CHANGED=1
fi
if [[ "${APACHE_CHANGED}" == "1" ]]; then
    if apachectl configtest >/dev/null 2>&1; then
        systemctl reload apache2
    else
        a2disconf -q joinery-office-editor >/dev/null || true
        refuse "Apache refused the editor's configuration ($(apachectl configtest 2>&1 | tail -n 1))"
    fi
fi

# --------------------------------------------------- control, sudo, cron
CANDIDATE="$(mktemp)"
cp "${SCRIPT_DIR}/office-editor-ctl" "${CANDIDATE}"
if ! cmp -s "${CANDIDATE}" "${CTL}"; then
    install -m 755 -o root -g root "${CANDIDATE}" "${CTL}"
fi
rm -f "${CANDIDATE}"

WEB_USER="www-data"
if ! id -u "${WEB_USER}" >/dev/null 2>&1; then
    refuse "The web server user ${WEB_USER} does not exist here"
fi
SUDOERS="/etc/sudoers.d/joinery-office-editor"
CANDIDATE="$(mktemp)"
echo "${WEB_USER} ALL=(root) NOPASSWD: ${CTL} start, ${CTL} status" > "${CANDIDATE}"
if visudo -cf "${CANDIDATE}" >/dev/null 2>&1; then
    if ! cmp -s "${CANDIDATE}" "${SUDOERS}"; then
        install -m 440 "${CANDIDATE}" "${SUDOERS}"
    fi
    rm -f "${CANDIDATE}"
else
    rm -f "${CANDIDATE}"
    refuse "The sudoers rule for the editor did not validate"
fi

CRON="/etc/cron.d/joinery-office-editor"
WANT_CRON="# Written by ${SCRIPT_DIR}/install_office_editor.sh - stops the document editor after 30 idle minutes.
* * * * * root ${CTL} idle-check >/dev/null 2>&1"
if [[ ! -f "${CRON}" ]] || [[ "$(cat "${CRON}")" != "${WANT_CRON}" ]]; then
    printf '%s\n' "${WANT_CRON}" > "${CRON}"
    chmod 644 "${CRON}"
fi

rm -f "${REFUSED}"
date -u +%Y-%m-%dT%H:%M:%SZ > "${MARK}"
chmod 644 "${MARK}"
say "installed (memory cap ${MEM_CAP_MB} MB); starts when someone opens a document."
