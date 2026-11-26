#!/bin/bash
# @joinery-test
# name: quiet_check
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/quiet_check.sh]
#
# quiet_check.sh is what the agent's quiet_check observe word runs as root
# (specs/automatic_reboot_when_quiet.md WP1). This gate pins its contract:
# quiet only when no signal says busy; each signal busy and quiet; browser
# page views counted and never busy; bots, loopback and lines outside the
# window not counted; a signal that applies and cannot be read unknown and
# busy; one that does not apply not reported; every key always present; an
# argument that is not a plain absolute path treated as no site.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/quiet_check.sh"
T=$(mktemp -d)
holder=""
trap '[ -n "$holder" ] && kill "$holder" 2>/dev/null; rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
jv() {
    php -r '
        $o = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($o)) { echo "NOTJSON"; exit; }
        if ($argv[2] === "keys") { echo implode(",", array_keys($o)); exit; }
        if (!array_key_exists($argv[2], $o)) { echo "ABSENT"; exit; }
        $v = $o[$argv[2]];
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : implode(",", $v));
    ' "$1" "$2"
}

R="$T/root"
SITE=/var/www/html/demo
mkdir -p "$R$SITE/logs" "$R/var/lib/php/sessions" "$R/run/joinery" "$T/bin"

# Stand-ins for what reads the machine: who, pgrep, ps, runuser.
cat > "$T/bin/who" <<STUB
#!/bin/bash
cat "$T/who" 2>/dev/null
STUB
cat > "$T/bin/pgrep" <<STUB
#!/bin/bash
if [[ "\$1" == -x ]]; then [[ -f "$T/pkg" ]]; exit; fi
if [[ "\$1" == -c ]]; then n=\$(cat "$T/work" 2>/dev/null || echo 0); echo "\$n"; [[ "\$n" -gt 0 ]]; exit; fi
[[ -f "$T/taskpid" ]] && echo 4242
STUB
cat > "$T/bin/ps" <<STUB
#!/bin/bash
cat "$T/taskage" 2>/dev/null
STUB
cat > "$T/bin/runuser" <<STUB
#!/bin/bash
[[ -f "$T/db" ]] || exit 2
cat "$T/db"
STUB
chmod +x "$T/bin/"*
q() { QUIET_CHECK_ROOT="$R" PATH="$T/bin:$PATH" bash "$SCRIPT" "$@" > "$T/out" 2> "$T/err"; }

echo "== A machine with no site and nothing running =="
q
chk "it is quiet" "$(jv "$T/out" quiet)" "true"
chk "every key is present" "$(jv "$T/out" keys)" "quiet,busy,unknown,window_minutes,logins,signed_in,visits,writes,packages,installers,site_work,db_queries,mail_sent"
chk "the site's signals are not reported" "$(jv "$T/out" signed_in) $(jv "$T/out" visits) $(jv "$T/out" writes)" "not_reported not_reported not_reported"
chk "no PostgreSQL, no Postfix: not reported" "$(jv "$T/out" db_queries) $(jv "$T/out" mail_sent)" "not_reported not_reported"
chk "the window is fixed at 15 minutes" "$(jv "$T/out" window_minutes)" "15"
for bad in "../etc" "relative/path" "/var/www/html/demo/../../etc" '/tmp/$(id)'; do
    q "$bad"
    chk "argument '$bad' is no site" "$(jv "$T/out" signed_in)" "not_reported"
done

echo "== A person logged in =="
echo "user1 pts/0 2026-11-26 14:00 (10.0.0.1)" > "$T/who"
q
chk "a login is busy" "$(jv "$T/out" quiet) $(jv "$T/out" busy) $(jv "$T/out" logins)" "false logins 1"
rm -f "$T/who"

echo "== The site's access log =="
now="$(date +'%d/%b/%Y:%H:%M:%S %z')"; old="$(date -d '-40 minutes' +'%d/%b/%Y:%H:%M:%S %z')"
UA='Mozilla/5.0 (X11; Linux x86_64) Firefox/140.0'
{
    printf '203.0.113.5 - - [%s] "GET / HTTP/1.1" 200 512 900 "-" "%s"\n' "$now" "$UA"
    printf '203.0.113.5 - - [%s] "GET /events HTTP/1.1" 200 512 900 "-" "%s"\n' "$now" "$UA"
    printf '203.0.113.6 - - [%s] "POST /login HTTP/1.1" 302 0 900 "-" "Mozilla/5.0 (compatible; Googlebot/2.1)"\n' "$now"
    printf '127.0.0.1 - - [%s] "POST /api HTTP/1.1" 200 0 900 "-" "%s"\n' "$now" "$UA"
    printf '203.0.113.7 - - [%s] "GET /health HTTP/1.1" 200 0 900 "-" "-"\n' "$now"
    printf '203.0.113.8 - - [%s] "POST /checkout HTTP/1.1" 200 0 900 "-" "%s"\n' "$old" "$UA"
    printf '203.0.113.9 - - [%s] "POST /api/v1/agent/claim HTTP/1.1" 200 41 900 "-" "%s"\n' "$now" "$UA"
} > "$R$SITE/logs/access.log"
q "$SITE"
chk "browser page views counted, never busy" "$(jv "$T/out" visits) $(jv "$T/out" quiet)" "2 true"
chk "a bot's POST, loopback, the monitor, an agent's call and a POST 40 minutes ago are not writes" "$(jv "$T/out" writes)" "0"
printf '203.0.113.5 - - [%s] "POST /checkout HTTP/1.1" 200 0 900 "-" "%s"\n' "$now" "$UA" >> "$R$SITE/logs/access.log"
q "$SITE"
chk "a browser's POST now is busy" "$(jv "$T/out" writes) $(jv "$T/out" quiet) $(jv "$T/out" busy)" "1 false writes"
mv "$R$SITE/logs/access.log" "$T/access.log"
q "$SITE"
chk "a site whose access log cannot be read: unknown, busy" "$(jv "$T/out" writes) $(jv "$T/out" unknown) $(jv "$T/out" quiet)" "unknown writes false"
printf '' > "$R$SITE/logs/access.log"

echo "== Signed-in sessions =="
printf 'csrf|s:4:"abcd";' > "$R/var/lib/php/sessions/sess_anon"
printf 'loggedin|b:1;usr_user_id|i:7;' > "$R/var/lib/php/sessions/sess_old"
touch -d '-40 minutes' "$R/var/lib/php/sessions/sess_old"
q "$SITE"
chk "an anonymous session and a signed-in one idle 40 minutes: quiet" "$(jv "$T/out" signed_in) $(jv "$T/out" quiet)" "0 true"
printf 'a:2:{s:8:"loggedin";b:1;s:11:"usr_user_id";i:7;}' > "$R/var/lib/php/sessions/sess_now"
printf 'loggedin|b:0;' > "$R/var/lib/php/sessions/sess_out"
q "$SITE"
chk "a signed-in session used now (php_serialize too) is busy; a signed-out one is not" "$(jv "$T/out" signed_in) $(jv "$T/out" busy)" "1 signed_in"
rm -f "$R/var/lib/php/sessions/sess_now"
chmod 000 "$R/var/lib/php/sessions"
q "$SITE"
chk "a session folder that cannot be read: unknown" "$(jv "$T/out" signed_in) $(jv "$T/out" quiet)" "unknown false"
chmod 755 "$R/var/lib/php/sessions"

echo "== Work under way =="
touch "$T/pkg"; q
chk "dpkg or apt running is busy" "$(jv "$T/out" packages) $(jv "$T/out" busy)" "1 packages"
rm -f "$T/pkg"
echo 2 > "$T/work"; q
chk "a backup or task run is busy" "$(jv "$T/out" site_work) $(jv "$T/out" busy)" "2 site_work"
rm -f "$T/work"
touch "$T/taskpid"; echo 30 > "$T/taskage"; q
chk "a scheduled-task run 30 seconds old (the asker's own) is not busy" "$(jv "$T/out" site_work) $(jv "$T/out" quiet)" "0 true"
echo 300 > "$T/taskage"; q
chk "one still running after five minutes is busy" "$(jv "$T/out" site_work) $(jv "$T/out" busy)" "1 site_work"
rm -f "$T/taskpid" "$T/taskage"
touch "$R/run/joinery/host-installers.x.lock"
( flock 9; exec sleep 30 ) 9> "$R/run/joinery/host-installers.x.lock" &
holder=$!
sleep 0.3
q
chk "the installers' lock held is busy" "$(jv "$T/out" installers) $(jv "$T/out" busy)" "1 installers"
kill "$holder" 2>/dev/null; wait "$holder" 2>/dev/null
q
chk "the lock free again: quiet" "$(jv "$T/out" installers) $(jv "$T/out" quiet)" "0 true"

echo "== Long database queries =="
mkdir -p "$R/etc/postgresql"
echo 0 > "$T/db"; q
chk "no query over five seconds: quiet" "$(jv "$T/out" db_queries) $(jv "$T/out" quiet)" "0 true"
echo 2 > "$T/db"; q
chk "a long query is busy" "$(jv "$T/out" db_queries) $(jv "$T/out" busy)" "2 db_queries"
rm -f "$T/db"; q
chk "PostgreSQL that does not answer: unknown" "$(jv "$T/out" db_queries) $(jv "$T/out" quiet)" "unknown false"
rm -rf "$R/etc/postgresql"

echo "== Mail a person sent =="
mkdir -p "$R/etc/postfix" "$R/var/log"
q
chk "Postfix with no log to read: unknown" "$(jv "$T/out" mail_sent) $(jv "$T/out" quiet)" "unknown false"
iso="$(date +'%Y-%m-%dT%H:%M:%S.000000+00:00')"; sys="$(date +'%b %e %H:%M:%S')"
{
    printf '%s mx postfix/smtpd[1]: 4AB: client=mail.example[203.0.113.9]\n' "$iso"
    printf '%s mx postfix/local[2]: 4AB: to=<a@b>, status=sent (delivered)\n' "$iso"
} > "$R/var/log/mail.log"
q
chk "mail arriving is not a person at work" "$(jv "$T/out" mail_sent) $(jv "$T/out" quiet)" "0 true"
printf '%s mx postfix/submission/smtpd[3]: 5CD: client=x[203.0.113.10], sasl_method=PLAIN, sasl_username=a@b\n' "$sys" >> "$R/var/log/mail.log"
q
chk "an authenticated submission now is busy (syslog dates too)" "$(jv "$T/out" mail_sent) $(jv "$T/out" busy)" "1 mail_sent"

echo
echo "PASSED: $passed   FAILED: $failed"
[ "$failed" -eq 0 ]
