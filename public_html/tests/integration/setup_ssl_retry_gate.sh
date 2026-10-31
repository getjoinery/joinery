#!/bin/bash
# @joinery-test
# name: setup_ssl_retry
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/setup_ssl.sh, maintenance_scripts/install_tools/install.sh]
#
# setup_ssl.sh ends by arming the certificate retry timer whenever no
# CA-signed certificate is in place for its domain (B67, from the final copy
# test's C11): a copy's switch runs it once, seconds after the record moves,
# and when the name did not reach the copy yet nothing ever asked again, so the
# site kept its placeholder certificate. install.sh's
# arm_ssl_retry_unless_issued is run here against a fixture certificate folder
# and a stand-in arm_ssl_retry.sh that records how it was called:
#   - no certificate, or a self-signed one (the placeholder's shape): armed,
#     to run the setup_ssl.sh it was given, and the run says so;
#   - a certificate a CA signed: nothing armed;
#   - an armer that cannot arm: the run says to run setup_ssl.sh again by hand,
#     and still ends 0 (setup_ssl.sh never fails its caller).
# And setup_ssl.sh calls it right after provision_origin_cert, with its own
# path, taken before sourcing install.sh changes SCRIPT_DIR.

set -u
SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$SITE_ROOT/maintenance_scripts/install_tools"
SETUP_SSL="$SITE_ROOT/maintenance_scripts/sysadmin_tools/setup_ssl.sh"
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

# The stand-in armer: records its arguments, answers as arm_ssl_retry.sh does.
cat > "$T/armer.sh" <<'EOF'
#!/bin/bash
echo "$*" >> "$ARMER_LOG"
[ "${ARMER_FAILS:-0}" = "1" ] && { echo "SSL_RETRY_UNAVAILABLE (no systemd on this machine)"; exit 1; }
echo "SSL_RETRY_ARMED $1"
EOF

# run DOMAIN: arm_ssl_retry_unless_issued as setup_ssl.sh runs it; its output in $T/out, its exit in $T/rc.
run() {
    : > "$T/armed"
    bash -c ". '$TOOLS/install.sh'; arm_ssl_retry_unless_issued '$1' /srv/x/maintenance_scripts/sysadmin_tools/setup_ssl.sh" \
        > "$T/out" 2>&1
    echo $? > "$T/rc"
}
export SSL_LIVE_DIR="$T/live" SSL_RETRY_ARMER="$T/armer.sh" ARMER_LOG="$T/armed"
mkdir -p "$T/live"

# No certificate at all.
run nocert.example.org
chk "no certificate: the retry timer is armed for the domain, to run the setup_ssl.sh given" \
    "$(cat "$T/armed")" "nocert.example.org --setup-ssl /srv/x/maintenance_scripts/sysadmin_tools/setup_ssl.sh"
chk "and the run says a timer asks every five minutes" "$(grep -c 'A retry timer asks every five minutes whether nocert.example.org reaches this box' "$T/out")" "1"
chk "and ends 0" "$(cat "$T/rc")" "0"

# A self-signed certificate: the placeholder's shape.
mkdir -p "$T/live/placeholder.example.org"
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 2 -subj "/CN=placeholder.example.org" \
    -keyout "$T/ph.key" -out "$T/live/placeholder.example.org/fullchain.pem" > /dev/null 2>&1
run placeholder.example.org
chk "a self-signed certificate in place: armed all the same" "$(cut -d' ' -f1 "$T/armed")" "placeholder.example.org"

# A certificate a CA signed.
openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -days 2 -subj "/CN=Gate Test CA" \
    -keyout "$T/ca.key" -out "$T/ca.pem" > /dev/null 2>&1
openssl req -new -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -subj "/CN=issued.example.org" \
    -keyout "$T/leaf.key" -out "$T/leaf.csr" > /dev/null 2>&1
mkdir -p "$T/live/issued.example.org"
openssl x509 -req -in "$T/leaf.csr" -CA "$T/ca.pem" -CAkey "$T/ca.key" -CAcreateserial -days 2 \
    -out "$T/live/issued.example.org/fullchain.pem" > /dev/null 2>&1
run issued.example.org
chk "a CA-signed certificate in place: nothing armed" "$(wc -c < "$T/armed" | tr -d ' ')" "0"
chk "and nothing said about a timer" "$(grep -c 'retry timer' "$T/out")" "0"

# An armer that cannot arm.
ARMER_FAILS=1 run unarmed.example.org
chk "the timer cannot be armed: the run says to run setup_ssl.sh again once the name reaches here" \
    "$(grep -c 'No retry timer could be armed for unarmed.example.org: run setup_ssl.sh unarmed.example.org again' "$T/out")" "1"
chk "and still ends 0" "$(cat "$T/rc")" "0"

# setup_ssl.sh itself.
chk "setup_ssl.sh arms right after provision_origin_cert, with its own path" \
    "$(grep -A1 '^provision_origin_cert "\$DOMAIN"$' "$SETUP_SSL" | tail -1)" 'arm_ssl_retry_unless_issued "$DOMAIN" "$SETUP_SSL_SELF"'
chk "its own path is taken before install.sh is sourced" \
    "$(awk '/^SETUP_SSL_SELF=/{s=NR} /^\. "\$INSTALL_SH"/{i=NR} END{print (s && i && s < i) ? "before" : "not before"}' "$SETUP_SSL")" "before"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
