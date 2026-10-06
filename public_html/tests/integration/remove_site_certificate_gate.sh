#!/bin/bash
# @joinery-test
# name: remove_site_certificate
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/remove_site_certificate.sh, maintenance_scripts/sysadmin_tools/remove_account.sh]
#
# remove_site_certificate.sh is what the agent's remove_site_certificate
# operate word runs as root, and what remove_account.sh calls for each
# certificate a removed site's vhost named. This gate pins its contract: a
# name that is not a certificate name is refused; a certificate anything still
# uses is refused and nothing is touched (a file under /etc outside Apache, a
# file Apache loads wherever it lives, a site that could be enabled, or the
# machine serving it on 443), while a vhost backup Apache never loads does not
# keep it; otherwise the lineage goes through certbot delete and the
# placeholder directory goes, each judged by looking afterwards, and the
# object always has every key. The paths hang from a temporary tree; certbot,
# apache2ctl and openssl are stubs.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/remove_site_certificate.sh"
ACCOUNT="$ROOT/maintenance_scripts/sysadmin_tools/remove_account.sh"
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

E="$T/etc"
mkdir -p "$T/bin" "$E/apache2/sites-enabled" "$E/apache2/sites-available"
# The stub deletes the lineage the way certbot does, unless told to fail.
cat > "$T/bin/certbot" <<STUB
#!/bin/bash
echo "\$*" >> "$T/certbot_calls"
[ "\${STUB_CERTBOT_FAIL:-0}" = 1 ] && exit 1
name=""
while [ \$# -gt 0 ]; do [ "\$1" = "--cert-name" ] && name="\$2"; shift; done
rm -rf "$E/letsencrypt/live/\$name" "$E/letsencrypt/archive/\$name" "$E/letsencrypt/renewal/\$name.conf"
STUB
chmod +x "$T/bin/certbot"
# apache2ctl lists apache2.conf plus whatever the test puts in includes.
cat > "$T/bin/apache2ctl" <<STUB
#!/bin/bash
[ "\${STUB_APACHE_RC:-0}" = 0 ] || exit 1
echo "Included configuration files:"
echo "  (*) $E/apache2/apache2.conf"
[ -f "$T/includes" ] && sed 's/^/    (1) /' "$T/includes"
exit 0
STUB
chmod +x "$T/bin/apache2ctl"
# openssl: s_client hands back what the test says is served; x509 prints a
# fingerprint made of the first line it reads, so a lineage's fingerprint is
# its file's first line.
cat > "$T/bin/openssl" <<STUB
#!/bin/bash
case "\$1" in
    s_client) cat > /dev/null; [ -f "$T/served" ] && cat "$T/served"; exit 0 ;;
    x509) first=\$(head -1); echo "sha256 Fingerprint=\$first" ;;
esac
STUB
chmod +x "$T/bin/openssl"

lineage() { # lineage <name>: a certbot lineage on disk
    mkdir -p "$E/letsencrypt/live/$1" "$E/letsencrypt/archive/$1" "$E/letsencrypt/renewal"
    echo "$1" > "$E/letsencrypt/live/$1/fullchain.pem"
    echo x > "$E/letsencrypt/renewal/$1.conf"
}
placeholder() { mkdir -p "$E/ssl/joinery/$1"; echo "placeholder-$1" > "$E/ssl/joinery/$1/fullchain.pem"; }
# A vhost rendered the way default_proxy_vhost.conf renders one.
vhost() { # vhost <file> <domain>
    cat > "$1" <<V
<VirtualHost *:80>
    ServerName $2
    <IfFile /etc/letsencrypt/live/$2/fullchain.pem>
    </IfFile>
</VirtualHost>
Define JOINERY_CERT_DIR_x /etc/ssl/joinery/$2
<IfFile /etc/letsencrypt/live/$2/fullchain.pem>
Define JOINERY_CERT_DIR_x /etc/letsencrypt/live/$2
</IfFile>
V
}
run() { PATH="$T/bin:$PATH" REMOVE_SITE_CERTIFICATE_ETC="$E" bash "$SCRIPT" "$@"; }
jv() { php -r '$o=json_decode(file_get_contents("php://stdin"),true); echo is_array($o) ? ($argv[1]==="keys" ? implode(",",array_keys($o)) : ($o[$argv[1]] ?? "ABSENT")) : "NOTJSON";' "$1"; }

echo "=== A name that is not a certificate name is refused, and nothing is touched ==="
lineage keep.example.com
for bad in "" "Keep.example.com" "keep" "../keep.example.com" "keep.example.com/x" "-keep.example.com" "keep.example.com;rm" "*.example.com"; do
    rm -f "$T/certbot_calls"
    out="$(run "$bad" 2>"$T/err")"; rc=$?
    chk "refused: '$bad' (exit 2)" "$rc" "2"
    chk "refused: '$bad' printed no object" "$(printf '%s' "$out" | wc -c)" "0"
    chk "refused: '$bad' called no certbot" "$([ -f "$T/certbot_calls" ] && echo called || echo none)" "none"
done
chk "the lineage is still there" "$([ -d "$E/letsencrypt/live/keep.example.com" ] && echo yes || echo no)" "yes"

echo "=== A certificate an enabled site still names is refused, and kept ==="
vhost "$E/apache2/sites-available/keep.conf" keep.example.com
ln -s ../sites-available/keep.conf "$E/apache2/sites-enabled/keep.conf"
placeholder keep.example.com
rm -f "$T/certbot_calls"
out="$(run keep.example.com 2>"$T/err")"; rc=$?
chk "in use: exit 2" "$rc" "2"
chk "in use: names the file using it" "$(grep -c 'is still in use: named by .*sites-available/keep.conf' "$T/err")" "1"
chk "in use: certbot never called" "$([ -f "$T/certbot_calls" ] && echo called || echo none)" "none"
chk "in use: lineage kept" "$([ -d "$E/letsencrypt/live/keep.example.com" ] && echo yes || echo no)" "yes"
chk "in use: placeholder kept" "$([ -d "$E/ssl/joinery/keep.example.com" ] && echo yes || echo no)" "yes"

echo "=== A name that only resembles one in use is not protected by it ==="
# keep.example.com's vhost must not shield demo.keep.example.com or keep.example.com.au.
for other in demo.keep.example.com keep.example.com.au; do
    lineage "$other"; placeholder "$other"
    out="$(run "$other" 2>"$T/err")"; rc=$?
    chk "$other: exit 0" "$rc" "0"
    chk "$other: lineage removed" "$(printf '%s' "$out" | jv letsencrypt)" "removed"
    chk "$other: placeholder removed" "$(printf '%s' "$out" | jv placeholder)" "removed"
done

echo "=== Anything else that uses it keeps it ==="
refused() { # refused <label> <name> <reason-fragment>: exit 2, said why, lineage kept, certbot not called
    rm -f "$T/certbot_calls"
    out="$(run "$2" 2>"$T/err")"; rc=$?
    chk "$1: exit 2" "$rc" "2"
    chk "$1: says why" "$(grep -c "$3" "$T/err")" "1"
    chk "$1: certbot never called" "$([ -f "$T/certbot_calls" ] && echo called || echo none)" "none"
    chk "$1: lineage kept" "$([ -d "$E/letsencrypt/live/$2" ] && echo yes || echo no)" "yes"
}
lineage mail.example.com
mkdir -p "$E/postfix"; echo "smtpd_tls_cert_file = /etc/letsencrypt/live/mail.example.com/fullchain.pem" > "$E/postfix/main.cf"
refused "a mail server's config outside Apache" mail.example.com "named by .*postfix/main.cf"
rm -f "$E/postfix/main.cf"
lineage dis.example.com
vhost "$E/apache2/sites-available/dis.conf" dis.example.com
refused "a disabled site that could be enabled" dis.example.com "named by .*sites-available/dis.conf"
rm -f "$E/apache2/sites-available/dis.conf"
lineage inc.example.com
mkdir -p "$T/outside"; vhost "$T/outside/extra.conf" inc.example.com; echo "$T/outside/extra.conf" > "$T/includes"
refused "a file Apache loads from outside /etc" inc.example.com "named by $T/outside/extra.conf"
# Apache is asked whenever apache2ctl is there, not only when its tree is.
E2="$T/etc2"; mkdir -p "$E2/letsencrypt/live/inc.example.com"; echo inc > "$E2/letsencrypt/live/inc.example.com/fullchain.pem"
out="$(PATH="$T/bin:$PATH" REMOVE_SITE_CERTIFICATE_ETC="$E2" bash "$SCRIPT" inc.example.com 2>"$T/err")"; rc=$?
chk "a machine with apache2ctl and no Apache tree still asks Apache (exit 2)" "$rc" "2"
chk "and names the file Apache loads" "$(grep -c "named by $T/outside/extra.conf" "$T/err")" "1"
rm -f "$T/includes"
lineage broken.example.com
rm -f "$T/certbot_calls"
out="$(STUB_APACHE_RC=1 run broken.example.com 2>"$T/err")"; rc=$?
chk "an Apache configuration that does not load refuses" "$rc" "2"
chk "and says it cannot read what Apache uses" "$(grep -c 'does not load' "$T/err")" "1"
lineage served.example.com
echo "served.example.com" > "$T/served"
refused "served on 443 right now" served.example.com "serves it for served.example.com on 443"
rm -f "$T/served"
placeholder ph.example.com
echo "placeholder-ph.example.com" > "$T/served"
rm -f "$T/certbot_calls"
out="$(run ph.example.com 2>"$T/err")"; rc=$?
chk "a placeholder served on 443 right now is kept too" "$rc" "2"
chk "its directory stays" "$([ -d "$E/ssl/joinery/ph.example.com" ] && echo yes || echo no)" "yes"
echo "another.example.com" > "$T/served"
out="$(run served.example.com 2>"$T/err")"; rc=$?
chk "a different certificate served on 443 does not keep it" "$rc" "0"
rm -f "$T/served"

echo "=== A certificate nothing uses goes: lineage through certbot, placeholder too ==="
lineage gone.example.com; placeholder gone.example.com
# The vhost's backups beside it do not keep it: Apache never loads them.
vhost "$E/apache2/sites-available/gone.conf.before-render.20260911150535" gone.example.com
vhost "$E/apache2/sites-available/gone.conf.bak" gone.example.com
rm -f "$T/certbot_calls"
out="$(run gone.example.com 2>"$T/err")"; rc=$?
chk "exit 0" "$rc" "0"
chk "nothing on stderr" "$(wc -c < "$T/err" | tr -d ' ')" "0"
chk "every key present, in order" "$(printf '%s' "$out" | jv keys)" "name,letsencrypt,placeholder"
chk "lineage removed" "$(printf '%s' "$out" | jv letsencrypt)" "removed"
chk "placeholder removed" "$(printf '%s' "$out" | jv placeholder)" "removed"
chk "certbot asked to delete exactly that lineage" "$(cat "$T/certbot_calls")" "delete --cert-name gone.example.com --non-interactive"
chk "renewal config gone, so renewal stops" "$([ -e "$E/letsencrypt/renewal/gone.example.com.conf" ] && echo yes || echo no)" "no"

echo "=== A second lineage (-0001) is its own name; the placeholder is the bare domain's ==="
lineage two.example.com-0001; placeholder two.example.com
out="$(run two.example.com-0001)"
chk "-0001 lineage removed" "$(printf '%s' "$out" | jv letsencrypt)" "removed"
chk "bare domain's placeholder removed" "$(printf '%s' "$out" | jv placeholder)" "removed"

echo "=== Nothing there is absent, not an error ==="
out="$(run never.example.com)"; rc=$?
chk "exit 0" "$rc" "0"
chk "letsencrypt absent" "$(printf '%s' "$out" | jv letsencrypt)" "absent"
chk "placeholder absent" "$(printf '%s' "$out" | jv placeholder)" "absent"

echo "=== certbot failing is reported as failed, judged by looking ==="
lineage stuck.example.com
out="$(STUB_CERTBOT_FAIL=1 run stuck.example.com)"
chk "letsencrypt failed" "$(printf '%s' "$out" | jv letsencrypt)" "failed"

echo "=== remove_account.sh reads the vhost's certificates before removing it, and calls this script ==="
# The extraction remove_account.sh runs, against a vhost rendered from the template.
vhost "$T/site.conf" site.example.com
names=$( { grep -oE '/etc/letsencrypt/live/[a-z0-9.-]+/' "$T/site.conf" | cut -d/ -f5
           grep -oE '/etc/ssl/joinery/[a-z0-9.-]+' "$T/site.conf" | cut -d/ -f5; } 2>/dev/null | sort -u )
chk "the template's certificate names, once" "$names" "site.example.com"
grep -q "grep -oE '/etc/letsencrypt/live/\[a-z0-9.-\]+/' \"\$vh\"" "$ACCOUNT" && same=yes || same=no
chk "remove_account.sh runs that same extraction on each vhost" "$same" "yes"
grep -q 'for vh in "\$VIRTUALHOST_FILE" "\$LE_SSL_FILE"; do' "$ACCOUNT" && both=yes || both=no
chk "on the site's vhost and its certbot-made SSL vhost" "$both" "yes"
read_line=$(grep -n 'CERT_NAMES="\$CERT_NAMES"' "$ACCOUNT" | head -1 | cut -d: -f1)
rm_line=$(grep -n 'rm -f "\${VIRTUALHOST_FILE:?}"' "$ACCOUNT" | head -1 | cut -d: -f1)
bak_line=$(grep -n 'for backup in "\$VIRTUALHOST_FILE"\.\*; do' "$ACCOUNT" | head -1 | cut -d: -f1)
call_line=$(grep -n 'bash "\$CERT_SCRIPT" "\$cert"' "$ACCOUNT" | head -1 | cut -d: -f1)
chk "it reads the names before the vhost is removed" "$([ "$read_line" -lt "$rm_line" ] && echo yes || echo no)" "yes"
chk "it removes the vhost's backups" "$([ -n "$bak_line" ] && echo yes || echo no)" "yes"
chk "and removes the certificates after both" "$([ "$call_line" -gt "$rm_line" ] && [ "$call_line" -gt "${bak_line:-999999}" ] && echo yes || echo no)" "yes"
grep -q 'CERT_SCRIPT="\$(dirname "\$0")/remove_site_certificate.sh"' "$ACCOUNT" && sib=yes || sib=no
chk "the script it calls is its sibling" "$sib" "yes"

echo "RESULT: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
