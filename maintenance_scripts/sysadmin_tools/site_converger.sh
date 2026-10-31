#!/usr/bin/env bash
#
# site_converger.sh - whether one site container's host converger is running,
# read from the host it runs on, as ONE JSON object on stdout: when the
# converger last ran and how that ended, whether its converge stamp is there,
# the site's code check, whether the container carries the converger's cron
# entry and has cron running, and the last N lines of its converger log.
#
# Version: 1.0 - the site_converger observe word (specs/agent_recipes_and_vocabulary.md,
#                running list). se1's site sa ran no converge for four days while its
#                cron ran its scheduled tasks (incident 10975); the host's agent could see
#                the code check go stale and nothing that said why.
#
# THE CONTRACT, which tests/integration/site_converger_gate.sh pins:
#
#   - TWO arguments. The site is a name of the compiled shape AND a container
#     on this host whose SITENAME is that name (the check hold_container.sh
#     makes): a name alone proves nothing. The line count is 1..200 and is
#     clamped here, not trusted.
#   - It reads the site's own files, from the site's named volumes (cache,
#     logs, config) and its running root filesystem (/etc/cron.d), as root,
#     and every one of them is the site's to plant: a link is never followed,
#     a folder on the way that is a link is not entered, and only a regular
#     file is read, under a timeout. A volume is the one the container mounts
#     at the site's own folder, under a path of the engine's own shape; a bind
#     mount is never read.
#   - Every key is ALWAYS present. A fact this run cannot read is the string
#     unknown, or false, for that key and never an error for the whole
#     object; the exit code is 0 whenever the object was printed.
#   - The log text is the one thing here that is not a compiled fact, and it
#     is handled as hostile, as unit_journal.sh handles the journal: control
#     characters and invalid UTF-8 dropped, quotes and backslashes escaped,
#     each line and the count capped. The AGENT redacts what this prints
#     before it leaves the machine (ScriptSpec.Redact). The cron entry is
#     never carried as text: only whether it is there, whether it runs this
#     site's converger, and when it and its folder last changed.
#   - READ ONLY: the engine's ps and inspect, /proc, and file reads. Nothing
#     here starts, stops, writes or signals anything.
#
# Runs on: a host whose sites run in containers (Docker or the site container
# engine). Anywhere else it refuses: there is no container to name.

set -u
export LC_ALL=C

CMD_TIMEOUT=20          # seconds per external command
MAX_LINES=200           # the agent's cap, mirrored
MAX_LINE_CHARS=2000     # one log line, capped
MAX_READ_BYTES=65536    # what is read from the end of the log before lines are taken

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }
json_num_or_unknown() {
    if [[ "${1:-}" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '"unknown"'; fi
}
json_safe() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '"%s"' "${s:0:64}"
}
json_bool() { if [[ "$1" == 1 ]]; then printf 'true'; else printf 'false'; fi; }
refuse() {
    printf 'site_converger: %s\n' "$1" >&2
    exit 2
}

SITE="${1:-}"
LINES="${2:-100}"
[[ "$SITE" =~ ^[a-z0-9][a-z0-9_-]{0,49}$ ]] || refuse "${SITE:0:64} is not a site this node will read"
[[ "$LINES" =~ ^[0-9]+$ ]] || LINES=100
(( LINES >= 1 )) || LINES=1
(( LINES <= MAX_LINES )) || LINES=$MAX_LINES

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ -f "$HERE/../install_tools/_site_engine.sh" ]] || refuse "there is no install_tools/_site_engine.sh beside this script's tree; nothing was read"
. "$HERE/../install_tools/_site_engine.sh"
ENGINE="$(site_engine_bin)" || refuse "this machine's container engine could not be named; nothing was read"
command -v "$ENGINE" >/dev/null 2>&1 || refuse "this machine has no ${ENGINE}"

# The host's own list: a container named SITE whose SITENAME is SITE.
own_container() {
    local c site
    for c in $(run "$ENGINE" ps -a --format '{{.Names}}'); do
        [[ "$c" == "$1" ]] || continue
        site="$(run "$ENGINE" inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$c" | awk -F= '$1=="SITENAME" {print $2; exit}')"
        [[ "$site" == "$c" ]] && return 0
    done
    return 1
}
own_container "$SITE" || refuse "$SITE is not a site container on this host"

STATE="$(run "$ENGINE" inspect -f '{{.State.Status}}' "$SITE")"
PID="$(run "$ENGINE" inspect -f '{{.State.Pid}}' "$SITE")"
[[ "$PID" =~ ^[1-9][0-9]*$ ]] || PID=""

# The volume the container mounts at /var/www/html/SITE/WHAT, when it is a
# named volume of the engine's shape (.../SITE_WHAT/_data). Empty otherwise.
MOUNTS="$(run "$ENGINE" inspect -f '{{range .Mounts}}{{.Destination}}|{{.Source}}{{println}}{{end}}' "$SITE")"
volume_dir() {  # WHAT
    local dest src
    while IFS='|' read -r dest src; do
        [[ "$dest" == "/var/www/html/${SITE}/$1" ]] || continue
        [[ "$src" =~ ^/[A-Za-z0-9._/-]+/${SITE}_$1/_data$ && "$src" != *..* ]] || continue
        [[ -d "$src" && ! -L "$src" ]] || continue
        printf '%s' "$src"
        return 0
    done <<< "$MOUNTS"
}
CACHE_DIR="$(volume_dir cache)"
LOGS_DIR="$(volume_dir logs)"
CONFIG_DIR="$(volume_dir config)"

# A file of the site's that may be read: a regular file, not a link.
readable() { [[ -n "$1" && -f "$1" && ! -L "$1" ]]; }
mtime_of() { run stat -c %Y -- "$1"; }

# --- The last run ------------------------------------------------------------
# cache/host_converger.last is "<unix seconds> <outcome>", written by the
# converger at the start and end of every run (record_last).
LAST_AT=""; LAST_OUTCOME="unknown"; LAST_PRESENT=0; STAMP=0
if [[ -n "$CACHE_DIR" ]]; then
    f="$CACHE_DIR/host_converger.last"
    if readable "$f"; then
        LAST_PRESENT=1
        read -r LAST_AT LAST_OUTCOME < <(run head -c 200 -- "$f" | head -n 1)
        [[ "${LAST_AT:-}" =~ ^[0-9]+$ ]] || LAST_AT=""
        LAST_OUTCOME="${LAST_OUTCOME:-unknown}"
    fi
    readable "$CACHE_DIR/host_converger.stamp" && STAMP=1
fi

# --- The code check ----------------------------------------------------------
# config/code_check's first line is "ok AT" or "failed AT" (site_housekeeping.sh).
CODE_STATE="none"; CODE_AT=""
if [[ -z "$CONFIG_DIR" ]]; then
    CODE_STATE="unknown"
elif [[ -e "$CONFIG_DIR/code_check" || -L "$CONFIG_DIR/code_check" ]]; then
    CODE_STATE="unknown"
    if readable "$CONFIG_DIR/code_check"; then
        first="$(run head -c 200 -- "$CONFIG_DIR/code_check" | head -n 1)"
        if [[ "$first" =~ ^(ok|failed)\ ([0-9TZ:.-]{1,40})$ ]]; then
            CODE_STATE="${BASH_REMATCH[1]}"; CODE_AT="${BASH_REMATCH[2]}"
        fi
    fi
fi

# --- cron inside the container ----------------------------------------------
# The entry install_host_converger.sh writes, read from the container's running
# root filesystem through /proc/PID/root. Neither etc nor etc/cron.d may be a
# link: the container's root could point either anywhere on this host. cron
# loads /etc/cron.d by the folder's whole-second mtime, so both times are
# carried: an entry written in the second the folder last changed before
# cron's load is never read.
CRON_ENTRY=0; CRON_RUNS_SITE=0; CRON_ENTRY_AT=""; CRON_DIR_AT=""; CRON_RUNNING="unknown"
if [[ -n "$PID" && "$STATE" == "running" ]]; then
    root="/proc/${PID}/root"
    if [[ -d "$root/etc" && ! -L "$root/etc" && -d "$root/etc/cron.d" && ! -L "$root/etc/cron.d" ]]; then
        CRON_DIR_AT="$(mtime_of "$root/etc/cron.d")"
        entry="$root/etc/cron.d/joinery-host-converger"
        if readable "$entry"; then
            CRON_ENTRY=1
            CRON_ENTRY_AT="$(mtime_of "$entry")"
            run head -c 4096 -- "$entry" | grep -v '^[[:space:]]*#' \
                | grep -qE "joinery-host-converger --when-changed ${SITE}( |$)" && CRON_RUNS_SITE=1
        fi
    fi
    # cron running: a process named cron in the container's pid namespace.
    ns="$(readlink "/proc/${PID}/ns/pid" 2>/dev/null)"
    if [[ -n "$ns" ]]; then
        CRON_RUNNING="false"
        for p in /proc/[0-9]*; do
            [[ "$(cat "$p/comm" 2>/dev/null)" == "cron" ]] || continue
            [[ "$(readlink "$p/ns/pid" 2>/dev/null)" == "$ns" ]] && { CRON_RUNNING="true"; break; }
        done
    fi
fi

# --- The converger log -------------------------------------------------------
LOG_PRESENT=0; LOG_SIZE=""; LOG_AT=""
emit_lines() {
    local f="$1" raw n=0 first=1 line out='['
    raw="$(run tail -c "$MAX_READ_BYTES" -- "$f" | tail -n "$LINES")" || { printf '0:[]'; return; }
    [[ -n "$raw" ]] || { printf '0:[]'; return; }
    if command -v iconv >/dev/null 2>&1; then
        raw="$(printf '%s\n' "$raw" | iconv -f UTF-8 -t UTF-8 -c)"
    else
        raw="$(printf '%s\n' "$raw" | tr -cd '\11\12\15\40-\176')"
    fi
    while IFS= read -r line; do
        (( n < LINES )) || break
        line="$(printf '%s' "$line" | tr '\011' ' ' | tr -d '\000-\037\177')"
        line="${line//\\/\\\\}"
        line="${line//\"/\\\"}"
        line="${line:0:$MAX_LINE_CHARS}"
        (( first )) || out+=','
        first=0
        out+="\"$line\""
        n=$((n+1))
    done <<< "$raw"
    out+=']'
    printf '%s:%s' "$n" "$out"
}
EMITTED="0:[]"
if [[ -n "$LOGS_DIR" ]] && readable "$LOGS_DIR/host_converger.log"; then
    LOG_PRESENT=1
    LOG_SIZE="$(run stat -c %s -- "$LOGS_DIR/host_converger.log")"
    LOG_AT="$(mtime_of "$LOGS_DIR/host_converger.log")"
    EMITTED="$(emit_lines "$LOGS_DIR/host_converger.log")"
fi
LINES_RETURNED="${EMITTED%%:*}"
LOG_LINES="${EMITTED#*:}"
[[ "$LINES_RETURNED" =~ ^[0-9]+$ ]] || LINES_RETURNED=0

# --- The object --------------------------------------------------------------
printf '{'
printf '"site":%s,' "$(json_safe "$SITE")"
printf '"state":%s,' "$(json_safe "${STATE:-unknown}")"
printf '"now_unix":%s,' "$(date -u +%s)"
printf '"last_run":{"present":%s,"at_unix":%s,"outcome":%s},' "$(json_bool "$LAST_PRESENT")" "$(json_num_or_unknown "$LAST_AT")" "$(json_safe "$LAST_OUTCOME")"
printf '"stamp_present":%s,' "$(json_bool "$STAMP")"
printf '"code_check":{"state":%s,"at":%s},' "$(json_safe "$CODE_STATE")" "$(json_safe "${CODE_AT:-unknown}")"
printf '"cron":{"entry_present":%s,"entry_runs_this_site":%s,"entry_mtime_unix":%s,"folder_mtime_unix":%s,"cron_running":%s},' \
    "$(json_bool "$CRON_ENTRY")" "$(json_bool "$CRON_RUNS_SITE")" "$(json_num_or_unknown "$CRON_ENTRY_AT")" \
    "$(json_num_or_unknown "$CRON_DIR_AT")" "$( [[ "$CRON_RUNNING" == unknown ]] && printf '"unknown"' || printf '%s' "$CRON_RUNNING")"
printf '"log":{"present":%s,"size_bytes":%s,"mtime_unix":%s,"lines_requested":%s,"lines_returned":%s,"lines":%s}' \
    "$(json_bool "$LOG_PRESENT")" "$(json_num_or_unknown "$LOG_SIZE")" "$(json_num_or_unknown "$LOG_AT")" \
    "$LINES" "$LINES_RETURNED" "$LOG_LINES"
printf '}\n'
exit 0
