#!/usr/bin/env bash
#VERSION 2.42 - The help text reads its version from the header above instead
#              of a hand-kept copy, which had drifted to 2.7 and told anyone
#              running --help they had a script 34 releases older than theirs.
#VERSION 2.41 - The database password never enters the image. It was a
#              --build-arg promoted to ENV, so docker inspect and docker
#              history kept it; now it arrives at run time via --env-file.
#VERSION 2.40 - The database password no longer has to travel in argv, where
#              ps exposes it to every account on the box. It goes to
#              _site_init.sh in the environment, and a positional one warns.
#VERSION 2.39 - PostgreSQL config this script creates gets an explicit mode
#              rather than the caller umask. A 0600 drop-in stops the server
#              starting at all, and the install then fails somewhere else.
#VERSION 2.38 - A package apt removed but did not purge no longer reads as
#              installed. dpkg -s exits 0 for it; the Status field does not.
#VERSION 2.37 - PostgreSQL logs connections and the address they came from, via
#              a conf.d drop-in. Successful logins were not logged at all and
#              failures carried no client address, so a box under attack could
#              report how many attempts it refused but not whether any had
#              succeeded. Found the hard way: the dev box was answering
#              password attempts from the public internet with no way to tell
#              whether any had worked.
#VERSION 2.36 - The generated pg_hba.conf names scram-sha-256, which is what the
#              roles on these boxes have held since PostgreSQL 14 defaulted
#              password_encryption to it - md5 was a word that had stopped
#              meaning anything, and PostgreSQL 18 deprecates it. The method is
#              one variable, so the rules and the post-password restore cannot
#              describe different things, and both seds match the method as a
#              field rather than as an exact line. A restore that does not take
#              is now a stop: sed exits 0 on no match, so the box was otherwise
#              left granting trust on the postgres socket with nothing said.
#VERSION 2.35 - The server setup no longer names a PHP version anywhere. It
#              detects one - keeping the box's existing PHP if it has one, else
#              asking apt what it would install - and derives every package,
#              service, Apache conf and ini path from it. An undetectable
#              version and a missing fpm ini are both stops: each would have
#              configured /etc/php//fpm/ and reported success. The OS gate
#              accepts 24.04 and 26.04, and reads as what has been tested rather
#              than what the script can express, which is what it now is.
#VERSION 2.34 - Stop installing php-imap. PHP 8.4 unbundled ext/imap to PECL, so
#              no distro ships php8.5-imap and the whole apt install would abort
#              on 26.04 over an extension nothing in the platform uses. Inbound
#              mail speaks IMAP through bytestream/horde-imap-client, which is
#              pure PHP; the one ext/imap caller was a manual email-analysis
#              tool, which now reports the extension as absent instead of
#              telling the operator to install a package that no longer exists.
#VERSION 2.33 - The forward-only guard covers bare metal too, not just Docker.
#              An install over an existing bare-metal site rsyncs the archive
#              onto the live tree with no --delete, so an older archive merges
#              rather than replaces: shared files roll back, newer-release files
#              stay, and VERSION names the older release — a tree no release
#              shipped, against a forward-migrated database. With -y there was
#              no prompt at all. The guard runs before the overwrite prompt, so
#              a refusal has touched nothing, and --wipe-data is not a bypass
#              there (it deletes volumes, of which bare metal has none).
#VERSION 2.32 - Only default_Globalvars_site.php goes into a site image's
#              config/, never the directory wholesale. A release archive holds
#              just the template, but `install.sh site` run from a live site
#              directory copied that site's Globalvars_site.php — database
#              password, secret_box_key — along with any signing, provisioning
#              or relay keys kept beside it, into an image layer. It also broke
#              the new site: a Globalvars_site.php in the image makes the
#              container skip _site_init.sh, so no database is ever created.
#              .dockerignore allowlists config/ for the same reason.
#VERSION 2.31 - Two data-loss fixes and one silent-breakage fix:
#              (1) A rebuild only deletes data volumes under --wipe-data. The
#              interactive path deleted every volume — database, uploads,
#              storage, config, backups — on a plain yes to a rebuild prompt,
#              ignoring the flag that exists to gate exactly that, and undoing
#              the 2.29 guard for sites whose code is on a volume. Both removal
#              sites now share one ALL_SITE_VOLUMES list.
#              (2) Declared PHP extensions are installed at every container
#              start, not just on bare metal. They are apt packages in the
#              writable layer, so a rebuild dropped them; composer validation
#              then failed and update_database was skipped in silence.
#              _install_declared_dependencies.sh is the shared implementation.
#VERSION 2.30 - Site code lives on named volumes (spec
#              container_rebuild_never_downgrades, part 2 phase A): public_html,
#              vendor and maintenance_scripts each get one, so removing and
#              recreating a container no longer discards the code an in-place
#              upgrade wrote. Docker seeds a new volume from the image and
#              leaves a populated one alone, which is the roll-forward rule the
#              spec asked for. The 2.29 guard now skips sites whose code volume
#              is already populated, since a rebuild there cannot write code.
#VERSION 2.29 - A site rebuild refuses unless it can prove it moves the site's
#              code forward (spec container_rebuild_never_downgrades, part 1).
#              Site code lives in the container's writable layer, so a rebuild
#              from a stale archive root drops months-old code onto a database
#              that has already migrated forward. The check compares the
#              running container's VERSION against the archive's with sort -V
#              semantics, runs before anything stops or removes the container,
#              refuses when either version is unreadable, is skipped by
#              --wipe-data, and is overridden only by --allow-downgrade.
#VERSION 2.28 - BASE_IMAGE_VERSION 1.1 -> 1.2. The 2.27 SAPI switch changed
#              do_server_setup (packages and Apache modules), which is exactly
#              what the base image bakes — without the bump, a container build
#              reuses joinery-base:1.1 and ships mod_php no matter what this
#              script now says.
#VERSION 2.27 - PHP serves via php-fpm (event MPM + proxy_fcgi), never mod_php:
#              the AI chat async turn path needs fastcgi_finish_request(),
#              which only the fpm SAPI provides. mod_php/prefork silently
#              disabled live streaming and turn activity on every install.
#              Package list drops libapache2-mod-php8.3, php.ini tuning moved
#              to the fpm SAPI, prefork tuning replaced by event tuning.
#VERSION 2.26 - Core half of spec linode_stackscript:
#              (1) UPGRADE_SERVER defaults to https://getjoinery.com — the
#              published one-liner handed every public install a dev build.
#              (2) `server` hard-fails off Ubuntu 24.04 (PHP 8.3 paths are
#              hardcoded throughout, so continuing produced a half-configured
#              box), with --allow-unsupported-os to proceed anyway.
#              (3) php.ini gets date.timezone = UTC, matching the CLI and
#              Docker defaults and the platform's UTC-in-the-database doctrine.
#              (4) A deferred certificate now installs a self-disabling systemd
#              timer that resolves the domain before each certbot attempt, so a
#              site whose DNS lands later gets HTTPS with no operator action.
#              (5) `site` takes --admin-email=, passed to _site_init.sh.
#VERSION 2.25 - Three installer defects (spec installer_defects):
#              (1) `site` no longer aborts when a domain's DNS isn't pointing
#              here yet — it warns, installs on HTTP, and names setup_ssl.sh in
#              the summary. provision_origin_cert was already failure-tolerant
#              and the vhost guards :443 with <IfFile>, so the gate was the only
#              thing turning "no cert yet" into "no site".
#              (2) `server` derives a reachable SSH account (new
#              derive_ssh_access) before disabling root login, mirroring root's
#              keys to user1 with NOPASSWD sudo, or declining to touch
#              PermitRootLogin when nothing else can reach the box. Also stops
#              actively enabling PasswordAuthentication.
#              (3) The summary prints the per-site admin password that
#              _site_init.sh now generates, replacing the seeded default.
#VERSION 2.24 - Postgres is local-only by default. Bare metal: listen_addresses
#              = localhost + loopback-only pg_hba (no 0.0.0.0/0 rule). Docker:
#              the container DB port publish binds 127.0.0.1 (docker's iptables
#              bypass ufw, so an unbound -p exposed every container's Postgres
#              to the internet regardless of firewall); in-container Postgres
#              still listens on '*' for the bridge path, pg_hba scoped to
#              172.16.0.0/12. Nothing in the managed flow connects to Postgres
#              remotely — jobs run over SSH / docker exec.
#VERSION 2.23 - Bare-metal firewall no longer opens 5432 to the world. Nothing
#              in the managed flow connects to Postgres remotely (jobs run
#              psql/pg_dump on the node over SSH), so the rule was pure attack
#              surface. Found on the first bare-metal customer-cloud node.
#VERSION 2.22 - Declared-dependency install (spec plugin_dependency_installation):
#               new install_declared_dependencies() reads the deployed source's
#               dependency declarations via utils/list_dependencies.php --apt and
#               installs the PHP extensions it emits, replacing reliance on the
#               hardcoded bootstrap list for anything the code declares. Wired
#               into the bare-metal site path (the Docker path gets the same
#               step at image build via Dockerfile.template). Bare-metal site
#               builds also run _plugin_installers_start.sh (the generalized
#               successor of _mail_stack_start.sh) after _site_init.sh.
#VERSION 2.21 - Add www redirect vhost to all generated proxy configs so that
#               www.domain requests redirect to https://domain instead of
#               falling through to the default vhost (wrong site).
#               Extracted write_proxy_conf() helper to avoid duplicating the
#               vhost template across three code paths.
#VERSION 2.19 - Fix domain argument parsing: when no password is given and DOMAIN_NAME is already
#               set, a port-like arg (all digits) now correctly goes to PORT instead of
#               overwriting DOMAIN_NAME (bug: ./install.sh -y site foo 1.2.3.4 8080 set webDir=8080).
#VERSION 2.18 - Right-size Apache MPM prefork (ServerLimit/MaxRequestWorkers 50, MaxConnectionsPerChild 2000)
#               and Postgres shared_buffers (128MB -> 64MB) for low-traffic container sites.
#VERSION 2.17 - Rebuild safety: preflight-stop the target container before the port check
#               so rebuilds of a running site don't auto-pick a stray alternate port;
#               cd out of BUILD_DIR before removing it to avoid getcwd() warnings.
#
# Usage:
#   ./install.sh docker                              # One-time: install Docker
#   ./install.sh host-harden                          # One-time: harden a Docker host server
#   ./install.sh build-base                          # One-time per host: build joinery-base image
#   ./install.sh server [--allow-unsupported-os]     # One-time: set up bare-metal server
#   ./install.sh site SITENAME [DOMAIN] [PORT]      # Create a site (auto-generates password)
#   ./install.sh list                                # List existing sites
#
# Global Options:
#   -y, --yes     Auto-accept all prompts (non-interactive mode)
#   -q, --quiet   Suppress most output, show only errors and final status
#
# Site Options:
#   --password-file=FILE   Read database password from file (recommended for special chars)
#   --admin-email=EMAIL    Address for the site's admin account (default admin@example.com)
#   --activate THEME       Set active theme after installation
#   --with-test-site       Create companion test site (bare-metal only)
#   --no-ssl               Skip automatic SSL certificate setup
#   --themes               Download themes/plugins from distribution server
#   --upgrade-server=URL   Override default distribution server
#   --clone-from=URL       Clone database and uploads from existing site
#   --clone-key=KEY        Authentication key for clone source
#
# Password Handling:
#   If no password is provided, a secure 24-character password is auto-generated.
#   That is the recommended form: nothing has to be typed, so nothing is exposed.
#
#   To choose the password yourself, pass it in a file (--password-file) or in
#   the environment (POSTGRES_PASSWORD=...). A password given as a positional
#   argument is readable by every account on this machine through ps for as long
#   as the install runs, and stays in the shell history of whoever ran it; the
#   script warns when that happens. It is still accepted, so existing automation
#   keeps working.
#
#   --password-file also avoids shell escaping issues with characters like !.
#
# SSL Behavior:
#   SSL is automatically configured when a domain name is provided (not localhost/IP).
#   DNS must point to this server for SSL setup to succeed.
#   Use --no-ssl to skip SSL setup.
#
# Examples:
#   # Auto-generate secure password (recommended)
#   sudo ./install.sh site mysite mysite.com 8080
#
#   # Choose the password yourself, without putting it in ps or shell history
#   (umask 077; echo 'MyP@ss!word' > /tmp/dbpass.txt)
#   sudo ./install.sh site mysite --password-file=/tmp/dbpass.txt mysite.com 8080
#   rm -f /tmp/dbpass.txt
#
#   # Skip SSL setup
#   sudo ./install.sh site mysite mysite.com --no-ssl
#
# See INSTALL_README.md for complete documentation.

set -e  # Exit on error
set +H  # Disable history expansion (prevents ! in passwords from being interpreted)

# Get the directory where this script is located
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

#==============================================================================
# GLOBAL CONSTANTS
#==============================================================================

# joinery-base image tag. Bump when Dockerfile.base or do_server_setup changes
# (Ubuntu version, PHP version, new apt packages, new system config, etc.).
# After bumping: run './install.sh build-base' on each host, then rebuild sites.
BASE_IMAGE_VERSION="1.2"

# This script's own version, read from the newest #VERSION header above rather
# than restated here, so the number the help text prints is the number the file
# actually carries. A second copy drifts the moment someone bumps the header.
INSTALLER_VERSION="$(sed -n 's/^#VERSION \([0-9][0-9.]*\).*/\1/p' "${BASH_SOURCE[0]}" | head -1)"
[ -n "$INSTALLER_VERSION" ] || INSTALLER_VERSION="unknown"

#==============================================================================
# GLOBAL FLAGS (parsed before command dispatch)
#==============================================================================

ASSUME_YES=0      # -y/--yes: Auto-accept all prompts (never deletes volumes on its own)
WIPE_DATA=0       # --wipe-data: Also delete data volumes when removing an existing container (requires -y)
ALLOW_DOWNGRADE=0 # --allow-downgrade: Rebuild a site even when the archive's code is older than what it is running
QUIET_MODE=0      # -q/--quiet: Suppress most output
CLOUDFLARE_PROXY=0  # Set to 1 if domain is behind Cloudflare proxy
SSL_DEFERRED=0      # Set to 1 when DNS wasn't ready, so the closing summary can say so
SSH_ROOT_LOGIN_SAFE=0    # Set by derive_ssh_access: 1 when disabling root SSH orphans nobody
SSH_REACHABLE_ACCOUNT="" # Set by derive_ssh_access: the account that keeps access

#==============================================================================
# HELPER FUNCTIONS (from docker_install_master.sh)
#==============================================================================

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_header() {
    [ "$QUIET_MODE" -eq 1 ] && return
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  $1${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
}

print_step() {
    [ "$QUIET_MODE" -eq 1 ] && return
    echo -e "${GREEN}[STEP]${NC} $1"
}

print_info() {
    [ "$QUIET_MODE" -eq 1 ] && return
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_warning() {
    # Warnings always shown (even in quiet mode)
    echo -e "${YELLOW}[WARN]${NC} $1"
}

print_error() {
    # Errors always shown (even in quiet mode)
    echo -e "${RED}[ERROR]${NC} $1"
}

print_success() {
    [ "$QUIET_MODE" -eq 1 ] && return
    echo -e "${GREEN}[OK]${NC} $1"
}

# Final summary output (always shown, even in quiet mode)
print_final() {
    echo -e "$1"
}

#==============================================================================
# ADMIN CREDENTIAL REPORTING
#==============================================================================

# _site_init.sh gives every fresh site its own admin password and, unless the
# password was supplied through JOINERY_ADMIN_PASSWORD, writes it to
# config/admin_credentials.txt. Surface it here so the operator does not have to
# know the file exists.
#
# $1 = path to the credentials file
# $2 = optional command for reading it (default cat; docker sites need docker exec)
# $3 = optional command to show the operator for re-reading it later
print_admin_login() {
    local cred_path="$1"
    local reader="${2:-cat}"
    local show_cmd="${3:-cat $cred_path}"
    local password=""
    local email=""

    password=$($reader "$cred_path" 2>/dev/null | grep '^Password: ' | cut -d' ' -f2- || true)
    email=$($reader "$cred_path" 2>/dev/null | grep '^Email: ' | cut -d' ' -f2- || true)
    [ -n "$email" ] || email="${JOINERY_ADMIN_EMAIL:-admin@example.com}"

    echo "Admin login:"
    echo -e "  Email:    ${YELLOW}${email}${NC}"
    if [ -n "$password" ]; then
        echo -e "  Password: ${YELLOW}${password}${NC}"
        echo ""
        echo "You will be asked to choose a new password at first sign-in."
        echo -e "To see it again: ${BLUE}sudo ${show_cmd}${NC}"
    else
        echo -e "  Password: ${YELLOW}the one supplied at install time${NC}"
    fi
    echo ""
    print_email_setup_notice
}

# Configuring an email provider is the first task on any new deployment, and
# the one that decides whether a forgotten password is a nuisance or a locked
# door. Said unconditionally rather than only when it looks unconfigured: a
# detection rule that guesses wrong is worse than one extra line for an admin
# who has already done it.
print_email_setup_notice() {
    echo -e "${YELLOW}First task: set up email${NC}"
    echo -e "  ${BLUE}http://${DOMAIN_NAME}/admin/admin_settings_email${NC}"
    echo ""
    echo "  This site cannot send mail until you name a provider, and password reset"
    echo "  is how you get back in if the admin password is ever lost. Most hosts"
    echo "  block outbound port 25, so a mail server on this machine will not deliver."
    echo ""
}

#==============================================================================
# SSH ACCESS DERIVATION
#==============================================================================

# Work out which account will still be able to reach this box once root SSH
# login is disabled, and make one true where we can. Sets:
#
#   SSH_ROOT_LOGIN_SAFE     1 when disabling root login orphans nobody
#   SSH_REACHABLE_ACCOUNT   the account that keeps access (informational)
#
# Three cases:
#
#   1. Running as root with keys in /root/.ssh/authorized_keys — copy them to
#      user1 and grant it passwordless sudo. Root login can then be disabled.
#   2. Running under sudo from a normal account — that account already holds a
#      credential and sudo, so root login can be disabled with nothing to do.
#   3. Neither (root reached by password, no key) — the only way in is root.
#      Leave root login alone and print the remedy.
#
# This is the same pre-stage the control plane performs before running
# `install.sh server` on a managed node (JobCommandBuilder::build_install_node,
# "Pre-stage user1 for managed access"). Doing it here means a hand-run install
# gets the same protection instead of relying on the operator knowing the trap.
derive_ssh_access() {
    SSH_ROOT_LOGIN_SAFE=0
    SSH_REACHABLE_ACCOUNT=""

    if [ -s /root/.ssh/authorized_keys ]; then
        print_info "Root has authorized SSH keys — mirroring them to user1"

        id user1 >/dev/null 2>&1 || useradd -m -s /bin/bash user1
        install -d -m 700 -o user1 -g user1 /home/user1/.ssh
        touch /home/user1/.ssh/authorized_keys
        cat /root/.ssh/authorized_keys >> /home/user1/.ssh/authorized_keys
        sort -u /home/user1/.ssh/authorized_keys -o /home/user1/.ssh/authorized_keys
        chmod 600 /home/user1/.ssh/authorized_keys
        chown user1:user1 /home/user1/.ssh/authorized_keys

        echo 'user1 ALL=(ALL:ALL) NOPASSWD: ALL' > /etc/sudoers.d/user1
        chmod 440 /etc/sudoers.d/user1

        SSH_ROOT_LOGIN_SAFE=1
        SSH_REACHABLE_ACCOUNT="user1"
        print_success "user1 holds root's SSH key(s) and has passwordless sudo"
        return 0
    fi

    if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
        SSH_ROOT_LOGIN_SAFE=1
        SSH_REACHABLE_ACCOUNT="$SUDO_USER"
        print_info "Running under sudo as '$SUDO_USER' — that account keeps its own SSH access"
        return 0
    fi

    echo ""
    print_warning "No SSH key in /root/.ssh/authorized_keys, and this run is not from a sudo account."
    print_warning "Root password login is the only way into this server, so it is being left enabled."
    echo ""
    echo "To finish hardening, add your public key and re-run the hardening step:"
    echo "  ssh-copy-id root@<this-server>            # from your own machine"
    echo "  sudo ./install.sh host-harden             # on this server"
    echo ""
    return 0
}

#==============================================================================
# SSL SETUP FUNCTIONS
#==============================================================================

# Check if domain should have SSL configured
should_setup_ssl() {
    local domain="$1"
    local no_ssl="$2"

    # Skip if --no-ssl flag was passed
    if [ "$no_ssl" = true ]; then
        return 1
    fi

    # Skip for localhost
    if [ "$domain" = "localhost" ]; then
        return 1
    fi

    # Skip for IP addresses
    if [[ "$domain" =~ ^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        return 1
    fi

    return 0
}

# An install that came up on HTTP because DNS wasn't pointing here yet leaves
# behind a timer that keeps watching for it. The deployer points DNS whenever
# they get to it — an hour later or a week later — and the certificate arrives
# without them doing anything or knowing this existed.
#
# The DNS lookup before each attempt is what makes an indefinite retry safe.
# Let's Encrypt allows five failed validations per hostname per hour, so
# hammering certbot on a domain that cannot resolve would burn the budget that
# the eventually-correct attempt needs. A failed lookup costs nothing.
#
# Units are templated on the domain so a multi-site box gets one instance per
# site rather than one timer that can only ever serve the first.
#
# $1 = domain, $2 = on-host path to setup_ssl.sh for this install mode.
install_ssl_retry_timer() {
    local domain="$1"
    local ssl_script="$2"

    if ! command -v systemctl > /dev/null 2>&1 || ! systemctl list-units > /dev/null 2>&1; then
        return 1
    fi

    mkdir -p /etc/joinery/ssl-retry
    cat > "/etc/joinery/ssl-retry/${domain}.conf" <<EOF
# Written by install.sh when a certificate was deferred. Read by
# /usr/local/sbin/joinery-ssl-retry. Removing this file stops the retries.
DOMAIN=${domain}
SETUP_SSL=${ssl_script}
EOF
    chmod 600 "/etc/joinery/ssl-retry/${domain}.conf"

    cat > /usr/local/sbin/joinery-ssl-retry <<'RETRY_EOF'
#!/usr/bin/env bash
# Issue the certificate an install could not, once DNS finally points here.
#
# Installed by install.sh. Run from joinery-ssl-retry@<domain>.timer every few
# minutes; does nothing at all until the domain resolves to this server, then
# issues once and disables its own timer.
set -u

DOMAIN="${1:-}"
[ -n "$DOMAIN" ] || exit 0

CONF="/etc/joinery/ssl-retry/${DOMAIN}.conf"
[ -f "$CONF" ] || exit 0
# shellcheck source=/dev/null
. "$CONF"

SETUP_SSL="${SETUP_SSL:-}"
if [ ! -x "$SETUP_SSL" ] && [ ! -f "$SETUP_SSL" ]; then
    echo "setup_ssl.sh is no longer at $SETUP_SSL — nothing to run"
    exit 0
fi

give_up() {
    echo "$1"
    rm -f "$CONF"
    systemctl disable "joinery-ssl-retry@${DOMAIN}.timer" > /dev/null 2>&1 || true
    systemctl stop --no-block "joinery-ssl-retry@${DOMAIN}.timer" > /dev/null 2>&1 || true
    exit 0
}

# A certificate signed by somebody else is the finish line. provision_origin_cert
# falls back to a self-signed certificate rather than failing, so "a file exists
# at the cert path" would end the retries on the first run and never issue a
# real one. Self-signed means issuer equals subject.
have_real_cert() {
    local pem="/etc/letsencrypt/live/${DOMAIN}/fullchain.pem"
    [ -f "$pem" ] || return 1
    local issuer subject
    issuer=$(openssl x509 -in "$pem" -noout -issuer 2>/dev/null | sed 's/^issuer=//')
    subject=$(openssl x509 -in "$pem" -noout -subject 2>/dev/null | sed 's/^subject=//')
    [ -n "$issuer" ] && [ "$issuer" != "$subject" ]
}

have_real_cert && give_up "A CA-issued certificate is already in place for $DOMAIN."

# The cheap check, before spending an attempt. Let's Encrypt counts failed
# validations, not failed lookups.
SERVER_IP=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || curl -s --max-time 5 icanhazip.com 2>/dev/null)
DNS_IP=$(dig +short "$DOMAIN" 2>/dev/null | grep -E '^[0-9.]+$' | head -1)

if [ -z "$DNS_IP" ]; then
    echo "$DOMAIN does not resolve yet — waiting."
    exit 0
fi
if [ -z "$SERVER_IP" ]; then
    echo "Could not determine this server's public IP — waiting."
    exit 0
fi
if [ "$DNS_IP" != "$SERVER_IP" ]; then
    echo "$DOMAIN resolves to $DNS_IP, this server is $SERVER_IP — waiting."
    exit 0
fi

echo "$DOMAIN now points here. Requesting a certificate."
bash "$SETUP_SSL" "$DOMAIN" || echo "setup_ssl.sh returned non-zero; will try again."

have_real_cert && give_up "Certificate issued for $DOMAIN. Retry timer disabled."

echo "Still no CA-issued certificate for $DOMAIN — will try again."
exit 0
RETRY_EOF
    chmod 755 /usr/local/sbin/joinery-ssl-retry

    cat > /etc/systemd/system/joinery-ssl-retry@.service <<'EOF'
[Unit]
Description=Issue a deferred Joinery SSL certificate for %i
After=network-online.target apache2.service
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/joinery-ssl-retry %i
EOF

    cat > /etc/systemd/system/joinery-ssl-retry@.timer <<'EOF'
[Unit]
Description=Retry a deferred Joinery SSL certificate for %i

[Timer]
OnBootSec=3min
OnUnitActiveSec=5min
AccuracySec=30s
Unit=joinery-ssl-retry@%i.service

[Install]
WantedBy=timers.target
EOF

    systemctl daemon-reload > /dev/null 2>&1 || true
    systemctl enable --now "joinery-ssl-retry@${domain}.timer" > /dev/null 2>&1 || return 1
    return 0
}

# Closing reminder for an install that came up on HTTP because DNS wasn't
# pointing here yet. Printed in the summary so it survives a long scrollback.
# Installs the retry timer at the same time — this is called once per install
# mode, from the summary, with the on-host path that mode uses.
# $1 = on-host path to setup_ssl.sh for this install mode.
print_ssl_deferred_notice() {
    [ "$SSL_DEFERRED" -eq 1 ] || return 0
    local ssl_script="$1"

    echo -e "${YELLOW}No SSL certificate was issued — DNS did not point here during install.${NC}"
    echo "Your site is serving HTTP."
    echo ""

    if install_ssl_retry_timer "$DOMAIN_NAME" "$ssl_script"; then
        echo "Nothing further is needed. Point $DOMAIN_NAME at this server whenever you"
        echo "are ready and a certificate will be issued within a few minutes, on its own."
        echo ""
        echo -e "To watch it: ${BLUE}journalctl -fu joinery-ssl-retry@${DOMAIN_NAME}${NC}"
        echo -e "To issue one immediately: ${BLUE}sudo $ssl_script $DOMAIN_NAME${NC}"
    else
        echo "Once $DOMAIN_NAME resolves to this server, run:"
        echo -e "  ${BLUE}sudo $ssl_script $DOMAIN_NAME${NC}"
    fi
    echo ""
}

# Check if an IP address belongs to Cloudflare
# Returns 0 if IP is Cloudflare, 1 otherwise
is_cloudflare_ip() {
    local ip="$1"

    # Try to fetch current Cloudflare IP ranges
    local cf_ranges=$(curl -s --max-time 5 https://www.cloudflare.com/ips-v4 2>/dev/null)

    # Fallback to known ranges if fetch fails (updated Jan 2025)
    if [ -z "$cf_ranges" ]; then
        cf_ranges="173.245.48.0/20
103.21.244.0/22
103.22.200.0/22
103.31.4.0/22
141.101.64.0/18
108.162.192.0/18
190.93.240.0/20
188.114.96.0/20
197.234.240.0/22
198.41.128.0/17
162.158.0.0/15
104.16.0.0/13
104.24.0.0/14
172.64.0.0/13
131.0.72.0/22"
    fi

    # Use Python to check CIDR membership (Python3 is standard on Ubuntu)
    python3 -c "
import ipaddress
import sys

ip = ipaddress.ip_address('$ip')
ranges = '''$cf_ranges'''.strip().split('\n')

for cidr in ranges:
    cidr = cidr.strip()
    if cidr and ip in ipaddress.ip_network(cidr):
        sys.exit(0)
sys.exit(1)
" 2>/dev/null
}

# Check if DNS for domain points to this server
# Returns: 0 = points here, 1 = doesn't point here, 2 = Cloudflare proxy detected
check_dns_points_here() {
    local domain="$1"

    # Get this server's public IP
    local server_ip=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || curl -s --max-time 5 icanhazip.com 2>/dev/null)
    if [ -z "$server_ip" ]; then
        print_warning "Could not determine server's public IP"
        return 1
    fi

    # Get DNS resolution for domain
    local dns_ip=$(dig +short "$domain" 2>/dev/null | grep -E '^[0-9.]+$' | head -1)
    if [ -z "$dns_ip" ]; then
        print_warning "DNS lookup failed for $domain"
        return 1
    fi

    # Compare - direct match
    if [ "$dns_ip" = "$server_ip" ]; then
        return 0
    fi

    # Check if it's Cloudflare proxy
    if is_cloudflare_ip "$dns_ip"; then
        print_info "DNS for $domain points to Cloudflare proxy ($dns_ip)"
        CLOUDFLARE_PROXY=1
        return 2  # Special return code for Cloudflare
    fi

    # Neither direct nor Cloudflare
    print_warning "DNS for $domain points to $dns_ip, but this server is $server_ip"
    return 1
}

# Set up SSL for bare-metal site.
# The bare-metal vhost template (default_virtualhost.conf v2+) already declares
# the :443 vhost pointing at /etc/letsencrypt/live/${domain}/. We just need a
# cert at that path. provision_origin_cert handles all three cases (LE HTTP-01,
# LE DNS-01, self-signed fallback) and never fails the install.
setup_ssl_baremetal() {
    local domain="$1"

    print_step "Provisioning origin SSL certificate for $domain..."

    provision_origin_cert "$domain"

    # Reload Apache so the :443 vhost picks up the new cert.
    if apache2ctl configtest > /dev/null 2>&1; then
        systemctl reload apache2 || true
    else
        print_warning "apache2ctl configtest failed — review the vhost manually"
    fi
}

#==============================================================================
# UNIVERSAL VHOST + CERT PROVISIONING
#
# Every site installed by install.sh ends up with the same Apache vhost shape:
# port 80 with a CF-Visitor-guarded HTTP->HTTPS redirect, port 443 with SSL
# pointing at a fixed cert path. Whatever cert exists at that path (LE via
# HTTP challenge, LE via DNS challenge, or self-signed fallback) makes the
# vhost load cleanly. See specs/implemented/universal_apache_vhost.md.
#==============================================================================

# Emit the universal vhost into a named conf file by sed-substituting one of
# the template files in install_tools/ (single source of truth per mode):
#
#   default_virtualhost.conf  — bare-metal sites (DocumentRoot + Directory)
#   default_proxy_vhost.conf  — Docker reverse-proxy sites (ProxyPass)
#
# Args:
#   $1 sitename       (filename base; conf is written to sites-available/${sitename}.conf)
#   $2 domain         (ServerName, cert filename)
#   $3 mode           ("baremetal" or "proxy")
#   $4 mode_argument  (baremetal: ignored (template uses {{SITE_NAME}}); proxy: localhost port)
write_universal_vhost() {
    local sitename="$1"
    local domain="$2"
    local mode="$3"
    local mode_arg="$4"

    local conf="/etc/apache2/sites-available/${sitename}.conf"
    local template

    case "$mode" in
        baremetal)
            template="${SCRIPT_DIR:-${BASH_SOURCE%/*}}/default_virtualhost.conf"
            ;;
        proxy)
            template="${SCRIPT_DIR:-${BASH_SOURCE%/*}}/default_proxy_vhost.conf"
            ;;
        *)
            print_error "write_universal_vhost: unknown mode '$mode'"
            return 1
            ;;
    esac

    if [ ! -f "$template" ]; then
        print_error "write_universal_vhost: template not found at $template"
        return 1
    fi

    # Substitute placeholders into the template.
    local server_ip
    server_ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    [ -z "$server_ip" ] && server_ip="*"

    sed -e "s|{{DOMAIN_NAME}}|${domain}|g" \
        -e "s|{{SITE_NAME}}|${sitename}|g" \
        -e "s|{{SERVER_IP}}|${server_ip}|g" \
        -e "s|{{PORT}}|${mode_arg}|g" \
        "$template" > "$conf"

    # Enable required modules for both modes; idempotent.
    a2enmod ssl headers rewrite > /dev/null 2>&1 || true
    if [ "$mode" = "proxy" ]; then
        a2enmod proxy proxy_http > /dev/null 2>&1 || true
    fi

    a2ensite "${sitename}.conf" > /dev/null 2>&1 || true
}

# Detect the DNS provider for a domain by inspecting its zone NS records.
# Echoes a short provider tag (cloudflare/route53/linode/digitalocean) or
# nothing if the provider isn't recognised.
detect_dns_provider() {
    local domain="$1"
    # Walk up to the registrable zone if needed; `dig +short NS` on a subdomain
    # often returns nothing, so try the apex two-label form as a fallback.
    local ns
    ns=$(dig +short NS "$domain" @1.1.1.1 2>/dev/null | head -1)
    if [ -z "$ns" ]; then
        local apex
        apex=$(echo "$domain" | awk -F. '{print $(NF-1)"."$NF}')
        ns=$(dig +short NS "$apex" @1.1.1.1 2>/dev/null | head -1)
    fi
    [ -z "$ns" ] && return 0

    case "$ns" in
        *.ns.cloudflare.com.|*.ns.cloudflare.com)   echo "cloudflare" ;;
        *awsdns*)                                   echo "route53" ;;
        ns[1-5].linode.com.|ns[1-5].linode.com)     echo "linode" ;;
        ns[1-3].digitalocean.com.|ns[1-3].digitalocean.com) echo "digitalocean" ;;
        *) ;;
    esac
}

# Provision an origin LE cert. Two-step decision tree:
#
#   1. Domain resolves to this server -> certbot --apache HTTP-01.
#   2. Domain resolves elsewhere -> DNS-01 via auto-detected provider plugin
#      (if credentials are present at /etc/letsencrypt/<provider>.ini).
#
# If neither path issues a cert, the function exits silently. The vhost
# template's <IfFile> guard means the :443 vhost simply doesn't activate,
# and any TLS-terminating proxy in front (e.g. Cloudflare) handles HTTPS at
# the edge. Origin SSL is opt-in: drop a credential file and re-run
# `sysadmin_tools/setup_ssl.sh <domain>` whenever you want it.
provision_origin_cert() {
    local domain="$1"

    # Ensure certbot is available (HTTP-01 path needs it; DNS-01 plugins extend it).
    if ! command -v certbot &> /dev/null; then
        print_info "Installing certbot..."
        apt-get install -y -qq certbot python3-certbot-apache
    fi

    local server_ip dns_ip
    server_ip=$(curl -s --max-time 5 ifconfig.me 2>/dev/null || curl -s --max-time 5 icanhazip.com 2>/dev/null)
    dns_ip=$(dig +short "$domain" @1.1.1.1 2>/dev/null | grep -E '^[0-9.]+$' | head -1)

    # Step 1: direct-to-origin -> HTTP-01.
    if [ -n "$server_ip" ] && [ -n "$dns_ip" ] && [ "$server_ip" = "$dns_ip" ]; then
        print_step "Domain ${domain} points at this server — using LE HTTP-01 challenge"
        if certbot --apache -d "$domain" --non-interactive --agree-tos --register-unsafely-without-email --no-redirect; then
            print_success "Issued LE certificate for ${domain} (HTTP-01)"
            return 0
        fi
        print_warning "HTTP-01 failed; trying DNS-01"
    fi

    # Step 2: DNS-01 via detected provider.
    local provider
    provider=$(detect_dns_provider "$domain")
    if [ -n "$provider" ]; then
        local plugin="certbot-dns-${provider}"
        local cred="/etc/letsencrypt/${provider}.ini"
        if [ -r "$cred" ]; then
            # Status rather than `dpkg -s`, which exits 0 for a package apt
            # removed without purging and would skip a reinstall it needs.
            if ! dpkg-query -W -f='${Status}' "python3-${plugin}" 2>/dev/null | grep -q '^install ok installed$' \
               && ! pip3 show "$plugin" >/dev/null 2>&1; then
                print_info "Installing ${plugin}..."
                apt-get install -y -qq "python3-${plugin}" 2>/dev/null \
                    || pip3 install --quiet "$plugin" 2>/dev/null \
                    || print_warning "Could not auto-install ${plugin}; skipping DNS-01"
            fi
            print_step "Domain ${domain} resolves to ${provider}; using DNS-01"
            if certbot certonly --non-interactive --agree-tos --register-unsafely-without-email \
                    "--dns-${provider}" "--dns-${provider}-credentials" "$cred" \
                    -d "$domain"; then
                print_success "Issued LE certificate for ${domain} (DNS-01 via ${provider})"
                return 0
            fi
            print_warning "DNS-01 via ${provider} failed"
        else
            print_info "No origin cert issued for ${domain}."
            print_info "  Drop credentials at ${cred} and re-run sysadmin_tools/setup_ssl.sh ${domain} to enable origin SSL via DNS-01."
        fi
    else
        print_info "No origin cert issued for ${domain} (no LE challenge path available)."
    fi
    return 0
}

# Set up Docker-container reverse-proxy vhost with SSL.
# Single code path regardless of front-end posture (CF / direct / other):
# write the universal proxy vhost (80 + 443, guard, fixed cert path),
# provision_origin_cert puts whatever cert it can at that path, reload Apache.
setup_ssl_docker_proxy() {
    local sitename="$1"
    local domain="$2"
    local port="$3"

    print_step "Setting up reverse proxy for $domain..."

    # Ensure Apache + required modules are installed/enabled.
    if ! command -v apache2 &> /dev/null; then
        print_info "Installing Apache..."
        apt-get update -qq
        apt-get install -y -qq apache2
    fi
    a2enmod proxy proxy_http ssl headers rewrite > /dev/null 2>&1 || true

    # Write the vhost in proxy mode (file: ${sitename}.conf).
    write_universal_vhost "$sitename" "$domain" "proxy" "$port"

    # Apache needs to be running with the vhost loaded before provision_origin_cert
    # attempts the HTTP-01 challenge. Reload now; the HTTPS half of the vhost will
    # fail to load (no cert yet) — that's fine, we'll reload again after the cert.
    apache2ctl configtest > /dev/null 2>&1 || true
    systemctl reload apache2 2>/dev/null || true

    # Provision the cert — never fails the install; falls back to self-signed if
    # neither HTTP-01 nor DNS-01 work.
    provision_origin_cert "$domain"

    if apache2ctl configtest > /dev/null 2>&1; then
        systemctl reload apache2 || true
        print_success "Reverse proxy + SSL configured for $domain"
    else
        print_warning "apache2ctl configtest failed — review the vhost manually"
    fi
}

#==============================================================================
# PORT MANAGEMENT FUNCTIONS (from docker_install_master.sh)
#==============================================================================

# Check if a port is in use (by system or Docker)
is_port_in_use() {
    local port=$1

    # Check system ports using ss (preferred) or netstat
    if command -v ss &> /dev/null; then
        if ss -tuln | grep -q ":${port} "; then
            return 0
        fi
    elif command -v netstat &> /dev/null; then
        if netstat -tuln | grep -q ":${port} "; then
            return 0
        fi
    fi

    # Check Docker container port mappings
    if command -v docker &> /dev/null && docker info &> /dev/null 2>&1; then
        if docker ps --format '{{.Ports}}' 2>/dev/null | grep -q "0.0.0.0:${port}->"; then
            return 0
        fi
    fi

    return 1
}

# Find next available port starting from given port
find_available_port() {
    local start_port=$1
    local port=$start_port
    local max_port=$((start_port + 100))

    while [ $port -lt $max_port ]; do
        if ! is_port_in_use $port && ! is_port_in_use $((port + 1000)); then
            echo $port
            return 0
        fi
        port=$((port + 1))
    done

    echo ""
    return 1
}

# List existing Joinery Docker containers with their ports
list_docker_containers() {
    echo ""
    echo -e "${BLUE}Docker Containers:${NC}"
    echo "───────────────────────────────────────────────────────────────"
    printf "%-20s %-15s %-12s %s\n" "SITE NAME" "WEB PORT" "DB PORT" "STATUS"
    echo "───────────────────────────────────────────────────────────────"

    local found=0
    while IFS= read -r line; do
        if [ -n "$line" ]; then
            local name=$(echo "$line" | awk '{print $1}')
            local ports=$(echo "$line" | awk '{print $2}')
            local status=$(echo "$line" | awk '{$1=$2=""; print $0}' | xargs)

            # Extract web port (format: 0.0.0.0:8080->80/tcp)
            local web_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->80)' | head -1)
            local db_port=$(echo "$ports" | grep -oP '(?:0\.0\.0\.0|127\.0\.0\.1):\K[0-9]+(?=->5432)' | head -1)

            if [ -n "$web_port" ]; then
                printf "%-20s %-15s %-12s %s\n" "$name" "$web_port" "${db_port:-N/A}" "$status"
                found=1
            fi
        fi
    done < <(docker ps -a --filter "ancestor=joinery-*" --format "{{.Names}} {{.Ports}} {{.Status}}" 2>/dev/null)

    # Also check by naming convention if ancestor filter didn't work
    if [ $found -eq 0 ]; then
        while IFS= read -r line; do
            if [ -n "$line" ]; then
                local name=$(echo "$line" | awk '{print $1}')
                local image=$(echo "$line" | awk '{print $2}')
                local ports=$(echo "$line" | awk '{print $3}')
                local status=$(echo "$line" | awk '{$1=$2=$3=""; print $0}' | xargs)

                # Check if image starts with joinery-
                if [[ "$image" == joinery-* ]]; then
                    local web_port=$(echo "$ports" | grep -oP '0\.0\.0\.0:\K[0-9]+(?=->80)' | head -1)
                    local db_port=$(echo "$ports" | grep -oP '(?:0\.0\.0\.0|127\.0\.0\.1):\K[0-9]+(?=->5432)' | head -1)

                    printf "%-20s %-15s %-12s %s\n" "$name" "${web_port:-N/A}" "${db_port:-N/A}" "$status"
                    found=1
                fi
            fi
        done < <(docker ps -a --format "{{.Names}} {{.Image}} {{.Ports}} {{.Status}}" 2>/dev/null)
    fi

    if [ $found -eq 0 ]; then
        echo "  (no existing Joinery containers found)"
    fi
    echo "───────────────────────────────────────────────────────────────"
    echo ""
}

# List existing Joinery bare-metal sites
list_baremetal_sites() {
    echo ""
    echo -e "${BLUE}Bare-Metal Sites:${NC}"
    echo "───────────────────────────────────────────────────────────────"
    printf "%-30s %-20s %s\n" "SITE NAME" "DOMAIN" "STATUS"
    echo "───────────────────────────────────────────────────────────────"

    local found=0
    for site_dir in /var/www/html/*/; do
        # Skip if no directories found
        [ -d "$site_dir" ] || continue

        local site_name=$(basename "$site_dir")

        # Skip test sites and common non-site directories
        [[ "$site_name" == *_test ]] && continue
        [[ "$site_name" == "html" ]] && continue

        # Check if it looks like a Joinery site (has public_html and config)
        if [ -d "${site_dir}public_html" ] && [ -d "${site_dir}config" ]; then
            # Try to extract domain from config
            local domain="N/A"
            local config_file="${site_dir}config/Globalvars_site.php"
            if [ -f "$config_file" ]; then
                domain=$(grep -oP "site_url.*?'https?://\K[^'/]+" "$config_file" 2>/dev/null | head -1 || echo "N/A")
            fi

            # Check if Apache virtualhost is enabled
            local status="configured"
            if [ -f "/etc/apache2/sites-enabled/${site_name}.conf" ]; then
                status="active"
            elif [ -f "/etc/apache2/sites-available/${site_name}.conf" ]; then
                status="disabled"
            fi

            printf "%-30s %-20s %s\n" "$site_name" "$domain" "$status"
            found=1
        fi
    done

    if [ $found -eq 0 ]; then
        echo "  (no existing Joinery sites found)"
    fi
    echo "───────────────────────────────────────────────────────────────"
    echo ""
}

#==============================================================================
# CODE DEPLOYMENT (for bare-metal installs)
#==============================================================================

# Deploy application code from archive to site directory
deploy_application_code() {
    local site_name="$1"
    local archive_root="$2"
    local site_root="/var/www/html/$site_name"

    print_step "Deploying application code..."

    # Create site directory
    mkdir -p "$site_root"

    # Copy public_html (excluding runtime directories)
    if [ -d "$archive_root/public_html" ]; then
        print_info "Copying public_html..."
        rsync -av --exclude='.git' \
                  --exclude='uploads' \
                  --exclude='cache' \
                  --exclude='logs' \
                  --exclude='.playwright-mcp' \
                  "$archive_root/public_html/" \
                  "$site_root/public_html/" > /dev/null
    else
        print_error "public_html directory not found in archive"
        return 1
    fi

    # Copy maintenance_scripts
    if [ -d "$archive_root/maintenance_scripts" ]; then
        print_info "Copying maintenance_scripts..."
        rsync -av "$archive_root/maintenance_scripts/" \
                  "$site_root/maintenance_scripts/" > /dev/null
    fi

    # Copy config templates if they exist
    if [ -d "$archive_root/config" ]; then
        print_info "Copying config templates..."
        mkdir -p "$site_root/config"
    fi

    print_success "Application code deployed to $site_root"
}

# Install every PHP extension the deployed source declares. The work lives in
# _install_declared_dependencies.sh so the container CMD can run the same code
# at every start — in Docker these are apt packages in the writable layer, which
# a rebuild destroys.
# Usage: install_declared_dependencies /var/www/html/SITENAME/public_html
install_declared_dependencies() {
    local public_html="$1"

    print_step "Installing declared PHP extensions..."
    bash "$SCRIPT_DIR/_install_declared_dependencies.sh" "$public_html" || true
}

#==============================================================================
# THEME/PLUGIN DOWNLOAD FUNCTIONS
#==============================================================================

# Where this install fetches its code from, and — because _site_init.sh seeds
# upgrade_source from it — where the finished site will fetch every upgrade
# after. getjoinery.com serves stable releases; it is running the code it
# serves, because a release reaches it by being published there.
#
# Override with --upgrade-server to install from somewhere else, which is what
# we do internally to install from dev. The override is the only distinction
# between our sites and a stranger's, and it is enough: whatever an install
# came from is what it upgrades from.
UPGRADE_SERVER="${UPGRADE_SERVER:-https://getjoinery.com}"

# Download themes and plugins from distribution server
# Usage: download_themes_and_plugins TARGET_DIR [THEMES_LIST]
#   If THEMES_LIST is empty, downloads all system themes/plugins
download_themes_and_plugins() {
    local target_dir="$1"
    local themes_list="$2"

    print_step "Downloading themes and plugins from $UPGRADE_SERVER..."

    # Ensure target directories exist
    mkdir -p "$target_dir/theme"
    mkdir -p "$target_dir/plugins"

    if [[ -n "$themes_list" ]]; then
        # Download specific themes (comma-separated)
        IFS=',' read -ra THEME_ARRAY <<< "$themes_list"
        for theme in "${THEME_ARRAY[@]}"; do
            theme=$(echo "$theme" | xargs)  # Trim whitespace
            download_single_item "$theme" "$target_dir"
        done
    else
        # No themes specified - download all system themes and plugins
        print_info "No --themes specified, downloading system themes and plugins..."

        # Get system themes from server
        local themes_json=$(curl -sf "${UPGRADE_SERVER}/utils/publish_theme?list=themes" 2>/dev/null)
        if [[ -n "$themes_json" ]]; then
            # Parse JSON and download each system theme
            local system_themes=$(echo "$themes_json" | grep -oP '"name"\s*:\s*"\K[^"]+' | while read theme_name; do
                # Check if this theme is a system theme
                if echo "$themes_json" | grep -A5 "\"name\".*\"$theme_name\"" | grep -q '"is_system"\s*:\s*true'; then
                    echo "$theme_name"
                fi
            done)

            for theme in $system_themes; do
                download_single_item "$theme" "$target_dir" "theme"
            done
        else
            print_warning "Could not fetch theme list from server"
        fi

        # Get system plugins from server
        local plugins_json=$(curl -sf "${UPGRADE_SERVER}/utils/publish_theme?list=plugins" 2>/dev/null)
        if [[ -n "$plugins_json" ]]; then
            # Parse JSON and download each system plugin
            local system_plugins=$(echo "$plugins_json" | grep -oP '"name"\s*:\s*"\K[^"]+' | while read plugin_name; do
                # Check if this plugin is a system plugin
                if echo "$plugins_json" | grep -A5 "\"name\".*\"$plugin_name\"" | grep -q '"is_system"\s*:\s*true'; then
                    echo "$plugin_name"
                fi
            done)

            for plugin in $system_plugins; do
                download_single_item "$plugin" "$target_dir" "plugin"
            done
        else
            print_warning "Could not fetch plugin list from server"
        fi
    fi

    print_success "Theme and plugin download complete"
}

# Download fresh core application files from distribution server and overlay
# them onto TARGET_DIR (the public_html directory in the build context).
# Theme/ and plugins/ subdirectories are excluded so freshly-downloaded
# themes and plugins are never overwritten by the empty stubs in the core archive.
# Gracefully warns and continues if the server is unreachable or download fails.
# Usage: download_core_archive TARGET_DIR
download_core_archive() {
    local target_dir="$1"

    print_step "Downloading fresh core archive from $UPGRADE_SERVER..."

    # Fetch upgrade metadata
    local upgrade_info
    upgrade_info=$(curl -sf --max-time 30 "${UPGRADE_SERVER}/utils/upgrade?serve-upgrade=1" 2>/dev/null)
    if [[ -z "$upgrade_info" ]]; then
        print_warning "Could not reach $UPGRADE_SERVER — building with archive copy as-is"
        return 0
    fi

    # Extract core_location from JSON response and unescape \/ -> /
    local core_location
    core_location=$(echo "$upgrade_info" | grep -oP '"core_location"\s*:\s*"\K[^"]+' | sed 's/\\\//\//g')
    if [[ -z "$core_location" ]]; then
        print_warning "Upgrade server response missing core_location — building with archive copy as-is"
        return 0
    fi

    print_info "Core archive: $(basename "$core_location")"

    local tmp_archive tmp_extract
    tmp_archive=$(mktemp /tmp/joinery-core-XXXXXX.tar.gz)
    tmp_extract=$(mktemp -d /tmp/joinery-core-extract-XXXXXX)

    # Download
    if ! curl -sf --max-time 300 -o "$tmp_archive" "$core_location"; then
        print_warning "Failed to download core archive — building with archive copy as-is"
        rm -f "$tmp_archive"; rm -rf "$tmp_extract"
        return 0
    fi

    # Extract
    if ! tar -xzf "$tmp_archive" -C "$tmp_extract" 2>/dev/null; then
        print_warning "Failed to extract core archive — building with archive copy as-is"
        rm -f "$tmp_archive"; rm -rf "$tmp_extract"
        return 0
    fi

    # Overlay core files, skipping theme/ and plugins/ (already downloaded fresh)
    # .claude is a development artifact (Claude Code config) that must never ship to containers.
    if [[ -d "$tmp_extract/public_html" ]]; then
        rsync -a --exclude='theme/' --exclude='plugins/' --exclude='.claude' "$tmp_extract/public_html/" "$target_dir/"
        local rsync_rc=$?
        if [ "$rsync_rc" -eq 0 ]; then
            print_success "Core archive applied: $(basename "$core_location")"
        else
            print_warning "Core archive rsync failed (exit ${rsync_rc}) — container may be missing fresh core files"
        fi
    else
        print_warning "Core archive has unexpected structure — building with archive copy as-is"
    fi

    rm -f "$tmp_archive"; rm -rf "$tmp_extract"
}

# Download a single theme or plugin
# Usage: download_single_item NAME TARGET_DIR [TYPE]
#   TYPE is "theme" or "plugin" (defaults to trying both)
download_single_item() {
    local item_name="$1"
    local target_dir="$2"
    local item_type="${3:-auto}"

    if [[ "$item_type" == "theme" ]]; then
        print_info "Downloading theme: $item_name"
        if curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${item_name}" | tar xz -C "$target_dir/theme" 2>/dev/null; then
            if [[ -d "$target_dir/theme/$item_name" ]]; then
                print_success "Downloaded theme: $item_name"
                return 0
            fi
        fi
        print_error "Failed to download theme: $item_name"
        return 1
    elif [[ "$item_type" == "plugin" ]]; then
        print_info "Downloading plugin: $item_name"
        if curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${item_name}&type=plugin" | tar xz -C "$target_dir/plugins" 2>/dev/null; then
            if [[ -d "$target_dir/plugins/$item_name" ]]; then
                print_success "Downloaded plugin: $item_name"
                return 0
            fi
        fi
        print_error "Failed to download plugin: $item_name"
        return 1
    else
        # Auto-detect: try theme first, then plugin
        print_info "Downloading: $item_name"

        # Try as theme first
        if curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${item_name}" | tar xz -C "$target_dir/theme" 2>/dev/null; then
            if [[ -d "$target_dir/theme/$item_name" ]]; then
                print_success "Downloaded theme: $item_name"
                return 0
            fi
        fi

        # Try as plugin
        if curl -sfL "${UPGRADE_SERVER}/utils/publish_theme?download=${item_name}&type=plugin" | tar xz -C "$target_dir/plugins" 2>/dev/null; then
            if [[ -d "$target_dir/plugins/$item_name" ]]; then
                print_success "Downloaded plugin: $item_name"
                return 0
            fi
        fi

        print_error "Failed to download: $item_name (not found as theme or plugin)"
        return 1
    fi
}

# Create a test site (copy from main site)
create_test_site() {
    local main_site="$1"
    local password="$2"
    local domain="$3"

    local test_site="${main_site}_test"
    local test_domain="test.${domain}"

    print_step "Creating test site: $test_site"

    # Deploy code (copy from main site to save time)
    local site_root="/var/www/html/$test_site"
    mkdir -p "$site_root"

    rsync -av --exclude='uploads/*' \
              --exclude='storage/*' \
              --exclude='cache/*' \
              --exclude='logs/*' \
              "/var/www/html/$main_site/public_html/" \
              "$site_root/public_html/" > /dev/null

    if [ -d "/var/www/html/$main_site/maintenance_scripts" ]; then
        rsync -av "/var/www/html/$main_site/maintenance_scripts/" \
                  "$site_root/maintenance_scripts/" > /dev/null
    fi

    # Run initialization (creates separate database). Password by environment,
    # not argv — ps is readable by every account on the box.
    JOINERY_DB_PASSWORD="$password" "$SCRIPT_DIR/_site_init.sh" "$test_site" "" "$test_domain"

    print_success "Test site created: $test_site"
}

#==============================================================================
# ENVIRONMENT DETECTION (from server_setup.sh)
#==============================================================================

# Detect if running in Docker (including during docker build)
is_docker() {
    # Check for running container
    [ -f /.dockerenv ] && return 0

    # Check cgroup for running container
    grep -q docker /proc/1/cgroup 2>/dev/null && return 0

    # Check for Docker build environment (no systemd running)
    [ ! -d /run/systemd/system ] && return 0

    return 1
}

# Check if Docker is installed and running
is_docker_available() {
    command -v docker &> /dev/null && docker info &> /dev/null 2>&1
}

# Check if bare-metal prerequisites are met
check_bare_metal_ready() {
    local missing=()

    command -v apache2 &> /dev/null || missing+=("Apache")
    command -v psql &> /dev/null || missing+=("PostgreSQL")
    command -v php &> /dev/null || missing+=("PHP")

    if [ ${#missing[@]} -gt 0 ]; then
        print_error "Missing prerequisites: ${missing[*]}"
        print_info "Run './install.sh server' first to set up the base server"
        return 1
    fi
    return 0
}

#==============================================================================
# SERVICE MANAGEMENT (from server_setup.sh)
#==============================================================================

# Prevent services from auto-starting during package installation (Docker)
prevent_service_start() {
    printf '#!/bin/sh\nexit 101' > /usr/sbin/policy-rc.d
    chmod +x /usr/sbin/policy-rc.d
}

# Allow services to auto-start again
allow_service_start() {
    rm -f /usr/sbin/policy-rc.d
}

# Service management that works in both Docker and traditional environments
service_start() {
    local service_name="$1"
    if is_docker; then
        service "$service_name" start || true
    else
        systemctl start "$service_name"
        systemctl enable "$service_name"
    fi
}

service_stop() {
    local service_name="$1"
    if is_docker; then
        service "$service_name" stop || true
    else
        systemctl stop "$service_name"
    fi
}

service_restart() {
    local service_name="$1"
    if is_docker; then
        service "$service_name" restart || true
    else
        systemctl restart "$service_name"
    fi
}

service_reload() {
    local service_name="$1"
    if is_docker; then
        service "$service_name" reload || true
    else
        systemctl reload "$service_name"
    fi
}

#==============================================================================
# SUBCOMMAND: docker - Install Docker on the server
#==============================================================================

do_docker_install() {
    print_header "Docker Installation"

    # Check if running as root
    if [ "$EUID" -ne 0 ]; then
        print_error "This command must be run as root (use sudo)"
        exit 1
    fi

    print_step "Checking Docker installation..."

    if command -v docker &> /dev/null; then
        DOCKER_VERSION=$(docker --version)
        print_success "Docker is already installed: $DOCKER_VERSION"

        # Verify Docker is running
        if ! docker info &> /dev/null; then
            print_warning "Docker daemon is not running. Starting Docker..."
            systemctl start docker
            sleep 2
            if ! docker info &> /dev/null; then
                print_error "Failed to start Docker daemon"
                exit 1
            fi
            print_success "Docker daemon started"
        else
            print_success "Docker daemon is running"
        fi
        exit 0
    fi

    print_info "Docker is not installed"

    if [ "$ASSUME_YES" -eq 1 ]; then
        print_info "Auto-accepting Docker installation (-y flag)"
    else
        echo ""
        read -p "Would you like to install Docker now? [Y/n] " -n 1 -r
        echo ""

        if [[ $REPLY =~ ^[Nn]$ ]]; then
            print_info "Docker installation cancelled"
            exit 0
        fi
    fi

    print_step "Installing Docker..."

    # Update packages
    apt-get update

    # Install prerequisites
    apt-get install -y ca-certificates curl gnupg lsb-release

    # Add Docker's GPG key
    mkdir -m 0755 -p /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg

    # Add Docker repository
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null

    # Install Docker
    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io

    # Verify installation
    if command -v docker &> /dev/null; then
        print_success "Docker installed successfully"
        DOCKER_VERSION=$(docker --version)
        print_info "$DOCKER_VERSION"

        # Start Docker
        systemctl start docker
        systemctl enable docker

        if docker info &> /dev/null; then
            print_success "Docker daemon is running"
        else
            print_warning "Docker installed but daemon is not running"
        fi
    else
        print_error "Docker installation failed"
        exit 1
    fi

    # Block external access to Docker-exposed Postgres ports (9080-9099).
    # Our convention publishes container Postgres on host ports 908X (5432 in-container).
    # UFW can't do this — Docker's DNAT rewrites the dst port before the filter INPUT
    # chain runs, so ufw deny on 9080-9099 is silently ignored. DOCKER-USER is Docker's
    # official admin-rules hook (runs in FORWARD). --ctorigdstport matches the original
    # pre-NAT port recorded in conntrack. Loopback traffic bypasses eth0 and this rule,
    # so `ssh -L 908X:localhost:908X` tunnels still work.
    # Toggle off: iptables -D DOCKER-USER -i <iface> -p tcp -m conntrack --ctorigdstport 9080:9099 -j DROP && netfilter-persistent save
    print_step "Blocking external access to Docker Postgres ports 9080-9099..."
    apt-get install -y iptables-persistent
    PUBLIC_IFACE=$(ip route | awk '/^default/ {print $5; exit}')
    if [ -z "$PUBLIC_IFACE" ]; then
        print_warning "Could not detect public interface — skipping DOCKER-USER rule"
    else
        iptables -I DOCKER-USER -i "$PUBLIC_IFACE" -p tcp -m conntrack --ctorigdstport 9080:9099 -j DROP
        netfilter-persistent save
        print_success "Postgres ports 9080-9099 blocked on $PUBLIC_IFACE (tunnels still work)"
    fi

    if [ "$QUIET_MODE" -eq 1 ]; then
        echo -e "${GREEN}Docker installation complete!${NC}"
    else
        print_success "Docker installation complete!"
    fi
}

#==============================================================================
# SUBCOMMAND: host-harden - Harden a Docker host server after initial provisioning
#==============================================================================

do_host_harden() {
    print_header "Docker Host Security Hardening"

    if [ "$EUID" -ne 0 ]; then
        print_error "This command must be run as root (use sudo)"
        exit 1
    fi

    # Safety check: require at least one authorized SSH key before disabling password auth
    local AUTH_KEYS="${HOME}/.ssh/authorized_keys"
    if [ ! -f "$AUTH_KEYS" ] || [ ! -s "$AUTH_KEYS" ]; then
        print_error "No SSH authorized_keys found at $AUTH_KEYS"
        print_error "Add your SSH public key before running host-harden to avoid being locked out"
        exit 1
    fi
    local KEY_COUNT
    KEY_COUNT=$(grep -c 'ssh-' "$AUTH_KEYS" 2>/dev/null || echo 0)
    print_info "Found $KEY_COUNT SSH key(s) in authorized_keys — safe to disable password auth"

    if [ "$ASSUME_YES" -ne 1 ]; then
        echo ""
        print_warning "This will disable SSH password authentication on this server."
        print_warning "You will only be able to log in with the key(s) listed above."
        read -p "Proceed? [y/N] " -n 1 -r
        echo ""
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            print_info "Aborted"
            exit 0
        fi
    fi

    # --- SSH hardening ---
    print_step "Hardening SSH..."
    cp /etc/ssh/sshd_config /etc/ssh/sshd_config.backup
    sed -i 's/#PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
    sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
    sed -i 's/#PermitRootLogin yes/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
    sed -i 's/PermitRootLogin yes/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
    sed -i 's/#MaxAuthTries 6/MaxAuthTries 3/' /etc/ssh/sshd_config
    systemctl restart ssh
    print_success "SSH: password auth disabled, key-only login enforced"

    # --- fail2ban ---
    print_step "Installing and configuring fail2ban..."
    apt-get install -y fail2ban > /dev/null 2>&1
    cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
    tee -a /etc/fail2ban/jail.local > /dev/null << 'EOF'

# Joinery host hardening
[sshd]
enabled = true
bantime = 1h
findtime = 10m
maxretry = 3
EOF
    systemctl enable fail2ban
    systemctl start fail2ban
    print_success "fail2ban: installed and running, SSH jail active (ban after 3 failures in 10m)"

    # --- journald size limit ---
    print_step "Capping systemd journal size..."
    mkdir -p /etc/systemd/journald.conf.d
    tee /etc/systemd/journald.conf.d/size-limit.conf > /dev/null << 'EOF'
[Journal]
SystemMaxUse=100M
EOF
    systemctl restart systemd-journald
    print_success "journald: capped at 200M, 2-week retention"

    # --- Docker BuildKit GC policy ---
    if command -v docker &> /dev/null; then
        print_step "Configuring Docker BuildKit GC policy..."
        local DAEMON_JSON="/etc/docker/daemon.json"
        if [ -f "$DAEMON_JSON" ] && grep -q '"builder"' "$DAEMON_JSON"; then
            print_info "daemon.json already has builder config — skipping"
        else
            if [ -f "$DAEMON_JSON" ]; then
                # Merge into existing daemon.json — insert before closing brace
                sed -i 's/}$/,"builder":{"gc":{"enabled":true,"defaultKeepStorage":"2GB"}}}/' "$DAEMON_JSON"
            else
                tee "$DAEMON_JSON" > /dev/null << 'EOF'
{
  "builder": {
    "gc": {
      "enabled": true,
      "defaultKeepStorage": "0"
    }
  }
}
EOF
            fi
            systemctl reload docker 2>/dev/null || true
            print_success "Docker BuildKit GC: auto-prune to 2GB"
        fi
    else
        print_info "Docker not installed — skipping BuildKit GC config"
    fi

    # --- Orphaned build dir scan ---
    print_step "Scanning for orphaned build directories..."
    local ORPHANS
    ORPHANS=$(find ~ -maxdepth 1 -type d -name 'joinery-docker-build-*' 2>/dev/null)
    if [ -n "$ORPHANS" ]; then
        print_warning "Orphaned build directories found:"
        echo "$ORPHANS"
        if [ "$ASSUME_YES" -ne 1 ]; then
            read -p "Delete them? [y/N] " -n 1 -r
            echo ""
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                echo "$ORPHANS" | xargs rm -rf
                print_success "Orphaned build directories removed"
            fi
        else
            echo "$ORPHANS" | xargs rm -rf
            print_success "Orphaned build directories removed"
        fi
    else
        print_success "No orphaned build directories found"
    fi

    # --- Swap ---
    print_step "Configuring swap..."
    local SWAP_SIZE="2G"
    local SWAPFILE="/swapfile"
    if swapon --show | grep -q "$SWAPFILE"; then
        print_info "Swapfile already active at $SWAPFILE — skipping"
    else
        swapoff -a 2>/dev/null || true
        fallocate -l "$SWAP_SIZE" "$SWAPFILE"
        chmod 600 "$SWAPFILE"
        mkswap "$SWAPFILE"
        swapon "$SWAPFILE"
        # Replace any existing swap entries with the swapfile
        sed -i '/[[:space:]]swap[[:space:]]/d' /etc/fstab
        echo "$SWAPFILE none swap sw 0 0" >> /etc/fstab
        print_success "Swap: ${SWAP_SIZE} swapfile created and active"
    fi

    # --- Truncate btmp ---
    print_step "Truncating failed-login logs..."
    truncate -s 0 /var/log/btmp 2>/dev/null || true
    truncate -s 0 /var/log/btmp.1 2>/dev/null || true
    print_success "btmp logs cleared"

    print_header "Host Hardening Complete!"
    echo -e "${GREEN}✓${NC} SSH password authentication disabled"
    echo -e "${GREEN}✓${NC} fail2ban active (SSH jail)"
    echo -e "${GREEN}✓${NC} journald capped at 200M"
    echo -e "${GREEN}✓${NC} Docker BuildKit GC configured"
    echo -e "${GREEN}✓${NC} Swap: 2G swapfile"
    echo -e "${GREEN}✓${NC} btmp logs cleared"
    echo ""
}

#==============================================================================
# SUBCOMMAND: build-base - Build the shared joinery-base image
#==============================================================================

# Hash the do_server_setup function body so we can label the base image and
# detect drift later. Whole-file hash produces too many false positives when
# unrelated functions change. The range end is `^}$` (bare closing brace on
# its own line), not `^}`, because do_server_setup contains heredocs with
# `};` lines that would otherwise truncate the range.
compute_install_sh_hash() {
    awk '/^do_server_setup\(\) \{/,/^\}$/' "$SCRIPT_DIR/install.sh" \
        | sha256sum | cut -c1-16
}

do_build_base() {
    print_header "Building Joinery Base Image"

    if ! command -v docker >/dev/null 2>&1; then
        print_error "Docker is not installed. Run './install.sh docker' first."
        exit 1
    fi

    if [ ! -f "$SCRIPT_DIR/Dockerfile.base" ]; then
        print_error "Dockerfile.base not found in $SCRIPT_DIR"
        exit 1
    fi

    # Build context: just install.sh at the context root (matches
    # Dockerfile.base's `COPY install.sh /tmp/install.sh`).
    BUILD_DIR=$(mktemp -d)
    mkdir -p "$BUILD_DIR/install_tools"
    cp "$SCRIPT_DIR/install.sh" "$BUILD_DIR/install_tools/install.sh"
    cp "$SCRIPT_DIR/Dockerfile.base" "$BUILD_DIR/install_tools/Dockerfile.base"

    INSTALL_SH_HASH=$(compute_install_sh_hash)

    print_step "Building joinery-base:${BASE_IMAGE_VERSION} (takes 5-10 minutes)..."
    print_info "install.sh hash: ${INSTALL_SH_HASH}"

    local BUILD_STATUS=0
    if [ "$QUIET_MODE" -eq 1 ]; then
        docker build -q \
            -f "$BUILD_DIR/install_tools/Dockerfile.base" \
            --build-arg "INSTALL_SH_HASH=${INSTALL_SH_HASH}" \
            -t "joinery-base:${BASE_IMAGE_VERSION}" \
            -t "joinery-base:latest" \
            "$BUILD_DIR/install_tools" > /dev/null || BUILD_STATUS=$?
    else
        docker build \
            -f "$BUILD_DIR/install_tools/Dockerfile.base" \
            --build-arg "INSTALL_SH_HASH=${INSTALL_SH_HASH}" \
            -t "joinery-base:${BASE_IMAGE_VERSION}" \
            -t "joinery-base:latest" \
            "$BUILD_DIR/install_tools" || BUILD_STATUS=$?
    fi

    rm -rf "$BUILD_DIR"

    if [ "$BUILD_STATUS" -eq 0 ]; then
        print_success "joinery-base:${BASE_IMAGE_VERSION} built successfully"
        print_info "install.sh hash: ${INSTALL_SH_HASH}"
        print_info "Run './install.sh site SITENAME ...' to create a site using this base"
    else
        print_error "Base image build failed"
        exit 1
    fi
}

# The PHP version this box runs, as MAJOR.MINOR. Every package name, service
# name, Apache conf name and ini path in the server setup is built from it, so
# it has to be decided once, before any of them are used.
#
# Resolution order is deliberate. A box that already has PHP keeps that version:
# configuring a different one installs a second PHP alongside the first, and the
# two halves of the setup then disagree - Apache proxying to one fpm socket
# while the ini tuning lands on the other. Only a box with no PHP asks apt, and
# it asks the question apt itself would answer for `apt install php-cli`, so the
# version installed and the version configured cannot diverge.
#
# Prints nothing when it cannot tell. Callers must treat that as a stop: every
# path built from an empty version silently becomes /etc/php//fpm/php.ini.
detect_php_version() {
    local ver=""

    if command -v php > /dev/null 2>&1; then
        ver=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null)
    fi

    if [ -z "$ver" ]; then
        ver=$(apt-cache depends php-cli 2>/dev/null \
              | grep -oE 'php[0-9]+\.[0-9]+-cli' \
              | head -1 \
              | grep -oE '[0-9]+\.[0-9]+')
    fi

    echo "$ver"
}

#==============================================================================
# SUBCOMMAND: server - Set up bare-metal server (integrated from server_setup.sh)
#==============================================================================

do_server_setup() {
    print_header "Bare-Metal Server Setup"

    # Parse do_server_setup-specific flags.
    # --skip-postgres-password is used when building the shared joinery-base
    # image: the base image must not bake any postgres credential (real or
    # placeholder). Per-site postgres passwords are set by _site_init.sh at
    # first container run.
    local SKIP_POSTGRES_PASSWORD=0
    local ALLOW_UNSUPPORTED_OS=0
    for arg in "$@"; do
        case "$arg" in
            --skip-postgres-password) SKIP_POSTGRES_PASSWORD=1 ;;
            --allow-unsupported-os) ALLOW_UNSUPPORTED_OS=1 ;;
        esac
    done

    # Check if running as root
    if [ "$EUID" -ne 0 ]; then
        print_error "This command must be run as root (use sudo)"
        exit 1
    fi

    # The releases this is exercised on. Nothing below hardcodes a PHP version -
    # it is detected and every path derives from it - so the gate is about what
    # has been tested end to end, not about what the script can express. On an
    # untested release the likely failure is a package or service name the
    # distro arranges differently, which leaves a half-configured server that
    # looks installed and fails much later and much less clearly.
    #
    # `site` deliberately does not repeat this check. It presupposes `server`
    # ran, so this is the one place it can fire on a real path, and nothing
    # persists the override for a second command to find.
    if ! grep -qE "Ubuntu (24|26)\.04" /etc/os-release; then
        local detected
        detected=$(grep '^PRETTY_NAME=' /etc/os-release 2>/dev/null | cut -d'"' -f2)
        if [ "$ALLOW_UNSUPPORTED_OS" -eq 1 ]; then
            print_warning "Unsupported OS: ${detected:-unknown}. Proceeding because --allow-unsupported-os was given."
            print_warning "Package and service layout is only verified on Ubuntu 24.04 and 26.04. Expect to finish the setup by hand."
        else
            print_error "Unsupported OS: ${detected:-unknown}"
            echo ""
            echo "Joinery server setup targets Ubuntu 24.04 and 26.04 LTS. Package and service"
            echo "layout is not verified on any other release, so it could configure a server"
            echo "that does not work."
            echo ""
            echo "To proceed anyway and finish the setup by hand:"
            echo -e "  ${BLUE}sudo ./install.sh server --allow-unsupported-os${NC}"
            echo ""
            exit 1
        fi
    fi

    # Get PostgreSQL password (skipped when building the shared base image)
    POSTGRES_PASSWORD="${POSTGRES_PASSWORD:-}"

    if [ "$SKIP_POSTGRES_PASSWORD" -eq 0 ] && [[ -z "$POSTGRES_PASSWORD" ]]; then
        print_info "PostgreSQL password not set."
        echo -n "Please enter a password for PostgreSQL postgres user: "
        read -s POSTGRES_PASSWORD
        echo ""

        if [[ -z "$POSTGRES_PASSWORD" ]]; then
            print_error "PostgreSQL password cannot be empty."
            exit 1
        fi

        echo -n "Confirm password: "
        read -s POSTGRES_PASSWORD_CONFIRM
        echo ""

        if [[ "$POSTGRES_PASSWORD" != "$POSTGRES_PASSWORD_CONFIRM" ]]; then
            print_error "Passwords do not match. Please run the script again."
            exit 1
        fi

        print_success "PostgreSQL password set successfully."
    fi

    # Update system packages
    print_step "Updating system packages..."
    apt update && apt upgrade -y

    # Install essential packages
    print_step "Installing essential packages..."
    apt install -y curl wget git unzip rsync software-properties-common apt-transport-https ca-certificates gnupg lsb-release build-essential fail2ban cron

    # Create and configure user1
    print_step "Setting up user1..."

    if ! id "user1" &>/dev/null; then
        print_info "Creating user1..."
        useradd -m -s /bin/bash user1
        print_success "user1 created"
    fi

    # Configure user1's SSH directory
    mkdir -p /home/user1/.ssh
    chmod 700 /home/user1/.ssh
    chown user1:user1 /home/user1/.ssh
    touch /home/user1/.ssh/authorized_keys
    chmod 600 /home/user1/.ssh/authorized_keys
    chown user1:user1 /home/user1/.ssh/authorized_keys

    print_success "user1 configured successfully"

    # Prevent service auto-start during package installation (Docker safety)
    if is_docker; then
        print_info "Docker detected - preventing service auto-start during package installation..."
        prevent_service_start
    fi

    # Decided once, here, and used for every package, service, conf and ini path
    # from this point down. Must come after `apt update` above, because with no
    # PHP installed the answer comes out of the package lists that call refreshes.
    local PHP_VERSION
    PHP_VERSION="$(detect_php_version)"
    if [ -z "$PHP_VERSION" ]; then
        print_error "Could not determine which PHP version to install"
        echo ""
        echo "No php binary is present and apt offers no php-cli package, so there is"
        echo "no version to build package and path names from. Configuring anything"
        echo "further would write to /etc/php//fpm/ and appear to succeed."
        echo ""
        echo "Check that the distribution's PHP repository is enabled, then re-run."
        exit 1
    fi

    # Install PHP and extensions
    print_step "Installing PHP ${PHP_VERSION}..."
    # Suffixes, not package names: apt takes the whole list as one transaction,
    # so a name that does not resolve on this release fails the batch and takes
    # every other extension down with it.
    local PHP_EXTENSIONS=(fpm cli common pgsql xml curl gd dev mbstring opcache
                          soap zip bcmath intl readline sqlite3)
    local PHP_PACKAGES=()
    local ext
    for ext in "${PHP_EXTENSIONS[@]}"; do
        PHP_PACKAGES+=("php${PHP_VERSION}-${ext}")
    done
    apt install -y "${PHP_PACKAGES[@]}"

    print_success "PHP installation completed. Version: $(php -v | head -n1)"

    # Install Composer
    print_step "Installing Composer..."
    cd /tmp
    export COMPOSER_ALLOW_SUPERUSER=1
    curl -sS https://getcomposer.org/installer | php
    mv composer.phar /usr/local/bin/composer
    chmod +x /usr/local/bin/composer

    print_success "Composer installed. Version: $(COMPOSER_ALLOW_SUPERUSER=1 composer --version)"

    # Install Apache
    print_step "Installing Apache web server..."
    apt install -y apache2

    # Enable Apache modules
    print_step "Enabling Apache modules..."
    a2enmod rewrite
    a2enmod ssl
    a2enmod headers
    # PHP runs under php-fpm (event MPM + proxy_fcgi), never mod_php: the AI
    # chat async path needs fastcgi_finish_request(), which only fpm provides.
    # The a2dismod covers re-runs on a box that previously used mod_php.
    a2dismod "php${PHP_VERSION}" mpm_prefork > /dev/null 2>&1 || true
    a2enmod mpm_event proxy_fcgi setenvif
    a2enconf "php${PHP_VERSION}-fpm"

    # Configure Apache settings
    print_step "Configuring Apache..."
    cp /etc/apache2/apache2.conf /etc/apache2/apache2.conf.backup

    # Update Apache configuration for /var/www/ directory
    sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ {
        s/Options Indexes FollowSymLinks/Options -Indexes +FollowSymLinks/
        s/AllowOverride None/AllowOverride All/
    }' /etc/apache2/apache2.conf

    # Set global ServerName to suppress warning messages
    echo "ServerName localhost" >> /etc/apache2/apache2.conf

    # Ensure proper configuration for rewrite rules
    cat >> /etc/apache2/apache2.conf << 'EOF'

# Global settings for membership applications
<Directory /var/www/html>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
EOF

    # Right-size the event MPM for low-traffic sites. PHP work happens in the
    # fpm pool, so Apache threads only shuttle requests and static files.
    cat > /etc/apache2/mods-available/mpm_event.conf << 'EOF'
# event MPM
StartServers             2
MinSpareThreads         10
MaxSpareThreads         25
ThreadsPerChild         25
MaxRequestWorkers       50
MaxConnectionsPerChild  2000
EOF

    print_success "Apache configured"

    # Install PostgreSQL Database
    print_step "Installing PostgreSQL server..."
    apt install -y postgresql postgresql-contrib

    # Install the inbound mail stack (Postfix + opendkim). Baked into the base
    # image so it survives container rebuilds; the Inbound Email plugin's
    # install_email.sh only configures it. DEBIAN_FRONTEND keeps postfix's
    # debconf prompt from blocking a bare-metal run. See spec
    # mail_stack_container_persistence.
    print_step "Installing mail stack (Postfix, opendkim)..."
    DEBIAN_FRONTEND=noninteractive apt install -y \
        postfix \
        postfix-pgsql \
        opendkim \
        opendkim-tools

    # Start and enable PostgreSQL
    service_start postgresql

    # Configure PostgreSQL
    print_step "Configuring PostgreSQL..."

    # Get PostgreSQL version for config paths
    PG_VERSION=$(psql --version | grep -oP '\d+\.\d+' | head -1 | cut -d. -f1)
    PG_CONFIG_DIR="/etc/postgresql/${PG_VERSION}/main"

    print_info "PostgreSQL version detected: ${PG_VERSION}"

    # Backup original configuration files
    cp ${PG_CONFIG_DIR}/pg_hba.conf ${PG_CONFIG_DIR}/pg_hba.conf.backup
    cp ${PG_CONFIG_DIR}/postgresql.conf ${PG_CONFIG_DIR}/postgresql.conf.backup

    # Configure authentication in pg_hba.conf.
    #
    # Postgres is LOCAL-ONLY by default: nothing legitimately connects to it
    # from off the box — the site is co-located, and every management DB job
    # arrives over SSH (bare metal) or docker exec (containers).
    #
    # The container image build (--skip-postgres-password) is the one shape
    # that needs network listening: the docker published-port path delivers
    # connections to the container's eth0 from the host's bridge, so it
    # listens on '*' with the bridge subnet allowed in pg_hba. The exposure
    # boundary for containers is the docker -p binding, which is loopback-only.
    print_info "Configuring PostgreSQL authentication..."

    # One variable, used by the rules written below and by the restore after the
    # password is set, so the two cannot describe different methods.
    #
    # scram-sha-256 is what the roles on these boxes actually hold:
    # password_encryption has defaulted to it since PostgreSQL 14, and an md5
    # line accepts a SCRAM verifier, so md5 in this file was a word that had
    # stopped meaning anything. Naming the method the roles use costs nothing on
    # 16 and keeps working on 18, which deprecates md5 passwords.
    local PG_AUTH_METHOD="scram-sha-256"

    if [ "$SKIP_POSTGRES_PASSWORD" -eq 1 ]; then
        # Container image: allow loopback + the docker bridge subnets.
        PG_HOST_RULES="host    all             all             127.0.0.1/32            ${PG_AUTH_METHOD}
host    all             all             172.16.0.0/12           ${PG_AUTH_METHOD}"
        PG_LISTEN="*"
    else
        # Bare metal: loopback only.
        PG_HOST_RULES="host    all             all             127.0.0.1/32            ${PG_AUTH_METHOD}"
        PG_LISTEN="localhost"
    fi

    tee ${PG_CONFIG_DIR}/pg_hba.conf > /dev/null << EOF
# PostgreSQL Client Authentication Configuration File
# ===================================================

# TYPE  DATABASE        USER            ADDRESS                 METHOD

# "local" is for Unix domain socket connections only
local   all             postgres                                ${PG_AUTH_METHOD}
local   all             all                                     ${PG_AUTH_METHOD}

# IPv4 connections:
${PG_HOST_RULES}

# IPv6 local connections:
host    all             all             ::1/128                 ${PG_AUTH_METHOD}

# Allow replication connections from localhost, by a user with the
# replication privilege.
local   replication     all                                     peer
host    replication     all             127.0.0.1/32            ${PG_AUTH_METHOD}
host    replication     all             ::1/128                 ${PG_AUTH_METHOD}
EOF

    # Owner and mode stated, not inherited. This survives a restrictive umask
    # today only because the package created the file first and `tee` keeps an
    # existing file's mode — on any layout where it does not exist, tee creates
    # it as root with the caller's umask, and a pg_hba.conf the postmaster cannot
    # read stops PostgreSQL starting exactly as the drop-in below did. 640
    # postgres:postgres is what the Debian and Ubuntu packages ship: readable by
    # the server, not by the rest of the box, since it is the authentication
    # policy.
    chown postgres:postgres ${PG_CONFIG_DIR}/pg_hba.conf 2>/dev/null || true
    chmod 640 ${PG_CONFIG_DIR}/pg_hba.conf

    # Configure PostgreSQL listening
    print_info "Configuring PostgreSQL to listen on port 5432 (${PG_LISTEN})..."
    sed -i "s/#listen_addresses = 'localhost'/listen_addresses = '${PG_LISTEN}'/" ${PG_CONFIG_DIR}/postgresql.conf
    sed -i "s/#port = 5432/port = 5432/" ${PG_CONFIG_DIR}/postgresql.conf
    sed -i "s/#max_wal_size = 1GB/max_wal_size = 64MB/" ${PG_CONFIG_DIR}/postgresql.conf
    sed -i "s/max_wal_size = 1GB/max_wal_size = 64MB/" ${PG_CONFIG_DIR}/postgresql.conf
    sed -i "s/shared_buffers = 128MB/shared_buffers = 64MB/" ${PG_CONFIG_DIR}/postgresql.conf

    # Record who connects, and from where.
    #
    # Without this a break-in leaves no trace: PostgreSQL logs failed logins but
    # not successful ones, and the packaged log_line_prefix carries no client
    # address, so even the failures cannot be attributed. A box found under
    # attack can then be asked how many attempts happened, but not whether any
    # of them worked - which is the only question that matters.
    #
    # A drop-in rather than a sed on postgresql.conf: log_line_prefix ships
    # uncommented and already set, so a sed written against the commented form
    # matches nothing and reports success. conf.d is included at the end of
    # postgresql.conf, so these win, and re-running the installer rewrites one
    # small file instead of editing lines that may already have been edited.
    #
    # log_disconnections stays off deliberately - it doubles the line count for
    # a duration figure nothing here needs.
    print_info "Configuring PostgreSQL connection logging..."
    mkdir -p ${PG_CONFIG_DIR}/conf.d
    tee ${PG_CONFIG_DIR}/conf.d/10-joinery-logging.conf > /dev/null << 'EOF'
# Managed by Joinery install.sh. Overwritten on reinstall.
# Attribution for connection attempts: %h is the client address.
log_connections = on
log_disconnections = off
log_line_prefix = '%m [%p] %q%u@%d %h '
EOF

    # State the mode instead of inheriting it. This file is created here rather
    # than by the package, so `tee` gives it whatever the operator's umask says —
    # and the postmaster runs as postgres, not as the user running the installer.
    # Under a restrictive umask (0077, common on hardened images) it lands 0600
    # root:root, PostgreSQL cannot read it and REFUSES TO START, and the install
    # then dies at the next psql with a socket error naming nothing about
    # permissions. Reproduced 2026-08-06. The directory needs the same treatment:
    # unreadable conf.d is the same failure one level up, and `mkdir -p` takes
    # the umask too on any layout where the package did not ship it.
    chmod 755 ${PG_CONFIG_DIR}/conf.d
    chmod 644 ${PG_CONFIG_DIR}/conf.d/10-joinery-logging.conf

    # conf.d is included by the packaged postgresql.conf on Debian and Ubuntu,
    # but a file that is never read is worse than no file - it looks configured.
    if ! grep -qE "^[[:space:]]*include_dir[[:space:]]*=[[:space:]]*'conf\.d'" ${PG_CONFIG_DIR}/postgresql.conf; then
        echo "include_dir = 'conf.d'" >> ${PG_CONFIG_DIR}/postgresql.conf
        print_info "Added include_dir = 'conf.d' to postgresql.conf"
    fi

    # Restart PostgreSQL to apply configuration
    service_restart postgresql

    # Set PostgreSQL postgres user password automatically.
    # Skipped when --skip-postgres-password is set (shared base image build);
    # in that case the Dockerfile CMD runs the same trust/ALTER/restore dance at
    # first container run per-site.
    #
    # Both seds match the method as a field rather than as a literal line, so
    # neither depends on the exact column spacing above, and neither has to know
    # which method is in force.
    if [ "$SKIP_POSTGRES_PASSWORD" -eq 0 ]; then
        print_info "Setting PostgreSQL postgres user password..."

        # Temporarily allow trust authentication for postgres user to set password
        sed -i -E 's/^(local[[:space:]]+all[[:space:]]+postgres[[:space:]]+)[A-Za-z0-9-]+/\1trust/' ${PG_CONFIG_DIR}/pg_hba.conf

        # Reload PostgreSQL configuration
        service_reload postgresql

        # Set the postgres user password
        su -c "psql -c \"ALTER USER postgres PASSWORD '${POSTGRES_PASSWORD}';\"" postgres

        # Restore authenticated access
        sed -i -E "s/^(local[[:space:]]+all[[:space:]]+postgres[[:space:]]+)[A-Za-z0-9-]+/\1${PG_AUTH_METHOD}/" ${PG_CONFIG_DIR}/pg_hba.conf

        # The restore is the dangerous half of the dance. sed exits 0 when it
        # matches nothing, so a pattern that no longer fits the file leaves the
        # local postgres role on trust - superuser for anyone with a shell on
        # this box - and every later step still succeeds. Checked, not assumed.
        if grep -qE '^local[[:space:]]+all[[:space:]]+postgres[[:space:]]+trust' ${PG_CONFIG_DIR}/pg_hba.conf; then
            print_error "Could not restore authenticated access for the local postgres role"
            echo ""
            echo "pg_hba.conf still grants trust on the Unix socket, which makes anyone"
            echo "with a shell on this server a PostgreSQL superuser."
            echo ""
            echo "Set the method on the 'local all postgres' line in"
            echo "${PG_CONFIG_DIR}/pg_hba.conf to ${PG_AUTH_METHOD} and reload PostgreSQL"
            echo "before using this server."
            exit 1
        fi

        # Reload PostgreSQL configuration again
        service_reload postgresql

        print_success "PostgreSQL postgres user password set successfully"
    else
        print_info "Skipping postgres user password (--skip-postgres-password)"
    fi

    # Start and enable services
    print_step "Starting services..."
    service_start apache2
    service_start postgresql
    service_start "php${PHP_VERSION}-fpm"

    # Install Certbot for SSL
    print_step "Installing Certbot for SSL certificates..."
    apt install -y certbot python3-certbot-apache

    # Configure PHP for production (fpm SAPI serves all web requests)
    print_step "Configuring PHP settings..."
    local PHP_INI="/etc/php/${PHP_VERSION}/fpm/php.ini"
    if [ ! -f "$PHP_INI" ]; then
        print_error "Expected PHP configuration at ${PHP_INI}, which does not exist"
        echo ""
        echo "PHP ${PHP_VERSION} installed but did not lay its fpm ini down where this"
        echo "script expects. Every tuning below would silently write nothing."
        exit 1
    fi
    cp "$PHP_INI" "${PHP_INI}.backup"

    # Update PHP settings optimized for 1GB VPS
    sed -i 's/upload_max_filesize = .*/upload_max_filesize = 32M/' "$PHP_INI"
    sed -i 's/post_max_size = .*/post_max_size = 32M/' "$PHP_INI"
    sed -i 's/max_execution_time = .*/max_execution_time = 300/' "$PHP_INI"
    sed -i 's/memory_limit = .*/memory_limit = 128M/' "$PHP_INI"
    # UTC, matching what the CLI and a Docker site already get. Every stored
    # time in the platform is UTC and display conversion is per user, so a web
    # request and a scheduled task on the same box have to agree about what
    # date() means. Individual users still see their own timezone.
    sed -i 's/;date.timezone =/date.timezone = UTC/' "$PHP_INI"

    # Enable PDO PostgreSQL extension
    sed -i 's/^;extension=pdo_pgsql/extension=pdo_pgsql/' "$PHP_INI"
    sed -i 's/^;extension=pgsql/extension=pgsql/' "$PHP_INI"

    print_success "PHP configured"

    # Skip SSH, firewall, and security hardening in Docker
    if is_docker; then
        print_info "Docker detected - skipping SSH, firewall, and security hardening"
    else
        # Configure SSH security
        print_step "Configuring SSH security..."
        cp /etc/ssh/sshd_config /etc/ssh/sshd_config.backup

        # Work out whether anything other than root can still get in, and make
        # that true where we can, before touching PermitRootLogin.
        derive_ssh_access

        sed -i 's/#PubkeyAuthentication yes/PubkeyAuthentication yes/' /etc/ssh/sshd_config
        sed -i 's/#PermitEmptyPasswords no/PermitEmptyPasswords no/' /etc/ssh/sshd_config
        sed -i 's/PermitEmptyPasswords yes/PermitEmptyPasswords no/' /etc/ssh/sshd_config
        sed -i 's/#MaxAuthTries 6/MaxAuthTries 3/' /etc/ssh/sshd_config
        sed -i 's/#ClientAliveInterval 0/ClientAliveInterval 300/' /etc/ssh/sshd_config
        sed -i 's/#ClientAliveCountMax 3/ClientAliveCountMax 2/' /etc/ssh/sshd_config

        # The one directive that can lock the operator out. Everything above is
        # applied unconditionally; this is applied only when another account can
        # still reach the box.
        if [ "$SSH_ROOT_LOGIN_SAFE" -eq 1 ]; then
            sed -i 's/#PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
            sed -i 's/PermitRootLogin yes/PermitRootLogin no/' /etc/ssh/sshd_config
        fi

        service_restart ssh

        if [ "$SSH_ROOT_LOGIN_SAFE" -eq 1 ]; then
            print_success "SSH security configured (root login disabled; ${SSH_REACHABLE_ACCOUNT} retains access)"
        else
            print_success "SSH security configured (root login left enabled — see the warning above)"
        fi

        # To go key-only afterwards, add your public key to the reachable account
        # and run 'install.sh host-harden' — it refuses unless a key is present.

        # Configure UFW firewall
        print_step "Configuring firewall..."
        ufw --force reset
        ufw default deny incoming
        ufw default allow outgoing
        ufw allow ssh
        ufw allow http
        ufw allow https
        # Postgres stays firewalled: management jobs run psql/pg_dump ON the
        # node over SSH, so nothing connects to 5432 from outside the box.
        ufw --force enable

        # Configure fail2ban
        print_step "Configuring fail2ban..."
        service_start fail2ban

        cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local

        tee -a /etc/fail2ban/jail.local > /dev/null << 'EOF'

# Enable SSH protection
[sshd]
enabled = true

# Enable basic Apache protection
[apache-auth]
enabled = true

[apache-badbots]
enabled = true

[apache-noscript]
enabled = true

[apache-overflows]
enabled = true
EOF

        service_restart fail2ban

        print_success "fail2ban configured"

        # Install automatic security updates
        print_step "Configuring automatic security updates..."
        apt install -y unattended-upgrades apt-listchanges

        tee /etc/apt/apt.conf.d/20auto-upgrades > /dev/null << 'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
APT::Periodic::Download-Upgradeable-Packages "1";
APT::Periodic::AutocleanInterval "7";
EOF

        tee /etc/apt/apt.conf.d/50unattended-upgrades > /dev/null << 'EOF'
Unattended-Upgrade::Allowed-Origins {
    "${distro_id}:${distro_codename}-security";
    "${distro_id}ESMApps:${distro_codename}-apps-security";
    "${distro_id}ESM:${distro_codename}-infra-security";
};
Unattended-Upgrade::Package-Blacklist {
};
Unattended-Upgrade::DevRelease "false";
Unattended-Upgrade::Remove-Unused-Dependencies "true";
Unattended-Upgrade::Automatic-Reboot "false";
Unattended-Upgrade::Automatic-Reboot-Time "02:00";
EOF

        print_success "Automatic security updates configured"

        # Security hardening
        print_step "Applying security hardening..."

        echo "install dccp /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf
        echo "install sctp /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf
        echo "install rds /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf
        echo "install tipc /bin/true" | tee -a /etc/modprobe.d/blacklist-rare-network.conf

        tee /etc/sysctl.d/99-security.conf > /dev/null << 'EOF'
# IP Spoofing protection
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.rp_filter = 1

# Ignore ICMP redirects
net.ipv4.conf.all.accept_redirects = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv6.conf.default.accept_redirects = 0

# Ignore send redirects
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0

# Disable source packet routing
net.ipv4.conf.all.accept_source_route = 0
net.ipv6.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
net.ipv6.conf.default.accept_source_route = 0

# Log Martians
net.ipv4.conf.all.log_martians = 1
net.ipv4.conf.default.log_martians = 1

# Ignore ICMP ping requests
net.ipv4.icmp_echo_ignore_all = 0

# Ignore Directed pings
net.ipv4.icmp_echo_ignore_broadcasts = 1

# Disable IPv6 if not needed
net.ipv6.conf.all.disable_ipv6 = 0
net.ipv6.conf.default.disable_ipv6 = 0

# TCP SYN flood protection
net.ipv4.tcp_syncookies = 1
net.ipv4.tcp_max_syn_backlog = 2048
net.ipv4.tcp_synack_retries = 2
net.ipv4.tcp_syn_retries = 5

# Control Buffer Overflow attacks
kernel.randomize_va_space = 2
EOF

        sysctl -p /etc/sysctl.d/99-security.conf || true

        print_success "Security hardening applied"
    fi

    # Set proper permissions for web directory
    print_step "Setting up web directory permissions..."
    chown -R www-data:www-data /var/www/
    chmod -R 755 /var/www/

    # Add user1 to www-data group for web development
    usermod -aG www-data user1

    # Restart services
    print_step "Restarting services..."
    service_restart apache2
    service_restart "php${PHP_VERSION}-fpm"

    # Remove policy-rc.d if we created it (Docker cleanup)
    allow_service_start

    # Display completion message
    print_header "Server Setup Complete!"

    local OS_PRETTY
    OS_PRETTY=$(grep '^PRETTY_NAME=' /etc/os-release 2>/dev/null | cut -d'"' -f2)
    echo -e "${GREEN}✓${NC} ${OS_PRETTY:-System} updated"
    echo -e "${GREEN}✓${NC} user1 configured"
    echo -e "${GREEN}✓${NC} PHP ${PHP_VERSION} with required extensions installed"
    echo -e "${GREEN}✓${NC} Composer installed globally"
    echo -e "${GREEN}✓${NC} Apache web server configured"
    echo -e "${GREEN}✓${NC} PostgreSQL database server configured"
    echo -e "${GREEN}✓${NC} Certbot for SSL certificates installed"
    if ! is_docker; then
        echo -e "${GREEN}✓${NC} UFW firewall configured"
        echo -e "${GREEN}✓${NC} fail2ban installed and configured"
        echo -e "${GREEN}✓${NC} SSH security configured"
        echo -e "${GREEN}✓${NC} Automatic security updates enabled"
        echo -e "${GREEN}✓${NC} System security hardening applied"
    fi
    echo ""
    print_warning "=== NEXT STEPS ==="
    print_info "1. Add your SSH public key to /home/user1/.ssh/authorized_keys"
    print_info "2. Create sites using: ./install.sh site SITENAME DOMAIN"
    print_info "   (the database password is generated for you; --password-file to choose one)"
    print_info "3. PostgreSQL postgres password is: ${POSTGRES_PASSWORD}"
    echo ""
    print_success "Server is ready for site deployment!"
}

#==============================================================================
# SUBCOMMAND: site - Create a new Joinery site
#==============================================================================

do_site_create() {
    local FORCE_MODE=""
    local SITENAME=""
    local POSTGRES_PASSWORD=""
    local PASSWORD_FILE=""
    local PASSWORD_FROM_ARGV=0
    local DOMAIN_NAME=""
    local PORT=""
    local ACTIVATE_THEME=""
    local WITH_TEST_SITE=false
    local NO_SSL=false
    local THEMES=""
    local CLONE_FROM=""
    local CLONE_KEY=""
    local ADMIN_EMAIL=""

    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --docker)
                FORCE_MODE="docker"
                shift
                ;;
            --bare-metal)
                FORCE_MODE="bare-metal"
                shift
                ;;
            -y|--yes)
                ASSUME_YES=1
                shift
                ;;
            --wipe-data)
                WIPE_DATA=1
                shift
                ;;
            --allow-downgrade)
                ALLOW_DOWNGRADE=1
                shift
                ;;
            --activate)
                ACTIVATE_THEME="$2"
                shift 2
                ;;
            --with-test-site)
                WITH_TEST_SITE=true
                shift
                ;;
            --no-ssl)
                NO_SSL=true
                shift
                ;;
            --admin-email=*)
                ADMIN_EMAIL="${1#*=}"
                shift
                ;;
            --admin-email)
                ADMIN_EMAIL="$2"
                shift 2
                ;;
            --password-file=*)
                PASSWORD_FILE="${1#*=}"
                shift
                ;;
            --password-file)
                PASSWORD_FILE="$2"
                shift 2
                ;;
            --themes=*)
                THEMES="${1#*=}"
                shift
                ;;
            --themes)
                # --themes alone means "download all" (default), --themes VALUE means specific list
                if [[ -n "${2:-}" && ! "$2" =~ ^- ]]; then
                    THEMES="$2"
                    shift 2
                else
                    # No value provided - keep THEMES empty to trigger "download all" behavior
                    THEMES=""
                    shift
                fi
                ;;
            --upgrade-server=*)
                UPGRADE_SERVER="${1#*=}"
                shift
                ;;
            --upgrade-server)
                UPGRADE_SERVER="$2"
                shift 2
                ;;
            --clone-from=*)
                CLONE_FROM="${1#*=}"
                shift
                ;;
            --clone-from)
                CLONE_FROM="$2"
                shift 2
                ;;
            --clone-key=*)
                CLONE_KEY="${1#*=}"
                shift
                ;;
            --clone-key)
                CLONE_KEY="$2"
                shift 2
                ;;
            -h|--help)
                echo "Usage: $0 site [OPTIONS] SITENAME [DOMAIN] [PORT]"
                echo "       password: generated by default; --password-file=FILE or POSTGRES_PASSWORD= to choose"
                echo ""
                echo "Mode Options:"
                echo "  --docker      Force Docker mode (requires Docker installed)"
                echo "  --bare-metal  Force bare-metal mode (requires Apache/PHP/PostgreSQL)"
                echo ""
                echo "Site Options:"
                echo "  --admin-email=EMAIL    Address for the admin account (default admin@example.com)"
                echo "  --activate THEME       Set active theme after installation"
                echo "  --with-test-site       Create companion test site (bare-metal only)"
                echo "  --no-ssl               Skip automatic SSL certificate setup"
                echo ""
                echo "Clone Options:"
                echo "  --clone-from=URL       Clone database and uploads from existing site"
                echo "  --clone-key=KEY        Authentication key for clone source"
                echo ""
                echo "Automation:"
                echo "  -y / --yes     Auto-accept: remove existing container, keep volumes"
                echo "  --wipe-data    Combined with -y: also delete data volumes (fresh install)"
                echo "                 DANGER: irreversible, destroys all site data"
                echo "  --allow-downgrade  Rebuild even though this archive's code is older than"
                echo "                 what the site is running. DANGER: replaces the running code"
                echo "                 with older code, against a database already migrated forward"
                echo ""
                echo "Parameters:"
                echo "  SITENAME      Site/database name (required)"
                echo "  PASSWORD      PostgreSQL password (required, use '-' to auto-generate)"
                echo "  DOMAIN        Domain name (optional, defaults to server IP)"
                echo "  PORT          Web port (Docker only, default: 8080)"
                echo ""
                echo "SSL:"
                echo "  SSL is automatically configured when a domain is provided."
                echo "  DNS must point to this server. Use --no-ssl to skip."
                echo ""
                echo "Cloning:"
                echo "  Clone an existing site's database and uploads to create a new site."
                echo "  The source site must have clone_export_key configured in stg_settings."
                echo ""
                echo "Auto-detection:"
                echo "  - With PORT specified: Docker mode"
                echo "  - Without PORT: Bare-metal mode"
                exit 0
                ;;
            *)
                if [ -z "$SITENAME" ]; then
                    SITENAME="$1"
                elif [ -z "$POSTGRES_PASSWORD" ] && [ -z "$PASSWORD_FILE" ]; then
                    # Check if this looks like a domain or port (skip password)
                    # Domain: contains a dot (example.com, localhost.local)
                    # Port: all digits
                    if [[ "$1" =~ \. ]] || [[ "$1" =~ ^[0-9]+$ ]]; then
                        # Looks like domain or port - skip password slot
                        if [ -z "$DOMAIN_NAME" ]; then
                            DOMAIN_NAME="$1"
                        else
                            PORT="$1"
                        fi
                    else
                        POSTGRES_PASSWORD="$1"
                        # Everything in argv is readable by every user on the box
                        # via ps, for as long as the install runs. Recorded here
                        # so the warning below can name the safe alternatives.
                        PASSWORD_FROM_ARGV=1
                    fi
                elif [ -z "$DOMAIN_NAME" ]; then
                    DOMAIN_NAME="$1"
                elif [ -z "$PORT" ]; then
                    PORT="$1"
                fi
                shift
                ;;
        esac
    done

    # Validate required parameters
    if [ -z "$SITENAME" ]; then
        print_error "SITENAME is required"
        echo "Usage: $0 site [--docker|--bare-metal] SITENAME [DOMAIN] [PORT]"
        echo "       password: generated by default; --password-file=FILE or POSTGRES_PASSWORD= to choose"
        exit 1
    fi

    # Handle password: --password-file takes priority, then command line, then auto-generate
    PASSWORD_WAS_GENERATED=0

    if [ -n "$PASSWORD_FILE" ]; then
        # Read password from file
        if [ ! -f "$PASSWORD_FILE" ]; then
            print_error "Password file not found: $PASSWORD_FILE"
            exit 1
        fi
        POSTGRES_PASSWORD=$(cat "$PASSWORD_FILE" | tr -d '\n')
        if [ -z "$POSTGRES_PASSWORD" ]; then
            print_error "Password file is empty: $PASSWORD_FILE"
            exit 1
        fi
        print_info "Password read from file: $PASSWORD_FILE"
    elif [ -z "$POSTGRES_PASSWORD" ] || [ "$POSTGRES_PASSWORD" = "-" ]; then
        # Auto-generate secure password if none provided or "-" specified
        POSTGRES_PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | head -c 24)
        print_info "Auto-generated secure password: $POSTGRES_PASSWORD"
        print_warning "Save this password - you will need it for database access!"
        PASSWORD_WAS_GENERATED=1
    fi

    # A password given on the command line was readable by every account on this
    # machine, via ps, from the moment the command started — and it is in the
    # shell history of whoever ran it. Nothing here can undo that, so say so
    # while the operator is still at the keyboard and can rotate it.
    if [ "$PASSWORD_FROM_ARGV" -eq 1 ]; then
        print_warning "The database password was passed as a command-line argument."
        echo "  Anything in argv is visible to every user on this box (ps) while the"
        echo "  install runs, and it is now in your shell history. Prefer either:"
        echo "    ./install.sh site --password-file=/path/to/file SITENAME DOMAIN"
        echo "    POSTGRES_PASSWORD=... ./install.sh site SITENAME DOMAIN"
        echo "  Treat this one as exposed and rotate it if the box is shared."
    fi

    # Check if running as root
    if [ "$EUID" -ne 0 ]; then
        print_error "This command must be run as root (use sudo)"
        exit 1
    fi

    # Determine mode
    local MODE=""

    if [ "$FORCE_MODE" = "docker" ]; then
        if ! is_docker_available; then
            print_error "Docker mode requested but Docker is not installed or running"
            print_info "Run './install.sh docker' first to install Docker"
            exit 1
        fi
        MODE="docker"
    elif [ "$FORCE_MODE" = "bare-metal" ]; then
        if ! check_bare_metal_ready; then
            exit 1
        fi
        MODE="bare-metal"
    elif [ -n "$PORT" ]; then
        # PORT specified implies Docker mode
        if ! is_docker_available; then
            print_error "PORT specified but Docker is not available"
            print_info "Either remove PORT parameter for bare-metal mode, or install Docker first"
            exit 1
        fi
        MODE="docker"
    elif is_docker_available; then
        # Docker is available, use it
        MODE="docker"
        PORT="${PORT:-8080}"
    else
        # Fall back to bare-metal
        if ! check_bare_metal_ready; then
            exit 1
        fi
        MODE="bare-metal"
    fi

    print_header "Creating Joinery Site: $SITENAME"
    print_info "Mode: $MODE"
    if [ -n "$ACTIVATE_THEME" ]; then
        print_info "Theme: $ACTIVATE_THEME"
    fi
    if [ "$WITH_TEST_SITE" = true ]; then
        print_info "Test site: enabled"
    fi
    if [ "$NO_SSL" = true ]; then
        print_info "SSL: disabled"
    fi

    # Early DNS check. Informational only: "no cert yet" is handled gracefully all
    # the way down (provision_origin_cert tries HTTP-01, then DNS-01, then returns
    # without issuing; the vhost guards its :443 block with <IfFile>, so a missing
    # cert means the site serves HTTP rather than Apache refusing to start).
    if should_setup_ssl "$DOMAIN_NAME" "$NO_SSL"; then
        print_step "Checking DNS configuration for SSL..."
        # Capture return code without set -e killing the script
        local dns_result=0
        check_dns_points_here "$DOMAIN_NAME" || dns_result=$?

        if [ $dns_result -eq 0 ]; then
            # Direct DNS match - proceed with Let's Encrypt
            print_success "DNS validated - $DOMAIN_NAME points to this server"
        elif [ $dns_result -eq 2 ]; then
            # Cloudflare proxy detected - proceed without Let's Encrypt
            print_success "Cloudflare proxy detected - SSL handled by Cloudflare"
            echo ""
            print_info "Cloudflare provides SSL at the edge. For origin encryption, configure:"
            echo "  - Cloudflare SSL/TLS → Full (Strict) with Origin Certificate, or"
            echo "  - Cloudflare SSL/TLS → Full (works with self-signed or no origin cert)"
            echo ""
        else
            # DNS doesn't point here and it's not Cloudflare. The install goes
            # ahead on HTTP; the certificate is the only thing deferred.
            SSL_DEFERRED=1
            echo ""
            print_warning "DNS for $DOMAIN_NAME does not point to this server yet"
            echo ""
            echo "Installation continues. Your site will be reachable over HTTP, and no"
            echo "certificate will be issued during this run. The command to issue one"
            echo "later is printed in the summary at the end of this install."
            echo ""
        fi
    fi

    # Clone source verification
    if [ -n "$CLONE_FROM" ]; then
        print_step "Verifying clone source..."

        if [ -z "$CLONE_KEY" ]; then
            print_error "--clone-key is required when using --clone-from"
            exit 1
        fi

        MANIFEST=$(curl -sf -H "Authorization: Bearer ${CLONE_KEY}" "${CLONE_FROM}/utils/clone_export?action=manifest" 2>/dev/null)

        if [ $? -ne 0 ] || [ -z "$MANIFEST" ]; then
            print_error "Cannot connect to clone source or invalid key"
            print_info "Verify the URL and clone key are correct"
            exit 1
        fi

        # Check for error response
        if echo "$MANIFEST" | grep -q '"status".*"error"'; then
            ERROR_MSG=$(echo "$MANIFEST" | grep -oP '"message"\s*:\s*"\K[^"]+')
            print_error "Clone source error: $ERROR_MSG"
            exit 1
        fi

        # Display clone info (using grep to avoid jq dependency)
        print_info "Clone source: $CLONE_FROM"
        print_info "Database size: $(echo "$MANIFEST" | grep -oP '"database_size_mb"\s*:\s*\K[0-9]+') MB"
        print_info "Uploads size: $(echo "$MANIFEST" | grep -oP '"uploads_size_mb"\s*:\s*\K[0-9]+') MB"
        print_info "Static files size: $(echo "$MANIFEST" | grep -oP '"static_files_size_mb"\s*:\s*\K[0-9]+') MB"
        print_info "Themes: $(echo "$MANIFEST" | grep -oP '"themes"\s*:\s*\[\K[^\]]+' | tr -d '"')"

        if [ "$ASSUME_YES" -eq 0 ]; then
            echo ""
            read -p "Proceed with clone? [y/N] " confirm
            if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
                print_info "Clone cancelled"
                exit 0
            fi
        fi

        # Use clone source as upgrade server for themes/plugins
        UPGRADE_SERVER="$CLONE_FROM"
    fi

    # _site_init.sh reads both of these from the environment, the same way it
    # already reads JOINERY_ADMIN_PASSWORD. UPGRADE_SERVER is exported because
    # the finished site records it as the place its upgrades come from.
    if [ -n "$ADMIN_EMAIL" ]; then
        export JOINERY_ADMIN_EMAIL="$ADMIN_EMAIL"
    fi
    export UPGRADE_SERVER

    if [ "$MODE" = "docker" ]; then
        do_site_docker "$SITENAME" "$POSTGRES_PASSWORD" "$DOMAIN_NAME" "$PORT" "$ACTIVATE_THEME" "$NO_SSL" "$CLONE_FROM" "$CLONE_KEY"
    else
        do_site_baremetal "$SITENAME" "$POSTGRES_PASSWORD" "$DOMAIN_NAME" "$ACTIVATE_THEME" "$WITH_TEST_SITE" "$NO_SSL" "$CLONE_FROM" "$CLONE_KEY"
    fi
}

#------------------------------------------------------------------------------
# Rebuild guard: a rebuild must never move a site's code backward
#------------------------------------------------------------------------------

# The three volumes that hold a site's code, as `volume_suffix:container_path`.
# Everything an in-place upgrade writes is under one of them. They are siblings
# of the data volumes rather than one volume at the site root, so no volume is
# ever mounted inside another.
CODE_VOLUMES=(
    "code:public_html"
    "vendor:vendor"
    "scripts:maintenance_scripts"
)

# Every volume a site owns, code and data alike — the full set `--wipe-data`
# deletes. Held in one place because it was previously spelled out at each
# removal site, and a list that is written twice is a list that drifts: a volume
# added to one copy and not the other survives a wipe that promised to be total.
ALL_SITE_VOLUMES=(
    code vendor scripts
    postgres uploads storage config backups static
    logs cache sessions apache_logs pg_logs
)

# Delete every volume belonging to a site. Irreversible: this is the database,
# the uploads, the config that holds secret_box_key, and the backups. Only ever
# called behind --wipe-data.
remove_site_volumes() {
    local SITENAME="$1"
    local vol
    for vol in "${ALL_SITE_VOLUMES[@]}"; do
        docker volume rm "${SITENAME}_${vol}" 2>/dev/null || true
    done
}

# True when the site's code volume already holds a release. Read straight off
# the host filesystem rather than through a throwaway container: this runs
# before the base image has been checked for, and a version file is not worth
# starting a container to read.
#
# Anything unexpected — no volume, no mountpoint, unreadable — answers "no", so
# an unclear result sends the caller to the version comparison rather than
# skipping it.
code_volume_is_populated() {
    local SITENAME="$1"
    local MOUNTPOINT
    MOUNTPOINT=$(docker volume inspect -f '{{ .Mountpoint }}' "${SITENAME}_code" 2>/dev/null) || return 1
    [ -n "$MOUNTPOINT" ] && [ -f "${MOUNTPOINT}/VERSION" ]
}

# A site's PHP code lives in the container's writable layer, not on a volume.
# In-place upgrades (utils/upgrade.php) write code there and nowhere else, so a
# long-lived site runs code the image knows nothing about, against a database
# that migrated forward alongside it. Removing and rebuilding the container
# therefore replaces the running code with whatever the archive root holds —
# which is destructive whenever that archive is older, however loudly the
# rebuild says data volumes are preserved.
#
# So a rebuild has to prove it moves the code forward, or refuse. This runs
# before anything stops or removes the container, so refusing costs the site
# nothing.
#
# $1 SITENAME  $2 archive root
assert_rebuild_moves_code_forward() {
    local SITENAME="$1"
    local ARCHIVE_ROOT="$2"
    local DEPLOY_MODE="${3:-docker}"
    local LIVE_VERSION_PATH="/var/www/html/${SITENAME}/public_html/VERSION"
    local ARCHIVE_VERSION_FILE="${ARCHIVE_ROOT}/public_html/VERSION"
    local RUNNING_VERSION=""
    local ACTION="rebuild"

    if [ "$DEPLOY_MODE" = "docker" ]; then
        # Nothing installed under this name yet — there is no code to move backward.
        if ! docker ps -a --format '{{.Names}}' 2>/dev/null | grep -q "^${SITENAME}$"; then
            return 0
        fi

        # --wipe-data asks for a fresh site outright: the database that makes the
        # running code load-bearing is being deleted in the same breath.
        if [ "$WIPE_DATA" -eq 1 ]; then
            print_info "Skipping the code-version check (--wipe-data: this is a fresh site)"
            return 0
        fi

        # A populated code volume puts the site's code out of a rebuild's reach:
        # Docker seeds a volume from the image only when the volume is empty, so
        # there is nothing here to move backward and no version worth comparing.
        if code_volume_is_populated "$SITENAME"; then
            print_success "${SITENAME}'s code is on a volume — a rebuild cannot replace it"
            return 0
        fi

        print_step "Checking that this rebuild moves ${SITENAME}'s code forward..."

        # docker cp reads a stopped container as well as a running one, which
        # docker exec cannot — and a previous failed run may have left it stopped.
        local TMP_VERSION
        TMP_VERSION=$(mktemp)
        if docker cp "${SITENAME}:${LIVE_VERSION_PATH}" "$TMP_VERSION" > /dev/null 2>&1; then
            RUNNING_VERSION=$(tr -d '[:space:]' < "$TMP_VERSION")
        fi
        rm -f "$TMP_VERSION"
    else
        ACTION="install"

        # Bare metal has no volume to put the code out of reach: an install over
        # an existing site rsyncs the archive straight onto the live tree. There
        # is no --delete, so an older archive does not replace that tree, it
        # merges into it — files present in both roll back, files only the newer
        # release shipped stay behind, and VERSION ends up naming the older one.
        # The result is a tree no release ever shipped, describing itself as a
        # version it is not.
        #
        # A site is "there" by the same test the overwrite prompt uses, so the
        # guard and the prompt never disagree about whether one exists.
        if [ ! -d "/var/www/html/${SITENAME}" ] || \
           [ ! -f "/var/www/html/${SITENAME}/config/Globalvars_site.php" ]; then
            return 0
        fi

        print_step "Checking that this install moves ${SITENAME}'s code forward..."

        if [ -r "$LIVE_VERSION_PATH" ]; then
            RUNNING_VERSION=$(tr -d '[:space:]' < "$LIVE_VERSION_PATH")
        fi
    fi

    local ARCHIVE_VERSION=""
    if [ -r "$ARCHIVE_VERSION_FILE" ]; then
        ARCHIVE_VERSION=$(tr -d '[:space:]' < "$ARCHIVE_VERSION_FILE")
    fi

    # sort -V, never string comparison: 0.8.24 against 0.8.221 is exactly the
    # pair string comparison gets backwards, and it is the pair in the field.
    local VERDICT="forward"
    if [ -z "$RUNNING_VERSION" ] || [ -z "$ARCHIVE_VERSION" ]; then
        VERDICT="unknown"
    elif [ "$RUNNING_VERSION" != "$ARCHIVE_VERSION" ] && \
         [ "$(printf '%s\n%s\n' "$ARCHIVE_VERSION" "$RUNNING_VERSION" | sort -V | head -n1)" = "$ARCHIVE_VERSION" ]; then
        VERDICT="backward"
    fi

    if [ "$VERDICT" = "forward" ]; then
        print_success "Archive ${ARCHIVE_VERSION} is not older than the running ${RUNNING_VERSION}"
        return 0
    fi

    # An archive that cannot say what it is, is the same signal as an older one
    # with less information — so unknown refuses too.
    if [ "$VERDICT" = "unknown" ]; then
        print_warning "Cannot tell whether this ${ACTION} moves ${SITENAME}'s code forward"
    else
        print_warning "This ${ACTION} would move ${SITENAME}'s code BACKWARD"
    fi
    if [ "$DEPLOY_MODE" = "docker" ]; then
        print_warning "  running in the container:  ${RUNNING_VERSION:-unreadable at $LIVE_VERSION_PATH}"
    else
        print_warning "  installed on this server:  ${RUNNING_VERSION:-unreadable at $LIVE_VERSION_PATH}"
    fi
    print_warning "  in this archive:           ${ARCHIVE_VERSION:-unreadable at $ARCHIVE_VERSION_FILE}"
    print_warning "  archive root:              ${ARCHIVE_ROOT}"

    if [ "$ALLOW_DOWNGRADE" -eq 1 ]; then
        print_warning "Proceeding anyway (--allow-downgrade)"
        print_warning "  ${SITENAME}'s code will be replaced with the archive copy named above."
        print_warning "  Its database is not rolled back and stays where it is."
        return 0
    fi

    print_error "Refusing to ${ACTION} ${SITENAME}."
    if [ "$DEPLOY_MODE" = "docker" ]; then
        print_error "The site's code lives in the container, so a rebuild replaces it with the"
        print_error "archive copy — against a database that has already migrated forward."
    else
        print_error "The archive is copied onto the live tree without deleting anything, so an"
        print_error "older one leaves a mix: shared files rolled back, newer-release files left"
        print_error "behind, VERSION naming the older release — against a database that has"
        print_error "already migrated forward. To move a bare-metal site forward in place, run"
        print_error "its own utils/upgrade.php instead of reinstalling over it."
    fi
    print_error "Publish a current release, extract it, and run that copy's install.sh."
    print_error "To ${ACTION} from this archive regardless: re-run with --allow-downgrade"
    exit 1
}

#------------------------------------------------------------------------------
# Site creation: Docker mode
#------------------------------------------------------------------------------

do_site_docker() {
    local SITENAME="$1"
    local POSTGRES_PASSWORD="$2"
    local DOMAIN_NAME="${3:-localhost}"
    local PORT="${4:-8080}"
    local ACTIVATE_THEME="${5:-}"
    local NO_SSL="${6:-false}"
    local CLONE_FROM="${7:-}"
    local CLONE_KEY="${8:-}"
    local DB_PORT=$((PORT + 1000))

    # Auto-detect server IP if domain is localhost
    if [ "$DOMAIN_NAME" = "localhost" ]; then
        SERVER_IP=$(hostname -I | awk '{print $1}')
        if [ -n "$SERVER_IP" ]; then
            DOMAIN_NAME="$SERVER_IP"
            print_info "Auto-detected server IP: $DOMAIN_NAME"
        fi
    fi

    # Archive root: parent of maintenance_scripts, which is parent of install_tools.
    ARCHIVE_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

    # Before anything stops or removes the container, refuse a rebuild that
    # cannot prove it moves this site's code forward. A refusal here leaves the
    # site running exactly as it was.
    assert_rebuild_moves_code_forward "$SITENAME" "$ARCHIVE_ROOT" docker

    # Preflight: if a container with this SITENAME is already running, stop it
    # BEFORE the port check. Otherwise is_port_in_use sees the target's own
    # docker-proxy listener and treats $PORT as "taken by something else,"
    # causing install.sh to auto-pick a stray alternate port (breaks the
    # domain→port mapping on rebuild). The container-exists block below still
    # runs and does the actual `docker rm` (and --wipe-data if requested).
    if docker ps --format '{{.Names}}' 2>/dev/null | grep -q "^${SITENAME}$"; then
        print_info "Existing container '${SITENAME}' is running — stopping it before port check"
        docker stop "$SITENAME" > /dev/null 2>&1 || true
    fi

    # Port conflict detection
    print_step "Checking port availability..."

    PORT_CONFLICT=0
    SUGGESTED_PORT=""

    if is_port_in_use $PORT; then
        print_warning "Port $PORT is already in use"
        PORT_CONFLICT=1
    fi

    if is_port_in_use $DB_PORT; then
        print_warning "Database port $DB_PORT is already in use"
        PORT_CONFLICT=1
    fi

    if [ $PORT_CONFLICT -eq 1 ]; then
        list_docker_containers

        SUGGESTED_PORT=$(find_available_port 8080)

        if [ -n "$SUGGESTED_PORT" ]; then
            if [ "$ASSUME_YES" -eq 1 ]; then
                print_info "Auto-accepting suggested port $SUGGESTED_PORT (-y flag)"
                PORT=$SUGGESTED_PORT
                DB_PORT=$((PORT + 1000))
                print_success "Using port $PORT (database: $DB_PORT)"
            else
                echo ""
                echo -e "Suggested available port: ${GREEN}$SUGGESTED_PORT${NC} (database: $((SUGGESTED_PORT + 1000)))"
                echo ""
                read -p "Would you like to use port $SUGGESTED_PORT instead? [Y/n] " -n 1 -r
                echo ""

                if [[ ! $REPLY =~ ^[Nn]$ ]]; then
                    PORT=$SUGGESTED_PORT
                    DB_PORT=$((PORT + 1000))
                    print_success "Using port $PORT (database: $DB_PORT)"
                else
                    print_error "Cannot continue with port conflict. Please specify a different port."
                    exit 1
                fi
            fi
        else
            print_error "Could not find an available port in range 8080-8180"
            exit 1
        fi
    else
        print_success "Ports $PORT and $DB_PORT are available"
    fi

    # Require joinery-base image (all site images build FROM it). A host that
    # has never built it — e.g. a freshly provisioned customer-cloud VPS —
    # gets it built here automatically; Dockerfile.base ships alongside this
    # script in every release archive.
    print_step "Checking for joinery-base:${BASE_IMAGE_VERSION}..."
    if ! docker image inspect "joinery-base:${BASE_IMAGE_VERSION}" > /dev/null 2>&1; then
        print_warning "joinery-base:${BASE_IMAGE_VERSION} not found — building it now (one-time per host, 5-10 minutes)."
        do_build_base
    fi
    print_success "joinery-base:${BASE_IMAGE_VERSION} found"

    # Drift detection: warn (do not fail) if the current install.sh
    # do_server_setup differs from the hash baked into the base image. A
    # mismatch means someone edited install.sh without bumping
    # BASE_IMAGE_VERSION and rebuilding the base.
    CURRENT_HASH=$(compute_install_sh_hash)
    BASE_HASH=$(docker image inspect "joinery-base:${BASE_IMAGE_VERSION}" \
        --format '{{ index .Config.Labels "joinery.install_sh_hash" }}' 2>/dev/null)
    if [ -n "$BASE_HASH" ] && [ "$BASE_HASH" != "unknown" ] && [ "$CURRENT_HASH" != "$BASE_HASH" ]; then
        print_warning "install.sh do_server_setup has changed since joinery-base was built"
        print_warning "  base image hash:  ${BASE_HASH}"
        print_warning "  current hash:     ${CURRENT_HASH}"
        print_warning "  If system packages or PHP extensions changed, bump BASE_IMAGE_VERSION"
        print_warning "  and rebuild:  ./install.sh build-base"
    fi

    # Verify archive structure
    print_step "Verifying archive structure..."

    if [ ! -d "$ARCHIVE_ROOT/public_html" ]; then
        print_error "Cannot find public_html directory in $ARCHIVE_ROOT"
        print_error "Make sure you've extracted the joinery archive correctly"
        exit 1
    fi

    if [ ! -d "$ARCHIVE_ROOT/config" ]; then
        print_error "Cannot find config directory in $ARCHIVE_ROOT"
        exit 1
    fi

    if [ ! -f "$SCRIPT_DIR/Dockerfile.template" ]; then
        print_error "Cannot find Dockerfile.template in $SCRIPT_DIR"
        exit 1
    fi

    print_success "Archive structure verified"

    # Check for existing container
    print_step "Checking for existing container named '$SITENAME'..."

    if docker ps -a --format '{{.Names}}' | grep -q "^${SITENAME}$"; then
        print_warning "A container named '$SITENAME' already exists"

        if [ "$ASSUME_YES" -eq 1 ]; then
            if [ "$WIPE_DATA" -eq 1 ]; then
                # Full wipe: remove container AND all data volumes (fresh install)
                print_warning "Auto-removing existing container AND data volumes (-y --wipe-data)"
                docker stop "$SITENAME" 2>/dev/null || true
                docker rm "$SITENAME" 2>/dev/null || true
                remove_site_volumes "$SITENAME"
                print_success "Existing container and volumes removed"
            else
                # Safe rebuild: remove only the container; volumes survive and reattach
                print_info "Auto-removing existing container (-y flag); data volumes preserved"
                print_info "Add --wipe-data to also delete volumes (irreversible)"
                docker stop "$SITENAME" 2>/dev/null || true
                docker rm "$SITENAME" 2>/dev/null || true
                print_success "Existing container removed (volumes intact)"
            fi
        else
            # Deleting the data volumes is what --wipe-data asks for, and asking
            # interactively does not make it any less irreversible. Answering yes
            # to a rebuild prompt is not consent to lose the database, so the
            # prompt only ever offers what the flags allow.
            echo ""
            if [ "$WIPE_DATA" -eq 1 ]; then
                print_warning "--wipe-data will DELETE this site's database, uploads, storage,"
                print_warning "config (including its secret_box_key) and backups. Irreversible."
                read -p "Remove the container AND every data volume? [y/N] " -n 1 -r
                echo ""
                if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                    print_error "Aborted. Nothing was removed."
                    exit 1
                fi
                print_info "Stopping and removing existing container and volumes..."
                docker stop "$SITENAME" 2>/dev/null || true
                docker rm "$SITENAME" 2>/dev/null || true
                remove_site_volumes "$SITENAME"
                print_success "Existing container and volumes removed"
            else
                print_info "The container will be removed and rebuilt. Data volumes are kept:"
                print_info "the database, uploads, storage, config and backups all survive."
                read -p "Remove and rebuild it? [y/N] " -n 1 -r
                echo ""
                if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                    print_error "Aborted. Nothing was removed."
                    exit 1
                fi
                print_info "Stopping and removing existing container..."
                docker stop "$SITENAME" 2>/dev/null || true
                docker rm "$SITENAME" 2>/dev/null || true
                print_success "Existing container removed (volumes intact)"
            fi
        fi
    else
        print_success "No existing container found"
    fi

    # Warn about orphaned build dirs from previous interrupted installs
    ORPHANED_BUILDS=$(find ~ -maxdepth 1 -type d -name 'joinery-docker-build-*' ! -name "joinery-docker-build-${SITENAME}" 2>/dev/null)
    if [ -n "$ORPHANED_BUILDS" ]; then
        print_warning "Orphaned build directories found from previous installs:"
        echo "$ORPHANED_BUILDS" | while read -r d; do echo "  $d ($(du -sh "$d" 2>/dev/null | cut -f1))"; done
        if [ "$ASSUME_YES" -eq 1 ]; then
            echo "$ORPHANED_BUILDS" | xargs rm -rf
            print_success "Orphaned build directories removed"
        else
            read -p "Remove them now? [y/N] " -n 1 -r; echo ""
            if [[ $REPLY =~ ^[Yy]$ ]]; then
                echo "$ORPHANED_BUILDS" | xargs rm -rf
                print_success "Orphaned build directories removed"
            fi
        fi
    fi

    # Prepare build context
    print_step "Preparing build context..."

    BUILD_DIR=~/joinery-docker-build-${SITENAME}

    if [ -d "$BUILD_DIR" ]; then
        print_info "Cleaning up existing build directory..."
        rm -rf "$BUILD_DIR"
    fi

    mkdir -p "$BUILD_DIR/$SITENAME"

    # Download themes and plugins to archive before copying (skip when cloning)
    if [ -z "$CLONE_FROM" ]; then
        if [ -n "$THEMES" ] || [ -n "$UPGRADE_SERVER" ]; then
            download_themes_and_plugins "$ARCHIVE_ROOT/public_html" "$THEMES"
        fi
    else
        print_info "Skipping theme download (will be cloned from source)"
    fi

    print_info "Copying public_html..."
    cp -r "$ARCHIVE_ROOT/public_html" "$BUILD_DIR/$SITENAME/"

    # Overlay fresh core files from the upgrade server so the image always ships
    # with current code, not whatever was in the local archive.  Skip when cloning:
    # the clone source manages its own versioning.
    if [ -z "$CLONE_FROM" ]; then
        download_core_archive "$BUILD_DIR/$SITENAME/public_html"
    fi

    print_info "Setting up config directory..."
    mkdir -p "$BUILD_DIR/$SITENAME/config"
    if [ -n "$CLONE_FROM" ]; then
        # When cloning, don't copy Globalvars_site.php - _site_init.sh will create it
        # This ensures first-run initialization happens with the clone
        print_info "Skipping config copy (will be configured during clone)"
    else
        # Only the template travels into the image, never the directory wholesale.
        #
        # A release archive's config/ holds exactly one file, so copying it all
        # looked harmless. A LIVE site's config/ is a different thing: it holds
        # Globalvars_site.php — database password and secret_box_key — beside
        # whatever the deployment keeps next to it, which on a control plane is
        # the agent signing key, the provisioning and relay keys, and the DNS
        # token. `install.sh site` run from a site directory rather than an
        # extracted archive baked all of that into an image layer.
        #
        # It breaks the install too: a Globalvars_site.php in the image means the
        # container's start-up test for one finds it, skips _site_init.sh, and
        # the new site comes up with no database and someone else's keys. So
        # nothing is lost by narrowing this — a config/ with more in it than the
        # template has never produced a working site.
        if [ -f "$ARCHIVE_ROOT/config/default_Globalvars_site.php" ]; then
            cp "$ARCHIVE_ROOT/config/default_Globalvars_site.php" "$BUILD_DIR/$SITENAME/config/"
        fi

        local SKIPPED_CONFIG
        SKIPPED_CONFIG=$(find "$ARCHIVE_ROOT/config" -maxdepth 1 -type f \
            ! -name 'default_Globalvars_site.php' -printf '%f ' 2>/dev/null)
        if [ -n "$SKIPPED_CONFIG" ]; then
            print_warning "Live configuration found beside this archive — not copying it into the image:"
            print_warning "  ${SKIPPED_CONFIG}"
            print_warning "  ${ARCHIVE_ROOT} looks like a running site rather than an extracted release."
            print_warning "  The new site generates its own config and keys at first start."
        fi
    fi

    print_info "Copying maintenance_scripts..."
    mkdir -p "$BUILD_DIR/maintenance_scripts"
    cp -r "$(dirname "$SCRIPT_DIR")"/* "$BUILD_DIR/maintenance_scripts/"

    print_info "Setting up Dockerfile..."
    cp "$SCRIPT_DIR/Dockerfile.template" "$BUILD_DIR/Dockerfile"

    # config/ is an allowlist, not a denylist: the only file that belongs in a
    # site image is the template. Anything else under config/ is this
    # deployment's live secrets, and a denylist would have to guess their names.
    # Belt and braces over the copy above — it also covers a build directory
    # left behind by an earlier run.
    cat > "$BUILD_DIR/.dockerignore" << 'EOF'
.git
*.log
*/backups/*
*/storage/*
*/config/*
!*/config/default_Globalvars_site.php
EOF

    print_success "Build context prepared at $BUILD_DIR"

    # Build Docker image
    print_step "Building Docker image (this may take 5-10 minutes)..."

    cd "$BUILD_DIR"

    # Build with -q flag in quiet mode to suppress build output
    # Note: Clone options are passed at runtime, not build time (security)
    # BASE_IMAGE_VERSION is consumed by Dockerfile.template's `ARG` before FROM.
    if [ "$QUIET_MODE" -eq 1 ]; then
        docker build -q \
            --build-arg BASE_IMAGE_VERSION="$BASE_IMAGE_VERSION" \
            --build-arg SITENAME="$SITENAME" \
            --build-arg DOMAIN_NAME="$DOMAIN_NAME" \
            -t "joinery-$SITENAME" . > /dev/null
    else
        docker build \
            --build-arg BASE_IMAGE_VERSION="$BASE_IMAGE_VERSION" \
            --build-arg SITENAME="$SITENAME" \
            --build-arg DOMAIN_NAME="$DOMAIN_NAME" \
            -t "joinery-$SITENAME" .
    fi

    if [ $? -eq 0 ]; then
        print_success "Docker image built successfully"
    else
        print_error "Docker image build failed"
        exit 1
    fi

    # Run container
    print_step "Starting container..."

    # Build clone environment options (passed at runtime, not baked into image)
    CLONE_ENV_OPTS=""
    if [ -n "$CLONE_FROM" ] && [ -n "$CLONE_KEY" ]; then
        CLONE_ENV_OPTS="-e CLONE_FROM=${CLONE_FROM} -e CLONE_KEY=${CLONE_KEY}"
    fi

    # The database password reaches the container here, at run time, and only
    # here — not through --build-arg, which would bake it into the image where
    # `docker inspect` and `docker history` keep it after the container is gone.
    # A file rather than -e: anything in argv is readable by every account on
    # this box through ps. Docker copies the values into the container's own
    # config at create time, so restarts keep working and the file is needed
    # only for the length of this command.
    local ENV_FILE
    ENV_FILE="$(mktemp /tmp/joinery-env-XXXXXX)"
    chmod 600 "$ENV_FILE"
    printf 'POSTGRES_PASSWORD=%s\n' "$POSTGRES_PASSWORD" > "$ENV_FILE"
    trap 'rm -f "$ENV_FILE"' RETURN

    if [ "$QUIET_MODE" -eq 1 ]; then
        docker run -d \
            --name "$SITENAME" \
            --restart unless-stopped \
            --env-file "$ENV_FILE" \
            -p "$PORT":80 \
            -p 127.0.0.1:"$DB_PORT":5432 \
            $CLONE_ENV_OPTS \
            -v "${SITENAME}_code":/var/www/html/"${SITENAME}"/public_html \
            -v "${SITENAME}_vendor":/var/www/html/"${SITENAME}"/vendor \
            -v "${SITENAME}_scripts":/var/www/html/"${SITENAME}"/maintenance_scripts \
            -v "${SITENAME}_postgres":/var/lib/postgresql \
            -v "${SITENAME}_uploads":/var/www/html/"${SITENAME}"/uploads \
            -v "${SITENAME}_storage":/var/www/html/"${SITENAME}"/storage \
            -v "${SITENAME}_config":/var/www/html/"${SITENAME}"/config \
            -v "${SITENAME}_backups":/var/www/html/"${SITENAME}"/backups \
            -v "${SITENAME}_static":/var/www/html/"${SITENAME}"/static_files \
            -v "${SITENAME}_logs":/var/www/html/"${SITENAME}"/logs \
            -v "${SITENAME}_cache":/var/www/html/"${SITENAME}"/cache \
            -v "${SITENAME}_sessions":/var/lib/php/sessions \
            -v "${SITENAME}_apache_logs":/var/log/apache2 \
            -v "${SITENAME}_pg_logs":/var/log/postgresql \
            "joinery-$SITENAME" > /dev/null
    else
        docker run -d \
            --name "$SITENAME" \
            --restart unless-stopped \
            --env-file "$ENV_FILE" \
            -p "$PORT":80 \
            -p 127.0.0.1:"$DB_PORT":5432 \
            $CLONE_ENV_OPTS \
            -v "${SITENAME}_code":/var/www/html/"${SITENAME}"/public_html \
            -v "${SITENAME}_vendor":/var/www/html/"${SITENAME}"/vendor \
            -v "${SITENAME}_scripts":/var/www/html/"${SITENAME}"/maintenance_scripts \
            -v "${SITENAME}_postgres":/var/lib/postgresql \
            -v "${SITENAME}_uploads":/var/www/html/"${SITENAME}"/uploads \
            -v "${SITENAME}_storage":/var/www/html/"${SITENAME}"/storage \
            -v "${SITENAME}_config":/var/www/html/"${SITENAME}"/config \
            -v "${SITENAME}_backups":/var/www/html/"${SITENAME}"/backups \
            -v "${SITENAME}_static":/var/www/html/"${SITENAME}"/static_files \
            -v "${SITENAME}_logs":/var/www/html/"${SITENAME}"/logs \
            -v "${SITENAME}_cache":/var/www/html/"${SITENAME}"/cache \
            -v "${SITENAME}_sessions":/var/lib/php/sessions \
            -v "${SITENAME}_apache_logs":/var/log/apache2 \
            -v "${SITENAME}_pg_logs":/var/log/postgresql \
            "joinery-$SITENAME"
    fi

    if [ $? -eq 0 ]; then
        print_success "Container started"
    else
        print_error "Failed to start container"
        exit 1
    fi

    # Create host-side logs directory for reverse proxy (used by manage_domain.sh)
    # Container has its own /var/www/html/{site}/ but host needs logs dir for proxy
    mkdir -p "/var/www/html/${SITENAME}/logs"

    # Verify installation
    print_step "Waiting for services to initialize..."

    MAX_ATTEMPTS=12
    ATTEMPT=1

    while [ $ATTEMPT -le $MAX_ATTEMPTS ]; do
        print_info "Checking site availability (attempt $ATTEMPT/$MAX_ATTEMPTS)..."

        HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost:$PORT/" 2>/dev/null || echo "000")

        if [ "$HTTP_CODE" = "200" ]; then
            print_success "Site is responding with HTTP 200"
            break
        elif [ "$HTTP_CODE" = "500" ]; then
            print_warning "Site returned HTTP 500 - may still be initializing..."
        else
            print_info "HTTP response: $HTTP_CODE"
        fi

        if [ $ATTEMPT -eq $MAX_ATTEMPTS ]; then
            print_warning "Site not responding after $MAX_ATTEMPTS attempts"
            print_info "This may be normal - check logs with: docker logs $SITENAME"
        fi

        ATTEMPT=$((ATTEMPT + 1))
        sleep 5
    done

    # Cleanup build directory
    print_step "Cleaning up build directory..."
    if [ -d "$BUILD_DIR" ]; then
        # Move out of $BUILD_DIR before removing it — otherwise any subshell
        # spawned after this point (e.g., manage_domain.sh invocation) will
        # print "sh: 0: getcwd() failed" because the cwd was deleted.
        cd "$SCRIPT_DIR"
        rm -rf "$BUILD_DIR"
        print_success "Build directory removed"
    fi

    # Summary (always shown, even in quiet mode)
    if [ "$QUIET_MODE" -eq 1 ]; then
        # Minimal summary for quiet mode
        echo ""
        echo -e "${GREEN}Installation Complete!${NC}"
        echo -e "Site: ${GREEN}$SITENAME${NC} | URL: ${GREEN}http://$DOMAIN_NAME:$PORT/${NC}"
        if [ "$PASSWORD_WAS_GENERATED" = "1" ]; then
            echo -e "Database Password: ${GREEN}$POSTGRES_PASSWORD${NC}"
        fi
    else
        print_header "Installation Complete!"

        echo -e "Site Name:        ${GREEN}$SITENAME${NC}"
        echo -e "Domain:           ${GREEN}$DOMAIN_NAME${NC}"
        echo -e "Web Port:         ${GREEN}$PORT${NC}"
        echo -e "Database Port:    ${GREEN}$DB_PORT${NC}"
        echo ""
        if [ "$PASSWORD_WAS_GENERATED" = "1" ]; then
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo -e "${YELLOW}  IMPORTANT: Save this auto-generated password!${NC}"
            echo -e "${YELLOW}  Database Password: ${GREEN}$POSTGRES_PASSWORD${NC}"
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo ""
        fi
        echo -e "Access your site: ${GREEN}http://$DOMAIN_NAME:$PORT/${NC}"
        echo ""
        print_admin_login "/var/www/html/$SITENAME/config/admin_credentials.txt" \
            "docker exec $SITENAME cat" \
            "docker exec $SITENAME cat /var/www/html/$SITENAME/config/admin_credentials.txt"
        print_ssl_deferred_notice "$ARCHIVE_ROOT/maintenance_scripts/sysadmin_tools/setup_ssl.sh"
        echo "Useful commands:"
        echo -e "  View logs:      ${BLUE}docker logs $SITENAME${NC}"
        echo -e "  Shell access:   ${BLUE}docker exec -it $SITENAME bash${NC}"
        echo -e "  Stop container: ${BLUE}docker stop $SITENAME${NC}"
        echo -e "  Start container:${BLUE}docker start $SITENAME${NC}"
        echo ""

        CONTAINER_STATUS=$(docker ps --filter "name=$SITENAME" --format "{{.Status}}" 2>/dev/null)
        if [ -n "$CONTAINER_STATUS" ]; then
            echo -e "Container status: ${GREEN}$CONTAINER_STATUS${NC}"
        else
            print_warning "Container may not be running. Check logs with: docker logs $SITENAME"
        fi

        list_docker_containers

        print_success "Docker site installation complete!"

        # Remind about source archive disk usage
        ARCHIVE_SIZE=$(du -sh "$ARCHIVE_ROOT" 2>/dev/null | cut -f1)
        print_info "Note: Source archive at $ARCHIVE_ROOT (${ARCHIVE_SIZE}) is no longer needed for this site."
        print_info "If all sites are installed, you can free space with: rm -rf $ARCHIVE_ROOT"
    fi

    # Set up SSL with reverse proxy if domain provided
    if should_setup_ssl "$DOMAIN_NAME" "$NO_SSL"; then
        setup_ssl_docker_proxy "$SITENAME" "$DOMAIN_NAME" "$PORT"
    fi
}

#------------------------------------------------------------------------------
# Site creation: Bare-metal mode
#------------------------------------------------------------------------------

do_site_baremetal() {
    local SITENAME="$1"
    local POSTGRES_PASSWORD="$2"
    local DOMAIN_NAME="${3:-localhost}"
    local ACTIVATE_THEME="${4:-}"
    local WITH_TEST_SITE="${5:-false}"
    local NO_SSL="${6:-false}"
    local CLONE_FROM="${7:-}"
    local CLONE_KEY="${8:-}"

    # Auto-detect server IP if domain is localhost
    if [ "$DOMAIN_NAME" = "localhost" ]; then
        SERVER_IP=$(hostname -I | awk '{print $1}')
        if [ -n "$SERVER_IP" ]; then
            DOMAIN_NAME="$SERVER_IP"
            print_info "Auto-detected server IP: $DOMAIN_NAME"
        fi
    fi

    # Archive root: parent of maintenance_scripts, which is parent of install_tools.
    # Resolved here rather than further down because the guard below needs it, and
    # the guard has to run before anything is asked or overwritten.
    ARCHIVE_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

    # Refuse an install that cannot prove it moves this site's code forward,
    # before the overwrite prompt rather than after it: there is no point asking
    # a question the next check is going to override, and a refusal here has
    # touched nothing.
    assert_rebuild_moves_code_forward "$SITENAME" "$ARCHIVE_ROOT" baremetal

    # Check if site already exists
    if [ -d "/var/www/html/$SITENAME" ] && [ -f "/var/www/html/$SITENAME/config/Globalvars_site.php" ]; then
        if [ "$ASSUME_YES" -eq 1 ]; then
            print_warning "Site $SITENAME already exists. Overwriting..."
        else
            echo ""
            read -p "Site $SITENAME already exists. Overwrite? [y/N] " -n 1 -r
            echo ""
            if [[ ! $REPLY =~ ^[Yy]$ ]]; then
                print_error "Aborted."
                exit 1
            fi
        fi
    fi

    # Verify archive structure and locate source files. ARCHIVE_ROOT is already
    # resolved above, where the forward-only guard needed it.
    print_step "Locating source files..."

    if [ ! -d "$ARCHIVE_ROOT/public_html" ]; then
        print_error "Cannot find public_html directory in $ARCHIVE_ROOT"
        print_error "Make sure you've extracted the joinery archive correctly"
        exit 1
    fi

    print_success "Source files located at $ARCHIVE_ROOT"

    # Download themes and plugins to archive before deployment
    download_themes_and_plugins "$ARCHIVE_ROOT/public_html" "$THEMES"

    # Deploy application code
    deploy_application_code "$SITENAME" "$ARCHIVE_ROOT"

    # Install PHP extensions the deployed source declares
    install_declared_dependencies "/var/www/html/$SITENAME/public_html"

    # Verify _site_init.sh exists
    if [ ! -f "${SCRIPT_DIR}/_site_init.sh" ]; then
        print_error "Cannot find _site_init.sh in $SCRIPT_DIR"
        exit 1
    fi

    # Build _site_init.sh arguments.
    #
    # An array, not a string: word-splitting a string breaks the moment any value
    # contains a space, and silently shifts every argument after it — a password
    # with a space would have made the domain land in the password's slot. Glob
    # characters in a password would have been pathname-expanded for the same
    # reason.
    #
    # The password slot is deliberately empty. It travels in the environment
    # instead (JOINERY_DB_PASSWORD), because argv is readable by every account on
    # this machine through ps for as long as site init runs. The slot still has
    # to exist: _site_init.sh consumes three leading positionals.
    local INIT_ARGS=("$SITENAME" "" "$DOMAIN_NAME")
    if [ -n "$ACTIVATE_THEME" ]; then
        INIT_ARGS+=(--activate "$ACTIVATE_THEME")
    fi
    if [ "$QUIET_MODE" -eq 1 ]; then
        INIT_ARGS+=(-q)
    fi
    if [ -n "$CLONE_FROM" ] && [ -n "$CLONE_KEY" ]; then
        INIT_ARGS+=("--clone-from=${CLONE_FROM}" "--clone-key=${CLONE_KEY}")
    fi

    # Call _site_init.sh for shared setup
    print_step "Initializing site via _site_init.sh..."

    # Export PGPASSWORD for non-interactive database operations
    export PGPASSWORD="$POSTGRES_PASSWORD"

    # Run the initialization script
    if ! JOINERY_DB_PASSWORD="$POSTGRES_PASSWORD" "$SCRIPT_DIR/_site_init.sh" "${INIT_ARGS[@]}"; then
        print_error "_site_init.sh failed"
        exit 1
    fi

    # Run active plugins' declared host installers (idempotent; matters when
    # cloning from a site with active plugins that need host services)
    bash "$SCRIPT_DIR/_plugin_installers_start.sh" "$SITENAME" || true

    # Create test site if requested
    if [ "$WITH_TEST_SITE" = true ]; then
        create_test_site "$SITENAME" "$POSTGRES_PASSWORD" "$DOMAIN_NAME"
    fi

    # Verify site is responding
    print_step "Verifying site installation..."

    sleep 2

    HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/" 2>/dev/null || echo "000")

    if [ "$HTTP_CODE" = "200" ]; then
        print_success "Site is responding with HTTP 200"
    else
        print_warning "Site returned HTTP $HTTP_CODE - may need manual verification"
    fi

    # Summary (always shown, even in quiet mode)
    if [ "$QUIET_MODE" -eq 1 ]; then
        # Minimal summary for quiet mode
        echo ""
        echo -e "${GREEN}Installation Complete!${NC}"
        echo -e "Site: ${GREEN}$SITENAME${NC} | URL: ${GREEN}http://$DOMAIN_NAME/${NC}"
        if [ "$PASSWORD_WAS_GENERATED" = "1" ]; then
            echo -e "Database Password: ${GREEN}$POSTGRES_PASSWORD${NC}"
        fi
    else
        print_header "Installation Complete!"

        echo -e "Site Name:        ${GREEN}$SITENAME${NC}"
        echo -e "Domain:           ${GREEN}$DOMAIN_NAME${NC}"
        echo -e "Location:         ${GREEN}/var/www/html/$SITENAME/${NC}"
        if [ -n "$ACTIVATE_THEME" ]; then
            echo -e "Theme:            ${GREEN}$ACTIVATE_THEME${NC}"
        fi
        if [ "$WITH_TEST_SITE" = true ]; then
            echo -e "Test Site:        ${GREEN}/var/www/html/${SITENAME}_test/${NC}"
        fi
        echo ""
        if [ "$PASSWORD_WAS_GENERATED" = "1" ]; then
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo -e "${YELLOW}  IMPORTANT: Save this auto-generated password!${NC}"
            echo -e "${YELLOW}  Database Password: ${GREEN}$POSTGRES_PASSWORD${NC}"
            echo -e "${YELLOW}═══════════════════════════════════════════════════════════════${NC}"
            echo ""
        fi
        echo -e "Access your site: ${GREEN}http://$DOMAIN_NAME/${NC}"
        if [ "$WITH_TEST_SITE" = true ]; then
            echo -e "Test site:        ${GREEN}http://test.$DOMAIN_NAME/${NC}"
        fi
        echo ""
        print_admin_login "/var/www/html/$SITENAME/config/admin_credentials.txt"
        print_ssl_deferred_notice "/var/www/html/$SITENAME/maintenance_scripts/sysadmin_tools/setup_ssl.sh"
        echo "Useful commands:"
        echo -e "  View logs:      ${BLUE}tail -f /var/www/html/$SITENAME/logs/error.log${NC}"
        echo -e "  Restart Apache: ${BLUE}sudo systemctl restart apache2${NC}"
        echo ""

        list_baremetal_sites

        print_success "Bare-metal site installation complete!"
    fi

    # Set up SSL if domain provided
    if should_setup_ssl "$DOMAIN_NAME" "$NO_SSL"; then
        setup_ssl_baremetal "$DOMAIN_NAME"
    fi
}

#==============================================================================
# SUBCOMMAND: list - List existing Joinery sites
#==============================================================================

do_list() {
    print_header "Existing Joinery Sites"

    # Check for Docker sites
    if command -v docker &> /dev/null; then
        echo -e "${BLUE}Docker containers:${NC}"

        local found=false
        while IFS= read -r line; do
            if [ -n "$line" ]; then
                local name=$(echo "$line" | awk '{print $1}')
                local status=$(echo "$line" | awk '{$1=""; print $0}' | xargs)
                local ports=$(docker port "$name" 2>/dev/null | head -1 | sed 's/.*://')

                # Only show joinery containers
                if [[ "$name" == joinery-* ]] || docker inspect "$name" --format '{{.Config.Image}}' 2>/dev/null | grep -q "^joinery-"; then
                    echo "  $name	$status	Port: ${ports:-N/A}"
                    found=true
                fi
            fi
        done < <(docker ps -a --format "{{.Names}} {{.Status}}" 2>/dev/null)

        # Also show stopped containers
        local stopped=$(docker ps -a --filter "status=exited" --format "{{.Names}}" 2>/dev/null | grep "^joinery-")
        if [ -n "$stopped" ]; then
            echo "$stopped" | while read name; do
                if ! docker ps --format "{{.Names}}" 2>/dev/null | grep -q "^${name}$"; then
                    echo "  ${name}	(stopped)"
                    found=true
                fi
            done
        fi

        if [ "$found" = false ]; then
            echo "  (none)"
        fi
        echo ""
    else
        print_info "Docker not installed"
        echo ""
    fi

    # Check for bare-metal sites
    echo -e "${BLUE}Bare-metal sites:${NC}"
    local found=false
    for dir in /var/www/html/*/; do
        if [ -f "${dir}config/Globalvars_site.php" ]; then
            local sitename=$(basename "$dir")
            # Skip test sites in listing (show as suffix)
            if [[ "$sitename" != *"_test" ]]; then
                local status="configured"
                if [ -f "/etc/apache2/sites-enabled/${sitename}.conf" ]; then
                    status="enabled"
                fi
                # Check for companion test site
                local test_suffix=""
                if [ -d "/var/www/html/${sitename}_test" ]; then
                    test_suffix=" (+test site)"
                fi
                echo "  ${sitename}	${status}${test_suffix}"
                found=true
            fi
        fi
    done
    if [ "$found" = false ]; then
        echo "  (none)"
    fi
}

#==============================================================================
# HELP OUTPUT
#==============================================================================

show_help() {
    echo ""
    echo "Joinery Installation Script v${INSTALLER_VERSION}"
    echo ""
    echo "Usage:"
    echo "  ./install.sh [global-options] <command> [options]"
    echo ""
    echo "Global Options:"
    echo "  -y, --yes     Auto-accept all prompts (non-interactive mode)"
    echo "  -q, --quiet   Suppress most output, show only errors and final status"
    echo ""
    echo "Commands:"
    echo "  docker       Install Docker (one-time, for Docker deployments)"
    echo "  host-harden   Harden a Docker host server (one-time, run after docker install)"
    echo "  build-base   Build the shared joinery-base image (one-time per Docker host)"
    echo "  server       Set up base server (one-time, for bare-metal deployments)"
    echo "  site         Create a new Joinery site"
    echo "  list         List existing Joinery sites (Docker and bare-metal)"
    echo ""
    echo "Server Command Options:"
    echo "  --allow-unsupported-os Proceed on an OS other than Ubuntu 24.04 LTS"
    echo ""
    echo "Site Command Options:"
    echo "  --admin-email=EMAIL    Address for the admin account (default admin@example.com)"
    echo "  --activate THEME       Activate specified theme after installation"
    echo "  --with-test-site       Also create a test site (bare-metal only)"
    echo "  --no-ssl               Skip automatic SSL certificate setup"
    echo "  --docker               Force Docker mode"
    echo "  --bare-metal           Force bare-metal mode"
    echo "  --clone-from=URL       Clone database and uploads from existing site"
    echo "  --clone-key=KEY        Authentication key for clone source"
    echo ""
    echo "SSL (Automatic):"
    echo "  When a domain name is provided (not localhost/IP), SSL is automatically"
    echo "  configured using Let's Encrypt. DNS must point to this server."
    echo "  Use --no-ssl to skip SSL setup."
    echo ""
    echo "Examples:"
    echo "  # Install Docker (once), then harden the host"
    echo "  sudo ./install.sh docker"
    echo "  sudo ./install.sh host-harden"
    echo ""
    echo "  # Create Docker site (with automatic SSL)"
    echo "  sudo ./install.sh site production SecurePass! prod.example.com 8080"
    echo ""
    echo "  # Create site without SSL"
    echo "  sudo ./install.sh site staging StagePass! stage.example.com 8081 --no-ssl"
    echo ""
    echo "  # Clone an existing site"
    echo "  sudo ./install.sh site newsite example.com 8080 \\"
    echo "      --clone-from=https://source.example.com --clone-key=SecretKey123"
    echo ""
    echo "  # Set up bare-metal server (once)"
    echo "  sudo ./install.sh server"
    echo ""
    echo "  # Create bare-metal site (with automatic SSL)"
    echo "  sudo ./install.sh site client1 Pass1! client1.example.com"
    echo ""
    echo "  # Create site with test site"
    echo "  sudo ./install.sh site client2 Pass2! client2.example.com --with-test-site"
    echo ""
    echo "  # Non-interactive deployment (for scripting/CI)"
    echo "  sudo ./install.sh -y -q site mysite SecurePass! mysite.com 8080"
    echo ""
    echo "  # List all sites"
    echo "  sudo ./install.sh list"
    echo ""
    echo "Auto-Detection:"
    echo "  'install.sh site' automatically detects the environment:"
    echo "  - With PORT specified: Docker mode"
    echo "  - Without PORT: Bare-metal mode"
    echo "  - Use --docker or --bare-metal flags to override"
    echo ""
    echo "Run './install.sh <command> --help' for command-specific help."
    echo ""
}

#==============================================================================
# MAIN DISPATCHER
#==============================================================================

# Allow other scripts (e.g., scripts/setup_ssl.sh) to source this file just to
# pick up the helper functions, without running the install dispatcher.
if [ "${BASH_SOURCE[0]}" != "${0}" ]; then
    return 0
fi

# Parse global flags first (before command)
while [[ $# -gt 0 ]]; do
    case "$1" in
        -y|--yes)
            ASSUME_YES=1
            shift
            ;;
        -q|--quiet)
            QUIET_MODE=1
            shift
            ;;
        *)
            break
            ;;
    esac
done

case "${1:-}" in
    docker)
        shift
        do_docker_install "$@"
        ;;
    host-harden)
        shift
        do_host_harden "$@"
        ;;
    build-base)
        shift
        do_build_base "$@"
        ;;
    server)
        shift
        do_server_setup "$@"
        ;;
    site)
        shift
        do_site_create "$@"
        ;;
    list)
        shift
        do_list "$@"
        ;;
    --help|-h|"")
        show_help
        ;;
    *)
        print_error "Unknown command: $1"
        show_help
        exit 1
        ;;
esac
