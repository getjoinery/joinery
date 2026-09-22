#!/usr/bin/env bash
#
# unit_journal.sh - why one systemd unit is in the state it is in, as ONE JSON
# object on stdout: the unit's state, the result systemd recorded, its exit
# status, when it last ran, and the last N lines of its journal.
#
# Version: 1.0 - the unit_journal observe word of
#                specs/disk_headroom_and_unit_diagnosis.md § 8. The agent runs
#                this file, verified against the release manifest, with two
#                argv elements it has already validated.
#
# THE CONTRACT, which tests/integration/unit_journal_gate.sh pins:
#
#   - TWO arguments, both closed. The unit must be a member of the compiled
#     list below — the list is a MIRROR of the enum in the agent's
#     observe_unit_journal.go, and either alone refuses. There is no path, no
#     pattern, no "all units", and no way to name a unit that is not here.
#   - The line count is 1..200 and is clamped here, not trusted.
#   - Every key is ALWAYS present. A fact this run cannot read is the string
#     unknown for that key and never an error for the whole object; the exit
#     code is 0 whenever the object was printed.
#   - The journal text is the one thing here that is not a compiled fact, and
#     it is handled as hostile: control characters are dropped, invalid UTF-8
#     is dropped, quotes and backslashes are escaped, each line is capped, and
#     the number of lines is capped. The AGENT redacts what this prints before
#     it leaves the machine (ScriptSpec.Redact) — the node is the last place
#     that sees a credential, an address or a mail recipient in a log line.
#     A tab becomes a space and every other control character is dropped: an
#     unescaped control character inside a JSON string is what makes an object
#     unparseable at the other end.
#   - READ ONLY: systemctl show, journalctl. Nothing here starts, stops or
#     resets anything.
#
# Runs on: any systemd host. A machine without systemd prints the object with
# unknowns and an empty line list.

set -u
export LC_ALL=C

CMD_TIMEOUT=20          # seconds per external command
MAX_LINES=200           # the agent's cap, mirrored
MAX_LINE_CHARS=2000     # one journal line, capped

# THE CLOSED LIST. A mirror of unitJournalUnits in the agent's
# observe_unit_journal.go and of UNIT_JOURNAL_UNITS in the plane's
# JobCommandBuilder. Three copies on purpose: the plane offers the list, the
# agent validates against it, and the node refuses anything outside it whatever
# the other two say.
#
# The five the Host card already names, the agent itself, and the housekeeping
# units that turn up failed on an ordinary Debian box — which is the whole
# reason this word exists: man-db.service failed on a node and the plane could
# name it and not ask why.
UNITS=(
    fail2ban apache2 cron postgresql
    joinery-agent
    man-db unattended-upgrades logrotate
    apt-daily apt-daily-upgrade
    fstrim e2scrub_all
)

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }
json_num_or_unknown() {
    if [[ "${1:-}" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '"unknown"'; fi
}
# A compiled fact (a state, a result): reduced to a safe character set, so
# there is never anything to escape.
json_safe() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '"%s"' "${s:0:64}"
}

# ---------------------------------------------------------------------------
# Arguments. Refusing here is the point of the word: an argument outside the
# list leaves with a reason on stderr and nothing read.
# ---------------------------------------------------------------------------
UNIT="${1:-}"
LINES="${2:-100}"

ok=0
for u in "${UNITS[@]}"; do [[ "$UNIT" == "$u" ]] && { ok=1; break; }; done
if (( ! ok )); then
    printf 'unit_journal: %s is not a unit this node will read\n' "${UNIT:0:64}" >&2
    exit 2
fi
[[ "$LINES" =~ ^[0-9]+$ ]] || LINES=100
(( LINES >= 1 )) || LINES=1
(( LINES <= MAX_LINES )) || LINES=$MAX_LINES

SERVICE="${UNIT}.service"

# ---------------------------------------------------------------------------
# What systemd says about the unit. ExecMainStatus is the number that answers
# "why did it fail" more often than the journal does: 1 is the unit's own
# error, 137 is a SIGKILL (the OOM killer), and Result names the kind.
# ---------------------------------------------------------------------------
show_value() {
    local prop="$1" v
    v="$(run systemctl show -p "$prop" --value "$SERVICE")" || { printf 'unknown'; return; }
    [[ -n "$v" ]] && printf '%s' "$v" || printf 'unknown'
}

LOAD_STATE="$(show_value LoadState)"
ACTIVE_STATE="$(show_value ActiveState)"
SUB_STATE="$(show_value SubState)"
RESULT="$(show_value Result)"
EXIT_STATUS="$(show_value ExecMainStatus)"
# A timestamp is the one compiled fact carrying spaces, so it never reaches the
# object as text: it is converted to seconds here, or it is unknown.
LAST_RUN="$(show_value ExecMainExitTimestamp)"
LAST_RUN_UNIX=""
if [[ "$LAST_RUN" != "unknown" && -n "$LAST_RUN" ]]; then
    LAST_RUN_UNIX="$(run date -u -d "$LAST_RUN" +%s)" || LAST_RUN_UNIX=""
fi

# ---------------------------------------------------------------------------
# The journal. Hostile text, handled as such: drop control characters and
# invalid UTF-8, escape what JSON needs, cap the line and cap the count.
# ---------------------------------------------------------------------------
# Prints "<count>:<json array>". The count comes back beside the array because
# this runs in a subshell to be captured, and because counting separators in
# the finished array would count any a journal line happened to contain.
emit_lines() {
    local raw n=0 first=1 line
    if [[ "$LOAD_STATE" == "unknown" ]]; then printf '0:[]'; return; fi
    raw="$(run journalctl -u "$SERVICE" -n "$LINES" --no-pager -o short-iso)" || { printf '0:[]'; return; }
    [[ -n "$raw" ]] || { printf '0:[]'; return; }
    # iconv drops invalid UTF-8 where it exists; where it does not, tr keeps
    # the object valid by dropping everything outside printable ASCII.
    if command -v iconv >/dev/null 2>&1; then
        raw="$(printf '%s\n' "$raw" | iconv -f UTF-8 -t UTF-8 -c)"
    else
        raw="$(printf '%s\n' "$raw" | tr -cd '\11\12\15\40-\176')"
    fi
    local out='['
    while IFS= read -r line; do
        (( n < LINES )) || break
        # A tab becomes a space and every other control character goes. JSON
        # forbids an unescaped control character inside a string, and a raw tab
        # from a journal line is exactly the byte that would make this object
        # unparseable on the plane — dropping the tab outright would run words
        # together, so it becomes the space it was standing in for.
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

EMITTED="$(emit_lines)"
LINES_RETURNED="${EMITTED%%:*}"
JOURNAL="${EMITTED#*:}"
[[ "$LINES_RETURNED" =~ ^[0-9]+$ ]] || LINES_RETURNED=0

# ---------------------------------------------------------------------------
# The object. One line, every key, in this order.
# ---------------------------------------------------------------------------
printf '{'
printf '"unit":%s,' "$(json_safe "$SERVICE")"
printf '"load_state":%s,' "$(json_safe "$LOAD_STATE")"
printf '"active_state":%s,' "$(json_safe "$ACTIVE_STATE")"
printf '"sub_state":%s,' "$(json_safe "$SUB_STATE")"
printf '"result":%s,' "$(json_safe "$RESULT")"
printf '"exit_status":%s,' "$(json_num_or_unknown "$EXIT_STATUS")"
printf '"last_run_unix":%s,' "$(json_num_or_unknown "${LAST_RUN_UNIX:-}")"
printf '"lines_requested":%s,' "$LINES"
printf '"lines_returned":%s,' "$LINES_RETURNED"
printf '"journal":%s' "$JOURNAL"
printf '}\n'
exit 0
