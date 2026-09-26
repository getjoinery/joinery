#!/bin/bash
# @joinery-test
# name: host_housekeeping
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/host_housekeeping.sh, maintenance_scripts/install_tools/_host_files.sh, public_html/includes/cloudflare_ip_ranges.txt]
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
#
# PostgreSQL answers only locally: a standalone server's pg_hba keeps its local
# and loopback rules and gains the listen drop-in; a container admits its
# gateway (the Docker host) and nothing else. A config/postgres_access.conf
# left behind is not read, and is named once.

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

echo "== --machine ROOT: the same work, from the agent's bundle =="
BROOT="$T/bundle"
mkdir -p "$BROOT/public_html/includes" "$BROOT/maintenance_scripts/install_tools"
cp "$RANGES" "$BROOT/public_html/includes/"
R="$T/machine"
mkdir -p "$R/etc/fail2ban/jail.d" "$R/etc/apache2/conf-available" "$R/etc/apache2/conf-enabled"
printf '[DEFAULT]\nbantime = 10m\n\n[sshd]\nport = ssh\n' > "$R/etc/fail2ban/jail.conf"
{ cat "$R/etc/fail2ban/jail.conf"; legacy_block; } > "$R/etc/fail2ban/jail.local"
out="$(JOINERY_HOUSEKEEPING_ROOT="$R" bash "$SCRIPT" --machine "$BROOT" 2>&1)"; rc=$?
chk "exit 0" "$rc" "0"
chk "writes the sshd drop-in" "$( [ -f "$R/etc/fail2ban/jail.d/joinery-sshd.local" ] && echo yes )" "yes"
chk "removes the copy-and-append jail.local" "$( [ -f "$R/etc/fail2ban/jail.local" ] && echo still || echo gone )" "gone"
chk "reads the Cloudflare ranges from the bundle (no 'no Cloudflare range list' warning)" "$(printf '%s\n' "$out" | grep -c 'no Cloudflare range list')" "0"
out="$(JOINERY_HOUSEKEEPING_ROOT="$R" bash "$SCRIPT" --machine "$T/absent-root" 2>&1)"; rc=$?
chk "--machine with no such root skips, exit 0" "$rc:$(printf '%s\n' "$out" | grep -c -- '--machine needs the bundle root')" "0:1"
chk "the flag is the first argument and the root the second" "$(grep -c '^if \[\[ "\${1:-}" == "--machine" \]\]; then$' "$SCRIPT")" "1"

echo "=== Host files: written when absent, never over an owner's edit ==="
RH="$T/hostfiles"
mkdir -p "$RH/etc/apache2/mods-available" "$RH/etc/php/8.3/fpm" "$RH/etc/php/8.1/fpm" "$RH/usr/lib/php/8.3"
printf 'upload_max_filesize = 2M\nmemory_limit = 128M\n;date.timezone =\n;extension=pdo_pgsql\n' > "$RH/usr/lib/php/8.3/php.ini-production"
printf 'memory_limit = 999M\n' > "$RH/etc/php/8.1/fpm/php.ini"
out="$(JOINERY_HOUSEKEEPING_ROOT="$RH" bash "$SCRIPT" 2>&1)"
chk "mpm_event.conf absent: written" "$(grep -c '^MaxRequestWorkers       50$' "$RH/etc/apache2/mods-available/mpm_event.conf" 2>/dev/null)" "1"
chk "the journal cap absent: written" "$(grep -c '^SystemMaxUse=100M$' "$RH/etc/systemd/journald.conf.d/size-limit.conf" 2>/dev/null)" "1"
chk "php.ini absent: rebuilt from php.ini-production and tuned" "$(grep -cE '^(upload_max_filesize = 32M|date.timezone = UTC)$' "$RH/etc/php/8.3/fpm/php.ini" 2>/dev/null)" "2"
chk "the tuning leaves the PostgreSQL extensions to conf.d" "$(grep -c '^;extension=pdo_pgsql$' "$RH/etc/php/8.3/fpm/php.ini" 2>/dev/null)" "1"
chk "a php.ini that exists is never touched" "$(cat "$RH/etc/php/8.1/fpm/php.ini")" "memory_limit = 999M"
# A new PHP version arrives with its php.ini an untouched copy of the template.
mkdir -p "$RH/etc/php/8.5/fpm" "$RH/usr/lib/php/8.5"
printf 'upload_max_filesize = 2M\nmemory_limit = 128M\n;date.timezone =\n;extension=pdo_pgsql\n' > "$RH/usr/lib/php/8.5/php.ini-production"
cp "$RH/usr/lib/php/8.5/php.ini-production" "$RH/etc/php/8.5/fpm/php.ini"
# An owner's edit on a version that has a template must survive.
printf 'upload_max_filesize = 64M\nmemory_limit = 128M\n;date.timezone =\n;extension=pdo_pgsql\n' > "$RH/etc/php/8.3/fpm/php.ini"
out="$(JOINERY_HOUSEKEEPING_ROOT="$RH" bash "$SCRIPT" 2>&1)"
chk "a php.ini identical to the packaged template is tuned" "$(grep -cE '^(upload_max_filesize = 32M|date.timezone = UTC)$' "$RH/etc/php/8.5/fpm/php.ini" 2>/dev/null)" "2"
chk "and says so" "$(printf '%s\n' "$out" | grep -c 'tuned .*8.5/fpm/php.ini: it was the packaged php.ini-production')" "1"
chk "an owner's edit beside a template is never touched" "$(grep -c '^upload_max_filesize = 64M$' "$RH/etc/php/8.3/fpm/php.ini")" "1"
before="$(tree_sum "$RH")"
out="$(JOINERY_HOUSEKEEPING_ROOT="$RH" bash "$SCRIPT" 2>&1)"
chk "a second converge changes no php.ini and says nothing about one" "$( [ "$(tree_sum "$RH")" = "$before" ] && echo same):$(printf '%s\n' "$out" | grep -c 'php.ini')" "same:0"
printf 'nothing the tuning names\n' > "$RH/usr/lib/php/8.5/php.ini-production"
cp "$RH/usr/lib/php/8.5/php.ini-production" "$RH/etc/php/8.5/fpm/php.ini"
out="$(JOINERY_HOUSEKEEPING_ROOT="$RH" bash "$SCRIPT" 2>&1)"
chk "a template the tuning cannot change is not announced as tuned (no restart a minute)" "$(printf '%s\n' "$out" | grep -c 'tuned .*8.5')" "0"
rm -rf "$RH/etc/php/8.5" "$RH/usr/lib/php/8.5"
# The extension lines the tuning used to write: commented back out only where
# conf.d loads the same module, and nothing else in the file moves.
mkdir -p "$RH/etc/php/8.3/fpm/conf.d"
printf 'upload_max_filesize = 64M\nextension=pdo_pgsql\nextension=pgsql\n' > "$RH/etc/php/8.3/fpm/php.ini"
: > "$RH/etc/php/8.3/fpm/conf.d/20-pdo_pgsql.ini"
out="$(JOINERY_HOUSEKEEPING_ROOT="$RH" bash "$SCRIPT" 2>&1)"
chk "a php.ini loading pdo_pgsql that conf.d also loads has the line commented out" "$(grep -c '^;extension=pdo_pgsql$' "$RH/etc/php/8.3/fpm/php.ini")" "1"
chk "pgsql, which conf.d does not load here, is left enabled" "$(grep -c '^extension=pgsql$' "$RH/etc/php/8.3/fpm/php.ini")" "1"
chk "and the owner's own setting beside it is untouched" "$(grep -c '^upload_max_filesize = 64M$' "$RH/etc/php/8.3/fpm/php.ini")" "1"
chk "and it says so" "$(printf '%s\n' "$out" | grep -c 'stopped loading pdo_pgsql/pgsql a second time')" "1"
before="$(tree_sum "$RH")"
JOINERY_HOUSEKEEPING_ROOT="$RH" bash "$SCRIPT" >/dev/null 2>&1
chk "a second converge changes nothing" "$( [ "$(tree_sum "$RH")" = "$before" ] && echo same)" "same"
rm -rf "$RH/etc/php/8.3/fpm/conf.d"
printf 'MaxRequestWorkers 400\n' > "$RH/etc/apache2/mods-available/mpm_event.conf"
printf '[Journal]\nSystemMaxUse=2G\n' > "$RH/etc/systemd/journald.conf.d/size-limit.conf"
JOINERY_HOUSEKEEPING_ROOT="$RH" bash "$SCRIPT" >/dev/null 2>&1
chk "an owner's mpm_event.conf survives a converge" "$(cat "$RH/etc/apache2/mods-available/mpm_event.conf")" "MaxRequestWorkers 400"
chk "an owner's journal cap survives a converge" "$(grep -c 'SystemMaxUse=2G' "$RH/etc/systemd/journald.conf.d/size-limit.conf")" "1"
rm -f "$RH/etc/php/8.1/fpm/php.ini"
out="$(JOINERY_HOUSEKEEPING_ROOT="$RH" bash "$SCRIPT" 2>&1)"; rc=$?
chk "a php.ini with no template to rebuild from is named, and does not fail the run" "$rc:$(printf '%s\n' "$out" | grep -c 'no .*php.ini-production to rebuild it from')" "0:1"
RJ="$T/journal-owner"; mkdir -p "$RJ/etc/systemd/journald.conf.d"
printf '[Journal]\nSystemMaxUse=200M\n' > "$RJ/etc/systemd/journald.conf.d/size-cap.conf"
JOINERY_HOUSEKEEPING_ROOT="$RJ" bash "$SCRIPT" >/dev/null 2>&1
chk "an owner's own journal cap under another name is respected: ours is not added" "$([ -e "$RJ/etc/systemd/journald.conf.d/size-limit.conf" ] && echo added || echo absent)" "absent"
chk "install.sh uses the same definitions" "$(grep -c 'host_files_write_mpm_event\|host_files_write_journald_limit\|host_files_tune_php_ini' "$SITE_ROOT/maintenance_scripts/install_tools/install.sh")" "3"

echo "=== certbot's renewal configs: every Apache lineage on the machine, whatever its name ==="
# specs/fleet_ubuntu_2604_postgres_upgrade.md B23: demo.getjoinery.com is no
# site's vhost file name or DOMAIN_NAME, and on a Docker host nothing rendered
# its vhost, so its lineage kept letting certbot edit the vhost.
RL="$T/renewals"; mkdir -p "$RL/etc/letsencrypt/renewal"
printf 'version = 2.9.0\n\n[renewalparams]\naccount = abc\nauthenticator = apache\ninstaller = apache\n' > "$RL/etc/letsencrypt/renewal/demo.getjoinery.com.conf"
printf 'version = 2.9.0\n\n[renewalparams]\nauthenticator = dns-cloudflare\ninstaller = None\n' > "$RL/etc/letsencrypt/renewal/dns.example.com.conf"
printf 'version = 2.9.0\n\n[renewalparams]\nauthenticator = apache\ninstaller = apache\nrenew_hook = /usr/local/bin/mine\n' > "$RL/etc/letsencrypt/renewal/mine.example.com.conf"
cp "$RL/etc/letsencrypt/renewal/dns.example.com.conf" "$T/dns.before"
out="$(JOINERY_HOUSEKEEPING_ROOT="$RL" bash "$SCRIPT" 2>&1)"; rc=$?
chk "a lineage under a name no site carries: installer = None" "$(grep -c '^installer = None$' "$RL/etc/letsencrypt/renewal/demo.getjoinery.com.conf")" "1"
chk "with a renew_hook that reloads Apache" "$(grep -c '^renew_hook = systemctl reload apache2$' "$RL/etc/letsencrypt/renewal/demo.getjoinery.com.conf")" "1"
chk "an owner's renew_hook is kept, and only the installer changes" "$(grep -cE '^(installer = None|renew_hook = /usr/local/bin/mine)$' "$RL/etc/letsencrypt/renewal/mine.example.com.conf"):$(grep -c '^renew_hook' "$RL/etc/letsencrypt/renewal/mine.example.com.conf")" "2:1"
chk "a lineage issued another way (DNS) is not touched" "$(cmp -s "$T/dns.before" "$RL/etc/letsencrypt/renewal/dns.example.com.conf" && echo same)" "same"
chk "the run says so for each lineage it changed, and succeeds" "$rc:$(printf '%s\n' "$out" | grep -c 'housekeeping: renewal: .* says installer = None')" "0:2"
chk "the originals are kept beside them under names certbot does not read" "$(ls "$RL/etc/letsencrypt/renewal" | grep -c '\.conf\.before-render\.')" "2"
before="$(tree_sum "$RL")"
out="$(JOINERY_HOUSEKEEPING_ROOT="$RL" bash "$SCRIPT" 2>&1)"
chk "a second converge changes nothing and says nothing about renewals" "$( [ "$(tree_sum "$RL")" = "$before" ] && echo same):$(printf '%s\n' "$out" | grep -c 'renewal:')" "same:0"
chk "render_vhost.sh no longer defines or runs its own copy of the rule" "$(grep -cE '^[[:space:]]*heal_renewal_conf(\(\)| )' "$SITE_ROOT/maintenance_scripts/install_tools/render_vhost.sh")" "0"

echo "=== PostgreSQL answers only locally ==="
mkpg() {  # $1 root, $2 listen_addresses, $3 pg_hba body
    mkdir -p "$1/etc/postgresql/16/main/conf.d"
    printf "data_directory = '/var/lib/postgresql/16/main'\nlisten_addresses = '%s'\ninclude_dir = 'conf.d'\n" "$2" > "$1/etc/postgresql/16/main/postgresql.conf"
    printf '%b' "$3" > "$1/etc/postgresql/16/main/pg_hba.conf"
}
LOCAL_HBA='local   all             postgres                                scram-sha-256\nlocal   all             all                                     md5\nhost    all             all             127.0.0.1/32            md5\nhost    all             all             ::1/128                 md5\nlocal   replication     all                                     peer\nhost    replication     all             127.0.0.1/32            md5\n'
PG="$T/pg-local"; mkpg "$PG" localhost "$LOCAL_HBA"; cp "$PG/etc/postgresql/16/main/pg_hba.conf" "$T/pg-local.hba"
out="$(JOINERY_HOUSEKEEPING_ROOT="$PG" bash "$SCRIPT" x "$T/pg-local-site" 2>&1)"
chk "a standalone server already local keeps its rules byte for byte" "$(cmp -s "$T/pg-local.hba" "$PG/etc/postgresql/16/main/pg_hba.conf" && echo same)" "same"
chk "and gains the listen drop-in" "$(cat "$PG/etc/postgresql/16/main/conf.d/99-joinery-local-only.conf" 2>/dev/null | tail -1)" "listen_addresses = 'localhost'"
chk "without a restart, since it was already listening on localhost" "$(printf '%s\n' "$out" | grep -c 'restarting it')" "0"

PG="$T/pg-open"; mkpg "$PG" '*' 'local   all             postgres                                md5\nhost    all             all             127.0.0.1/32            md5\nhost    all             all             0.0.0.0/0               md5\nhost    all             all             ::/0                    md5\ninclude_dir extra.d\n'
out="$(JOINERY_HOUSEKEEPING_ROOT="$PG" bash "$SCRIPT" x "$T/pg-open-site" 2>&1)"
chk "an open standalone server loses every network rule and include" "$(grep -cE '0\.0\.0\.0/0|::/0|include_dir' "$PG/etc/postgresql/16/main/pg_hba.conf")" "0"
chk "and keeps its local and loopback rules" "$(grep -cE '^(local|host +all +all +127\.0\.0\.1/32)' "$PG/etc/postgresql/16/main/pg_hba.conf")" "2"
chk "the original is kept beside it" "$(grep -c '0\.0\.0\.0/0' "$PG/etc/postgresql/16/main/pg_hba.conf.pre-local-only")" "1"
chk "and PostgreSQL is restarted onto localhost" "$(printf '%s\n' "$out" | grep -c "was listening on '\*': restarting it on localhost only")" "1"

PG="$T/pg-ctr"; mkpg "$PG" '*' 'local   all             postgres                                md5\nlocal   all             all                                     md5\nhost    all             all             127.0.0.1/32            md5\nhost    all             all             0.0.0.0/0               md5\nhost    all             all             ::1/128                 md5\n# joinery-local-only: declared in config/postgres_access.conf\nhost    scrolldaddy     scrolldaddy_reader 192.168.206.21/32       md5\n'
touch "$PG/.dockerenv"; mkdir -p "$PG/proc/net" "$T/pg-ctr-site/config"
printf 'Iface\tDestination\tGateway \tFlags\tRefCnt\tUse\tMetric\tMask\t\tMTU\tWindow\tIRTT\neth0\t00000000\t010011AC\t0003\t0\t0\t0\t00000000\t0\t0\t0\n' > "$PG/proc/net/route"
# The file a site used to declare a network reader in: left behind, it is read by nothing.
printf 'host scrolldaddy scrolldaddy_reader 192.168.206.21/32 md5\npublish 192.168.206.198\n' > "$T/pg-ctr-site/config/postgres_access.conf"
out="$(JOINERY_HOUSEKEEPING_ROOT="$PG" bash "$SCRIPT" x "$T/pg-ctr-site" 2>&1)"
HBA="$PG/etc/postgresql/16/main/pg_hba.conf"
chk "a container loses the network-wide rule" "$(grep -c '0\.0\.0\.0/0' "$HBA")" "0"
chk "and admits the Docker host, its gateway, with the image's own method" "$(grep -cE '^host +all +all +172\.17\.0\.1/32 +md5$' "$HBA")" "1"
chk "and nothing else from the network: a once-declared reader line is removed" "$(grep -c 'scrolldaddy_reader\|declared' "$HBA")" "0"
chk "a leftover postgres_access.conf is not read, and is named once" "$(printf '%s\n' "$out" | grep -c 'postgres_access.conf is not read')" "1"
chk "the container keeps listening on its interface (how the host reaches it)" "$([ -e "$PG/etc/postgresql/16/main/conf.d/99-joinery-local-only.conf" ] && echo pinned || echo untouched)" "untouched"
before="$(tree_sum "$PG")"
out="$(JOINERY_HOUSEKEEPING_ROOT="$PG" bash "$SCRIPT" x "$T/pg-ctr-site" 2>&1)"
chk "a second start changes nothing" "$( [ "$(tree_sum "$PG")" = "$before" ] && echo same):$(printf '%s\n' "$out" | grep -c 'answers only locally now')" "same:0"
rm -f "$PG/proc/net/route"
out="$(JOINERY_HOUSEKEEPING_ROOT="$PG" bash "$SCRIPT" x "$T/pg-ctr-site" 2>&1)"
chk "no default route: the host is not admitted, and says so" "$(grep -c '172\.17\.0\.1' "$HBA"):$(printf '%s\n' "$out" | grep -c 'so the Docker host is not admitted')" "0:1"

# Dev's own shape: postgresql.conf says '*', ALTER SYSTEM says localhost - the
# running server is local already, so nothing restarts.
PG="$T/pg-auto-local"; mkpg "$PG" '*' "$LOCAL_HBA"; mkdir -p "$PG/var/lib/postgresql/16/main"
printf "listen_addresses = 'localhost'\n" > "$PG/var/lib/postgresql/16/main/postgresql.auto.conf"
out="$(JOINERY_HOUSEKEEPING_ROOT="$PG" bash "$SCRIPT" x "$T/pg-auto-local-site" 2>&1)"
chk "ALTER SYSTEM already saying localhost: drop-in written, no restart, no warning" "$([ -f "$PG/etc/postgresql/16/main/conf.d/99-joinery-local-only.conf" ] && echo written):$(printf '%s\n' "$out" | grep -c 'restarting it'):$(printf '%s\n' "$out" | grep -c 'ALTER SYSTEM set')" "written:0:0"
PG="$T/pg-auto-open"; mkpg "$PG" localhost "$LOCAL_HBA"; mkdir -p "$PG/var/lib/postgresql/16/main"
printf "listen_addresses = '*'\n" > "$PG/var/lib/postgresql/16/main/postgresql.auto.conf"
out="$(JOINERY_HOUSEKEEPING_ROOT="$PG" bash "$SCRIPT" x "$T/pg-auto-open-site" 2>&1)"; rc=$?
chk "ALTER SYSTEM saying '*' outranks the drop-in: named, and the run fails" "$rc:$(printf '%s\n' "$out" | grep -c "ALTER SYSTEM set listen_addresses = '\*'")" "1:1"

PG="$T/pg-bm-access"; mkpg "$PG" localhost "$LOCAL_HBA"; mkdir -p "$T/pg-bm-site/config"
printf 'host scrolldaddy scrolldaddy_reader 192.168.206.21/32 md5\n' > "$T/pg-bm-site/config/postgres_access.conf"
out="$(JOINERY_HOUSEKEEPING_ROOT="$PG" bash "$SCRIPT" x "$T/pg-bm-site" 2>&1)"
chk "a standalone server does not read an access file either, and names it once" "$(grep -c scrolldaddy_reader "$PG/etc/postgresql/16/main/pg_hba.conf"):$(printf '%s\n' "$out" | grep -c 'postgres_access.conf is not read')" "0:1"

echo
echo "host_housekeeping gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
