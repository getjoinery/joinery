#!/usr/bin/env bash
# Keep the public test node's checkout (dev.getjoinery.com) at GitHub's main.
#
# Code is written and committed on localdev; `work NAME --commit` pushes it to
# GitHub. This brings each pushed commit to dev within a minute, so the site, its
# mail and webhooks, and the next publish all run what was last committed. Nothing
# is ever edited on dev.
#
# Each run:
#   1. Fetches origin. Already at origin/main: done, silently.
#   2. Refuses, and says so, when the checkout has local edits or untracked files
#      (someone edited dev), when main is not a fast-forward of HEAD, or while a
#      publish is building (it reads this tree).
#   3. Fast-forwards to origin/main.
#   3a. Holds the site's scheduled-task passes off from before the fast-forward until
#      update_database has run (the hold a test takes, tests/lib/harness.php): a pass in
#      between ran the new code against the old schema and read columns not there yet.
#   3b. Installs the libraries the lock file names (composer install --no-dev) whenever
#      public_html/composer.lock differs from the one last installed, the way a release's
#      upgrade does. Checked on every run, so a failed install is tried again next minute.
#   4. Runs update_database --upgrade when the commits touched what it applies: a data class,
#      a migration, or a declaration it seeds (settings.json, admin_menus.json,
#      permissions.json, a plugin.json). One that failed is run again after the next pull
#      or library install, whatever that touched: it refuses while the libraries do not
#      match the lock file, and nothing else would ever run it again.
#
# First, the agent source (joinery-agent) is brought to its own origin/main the
# same way, steps 1 to 3: a publish builds the agent it bundles from that
# checkout, so one left behind ships an old agent in every release. A refusal
# there is said and does not stop the site's own pull.
#
# A run that changes nothing prints nothing, so it can run every minute.
#
# Cron (user1 on dev, no sudo needed):
#   * * * * * /var/www/html/joinerytest/maintenance_scripts/dev_tools/follow_main.sh >> /var/www/html/joinerytest/logs/follow_main.log 2>&1
#
# @version 1.6 - installs the lock file's libraries when it changes, and runs a failed update_database
#               again after the next pull or install. dev's vendor/ had not moved since 10-14, and once the
#               lock moved past it (guzzle 7.17.0) every update_database refused (11-22).
# @version 1.5 - scheduled-task passes are held off across the fast-forward and update_database;
#               a pass in that window warned on every new column (cvp_domain_renew_quote, 11-16)
# @version 1.4 - update_database runs with --upgrade, the way a release's upgrade runs it. Run plain,
#               it created no declared index and fixed no column type, so dev's schema drifted
#               from every node that upgrades.
# @version 1.3 - psql -w: the publish check never waits at a password prompt. Follows the agent source too (/home/user1/joinery-agent, the checkout a publish builds the
#               agent from), under the same rules. Only the main repo was pulled, so every release from
#               1.96.0 on bundled agent 1.96.0: 1.97.0 to 1.100.0 never shipped.
# @version 1.2 - A difference in a file's mode alone is not a local edit: the host converger, not a
#               person, sets every mode in the tree (.sh 755, the rest 644). Scripts git recorded at
#               644 read as edited after every converge, and every pull was refused from 10-31.
# @version 1.1 - Pulls under umask 022. user1's umask on dev is 0002, so every file a
#               fast-forward rewrote came out group-writable, and the host converger,
#               which runs installers as root, refuses one writable by anyone but its
#               owner: host_housekeeping.sh at 775 stopped every run (installer-refused).
# @version 1.0
set -u
umask 022   # see 1.1: the converger runs this tree's installers as root

REPO="${FOLLOW_MAIN_REPO:-/var/www/html/joinerytest}"   # override only to try it on a scratch clone
# The agent source. A scratch REPO never reaches the real agent checkout: with
# FOLLOW_MAIN_REPO set, the agent is followed only where FOLLOW_MAIN_AGENT_REPO
# names a scratch one too.
if [ -n "${FOLLOW_MAIN_REPO:-}" ]; then
    AGENT_REPO="${FOLLOW_MAIN_AGENT_REPO:-}"
else
    AGENT_REPO="${FOLLOW_MAIN_AGENT_REPO:-/home/user1/joinery-agent}"
fi
BRANCH="main"
LOCK="${HOME}/.cache/follow_main.lock"
COMPOSER="${FOLLOW_MAIN_COMPOSER:-composer}"   # override only in the scratch-clone test
UPDATE_DB_LOG="${HOME}/.cache/follow_main.update_database.log"
UPDATE_DB_PENDING="${HOME}/.cache/follow_main.update_database.pending"   # the last one failed
VENDOR_STAMP="${HOME}/.cache/follow_main.composer_lock"   # the lock file last installed (its hash)

say() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) follow_main: $*"; }

mkdir -p "$(dirname "$LOCK")"
exec 9> "$LOCK"
flock -n 9 || exit 0   # the previous minute's run is still going

# A publish reads both checkouts; while one is building neither moves.
publish_building() {
    local building
    building="$(psql -w -h localhost -U postgres -d joinerytest -Atc "SELECT count(*) FROM mjb_management_jobs
        WHERE mjb_job_type = 'publish_upgrade' AND mjb_status IN ('queued', 'pending', 'running')
        AND mjb_create_time > now() - interval '3 hours'" 2>/dev/null)"
    [ "${building:-0}" != "0" ] || pgrep -f 'publish_upgrade\.php' >/dev/null 2>&1
}

# The agent source, to its origin/main: fetch, refuse local edits or a
# non-fast-forward, fast-forward. Its own subshell, so nothing it does can stop
# the site's pull below.
follow_agent() {
    [ -n "$AGENT_REPO" ] && [ -d "$AGENT_REPO/.git" ] || return 0
    (
        cd "$AGENT_REPO" || exit 0
        git fetch -q origin "$BRANCH" 2>/dev/null || { say "agent: fetch failed"; exit 1; }
        a_old="$(git rev-parse HEAD)"
        a_new="$(git rev-parse "origin/${BRANCH}")"
        [ "$a_old" = "$a_new" ] && exit 0
        if [ -n "$(git -c core.fileMode=false status --porcelain)" ]; then
            say "agent: NOT pulled $(git rev-parse --short "$a_new"): the checkout has local changes"
            exit 1
        fi
        if ! git merge-base --is-ancestor "$a_old" "$a_new"; then
            say "agent: NOT pulled $(git rev-parse --short "$a_new"): origin/${BRANCH} is not a fast-forward of $(git rev-parse --short "$a_old")"
            exit 1
        fi
        publish_building && exit 0
        if git -c core.fileMode=false merge -q --ff-only "$a_new" 2>&1; then
            say "agent: at $(git log -1 --format='%h %s' "$a_new") (was $(git rev-parse --short "$a_old"))"
        else
            say "agent: fast-forward to $(git rev-parse --short "$a_new") failed"
        fi
    ) || true
}
follow_agent

# The libraries the lock file names, installed when it differs from the one
# installed last. Returns 0 when it installed, 1 when there was nothing to do,
# 2 when the install failed (said; the next run tries again).
sync_vendor() {
    local lock="$REPO/public_html/composer.lock" want have
    [ -f "$lock" ] || return 1
    want="$(sha256sum "$lock" | cut -d' ' -f1)"
    have="$(cat "$VENDOR_STAMP" 2>/dev/null)"
    [ "$want" = "$have" ] && return 1
    # No install recorded yet: whatever update_database last did may have been
    # refused over the libraries, so it runs once they are in.
    [ -z "$have" ] && touch "$UPDATE_DB_PENDING"
    if (cd "$REPO/public_html" && "$COMPOSER" install --no-dev --no-interaction --no-progress -q) \
            > "${HOME}/.cache/follow_main.composer.log" 2>&1; then
        printf '%s\n' "$want" > "$VENDOR_STAMP"
        say "libraries installed from composer.lock (log: ~/.cache/follow_main.composer.log)"
        return 0
    fi
    say "composer install FAILED (log: ~/.cache/follow_main.composer.log)"
    return 2
}

# update_database --upgrade, as a release's upgrade runs it: without --upgrade
# declared indexes are never created and column type changes are only warned
# about. It reports a failed step as '#Failed: N' and may still exit 0.
run_update_database() {
    if ${FOLLOW_MAIN_UPDATE_DB:-php "$REPO/public_html/utils/update_database.php"} --upgrade > "$UPDATE_DB_LOG" 2>&1 \
       && ! grep -q -E '#Failed: [1-9]' "$UPDATE_DB_LOG"; then
        rm -f "$UPDATE_DB_PENDING"
        say "update_database ran (log: ~/.cache/follow_main.update_database.log)"
        return 0
    fi
    touch "$UPDATE_DB_PENDING"
    say "update_database FAILED (log: ~/.cache/follow_main.update_database.log)"
    return 1
}

cd "$REPO" || { say "no checkout at $REPO"; exit 1; }

if ! git fetch -q origin "$BRANCH" 2>/tmp/follow_main.fetch.$$; then
    say "fetch failed: $(tail -1 /tmp/follow_main.fetch.$$)"
    rm -f /tmp/follow_main.fetch.$$
    exit 1
fi
rm -f /tmp/follow_main.fetch.$$

old="$(git rev-parse HEAD)"
new="$(git rev-parse "origin/${BRANCH}")"
if [ "$old" = "$new" ]; then
    # Nothing to pull, but a failed install is tried again, and an update_database
    # it held back runs once it succeeds.
    publish_building && exit 0
    sync_vendor
    if [ $? -eq 0 ] && [ -e "$UPDATE_DB_PENDING" ]; then
        run_update_database || exit 1
    fi
    exit 0
fi

# Modes are the converger's (see 1.2), so only content and untracked files count.
if [ -n "$(git -c core.fileMode=false status --porcelain)" ]; then
    say "NOT pulled $(git rev-parse --short "$new"): the checkout has local changes (dev is never edited; see git status)"
    exit 1
fi
if ! git merge-base --is-ancestor "$old" "$new"; then
    say "NOT pulled $(git rev-parse --short "$new"): origin/${BRANCH} is not a fast-forward of $(git rev-parse --short "$old")"
    exit 1
fi
if publish_building; then
    exit 0   # a publish is reading this tree; the next minute tries again
fi

# Hold scheduled-task passes until the schema matches the code. A psql session
# beside this script takes the hold lock a test takes, then waits (at most 90 s)
# for a pass already under way to finish; the hold ends when this script exits
# and the session's input closes. Not getting it only means pulling unheld.
hold_task_passes() {
    coproc TASK_HOLD { psql -w -h localhost -U postgres -d "${FOLLOW_MAIN_DB:-joinerytest}" -Atq 2>/dev/null; }
    printf '%s\n' "SELECT pg_advisory_lock_shared(hashtext('scheduled_tasks'), hashtext('test_hold'));" \
        "SET lock_timeout = '90s';" \
        "SELECT pg_advisory_lock(hashtext('scheduled_tasks'), hashtext('pass'));" \
        "SELECT pg_advisory_unlock(hashtext('scheduled_tasks'), hashtext('pass'));" \
        "SELECT 'held';" >&"${TASK_HOLD[1]}"
    local line
    while read -r -t 100 -u "${TASK_HOLD[0]}" line; do
        [ "$line" = "held" ] && return 0
    done
    say "scheduled-task passes NOT held for this pull"
    return 1
}
hold_task_passes || true

if ! git -c core.fileMode=false merge -q --ff-only "$new" 2>&1; then
    say "fast-forward to $(git rev-parse --short "$new") failed"
    exit 1
fi
say "at $(git log -1 --format='%h %s' "$new") (was $(git rev-parse --short "$old"))"

sync_vendor
vendor=$?
[ "$vendor" -eq 2 ] && touch "$UPDATE_DB_PENDING"   # it would refuse the mismatch; run it after the install

if git diff --name-only "$old" "$new" | grep -q -E '^public_html/((plugins/[^/]+/)?data/.*_class\.php|migrations/|settings\.json$|admin_menus\.json$|permissions\.json$|plugins/[^/]+/plugin\.json$)' \
        || [ -e "$UPDATE_DB_PENDING" ]; then
    [ "$vendor" -eq 2 ] && exit 1   # said above; the next run installs, then runs it
    run_update_database || exit 1
fi
