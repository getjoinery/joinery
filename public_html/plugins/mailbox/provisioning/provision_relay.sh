#!/usr/bin/env bash
#
# provision_relay.sh - installer + configurator for a HARDENED INGEST RELAY.
#
# Sibling of install_email.sh (colocated mode). This builds the minimal, hardened,
# disposable VPS that fronts the public MX for a relay-fronted deployment
# (specs/inbound_email_hardened_ingest_relay_executor.md): Postfix + verify
# milters + the Go sealing binary + WireGuard, and NOTHING else — no PHP, no
# database, no web, no application. It accepts mail, verifies it, seals it to the
# recipient's public key at acceptance, and spools ciphertext; each tenant's
# Joinery box dials out over WireGuard and pulls its own sealed blobs.
#
# Version: 2.9 - IDEMPOTENCY PASS (specs/agent_machine_posture_and_relay_converge.md
#                §5.4). A second run on an already-configured relay changes nothing
#                and restarts nothing, which is the precondition for this script
#                being the reconciler behind a scheduled relay_converge. Six things
#                were fixed. The firewall is converged rule by rule instead of
#                'ufw --force reset', which wiped every rule each run - including,
#                during a rebuild, the rebuild's own 'ufw deny 25/tcp', so port 25
#                re-opened while the spool was carried aside. Every service action
#                is now conditional on a configuration THIS RUN changed. The Go
#                toolchain is gone: relay-sealer arrives PREBUILT under bin/, with a
#                loud failure when it is absent. wg0.conf's [Peer] list is derived
#                from the tenant registry, so a rotated key replaces its predecessor
#                instead of leaving a dead stanza claiming the same tunnel address.
#                /etc/default/opendkim and opendmarc are written only when the result
#                would differ. unattended-upgrades is reconfigured only when
#                20auto-upgrades does not already say what we want it to say.
# Version: 2.8 - the firewall admits the Direct egress port (8442/tcp) on the
#                WireGuard interface. Default-deny was eating it: the listener
#                binds the tunnel address and was healthy, but every tenant
#                egress POST was silently dropped, so a fronted deployment's
#                Direct sends always read as a transport failure and retried
#                forever. Scoped to the tunnel interface — reaching it still
#                requires a WireGuard peer key the relay issued.
# Version: 2.5 - the relay serves JOINERY DIRECT for its tenants (docs/joinery_direct.md).
#                At Fortress the relay IS the Direct endpoint, because an SRV record
#                pointing at the origin box would advertise the address the relay
#                exists to conceal. Adds: the joinery-direct service (the same sealer
#                binary in direct-serve mode, TLS terminated in-process over
#                TLS-ALPN-01 — no web server, no certbot), 443 in the firewall, a
#                tunnel-only egress listener so a tenant's box-signed request leaves
#                from the relay's address, and Direct health in joinery-ping. The
#                relay stays KIND-BLIND: served kinds, caps and the decoy secret all
#                arrive as relay-map data, so a new kind is a map update rather than
#                a fleet upgrade.
# Version: 2.4 - joinery-ping answers "sole": is the asking tenant the only one on
#                this relay? An upgrade replaces every byte on the machine, and the
#                deployment asking can only see its own tenancy — so the relay has
#                to answer, and anything but a confirmed count of one answers false.
#                specs/mailbox_relay_upgrade_without_server_manager.md
# Version: 2.3 - joinery-ping also reports Postfix queue depth, but ONLY on a relay
#                with exactly one tenant: on a shard the queue is shared and its
#                depth would read out every other tenant's mail volume. An upgrade
#                wipes the machine and the queue with it, so a self-hosted operator
#                has to be able to see what a wipe would cost.
#                specs/mailbox_relay_upgrade_without_server_manager.md
# Version: 2.2 - joinery-ping answers the relay's health as JSON (service liveness,
#                milter wiring, and a hash check of the rspamd header contract), so
#                a tenant can tell a scanning relay from a silently dead one.
#                specs/mailbox_relay_scanner_health.md
# Version: 2.1 - Only Spamhaus (zen + dbl) is rejected on at RCPT time; SpamCop
#                and Barracuda list shared ESP outbound IPs on brief triggers, so
#                rejecting on them permanently bounced ordinary Mailgun/SendGrid mail
# Version: 2.0 - TENANCY-NATIVE (specs/mailbox_relay_shared_fleet.md): the relay
#                stack is multi-tenant at every layer and a self-hosted relay is
#                a fleet of one. Provisioning creates the shard skeleton; each
#                tenant is added with the add-tenant operation (per-tenant spool
#                subdirectory, restricted pull account, WireGuard peer with an
#                allocated tunnel address, root-owned domain allowlist). Map
#                sync is fragment push + shard-side merge (the relay-sealer
#                binary's merge-maps mode — the domain-claim enforcement point).
#                rspamd runs STATELESS (Bayes/autolearn off — shared learned
#                state would be a cross-tenant leak and a poisoning vector).
#
# Usage:
#   sudo bash provision_relay.sh <mail-hostname> [smarthost]   # shard skeleton
#   sudo bash provision_relay.sh add-tenant <slug> [--pull-pubkey "ssh-ed25519 ..."]
#        [--wg-pubkey KEY] [--tunnel-ip 10.99.0.N] [--domains a.com,b.com | --domains '*']
#        [--forward-limit N] [--spool-max-mib N] [--spool-max-entries N]
#   sudo bash provision_relay.sh remove-tenant <slug> [--force]
#   sudo bash provision_relay.sh set-domains <slug> <domains-csv | *>
#
# The skeleton is deployment-independent. A tenant is: a spool subdirectory
# (setgid, tenant-group readable), an SSH account locked to the
# joinery-tenant-shell forced command (rsync pull of its own spool, rsync push
# into its own fragment drop area, ack, merge trigger — nothing else), a
# WireGuard peer at an allocated tunnel address, and a root-owned registry entry
# (domain allowlist + shard-policy limits) under /opt/joinery-relay/tenants/.
# A self-hosted relay runs add-tenant once (allowlist '*' — no other tenant
# exists to claim against); a fleet shard runs it per tenant with explicit
# allowlists written on TXT-challenge success.
#
# By DEFAULT the relay is INBOUND-ONLY: the tunnel submission listener that lets
# a main box send compose mail THROUGH the relay (smarthost) is NOT opened.
# Smarthost is a SELF-HOSTED-ONLY opt-in ("smarthost" second argument or
# JOINERY_RELAY_SMARTHOST=1) and is refused on a shard with more than one
# tenant — the WireGuard subnet is mynetworks in that mode, which would let
# every tenant relay as the shard.
set -euo pipefail

# --- shared definitions --------------------------------------------------------
RELAY_VERSION="2.9"
RELAY_HOME="/opt/joinery-relay"
SEALER_BIN="${RELAY_HOME}/relay-sealer"
SPOOL_ROOT="/var/spool/joinery-relay"
RELAY_USER="joinery-relay"
TENANT_GROUP="joinery-tenants"
TENANT_SHELL="${RELAY_HOME}/bin/joinery-tenant-shell"
TENANTS_DIR="${RELAY_HOME}/tenants"
HOMES_DIR="${RELAY_HOME}/home"
WG_IF="wg0"
WG_PORT="51820"
WG_ADDR="10.99.0.1/24"          # the relay's tunnel address; tenants allocate from .2
MAP_DOMAINS="/etc/postfix/joinery-relay-domains"
MAP_RECIPIENTS="/etc/postfix/joinery-recipients"
MAP_TRANSPORT="/etc/postfix/joinery-transport"
MAP_SRS="/etc/postfix/joinery-srs"
ROUTING_JSON="${RELAY_HOME}/routing.json"
SLUG_RE='^[a-z0-9][a-z0-9-]{0,27}$'

if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must run as root (installs packages, edits /etc/postfix)." >&2
    echo "Re-run with: sudo bash $0 ..." >&2
    exit 1
fi

# =============================================================================
# Idempotence helpers
# =============================================================================
# This script is the reconciler behind relay_converge
# (specs/agent_machine_posture_and_relay_converge.md §5), so a run that finds the
# relay already in its intended state must change nothing and - above all -
# restart nothing. A converge that drops mail service every time it runs cannot
# be put on a schedule. Every mutation below therefore goes through one of these
# helpers, and every service action is conditional on a configuration THIS RUN
# changed.

CHANGED_UNITS=""

mark_changed() {
    case " ${CHANGED_UNITS} " in
        *" $1 "*) ;;
        *) CHANGED_UNITS="${CHANGED_UNITS} $1";;
    esac
}

changed() {
    case " ${CHANGED_UNITS} " in
        *" $1 "*) return 0;;
        *) return 1;;
    esac
}

# write_if_changed <dest> [mode] [owner:group] - content arrives on stdin.
# Returns 0 when it WROTE (the caller marks whatever unit reads the file) and 1
# when the file already matched. USE ONLY AS AN `if` CONDITION: under set -e a
# bare call that found no difference would end the script.
write_if_changed() {
    local dest="$1" mode="${2:-644}" own="${3:-}"
    mkdir -p "$(dirname "${dest}")"
    local tmp; tmp="$(mktemp "${dest}.joinery-XXXXXX")"
    cat > "${tmp}"
    chmod "${mode}" "${tmp}"
    if [[ -n "${own}" ]]; then chown "${own}" "${tmp}"; fi
    if [[ -f "${dest}" ]] && cmp -s "${tmp}" "${dest}"; then
        rm -f "${tmp}"
        # Mode and ownership are asserted even when the content matched: they are
        # part of the desired state, and neither is a reason to restart anything.
        chmod "${mode}" "${dest}"
        if [[ -n "${own}" ]]; then chown "${own}" "${dest}"; fi
        return 1
    fi
    mv -f "${tmp}" "${dest}"
    return 0
}

# postconf_set <parameter> <value> - set it only when the live value differs, so
# a converge that changes no Postfix parameter leaves Postfix alone.
postconf_set() {
    local key="$1" val="$2" cur
    cur="$(postconf -h "${key}" 2>/dev/null || true)"
    if [[ "${cur}" != "${val}" ]]; then
        postconf -e "${key} = ${val}"
        mark_changed postfix
    fi
    return 0
}

# converge_socket_default <file> <socket> - /etc/default/{opendkim,opendmarc}.
# The old edit rewrote the file on every run, and APPENDED a SOCKET= line when
# the packaged one was commented out, so nothing downstream could tell a run
# that changed the socket from a run that changed nothing. Returns 0 on a write.
converge_socket_default() {
    local file="$1" socket="$2" desired
    [[ -f "${file}" ]] || return 1
    if grep -qE '^[[:space:]]*SOCKET=' "${file}"; then
        desired="$(sed "s#^[[:space:]]*SOCKET=.*#SOCKET=\"${socket}\"#" "${file}")"
    else
        desired="$(cat "${file}"; printf 'SOCKET="%s"' "${socket}")"
    fi
    if printf '%s\n' "${desired}" | cmp -s - "${file}"; then
        return 1
    fi
    printf '%s\n' "${desired}" > "${file}"
    return 0
}

# sync_service <unit> [reload|restart] - start it if it is not running, act on it
# only if something it reads changed this run, and otherwise leave it alone.
sync_service() {
    local unit="$1" mode="${2:-restart}" state
    state="$(systemctl is-active "${unit}" 2>/dev/null || true)"
    [[ -n "${state}" ]] || state="unknown"
    if [[ "${state}" != "active" ]]; then
        if systemctl start "${unit}" >/dev/null 2>&1; then
            echo "${unit}: started (was ${state})"
        else
            echo "WARN: ${unit} is ${state} and would not start" >&2
        fi
    elif changed "${unit}"; then
        if [[ "${mode}" == "reload" ]]; then
            systemctl reload "${unit}" >/dev/null 2>&1 \
                || systemctl restart "${unit}" >/dev/null 2>&1 \
                || echo "WARN: ${unit} would not reload" >&2
            echo "${unit}: reloaded (its configuration changed this run)"
        else
            systemctl restart "${unit}" >/dev/null 2>&1 \
                || echo "WARN: ${unit} would not restart" >&2
            echo "${unit}: restarted (its configuration changed this run)"
        fi
    else
        echo "${unit}: unchanged - left running"
    fi
    return 0
}

# The persisted WireGuard peer list is DERIVED from the tenant registry, never
# appended to. A tenant that rotated its key used to leave its old [Peer] stanza
# behind for ever - the same defect class as the AllowedIPs collision fixed once
# already - and two stanzas claiming one tunnel address is a routing coin toss.
# Rebuilding the list from ${TENANTS_DIR} makes a rotation a replacement and a
# removal a removal. The [Interface] block, which holds the private key, is
# preserved byte for byte. Returns 0 when the file changed.
converge_wg_peers() {
    local conf="/etc/wireguard/${WG_IF}.conf"
    [[ -f "${conf}" ]] || return 1
    local tmp; tmp="$(mktemp "${conf}.joinery-XXXXXX")"
    chmod 600 "${tmp}"
    # The [Interface] block, with trailing blank lines trimmed: each peer below
    # supplies its own separating blank line, and keeping the one that preceded
    # the first [Peer] last time would add a line to the file on every single
    # run — a converge that is not a fixpoint is not a converge.
    local iface; iface="$(awk '/^\[Peer\]/{exit} {print}' "${conf}")"
    printf '%s\n' "${iface}" > "${tmp}"
    local d slug key ip
    for d in "${TENANTS_DIR}"/*/; do
        [[ -d "${d}" ]] || continue
        slug="$(basename "${d}")"
        [[ "${slug}" =~ ${SLUG_RE} ]] || continue
        [[ -s "${d}wg_pubkey" ]] || continue
        key="$(cat "${d}wg_pubkey")"
        ip="$(cat "${d}tunnel_ip" 2>/dev/null || true)"
        [[ -n "${key}" && -n "${ip}" ]] || continue
        printf '\n[Peer]\n# tenant %s\nPublicKey = %s\nAllowedIPs = %s/32\n' \
            "${slug}" "${key}" "${ip}" >> "${tmp}"
    done
    if cmp -s "${tmp}" "${conf}"; then
        rm -f "${tmp}"
        return 1
    fi
    mv -f "${tmp}" "${conf}"
    chmod 600 "${conf}"
    mark_changed "wg-quick@${WG_IF}"
    return 0
}

# Apply the converged peer set to the RUNNING interface without taking it down.
# 'wg syncconf' adds, updates and removes peers in place; restarting wg-quick to
# change one peer would drop every other tenant's tunnel with it.
apply_wg_live() {
    changed "wg-quick@${WG_IF}" || return 0
    if wg show "${WG_IF}" >/dev/null 2>&1; then
        if wg syncconf "${WG_IF}" <(wg-quick strip "${WG_IF}") 2>/dev/null; then
            echo "wireguard: peers synced live on ${WG_IF} (no interface restart)"
        else
            echo "WARN: live wg syncconf failed - run 'systemctl restart wg-quick@${WG_IF}'" >&2
        fi
    fi
    return 0
}

ufw_allow_once() {
    local rules="$1" spec="$2"
    if printf '%s\n' "${rules}" | grep -qE "^${spec}[[:space:]]+ALLOW"; then
        echo "firewall: ${spec} already allowed"
    else
        ufw allow "${spec}" >/dev/null 2>&1 || true
        echo "firewall: allow ${spec}"
    fi
    return 0
}

# Converge the intended rule set instead of 'ufw --force reset'. The reset wiped
# every rule on every run - including the rebuild flow's own 'ufw deny 25/tcp'
# (JobCommandBuilder::build_rebuild_relay closes 25 so the queue can drain, then
# re-runs provisioning). Port 25 is therefore converged ONE WAY here: opened if
# nothing has deliberately closed it, and an existing DENY is left for the
# rebuild's own reopen step to lift.
converge_firewall() {
    if ! command -v ufw >/dev/null 2>&1; then
        echo "firewall: ufw not installed - skipped"
        return 0
    fi
    local verbose rules
    verbose="$(ufw status verbose 2>/dev/null || true)"
    rules="$(ufw status 2>/dev/null || true)"

    if ! printf '%s\n' "${verbose}" | grep -q 'deny (incoming)'; then
        ufw default deny incoming >/dev/null 2>&1 || true
        echo "firewall: default incoming set to deny"
    fi
    if ! printf '%s\n' "${verbose}" | grep -q 'allow (outgoing)'; then
        ufw default allow outgoing >/dev/null 2>&1 || true
        echo "firewall: default outgoing set to allow"
    fi

    if printf '%s\n' "${rules}" | grep -qE '^25/tcp[[:space:]]+DENY'; then
        echo "firewall: 25/tcp is DENY - a rebuild window is open; left closed"
    else
        ufw_allow_once "${rules}" '25/tcp'
    fi
    ufw_allow_once "${rules}" "${WG_PORT}/udp"
    ufw_allow_once "${rules}" '22/tcp'
    # 443 is Joinery Direct's endpoint AND the port its certificate is obtained
    # on (TLS-ALPN-01, in-process - no web server and no certbot on this
    # machine). Opened unconditionally: the listener refuses everything except
    # the one Direct path, and a tenant that has not enabled Direct has no
    # capability record published, so nothing sends here.
    ufw_allow_once "${rules}" '443/tcp'

    # The Direct egress proxy for tenants. The listener already binds the tunnel
    # address only; this rule is scoped to the tunnel interface so the port is
    # never reachable from the public side even if the bind ever loosened.
    if printf '%s\n' "${rules}" | grep -qE "^8442/tcp on ${WG_IF}[[:space:]]+ALLOW"; then
        echo "firewall: 8442/tcp on ${WG_IF} already allowed"
    else
        ufw allow in on "${WG_IF}" to any port 8442 proto tcp >/dev/null 2>&1 || true
        echo "firewall: allow 8442/tcp on ${WG_IF} (Direct egress, tunnel only)"
    fi

    if printf '%s\n' "${verbose}" | grep -qE '^Status:[[:space:]]+active'; then
        echo "firewall: already active - not re-enabled"
    else
        ufw --force enable >/dev/null 2>&1 || true
        echo "firewall: enabled"
    fi
    return 0
}

# =============================================================================
# Tenant operations (add-tenant / remove-tenant / set-domains)
# =============================================================================

tenant_user() { echo "jt-${1}"; }

# The lowest free tunnel address in the relay subnet, skipping .1 (the relay).
allocate_tunnel_ip() {
    local used="" f
    for f in "${TENANTS_DIR}"/*/tunnel_ip; do
        [[ -f "${f}" ]] && used="${used} $(cat "${f}")"
    done
    for n in $(seq 2 254); do
        local candidate="10.99.0.${n}"
        [[ " ${used} " == *" ${candidate} "* ]] || { echo "${candidate}"; return 0; }
    done
    echo "ERROR: relay tunnel subnet exhausted" >&2
    return 1
}

tenant_count() {
    local n=0 d
    for d in "${TENANTS_DIR}"/*/; do
        [[ -d "${d}" ]] && n=$((n+1))
    done
    echo "${n}"
}

smarthost_active() {
    postconf -h mynetworks 2>/dev/null | grep -q '10\.99\.0\.0/24'
}

run_merge() {
    if [[ -x "${SEALER_BIN}" ]]; then
        "${SEALER_BIN}" merge-maps || echo "WARN: map merge reported a problem (see above)" >&2
    fi
}

add_tenant() {
    local slug="${1:-}"
    shift || true
    if [[ ! "${slug}" =~ ${SLUG_RE} ]]; then
        echo "ERROR: add-tenant requires a slug matching ${SLUG_RE}" >&2
        exit 1
    fi
    local pull_pubkey="" wg_pubkey="" tunnel_ip="" domains="*"
    local forward_limit=0 spool_max_mib=0 spool_max_entries=0
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --pull-pubkey)      pull_pubkey="${2:-}"; shift 2;;
            --wg-pubkey)        wg_pubkey="${2:-}"; shift 2;;
            --tunnel-ip)        tunnel_ip="${2:-}"; shift 2;;
            --domains)          domains="${2:-}"; shift 2;;
            --forward-limit)    forward_limit="${2:-0}"; shift 2;;
            --spool-max-mib)    spool_max_mib="${2:-0}"; shift 2;;
            --spool-max-entries) spool_max_entries="${2:-0}"; shift 2;;
            *) echo "ERROR: unknown add-tenant option $1" >&2; exit 1;;
        esac
    done

    if smarthost_active && [[ "$(tenant_count)" -ge 1 && ! -d "${TENANTS_DIR}/${slug}" ]]; then
        echo "ERROR: this relay runs the smarthost (mynetworks trusts the tunnel subnet)." >&2
        echo "Smarthost is single-tenant only — a second tenant could relay as this box." >&2
        exit 1
    fi

    local user; user="$(tenant_user "${slug}")"
    local home="${HOMES_DIR}/${slug}"
    local spool="${SPOOL_ROOT}/${slug}"

    getent group "${TENANT_GROUP}" >/dev/null || groupadd --system "${TENANT_GROUP}"

    if ! id -u "${user}" >/dev/null 2>&1; then
        mkdir -p "${HOMES_DIR}"
        useradd --system --user-group --groups "${TENANT_GROUP}" \
            --home-dir "${home}" --create-home --shell /bin/sh "${user}"
        echo "created tenant account ${user}"
    fi
    chmod 750 "${home}"
    mkdir -p "${home}/fragments" "${home}/.ssh"
    chown -R "${user}:${user}" "${home}/fragments" "${home}/.ssh"
    chmod 750 "${home}/fragments"
    chmod 700 "${home}/.ssh"

    # The pull key is locked to the tenant shell: rsync pull of its own spool,
    # rsync push into its own fragment drop, ack, merge trigger. Nothing else.
    if [[ -n "${pull_pubkey}" ]]; then
        printf 'command="%s %s",restrict %s\n' "${TENANT_SHELL}" "${slug}" "${pull_pubkey}" \
            > "${home}/.ssh/authorized_keys"
        chown "${user}:${user}" "${home}/.ssh/authorized_keys"
        chmod 600 "${home}/.ssh/authorized_keys"
        echo "authorized pull key for ${user} (forced command: tenant shell)"
    fi

    # Per-tenant spool: the sealer (owner) writes, the tenant group reads and
    # acks; setgid so committed entries inherit the tenant group. 2770 is the
    # cross-tenant isolation boundary.
    mkdir -p "${spool}/tmp"
    chown "${RELAY_USER}:${user}" "${spool}" "${spool}/tmp"
    chmod 2770 "${spool}" "${spool}/tmp"

    # Root-owned registry: allowlist + tunnel allocation + shard-policy limits.
    # The tenant account can read (merge verdicts return in-band via the shell)
    # but never write here.
    mkdir -p "${TENANTS_DIR}/${slug}"
    chmod 755 "${TENANTS_DIR}/${slug}"
    if [[ "${domains}" == "*" ]]; then
        printf '*\n' > "${TENANTS_DIR}/${slug}/allowed_domains"
    else
        # An empty list is valid (a fleet tenant has no domains until its first
        # TXT verification) — grep exiting 1 on no matches must not abort.
        { echo "${domains}" | tr ',' '\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v '^$' || true; } \
            > "${TENANTS_DIR}/${slug}/allowed_domains"
    fi
    chmod 644 "${TENANTS_DIR}/${slug}/allowed_domains"
    printf '{"forward_hourly_limit":%d,"spool_max_mib":%d,"spool_max_entries":%d}\n' \
        "${forward_limit}" "${spool_max_mib}" "${spool_max_entries}" \
        > "${TENANTS_DIR}/${slug}/limits.json"
    chmod 644 "${TENANTS_DIR}/${slug}/limits.json"

    if [[ -z "${tunnel_ip}" ]]; then
        if [[ -f "${TENANTS_DIR}/${slug}/tunnel_ip" ]]; then
            tunnel_ip="$(cat "${TENANTS_DIR}/${slug}/tunnel_ip")"
        else
            tunnel_ip="$(allocate_tunnel_ip)"
        fi
    fi
    printf '%s\n' "${tunnel_ip}" > "${TENANTS_DIR}/${slug}/tunnel_ip"

    # WireGuard peer: the tenant dials out; the shard listens. AllowedIPs pins
    # the peer to its allocated address — tenant A cannot source as tenant B.
    #
    # The REGISTRY is the source of truth and wg0.conf is derived from it. The
    # old append-if-absent could only ever add: a tenant that rotated its key
    # left its previous stanza in place, and two stanzas claiming one tunnel
    # address is a routing coin toss. Converging the whole list makes a rotation
    # a replacement.
    if [[ -n "${wg_pubkey}" ]]; then
        printf '%s\n' "${wg_pubkey}" > "${TENANTS_DIR}/${slug}/wg_pubkey"
        if converge_wg_peers; then
            echo "wireguard: peer list converged (tenant ${slug} at ${tunnel_ip})"
        else
            echo "wireguard: peer list already current for tenant ${slug}"
        fi
        apply_wg_live
    fi

    run_merge

    echo "TENANT_ADDED"
    echo "TENANT_SLUG=${slug}"
    echo "TENANT_SSH_USER=${user}"
    echo "TENANT_TUNNEL_IP=${tunnel_ip}"
    echo "TENANT_SPOOL=${spool}"
    echo "TENANT_FRAGMENT_DIR=${home}/fragments"
}

remove_tenant() {
    local slug="${1:-}" force="${2:-}"
    if [[ ! "${slug}" =~ ${SLUG_RE} ]]; then
        echo "ERROR: remove-tenant requires a slug" >&2
        exit 1
    fi
    local user; user="$(tenant_user "${slug}")"
    local spool="${SPOOL_ROOT}/${slug}"

    # An undrained spool is accepted mail that exists nowhere else — refuse to
    # destroy it unless forced.
    if compgen -G "${spool}/*.seal" >/dev/null 2>&1 && [[ "${force}" != "--force" ]]; then
        echo "ERROR: ${spool} still holds undrained sealed mail. Let the tenant pull, or pass --force." >&2
        exit 1
    fi

    if [[ -f "${TENANTS_DIR}/${slug}/wg_pubkey" ]]; then
        local key; key="$(cat "${TENANTS_DIR}/${slug}/wg_pubkey")"
        wg set "${WG_IF}" peer "${key}" remove 2>/dev/null || true
    fi

    id -u "${user}" >/dev/null 2>&1 && userdel "${user}" 2>/dev/null || true
    rm -rf "${HOMES_DIR}/${slug}" "${TENANTS_DIR}/${slug}" "${spool}"

    # The persisted peer list is rebuilt from what remains in the registry, so
    # the departing tenant's stanza goes with it. No text surgery on the config:
    # the awk that used to strip one [Peer] block depended on AllowedIPs being
    # the last line of every stanza, which is true only of the ones we wrote.
    if converge_wg_peers; then
        echo "wireguard: peer list converged after removing ${slug}"
    fi
    apply_wg_live

    run_merge

    echo "TENANT_REMOVED"
    echo "TENANT_SLUG=${slug}"
}

set_domains() {
    local slug="${1:-}" domains="${2:-}"
    if [[ ! "${slug}" =~ ${SLUG_RE} ]] || [[ ! -d "${TENANTS_DIR}/${slug}" ]]; then
        echo "ERROR: set-domains requires an existing tenant slug" >&2
        exit 1
    fi
    if [[ -z "${domains}" ]]; then
        echo "ERROR: set-domains requires a domain list (csv) or '*'" >&2
        exit 1
    fi
    if [[ "${domains}" == "*" ]]; then
        printf '*\n' > "${TENANTS_DIR}/${slug}/allowed_domains"
    elif [[ "${domains}" == "-" ]]; then
        # '-' empties the allowlist (suspension: the merge drops every domain on
        # the next pass, so the shard stops accepting the tenant's mail).
        : > "${TENANTS_DIR}/${slug}/allowed_domains"
    else
        { echo "${domains}" | tr ',' '\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//' | grep -v '^$' || true; } \
            > "${TENANTS_DIR}/${slug}/allowed_domains"
    fi
    chmod 644 "${TENANTS_DIR}/${slug}/allowed_domains"
    # Re-merge immediately: a shrunk allowlist must take effect now, not at the
    # tenant's next push.
    run_merge
    echo "DOMAINS_SET"
    echo "TENANT_SLUG=${slug}"
}

case "${1:-}" in
    add-tenant)    shift; add_tenant "$@"; exit 0;;
    remove-tenant) shift; remove_tenant "$@"; exit 0;;
    set-domains)   shift; set_domains "$@"; exit 0;;
esac

# =============================================================================
# Shard skeleton provisioning (default operation)
# =============================================================================

if ! command -v apt-get >/dev/null 2>&1; then
    echo "This installer supports apt-based systems (Debian/Ubuntu) only." >&2
    exit 1
fi

MAIL_HOSTNAME="${1:-}"
if [[ -z "${MAIL_HOSTNAME}" || "${MAIL_HOSTNAME}" != *.* ]]; then
    echo "Usage: sudo bash $0 <mail-hostname> [smarthost]   (a FQDN, e.g. mx.example.com)" >&2
    echo "   or: sudo bash $0 add-tenant|remove-tenant|set-domains ..." >&2
    exit 1
fi

# Smarthost (tunnel submission) opt-in: second positional arg "smarthost", or
# JOINERY_RELAY_SMARTHOST=1. Default 0 = inbound-only (open nothing for it).
# Self-hosted-only: refused when the shard already has more than one tenant.
SMARTHOST_MODE=0
if [[ "${2:-}" == "smarthost" || "${JOINERY_RELAY_SMARTHOST:-0}" == "1" ]]; then
    SMARTHOST_MODE=1
fi
if [[ "${SMARTHOST_MODE}" -eq 1 && "$(tenant_count)" -gt 1 ]]; then
    echo "ERROR: smarthost mode is single-tenant only; this relay has $(tenant_count) tenants." >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# The sealer arrives PREBUILT. It used to be compiled here, which put a Go
# toolchain on a mail relay, fetched golang.org/x/crypto over the network on
# every provision, burned minutes of CPU, and produced a byte-identical binary
# with a fresh mtime each run — so nothing could tell whether the sealer had
# actually changed. The signed support bundle
# (specs/agent_machine_posture_and_relay_converge.md §4) delivers the binary
# instead, in bin/ beside this script, one file per architecture named by
# `uname -m` (bin/relay-sealer-x86_64, bin/relay-sealer-aarch64). Resolved
# relative to this script, the way its sibling provisioning files already are.
SEALER_MACHINE="$(uname -m)"
SEALER_SRC=""
SEALER_CANDIDATES=()
if [[ -n "${JOINERY_RELAY_SEALER:-}" ]]; then
    SEALER_CANDIDATES+=("${JOINERY_RELAY_SEALER}")
fi
SEALER_CANDIDATES+=("${SCRIPT_DIR}/bin/relay-sealer-${SEALER_MACHINE}")
for candidate in "${SEALER_CANDIDATES[@]}"; do
    if [[ -f "${candidate}" ]]; then
        SEALER_SRC="${candidate}"
        break
    fi
done
if [[ -z "${SEALER_SRC}" ]]; then
    echo "ERROR: no prebuilt relay-sealer binary was delivered with this script." >&2
    echo "This machine is ${SEALER_MACHINE}. Looked for:" >&2
    for candidate in "${SEALER_CANDIDATES[@]}"; do echo "  ${candidate}" >&2; done
    echo "The binary ships in the signed support bundle. To build one by hand:" >&2
    echo "  bash ${SCRIPT_DIR}/relay-sealer/build.sh ${SCRIPT_DIR}/bin/relay-sealer-${SEALER_MACHINE}" >&2
    exit 1
fi
# A source tree, a text placeholder or a truncated download named like the
# binary would fail at the first piece of mail — hours later, as a delivery
# error, on a machine nobody is watching. Refuse it here instead.
if [[ "$(head -c 4 "${SEALER_SRC}" 2>/dev/null | od -An -tx1 | tr -d ' \n')" != "7f454c46" ]]; then
    echo "ERROR: ${SEALER_SRC} is not an ELF executable." >&2
    exit 1
fi
echo "relay-sealer: using prebuilt ${SEALER_SRC}"

export DEBIAN_FRONTEND=noninteractive

# --- 1. install packages -----------------------------------------------------
# No postfix-pgsql (no app DB on the relay), no php, and NO redis: the relay's
# rspamd is stateless (no Bayes) so nothing needs a store. NO COMPILER either -
# the sealer arrives prebuilt (see the sealer resolution above), which takes a
# Go toolchain and a network fetch off a mail relay whose smallness is the
# security property.
PACKAGES=(postfix opendkim opendkim-tools opendmarc wireguard wireguard-tools rsync ufw ca-certificates)
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
    apt-get update -qq
    apt-get install -y "${MISSING[@]}"
else
    echo "All relay packages already installed."
fi
systemctl enable postfix >/dev/null 2>&1 || true

# --- 2. relay user + tenant scaffolding + spool root + build the sealer -------
if ! id -u "${RELAY_USER}" >/dev/null 2>&1; then
    useradd --system --home-dir "${RELAY_HOME}" --shell /usr/sbin/nologin "${RELAY_USER}"
    echo "created system user ${RELAY_USER}"
fi
getent group "${TENANT_GROUP}" >/dev/null || groupadd --system "${TENANT_GROUP}"

mkdir -p "${RELAY_HOME}" "${RELAY_HOME}/bin" "${TENANTS_DIR}" "${HOMES_DIR}"
chown root:"${RELAY_USER}" "${RELAY_HOME}"
chmod 755 "${RELAY_HOME}"
chmod 755 "${RELAY_HOME}/bin" "${TENANTS_DIR}" "${HOMES_DIR}"

# The spool root holds only per-tenant subdirectories (created by add-tenant,
# owner joinery-relay, group jt-<slug>, mode 2770 — the isolation boundary).
mkdir -p "${SPOOL_ROOT}"
chown root:root "${SPOOL_ROOT}"
chmod 755 "${SPOOL_ROOT}"

# Install the prebuilt sealer, and ONLY when its bytes differ. This one binary
# is the Postfix pipe transport, the merge-maps unit AND the Joinery Direct
# service, so replacing it needlessly would restart Direct on every converge.
# Rename rather than overwrite: a running Direct holds the old inode open, and
# writing in place would earn ETXTBSY.
if [[ -f "${SEALER_BIN}" ]] && cmp -s "${SEALER_SRC}" "${SEALER_BIN}"; then
    echo "relay-sealer: ${SEALER_BIN} already current"
else
    cp -f "${SEALER_SRC}" "${SEALER_BIN}.new"
    chown root:root "${SEALER_BIN}.new"
    chmod 755 "${SEALER_BIN}.new"
    mv -f "${SEALER_BIN}.new" "${SEALER_BIN}"
    mark_changed joinery-direct
    echo "relay-sealer: installed ${SEALER_BIN} (also the merge-maps unit)"
fi

# Self-install so tenant lifecycle operations (add-tenant / remove-tenant /
# set-domains) can run later without re-shipping the provisioning bundle —
# fleet jobs invoke /opt/joinery-relay/provision_relay.sh directly.
if [[ "$(readlink -f "${BASH_SOURCE[0]}")" != "${RELAY_HOME}/provision_relay.sh" ]]; then
    if [[ -f "${RELAY_HOME}/provision_relay.sh" ]] \
       && cmp -s "${BASH_SOURCE[0]}" "${RELAY_HOME}/provision_relay.sh"; then
        echo "${RELAY_HOME}/provision_relay.sh is already this version"
    else
        cp "${BASH_SOURCE[0]}" "${RELAY_HOME}/provision_relay.sh"
        chmod 755 "${RELAY_HOME}/provision_relay.sh"
        echo "installed: ${RELAY_HOME}/provision_relay.sh (tenant lifecycle operations)"
    fi
fi

# What built this relay, so joinery-ping can say so and a tenant can tell an
# old shard from a current one without guessing from behaviour.
printf '%s' "${RELAY_VERSION}" > "${RELAY_HOME}/version"
chmod 644 "${RELAY_HOME}/version"

# --- 2b. tenant shell (the ONLY surface a tenant's SSH key reaches) -----------
if write_if_changed "${TENANT_SHELL}" 755 <<'TENANTSHELL'
#!/usr/bin/env bash
# joinery-tenant-shell <slug> — forced command for tenant pull accounts on a
# Joinery relay (managed by provision_relay.sh; specs/mailbox_relay_shared_fleet.md).
# Allows exactly: rsync pull of the tenant's own spool, rsync push into the
# tenant's own fragment drop area, spool ack, map-merge trigger, health ping.
set -euo pipefail
SLUG="${1:-}"
[[ "${SLUG}" =~ ^[a-z0-9][a-z0-9-]{0,27}$ ]] || { echo "denied: bad tenant" >&2; exit 1; }
RELAY_HOME="/opt/joinery-relay"
SPOOL="/var/spool/joinery-relay/${SLUG}"
FRAGMENTS="${RELAY_HOME}/home/${SLUG}/fragments"
CMD="${SSH_ORIGINAL_COMMAND:-}"
[[ -n "${CMD}" ]] || { echo "denied: no command" >&2; exit 1; }
read -r -a TOK <<< "${CMD}"
case "${TOK[0]}" in
  rsync)
    # Server invocations only, with the destination pinned to the tenant's own
    # areas. --daemon/--config are the classic forced-command escape; refuse.
    [[ "${TOK[1]:-}" == "--server" ]] || { echo "denied: rsync server mode only" >&2; exit 1; }
    for t in "${TOK[@]}"; do
      case "${t}" in
        --daemon|--config*|--remove-source-files|--copy-links|-L)
          echo "denied: option ${t}" >&2; exit 1;;
      esac
    done
    LAST="${TOK[$((${#TOK[@]}-1))]}"
    if [[ " ${CMD} " == *" --sender "* ]]; then
      # pull: read-only listing/copy of the tenant spool
      [[ "${LAST}" == "${SPOOL}/" || "${LAST}" == "${SPOOL}" ]] \
        || { echo "denied: pull path" >&2; exit 1; }
    else
      # push: the fragment drop area only
      [[ "${LAST}" == "${FRAGMENTS}/" || "${LAST}" == "${FRAGMENTS}" ]] \
        || { echo "denied: push path" >&2; exit 1; }
    fi
    exec rsync "${TOK[@]:1}"
    ;;
  joinery-ack)
    # Delete-after-store ack: ids only (no path separators — the tmp/ working
    # dir and the forward-rate bucket are unreachable). An id names whichever
    # artifact kind the entry is (.seal from the MX path, .direct from Joinery
    # Direct) plus its .meta sidecar — the ack must remove every kind, or an
    # acked entry of the unhandled kind is left orphaned on the spool forever.
    IDS=("${TOK[@]:1}")
    for id in "${IDS[@]}"; do
      [[ "${id}" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "denied: bad id" >&2; exit 1; }
      rm -f "${SPOOL}/${id}.seal" "${SPOOL}/${id}.direct" "${SPOOL}/${id}.meta"
    done
    echo "ACKED ${#IDS[@]}"
    ;;
  joinery-merge)
    # Trigger the shard-side merge (root, via the narrow sudoers rule) and
    # return THIS tenant's verdict in-band.
    sudo -n "${RELAY_HOME}/relay-sealer" merge-maps >/dev/null 2>&1 || true
    if [[ -f "${RELAY_HOME}/tenants/${SLUG}/merge_result.json" ]]; then
      cat "${RELAY_HOME}/tenants/${SLUG}/merge_result.json"
    else
      echo '{"status":"error","reason":"merge produced no verdict"}'
      exit 1
    fi
    ;;
  joinery-ping)
    # Health, as one JSON object (specs/mailbox_relay_scanner_health.md). A dead
    # content scanner is INVISIBLE from the tenant's side — milter_default_action
    # is accept, so unscanned mail arrives looking exactly like clean mail — which
    # is why the relay has to be asked rather than inferred from.
    #
    # SHARD-LEVEL SERVICE LIVENESS ONLY. No message counts, spool sizes or
    # anything per-tenant: several deployments share this shard, and one tenant's
    # mail volume is not another's to read. Service state is not tenant data.
    #
    # The queue depth below is the one measured exception, and it is gated rather
    # than excepted: it is emitted ONLY when this relay has exactly one tenant, so
    # the queue being reported is wholly the asker's. On a shard the key is absent
    # and the caller says nothing rather than guessing. An upgrade wipes the
    # machine and the Postfix queue with it, so a self-hosted operator has to be
    # able to see what a wipe would cost (specs/mailbox_relay_upgrade_without_server_manager.md).
    svc() { local s; s="$(systemctl is-active "$1" 2>/dev/null || true)"; [[ -n "${s}" ]] || s="unknown"; printf '%s' "${s}"; }
    # Joinery Direct's endpoint is invisible from a tenant's side when it is
    # down: the tenant's own sends still work (they go out through egress) and
    # inbound deliveries simply fail at the far end, which every sender reads as
    # "not reachable" and downgrades to SMTP. Mail keeps flowing, nothing is
    # marked verified, nobody notices. So the relay has to be asked. Service
    # state is not tenant data, so this leaks nothing per-tenant.
    DIRECT_STATE_SVC="$(svc joinery-direct)"
    DIRECT_CERT="false"
    if compgen -G "/opt/joinery-relay/acme/*" >/dev/null 2>&1; then DIRECT_CERT="true"; fi
    MILTERS="$(postconf -h smtpd_milters 2>/dev/null || true)"
    wired() { case "${MILTERS}" in *":$1"*) printf 'true';; *) printf 'false';; esac; }
    # The header contract by HASH: provisioning writes these two rspamd configs
    # itself and records their digest, so drift is a comparison rather than a
    # config parser. What drifted is not reported because the remedy does not
    # vary — reprovision the relay.
    CONTRACT="false"
    RS_HDR="/etc/rspamd/local.d/milter_headers.conf"
    RS_ACT="/etc/rspamd/local.d/actions.conf"
    CONTRACT_FILE="${RELAY_HOME}/contract.sha256"
    if [[ -r "${CONTRACT_FILE}" && -r "${RS_HDR}" && -r "${RS_ACT}" ]]; then
      WANT="$(cat "${CONTRACT_FILE}" 2>/dev/null || true)"
      HAVE="$(cat "${RS_HDR}" "${RS_ACT}" 2>/dev/null | sha256sum 2>/dev/null | cut -d' ' -f1 || true)"
      [[ -n "${WANT}" && "${WANT}" == "${HAVE}" ]] && CONTRACT="true"
    fi
    PROVISIONED="$(cat "${RELAY_HOME}/version" 2>/dev/null || true)"
    # Queue depth, fleet-of-one only (see the gate note above). An unreadable or
    # missing postqueue leaves the key ABSENT — never 0. "Cannot tell" and
    # "nothing queued" must not render alike when the difference is lost mail.
    TENANTS=0
    for d in "${RELAY_HOME}"/tenants/*/; do
      [[ -d "${d}" ]] && TENANTS=$((TENANTS+1))
    done
    # SOLE is the wipe guard. An upgrade replaces every byte on this machine, and
    # the deployment asking has no idea whether anyone else lives here — it can
    # only see its own tenancy. So the RELAY answers the question. Anything but a
    # confirmed count of one answers false, including an unreadable registry:
    # "cannot tell" must never authorise a wipe. It reveals whether the asker is
    # alone, not who or how many, so it leaks nothing a tenant should not know.
    SOLE="false"
    [[ "${TENANTS}" -eq 1 ]] && SOLE="true"
    QUEUE_JSON=""
    if [[ "${TENANTS}" -eq 1 ]]; then
      # postqueue is setgid postdrop, so an unprivileged tenant can list the
      # queue: no sudoers rule and no root reach are added by this.
      PQ="$(command -v postqueue 2>/dev/null || true)"
      [[ -n "${PQ}" ]] || PQ="/usr/sbin/postqueue"
      if [[ -x "${PQ}" ]]; then
        QOUT="$("${PQ}" -p 2>/dev/null || true)"
        QN=""
        if [[ "${QOUT}" == *"Mail queue is empty"* ]]; then
          QN=0
        elif [[ -n "${QOUT}" ]]; then
          # Summary line: "-- 5 Kbytes in 3 Requests." Read the count from the
          # summary rather than the entries, whose queue-id shape varies with
          # enable_long_queue_ids.
          QN="$(printf '%s\n' "${QOUT}" | awk '/^-- .*Request/ {print $(NF-1)}' | tail -1)"
        fi
        [[ "${QN}" =~ ^[0-9]+$ ]] && QUEUE_JSON=",\"queue\":${QN}"
      fi
    fi
    printf '{"status":"ok","services":{"rspamd":"%s","opendkim":"%s","opendmarc":"%s","joinery_direct":"%s"},"milters":{"opendkim":%s,"opendmarc":%s,"rspamd":%s},"direct":{"certificate":%s},"contract":%s,"provisioned":"%s","slug":"%s","sole":%s%s}\n' \
      "$(svc rspamd)" "$(svc opendkim)" "$(svc opendmarc)" "${DIRECT_STATE_SVC}" \
      "$(wired 8891)" "$(wired 8893)" "$(wired 11332)" \
      "${DIRECT_CERT}" \
      "${CONTRACT}" "${PROVISIONED}" "${SLUG}" "${SOLE}" "${QUEUE_JSON}"
    ;;
  *)
    echo "denied: unknown command" >&2
    exit 1
    ;;
esac
TENANTSHELL
then
    echo "tenant shell installed: ${TENANT_SHELL}"
else
    echo "tenant shell already current: ${TENANT_SHELL}"
fi

# Tenants may trigger exactly one root action: the map merge (which validates
# their fragments against root-owned allowlists — its whole purpose).
SUDOERS_MERGE="/etc/sudoers.d/joinery-relay-merge"
# Validated before it is installed, not after: a rule that fails visudo must
# never sit in /etc/sudoers.d at all, not even for the moment it takes to notice
# and delete it — sudo reads the directory, and a broken file there breaks sudo
# for everyone. sudo ignores names containing a dot, so the staging file is
# inert while it exists.
SUDOERS_TMP="$(mktemp /etc/sudoers.d/.joinery-relay-merge.XXXXXX)"
echo "%${TENANT_GROUP} ALL=(root) NOPASSWD: ${SEALER_BIN} merge-maps" > "${SUDOERS_TMP}"
chmod 440 "${SUDOERS_TMP}"
if ! visudo -cf "${SUDOERS_TMP}" >/dev/null; then
    rm -f "${SUDOERS_TMP}"
    echo "ERROR: generated sudoers rule failed validation - not installed." >&2
    exit 1
fi
if [[ -f "${SUDOERS_MERGE}" ]] && cmp -s "${SUDOERS_TMP}" "${SUDOERS_MERGE}"; then
    rm -f "${SUDOERS_TMP}"
    chmod 440 "${SUDOERS_MERGE}"
    echo "sudoers: rule already in place"
else
    mv -f "${SUDOERS_TMP}" "${SUDOERS_MERGE}"
    chmod 440 "${SUDOERS_MERGE}"
    echo "sudoers: ${TENANT_GROUP} may run '${SEALER_BIN} merge-maps'"
fi

# --- 3. placeholder synced maps (Postfix must start before the first merge) ---
for f in "${MAP_DOMAINS}" "${MAP_RECIPIENTS}" "${MAP_TRANSPORT}"; do
    [[ -f "${f}" ]] || : > "${f}"
    if [[ ! -f "${f}.db" || "${f}" -nt "${f}.db" ]]; then
        postmap "${f}"
        echo "postmap: rebuilt ${f}.db"
    fi
done
# The SRS accept map is a regexp map (no postmap); create it empty if absent.
[[ -f "${MAP_SRS}" ]] || : > "${MAP_SRS}"
if [[ ! -f "${ROUTING_JSON}" ]]; then
    printf '{"format":2,"tenants":{},"recipients":{},"domains":{}}\n' > "${ROUTING_JSON}"
fi
chown root:"${RELAY_USER}" "${ROUTING_JSON}"
chmod 640 "${ROUTING_JSON}"

# --- 4. master.cf: the Go sealer pipe transport (assert, self-repairing) ------
# flags=DRh — deliberately NOT 'u' (fold localpart to lowercase): SRS bounce
# addresses carry a case-sensitive hash in the local part, and folding it makes
# every bounce fail validation on the main box. The sealer receives ${recipient}
# ${sender} as argv and the raw message on stdin, and runs as the unprivileged
# relay user. The Go binary reads its paths from JOINERY_RELAY_ROUTING /
# JOINERY_RELAY_SPOOL, which default to the paths configured above; the
# per-tenant spool directory comes from the merged routing map's tenant block.
SEALER_ARGV="argv=${SEALER_BIN} \${recipient} \${sender}"
SEALER_DEF="joinery unix - n n - 5 pipe flags=DRh user=${RELAY_USER} ${SEALER_ARGV}"
existing_joinery="$(postconf -M joinery/unix 2>/dev/null | tr -s ' \t' ' ' | tr -d '\n' || true)"
if [[ -z "${existing_joinery}" ]]; then
    postconf -Me "joinery/unix=${SEALER_DEF}"
    mark_changed postfix
    echo "master.cf: added joinery sealer pipe transport"
elif [[ ( "${existing_joinery}" == *"${SEALER_ARGV} "* || "${existing_joinery}" == *"${SEALER_ARGV}" ) && "${existing_joinery}" == *"flags=DRh "* ]]; then
    echo "master.cf: joinery sealer transport already correct."
else
    postconf -Me "joinery/unix=${SEALER_DEF}"
    mark_changed postfix
    echo "master.cf: repaired stale joinery sealer transport"
fi

# --- 5. main.cf --------------------------------------------------------------
postconf_set "myhostname" "${MAIL_HOSTNAME}"
postconf_set "inet_interfaces" "all"
postconf_set "mydestination" "localhost, localhost.localdomain"

# Prefer IPv4 for outbound (forward + SRS bounce legs). A fresh VPS gets an IPv6
# address whose PTR is almost never set, and big receivers (Gmail) hard-reject
# IPv6 mail without a matching PTR + authentication (550 IPv6AuthError). The
# IPv4 PTR is what the provisioning DNS sets, so send from IPv4.
postconf_set "smtp_address_preference" "ipv4"

# Box-level acceptance flood control (anvil). Per-tenant enforcement lives in
# the sealer (forward throttle + spool quota from the tenant's routing block);
# these anvil limits bound what any single CLIENT can push at the shard.
postconf_set "smtpd_client_connection_rate_limit" "120"
postconf_set "smtpd_client_message_rate_limit" "300"

# Tunnel submission listener (smarthost) is OPT-IN and SELF-HOSTED-ONLY. Only
# when opted in is the WireGuard subnet trusted to relay outbound compose
# through the relay, and only then does permit_mynetworks lead the recipient
# restrictions. In the default inbound-only mode the tunnel carries no
# submission — a compromised relay in default mode can send only the inbound
# mail it forwards onward, nothing else.
if [[ "${SMARTHOST_MODE}" -eq 1 ]]; then
    postconf_set "mynetworks" "127.0.0.0/8, [::1]/128, 10.99.0.0/24"
    RCPT_LEAD="permit_mynetworks, "
    echo "smarthost: ENABLED — tunnel submission open (WG subnet trusted to relay outbound compose)"
else
    postconf_set "mynetworks" "127.0.0.0/8, [::1]/128"
    RCPT_LEAD=""
    echo "smarthost: DISABLED (inbound-only default) — no tunnel submission listener"
fi

# The relay is authoritative for the hosted domains (merged from tenant
# fragments) and routes each to the sealer pipe. reject_unauth_destination then
# accepts recipients in these and rejects relay attempts for anything else.
postconf_set "relay_domains" "hash:${MAP_DOMAINS}"
postconf_set "transport_maps" "hash:${MAP_TRANSPORT}"

# RBL block — verbatim from install_email.sh — plus SMTP-time recipient
# validation against the merged access map (preserving reject_unmatched: listed
# aliases OK, unmatched under a reject domain REJECTed, no backscatter). The
# permit_mynetworks lead is present only in smarthost mode (see above).
#
# Only Spamhaus rejects. Zen and DBL are built to be rejected on: low false
# positive, and Zen deliberately excludes the shared outbound ranges every ESP
# sends from. SpamCop and Barracuda list those shared IPs on brief automated
# triggers and de-list hours later, so rejecting on them bounces ordinary mail
# from Mailgun, SendGrid or Google at random — permanently, since a 5xx stops
# the sender retrying. SpamCop says as much itself: use it to score, not to
# refuse. Content scoring is where a weaker signal belongs.
postconf_set "smtpd_recipient_restrictions" "${RCPT_LEAD}reject_unauth_destination, reject_rbl_client zen.spamhaus.org, reject_rhsbl_helo dbl.spamhaus.org, reject_rhsbl_sender dbl.spamhaus.org, check_recipient_access regexp:${MAP_SRS}, check_recipient_access hash:${MAP_RECIPIENTS}, permit"
echo "main.cf: relay_domains, transport, recipient validation, RBL, anvil limits set"

# --- 6. opendkim + opendmarc (verify-mode, verbatim from install_email.sh) ----
AUTHSERV_ID="${MAIL_HOSTNAME}"
mkdir -p /run/opendkim
chown opendkim:opendkim /run/opendkim 2>/dev/null || true
mkdir -p /etc/opendkim
[[ -f /etc/opendkim/key.table ]]     || : > /etc/opendkim/key.table
[[ -f /etc/opendkim/signing.table ]] || : > /etc/opendkim/signing.table
[[ -f /etc/opendkim/trusted.hosts ]] || printf '127.0.0.1\n::1\nlocalhost\n' > /etc/opendkim/trusted.hosts

OPENDKIM_MARKER='joinery-managed opendkim.conf'
if [[ -f /etc/opendkim.conf && ! -f /etc/opendkim.conf.pre-joinery ]] \
   && ! grep -qF "${OPENDKIM_MARKER}" /etc/opendkim.conf 2>/dev/null; then
    cp /etc/opendkim.conf /etc/opendkim.conf.pre-joinery
fi
if write_if_changed /etc/opendkim.conf 644 <<OPENDKIMCONF
# ${OPENDKIM_MARKER} — managed by mailbox/provisioning/provision_relay.sh.
# Mode v = VERIFY inbound only (the relay does not sign; DKIM signing stays in-app
# on each tenant's main box).
# RemoveARAll + RemoveARFrom strip any inbound Authentication-Results header that
# forges OUR authserv-id BEFORE opendkim stamps its own, so a sender cannot smuggle
# a fake "spf=pass dkim=pass" verdict a tenant box would trust.
Syslog                  yes
SyslogSuccess           yes
UMask                   007
Mode                    v
Canonicalization        relaxed/simple
Socket                  inet:8891@localhost
PidFile                 /run/opendkim/opendkim.pid
UserID                  opendkim
AuthservID              ${AUTHSERV_ID}
RemoveARAll             yes
RemoveARFrom            ${AUTHSERV_ID}
KeyTable                /etc/opendkim/key.table
SigningTable            refile:/etc/opendkim/signing.table
ExternalIgnoreList      /etc/opendkim/trusted.hosts
InternalHosts           /etc/opendkim/trusted.hosts
OPENDKIMCONF
then
    mark_changed opendkim
    echo "opendkim: wrote /etc/opendkim.conf (verify, AuthservID ${AUTHSERV_ID})"
else
    echo "opendkim: /etc/opendkim.conf already correct."
fi
if converge_socket_default /etc/default/opendkim "inet:8891@localhost"; then
    mark_changed opendkim
    echo "opendkim: /etc/default/opendkim SOCKET set"
fi

mkdir -p /run/opendmarc
chown opendmarc:opendmarc /run/opendmarc 2>/dev/null || true
OPENDMARC_MARKER='joinery-managed opendmarc.conf'
if [[ -f /etc/opendmarc.conf && ! -f /etc/opendmarc.conf.pre-joinery ]] \
   && ! grep -qF "${OPENDMARC_MARKER}" /etc/opendmarc.conf 2>/dev/null; then
    cp /etc/opendmarc.conf /etc/opendmarc.conf.pre-joinery
fi
if write_if_changed /etc/opendmarc.conf 644 <<OPENDMARCCONF
# ${OPENDMARC_MARKER} — managed by mailbox/provisioning/provision_relay.sh.
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
then
    mark_changed opendmarc
    echo "opendmarc: wrote /etc/opendmarc.conf (AuthservID ${AUTHSERV_ID})"
else
    echo "opendmarc: /etc/opendmarc.conf already correct."
fi
if converge_socket_default /etc/default/opendmarc "inet:8893@localhost"; then
    mark_changed opendmarc
    echo "opendmarc: /etc/default/opendmarc SOCKET set"
fi

systemctl enable opendkim opendmarc >/dev/null 2>&1 || true
# Neither daemon re-reads its configuration on a signal, so a genuine change
# means a restart — but ONLY a genuine change. These two are inline in the milter
# chain: restarting them for nothing stalls acceptance on every converge.
sync_service opendkim restart
sync_service opendmarc restart

# --- 6b. rspamd content spam scanner (STATELESS) -------------------------------
# The relay stamps the X-Spam header inside the sealed raw so each tenant's
# deferred ingest can read a content-spam verdict — identical to what a
# colocated main-box MTA stamps. add_header only; the relay NEVER rejects on
# content (the reviewable-verdict model). rspamd runs as a milter AFTER
# opendkim(verify)+opendmarc so it can score on the auth results.
#
# STATELESS BY DESIGN (specs/mailbox_relay_shared_fleet.md): static rules only.
# The Bayes classifier and autolearn are OFF and no redis is configured, so no
# statistical state persists on the relay. Learned state on a shared shard
# would be one model trained on every tenant's mail — a cross-tenant privacy
# leak in token form and a poisoning vector. Nothing of value is lost: the
# relay's header was never the verdict — each tenant's own rspamd re-scores at
# ingest with its own state. Self-hosted relays run this same configuration.
CS_PACKAGES=(rspamd)
CS_MISSING=()
for pkg in "${CS_PACKAGES[@]}"; do
    dpkg -s "${pkg}" >/dev/null 2>&1 && echo "Already installed: ${pkg}" || CS_MISSING+=("${pkg}")
done
if [[ ${#CS_MISSING[@]} -gt 0 ]]; then
    echo "Installing: ${CS_MISSING[*]}"
    apt-get update -qq
    apt-get install -y "${CS_MISSING[@]}"
fi
mkdir -p /etc/rspamd/local.d
# The header NAMES are the contract InboundEmailRouter::readSpamHeader() parses;
# keep them in step with that class's SPAM_*_HEADER constants.
if write_if_changed /etc/rspamd/local.d/milter_headers.conf 644 <<'RSPAMDHDR'
# joinery-managed - content spam header contract (InboundEmailRouter::readSpamHeader).
extended_spam_headers = true;
use = ["spam-header", "x-spam-status", "authentication-results"];
RSPAMDHDR
then
    mark_changed rspamd
fi
if write_if_changed /etc/rspamd/local.d/actions.conf 644 <<'RSPAMDACT'
# joinery-managed - header-stamping only; rejection disabled (out of scope).
reject = null;
greylist = null;
add_header = 6;
RSPAMDACT
then
    mark_changed rspamd
fi
# The digest of the two files that ARE the contract, recorded at the moment we
# write them so joinery-ping can report drift without parsing rspamd's config
# format (specs/mailbox_relay_scanner_health.md). World-readable: a tenant's
# forced-command shell computes the comparison, and a hash of our own published
# configuration is not tenant data.
CONTRACT_DIGEST="$(cat /etc/rspamd/local.d/milter_headers.conf /etc/rspamd/local.d/actions.conf \
    | sha256sum | cut -d' ' -f1)"
if write_if_changed "${RELAY_HOME}/contract.sha256" 644 <<< "${CONTRACT_DIGEST}"; then
    echo "content-spam: header contract digest recorded (${RELAY_HOME}/contract.sha256)"
else
    echo "content-spam: header contract digest unchanged"
fi
if write_if_changed /etc/rspamd/local.d/classifier-bayes.conf 644 <<'RSPAMDBAYES'
# joinery-managed - STATELESS relay: Bayes off. Learned state on a shared
# relay is a cross-tenant privacy leak and a poisoning vector; each tenant's
# own rspamd re-scores at ingest with its own state.
enabled = false;
autolearn = false;
RSPAMDBAYES
then
    mark_changed rspamd
fi
# No local.d/redis.conf: without a global redis config every redis-backed
# module (statistics, history) stays off — nothing persists.
if [[ -f /etc/rspamd/local.d/redis.conf ]]; then
    rm -f /etc/rspamd/local.d/redis.conf
    mark_changed rspamd
    echo "content-spam: removed a redis config (the relay's rspamd is stateless)"
fi
if write_if_changed /etc/rspamd/local.d/worker-proxy.inc 644 <<'RSPAMDPROXY'
# joinery-managed - Postfix milter (self-scan) on 11332.
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
bind_socket = "*:11332";
RSPAMDPROXY
then
    mark_changed rspamd
fi

# Wire rspamd into the milter chain AFTER opendkim+opendmarc.
postconf_set "milter_default_action" "accept"
postconf_set "smtpd_milters" "inet:localhost:8891, inet:localhost:8893, inet:localhost:11332"
postconf_set "non_smtpd_milters" ""
echo "main.cf: milters wired (opendkim:8891, opendmarc:8893, rspamd:11332)"

systemctl enable rspamd >/dev/null 2>&1 || true
# rspamd re-reads its configuration on reload, so a converge that changed a
# local.d file costs no scanning downtime at all.
sync_service rspamd reload
echo "content-spam: rspamd milter on 11332 (add-header only, STATELESS - no Bayes/redis)."

# --- 7. WireGuard (the relay LISTENS; tenants dial out) ------------------------
mkdir -p /etc/wireguard
chmod 700 /etc/wireguard
if [[ ! -f /etc/wireguard/relay_private.key ]]; then
    umask 077
    wg genkey > /etc/wireguard/relay_private.key
    wg pubkey < /etc/wireguard/relay_private.key > /etc/wireguard/relay_public.key
    echo "wireguard: generated relay keypair"
fi
WG_PRIV="$(cat /etc/wireguard/relay_private.key)"
WG_PUB="$(cat /etc/wireguard/relay_public.key)"

# Write the interface config only if absent: it holds the relay's PRIVATE KEY,
# which is generated once and must survive every re-run. The [Peer] list below
# it is converged separately from the tenant registry.
if [[ ! -f "/etc/wireguard/${WG_IF}.conf" ]]; then
    cat > "/etc/wireguard/${WG_IF}.conf" <<WGCONF
# joinery-managed - hardened ingest relay tunnel. The relay only LISTENS; each
# tenant's Joinery box initiates its peering. The tenant [Peer] blocks below are
# DERIVED from /opt/joinery-relay/tenants/*/ by provision_relay.sh; edit the
# registry, not this list.
[Interface]
Address = ${WG_ADDR}
ListenPort = ${WG_PORT}
PrivateKey = ${WG_PRIV}
WGCONF
    chmod 600 "/etc/wireguard/${WG_IF}.conf"
    echo "wireguard: wrote /etc/wireguard/${WG_IF}.conf"
else
    echo "wireguard: /etc/wireguard/${WG_IF}.conf exists - interface block left as is."
fi
# Peers are derived from the tenant registry, so a rotated or departed tenant's
# stanza is replaced rather than accumulated (§5.4.4).
if converge_wg_peers; then
    echo "wireguard: peer list converged from the tenant registry"
else
    echo "wireguard: peer list already matches the tenant registry"
fi
systemctl enable "wg-quick@${WG_IF}" >/dev/null 2>&1 || true
if wg show "${WG_IF}" >/dev/null 2>&1; then
    # Never restart a live tunnel to change a peer: it would drop every OTHER
    # tenant's tunnel to add or remove one.
    apply_wg_live
    changed "wg-quick@${WG_IF}" || echo "wg-quick@${WG_IF}: unchanged - left up"
else
    systemctl start "wg-quick@${WG_IF}" >/dev/null 2>&1 \
        || echo "WARN: bring up wg-quick@${WG_IF} manually once a peer is added" >&2
fi

# --- 7b. Joinery Direct endpoint ---------------------------------------------
# At Fortress the relay IS the Direct endpoint (docs/joinery_direct.md): an SRV
# record pointing at the origin box would advertise in public DNS exactly the
# address this relay exists to conceal. So the same binary that seals mail also
# serves the channel — one more mode, not one more package.
#
# TLS is terminated in-process with an ACME certificate obtained over
# TLS-ALPN-01 on the port it already listens on. That is deliberate: adding
# nginx plus certbot to obtain one certificate would roughly double the software
# on a machine whose smallness IS the security property.
#
# The service runs as the unprivileged relay user, so it needs an explicit
# capability to bind 443 — granted to the service, not to the binary on disk.
DIRECT_STATE="${RELAY_HOME}/direct"
DIRECT_ACME="${RELAY_HOME}/acme"
mkdir -p "${DIRECT_STATE}" "${DIRECT_ACME}"
chown -R "${RELAY_USER}:${RELAY_USER}" "${DIRECT_STATE}" "${DIRECT_ACME}"
chmod 700 "${DIRECT_STATE}" "${DIRECT_ACME}"

if write_if_changed /etc/systemd/system/joinery-direct.service 644 <<DIRECTUNIT
[Unit]
Description=Joinery Direct endpoint (relay)
Documentation=https://github.com/getjoinery/joinery/blob/main/public_html/docs/joinery_direct.md
After=network-online.target wg-quick@${WG_IF}.service
Wants=network-online.target

[Service]
Type=simple
User=${RELAY_USER}
Group=${RELAY_USER}
ExecStart=${SEALER_BIN} direct-serve --hostname ${MAIL_HOSTNAME} \\
    --routing ${RELAY_HOME}/routing.json \\
    --spool ${SPOOL_ROOT} \\
    --cert-cache ${DIRECT_ACME} \\
    --state ${DIRECT_STATE} \\
    --tunnel ${WG_ADDR%/*}
Restart=always
RestartSec=5
# Binding 443 as an unprivileged user, and nothing else.
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=${DIRECT_STATE} ${DIRECT_ACME} ${SPOOL_ROOT}
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_INET AF_INET6
RestrictNamespaces=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes

[Install]
WantedBy=multi-user.target
DIRECTUNIT
then
    mark_changed joinery-direct
    mark_changed joinery-direct-unit
    echo "joinery-direct: unit written"
else
    echo "joinery-direct: unit already correct."
fi

# The spool is setgid to each tenant's group and the sealer writes as the relay
# user, so the Direct service writing there needs the same group membership the
# pipe transport already has.
if changed joinery-direct-unit; then
    systemctl daemon-reload >/dev/null 2>&1 || true
fi
systemctl enable joinery-direct >/dev/null 2>&1 || true
# Restarted when the unit changed OR the sealer binary behind it was replaced —
# and otherwise left alone. Direct holds an ACME certificate and live tunnel
# connections; bouncing it on every converge is not free.
sync_service joinery-direct restart
echo "Joinery Direct: endpoint enabled on 443 for ${MAIL_HOSTNAME} (certificate obtained on first request)"

# --- 8. hardening ------------------------------------------------------------
# Unattended security upgrades.
if ! dpkg -s unattended-upgrades >/dev/null 2>&1; then
    apt-get install -y unattended-upgrades
fi
# dpkg-reconfigure ran on every provision, rewriting 20auto-upgrades whether or
# not it already said what we want. What it actually decides is those two
# periodic switches, so read them and only reconfigure when they disagree.
AUTO_UPGRADES=/etc/apt/apt.conf.d/20auto-upgrades
if [[ -f "${AUTO_UPGRADES}" ]] \
   && grep -qE '^APT::Periodic::Update-Package-Lists[[:space:]]+"1";' "${AUTO_UPGRADES}" \
   && grep -qE '^APT::Periodic::Unattended-Upgrade[[:space:]]+"1";' "${AUTO_UPGRADES}"; then
    echo "unattended-upgrades: already configured - left alone"
else
    dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true
    echo "unattended-upgrades: configured"
fi

# Key-only SSH.
SSHD_DROPIN=/etc/ssh/sshd_config.d/10-joinery-relay.conf
mkdir -p /etc/ssh/sshd_config.d
if write_if_changed "${SSHD_DROPIN}" 644 <<SSHDCONF
# joinery-managed - key-only SSH on the relay.
PasswordAuthentication no
ChallengeResponseAuthentication no
PermitRootLogin prohibit-password
SSHDCONF
then
    systemctl reload ssh >/dev/null 2>&1 || systemctl reload sshd >/dev/null 2>&1 || true
    echo "sshd: key-only drop-in written and sshd reloaded"
else
    echo "sshd: key-only drop-in already correct."
fi

# Default-deny firewall: SMTP in, WireGuard in, SSH in — converged rule by rule
# rather than reset and rebuilt (§5.4.1).
converge_firewall

# --- 9. validate + restart Postfix -------------------------------------------
if postfix check; then
    # 'postfix reload' re-reads main.cf AND master.cf, so a converge that changed
    # a parameter costs no accept downtime — and one that changed nothing does
    # nothing at all.
    sync_service postfix reload
    echo "Postfix configuration validated."
else
    echo "WARNING: 'postfix check' reported problems - NOT restarting. Review above." >&2
    exit 1
fi

# --- 10. print what a tenant's main server needs -------------------------------
PUBLIC_IP="$(curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I 2>/dev/null | awk '{print $1}' || echo 'unknown')"
echo
echo "================= HARDENED RELAY READY ================="
echo "RELAY_READY"
echo "  Mail hostname     : ${MAIL_HOSTNAME}"
echo "  Relay public IP   : ${PUBLIC_IP}"
echo "  WireGuard pubkey  : ${WG_PUB}"
echo "  WireGuard endpoint: ${PUBLIC_IP}:${WG_PORT}"
echo "  Relay tunnel IP   : ${WG_ADDR%/*}"
echo "  Joinery Direct    : https://${MAIL_HOSTNAME}:443 (SRV target for Fortress tenants)"
echo "  Tenants           : $(tenant_count) (add with: sudo bash provision_relay.sh add-tenant <slug> ...)"
echo
echo "Next:"
echo "  1. Add each tenant:  sudo bash provision_relay.sh add-tenant <slug> \\"
echo "         --pull-pubkey '<tenant pull key>' --wg-pubkey '<tenant WG key>' [--domains ...]"
echo "     (the provisioning job does this automatically for a self-hosted relay)"
echo "  2. Point the MX + A records for each tenant's hosted domains at ${PUBLIC_IP}."
echo "  3. Set the relay's PTR record to ${MAIL_HOSTNAME} at your VPS provider."
echo "  4. For Joinery Direct, publish each tenant's capability record LAST —"
echo "     _joinery._tcp -> ${MAIL_HOSTNAME}:443 — once this relay answers there."
echo "========================================================"
