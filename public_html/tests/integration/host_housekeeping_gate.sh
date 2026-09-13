#!/bin/bash
# @joinery-test
# name: host_housekeeping
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/host_housekeeping.sh, public_html/includes/cloudflare_ip_ranges.txt]
#
# host_housekeeping.sh (specs/post_release_fleet_defects.md B2) run in its
# override mode against a temporary /etc as an unprivileged user: it writes
# both fail2ban drop-ins whole, removes a jail.local that is jail.conf plus an
# enabling block - install.sh's text or docker-prod's (the duplicate [sshd]
# fail2ban 1.0.2 refuses) - leaves a jail.local with any other content and
# warns when that one repeats a section, writes the Apache remoteip
# configuration from the one Cloudflare range list, writes the Apache jails
# ONLY when that configuration is in place (a jail on a log naming the peer
# would ban the edge), and a second run changes no file. Where fail2ban-client
# is installed, the drop-ins are also parsed by fail2ban itself.

set -u
SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$SITE_ROOT/maintenance_scripts/install_tools/host_housekeeping.sh"
RANGES="$SITE_ROOT/public_html/includes/cloudflare_ip_ranges.txt"
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

tree_sum() { (cd "$1" && find . \( -type f -o -type l \) | LC_ALL=C sort | while IFS= read -r f; do
    if [ -L "$f" ]; then printf '%s -> %s\n' "$f" "$(readlink "$f")"; else printf '%s %s\n' "$f" "$(md5sum < "$f" | cut -c1-32)"; fi
done | md5sum | cut -c1-32); }

legacy_block() { cat <<'EOF'

# Enable SSH protection
[sshd]
enabled = true

# Enable basic Apache protection
[apache-auth]
enabled = true

[apache-badbots]
enabled = true

[apache-noscript]
enabled = true

[apache-overflows]
enabled = true
EOF
}

echo "== the script exists, parses, and skips without root or an override =="
chk "parses" "$(bash -n "$SCRIPT" && echo ok)" "ok"
chk "not root and no override: skips with exit 0" "$(bash "$SCRIPT" >/dev/null 2>&1; echo $?)" "0"
chk "and writes nothing to /etc (says so)" "$(bash "$SCRIPT" 2>&1 | grep -c 'not root - skipping')" "1"

echo "== a host built by the old recipe =="
R="$T/host"
mkdir -p "$R/etc/fail2ban/jail.d" "$R/etc/apache2/conf-available"
# A jail.conf that already declares [sshd] and the apache sections, as the real one does.
printf '[DEFAULT]\nbantime = 10m\n\n[sshd]\nport = ssh\n\n[apache-auth]\nport = http,https\n' > "$R/etc/fail2ban/jail.conf"
{ cat "$R/etc/fail2ban/jail.conf"; legacy_block; } > "$R/etc/fail2ban/jail.local"

out="$(JOINERY_HOUSEKEEPING_ROOT="$R" bash "$SCRIPT" 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "runs no system command (says override mode)" "$(echo "$out" | grep -c 'override mode')" "1"
chk "writes jail.d/joinery-sshd.local" "$( [ -f "$R/etc/fail2ban/jail.d/joinery-sshd.local" ] && echo yes )" "yes"
chk "the sshd drop-in enables the jail once" "$(grep -c '^\[sshd\]' "$R/etc/fail2ban/jail.d/joinery-sshd.local")" "1"
chk "with the ban policy" "$(grep -cE '^(bantime = 1h|findtime = 10m|maxretry = 3)$' "$R/etc/fail2ban/jail.d/joinery-sshd.local")" "3"
chk "writes jail.d/joinery-apache.local (Apache is present)" "$( [ -f "$R/etc/fail2ban/jail.d/joinery-apache.local" ] && echo yes )" "yes"
chk "all four apache jails" "$(grep -cE '^\[apache-(auth|badbots|noscript|overflows)\]$' "$R/etc/fail2ban/jail.d/joinery-apache.local")" "4"
chk "each on the file backend (Debian defaults every jail to the journal)" "$(grep -c '^backend = auto$' "$R/etc/fail2ban/jail.d/joinery-apache.local")" "4"
chk "watching the site logs as well as /var/log/apache2" "$(grep -c '/var/www/html/\*/logs/' "$R/etc/fail2ban/jail.d/joinery-apache.local")" "8"
chk "including a Docker host's proxy vhost logs (proxy_access.log, proxy_error.log)" "$(grep -cE '/var/www/html/\*/logs/proxy_(access|error)\.log$' "$R/etc/fail2ban/jail.d/joinery-apache.local")" "4"
chk "the access-log jail reads the access logs" "$(sed -n '/^\[apache-badbots\]/,/^\[/p' "$R/etc/fail2ban/jail.d/joinery-apache.local" | grep -cE 'apache_access_log|/access\.log|/proxy_access\.log')" "3"
chk "removes the jail.local that was jail.conf plus our block" "$( [ ! -e "$R/etc/fail2ban/jail.local" ] && echo gone )" "gone"
chk "and says why" "$(echo "$out" | grep -c 'removed .*jail.local')" "1"
chk "jail.conf itself is untouched" "$(printf '[DEFAULT]\nbantime = 10m\n\n[sshd]\nport = ssh\n\n[apache-auth]\nport = http,https\n' | cmp -s - "$R/etc/fail2ban/jail.conf" && echo same)" "same"

echo "== the Apache side =="
CONF="$R/etc/apache2/conf-available/joinery-remoteip.conf"
chk "writes conf-available/joinery-remoteip.conf" "$( [ -f "$CONF" ] && echo yes )" "yes"
chk "enables it" "$(readlink "$R/etc/apache2/conf-enabled/joinery-remoteip.conf")" "../conf-available/joinery-remoteip.conf"
chk "reads X-Forwarded-For" "$(grep -c '^RemoteIPHeader X-Forwarded-For$' "$CONF")" "1"
want_ranges="$(grep -vE '^[[:space:]]*(#|$)' "$RANGES" | tr -d '[:blank:]')"
got_ranges="$(sed -n 's/^RemoteIPTrustedProxy //p' "$CONF")"
chk "trusts exactly the ranges in includes/cloudflare_ip_ranges.txt, in order" "$got_ranges" "$want_ranges"
chk "at least the fifteen IPv4 and seven IPv6 ranges Cloudflare publishes" "$( [ "$(echo "$got_ranges" | wc -l)" -ge 22 ] && echo yes )" "yes"
chk "no internal proxy on a bare-metal host (only the container has a bridge)" "$(grep -c '^RemoteIPInternalProxy' "$CONF")" "0"
chk "combined logs %a (the resolved client), not %h (the peer)" "$(grep -c '^LogFormat "%a %l %u %t' "$CONF")" "1"
chk "vhost_combined too" "$(grep -c '^LogFormat "%v:%p %a %l %u %t' "$CONF")" "1"
chk "and no format logs %h" "$(grep '^LogFormat' "$CONF" | grep -c '%h')" "0"

echo "== a second run changes no file =="
before="$(tree_sum "$R")"
out2="$(JOINERY_HOUSEKEEPING_ROOT="$R" bash "$SCRIPT" 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "tree checksum unchanged" "$(tree_sum "$R")" "$before"
chk "and nothing is reported written" "$(echo "$out2" | grep -c 'wrote\|removed')" "0"

echo "== a hand-edited jail.local is left alone =="
R2="$T/edited"
mkdir -p "$R2/etc/fail2ban" "$R2/etc/apache2/conf-available"
printf '[DEFAULT]\nbantime = 10m\n\n[sshd]\nport = ssh\n' > "$R2/etc/fail2ban/jail.conf"
{ cat "$R2/etc/fail2ban/jail.conf"; legacy_block; printf '\n[nginx-http-auth]\nenabled = true\n'; } > "$R2/etc/fail2ban/jail.local"
edited_sum="$(md5sum < "$R2/etc/fail2ban/jail.local")"
out3="$(JOINERY_HOUSEKEEPING_ROOT="$R2" bash "$SCRIPT" 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "jail.local still there" "$( [ -f "$R2/etc/fail2ban/jail.local" ] && echo yes )" "yes"
chk "byte for byte" "$(md5sum < "$R2/etc/fail2ban/jail.local")" "$edited_sum"
chk "and named as hand-edited in the transcript" "$(echo "$out3" | grep -c 'hand-edited')" "1"
chk "the drop-ins are still written beside it" "$( [ -f "$R2/etc/fail2ban/jail.d/joinery-sshd.local" ] && echo yes )" "yes"
chk "and the transcript warns that it repeats [sshd] (fail2ban will refuse it)" "$(echo "$out3" | grep -c 'declares \[sshd\] more than once')" "1"

echo "== docker-prod's jail.local: jail.conf plus a hardening block in other words =="
# The tail a hand appended on docker-prod: a comment and [sshd] with a ban
# policy. Not install.sh's text, but the same shape - a copy of jail.conf
# followed by nothing but section headers, enabled and a ban policy - and the
# same defect, a second [sshd].
R8="$T/dockerprod"
mkdir -p "$R8/etc/fail2ban" "$R8/etc/apache2/conf-available"
printf '[DEFAULT]\nbantime = 10m\n\n[sshd]\nport = ssh\n\n[apache-auth]\nport = http,https\n' > "$R8/etc/fail2ban/jail.conf"
{ cat "$R8/etc/fail2ban/jail.conf"; printf '\n# Joinery hardening\n[sshd]\nenabled = true\nbantime  = 3600\nfindtime = 600\nmaxretry = 5\n'; } > "$R8/etc/fail2ban/jail.local"
out8="$(JOINERY_HOUSEKEEPING_ROOT="$R8" bash "$SCRIPT" 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "removed" "$( [ ! -e "$R8/etc/fail2ban/jail.local" ] && echo gone )" "gone"
chk "and the transcript names what the block enabled" "$(echo "$out8" | grep -c 'removed .*jail.local: it was jail.conf with a block enabling sshd ')" "1"
chk "the sshd drop-in carries the policy instead" "$(grep -c '^\[sshd\]' "$R8/etc/fail2ban/jail.d/joinery-sshd.local")" "1"
# The same tail with one line that is not an enable/policy line is somebody's work.
R9="$T/dockerprod_edited"
mkdir -p "$R9/etc/fail2ban"
cp "$R8/etc/fail2ban/jail.conf" "$R9/etc/fail2ban/jail.conf"
{ cat "$R9/etc/fail2ban/jail.conf"; printf '\n# Joinery hardening\n[sshd]\nenabled = true\nmaxretry = 5\nignoreip = 203.0.113.7\n'; } > "$R9/etc/fail2ban/jail.local"
JOINERY_HOUSEKEEPING_ROOT="$R9" bash "$SCRIPT" >/dev/null 2>&1
chk "a block that also sets ignoreip stays" "$( [ -f "$R9/etc/fail2ban/jail.local" ] && echo yes )" "yes"
# A plain copy of jail.conf (no block appended) is also somebody's - or an older
# recipe's - and has no duplicate section; it stays.
R3="$T/plaincopy"
mkdir -p "$R3/etc/fail2ban"
printf '[DEFAULT]\nbantime = 10m\n\n[sshd]\nport = ssh\n' > "$R3/etc/fail2ban/jail.conf"
cp "$R3/etc/fail2ban/jail.conf" "$R3/etc/fail2ban/jail.local"
JOINERY_HOUSEKEEPING_ROOT="$R3" bash "$SCRIPT" >/dev/null 2>&1
chk "a plain copy of jail.conf stays" "$( [ -f "$R3/etc/fail2ban/jail.local" ] && echo yes )" "yes"

echo "== no Apache: no apache drop-in, and a stale one is removed =="
R4="$T/noapache"
mkdir -p "$R4/etc/fail2ban/jail.d"
printf '[sshd]\nport = ssh\n' > "$R4/etc/fail2ban/jail.conf"
printf '[apache-auth]\nenabled = true\n' > "$R4/etc/fail2ban/jail.d/joinery-apache.local"
out4="$(JOINERY_HOUSEKEEPING_ROOT="$R4" bash "$SCRIPT" 2>&1)"
chk "sshd drop-in written" "$( [ -f "$R4/etc/fail2ban/jail.d/joinery-sshd.local" ] && echo yes )" "yes"
chk "apache drop-in removed" "$( [ ! -e "$R4/etc/fail2ban/jail.d/joinery-apache.local" ] && echo gone )" "gone"
chk "no conf-available written" "$( [ ! -e "$R4/etc/apache2" ] && echo none )" "none"
chk "says the remoteip step was skipped" "$(echo "$out4" | grep -c 'remoteip step skipped')" "1"

echo "== inside a container: the bridge is an internal proxy, fail2ban is the host's =="
R5="$T/container"
mkdir -p "$R5/etc/apache2/conf-available"
touch "$R5/.dockerenv"
JOINERY_HOUSEKEEPING_ROOT="$R5" bash "$SCRIPT" >/dev/null 2>&1
chk "bridge and loopback are internal proxies" "$(grep -cE '^RemoteIPInternalProxy (172\.17\.0\.0/16|127\.0\.0\.1)$' "$R5/etc/apache2/conf-available/joinery-remoteip.conf")" "2"
chk "the edge ranges are trusted here too (the host appends to the chain)" "$(sed -n 's/^RemoteIPTrustedProxy //p' "$R5/etc/apache2/conf-available/joinery-remoteip.conf")" "$want_ranges"
chk "no fail2ban directory is created" "$( [ ! -e "$R5/etc/fail2ban" ] && echo none )" "none"

echo "== a missing range list is a failure, not a silent skip - and no Apache jail =="
# The Docker host hand run: Apache is there, no site tree is, so the range
# list is not where the script looks. Apache then logs the peer, which behind
# Cloudflare is an edge, so the Apache jails must not be enabled (they would
# ban the edge). A stale drop-in from a run that had the list is removed.
R6="$T/noranges"
mkdir -p "$R6/etc/apache2/conf-available" "$R6/etc/fail2ban/jail.d" "$T/fakesite/public_html/includes"
printf '[sshd]\nport = ssh\n' > "$R6/etc/fail2ban/jail.conf"
printf '[apache-auth]\nenabled = true\n' > "$R6/etc/fail2ban/jail.d/joinery-apache.local"
out6="$(JOINERY_HOUSEKEEPING_ROOT="$R6" bash "$SCRIPT" ignored "$T/fakesite" 2>&1)"; rc=$?
chk "exit 1" "$rc" "1"
chk "nothing written" "$( [ ! -e "$R6/etc/apache2/conf-available/joinery-remoteip.conf" ] && echo none )" "none"
chk "and the transcript names the missing file" "$(echo "$out6" | grep -c 'no Cloudflare range list')" "1"
chk "the sshd jail is still configured" "$( [ -f "$R6/etc/fail2ban/jail.d/joinery-sshd.local" ] && echo yes )" "yes"
chk "the Apache jails are not (the log would name the edge)" "$( [ ! -e "$R6/etc/fail2ban/jail.d/joinery-apache.local" ] && echo gone )" "gone"
chk "and the transcript says why" "$(echo "$out6" | grep -c 'removed jail.d/joinery-apache.local: Apache does not log the real client')" "1"

if command -v fail2ban-client >/dev/null 2>&1 && [ -d /etc/fail2ban/filter.d ]; then
    echo "== fail2ban itself parses the drop-ins (fail2ban-client -d) =="
    R7="$T/parse"
    mkdir -p "$R7/etc/apache2/conf-available"
    cp -r /etc/fail2ban "$R7/etc/fail2ban"
    rm -f "$R7/etc/fail2ban/jail.local"
    JOINERY_HOUSEKEEPING_ROOT="$R7" bash "$SCRIPT" >/dev/null 2>&1
    dump="$(fail2ban-client -c "$R7/etc/fail2ban" -d 2>&1)"; rc=$?
    chk "the configuration dumps (exit 0)" "$rc" "0"
    chk "the sshd jail is defined once with our policy" "$(echo "$dump" | grep -c "^\['set', 'sshd', 'maxretry', 3\]")" "1"
    chk "the apache jails are added on the file backend" "$(echo "$dump" | grep -cE "^\['add', 'apache-(auth|badbots|noscript|overflows)', 'auto'\]")" "4"
    chk "no configuration error" "$(echo "$dump" | grep -cE '^[0-9]{4}-[0-9]{2}-[0-9]{2} .* (ERROR|CRITICAL) ')" "0"
else
    echo "== fail2ban-client not installed here: the parse check is skipped =="
fi

echo
echo "host_housekeeping gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
