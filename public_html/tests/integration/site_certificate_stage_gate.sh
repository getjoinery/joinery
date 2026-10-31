#!/bin/bash
# @joinery-test
# name: site_certificate_stage
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/sysadmin_tools/site_certificate_stage.sh, maintenance_scripts/install_tools/install.sh]
#
# A container site's certificate, handed to its copy (C7's B49): the host
# stages the site's own lineage inside its container for the export, and the
# copy adopts it under its own ACME account, its certificate step saying it
# kept that certificate rather than issued one. Driven unprivileged: the host's
# /etc is a fixture (SITE_CERT_STAGE_ETC), the engine a stub on ENGINE whose
# exec and cp act on a fixture container root, and certbot a stub on PATH.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
STAGE="$ROOT/maintenance_scripts/sysadmin_tools/site_certificate_stage.sh"
INSTALL="$ROOT/maintenance_scripts/install_tools/install.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; echo "RESULT: PASS 0 0"; exit 0; fi

# ── The host: two sites' lineages and the shared account ──
H="$T/host/etc"; C="$T/container"; LE="$H/letsencrypt"
mkdir -p "$LE/renewal" "$LE/accounts/acme-v02.api.letsencrypt.org/directory/hostacct" "$H/apache2/sites-available" "$C/etc/letsencrypt"
lineage() {  # NAME NAMES...
    local name="$1"; shift
    local san; san="$(printf 'DNS:%s,' "$@")"; san="${san%,}"
    mkdir -p "$LE/archive/$name" "$LE/live/$name"
    openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=$1" -addext "subjectAltName=$san" \
        -keyout "$LE/archive/$name/privkey1.pem" -out "$LE/archive/$name/cert1.pem" >/dev/null 2>&1
    cp "$LE/archive/$name/cert1.pem" "$LE/archive/$name/chain1.pem"
    cat "$LE/archive/$name/cert1.pem" "$LE/archive/$name/chain1.pem" > "$LE/archive/$name/fullchain1.pem"
    for f in cert chain fullchain privkey; do ln -s "../../archive/$name/${f}1.pem" "$LE/live/$name/$f.pem"; done
    printf 'version = 2.9.0\narchive_dir = /etc/letsencrypt/archive/%s\n[renewalparams]\naccount = hostacct\nauthenticator = apache\nserver = https://acme-v02.api.letsencrypt.org/directory\n' "$name" > "$LE/renewal/$name.conf"
}
lineage site.example.org site.example.org www.site.example.org
lineage other.example.org other.example.org
printf '<VirtualHost *:443>\n    ServerName site.example.org\n</VirtualHost>\n' > "$H/apache2/sites-available/csite.conf"

# ── The engine: exec and cp on the fixture container, csite its one site ──
cat > "$T/engine" <<EOF
#!/bin/bash
CROOT="$C"
case "\$1" in
    ps) echo csite ;;
    inspect)
        case "\$3" in
            *State.Running*) echo true ;;
            *Config.Env*) printf 'SITENAME=csite\nDOMAIN_NAME=site.example.org\n' ;;
        esac ;;
    exec)
        shift 2
        args=()
        for a in "\$@"; do [[ "\$a" == /etc/letsencrypt* ]] && a="\$CROOT\$a"; args+=("\$a"); done
        case "\${args[0]}" in chown) exit 0 ;; esac
        "\${args[@]}" ;;
    cp)
        dst="\${3#csite:}"
        cp -a "\$2" "\$CROOT\$dst" ;;
esac
EOF
chmod +x "$T/engine"
run_stage() { SITE_CERT_STAGE_ETC="$H" ENGINE="$T/engine" bash "$STAGE" "$@" 2>&1; }

echo "=== stage: the site's own lineage, without the host's account ==="
out="$(run_stage stage csite site.example.org)"; rc=$?
chk "staged, exit 0" "$rc" "0"
CL="$C/etc/letsencrypt"
chk "the archive files the live links point at, the key closed" \
    "$(ls "$CL/archive/site.example.org" | tr '\n' ' '):$(stat -c %a "$CL/archive/site.example.org/privkey1.pem")" "cert1.pem chain1.pem fullchain1.pem privkey1.pem :600"
chk "the live links, into their own archive" "$(readlink "$CL/live/site.example.org/fullchain.pem")" "../../archive/site.example.org/fullchain1.pem"
chk "the same certificate the host holds" "$(cmp -s "$CL/live/site.example.org/cert.pem" "$LE/live/site.example.org/cert.pem" && echo same)" "same"
chk "its renewal conf, without the account line" "$(grep -c '^account' "$CL/renewal/site.example.org.conf"):$(grep -c '^authenticator = apache' "$CL/renewal/site.example.org.conf")" "0:1"
chk "never the host's account, nor another site's lineage" "$([ -e "$CL/accounts" ] && echo acct || echo none):$([ -e "$CL/live/other.example.org" ] && echo other || echo none)" "none:none"
chk "what it wrote is recorded on the host" "$(cat "$H/joinery/sites/csite/certificate-staged")" "site.example.org"

echo "=== clear: exactly what stage wrote ==="
out="$(run_stage clear csite)"; rc=$?
chk "cleared, exit 0, the record gone" "$rc:$([ -e "$H/joinery/sites/csite/certificate-staged" ] && echo rec || echo none)" "0:none"
chk "nothing of it left in the container" "$(ls "$CL/live" "$CL/archive" "$CL/renewal" 2>/dev/null | grep -c example)" "0"

echo "=== refusals ==="
out="$(run_stage stage csite other.example.org)"; rc=$?
chk "another site's domain for this container: refused, nothing staged" "$rc:$(ls "$CL/live" 2>/dev/null | wc -l)" "2:0"
out="$(run_stage stage nosuch site.example.org)"; rc=$?
chk "a container that is not one of this host's sites: refused" "$rc" "2"
out="$(run_stage stage csite 'x;reboot')"; rc=$?
chk "anything but a domain: refused" "$rc" "2"
lineage shared.example.org site.example.org shared.example.org
out="$(run_stage stage csite site.example.org)"; rc=$?
chk "a lineage naming the site beside another name: refused, so no other site's key travels" "$rc:$(echo "$out" | grep -c 'beside other names')" "2:1"
rm -rf "$LE/live/shared.example.org" "$LE/archive/shared.example.org" "$LE/renewal/shared.example.org.conf"
mv "$LE/renewal/site.example.org.conf" "$T/site.conf"
out="$(run_stage stage csite site.example.org)"; rc=$?
chk "no lineage for the domain: nothing staged, exit 0" "$rc:$(echo "$out" | grep -c 'issues its own')" "0:1"
mv "$T/site.conf" "$LE/renewal/site.example.org.conf"

echo "=== the copy adopts a carried certificate under its own account ==="
fn="$(sed -n '/^adopt_carried_lineage() {/,/^}/p' "$INSTALL")"
chk "adopt_carried_lineage is in install.sh, and provision_origin_cert runs it first" \
    "$([ -n "$fn" ] && echo found):$(sed -n '/^provision_origin_cert() {/,/^}/p' "$INSTALL" | grep -c '^    adopt_carried_lineage "\$domain"$')" "found:1"
CP="$T/copy/etc/letsencrypt"; ACCTS="$CP/accounts/acme-v02.api.letsencrypt.org/directory"
mkdir -p "$CP/renewal" "$T/bin"
cat > "$T/bin/certbot" <<EOF
#!/bin/bash
echo "certbot \$*" >> "$T/certbot.calls"
[ "\$1" = register ] && mkdir -p "$ACCTS/copyacct"
exit 0
EOF
chmod +x "$T/bin/certbot"
adopt() { ( PATH="$T/bin:$PATH"; CARRIED_LE_DIR="$CP"; print_info() { :; }; print_warning() { echo "WARN $*"; }; eval "$fn"; adopt_carried_lineage "$1" ); }
grep -v '^account' "$LE/renewal/site.example.org.conf" > "$CP/renewal/site.example.org.conf"
adopt site.example.org
chk "no account here: one is registered, and the lineage names it" \
    "$(grep -c 'certbot register' "$T/certbot.calls"):$(grep -c '^account = copyacct$' "$CP/renewal/site.example.org.conf"):$(sed -n '/^\[renewalparams\]/{n;p}' "$CP/renewal/site.example.org.conf")" "1:1:account = copyacct"
cp "$CP/renewal/site.example.org.conf" "$T/before.conf"
adopt site.example.org
chk "a lineage whose account is here is left alone, nothing registered again" \
    "$(cmp -s "$T/before.conf" "$CP/renewal/site.example.org.conf" && echo same):$(grep -c 'certbot register' "$T/certbot.calls")" "same:1"
sed -i 's/^account = copyacct$/account = hostacct/' "$CP/renewal/site.example.org.conf"
adopt site.example.org
chk "an account named that is not here: this machine's own takes its place" "$(grep -c '^account = copyacct$' "$CP/renewal/site.example.org.conf"):$(grep -c 'certbot register' "$T/certbot.calls")" "1:1"
printf '[renewalparams]\naccount = hostacct\nauthenticator = dns-cloudflare\ndns_cloudflare_credentials = /etc/letsencrypt/cloudflare.ini\ndns_cloudflare_propagation_seconds = 30\n' > "$CP/renewal/dns.example.org.conf"
adopt dns.example.org
chk "issued over DNS-01 and its credentials did not travel: renews over HTTP-01" \
    "$(grep -c '^authenticator = apache$' "$CP/renewal/dns.example.org.conf"):$(grep -c '^dns_cloudflare' "$CP/renewal/dns.example.org.conf")" "1:0"
adopt none.example.org
chk "no lineage: nothing done" "$(ls "$CP/renewal" | wc -l)" "2"

echo "=== the certificate step says it kept a valid certificate, never that it issued one ==="
rfn="$(sed -n '/^report_certbot_success() {/,/^}/p' "$INSTALL")"
rep() { ( print_success() { echo "OK $*"; }; eval "$rfn"; report_certbot_success "$1" "site.example.org" "HTTP-01" ); }
printf 'Certificate not yet due for renewal; no action taken.\n' > "$T/kept.log"
printf 'Successfully received certificate.\n' > "$T/issued.log"
chk "certbot kept a valid certificate: the line says kept" "$(rep "$T/kept.log")" \
    "OK Kept the LE certificate for site.example.org: valid here and not yet due for renewal (HTTP-01)"
chk "certbot issued one: the line says issued" "$(rep "$T/issued.log")" "OK Issued LE certificate for site.example.org (HTTP-01)"
chk "both ways of issuing report through it, and nothing claims an issue on its own" \
    "$(sed -n '/^provision_origin_cert() {/,/^}/p' "$INSTALL" | grep -c 'report_certbot_success "\$'):$(sed -n '/^provision_origin_cert() {/,/^}/p' "$INSTALL" | grep -c 'Issued LE certificate')" "2:0"
pfn="$(sed -n '/^provision_origin_cert() {/,/^}/p' "$INSTALL")"
chk "the lines it prints name the domains alone, never certbot's -d flags (B54)" \
    "$(grep -c 'challenge for ${certnames\[\*\]}"$' <<<"$pfn"):$(grep -c 'report_certbot_success "$cb_log" "${certnames\[\*\]}"' <<<"$pfn"):$(grep -c '//-d' <<<"$pfn")" "1:1:0"
chk "and certbot is asked for exactly those names" \
    "$(grep -c 'for n in "${certnames\[@\]}"; do names+=(-d "$n"); done' <<<"$pfn")" "1"

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
