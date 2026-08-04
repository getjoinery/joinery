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
RELAY_VERSION="2.4"
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
    if [[ -n "${wg_pubkey}" ]]; then
        printf '%s\n' "${wg_pubkey}" > "${TENANTS_DIR}/${slug}/wg_pubkey"
        if ! grep -qF "${wg_pubkey}" "/etc/wireguard/${WG_IF}.conf" 2>/dev/null; then
            printf '\n[Peer]\n# tenant %s\nPublicKey = %s\nAllowedIPs = %s/32\n' \
                "${slug}" "${wg_pubkey}" "${tunnel_ip}" >> "/etc/wireguard/${WG_IF}.conf"
        fi
        wg set "${WG_IF}" peer "${wg_pubkey}" allowed-ips "${tunnel_ip}/32" 2>/dev/null \
            || echo "WARN: live wg peer add failed - bring up wg-quick@${WG_IF}" >&2
        echo "wireguard: peered tenant ${slug} at ${tunnel_ip}"
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
        # Strip the [Peer] block for this key from the persisted config.
        if [[ -f "/etc/wireguard/${WG_IF}.conf" ]]; then
            awk -v key="${key}" '
                /^\[Peer\]/ { block=""; inpeer=1 }
                inpeer { block=block $0 ORS; if (index($0, key)) drop=1;
                         if (/^AllowedIPs/) { inpeer=0; if (!drop) printf "%s", block; drop=0 } ; next }
                { print }
            ' "/etc/wireguard/${WG_IF}.conf" > "/etc/wireguard/${WG_IF}.conf.tmp" \
                && mv "/etc/wireguard/${WG_IF}.conf.tmp" "/etc/wireguard/${WG_IF}.conf"
            chmod 600 "/etc/wireguard/${WG_IF}.conf"
        fi
    fi

    id -u "${user}" >/dev/null 2>&1 && userdel "${user}" 2>/dev/null || true
    rm -rf "${HOMES_DIR}/${slug}" "${TENANTS_DIR}/${slug}" "${spool}"

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
SEALER_SRC="${SCRIPT_DIR}/relay-sealer"

if [[ ! -d "${SEALER_SRC}" ]]; then
    echo "ERROR: sealer source not found at ${SEALER_SRC}" >&2
    echo "Run this script from the shipped plugins/mailbox/provisioning/ directory." >&2
    exit 1
fi

export DEBIAN_FRONTEND=noninteractive

# --- 1. install packages -----------------------------------------------------
# No postfix-pgsql (no app DB on the relay), no php, and NO redis: the relay's
# rspamd is stateless (no Bayes) so nothing needs a store. golang-go builds the
# sealer.
PACKAGES=(postfix opendkim opendkim-tools opendmarc wireguard wireguard-tools rsync ufw golang-go ca-certificates)
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

echo "building relay-sealer (CGO off, static)..."
# Build as a normal user context but write into RELAY_HOME. A module-cache dir is
# needed for the one dependency (golang.org/x/crypto) to be fetched.
export GOFLAGS="-mod=mod"
export GOCACHE="${RELAY_HOME}/.gocache"
export GOPATH="${RELAY_HOME}/.gopath"
mkdir -p "${GOCACHE}" "${GOPATH}"
( cd "${SEALER_SRC}" && CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "${SEALER_BIN}" . )
chown root:root "${SEALER_BIN}"
chmod 755 "${SEALER_BIN}"
echo "sealer built: ${SEALER_BIN} (also the merge-maps unit)"

# Self-install so tenant lifecycle operations (add-tenant / remove-tenant /
# set-domains) can run later without re-shipping the provisioning bundle —
# fleet jobs invoke /opt/joinery-relay/provision_relay.sh directly.
if [[ "$(readlink -f "${BASH_SOURCE[0]}")" != "${RELAY_HOME}/provision_relay.sh" ]]; then
    cp "${BASH_SOURCE[0]}" "${RELAY_HOME}/provision_relay.sh"
    chmod 755 "${RELAY_HOME}/provision_relay.sh"
    echo "installed: ${RELAY_HOME}/provision_relay.sh (tenant lifecycle operations)"
fi

# What built this relay, so joinery-ping can say so and a tenant can tell an
# old shard from a current one without guessing from behaviour.
printf '%s' "${RELAY_VERSION}" > "${RELAY_HOME}/version"
chmod 644 "${RELAY_HOME}/version"

# --- 2b. tenant shell (the ONLY surface a tenant's SSH key reaches) -----------
cat > "${TENANT_SHELL}" <<'TENANTSHELL'
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
    # dir and the forward-rate bucket are unreachable).
    IDS=("${TOK[@]:1}")
    for id in "${IDS[@]}"; do
      [[ "${id}" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "denied: bad id" >&2; exit 1; }
      rm -f "${SPOOL}/${id}.seal" "${SPOOL}/${id}.meta"
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
    printf '{"status":"ok","services":{"rspamd":"%s","opendkim":"%s","opendmarc":"%s"},"milters":{"opendkim":%s,"opendmarc":%s,"rspamd":%s},"contract":%s,"provisioned":"%s","slug":"%s","sole":%s%s}\n' \
      "$(svc rspamd)" "$(svc opendkim)" "$(svc opendmarc)" \
      "$(wired 8891)" "$(wired 8893)" "$(wired 11332)" \
      "${CONTRACT}" "${PROVISIONED}" "${SLUG}" "${SOLE}" "${QUEUE_JSON}"
    ;;
  *)
    echo "denied: unknown command" >&2
    exit 1
    ;;
esac
TENANTSHELL
chmod 755 "${TENANT_SHELL}"
echo "tenant shell installed: ${TENANT_SHELL}"

# Tenants may trigger exactly one root action: the map merge (which validates
# their fragments against root-owned allowlists — its whole purpose).
SUDOERS_MERGE="/etc/sudoers.d/joinery-relay-merge"
echo "%${TENANT_GROUP} ALL=(root) NOPASSWD: ${SEALER_BIN} merge-maps" > "${SUDOERS_MERGE}"
chmod 440 "${SUDOERS_MERGE}"
if ! visudo -cf "${SUDOERS_MERGE}" >/dev/null; then
    rm -f "${SUDOERS_MERGE}"
    echo "ERROR: generated sudoers rule failed validation - removed." >&2
    exit 1
fi
echo "sudoers: ${TENANT_GROUP} may run '${SEALER_BIN} merge-maps'"

# --- 3. placeholder synced maps (Postfix must start before the first merge) ---
for f in "${MAP_DOMAINS}" "${MAP_RECIPIENTS}" "${MAP_TRANSPORT}"; do
    [[ -f "${f}" ]] || : > "${f}"
    postmap "${f}"
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
    echo "master.cf: added joinery sealer pipe transport"
elif [[ ( "${existing_joinery}" == *"${SEALER_ARGV} "* || "${existing_joinery}" == *"${SEALER_ARGV}" ) && "${existing_joinery}" == *"flags=DRh "* ]]; then
    echo "master.cf: joinery sealer transport already correct."
else
    postconf -Me "joinery/unix=${SEALER_DEF}"
    echo "master.cf: repaired stale joinery sealer transport"
fi

# --- 5. main.cf --------------------------------------------------------------
postconf -e "myhostname = ${MAIL_HOSTNAME}"
postconf -e "inet_interfaces = all"
postconf -e "mydestination = localhost, localhost.localdomain"

# Prefer IPv4 for outbound (forward + SRS bounce legs). A fresh VPS gets an IPv6
# address whose PTR is almost never set, and big receivers (Gmail) hard-reject
# IPv6 mail without a matching PTR + authentication (550 IPv6AuthError). The
# IPv4 PTR is what the provisioning DNS sets, so send from IPv4.
postconf -e "smtp_address_preference = ipv4"

# Box-level acceptance flood control (anvil). Per-tenant enforcement lives in
# the sealer (forward throttle + spool quota from the tenant's routing block);
# these anvil limits bound what any single CLIENT can push at the shard.
postconf -e "smtpd_client_connection_rate_limit = 120"
postconf -e "smtpd_client_message_rate_limit = 300"

# Tunnel submission listener (smarthost) is OPT-IN and SELF-HOSTED-ONLY. Only
# when opted in is the WireGuard subnet trusted to relay outbound compose
# through the relay, and only then does permit_mynetworks lead the recipient
# restrictions. In the default inbound-only mode the tunnel carries no
# submission — a compromised relay in default mode can send only the inbound
# mail it forwards onward, nothing else.
if [[ "${SMARTHOST_MODE}" -eq 1 ]]; then
    postconf -e "mynetworks = 127.0.0.0/8, [::1]/128, 10.99.0.0/24"
    RCPT_LEAD="permit_mynetworks, "
    echo "smarthost: ENABLED — tunnel submission open (WG subnet trusted to relay outbound compose)"
else
    postconf -e "mynetworks = 127.0.0.0/8, [::1]/128"
    RCPT_LEAD=""
    echo "smarthost: DISABLED (inbound-only default) — no tunnel submission listener"
fi

# The relay is authoritative for the hosted domains (merged from tenant
# fragments) and routes each to the sealer pipe. reject_unauth_destination then
# accepts recipients in these and rejects relay attempts for anything else.
postconf -e "relay_domains = hash:${MAP_DOMAINS}"
postconf -e "transport_maps = hash:${MAP_TRANSPORT}"

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
postconf -e "smtpd_recipient_restrictions = ${RCPT_LEAD}reject_unauth_destination, reject_rbl_client zen.spamhaus.org, reject_rhsbl_helo dbl.spamhaus.org, reject_rhsbl_sender dbl.spamhaus.org, check_recipient_access regexp:${MAP_SRS}, check_recipient_access hash:${MAP_RECIPIENTS}, permit"
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
if ! grep -qF "${OPENDKIM_MARKER}" /etc/opendkim.conf 2>/dev/null; then
    [[ -f /etc/opendkim.conf && ! -f /etc/opendkim.conf.pre-joinery ]] && cp /etc/opendkim.conf /etc/opendkim.conf.pre-joinery
    cat > /etc/opendkim.conf <<OPENDKIMCONF
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
    echo "opendkim: wrote /etc/opendkim.conf (verify, AuthservID ${AUTHSERV_ID})"
else
    echo "opendkim: already managed by us - leaving it."
fi
if [[ -f /etc/default/opendkim ]]; then
    if grep -qE '^[[:space:]]*SOCKET=' /etc/default/opendkim; then
        sed -i 's#^[[:space:]]*SOCKET=.*#SOCKET="inet:8891@localhost"#' /etc/default/opendkim
    else
        echo 'SOCKET="inet:8891@localhost"' >> /etc/default/opendkim
    fi
fi

mkdir -p /run/opendmarc
chown opendmarc:opendmarc /run/opendmarc 2>/dev/null || true
OPENDMARC_MARKER='joinery-managed opendmarc.conf'
if ! grep -qF "${OPENDMARC_MARKER}" /etc/opendmarc.conf 2>/dev/null; then
    [[ -f /etc/opendmarc.conf && ! -f /etc/opendmarc.conf.pre-joinery ]] && cp /etc/opendmarc.conf /etc/opendmarc.conf.pre-joinery
    cat > /etc/opendmarc.conf <<OPENDMARCCONF
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
    echo "opendmarc: wrote /etc/opendmarc.conf (AuthservID ${AUTHSERV_ID})"
else
    echo "opendmarc: already managed by us - leaving it."
fi
if [[ -f /etc/default/opendmarc ]]; then
    if grep -qE '^[[:space:]]*SOCKET=' /etc/default/opendmarc; then
        sed -i 's#^[[:space:]]*SOCKET=.*#SOCKET="inet:8893@localhost"#' /etc/default/opendmarc
    else
        echo 'SOCKET="inet:8893@localhost"' >> /etc/default/opendmarc
    fi
fi

systemctl enable opendkim opendmarc >/dev/null 2>&1 || true
systemctl restart opendkim 2>/dev/null || service opendkim restart 2>/dev/null || echo "WARN: restart opendkim manually" >&2
systemctl restart opendmarc 2>/dev/null || service opendmarc restart 2>/dev/null || echo "WARN: restart opendmarc manually" >&2

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
cat > /etc/rspamd/local.d/milter_headers.conf <<'RSPAMDHDR'
# joinery-managed - content spam header contract (InboundEmailRouter::readSpamHeader).
extended_spam_headers = true;
use = ["spam-header", "x-spam-status", "authentication-results"];
RSPAMDHDR
cat > /etc/rspamd/local.d/actions.conf <<'RSPAMDACT'
# joinery-managed - header-stamping only; rejection disabled (out of scope).
reject = null;
greylist = null;
add_header = 6;
RSPAMDACT
# The digest of the two files that ARE the contract, recorded at the moment we
# write them so joinery-ping can report drift without parsing rspamd's config
# format (specs/mailbox_relay_scanner_health.md). World-readable: a tenant's
# forced-command shell computes the comparison, and a hash of our own published
# configuration is not tenant data.
cat /etc/rspamd/local.d/milter_headers.conf /etc/rspamd/local.d/actions.conf \
    | sha256sum | cut -d' ' -f1 > "${RELAY_HOME}/contract.sha256"
chmod 644 "${RELAY_HOME}/contract.sha256"
echo "content-spam: header contract digest recorded (${RELAY_HOME}/contract.sha256)"
cat > /etc/rspamd/local.d/classifier-bayes.conf <<'RSPAMDBAYES'
# joinery-managed - STATELESS relay: Bayes off. Learned state on a shared
# relay is a cross-tenant privacy leak and a poisoning vector; each tenant's
# own rspamd re-scores at ingest with its own state.
enabled = false;
autolearn = false;
RSPAMDBAYES
# No local.d/redis.conf: without a global redis config every redis-backed
# module (statistics, history) stays off — nothing persists.
rm -f /etc/rspamd/local.d/redis.conf
cat > /etc/rspamd/local.d/worker-proxy.inc <<'RSPAMDPROXY'
# joinery-managed - Postfix milter (self-scan) on 11332.
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
bind_socket = "*:11332";
RSPAMDPROXY

# Wire rspamd into the milter chain AFTER opendkim+opendmarc.
postconf -e "milter_default_action = accept"
postconf -e "smtpd_milters = inet:localhost:8891, inet:localhost:8893, inet:localhost:11332"
postconf -e "non_smtpd_milters ="
echo "main.cf: milters wired (opendkim:8891, opendmarc:8893, rspamd:11332)"

systemctl enable rspamd >/dev/null 2>&1 || true
systemctl restart rspamd 2>/dev/null || service rspamd restart 2>/dev/null || echo "WARN: restart rspamd manually" >&2
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

# Write the interface config only if absent, so a re-run never wipes the [Peer]
# blocks add-tenant has since appended.
if [[ ! -f "/etc/wireguard/${WG_IF}.conf" ]]; then
    cat > "/etc/wireguard/${WG_IF}.conf" <<WGCONF
# joinery-managed - hardened ingest relay tunnel. The relay only LISTENS; each
# tenant's Joinery box initiates its peering. Tenant [Peer] blocks are appended
# by 'provision_relay.sh add-tenant' with per-tenant AllowedIPs.
[Interface]
Address = ${WG_ADDR}
ListenPort = ${WG_PORT}
PrivateKey = ${WG_PRIV}
WGCONF
    chmod 600 "/etc/wireguard/${WG_IF}.conf"
    echo "wireguard: wrote /etc/wireguard/${WG_IF}.conf"
else
    echo "wireguard: /etc/wireguard/${WG_IF}.conf exists - leaving it (peer edits preserved)."
fi
systemctl enable "wg-quick@${WG_IF}" >/dev/null 2>&1 || true
systemctl restart "wg-quick@${WG_IF}" 2>/dev/null || echo "WARN: bring up wg-quick@${WG_IF} manually once a peer is added" >&2

# --- 8. hardening ------------------------------------------------------------
# Unattended security upgrades.
if ! dpkg -s unattended-upgrades >/dev/null 2>&1; then
    apt-get install -y unattended-upgrades
fi
dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true

# Key-only SSH.
SSHD_DROPIN=/etc/ssh/sshd_config.d/10-joinery-relay.conf
mkdir -p /etc/ssh/sshd_config.d
cat > "${SSHD_DROPIN}" <<SSHDCONF
# joinery-managed - key-only SSH on the relay.
PasswordAuthentication no
ChallengeResponseAuthentication no
PermitRootLogin prohibit-password
SSHDCONF
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true

# Default-deny firewall: SMTP in, WireGuard in, SSH in.
ufw --force reset >/dev/null 2>&1 || true
ufw default deny incoming >/dev/null 2>&1 || true
ufw default allow outgoing >/dev/null 2>&1 || true
ufw allow 25/tcp        >/dev/null 2>&1 || true
ufw allow "${WG_PORT}/udp" >/dev/null 2>&1 || true
ufw allow 22/tcp        >/dev/null 2>&1 || true
ufw --force enable      >/dev/null 2>&1 || true
echo "firewall: default-deny; allow 25/tcp, ${WG_PORT}/udp, 22/tcp"

# --- 9. validate + restart Postfix -------------------------------------------
if postfix check; then
    systemctl restart postfix 2>/dev/null || { postfix stop 2>/dev/null || true; postfix start; }
    echo "Postfix configuration validated and restarted."
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
echo "  Tenants           : $(tenant_count) (add with: sudo bash provision_relay.sh add-tenant <slug> ...)"
echo
echo "Next:"
echo "  1. Add each tenant:  sudo bash provision_relay.sh add-tenant <slug> \\"
echo "         --pull-pubkey '<tenant pull key>' --wg-pubkey '<tenant WG key>' [--domains ...]"
echo "     (the provisioning job does this automatically for a self-hosted relay)"
echo "  2. Point the MX + A records for each tenant's hosted domains at ${PUBLIC_IP}."
echo "  3. Set the relay's PTR record to ${MAIL_HOSTNAME} at your VPS provider."
echo "========================================================"
