#!/usr/bin/env bash
#
# largest_files.sh - this site's biggest files, as ONE JSON object on stdout:
# the files of a megabyte or more in the site tree and in the site's own folder
# on the data root, biggest first, with how many smaller ones were passed over.
#
# Version: 1.0 - the largest_files observe word (agent 1.100.0). disk_usage says
#                which directory holds the space; this names the files in it.
#                The agent runs this file, verified against the release
#                manifest, with no argv and no stdin.
#
# THE CONTRACT, which tests/integration/largest_files_gate.sh pins:
#
#   - It takes NOTHING. The trees are this file's own site (its location) and
#     that site's folder on the data root (/srv/joinery/sites/<site>), and
#     nothing else: not another site on the same host, not the machine.
#   - It names files, so the agent runs it only where the site's owner allows
#     log access, and redacts what it prints. A path is reduced to
#     [A-Za-z0-9._/@:+=-], every other character shown as ?, and capped.
#   - config/ is never walked: its file names are the site's secrets' names.
#   - Twenty-five files, each a megabyte or more. A tree of a million files
#     reports the twenty-five biggest and a count of the rest, so the object
#     is bounded by the script and never by the agent's output cap.
#   - -xdev, always: find never walks off the filesystem it started on, which
#     is why the data-root folder is walked from its own top.
#   - READ ONLY, under nice and ionice, and a walk that could not finish says
#     so (partial: true) rather than reporting a list that is quietly short.
#
# Runs on: anything with findutils and coreutils.

set -u
export LC_ALL=C

FIND_TIMEOUT=240        # seconds for one walk
MAX_FILES=25            # files reported
MIN_BYTES=1048576       # a file under this is counted, not named
MAX_PATH=200            # characters kept of any path
DATA_ROOT=/srv/joinery

SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SITE_NAME="$(basename "$SITE_ROOT")"
DATA_SITE="$DATA_ROOT/sites/$SITE_NAME"

safe_path() {
    local s
    s="$(printf '%s' "$1" | sed 's#[^A-Za-z0-9._/@:+=-]#?#g')"
    printf '%s' "${s:0:$MAX_PATH}"
}

# One walk: "<bytes>\t<YYYY-MM-DD>\t<path relative to ROOT>" for every regular
# file, config/ pruned. Polite by construction.
walk() {  # ROOT
    local nice_cmd=(nice -n 19)
    command -v ionice >/dev/null 2>&1 && nice_cmd=(ionice -c 3 nice -n 19)
    timeout "$FIND_TIMEOUT" "${nice_cmd[@]}" find "$1" -xdev \
        \( -path "$1/config" -prune \) -o -type f -printf '%s\t%TY-%Tm-%Td\t%P\n' 2>/dev/null
}

LIST="$(mktemp)"
trap 'rm -f "$LIST"' EXIT
partial=false
roots_json=""

add_root() {  # LABEL ROOT
    local label="$1" root="$2" rc
    [[ -d "$root" ]] || return 0
    walk "$root" | awk -v l="$label" -F'\t' 'NF>=3 {print $1 "\t" $2 "\t" l "\t" $3}' >> "$LIST"
    rc=${PIPESTATUS[0]}
    (( rc == 0 )) || partial=true
    [[ -n "$roots_json" ]] && roots_json+=","
    roots_json+="{\"label\":\"$label\",\"path\":\"$(safe_path "$root")\"}"
}

add_root site "$SITE_ROOT"
# The data-root folder only where it is a different filesystem from the site
# tree; where it is not, the first walk already covered whatever of it lies
# under the site.
if [[ -d "$DATA_SITE" ]] && [[ "$(stat -c %d "$DATA_SITE" 2>/dev/null)" != "$(stat -c %d "$SITE_ROOT" 2>/dev/null)" ]]; then
    add_root data "$DATA_SITE"
fi

total_files="$(wc -l < "$LIST" | tr -d ' ')"
big_files="$(awk -F'\t' -v m="$MIN_BYTES" '$1>=m' "$LIST" | wc -l | tr -d ' ')"
total_bytes="$(awk -F'\t' '{s+=$1} END {printf "%d", s+0}' "$LIST")"

printf '{'
printf '"roots":[%s],' "$roots_json"
printf '"files":['
first=1
while IFS=$'\t' read -r bytes day label path; do
    [[ -n "$path" ]] || continue
    (( first )) || printf ','
    first=0
    [[ "$day" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || day=""
    printf '{"root":"%s","path":"%s","bytes":%s,"modified":"%s"}' \
        "$label" "$(safe_path "$path")" "$bytes" "$day"
# Capped by awk, which reads to the end: head would close the pipe early, and
# where SIGPIPE is ignored sort then complains on stderr.
done < <(awk -F'\t' -v m="$MIN_BYTES" '$1>=m' "$LIST" | sort -t$'\t' -k1,1nr | awk -v n="$MAX_FILES" 'NR<=n')
printf '],'
printf '"files_total":%s,' "${total_files:-0}"
printf '"files_over_min":%s,' "${big_files:-0}"
printf '"bytes_total":%s,' "${total_bytes:-0}"
printf '"min_bytes":%s,' "$MIN_BYTES"
printf '"max_files":%s,' "$MAX_FILES"
printf '"partial":%s,' "$partial"
printf '"generated_at":%s' "$(date -u +%s)"
printf '}\n'
exit 0
