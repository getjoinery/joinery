#!/usr/bin/env bash
#
# host_housekeeping.sh - keep the guard in front of this host correct: fail2ban
# configured and RUNNING, and Apache logging the real client, so that a ban
# lands on an attacker and never on a proxy.
#
# Version: 1.10 - A container's pg_hba admits loopback and its gateway, and nothing else: the
#                declared lines are gone (specs/dns_resolvers_read_over_https.md WP7).
#                scrolldaddy's DNS resolvers read the site over HTTPS, so no machine reads
#                a Joinery database over the network. A leftover config/postgres_access.conf
#                gets one warning that it is not read.
# Version: 1.9 - Every certbot lineage the machine renews through Apache is healed at every
#                converge (host_files_heal_renewal_confs): installer = None and a reload
#                hook, so a renewal never edits a rendered vhost. render_vhost.sh healed
#                only its own vhost's name and never ran for a Docker host's proxy
#                vhosts (specs/fleet_ubuntu_2604_postgres_upgrade.md B23).
# Version: 1.8 - A `publish <address>` line in config/postgres_access.conf is passed over: it
#                tells install.sh where the host publishes the database port, and is no
#                pg_hba line.
# Version: 1.7 - PostgreSQL answers only locally, enforced on every converge and at every
#                container start. pg_hba.conf keeps its local and loopback rules and
#                loses every rule admitting a network address. A container adds one
#                rule for the Docker host (its gateway, which reaches the site's
#                loopback-published port) and the lines its site declares in
#                config/postgres_access.conf, each checked. A standalone server's
#                listen_addresses is pinned to localhost by a conf.d drop-in.
# Version: 1.6 - An FPM php.ini that enables pdo_pgsql or pgsql while its conf.d already
#                loads that module has the line commented back out. The platform's
#                tuning wrote those lines on every install until _host_files.sh 1.1;
#                each loaded a module twice (pdo_pgsql before PDO), a startup warning
#                on every PHP start. Only those two lines, only when conf.d loads the
#                module; the rest of the file is left as it is.
# Version: 1.5 - a php.ini byte-identical to its php.ini-production is tuned too:
#                it is the untouched copy a PHP package installs, so a new PHP
#                version on the box gets the platform's settings. Any php.ini that
#                differs from the template is still never touched.
# Version: 1.4 - review 2026-09-23: the journal cap counts as present when any
#                drop-in already sets SystemMaxUse (B7); a PHP directory with no
#                php.ini-production is named and no longer fails the run.
# Version: 1.3 - Host files written when absent (_host_files.sh, shared with
#                install.sh): the event MPM's sizing, the journal's cap, and
#                PHP-FPM's php.ini rebuilt from php.ini-production. Absent-only,
#                so an owner's tuning survives; moving a file aside is the reset
#                (specs/agent_recipes_and_vocabulary.md, reclaim_managed_file).
# Version: 1.2 - `--machine ROOT`: the call the runner makes on a host with no
#                site (runner 2.17 --machine). ROOT is the agent's support
#                bundle, laid out as a site root, so the Cloudflare range list
#                is where it always is; nothing else about the run differs,
#                since fail2ban and Apache are the host's whichever tree
#                described them.
# Version: 1.1 - jail.local is ours when its head is jail.conf and the rest only
#                enables jails and sets a ban policy - whichever hand appended
#                it (docker-prod's tail is not install.sh's text); the Apache
#                jails are written only when Apache logs the real client, since
#                a jail on a log that names the peer bans the edge in front of
#                us; the proxy vhosts' logs are watched (review R1-R3).
# Version: 1.0 - The one implementation of "fail2ban is configured"
#                (specs/post_release_fleet_defects.md B2). install.sh used to
#                copy jail.conf to jail.local and append a second [sshd]
#                section; fail2ban 1.0.2 (Ubuntu 24.04) refuses a repeated
#                section, so the service had been failed since install day on
#                every 24.04 host we built, while jeremytunnell took SSH
#                password guesses with nothing in front of them.
#
# What it leaves behind, every time it runs:
#   - /etc/fail2ban/jail.d/joinery-sshd.local and, where Apache is installed
#     AND logs the real client (the remoteip step below succeeded),
#     jail.d/joinery-apache.local - written whole (tee, never tee -a), so a
#     re-run cannot append a second section. Without remoteip an Apache log
#     names the peer, and behind Cloudflare the peer is an edge: an Apache
#     jail there would ban the edge, so the drop-in is removed instead;
#   - no /etc/fail2ban/jail.local when the one on disk is jail.conf followed by
#     nothing but section headers, `enabled`, `bantime`, `findtime`,
#     `maxretry`, comments and blank lines, naming only jails the drop-ins
#     carry - the shape every hand that "enabled fail2ban" by copy-and-append
#     left behind (install.sh's block, docker-prod's hardening block). That file is the defect: its repeated
#     [sshd] is what fail2ban 1.0.2 refuses, and the drop-ins carry everything
#     it enabled. One with any other content is somebody's work: it is left
#     alone and named in the transcript, with a warning when it repeats a
#     section, because then fail2ban cannot start until a hand removes it;
#   - fail2ban enabled and active, PROVEN: the script exits 1 when the unit is
#     not active after its work, with the journal's last lines in the transcript;
#   - /etc/apache2/conf-available/joinery-remoteip.conf, enabled: mod_remoteip
#     trusts X-Forwarded-For only from Cloudflare's published edge ranges
#     (includes/cloudflare_ip_ranges.txt, the same list PHP trusts a
#     CF-Connecting-IP from) and, inside a container, from the host's reverse
#     proxy; and the `combined` / `vhost_combined` log formats record that
#     client (%a) rather than the peer (%h). A jail bans what the log names,
#     so the log must name the client - and must name it ONLY when every hop
#     is a proxy we know. A forged header on a direct connection is never
#     believed, so a ban can never be steered onto a third party.
#
# Three callers, one implementation: install.sh (host_housekeeping), the host
# timer (_plugin_installers_start.sh, CORE_INSTALLERS) on every converge, and
# the agent's fail2ban recipe (specs/agent_tier1_recipes.md). It reports sshd
# posture (password authentication, root login) and changes neither: a
# customer's authentication settings are theirs; the guard in front of them is
# ours to keep running.
#
# Contract (docs/plugin_developer_guide.md): idempotent, root, non-interactive,
# exit 0 when not applicable. Exit 1 means fail2ban should be running here and
# is not, or Apache refused the configuration (which is then put back).
#
# Usage:  host_housekeeping.sh [SITENAME] [SITE_ROOT]
#         host_housekeeping.sh --machine ROOT      (a host with no site; ROOT
#                                                   is the support bundle)
#
# Test hooks, honoured only when this is NOT root (a root run uses the real
# machine whatever the environment says, and says once that it ignored them):
#   JOINERY_HOUSEKEEPING_ROOT=<dir>      treat <dir> as / : <dir>/etc/fail2ban and
#                                        <dir>/etc/apache2 are read and written, no
#                                        system command runs (apt, systemctl,
#                                        a2enmod, fail2ban-client). <dir>/.dockerenv
#                                        makes the run believe it is in a container.
#   JOINERY_HOUSEKEEPING_NO_SYSTEMCTL=1  the real /etc, but no system command.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
say()  { echo "housekeeping: $*"; }
warn() { echo "housekeeping: WARNING - $*" >&2; }

MACHINE=0
if [[ "${1:-}" == "--machine" ]]; then
    MACHINE=1
    SITE_ROOT="${2:-}"
    [[ -n "${SITE_ROOT}" && -d "${SITE_ROOT}" ]] || { warn "--machine needs the bundle root as its argument - skipping"; exit 0; }
else
    SITE_ROOT="${2:-}"
    if [[ -z "${SITE_ROOT}" ]]; then
        SITENAME="${1:-}"
        if [[ -n "${SITENAME}" && -d "/var/www/html/${SITENAME}" ]]; then
            SITE_ROOT="/var/www/html/${SITENAME}"
        else
            SITE_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
        fi
    fi
fi
RANGES_FILE="${SITE_ROOT}/public_html/includes/cloudflare_ip_ranges.txt"

# --- mode --------------------------------------------------------------------
FS_ROOT=""
RUN_SYSTEM=1
if [[ "$(id -u)" == "0" ]]; then
    for _hook in JOINERY_HOUSEKEEPING_ROOT JOINERY_HOUSEKEEPING_NO_SYSTEMCTL; do
        if [[ -n "${!_hook:-}" ]]; then
            say "${_hook} is set but this is root - hook ignored"
            unset "${_hook}"
        fi
    done
else
    if [[ -n "${JOINERY_HOUSEKEEPING_ROOT:-}" ]]; then
        FS_ROOT="${JOINERY_HOUSEKEEPING_ROOT%/}"
        RUN_SYSTEM=0
        say "override mode: /etc is ${FS_ROOT}/etc, no system command runs"
    elif [[ -n "${JOINERY_HOUSEKEEPING_NO_SYSTEMCTL:-}" ]]; then
        RUN_SYSTEM=0
        say "no-systemctl mode: files only"
    else
        say "not root - skipping (run: sudo bash ${SCRIPT_DIR}/host_housekeeping.sh)"
        exit 0
    fi
fi

F2B_DIR="${FS_ROOT}/etc/fail2ban"
APACHE_DIR="${FS_ROOT}/etc/apache2"

IN_CONTAINER=0
[[ -f "${FS_ROOT}/.dockerenv" ]] && IN_CONTAINER=1

HAVE_SYSTEMD=0
if [[ "${RUN_SYSTEM}" == 1 ]] && [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null 2>&1; then
    HAVE_SYSTEMD=1
fi

FAILED=0
APACHE_CHANGED=0
F2B_CHANGED=0
# 1 once joinery-remoteip.conf is written, enabled and accepted by Apache: the
# logs name the real client, and only then may a jail read them.
REMOTEIP_OK=0

# Install $2 at $1 only when the content differs; echoes 1 when it wrote.
install_file() {
    local dest="$1" src="$2"
    if [[ -f "${dest}" ]] && cmp -s "${src}" "${dest}"; then
        return 1
    fi
    mkdir -p "$(dirname "${dest}")"
    cat "${src}" > "${dest}"
    chmod 644 "${dest}"
    return 0
}

# --- 1. Apache: log the real client, and only when every hop is known --------
#
# Applicable wherever /etc/apache2 exists: a bare-metal node, the Docker host
# whose Apache proxies to the containers, and the container itself (this runs
# at container start, before Apache, so a reload is skipped there and the
# configuration is read when Apache comes up).
APACHE_CONF="${APACHE_DIR}/conf-available/joinery-remoteip.conf"
if [[ -d "${APACHE_DIR}" ]]; then
    if [[ ! -f "${RANGES_FILE}" ]]; then
        warn "no Cloudflare range list at ${RANGES_FILE} - Apache logging left as it is"
        FAILED=1
    else
        RANGES="$(grep -vE '^[[:space:]]*(#|$)' "${RANGES_FILE}" | tr -d '[:blank:]')"
        if [[ -z "${RANGES}" ]]; then
            warn "${RANGES_FILE} names no range - Apache logging left as it is"
            FAILED=1
        fi
    fi
fi
if [[ -d "${APACHE_DIR}" && "${FAILED}" == 0 ]]; then
    CANDIDATE="$(mktemp)"
    {
        echo "# Written by host_housekeeping.sh (Joinery) on every run - edits here do not survive."
        echo "#"
        echo "# Apache records the real client, not the proxy in front of it, and does so"
        echo "# only when every hop between the client and this server is a proxy we know:"
        echo "# Cloudflare's published edge ranges (public_html/includes/cloudflare_ip_ranges.txt,"
        echo "# the list PHP trusts a CF-Connecting-IP from) and, inside a container, the"
        echo "# host's reverse proxy. A forwarding header on a direct connection is never"
        echo "# believed. fail2ban bans what these logs name, so this is what keeps a ban"
        echo "# on an attacker and off a proxy or a third party."
        echo "<IfModule remoteip_module>"
        echo "RemoteIPHeader X-Forwarded-For"
        if [[ "${IN_CONTAINER}" == 1 ]]; then
            echo "# The host's proxy reaches this container over the Docker bridge."
            echo "RemoteIPInternalProxy 172.17.0.0/16"
            echo "RemoteIPInternalProxy 127.0.0.1"
        fi
        while IFS= read -r range; do
            [[ -n "${range}" ]] && echo "RemoteIPTrustedProxy ${range}"
        done <<< "${RANGES}"
        echo "</IfModule>"
        echo "# The stock formats with %a (the client mod_remoteip resolved) for %h (the peer)."
        echo 'LogFormat "%v:%p %a %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\"" vhost_combined'
        echo 'LogFormat "%a %l %u %t \"%r\" %>s %O \"%{Referer}i\" \"%{User-Agent}i\"" combined'
    } > "${CANDIDATE}"

    PREVIOUS=""
    if [[ -f "${APACHE_CONF}" ]]; then
        PREVIOUS="$(mktemp)"
        cp "${APACHE_CONF}" "${PREVIOUS}"
    fi
    if install_file "${APACHE_CONF}" "${CANDIDATE}"; then
        APACHE_CHANGED=1
        say "wrote ${APACHE_CONF} ($(grep -c '^RemoteIPTrustedProxy' "${APACHE_CONF}") trusted edge ranges$([[ "${IN_CONTAINER}" == 1 ]] && echo ', container bridge internal'))"
    fi
    rm -f "${CANDIDATE}"

    if [[ "${RUN_SYSTEM}" == 1 ]]; then
        if command -v a2enmod >/dev/null 2>&1; then
            if [[ ! -e "${APACHE_DIR}/mods-enabled/remoteip.load" ]]; then
                a2enmod -q remoteip >/dev/null 2>&1 && APACHE_CHANGED=1 && say "enabled mod_remoteip"
            fi
            if [[ ! -e "${APACHE_DIR}/conf-enabled/joinery-remoteip.conf" ]]; then
                a2enconf -q joinery-remoteip >/dev/null 2>&1 && APACHE_CHANGED=1 && say "enabled joinery-remoteip.conf"
            fi
        fi
        if [[ "${APACHE_CHANGED}" == 1 ]] && command -v apache2ctl >/dev/null 2>&1; then
            if ! parse_out="$(apache2ctl -t 2>&1)"; then
                warn "Apache refuses the configuration; putting the previous one back"
                warn "  $(printf '%s' "${parse_out}" | grep -viE 'AH00558|Syntax' | head -3 | tr '\n' ' ')"
                if [[ -n "${PREVIOUS}" ]]; then
                    cat "${PREVIOUS}" > "${APACHE_CONF}"
                else
                    rm -f "${APACHE_CONF}" "${APACHE_DIR}/conf-enabled/joinery-remoteip.conf"
                fi
                FAILED=1
            elif pidof apache2 >/dev/null 2>&1; then
                if systemctl reload apache2 >/dev/null 2>&1 || apache2ctl graceful >/dev/null 2>&1; then
                    say "Apache reloaded"
                else
                    warn "Apache could not be reloaded - the configuration applies at its next restart"
                fi
            else
                say "Apache is not running here yet - the configuration is read when it starts"
            fi
        fi
        # Accepted = the module and the conf are both enabled and nothing above
        # put the previous configuration back.
        if [[ "${FAILED}" == 0 && -e "${APACHE_DIR}/mods-enabled/remoteip.load" && -e "${APACHE_DIR}/conf-enabled/joinery-remoteip.conf" ]]; then
            REMOTEIP_OK=1
        elif [[ "${FAILED}" == 0 ]]; then
            warn "mod_remoteip or joinery-remoteip.conf could not be enabled - Apache logs the peer"
            FAILED=1
        fi
    else
        # Override mode stands in for a2enconf so a second run has nothing to do.
        if [[ ! -e "${APACHE_DIR}/conf-enabled/joinery-remoteip.conf" ]]; then
            mkdir -p "${APACHE_DIR}/conf-enabled"
            ln -s ../conf-available/joinery-remoteip.conf "${APACHE_DIR}/conf-enabled/joinery-remoteip.conf"
            APACHE_CHANGED=1
            say "would run: a2enmod remoteip; a2enconf joinery-remoteip"
        fi
        REMOTEIP_OK=1
    fi
    [[ -n "${PREVIOUS}" ]] && rm -f "${PREVIOUS}"
else
    [[ -d "${APACHE_DIR}" ]] || say "no Apache on this machine - remoteip step skipped"
fi

# --- 2. fail2ban -------------------------------------------------------------
#
# A container has no systemd and runs no fail2ban; the host in front of it
# does. In override mode the temp copy of /etc/fail2ban decides.
F2B_APPLICABLE=0
if [[ "${RUN_SYSTEM}" == 1 ]]; then
    if [[ "${HAVE_SYSTEMD}" == 1 && "${IN_CONTAINER}" == 0 ]]; then
        F2B_APPLICABLE=1
        if ! command -v fail2ban-server >/dev/null 2>&1; then
            say "installing fail2ban"
            if ! DEBIAN_FRONTEND=noninteractive apt-get install -y -q fail2ban >/dev/null 2>&1; then
                warn "fail2ban could not be installed"
                FAILED=1
                F2B_APPLICABLE=0
            fi
        fi
    else
        say "no systemd here - fail2ban is the host's, not this container's"
    fi
else
    [[ -d "${F2B_DIR}" ]] && F2B_APPLICABLE=1
fi

if [[ "${F2B_APPLICABLE}" == 1 ]]; then
    mkdir -p "${F2B_DIR}/jail.d"

    CANDIDATE="$(mktemp)"
    cat > "${CANDIDATE}" <<'EOF'
# Written by host_housekeeping.sh (Joinery) on every run - edits here do not survive.
# One drop-in, written whole: the [sshd] section jail.conf already declares is
# enabled here, never repeated in a jail.local.
[sshd]
enabled = true
bantime = 1h
findtime = 10m
maxretry = 3
EOF
    if install_file "${F2B_DIR}/jail.d/joinery-sshd.local" "${CANDIDATE}"; then
        F2B_CHANGED=1
        say "wrote ${F2B_DIR}/jail.d/joinery-sshd.local"
    fi

    if [[ "${REMOTEIP_OK}" == 1 ]]; then
        cat > "${CANDIDATE}" <<'EOF'
# Written by host_housekeeping.sh (Joinery) on every run - edits here do not survive.
# The sites log under /var/www/html/<site>/logs rather than /var/log/apache2
# (a Docker host's proxy vhosts as proxy_*.log there), so every jail watches
# all of them. A ban lands on the address Apache logged, which
# conf-available/joinery-remoteip.conf makes the real client and never a
# proxy we know: direct attackers are banned, and behind an edge the ban is
# inert rather than harmful. This file exists only while that configuration
# is enabled and accepted; without it the log would name the edge.
#
# backend = auto on every jail: Ubuntu's jail.d/defaults-debian.conf sets the
# DEFAULT backend to systemd, under which a jail reads the journal and ignores
# its logpath - Apache writes files, not journal entries, so a jail left on
# the default watches nothing.
[apache-auth]
enabled = true
backend = auto
logpath = %(apache_error_log)s
          /var/www/html/*/logs/error.log
          /var/www/html/*/logs/proxy_error.log

[apache-badbots]
enabled = true
backend = auto
logpath = %(apache_access_log)s
          /var/www/html/*/logs/access.log
          /var/www/html/*/logs/proxy_access.log

[apache-noscript]
enabled = true
backend = auto
logpath = %(apache_error_log)s
          /var/www/html/*/logs/error.log
          /var/www/html/*/logs/proxy_error.log

[apache-overflows]
enabled = true
backend = auto
logpath = %(apache_error_log)s
          /var/www/html/*/logs/error.log
          /var/www/html/*/logs/proxy_error.log
EOF
        if install_file "${F2B_DIR}/jail.d/joinery-apache.local" "${CANDIDATE}"; then
            F2B_CHANGED=1
            say "wrote ${F2B_DIR}/jail.d/joinery-apache.local"
        fi
    elif [[ -f "${F2B_DIR}/jail.d/joinery-apache.local" ]]; then
        rm -f "${F2B_DIR}/jail.d/joinery-apache.local"
        F2B_CHANGED=1
        if [[ -d "${APACHE_DIR}" ]]; then
            say "removed jail.d/joinery-apache.local: Apache does not log the real client here (see above), and a jail on a log that names the peer would ban the edge in front of us"
        else
            say "removed jail.d/joinery-apache.local (no Apache on this machine)"
        fi
    elif [[ -d "${APACHE_DIR}" ]]; then
        say "Apache jails not enabled: Apache does not log the real client here (see above)"
    fi
    rm -f "${CANDIDATE}"

    # jail.local: a copy of jail.conf with an enabling block appended is the
    # defect (the copy already declares [sshd]; the block declares it again)
    # and is removed - the drop-ins carry everything it enabled. The block is
    # recognised by shape, not text: install.sh's and docker-prod's differ in
    # wording, and both are nothing but section headers, `enabled` and a ban
    # policy. Anything else is somebody's work and is left exactly as it is.
    if [[ -f "${F2B_DIR}/jail.local" ]]; then
        if [[ -f "${F2B_DIR}/jail.conf" ]]; then
            conf_size="$(stat -c %s "${F2B_DIR}/jail.conf")"
            appended=""
            if head -c "${conf_size}" "${F2B_DIR}/jail.local" | cmp -s - "${F2B_DIR}/jail.conf"; then
                appended="$(tail -c +"$((conf_size + 1))" "${F2B_DIR}/jail.local" | grep -vE '^[[:space:]]*(#|$)')"
            fi
            # Ours: every line enables a jail or sets a ban policy, and every
            # jail named is one the drop-ins above carry - a block enabling
            # anything else (nginx-http-auth, say) would be lost with the file.
            ours=0
            if [[ -n "${appended}" ]] \
                && ! printf '%s\n' "${appended}" | grep -qvE '^[[:space:]]*(\[[A-Za-z0-9_-]+\][[:space:]]*|(enabled|bantime|findtime|maxretry)[[:space:]]*=.*)$' \
                && ! printf '%s\n' "${appended}" | grep -oE '^\[[A-Za-z0-9_-]+\]' | grep -qvE '^\[(sshd|apache-auth|apache-badbots|apache-noscript|apache-overflows)\]$'; then
                ours=1
            fi
            if [[ "${ours}" == 1 ]]; then
                enabled_here="$(printf '%s\n' "${appended}" | grep -oE '^\[[A-Za-z0-9_-]+\]' | tr -d '[]' | tr '\n' ' ')"
                rm -f "${F2B_DIR}/jail.local"
                F2B_CHANGED=1
                say "removed ${F2B_DIR}/jail.local: it was jail.conf with a block enabling ${enabled_here}appended - sections jail.conf already declares, and a repeated section is what kept fail2ban from starting (the drop-ins under jail.d enable the same jails)"
            else
                say "${F2B_DIR}/jail.local was hand-edited - left as it is (the drop-ins under jail.d compose with it)"
                repeated="$(grep -oE '^\[[A-Za-z0-9_-]+\]' "${F2B_DIR}/jail.local" | sort | uniq -d | tr '\n' ' ')"
                if [[ -n "${repeated}" ]]; then
                    warn "jail.local declares ${repeated}more than once; fail2ban 1.0.2 refuses a repeated section and will not start until the file is fixed by hand"
                fi
            fi
        else
            say "${F2B_DIR}/jail.local exists and there is no jail.conf to compare it with - left as it is"
        fi
    fi

    if [[ "${RUN_SYSTEM}" == 1 ]]; then
        systemctl enable fail2ban >/dev/null 2>&1 || true
        if [[ "${F2B_CHANGED}" == 1 ]] || ! systemctl is-active --quiet fail2ban; then
            say "starting fail2ban"
            systemctl restart fail2ban >/dev/null 2>&1 || true
            sleep 2
        fi
        # PROOF, not a report of what was written: the unit is active and the
        # sshd jail answers. Otherwise the journal's last lines and exit 1.
        if systemctl is-active --quiet fail2ban; then
            status=""
            for _try in 1 2 3 4 5; do
                status="$(fail2ban-client status sshd 2>/dev/null)" && break
                sleep 1
            done
            if [[ -n "${status}" ]]; then
                say "fail2ban active; sshd jail: $(printf '%s\n' "${status}" | grep -E 'Currently banned|Total banned' | sed -E 's/^[[:space:]|`-]+//' | tr '\n' ';' | sed 's/;$//')"
            else
                warn "fail2ban is active but the sshd jail does not answer"
                fail2ban-client status 2>&1 | sed 's/^/housekeeping:   /' || true
                FAILED=1
            fi
        else
            warn "fail2ban is NOT active after configuration"
            journalctl -u fail2ban -n 15 --no-pager 2>/dev/null | sed 's/^/housekeeping:   /' >&2 || true
            FAILED=1
        fi
    else
        say "would run: systemctl enable --now fail2ban; fail2ban-client status sshd"
    fi
fi

# --- 3. sshd posture: reported, never changed ---------------------------------
if [[ "${RUN_SYSTEM}" == 1 ]] && command -v sshd >/dev/null 2>&1; then
    posture="$(sshd -T 2>/dev/null | grep -iE '^(passwordauthentication|permitrootlogin) ' | tr '\n' ' ')"
    [[ -n "${posture}" ]] && say "sshd: ${posture}(reported, not changed)"
fi

# --- 4. Host files, written when absent ---------------------------------------
# The files install.sh once wrote and nothing rewrote (specs/agent_recipes_and_
# vocabulary.md, "Host files"): the event MPM's sizing, the journal's cap, and
# PHP-FPM's php.ini. Each is written only when it is ABSENT, so an owner's
# tuning survives every converge; moving one aside (the agent's
# reclaim_managed_file does, keeping a dated copy) is how it is put back to
# the platform's definition. One definition, shared with install.sh.
if [[ -f "${SCRIPT_DIR}/_host_files.sh" ]]; then
    # shellcheck source=_host_files.sh
    . "${SCRIPT_DIR}/_host_files.sh"

    MPM_CONF="${APACHE_DIR}/mods-available/mpm_event.conf"
    if [[ -d "${APACHE_DIR}/mods-available" && ! -e "${MPM_CONF}" ]]; then
        host_files_write_mpm_event "${MPM_CONF}"
        say "wrote ${MPM_CONF} (it was absent)"
        if [[ "${RUN_SYSTEM}" == 1 ]] && command -v apache2ctl >/dev/null 2>&1; then
            if ! parse_out="$(apache2ctl -t 2>&1)"; then
                warn "Apache refuses the configuration with the new ${MPM_CONF}; removing it"
                warn "  $(printf '%s' "${parse_out}" | grep -viE 'AH00558|Syntax' | head -3 | tr '\n' ' ')"
                rm -f "${MPM_CONF}"
                FAILED=1
            elif pidof apache2 >/dev/null 2>&1; then
                apache2ctl graceful >/dev/null 2>&1 || systemctl reload apache2 >/dev/null 2>&1 || true
                say "Apache reloaded"
            fi
        fi
    fi

    # The journal's cap is present when ANY drop-in (or journald.conf itself)
    # already sets SystemMaxUse: an owner's own size-cap.conf would otherwise
    # be overridden by ours, which sorts later (review B7, 2026-09-23).
    JOURNAL_CONF="${FS_ROOT}/etc/systemd/journald.conf.d/size-limit.conf"
    JOURNAL_CAP_SET=0
    grep -qsE '^[[:space:]]*SystemMaxUse=' "${FS_ROOT}/etc/systemd/journald.conf" "${FS_ROOT}"/etc/systemd/journald.conf.d/*.conf && JOURNAL_CAP_SET=1
    if [[ "${IN_CONTAINER}" == 0 && ! -e "${JOURNAL_CONF}" && "${JOURNAL_CAP_SET}" == 0 ]] \
        && { [[ "${HAVE_SYSTEMD}" == 1 ]] || [[ -n "${FS_ROOT}" ]]; }; then
        host_files_write_journald_limit "${JOURNAL_CONF}"
        say "wrote ${JOURNAL_CONF} (it was absent): the journal is capped at 100M"
        if [[ "${HAVE_SYSTEMD}" == 1 ]]; then
            systemctl restart systemd-journald >/dev/null 2>&1 || warn "systemd-journald could not be restarted - the cap applies at its next start"
        fi
    fi

    # php.ini: rebuilt from the distribution's own production template, then
    # tuned. A version whose fpm directory exists and holds no php.ini is one
    # somebody moved aside; one with no template to rebuild from is named.
    #
    # A php.ini byte-identical to that template is tuned too. It is the
    # untouched copy a PHP package installs, which is what a new PHP version
    # on this box has - an in-place PHP upgrade would otherwise keep the
    # packaged 2M upload limit and no timezone for good. Any php.ini that
    # differs from the template, tuned by us or edited by its owner, is left
    # alone, as it always was.
    for fpm_dir in "${FS_ROOT}"/etc/php/*/fpm; do
        [[ -d "${fpm_dir}" ]] || continue
        php_ver="$(basename "$(dirname "${fpm_dir}")")"
        [[ "${php_ver}" =~ ^[0-9]+\.[0-9]+$ ]] || continue
        php_ini="${fpm_dir}/php.ini"
        production="${FS_ROOT}/usr/lib/php/${php_ver}/php.ini-production"
        # The two extension lines the platform's tuning once wrote. They are the
        # only lines of an existing php.ini this ever changes, and only while
        # conf.d loads the same module - which is what makes them a duplicate.
        if [[ -f "${php_ini}" ]]; then
            repaired=0
            for ext in pdo_pgsql pgsql; do
                if grep -qx "extension=${ext}" "${php_ini}" \
                    && compgen -G "${fpm_dir}/conf.d/*-${ext}.ini" > /dev/null; then
                    sed -i "s/^extension=${ext}\$/;extension=${ext}/" "${php_ini}"
                    repaired=1
                fi
            done
            if [[ "${repaired}" == 1 ]]; then
                say "${php_ini}: stopped loading pdo_pgsql/pgsql a second time (conf.d loads them)"
                if [[ "${HAVE_SYSTEMD}" == 1 ]]; then
                    systemctl restart "php${php_ver}-fpm" >/dev/null 2>&1 || warn "php${php_ver}-fpm could not be restarted - the change applies at its next start"
                fi
            fi
        fi
        if [[ -e "${php_ini}" ]]; then
            if [[ -f "${production}" ]] && cmp -s "${php_ini}" "${production}"; then
                host_files_tune_php_ini "${php_ini}"
                # A template the tuning finds nothing to change in stays
                # identical to it; restarting FPM for that on every converge
                # would be a restart a minute for nothing.
                if ! cmp -s "${php_ini}" "${production}"; then
                    say "tuned ${php_ini}: it was the packaged php.ini-production, untouched"
                    if [[ "${HAVE_SYSTEMD}" == 1 ]]; then
                        systemctl restart "php${php_ver}-fpm" >/dev/null 2>&1 || warn "php${php_ver}-fpm could not be restarted - the settings apply at its next start"
                    fi
                fi
            fi
            continue
        fi
        if [[ ! -f "${production}" ]]; then
            # A leftover directory of a PHP version no longer installed:
            # named, and not a failure of every converge. A reclaim of a
            # php.ini here puts its copy back.
            say "${php_ini} is absent and there is no ${production} to rebuild it from - left as it is"
            continue
        fi
        cp "${production}" "${php_ini}"
        chmod 644 "${php_ini}"
        host_files_tune_php_ini "${php_ini}"
        say "rebuilt ${php_ini} from php.ini-production with the platform's settings"
        if [[ "${HAVE_SYSTEMD}" == 1 ]]; then
            systemctl restart "php${php_ver}-fpm" >/dev/null 2>&1 || warn "php${php_ver}-fpm could not be restarted - the settings apply at its next start"
        fi
    done

    # certbot's renewal configs: every lineage renewed through Apache writes its
    # certificate and reloads, and never edits a vhost. A machine with no
    # /etc/letsencrypt (a container, a box with no certificate yet) has none.
    while IFS= read -r line; do
        say "${line}"
    done < <(host_files_heal_renewal_confs "${FS_ROOT}/etc/letsencrypt")
else
    warn "_host_files.sh is missing from ${SCRIPT_DIR} - host files not checked"
fi

# --- 5. PostgreSQL answers only locally ----------------------------------------
# A Joinery site's database is used by the site on the same machine and by
# nothing else, so nothing beyond the machine may log in to it. Enforced here
# in the configuration itself, not left to a firewall or a port binding:
#
#   - pg_hba.conf keeps its "local" rules and its loopback "host" rules and
#     loses every other "host" rule (and any include directive). On a
#     standalone server that is the whole policy.
#   - In a container, one rule is added for the Docker host: its gateway,
#     the address the host's connections to the site's loopback-published
#     database port arrive from. Other containers on the same host, and every
#     other machine, are refused. There is no way to declare another: a
#     config/postgres_access.conf is not read, and one left behind is named.
#   - A standalone server's listen_addresses is pinned to localhost by a
#     conf.d drop-in, restarting PostgreSQL only when the setting it was
#     running with was something else. A container keeps listening on its
#     own interface: that is how the host reaches it, and pg_hba decides who
#     gets in.
#
# The first rewrite keeps the original beside it as pg_hba.conf.pre-local-only.
pg_is_loopback_rule() {  # $1 address field, $2 the field after it
    case "$1" in
        127.0.0.1/32|::1/128|localhost|samehost) return 0 ;;
        127.0.0.1) [[ "$2" == "255.255.255.255" ]] && return 0 ;;
        ::1) [[ "$2" == "ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff" ]] && return 0 ;;
    esac
    return 1
}
pg_container_gateway() {
    local hex
    hex="$(awk 'NR > 1 && $2 == "00000000" { print $3; exit }' "${FS_ROOT}/proc/net/route" 2>/dev/null)"
    [[ "${hex}" =~ ^[0-9A-Fa-f]{8}$ ]] || return 1
    printf '%d.%d.%d.%d' "0x${hex:6:2}" "0x${hex:4:2}" "0x${hex:2:2}" "0x${hex:0:2}"
}
PG_ACCESS_FILE="${SITE_ROOT}/config/postgres_access.conf"
if [[ -f "${PG_ACCESS_FILE}" ]]; then
    warn "${PG_ACCESS_FILE} is not read: a Joinery database answers only its own machine; remove it"
fi
for pg_dir in "${FS_ROOT}"/etc/postgresql/*/main; do
    [[ -f "${pg_dir}/pg_hba.conf" ]] || continue
    hba="${pg_dir}/pg_hba.conf"
    pg_ver="$(basename "$(dirname "${pg_dir}")")"

    # The rules this cluster may keep: every local line, every loopback host
    # line, comments and blanks - minus the lines this section writes itself,
    # which are rebuilt below so they never accumulate.
    kept="$(mktemp)"; removed=0; method=""
    while IFS= read -r line || [[ -n "${line}" ]]; do
        [[ "${line}" == "# joinery-local-only:"* ]] && continue
        read -r f1 f2 f3 f4 f5 _ <<< "${line}"
        case "${f1}" in
            host|hostssl|hostnossl|hostgssenc|hostnogssenc)
                if pg_is_loopback_rule "${f4}" "${f5}"; then
                    printf '%s\n' "${line}" >> "${kept}"
                    if [[ -z "${method}" && "${f2}" == "all" && "${f3}" == "all" && "${f4}" == 127.0.0.1* ]]; then
                        method="$([[ "${f4}" == */* ]] && echo "${f5}" || awk '{print $6}' <<< "${line}")"
                    fi
                else
                    removed=$((removed + 1))
                fi ;;
            include|include_if_exists|include_dir)
                removed=$((removed + 1)) ;;
            *)
                printf '%s\n' "${line}" >> "${kept}" ;;
        esac
    done < "${hba}"
    [[ "${method}" == "md5" || "${method}" == "scram-sha-256" ]] || method="scram-sha-256"

    if [[ "${IN_CONTAINER}" == 1 ]]; then
        if gw="$(pg_container_gateway)"; then
            printf '# joinery-local-only: the Docker host, through the site'"'"'s loopback-published port\n' >> "${kept}"
            printf 'host    all             all             %-23s %s\n' "${gw}/32" "${method}" >> "${kept}"
        else
            warn "PostgreSQL ${pg_ver}: no default route in ${FS_ROOT}/proc/net/route, so the Docker host is not admitted"
        fi
    fi

    if ! cmp -s "${kept}" "${hba}"; then
        [[ -e "${hba}.pre-local-only" ]] || cp -p "${hba}" "${hba}.pre-local-only"
        cat "${kept}" > "${hba}"
        say "PostgreSQL ${pg_ver}: pg_hba.conf answers only locally now ($removed network rule(s) removed); the original is ${hba}.pre-local-only"
        if [[ "${RUN_SYSTEM}" == 1 ]]; then
            if [[ "${HAVE_SYSTEMD}" == 1 ]]; then
                systemctl reload postgresql >/dev/null 2>&1 || warn "PostgreSQL could not be reloaded - the rules apply at its next start"
            else
                service postgresql reload >/dev/null 2>&1 || warn "PostgreSQL could not be reloaded - the rules apply at its next start"
            fi
        fi
    fi
    rm -f "${kept}"

    # listen_addresses: a standalone server listens on localhost only.
    if [[ "${IN_CONTAINER}" == 0 ]]; then
        conf="${pg_dir}/postgresql.conf"
        dropin="${pg_dir}/conf.d/99-joinery-local-only.conf"
        want="listen_addresses = 'localhost'"
        if [[ -f "${conf}" ]] && grep -qE "^[[:space:]]*include_dir[[:space:]]*=[[:space:]]*'conf\.d'" "${conf}"; then
            # What it runs with today: the last assignment, in the order
            # PostgreSQL reads them - postgresql.conf, then conf.d, then
            # postgresql.auto.conf in the data directory (ALTER SYSTEM), which
            # is read last and so outranks the drop-in written here.
            listen_of() { sed -nE "s/^[[:space:]]*listen_addresses[[:space:]]*=[[:space:]]*'([^']*)'.*/\1/p" "$@" 2>/dev/null | tail -1; }
            data_dir="$(sed -nE "s/^[[:space:]]*data_directory[[:space:]]*=[[:space:]]*'([^']*)'.*/\1/p" "${conf}" | tail -1)"
            auto="${FS_ROOT}${data_dir}/postgresql.auto.conf"
            auto_listen=""
            [[ -n "${data_dir}" && -r "${auto}" ]] && auto_listen="$(listen_of "${auto}")"
            current="$(listen_of "${conf}" $(ls -1 "${pg_dir}"/conf.d/*.conf 2>/dev/null | sort))"
            [[ -n "${auto_listen}" ]] && current="${auto_listen}"
            is_local() { case "$1" in ""|localhost|127.0.0.1|"localhost,::1"|"127.0.0.1,::1"|"::1") return 0 ;; esac; return 1; }
            if [[ "$(cat "${dropin}" 2>/dev/null)" != "# Managed by host_housekeeping.sh: PostgreSQL answers only locally."$'\n'"${want}" ]]; then
                mkdir -p "${pg_dir}/conf.d"
                printf '# Managed by host_housekeeping.sh: PostgreSQL answers only locally.\n%s\n' "${want}" > "${dropin}"
                chmod 644 "${dropin}"
                say "PostgreSQL ${pg_ver}: wrote ${dropin}"
                if ! is_local "${current}" && [[ -z "${auto_listen}" ]]; then
                    say "PostgreSQL ${pg_ver} was listening on '${current}': restarting it on localhost only"
                    if [[ "${RUN_SYSTEM}" == 1 && "${HAVE_SYSTEMD}" == 1 ]]; then
                        systemctl restart postgresql >/dev/null 2>&1 || { warn "PostgreSQL could not be restarted"; FAILED=1; }
                    fi
                fi
            fi
            if [[ -n "${auto_listen}" ]] && ! is_local "${auto_listen}"; then
                warn "PostgreSQL ${pg_ver}: ALTER SYSTEM set listen_addresses = '${auto_listen}' (${auto}), which outranks ${dropin} - run ALTER SYSTEM RESET listen_addresses and restart it"
                FAILED=1
            fi
        else
            warn "PostgreSQL ${pg_ver}: ${conf} does not include conf.d, so listen_addresses is not pinned - check it by hand"
        fi
    fi
done

if [[ "${FAILED}" == 1 ]]; then
    exit 1
fi
exit 0
