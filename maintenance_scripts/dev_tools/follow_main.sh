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
#   4. Runs update_database when the commits touched what it applies: a data class,
#      a migration, or a declaration it seeds (settings.json, admin_menus.json,
#      permissions.json, a plugin.json).
#
# A run that changes nothing prints nothing, so it can run every minute.
#
# Cron (user1 on dev, no sudo needed):
#   * * * * * /var/www/html/joinerytest/maintenance_scripts/dev_tools/follow_main.sh >> /var/www/html/joinerytest/logs/follow_main.log 2>&1
#
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
BRANCH="main"
LOCK="${HOME}/.cache/follow_main.lock"

say() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) follow_main: $*"; }

mkdir -p "$(dirname "$LOCK")"
exec 9> "$LOCK"
flock -n 9 || exit 0   # the previous minute's run is still going

cd "$REPO" || { say "no checkout at $REPO"; exit 1; }

if ! git fetch -q origin "$BRANCH" 2>/tmp/follow_main.fetch.$$; then
    say "fetch failed: $(tail -1 /tmp/follow_main.fetch.$$)"
    rm -f /tmp/follow_main.fetch.$$
    exit 1
fi
rm -f /tmp/follow_main.fetch.$$

old="$(git rev-parse HEAD)"
new="$(git rev-parse "origin/${BRANCH}")"
[ "$old" = "$new" ] && exit 0

# Modes are the converger's (see 1.2), so only content and untracked files count.
if [ -n "$(git -c core.fileMode=false status --porcelain)" ]; then
    say "NOT pulled $(git rev-parse --short "$new"): the checkout has local changes (dev is never edited; see git status)"
    exit 1
fi
if ! git merge-base --is-ancestor "$old" "$new"; then
    say "NOT pulled $(git rev-parse --short "$new"): origin/${BRANCH} is not a fast-forward of $(git rev-parse --short "$old")"
    exit 1
fi
building="$(psql -h localhost -U postgres -d joinerytest -Atc "SELECT count(*) FROM mjb_management_jobs
    WHERE mjb_job_type = 'publish_upgrade' AND mjb_status IN ('queued', 'pending', 'running')
    AND mjb_create_time > now() - interval '3 hours'" 2>/dev/null)"
if [ "${building:-0}" != "0" ] || pgrep -f 'publish_upgrade\.php' >/dev/null 2>&1; then
    exit 0   # a publish is reading this tree; the next minute tries again
fi

if ! git -c core.fileMode=false merge -q --ff-only "$new" 2>&1; then
    say "fast-forward to $(git rev-parse --short "$new") failed"
    exit 1
fi
say "at $(git log -1 --format='%h %s' "$new") (was $(git rev-parse --short "$old"))"

if git diff --name-only "$old" "$new" | grep -q -E '^public_html/((plugins/[^/]+/)?data/.*_class\.php|migrations/|settings\.json$|admin_menus\.json$|permissions\.json$|plugins/[^/]+/plugin\.json$)'; then
    # It reports a failed step as '#Failed: N' and may still exit 0.
    if php "$REPO/public_html/utils/update_database.php" > "${HOME}/.cache/follow_main.update_database.log" 2>&1 \
       && ! grep -q -E '#Failed: [1-9]' "${HOME}/.cache/follow_main.update_database.log"; then
        say "update_database ran (log: ~/.cache/follow_main.update_database.log)"
    else
        say "update_database FAILED (log: ~/.cache/follow_main.update_database.log)"
        exit 1
    fi
fi
