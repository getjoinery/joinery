#!/bin/bash
# @joinery-test
# name: host_report
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/sysadmin_tools/host_report.sh, maintenance_scripts/install_tools/_kernel_settings.sh]
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
# printed. A site container's figures (memory, peak, limit, kills, CPU,
# processes, bytes sent, disk) come from its cgroup, its network namespace and
# one bounded du; inside a container, memory is the site's own group.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/host_report.sh"
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

KEYS="failed_units,expected_units,fail2ban_jails,ssh_auth_failures_24h,kernel_events_24h,sshd,root_ssh,disk,disk_pool,data_root_encryption,data_root_script,data_root_stopped,memory,swap,cpus,reboot_required,reboot_required_since,boot_id,unattended_upgrades_last_run,os,answers,served_certificates,host_restrictions,site_engine,hardening,containers,sites,site_security,site_refresh,outbound_limits,mail_gateway,generated_at"

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
chk "kernel events are four counts or unknown" "$( t=$(jv "$T/real.json" kernel_events_24h type); [ "$t" = object ] && [ "$(jv "$T/real.json" kernel_events_24h keys)" = "oom,enospc,io_error,kernel_warning" ] || [ "$(jv "$T/real.json" kernel_events_24h)" = unknown ]; echo $? )" "0"
chk "memory figures are integers" "$(jv "$T/real.json" memory.used_bytes type)/$(jv "$T/real.json" memory.total_bytes type)" "integer/integer"
chk "swap figures are integers" "$(jv "$T/real.json" swap.used_bytes type)/$(jv "$T/real.json" swap.total_bytes type)" "integer/integer"
chk "reboot_required is a boolean" "$(jv "$T/real.json" reboot_required type)" "boolean"
chk "reboot_required_since is a time when one is pending, null when none is" \
    "$( r=$(jv "$T/real.json" reboot_required); t=$(jv "$T/real.json" reboot_required_since type); { [ "$r" = true ] && [ "$t" = integer ]; } || { [ "$r" = false ] && [ "$t" = NULL ]; }; echo $? )" "0"
chk "os names its four parts" "$(jv "$T/real.json" os keys)" "id,version,codename,release_upgrade"
chk "os version is dotted digits or unknown" "$( v=$(jv "$T/real.json" os.version); [ "$v" = unknown ] || [[ "$v" =~ ^[0-9]+(\.[0-9]+)*$ ]]; echo $? )" "0"
chk "the offered upgrade is a version, none or unknown" "$( v=$(jv "$T/real.json" os.release_upgrade.offered); [ "$v" = none ] || [ "$v" = unknown ] || [[ "$v" =~ ^[0-9]+(\.[0-9]+)*$ ]]; echo $? )" "0"
chk "the upgrade check time is a time or unknown" "$( t=$(jv "$T/real.json" os.release_upgrade.checked_at type); [ "$t" = integer ] || [ "$(jv "$T/real.json" os.release_upgrade.checked_at)" = unknown ]; echo $? )" "0"
chk "cpus is a count or unknown" "$( v=$(jv "$T/real.json" cpus); [ "$v" = unknown ] || [[ "$v" =~ ^[0-9]+$ ]]; echo $? )" "0"
chk "answers names the three services" "$(jv "$T/real.json" answers keys)" "apache2,php-fpm,postgresql"
for u in apache2 php-fpm postgresql; do
    s="$(jv "$T/real.json" answers.$u)"
    case "$s" in yes|no|unknown|quiet) ok=1 ;; *) ok=0 ;; esac
    chk "answers.$u is yes, no, unknown or quiet ($s)" "$ok" "1"
done
chk "served certificates is a list or unknown" "$( t=$(jv "$T/real.json" served_certificates type); [ "$t" = list ] || [ "$(jv "$T/real.json" served_certificates)" = unknown ]; echo $? )" "0"
chk "containers is a list, none or unknown" "$( t=$(jv "$T/real.json" containers type); v=$(jv "$T/real.json" containers); [ "$t" = list ] || [ "$v" = none ] || [ "$v" = unknown ]; echo $? )" "0"
chk "outbound_limits names its four parts" "$(jv "$T/real.json" outbound_limits keys)" "state,reason,since,web_user_dropped"
chk "outbound_limits is a known state" "$( case "$(jv "$T/real.json" outbound_limits.state)" in on|off|refused|absent|none|unknown) echo ok ;; esac )" "ok"
chk "mail_gateway is none or names its parts" "$( v=$(jv "$T/real.json" mail_gateway); k=$(jv "$T/real.json" mail_gateway keys); [ "$v" = none ] || [ "$k" = "version,state,restarts,up_seconds,connections,sites" ]; echo $? )" "0"
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

echo "=== Since when a reboot is pending ==="
# The emitter alone, pointed at a scratch file: the path is fixed in the script.
eval "$(sed -n '/^emit_reboot_required_since() {/,/^}/p' "$SCRIPT" | sed "s#/var/run/reboot-required#$T/reboot-required#")"
CMD_TIMEOUT=5; run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }
json_num_or_unknown() { if [[ "$1" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '"unknown"'; fi; }
rm -f "$T/reboot-required"
chk "none pending: null" "$(emit_reboot_required_since)" "null"
echo "*** System restart required ***" > "$T/reboot-required"
first="$(emit_reboot_required_since)"
chk "pending: a time, and it is now" "$( n=$(date +%s); [[ "$first" =~ ^[0-9]+$ ]] && [ "$first" -le "$n" ] && [ "$first" -ge $((n-60)) ]; echo $? )" "0"
if [ "$(stat -c %W "$T/reboot-required")" != "0" ]; then
    sleep 1.2
    echo "*** System restart required ***" > "$T/reboot-required"
    chk "a later update rewriting it in place keeps the first request's time" "$(emit_reboot_required_since)" "$first"
else
    echo "  SKIP: this filesystem keeps no birth time"
fi

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
# each the way the real journalctl would is what makes the counts meaningful:
# -g exits 1 when nothing matched, as it does when the journal cannot be read
# (systemd 255 and 259; B35), and -n 0 tells the two apart. With -o cat and -q
# a no-match prints nothing; a journalctl that cannot run the pattern says so on
# stderr, with the same exit 1 (reviewer2 R2).
if [ -n "${GATE_JOURNAL_GREP_BROKEN:-}" ] && [[ "$*" == *" -g "* ]]; then
    echo "Compiled without pattern matching support" >&2
    exit 1
fi
case "$*" in
    *"-n 0"*)
        [ -n "${GATE_JOURNAL_UNREADABLE:-}" ] && exit 1
        exit 0
        ;;
    *"_TRANSPORT=kernel"*)
        echo "WARNING: CPU: 1 PID: 77 at net/core/dev.c:123 thing+0x1/0x2"
        echo "BUG: kernel NULL pointer dereference, address: 0000000000000008"
        echo "kernel BUG at mm/slub.c:451!"
        echo "Speculative Return Stack Overflow: WARNING: See https://kernel.org/doc/html/latest/admin-guide/hw-vuln/srso.html for mitigation options."
        echo "Spectre V2 : WARNING: Unprivileged eBPF is enabled with eIBRS on, data leaks possible via Spectre v2 BHB attacks!"
        ;;
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
        exit 1
        ;;
esac
STUB
cat > "$T/bin/curl" <<'STUB'
#!/bin/bash
[ -n "${GATE_CURL_LOG:-}" ] && echo "curl $*" >> "$GATE_CURL_LOG"
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
# expiry, subject and issuer for it: one name nearly expired and signing
# itself (a placeholder), one far off from a real issuer, one whose handshake
# fails and prints nothing.
case "$1" in
    s_client)
        for a in "$@"; do [ "$prev" = "-servername" ] && name="$a"; prev="$a"; done
        case "$name" in *fail*) exit 1 ;; esac
        echo "CERT-FOR $name" ;;
    x509)
        read -r _ name
        case "$name" in
            www.*) echo "notAfter=$(date -u -d '+5 days +1 hour' '+%b %d %H:%M:%S %Y GMT')"; echo "subject=CN=$name"; echo "issuer=CN=$name" ;;
            "") exit 1 ;;
            *) echo "notAfter=$(date -u -d '+80 days +1 hour' '+%b %d %H:%M:%S %Y GMT')"; echo "subject=CN=$name"; echo "issuer=CN=R11,O=Let's Encrypt,C=US" ;;
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
# SITENAME that is not its name, one with none at all. inspect answers the
# report's one line per container, for every name it is handed at once.
echo "docker $1" >> "${GATE_DOCKER_LOG:-/dev/null}"
case "$1 $2" in
    "ps -a") printf 'siteone\nsitetwo\nimpostor\npostgres\nEvil;Name\n' ;;
    "inspect -f")
        shift 3
        for c in "$@"; do
            case "$c" in
                # siteone's process is the gate's own, so its cgroup and network
                # namespace are real files to read; sitetwo has no process.
                siteone)  echo "/siteone|SITENAME=siteone|running|none|$GATE_PID|2026-09-28T18:01:15.123456789Z|8081|$GATE_VOL/one_data $GATE_VOL/one_uploads $GATE_VOL/siteone_config/_data " ;;
                sitetwo)  echo "/sitetwo|SITENAME=sitetwo|running|none|0|0001-01-01T00:00:00Z|8082|" ;;
                impostor) echo "/impostor|SITENAME=siteone|running|none|0|0001-01-01T00:00:00Z|8083|" ;;
                *)        echo "/$c||running|none|0|0001-01-01T00:00:00Z||" ;;
            esac
        done ;;
esac
STUB
chmod 755 "$T/bin"/*
mkdir -p "$T/vol/one_data" "$T/vol/one_uploads"
head -c 100000 /dev/zero > "$T/vol/one_data/db"; head -c 300000 /dev/zero > "$T/vol/one_uploads/photo"
# siteone's config volume holds the code check site_housekeeping.sh writes on a
# host that refuses unsigned code (specs/host_restrictions.md D8).
mkdir -p "$T/vol/siteone_config/_data"
printf 'failed 2026-10-13T01:40:00Z\nfail\tcore\t\ttampered\tpublic_html/includes/Mail Guard.php\nfail\tplugin\tmailbox\textra_file\tincludes/x.php\nnot a line\n' \
    > "$T/vol/siteone_config/_data/code_check"
export GATE_PID=$$ GATE_VOL="$T/vol"
# sitetwo is held stopped by hold_container (a switch-over's old container).
mkdir -p "$T/etc/joinery/sites/sitetwo"; printf 'restart=unless-stopped\n' > "$T/etc/joinery/sites/sitetwo/held"
# sitetwo is being replaced (recreate_site.sh's mark, fresh); siteone carries a
# mark two hours old: a replacement that was killed, not obeyed.
date -u > "$T/etc/joinery/sites/sitetwo/replacing"
mkdir -p "$T/etc/joinery/sites/siteone"; date -u > "$T/etc/joinery/sites/siteone/replacing"; touch -d '2 hours ago' "$T/etc/joinery/sites/siteone/replacing"
# Root's authorized keys (host_report 1.16): a bare key, the same key with options, junk, and a comment line.
mkdir -p "$T/rootssh"
ssh-keygen -q -t ed25519 -N '' -C 'gate@key' -f "$T/rootssh/k1"
{ cat "$T/rootssh/k1.pub"; echo "# a note"; echo "command=\"/bin/true\" $(cat "$T/rootssh/k1.pub")"; echo "not a key"; } > "$T/rootssh/authorized_keys"
PATH="$T/bin:$PATH" HOST_REPORT_ETC="$T/etc" HOST_REPORT_ROOT_SSH_DIR="$T/rootssh" bash "$SCRIPT" > "$T/root.json" 2> "$T/root.err"; rc=$?
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
site_is_quiet() { return 1; }
for case in "502:no" "503:no" "301:unknown" "403:unknown" "200:unknown"; do
    st="${case%%:*}"; want="${case##*:}"
    site_server_name() { echo site.example; }
    site_vhost_address() { echo 127.0.0.1; }
    loopback_headers() { printf 'HTTP/1.1 %s X\r\n\r\n' "$st"; }
    got="$(emit_answers | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["php-fpm"];')"
    chk "a headerless $st says php-fpm is $want" "$got" "$want"
done
# A quiet site (a dormant copy, a frozen source) answers every page with the
# quiet state's 503: that is not PHP failing, and must never read as no.
site_is_quiet() { return 0; }
loopback_headers() { printf 'HTTP/1.1 503 Service Unavailable\r\n\r\n'; }
got="$(emit_answers | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["apache2"] . "/" . $o["php-fpm"];')"
chk "a quiet site's 503 says Apache answers and php-fpm is quiet, never no" "$got" "yes/quiet"
# Inside a container, memory is the site's own group out of its limit.
CG="$T/cg"; mkdir -p "$CG"
printf '300000000\n' > "$CG/memory.current"; printf 'anon 5\ninactive_file 100000000\n' > "$CG/memory.stat"
printf '268435456\n' > "$CG/memory.max"; printf '150000 100000\n' > "$CG/cpu.max"
eval "$(sed -n -e '/^json_num_or_unknown() {/,/^}/p' -e '/^meminfo_kb() {/,/^}/p' "$SCRIPT")"
eval "$(sed -n '/^cg_num() {/,/^emit_swap() {/p' "$SCRIPT" | sed '$d')"
in_container() { return 0; }
own_cgroup_dir() { printf '%s' "$CG"; }
chk "in a container: in use is the group's, less inactive cache, out of its limit" "$(emit_memory)" '{"used_bytes":200000000,"total_bytes":268435456}'
printf 'max\n' > "$CG/memory.max"
chk "in a container with no limit: out of the whole server" "$(emit_memory | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["used_bytes"], "/", $o["total_bytes"] === (int)trim(shell_exec("awk \x27/^MemTotal:/ {print \$2*1024}\x27 /proc/meminfo")) ? "server" : $o["total_bytes"];')" "200000000/server"
in_container() { return 1; }
chk "outside a container: the machine's figures, unchanged" "$(emit_memory | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["used_bytes"] === 200000000 ? "group" : "machine";')" "machine"
chk "a CPU ceiling is thousandths of a core; max is none" "$(cg_cpu_limit "$CG/cpu.max")/$(printf 'max 100000\n' > "$CG/cpu.max"; cg_cpu_limit "$CG/cpu.max")" "1500/none"
sc_ok=0; sc_n="$(jv "$T/root.json" served_certificates count)"
for i in $(seq 0 $(( ${sc_n:-0} - 1 ))); do
    d="$(jv "$T/root.json" served_certificates.$i.domain)"; l="$(jv "$T/root.json" served_certificates.$i.days_left)"
    case "$d" in *fail*) sc_ok=1 ;; www.*) [ "$l" = 5 ] || sc_ok=1 ;; *) [ "$l" = 80 ] || sc_ok=1 ;; esac
done
chk "served certificates: whole days left per name, a failed handshake left out" "$sc_ok" "0"
ss_ok=0
for i in $(seq 0 $(( ${sc_n:-0} - 1 ))); do
    d="$(jv "$T/root.json" served_certificates.$i.domain)"; s="$(jv "$T/root.json" served_certificates.$i.self_signed)"
    case "$d" in www.*) [ "$s" = true ] || ss_ok=1 ;; *) [ "$s" = false ] || ss_ok=1 ;; esac
done
chk "served certificates: self_signed where the issuer is the subject, not for a real issuer" "$ss_ok" "0"
chk "containers: only the two whose name is their SITENAME" "$(jv "$T/root.json" containers count)/$(jv "$T/root.json" containers.0.name)/$(jv "$T/root.json" containers.1.name)" "2/siteone/sitetwo"
chk "containers: one answering through PHP, one not" "$(jv "$T/root.json" containers.0.answers)/$(jv "$T/root.json" containers.1.answers)" "yes/no"
# A copy waiting for its key, or a frozen source, is quiet on its host
# (site_quiet_host.sh's record): every page is the quiet state's 503, so it is
# not asked, and container_health must never restart it for not answering (B27).
mkdir -p "$T/etc/joinery/sites/siteone"; printf 'copy\n' > "$T/etc/joinery/sites/siteone/quiet"
GATE_CURL_LOG="$T/curl.log" PATH="$T/bin:$PATH" HOST_REPORT_ETC="$T/etc" HOST_REPORT_ROOT_SSH_DIR="$T/rootssh" bash "$SCRIPT" > "$T/quiet.json" 2>/dev/null
rm -f "$T/etc/joinery/sites/siteone/quiet"
chk "a container quiet on its host answers quiet, and is not asked; the others are" \
    "$(jv "$T/quiet.json" containers.0.answers)/$(grep -c '127.0.0.1:8081' "$T/curl.log")/$(jv "$T/quiet.json" containers.1.answers)/$(grep -c '127.0.0.1:8082' "$T/curl.log")" "quiet/0/no/1"
chk "containers: state and health" "$(jv "$T/root.json" containers.0.state)/$(jv "$T/root.json" containers.0.health)" "running/none"
# On the site container engine: the same list through podman, whose names carry
# no slash and whose start time is asked for in RFC 3339 (specs/site_container_engine.md WP3).
sed -e 's#echo "/\(siteone\|sitetwo\|impostor\|\$c\)|#echo "\1|#' -e 's#echo "docker \$1"#echo "podman \$1 \$3"#' "$T/bin/docker" > "$T/bin/podman"; chmod 755 "$T/bin/podman"
mv "$T/bin/docker" "$T/docker.real"
GATE_DOCKER_LOG="$T/podman.log" JOINERY_SITE_ENGINE_KIND=podman PATH="$T/bin:$PATH" HOST_REPORT_ETC="$T/etc" HOST_REPORT_ROOT_SSH_DIR="$T/rootssh" bash "$SCRIPT" > "$T/pod.json" 2>/dev/null
mv "$T/docker.real" "$T/bin/docker"; rm -f "$T/bin/podman"
chk "on the site container engine: the same two sites, through podman" "$(jv "$T/pod.json" containers count)/$(jv "$T/pod.json" containers.0.name)/$(jv "$T/pod.json" containers.0.answers)" "2/siteone/yes"
chk "  and it says which engine its sites run on, for a copy to choose a host by" "$(jv "$T/pod.json" site_engine)/$(jv "$T/root.json" site_engine)" "podman/docker"
chk "  its start time asked for in RFC 3339, so started_at holds" \
    "$(grep -c 'StartedAt.Format "2006-01-02T15:04:05.999999999Z07:00"' "$T/podman.log")|$(jv "$T/pod.json" containers.0.started_at)" "1|$(jv "$T/root.json" containers.0.started_at)"
# A code_check the site made a link is not followed (host_report runs as root; reviewer2 T1).
CC="$T/vol/siteone_config/_data/code_check"; mv "$CC" "$T/cc.real"; ln -s "$T/cc.real" "$CC"
PATH="$T/bin:$PATH" HOST_REPORT_ETC="$T/etc" HOST_REPORT_ROOT_SSH_DIR="$T/rootssh" bash "$SCRIPT" > "$T/link.json" 2>/dev/null
rm -f "$CC"; mv "$T/cc.real" "$CC"
chk "a code_check that is a link is not followed: unknown" "$(jv "$T/link.json" containers.0.code_check)" "unknown"
# A site's figures (multi_tenant_docker_hosts WP1), read from the cgroup and
# network namespace of the process docker names - here the gate's own.
int_or_none() { case "$1" in none|[0-9]*) echo ok ;; *) echo "$1" ;; esac; }
chk "containers: a held container says so, and only it" "$(jv "$T/root.json" containers.0.held)/$(jv "$T/root.json" containers.1.held)" "false/true"
chk "a site carries every figure key" "$(jv "$T/root.json" containers.0 keys)" "name,state,health,answers,held,started_at,memory,cpu,pids,net_tx_bytes,outbound_dropped,disk_bytes,code_check"
chk "containers: one being replaced says so; a mark two hours old is not obeyed and adds no key" \
    "$(jv "$T/root.json" containers.1.replacing)/$(jv "$T/root.json" containers.0.replacing)" "true/ABSENT"
rm -f "$T/etc/joinery/sites/sitetwo/replacing" "$T/etc/joinery/sites/siteone/replacing"
chk "code_check: the state and when, from the site's config volume" \
    "$(jv "$T/root.json" containers.0.code_check.state)/$(jv "$T/root.json" containers.0.code_check.at)" "failed/2026-10-13T01:40:00Z"
chk "code_check: each failure, and nothing that is not one" "$(jv "$T/root.json" containers.0.code_check.failures count)" "2"
chk "code_check: kind, name, verdict and file; a file name reduced to safe characters" \
    "$(jv "$T/root.json" containers.0.code_check.failures.0.kind)|$(jv "$T/root.json" containers.0.code_check.failures.0.file)|$(jv "$T/root.json" containers.0.code_check.failures.1.name)|$(jv "$T/root.json" containers.0.code_check.failures.1.verdict)" \
    "core|public_html/includes/MailGuard.php|mailbox|extra_file"
chk "code_check: a site whose config volume is not found is unknown" "$(jv "$T/root.json" containers.1.code_check)" "unknown"
chk "started_at is the container's start, as a Unix time" "$(jv "$T/root.json" containers.0.started_at)" "$(date -u -d 2026-09-28T18:01:15Z +%s)"
chk "memory in use, its peak and its kill count are numbers" "$(jv "$T/root.json" containers.0.memory.used_bytes type)/$(jv "$T/root.json" containers.0.memory.peak_bytes type)/$(jv "$T/root.json" containers.0.memory.oom_kills type)" "integer/integer/integer"
chk "the memory and process ceilings are a number or none" "$(int_or_none "$(jv "$T/root.json" containers.0.memory.limit_bytes)")/$(int_or_none "$(jv "$T/root.json" containers.0.pids.limit)")" "ok/ok"
chk "CPU used and processes running are numbers" "$(jv "$T/root.json" containers.0.cpu.usage_usec type)/$(jv "$T/root.json" containers.0.pids.current type)" "integer/integer"
chk "bytes sent is a number" "$(jv "$T/root.json" containers.0.net_tx_bytes type)" "integer"
chk "disk is the sum of the site's volumes, measured" "$(jv "$T/root.json" containers.0.disk_bytes)" "$(( $(du -s -B1 "$T/vol/one_data" | cut -f1) + $(du -s -B1 "$T/vol/one_uploads" | cut -f1) + $(du -s -B1 "$T/vol/siteone_config/_data" | cut -f1) ))"
chk "a site with no process says unknown for every figure" "$(jv "$T/root.json" containers.1.started_at)/$(jv "$T/root.json" containers.1.memory.used_bytes)/$(jv "$T/root.json" containers.1.cpu.usage_usec)/$(jv "$T/root.json" containers.1.net_tx_bytes)" "unknown/unknown/unknown/unknown"
chk "a site with no volumes has no disk figure" "$(jv "$T/root.json" containers.1.disk_bytes)" "unknown"
printf 'ok 2026-10-13T02:00:00Z\n' > "$T/vol/siteone_config/_data/code_check"
PATH="$T/bin:$PATH" HOST_REPORT_ETC="$T/etc" HOST_REPORT_ROOT_SSH_DIR="$T/rootssh" bash "$SCRIPT" > "$T/cc.json" 2>/dev/null
chk "code_check: ok, with no failures" "$(jv "$T/cc.json" containers.0.code_check.state)/$(jv "$T/cc.json" containers.0.code_check.failures count)" "ok/0"
printf 'whatever\n' > "$T/vol/siteone_config/_data/code_check"
PATH="$T/bin:$PATH" HOST_REPORT_ETC="$T/etc" HOST_REPORT_ROOT_SSH_DIR="$T/rootssh" bash "$SCRIPT" > "$T/cc.json" 2>/dev/null
chk "code_check: a file not in the form the site writes is unknown, never ok" "$(jv "$T/cc.json" containers.0.code_check)" "unknown"
rm -f "$T/vol/siteone_config/_data/code_check"
PATH="$T/bin:$PATH" HOST_REPORT_ETC="$T/etc" HOST_REPORT_ROOT_SSH_DIR="$T/rootssh" bash "$SCRIPT" > "$T/cc.json" 2>/dev/null
chk "code_check: none where the site keeps no file (a host that does not refuse unsigned code)" "$(jv "$T/cc.json" containers.0.code_check)" "none"
chk "host_restrictions: none where the machine has no restrictions file" \
    "$(HOST_REPORT_RESTRICTIONS="$T/norestr/restrictions" PATH="$T/bin:$PATH" bash "$SCRIPT" 2>/dev/null > "$T/hr.json"; jv "$T/hr.json" host_restrictions)" "none"
mkdir -p "$T/restr"
printf '# joinery-managed\nunsigned_packages=refuse\n\n' > "$T/restr/restrictions"
HOST_REPORT_RESTRICTIONS="$T/restr/restrictions" PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/hr.json" 2>/dev/null
chk "host_restrictions: each name and value the file holds" "$(jv "$T/hr.json" host_restrictions.unsigned_packages)|$(jv "$T/hr.json" host_restrictions count)" "refuse|1"
printf 'unsigned_packages=refuse\nnot a line\n' > "$T/restr/restrictions"
HOST_REPORT_RESTRICTIONS="$T/restr/restrictions" PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/hr.json" 2>/dev/null
chk "host_restrictions: a file not in the form it is written is unknown (the sites read it as all on)" "$(jv "$T/hr.json" host_restrictions)" "unknown"
rm -f "$T/restr/restrictions"
HOST_REPORT_RESTRICTIONS="$T/restr/restrictions" PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/hr.json" 2>/dev/null
chk "host_restrictions: the directory without its file is unknown, never none" "$(jv "$T/hr.json" host_restrictions)" "unknown"
chk "root_ssh lists each key line with its fingerprint" "$(jv "$T/root.json" root_ssh.keys count)" "3"
chk "a bare key is carried, with its type, key and comment" "$(jv "$T/root.json" root_ssh.keys.0.carry)/$(jv "$T/root.json" root_ssh.keys.0.type)/$(jv "$T/root.json" root_ssh.keys.0.comment)" "true/ssh-ed25519/gate@key"
chk "a key line with options is listed, never carried" "$(jv "$T/root.json" root_ssh.keys.1.carry)/$(jv "$T/root.json" root_ssh.keys.1.key)" "false/"
chk "a line that is not a key has no fingerprint and is not carried" "$(jv "$T/root.json" root_ssh.keys.2.carry)/$(jv "$T/root.json" root_ssh.keys.2.fingerprint)" "false/unknown"
chk "unreadable keys read unknown, not none" "$(HOST_REPORT_ROOT_SSH_DIR="$T/nonexistent" bash "$SCRIPT" 2>/dev/null | python3 -c 'import sys,json;print(json.load(sys.stdin)["root_ssh"])')" "unknown"
chk "sshd carries the compiled keys, in order" "$(jv "$T/root.json" sshd keys)" "password_authentication,permit_root_login,pubkey_authentication,kbd_interactive_authentication,max_auth_tries,ports,allow_users,allow_groups"
chk "sshd auth methods and tries" "$(jv "$T/root.json" sshd.pubkey_authentication)/$(jv "$T/root.json" sshd.kbd_interactive_authentication)/$(jv "$T/root.json" sshd.max_auth_tries)" "yes/no/6"
chk "sshd ports are every port line" "$(jv "$T/root.json" sshd.ports)" '["22","2222"]'
chk "sshd allowed users and groups are lists" "$(jv "$T/root.json" sshd.allow_users)/$(jv "$T/root.json" sshd.allow_groups)" '["admin","deploy"]/["sshusers"]'
chk "no host key or authorized-keys path from sshd -T reaches the object" "$(grep -c -E 'ssh_host_|authorized_keys' "$T/root.json")" "0"
chk "the three events are counted from the system journal" "$(jv "$T/root.json" kernel_events_24h.oom)/$(jv "$T/root.json" kernel_events_24h.enospc)/$(jv "$T/root.json" kernel_events_24h.io_error)" "1/2/0"
chk "a pattern nothing matched counts 0" "$(jv "$T/root.json" kernel_events_24h.io_error)" "0"
chk "a pattern nothing matched (journalctl exits 1) is zero, not unknown (B35)" "$(jv "$T/root.json" kernel_events_24h type)" "object"
chk "the kernel's own warnings are counted from its messages alone, a BUG() with no colon among them, never a boot notice about the CPU's mitigations (D7, R1)" \
    "$(jv "$T/root.json" kernel_events_24h.kernel_warning)|$(grep -c 'journal_event_count "\$KERNEL_EVENT_WARNING" _TRANSPORT=kernel' "$SCRIPT")" "3|1"
# The rest by the functions alone, in a subshell each: a whole run per case
# would double this gate's time.
fn_src() { sed -n -e '/^safe_name() {/,/^}/p' -e '/^json_str() {.*}$/p' -e '/^json_num_or_unknown() {/,/^}/p' \
    -e '/^run() {/,/^}/p' -e '/^in_container() {/p' -e '/^CMD_TIMEOUT=/p' -e '/^MAX_LIST=/p' -e '/^MAX_NAME=/p' \
    -e '/^KERNEL_EVENT_[A-Z]*=/p' -e '/^journal_readable() {/p' -e '/^journal_event_count() {/,/^}/p' \
    -e '/^emit_kernel_events() {/,/^}/p' -e '/^emit_hardening() {/,/^}/p' "$SCRIPT"; }
fn_src > "$T/fns.sh"
fn() { (PATH="$T/bin:$PATH"; . "$T/fns.sh"; "$@") 2>/dev/null; }
chk "the functions under test were each found once, and what they read" \
    "$(grep -c -E '^(journal_readable|journal_event_count|emit_kernel_events|emit_hardening|run|json_str|json_num_or_unknown|safe_name|in_container)\(\)' "$T/fns.sh")|$(grep -c -E '^(CMD_TIMEOUT|MAX_LIST|MAX_NAME|KERNEL_EVENT_[A-Z]+)=' "$T/fns.sh")|$(bash -n "$T/fns.sh" && echo parses)" "9|7|parses"
chk "  and they count from a readable journal" "$(fn emit_kernel_events)" '{"oom":1,"enospc":2,"io_error":0,"kernel_warning":3}'
chk "a journal this run cannot read: unknown" "$(GATE_JOURNAL_UNREADABLE=1 fn emit_kernel_events)" '"unknown"'
chk "a journalctl that says it cannot run the pattern (exit 1, a complaint): unknown, never 0 (R2)" "$(GATE_JOURNAL_GREP_BROKEN=1 fn emit_kernel_events)" '"unknown"'
mkdir -p "$T/badgrep"; printf '#!/bin/sh\nexit 2\n' > "$T/badgrep/grep"; chmod 755 "$T/badgrep/grep"
chk "a grep that fails while counting: unknown, never its exit code as a count" \
    "$( (PATH="$T/badgrep:$T/bin:$PATH"; . "$T/fns.sh"; journal_event_count "$KERNEL_EVENT_OOM") 2>/dev/null; echo "rc=$?")" "rc=1"

echo "=== The pinned kernel settings (hardening, D1) ==="
# A /proc/sys of the helper's own values, then one setting loosened and one
# this kernel lacks.
KP="$T/procsys"
( . "$ROOT/maintenance_scripts/install_tools/_kernel_settings.sh"
  for s in "${KERNEL_SETTINGS[@]}" "${NETWORK_SETTINGS[@]}"; do
      k="${s%% =*}"; mkdir -p "$KP/$(dirname "${k//.//}")"; echo "${s##*= }" > "$KP/${k//.//}"
  done )
KS="$ROOT/maintenance_scripts/install_tools/_kernel_settings.sh"
hard() { printf '{"hardening":%s}' "$(KERNEL_SETTINGS_PROC="$KP" HOST_REPORT_KERNEL_SETTINGS="${1:-$KS}" fn emit_hardening)" > "$T/hard.json"; }
hard
chk "every pinned setting held: ok, all 38 read" "$(jv "$T/hard.json" hardening.state)|$(jv "$T/hard.json" hardening.pinned)|$(jv "$T/hard.json" hardening.unread)|$(jv "$T/hard.json" hardening.drift count)" "ok|38|0|0"
echo 0 > "$KP/kernel/yama/ptrace_scope"; rm "$KP/net/core/bpf_jit_harden"
hard
chk "a loosened setting is drift, naming it, its live value and the pinned one" \
    "$(jv "$T/hard.json" hardening.state)|$(jv "$T/hard.json" hardening.drift.0.key)|$(jv "$T/hard.json" hardening.drift.0.live)|$(jv "$T/hard.json" hardening.drift.0.want)" "drift|kernel.yama.ptrace_scope|0|1"
chk "  and one this run cannot read is unread, not drift" "$(jv "$T/hard.json" hardening.unread)|$(jv "$T/hard.json" hardening.drift count)" "1|1"
hard "$T/no/_kernel_settings.sh"
chk "without _kernel_settings.sh beside it: unknown" "$(jv "$T/hard.json" hardening)" "unknown"
chk "  where it is looked for beside the script, by default" "$(grep -c 'pwd)/../install_tools/_kernel_settings.sh}"' "$SCRIPT")" "1"
chk "with no limits on the machine, a site's drops are none" "$(jv "$T/root.json" containers.0.outbound_dropped)" "none"

echo "=== Outbound limits (outbound_limits.sh) ==="
# The reader alone, pointed at a scratch status file and unit: both paths are
# fixed in the script. nft is a stub printing the counters as nft lists them.
eval "$(sed -n -e '/^json_str() {/,/^}/p' -e '/^safe_name() {/,/^}/p' "$SCRIPT")"
MAX_NAME=64
eval "$(grep '^MAX_SITES=' "$SCRIPT")"
eval "$(sed -n '/^LIMITS_STATE=""/,/^emit_outbound_limits() {/p' "$SCRIPT" | sed '$d')"
eval "$(sed -n '/^emit_outbound_limits() {/,/^}/p' "$SCRIPT")"
LS="$T/limits.status"; LU="$T/joinery-limits.service"
eval "$(declare -f limits_read | sed -e "s#/run/joinery/outbound_limits.status#$LS#" -e "s#/etc/systemd/system/joinery-limits.service#$LU#")"
cat > "$T/bin/nft" <<'STUB'
#!/bin/bash
echo "nft $*" >> "$GATE_NFT_LOG"
[ "$*" = "list counters table inet joinery_limits" ] || exit 2
[ -n "${GATE_NFT_FAIL:-}" ] && exit 1
printf 'table inet joinery_limits {\n\tcounter drops_site_siteone {\n\t\tpackets 12 bytes 720\n\t}\n\tcounter drops_web_user {\n\t\tpackets 3 bytes 180\n\t}\n\tcounter drops_site_my-site {\n\t\tpackets 7 bytes 420\n\t}\n}\n'
STUB
chmod 755 "$T/bin/nft"; export GATE_NFT_LOG="$T/nft.log"
lim_reset() { LIMITS_STATE=""; LIMITS_REASON=""; LIMITS_SINCE=""; LIMITS_SITES=""; LIMITS_WEB=""; LIMITS_COUNTS=""; LIMITS_COUNTS_READ=0; LIMITS_FILE_LINES=""; }
lim_obj() { lim_reset; PATH="$T/bin:$PATH" limits_read; emit_outbound_limits; }
lim_site() { lim_reset; PATH="$T/bin:$PATH" limits_read; limits_dropped "drops_site_$1" "$([[ "$LIMITS_SITES" == *" $1 "* ]] && echo 1 || echo 0)"; }
in_container() { return 1; }
rm -f "$LS" "$LU"
chk "no unit and no status: absent" "$(lim_obj)" '{"state":"absent","reason":"none","since":"none","web_user_dropped":"none"}'
touch "$LU"
chk "a unit with no status yet: unknown" "$(lim_obj)" '{"state":"unknown","reason":"none","since":"none","web_user_dropped":"none"}'
printf 'state=on\nreason=\nsince=1760000000\nsites=siteone sitetwo my-site\nuncovered=old\nweb_user=yes\n' > "$LS"
chk "on: its since and the web user's drops, read from nft" "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["state"], "/", $o["reason"], "/", $o["since"], "/", $o["web_user_dropped"];')" "on/none/1760000000/3"
chk "a status file from before the figures: each figure unknown or none, no site" \
    "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo json_encode($o["figures"]), "|", json_encode($o["sites"]), "|", $o["web_ceiling_mbit"];')" \
    '{"ceiling_mbit":"none","conn_rate":"unknown","conn_burst":"unknown","open_conns":"unknown","set_by":""}|[]|none'
printf 'state=on\nreason=\nsince=1760000000\nsites=siteone my-site\nweb_user=yes\nceiling_mbit=300\nconn_rate=40\nconn_burst=120\nopen_conns=512\nset_by=plane\nweb_ceiling_mbit=25\nsite_figures=siteone:50:40:120:512 my-site:-:5:100:256 bad"name:1:1:1:1\n' > "$LS"
chk "on with figures (WP5): the machine's, who set them, the web server's user's ceiling, each site's in force" \
    "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo json_encode($o["figures"]), "|", $o["web_ceiling_mbit"], "|", json_encode($o["sites"]);')" \
    '{"ceiling_mbit":300,"conn_rate":40,"conn_burst":120,"open_conns":512,"set_by":"plane"}|25|{"siteone":{"ceiling_mbit":50,"conn_rate":40,"conn_burst":120,"open_conns":512,"set_by":"plane"},"my-site":{"ceiling_mbit":"none","conn_rate":5,"conn_burst":100,"open_conns":256,"set_by":"plane"}}'
printf 'state=on\nreason=\nsince=1760000000\nsites=siteone my-site\nceiling_mbit=200\nconn_rate=20\nconn_burst=100\nopen_conns=256\nset_by=\nsite_figures=siteone:80:20:100:256 my-site:200:20:100:256\nsite_set_by=siteone\n' > "$LS"
chk "the machine's set on it, one site's own set from the management node (1.14): only that site says plane" \
    "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["figures"]["set_by"], "|", $o["sites"]["siteone"]["set_by"], "|", $o["sites"]["my-site"]["set_by"];')" "|plane|"
printf 'state=off\nreason=off\nsince=\nsites=\nceiling_mbit=300\nconn_rate=40\nconn_burst=100\nopen_conns=256\nset_by=\nsite_figures=siteone:50:40:100:256\nsite_set_by=\n' > "$LS"
chk "off (1.14): the figures turning them on brings back, and no since" \
    "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["state"], "|", $o["since"], "|", $o["figures"]["ceiling_mbit"], "|", $o["sites"]["siteone"]["ceiling_mbit"];')" "off|none|300|50"
printf 'state=off\nreason=off\nsince=\nsites=\nceiling_mbit=\nconn_rate=40\nset_by=\nsite_figures=\n' > "$LS"
chk "off from an older status (no site_set_by, its ceiling left empty): no figures, rather than none for the ceiling" \
    "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["state"], "|", array_key_exists("figures", $o) ? "figures" : "";')" "off|"
printf 'state=on\nsince=1760000000\nsites=\nsite_figures=%s\n' "$(for i in $(seq 1 120); do printf 'site%03d:50:40:120:512 ' "$i"; done)" > "$LS"
chk "a host's sites' figures are capped at 100, the first hundred" \
    "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo count($o["sites"]), "/", array_key_last($o["sites"]);')" "100/site100"
printf 'state=on\nreason=\nsince=1760000000\nsites=siteone sitetwo my-site\nuncovered=old\nweb_user=yes\n' > "$LS"
chk "a limited site's drops are its counter's packets" "$(lim_site siteone)" "12"
chk "a site still on Docker's default network is none, not zero" "$(lim_site old)" '"none"'
chk "a limited site whose counter nft does not list is unknown" "$(lim_site sitetwo)" '"unknown"'
chk "a site name with a hyphen finds its counter (nft lists it unquoted)" "$(lim_site my-site)" "7"
chk "a name that only begins another's is not matched" "$(lim_site site)" '"none"'
chk "counters that cannot be read (not root) are unknown" "$(GATE_NFT_FAIL=1 lim_site siteone)" '"unknown"'
printf 'state=on\nreason=nft_refused\nsince=1760000000\nsites=siteone\nweb_user=no\n' > "$LS"
chk "on with a reason (the last change refused, the table before it in force) carries the reason" "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["state"], "/", $o["reason"], "/", $o["since"];')" "on/nft_refused/1760000000"
printf 'state=on\nsince=1760000000\nsites=siteone\nweb_user=no\n' > "$LS"
chk "a machine whose sites are containers: the web user is none" "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["web_user_dropped"];')" "none"
printf 'state=refused\nreason=resolver_not_loopback\nsince=\nsites=siteone\n' > "$LS"
: > "$GATE_NFT_LOG"
chk "refused: the reason code, no since, and nft is not asked" "$(lim_obj)|$(wc -l < "$GATE_NFT_LOG")" '{"state":"refused","reason":"resolver_not_loopback","since":"none","web_user_dropped":"none"}|0'
chk "and a site under refused limits is none" "$(lim_site siteone)" '"none"'
printf 'state=refused\nreason=evil"code;$(reboot)\n' > "$LS"
chk "a reason is reduced to safe characters" "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["reason"];')" "evilcodereboot"
printf 'state=off\nsince=1\n' > "$LS"
chk "off: no since, no drops, no figures" "$(lim_obj)" '{"state":"off","reason":"none","since":"none","web_user_dropped":"none"}'
printf 'state=hacked\n' > "$LS"
chk "a state it does not know is unknown" "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["state"];')" "unknown"
printf 'state=on\nsince=soon\nsites=siteone\n' > "$LS"
chk "a since that is not a time is unknown" "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["since"];')" "unknown"
in_container() { return 0; }
chk "inside a container: none (its host's report says)" "$(lim_obj | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["state"];')" "none"
in_container() { return 1; }
chk "nft is only ever asked to list the counters" "$(grep -o 'run nft [a-z_ ]*' "$SCRIPT" | sort -u)" "run nft list counters table inet joinery_limits"

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

echo "=== 120 sites: the first 100 listed, one docker inspect, every site asked at once ==="
# Each site takes 2 s to answer: asked one after another, 100 would take 200 s,
# three times the minute the agent gives the report.
mkdir -p "$T/many"
cat > "$T/many/docker" <<'STUB'
#!/bin/bash
echo "docker $1" >> "$GATE_DOCKER_LOG"
case "$1 $2" in
    # Names at the 50-character limit, each read through a live process, so
    # every figure is a number: the largest entry a site can make.
    "ps -a") for i in $(seq 1 120); do printf 'site%03d-%s\n' "$i" "$(printf 'x%.0s' $(seq 1 42))"; done ;;
    "inspect -f")
        shift 3
        for c in "$@"; do echo "/$c|SITENAME=$c|running|healthy|$GATE_PID|2026-09-28T18:01:15.123456789Z|$(( 9000 + 10#${c:4:3} ))|"; done ;;
esac
STUB
cat > "$T/many/curl" <<'STUB'
#!/bin/bash
case "$*" in
    *127.0.0.1:9[0-9][0-9][0-9]/*) sleep 2; printf 'HTTP/1.1 200 OK\r\nX-Joinery-Version: 0.8.470\r\n\r\n' ;;
    *) exec "$GATE_BIN/curl" "$@" ;;
esac
STUB
chmod 755 "$T/many"/*
export GATE_DOCKER_LOG="$T/docker.log" GATE_BIN="$T/bin"; : > "$GATE_DOCKER_LOG"
start=$(date +%s)
PATH="$T/many:$T/bin:$PATH" HOST_REPORT_ETC="$T/etc" bash "$SCRIPT" > "$T/many.json" 2>/dev/null; rc=$?
elapsed=$(( $(date +%s) - start ))
chk "120 sites: exit 0, a JSON object" "$rc/$(jv "$T/many.json" "" type)" "0/object"
chk "120 sites: the first 100 are listed" "$(jv "$T/many.json" containers count)/$(jv "$T/many.json" containers.0.name | cut -c1-7)/$(jv "$T/many.json" containers.99.name | cut -c1-7)" "100/site001/site100"
chk "120 sites: each one answers" "$(php -r '$o=json_decode(file_get_contents($argv[1]),true); echo count(array_filter($o["containers"], fn($c) => $c["answers"] === "yes"));' "$T/many.json")" "100"
chk "120 sites: docker inspect runs once, for all of them" "$(grep -c '^docker inspect' "$GATE_DOCKER_LOG")" "1"
# Asked one after another, 100 would take 200 s; at once, the slowest. The bound
# is the agent's minute with room: a box busy with other runs adds seconds,
# never the 198 s a serial walk would.
chk "120 sites answering in 2 s each: the report took the slowest, not the sum, inside the agent's minute (${elapsed}s)" "$( [ "$elapsed" -lt 45 ]; echo $? )" "0"
# The largest a host's sites can make the report: 100 such entries, plus 100
# sites' outbound figures (122 bytes each at the longest name), must stay
# under the agent's 64 KiB with room for the rest of the report.
many_bytes=$(( $(wc -c < "$T/many.json") + 100 * 123 ))
chk "100 sites at the longest name, every figure a number: the report fits the agent's 64 KiB with 4 KiB to spare (${many_bytes} bytes)" "$( [ "$many_bytes" -lt 61440 ]; echo $? )" "0"

echo "=== The data root (a Docker host's disk pool), and an inspect that answers nothing ==="
# The data root is a filesystem of its own at /srv/joinery: its figures, which
# the root disk never shows (reviewer2 B2, specs/one_data_root.md WP1). Here a
# real directory stands in, with findmnt saying it is a mount.
mkdir -p "$T/pooldir" "$T/poolbin"
cat > "$T/poolbin/findmnt" <<STUB
#!/bin/bash
[ "\${@: -1}" = "$T/pooldir" ] && echo "xfs rw,relatime,prjquota"
exit 0
STUB
chmod 755 "$T/poolbin/findmnt"
eval "$(sed -n -e '/^json_str() {/,/^}/p' -e '/^safe_name() {/,/^}/p' -e '/^run() {/p' -e '/^emit_disk_pool() {/,/^}/p' "$SCRIPT")"
CMD_TIMEOUT=10
pool="$(PATH="$T/poolbin:$PATH" HOST_REPORT_DATA_ROOT="$T/pooldir" emit_disk_pool)"
chk "a data root: its own figures, xfs, with project quotas" \
    "$(php -r '$o=json_decode($argv[1],true); echo $o["fstype"], "/", var_export($o["prjquota"], true), "/", is_int($o["total_bytes"]) && is_int($o["avail_bytes"]) ? "figures" : "none";' "$pool")" "xfs/true/figures"
chk "no mount of its own: none" "$(PATH="$T/poolbin:$PATH" HOST_REPORT_DATA_ROOT="$T/elsewhere" HOST_REPORT_DATA_ROOT_CONF="$T/no_conf" emit_disk_pool)" '"none"'
printf 'backing=file\nsource=/srv/joinery.img\n' > "$T/dr_file"
printf 'backing=luks-device\nsource=/dev/disk/by-id/scsi-0Linode_Volume_mgr-b-vol\n' > "$T/dr_dev"
chk "on a device, which one (a volume's own encryption is the provider's layer under it); in a file, none" \
    "$(PATH="$T/poolbin:$PATH" HOST_REPORT_DATA_ROOT="$T/pooldir" HOST_REPORT_DATA_ROOT_CONF="$T/dr_dev" emit_disk_pool | php -r 'echo json_decode(stream_get_contents(STDIN), true)["device"];')|$(PATH="$T/poolbin:$PATH" HOST_REPORT_DATA_ROOT="$T/pooldir" HOST_REPORT_DATA_ROOT_CONF="$T/dr_file" emit_disk_pool | php -r 'echo json_decode(stream_get_contents(STDIN), true)["device"];')" \
    "/dev/disk/by-id/scsi-0Linode_Volume_mgr-b-vol|"
: > "$T/data_root_conf"
chk "declared and not mounted: not_mounted, never none (one_data_root WP4)" \
    "$(PATH="$T/poolbin:$PATH" HOST_REPORT_DATA_ROOT="$T/elsewhere" HOST_REPORT_DATA_ROOT_CONF="$T/data_root_conf" emit_disk_pool)" '"not_mounted"'
# A docker inspect that answers nothing (timed out) is unknown, never no sites.
cat > "$T/poolbin/docker" <<'STUB'
#!/bin/bash
[ "$1 $2" = "ps -a" ] && printf 'siteone\nsitetwo\n'
exit 1
STUB
chmod 755 "$T/poolbin/docker"
PATH="$T/poolbin:$T/bin:$PATH" bash "$SCRIPT" > "$T/noinspect.json" 2>/dev/null
chk "an inspect that answers nothing: containers unknown, not an empty list (N5)" "$(jv "$T/noinspect.json" containers)" "unknown"

echo "=== The nightly replacement of the site containers (host_report 1.31) ==="
eval "$(sed -n -e '/^safe_name() {/,/^}/p' -e '/^json_str() /p' -e '/^json_num_or_unknown() {/,/^}/p' -e '/^run() /p' \
    -e '/^json_text() {/,/^}/p' -e '/^emit_site_refresh() {/,/^}/p' "$SCRIPT")"
MAX_NAME=64; CMD_TIMEOUT=5
mkdir -p "$T/sr/bin" "$T/sr/state"
printf '#!/bin/bash\n[ "$1 $2 $3" = "show -p UnitFileState" ] && echo "${GATE_TIMER:-disabled}"\nexit 0\n' > "$T/sr/bin/systemctl"; chmod 755 "$T/sr/bin/systemctl"
sr() { PATH="$T/sr/bin:$PATH" HOST_REPORT_SITE_REFRESH_DIR="$1" emit_site_refresh; }
srv() { sr "$1" > "$T/sr.json"; jv "$T/sr.json" "$2" "${3:-value}"; }
chk "never run (no state): none" "$(sr "$T/sr/nothing")" '"none"'
cat > "$T/sr/state/state" <<'STATE'
installed_at=2026-10-10T01:00:00Z
checked_at=2026-10-17T01:40:00Z
checked_ok_at=2026-10-17T01:40:00Z
check=ok
check_error=
pending=10
pending_security=9
security_packages=apache2 curl Bad\Name openssh-client
base_version=2.2
base_id=88bf90ef691d
base_built_at=2026-10-17T01:42:00Z
base_due=fix
ran_at=2026-10-17T01:40:00Z
run=held
run_error=
replaced=sa:10.4 sb:junk
passed_over=wd5:busy
sites=3
sites_due=1
STATE
mkdir -p "$T/sr/state/held"
printf 'outcome=rolled_back\nreason=not "healthy" \\ in 180 s","x":"y\nat=2026-10-17T01:50:00Z\nbase=88bf90ef691d\n' > "$T/sr/state/held/wd5"
printf 'outcome=down\n' > "$T/sr/state/held/Bad;Name"
ln -s /etc/passwd "$T/sr/state/held/linked"
chk "a state: one JSON object, every key in order" "$(srv "$T/sr/state" "" keys)" \
    "installed_at,checked_at,checked_ok_at,check,check_error,base_version,base_id,base_built_at,base_due,ran_at,run,run_error,pending,pending_security,sites,sites_due,security_packages,replaced,passed_over,holds,scheduled"
chk "counts are numbers, the pending packages names only (a hostile one reduced to a name)" \
    "$(srv "$T/sr/state" pending)/$(srv "$T/sr/state" pending_security)/$(srv "$T/sr/state" security_packages)" '10/9/["apache2","curl","BadName","openssh-client"]'
chk "each replaced site with its seconds down; one not a figure is null" "$(srv "$T/sr/state" replaced)" '[{"site":"sa","down_seconds":10.4},{"site":"sb","down_seconds":null}]'
chk "a site passed over, and why" "$(srv "$T/sr/state" passed_over)" '[{"site":"wd5","why":"busy"}]'
chk "the holds come from held/ itself: one per site file; a name that is not a site's, and a link, are not read" \
    "$(srv "$T/sr/state" holds count)|$(srv "$T/sr/state" holds.0.site)" "1|wd5"
chk "a hold: a reason with quotes and a backslash stays text and cannot add a key" \
    "$(srv "$T/sr/state" holds.0 keys)|$(srv "$T/sr/state" holds.0.reason)" "site,outcome,reason,at,base|not healthy  in 180 s,x:y"
chk "the timer: scheduled only when systemd says enabled" "$(srv "$T/sr/state" scheduled)/$(GATE_TIMER=enabled srv "$T/sr/state" scheduled)" "false/true"
rm -f "$T/sr/state/held/wd5"
chk "a hold lifted (its file removed, the state not yet rewritten) is gone at once" "$(srv "$T/sr/state" holds)" "[]"
ln -s "$T/sr/state/state" "$T/sr/nothing/state" 2>/dev/null || { mkdir -p "$T/sr/nothing"; ln -s "$T/sr/state/state" "$T/sr/nothing/state"; }
chk "a state file that is a link is not read: none" "$(sr "$T/sr/nothing")" '"none"'

echo "=== The data root's own script, and the boot id (host_report 1.23) ==="
eval "$(sed -n -e '/^script_version() {/,/^}/p' -e '/^emit_data_root_script() {/,/^}/p' -e '/^emit_boot_id() {/,/^}/p' "$SCRIPT")"
MAX_LIST=20
mkdir -p "$T/drs/tree/maintenance_scripts/install_tools" "$T/drs/etc/systemd/system/postgresql.service.d" "$T/drs/bin"
printf '#!/bin/bash\n#\n# Version: 1.22 - x\n# Version: 1.21 - y\nCONSUMERS="postgresql.service docker.service apache2.service bad;unit.service"\n' \
    > "$T/drs/tree/maintenance_scripts/install_tools/joinery_data_root.sh"
printf '#!/bin/bash\n# Version: 1.21 - y\nCONSUMERS="postgresql.service"\n' > "$T/drs/sbin"
: > "$T/drs/etc/systemd/system/postgresql.service.d/joinery-data-root.conf"
cat > "$T/drs/bin/systemctl" <<'STUB'
#!/bin/bash
[ "$1" = "list-unit-files" ] && printf 'postgresql.service enabled enabled\napache2.service enabled enabled\n'
exit 0
STUB
chmod 755 "$T/drs/bin/systemctl"
printf 'backing=file\n' > "$T/drs/conf"
drs() { PATH="$T/drs/bin:$PATH" SITE_ROOT="$T/drs/tree" HOST_REPORT_ETC="$T/drs/etc" HOST_REPORT_DATA_ROOT_CONF="$1" HOST_REPORT_DATA_ROOT_SBIN="$2" emit_data_root_script; }
drs_v() { drs "$1" "$2" | php -r '$o=json_decode(stream_get_contents(STDIN),true); $u=[]; foreach ($o["consumers"] as $c) { $u[]=$c["unit"].":".($c["drop_in"]?"d":"-").($c["installed"]?"i":"-"); } echo $o["installed_version"], "/", $o["release_version"], "/", implode(",", $u);'; }
chk "the installed copy's version beside the release's, and each consumer's drop-in and unit; a hostile name reduced" \
    "$(drs_v "$T/drs/conf" "$T/drs/sbin")" "1.21/1.22/postgresql.service:di,docker.service:--,apache2.service:-i,badunit.service:--"
chk "no installed copy: none, not unknown" "$(drs_v "$T/drs/conf" "$T/drs/absent" | cut -d/ -f1)" "none"
chk "a host that declares no data root: none" "$(drs "$T/drs/no_conf" "$T/drs/sbin")" '"none"'
chk "the boot id is the kernel's" "$(jv "$T/root.json" boot_id)" "$(tr -cd '0-9a-f-' < /proc/sys/kernel/random/boot_id)"

echo "=== A data root stopped by hand (host_report 1.28, reviewer2 B17 D2) ==="
eval "$(sed -n -e '/^safe_name() {/,/^}/p' -e '/^json_str() {/,/^}/p' -e '/^emit_data_root_stopped() {/,/^}/p' "$SCRIPT")"
eval "$(grep '^MAX_NAME=' "$SCRIPT")"
drst() { HOST_REPORT_DATA_ROOT_CONF="$1" HOST_REPORT_DATA_ROOT_STOPPED="$2" emit_data_root_stopped; }
printf 'stopped by hand at 2026-10-16 17:02:03 UTC\npostgresql.service\nsyslog.socket\nbad;unit.service\nnot a unit\n' > "$T/drs/stopped"
chk "stopped by hand: when, in ISO UTC, and the units it stopped; a line that is no unit left out" \
    "$(drst "$T/drs/conf" "$T/drs/stopped" | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["at"], "|", implode(",", $o["services"]);')" \
    "2026-10-16T17:02:03Z|postgresql.service,syslog.socket"
chk "not stopped: null" "$(drst "$T/drs/conf" "$T/drs/absent")" "null"
chk "no data root declared: null, whatever lies in /run" "$(drst "$T/drs/no_conf" "$T/drs/stopped")" "null"
printf 'something else\npostgresql.service\n' > "$T/drs/odd"
chk "a record not in the form the stop writes: null" "$(drst "$T/drs/conf" "$T/drs/odd")" "null"
ln -s "$T/drs/stopped" "$T/drs/link"
chk "a symlink in its place: null" "$(drst "$T/drs/conf" "$T/drs/link")" "null"
chk "the real run carries the key (null here: nothing is stopped)" "$(jv "$T/real.json" data_root_stopped)" "null"

echo "=== Who named the break-glass copy's recovery key (host_report 1.35) ==="
eval "$(sed -n -e '/^json_num_or_unknown() {/,/^}/p' -e '/^emit_data_root_encryption() {/,/^}/p' "$SCRIPT")"
mkdir -p "$T/dre"
printf 'backing=luks-file\nsidecar=/nonexistent\n' > "$T/dre/conf"
dre() { printf 'public_key=x\nfingerprint=%s\nset_by=%s\n' "$(printf 'ab%.0s' {1..32})" "$1" > "$T/dre/recovery_recipient"
    HOST_REPORT_DATA_ROOT_CONF="$T/dre/conf" HOST_REPORT_DATA_ROOT_DIR="$T/dre" HOST_REPORT_MAPPER="$T/dre/none" emit_data_root_encryption \
        | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo is_array($o) ? strlen($o["recipient_fingerprint"]) . "|" . $o["recipient_set_by"] : "NOTJSON";'; }
chk "the key a management node named is marked as the operator's" "$(dre operator)" "64|operator"
chk "the key the install was given is marked as the install's" "$(dre install)" "64|install"
chk "anything but letters is dropped" "$(dre 'op\"erator;')" "64|operator"

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
chk "journalctl appears three times, each under the command timeout: the SSH read, the readability probe, which reads no line, and the event read" \
    "$(grep -c 'run journalctl --system' "$SCRIPT")|$(grep -c 'run journalctl --system -n 0 --no-pager -q >/dev/null' "$SCRIPT")|$(grep -c 'timeout "\$CMD_TIMEOUT" journalctl --system' "$SCRIPT")|$(grep -c 'journalctl' <<< "$(grep -v '^[[:space:]]*#' "$SCRIPT")")" "2|1|1|3"
# journalctl does the matching itself, so a day of log is never handed to the
# shell; and the read is the SYSTEM journal, because an ENOSPC line comes from
# the program that hit it and never from the kernel ring.
chk "the event read filters in journalctl (-g) over the system journal" "$(grep -c 'journalctl --system "\$@" --since "24 hours ago" --no-pager -o cat -q -g "\$pattern" 2>&3' "$SCRIPT")" "1"
chk "no kernel-ring read is left" "$(grep -c 'journalctl --system -k' "$SCRIPT")" "0"
chk "the list cap is 20" "$(grep -c '^MAX_LIST=20' "$SCRIPT")" "1"
chk "the site cap is 100" "$(grep -c '^MAX_SITES=100' "$SCRIPT")" "1"
# docker reruns a template that fails on its typed container against the raw
# JSON, where a never-started container has no Health key and drops out of the
# list (seen on docker 29.8, 2026-10-07). Indexing the port map by a string is
# what fails on the typed one.
chk "the inspect template never indexes the port map (it would fail on docker's typed container)" "$(grep '^INSPECT_FORMAT=' "$SCRIPT" | grep -c 'index .NetworkSettings')" "0"
chk "the environment is filtered to SITENAME inside docker" "$(grep -cF '0) "SITENAME"}}{{.}}{{end}}' "$SCRIPT")" "1"
chk "the name cap is 64" "$(grep -c '^MAX_NAME=64' "$SCRIPT")" "1"
chk "sshd is invoked once, read-only (-T)" "$(grep -o 'run sshd[^)]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "run sshd -T "
chk "openssl only reads: s_client and x509 -noout" "$(grep -o 'run openssl [a-z_0-9]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "run openssl s_client run openssl x509 "
chk "no engine verb but ps and inspect" "$(grep -o -E 'run (docker|"\$engine") [a-z]*' "$SCRIPT" | sed 's/^run [^ ]* //' | sort -u | tr '\n' ' ')" "inspect ps "
chk "and no docker by name: the engine is the one this host's sites run on" "$(grep -c -E 'run docker [a-z]' "$SCRIPT")" "0"
chk "du runs once, summarising (-s), under the command timeout" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c '\bdu ')/$(grep -c 'run du -s -B1 -- ' "$SCRIPT")" "1/1"
chk "a container's figures come from its cgroup, never from docker stats or exec" "$(grep -c -E '(docker|"\$engine") (stats|exec)' "$SCRIPT")" "0"
chk "curl is only ever asked for headers, body discarded" "$(grep -c 'run curl' "$SCRIPT")/$(grep 'run curl' "$SCRIPT" | grep -c -- '-o /dev/null -D -')" "3/3"
chk "no systemctl verb but show and list" "$(grep -o 'systemctl [a-z-]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "systemctl list-unit-files systemctl list-units systemctl show "
chk "no fail2ban-client verb but status" "$(grep -o 'fail2ban-client [a-z]\+' "$SCRIPT" | sort -u | tr '\n' ' ')" "fail2ban-client status "
chk "the release check is read from its cache, never run" "$(grep -c -E 'check-new-release -|do-release-upgrade -' "$SCRIPT")" "0"
chk "nothing writes: no tee, no redirect into /etc or /var, no rm, no mv" "$(grep -c -E '\btee\b|> */(etc|var)|\brm |\bmv |\bcp ' "$SCRIPT")" "0"
chk "exit 0 is the last thing it does" "$(tail -n 1 "$SCRIPT")" "exit 0"

echo
echo "host_report gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
