#!/usr/bin/env bash
#
# web_workers.sh - is the web tier out of workers? Apache's scoreboard and
# PHP-FPM's pool, as ONE JSON object on stdout.
#
# Version: 1.1 - a PHP-FPM log that is there and cannot be read counts unknown, never 0 (reviewer2 K6)
# Version: 1.0 - the web_workers observe word of
#                specs/slow_request_diagnosis_words.md WP3. The agent runs this
#                file, verified against the release manifest, with one argument
#                it derives itself (the site's own domain from its config,
#                never a value from the wire) and no stdin.
#
# THE CONTRACT, which tests/integration/web_workers_gate.sh pins:
#
#   - ONE argument: the site's domain, as the agent read it from the site's
#     own config, or nothing on a machine with no site. Anything that is not
#     a host name is treated as nothing.
#   - COUNTS ONLY. Apache's ?auto scoreboard (busy, idle, slots by state) and
#     PHP-FPM's ?json pool status. Never ?full, which lists each worker's
#     request URI and query string; never a request line, never a client.
#   - Both status pages are asked over loopback: the site's own name pinned
#     to 127.0.0.1, https first and then http, where the site vhost answers
#     them for local callers only (default_virtualhost.conf 2.10).
#   - The pool's configured ceiling (pm.max_children) is read from the pool
#     files of the PHP version Apache hands PHP to: the status page does not
#     say it. Only that one number is read; no line of a pool file is sent.
#   - How often the ceiling was hit in the last day, counted from PHP-FPM's
#     own log: the history the live status cannot give.
#   - Every key is ALWAYS present: a source that cannot be read is the string
#     "unknown", a machine with no site "not_reported". Exit 0 whenever the
#     object was printed.
#   - READ ONLY. Any awk: a new server may have mawk alone.

set -u
export LC_ALL=C

CMD_TIMEOUT=10
ROOT="${WEB_WORKERS_ROOT:-}"
LOOPBACK="${WEB_WORKERS_LOOPBACK:-127.0.0.1}"

DOMAIN="${1:-}"
[[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || DOMAIN=""

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }

# A status page over loopback, https first then http, the site's own name
# pinned so its vhost answers. Prints the body; fails when neither answers 200.
fetch() {  # PATH
    local scheme port body
    for scheme in https http; do
        [[ "$scheme" == https ]] && port=443 || port=80
        body="$(run curl -s -k --max-time 8 --max-filesize 65536 -o - -w '\n%{http_code}' \
            --resolve "${DOMAIN}:${port}:${LOOPBACK}" "${scheme}://${DOMAIN}${1}")" || continue
        [[ "$(tail -n 1 <<< "$body")" == "200" ]] || continue
        head -n -1 <<< "$body"
        return 0
    done
    return 1
}

# The PHP version Apache hands PHP to: conf-enabled's php<ver>-fpm.conf.
active_php() {
    local c
    for c in "${ROOT}"/etc/apache2/conf-enabled/php*-fpm.conf; do
        [[ -e "$c" ]] || continue
        c="$(basename "$c")"
        [[ "$c" =~ ^php([0-9]+\.[0-9]+)-fpm\.conf$ ]] && { printf '%s' "${BASH_REMATCH[1]}"; return 0; }
    done
    return 1
}

emit_apache() {
    local body
    [[ -n "$DOMAIN" ]] || { printf '"not_reported"'; return; }
    body="$(fetch '/server-status?auto')" || { printf '"unknown"'; return; }
    awk -F': ' '
        $1 == "BusyWorkers" && $2 ~ /^[0-9]+$/ { busy = $2 }
        $1 == "IdleWorkers" && $2 ~ /^[0-9]+$/ { idle = $2 }
        $1 == "Scoreboard" { sb = $2 }
        END {
            if (busy == "" || idle == "") { printf "\"unknown\""; exit }
            n = split("_SRWKDCLGI.", keys, "")
            names["_"] = "waiting"; names["S"] = "starting"; names["R"] = "reading"; names["W"] = "writing"
            names["K"] = "keepalive"; names["D"] = "dns"; names["C"] = "closing"; names["L"] = "logging"
            names["G"] = "finishing"; names["I"] = "idle_cleanup"; names["."] = "open_slot"
            for (i = 1; i <= length(sb); i++) { ch = substr(sb, i, 1); if (ch in names) cnt[names[ch]]++ }
            printf "{\"busy\":%d,\"idle\":%d,\"slots\":%d,\"scoreboard\":{", busy, idle, length(sb)
            sep = ""
            for (i = 1; i <= n; i++) { k = names[keys[i]]; printf "%s\"%s\":%d", sep, k, cnt[k] + 0; sep = "," }
            printf "}}"
        }' <<< "$body"
}

# The pool's pm.max_children as configured, last value wins in file order,
# [www] only. Empty when no file says.
pool_max_children() {
    local ver="$1"
    cat "${ROOT}/etc/php/${ver}/fpm/pool.d/"*.conf 2>/dev/null | awk '
        /^[ \t]*\[/ { sec = $0; gsub(/[ \t\[\]]/, "", sec); next }
        sec == "www" && /^[ \t]*pm\.max_children[ \t]*=/ { v = $0; sub(/.*=[ \t]*/, "", v); gsub(/[^0-9]/, "", v); if (v != "") m = v }
        END { if (m != "") print m }'
}

emit_fpm() {
    local body ver max
    [[ -n "$DOMAIN" ]] || { printf '"not_reported"'; return; }
    body="$(fetch '/fpm-status?json')" || { printf '"unknown"'; return; }
    ver="$(active_php)"
    max=""
    [[ -n "$ver" ]] && max="$(pool_max_children "$ver")"
    # Only the numeric fields, by name; the pool's name reduced to a safe set.
    php -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($d)) { echo "\"unknown\""; exit; }
        $num = function ($k) use ($d) { return isset($d[$k]) && is_int($d[$k]) ? $d[$k] : null; };
        $out = array(
            "pool"                 => substr(preg_replace("/[^A-Za-z0-9._-]/", "", (string)($d["pool"] ?? "")), 0, 64),
            "process_manager"      => substr(preg_replace("/[^a-z]/", "", (string)($d["process manager"] ?? "")), 0, 16),
            "active"               => $num("active processes"),
            "idle"                 => $num("idle processes"),
            "total"                => $num("total processes"),
            "max_active"           => $num("max active processes"),
            "listen_queue"         => $num("listen queue"),
            "max_listen_queue"     => $num("max listen queue"),
            "max_children_reached" => $num("max children reached"),
            "accepted"             => $num("accepted conn"),
            "start_since_seconds"  => $num("start since"),
        );
        $m = $argv[1] ?? "";
        $out["max_children"] = preg_match("/^[0-9]{1,5}$/", $m) ? (int)$m : null;
        echo json_encode($out);
    ' -- "$max" <<< "$body" 2>/dev/null || printf '"unknown"'
}

# Lines saying the pool reached its ceiling, in the last 24 hours, from the
# current log and its first rotation.
emit_max_children_events() {
    local ver log since
    ver="$(active_php)" || { printf '"unknown"'; return; }
    log="${ROOT}/var/log/php${ver}-fpm.log"
    [[ -e "$log" || -e "${log}.1" ]] || { printf '0'; return; }
    # A log that is there and cannot be read is not a log with nothing in it.
    { [[ ! -e "$log" || -r "$log" ]] && [[ ! -e "${log}.1" || -r "${log}.1" ]]; } || { printf '"unknown"'; return; }
    # PHP-FPM stamps its log in the machine's local time as [23-Oct-2026
    # 17:08:27]; each stamp becomes YYYYMMDDHHMMSS and is compared as a
    # string, which any awk can do (mawk has no mktime).
    since="$(date -d '24 hours ago' +%Y%m%d%H%M%S)"
    cat "${log}.1" "$log" 2>/dev/null | awk -v since="$since" '
        BEGIN { split("Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec", m, " "); for (i = 1; i <= 12; i++) mon[m[i]] = sprintf("%02d", i) }
        /reached pm\.max_children setting/ {
            if (substr($0, 1, 1) != "[" || substr($0, 22, 1) != "]") next
            d = substr($0, 2, 2); mo = substr($0, 5, 3); y = substr($0, 9, 4); t = substr($0, 14, 8)
            if (!(mo in mon) || d !~ /^[0-9][0-9]$/ || y !~ /^[0-9][0-9][0-9][0-9]$/) next
            gsub(/:/, "", t)
            if (t !~ /^[0-9][0-9][0-9][0-9][0-9][0-9]$/) next
            if ((y mon[mo] d t) >= since) n++
        }
        END { printf "%d", n + 0 }'
}

printf '{"site":"%s","apache":%s,"fpm":%s,"fpm_max_children_events_24h":%s,"generated_at":%d}\n' \
    "$DOMAIN" "$(emit_apache)" "$(emit_fpm)" "$(emit_max_children_events)" "$(date +%s)"
exit 0
