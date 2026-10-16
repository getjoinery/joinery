#!/usr/bin/env bash
# manage_domain.sh - change the domain a Joinery site answers to, after the install
# VERSION: 2.0 - a domain is set the way install.sh sets one (B7): the vhost is the
#                template's render (_vhost_install.sh), at the one path
#                install.sh writes, <site>.conf, for a container's proxy and a
#                bare-metal site alike, recorded so the next converge keeps it; the
#                certificate is issued by setup_ssl.sh (through an edge such as
#                Cloudflare too) and otherwise left to the retry timer, which moves
#                from the old name to the new; the site's own webDir follows
#                (reconcile_site.sh). The <site>-proxy.conf an earlier version wrote
#                is retired on the next set or clear. remove-ssl is gone: a site's
#                certificate belongs to its vhost's name (remove_site_certificate.sh
#                takes one no vhost uses).
# VERSION: 1.3 - a container site is read through the engine this host's sites run on
#                (_site_engine.sh): Docker, or the site container engine (specs/site_container_engine.md WP3)
# VERSION: 1.2 - says when a container's web port answers around the proxy (published on every
#                interface) or cannot be reached once the domain is cleared (published on
#                127.0.0.1, as install.sh publishes a site with a domain)
# VERSION: 1.1 - the proxy vhost appends to X-Forwarded-For rather than
#                replacing it, so the chain Cloudflare started reaches the
#                container and mod_remoteip there can resolve the real client
#                (specs/post_release_fleet_defects.md B2)
# VERSION: 1.0
#
# Usage:
#   ./manage_domain.sh COMMAND SITENAME [OPTIONS]
#
# Commands:
#   set SITENAME DOMAIN [--no-ssl]  - Assign or change the domain
#   clear SITENAME                   - Remove the domain (the site answers at this machine's
#                                      address, as an install with no domain does)
#   status SITENAME                  - Show the current configuration
#   rollback SITENAME                - Restore the configuration before the last set or clear;
#                                      each rollback steps one further back, never forward
#
# A container site's vhost is the host's reverse proxy; a bare-metal site's is its
# own. Both live at /etc/apache2/sites-available/<site>.conf. Run as root on the
# machine the vhost is on (a container site's host).

set -e

#==============================================================================
# CONFIGURATION
#==============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TOOLS_DIR="$(cd "$SCRIPT_DIR/../install_tools" 2>/dev/null && pwd || true)"
# The engine this host's sites run on, and the one way to call it.
if [ -z "$TOOLS_DIR" ] || [ ! -f "$TOOLS_DIR/_site_engine.sh" ]; then
    echo "manage_domain: there is no install_tools/_site_engine.sh beside this script's tree" >&2
    exit 1
fi
. "$TOOLS_DIR/_site_engine.sh"
# vhost_install: the render a converge makes, put in place and recorded.
if [ ! -f "$TOOLS_DIR/_vhost_install.sh" ]; then
    echo "manage_domain: there is no install_tools/_vhost_install.sh beside this script's tree" >&2
    exit 1
fi
. "$TOOLS_DIR/_vhost_install.sh"

AVAILABLE="/etc/apache2/sites-available"

#==============================================================================
# COLORS AND OUTPUT
#==============================================================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
print_success() { echo -e "${GREEN}[OK]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
print_error()   { echo -e "${RED}[ERROR]${NC} $1" >&2; }

#==============================================================================
# HELPER FUNCTIONS
#==============================================================================

# A name a certificate can be issued for: letters, digits, dots and hyphens,
# with a dot, not an address.
is_domain_name() {
    local value="$1"
    [[ "$value" =~ ^[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)+$ ]] \
        && ! [[ "$value" =~ ^[0-9.]+$ ]]
}

# What a site with no domain answers to, as install.sh decides it: this
# machine's first address, or localhost when it has none.
no_domain_address() {
    local addr
    addr="$(hostname -I 2>/dev/null | awk '{print $1}')"
    echo "${addr:-localhost}"
}

# Check if site is running in a container
is_docker_site() {
    local sitename="$1"
    site_engine ps --format '{{.Names}}' 2>/dev/null | grep -qx "$sitename"
}

# Get the container's web port
get_container_port() {
    local sitename="$1"
    site_engine port "$sitename" 80 2>/dev/null | head -1 | sed 's/.*://'
}

# The host address the container's web port is published on: 127.0.0.1 when
# only this machine (its proxy) reaches it, 0.0.0.0 when every interface does.
get_container_bind() {
    local sitename="$1"
    site_engine port "$sitename" 80 2>/dev/null | head -1 | sed 's/:[0-9]*$//'
}

# container, baremetal, or nothing (no such site here).
site_kind() {
    local sitename="$1"
    if is_docker_site "$sitename"; then
        echo container
    elif [ -f "${AVAILABLE}/${sitename}.conf" ] && [ -f "/var/www/html/${sitename}/config/Globalvars_site.php" ]; then
        echo baremetal
    fi
}

# The domain a vhost file serves: its first ServerName.
conf_domain() {
    [ -f "$1" ] || return 0
    grep -m1 -oE '^[[:space:]]*ServerName[[:space:]]+\S+' "$1" | awk '{print $2}'
}

# The site's vhost, and the one an earlier version of this script wrote for a
# container beside it (port 80 only, its own certificate file from certbot).
site_conf()   { echo "${AVAILABLE}/$1.conf"; }
legacy_conf() { echo "${AVAILABLE}/$1-proxy.conf"; }

# Take the earlier version's proxy files out of Apache. They name the same
# domain as the template's vhost, so leaving them enabled serves the site twice.
# They are moved into the backup set, never deleted.
retire_legacy_proxy() {
    local sitename="$1" backup="$2" f retired=0
    for f in "${sitename}-proxy.conf" "${sitename}-proxy-le-ssl.conf"; do
        if [ -f "${AVAILABLE}/${f}" ]; then
            a2dissite "$f" > /dev/null 2>&1 || true
            mv "${AVAILABLE}/${f}" "${backup}/${f}"
            print_info "Retired ${f} (kept in ${backup})"
            retired=1
        fi
    done
    # The earlier version had certbot's Apache installer write the -le-ssl
    # file, and the lineage's renewal config still names that installer: heal
    # it as host housekeeping does, so a renewal edits no vhost.
    if [ "${retired:-0}" = 1 ] && [ -f "$TOOLS_DIR/_host_files.sh" ]; then
        # shellcheck source=../install_tools/_host_files.sh
        . "$TOOLS_DIR/_host_files.sh"
        host_files_heal_renewal_confs /etc/letsencrypt || true
    fi
}

# Keep what a set or clear is about to change, as one set per run, so rollback
# puts back exactly what was there.
create_backup() {
    local sitename="$1"
    local dir="/var/www/html/${sitename}/backups/domain/$(date -u +%Y%m%d_%H%M%S)"
    mkdir -p "$dir"
    local f
    for f in "${sitename}.conf" ".${sitename}.conf.rendered" "${sitename}-proxy.conf" "${sitename}-proxy-le-ssl.conf"; do
        [ -f "${AVAILABLE}/${f}" ] && cp -p "${AVAILABLE}/${f}" "${dir}/${f}"
    done
    [ -L "/etc/apache2/sites-enabled/${sitename}.conf" ] && touch "${dir}/.enabled"
    echo "$dir"
}

# Ensure Apache with mod_proxy is available on host (for container sites)
ensure_apache_on_host() {
    if ! command -v apache2 &> /dev/null; then
        print_info "Installing Apache on host for reverse proxy..."
        apt-get update -qq
        apt-get install -y -qq apache2
    fi

    # Everything the proxy vhost template uses: ProxyPass (proxy, proxy_http),
    # RequestHeader (headers), RewriteEngine (rewrite), SSLEngine (ssl).
    local needed="proxy proxy_http headers rewrite ssl"
    local missing=""
    local m
    for m in $needed; do
        apache2ctl -M 2>/dev/null | grep -q "${m}_module" || missing="$missing $m"
    done
    if [ -n "$missing" ]; then
        print_info "Enabling Apache modules:${missing}..."
        # shellcheck disable=SC2086
        a2enmod -q $missing >/dev/null
    fi
}

# A bare-metal vhost is kept current by render_vhost.sh, which holds back a file
# an operator edited. Converge first: when the file on disk is then exactly the
# recorded render, it is ours to replace; otherwise somebody's edit is in it and
# a domain change would overwrite it.
assert_baremetal_vhost_is_ours() {
    local sitename="$1"
    local conf state
    conf="$(site_conf "$sitename")"
    state="${AVAILABLE}/.${sitename}.conf.rendered"
    bash "$TOOLS_DIR/render_vhost.sh" "$sitename" "/var/www/html/${sitename}" || true
    if [ ! -f "$state" ] || ! cmp -s "$state" "$conf"; then
        print_error "${conf} carries edits the template does not, so the domain was not changed."
        print_error "render_vhost.sh's candidate (${conf}.new, when it wrote one) shows the difference; merge the edits into the template's render, copy it over, and run this again."
        exit 4
    fi
}

# On a container host nothing converges the proxy vhost, so its render record
# is only what this script last wrote. When one exists and the file differs,
# somebody edited the file since: refuse, as for bare metal. With no record
# (the installer's file), the replaced file is kept and named by vhost_install.
assert_container_vhost_is_ours() {
    local sitename="$1"
    local conf state
    conf="$(site_conf "$sitename")"
    state="${AVAILABLE}/.${sitename}.conf.rendered"
    if [ -f "$conf" ] && [ -f "$state" ] && ! cmp -s "$state" "$conf"; then
        print_error "${conf} has been edited since manage_domain.sh last wrote it, so the domain was not changed."
        print_error "Compare it with ${state} (what was written), carry the edits over by hand or remove them, then run this again."
        exit 4
    fi
}

reload_apache() {
    if ! apache2ctl configtest > /dev/null 2>&1; then
        print_error "Apache configuration error"
        exit 4
    fi
    systemctl reload apache2 2>/dev/null || apache2ctl graceful
}

# The site's own idea of its address (webDir, in its config and its settings)
# follows the vhost. reconcile_site.sh is the one place that is settled; the
# vhost and certificate steps are this script's.
settle_site_identity() {
    local sitename="$1" kind="$2" domain="$3"
    local script="/var/www/html/${sitename}/maintenance_scripts/sysadmin_tools/reconcile_site.sh"
    local out rc=0
    if [ "$kind" = "container" ]; then
        out="$(site_engine exec "$sitename" bash "$script" "$sitename" --domain "$domain" --skip-web-config --skip-ssl 2>&1)" || rc=$?
    else
        out="$(bash "$script" "$sitename" --domain "$domain" --skip-web-config --skip-ssl 2>&1)" || rc=$?
    fi
    if [ "$rc" -ne 0 ]; then
        print_warning "The site's own address (webDir) was not updated:"
        printf '%s\n' "$out" | grep -E 'RECONCILE_FAILED|ERROR' | sed 's/^/         /' >&2 || true
        return 0
    fi
    local changes
    changes="$(printf '%s\n' "$out" | sed -n 's/^RECONCILE_SET //p')"
    if [ -n "$changes" ]; then
        printf '%s\n' "$changes" | while IFS= read -r line; do print_success "Site settings: ${line}"; done
    else
        print_info "Site settings already name ${domain}"
    fi
}

# Where the retry timer finds setup_ssl.sh, most durable first, as install.sh
# arms it: a container host's agent bundle, then this tree; a bare-metal
# site's own tree.
setup_ssl_candidates() {
    local sitename="$1" kind="$2"
    if [ "$kind" = "container" ]; then
        local candidates="${SCRIPT_DIR}/setup_ssl.sh"
        if command -v joinery-agent > /dev/null 2>&1 || [ -d /opt/joinery-agent ]; then
            candidates="/opt/joinery-agent/tree/maintenance_scripts/sysadmin_tools/setup_ssl.sh:${candidates}"
        fi
        echo "$candidates"
    else
        echo "/var/www/html/${sitename}/maintenance_scripts/sysadmin_tools/setup_ssl.sh"
    fi
}

# The certificate, as an install gets one: issued now when the name reaches
# this box (directly or through an edge) or a DNS-01 credential is in place,
# otherwise by the retry timer once it does. The old name's timer stops.
settle_certificate() {
    local sitename="$1" kind="$2" domain="$3" old_domain="$4" no_ssl="$5"
    local armer="${SCRIPT_DIR}/arm_ssl_retry.sh"

    if [ -n "$old_domain" ] && [ "$old_domain" != "$domain" ] && is_domain_name "$old_domain"; then
        bash "$armer" "$old_domain" --disarm > /dev/null 2>&1 || true
        if [ -d "/etc/letsencrypt/live/${old_domain}" ]; then
            print_info "The certificate for ${old_domain} is still on this machine. Once nothing uses it:"
            print_info "  sudo bash ${SCRIPT_DIR}/remove_site_certificate.sh ${old_domain}"
        fi
    fi

    # An address or localhost: no certificate is issued for one.
    is_domain_name "$domain" || return 0
    if [ "$no_ssl" = true ]; then
        print_info "No certificate asked for (--no-ssl). The site answers on HTTP; issue one later with:"
        print_info "  sudo bash ${SCRIPT_DIR}/setup_ssl.sh ${domain}"
        return 0
    fi

    print_info "Issuing a certificate for ${domain}..."
    bash "${SCRIPT_DIR}/setup_ssl.sh" "$domain" || true
    if [ -f "/etc/letsencrypt/live/${domain}/fullchain.pem" ]; then
        bash "$armer" "$domain" --disarm > /dev/null 2>&1 || true
        print_success "Certificate in place for ${domain}"
    elif bash "$armer" "$domain" --setup-ssl "$(setup_ssl_candidates "$sitename" "$kind")" > /dev/null 2>&1; then
        print_info "No certificate yet. The retry timer issues one on its own once ${domain} reaches this machine."
        print_info "Behind Cloudflare, keep SSL/TLS on Full (not Full (Strict)) until it lands."
    else
        print_warning "No certificate yet, and the retry timer could not be armed. Once ${domain} reaches this machine, run:"
        print_warning "  sudo bash ${SCRIPT_DIR}/setup_ssl.sh ${domain}"
    fi
}

# Put the template's vhost in place for a domain, by site kind.
install_site_vhost() {
    local sitename="$1" kind="$2" domain="$3"
    if [ "$kind" = "container" ]; then
        ensure_apache_on_host
        local port
        port="$(get_container_port "$sitename")"
        if [ -z "$port" ]; then
            print_error "Could not determine the container's web port for $sitename"
            exit 1
        fi
        print_info "Container web port: $port ($(site_engine_kind))"
        # The proxy vhost logs into the site's folder on the host.
        mkdir -p "/var/www/html/${sitename}/logs"
        PORT="$port" vhost_install "$TOOLS_DIR/default_proxy_vhost.conf" "/var/www/html/${sitename}" "$domain" || exit 4
        # Bare-IP requests reach the site's proxy, not Ubuntu's welcome page.
        a2dissite 000-default.conf > /dev/null 2>&1 || true
    else
        vhost_install "$TOOLS_DIR/default_virtualhost.conf" "/var/www/html/${sitename}" "$domain" || exit 4
    fi
}

#==============================================================================
# COMMAND: STATUS
#==============================================================================

cmd_status() {
    local sitename="$1"

    if [ -z "$sitename" ]; then
        print_error "Usage: $0 status SITENAME"
        exit 3
    fi

    local kind
    kind="$(site_kind "$sitename")"
    if [ -z "$kind" ]; then
        print_error "Site not found: $sitename"
        exit 2
    fi

    echo ""
    echo -e "${BLUE}Site: ${GREEN}${sitename}${NC}"
    echo "─────────────────────────────────────────"

    if [ "$kind" = "container" ]; then
        echo -e "Type:   ${GREEN}Container${NC} ($(site_engine_kind), port $(get_container_port "$sitename") on $(get_container_bind "$sitename"))"
    else
        echo -e "Type:   ${GREEN}Bare-metal${NC}"
    fi

    local conf domain
    conf="$(site_conf "$sitename")"
    domain="$(conf_domain "$conf")"
    if [ -z "$domain" ] || ! is_domain_name "$domain"; then
        echo -e "Domain: ${YELLOW}(none${domain:+: answers at ${domain}})${NC}"
    else
        echo -e "Domain: ${GREEN}${domain}${NC}"
    fi
    if [ -f "$(legacy_conf "$sitename")" ]; then
        echo -e "Legacy: ${YELLOW}$(legacy_conf "$sitename") (from an earlier manage_domain.sh; the next set retires it)${NC}"
    fi

    if [ -n "$domain" ] && is_domain_name "$domain"; then
        local cert="/etc/letsencrypt/live/${domain}/fullchain.pem"
        if [ -f "$cert" ]; then
            local expiry
            expiry="$(openssl x509 -enddate -noout -in "$cert" 2>/dev/null | cut -d= -f2)"
            echo -e "SSL:    ${GREEN}Let's Encrypt${NC} (expires ${expiry})"
        elif [ -f "/etc/joinery/ssl-retry/${domain}.conf" ]; then
            echo -e "SSL:    ${YELLOW}Waiting${NC} (the retry timer issues one once ${domain} reaches this machine)"
        else
            echo -e "SSL:    ${YELLOW}None${NC} (sudo bash ${SCRIPT_DIR}/setup_ssl.sh ${domain})"
        fi
    fi

    echo ""
}

#==============================================================================
# COMMAND: SET
#==============================================================================

cmd_set() {
    local sitename="${1:-}"
    local domain="${2:-}"
    local no_ssl=false

    shift 2 || true
    while [[ $# -gt 0 ]]; do
        case $1 in
            --no-ssl) no_ssl=true ;;
            *) print_error "Unknown option: $1"; exit 3 ;;
        esac
        shift
    done

    if [ -z "$sitename" ] || [ -z "$domain" ]; then
        print_error "Usage: $0 set SITENAME DOMAIN [--no-ssl]"
        exit 3
    fi
    domain="${domain,,}"
    if ! is_domain_name "$domain"; then
        print_error "'${domain}' is not a domain name. To serve the site with no domain, use: $0 clear ${sitename}"
        exit 3
    fi

    local kind
    kind="$(site_kind "$sitename")"
    if [ -z "$kind" ]; then
        print_error "Site not found: $sitename (a container site must be running)"
        exit 2
    fi
    if [ "$kind" = "baremetal" ]; then
        assert_baremetal_vhost_is_ours "$sitename"
    else
        assert_container_vhost_is_ours "$sitename"
    fi

    local old_domain backup
    old_domain="$(conf_domain "$(site_conf "$sitename")")"
    [ -n "$old_domain" ] || old_domain="$(conf_domain "$(legacy_conf "$sitename")")"
    backup="$(create_backup "$sitename")"
    print_info "Saved the current configuration in ${backup}"

    [ "$kind" = "container" ] && retire_legacy_proxy "$sitename" "$backup"
    install_site_vhost "$sitename" "$kind" "$domain"
    reload_apache
    print_success "Apache serves ${sitename} at ${domain}"

    settle_site_identity "$sitename" "$kind" "$domain"
    settle_certificate "$sitename" "$kind" "$domain" "$old_domain" "$no_ssl"

    print_success "Domain $domain configured for $sitename"
    if [ "$kind" = "container" ] && [ "$(get_container_bind "$sitename")" != "127.0.0.1" ]; then
        print_warning "Port $(get_container_port "$sitename") still answers on every interface, around this proxy, until the site is rebuilt with install.sh, which publishes a site with a domain on 127.0.0.1 only."
    fi
}

#==============================================================================
# COMMAND: CLEAR
#==============================================================================

cmd_clear() {
    local sitename="${1:-}"

    if [ -z "$sitename" ]; then
        print_error "Usage: $0 clear SITENAME"
        exit 3
    fi

    local kind
    kind="$(site_kind "$sitename")"
    if [ -z "$kind" ]; then
        print_error "Site not found: $sitename (a container site must be running)"
        exit 2
    fi
    [ "$kind" = "baremetal" ] && assert_baremetal_vhost_is_ours "$sitename"

    local old_domain backup
    old_domain="$(conf_domain "$(site_conf "$sitename")")"
    [ -n "$old_domain" ] || old_domain="$(conf_domain "$(legacy_conf "$sitename")")"
    backup="$(create_backup "$sitename")"
    print_info "Saved the current configuration in ${backup}"

    # What an install with no domain records: this machine's address.
    local address
    address="$(no_domain_address)"

    if [ "$kind" = "container" ]; then
        # A container with no domain has no proxy: it is reached at its port.
        retire_legacy_proxy "$sitename" "$backup"
        if [ -f "$(site_conf "$sitename")" ]; then
            a2dissite "${sitename}.conf" > /dev/null 2>&1 || true
            mv "$(site_conf "$sitename")" "${backup}/${sitename}.conf.removed"
            rm -f "${AVAILABLE}/.${sitename}.conf.rendered"
        fi
    else
        # A bare-metal site with no domain is served at the machine's
        # address, as an install with no domain serves it.
        install_site_vhost "$sitename" "$kind" "$address"
    fi
    reload_apache

    settle_site_identity "$sitename" "$kind" "$address"
    settle_certificate "$sitename" "$kind" "$address" "$old_domain" false

    if [ "$kind" = "container" ]; then
        local port
        port="$(get_container_port "$sitename")"
        if [ "$(get_container_bind "$sitename")" = "127.0.0.1" ]; then
            print_warning "Domain cleared. Port $port is published on 127.0.0.1 only, so nothing off this machine reaches the site now. Set a domain again, or rebuild the site with install.sh without one to publish it on every interface."
        else
            print_success "Domain cleared. Site reachable at http://${address}:${port}/"
        fi
    else
        print_success "Domain cleared. Site reachable at http://${address}/"
    fi
}

#==============================================================================
# COMMAND: ROLLBACK
#==============================================================================

cmd_rollback() {
    local sitename="${1:-}"

    if [ -z "$sitename" ]; then
        print_error "Usage: $0 rollback SITENAME"
        exit 3
    fi

    local kind
    kind="$(site_kind "$sitename")"
    if [ -z "$kind" ]; then
        print_error "Site not found: $sitename (a container site must be running)"
        exit 2
    fi

    local base="/var/www/html/${sitename}/backups/domain"
    local latest
    latest="$(ls -1d "$base"/*/ 2>/dev/null | grep -v '\.restored/$' | sort | tail -1 || true)"
    latest="${latest%/}"
    if [ -z "$latest" ]; then
        # An earlier version kept loose copies (<site>.conf.<time>, and
        # <site>-proxy.conf.<time> beside it); gather the newest into a set.
        local stamp
        stamp="$(ls -1 "$base"/"${sitename}".conf.* "$base"/"${sitename}"-proxy.conf.* 2>/dev/null \
            | sed -n 's/.*\.conf\.\([0-9_]*\)$/\1/p' | sort | tail -1 || true)"
        if [ -n "$stamp" ]; then
            latest="${base}/${stamp}"
            mkdir -p "$latest"
            local f
            for f in "${sitename}.conf" "${sitename}-proxy.conf"; do
                [ -f "${base}/${f}.${stamp}" ] && mv "${base}/${f}.${stamp}" "${latest}/${f}"
            done
            [ -f "${latest}/${sitename}.conf" ] && touch "${latest}/.enabled"
        fi
    fi
    if [ -z "$latest" ]; then
        print_error "No saved configuration for $sitename in $base"
        exit 1
    fi
    print_info "Restoring the configuration saved in ${latest}"

    local current_domain
    current_domain="$(conf_domain "$(site_conf "$sitename")")"

    # Put the set back exactly: the vhost and its render record, and any
    # earlier version's proxy files that were in place then.
    local f
    for f in "${sitename}.conf" ".${sitename}.conf.rendered" "${sitename}-proxy.conf" "${sitename}-proxy-le-ssl.conf"; do
        if [ -f "${latest}/${f}" ]; then
            cp -p "${latest}/${f}" "${AVAILABLE}/${f}"
        elif [ "$f" != "${sitename}.conf" ]; then
            if [ -f "${AVAILABLE}/${f}" ]; then
                a2dissite "$f" > /dev/null 2>&1 || true
                rm -f "${AVAILABLE}/${f}"
            fi
        fi
    done
    if [ -f "${latest}/${sitename}.conf" ]; then
        if [ -f "${latest}/.enabled" ]; then
            a2ensite "${sitename}.conf" > /dev/null 2>&1 || true
        else
            a2dissite "${sitename}.conf" > /dev/null 2>&1 || true
        fi
    elif [ -f "$(site_conf "$sitename")" ]; then
        # There was no vhost at <site>.conf then (a container with no domain,
        # or one served by an earlier version's proxy file).
        a2dissite "${sitename}.conf" > /dev/null 2>&1 || true
        rm -f "$(site_conf "$sitename")" "${AVAILABLE}/.${sitename}.conf.rendered"
    fi
    for f in "${sitename}-proxy.conf" "${sitename}-proxy-le-ssl.conf"; do
        [ -f "${AVAILABLE}/${f}" ] && a2ensite "$f" > /dev/null 2>&1 || true
    done

    if ! apache2ctl configtest > /dev/null 2>&1; then
        print_error "Apache configuration error after restore; the files are in place and Apache was not reloaded."
        exit 4
    fi
    systemctl reload apache2 2>/dev/null || apache2ctl graceful

    # A set saved by an earlier version carries no render record; the
    # converger adopts the restored file now (or names what is in it).
    if [ "$kind" = "baremetal" ] && [ ! -f "${AVAILABLE}/.${sitename}.conf.rendered" ]; then
        bash "$TOOLS_DIR/render_vhost.sh" "$sitename" "/var/www/html/${sitename}" || true
    fi

    local restored_domain
    restored_domain="$(conf_domain "$(site_conf "$sitename")")"
    [ -n "$restored_domain" ] || restored_domain="$(conf_domain "$(legacy_conf "$sitename")")"
    [ -n "$restored_domain" ] || restored_domain="$(no_domain_address)"
    settle_site_identity "$sitename" "$kind" "$restored_domain"
    settle_certificate "$sitename" "$kind" "$restored_domain" "$current_domain" false

    # A restored set is used once: the next rollback goes one step further back.
    mv "$latest" "${latest}.restored"
    print_success "Configuration restored from ${latest##*/} (${restored_domain})"
}

#==============================================================================
# MAIN
#==============================================================================

show_usage() {
    echo "Usage: $0 COMMAND SITENAME [OPTIONS]"
    echo ""
    echo "Commands:"
    echo "  set SITENAME DOMAIN [--no-ssl]  Assign or change the domain"
    echo "  clear SITENAME                  Remove the domain"
    echo "  status SITENAME                 Show the current configuration"
    echo "  rollback SITENAME               Restore the configuration before the last set or clear;"
    echo "                                  each rollback steps one further back, never forward"
    echo ""
    echo "Examples:"
    echo "  $0 set mysite example.com"
    echo "  $0 set mysite example.com --no-ssl"
    echo "  $0 status mysite"
    echo "  $0 clear mysite"
}

# Check for root
if [ "$EUID" -ne 0 ]; then
    print_error "This script must be run as root"
    exit 1
fi

# Parse command
COMMAND="${1:-}"
shift || true

case "$COMMAND" in
    set)
        cmd_set "$@"
        ;;
    clear)
        cmd_clear "$@"
        ;;
    status)
        cmd_status "$@"
        ;;
    rollback)
        cmd_rollback "$@"
        ;;
    -h|--help|help|"")
        show_usage
        exit 0
        ;;
    *)
        print_error "Unknown command: $COMMAND"
        show_usage
        exit 3
        ;;
esac
