#!/usr/bin/env bash
#
# site_certificate_stage.sh - a site container's certificate, handed to its copy
# (specs/site_copy_anything_to_anything.md D4). A container site's certificate
# lineage lives on its host, which ends TLS for it, so the site's own agent,
# which makes the copy's export inside the container, finds none, and the copy
# would start on a self-signed certificate. For the export's span the host
# stages the site's own lineage into the container's /etc/letsencrypt, where
# the export's host bundle takes it (sealed to the copy, approved by the site's
# owner, as on bare metal); then it clears it.
#
# Version: 1.0
#
#   site_certificate_stage.sh stage SITE DOMAIN
#                         Root. Every lineage on this host whose certificate
#                         names DOMAIN or www.DOMAIN and nothing else: its live
#                         links, the archive files they point at, and its
#                         renewal conf without its account line, into SITE's
#                         running container under /etc/letsencrypt. The ACME
#                         account is the host's, shared by every site here,
#                         and never leaves it: the copy issues under its own.
#                         A lineage that also names another name is refused,
#                         so no other site's key can ride along, and DOMAIN must
#                         be the one this host serves SITE at (its vhost). What it wrote
#                         is recorded (/etc/joinery/sites/SITE/certificate-staged)
#                         and anything an earlier stage left is cleared first.
#                         No lineage for DOMAIN: nothing staged, said, exit 0.
#   site_certificate_stage.sh clear SITE
#                         Root. Removes from the container exactly what stage
#                         recorded, then the record. Nothing recorded: done.
#
# Exit 0 done; 1 failed (said why); 2 refused.
set -uo pipefail

CMD_TIMEOUT=60
ETC="/etc"
if [[ "$(id -u)" != "0" ]]; then
    [[ -n "${SITE_CERT_STAGE_ETC:-}" ]] && ETC="${SITE_CERT_STAGE_ETC%/}"
fi
LE="${ETC}/letsencrypt"
SITES_DIR="${ETC}/joinery/sites"
IN_LE="/etc/letsencrypt"

say()    { echo "site_certificate_stage: $*"; }
warn()   { echo "site_certificate_stage: $*" >&2; }
refuse() { warn "$1"; exit 2; }
fail()   { warn "$1"; exit 1; }
run()    { timeout "$CMD_TIMEOUT" "$@"; }

site_ok()    { [[ "$1" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]]; }
domain_ok()  { [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ && ${#1} -le 253 ]]; }
lineage_ok() { [[ "$1" =~ ^[a-z0-9][a-z0-9.-]{0,252}$ && "$1" != *..* ]]; }
pem_ok()     { [[ "$1" =~ ^(cert|chain|fullchain|privkey)[0-9]{1,6}\.pem$ ]]; }

# The engine helper: beside this script in a tree, or site_quiet_host's installed copy.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "${HERE}/../install_tools/_site_engine.sh" ]]; then
    HELPER="${HERE}/../install_tools/_site_engine.sh"
else
    HELPER="/usr/local/lib/joinery-site-quiet/_site_engine.sh"
fi
engine_ready() {
    [[ -n "${ENGINE:-}" ]] && return 0
    [[ -f "${HELPER}" ]] || { warn "no engine helper at ${HELPER}"; return 1; }
    # shellcheck source=../install_tools/_site_engine.sh
    . "${HELPER}"
    ENGINE="$(site_engine_bin)" || { warn "this machine's container engine could not be named"; return 1; }
}

# Whether NAME is one of this host's own site containers: one whose name is its
# SITENAME, the shape install.sh creates (site_quiet_host.sh's test).
own_container() {
    local c site
    engine_ready || return 1
    for c in $(run "$ENGINE" ps -a --format '{{.Names}}' 2>/dev/null); do
        [[ "$c" == "$1" ]] || continue
        site="$(run "$ENGINE" inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$c" 2>/dev/null | awk -F= '$1=="SITENAME" {print $2; exit}')"
        [[ "$site" == "$c" ]] && return 0
    done
    return 1
}
running() { [[ "$(run "$ENGINE" inspect -f '{{.State.Running}}' "$1" 2>/dev/null)" == "true" ]]; }

# The domain this host serves the container at: its vhost's ServerName (the
# host's own file, which sends that name to the container), else the
# DOMAIN_NAME the host gave the container when it made it. Never what the
# site itself says. Only a lineage for this domain is ever staged into it, so
# a caller that names one site's container and another's domain stages nothing.
domain_of() {
    local vhost="${ETC}/apache2/sites-available/$1.conf" d
    if [[ -f "$vhost" && ! -L "$vhost" ]]; then
        d="$(awk 'tolower($1)=="servername" {print tolower($2); exit}' "$vhost")"
    else
        d="$(run "$ENGINE" inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$1" 2>/dev/null | awk -F= '$1=="DOMAIN_NAME" {print tolower($2); exit}')"
    fi
    echo "$d"
}

record_of() { echo "${SITES_DIR}/$1/certificate-staged"; }

# The DNS names a lineage's current certificate covers, one per line.
names_of() {
    openssl x509 -in "${LE}/live/$1/cert.pem" -noout -ext subjectAltName 2>/dev/null \
        | tr ',' '\n' | sed -n 's/^[[:space:]]*DNS:\([^[:space:]]*\).*/\1/p' | tr 'A-Z' 'a-z'
}

clear_site() {  # SITE
    local site="$1" rec name
    rec="$(record_of "$site")"
    [[ -f "$rec" && ! -L "$rec" ]] || { say "nothing staged in ${site}"; return 0; }
    running "$site" || fail "${site} is not running, so what was staged in it cannot be removed now; run clear again once it runs"
    while IFS= read -r name; do
        lineage_ok "$name" || continue
        run "$ENGINE" exec "$site" rm -rf "${IN_LE}/live/${name}" "${IN_LE}/archive/${name}" "${IN_LE}/renewal/${name}.conf" \
            || fail "could not remove the staged ${name} from ${site}"
        say "removed the staged ${name} from ${site}"
    done < "$rec"
    rm -f "$rec"
}

stage_site() {  # SITE DOMAIN
    local site="$1" domain="$2" conf name n allowed f t target kept=() tmp
    [[ -d "${LE}/renewal" ]] || { say "no certificate on this host; the copy issues its own"; return 0; }
    clear_site "$site" >/dev/null
    for conf in "${LE}"/renewal/*.conf; do
        [[ -f "$conf" && ! -L "$conf" ]] || continue
        name="$(basename "$conf" .conf)"
        lineage_ok "$name" || continue
        allowed=1
        n=0
        while IFS= read -r f; do
            [[ -n "$f" ]] || continue
            n=$((n + 1))
            [[ "$f" == "$domain" || "$f" == "www.${domain}" ]] || allowed=0
        done < <(names_of "$name")
        # A lineage for another site entirely is passed over; one that names
        # this site beside another is refused, so nobody else's key travels.
        if ! names_of "$name" | grep -qxF -e "$domain" -e "www.${domain}"; then
            continue
        fi
        (( n > 0 && allowed )) || refuse "the lineage ${name} names ${domain} beside other names; it is not staged"
        kept+=("$name")
    done
    if (( ${#kept[@]} == 0 )); then
        say "no certificate on this host names ${domain}; the copy issues its own"
        return 0
    fi

    tmp="$(mktemp -d "${TMPDIR:-/tmp}/joinery-cert-stage.XXXXXX")" || fail "no room to stage"
    STAGE_TMP="$tmp"
    chmod 700 "$tmp"
    trap '[[ -n "${STAGE_TMP:-}" ]] && rm -rf "$STAGE_TMP"' EXIT
    mkdir -p "$tmp/live" "$tmp/archive" "$tmp/renewal"
    for name in "${kept[@]}"; do
        mkdir -p "$tmp/live/${name}" "$tmp/archive/${name}"
        for f in cert chain fullchain privkey; do
            [[ -L "${LE}/live/${name}/${f}.pem" ]] || fail "${name}'s ${f}.pem is not the link certbot makes"
            t="$(readlink "${LE}/live/${name}/${f}.pem")"
            target="$(basename "$t")"
            # Only the lineage's own archive, as certbot links it.
            [[ "$t" == "../../archive/${name}/${target}" ]] && pem_ok "$target" \
                || fail "${name}'s ${f}.pem points outside its own archive"
            [[ -f "${LE}/archive/${name}/${target}" && ! -L "${LE}/archive/${name}/${target}" ]] \
                || fail "${name}'s ${target} is missing"
            cp -p "${LE}/archive/${name}/${target}" "$tmp/archive/${name}/${target}"
            ln -s "../../archive/${name}/${target}" "$tmp/live/${name}/${f}.pem"
        done
        chmod 600 "$tmp/archive/${name}"/privkey*.pem
        # The account is the host's: the copy registers its own.
        grep -v -E '^[[:space:]]*account[[:space:]]*=' "${LE}/renewal/${name}.conf" > "$tmp/renewal/${name}.conf"
        chmod 644 "$tmp/renewal/${name}.conf"
        if run "$ENGINE" exec "$site" test -e "${IN_LE}/live/${name}" -o -e "${IN_LE}/archive/${name}" -o -e "${IN_LE}/renewal/${name}.conf"; then
            refuse "${site} already holds a lineage named ${name}; nothing staged"
        fi
    done
    run "$ENGINE" exec "$site" mkdir -p "${IN_LE}/live" "${IN_LE}/archive" "${IN_LE}/renewal" || fail "could not make ${IN_LE} in ${site}"
    run "$ENGINE" exec "$site" chmod 700 "${IN_LE}/live" "${IN_LE}/archive" || fail "could not close ${IN_LE} in ${site}"
    # Recorded before anything is written, so a stage cut short is still cleared.
    mkdir -p "${SITES_DIR}/${site}"
    printf '%s\n' "${kept[@]}" > "$(record_of "$site").tmp" && mv -f "$(record_of "$site").tmp" "$(record_of "$site")" \
        || fail "could not record what is staged"
    for name in "${kept[@]}"; do
        run "$ENGINE" cp "$tmp/archive/${name}" "${site}:${IN_LE}/archive/" \
            && run "$ENGINE" cp "$tmp/live/${name}" "${site}:${IN_LE}/live/" \
            && run "$ENGINE" cp "$tmp/renewal/${name}.conf" "${site}:${IN_LE}/renewal/${name}.conf" \
            || fail "could not stage ${name} into ${site}"
        run "$ENGINE" exec "$site" chown -R 0:0 "${IN_LE}/archive/${name}" "${IN_LE}/live/${name}" "${IN_LE}/renewal/${name}.conf" \
            || fail "could not hand ${name} to ${site}'s root"
        say "staged ${name} ($(names_of "$name" | paste -sd' ' -), until $(openssl x509 -in "${LE}/live/${name}/cert.pem" -noout -enddate 2>/dev/null | cut -d= -f2)) into ${site}"
    done
}

action="${1:-}"
case "$action" in
    stage|clear) ;;
    *) refuse "usage: site_certificate_stage.sh stage SITE DOMAIN | clear SITE" ;;
esac
[[ "$(id -u)" == "0" || -n "${SITE_CERT_STAGE_ETC:-}" ]] || refuse "run as root"
SITE="${2:-}"
site_ok "$SITE" || refuse "not a site name: ${SITE}"
own_container "$SITE" || refuse "${SITE} is not one of this host's site containers"
if [[ "$action" == "clear" ]]; then
    [[ $# -eq 2 ]] || refuse "clear takes only the site"
    clear_site "$SITE"
    exit $?
fi
[[ $# -eq 3 ]] || refuse "stage takes the site and its domain"
DOMAIN="$(echo "${3:-}" | tr 'A-Z' 'a-z')"
domain_ok "$DOMAIN" || refuse "not a domain: ${3:-}"
running "$SITE" || refuse "${SITE} is not running: the export that takes the certificate runs inside it"
[[ "$(domain_of "$SITE")" == "$DOMAIN" ]] || refuse "${SITE} is not served at ${DOMAIN} on this host; nothing staged"
stage_site "$SITE" "$DOMAIN"
