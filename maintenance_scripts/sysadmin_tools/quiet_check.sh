#!/usr/bin/env bash
#
# quiet_check.sh - is anyone busy on this machine right now? ONE JSON object
# on stdout, counts only.
#
# Version: 1.1 - a scheduled-task run is busy only past TASK_RUN_SECONDS: the task that asks
#                runs one itself; the agents' own endpoints are never a person's visit or write
# Version: 1.0 - the quiet_check observe word of specs/automatic_reboot_when_quiet.md
#                WP1: the Reboot When Quiet task asks it before rebooting a machine
#                that owes a reboot. The agent runs this file, verified against the
#                release manifest, with one argument it derives itself (the site
#                root from its own config, never a value from the wire) and no stdin.
#
# THE CONTRACT, which tests/integration/quiet_check_gate.sh pins:
#
#   - ONE argument: the site root (the directory holding config/ and
#     public_html/), as the agent knows it, or nothing on a machine with no
#     site. Anything that is not an absolute path of plain characters is
#     treated as nothing.
#   - The window is fixed here (WINDOW_MINUTES), never taken from the wire.
#   - Busy, each signal named in "busy":
#       logins      a person logged in to the machine (who: SSH, console)
#       signed_in   a site session carrying a signed-in user changed in the
#                   window (PHP touches the file on every request)
#       writes      a POST, PUT, PATCH or DELETE from a browser in the site's
#                   access log in the window (the agents' /api/v1/agent/ calls
#                   are machines, never counted)
#       packages    dpkg or apt running
#       installers  the host's installers or the data root script hold their lock
#       site_work   a scheduled task run over TASK_RUN_SECONDS (the Reboot When
#                   Quiet task asks from one, every minute), a backup, a container replacement, an
#                   update run or a data root run among the processes
#       db_queries  a database query running over DB_LONG_SECONDS
#       mail_sent   mail a person sent (an authenticated submission) in the window
#     Browser page views (visits) are counted and NEVER make it busy: an
#     anonymous reader is not a person at work.
#   - A signal that applies here and cannot be read is "unknown", named in
#     "unknown", and counts as busy: quiet is proved, never assumed. One that
#     does not apply (no site, no PostgreSQL, no Postfix) is "not_reported".
#   - COUNTS ONLY. No request line, address, session, query or message
#     leaves the machine.
#   - Every key is ALWAYS present. Exit 0 whenever the object was printed.
#   - READ ONLY. Any awk: a new server may have mawk alone.

set -u
export LC_ALL=C

WINDOW_MINUTES=15
DB_LONG_SECONDS=5
TASK_RUN_SECONDS=120
CMD_TIMEOUT=10
TAIL_LINES=50000
ROOT="${QUIET_CHECK_ROOT:-}"

SITE="${1:-}"
[[ "$SITE" =~ ^/[A-Za-z0-9._/-]{1,250}$ && "$SITE" != *..* ]] || SITE=""
[[ -n "$SITE" && -d "${ROOT}${SITE}" ]] || SITE=""

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }

declare -A V=()
BUSY=()
UNKNOWN=()
# A count: busy when above zero. "unknown" is busy too; "not_reported" never.
# A count passed 0 is reported only (visits): never busy, never unknown.
count() {  # NAME VALUE [busy-if-positive: 1|0]
    V[$1]="$2"
    if [[ "${3:-1}" != 1 ]]; then
        return 0
    elif [[ "$2" == unknown ]]; then
        UNKNOWN+=("$1"); BUSY+=("$1")
    elif [[ "${3:-1}" == 1 && "$2" =~ ^[0-9]+$ && "$2" -gt 0 ]]; then
        BUSY+=("$1")
    fi
}

# The window's minutes as a log writes them, newest first: one per line.
minutes() {  # date FORMAT
    local i
    for (( i = 0; i <= WINDOW_MINUTES; i++ )); do
        date -d "-${i} minutes" +"$1"
    done
}

# --- A person logged in to the machine. -----------------------------------------
if command -v who >/dev/null 2>&1; then
    n="$(run who | grep -c .)"
    count logins "${n:-0}"
else
    count logins unknown
fi

# --- A person using the site. ---------------------------------------------------
BOTS='bot|crawl|spider|slurp|fetch|scan|monitor|uptime|preview|headless|lighthouse|python|curl|wget|go-http|java/|okhttp|axios|node-fetch|libwww|httpclient|joinery'
if [[ -z "$SITE" ]]; then
    count visits not_reported 0; count writes not_reported
    count signed_in not_reported
else
    log="${ROOT}${SITE}/logs/access.log"
    if [[ ! -r "$log" ]]; then
        count visits unknown 0; count writes unknown
    else
        pat="$(minutes '[%d/%b/%Y:%H:%M:')"
        lines="$( { [[ -r "${log}.1" && -n "$(find "${log}.1" -mmin -"$WINDOW_MINUTES" 2>/dev/null)" ]] && tail -n "$TAIL_LINES" "${log}.1"; \
            tail -n "$TAIL_LINES" "$log"; } 2>/dev/null | grep -F -f <(printf '%s\n' "$pat"))"
        # Fields split on the double quote: $1 holds the client, $2 the
        # request line, $6 the user agent (joinery_timed).
        read -r visits writes < <(printf '%s\n' "$lines" | awk -F'"' -v bots="$BOTS" '
            NF >= 6 {
                split($1, c, " ")
                if (c[1] == "127.0.0.1" || c[1] == "::1") next
                ua = tolower($6)
                if (index(ua, "mozilla/") == 0 || ua ~ bots) next
                split($2, r, " ")
                if (index(r[2], "/api/v1/agent/") == 1) next
                v++
                if (r[1] == "POST" || r[1] == "PUT" || r[1] == "PATCH" || r[1] == "DELETE") w++
            }
            END { printf "%d %d\n", v, w }')
        count visits "${visits:-0}" 0
        count writes "${writes:-0}"
    fi
    sess="${ROOT}/var/lib/php/sessions"
    if [[ ! -d "$sess" || ! -r "$sess" || ! -x "$sess" ]]; then
        count signed_in unknown
    else
        n="$(run find "$sess" -maxdepth 1 -type f -name 'sess_*' -mmin -"$WINDOW_MINUTES" -print0 \
            | xargs -0 -r grep -l -E 'loggedin\|b:1;|"loggedin";b:1;' 2>/dev/null | grep -c .)"
        count signed_in "${n:-0}"
    fi
fi

# --- Work under way. --------------------------------------------------------------
if pgrep -x 'dpkg|apt|apt-get' >/dev/null 2>&1; then count packages 1; else count packages 0; fi
held=0
for lock in "${ROOT}"/run/joinery/host-installers.*.lock "${ROOT}/run/joinery/data-root.lock"; do
    [[ -f "$lock" ]] || continue
    # 75 is a lock another holds; a file that cannot be opened is not a run.
    flock -E 75 -n "$lock" true 2>/dev/null
    (( $? != 75 )) || held=$((held + 1))
done
count installers "$held"
WORK='run_backup\.php|upload_backup\.php|verify_backup\.php|build_backup_download\.php|recreate_site\.sh|site_refresh\.sh|rebase_site_container\.sh|install_security_updates\.sh|joinery_data_root\.sh'
n="$(pgrep -c -f "$WORK" 2>/dev/null)"
# A scheduled-task run starts every minute and most end in seconds; one still
# running past TASK_RUN_SECONDS is real work.
long=0
for pid in $(pgrep -f 'process_scheduled_tasks\.php' 2>/dev/null); do
    age="$(ps -o etimes= -p "$pid" 2>/dev/null | tr -d ' ')"
    [[ "$age" =~ ^[0-9]+$ ]] && (( age > TASK_RUN_SECONDS )) && long=$((long + 1))
done
count site_work "$(( ${n:-0} + long ))"

if [[ -d "${ROOT}/etc/postgresql" ]]; then
    n="$(run runuser -u postgres -- psql -XtAqc "SELECT count(*) FROM pg_stat_activity WHERE backend_type = 'client backend' AND state = 'active' AND pid <> pg_backend_pid() AND now() - query_start > interval '${DB_LONG_SECONDS} seconds'" < /dev/null)"
    [[ "$n" =~ ^[0-9]+$ ]] && count db_queries "$n" || count db_queries unknown
else
    count db_queries not_reported
fi

# --- Mail a person sent. ------------------------------------------------------------
if [[ -d "${ROOT}/etc/postfix" ]]; then
    mlog="${ROOT}/var/log/mail.log"
    if [[ -r "$mlog" ]]; then
        pat="$( { minutes '%b %e %H:%M:'; minutes '%Y-%m-%dT%H:%M:'; } )"
        n="$(tail -n "$TAIL_LINES" "$mlog" 2>/dev/null | grep -F -f <(printf '%s\n' "$pat") | grep -c 'sasl_username=')"
        count mail_sent "${n:-0}"
    elif [[ -z "$ROOT" ]] && command -v journalctl >/dev/null 2>&1; then
        n="$(run journalctl -q --since "-${WINDOW_MINUTES}min" -o cat -t postfix/smtpd -t postfix/submission/smtpd -t postfix/smtps/smtpd | grep -c 'sasl_username=')"
        count mail_sent "${n:-0}"
    else
        count mail_sent unknown
    fi
else
    count mail_sent not_reported
fi

# --- The answer. -------------------------------------------------------------------
json_list() {
    local out="" x
    for x in "$@"; do out+="${out:+,}\"$x\""; done
    printf '[%s]' "$out"
}
val() { [[ "${V[$1]}" =~ ^[0-9]+$ ]] && printf '%s' "${V[$1]}" || printf '"%s"' "${V[$1]}"; }
quiet=true
(( ${#BUSY[@]} == 0 )) || quiet=false
printf '{"quiet":%s,"busy":%s,"unknown":%s,"window_minutes":%d,"logins":%s,"signed_in":%s,"visits":%s,"writes":%s,"packages":%s,"installers":%s,"site_work":%s,"db_queries":%s,"mail_sent":%s}\n' \
    "$quiet" "$(json_list "${BUSY[@]}")" "$(json_list "${UNKNOWN[@]}")" "$WINDOW_MINUTES" \
    "$(val logins)" "$(val signed_in)" "$(val visits)" "$(val writes)" "$(val packages)" "$(val installers)" \
    "$(val site_work)" "$(val db_queries)" "$(val mail_sent)"
exit 0
