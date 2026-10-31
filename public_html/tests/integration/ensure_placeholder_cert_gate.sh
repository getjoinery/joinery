#!/bin/bash
# @joinery-test
# name: ensure_placeholder_cert
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/install.sh, maintenance_scripts/install_tools/_placeholder_cert.sh, maintenance_scripts/sysadmin_tools/arm_ssl_retry.sh, maintenance_scripts/sysadmin_tools/issue_origin_cert.sh]
#
# Every certificate attempt mints a site's missing placeholder before it probes
# (B110): without a placeholder or a real certificate a site's :443 host is not
# configured, an edge's https hop completes no handshake, and the first
# challenge never arrives. A container host's proxy vhosts are written once and
# never re-rendered, and the advice the probe gave named a render_vhost.sh a
# host does not have. install.sh's ensure_placeholder_cert is run here against
# fixture folders, with the vhost lookup, apache2ctl and systemctl stood in:
#   - a site served here with no certificate at all: the placeholder is minted,
#     the run says so, and Apache is reloaded once;
#   - again: nothing minted, nothing said, no reload;
#   - a site with a Let's Encrypt lineage, or a name no vhost serves: nothing;
#   - a placeholder that cannot be written: a warning, still 0;
#   - Apache's configuration not parsing: minted, not reloaded, and said;
# and the three callers (provision_origin_cert, the retry timer's tick,
# issue_origin_cert.sh) run it before they probe, none naming render_vhost.sh.
set -u
SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$SITE_ROOT/maintenance_scripts/install_tools"
SYSADMIN="$SITE_ROOT/maintenance_scripts/sysadmin_tools"
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

# run DOMAIN: ensure_placeholder_cert as provision_origin_cert runs it, with
# served.example.org the only name a vhost here serves. Output in $T/out, exit
# in $T/rc, every reload appended to $T/reloads.
run() {
    bash -c ". '$TOOLS/install.sh'
        site_serving_name() { case \"\$1\" in served.example.org|www.served.example.org|le.example.org|nowrite.example.org|badconf.example.org) echo 'site direct' ;; *) return 1 ;; esac; }
        apache2ctl() { [ \"\${CONF_BROKEN:-0}\" = 1 ] && return 1; return 0; }
        systemctl() { echo \"\$*\" >> '$T/reloads'; }
        ensure_placeholder_cert '$1'" > "$T/out" 2>&1
    echo $? > "$T/rc"
}
reloads() { [ -f "$T/reloads" ] && wc -l < "$T/reloads" | tr -d ' ' || echo 0; }
export JOINERY_PLACEHOLDER_ROOT="$T/ph" JOINERY_LETSENCRYPT_DIR="$T/le"
mkdir -p "$T/ph" "$T/le/live"

# A site served here with no certificate at all.
run served.example.org
chk "no certificate: the placeholder is minted" "$([ -f "$T/ph/served.example.org/fullchain.pem" ] && echo yes || echo no)" "yes"
chk "and it names the apex and www" \
    "$(openssl x509 -in "$T/ph/served.example.org/fullchain.pem" -noout -ext subjectAltName 2>/dev/null | grep -c 'DNS:served.example.org, DNS:www.served.example.org')" "1"
chk "and the run says it minted it" "$(grep -c 'served.example.org had no certificate here, not even its placeholder; minted the placeholder' "$T/out")" "1"
chk "and Apache is reloaded once" "$(reloads)" "1"
chk "and ends 0" "$(cat "$T/rc")" "0"

# Again: the placeholder is there.
run served.example.org
chk "again: nothing said" "$(wc -c < "$T/out" | tr -d ' ')" "0"
chk "and no second reload" "$(reloads)" "1"

# www is answered by the apex's placeholder.
chk "a www name counts the apex's placeholder as its certificate" \
    "$(bash -c ". '$TOOLS/install.sh'; certificate_files_here www.served.example.org && echo yes || echo no")" "yes"

# A Let's Encrypt lineage.
mkdir -p "$T/le/live/le.example.org"
echo lineage > "$T/le/live/le.example.org/fullchain.pem"
run le.example.org
chk "a Let's Encrypt lineage: no placeholder minted" "$([ -e "$T/ph/le.example.org" ] && echo minted || echo none)" "none"
chk "and nothing said" "$(wc -c < "$T/out" | tr -d ' ')" "0"

# A name no vhost here serves.
run elsewhere.example.org
chk "a name no vhost serves: no placeholder minted" "$([ -e "$T/ph/elsewhere.example.org" ] && echo minted || echo none)" "none"
chk "and no reload" "$(reloads)" "1"

# A placeholder that cannot be written.
echo "a file where its folder goes" > "$T/ph/nowrite.example.org"
run nowrite.example.org
chk "the placeholder cannot be written: the run says so" "$(grep -c 'nowrite.example.org has no certificate here, and its placeholder could not be minted' "$T/out")" "1"
chk "and still ends 0" "$(cat "$T/rc")" "0"
chk "and no reload" "$(reloads)" "1"

# Apache's configuration does not parse.
CONF_BROKEN=1 run badconf.example.org
chk "the configuration does not parse: minted all the same" "$([ -f "$T/ph/badconf.example.org/fullchain.pem" ] && echo yes || echo no)" "yes"
chk "and not reloaded, and said" "$(reloads) $(grep -c 'was not reloaded to read the placeholder for badconf.example.org' "$T/out")" "1 1"

# The callers.
chk "provision_origin_cert ensures the placeholder right after adopting a carried lineage" \
    "$(awk '/^provision_origin_cert\(\) \{/{f=1} f && /^\}/{f=0} f' "$TOOLS/install.sh" | grep -A1 '^    adopt_carried_lineage "\$domain"$' | tail -1 | sed 's/^ *//')" \
    "# The placeholder an edge's https hop needs before any challenge arrives."
chk "and calls it before it probes" \
    "$(awk '/^provision_origin_cert\(\) \{/{f=1} f && /^\}/{f=0} f' "$TOOLS/install.sh" | awk '/ensure_placeholder_cert "\$domain"/{e=NR} /name_reaches_here "\$domain"/{r=NR} END{print (e && r && e < r) ? "before" : "not before"}')" "before"
chk "the retry timer's tick ensures it before it probes" \
    "$(awk '/ensure_placeholder_cert "\$DOMAIN"/{e=NR} /^name_reaches_here "\$DOMAIN"/{r=NR} END{print (e && r && e < r) ? "before" : "not before"}' "$SYSADMIN/arm_ssl_retry.sh")" "before"
chk "issue_origin_cert.sh ensures it before it probes" \
    "$(awk '/^ensure_placeholder_cert "\$DOMAIN"/{e=NR} /^name_reaches_here "\$DOMAIN"/{r=NR} END{print (e && r && e < r) ? "before" : "not before"}' "$SYSADMIN/issue_origin_cert.sh")" "before"
chk "no caller's advice names render_vhost.sh, which a container host does not have" \
    "$(cat "$TOOLS/install.sh" "$SYSADMIN/arm_ssl_retry.sh" "$SYSADMIN/issue_origin_cert.sh" | grep -v '^\s*#' | grep -c 'render_vhost\.sh')" "0"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
