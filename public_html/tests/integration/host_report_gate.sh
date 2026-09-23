#!/bin/bash
# @joinery-test
# name: host_report
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/sysadmin_tools/host_report.sh]
#
# host_report.sh is what the agent's host_report observe word runs as root
# (specs/agent_tier1_recipes.md). This gate pins its contract, driven as an
# unprivileged user: the real run on this box prints one valid JSON object
# with every key present and unknowns where root was needed; a run against
# stubbed systemctl / fail2ban-client / sshd / journalctl (on PATH, the way a
# root run would find the real ones) proves the parsing, the caps and the
# sanitising; the SSH auth-failure figure is a COUNT and no username or
# address from the journal ever reaches the object; a stub that hangs costs
# its key and not the report; an argument and stdin change nothing; the
# script never runs a write; and the exit code is 0 whenever the object
# printed.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/host_report.sh"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# A PHP one-liner reads the object so the gate depends on nothing but php,
# which every node has. jq() prints the value at a dotted path, or TYPE:name.
jv() {
    php -r '
        $o = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($o)) { echo "NOTJSON"; exit; }
        $path = $argv[2]; $v = $o;
        if ($path !== "") { foreach (explode(".", $path) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "ABSENT"; exit; } $v = $v[$k]; } }
        if ($argv[3] === "type") { echo is_array($v) ? (array_is_list($v) ? "list" : "object") : gettype($v); exit; }
        if ($argv[3] === "count") { echo is_array($v) ? count($v) : "NOTLIST"; exit; }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

KEYS="failed_units,expected_units,fail2ban_jails,ssh_auth_failures_24h,kernel_events_24h,sshd,disk,memory,swap,reboot_required,unattended_upgrades_last_run,os,answers,served_certificates,containers,generated_at"

echo "=== The real run on this box, unprivileged ==="
if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: this gate runs unprivileged (the unknowns are the point)"; exit 0
fi
bash "$SCRIPT" > "$T/real.json" 2> "$T/real.err"; rc=$?
chk "exit 0" "$rc" "0"
chk "one line" "$(wc -l < "$T/real.json")" "1"
chk "nothing on stderr" "$(wc -c < "$T/real.err")" "0"
chk "a JSON object" "$(jv "$T/real.json" "" type)" "object"
chk "every key present, in order" "$(jv "$T/real.json" "" keys)" "$KEYS"
chk "expected units name the five" "$(jv "$T/real.json" expected_units keys)" "fail2ban,apache2,php-fpm,cron,postgresql"
for u in fail2ban apache2 php-fpm cron postgresql; do
    s="$(jv "$T/real.json" expected_units.$u)"
    case "$s" in active|inactive|failed|absent|unknown) ok=1 ;; *) ok=0 ;; esac
    chk "expected unit $u is a known state ($s)" "$ok" "1"
done
chk "failed units is a list or unknown" "$( t=$(jv "$T/real.json" failed_units type); [ "$t" = list ] || [ "$(jv "$T/real.json" failed_units)" = unknown ]; echo $? )" "0"
chk "jails without root are unknown" "$(jv "$T/real.json" fail2ban_jails)" "unknown"
chk "ssh auth failures without journal access are unknown" "$(jv "$T/real.json" ssh_auth_failures_24h)" "unknown"
chk "sshd posture without root is unknown" "$(jv "$T/real.json" sshd.password_authentication)/$(jv "$T/real.json" sshd.permit_root_login)" "unknown/unknown"
chk "disk path is this site's web root" "$(jv "$T/real.json" disk.path)" "$ROOT/public_html"
chk "disk figures are integers" "$(jv "$T/real.json" disk.used_bytes type)/$(jv "$T/real.json" disk.total_bytes type)" "integer/integer"
chk "disk reports what a writer can use, not total minus used" "$(jv "$T/real.json" disk.avail_bytes type)" "integer"
chk "avail is below total minus used (the root reserve is not free space)" "$( a=$(jv "$T/real.json" disk.avail_bytes); u=$(jv "$T/real.json" disk.used_bytes); t=$(jv "$T/real.json" disk.total_bytes); [ "$a" -le $((t-u)) ]; echo $? )" "0"
chk "inode use is a percentage or unknown" "$( v=$(jv "$T/real.json" disk.inodes_used_pct); [ "$v" = unknown ] || { [ "$v" -ge 0 ] && [ "$v" -le 100 ]; }; echo $? )" "0"
chk "kernel events are three counts or unknown" "$( t=$(jv "$T/real.json" kernel_events_24h type); [ "$t" = object ] && [ "$(jv "$T/real.json" kernel_events_24h keys)" = "oom,enospc,io_error" ] || [ "$(jv "$T/real.json" kernel_events_24h)" = unknown ]; echo $? )" "0"
chk "memory figures are integers" "$(jv "$T/real.json" memory.used_bytes type)/$(jv "$T/real.json" memory.total_bytes type)" "integer/integer"
chk "swap figures are integers" "$(jv "$T/real.json" swap.used_bytes type)/$(jv "$T/real.json" swap.total_bytes type)" "integer/integer"
chk "reboot_required is a boolean" "$(jv "$T/real.json" reboot_required type)" "boolean"
chk "os names its four parts" "$(jv "$T/real.json" os keys)" "id,version,codename,release_upgrade"
chk "os version is dotted digits or unknown" "$( v=$(jv "$T/real.json" os.version); [ "$v" = unknown ] || [[ "$v" =~ ^[0-9]+(\.[0-9]+)*$ ]]; echo $? )" "0"
chk "the offered upgrade is a version, none or unknown" "$( v=$(jv "$T/real.json" os.release_upgrade.offered); [ "$v" = none ] || [ "$v" = unknown ] || [[ "$v" =~ ^[0-9]+(\.[0-9]+)*$ ]]; echo $? )" "0"
chk "the upgrade check time is a time or unknown" "$( t=$(jv "$T/real.json" os.release_upgrade.checked_at type); [ "$t" = integer ] || [ "$(jv "$T/real.json" os.release_upgrade.checked_at)" = unknown ]; echo $? )" "0"
chk "answers names the three services" "$(jv "$T/real.json" answers keys)" "apache2,php-fpm,postgresql"
for u in apache2 php-fpm postgresql; do
    s="$(jv "$T/real.json" answers.$u)"
    case "$s" in yes|no|unknown) ok=1 ;; *) ok=0 ;; esac
    chk "answers.$u is yes, no or unknown ($s)" "$ok" "1"
done
chk "served certificates is a list or unknown" "$( t=$(jv "$T/real.json" served_certificates type); [ "$t" = list ] || [ "$(jv "$T/real.json" served_certificates)" = unknown ]; echo $? )" "0"
chk "containers is a list, none or unknown" "$( t=$(jv "$T/real.json" containers type); v=$(jv "$T/real.json" containers); [ "$t" = list ] || [ "$v" = none ] || [ "$v" = unknown ]; echo $? )" "0"
chk "generated_at is now" "$( g=$(jv "$T/real.json" generated_at); n=$(date -u +%s); [ "$g" -le "$n" ] && [ "$g" -ge $((n-60)) ]; echo $? )" "0"

echo "=== The release-upgrade cache, read as Ubuntu writes it ==="
# The parser alone, lifted out of the script, fed what check-new-release -q
# prints. The cache path is fixed in the script, so the cases are driven here.
eval "$(sed -n '/^release_offered_from() {/,/^}/p' "$SCRIPT")"
chk "an empty cache offers none" "$(printf '' | release_offered_from)" "none"
chk "a blank-line cache offers none" "$(printf '\n\n' | release_offered_from)" "none"
chk "a new LTS names its version" "$(printf "New release '26.04.1 LTS' available.\nRun 'do-release-upgrade' to upgrade to it.\n" | release_offered_from)" "26.04.1"
chk "text it does not recognise is unknown, never quoted" "$(printf 'Neue Version verfügbar; $(reboot)\n' | release_offered_from)" "unknown"
chk "a hostile version keeps only its leading digits" "$(printf "New release '26.04;rm -rf /' available.\n" | release_offered_from)" "26.04"

echo "=== An argument and stdin change nothing ==="
echo "some stdin the script must ignore" | bash "$SCRIPT" /etc/passwd --lines=5000 > "$T/arg.json" 2>/dev/null
chk "with an argument and stdin: still exit 0 and the same keys" "$(jv "$T/arg.json" "" keys)" "$KEYS"
chk "the argument did not become the disk path" "$(jv "$T/arg.json" disk.path)" "$ROOT/public_html"
chk "no environment hook: the script reads none" "$(grep -c 'JOINERY_' "$SCRIPT")" "0"

echo "=== The root path, against stubbed commands ==="
# Stubs on PATH ahead of the real ones, printing what the real commands print
# as root. Unit and jail names carry what a hostile host could plant; the
# journal carries a username and an address that must never reach the object.
mkdir -p "$T/bin"
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
case "$*" in
    *"list-units --state=failed"*)
        for i in $(seq 1 25); do echo "broken$i.service loaded failed failed Broken thing $i"; done
        echo 'evil"name;$(reboot)<b>.service loaded failed failed X'
        ;;
    *"list-unit-files php*-fpm.service"*) echo "php8.3-fpm.service enabled enabled" ;;
    *"-p Version"*) echo 255 ;;
    *"-p LoadState"*)
        case "$*" in *postgresql*) echo not-found ;; *) echo loaded ;; esac ;;
    *"-p ActiveState"*)
        case "$*" in *fail2ban*) echo failed ;; *cron*) echo inactive ;; *) echo active ;; esac ;;
    *) exit 1 ;;
esac
STUB
cat > "$T/bin/fail2ban-client" <<'STUB'
#!/bin/bash
if [ "$1" = status ] && [ -z "${2:-}" ]; then
    echo "Status"
    echo "|- Number of jail:	23"
    printf '`- Jail list:	sshd, apache-auth, weird"jail;name'
    for i in $(seq 1 21); do printf ', extra%s' "$i"; done
    echo
    exit 0
fi
case "${2:-}" in
    sshd) printf 'Status for the jail: sshd\n|- Filter\n|  |- Currently failed:\t2\n`- Actions\n   |- Currently banned:\t7\n   `- Banned IP list:\t203.0.113.9 198.51.100.4\n' ;;
    apache-auth) printf 'Status for the jail: apache-auth\n`- Actions\n   |- Currently banned:\t0\n' ;;
    *) printf 'Status for the jail: %s\n`- Actions\n   |- Currently banned:\t1\n' "$2" ;;
esac
STUB
cat > "$T/bin/sshd" <<'STUB'
#!/bin/bash
[ "$1" = "-T" ] || exit 1
echo "port 22"
echo "port 2222"
echo "passwordauthentication no"
echo "permitrootlogin prohibit-password"
echo "pubkeyauthentication yes"
echo "kbdinteractiveauthentication no"
echo "maxauthtries 6"
echo "allowusers admin deploy"
echo "allowgroups sshusers"
echo "authorizedkeysfile .ssh/authorized_keys"
echo "hostkey /etc/ssh/ssh_host_ed25519_key"
STUB
cat > "$T/bin/journalctl" <<'STUB'
#!/bin/bash
# The SSH read is unit-filtered; the event reads pass a pattern to -g. Answering
# each the way the real journalctl would is what makes the counts meaningful.
case "$*" in
    *"-u ssh"*)
        echo "Failed password for invalid user eve from 203.0.113.9 port 4444 ssh2"
        echo "Invalid user eve from 203.0.113.9 port 4444"
        echo "Accepted publickey for ops from 198.51.100.7 port 5555 ssh2"
        echo "pam_unix(sshd:auth): authentication failure; logname= uid=0 euid=0 tty=ssh ruser= rhost=203.0.113.9  user=mallory"
        echo "Failed password for mallory from 203.0.113.9 port 4445 ssh2"
        ;;
    *"No space left on device"*)
        # The line that actually proved a full disk on 2026-09-22: userspace,
        # from mandb, carrying a path and a pid. A kernel-ring read never saw it.
        echo "mandb[3420559]: /usr/bin/mandb: can not write to /var/cache/man/3420559: No space left on device"
        echo "mandb[3420559]: /usr/bin/mandb: can not create index cache /var/cache/man/3420559: No space left on device"
        ;;
    *"Out of memory"*)
        echo "Out of memory: Killed process 1234 (postgres) total-vm:900000kB"
        ;;
    *"I/O error"*)
        echo "-- No entries --"
        ;;
esac
STUB
cat > "$T/bin/curl" <<'STUB'
#!/bin/bash
# The site answers through PHP on https: serve.php's header is present.
case "$*" in
    *https://*) printf 'HTTP/1.1 200 OK\r\nX-Joinery-Version: 0.8.410\r\n\r\n' ;;
    *127.0.0.1:8081*) printf 'HTTP/1.1 200 OK\r\nX-Joinery-Version: 0.8.410\r\n\r\n' ;;
    *127.0.0.1:8082*) printf 'HTTP/1.1 503 Service Unavailable\r\n\r\n' ;;
    *) exit 7 ;;
esac
STUB
cat > "$T/bin/openssl" <<'STUB'
#!/bin/bash
# s_client hands the name it was asked for down the pipe; x509 answers an
# expiry for it: one name nearly expired, one far off, one whose handshake
# fails and prints nothing.
case "$1" in
    s_client)
        for a in "$@"; do [ "$prev" = "-servername" ] && name="$a"; prev="$a"; done
        case "$name" in *fail*) exit 1 ;; esac
        echo "CERT-FOR $name" ;;
    x509)
        read -r _ name
        case "$name" in
            www.*) echo "notAfter=$(date -u -d '+5 days +1 hour' '+%b %d %H:%M:%S %Y GMT')" ;;
            "") exit 1 ;;
            *) echo "notAfter=$(date -u -d '+80 days +1 hour' '+%b %d %H:%M:%S %Y GMT')" ;;
        esac ;;
esac
STUB
cat > "$T/bin/pg_isready" <<'STUB'
#!/bin/bash
exit 2
STUB
cat > "$T/bin/docker" <<'STUB'
#!/bin/bash
# Four containers: two of ours (name = SITENAME), one planted with a
# SITENAME that is not its name, one with none at all.
case "$1 $2" in
    "ps -a") printf 'siteone\nsitetwo\nimpostor\npostgres\nEvil;Name\n' ;;
    "inspect -f")
        case "$3" in
            *Config.Env*) case "$4" in siteone) echo SITENAME=siteone ;; sitetwo) echo SITENAME=sitetwo ;; impostor) echo SITENAME=siteone ;; *) echo PATH=/bin ;; esac ;;
            *State.Status*) case "$4" in siteone) echo running ;; *) echo running ;; esac ;;
            *State.Health*) echo none ;;
        esac ;;
    "port siteone") echo "0.0.0.0:8081" ;;
    "port sitetwo") echo "0.0.0.0:8082" ;;
esac
STUB
chmod 755 "$T/bin"/*
PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/root.json" 2> "$T/root.err"; rc=$?
chk "stubbed run: exit 0" "$rc" "0"
chk "stubbed run: a JSON object" "$(jv "$T/root.json" "" type)" "object"
chk "failed units are capped at 20" "$(jv "$T/root.json" failed_units count)" "20"
chk "the first failed unit is named" "$(jv "$T/root.json" failed_units.0)" "broken1.service"
chk "expected units read their states" "$(jv "$T/root.json" expected_units.fail2ban)/$(jv "$T/root.json" expected_units.apache2)/$(jv "$T/root.json" expected_units.php-fpm)/$(jv "$T/root.json" expected_units.cron)/$(jv "$T/root.json" expected_units.postgresql)" "failed/active/active/inactive/absent"
chk "jails are capped at 20" "$(jv "$T/root.json" fail2ban_jails count)" "20"
chk "the sshd jail carries its banned count" "$(jv "$T/root.json" fail2ban_jails.0.name)=$(jv "$T/root.json" fail2ban_jails.0.banned)" "sshd=7"
chk "a jail with nothing banned says 0" "$(jv "$T/root.json" fail2ban_jails.1.name)=$(jv "$T/root.json" fail2ban_jails.1.banned)" "apache-auth=0"
chk "a hostile jail name is reduced to safe characters" "$(jv "$T/root.json" fail2ban_jails.2.name)" "weirdjailname"
chk "ssh auth failures are a count of the matching lines" "$(jv "$T/root.json" ssh_auth_failures_24h)" "4"
chk "sshd posture as sshd -T prints it" "$(jv "$T/root.json" sshd.password_authentication)/$(jv "$T/root.json" sshd.permit_root_login)" "no/prohibit-password"
chk "answers: Apache and PHP answer through the site's own name" "$(jv "$T/root.json" answers.apache2)/$(jv "$T/root.json" answers.php-fpm)" "yes/yes"
chk "answers: pg_isready saying no response is no" "$(jv "$T/root.json" answers.postgresql)" "no"
chk "served certificates mark the vhost's ServerName as primary" "$( for i in 0 1 2; do [ "$(jv "$T/root.json" served_certificates.$i.primary)" = true ] && echo x; done | wc -l | tr -d ' ' )" "1"
eval "$(sed -n '/^emit_answers() {/,/^}/p' "$SCRIPT")"
for case in "502:no" "503:no" "301:unknown" "403:unknown" "200:unknown"; do
    st="${case%%:*}"; want="${case##*:}"
    site_server_name() { echo site.example; }
    site_vhost_address() { echo 127.0.0.1; }
    loopback_headers() { printf 'HTTP/1.1 %s X\r\n\r\n' "$st"; }
    got="$(emit_answers | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["php-fpm"];')"
    chk "a headerless $st says php-fpm is $want" "$got" "$want"
done
sc_ok=0; sc_n="$(jv "$T/root.json" served_certificates count)"
for i in $(seq 0 $(( ${sc_n:-0} - 1 ))); do
    d="$(jv "$T/root.json" served_certificates.$i.domain)"; l="$(jv "$T/root.json" served_certificates.$i.days_left)"
    case "$d" in *fail*) sc_ok=1 ;; www.*) [ "$l" = 5 ] || sc_ok=1 ;; *) [ "$l" = 80 ] || sc_ok=1 ;; esac
done
chk "served certificates: whole days left per name, a failed handshake left out" "$sc_ok" "0"
chk "containers: only the two whose name is their SITENAME" "$(jv "$T/root.json" containers count)/$(jv "$T/root.json" containers.0.name)/$(jv "$T/root.json" containers.1.name)" "2/siteone/sitetwo"
chk "containers: one answering through PHP, one not" "$(jv "$T/root.json" containers.0.answers)/$(jv "$T/root.json" containers.1.answers)" "yes/no"
chk "containers: state and health" "$(jv "$T/root.json" containers.0.state)/$(jv "$T/root.json" containers.0.health)" "running/none"
chk "sshd carries the compiled keys, in order" "$(jv "$T/root.json" sshd keys)" "password_authentication,permit_root_login,pubkey_authentication,kbd_interactive_authentication,max_auth_tries,ports,allow_users,allow_groups"
chk "sshd auth methods and tries" "$(jv "$T/root.json" sshd.pubkey_authentication)/$(jv "$T/root.json" sshd.kbd_interactive_authentication)/$(jv "$T/root.json" sshd.max_auth_tries)" "yes/no/6"
chk "sshd ports are every port line" "$(jv "$T/root.json" sshd.ports)" '["22","2222"]'
chk "sshd allowed users and groups are lists" "$(jv "$T/root.json" sshd.allow_users)/$(jv "$T/root.json" sshd.allow_groups)" '["admin","deploy"]/["sshusers"]'
chk "no host key or authorized-keys path from sshd -T reaches the object" "$(grep -c -E 'ssh_host_|authorized_keys' "$T/root.json")" "0"
chk "the three events are counted from the system journal" "$(jv "$T/root.json" kernel_events_24h.oom)/$(jv "$T/root.json" kernel_events_24h.enospc)/$(jv "$T/root.json" kernel_events_24h.io_error)" "1/2/0"
chk "journalctl's own no-entries line is not counted as an event" "$(jv "$T/root.json" kernel_events_24h.io_error)" "0"
chk "no text from an event line reaches the object" "$(grep -c -E 'mandb|/var/cache/man|3420559|total-vm|Killed process' "$T/root.json")" "0"
chk "the banned IP list never reaches the object" "$(grep -c '203.0.113.9\|198.51.100' "$T/root.json")" "0"
# Anchored to word edges on purpose: an unanchored 'eve' also matches the key
# name kernel_events_24h, which would make this check pass or fail for a reason
# that has nothing to do with a username.
chk "no username from the journal reaches the object" "$(grep -c -E '(^|[^a-z])(eve|mallory|ops)([^a-z]|$)' "$T/root.json")" "0"
chk "no quote, semicolon, dollar or angle bracket from a planted name survives" "$(grep -c '\$(\|<b>\|;' "$T/root.json")" "0"
# The planted unit name is the 26th line and falls outside the cap; plant it
# first to prove the sanitiser rather than the cap.
sed -i 's/for i in $(seq 1 25); do echo "broken$i.service loaded failed failed Broken thing $i"; done/echo '"'"'evil"name;$(reboot)<b>.service loaded failed failed X'"'"'/' "$T/bin/systemctl"
PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/root2.json" 2>/dev/null
chk "a hostile unit name is reduced to safe characters" "$(jv "$T/root2.json" failed_units.0)" "evilnamerebootb.service"
chk "and the object still parses" "$(jv "$T/root2.json" "" type)" "object"

echo "=== A command that hangs costs its key, not the report ==="
cat > "$T/bin/fail2ban-client" <<'STUB'
#!/bin/bash
sleep 60
STUB
start=$(date +%s)
PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/hang.json" 2>/dev/null; rc=$?
elapsed=$(( $(date +%s) - start ))
chk "hung fail2ban-client: exit 0" "$rc" "0"
chk "hung fail2ban-client: jails are unknown" "$(jv "$T/hang.json" fail2ban_jails)" "unknown"
chk "hung fail2ban-client: the rest of the report is intact" "$(jv "$T/hang.json" ssh_auth_failures_24h)" "4"
chk "the report returned well inside the word's minute (${elapsed}s)" "$( [ "$elapsed" -lt 30 ]; echo $? )" "0"

echo "=== No systemd answering: a count from no journal is unknown, not 0 ==="
# A container: systemctl cannot connect, journalctl exits clean with nothing.
# Reading that as zero failures would be a false clean on every container.
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
echo "System has not been booted with systemd as init system (PID 1). Can't operate." >&2
exit 1
STUB
cat > "$T/bin/journalctl" <<'STUB'
#!/bin/bash
exit 0
STUB
cat > "$T/bin/fail2ban-client" <<'STUB'
#!/bin/bash
exit 1
STUB
PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/nosd.json" 2>/dev/null; rc=$?
chk "no systemd: exit 0" "$rc" "0"
chk "no systemd: ssh auth failures are unknown, not 0" "$(jv "$T/nosd.json" ssh_auth_failures_24h)" "unknown"
chk "no systemd: the expected units are unknown too" "$(jv "$T/nosd.json" expected_units.apache2)" "unknown"
chk "no systemd: disk is still measured" "$(jv "$T/nosd.json" disk.total_bytes type)" "integer"

echo "=== Static pins ==="
chk "every command runs under the per-command timeout" "$(grep -c '^run() { timeout "\$CMD_TIMEOUT"' "$SCRIPT")" "1"
# Two, and both are counts: the SSH auth failures, and journal_event_count,
# which every event pattern goes through. A journal read that is not piped into
# grep -c is a journal read whose text could reach the object.
chk "the journal is only ever counted (grep -c), never printed" "$(grep -c 'grep -c -E' "$SCRIPT")" "2"
chk "journalctl appears twice, under run both times" "$(grep -c 'run journalctl --system' "$SCRIPT")" "2"
# journalctl does the matching itself, so a day of log is never handed to the
# shell; and the read is the SYSTEM journal, because an ENOSPC line comes from
# the program that hit it and never from the kernel ring.
chk "the event read filters in journalctl (-g) over the system journal" "$(grep -c 'run journalctl --system --since "24 hours ago" --no-pager -o cat -g' "$SCRIPT")" "1"
chk "no kernel-ring read is left" "$(grep -c 'journalctl --system -k' "$SCRIPT")" "0"
chk "the list cap is 20" "$(grep -c '^MAX_LIST=20' "$SCRIPT")" "1"
chk "the name cap is 64" "$(grep -c '^MAX_NAME=64' "$SCRIPT")" "1"
chk "sshd is invoked once, read-only (-T)" "$(grep -o 'run sshd[^)]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "run sshd -T "
chk "openssl only reads: s_client and x509 -noout" "$(grep -o 'run openssl [a-z_0-9]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "run openssl s_client run openssl x509 "
chk "no docker verb but ps, inspect and port" "$(grep -o 'run docker [a-z]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "run docker inspect run docker port run docker ps "
chk "curl is only ever asked for headers, body discarded" "$(grep -c 'run curl' "$SCRIPT")/$(grep 'run curl' "$SCRIPT" | grep -c -- '-o /dev/null -D -')" "3/3"
chk "no systemctl verb but show and list" "$(grep -o 'systemctl [a-z-]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "systemctl list-unit-files systemctl list-units systemctl show "
chk "no fail2ban-client verb but status" "$(grep -o 'fail2ban-client [a-z]\+' "$SCRIPT" | sort -u | tr '\n' ' ')" "fail2ban-client status "
chk "the release check is read from its cache, never run" "$(grep -c -E 'check-new-release -|do-release-upgrade -' "$SCRIPT")" "0"
chk "nothing writes: no tee, no redirect into /etc or /var, no rm, no mv" "$(grep -c -E '\btee\b|> */(etc|var)|\brm |\bmv |\bcp ' "$SCRIPT")" "0"
chk "exit 0 is the last thing it does" "$(tail -n 1 "$SCRIPT")" "exit 0"

echo
echo "host_report gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
