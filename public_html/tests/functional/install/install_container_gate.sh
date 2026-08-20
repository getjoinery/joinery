#!/bin/bash
# @joinery-test
# name: install_container
# tier: live
# env: any
# needs: [docker]
# timeout: 3600
#
# A container install of the current tree on a throwaway Docker host, held to
# the promises specs/implemented/installer_defects_2026_08_06.md pins as text elsewhere.
# The text assertions prove the guards are written down; this gate proves a
# real install behaves. Every assertion here failed on a real box on
# 2026-08-06 before the fixes:
#
#   * the site answers 200 for a request carrying its CONFIGURED DOMAIN, not
#     just Host: localhost (the probe defect let a 301-to-nowhere ship green)
#   * logs/error.log is empty after ten requests (root-owned cache/static_pages
#     logged "caching disabled" on every request)
#   * cache/static_pages is www-data-writable and holds a rendered entry
#   * exactly one file under /etc/cron.d runs process_scheduled_tasks.php
#     (two runners collided on every shared tick)
#   * the same is true AFTER a container rebuild — /etc/cron.d does not
#     survive one, and _site_init.sh only runs on first boot
#   * docker inspect on the IMAGE and docker history carry no database
#     password (it used to sit in 7 history layers)
#
# Plus one that failed on a real box on 2026-08-20 (a user's setup wizard
# offered to send a test to admin@example.com):
#
#   * the --admin-email address and the upgrade source land inside the
#     container — _site_init.sh runs in there on first boot, and a host-side
#     export never crossed, so both were silently dropped
#
# Run this on a host you can throw away or at least freely install Docker
# sites on. It builds joinery-base if missing (5-10 minutes, once per host),
# installs a site with --no-ssl on a port of its own, asserts, rebuilds,
# asserts again, and removes everything it created.
#
# Never run this on the dev box or a managed production node.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/install_tools"

SITENAME="joinerygate"
DOMAIN="joinerygate.example.test"
PORT=8099
ADMIN_EMAIL="gate-admin@joinerygate.example.test"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

if ! command -v docker >/dev/null 2>&1; then
    echo "SKIP: docker not available on this host"
    echo "RESULT: PASS 0 0"
    exit 0
fi

# The password is known to this gate alone, so the image checks can grep for
# the literal. File, not argv — argv is readable through ps.
PW="gate_$(openssl rand -hex 16)"
PWFILE="$(mktemp)"
chmod 600 "$PWFILE"
printf '%s' "$PW" > "$PWFILE"

cleanup() {
    docker rm -f "$SITENAME" >/dev/null 2>&1 || true
    docker volume ls -q --filter "name=${SITENAME}_" 2>/dev/null | xargs -r docker volume rm >/dev/null 2>&1 || true
    docker image rm "joinery-$SITENAME" >/dev/null 2>&1 || true
    rm -f "$PWFILE"
    rm -f "/etc/apache2/sites-available/${SITENAME}-proxy.conf" 2>/dev/null || true
    a2dissite "${SITENAME}-proxy" >/dev/null 2>&1 && systemctl reload apache2 >/dev/null 2>&1 || true
}
trap cleanup EXIT

probe() {
    # http_code for a request carrying the configured domain — the request a
    # visitor's browser makes, not the one that flatters a dead vhost.
    curl -s -o /dev/null -w "%{http_code}" -H "Host: $DOMAIN" "http://localhost:$PORT/" 2>/dev/null || true
}

assert_installed_state() {
    local phase="$1"

    # Reachability by domain. Retry briefly: first boot runs update_database.
    local code=""
    for _ in $(seq 1 24); do
        code="$(probe)"
        [ "$code" = "200" ] && break
        sleep 5
    done
    chk "[$phase] site answers 200 for Host: $DOMAIN" "$code" "200"

    # Ten requests, then the error log must be empty. The cache-permission
    # defect wrote one line per request; anything here is a defect surfacing.
    for _ in $(seq 1 10); do probe >/dev/null; done
    local errlog
    errlog="$(docker exec "$SITENAME" sh -c "cat /var/www/html/$SITENAME/logs/error.log 2>/dev/null | wc -c" 2>/dev/null || echo probe-failed)"
    chk "[$phase] logs/error.log is empty after ten requests" "$errlog" "0"

    # The cache directory the code reads: owned by www-data, writable by
    # www-data, and holding a rendered entry after real requests.
    local owner
    owner="$(docker exec "$SITENAME" stat -c %U "/var/www/html/$SITENAME/cache/static_pages" 2>/dev/null || echo missing)"
    chk "[$phase] cache/static_pages is owned by www-data" "$owner" "www-data"
    local writable
    writable="$(docker exec -u www-data "$SITENAME" sh -c "touch /var/www/html/$SITENAME/cache/static_pages/.gate_probe && rm /var/www/html/$SITENAME/cache/static_pages/.gate_probe && echo yes" 2>/dev/null || echo no)"
    chk "[$phase] cache/static_pages is writable by www-data" "$writable" "yes"
    local entries
    entries="$(docker exec "$SITENAME" sh -c "ls /var/www/html/$SITENAME/cache/static_pages | wc -l" 2>/dev/null || echo 0)"
    if [ "$entries" -gt 0 ] 2>/dev/null; then
        chk "[$phase] cache/static_pages holds a rendered entry" "yes" "yes"
    else
        chk "[$phase] cache/static_pages holds a rendered entry" "empty" "non-empty"
    fi

    # Exactly one cron.d file runs the task runner.
    local cron_count
    cron_count="$(docker exec "$SITENAME" sh -c "grep -rl process_scheduled_tasks.php /etc/cron.d/ 2>/dev/null | wc -l" 2>/dev/null || echo probe-failed)"
    chk "[$phase] exactly one cron.d file runs the task runner" "$cron_count" "1"

    # The env-file handoff. _site_init.sh runs inside the container on first
    # boot, so the admin email and upgrade source chosen on the host reach it
    # only through the run-time env file — an export alone never crossed, and
    # both were silently dropped.
    local admin_email
    admin_email="$(docker exec "$SITENAME" su -c "psql -d $SITENAME -tAc \"SELECT usr_email FROM usr_users WHERE usr_user_id = 1\"" postgres 2>/dev/null || echo probe-failed)"
    chk "[$phase] admin account carries the --admin-email address" "$admin_email" "$ADMIN_EMAIL"
    local upgrade_source
    upgrade_source="$(docker exec "$SITENAME" su -c "psql -d $SITENAME -tAc \"SELECT stg_value FROM stg_settings WHERE stg_name = 'upgrade_source'\"" postgres 2>/dev/null || echo probe-failed)"
    if [ -n "$upgrade_source" ] && [ "$upgrade_source" != "probe-failed" ]; then
        chk "[$phase] upgrade_source setting is seeded" "seeded" "seeded"
    else
        chk "[$phase] upgrade_source setting is seeded" "${upgrade_source:-empty}" "non-empty"
    fi
}

echo "== Installing $SITENAME on port $PORT (this builds joinery-base if missing) =="
if ! (cd "$TOOLS" && ./install.sh -y -q site "$SITENAME" --password-file="$PWFILE" --admin-email="$ADMIN_EMAIL" "$DOMAIN" "$PORT" --no-ssl); then
    echo "  FAIL: install.sh exited non-zero"
    echo "RESULT: FAIL $passed $((failed+1))"
    exit 1
fi
echo "  PASS: install.sh completed"; passed=$((passed+1))

assert_installed_state "fresh install"

# The image must not know the password. The container's env legitimately
# carries it (that is how it arrives at run time); the IMAGE and its history
# travel, and must not.
IMG_HITS="$(docker inspect "joinery-$SITENAME" 2>/dev/null | grep -c "$PW" || true)"
chk "docker inspect of the image carries no database password" "$IMG_HITS" "0"
HIST_HITS="$(docker history --no-trunc "joinery-$SITENAME" 2>/dev/null | grep -c "$PW" || true)"
chk "docker history carries no database password" "$HIST_HITS" "0"

# Rebuild: same command again. -y removes the container, keeps the volumes,
# and _site_init.sh does not run again — the state where the cron file and
# cache ownership have no writer unless the start command owns them.
echo "== Rebuilding the container (volumes kept, _site_init.sh skipped) =="
if ! (cd "$TOOLS" && ./install.sh -y -q site "$SITENAME" --password-file="$PWFILE" --admin-email="$ADMIN_EMAIL" "$DOMAIN" "$PORT" --no-ssl); then
    echo "  FAIL: rebuild install.sh exited non-zero"
    echo "RESULT: FAIL $passed $((failed+1))"
    exit 1
fi
echo "  PASS: rebuild completed"; passed=$((passed+1))

assert_installed_state "after rebuild"

echo
if [ "$failed" -eq 0 ]; then
    echo "RESULT: PASS $passed $failed"
    exit 0
fi
echo "RESULT: FAIL $passed $failed"
exit 1
