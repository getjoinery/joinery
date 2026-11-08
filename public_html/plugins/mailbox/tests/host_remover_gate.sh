#!/bin/bash
# @joinery-test
# name: mailbox_host_remover
# tier: safe
# env: any
# needs: []
# timeout: 60
#
# The Mailbox plugin's host remover (provisioning/remove_email.sh,
# specs/plugin_host_removal.md WP3) takes off a machine what install_email.sh
# put on it for one site, and nothing else. It runs here for real against a
# scratch site under a scratch root (REMOVE_EMAIL_ROOT), with postconf, psql,
# postfix and systemctl stubbed on PATH, each recording its calls and answering
# from a small state the gate sets per case. Pinned: DROP OWNED BY runs in both
# of the site's databases before DROP ROLE; a second iemap_* role granted here
# is dropped too; a DROP ROLE PostgreSQL refuses is reported in its words and
# exits non-zero; the two maps go and the Postfix settings that named them are
# unset while the rest stays; the DKIM keys of the table's domains go when the
# table answers and stay, said, when it does not; the relay helpers go when
# present; nothing runs in a container; a second run is a no-op.

PROVISIONING="$(cd "$(dirname "${BASH_SOURCE[0]}")/../provisioning" && pwd)"
REMOVER="$PROVISIONING/remove_email.sh"
TOOLS="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)/maintenance_scripts/install_tools"

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

echo "== the remover exists, parses and is declared =="
chk "remove_email.sh parses" "$(bash -n "$REMOVER" 2>/dev/null && echo yes || echo no)" "yes"
chk "plugin.json declares it as the host_remover" "$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["host_remover"] ?? "";' "$PROVISIONING/../plugin.json")" "provisioning/remove_email.sh"
chk "it never runs under set -e (every step runs, failures are collected)" "$(grep -c '^set -euo\|^set -e$' "$REMOVER")" "0"

# --- the stubs: state in $D, every call logged in $D/log ----------------------
D="$T/state"; BIN="$T/bin"
mkdir -p "$D" "$BIN"
# postconf: main.cf settings in $D/main.cf (key = value), master.cf services in $D/master (name def).
cat > "$BIN/postconf" <<STUB
#!/bin/bash
D="$D"
echo "postconf \$*" >> "\$D/log"
[ -f "\$D/postconf_fail" ] && case "\$1" in -X|-e|-MX) echo "postconf: fatal: cannot write main.cf" >&2; exit 1 ;; esac
case "\$1" in
    -h)  sed -n "s/^\$2 = //p" "\$D/main.cf" ;;
    -X)  grep -v "^\$2 = " "\$D/main.cf" > "\$D/main.cf.new"; mv "\$D/main.cf.new" "\$D/main.cf" ;;
    -e)  k="\${2%% = *}"; grep -v "^\$k = " "\$D/main.cf" > "\$D/main.cf.new"; mv "\$D/main.cf.new" "\$D/main.cf"; echo "\$2" >> "\$D/main.cf" ;;
    -M)  grep "^\${2%/*} " "\$D/master" ;;
    -MX) grep -v "^\${2%/*} " "\$D/master" > "\$D/master.new"; mv "\$D/master.new" "\$D/master" ;;
esac
exit 0
STUB
cat > "$BIN/postfix" <<STUB
#!/bin/bash
echo "postfix \$*" >> "$D/log"
[ "\$1" = status ] && { [ -f "$D/postfix_running" ]; exit \$?; }
exit 0
STUB
printf '#!/bin/bash\necho "systemctl $*" >> "%s/log"\nexit 0\n' "$D" > "$BIN/systemctl"
# psql: databases in $D/databases, roles in $D/roles, CONNECT grants in $D/grants
# ("db role"), the domains table present when $D/table exists, its rows in
# $D/domains. The statement arrives on stdin with :'x' / :"x" variables from
# -v; DROP ROLE is refused while any grant to the role is left anywhere.
cat > "$BIN/psql" <<'STUB'
#!/bin/bash
D="__D__"
db=""; declare -A v=()
while [ $# -gt 0 ]; do
    case "$1" in
        -d) db="$2"; shift 2 ;;
        -v) v["${2%%=*}"]="${2#*=}"; shift 2 ;;
        *) shift ;;
    esac
done
sql="$(cat)"
echo "psql pw=${PGPASSWORD:-} db=$db $(printf '%s' "$sql" | tr '\n' ' ') [$(for k in d r; do [ -n "${v[$k]+x}" ] && printf '%s=%s ' "$k" "${v[$k]}"; done)]" >> "$D/log"
[ -f "$D/pgdown" ] && { echo "psql: error: connection to server failed" >&2; exit 2; }
case "$sql" in
    "SELECT 1;") echo 1 ;;
    *"FROM pg_database WHERE datname = :'d';") grep -qxF -- "${v[d]}" "$D/databases" && echo 1 ;;
    *"FROM pg_roles WHERE rolname = :'r';") grep -qxF -- "${v[r]}" "$D/roles" && echo 1 ;;
    *"aclexplode"*) sed -n "s/^${v[d]} //p" "$D/grants" | sort ;;
    'DROP OWNED BY :"r";') grep -vxF -- "$db ${v[r]}" "$D/grants" > "$D/grants.new"; mv "$D/grants.new" "$D/grants" ;;
    'DROP ROLE IF EXISTS :"r";')
        if grep -q " ${v[r]}\$" "$D/grants"; then
            other="$(grep " ${v[r]}\$" "$D/grants" | head -1 | cut -d' ' -f1)"
            printf 'ERROR:  role "%s" cannot be dropped because some objects depend on it\nDETAIL:  privileges for database %s\n' "${v[r]}" "$other" >&2
            exit 1
        fi
        grep -vxF -- "${v[r]}" "$D/roles" > "$D/roles.new"; mv "$D/roles.new" "$D/roles" ;;
    *"to_regclass('public.ied_inbound_email_domains')"*) [ -f "$D/table" ] && echo t || echo f ;;
    *"SELECT ied_domain FROM ied_inbound_email_domains"*) cat "$D/domains" ;;
esac
exit 0
STUB
sed -i "s#__D__#$D#" "$BIN/psql"
chmod +x "$BIN"/*

# --- the scratch site and machine ----------------------------------------------
SITE="$T/site"; FS="$T/fs"
site() {   # $1 = deployment_environment or empty
    rm -rf "$SITE" "$FS"
    local prov="$SITE/public_html/plugins/mailbox/provisioning"
    mkdir -p "$prov" "$SITE/config" "$SITE/maintenance_scripts/install_tools"
    cp "$REMOVER" "$PROVISIONING/rspamd_stateless.sh" "$prov/"
    cp "$TOOLS/_config_value.php" "$SITE/maintenance_scripts/install_tools/"
    {
        echo '<?php'
        echo "\$this->settings['dbname'] = 'gee';"
        echo "\$this->settings['dbusername'] = 'joinery_gee';"
        echo "\$this->settings['dbpassword'] = 'rolepw';"
        echo "\$this->settings['dbname_test'] = 'test_gee';"
        [ -n "$1" ] && echo "\$this->settings['deployment_environment'] = '$1';"
    } > "$SITE/config/Globalvars_site.php"
    printf "<?php\n\$this->settings['dbadminpassword'] = 'adminpw';\n" > "$SITE/config/db_admin.php"
    mkdir -p "$FS/etc/postfix" "$FS/etc/rspamd/dkim/gee.example" "$FS/etc/rspamd/dkim/other.example" "$FS/usr/local/sbin" "$FS/etc/sudoers.d"
    echo 'password = s3cret' > "$FS/etc/postfix/joinery-domains.cf"
    echo 'password = s3cret' > "$FS/etc/postfix/joinery-recipient-access.cf"
    echo key > "$FS/etc/rspamd/dkim/gee.example/mail.private"; echo key > "$FS/etc/rspamd/dkim/other.example/mail.private"
    printf 'gee.example %s/etc/rspamd/dkim/gee.example/mail.private\nother.example %s/etc/rspamd/dkim/other.example/mail.private\n' "$FS" "$FS" > "$FS/etc/rspamd/dkim/signing.map"
    : > "$FS/usr/local/sbin/joinery-mail-listener"; : > "$FS/usr/local/sbin/joinery-dkim-remove"; : > "$FS/etc/sudoers.d/joinery-relay"
    # The machine's Postfix and PostgreSQL as the installer left them.
    : > "$D/log"; rm -f "$D/pgdown" "$D/postconf_fail"; touch "$D/postfix_running" "$D/table"
    printf 'virtual_mailbox_domains = proxy:pgsql:/etc/postfix/joinery-domains.cf\nvirtual_transport = joinery\nsmtpd_recipient_restrictions = permit_mynetworks, reject_unauth_destination, reject_rbl_client zen.spamhaus.org=127.0.0.[2..11], check_recipient_access proxy:pgsql:/etc/postfix/joinery-recipient-access.cf, permit\nproxy_read_maps = $local_recipient_maps $mydestination proxy:pgsql:/etc/postfix/joinery-recipient-access.cf\nmyhostname = mail.gee.example\n' > "$D/main.cf"
    printf 'joinery unix - n n - 5 pipe flags=DRh user=www-data argv=/usr/bin/php handler\nsmtp inet n - y - - smtpd\n' > "$D/master"
    printf 'gee\ntest_gee\npostgres\n' > "$D/databases"
    printf 'iemap_gee\niemap_other\niemap_elsewhere\n' > "$D/roles"
    printf 'gee iemap_gee\ntest_gee iemap_gee\ngee iemap_other\nfar iemap_elsewhere\n' > "$D/grants"
    printf 'gee.example\n' > "$D/domains"
}
run() { PATH="$BIN:$PATH" REMOVE_EMAIL_ROOT="$FS" bash "$SITE/public_html/plugins/mailbox/provisioning/remove_email.sh" gee "$SITE" > "$T/out" 2>&1; echo $?; }
logged() { grep -c -- "$1" "$D/log" | tr -d ' '; }
there() { [ -e "$FS$1" ] && echo yes || echo no; }
loggedF() { grep -cF -- "$1" "$D/log" | tr -d ' '; }
line_of() { grep -nF -- "$1" "$D/log" | head -1 | cut -d: -f1; }

echo "== a site removal: everything the installer put here goes =="
site baremetal
rc=$(run)
chk "exits 0" "$rc:$(grep -c "machine state for gee is off" "$T/out")" "0:1"
chk "the lookup roles' grants are revoked in BOTH of the site's databases before the role is dropped" \
    "$([ "$(line_of 'db=gee DROP OWNED BY :"r"; [r=iemap_gee')" -lt "$(line_of 'DROP ROLE IF EXISTS :"r"; [r=iemap_gee')" ] && [ "$(line_of 'db=test_gee DROP OWNED BY :"r"; [r=iemap_gee')" -lt "$(line_of 'DROP ROLE IF EXISTS :"r"; [r=iemap_gee')" ] && echo yes)" "yes"
chk "this site's role is dropped" "$(grep -cxF iemap_gee "$D/roles")|$(grep -c '^postgres: dropped role iemap_gee$' "$T/out")" "0|1"
chk "a second iemap_* role granted CONNECT here is dropped too" "$(grep -cxF iemap_other "$D/roles")|$(loggedF 'DROP OWNED BY :"r"; [r=iemap_other')" "0|2"
chk "an iemap_* role granted only elsewhere is left alone" "$(grep -cxF iemap_elsewhere "$D/roles")|$(logged 'r=iemap_elsewhere')" "1|0"
chk "as postgres, with the pair from config/db_admin.php, never the site's" "$(logged 'pw=adminpw')|$(logged 'pw=rolepw')|$(logged 'pw= ')" "$(logged '^psql')|0|0"
chk "the two maps are gone" "$(there /etc/postfix/joinery-domains.cf)|$(there /etc/postfix/joinery-recipient-access.cf)" "no|no"
chk "virtual_mailbox_domains and virtual_transport are unset" "$(grep -c '^virtual_' "$D/main.cf")" "0"
chk "the recipient lookup left smtpd_recipient_restrictions, the RBL checks beside it stay" \
    "$(sed -n 's/^smtpd_recipient_restrictions = //p' "$D/main.cf")" "permit_mynetworks, reject_unauth_destination, reject_rbl_client zen.spamhaus.org=127.0.0.[2..11], permit"
chk "and proxy_read_maps" "$(sed -n 's/^proxy_read_maps = //p' "$D/main.cf")" '$local_recipient_maps $mydestination'
chk "the joinery pipe service left master.cf, the others stay" "$(grep -c '^joinery ' "$D/master")|$(grep -c '^smtp ' "$D/master")" "0|1"
chk "the machine's own hostname setting is untouched" "$(sed -n 's/^myhostname = //p' "$D/main.cf")" "mail.gee.example"
chk "a running Postfix is reloaded, once" "$(logged '^postfix reload')" "1"
chk "the site's domain's DKIM key goes, another domain's stays" "$(there /etc/rspamd/dkim/gee.example)|$(there /etc/rspamd/dkim/other.example/mail.private)" "no|yes"
chk "and the signing list is rewritten from what is left" "$(cat "$FS/etc/rspamd/dkim/signing.map")" "other.example $FS/etc/rspamd/dkim/other.example/mail.private"
chk "the relay helpers and their sudoers rule go" "$(there /usr/local/sbin/joinery-mail-listener)|$(there /usr/local/sbin/joinery-dkim-remove)|$(there /etc/sudoers.d/joinery-relay)" "no|no|no"
chk "it says what stays" "$(grep -c 'left in place as the machine.s own: the mail packages, Postfix itself' "$T/out")" "1"
chk "no secret in the transcript" "$(grep -c 's3cret\|adminpw\|rolepw' "$T/out")" "0"

echo "== a second run is a no-op =="
: > "$D/log"
rc=$(run)
chk "exits 0 and changes nothing" "$rc|$(logged 'DROP')|$(logged 'postconf -X')|$(logged 'postconf -e')|$(logged 'postconf -MX')|$(logged '^postfix reload')" "0|0|0|0|0|0"
chk "says the role is already gone" "$(grep -c '^postgres: role iemap_gee is already gone$' "$T/out")" "1"

echo "== a DROP ROLE PostgreSQL refuses is said in its words, and counts as left =="
site baremetal
echo 'gee_copy iemap_gee' >> "$D/grants"     # a copy of the site's database on the same cluster
rc=$(run)
chk "exits 1" "$rc" "1"
chk "the refusal names the database that still grants to the role, and says what that means" \
    "$(grep -c 'left: role iemap_gee was not dropped: ERROR:  role "iemap_gee" cannot be dropped because some objects depend on it. Expected when a copy or restore of this site.s database on this cluster still grants to it' "$T/out")" "1"
chk "the other role and everything else still went" "$(grep -cxF iemap_other "$D/roles")|$(there /etc/postfix/joinery-domains.cf)|$(there /etc/rspamd/dkim/gee.example)" "0|no|no"

echo "== the domains table gone (a plugin uninstall): the keys are left and said =="
site baremetal
rm -f "$D/table"
rc=$(run)
chk "exits 0 (nothing left behind that the remover owns)" "$rc" "0"
chk "the key stays, and the transcript says so" "$(there /etc/rspamd/dkim/gee.example/mail.private)|$(grep -c 'domains table cannot be read here.*left for a reinstall to pick up again' "$T/out")" "yes|1"
chk "the role and the maps still go" "$(grep -cxF iemap_gee "$D/roles")|$(there /etc/postfix/joinery-domains.cf)" "0|no"

echo "== the site's database already dropped: the role still goes =="
site baremetal
printf 'postgres\n' > "$D/databases"
printf 'far iemap_elsewhere\n' > "$D/grants"     # a dropped database's grants went with it
rc=$(run)
chk "DROP ROLE runs with no DROP OWNED BY (no database to run it in)" "$rc|$(logged 'DROP OWNED BY')|$(grep -cxF iemap_gee "$D/roles")" "0|0|0"

echo "== PostgreSQL unreachable: said, left, the rest still goes =="
site baremetal
touch "$D/pgdown"
rc=$(run)
chk "exits 1 naming the reason" "$rc|$(grep -c 'left: PostgreSQL could not be reached as postgres to drop the lookup roles: psql: error' "$T/out")" "1|1"
chk "and the pair never reaches the transcript, nor does a 'no pair' note when one exists" "$(grep -c 'adminpw\|rolepw\|s3cret' "$T/out")|$(grep -c 'no postgres pair was found' "$T/out")" "0|0"
chk "Postfix and the helpers still went" "$(there /etc/postfix/joinery-domains.cf)|$(there /etc/sudoers.d/joinery-relay)|$(grep -c '^virtual_' "$D/main.cf")" "no|no|0"

echo "== a hand-configured Postfix is left as it is =="
site baremetal
printf 'virtual_transport = lmtp:unix:private/dovecot\nmyhostname = mail.gee.example\n' > "$D/main.cf"
rc=$(run)
chk "a virtual_transport that is not ours stays" "$(sed -n 's/^virtual_transport = //p' "$D/main.cf")|$(logged 'postconf -X')" "lmtp:unix:private/dovecot|0"

echo "== a stopped Postfix is not started =="
site baremetal
rm -f "$D/postfix_running"
rc=$(run)
chk "no reload, said" "$(logged '^postfix reload')|$(grep -c 'not running; it reads the new settings when it starts' "$T/out")" "0|1"

echo "== a postconf that fails counts as left, and the maps stay (reviewer2 F1) =="
site baremetal
touch "$D/postconf_fail"
rc=$(run)
chk "exits 1 naming each failed postconf" "$rc|$(grep -c '^remove_email: left: Postfix: postconf -X virtual_mailbox_domains failed' "$T/out")|$(grep -c '^remove_email: left: Postfix: postconf -MX joinery/unix failed' "$T/out")" "1|1|1"
chk "nothing is reported as done" "$(grep -c '^postfix: virtual_mailbox_domains no longer' "$T/out")" "0"
chk "the map files stay while Postfix still names them, said" "$(there /etc/postfix/joinery-domains.cf)|$(there /etc/postfix/joinery-recipient-access.cf)|$(grep -c 'Postfix still names the site.s lookups (virtual_mailbox_domains = proxy:pgsql:/etc/postfix/joinery-domains.cf); the map files are kept' "$T/out")" "yes|yes|1"
chk "the roles and helpers still go" "$(grep -cxF iemap_gee "$D/roles")|$(there /etc/sudoers.d/joinery-relay)" "0|no"

echo "== a setting that names the map in another spelling is said as left, maps kept (reviewer2 F2) =="
site baremetal
printf 'virtual_mailbox_domains = proxy:pgsql:/etc/postfix/joinery-domains.cf\nsmtpd_recipient_restrictions = permit_mynetworks,  check_recipient_access  proxy:pgsql:/etc/postfix/joinery-recipient-access.cf, permit\nmyhostname = mail.gee.example\n' > "$D/main.cf"
rc=$(run)
chk "the exact match is unset, the other spelling is left and said" "$rc|$(grep -c '^virtual_mailbox_domains' "$D/main.cf")|$(grep -c 'left: Postfix still names the site.s lookups (smtpd_recipient_restrictions = permit_mynetworks,  check_recipient_access' "$T/out")" "1|0|1"
chk "and the map files stay" "$(there /etc/postfix/joinery-recipient-access.cf)" "yes"

echo "== the postgres pair handed down as PGPASSWORD is used when the site has no db_admin.php (reviewer2 F3) =="
site baremetal
rm -f "$SITE/config/db_admin.php"
rc=$(PATH="$BIN:$PATH" REMOVE_EMAIL_ROOT="$FS" PGPASSWORD=handedpw bash "$SITE/public_html/plugins/mailbox/provisioning/remove_email.sh" gee "$SITE" > "$T/out" 2>&1; echo $?)
chk "the roles are dropped with the handed pair" "$rc|$(grep -cxF iemap_gee "$D/roles")|$(logged 'pw=handedpw')|$(logged 'pw= ')" "0|0|$(logged '^psql')|0"
site baremetal
rm -f "$SITE/config/db_admin.php"
touch "$D/pgdown"
rc=$(run)
chk "with no pair at all the probe is still made and the refusal says so" "$rc|$(grep -c 'could not be reached as postgres.*psql: error.*no postgres pair was found (config/db_admin.php, or PGPASSWORD)' "$T/out")" "1|1"
chk "the handed pair never reaches the transcript either" "$(PATH="$BIN:$PATH" REMOVE_EMAIL_ROOT="$FS" PGPASSWORD=handedpw bash "$SITE/public_html/plugins/mailbox/provisioning/remove_email.sh" gee "$SITE" 2>&1 | grep -c 'handedpw')" "0"

echo "== in a site container nothing runs =="
site docker
rc=$(run)
chk "exits 0 and says why" "$rc|$(grep -c 'runs in a container, which has no mail server of its own: nothing to take off' "$T/out")" "0|1"
chk "calls nothing and removes nothing" "$(wc -l < "$D/log" | tr -d ' ')|$(there /etc/postfix/joinery-domains.cf)|$(there /etc/sudoers.d/joinery-relay)" "0|yes|yes"

echo "== no site config: refused as left =="
site baremetal
rm -f "$SITE/config/Globalvars_site.php"
rc=$(run)
chk "exits 1 having taken nothing off" "$rc|$(grep -c 'left: no site config at' "$T/out")|$(there /etc/postfix/joinery-domains.cf)" "1|1|yes"

echo "== not root and no scratch root: refused =="
rc=$(PATH="$BIN:$PATH" bash "$REMOVER" gee "$SITE" > "$T/out" 2>&1; echo $?)
chk "exits 1 asking for root" "$rc|$(grep -c 'must run as root' "$T/out")" "1|1"

echo
echo "mailbox_host_remover: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
