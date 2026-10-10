#!/usr/bin/env bash
#
# disk_usage.sh - where the space went, as ONE JSON object on stdout: the
# filesystem's own figures, the site tree's biggest directories to depth two,
# and the machine directories that are usually the answer when a site tree is
# not.
#
# Version: 1.1 - data_root: on a host with a data root (/srv/joinery, specs/one_data_root.md),
#                its own figures and its biggest directories to depth three. A site's folders
#                and Postgres are bind mounts of it, so the -x walk of the site tree stops at
#                them; this is where their space shows. "none" on a host without one.
# Version: 1.0 - the disk_usage observe word of
#                specs/disk_headroom_and_unit_diagnosis.md § 11. The agent runs
#                this file, verified against the release manifest, with no argv
#                and no stdin.
#
# THE CONTRACT, which tests/integration/disk_usage_gate.sh pins:
#
#   - It takes NOTHING. The site tree comes from this file's own location and
#     the machine directories are a compiled list. A caller cannot name a
#     directory, and there is no argument for a future edit to make
#     wire-supplied.
#   - SIZES ONLY. A directory name and a byte count. No file names, no file
#     counts, no modification times, no owners: "where did the space go" is
#     answerable without describing anyone's content, and this word answers
#     only that.
#   - Depth two, twenty entries. A tree with ten thousand directories reports
#     the twenty biggest of the two shallowest levels, so the object is bounded
#     by the script and never by the agent's output cap.
#   - Every path is reduced to [A-Za-z0-9._/@:-] and capped, so there is never
#     anything to escape and nothing a directory was named can break the JSON.
#   - -x, always: du never walks off the filesystem it started on. A bind mount
#     or a network mount under the tree is somebody else's disk and not this
#     figure's business. The data root is the one other disk reported, walked
#     from its own top, and only where it is mounted.
#   - READ ONLY, and deliberately polite: nice and ionice, because walking a
#     large tree on a small box is the one read in this vocabulary that costs
#     the machine something.
#   - A run that could not read everything says so (partial: true) rather than
#     reporting a total that is quietly too small.
#
# Runs on: anything with coreutils. Nothing here needs systemd.

set -u
export LC_ALL=C

DU_TIMEOUT=240          # seconds for one walk
MAX_ENTRIES=20          # directories reported from the tree
MAX_PATH=200            # characters kept of any path
TREE_DEPTH=2
# The data root, where a host has one: depth three reaches sites/<site>/<folder>.
DATA_ROOT=/srv/joinery
DATA_ROOT_DEPTH=3

SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WEB_ROOT="$SITE_ROOT/public_html"
[[ -d "$WEB_ROOT" ]] || WEB_ROOT="/"

# THE COMPILED LIST. Where a machine's space goes when it is not the site: the
# logs, the database, the package caches, and the backups the site keeps.
MACHINE_DIRS=(
    /var/log
    /var/lib/postgresql
    /var/cache
    /var/backups
    /var/lib/docker
)

safe_path() {
    local s="${1//[^A-Za-z0-9._\/@:-]/}"
    printf '%s' "${s:0:$MAX_PATH}"
}
json_path() { printf '"%s"' "$(safe_path "$1")"; }
json_num_or_unknown() {
    if [[ "${1:-}" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '"unknown"'; fi
}

# Polite by construction: a du over tens of gigabytes should never be the
# reason a site got slow while somebody was diagnosing why it was full.
du_run() {
    local target="$1" depth="${2:-}" nice_cmd=(nice -n 19)
    command -v ionice >/dev/null 2>&1 && nice_cmd=(ionice -c 3 nice -n 19)
    if [[ -n "$depth" ]]; then
        timeout "$DU_TIMEOUT" "${nice_cmd[@]}" du -x -b --max-depth="$depth" "$target" 2>/dev/null
    else
        timeout "$DU_TIMEOUT" "${nice_cmd[@]}" du -x -b -s "$target" 2>/dev/null
    fi
}

# ---------------------------------------------------------------------------
# The filesystem, from df — the same figures host_report carries, repeated here
# on purpose so one answer explains itself without a second word.
# ---------------------------------------------------------------------------
emit_filesystem() {  # PATH
    local line used total avail
    line="$(timeout 10 df -B1 --output=used,size,avail "$1" 2>/dev/null | tail -n 1)"
    read -r used total avail <<< "$line"
    printf '{"path":%s,"used_bytes":%s,"total_bytes":%s,"avail_bytes":%s}' \
        "$(json_path "$1")" \
        "$(json_num_or_unknown "${used:-}")" "$(json_num_or_unknown "${total:-}")" \
        "$(json_num_or_unknown "${avail:-}")"
}

# ---------------------------------------------------------------------------
# A tree: its own total, and its biggest directories to DEPTH, with paths
# relative to the tree so the object never repeats its root.
# ---------------------------------------------------------------------------
emit_tree() {  # ROOT DEPTH
    local root="$1" out rc total entries n=0 first=1 bytes path rel
    out="$(du_run "$root" "$2")"; rc=$?
    if [[ -z "$out" ]]; then
        printf '{"path":%s,"total_bytes":"unknown","partial":true,"entries":[]}' "$(json_path "$root")"
        return
    fi
    total="$(printf '%s\n' "$out" | awk -v r="$root" -F'\t' '$2==r {print $1; exit}')"
    printf '{"path":%s,' "$(json_path "$root")"
    printf '"total_bytes":%s,' "$(json_num_or_unknown "${total:-}")"
    printf '"partial":%s,' "$( (( rc == 0 )) && printf 'false' || printf 'true' )"
    printf '"entries":['
    entries="$(printf '%s\n' "$out" | awk -v r="$root" -F'\t' '$2!=r' | sort -t$'\t' -k1,1nr)"
    while IFS=$'\t' read -r bytes path; do
        [[ -n "$path" ]] || continue
        (( n < MAX_ENTRIES )) || break
        rel="${path#"$root"/}"
        (( first )) || printf ','
        first=0
        printf '{"path":%s,"bytes":%s}' "$(json_path "$rel")" "$(json_num_or_unknown "$bytes")"
        n=$((n+1))
    done <<< "$entries"
    printf ']}'
}

# ---------------------------------------------------------------------------
# The data root, where it is mounted: its figures and its tree. "none" where
# this host has none mounted, which is an answer too.
# ---------------------------------------------------------------------------
emit_data_root() {
    if ! timeout 10 findmnt -n --mountpoint "$DATA_ROOT" >/dev/null 2>&1; then
        printf '"none"'
        return
    fi
    printf '{"filesystem":%s,' "$(emit_filesystem "$DATA_ROOT")"
    printf '"tree":%s,' "$(emit_tree "$DATA_ROOT" "$DATA_ROOT_DEPTH")"
    printf '"depth":%s}' "$DATA_ROOT_DEPTH"
}

# ---------------------------------------------------------------------------
# The machine directories, one total each. A directory that is not there is
# absent, which is an answer; one that could not be read is unknown, which is
# a different answer.
# ---------------------------------------------------------------------------
emit_machine() {
    local dir out bytes first=1
    printf '['
    for dir in "${MACHINE_DIRS[@]}"; do
        (( first )) || printf ','
        first=0
        if [[ ! -d "$dir" ]]; then
            printf '{"path":%s,"bytes":"absent"}' "$(json_path "$dir")"
            continue
        fi
        out="$(du_run "$dir")"
        bytes="$(printf '%s\n' "$out" | awk -F'\t' 'NR==1 {print $1}')"
        printf '{"path":%s,"bytes":%s}' "$(json_path "$dir")" "$(json_num_or_unknown "${bytes:-}")"
    done
    printf ']'
}

# ---------------------------------------------------------------------------
# The object. One line, every key, in this order.
# ---------------------------------------------------------------------------
printf '{'
printf '"filesystem":%s,' "$(emit_filesystem "$WEB_ROOT")"
printf '"tree":%s,' "$(emit_tree "$SITE_ROOT" "$TREE_DEPTH")"
printf '"data_root":%s,' "$(emit_data_root)"
printf '"machine":%s,' "$(emit_machine)"
printf '"depth":%s,' "$TREE_DEPTH"
printf '"max_entries":%s,' "$MAX_ENTRIES"
printf '"generated_at":%s' "$(date -u +%s)"
printf '}\n'
exit 0
