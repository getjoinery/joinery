#!/usr/bin/env bash
#
# rspamd_stateless.sh - the ONE rspamd configuration every joinery box writes
# (spam_learning_in_core.md § One rspamd configuration). Sourced, never
# run: provision_spam_scanner.sh (a deployment's own box) and provision_relay.sh
# (a relay) both source it and write the same files from it.
#
# Version: 1.2 - The milter listens on the loopback only, and "local" means the loopback
#                only (override.d/options.inc): rspamd signs local mail and leaves its
#                headers alone, so neither may be reachable or claimable from outside
#                this box. The GTUBE test string no longer makes rspamd refuse a
#                message. A key is adopted when the key FILE is missing, through a
#                temporary name; the old copy is dropped by rspamd_dkim_drop_old_keys.
# Version: 1.1 - rspamd is the only checker (mail_checking_in_rspamd.md): it strips
#                every Authentication-Results line a message arrives with, on
#                purpose, and writes the one the app reads. A deployment's own
#                box also has it sign outgoing mail (RSPAMD_SIGNING_FILES), and
#                mail_checkers_retire_old removes opendkim and opendmarc.
# Version: 1.0
#
# rspamd here is a header-stamping milter and nothing else:
#   - static rules only (phishing, malformed MIME, fingerprints, URL lists,
#     auth-aware scoring), maintained upstream;
#   - the SPF, DKIM and DMARC verdicts, in the one Authentication-Results line
#     AuthenticationResults reads. rspamd writes it under the name Postfix
#     gives it (myhostname), so that name is never configured here;
#   - add_header only, NEVER reject or greylist (the reviewable-verdict model:
#     a wrong guess costs a click, never a lost message);
#   - Bayes OFF and no autolearn, no redis, no controller the app talks to.
#     Spam learning lives in the application (SpamBayes), in Postgres, where
#     it is taught from what users mark and visible to tests.
#
# The X-Spam header NAMES are the contract InboundEmailRouter::readSpamHeader()
# parses; keep them in step with that class's SPAM_*_HEADER constants.
#
# Interface for the caller:
#   RSPAMD_STATELESS_FILES   the local.d files this configuration owns
#   rspamd_stateless_render <file>
#                            print one file's content on stdout
#
#   RSPAMD_STATELESS_OVERRIDE_FILES
#                            the override.d files it owns, rendered the same way
#   RSPAMD_SIGNING_FILES     the local.d files that make rspamd sign outgoing
#                            mail, rendered the same way. Only a deployment's
#                            own box writes them: a relay sends nothing.
#   RSPAMD_DKIM_DIR, RSPAMD_DKIM_MAP
#                            where signing keys live and the list of domains
#                            that have one
#   rspamd_dkim_render_map   print that list from the key directory
#   rspamd_dkim_secure_keys  assert who may read the keys
#   rspamd_dkim_adopt_old_keys
#                            copy keys opendkim signed with into the directory
#   rspamd_dkim_drop_old_keys, rspamd_dkim_forget_old_key
#                            delete the old copies, all or one domain's
#   rspamd_milter_answers    is rspamd listening for Postfix
#   mail_checkers_retire_old remove opendkim and opendmarc from this box
#
# The caller writes each file with its own write-if-changed helper and reloads
# rspamd when anything changed. A local.d file NOT in the list is never touched,
# so a hand-written override elsewhere in local.d survives.

RSPAMD_STATELESS_FILES=(
    "milter_headers.conf"
    "actions.conf"
    "classifier-bayes.conf"
    "rbl.conf"
    "worker-proxy.inc"
)

# Written to override.d, beside local.d, because a local.d file is MERGED with
# rspamd's own: a list set there is appended to the stock list, never replaced.
RSPAMD_STATELESS_OVERRIDE_FILES=(
    "options.inc"
)

RSPAMD_SIGNING_FILES=(
    "dkim_signing.conf"
)

# A domain's key is ${RSPAMD_DKIM_DIR}/<domain>/mail.private, its DNS record
# beside it in mail.txt (provision_dkim.sh). The directory is the truth: a
# domain signs exactly while its key is there, and the map is written from it.
RSPAMD_DKIM_DIR="${RSPAMD_DKIM_DIR:-/etc/rspamd/dkim}"
RSPAMD_DKIM_MAP="${RSPAMD_DKIM_DIR}/signing.map"
RSPAMD_DKIM_SELECTOR="mail"
# Where opendkim kept the same keys, on a box that signed with it.
RSPAMD_OLD_DKIM_DIR="${RSPAMD_OLD_DKIM_DIR:-/etc/opendkim/keys}"

# One "<domain> <private key path>" line per key in the directory, sorted so
# the same directory always renders the same file. rspamd re-reads the map on
# its own when it changes; nothing has to be restarted for a new key.
rspamd_dkim_render_map() {
    local key domain
    for key in "${RSPAMD_DKIM_DIR}"/*/"${RSPAMD_DKIM_SELECTOR}.private"; do
        [[ -f "${key}" ]] || continue
        domain="$(basename "$(dirname "${key}")")"
        printf '%s %s\n' "${domain}" "${key}"
    done | LC_ALL=C sort
}

# rspamd signs with a key, and where a relay fronts the deployment the app
# signs with the same key itself (MailboxDkimSigner), so the web server's group
# reads it. Nobody else does. Asserted on every run, which is also what hands a
# key moved from opendkim to rspamd.
rspamd_dkim_secure_keys() {
    local web_group="www-data" key dir
    getent group "${web_group}" >/dev/null 2>&1 || web_group="apache"
    mkdir -p "${RSPAMD_DKIM_DIR}"
    chown root:root "${RSPAMD_DKIM_DIR}"
    chmod 755 "${RSPAMD_DKIM_DIR}"
    for key in "${RSPAMD_DKIM_DIR}"/*/"${RSPAMD_DKIM_SELECTOR}.private"; do
        [[ -f "${key}" ]] || continue
        dir="$(dirname "${key}")"
        chown root:root "${dir}"
        chmod 755 "${dir}"
        chown "_rspamd:${web_group}" "${key}"
        chmod 640 "${key}"
        if [[ -f "${dir}/${RSPAMD_DKIM_SELECTOR}.txt" ]]; then
            chown root:root "${dir}/${RSPAMD_DKIM_SELECTOR}.txt"
            chmod 644 "${dir}/${RSPAMD_DKIM_SELECTOR}.txt"
        fi
    done
}

# A box that signed with opendkim keeps its keys under RSPAMD_OLD_DKIM_DIR, in
# the same <domain>/mail.private layout. Copy each one here. Copied, not moved:
# opendkim is still signing with the old one until Postfix is told otherwise,
# and mail_checkers_retire_old deletes the old copy once it is. A domain that
# already has a key here keeps it.
rspamd_dkim_adopt_old_keys() {
    local dir domain target staging
    [[ -d "${RSPAMD_OLD_DKIM_DIR}" ]] || return 0
    mkdir -p "${RSPAMD_DKIM_DIR}"
    for dir in "${RSPAMD_OLD_DKIM_DIR}"/*/; do
        [[ -f "${dir}${RSPAMD_DKIM_SELECTOR}.private" ]] || continue
        domain="$(basename "${dir}")"
        target="${RSPAMD_DKIM_DIR}/${domain}"
        # The key FILE decides, not the directory: a directory left without
        # its key by an interrupted run must still get it.
        [[ -f "${target}/${RSPAMD_DKIM_SELECTOR}.private" ]] && continue
        # Copied under a name the signing list never reads, then renamed, so a
        # half-written key is never one rspamd signs with or a later run skips.
        staging="${RSPAMD_DKIM_DIR}/.adopting-${domain}"
        rm -rf "${staging:?}"
        cp -a "${dir%/}" "${staging}"
        if [[ -d "${target}" ]]; then
            if [[ -f "${staging}/${RSPAMD_DKIM_SELECTOR}.txt" && ! -f "${target}/${RSPAMD_DKIM_SELECTOR}.txt" ]]; then
                mv -f "${staging}/${RSPAMD_DKIM_SELECTOR}.txt" "${target}/${RSPAMD_DKIM_SELECTOR}.txt"
            fi
            mv -f "${staging}/${RSPAMD_DKIM_SELECTOR}.private" "${target}/${RSPAMD_DKIM_SELECTOR}.private"
            rm -rf "${staging:?}"
        else
            mv "${staging}" "${target}"
        fi
        echo "dkim: copied the signing key for ${domain} to ${target}"
    done
}

# Delete the directory opendkim kept its keys and tables in (the parent of
# RSPAMD_OLD_DKIM_DIR), but only when every private key in it has an identical
# copy under RSPAMD_DKIM_DIR: a key rspamd does not hold is never destroyed.
# Returns 0 when the directory is gone, 1 when it was kept.
rspamd_dkim_drop_old_keys() {
    local old_root key kept=0
    old_root="$(dirname "${RSPAMD_OLD_DKIM_DIR}")"
    # This directory is deleted whole. Refuse anything that is not a named
    # directory at least one level below the root.
    case "${old_root}" in
        /|.|..|""|/.|/..) echo "WARNING: refusing to treat '${old_root}' as the old key directory." >&2; return 1 ;;
    esac
    [[ "${old_root}" == /*/* ]] || { echo "WARNING: refusing to treat '${old_root}' as the old key directory." >&2; return 1; }
    [[ -d "${old_root}" ]] || return 0
    while IFS= read -r key; do
        if ! cmp -s "${key}" "${RSPAMD_DKIM_DIR}/$(basename "$(dirname "${key}")")/$(basename "${key}")"; then
            echo "WARNING: ${key} has no identical copy under ${RSPAMD_DKIM_DIR} - ${old_root} left in place." >&2
            kept=1
        fi
    done < <(find "${old_root}" -name '*.private' 2>/dev/null)
    if [[ "${kept}" -eq 1 ]]; then
        return 1
    fi
    rm -rf "${old_root:?}"
    echo "mail checkers: removed ${old_root}"
    return 0
}

# Take one domain's key out of the directory opendkim kept it in, on a box
# where that copy still exists. A removed key must not be read from there by
# the app, or copied back by the next installer run.
rspamd_dkim_forget_old_key() {
    local domain="$1"
    if [[ -n "${domain}" && -d "${RSPAMD_OLD_DKIM_DIR}/${domain}" ]]; then
        rm -rf "${RSPAMD_OLD_DKIM_DIR:?}/${domain:?}"
        echo "dkim: removed the old copy at ${RSPAMD_OLD_DKIM_DIR}/${domain}"
    fi
}

# Is rspamd answering Postfix on its milter port?
rspamd_milter_answers() {
    (echo >/dev/tcp/127.0.0.1/11332) >/dev/null 2>&1
}

# opendkim and opendmarc did what rspamd does alone. Call this only AFTER
# Postfix's milter lists name rspamd alone and Postfix has re-read them, so no
# message is ever handed to a program that is going away.
#
# And only while rspamd is answering: with it down, removing the old programs
# would leave outgoing mail unsigned and arriving mail unchecked with nothing
# left to fall back on. A box in that state keeps them until a run that finds
# rspamd up. Safe to call on every run.
mail_checkers_retire_old() {
    local svc purge=()

    if ! rspamd_milter_answers; then
        echo "WARNING: rspamd is not answering on 11332 - opendkim and opendmarc are left as they are; re-run once rspamd is up." >&2
        return 0
    fi

    for svc in opendkim opendmarc; do
        if systemctl list-unit-files "${svc}.service" --no-legend 2>/dev/null | grep -q "${svc}"; then
            systemctl disable --now "${svc}" >/dev/null 2>&1 || true
        fi
        service "${svc}" stop >/dev/null 2>&1 || true
    done
    for svc in opendkim opendkim-tools opendmarc; do
        if dpkg-query -W -f='${Status}' "${svc}" 2>/dev/null | grep -q '^install ok installed$'; then
            purge+=("${svc}")
        fi
    done
    if [[ ${#purge[@]} -gt 0 ]]; then
        # Named packages only, no autoremove: nothing this script did not
        # install is taken with them.
        if DEBIAN_FRONTEND=noninteractive apt-get purge -y "${purge[@]}" >/dev/null 2>&1; then
            echo "mail checkers: purged ${purge[*]} (rspamd checks and signs alone)"
        else
            echo "WARNING: could not purge ${purge[*]} - remove them by hand (apt-get purge ${purge[*]})." >&2
        fi
    fi

    rm -f /etc/opendkim.conf /etc/opendkim.conf.pre-joinery \
          /etc/opendmarc.conf /etc/opendmarc.conf.pre-joinery \
          /etc/default/opendkim /etc/default/opendmarc
    rm -rf /run/opendkim /run/opendmarc
    rspamd_dkim_drop_old_keys || true
}

rspamd_stateless_render() {
    case "$1" in
        milter_headers.conf)
            cat <<'RSPAMDHDR'
# joinery-managed - content spam header contract (InboundEmailRouter::readSpamHeader).
extended_spam_headers = true;
use = ["spam-header", "x-spam-status", "authentication-results"];
# spam-header adds 'X-Spam: Yes' on a spam verdict; x-spam-status adds
# 'X-Spam-Status: Yes|No, score=...' on every scan. The app reads the flag and
# the score from these.
#
# authentication-results writes the SPF, DKIM and DMARC verdicts the app reads
# (AuthenticationResults), under the name Postfix gives rspamd. remove = 0
# strips EVERY Authentication-Results line the message arrived with first, so
# a sender cannot supply a "passed" line in our name.
routines {
  authentication-results {
    remove = 0;
  }
}
RSPAMDHDR
            ;;
        dkim_signing.conf)
            cat <<RSPAMDSIGN
# joinery-managed - rspamd signs what this box itself sends, for a domain that
# has a key. ${RSPAMD_DKIM_MAP} lists those domains, one
# "<domain> <key path>" line each, written from the key directory by
# provision_dkim.sh. A domain not in it is sent unsigned; mail arriving from
# outside is never signed.
selector = "${RSPAMD_DKIM_SELECTOR}";
path_map = "${RSPAMD_DKIM_MAP}";
try_fallback = false;
# The key is chosen by the exact domain in the From header, whatever the
# envelope sender is (a forward leaves under a rewritten one).
use_domain = "header";
use_esld = false;
allow_hdrfrom_mismatch = true;
allow_hdrfrom_mismatch_local = true;
allow_username_mismatch = true;
sign_local = true;
RSPAMDSIGN
            ;;
        actions.conf)
            cat <<'RSPAMDACT'
# joinery-managed - header-stamping only; rejection disabled (out of scope).
reject = null;
greylist = null;
add_header = 6;
RSPAMDACT
            ;;
        classifier-bayes.conf)
            cat <<'RSPAMDBAYES'
# joinery-managed - STATELESS: Bayes off. Spam learning lives in the
# application's own corpus, taught from what users mark. rspamd's stock
# statistic.conf still declares a redis-backed classifier, so this file stays
# (rather than being deleted) to switch it off without an error on every scan.
enabled = false;
autolearn = false;
RSPAMDBAYES
            ;;
        rbl.conf)
            cat <<'RSPAMDRBL'
# joinery-managed - DNS lists that cannot answer this box.
# NiX Spam (ix.dnsbl.manitu.net) was shut down; its zone has no nameservers, so
# every query waits out the DNS retransmits (timeout 1s x 5 = ~5s) and stalls
# the scan, up to the 8s task timeout.
# SURBL and URIBL refuse queries that arrive through a shared public resolver
# (the provider's DNS this box uses): every answer is a "blocked" code, never
# a verdict. Asking them only spends lookups.
rbls {
  nixspam {
    enabled = false;
  }
  "SURBL_MULTI" {
    enabled = false;
  }
  "SURBL_HASHBL" {
    enabled = false;
  }
  "URIBL_MULTI" {
    enabled = false;
  }
}
RSPAMDRBL
            ;;
        worker-proxy.inc)
            cat <<'RSPAMDPROXY'
# joinery-managed - Postfix milter (self-scan) on 11332, on the loopback only
# (rspamd's own "localhost:11332"). No bind_socket here: one in local.d is
# ADDED to that, and a milter reachable from another machine can be told any
# client address, including a local one rspamd would sign for.
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}
RSPAMDPROXY
            ;;
        options.inc)
            cat <<'RSPAMDOPTS'
# joinery-managed - in override.d, so these replace rspamd's own values.
# "Local" is this box and nothing else. rspamd signs mail from a local address
# and leaves its Authentication-Results lines in place, and its stock list
# counts every private range as local: a message reaching Postfix from another
# container or over a tunnel would be signed, and a "passed" line it carried
# would be read as ours.
local_addrs = ["127.0.0.0/8", "::1"];
# The GTUBE test string would make rspamd refuse a message whatever the
# actions say. rspamd never refuses mail here.
gtube_patterns = "disable";
RSPAMDOPTS
            ;;
        *)
            echo "rspamd_stateless_render: unknown file $1" >&2
            return 2
            ;;
    esac
}
