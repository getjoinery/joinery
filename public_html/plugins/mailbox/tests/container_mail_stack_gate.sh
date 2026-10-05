#!/bin/bash
# @joinery-test
# name: container_mail_stack
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# A site container carries no mail stack (specs/multi_tenant_docker_hosts.md
# WP9): its mail arrives through a relay, and nothing in it sends through a
# local mail server.
#
#   - install_email.sh, the mailbox plugin's host installer, runs at every
#     container start. Here it runs for real against a scratch site whose
#     config records it as a container, with every system command stubbed on
#     PATH: it exits 0, says why, and calls none of them (no apt-get, no
#     postconf, no postfix, no psql, no rspamd). Recorded as bare metal, or
#     with no record, it goes on to configure the mail stack as before.
#   - install.sh server installs Postfix on bare metal and not in an image
#     build: its mail-stack step runs with is_docker answering each way.
#
# The root check is the one line changed in the copy that runs: the stubs make
# root unnecessary, and nothing outside the scratch directory is touched.

PROVISIONING="${PROVISIONING_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../provisioning" && pwd)}"
INSTALLER="$PROVISIONING/install_email.sh"
INSTALL_SH="${INSTALL_SH:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)/maintenance_scripts/install_tools/install.sh}"

T=$(mktemp -d) || { echo "mktemp failed"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] && [ "$T" != "/" ] || { echo "no scratch dir"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "== the scripts exist and parse =="
for f in "$INSTALLER" "$INSTALL_SH"; do
    chk "$(basename "$f") parses" "$(bash -n "$f" 2>/dev/null && echo yes || echo no)" "yes"
done

# Every system command the installer could reach records that it was called.
mkdir -p "$T/bin"
for c in apt-get dpkg postconf postfix psql systemctl ufw service rspamd rspamadm \
         install chown useradd; do
    printf '#!/bin/bash\necho %s >> "%s/calls"\n[ "%s" = psql ] && echo t\nexit 0\n' "$c" "$T" "$c" > "$T/bin/$c"
    chmod +x "$T/bin/$c"
done

# A scratch site: the installer finds its handler, renderer and config from its
# own path, four levels below the site root.
site() {   # $1 = the deployment_environment line, or empty for none
    rm -rf "$T/site" "$T/calls"
    local prov="$T/site/public_html/plugins/mailbox/provisioning"
    mkdir -p "$prov" "$T/site/public_html/plugins/mailbox/utils" "$T/site/config"
    sed 's/^if \[\[ "\${EUID}" -ne 0 \]\]; then$/if false; then/' "$INSTALLER" > "$prov/install_email.sh"
    touch "$prov/render_pgsql_map.php" "$T/site/public_html/plugins/mailbox/utils/inbound_email_handler.php"
    {
        echo '<?php'
        echo "\$this->settings['dbname'] = 'scratchdb';"
        echo "\$this->settings['dbusername'] = 'scratch';"
        echo "\$this->settings['dbpassword'] = 'x';"
        [ -n "$1" ] && echo "\$this->settings['deployment_environment'] = '$1';"
    } > "$T/site/config/Globalvars_site.php"
}
run_installer() {
    PATH="$T/bin:$PATH" bash "$T/site/public_html/plugins/mailbox/provisioning/install_email.sh" > "$T/out" 2>&1
    echo $?
}

echo "== the root check is the only line changed in the copy =="
site docker
chk "one line differs" "$(diff "$INSTALLER" "$T/site/public_html/plugins/mailbox/provisioning/install_email.sh" | grep -c '^[<>]')" "2"

echo "== in a site container it installs and starts nothing =="
site docker
code=$(run_installer)
chk "exits 0, so the container start goes on" "$code" "0"
chk "says the site receives mail through a relay" "$(grep -c 'receives mail' "$T/out")" "1"
chk "calls no system command" "$(cat "$T/calls" 2>/dev/null | sort -u | tr '\n' ' ')" ""

echo "== on bare metal it configures the mail stack =="
for env in baremetal ""; do
    site "$env"
    run_installer > /dev/null
    label="${env:-no record}"
    chk "$label: reaches postconf" "$(grep -cx postconf "$T/calls" 2>/dev/null | sed 's/^[1-9][0-9]*$/yes/')" "yes"
    chk "$label: asks the database" "$(grep -cx psql "$T/calls" 2>/dev/null | sed 's/^[1-9][0-9]*$/yes/')" "yes"
    chk "$label: no container message" "$(grep -c 'receives mail through' "$T/out")" "0"
done

echo "== install.sh server: Postfix on bare metal, not in an image =="
block="$T/mail_step.sh"
awk '/^    if is_docker; then$/ { buf=$0 "\n"; inblk=1; next }
     inblk { buf = buf $0 "\n"; if ($0 ~ /^    fi$/) { if (buf ~ /no mail stack/) { printf "%s", buf; exit } inblk=0 } }' \
    "$INSTALL_SH" > "$block"
chk "the mail-stack step is found" "$(grep -c 'postfix-pgsql' "$block")" "1"
for answer in 0 1; do
    out=$(
        is_docker() { return "$answer"; }
        print_info() { :; }; print_step() { :; }
        apt() { echo "apt $*"; }
        . "$block"
    )
    want=$([ "$answer" = 0 ] && echo "" || echo "apt install -y postfix postfix-pgsql")
    chk "is_docker=$([ "$answer" = 0 ] && echo yes || echo no): apt is asked for '$want'" "$out" "$want"
done

echo
echo "container_mail_stack: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
