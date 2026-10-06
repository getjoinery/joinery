#!/usr/bin/env bash
#
# _site_state.sh - the quiet state: a site that runs nothing and sends nothing,
# enforced by the machine, never by the site's code (specs/site_copy.md WP5).
#
# Version: 1.5 - /.joinery-look/{secret}/key sets the look cookie and lands on /copy-key: a copy
#               waiting for its recovery key is opened at its key page, not at a home page that
#               has no accounts yet. Both look paths are matched exactly.
# Version: 1.4 - the firewall rule lets the web user and Postfix answer a connection someone else
#               opened (ct direction reply). It rejected every packet they sent off the machine,
#               replies included, so no visitor reached a quiet site at all: not its 503, not the
#               owner's look, not Postfix's deferral (found on the copy's first live run).
# Version: 1.3 - a copy records its source's agent key (copy_of_key) beside copy_of; clearing removes it
#               and copy_import's high-water mark with the vouched record (site_copy.md WP4)
# Version: 1.2 - clearing removes a copy's vouched record with its copy_of (site_copy.md WP2)
# Version: 1.1 - review: the boot unit orders after nftables.service and firewalld.service (a stock
#               nftables.conf flushes the ruleset); a copy_of record always means a copy
# Version: 1.0
#
# One root-owned file, /etc/joinery/sites/{site}/state, holds `quiet copy` or
# `quiet switchover`, or is absent (a live site). While it exists:
#
#   * the web user (and Postfix's own client) cannot open a connection off the
#     machine: an nftables table of its own, inet joinery_site_state, rejects
#     their outgoing traffic except over loopback. It is loaded at boot, before
#     the web server starts, by joinery-site-state.service;
#   * Apache answers every request 503, from a drop-in in conf-enabled. Under
#     `copy` a per-copy secret cookie lets the owner look; the path
#     /.joinery-look/{secret} sets it and lands on the site's home page, and
#     /.joinery-look/{secret}/key sets it and lands on the copy's key page
#     (/copy-key). Under `switchover` everyone gets the
#     maintenance page;
#   * the site's cron file, and certbot's if there is one, are held in
#     {state dir}/held and nothing writes them back; certbot's timer is off;
#   * every Postfix smtpd service answers 4xx (smtpd_client_restrictions =
#     defer), so senders keep their mail and retry.
#
# Everything quiet took away is recorded under {state dir}/held, and clearing
# puts back exactly that: an owner's edited cron file comes back as it was, and
# a timer that was off before stays off.
#
# Two callers, and nothing else reads the state: the host converger's gate
# (_plugin_installers_start.sh), which asserts the measures every minute and
# runs no installer while the state exists, and clears leftovers once it is
# gone; and site_quiet.sh, which sets and clears `quiet switchover` for the
# agent. The dormant install writes `quiet copy` and leaves the rest to the gate.
#
# A test points /etc at a fixture with JOINERY_SITE_STATE_ROOT, and only an
# unprivileged run may. Without it, an unprivileged run has nothing it can
# assert, and site_state_init says so by returning 1.
#
# Sourced, never executed:  . "${TOOLS_DIR}/_site_state.sh"

# The one table, the one unit. One bare-metal site per machine, so a quiet site
# is a quiet machine; the names carry no site.
SS_NFT_TABLE="joinery_site_state"
SS_UNIT_NAME="joinery-site-state.service"
SS_LOOK_PREFIX="/.joinery-look/"
SS_COOKIE="joinery_look"

ss_say()  { echo "quiet state: $*"; }
ss_warn() { echo "quiet state: WARNING - $*" >&2; }

# site_state_init SITENAME -> 0 ready, 1 nothing this run can do.
site_state_init() {
    SS_SITE="$1"
    SS_ROOT=""
    if [[ "$(id -u)" == "0" ]]; then
        [[ -z "${JOINERY_SITE_STATE_ROOT:-}" ]] || ss_say "JOINERY_SITE_STATE_ROOT is set but this is root - hook ignored"
    elif [[ -n "${JOINERY_SITE_STATE_ROOT:-}" ]]; then
        SS_ROOT="${JOINERY_SITE_STATE_ROOT%/}"
    else
        return 1
    fi
    if [[ ! "${SS_SITE}" =~ ^[A-Za-z0-9_-]+$ ]]; then
        ss_warn "'${SS_SITE}' is not a site name"
        return 1
    fi
    SS_SITES_DIR="${SS_ROOT}/etc/joinery/sites"
    SS_DIR="${SS_SITES_DIR}/${SS_SITE}"
    SS_STATE="${SS_DIR}/state"
    SS_HELD="${SS_DIR}/held"
    SS_NFT_FILE="${SS_DIR}/quiet.nft"
    SS_LOOK_FILE="${SS_DIR}/look_secret"
    SS_APACHE_AVAILABLE="${SS_ROOT}/etc/apache2/conf-available/joinery-quiet-${SS_SITE}.conf"
    SS_APACHE_ENABLED="${SS_ROOT}/etc/apache2/conf-enabled/joinery-quiet-${SS_SITE}.conf"
    SS_CRON_DIR="${SS_ROOT}/etc/cron.d"
    SS_UNIT_FILE="${SS_ROOT}/etc/systemd/system/${SS_UNIT_NAME}"
    return 0
}

# The reason, or nothing for a live site. A file that says anything else is
# still a quiet site: it reads as `switchover`, the stricter of the two, since
# nobody can look behind it - unless a copy_of record is beside it, which
# always means a copy (the agent reads it the same way).
site_state_read() {
    [[ -f "${SS_STATE}" ]] || return 0
    local line
    line="$(head -1 "${SS_STATE}" 2>/dev/null | tr -d '\r')"
    # A copy_of record makes this a copy whatever the line says: a damaged
    # line must never turn a copy into something a bare off clears.
    if [[ -e "${SS_DIR}/copy_of" ]]; then
        [[ "${line}" == "quiet copy" ]] || ss_warn "${SS_STATE} says '${line:0:60}', but a copy_of record is beside it - held as quiet copy"
        echo "copy"
        return 0
    fi
    case "${line}" in
        "quiet copy")       echo "copy" ;;
        "quiet switchover") echo "switchover" ;;
        *)
            ss_warn "${SS_STATE} says '${line:0:60}', which is no state - held as quiet switchover"
            echo "switchover"
            ;;
    esac
}

# site_state_write REASON [COPY_OF [COPY_OF_KEY]] - the file itself, and a
# copy's record of whose copy it is and the key its source signs with.
site_state_write() {
    local reason="$1" copy_of="${2:-}" copy_of_key="${3:-}"
    mkdir -p "${SS_DIR}" && chmod 700 "${SS_SITES_DIR}" "${SS_DIR}" 2>/dev/null
    printf 'quiet %s\n' "${reason}" > "${SS_STATE}.tmp" && chmod 644 "${SS_STATE}.tmp" \
        && mv -f "${SS_STATE}.tmp" "${SS_STATE}" || return 1
    if [[ -n "${copy_of}" ]]; then
        printf '%s\n' "${copy_of}" > "${SS_DIR}/copy_of" && chmod 644 "${SS_DIR}/copy_of"
    fi
    if [[ -n "${copy_of_key}" ]]; then
        printf '%s\n' "${copy_of_key}" > "${SS_DIR}/copy_of_key" && chmod 644 "${SS_DIR}/copy_of_key"
    fi
}

# Anything a quiet state left behind, with the state itself gone.
site_state_leftovers() {
    [[ -e "${SS_HELD}" || -e "${SS_NFT_FILE}" || -e "${SS_APACHE_AVAILABLE}" \
       || -L "${SS_APACHE_ENABLED}" || -e "${SS_APACHE_ENABLED}" || -e "${SS_LOOK_FILE}" ]]
}

# --- The pieces ------------------------------------------------------------

ss_uids() {
    local u out=""
    for u in www-data postfix; do
        id -u "${u}" >/dev/null 2>&1 && out="${out:+${out}, }$(id -u "${u}")"
    done
    echo "${out}"
}

ss_render_nft() {
    local uids
    uids="$(ss_uids)"
    [[ -n "${uids}" ]] || return 1
    cat <<EOF
# The quiet state of ${SS_SITE} (specs/site_copy.md WP5). Written by _site_state.sh;
# loaded at boot by ${SS_UNIT_NAME} and every minute by the host converger.
# The web user and Postfix's client reach nothing but this machine. Root does:
# the agent, the backup run and a restore still reach storage. Replies to a
# connection someone else opened pass, so a visitor sees the 503 (or the owner
# the look) and a sending server sees Postfix's deferral; a connection the web
# user or Postfix opens itself never does.
table inet ${SS_NFT_TABLE}
delete table inet ${SS_NFT_TABLE}
table inet ${SS_NFT_TABLE} {
    chain output {
        type filter hook output priority 0; policy accept;
        oifname "lo" accept
        ct direction reply accept
        meta skuid { ${uids} } meta l4proto tcp reject with tcp reset
        meta skuid { ${uids} } reject with icmpx type admin-prohibited
    }
}
EOF
}

ss_render_unit() {
    local nft
    nft="$(command -v nft 2>/dev/null || echo /usr/sbin/nft)"
    cat <<EOF
# Written by _site_state.sh (specs/site_copy.md WP5): a quiet site's firewall
# rule is in place before the web server, cron or Postfix starts.
[Unit]
Description=Joinery quiet state: hold a quiet site's outgoing-traffic rule from boot
DefaultDependencies=no
After=local-fs.target nftables.service firewalld.service
Before=network-pre.target apache2.service cron.service postfix.service
Wants=network-pre.target

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=/bin/sh -c 'for f in ${SS_SITES_DIR}/*/quiet.nft; do [ -f "\$f" ] || continue; ${nft} -f "\$f" || exit 1; done'

[Install]
WantedBy=multi-user.target
EOF
}

ss_look_secret() {
    local s=""
    [[ -f "${SS_LOOK_FILE}" ]] && s="$(head -1 "${SS_LOOK_FILE}" 2>/dev/null)"
    if [[ ! "${s}" =~ ^[0-9a-f]{32}$ ]]; then
        s="$(od -An -tx1 -N16 /dev/urandom | tr -d ' \n')"
        ( umask 077; printf '%s\n' "${s}" > "${SS_LOOK_FILE}" ) || return 1
    fi
    echo "${s}"
}

ss_render_apache() {
    local reason="$1" secret="${2:-}"
    echo "# The quiet state of ${SS_SITE}: ${reason} (specs/site_copy.md WP5). Written by"
    echo "# _site_state.sh; removed when the state clears. Every host on this machine"
    echo "# answers 503: a <Location> here is merged after every vhost's <Directory>."
    if [[ "${reason}" == "copy" ]]; then
        cat <<EOF
<LocationMatch "^/\\.joinery-look/${secret}\$">
    Header always set Set-Cookie "${SS_COOKIE}=${secret}; Path=/; HttpOnly; SameSite=Lax"
    Redirect 303 /
</LocationMatch>
<LocationMatch "^/\\.joinery-look/${secret}/key\$">
    Header always set Set-Cookie "${SS_COOKIE}=${secret}; Path=/; HttpOnly; SameSite=Lax"
    Redirect 303 /copy-key
</LocationMatch>
<LocationMatch "^/(?!\\.joinery-look/${secret}(?:/key)?\$)">
    <If "! %{HTTP_COOKIE} =~ /(?:^|;\\s*)${SS_COOKIE}=${secret}(?:;|\$)/">
        Header always set Retry-After "3600"
        Redirect 503
    </If>
</LocationMatch>
ErrorDocument 503 "<!doctype html><title>Not open</title><h1>Not open</h1><p>This server holds a copy of a site. It is not open to visitors.</p>"
EOF
    else
        cat <<EOF
<Location "/">
    Header always set Retry-After "300"
    Redirect 503
</Location>
ErrorDocument 503 "<!doctype html><title>Moving</title><h1>Back in a few minutes</h1><p>This site is moving to a new server. Please try again in a few minutes.</p>"
EOF
    fi
}

# Write FILE from stdin when it differs; 0 = changed, 1 = unchanged, 2 = failed.
ss_write_if_changed() {
    local file="$1" mode="$2" tmp
    tmp="$(mktemp "${file}.XXXXXX" 2>/dev/null)" || return 2
    cat > "${tmp}" || { rm -f "${tmp}"; return 2; }
    if [[ -f "${file}" ]] && cmp -s "${tmp}" "${file}"; then
        rm -f "${tmp}"
        return 1
    fi
    chmod "${mode}" "${tmp}" && mv -f "${tmp}" "${file}" || { rm -f "${tmp}"; return 2; }
    return 0
}

ss_apache_reload() {
    command -v apache2ctl >/dev/null 2>&1 || return 0
    if ! apache2ctl configtest >/dev/null 2>&1; then
        ss_warn "apache2ctl configtest fails - Apache not reloaded: $(apache2ctl configtest 2>&1 | tail -1)"
        return 1
    fi
    systemctl reload apache2 2>/dev/null || ss_warn "Apache did not reload"
}

# Postfix's smtpd services: every master.cf entry whose command is smtpd,
# postscreen's `smtpd pass` included. "name/type" per line.
ss_smtpd_services() {
    postconf -M 2>/dev/null | awk '$8 == "smtpd" { print $1 "/" $2 }'
}

# --- Assert ----------------------------------------------------------------

# site_state_assert REASON -> 0 every measure holds, 1 one did not (said why).
site_state_assert() {
    local reason="$1" rc=0 changed secret svc now name f
    mkdir -p "${SS_HELD}/cron.d" 2>/dev/null && chmod 700 "${SS_DIR}" "${SS_HELD}" 2>/dev/null

    # Outgoing traffic. First: it is the measure that makes the rest safe.
    if ! command -v nft >/dev/null 2>&1 && [[ -z "${SS_ROOT}" ]]; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y -q nftables >/dev/null 2>&1 \
            || ss_warn "nftables is not installed and could not be"
    fi
    if [[ -z "$(ss_uids)" ]]; then
        ss_warn "no web user on this machine - no firewall rule to write"
        rc=1
    fi
    ss_render_nft | ss_write_if_changed "${SS_NFT_FILE}" 600
    case $? in
        0) ss_say "firewall rule written" ;;
        2) ss_warn "cannot write ${SS_NFT_FILE}"; rc=1 ;;
    esac
    # Loaded every time: a table deleted by hand is back within the minute.
    if ! nft -f "${SS_NFT_FILE}" 2>/dev/null; then
        ss_warn "nft could not load ${SS_NFT_FILE} - the web user can still reach the network"
        rc=1
    fi
    ss_render_unit | ss_write_if_changed "${SS_UNIT_FILE}" 644
    changed=$?
    [[ "${changed}" == "2" ]] && { ss_warn "cannot write ${SS_UNIT_FILE}"; rc=1; }
    if [[ "${changed}" == "0" ]] || ! systemctl is-enabled --quiet "${SS_UNIT_NAME}" 2>/dev/null; then
        systemctl daemon-reload 2>/dev/null
        systemctl enable "${SS_UNIT_NAME}" >/dev/null 2>&1 || { ss_warn "cannot enable ${SS_UNIT_NAME}"; rc=1; }
    fi

    # Web.
    if [[ -d "${SS_ROOT}/etc/apache2/conf-available" ]]; then
        secret=""
        if [[ "${reason}" == "copy" ]]; then
            secret="$(ss_look_secret)" || { ss_warn "cannot write ${SS_LOOK_FILE}"; rc=1; }
        fi
        ss_render_apache "${reason}" "${secret}" | ss_write_if_changed "${SS_APACHE_AVAILABLE}" 600
        changed=$?
        [[ "${changed}" == "2" ]] && { ss_warn "cannot write ${SS_APACHE_AVAILABLE}"; rc=1; }
        if [[ ! -L "${SS_APACHE_ENABLED}" ]]; then
            rm -f "${SS_APACHE_ENABLED}"
            ln -s "../conf-available/joinery-quiet-${SS_SITE}.conf" "${SS_APACHE_ENABLED}" && changed=0
        fi
        if [[ "${changed}" == "0" ]]; then
            ss_say "web drop-in written (${reason})"
            ss_apache_reload || rc=1
        fi
    fi

    # Cron: the site's and certbot's files, held. A file that reappears while
    # one is already held was written by someone after the fact; the held one
    # is what clearing puts back.
    for name in "joinery-${SS_SITE}" certbot; do
        f="${SS_CRON_DIR}/${name}"
        [[ -f "${f}" ]] || continue
        if [[ -f "${SS_HELD}/cron.d/${name}" ]]; then
            rm -f "${f}"
        else
            mv -f "${f}" "${SS_HELD}/cron.d/${name}" || { ss_warn "cannot hold ${f}"; rc=1; continue; }
        fi
        ss_say "cron file ${name} held"
    done

    # certbot's timer. Recorded only when it was on, so clearing never turns
    # on a timer somebody had turned off.
    if systemctl is-enabled --quiet certbot.timer 2>/dev/null || systemctl is-active --quiet certbot.timer 2>/dev/null; then
        if systemctl is-enabled --quiet certbot.timer 2>/dev/null; then
            : > "${SS_HELD}/certbot.timer"
        fi
        systemctl disable --now certbot.timer >/dev/null 2>&1 && ss_say "certbot timer off" \
            || { ss_warn "cannot stop certbot.timer"; rc=1; }
    fi

    # Postfix: each smtpd service defers, and its own setting before is kept.
    if command -v postconf >/dev/null 2>&1; then
        changed=1
        while read -r svc; do
            [[ -n "${svc}" ]] || continue
            now="$(postconf -Ph "${svc}/smtpd_client_restrictions" 2>/dev/null)"
            [[ "${now}" == "defer" ]] && continue
            if ! awk -F'\t' -v s="${svc}" '$1 == s { f = 1 } END { exit !f }' "${SS_HELD}/postfix" 2>/dev/null; then
                printf '%s\t%s\n' "${svc}" "${now:--}" >> "${SS_HELD}/postfix"
            fi
            postconf -P "${svc}/smtpd_client_restrictions=defer" 2>/dev/null \
                && changed=0 || { ss_warn "cannot defer Postfix ${svc}"; rc=1; }
        done < <(ss_smtpd_services)
        if [[ "${changed}" == "0" ]]; then
            ss_say "Postfix defers inbound mail"
            postfix status >/dev/null 2>&1 && { postfix reload >/dev/null 2>&1 || ss_warn "Postfix did not reload"; }
        fi
    fi
    return "${rc}"
}

# site_state_wait_cli_php SECONDS -> 0 when the web user runs no command-line
# PHP, 1 when some still does at the end (their pids said). Detached workers
# and scheduled-task runs are command-line PHP; the FPM pool is always there
# and sits idle behind the drop-in. A generic wait, not a list of workers.
site_state_wait_cli_php() {
    local limit="$1" waited=0 pids
    while :; do
        pids="$(pgrep -u www-data -x 'php[0-9.]*' 2>/dev/null | tr '\n' ' ')"
        [[ -z "${pids// /}" ]] && return 0
        if (( waited >= limit )); then
            ss_warn "the web user still runs command-line PHP after ${limit}s (pids ${pids% })"
            return 1
        fi
        sleep 2
        waited=$((waited + 2))
    done
}

# --- Clear -----------------------------------------------------------------

# site_state_clear -> 0 everything quiet took away is back, 1 not all of it
# (what remains is left in place for the next converger tick to retry).
site_state_clear() {
    local rc=0 name f svc prior others
    if [[ -e "${SS_APACHE_AVAILABLE}" || -L "${SS_APACHE_ENABLED}" || -e "${SS_APACHE_ENABLED}" ]]; then
        rm -f "${SS_APACHE_ENABLED}" "${SS_APACHE_AVAILABLE}"
        ss_apache_reload || rc=1
        ss_say "web drop-in removed"
    fi

    if [[ -e "${SS_NFT_FILE}" ]]; then
        if ! nft list table inet "${SS_NFT_TABLE}" >/dev/null 2>&1 \
           || nft delete table inet "${SS_NFT_TABLE}" 2>/dev/null; then
            rm -f "${SS_NFT_FILE}"
            ss_say "firewall rule removed"
        else
            ss_warn "cannot remove the firewall rule"; rc=1
        fi
    fi
    others="$(ls "${SS_SITES_DIR}"/*/quiet.nft 2>/dev/null)"
    if [[ -z "${others}" && -e "${SS_UNIT_FILE}" ]]; then
        systemctl disable "${SS_UNIT_NAME}" >/dev/null 2>&1
        rm -f "${SS_UNIT_FILE}"
        systemctl daemon-reload 2>/dev/null
    fi

    for name in "joinery-${SS_SITE}" certbot; do
        f="${SS_HELD}/cron.d/${name}"
        [[ -f "${f}" ]] || continue
        if [[ -e "${SS_CRON_DIR}/${name}" ]]; then
            rm -f "${f}"
        else
            mv -f "${f}" "${SS_CRON_DIR}/${name}" || { ss_warn "cannot put back cron file ${name}"; rc=1; continue; }
        fi
        ss_say "cron file ${name} back"
    done

    if [[ -e "${SS_HELD}/certbot.timer" ]]; then
        if systemctl enable --now certbot.timer >/dev/null 2>&1; then
            rm -f "${SS_HELD}/certbot.timer"
            ss_say "certbot timer on"
        else
            ss_warn "cannot start certbot.timer"; rc=1
        fi
    fi

    if [[ -f "${SS_HELD}/postfix" ]]; then
        local failed=0
        while IFS=$'\t' read -r svc prior; do
            [[ -n "${svc}" ]] || continue
            if [[ "${prior}" == "-" ]]; then
                postconf -PX "${svc}/smtpd_client_restrictions" 2>/dev/null || failed=1
            else
                postconf -P "${svc}/smtpd_client_restrictions=${prior}" 2>/dev/null || failed=1
            fi
        done < "${SS_HELD}/postfix"
        if [[ "${failed}" == "0" ]]; then
            rm -f "${SS_HELD}/postfix"
            postfix status >/dev/null 2>&1 && { postfix reload >/dev/null 2>&1 || ss_warn "Postfix did not reload"; }
            ss_say "Postfix accepts mail"
        else
            ss_warn "cannot put Postfix's smtpd settings back"; rc=1
        fi
    fi

    if [[ "${rc}" == "0" ]]; then
        rm -rf "${SS_HELD:?}"
        rm -f "${SS_LOOK_FILE}" "${SS_DIR}/copy_of" "${SS_DIR}/copy_of_key" "${SS_DIR}/vouched" \
              "${SS_DIR}/copy_import_issued"
        rmdir "${SS_DIR}" 2>/dev/null
    fi
    return "${rc}"
}
