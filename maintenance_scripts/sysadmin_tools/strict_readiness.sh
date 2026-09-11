#!/usr/bin/env bash
#
# strict_readiness.sh - would Cloudflare Full (Strict) work for these names?
# Version: 1.0 - specs/implemented/tls_and_origin_trust.md WP6. Read-only, run from anywhere
#                that can reach the origins: for each zone name, resolves the
#                apex and www, then probes the ORIGIN address with SNI for each
#                and prints one line per name: covered / not covered / does not
#                resolve. Strict rejects a handshake whose certificate does not
#                name the host, so a zone is ready only when every line of it
#                is "covered". The origin address is given per name; nothing
#                is guessed from DNS, which behind an edge names the edge.
#
# Usage:
#   ./strict_readiness.sh <name>=<origin-ip> [<name>=<origin-ip> ...]
#
# Example:
#   ./strict_readiness.sh getjoinery.com=23.239.11.53 jeremytunnell.com=45.79.204.178
#
# Exit status: 0 when every name that resolves is covered, 1 otherwise.

set -u

[ "$#" -ge 1 ] || { awk 'NR<3 {next} /^#/ {sub(/^# ?/,""); print; next} {exit}' "$0"; exit 1; }

command -v openssl >/dev/null 2>&1 || { echo "openssl is required." >&2; exit 1; }

resolves() {
    getent ahostsv4 "$1" 2>/dev/null | awk '{print $1}' | head -1
}

# The SANs of the certificate the origin presents for a name.
presented_names() {
    local origin="$1" sni="$2"
    echo | timeout 15 openssl s_client -connect "${origin}:443" -servername "${sni}" 2>/dev/null \
        | openssl x509 -noout -ext subjectAltName 2>/dev/null \
        | tr ',' '\n' | sed -n 's/.*DNS:[[:space:]]*//p' | sed 's/[[:space:]]*$//'
}

covers() {
    local host="$1" n
    shift
    for n in "$@"; do
        [ "$n" = "$host" ] && return 0
        case "$n" in
            \*.*) [ "${host#*.}" = "${n#\*.}" ] && return 0 ;;
        esac
    done
    return 1
}

rc=0
for spec in "$@"; do
    zone="${spec%%=*}"
    origin="${spec#*=}"
    if [ "$zone" = "$spec" ] || [ -z "$origin" ]; then
        echo "${spec}: give the origin address as <name>=<ip>" >&2
        rc=1
        continue
    fi
    for host in "$zone" "www.${zone}"; do
        ip="$(resolves "$host")"
        if [ -z "$ip" ]; then
            echo "${host}: does not resolve"
            continue
        fi
        mapfile -t names < <(presented_names "$origin" "$host")
        if [ "${#names[@]}" -eq 0 ]; then
            echo "${host}: not covered - the origin at ${origin} presented no certificate for SNI ${host}"
            rc=1
        elif covers "$host" "${names[@]}"; then
            expiry="$(echo | timeout 15 openssl s_client -connect "${origin}:443" -servername "${host}" 2>/dev/null \
                | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2)"
            echo "${host}: covered (resolves ${ip}; origin ${origin}; expires ${expiry:-?})"
        else
            echo "${host}: not covered - the origin at ${origin} presented $(IFS=,; echo "${names[*]}")"
            rc=1
        fi
    done
done
exit "$rc"
