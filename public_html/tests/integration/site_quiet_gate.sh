#!/bin/bash
# @joinery-test
# name: site_quiet
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/install_tools/_site_state.sh, maintenance_scripts/sysadmin_tools/site_quiet.sh, maintenance_scripts/install_tools/_plugin_installers_start.sh]
#
# The quiet state (specs/site_copy.md WP5): a site that runs nothing and sends
# nothing, enforced by the machine. Driven unprivileged against a fixture /etc
# (JOINERY_SITE_STATE_ROOT) with nft, systemctl, apache2ctl, postconf, postfix
# and pgrep stubbed on PATH, each recording what it was asked. A real Apache on
# a spare port answers a copy's drop-in (the look paths, the 503); what the
# kernel does with the table is the live check's (needs root).

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/install_tools"
SYSTOOLS="$ROOT/maintenance_scripts/sysadmin_tools"
T=$(mktemp -d)
trap '[ -s "$T/apache/logs/pid" ] && /usr/sbin/apache2 -k stop -f "$T/apache/main.conf" >/dev/null 2>&1; rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed+1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1)); fi
}
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; echo "RESULT: PASS 0 0"; exit 0; fi
if ! id -u www-data >/dev/null 2>&1; then echo "  SKIP: no www-data account here"; echo "RESULT: PASS 0 0"; exit 0; fi

# --- Stubs ---------------------------------------------------------------
B="$T/bin"; ST="$T/stub"; mkdir -p "$B" "$ST/pf"
cat > "$B/nft" <<EOF
#!/bin/bash
echo "nft \$*" >> "$ST/calls"
case "\$1" in
  -f) cp "\$2" "$ST/nft_loaded" ;;
  list) [ -f "$ST/nft_loaded" ] ;;
  delete) rm -f "$ST/nft_loaded" ;;
esac
EOF
cat > "$B/systemctl" <<EOF
#!/bin/bash
echo "systemctl \$*" >> "$ST/calls"
args=("\$@"); [ "\${args[1]:-}" = "--quiet" ] && unit="\${args[2]}" || unit="\${args[1]:-}"
[ "\$2" = "--now" ] && unit="\$3"
case "\$1" in
  is-enabled|is-active) [ -f "$ST/on.\$unit" ] ;;
  enable) touch "$ST/on.\$unit" ;;
  disable) rm -f "$ST/on.\$unit" ;;
  *) exit 0 ;;
esac
EOF
cat > "$B/apache2ctl" <<EOF
#!/bin/bash
echo "apache2ctl \$*" >> "$ST/calls"; exit 0
EOF
cat > "$B/postconf" <<EOF
#!/bin/bash
echo "postconf \$*" >> "$ST/calls"
case "\$1" in
  -M) cat "$ST/master" ;;
  -Ph) f="$ST/pf/\$(echo "\$2" | tr / _)"; [ -f "\$f" ] && cat "\$f"; exit 0 ;;
  -P) k="\${2%%=*}"; v="\${2#*=}"; printf '%s\n' "\$v" > "$ST/pf/\$(echo "\$k" | tr / _)" ;;
  -PX) rm -f "$ST/pf/\$(echo "\$2" | tr / _)" ;;
esac
EOF
cat > "$B/postfix" <<EOF
#!/bin/bash
echo "postfix \$*" >> "$ST/calls"; exit 0
EOF
cat > "$B/pgrep" <<EOF
#!/bin/bash
case "\$*" in
  *smtpd*) [ -s "$ST/pgrep_smtpd" ] && cat "$ST/pgrep_smtpd" ;;
  *) [ -s "$ST/pgrep" ] && cat "$ST/pgrep" ;;
esac
EOF
cat > "$B/postqueue" <<EOF
#!/bin/bash
[ -s "$ST/postqueue" ] && cat "$ST/postqueue"; exit 0
EOF
chmod +x "$B"/*
export PATH="$B:$PATH"
printf 'smtp      inet  n  -  y  -  -  smtpd\nsubmission inet n  -  y  -  -  smtpd -o smtpd_client_restrictions=$mua_client_restrictions\npickup    unix  n  -  y  60 1  pickup\n' > "$ST/master"
printf '%s\n' '$mua_client_restrictions' > "$ST/pf/submission_inet_smtpd_client_restrictions"
touch "$ST/on.certbot.timer"

# --- A fixture site and /etc ----------------------------------------------
R="$T/fs"; mkdir -p "$R/etc/cron.d" "$R/etc/apache2/conf-available" "$R/etc/apache2/conf-enabled" "$R/etc/systemd/system"
SITE="$T/html/qsite"
mkdir -p "$SITE/maintenance_scripts/install_tools" "$SITE/maintenance_scripts/sysadmin_tools" "$SITE/public_html"
cp "$TOOLS/_site_state.sh" "$SITE/maintenance_scripts/install_tools/"
cp "$SYSTOOLS/site_quiet.sh" "$SYSTOOLS/host_runner_lock.sh" "$SITE/maintenance_scripts/sysadmin_tools/"
echo "* * * * * www-data php my own edit" > "$R/etc/cron.d/joinery-qsite"
export JOINERY_SITE_STATE_ROOT="$R" JOINERY_SITE_STATE_WAIT=2 JOINERY_LOCK_WAIT_SECONDS=2
Q="$SITE/maintenance_scripts/sysadmin_tools/site_quiet.sh"
SD="$R/etc/joinery/sites/qsite"

echo "=== on: quiet switchover, every measure ==="
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "the state file says quiet switchover" "$(cat "$SD/state")" "quiet switchover"
chk "the state directory is root's alone" "$(stat -c %a "$SD")" "700"
chk "the cron file is held, not deleted" "$([ -e "$R/etc/cron.d/joinery-qsite" ] && echo live || echo gone):$(cat "$SD/held/cron.d/joinery-qsite")" "gone:* * * * * www-data php my own edit"
chk "the firewall rule is loaded" "$(grep -c 'meta skuid' "$ST/nft_loaded")" "2"
uid_www="$(id -u www-data)"
chk "it names the web user's uid" "$(grep -c "skuid { ${uid_www}" "$ST/nft_loaded")" "2"
chk "loopback is let through before the reject" "$(grep -n -m1 'oifname "lo" accept' "$ST/nft_loaded" | cut -d: -f1)" "$(( $(grep -n -m1 'meta skuid' "$ST/nft_loaded" | cut -d: -f1) - 2 ))"
chk "and so are replies to a connection someone else opened, so a visitor sees the 503 and the owner the look" "$(grep -n -m1 'ct direction reply accept' "$ST/nft_loaded" | cut -d: -f1)" "$(( $(grep -n -m1 'meta skuid' "$ST/nft_loaded" | cut -d: -f1) - 1 ))"
chk "the file replaces the table atomically" "$(grep -c '^delete table inet joinery_site_state' "$SD/quiet.nft")" "1"
chk "the boot unit is written and enabled" "$([ -f "$R/etc/systemd/system/joinery-site-state.service" ] && echo yes):$([ -f "$ST/on.joinery-site-state.service" ] && echo on)" "yes:on"
chk "after any other firewall that loads a ruleset at boot" "$(grep -c '^After=local-fs.target nftables.service firewalld.service$' "$R/etc/systemd/system/joinery-site-state.service")" "1"
chk "and it runs before the web server" "$(grep -c '^Before=network-pre.target apache2.service' "$R/etc/systemd/system/joinery-site-state.service")" "1"
chk "the web drop-in is enabled" "$(readlink "$R/etc/apache2/conf-enabled/joinery-quiet-qsite.conf")" "../conf-available/joinery-quiet-qsite.conf"
chk "switchover: everyone gets the maintenance page" "$(grep -c 'Redirect 503' "$R/etc/apache2/conf-available/joinery-quiet-qsite.conf"):$(grep -c 'joinery_look' "$R/etc/apache2/conf-available/joinery-quiet-qsite.conf")" "1:0"
chk "Apache was checked, then reloaded" "$(grep -c 'apache2ctl configtest' "$ST/calls"):$(grep -c 'systemctl reload apache2' "$ST/calls")" "1:1"
chk "certbot's timer is off, and remembered as on" "$([ -f "$ST/on.certbot.timer" ] && echo on || echo off):$([ -f "$SD/held/certbot.timer" ] && echo held)" "off:held"
chk "every smtpd service defers" "$(cat "$ST/pf/smtp_inet_smtpd_client_restrictions"):$(cat "$ST/pf/submission_inet_smtpd_client_restrictions")" "defer:defer"
chk "and what each had before is kept" "$(cat "$SD/held/postfix" | tr '\t' '|' | tr '\n' ' ')" 'smtp/inet|- submission/inet|$mua_client_restrictions '
chk "no look secret for a switch-over" "$([ -e "$SD/look_secret" ] && echo yes || echo no)" "no"

echo "=== on again: nothing new, and a table deleted by hand is back ==="
rm -f "$ST/nft_loaded"; : > "$ST/calls"
echo "* * * * * www-data php platform default" > "$R/etc/cron.d/joinery-qsite"
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "the rule is loaded again" "$([ -f "$ST/nft_loaded" ] && echo yes)" "yes"
chk "Apache is not reloaded when nothing changed" "$(grep -c 'systemctl reload apache2' "$ST/calls")" "0"
chk "a cron file written meanwhile is removed, the held one kept" "$([ -e "$R/etc/cron.d/joinery-qsite" ] && echo live || echo gone):$(cat "$SD/held/cron.d/joinery-qsite")" "gone:* * * * * www-data php my own edit"
chk "Postfix is not touched again" "$(grep -c 'postconf -P ' "$ST/calls")" "0"

echo "=== on waits for command-line PHP ==="
echo "4242" > "$ST/pgrep"
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "a worker still running when the wait ends: exit 3, and named" "$rc:$(printf '%s' "$out" | grep -c 'pids 4242')" "3:1"
chk "the site stays quiet" "$(cat "$SD/state")" "quiet switchover"
: > "$ST/pgrep"

echo "=== on waits for mail already on its way in (B4) ==="
bash "$Q" off >/dev/null 2>&1
sleep 60 & held_session=$!
echo "$held_session" > "$ST/pgrep_smtpd"
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "a session open from before the deferral is ended once its half minute (here the wait) is up: exit 0" \
    "$rc:$(kill -0 "$held_session" 2>/dev/null && echo alive || echo ended):$(printf '%s' "$out" | grep -c "pids ${held_session}")" "0:ended:1"
wait "$held_session" 2>/dev/null
: > "$ST/pgrep_smtpd"
echo '{"queue_name": "active", "queue_id": "E511B806E6B", "recipients": [{"address": "test@qsite.example"}]}' > "$ST/postqueue"
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "a message Postfix accepted still in its active queue when the wait ends: exit 3, and said" "$rc:$(printf '%s' "$out" | grep -c 'still in its queue')" "3:1"
echo '{"queue_name": "deferred", "queue_id": "AAAA1", "recipients": [{"address": "x@elsewhere.example"}]}' > "$ST/postqueue"
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "a deferred message is not waited for" "$rc" "0"
: > "$ST/postqueue"

echo "=== off: everything back as it was ==="
: > "$ST/calls"
out="$(bash "$Q" off 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "no state file" "$([ -e "$SD/state" ] && echo yes || echo no)" "no"
chk "the owner's cron file is back as it was" "$(cat "$R/etc/cron.d/joinery-qsite")" "* * * * * www-data php my own edit"
chk "the rule is gone, and so is the unit" "$([ -f "$ST/nft_loaded" ] && echo loaded || echo gone):$([ -f "$R/etc/systemd/system/joinery-site-state.service" ] && echo unit || echo nounit)" "gone:nounit"
chk "the web drop-in is gone" "$(ls "$R/etc/apache2/conf-enabled" "$R/etc/apache2/conf-available" | grep -c quiet)" "0"
chk "certbot's timer is on again" "$([ -f "$ST/on.certbot.timer" ] && echo on || echo off)" "on"
chk "Postfix: smtp has no override, submission its own again" "$([ -e "$ST/pf/smtp_inet_smtpd_client_restrictions" ] && echo set || echo unset):$(cat "$ST/pf/submission_inet_smtpd_client_restrictions")" 'unset:$mua_client_restrictions'
chk "the state directory is gone" "$([ -e "$SD" ] && echo yes || echo no)" "no"

echo "=== A timer that was off stays off ==="
rm -f "$ST/on.certbot.timer"
bash "$Q" on >/dev/null 2>&1; bash "$Q" off >/dev/null 2>&1
chk "certbot's timer was never turned on" "$([ -f "$ST/on.certbot.timer" ] && echo on || echo off)" "off"
touch "$ST/on.certbot.timer"

echo "=== forward: a frozen site forwards its visitors to its copy while a DNS switch settles ==="
cat > "$B/a2enmod" <<EOF2
#!/bin/bash
echo "a2enmod \$*" >> "$ST/calls"; exit 0
EOF2
chmod +x "$B/a2enmod"
CONF="$R/etc/apache2/conf-available/joinery-quiet-qsite.conf"
out="$(bash "$Q" forward 198.51.100.9 2>&1)"; rc=$?
chk "a live site does not forward: refused" "$rc:$([ -e "$SD/forward" ] && echo file || echo none)" "2:none"
bash "$Q" on >/dev/null 2>&1
: > "$ST/calls"
out="$(bash "$Q" forward 198.51.100.9 2>&1)"; rc=$?
chk "a frozen site forwards: exit 0, the address recorded" "$rc:$(cat "$SD/forward")" "0:198.51.100.9"
chk "the web user may reach that one address on 80 and 443, before the reject" "$(grep -c 'ip daddr 198.51.100.9 tcp dport { 80, 443 } accept' "$ST/nft_loaded"):$(( $(grep -n -m1 'ip daddr 198.51.100.9' "$ST/nft_loaded" | cut -d: -f1) < $(grep -n -m1 'reject' "$ST/nft_loaded" | cut -d: -f1) ))" "1:1"
chk "every request is proxied to the copy over https" "$(grep -c 'ProxyPass "https://198.51.100.9/"' "$CONF"):$(grep -c 'ProxyPreserveHost On' "$CONF")" "1:2"
chk "its certificate checked against the site's own name" "$(grep -c -e '^SSLProxyVerify require$' -e '^SSLProxyCheckPeerName on$' -e '^SSLProxyCheckPeerExpire on$' "$CONF")" "3"
chk "its whole chain checked, not refused past the first certificate" "$(grep -c '^SSLProxyVerifyDepth 10$' "$CONF")" "1"
chk "the ACME challenge goes over http, so the copy can be issued one" "$(grep -c 'ProxyPass "http://198.51.100.9/.well-known/acme-challenge/"' "$CONF")" "1"
chk "any other http request goes to https first, and a refused request does not hold the copy off" "$(grep -c "If \"%{HTTPS} != 'on' && ! %{REQUEST_URI} =~ m#^/\\\\.well-known/acme-challenge/#\"" "$CONF"):$(grep -c 'Redirect 301 "https://%{HTTP_HOST}%{REQUEST_URI}"' "$CONF"):$(grep -c 'retry=0$' "$CONF")" "1:1:2"
chk "no maintenance page while forwarding" "$(grep -c 'Redirect 503' "$CONF")" "0"
chk "the proxy modules are enabled, and Apache reloaded" "$(grep -c -e 'a2enmod -q proxy$' -e 'a2enmod -q proxy_http$' -e 'a2enmod -q ssl$' "$ST/calls"):$(grep -c 'systemctl reload apache2' "$ST/calls")" "3:1"
out="$(bash "$Q" forward '198.51.100.9;reboot' 2>&1)"; rc=$?
chk "anything but an address is refused, the forward unchanged" "$rc:$(cat "$SD/forward")" "2:198.51.100.9"
bash "$Q" forward 2001:db8::9 >/dev/null 2>&1
chk "an IPv6 copy: ip6 in the rule, brackets in the URL" "$(grep -c 'ip6 daddr 2001:db8::9 tcp dport' "$ST/nft_loaded"):$(grep -c 'ProxyPass "https://\[2001:db8::9\]/"' "$CONF")" "1:1"
bash "$Q" on >/dev/null 2>&1
chk "on again: the forward stops, the maintenance page is back" "$([ -e "$SD/forward" ] && echo file || echo none):$(grep -c 'Redirect 503' "$CONF"):$(grep -c 'daddr' "$ST/nft_loaded")" "none:1:0"
bash "$Q" forward 198.51.100.9 >/dev/null 2>&1
bash "$Q" off >/dev/null 2>&1
chk "off: the forward goes with everything else" "$([ -e "$SD/forward" ] && echo file || echo none):$([ -e "$SD" ] && echo dir || echo nodir)" "none:nodir"
rm -f "$B/a2enmod"

echo "=== quiet copy: set by the installer, never replaced by on, never cleared by a bare off ==="
. "$TOOLS/_site_state.sh"
site_state_init qsite
QKEY="$(printf 'k%.0s' $(seq 32) | base64)"
site_state_write copy 417 "$QKEY"
site_state_assert copy >/dev/null 2>&1
out="$(bash "$Q" on 2>&1)"; rc=$?
chk "on over a copy: quiet already, so it passes and the copy state stays (a way back that reached it before it started)" \
    "$rc:$(cat "$SD/state"):$(grep -c 'quiet already' <<< "$out")" "0:quiet copy:1"
out="$(bash "$Q" off 2>&1)"; rc=$?
chk "off over a copy: refused" "$rc:$(cat "$SD/state")" "2:quiet copy"
chk "the copy remembers its source's node id" "$(cat "$SD/copy_of")" "417"
chk "and its source's agent key (WP4)" "$(cat "$SD/copy_of_key")" "$QKEY"
secret="$(cat "$SD/look_secret")"
chk "a copy has a look secret, 32 hex, 0600" "$([[ "$secret" =~ ^[0-9a-f]{32}$ ]] && echo ok):$(stat -c %a "$SD/look_secret")" "ok:600"
CONF="$R/etc/apache2/conf-available/joinery-quiet-qsite.conf"
chk "the look path sets the cookie and lands on the home page" "$(grep -A8 -F "<LocationMatch \"^/\\.joinery-look/${secret}\$\">" "$CONF" | grep -c -e "Set-Cookie \"joinery_look=${secret};" -e 'Redirect 303 "https://%{HTTP_HOST}/"$' -e 'Redirect 303 /$')" "3"
chk "the key look path sets the cookie and lands on the key page" "$(grep -A8 -F "<LocationMatch \"^/\\.joinery-look/${secret}/key\$\">" "$CONF" | grep -c -e "Set-Cookie \"joinery_look=${secret};" -e 'Redirect 303 "https://%{HTTP_HOST}/copy-key"$' -e 'Redirect 303 /copy-key$')" "3"
# B19: Redirect alone names the scheme Apache was reached on, http in a container
# behind its host's proxy, and a key page served over http has no WebCrypto. A
# Location set beside Redirect goes out as a second one, which a browser refuses.
chk "the look paths set no Location of their own" "$(grep -c 'Header always set Location' "$CONF")" "0"
chk "both look paths, and nothing else, pass without the cookie" "$(grep -c -F "<LocationMatch \"^/(?!\\.joinery-look/${secret}(?:/key)?\$)\">" "$CONF")" "1"
chk "without the cookie, 503" "$(grep -c "HTTP_COOKIE} =~ /(?:^|;\\\\s\*)joinery_look=${secret}(?:;|\\$)/" "$CONF"):$(grep -c 'Redirect 503' "$CONF")" "1:1"

echo "=== a real Apache answers the copy's drop-in ==="
# What a browser gets, read from the wire: a text check cannot see two Location
# headers, and curl stops at the second without saying so under -s (B19).
AP="$T/apache"; mkdir -p "$AP/logs" "$AP/docroot"
AMODS=""
for m in mpm_event authz_core headers alias; do
    AMODS+="LoadModule ${m}_module /usr/lib/apache2/modules/mod_${m}.so"$'\n'
done
if [ ! -x /usr/sbin/apache2 ] || ! command -v python3 >/dev/null || [ ! -f /usr/lib/apache2/modules/mod_headers.so ]; then
    chk "this section needs Apache with mod_headers and mod_alias, and python3" "missing" "present"
else
    APORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])')"
    cat > "$AP/main.conf" <<EOF
ServerRoot "$AP"
ServerName localhost
PidFile $AP/logs/pid
ErrorLog $AP/logs/error.log
Mutex file:$AP/logs
${AMODS}Listen 127.0.0.1:$APORT
Include $CONF
<VirtualHost 127.0.0.1:$APORT>
    ServerName qsite.example
    DocumentRoot $AP/docroot
    <Directory $AP/docroot>
        Require all granted
    </Directory>
</VirtualHost>
EOF
    chk "Apache takes the drop-in" "$(/usr/sbin/apache2 -t -f "$AP/main.conf" >/dev/null 2>&1; echo $?)" "0"
    /usr/sbin/apache2 -k start -f "$AP/main.conf" 2>"$AP/start.err"
    for _ in $(seq 50); do [ -s "$AP/logs/pid" ] && python3 -c "import socket; socket.create_connection(('127.0.0.1',$APORT),1)" 2>/dev/null && break; sleep 0.1; done
    # STATUS|every Location, joined by a space|cookie set or not
    answer() {  # HOST PATH [COOKIE]
        python3 - "$APORT" "$1" "$2" "${3:-}" <<'PY'
import socket, sys
port, host, path, cookie = int(sys.argv[1]), sys.argv[2], sys.argv[3], sys.argv[4]
s = socket.create_connection(("127.0.0.1", port), 5)
req = "GET %s HTTP/1.1\r\nHost: %s\r\nConnection: close\r\n" % (path, host)
if cookie:
    req += "Cookie: %s\r\n" % cookie
s.sendall((req + "\r\n").encode())
raw = b""
while True:
    b = s.recv(65536)
    if not b:
        break
    raw += b
head = raw.split(b"\r\n\r\n", 1)[0].decode("latin-1").split("\r\n")
status = head[0].split(" ")[1]
locs = [h.split(":", 1)[1].strip() for h in head[1:] if h.lower().startswith("location:")]
setc = any(h.lower().startswith("set-cookie: joinery_look=") for h in head[1:])
print("%s|%s|%s" % (status, " ".join(locs), "cookie" if setc else "-"))
PY
    }
    K="/.joinery-look/${secret}/key"
    chk "the key look path, by name: one https Location, the cookie set" "$(answer qsite.example "$K")" "303|https://qsite.example/copy-key|cookie"
    chk "the look path, by name: lands on the home page" "$(answer qsite.example "/.joinery-look/${secret}")" "303|https://qsite.example/|cookie"
    chk "a name with a port keeps its port" "$(answer qsite.example:8443 "$K")" "303|https://qsite.example:8443/copy-key|cookie"
    chk "an IPv4 address" "$(answer 192.0.2.10 "$K")" "303|https://192.0.2.10/copy-key|cookie"
    v6="$(answer '[2001:db8::1]' "$K")"
    chk "an IPv6 address: one Location, its brackets kept" "$(echo "$v6" | grep -c -E '^303\|https?://\[2001:db8::1\](:[0-9]+)?/copy-key\|cookie$')" "1"
    chk "a query string goes along" "$(answer qsite.example "$K?x=1")" "303|https://qsite.example/copy-key?x=1|cookie"
    chk "anything else without the cookie: 503, no Location" "$(answer qsite.example /copy-key)" "503||-"
    chk "a wrong secret: 503" "$(answer qsite.example "/.joinery-look/$(printf '0%.0s' $(seq 32))/key" | cut -d'|' -f1)" "503"
    chk "with the cookie, the site answers (404 here: no page)" "$(answer qsite.example /copy-key "joinery_look=${secret}" | cut -d'|' -f1)" "404"
    /usr/sbin/apache2 -k stop -f "$AP/main.conf" >/dev/null 2>&1
    for _ in $(seq 50); do [ -e "$AP/logs/pid" ] || break; sleep 0.1; done
fi
chk "on --copy-promoted is refused" "$(bash "$Q" on --copy-promoted >/dev/null 2>&1; echo $?)" "2"
# The runs its source vouched for (WP2), and the last export it took (WP4), go
# with the copy's other records: a promoted copy trusts its source's key no more.
printf '%s chain-20260927_120000\n' "$(printf '0%.0s' $(seq 64))" > "$SD/vouched"; chmod 600 "$SD/vouched"
echo "2026-10-01T00:00:00Z" > "$SD/copy_import_issued"; chmod 600 "$SD/copy_import_issued"
printf 'profile manager\nchain-20260920_120000 %s 512 1758369600\n' "$(printf '0%.0s' $(seq 64))" > "$SD/history"; chmod 600 "$SD/history"
out="$(bash "$Q" off --copy-promoted 2>&1)"; rc=$?
chk "off --copy-promoted clears a copy, its vouched runs, backup history, source key and last import included" "$rc:$([ -e "$SD" ] && echo left || echo gone)" "0:gone"

echo "=== A state file that says anything else is quiet, the strict way ==="
site_state_write switchover; echo "garbage" > "$SD/state"
chk "read as switchover" "$(site_state_read 2>/dev/null)" "switchover"
echo 417 > "$SD/copy_of"
chk "unless a copy_of record is beside it: then a copy" "$(site_state_read 2>/dev/null)" "copy"
chk "and a bare off does not clear it" "$(bash "$Q" off >/dev/null 2>&1; echo $?):$([ -e "$SD/state" ] && echo kept)" "2:kept"
rm -rf "${SD:?}"

echo "=== The converger's gate: a quiet site runs no installer ==="
# The real runner against a temp tree (as host_converger_gate.sh does): the
# installers skip without root, but each prints its 'running' line first.
C="$T/html/csite"
mkdir -p "$C/public_html/plugins" "$C/config" "$C/cache" "$C/maintenance_scripts/install_tools"
cp "$TOOLS"/*.sh "$C/maintenance_scripts/install_tools/"
echo 0.8.384 > "$C/public_html/VERSION"; echo '<?php' > "$C/config/Globalvars_site.php"
RUNNER="$C/maintenance_scripts/install_tools/_plugin_installers_start.sh"
CD="$R/etc/joinery/sites/csite"
out="$(bash "$RUNNER" --when-changed --site-root="$C" 2>&1)"
chk "a live site converges" "$([ "$(printf '%s' "$out" | grep -c 'core installers: running')" -gt 0 ] && echo yes)" "yes"
. "$TOOLS/_site_state.sh"; site_state_init csite; site_state_write copy 417
echo "* * * * * www-data php csite" > "$R/etc/cron.d/joinery-csite"
: > "$ST/calls"
out="$(bash "$RUNNER" --when-changed --site-root="$C" 2>&1)"; rc=$?
chk "quiet: exit 0, and not one installer runs" "$rc:$(printf '%s' "$out" | grep -c 'core installers: running')" "0:0"
chk "the measures are asserted" "$([ -f "$CD/quiet.nft" ] && echo nft):$([ -L "$R/etc/apache2/conf-enabled/joinery-quiet-csite.conf" ] && echo web):$([ -e "$R/etc/cron.d/joinery-csite" ] && echo cron || echo held)" "nft:web:held"
chk "the run is recorded as quiet" "$(cut -d' ' -f2 "$C/cache/host_converger.last")" "quiet-copy"
chk "no converge stamp is kept while quiet" "$([ -e "$C/cache/host_converger.stamp" ] && echo kept || echo none)" "none"
out="$(bash "$RUNNER" --only=render_vhost.sh --site-root="$C" 2>&1)"
chk "--only runs nothing either" "$(printf '%s' "$out" | grep -c 'core installers: running')" "0"
mv "$C/maintenance_scripts/install_tools/_site_state.sh" "$T/helper.aside"
out="$(bash "$RUNNER" --when-changed --site-root="$C" 2>&1)"; rc=$?
chk "a quiet site whose tree lacks the helper runs nothing (fails closed)" "$rc:$(printf '%s' "$out" | grep -c 'core installers: running'):$(cut -d' ' -f2 "$C/cache/host_converger.last")" "0:0:quiet-no-helper"
mv "$T/helper.aside" "$C/maintenance_scripts/install_tools/_site_state.sh"
rm -f "$CD/state"
out="$(bash "$RUNNER" --when-changed --site-root="$C" 2>&1)"
chk "state gone: its leftovers are cleared" "$(printf '%s' "$out" | grep -c 'no longer quiet'):$([ -e "$CD" ] && echo left || echo gone):$(cat "$R/etc/cron.d/joinery-csite")" "1:gone:* * * * * www-data php csite"
chk "and the site converges at once, with no release change" "$([ "$(printf '%s' "$out" | grep -c 'core installers: running')" -gt 0 ] && echo yes)" "yes"

echo "=== In a site container: the host keeps the network, the container the rest ==="
# specs/site_container_engine.md D9: either engine's marker makes this the
# container's half. No nftables, boot unit or certbot timer here; the web
# drop-in reloads with apache2ctl graceful, and only while Apache runs.
R2="$T/fs2"; mkdir -p "$R2/etc/cron.d" "$R2/etc/apache2/conf-available" "$R2/etc/apache2/conf-enabled" "$R2/etc/systemd/system" "$R2/run"
touch "$R2/run/.containerenv"
echo "* * * * * www-data php /var/www/html/qsite/public_html/utils/process_scheduled_tasks.php" > "$R2/etc/cron.d/joinery-qsite"
cat > "$B/pgrep" <<EOF
#!/bin/bash
if [ "\$1" = "-x" ] && [ "\$2" = "apache2" ]; then [ -f "$ST/apache_runs" ]; exit; fi
[ -s "$ST/pgrep" ] && cat "$ST/pgrep"
EOF
chmod +x "$B/pgrep"
: > "$ST/calls"; rm -f "$ST/nft_loaded"; touch "$ST/on.certbot.timer"
SD2="$R2/etc/joinery/sites/qsite"
out="$(JOINERY_SITE_STATE_ROOT="$R2" bash "$Q" on 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "no nftables, no boot unit" "$(grep -c '^nft' "$ST/calls"):$([ -e "$SD2/quiet.nft" ] && echo file || echo nofile):$([ -e "$R2/etc/systemd/system/joinery-site-state.service" ] && echo unit || echo nounit)" "0:nofile:nounit"
chk "certbot's timer is not the container's" "$(grep -c 'certbot.timer' "$ST/calls"):$([ -f "$ST/on.certbot.timer" ] && echo on)" "0:on"
chk "the web drop-in is in place" "$(readlink "$R2/etc/apache2/conf-enabled/joinery-quiet-qsite.conf")" "../conf-available/joinery-quiet-qsite.conf"
chk "Apache not running yet: checked, not reloaded (its start reads the drop-in)" "$(grep -c 'apache2ctl configtest' "$ST/calls"):$(grep -c -e 'apache2ctl -k graceful' -e 'systemctl reload' "$ST/calls")" "1:0"
chk "the scheduled-task cron file is held" "$([ -e "$R2/etc/cron.d/joinery-qsite" ] && echo live || echo gone):$([ -f "$SD2/held/cron.d/joinery-qsite" ] && echo held)" "gone:held"
JOINERY_SITE_STATE_ROOT="$R2" bash "$Q" off >/dev/null 2>&1
touch "$ST/apache_runs"; : > "$ST/calls"
out="$(JOINERY_SITE_STATE_ROOT="$R2" bash "$Q" on 2>&1)"; rc=$?
chk "Apache running: reloaded gracefully, never through systemd" "$rc:$(grep -c 'apache2ctl -k graceful' "$ST/calls"):$(grep -c 'systemctl reload' "$ST/calls")" "0:1:0"
: > "$ST/calls"
out="$(JOINERY_SITE_STATE_ROOT="$R2" bash "$Q" off 2>&1)"; rc=$?
chk "off: the cron file back, the drop-in gone, Apache reloaded the same way" "$rc:$([ -e "$R2/etc/cron.d/joinery-qsite" ] && echo back):$(ls "$R2/etc/apache2/conf-enabled" | grep -c quiet):$(grep -c 'apache2ctl -k graceful' "$ST/calls")" "0:back:0:1"
rm -f "$ST/apache_runs"; rm -f "$R2/run/.containerenv"; touch "$R2/.dockerenv"
JOINERY_SITE_STATE_ROOT="$R2" bash -c '. "$1"; site_state_init qsite; echo "$SS_CONTAINER"' _ "$TOOLS/_site_state.sh" > "$T/c.out"
chk "Docker's marker makes it a container too" "$(cat "$T/c.out")" "1"

echo "=== The container's start: a quiet site starts quiet ==="
# _site_start.sh asserts the measures before cron and Apache start, and a cron
# file it could not hold stops the start.
START="$TOOLS/_site_start.sh"
chk "the assert comes before cron starts" "$(( $(grep -n 'site_state_assert' "$START" | head -1 | cut -d: -f1) < $(grep -n 'service cron start' "$START" | cut -d: -f1) ))" "1"
chk "only when the site's state file exists" "$(grep -c 'if \[ -e "/etc/joinery/sites/${SITENAME}/state" \]; then' "$START")" "1"
chk "a cron file still there is fatal" "$(grep -A1 'if \[ -e "/etc/cron.d/joinery-${SITENAME}" \]; then' "$START" | grep -c 'FATAL: ${SITENAME} is quiet')" "1"

echo "=== Not root and no fixture: nothing to do, refused ==="
out="$(JOINERY_SITE_STATE_ROOT= bash "$Q" on 2>&1)"; rc=$?
chk "refused, exit 2" "$rc" "2"

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
