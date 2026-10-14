#!/usr/bin/env bash
# Version: 1.1 - asks the engine this host's sites run on (_site_engine.sh): Docker, or the site
#                container engine (specs/site_container_engine.md WP3), whose diff reads the same.
#
# Read-only. `docker diff` lists every path that differs from the image: A=added,
# C=changed, D=deleted. Volume contents never appear, so whatever it reports is
# by definition living in the writable layer and destroyed by `docker rm`.
#
# The site root is excluded — that class is already known and handled. What is
# left is everything we have NOT accounted for.
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[ -f "$HERE/../install_tools/_site_engine.sh" ] || { echo "audit_container_drift: there is no install_tools/_site_engine.sh beside this script's tree" >&2; exit 1; }
. "$HERE/../install_tools/_site_engine.sh"

for C in $(site_engine ps --format '{{.Names}}' | sort); do
    SITE=$(site_engine inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$C" 2>/dev/null \
        | grep '^SITENAME=' | cut -d= -f2)
    [ -z "$SITE" ] && continue

    echo "=============================================================="
    echo "$C"

    TOTAL=$(site_engine diff "$C" 2>/dev/null | wc -l)
    echo "  total changed paths vs image: ${TOTAL}"

    # Group everything outside the site root by its first two path components,
    # so a few thousand lines become a readable inventory.
    site_engine diff "$C" 2>/dev/null \
        | grep -v " /var/www/html/${SITE}\(/\|$\)" \
        | awk '{ n=split($2, p, "/"); key = (n>=3 ? "/" p[2] "/" p[3] : $2); print $1, key }' \
        | sort | uniq -c | sort -rn | head -25
done
echo "=============================================================="
