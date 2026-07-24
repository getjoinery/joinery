#!/bin/bash
# @joinery-test
# name: remove_account
# tier: db
# env: dev-only
# needs: []
# timeout: 120
#
# remove_account.sh is the tested host teardown that the Server Manager
# decommission_node job ships and runs to permanently delete a site. Two
# properties make it safe to drive from a job:
#
#   * "nothing to remove" is idempotent success — a re-run (or a teardown that
#     already happened) prints REMOVE_ACCOUNT_NOTHING and exits 0, never exit 1.
#   * a real removal prints REMOVE_ACCOUNT_OK and leaves no container, no
#     ${site}_* volume, and no image behind.
#
# The static section verifies the marker/idempotency contract on any box (it is
# the regression guard for the job-safety change). The behavioral section builds
# a throwaway container + volume and removes it for real — that half needs root
# and a usable Docker, so on a box without them it is skipped with a logged
# reason rather than silently passing.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/remove_account.sh"

passed=0; failed=0
chk() { # chk "label" actual expected
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "== Static contract =="

if [ ! -f "$SCRIPT" ]; then
    echo "  FAIL: remove_account.sh not found at $SCRIPT"
    echo "RESULT: 0 passed, 1 failed"
    exit 1
fi

bash -n "$SCRIPT" 2>/dev/null && synok=yes || synok=no
chk "remove_account.sh is valid bash" "$synok" "yes"

# The nothing-found branch must announce REMOVE_ACCOUNT_NOTHING and exit 0.
grep -q "REMOVE_ACCOUNT_NOTHING" "$SCRIPT" && nothing=yes || nothing=no
chk "emits a REMOVE_ACCOUNT_NOTHING marker" "$nothing" "yes"
# The old behavior (exit 1 when no site found) must be gone from that branch.
if grep -A6 "No site found with name" "$SCRIPT" | grep -q "exit 0"; then novalue=yes; else novalue=no; fi
chk "nothing-to-remove exits 0 (idempotent), not 1" "$novalue" "yes"

grep -q "REMOVE_ACCOUNT_OK" "$SCRIPT" && okmark=yes || okmark=no
chk "emits a REMOVE_ACCOUNT_OK marker on success" "$okmark" "yes"

# The docker branch must remove the container, its ${site}_ volumes, and the image.
grep -q 'docker rm "\$SITE_NAME"' "$SCRIPT" && drm=yes || drm=no
chk "removes the container" "$drm" "yes"
grep -q 'grep "\^\${SITE_NAME}_"' "$SCRIPT" && dvol=yes || dvol=no
chk "removes the site's \${site}_ volumes" "$dvol" "yes"
grep -q 'docker rmi "joinery-\${SITE_NAME}:latest"' "$SCRIPT" && dimg=yes || dimg=no
chk "removes the site image" "$dimg" "yes"

echo "== Behavioral (root + Docker required) =="

can_docker=no
if [ "$(id -u)" = "0" ] && command -v docker >/dev/null 2>&1 && docker ps >/dev/null 2>&1; then
    can_docker=yes
fi

if [ "$can_docker" != "yes" ]; then
    echo "  SKIP: build-and-remove cycle needs root and a usable Docker daemon."
    echo "        Runs on a Docker host; this box has neither, so only the static"
    echo "        contract above was exercised. (Not counted as pass or fail.)"
else
    IMG="$(docker images --format '{{.Repository}}:{{.Tag}}' | grep -v '<none>' | head -1)"
    if [ -z "$IMG" ]; then
        echo "  SKIP: no local Docker image available to build a fixture container."
    else
        FIX="jygaterm_${RANDOM}_${RANDOM}"
        # Guard: never touch a real site name.
        if docker ps -a --format '{{.Names}}' | grep -qx "$FIX" || [ -d "/var/www/html/$FIX" ]; then
            echo "  FAIL: fixture name $FIX unexpectedly already exists; aborting"
            failed=$((failed+1))
        else
            docker volume create "${FIX}_data" >/dev/null 2>&1
            docker run -d --name "$FIX" "$IMG" sh -c 'sleep 300' >/dev/null 2>&1 \
                || docker run -d --name "$FIX" "$IMG" >/dev/null 2>&1

            out="$(bash "$SCRIPT" "$FIX" -y 2>&1)"
            echo "$out" | grep -q "REMOVE_ACCOUNT_OK" && m=yes || m=no
            chk "removal prints REMOVE_ACCOUNT_OK" "$m" "yes"

            docker ps -a --format '{{.Names}}' | grep -qx "$FIX" && stillc=yes || stillc=no
            chk "container is gone after removal" "$stillc" "no"
            docker volume ls --format '{{.Name}}' | grep -q "^${FIX}_" && stillv=yes || stillv=no
            chk "the \${site}_ volume is gone after removal" "$stillv" "no"

            # Idempotent re-run: nothing left → REMOVE_ACCOUNT_NOTHING, exit 0.
            out2="$(bash "$SCRIPT" "$FIX" -y 2>&1)"; rc=$?
            echo "$out2" | grep -q "REMOVE_ACCOUNT_NOTHING" && n=yes || n=no
            chk "a second run reports REMOVE_ACCOUNT_NOTHING" "$n" "yes"
            chk "a second run exits 0 (idempotent)" "$rc" "0"

            # Belt-and-suspenders cleanup in case the script left anything.
            docker rm -f "$FIX" >/dev/null 2>&1 || true
            docker volume rm "${FIX}_data" >/dev/null 2>&1 || true
        fi
    fi
fi

echo "RESULT: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
