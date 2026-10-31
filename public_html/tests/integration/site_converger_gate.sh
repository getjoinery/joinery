#!/bin/bash
# @joinery-test
# name: site_converger
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/site_converger.sh]
#
# site_converger.sh (the site_converger observe word) driven against a stub
# container engine whose site mounts temporary volumes: it names only a
# container whose SITENAME is its name, reads the last-run record, the stamp,
# the code check and the converger log from the site's own volumes, refuses
# a link the site planted in any of them, never reads a bind mount, clamps
# the line count, keeps every key when a fact cannot be read, and carries a
# hostile log line as valid JSON.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/site_converger.sh"
T=$(mktemp -d)
sleep 300 & SLEEPER=$!
trap 'kill "$SLEEPER" 2>/dev/null; rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

# jv FILE PATH: a value from the object, a.b for nested keys.
jv() {
    php -r '
        $o = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($o)) { echo "NOTJSON"; exit; }
        if ($argv[2] === "") { echo implode(",", array_keys($o)); exit; }
        $v = $o;
        foreach (explode(".", $argv[2]) as $k) { $v = is_array($v) && array_key_exists($k, $v) ? $v[$k] : "ABSENT"; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_array($v) ? count($v) : $v);
    ' "$1" "$2"
}

V="$T/volumes"
mkdir -p "$T/bin" "$V/siteone_cache/_data" "$V/siteone_logs/_data" "$V/siteone_config/_data" "$T/elsewhere"
SECRET="$T/elsewhere/secret"; echo 'not the site' > "$SECRET"
cat > "$T/bin/docker" <<STUB
case "\$1" in
    ps) printf 'siteone\nimpostor\npostgres\n' ;;
    inspect)
        f="\$3"; c="\$4"
        case "\$f" in
            *Config.Env*) [ "\$c" = siteone ] && printf 'PATH=/usr/bin\nSITENAME=siteone\n'
                          [ "\$c" = impostor ] && printf 'SITENAME=someoneelse\n' ;;
            *State.Status*) echo running ;;
            *State.Pid*) echo "$SLEEPER" ;;
            *Mounts*) printf '/var/www/html/siteone/cache|$V/siteone_cache/_data\n'
                      printf "/var/www/html/siteone/logs|\${STUB_LOGS_SRC:-$V/siteone_logs/_data}\\n"
                      printf '/var/www/html/siteone/config|$V/siteone_config/_data\n' ;;
        esac ;;
esac
STUB
chmod 755 "$T/bin/docker"
export PATH="$T/bin:$PATH"
export JOINERY_SITE_ENGINE_KIND=docker

echo '1793065577 converged' > "$V/siteone_cache/_data/host_converger.last"
echo 'abc123' > "$V/siteone_cache/_data/host_converger.stamp"
printf 'ok 2026-10-27T01:46:30Z\n' > "$V/siteone_config/_data/code_check"
for i in $(seq 1 250); do echo "line $i"; done > "$V/siteone_logs/_data/host_converger.log"
printf 'a "quoted" back\\slash\ttab\001ctl end\n' >> "$V/siteone_logs/_data/host_converger.log"

echo "== the site's own facts =="
bash "$SCRIPT" siteone 5 > "$T/o.json" 2> "$T/e"; rc=$?
chk "exits 0 with an object" "$rc/$(jv "$T/o.json" site)" "0/siteone"
chk "every key is there" "$(jv "$T/o.json" '')" "site,state,now_unix,last_run,stamp_present,code_check,cron,log"
chk "the last run's time" "$(jv "$T/o.json" last_run.at_unix)" "1793065577"
chk "and how it ended" "$(jv "$T/o.json" last_run.outcome)" "converged"
chk "the stamp is there" "$(jv "$T/o.json" stamp_present)" "true"
chk "the code check" "$(jv "$T/o.json" code_check.state)/$(jv "$T/o.json" code_check.at)" "ok/2026-10-27T01:46:30Z"
chk "the cron facts are keyed" "$(php -r 'echo implode(",", array_keys(json_decode(file_get_contents($argv[1]), true)["cron"]));' "$T/o.json")" "entry_present,entry_runs_this_site,entry_mtime_unix,folder_mtime_unix,cron_running"
chk "the log is there" "$(jv "$T/o.json" log.present)" "true"
chk "five lines asked, five returned" "$(jv "$T/o.json" log.lines_requested)/$(jv "$T/o.json" log.lines_returned)/$(jv "$T/o.json" log.lines)" "5/5/5"
chk "the last line, hostile text made valid" "$(php -r '$o=json_decode(file_get_contents($argv[1]),true); echo end($o["log"]["lines"]);' "$T/o.json")" 'a "quoted" back\slash tabctl end'

echo "== the line count is clamped =="
bash "$SCRIPT" siteone 100000 > "$T/o.json" 2>/dev/null
chk "200 at most" "$(jv "$T/o.json" log.lines_requested)/$(jv "$T/o.json" log.lines_returned)" "200/200"
bash "$SCRIPT" siteone 0 > "$T/o.json" 2>/dev/null
chk "1 at least" "$(jv "$T/o.json" log.lines_returned)" "1"
bash "$SCRIPT" siteone 'x;y' > "$T/o.json" 2>/dev/null
chk "not a number reads as the default" "$(jv "$T/o.json" log.lines_requested)" "100"

echo "== only a site container on this host =="
for bad in impostor postgres nosuch '../siteone' 'Siteone' ''; do
    out="$(bash "$SCRIPT" "$bad" 5 2>&1)"; rc=$?
    chk "'$bad' is refused, nothing printed to stdout" "$rc/$(bash "$SCRIPT" "$bad" 5 2>/dev/null | wc -c)" "2/0"
done

echo "== a link the site planted is never followed =="
mv "$V/siteone_cache/_data/host_converger.last" "$T/last.real"
ln -s "$SECRET" "$V/siteone_cache/_data/host_converger.last"
rm "$V/siteone_logs/_data/host_converger.log"
ln -s "$SECRET" "$V/siteone_logs/_data/host_converger.log"
rm "$V/siteone_config/_data/code_check"
ln -s "$SECRET" "$V/siteone_config/_data/code_check"
bash "$SCRIPT" siteone 5 > "$T/o.json" 2>/dev/null
chk "the last run reads as not there" "$(jv "$T/o.json" last_run.present)/$(jv "$T/o.json" last_run.at_unix)" "false/unknown"
chk "the log reads as not there" "$(jv "$T/o.json" log.present)/$(jv "$T/o.json" log.lines_returned)" "false/0"
chk "the code check reads as unknown" "$(jv "$T/o.json" code_check.state)" "unknown"
chk "nothing of the linked file is carried" "$(grep -c 'not the site' "$T/o.json")" "0"

echo "== a bind mount is never read =="
mkdir -p "$T/bind"; for i in 1 2 3; do echo "bound $i"; done > "$T/bind/host_converger.log"
STUB_LOGS_SRC="$T/bind" bash "$SCRIPT" siteone 5 > "$T/o.json" 2>/dev/null
chk "a logs mount outside the engine's volume shape is not read" "$(jv "$T/o.json" log.present)/$(grep -c bound "$T/o.json")" "false/0"

echo "== a fact that cannot be read keeps its key =="
rm -rf "$V/siteone_cache"
bash "$SCRIPT" siteone 5 > "$T/o.json" 2>/dev/null; rc=$?
chk "no cache volume: still an object, exit 0" "$rc/$(jv "$T/o.json" last_run.present)/$(jv "$T/o.json" stamp_present)" "0/false/false"

echo
echo "site_converger gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
