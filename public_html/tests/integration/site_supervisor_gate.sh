#!/bin/bash
# @joinery-test
# name: site_supervisor
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/install_tools/_site_supervisor.sh, maintenance_scripts/install_tools/Dockerfile.template, maintenance_scripts/sysadmin_tools/rebase_site_container.sh]
#
# A site container's main process keeps PostgreSQL, PHP-FPM, Apache and cron
# running (specs/multi_tenant_docker_hosts.md WP2 item 2). The real supervisor
# runs here against stand-in processes (copies of sleep named postgres,
# php-fpm8.5, apache2 and cron) under a scratch root, with service, apache2ctl,
# pkill and pgrep stubbed on PATH so nothing on this machine is touched:
#
#   - --check: 0 when all four run, 1 naming the one that does not, 0 when held
#   - Apache is started by the supervisor; a hold left from a previous run is cleared
#   - a dead PHP-FPM master: its leftover workers are killed, then it is started
#   - a dead cron is started and its jobs are left alone
#   - one or two missed checks restart nothing (a deliberate restart is not raced)
#   - nothing restarts while held; the restart follows once the hold goes
#   - a start that fails is logged and tried again; the supervisor keeps running
#   - docker stop's TERM stops Apache, PHP-FPM, cron and PostgreSQL in that order
#   - the image runs it as its main process and as its health check, and the
#     rebase script holds it before stopping the site's writes

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SUP="$ROOT/maintenance_scripts/install_tools/_site_supervisor.sh"
T=$(mktemp -d) || { echo "mktemp failed"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] && [ "$T" != "/" ] || { echo "no scratch dir"; exit 1; }
SUP_PID=""
cleanup() {
    [ -n "$SUP_PID" ] && kill "$SUP_PID" 2>/dev/null
    [ -f "$T/allpids" ] && while read -r p; do kill -9 "$p" 2>/dev/null; done < "$T/allpids"
    rm -rf "${T:?}"
}
trap cleanup EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

if [ "$(id -u)" -eq 0 ]; then
    echo "  SKIP: run as root, which ignores the scratch root"
    echo "site_supervisor gate: 0 passed, 0 failed"
    exit 0
fi

chk "_site_supervisor.sh parses" "$(bash -n "$SUP" 2>/dev/null && echo yes || echo no)" "yes"

RUN="$T/root"
mkdir -p "$RUN/etc/postgresql/18/main" "$RUN/etc/init.d" "$RUN/var/run/postgresql" "$RUN/run/php" \
         "$RUN/var/run/apache2" "$RUN/run/joinery" "$T/bin" "$T/fake" "$T/leftovers"
touch "$RUN/etc/init.d/php8.5-fpm" "$RUN/etc/init.d/php8.3-fpm"
SLEEP="$(command -v sleep)"
for c in postgres php-fpm8.5 apache2 cron; do cp "$SLEEP" "$T/fake/$c"; done

# service NAME start|stop|status, apache2ctl start|stop: a stand-in process
# and its pid file. A file $T/fail_NAME makes NAME's start fail.
cat > "$T/bin/_svc" <<EOF
#!/bin/bash
T="$T"; RUN="$RUN"
EOF
cat >> "$T/bin/_svc" <<'EOF'
name="$1"; action="$2"
case "$name" in
    postgresql) comm=postgres;   pf="$RUN/var/run/postgresql/18-main.pid" ;;
    php8.5-fpm) comm=php-fpm8.5; pf="$RUN/run/php/php8.5-fpm.pid" ;;
    apache2)    comm=apache2;    pf="$RUN/var/run/apache2/apache2.pid" ;;
    cron)       comm=cron;       pf="$RUN/var/run/crond.pid" ;;
    *) exit 1 ;;
esac
echo "$action $name" >> "$T/calls"
case "$action" in
    start)
        [ -e "$T/fail_$name" ] && exit 1
        "$T/fake/$comm" 3600 < /dev/null > /dev/null 2>&1 &
        echo $! > "$pf"; echo $! >> "$T/allpids" ;;
    stop) kill "$(cat "$pf" 2>/dev/null)" 2>/dev/null; rm -f "$pf" ;;
esac
exit 0
EOF
printf '#!/bin/bash\nexec "%s/bin/_svc" "$1" "$2"\n' "$T" > "$T/bin/service"
printf '#!/bin/bash\nexec "%s/bin/_svc" apache2 "$1"\n' "$T" > "$T/bin/apache2ctl"
# pkill -KILL -x NAME kills the leftovers this gate made for NAME, and no other process.
cat > "$T/bin/pkill" <<EOF
#!/bin/bash
name="\${@: -1}"; echo "pkill \$name" >> "$T/calls"
f="$T/leftovers/\$name"; [ -s "\$f" ] || exit 1
while read -r p; do kill -9 "\$p" 2>/dev/null; done < "\$f"; : > "\$f"; exit 0
EOF
printf '#!/bin/bash\nexit 1\n' > "$T/bin/pgrep"
chmod +x "$T/bin/"*

export SUPERVISOR_TEST_ROOT="$RUN"
sup() { PATH="$T/bin:$PATH" bash "$SUP" "$@"; }
pid_of() { cat "$RUN/$1" 2>/dev/null; }
FPM_PF=run/php/php8.5-fpm.pid; PG_PF=var/run/postgresql/18-main.pid; CRON_PF=var/run/crond.pid
calls() { cat "$T/calls" 2>/dev/null; }
wait_for() {  # wait_for SECONDS COMMAND... : until the command succeeds
    local end=$((SECONDS + $1)); shift
    while [ $SECONDS -lt "$end" ]; do "$@" && return 0; sleep 0.1; done
    return 1
}
logged() { grep -q "$1" "$T/out"; }

for s in postgresql php8.5-fpm cron; do PATH="$T/bin:$PATH" service "$s" start; done
: > "$T/calls"

echo "== --check, before the supervisor runs =="
out="$(sup --check)"; code=$?
chk "Apache down: exit 1" "$code" "1"
chk "names it" "$out" "apache2 is not running"
PATH="$T/bin:$PATH" apache2ctl start; : > "$T/calls"
out="$(sup --check)"; code=$?
chk "all four running: exit 0" "$code" "0"
kill "$(pid_of "$FPM_PF")"; wait_for 2 bash -c "! kill -0 $(pid_of "$FPM_PF") 2>/dev/null"
chk "PHP-FPM down: exit 1, named" "$(sup --check; echo "exit $?")" "php-fpm is not running
exit 1"
echo "rebase: a move" > "$RUN/run/joinery/supervisor.hold"
chk "held: exit 0 and says why" "$(sup --check; echo "exit $?")" "held: rebase: a move
exit 0"
PATH="$T/bin:$PATH" service php8.5-fpm start
PATH="$T/bin:$PATH" apache2ctl stop
: > "$T/calls"

echo "== the supervisor starts =="
SUPERVISOR_TEST_INTERVAL=0.5 PATH="$T/bin:$PATH" bash "$SUP" testsite > "$T/out" 2>&1 &
SUP_PID=$!
wait_for 5 logged "running; watching"
chk "it started Apache" "$(calls | grep -c '^start apache2$')" "1"
chk "the hold left from before is gone" "$([ -e "$RUN/run/joinery/supervisor.hold" ] && echo there || echo gone)" "gone"
chk "--check passes" "$(sup --check >/dev/null; echo $?)" "0"

echo "== a dead PHP-FPM master =="
left="$( ("$T/fake/php-fpm8.5" 3600 < /dev/null > /dev/null 2>&1 & echo $!) )"; echo "$left" >> "$T/allpids"; echo "$left" > "$T/leftovers/php-fpm8.5"
: > "$T/calls"
kill "$(pid_of "$FPM_PF")"
wait_for 6 logged "php-fpm was not running; started it again"
chk "restarted and logged" "$(grep -c 'php-fpm was not running; started it again' "$T/out")" "1"
chk "its leftovers were killed first, then it was started" "$(calls | tr '\n' ',')" "pkill php-fpm8.5,start php8.5-fpm,"
chk "the leftover worker is gone" "$(kill -0 "$left" 2>/dev/null && echo alive || echo gone)" "gone"
chk "--check passes again" "$(sup --check >/dev/null; echo $?)" "0"

echo "== a dead cron =="
: > "$T/calls"
kill "$(pid_of "$CRON_PF")"
wait_for 6 logged "cron was not running; started it again"
chk "cron started again, its jobs left alone" "$(calls | tr '\n' ',')" "start cron,"

echo "== a deliberate restart is not raced =="
: > "$T/calls"
old="$(pid_of "$PG_PF")"; kill "$old"
sleep 0.7
chk "after one or two missed checks nothing has restarted" "$(calls | grep -c 'start postgresql')" "0"
wait_for 6 logged "postgresql was not running; started it again"
chk "after three it has" "$(calls | grep -c '^start postgresql$')" "1"

echo "== held =="
echo "test hold" > "$RUN/run/joinery/supervisor.hold"
: > "$T/calls"
kill "$(pid_of "$FPM_PF")"
sleep 2.5
chk "nothing restarts while held" "$(calls | grep -c '^start')" "0"
rm -f "$RUN/run/joinery/supervisor.hold"
wait_for 6 bash -c "grep -q '^start php8.5-fpm' '$T/calls'"
chk "the restart follows once the hold goes" "$(calls | grep -c '^start php8.5-fpm$')" "1"

echo "== a start that fails =="
touch "$T/fail_cron"
kill "$(pid_of "$CRON_PF")"; rm -f "$RUN/$CRON_PF"
wait_for 6 logged "cron was not running and did not start"
chk "logged" "$(grep -c 'cron was not running and did not start' "$T/out" | sed 's/^[1-9][0-9]*$/yes/')" "yes"
chk "the supervisor keeps running" "$(kill -0 "$SUP_PID" 2>/dev/null && echo running)" "running"
chk "--check names cron" "$(sup --check)" "cron is not running"
rm -f "$T/fail_cron"
wait_for 6 bash -c "[ \$(grep -c 'cron was not running; started it again' '$T/out') -ge 2 ]"
chk "tried again, and cron runs" "$(sup --check >/dev/null; echo $?)" "0"

echo "== docker stop =="
: > "$T/calls"
kill -TERM "$SUP_PID"
wait_for 5 bash -c "! kill -0 $SUP_PID 2>/dev/null"
wait "$SUP_PID"; code=$?
SUP_PID=""
chk "exits 0" "$code" "0"
chk "stops Apache, PHP-FPM, cron, then PostgreSQL" "$(calls | tr '\n' ',')" "stop apache2,stop php8.5-fpm,stop cron,stop postgresql,"

echo "== where it is wired in =="
TPL="$ROOT/maintenance_scripts/install_tools/Dockerfile.template"
chk "the start command ends by exec'ing it" "$(tail -1 "$TPL" | grep -c 'exec bash "${SUPERVISOR}" "${SITENAME}"; else exec apache2ctl -D FOREGROUND; fi')" "1"
chk "the health check runs --check, and passes without the file" "$(grep -c '\[ ! -f "$f" \] || bash "$f" --check' "$TPL")" "1"
chk "PHP's pool is sized before PHP-FPM starts" "$(grep -A1 'tune_php_fpm.sh" --no-restart --container' "$TPL" | grep -c 'service "${FPM_SERVICE}" start')" "1"
REB="$ROOT/maintenance_scripts/sysadmin_tools/rebase_site_container.sh"
chk "rebase holds the supervisor before stopping PHP-FPM" \
    "$(awk '/^stop_site_writes\(\)/,/^}/' "$REB" | grep -n 'supervisor.hold\|service "\$(basename "\$s")" stop' | cut -d: -f1 | tr '\n' ' ' | awk '{print ($1 < $2) ? "first" : "after"}')" "first"
chk "it logs to the site's error log, which is already rotated" "$(grep -c 'local log="/var/www/html/${SITE}/logs/error.log"' "$SUP")" "1"

echo
echo "site_supervisor gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
