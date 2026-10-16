#!/usr/bin/env bash
#
# _vhost_render.sh - the one render of a bare-metal site's vhost template.
# Sourced by _site_init.sh (a site's first vhost) and render_vhost.sh (every
# converge); never run on its own.
#
# Version: 1.1 - vhost_render TEMPLATE ROOT DOMAIN whole renders the template as it stands, test-site host and
#                all, as releases before 1.0 rendered it: what render_vhost.sh compares an old vhost with
#                (reviewer2 D3).
# Version: 1.0 - A companion test site renders without a test site of its own.
#                install.sh site --with-test-site makes <site>_test beside
#                <site> and initialises it like any site, so its vhost carried
#                the template's test-site host too: test.test.<domain> serving
#                /var/www/html/<site>_test_test, whose folders, and on a data
#                root a logs bind, were made for it (one_data_root B1).
#
# A companion test site is a <name>_test whose main site <name> sits beside it
# with its own config, the same test joinery_data_root.sh uses for its logs.

# $1 = the site's root folder. True for a companion test site.
vhost_is_companion_test_site() {
    local root="${1%/}" name
    name="${root##*/}"
    [[ "${name}" == *_test && -f "${root%/*}/${name%_test}/config/Globalvars_site.php" ]]
}

# $1 template, $2 site root, $3 domain; SERVER_IP and PORT fill the older
# templates' and the proxy template's placeholders where they appear. Writes
# the render to stdout. For a companion test site the template's test-site
# <Directory> block and its test-site host are left out, unless $4 is
# "whole": an old vhost is compared with its template as it was rendered then.
vhost_render() {
    local template="$1" root="${2%/}" domain="$3" whole="${4:-}" sitename
    sitename="${root##*/}"
    local strip=()
    if [[ "${whole}" != whole ]] && vhost_is_companion_test_site "${root}"; then
        strip=(-e '\#^<Directory /var/www/html/{{SITE_NAME}}_test/public_html>$#,\#^</Directory>$#d'
               -e '\#^\# Test site .*HTTP only\.$#,\#^</VirtualHost>$#d')
    fi
    sed ${strip[@]+"${strip[@]}"} \
        -e "s|{{DOMAIN_NAME}}|${domain}|g" \
        -e "s|{{SITE_NAME}}|${sitename}|g" \
        -e "s|{{SERVER_IP}}|${SERVER_IP:-}|g" \
        -e "s|{{PORT}}|${PORT:-}|g" \
        "${template}"
}
