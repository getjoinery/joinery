#!/usr/bin/env bash
#
# render_vhost.sh - keep this site's Apache vhost in step with the template the
# deployed release ships.
#
# Version: 1.9 - The renewal-config heal moves to _host_files.sh, run by host_housekeeping.sh
#                over every lineage on the machine. Healing only the lineage named by this
#                vhost's ServerName missed any certificate under another name, and a
#                Docker host's proxy vhosts are never rendered here at all: three
#                lineages on docker-prod still let certbot edit their vhosts
#                (specs/fleet_ubuntu_2604_postgres_upgrade.md B23).
# Version: 1.8 - Reclaim: with the vhost moved aside by the agent's
#               reclaim_managed_file, which leaves a one-shot marker naming the
#               dated copy, the facts are read from that copy and the render is
#               written back in its place, parse-checked, with the copy restored
#               when Apache refuses the render. No marker (or one over an hour
#               old): an absent vhost is skipped as before, never resurrected.
# Version: 1.7 - Mints the site's placeholder certificate on every converge when
#                neither it nor the Let's Encrypt lineage exists
#                (_placeholder_cert.sh, specs/implemented/tls_and_origin_trust.md WP12), so
#                a box that somehow has no certificate at all still answers TLS
#                and can receive its first challenge through an edge.
# Version: 1.6 - certbot is taken out of the vhost (specs/implemented/tls_and_origin_trust.md
#                WP1a). Its Apache installer edited the domain's vhost on every
#                renewal - an Include line, and on older installs a redirect
#                block - so the file stopped matching the record and every
#                later converge refused it. Two changes: the site's renewal
#                conf is healed on every run (installer = None, a renew_hook
#                that reloads Apache), so the edit never recurs; and those two
#                known insertions count as not an operator edit, so a box that
#                already carries them adopts and re-renders instead of waiting
#                for a hand-apply. Nothing else is tolerated.
# Version: 1.5 - A candidate that does not parse is reported with Apache's own
#                error line and exits 1, so the runner logs it as a failed
#                installer instead of "ok" over a silent rollback.
# Version: 1.4 - Creates the test site's directories before rendering. The
#                template's test-site vhost logs to {site}_test/logs, and every
#                installer creates it; a box that never had it fails the config
#                check on a file that is otherwise fine.
# Version: 1.3 - On a box with no record, first asks whether the vhost on disk is
#                byte-for-byte a render of any template we ever shipped
#                (vhost_history/, 1.06 through 2.03, rendered for this domain
#                and address). A file identical to something we once wrote is
#                ours with no guessing: every node in the fleet was rendered
#                from template 2.00 and 2.04 changed redirect directives, so the
#                line-subset rule below could not adopt them, and a fleet nobody
#                has a shell on would have kept AllowOverride All forever. The
#                subset rule stays as the second test.
# Version: 1.2 - On a box with no record, adopts the vhost when it is an
#                unedited older render: every line on disk, ignoring
#                AllowOverride lines, also appears in the new render. That is
#                every box we ever installed, and refusing them all meant the
#                two rules this file exists to carry would have reached none of
#                them without an operator copying a file by hand. A line on disk
#                that is NOT in the new render is somebody's work, and still
#                gets the candidate and the message.
# Version: 1.1 - Applies only over its OWN previous output. It used to rewrite
#                the vhost whenever the text differed from the template, which
#                silently reverted an operator's ServerAlias, headers or extra
#                redirects; and it checked the LIVE configuration before
#                installing the candidate, which says nothing about the
#                candidate. What it last wrote is recorded beside the vhost;
#                anything else on disk is somebody's work and gets a `.new`
#                candidate and a message instead. The backup is kept.
# Version: 1.0 - The vhost is where two rules in specs/read_only_tree.md are
#                enforced: AllowOverride None, so a .htaccess dropped into a
#                directory the web user owns cannot configure Apache, and a
#                FilesMatch under static_files, so a .php written there is
#                refused rather than run. Both live in the template, and a site
#                installed before them keeps its old vhost forever unless
#                something re-renders it. This is that something: a core
#                installer, so every root moment applies it.
#
# Idempotent by construction: the rendered text is compared with what is on
# disk and nothing is written when they match, so this costs one sed and one
# diff on the overwhelming majority of runs. A rendered file that Apache will
# not parse is discarded rather than installed, and Apache is reloaded only
# when the file actually changed.
#
# Contract (docs/plugin_developer_guide.md): idempotent, root, non-interactive,
# exit 0 when not applicable.
#
# Usage:  render_vhost.sh [SITENAME] [SITE_ROOT]

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
say() { echo "vhost: $*"; }

SITE_ROOT="${2:-}"
if [[ -z "${SITE_ROOT}" ]]; then
    SITENAME="${1:-}"
    if [[ -n "${SITENAME}" && -d "/var/www/html/${SITENAME}" ]]; then
        SITE_ROOT="/var/www/html/${SITENAME}"
    else
        SITE_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
    fi
fi
SITENAME="$(basename "${SITE_ROOT}")"

[[ "$(id -u)" == "0" ]] || { say "not root - skipping"; exit 0; }
command -v apache2ctl >/dev/null 2>&1 || command -v apachectl >/dev/null 2>&1 || {
    say "no Apache on this machine - skipping"; exit 0; }

CONF="/etc/apache2/sites-available/${SITENAME}.conf"
# The facts a render needs (the domain, the proxy port, the address) are read
# out of the vhost on disk. The one exception is a reclaim: the agent's
# reclaim_managed_file moves an edited vhost to a dated copy and leaves a
# one-shot marker naming it, and only then is the render read from the copy
# and written back in its place. A vhost that is simply absent - a site moved
# off this machine - is never brought back (review B9, 2026-09-23).
RECLAIM_MARKER="${JOINERY_RECLAIM_DIR:-/var/lib/joinery/reclaimed}/vhost-pending.${SITENAME}"
[[ "$(id -u)" == "0" ]] && RECLAIM_MARKER="/var/lib/joinery/reclaimed/vhost-pending.${SITENAME}"
SOURCE="${CONF}"
RECLAIMING=0
if [[ ! -f "${CONF}" ]]; then
    SOURCE=""
    if [[ -f "${RECLAIM_MARKER}" ]] && [[ -z "$(find "${RECLAIM_MARKER}" -mmin +60 2>/dev/null)" ]]; then
        SOURCE="$(head -n 1 "${RECLAIM_MARKER}")"
    fi
    rm -f "${RECLAIM_MARKER}"
    if [[ -z "${SOURCE}" || ! -f "${SOURCE}" ]]; then
        say "no vhost at ${CONF} - skipping (this site was not installed by install.sh)"
        exit 0
    fi
    RECLAIMING=1
    say "reclaiming ${CONF}: rendering the template with the facts read from ${SOURCE}"
fi
if grep -q 'ProxyPass' "${SOURCE}"; then
    TEMPLATE="${SCRIPT_DIR}/default_proxy_vhost.conf"
    PORT="$(grep -oE 'ProxyPass[[:space:]]+/[[:space:]]+http://(127\.0\.0\.1|localhost):[0-9]+' "${SOURCE}" \
        | grep -oE '[0-9]+$' | head -1)"
    [[ -n "${PORT}" ]] || { say "could not read the proxy port out of ${SOURCE} - skipping"; exit 0; }
else
    TEMPLATE="${SCRIPT_DIR}/default_virtualhost.conf"
    PORT=""
fi
[[ -f "${TEMPLATE}" ]] || { say "template missing at ${TEMPLATE} - skipping" >&2; exit 0; }

# The domain and bind address come from what is already deployed, not from a
# fresh guess: re-rendering must not silently move a site to a different name.
DOMAIN="$(grep -m1 -oE '^[[:space:]]*ServerName[[:space:]]+\S+' "${SOURCE}" | awk '{print $2}')"
[[ -n "${DOMAIN}" ]] || { say "no ServerName in ${SOURCE} - skipping"; exit 0; }

SERVER_IP="$(grep -m1 -oE '<VirtualHost[[:space:]]+[^:]+:' "${SOURCE}" | sed -E 's/<VirtualHost[[:space:]]+//; s/:$//')"
[[ -n "${SERVER_IP}" ]] || SERVER_IP="*"

# certbot's renewal configs are not this script's: host_housekeeping.sh heals
# every lineage the machine renews through Apache, whatever its name
# (host_files_heal_renewal_confs in _host_files.sh).

# The placeholder the template's :443 host reads until a real certificate
# lands. Minted before the render so the vhost written below has something to
# answer with on the very next reload.
if [[ -f "${SCRIPT_DIR}/_placeholder_cert.sh" ]]; then
    # shellcheck source=_placeholder_cert.sh
    . "${SCRIPT_DIR}/_placeholder_cert.sh"
    mint_placeholder_cert "${DOMAIN}" || true
fi

# The lines certbot's installer inserts, and nothing else. $1 = the line with
# its indentation removed, $2 = the domain.
is_certbot_insertion() {
    case "$1" in
        'Include /etc/letsencrypt/options-ssl-apache.conf') return 0 ;;
        'RewriteEngine on') return 0 ;;
        "RewriteCond %{SERVER_NAME} =$2") return 0 ;;
        'RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]') return 0 ;;
    esac
    return 1
}

# Is the file on disk one of our renders plus any subset of certbot's
# insertions? Compared with diff rather than by stripping first: the template
# itself carries a `RewriteCond %{SERVER_NAME} =<domain>` line identical to
# certbot's, and stripping it would refuse every box. A line of the render
# that is missing, or an added line that is not certbot's, is an operator's
# edit. $1 = the render, $2 = the file on disk, $3 = the domain.
vhost_is_render_plus_certbot() {
    local d line trimmed
    d="$(diff "$1" "$2" 2>/dev/null)" && return 0
    while IFS= read -r line; do
        case "${line}" in
            '<'*) return 1 ;;
            '>'*)
                trimmed="${line#> }"
                trimmed="${trimmed#"${trimmed%%[![:space:]]*}"}"
                [[ -z "${trimmed}" ]] && continue
                is_certbot_insertion "${trimmed}" "$3" || return 1 ;;
            *) ;;
        esac
    done <<< "${d}"
    return 0
}

# The rule this file used to carry now lives in the vhost above, where the web
# user cannot edit it. Removed here rather than left inert, because AllowOverride
# None means a .htaccess in a web-writable directory is no longer read — and a
# file that looks like configuration but is not is worse than no file. Only the
# exact content we shipped is removed; anything an operator put there is theirs.
LEGACY_HTACCESS="${SITE_ROOT}/static_files/uploads/.htaccess"
if [[ -f "${LEGACY_HTACCESS}" ]] \
   && grep -q 'RewriteRule \^(\.\*)\$ /uploads/\$1' "${LEGACY_HTACCESS}" 2>/dev/null \
   && [[ "$(wc -l < "${LEGACY_HTACCESS}")" -le 5 ]]; then
    rm -f "${LEGACY_HTACCESS}" && say "removed ${LEGACY_HTACCESS} (its rule is in the vhost)"
fi

# What this script last wrote, so an operator's edits can be told from an old
# render. 0600 root:root beside the vhost: it is a record, not configuration.
STATE="/etc/apache2/sites-available/.${SITENAME}.conf.rendered"

# One render, the way install.sh, _site_init.sh and the container image all
# do it: four plain substitutions over the whole template. Anything a box was
# given by one of those is reproducible here from the values it still carries.
render_template() {
    sed -e "s|{{DOMAIN_NAME}}|${DOMAIN}|g" \
        -e "s|{{SITE_NAME}}|${SITENAME}|g" \
        -e "s|{{SERVER_IP}}|${SERVER_IP}|g" \
        -e "s|{{PORT}}|${PORT}|g" \
        "$1"
}

# The template's test-site vhost names {site}_test/public_html and
# {site}_test/logs, and Apache refuses a configuration whose ErrorLog directory
# does not exist. _site_init.sh and the container image create both; a box
# whose vhost was hand-trimmed may never have had them.
mkdir -p "${SITE_ROOT}_test/public_html" "${SITE_ROOT}_test/logs" 2>/dev/null || true

RENDERED="$(mktemp)"
trap 'rm -f "${RENDERED}"' EXIT
render_template "${TEMPLATE}" > "${RENDERED}"

# Is the vhost on disk exactly what one of our earlier templates rendered to?
#
# vhost_history/ holds every template version we ever shipped. A file that is
# byte-for-byte one of their renders for this domain and address is ours by
# construction - no operator edit survives an exact comparison - and it is
# adopted without a further test. certbot's own insertions are allowed on top
# (vhost_is_render_plus_certbot). Echoes the matching version's file name.
vhost_matches_history() {
    local on_disk="$1" history_dir="$2" old rendered_old
    [[ -d "${history_dir}" ]] || return 1
    rendered_old="$(mktemp)"
    for old in "${history_dir}"/*.conf; do
        [[ -f "${old}" ]] || continue
        render_template "${old}" > "${rendered_old}"
        if vhost_is_render_plus_certbot "${rendered_old}" "${on_disk}" "${DOMAIN}"; then
            rm -f "${rendered_old}"
            echo "${old##*/}"
            return 0
        fi
    done
    rm -f "${rendered_old}"
    return 1
}

# Reclaiming: the render goes where the moved file was, checked first, and the
# moved copy comes back if Apache will not parse the render.
if [[ "${RECLAIMING}" == 1 ]]; then
    cp "${RENDERED}" "${CONF}"
    chmod 644 "${CONF}"
    if ! parse_out="$(apache2ctl -t 2>&1)"; then
        say "the reclaimed vhost does not parse; putting ${SOURCE} back" >&2
        say "  $(printf '%s' "${parse_out}" | grep -viE 'AH00558|Syntax' | head -3 | tr '\n' ' ')" >&2
        cp "${SOURCE}" "${CONF}"
        exit 1
    fi
    cp "${RENDERED}" "${STATE}" 2>/dev/null || true
    chmod 600 "${STATE}" 2>/dev/null || true
    if apache2ctl graceful >/dev/null 2>&1 || systemctl reload apache2 >/dev/null 2>&1; then
        say "reclaimed ${CONF} from ${TEMPLATE##*/} (the edited copy is kept at ${SOURCE}) and reloaded Apache"
    else
        say "reclaimed ${CONF} (the edited copy is kept at ${SOURCE}); Apache could not be reloaded - it applies at the next restart" >&2
    fi
    exit 0
fi

if cmp -s "${RENDERED}" "${CONF}"; then
    # Already current. Record it as ours so a later template change can be
    # applied without asking again.
    cp "${RENDERED}" "${STATE}" 2>/dev/null || true
    chmod 600 "${STATE}" 2>/dev/null || true
    exit 0
fi

# Is the vhost on disk an unedited render from an older release?
#
# Line membership, not order: every line of the file on disk has to appear
# somewhere in the new render. An older template is a subset of the new one -
# blocks were added, none were removed - so an untouched old render passes and
# a file carrying an operator's ServerAlias, header or redirect does not,
# because that line appears nowhere in the template.
#
# `AllowOverride <value>` lines are excluded because changing them from All to
# None is the point of this release: every older render carries
# `AllowOverride All`, which by construction is not in the new one.
vhost_is_an_older_render() {
    local on_disk="$1" candidate="$2" domain="${3:-}" line trimmed
    while IFS= read -r line || [[ -n "${line}" ]]; do
        trimmed="${line#"${line%%[![:space:]]*}"}"
        # Blank lines and comments configure nothing, and the template carries a
        # `#Version` line that changes every release - so comparing those would
        # refuse every box on earth over a number in a comment.
        [[ -z "${trimmed}" ]] && continue
        [[ "${trimmed}" == \#* ]] && continue
        # certbot's installer wrote these, not an operator.
        is_certbot_insertion "${trimmed}" "${domain}" && continue
        # The directive itself, not every directive whose name starts the same
        # way: AllowOverrideList is an operator's, and skipping it would adopt a
        # file carrying one.
        case "${line}" in *AllowOverride[[:space:]]*) continue ;; esac
        grep -qxF -- "${line}" "${candidate}" || return 1
    done < "${on_disk}"
    return 0
}

if [[ ! -f "${STATE}" ]]; then
    # First run on this box: no previous output to compare with. Adopt when the
    # file on disk is an older render of this same template, which is every box
    # install.sh built before this script existed. Anything else is an edit, and
    # guessing wrong destroys it.
    if MATCHED="$(vhost_matches_history "${CONF}" "${SCRIPT_DIR}/vhost_history")"; then
        say "adopting ${CONF}: it is exactly what ${MATCHED} rendered to for this site"
        cp "${CONF}" "${STATE}" 2>/dev/null || true
        chmod 600 "${STATE}" 2>/dev/null || true
    elif vhost_is_an_older_render "${CONF}" "${RENDERED}" "${DOMAIN}"; then
        say "adopting ${CONF}: it is an unedited render from an older release"
        cp "${CONF}" "${STATE}" 2>/dev/null || true
        chmod 600 "${STATE}" 2>/dev/null || true
    else
        cp "${RENDERED}" "${CONF}.new" 2>/dev/null || true
        chmod 644 "${CONF}.new" 2>/dev/null || true
        say "${CONF} carries lines this release's template does not, and no previous render is on record."
        say "  candidate: ${CONF}.new"
        say "  diff:      diff -u ${CONF} ${CONF}.new"
        say "  apply:     cp ${CONF}.new ${CONF} && apache2ctl -t && apache2ctl graceful"
        say "  It carries AllowOverride None and refuses PHP under static_files (specs/read_only_tree.md)."
        exit 0
    fi
fi

# Is the vhost on disk still the one WE last wrote, or has an operator edited
# it? A ServerAlias, an extra header, a redirect for one path — all normal, all
# invisible to a renderer that just overwrites. So: apply only over our own
# previous output, allowing for certbot's insertions on top of it (the renewal
# conf healed above keeps them from coming back). Anything else is somebody's
# work, and it is written beside the file with a message instead of over it.
if ! vhost_is_render_plus_certbot "${STATE}" "${CONF}" "${DOMAIN}"; then
    cp "${RENDERED}" "${CONF}.new" 2>/dev/null || true
    chmod 644 "${CONF}.new" 2>/dev/null || true
    say "${CONF} has been edited since this script last wrote it; not overwriting." >&2
    say "  candidate: ${CONF}.new — merge your changes into it and copy it over." >&2
    exit 0
fi

# Ours, and out of date. Check the CANDIDATE parses before installing it: the
# old check tested the live configuration, which says nothing about the file
# about to replace it, and a vhost that does not parse takes every site on the
# box down at the next reload.
BACKUP="${CONF}.before-render.$(date -u +%Y%m%d%H%M%S)"
cp "${CONF}" "${BACKUP}" || { say "could not back up ${CONF} - not touching it" >&2; exit 0; }
cp "${RENDERED}" "${CONF}"
chmod 644 "${CONF}"

if ! parse_out="$(apache2ctl -t 2>&1)"; then
    say "the re-rendered vhost does not parse; putting the previous one back" >&2
    say "  $(printf '%s' "${parse_out}" | grep -viE 'AH00558|Syntax' | head -3 | tr '\n' ' ')" >&2
    cp "${BACKUP}" "${CONF}"
    exit 1
fi

# The backup is KEPT. It is the only copy of what was running a moment ago, and
# a config change that looked fine to apache2ctl -t is exactly the kind that is
# discovered to be wrong later.
cp "${RENDERED}" "${STATE}" 2>/dev/null || true
chmod 600 "${STATE}" 2>/dev/null || true

if apache2ctl graceful >/dev/null 2>&1 || systemctl reload apache2 >/dev/null 2>&1; then
    say "re-rendered ${CONF} from ${TEMPLATE##*/} (previous kept at ${BACKUP##*/}) and reloaded Apache"
else
    say "re-rendered ${CONF} (previous kept at ${BACKUP##*/}); Apache could not be reloaded - it applies at the next restart" >&2
fi

exit 0
