#!/usr/bin/env bash
# Keep the dev box's disk from filling with agent scratch.
#
# Every Claude session gets its own scratchpad under /tmp/claude-1000/<project>/<session>/
# and nothing ever removes it. A drive-sync session builds Rust there (a `target`
# dir is 2 GB+, and a session may keep several); the root disk is one 79 GB
# volume, and it hit 100% on 2026-09-12 with 42 GB of scratch in it.
#
# What this prunes, and only this:
#   1. Session scratchpads whose newest file is older than $KEEP_DAYS — the
#      session is gone; scratch is temporary by definition.
#   2. Any Rust `target` dir under /tmp/claude-1000 not written to in
#      $TARGET_DAYS days, wherever it sits — a build cache, always rebuildable.
#   3. Stale git worktrees under /tmp/claude-1000 (registered with the repo,
#      not touched in $KEEP_DAYS) — `git worktree remove` so the repo forgets
#      them too.
#   4. Build caches over the size cap: when the Rust `target` dirs that remain
#      add up to more than $CAP_GB GB, the coldest go first until the total is
#      under the cap. A cache written in the last $GRACE_HOURS hours is never
#      taken — that is a build in progress. Rule 2 waits for a cache to age;
#      this one catches a session that builds faster than its caches age
#      (2026-10-01: 80 target dirs, 30 GB, the disk at 3.6 GB free).
#
# It never touches: a scratchpad written to within $KEEP_DAYS, source trees, the
# repo, /var, or anything outside /tmp/claude-1000. Dry run by default — pass
# --delete to act. Prints what it would (or did) remove and the space involved;
# a --delete run that removes nothing prints nothing, so it can run hourly.
#
# Cron (user1, no sudo needed) — hourly, because a busy session can fill the
# disk in under two hours:
#   17 * * * * /var/www/html/joinerytest/maintenance_scripts/dev_tools/prune_dev_scratch.sh --delete >> /var/www/html/joinerytest/logs/prune_dev_scratch.log 2>&1
#
# @version 1.1
set -u

SCRATCH_ROOT="/tmp/claude-1000"
REPO="/var/www/html/joinerytest"
KEEP_DAYS="${KEEP_DAYS:-3}"      # a session idle this long is over
TARGET_DAYS="${TARGET_DAYS:-2}"  # a build cache idle this long is cold
CAP_GB="${CAP_GB:-15}"           # all build caches together stay under this
GRACE_HOURS="${GRACE_HOURS:-6}"  # a cache written this recently is in use
DO_DELETE=0
[ "${1:-}" = "--delete" ] && DO_DELETE=1

stamp() { date '+%Y-%m-%d %H:%M:%S'; }
human() { numfmt --to=iec --suffix=B "$1" 2>/dev/null || echo "$1"; }
total=0
announced=0
free_before=$(df -h --output=avail / | tail -1 | tr -d ' ')

announce() { # the header, once, before the first removal
	[ "$announced" = 1 ] && return
	announced=1
	echo "$(stamp) prune_dev_scratch: $( [ "$DO_DELETE" = 1 ] && echo DELETING || echo 'dry run (pass --delete)' ); free before: $free_before"
}

remove() { # path, reason [, bytes if already measured]
	local p="$1" why="$2" bytes="${3:-}"
	announce
	[ -n "$bytes" ] || { bytes=$(du -sb "$p" 2>/dev/null | cut -f1); bytes=${bytes:-0}; }
	total=$((total + bytes))
	if [ "$DO_DELETE" = 1 ]; then
		rm -rf -- "${p:?}" && echo "$(stamp) removed  $(human "$bytes")  $p  ($why)"
	else
		echo "$(stamp) would remove  $(human "$bytes")  $p  ($why)"
	fi
}

[ -d "$SCRATCH_ROOT" ] || { [ "$DO_DELETE" = 1 ] || echo "$(stamp) nothing at $SCRATCH_ROOT"; exit 0; }

# 3. Stale worktrees first, while git still knows about them.
if [ -d "$REPO/.git" ]; then
	while read -r wt; do
		case "$wt" in "$SCRATCH_ROOT"/*) ;; *) continue;; esac
		[ -d "$wt" ] || continue
		if [ -z "$(find "$wt" -type f -mtime -"$KEEP_DAYS" -print -quit 2>/dev/null)" ]; then
			announce
			bytes=$(du -sb "$wt" 2>/dev/null | cut -f1); total=$((total + ${bytes:-0}))
			if [ "$DO_DELETE" = 1 ]; then
				git -C "$REPO" worktree remove --force "$wt" && echo "$(stamp) removed  $(human "${bytes:-0}")  $wt  (stale worktree)"
			else
				echo "$(stamp) would remove  $(human "${bytes:-0}")  $wt  (stale worktree)"
			fi
		fi
	done < <(git -C "$REPO" worktree list --porcelain 2>/dev/null | awk '/^worktree /{print $2}')
	[ "$DO_DELETE" = 1 ] && git -C "$REPO" worktree prune
fi

# 1. Dead session scratchpads: <project>/<session-uuid>/ with nothing newer than KEEP_DAYS.
for proj in "$SCRATCH_ROOT"/*/; do
	[ -d "$proj" ] || continue
	for sess in "$proj"*/; do
		[ -d "$sess" ] || continue
		case "$(basename "$sess")" in
			[0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f][0-9a-f]-*) ;;
			*) continue;;  # not a session dir
		esac
		if [ -z "$(find "$sess" -type f -mtime -"$KEEP_DAYS" -print -quit 2>/dev/null)" ]; then
			remove "${sess%/}" "session idle > ${KEEP_DAYS}d"
		fi
	done
done

# 2. Cold Rust build caches anywhere under the scratch root (live sessions included —
#    a target dir nobody has written to in TARGET_DAYS is a cache, not work).
#    Cargo stamps every target dir with CACHEDIR.TAG (cross-compile subdirs
#    too); sorting and skipping anything under an already-chosen dir keeps each
#    cache counted and removed once.
#    Caches rule 2 leaves are listed as "newest-write-epoch bytes path" for rule 4.
last=""
kept=()
# (Loops read from process substitution, not a pipe, so the running total survives.)
while read -r t; do
	[ -d "$t" ] || continue
	# (Guarded: with $last empty the pattern is "/*", which matches every path.)
	[ -n "$last" ] && case "$t" in "$last"/*) continue;; esac
	last="$t"
	if [ -z "$(find "$t" -type f -mtime -"$TARGET_DAYS" -print -quit 2>/dev/null)" ]; then
		remove "$t" "build cache idle > ${TARGET_DAYS}d"
	else
		newest=$(find "$t" -type f -printf '%T@\n' 2>/dev/null | awk '$1>m{m=$1} END{printf "%d", m}')
		kept+=("${newest:-0} $(du -sb "$t" 2>/dev/null | cut -f1) $t")
	fi
done < <(find "$SCRATCH_ROOT" -type f -name CACHEDIR.TAG -printf '%h\n' 2>/dev/null | sort)

# 4. Over the cap: coldest first, until under it or only in-use caches remain.
cap_bytes=$((CAP_GB * 1024 * 1024 * 1024))
in_use_after=$(( $(date +%s) - GRACE_HOURS * 3600 ))
cache_total=0
for k in "${kept[@]}"; do read -r _ b _ <<<"$k"; cache_total=$((cache_total + ${b:-0})); done
while read -r newest b t; do
	[ -n "${t:-}" ] || continue
	[ "$cache_total" -gt "$cap_bytes" ] || break
	[ "$newest" -lt "$in_use_after" ] || break   # sorted coldest first: the rest are in use
	remove "$t" "build caches over ${CAP_GB} GB cap" "$b"
	cache_total=$((cache_total - b))
done < <(printf '%s\n' "${kept[@]}" | sort -n)

[ "$announced" = 1 ] || [ "$DO_DELETE" = 0 ] || exit 0
announce
echo "$(stamp) total $( [ "$DO_DELETE" = 1 ] && echo freed || echo reclaimable ): $(human "$total"); build caches left: $(human "$cache_total") of ${CAP_GB} GB cap; free after: $(df -h --output=avail / | tail -1 | tr -d ' ')"
