#!/bin/bash
# @joinery-test
# name: web_workers
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/web_workers.sh]
#
# web_workers.sh is what the agent's web_workers observe word runs as root
# (specs/slow_request_diagnosis_words.md WP3). This gate pins its contract:
# with no site it reports apache and fpm as not reported; it asks only the
# site's own name pinned to loopback, never ?full; it reads the scoreboard and
# the pool's numbers and passes nothing else on; it reads the pool's ceiling
# from the active PHP version's pool files, last value winning; it counts the
# ceiling being reached in the last day from PHP-FPM's log, under mawk too;
# and a status page that does not answer is unknown, never zero.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/web_workers.sh"
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
jv() {
    php -r '
        $o = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($o)) { echo "NOTJSON"; exit; }
        $v = $o; if ($argv[2] !== "") { foreach (explode(".", $argv[2]) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "ABSENT"; exit; } $v = $v[$k]; } }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

R="$T/root"
mkdir -p "$R/etc/apache2/conf-enabled" "$R/etc/php/8.3/fpm/pool.d" "$R/etc/php/8.1/fpm/pool.d" "$R/var/log" "$T/bin"
touch "$R/etc/apache2/conf-enabled/php8.3-fpm.conf"
printf '[www]\npm = ondemand\npm.max_children = 5\n' > "$R/etc/php/8.3/fpm/pool.d/www.conf"
printf '[global]\nprocess_control_timeout = 10s\n[www]\npm.max_children = 9\n[other]\npm.max_children = 40\n' > "$R/etc/php/8.3/fpm/pool.d/zz-joinery-memory.conf"
printf '[www]\npm.max_children = 77\n' > "$R/etc/php/8.1/fpm/pool.d/www.conf"
now=$(date +'%d-%b-%Y %H:%M:%S'); old=$(date -d '30 hours ago' +'%d-%b-%Y %H:%M:%S')
printf '[%s] WARNING: [pool www] server reached pm.max_children setting (5), consider raising it\n' "$old" > "$R/var/log/php8.3-fpm.log.1"
printf '[%s] WARNING: [pool www] server reached pm.max_children setting (5), consider raising it\n[%s] WARNING: [pool www] server reached pm.max_children setting (5), consider raising it\n[%s] NOTICE: fpm is running\n' "$now" "$now" "$now" > "$R/var/log/php8.3-fpm.log"

# A curl that answers as the site vhost's two status pages would, and records
# every URL and --resolve it was asked.
cat > "$T/bin/curl" <<STUB
#!/bin/bash
echo "\$*" >> "$T/curl.log"
url="\${@: -1}"
case "\$url" in
    https://*'/server-status?auto') printf 'ServerVersion: Apache\nBusyWorkers: 7\nIdleWorkers: 0\nScoreboard: WWRRKK_C..\n200' ;;
    https://*'/fpm-status?json') printf '{"pool":"www","process manager":"ondemand","start time":1,"start since":600,"accepted conn":900,"listen queue":4,"max listen queue":12,"listen queue len":0,"idle processes":0,"active processes":9,"total processes":9,"max active processes":9,"max children reached":3,"slow requests":0,"request uri":"/secret?token=LEAK"}\n200' ;;
    *) printf 'nope\n404' ;;
esac
STUB
chmod 755 "$T/bin/curl"

echo "=== No site ==="
PATH="$T/bin:$PATH" WEB_WORKERS_ROOT="$R" bash "$SCRIPT" > "$T/none.json" 2>/dev/null; rc=$?
chk "exit 0, every key" "$rc/$(jv "$T/none.json" "" keys)" "0/site,apache,fpm,fpm_max_children_events_24h,generated_at"
chk "no site: apache and fpm not reported, nothing asked" "$(jv "$T/none.json" apache)/$(jv "$T/none.json" fpm)/$(test -e "$T/curl.log" && echo asked || echo none)" "not_reported/not_reported/none"
chk "a name that is not a host name is no site" "$(PATH="$T/bin:$PATH" WEB_WORKERS_ROOT="$R" bash "$SCRIPT" 'x.example --resolve evil' 2>/dev/null | php -r '$o=json_decode(stream_get_contents(STDIN),true); echo $o["apache"];')" "not_reported"

echo "=== A site ==="
PATH="$T/bin:$PATH" WEB_WORKERS_ROOT="$R" bash "$SCRIPT" mysite.example > "$T/site.json" 2>/dev/null
chk "Apache's busy and idle" "$(jv "$T/site.json" apache.busy)/$(jv "$T/site.json" apache.idle)/$(jv "$T/site.json" apache.slots)" "7/0/10"
chk "the scoreboard by state" "$(jv "$T/site.json" apache.scoreboard.writing)/$(jv "$T/site.json" apache.scoreboard.reading)/$(jv "$T/site.json" apache.scoreboard.keepalive)/$(jv "$T/site.json" apache.scoreboard.open_slot)" "2/2/2/2"
chk "PHP-FPM's pool numbers" "$(jv "$T/site.json" fpm.active)/$(jv "$T/site.json" fpm.listen_queue)/$(jv "$T/site.json" fpm.max_children_reached)/$(jv "$T/site.json" fpm.process_manager)" "9/4/3/ondemand"
chk "the ceiling from the active version's pool files, [www], last file wins" "$(jv "$T/site.json" fpm.max_children)" "9"
chk "nothing but the named numbers passes: no request URI" "$(grep -c -E 'LEAK|request uri|secret' "$T/site.json")" "0"
chk "ceiling reached in the last day, from the log and its rotation" "$(jv "$T/site.json" fpm_max_children_events_24h)" "2"
chk "only the site's own name, pinned to loopback" "$(grep -c -v -- '--resolve mysite.example:[0-9]*:127.0.0.1' "$T/curl.log")" "0"
chk "never ?full" "$(grep -c 'full' "$T/curl.log")" "0"

echo "=== Under mawk ==="
ln -s "$(command -v mawk || command -v awk)" "$T/bin/awk"
PATH="$T/bin:$PATH" WEB_WORKERS_ROOT="$R" bash "$SCRIPT" mysite.example > "$T/mawk.json" 2>/dev/null
chk "the same answer with mawk as awk" "$(jv "$T/mawk.json" apache.busy)/$(jv "$T/mawk.json" fpm.max_children)/$(jv "$T/mawk.json" fpm_max_children_events_24h)" "7/9/2"
rm -f "$T/bin/awk"

if [ "$(id -u)" != 0 ]; then
    chmod 000 "$R/var/log/php8.3-fpm.log"
    PATH="$T/bin:$PATH" WEB_WORKERS_ROOT="$R" bash "$SCRIPT" mysite.example > "$T/unread.json" 2>/dev/null
    chk "a log that is there and cannot be read counts unknown, never 0" "$(jv "$T/unread.json" fpm_max_children_events_24h)" "unknown"
    chmod 644 "$R/var/log/php8.3-fpm.log"
fi

echo "=== Status pages that do not answer ==="
cat > "$T/bin/curl" <<'STUB'
#!/bin/bash
printf 'gone\n503'
STUB
PATH="$T/bin:$PATH" WEB_WORKERS_ROOT="$R" bash "$SCRIPT" mysite.example > "$T/down.json" 2>/dev/null; rc=$?
chk "unknown, never zero, still exit 0" "$rc/$(jv "$T/down.json" apache)/$(jv "$T/down.json" fpm)" "0/unknown/unknown"

echo "=== Static pins ==="
chk "nothing writes" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E '\btee\b|>\s*/(etc|var|srv|usr|run)|\brm |\bmv |\bkill\b')" "0"
chk "no gawk-only function" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E 'strtonum|mktime|gensub|(^|[^_a-z])match\([^)]*,[^)]*,')" "0"

echo
echo "web_workers gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
