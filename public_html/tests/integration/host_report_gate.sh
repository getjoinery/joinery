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

KEYS="failed_units,expected_units,fail2ban_jails,ssh_auth_failures_24h,sshd,disk,memory,swap,reboot_required,unattended_upgrades_last_run,generated_at"

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
chk "memory figures are integers" "$(jv "$T/real.json" memory.used_bytes type)/$(jv "$T/real.json" memory.total_bytes type)" "integer/integer"
chk "swap figures are integers" "$(jv "$T/real.json" swap.used_bytes type)/$(jv "$T/real.json" swap.total_bytes type)" "integer/integer"
chk "reboot_required is a boolean" "$(jv "$T/real.json" reboot_required type)" "boolean"
chk "generated_at is now" "$( g=$(jv "$T/real.json" generated_at); n=$(date -u +%s); [ "$g" -le "$n" ] && [ "$g" -ge $((n-60)) ]; echo $? )" "0"

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
echo "passwordauthentication no"
echo "permitrootlogin prohibit-password"
echo "authorizedkeysfile .ssh/authorized_keys"
STUB
cat > "$T/bin/journalctl" <<'STUB'
#!/bin/bash
echo "Failed password for invalid user eve from 203.0.113.9 port 4444 ssh2"
echo "Invalid user eve from 203.0.113.9 port 4444"
echo "Accepted publickey for ops from 198.51.100.7 port 5555 ssh2"
echo "pam_unix(sshd:auth): authentication failure; logname= uid=0 euid=0 tty=ssh ruser= rhost=203.0.113.9  user=mallory"
echo "Failed password for mallory from 203.0.113.9 port 4445 ssh2"
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
chk "the banned IP list never reaches the object" "$(grep -c '203.0.113.9\|198.51.100' "$T/root.json")" "0"
chk "no username from the journal reaches the object" "$(grep -c 'eve\|mallory\|ops' "$T/root.json")" "0"
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
chk "the journal is only ever counted (grep -c), never printed" "$(grep -c 'grep -c -E' "$SCRIPT")" "1"
chk "journalctl appears once, under run" "$(grep -c 'run journalctl --system' "$SCRIPT")" "1"
chk "the list cap is 20" "$(grep -c '^MAX_LIST=20' "$SCRIPT")" "1"
chk "the name cap is 64" "$(grep -c '^MAX_NAME=64' "$SCRIPT")" "1"
chk "sshd is invoked once, read-only (-T)" "$(grep -o 'run sshd[^)]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "run sshd -T "
chk "no systemctl verb but show and list" "$(grep -o 'systemctl [a-z-]*' "$SCRIPT" | sort -u | tr '\n' ' ')" "systemctl list-unit-files systemctl list-units systemctl show "
chk "no fail2ban-client verb but status" "$(grep -o 'fail2ban-client [a-z]\+' "$SCRIPT" | sort -u | tr '\n' ' ')" "fail2ban-client status "
chk "nothing writes: no tee, no redirect into /etc or /var, no rm, no mv" "$(grep -c -E '\btee\b|> */(etc|var)|\brm |\bmv |\bcp ' "$SCRIPT")" "0"
chk "exit 0 is the last thing it does" "$(tail -n 1 "$SCRIPT")" "exit 0"

echo
echo "host_report gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
