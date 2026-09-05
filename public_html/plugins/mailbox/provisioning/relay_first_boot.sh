#!/usr/bin/env bash
#
# relay_first_boot.sh - the user-data a relay is born from.
#
# specs/relay_without_a_shell.md § Birth. The plane renders this TEMPLATE (the
# double-underscore placeholders below) into the provider's first-boot user-data; the provider
# runs it once, as root, on a fresh image. It fetches the release's support
# bundle from the plane over the plane's own TLS, refuses a sha256 mismatch,
# runs provision_relay.sh out of it, removes sshd, and posts the signed birth
# report with the one-time run token. It logs to the provider console and
# nowhere else.
#
# The user-data carries only public keys, the bundle's hash, the plane's URL
# and a run token that is single-use, bound to the run, expires with the boot
# timeout and is erased with the run's other credentials. Nothing in it outlives
# the boot.
#
# HAND RUN (the WP1 proof, on a box you can watch): render the placeholders by
# hand and run it from a terminal with --keep-sshd, which keeps sshd and port 22
# so the box is not locked. The flag is refused when stdin is not a terminal,
# so a rendered user-data can never carry it into a provider boot.
#
#   sed -e 's#__PLANE__#https://dev.example#' -e 's#__RUN_ID__#17#' ... relay_first_boot.sh > /root/first_boot.sh
#   bash /root/first_boot.sh --keep-sshd
#
# Version: 1.1 - placeholders fall back to UDF environment variables, so one template
#                serves both rendered user-data and the StackScript fallback
set -euo pipefail

# Rendered user-data bakes the values in; a StackScript leaves the placeholders
# and supplies the same names as UDF environment variables. Either way an
# unrendered, unset value is refused below.
PLANE="${PLANE:-__PLANE__}"
RUN_ID="${RUN_ID:-__RUN_ID__}"
RUN_TOKEN="${RUN_TOKEN:-__RUN_TOKEN__}"
BUNDLE_SHA256="${BUNDLE_SHA256:-__BUNDLE_SHA256__}"
MAIL_HOSTNAME="${MAIL_HOSTNAME:-__MAIL_HOSTNAME__}"
AUTHSERV_ID="${AUTHSERV_ID:-__AUTHSERV_ID__}"
CLIENT_PUBLIC_KEY="${CLIENT_PUBLIC_KEY:-__CLIENT_PUBLIC_KEY__}"
OPERATOR_PUBLIC_KEY="${OPERATOR_PUBLIC_KEY:-__OPERATOR_PUBLIC_KEY__}"
SKELETON_ONLY="${SKELETON_ONLY:-__SKELETON_ONLY__}"

RELAY_HOME="/opt/joinery-relay"
BOOTSTRAP="${RELAY_HOME}/bootstrap"
BUNDLE_PATH_IN_TREE="public_html/plugins/mailbox/provisioning/provision_relay.sh"

KEEP_SSHD=0
for arg in "$@"; do
    case "${arg}" in
        --keep-sshd) KEEP_SSHD=1;;
        *) echo "first-boot: unknown argument ${arg}" >&2; exit 2;;
    esac
done
if [[ "${KEEP_SSHD}" -eq 1 && ! -t 0 ]]; then
    echo "first-boot: --keep-sshd is refused when not started from a terminal" >&2
    exit 2
fi
if [[ "${EUID}" -ne 0 ]]; then
    echo "first-boot: must run as root" >&2
    exit 1
fi

say() { echo "first-boot: $*"; }

# A placeholder that was not rendered is a template, not a relay.
for v in PLANE RUN_ID RUN_TOKEN BUNDLE_SHA256 MAIL_HOSTNAME; do
    if [[ "${!v}" == __*__ || -z "${!v}" ]]; then
        say "ERROR: ${v} was not rendered"; exit 1
    fi
done
[[ "${AUTHSERV_ID}" == __*__ ]] && AUTHSERV_ID="${MAIL_HOSTNAME}"
[[ "${CLIENT_PUBLIC_KEY}" == __*__ ]] && CLIENT_PUBLIC_KEY=""
[[ "${OPERATOR_PUBLIC_KEY}" == __*__ ]] && OPERATOR_PUBLIC_KEY=""
[[ "${SKELETON_ONLY}" == "1" ]] || SKELETON_ONLY=0
if [[ ! "${BUNDLE_SHA256}" =~ ^[0-9a-f]{64}$ ]]; then
    say "ERROR: bundle_sha256 is not a sha256"; exit 1
fi

say "relay first boot for ${MAIL_HOSTNAME} (run ${RUN_ID}, plane ${PLANE})"
export DEBIAN_FRONTEND=noninteractive

# --- 1. what the fetch needs -------------------------------------------------------
if ! command -v curl >/dev/null 2>&1 || ! dpkg -s ca-certificates >/dev/null 2>&1; then
    apt-get update -qq
    apt-get install -y curl ca-certificates
fi

# --- 2. fetch the bundle the run was created against ------------------------------
mkdir -p "${BOOTSTRAP}"
chmod 700 "${BOOTSTRAP}"
TOKEN_FILE="${BOOTSTRAP}/run_token"
umask 077
printf '%s' "${RUN_TOKEN}" > "${TOKEN_FILE}"
umask 022
RUN_TOKEN=""

BUNDLE="${BOOTSTRAP}/support_bundle.tar.gz"
say "fetching the bundle (${BUNDLE_SHA256})"
if ! curl -fsS --retry 8 --retry-delay 10 --retry-all-errors --max-time 300 \
        -H "X-Joinery-Relay-Run-Token: $(cat "${TOKEN_FILE}")" \
        -o "${BUNDLE}" \
        "${PLANE%/}/api/v1/relay/bundle?run=${RUN_ID}&sha256=${BUNDLE_SHA256}"; then
    say "ERROR: could not fetch the bundle from the plane"; exit 1
fi
GOT="$(sha256sum "${BUNDLE}" | cut -d' ' -f1)"
if [[ "${GOT}" != "${BUNDLE_SHA256}" ]]; then
    say "ERROR: bundle sha256 mismatch (got ${GOT}); refusing to run it"
    rm -f "${BUNDLE}"
    exit 1
fi
say "bundle verified"

mkdir -p "${BOOTSTRAP}/bundle"
tar -xzf "${BUNDLE}" -C "${BOOTSTRAP}/bundle"
SCRIPT="${BOOTSTRAP}/bundle/${BUNDLE_PATH_IN_TREE}"
if [[ ! -f "${SCRIPT}" ]]; then
    say "ERROR: the bundle carries no ${BUNDLE_PATH_IN_TREE}"; exit 1
fi

# --- 3. build ----------------------------------------------------------------------
ARGS=("${MAIL_HOSTNAME}" --authserv-id "${AUTHSERV_ID}" --bundle-sha256 "${BUNDLE_SHA256}" --run-id "${RUN_ID}")
if [[ "${SKELETON_ONLY}" -eq 1 ]]; then
    ARGS+=(--skeleton-only)
else
    ARGS+=(--client-public-key "${CLIENT_PUBLIC_KEY}")
fi
[[ -n "${OPERATOR_PUBLIC_KEY}" ]] && ARGS+=(--operator-public-key "${OPERATOR_PUBLIC_KEY}")
[[ "${KEEP_SSHD}" -eq 1 ]] && ARGS+=(--keep-sshd)
say "running provision_relay.sh"
bash "${SCRIPT}" "${ARGS[@]}"

# --- 4. no shell -------------------------------------------------------------------
if [[ "${KEEP_SSHD}" -eq 1 ]]; then
    say "sshd KEPT (--keep-sshd): this is a hand run, not a relay anyone should keep"
else
    say "removing sshd"
    systemctl disable --now ssh.socket ssh.service sshd.service >/dev/null 2>&1 || true
    apt-get purge -y openssh-server >/dev/null 2>&1 || true
    rm -rf /root/.ssh /etc/ssh/sshd_config /etc/ssh/sshd_config.d
    # The provider's own account keys, if the image planted any, go with it.
    find /home -maxdepth 3 -name authorized_keys -exec rm -f {} + 2>/dev/null || true
fi

# --- 5. tell the plane -------------------------------------------------------------
# Retried: the plane may be mid-request when we first call. The token file is
# read by the binary; it never appears on a command line.
say "posting the birth report"
posted=0
for attempt in 1 2 3 4 5 6; do
    if "${RELAY_HOME}/relay-sealer" birth-report --home "${RELAY_HOME}" --run-id "${RUN_ID}" \
            --out "${RELAY_HOME}/birth/report.json" --post "${PLANE}" --token-file "${TOKEN_FILE}"; then
        posted=1
        break
    fi
    say "birth report attempt ${attempt} failed; retrying"
    sleep 10
done

# --- 6. nothing of the boot outlives it -------------------------------------------
rm -rf "${BOOTSTRAP}"
if [[ "${posted}" -eq 1 ]]; then
    say "FIRST_BOOT_DONE"
    exit 0
fi
say "FIRST_BOOT_BUILT_BUT_UNREPORTED - the plane did not accept the birth report; see ${RELAY_HOME}/birth/report.json"
exit 1
