#!/usr/bin/env bash
#
# provision_relay.sh - installer + configurator for a HARDENED INGEST RELAY.
#
# Sibling of install_email.sh (colocated mode). This builds the minimal, hardened,
# disposable VPS that fronts the public MX for a relay-fronted deployment: Postfix
# + verify milters + the Go sealing binary, and NOTHING else - no PHP, no
# database, no web, no application, no shell. It accepts mail, verifies it, seals
# it to the recipient's public key at acceptance, and spools ciphertext; the
# deployment's plane pulls its sealed blobs over HTTPS from the relay's own API.
#
# Version: 3.0 - THE RELAY SERVES ITSELF (specs/relay_without_a_shell.md WP1).
#                A relay is a machine with two listeners, Postfix on 25 and one
#                Go binary on 443, and no other way in. Gone: WireGuard, sshd's
#                drop-in, the tenant shell, tenant Unix accounts, the sudoers
#                rule, tunnel addresses, the 8442 egress binding and smarthost.
#                In their place: `relay-sealer relay-serve` on 443 (Direct by SNI
#                on the mail hostname, the signed /relay/ API on the relay's own
#                identity certificate for the plane); a tenant registry of public
#                keys with no accounts; a root path unit that reacts to what the
#                listener files (merge, tenant changes) and a root timer that
#                collects the privileged half of the health ping. The build takes
#                the user-data arguments, creates the relay identity, adds tenant
#                `main` from the client public key, enables timesyncd, turns on
#                unattended-upgrades' automatic reboot, opens 25 and 443 only,
#                and writes the signed birth report. `--keep-sshd` exists for
#                exactly one thing: a hand run on a box you can watch.
# Version: 2.9 - idempotency pass: a second run changes nothing and restarts nothing.
# Version: 2.5 - the relay serves Joinery Direct on 443 (in-process ACME).
# Version: 2.0 - tenancy-native: a self-hosted relay is a fleet of one.
#
# Usage (the first-boot script runs this out of the fetched bundle):
#   sudo bash provision_relay.sh <mail-hostname> --client-public-key <base64 Ed25519> \
#        [--authserv-id <id>] [--operator-public-key <base64>] [--bundle-sha256 <hex>] \
#        [--run-id <id>] [--keep-sshd]
#   sudo bash provision_relay.sh <mail-hostname> --skeleton-only --operator-public-key <base64> [...]
#
# A tenant is: a spool subdirectory, a fragment drop directory, and a root-owned
# registry entry (public key, domain allowlist, shard-policy limits) under
# /opt/joinery-relay/tenants/<slug>/. No Unix account, no peer, no sudoers rule.
# A self-hosted relay gets tenant `main` (allowlist '*') from the build; a fleet
# shard (--skeleton-only) gets its tenants later through the operator-signed
# tenant routes.
#
# The relay is INBOUND ONLY. There is no smarthost and no tunnel submission.
set -euo pipefail

# --- shared definitions --------------------------------------------------------
RELAY_VERSION="3.0"
RELAY_HOME="/opt/joinery-relay"
SEALER_BIN="${RELAY_HOME}/relay-sealer"
SPOOL_ROOT="/var/spool/joinery-relay"
RELAY_USER="joinery-relay"
TENANTS_DIR="${RELAY_HOME}/tenants"
HOMES_DIR="${RELAY_HOME}/home"
REQUESTS_DIR="${RELAY_HOME}/requests"
VERDICTS_DIR="${RELAY_HOME}/verdicts"
STATUS_DIR="${RELAY_HOME}/status"
IDENTITY_DIR="${RELAY_HOME}/identity"
DIRECT_STATE="${RELAY_HOME}/direct"
DIRECT_ACME="${RELAY_HOME}/acme"
BIRTH_DIR="${RELAY_HOME}/birth"
MAP_DOMAINS="/etc/postfix/joinery-relay-domains"
MAP_RECIPIENTS="/etc/postfix/joinery-recipients"
MAP_TRANSPORT="/etc/postfix/joinery-transport"
MAP_SRS="/etc/postfix/joinery-srs"
ROUTING_JSON="${RELAY_HOME}/routing.json"
# The hour unattended-upgrades may reboot for a kernel update. Nobody can ask a
# relay to reboot, so it must be allowed to do it itself; senders retry across
# the minute it takes.
AUTO_REBOOT_TIME="04:17"

if [[ "${EUID}" -ne 0 ]]; then
    echo "This script must run as root (installs packages, edits /etc/postfix)." >&2
    echo "Re-run with: sudo bash $0 ..." >&2
    exit 1
fi

# =============================================================================
# Idempotence helpers
# =============================================================================
# Every mutation below goes through one of these, and every service action is
# conditional on a configuration THIS RUN changed - so a second run on a built
# relay changes nothing and restarts nothing.
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

# Converge the intended rule set instead of 'ufw --force reset', rule by rule:
# 25 and 443, and nothing else. 22 is admitted only by --keep-sshd, and an
# existing 22 rule is REMOVED otherwise, so a box that arrived with SSH open
# does not stay that way once it is a relay.
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

    ufw_allow_once "${rules}" '25/tcp'
    # 443 is the relay's one API: Joinery Direct for public callers and the
    # signed /relay/ routes for the plane, plus the port its ACME certificate is
    # obtained on (TLS-ALPN-01, in-process).
    ufw_allow_once "${rules}" '443/tcp'

    if [[ "${KEEP_SSHD}" -eq 1 ]]; then
        ufw_allow_once "${rules}" '22/tcp'
        echo "firewall: 22/tcp kept open (--keep-sshd: a hand run on a box you can watch)"
    elif printf '%s\n' "${rules}" | grep -qE '^22/tcp[[:space:]]+ALLOW'; then
        ufw delete allow 22/tcp >/dev/null 2>&1 || true
        echo "firewall: 22/tcp rule removed (a relay has no shell)"
    fi
    if printf '%s\n' "${rules}" | grep -qE '^(OpenSSH|22)[[:space:]]+ALLOW'; then
        ufw delete allow OpenSSH >/dev/null 2>&1 || true
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
# Arguments
# =============================================================================

if ! command -v apt-get >/dev/null 2>&1; then
    echo "This installer supports apt-based systems (Debian/Ubuntu) only." >&2
    exit 1
fi

usage() {
    echo "Usage: sudo bash $0 <mail-hostname> --client-public-key <base64> [--authserv-id <id>]" >&2
    echo "         [--operator-public-key <base64>] [--bundle-sha256 <hex>] [--run-id <id>] [--keep-sshd]" >&2
    echo "   or: sudo bash $0 <mail-hostname> --skeleton-only --operator-public-key <base64> [...]" >&2
    exit 1
}

MAIL_HOSTNAME="${1:-}"
if [[ -z "${MAIL_HOSTNAME}" || "${MAIL_HOSTNAME}" != *.* || "${MAIL_HOSTNAME}" == --* ]]; then
    usage
fi
shift

AUTHSERV_ID=""
CLIENT_PUBLIC_KEY=""
OPERATOR_PUBLIC_KEY=""
BUNDLE_SHA256=""
RUN_ID=""
SKELETON_ONLY=0
KEEP_SSHD=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --authserv-id)          AUTHSERV_ID="${2:-}"; shift 2;;
        --client-public-key)    CLIENT_PUBLIC_KEY="${2:-}"; shift 2;;
        --operator-public-key)  OPERATOR_PUBLIC_KEY="${2:-}"; shift 2;;
        --bundle-sha256)        BUNDLE_SHA256="${2:-}"; shift 2;;
        --run-id)               RUN_ID="${2:-}"; shift 2;;
        --skeleton-only)        SKELETON_ONLY=1; shift;;
        --keep-sshd)            KEEP_SSHD=1; shift;;
        *) echo "ERROR: unknown argument $1" >&2; usage;;
    esac
done
[[ -n "${AUTHSERV_ID}" ]] || AUTHSERV_ID="${MAIL_HOSTNAME}"

# A relay with no tenant key and no skeleton flag is a relay nothing could ever
# talk to. Refuse here, loudly, rather than build a box nobody can reach. This
# is also what stops any older caller that still runs this script with only a
# hostname (the SSH-era provisioner) from producing a relay it cannot speak to.
if [[ "${SKELETON_ONLY}" -eq 0 && -z "${CLIENT_PUBLIC_KEY}" ]]; then
    echo "ERROR: --client-public-key is required (or --skeleton-only for a fleet shard)." >&2
    echo "This relay is reached only through its signed HTTPS API; without the plane's" >&2
    echo "public key it would accept mail nobody could ever pull." >&2
    exit 1
fi
if [[ "${SKELETON_ONLY}" -eq 1 && -z "${OPERATOR_PUBLIC_KEY}" ]]; then
    echo "ERROR: --skeleton-only requires --operator-public-key (tenants are added through the operator routes)." >&2
    exit 1
fi
# --keep-sshd is for a hand run on a box you are watching. A provider first
# boot has no terminal, so a rendered user-data that somehow carried the flag
# is refused rather than leaving a relay with a shell.
if [[ "${KEEP_SSHD}" -eq 1 && ! -t 0 ]]; then
    echo "ERROR: --keep-sshd is refused when not started from a terminal." >&2
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
# No postfix-pgsql (no app DB on the relay), no php, NO redis (rspamd is
# stateless), NO compiler (the sealer arrives prebuilt), NO wireguard and NO
# rsync (the plane pulls over the relay's own HTTPS API). curl is for the
# birth report's transport check only; ca-certificates for ACME and the plane.
PACKAGES=(postfix opendkim opendkim-tools opendmarc ufw ca-certificates curl systemd-timesyncd)
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

# --- 2. relay user + directories + the sealer -------------------------------
if ! id -u "${RELAY_USER}" >/dev/null 2>&1; then
    useradd --system --user-group --home-dir "${RELAY_HOME}" --shell /usr/sbin/nologin "${RELAY_USER}"
    echo "created system user ${RELAY_USER}"
fi

mkdir -p "${RELAY_HOME}" "${TENANTS_DIR}" "${HOMES_DIR}" "${REQUESTS_DIR}" "${VERDICTS_DIR}" \
    "${STATUS_DIR}" "${DIRECT_STATE}" "${DIRECT_ACME}" "${BIRTH_DIR}"
chown root:"${RELAY_USER}" "${RELAY_HOME}"
chmod 755 "${RELAY_HOME}"
chmod 755 "${TENANTS_DIR}"
# Fragment drops are root's alone now: the listener files a request and root
# writes the fragment where the merge reads it.
chown root:root "${HOMES_DIR}"
chmod 700 "${HOMES_DIR}"
# The privilege split's three directories. requests/: the listener can WRITE
# and nothing but root can read (0730). verdicts/ and status/: root writes,
# the listener reads (0750, files 0640).
chown root:"${RELAY_USER}" "${REQUESTS_DIR}" "${VERDICTS_DIR}" "${STATUS_DIR}"
chmod 730 "${REQUESTS_DIR}"
chmod 750 "${VERDICTS_DIR}" "${STATUS_DIR}"
chown -R "${RELAY_USER}:${RELAY_USER}" "${DIRECT_STATE}" "${DIRECT_ACME}"
chmod 700 "${DIRECT_STATE}" "${DIRECT_ACME}"
chown root:root "${BIRTH_DIR}"
chmod 755 "${BIRTH_DIR}"

# The spool root holds only per-tenant subdirectories (owner joinery-relay,
# mode 0700 - the sealer pipe writes, the listener lists, serves and acks).
mkdir -p "${SPOOL_ROOT}"
chown root:root "${SPOOL_ROOT}"
chmod 755 "${SPOOL_ROOT}"
# Install the prebuilt sealer, and ONLY when its bytes differ. This one binary
# is the Postfix pipe transport, the merge unit, the root applier AND the
# relay API listener, so replacing it needlessly would restart the API.
# Rename rather than overwrite: a running Direct holds the old inode open, and
# writing in place would earn ETXTBSY.
if [[ -f "${SEALER_BIN}" ]] && cmp -s "${SEALER_SRC}" "${SEALER_BIN}"; then
    echo "relay-sealer: ${SEALER_BIN} already current"
else
    cp -f "${SEALER_SRC}" "${SEALER_BIN}.new"
    chown root:root "${SEALER_BIN}.new"
    chmod 755 "${SEALER_BIN}.new"
    mv -f "${SEALER_BIN}.new" "${SEALER_BIN}"
    mark_changed joinery-relay-serve
    echo "relay-sealer: installed ${SEALER_BIN}"
fi

# What built this relay, so the ping can say so and the plane can tell an old
# relay from a current one without guessing from behaviour.
printf '%s' "${RELAY_VERSION}" > "${RELAY_HOME}/version"
chmod 644 "${RELAY_HOME}/version"
if write_if_changed "${RELAY_HOME}/authserv_id" 644 <<< "${AUTHSERV_ID}"; then :; fi
if [[ ! -f "${RELAY_HOME}/built_at" ]]; then
    date -u +%Y-%m-%dT%H:%M:%SZ > "${RELAY_HOME}/built_at"
    chmod 644 "${RELAY_HOME}/built_at"
fi
if [[ -n "${BUNDLE_SHA256}" ]]; then
    printf '%s\n' "${BUNDLE_SHA256}" > "${RELAY_HOME}/bundle_sha256"
    chmod 644 "${RELAY_HOME}/bundle_sha256"
fi
# The operator key answers to the reserved tenant name "operator" on the
# tenant routes. Absent on a self-hosted relay unless the deployment wants one.
if [[ -n "${OPERATOR_PUBLIC_KEY}" ]]; then
    if write_if_changed "${RELAY_HOME}/operator_public_key" 644 <<< "${OPERATOR_PUBLIC_KEY}"; then
        echo "registry: operator public key installed"
    else
        echo "registry: operator public key already current"
    fi
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

# Loopback only. There is no tunnel and no smarthost: the relay accepts mail
# for its hosted domains from the world and submits nothing for anyone.
postconf_set "mynetworks" "127.0.0.0/8, [::1]/128"

# The relay is authoritative for the hosted domains (merged from tenant
# fragments) and routes each to the sealer pipe. reject_unauth_destination then
# accepts recipients in these and rejects relay attempts for anything else.
postconf_set "relay_domains" "hash:${MAP_DOMAINS}"
postconf_set "transport_maps" "hash:${MAP_TRANSPORT}"

# RBL block — verbatim from install_email.sh — plus SMTP-time recipient
# validation against the merged access map (preserving reject_unmatched: listed
# aliases OK, unmatched under a reject domain REJECTed, no backscatter).
#
# Only Spamhaus rejects. Zen and DBL are built to be rejected on: low false
# positive, and Zen deliberately excludes the shared outbound ranges every ESP
# sends from. SpamCop and Barracuda list those shared IPs on brief automated
# triggers and de-list hours later, so rejecting on them bounces ordinary mail
# from Mailgun, SendGrid or Google at random — permanently, since a 5xx stops
# the sender retrying. SpamCop says as much itself: use it to score, not to
# refuse. Content scoring is where a weaker signal belongs.
postconf_set "smtpd_recipient_restrictions" "reject_unauth_destination, reject_rbl_client zen.spamhaus.org, reject_rhsbl_helo dbl.spamhaus.org, reject_rhsbl_sender dbl.spamhaus.org, check_recipient_access regexp:${MAP_SRS}, check_recipient_access hash:${MAP_RECIPIENTS}, permit"
echo "main.cf: relay_domains, transport, recipient validation, RBL, anvil limits set"


# --- 6. opendkim + opendmarc (verify-mode, verbatim from install_email.sh) ----
# AUTHSERV_ID came from the arguments (default: the mail hostname); it is what
# the relay stamps and what the plane's RemoveARFrom strips.
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
    # --no-install-recommends: rspamd RECOMMENDS redis-server, and a relay
    # whose rspamd is stateless has no use for a redis it would never configure
    # — one more listener on a machine that is meant to have two.
    apt-get install -y --no-install-recommends "${CS_MISSING[@]}"
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

# --- 7. relay identity ---------------------------------------------------------
# An Ed25519 key and a self-signed certificate for it, generated once. The plane
# pins its SPKI fingerprint from the signed birth report and connects by IP; it
# is what the WireGuard public key was, with one fewer key. Created here as
# root and handed to the relay user, so the listener and the birth report read
# one identity rather than racing to create two.
IDENTITY_OUT="$("${SEALER_BIN}" identity-init --home "${RELAY_HOME}")"
IDENTITY_FINGERPRINT="$(printf '%s\n' "${IDENTITY_OUT}" | sed -n 's/^IDENTITY_FINGERPRINT=//p')"
chown -R "${RELAY_USER}:${RELAY_USER}" "${IDENTITY_DIR}"
chmod 700 "${IDENTITY_DIR}"
chmod 600 "${IDENTITY_DIR}/identity.key"
echo "identity: ${IDENTITY_FINGERPRINT}"

# --- 7b. tenant main -------------------------------------------------------------
# A self-hosted relay is a fleet of one: tenant `main`, allowlist '*', keyed by
# the plane's relay client identity from the user-data. The same code path the
# operator routes use (relay-sealer tenant-add), so there is one registry layout.
# It runs the merge, which installs the placeholder maps' first real content.
if [[ "${SKELETON_ONLY}" -eq 0 ]]; then
    TENANT_OUT="$("${SEALER_BIN}" tenant-add --slug main --public-key "${CLIENT_PUBLIC_KEY}" --domains '*')" \
        || { echo "ERROR: could not register tenant main: ${TENANT_OUT}" >&2; exit 1; }
    echo "registry: tenant main registered"
else
    echo "registry: skeleton only - no tenant; the operator adds them through the tenant routes"
    # A skeleton still needs a merged (empty) map so Postfix has consistent maps.
    "${SEALER_BIN}" merge-maps >/dev/null || echo "WARN: initial merge reported a problem" >&2
fi

# --- 7c. the listener and root's two reactions ---------------------------------
# relay-serve: one process, the relay user, 443. Direct by SNI on the mail
# hostname (ACME, in-process over TLS-ALPN-01); the signed /relay/ API on the
# identity certificate for everything else. It never gains root: it may write
# its own state, the ACME cache, the spool and the request drop, and nothing
# else on the machine.
if write_if_changed /etc/systemd/system/joinery-relay-serve.service 644 <<SERVEUNIT
[Unit]
Description=Joinery relay API (Direct + the plane's signed routes)
Documentation=https://github.com/getjoinery/joinery/blob/main/public_html/specs/relay_without_a_shell.md
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${RELAY_USER}
Group=${RELAY_USER}
Environment=JOINERY_RELAY_HOME=${RELAY_HOME}
Environment=JOINERY_RELAY_SPOOL_ROOT=${SPOOL_ROOT}
ExecStart=${SEALER_BIN} relay-serve --hostname ${MAIL_HOSTNAME} \\
    --home ${RELAY_HOME} \\
    --spool ${SPOOL_ROOT} \\
    --cert-cache ${DIRECT_ACME} \\
    --state ${DIRECT_STATE}
Restart=always
RestartSec=5
# Binding 443 as an unprivileged user, and nothing else.
AmbientCapabilities=CAP_NET_BIND_SERVICE
CapabilityBoundingSet=CAP_NET_BIND_SERVICE
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=${DIRECT_STATE} ${DIRECT_ACME} ${IDENTITY_DIR} ${REQUESTS_DIR} ${SPOOL_ROOT}
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_INET AF_INET6
RestrictNamespaces=yes
LockPersonality=yes
MemoryDenyWriteExecute=yes

[Install]
WantedBy=multi-user.target
SERVEUNIT
then
    mark_changed joinery-relay-serve
    mark_changed units
    echo "joinery-relay-serve: unit written"
else
    echo "joinery-relay-serve: unit already correct."
fi

# apply-requests: root reacts to a FILE. The listener drops a validated,
# signature-authenticated request into requests/; this fires, re-validates the
# file (regular, owned by the listener, under the cap, well-formed), performs
# the merge or the tenant change, and leaves a verdict the listener returns to
# the caller. The sudo rule the tenant shell had is this path unit.
if write_if_changed /etc/systemd/system/joinery-relay-apply.path 644 <<APPLYPATH
[Unit]
Description=Joinery relay: react to a filed request

[Path]
PathChanged=${REQUESTS_DIR}
DirectoryNotEmpty=${REQUESTS_DIR}
Unit=joinery-relay-apply.service

[Install]
WantedBy=multi-user.target
APPLYPATH
then
    mark_changed units
    echo "joinery-relay-apply.path: unit written"
fi
if write_if_changed /etc/systemd/system/joinery-relay-apply.service 644 <<APPLYUNIT
[Unit]
Description=Joinery relay: apply filed requests (merge, tenant changes)

[Service]
Type=oneshot
Environment=JOINERY_RELAY_HOME=${RELAY_HOME}
Environment=JOINERY_RELAY_SPOOL_ROOT=${SPOOL_ROOT}
Environment=JOINERY_RELAY_USER=${RELAY_USER}
ExecStart=${SEALER_BIN} apply-requests
APPLYUNIT
then
    mark_changed units
    echo "joinery-relay-apply.service: unit written"
fi

# collect-status: root reacts to a TIMER. Every thirty seconds it gathers the
# privileged half of the ping (unit state, firewall, journal excerpt, Postfix
# counts, reboot_required) into status/, which the listener merges with what
# it measures itself. Root never runs anything because a request asked.
if write_if_changed /etc/systemd/system/joinery-relay-collect.timer 644 <<COLLECTTIMER
[Unit]
Description=Joinery relay: collect privileged health facts

[Timer]
OnBootSec=20s
OnUnitActiveSec=30s
AccuracySec=5s
Unit=joinery-relay-collect.service

[Install]
WantedBy=timers.target
COLLECTTIMER
then
    mark_changed units
    echo "joinery-relay-collect.timer: unit written"
fi
if write_if_changed /etc/systemd/system/joinery-relay-collect.service 644 <<COLLECTUNIT
[Unit]
Description=Joinery relay: collect privileged health facts (one pass)

[Service]
Type=oneshot
Environment=JOINERY_RELAY_HOME=${RELAY_HOME}
Environment=JOINERY_RELAY_SPOOL_ROOT=${SPOOL_ROOT}
Environment=JOINERY_RELAY_USER=${RELAY_USER}
ExecStart=${SEALER_BIN} collect-status
COLLECTUNIT
then
    mark_changed units
    echo "joinery-relay-collect.service: unit written"
fi

if changed units; then
    systemctl daemon-reload >/dev/null 2>&1 || true
fi
systemctl enable joinery-relay-serve joinery-relay-apply.path joinery-relay-collect.timer >/dev/null 2>&1 || true
sync_service joinery-relay-serve restart
sync_service joinery-relay-apply.path restart
sync_service joinery-relay-collect.timer restart
# One collector pass now, so the birth report and the first ping have facts.
systemctl start joinery-relay-collect.service >/dev/null 2>&1 || true
echo "relay API: listening on 443 for ${MAIL_HOSTNAME} (Direct by SNI; /relay/ on the identity certificate)"

# --- 8. hardening ------------------------------------------------------------
# Unattended security upgrades, and the reboot nobody can request: the relay
# installs its own security updates and reboots at a fixed hour when a kernel
# needs it. Senders retry across the minute; the ping reports reboot_required
# in between.
if ! dpkg -s unattended-upgrades >/dev/null 2>&1; then
    apt-get install -y unattended-upgrades
fi
AUTO_UPGRADES=/etc/apt/apt.conf.d/20auto-upgrades
if [[ -f "${AUTO_UPGRADES}" ]] \
   && grep -qE '^APT::Periodic::Update-Package-Lists[[:space:]]+"1";' "${AUTO_UPGRADES}" \
   && grep -qE '^APT::Periodic::Unattended-Upgrade[[:space:]]+"1";' "${AUTO_UPGRADES}"; then
    echo "unattended-upgrades: already configured - left alone"
else
    dpkg-reconfigure -f noninteractive unattended-upgrades >/dev/null 2>&1 || true
    echo "unattended-upgrades: configured"
fi
if write_if_changed /etc/apt/apt.conf.d/52joinery-relay-reboot 644 <<REBOOTCONF
// joinery-managed - a relay has no shell, so it reboots itself for a kernel
// update at a fixed hour. specs/relay_without_a_shell.md.
Unattended-Upgrade::Automatic-Reboot "true";
Unattended-Upgrade::Automatic-Reboot-Time "${AUTO_REBOOT_TIME}";
REBOOTCONF
then
    echo "unattended-upgrades: automatic reboot at ${AUTO_REBOOT_TIME} enabled"
fi

# A synchronized clock: the API refuses stale and future timestamps, so the
# relay must know the time.
systemctl enable systemd-timesyncd >/dev/null 2>&1 || true
systemctl start systemd-timesyncd >/dev/null 2>&1 || true
timedatectl set-ntp true >/dev/null 2>&1 || true
echo "clock: systemd-timesyncd enabled"

# Default-deny firewall: SMTP in, HTTPS in, nothing else (22 only for --keep-sshd).
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

# --- 10. the birth report and what a plane needs --------------------------------
# Signed by the identity key over the canonical body, written here for the
# console and for the first-boot script to post once sshd is gone. The plane
# believes it only after dialling the provider's address with the fingerprint
# pinned, so nothing here is a secret.
if [[ -n "${RUN_ID}" ]]; then
    "${SEALER_BIN}" birth-report --home "${RELAY_HOME}" --run-id "${RUN_ID}" --out "${BIRTH_DIR}/report.json" \
        || echo "WARN: the birth report could not be written" >&2
fi
PUBLIC_IP="$(ip -4 route get 1.1.1.1 2>/dev/null | sed -n 's/.* src \([0-9.]*\).*/\1/p' | head -1)"
[[ -n "${PUBLIC_IP}" ]] || PUBLIC_IP="unknown"
echo
echo "================= HARDENED RELAY READY ================="
echo "RELAY_READY"
echo "  Mail hostname     : ${MAIL_HOSTNAME}"
echo "  Authserv-id       : ${AUTHSERV_ID}"
echo "  Relay public IP   : ${PUBLIC_IP}"
echo "  Identity pin      : ${IDENTITY_FINGERPRINT}"
echo "  Relay API         : https://${PUBLIC_IP}/relay/ (signed; pinned identity)"
echo "  Joinery Direct    : https://${MAIL_HOSTNAME}:443 (SRV target for Fortress tenants)"
echo "  Tenants           : $(find "${TENANTS_DIR}" -mindepth 1 -maxdepth 1 -type d | wc -l)"
echo "  Shell             : $([[ "${KEEP_SSHD}" -eq 1 ]] && echo 'KEPT (--keep-sshd, hand run)' || echo 'none - the first-boot script removes sshd')"
echo
echo "Next:"
echo "  1. Point the MX + A records for each hosted domain at ${PUBLIC_IP}."
echo "  2. Set the relay's PTR record to ${MAIL_HOSTNAME} at your VPS provider."
echo "  3. For Joinery Direct, publish each tenant's capability record LAST -"
echo "     _joinery._tcp -> ${MAIL_HOSTNAME}:443 - once this relay answers there."
echo "========================================================"
