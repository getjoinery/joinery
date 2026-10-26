#!/bin/bash
# @joinery-test
# name: credentials_site_scheme
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/install.sh]
#
# A fresh install's config/admin_credentials.txt and its closing summary name
# the address the site really serves (B145). _site_init.sh writes the file
# before the certificate step, so its URL lines say http; install.sh's
# credentials_name_site_scheme, run before the summary, turns them to https
# once a CA-signed certificate is in place:
#   - a CA-signed certificate: every http://DOMAIN/ line names https, the rest
#     of the file is kept, its mode too;
#   - no certificate, or only the self-signed placeholder: left at http;
#   - another name that merely contains the domain's letters is left alone;
#   - site_scheme answers the same for the summary's own lines.
set -u
SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$SITE_ROOT/maintenance_scripts/install_tools"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
export SSL_LIVE_DIR="$T/live"
mkdir -p "$T/live"

# The file as _site_init.sh writes it, plus a line naming a look-alike host.
write_cred() {
    printf 'Joinery admin login for site\n\nURL:      http://%s/login\nUsername: admin\nPassword: kept\n\nFIRST TASK: set up email at\n  http://%s/admin/admin_settings_email\n\nOther: http://%s/x\n' \
        "$1" "$1" "$(echo "$1" | tr . x)" > "$T/cred.txt"
    chmod 600 "$T/cred.txt"
}
# run DOMAIN: credentials_name_site_scheme on $T/cred.txt; site_scheme's answer in $T/scheme.
run() {
    bash -c ". '$TOOLS/install.sh'; DOMAIN_NAME='$1'; credentials_name_site_scheme '$T/cred.txt'; site_scheme '$1' > '$T/scheme'" \
        > "$T/out" 2>&1
}

# No certificate.
write_cred nocert.example.org
run nocert.example.org
chk "no certificate: the file still says http" "$(grep -c '^URL:      http://nocert.example.org/login$' "$T/cred.txt")" "1"
chk "and site_scheme says http" "$(cat "$T/scheme")" "http"

# The self-signed placeholder.
mkdir -p "$T/live/placeholder.example.org"
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 2 -subj "/CN=placeholder.example.org" \
    -keyout "$T/ph.key" -out "$T/live/placeholder.example.org/fullchain.pem" > /dev/null 2>&1
write_cred placeholder.example.org
run placeholder.example.org
chk "the self-signed placeholder: still http" "$(grep -c 'https://' "$T/cred.txt")" "0"

# A certificate a CA signed.
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 2 -subj "/CN=Gate Test CA" \
    -keyout "$T/ca.key" -out "$T/ca.pem" > /dev/null 2>&1
openssl req -new -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -subj "/CN=issued.example.org" \
    -keyout "$T/leaf.key" -out "$T/leaf.csr" > /dev/null 2>&1
mkdir -p "$T/live/issued.example.org"
openssl x509 -req -in "$T/leaf.csr" -CA "$T/ca.pem" -CAkey "$T/ca.key" -CAcreateserial -days 2 \
    -out "$T/live/issued.example.org/fullchain.pem" > /dev/null 2>&1
write_cred issued.example.org
run issued.example.org
chk "a CA-signed certificate: the sign-in URL names https" "$(grep -c '^URL:      https://issued.example.org/login$' "$T/cred.txt")" "1"
chk "and so does the email first-task line" "$(grep -c '^  https://issued.example.org/admin/admin_settings_email$' "$T/cred.txt")" "1"
chk "no http line for the domain is left" "$(grep -cF 'http://issued.example.org/' "$T/cred.txt")" "0"
chk "a look-alike host is left alone" "$(grep -c '^Other: http://issuedxexamplexorg/x$' "$T/cred.txt")" "1"
chk "the rest of the file is kept" "$(grep -c '^Password: kept$' "$T/cred.txt")" "1"
chk "and its mode" "$(stat -c %a "$T/cred.txt")" "600"
chk "and site_scheme says https" "$(cat "$T/scheme")" "https"
chk "the run said nothing" "$(wc -c < "$T/out" | tr -d ' ')" "0"

# Both summaries rewrite the file before they print it.
for site in '"/var/www/html/$SITENAME/config/admin_credentials.txt" "$ENGINE_CMD exec $SITENAME"' '"/var/www/html/$SITENAME/config/admin_credentials.txt"'; do
    chk "a summary rewrites the file before printing it: $site" \
        "$(grep -A1 "^ *credentials_name_site_scheme $site\$" "$TOOLS/install.sh" | tail -1 | grep -c 'print_admin_login')" "1"
done

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
