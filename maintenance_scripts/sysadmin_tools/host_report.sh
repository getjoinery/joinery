#!/usr/bin/env bash
#
# host_report.sh - the machine this site runs on, as ONE JSON object on stdout:
# failed units, the expected units and their state, fail2ban's jails and how
# many addresses each has banned, how many SSH logins failed in the last day,
# sshd's password and root-login posture, disk, memory, swap, whether a reboot
# is pending, when unattended-upgrades last ran, and the operating system with
# the release upgrade Ubuntu last said it offers.
#
# Version: 1.5 - sshd widens to the effective settings a lockout turns on
#                (specs/agent_recipes_and_vocabulary.md, Host files): public-key
#                and keyboard-interactive authentication, maximum auth tries,
#                the ports, and the allowed users and groups, each read out of
#                sshd -T by a compiled key. The two existing keys are unchanged.
#                answers: whether Apache, PHP-FPM and PostgreSQL answer, not
#                merely run (Apache and FPM by one loopback request for the
#                site's own name, PostgreSQL by pg_isready, no credential read).
#                php-fpm is "no" only on Apache's 502/503/504 without the
#                version header; any other headerless answer is unknown.
#                containers: each Joinery container's state, health, and
#                whether its site answers. Both feed the service_health recipe.
#                served_certificates: days left on the certificate each of the
#                site's names serves, the vhost's ServerName marked primary (the
#                name certificate_expiry renews), for the certificate_expiry recipe.
#                php-fpm in answers resolves to the ACTIVE unit, else the newest.
# Version: 1.4 - os:the distribution, its version and codename from
#                os-release, and the release upgrade Ubuntu's own daily check
#                last recorded (the release it offers, or none) with when it
#                recorded it. The check's cache is read, never refreshed: this
#                script makes no network call.
# Version: 1.3 - inode use is read with --output=ipcent alone (df refuses -i beside
#                --output, so the figure was always unknown); the three kernel
#                counts read the SYSTEM journal, because ENOSPC is an errno a
#                userspace program reports and never appears in the kernel ring —
#                the read that proved it (2026-09-22) was a mandb line, not a
#                kernel line.
# Version: 1.2 - disk carries avail_bytes and inodes_used_pct (what a writer can
#                actually use, and the other way a disk fills); kernel_events_24h
#                counts the three kernel events that explain a write that failed.
# Version: 1.1 - ssh_auth_failures_24h is unknown, not 0, where systemd does
#                not answer: a container has no system journal to count from.
# Version: 1.0 - the host_report observe word of specs/agent_tier1_recipes.md.
#                The agent runs this file, verified against the release
#                manifest, with no argv and no stdin; the plane stores what it
#                prints and renders the Host card from it.
#
# THE CONTRACT, which tests/integration/host_report_gate.sh pins:
#
#   - It takes NOTHING: no argument, no stdin, no environment hook. What it
#     reports is a compiled list of facts and the keys are a compiled set.
#     A caller cannot choose a unit, a jail, a file or a line count.
#   - Every key is ALWAYS present. A fact this run cannot read (no root, no
#     journal access, fail2ban not installed, systemd not answering) is the
#     string "unknown" for that key, never an error for the whole report, and
#     the exit code is 0 whenever the object was printed. Run as an ordinary
#     user it still prints the object, with unknowns where root was needed.
#   - Every list is capped here (20 failed units, 20 jails) and every string
#     is capped and reduced to a safe character set, so the agent's output cap
#     is never the thing that bounds this report and nothing a unit or jail
#     was named can break the JSON.
#   - COUNTS ONLY for SSH authentication failures and for kernel events. No
#     usernames, no source addresses, no kernel message text, ever: the journal
#     lines are counted and discarded. The spec records this as the line
#     (agent_tier1_recipes.md, "Accepted risk").
#   - READ ONLY. Every command here is a read: systemctl show and list-units,
#     fail2ban-client status, sshd -T, journalctl, df, /proc, stat. The sshd
#     posture is reported, never written.
#   - Each command runs under its own short timeout, so a stuck daemon costs
#     that one key its answer and never the report.
#
# The disk figure is for the filesystem holding this site's web root, derived
# from this file's own location (site root = two levels up), the way the host
# installers runner derives its site root. On a machine with no public_html
# beside this script (a support-bundle host) it is the root filesystem, and
# the object says which path it measured.
#
# Runs on: any systemd host. Nothing here is Ubuntu-specific except the
# unattended-upgrades stamp and the release-upgrade cache, each unknown where
# it is absent.

set -u
export LC_ALL=C

CMD_TIMEOUT=10          # seconds per external command
MAX_LIST=20             # failed units, jails
MAX_NAME=64             # characters kept of any unit or jail name

SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WEB_ROOT="$SITE_ROOT/public_html"
[[ -d "$WEB_ROOT" ]] || WEB_ROOT="/"

# ---------------------------------------------------------------------------
# Emission helpers. JSON is written by hand so the script depends on nothing
# but coreutils: every string that reaches the object is first reduced to
# [A-Za-z0-9._@:-] and capped, so there is never anything to escape.
# ---------------------------------------------------------------------------
safe_name() {
    local s="${1//[^A-Za-z0-9._@:-]/}"
    printf '%s' "${s:0:$MAX_NAME}"
}
json_str() { printf '"%s"' "$(safe_name "$1")"; }
# A number, or the string unknown when the value is not all digits.
json_num_or_unknown() {
    if [[ "$1" =~ ^[0-9]+$ ]]; then printf '%s' "$1"; else printf '"unknown"'; fi
}
run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }

# ---------------------------------------------------------------------------
# systemd units
# ---------------------------------------------------------------------------
# One unit's state as one of active|inactive|failed|absent, or unknown when
# systemd did not answer.
unit_state() {
    local unit="$1" load active
    load="$(run systemctl show -p LoadState --value "$unit")" || { printf 'unknown'; return; }
    [[ -n "$load" ]] || { printf 'unknown'; return; }
    case "$load" in
        not-found|masked) printf 'absent'; return ;;
    esac
    active="$(run systemctl show -p ActiveState --value "$unit")" || { printf 'unknown'; return; }
    case "$active" in
        active|activating|reloading|refreshing) printf 'active' ;;
        inactive|deactivating)                  printf 'inactive' ;;
        failed)                                 printf 'failed' ;;
        *)                                      printf 'unknown' ;;
    esac
}

# The php-fpm unit carries its version in its name (php8.3-fpm.service). The
# one this host runs is the ACTIVE one; with none active, the newest version
# installed (a host with 8.1 left beside 8.3 serves 8.3). The same rule as
# restart_unit.sh, so a restart lands on the unit this report judged.
php_fpm_unit() {
    local u
    u="$(run systemctl list-units 'php*-fpm.service' --state=active --plain --no-legend --no-pager | awk 'NR==1 {print $1}')"
    if [[ -z "$u" ]]; then
        u="$(run systemctl list-unit-files 'php*-fpm.service' --plain --no-legend --no-pager | awk '{print $1}' | sort -V | tail -n 1)"
    fi
    printf '%s' "$u"
}

emit_expected_units() {
    local fpm
    fpm="$(php_fpm_unit)"
    printf '{'
    printf '"fail2ban":"%s",' "$(unit_state fail2ban.service)"
    printf '"apache2":"%s",' "$(unit_state apache2.service)"
    if [[ -n "$fpm" ]]; then
        printf '"php-fpm":"%s",' "$(unit_state "$fpm")"
    else
        printf '"php-fpm":"absent",'
    fi
    printf '"cron":"%s",' "$(unit_state cron.service)"
    printf '"postgresql":"%s"' "$(unit_state postgresql.service)"
    printf '}'
}

emit_failed_units() {
    local out n=0 name first=1
    out="$(run systemctl list-units --state=failed --plain --no-legend --no-pager)" \
        || { printf '"unknown"'; return; }
    printf '['
    while read -r name _; do
        [[ -n "$name" ]] || continue
        (( n < MAX_LIST )) || break
        (( first )) || printf ','
        first=0
        json_str "$name"
        n=$((n+1))
    done <<< "$out"
    printf ']'
}

# ---------------------------------------------------------------------------
# fail2ban
# ---------------------------------------------------------------------------
emit_fail2ban_jails() {
    local status jails jail n=0 first=1 banned line
    command -v fail2ban-client >/dev/null 2>&1 || { printf '"unknown"'; return; }
    status="$(run fail2ban-client status)" || { printf '"unknown"'; return; }
    jails="$(printf '%s\n' "$status" | awk -F'Jail list:' '/Jail list:/ {print $2}' | tr ',' ' ')"
    printf '['
    for jail in $jails; do
        (( n < MAX_LIST )) || break
        jail="$(safe_name "$jail")"
        [[ -n "$jail" ]] || continue
        banned="unknown"
        if line="$(run fail2ban-client status "$jail")"; then
            banned="$(printf '%s\n' "$line" | awk -F'Currently banned:' '/Currently banned:/ {gsub(/[^0-9]/,"",$2); print $2; exit}')"
        fi
        (( first )) || printf ','
        first=0
        printf '{"name":%s,"banned":%s}' "$(json_str "$jail")" "$(json_num_or_unknown "$banned")"
        n=$((n+1))
    done
    printf ']'
}

# ---------------------------------------------------------------------------
# SSH authentication failures, last 24 hours: a COUNT. The matched lines are
# counted by grep and never printed; nothing from them reaches the object.
# --system makes journalctl exit non-zero when the journal is unreadable,
# which is what tells "unknown" from "none".
# ---------------------------------------------------------------------------
emit_ssh_auth_failures() {
    local lines count
    # Where systemd does not answer there is no system journal to count from:
    # in a container journalctl exits clean with nothing, and that read as
    # zero failures. Zero means the journal holds none, never that there is no
    # journal. The same signal the unit states already fail on.
    run systemctl show -p Version --value >/dev/null || { printf '"unknown"'; return; }
    lines="$(run journalctl --system -u ssh -u sshd --since "24 hours ago" --no-pager -o cat)" \
        || { printf '"unknown"'; return; }
    count="$(printf '%s\n' "$lines" | grep -c -E 'Failed password|Invalid user|authentication failure|Failed publickey')"
    json_num_or_unknown "$count"
}

# ---------------------------------------------------------------------------
# sshd posture, as sshd -T prints it (root only: it has to open the host keys).
# Reported, never written.
# ---------------------------------------------------------------------------
#
# The EFFECTIVE settings, not a file: Ubuntu's cloud images set
# PasswordAuthentication in sshd_config.d/, so sshd_config alone misreads a
# lockout. Only the compiled keys below are read out of sshd -T; nothing else
# it prints (host key paths, the authorized-keys file) reaches the object.
SSHD_WORDS=(passwordauthentication permitrootlogin pubkeyauthentication kbdinteractiveauthentication maxauthtries)
sshd_value() { printf '%s\n' "$2" | awk -v k="$1" '$1==k {print $2; exit}'; }
# Every value of a key sshd -T may print more than once (port, allowusers,
# allowgroups), as a JSON list, capped and sanitised.
sshd_list() {
    local key="$1" conf="$2" v n=0 first=1
    printf '['
    while read -r v; do
        [[ -n "$v" ]] || continue
        (( n < MAX_LIST )) || break
        (( first )) || printf ','
        first=0
        json_str "$v"
        n=$((n+1))
    done < <(printf '%s\n' "$conf" | awk -v k="$key" '$1==k { for (i = 2; i <= NF; i++) print $i }')
    printf ']'
}

emit_sshd() {
    local conf k v
    local -A val=()
    if conf="$(run sshd -T)" && [[ -n "$conf" ]]; then
        for k in "${SSHD_WORDS[@]}"; do
            v="$(sshd_value "$k" "$conf")"
            val[$k]="${v:-unknown}"
        done
        printf '{"password_authentication":%s,"permit_root_login":%s' \
            "$(json_str "${val[passwordauthentication]}")" "$(json_str "${val[permitrootlogin]}")"
        printf ',"pubkey_authentication":%s,"kbd_interactive_authentication":%s,"max_auth_tries":%s' \
            "$(json_str "${val[pubkeyauthentication]}")" "$(json_str "${val[kbdinteractiveauthentication]}")" \
            "$(json_num_or_unknown "${val[maxauthtries]}")"
        printf ',"ports":%s,"allow_users":%s,"allow_groups":%s}' \
            "$(sshd_list port "$conf")" "$(sshd_list allowusers "$conf")" "$(sshd_list allowgroups "$conf")"
    else
        printf '{"password_authentication":"unknown","permit_root_login":"unknown"'
        printf ',"pubkey_authentication":"unknown","kbd_interactive_authentication":"unknown","max_auth_tries":"unknown"'
        printf ',"ports":"unknown","allow_users":"unknown","allow_groups":"unknown"}'
    fi
}

# ---------------------------------------------------------------------------
# disk, memory, swap
# ---------------------------------------------------------------------------
# avail is NOT total minus used. A filesystem keeps blocks back for root (5% by
# default on ext4 — 2.4 GiB on a 48 GiB disk), so the subtraction overstates
# what a writer can use by exactly the amount that matters when a disk is
# filling. df knows the real figure; it is reported rather than inferred.
#
# inodes_used_pct is the other way a disk fills: a table with no free inodes
# refuses writes while df shows space. A filesystem that does not count inodes
# (btrfs, zfs) prints "-" for it, which is unknown, not zero.
emit_disk() {
    local line used total avail ipct
    line="$(run df -B1 --output=used,size,avail "$WEB_ROOT" | tail -n 1)"
    read -r used total avail <<< "$line"
    ipct="$(run df --output=ipcent "$WEB_ROOT" | tail -n 1)"
    ipct="${ipct//[^0-9]/}"
    printf '{"path":%s,"used_bytes":%s,"total_bytes":%s,"avail_bytes":%s,"inodes_used_pct":%s}' \
        "\"$(printf '%s' "$WEB_ROOT" | tr -cd 'A-Za-z0-9._/-' | head -c 200)\"" \
        "$(json_num_or_unknown "${used:-}")" "$(json_num_or_unknown "${total:-}")" \
        "$(json_num_or_unknown "${avail:-}")" "$(json_num_or_unknown "${ipct:-}")"
}

meminfo_kb() { awk -v k="$1" '$1==k":" {print $2; exit}' /proc/meminfo 2>/dev/null; }
kb_to_bytes_or_unknown() {
    if [[ "${1:-}" =~ ^[0-9]+$ ]]; then printf '%s' $(( $1 * 1024 )); else printf '"unknown"'; fi
}
emit_memory() {
    local total avail used=""
    total="$(meminfo_kb MemTotal)"; avail="$(meminfo_kb MemAvailable)"
    [[ "$total" =~ ^[0-9]+$ && "$avail" =~ ^[0-9]+$ ]] && used=$(( total - avail ))
    printf '{"used_bytes":%s,"total_bytes":%s}' "$(kb_to_bytes_or_unknown "$used")" "$(kb_to_bytes_or_unknown "$total")"
}
emit_swap() {
    local total free used=""
    total="$(meminfo_kb SwapTotal)"; free="$(meminfo_kb SwapFree)"
    [[ "$total" =~ ^[0-9]+$ && "$free" =~ ^[0-9]+$ ]] && used=$(( total - free ))
    printf '{"used_bytes":%s,"total_bytes":%s}' "$(kb_to_bytes_or_unknown "$used")" "$(kb_to_bytes_or_unknown "$total")"
}

# ---------------------------------------------------------------------------
# The three events that explain a write that failed, last 24 hours: THREE
# COUNTS and nothing else.
#
# The OOM killer ran, a filesystem had no space, or the device errored. Each is
# a number; the matched lines are counted and discarded, exactly as the SSH
# figure above is, so nothing from a message — a path, a process name, an
# address — ever reaches the object.
#
# THE SYSTEM JOURNAL, not the kernel ring, and that is the correction this
# version exists for. "No space left on device" is an errno handed to a
# userspace program, which is the one that says so: on 2026-09-22 the line that
# proved a node's disk had filled came from mandb, and a kernel-ring read
# (journalctl -k) returned zero for the same window. The system journal carries
# the kernel's own messages too, so the OOM and I/O counts lose nothing by
# being read here.
#
# journalctl does the matching itself (-g, its own PCRE pass) so the shell is
# never handed a day of log to filter, and the count is taken again here with
# the same pattern — which also drops journalctl's own "-- No entries --".
#
# Why it earns its place: on 2026-09-22 a node filled its disk for fifteen
# minutes, took PostgreSQL and the man-page index down with it, and freed the
# space again on the way out. Every gauge read normal afterwards. One of these
# three numbers would have named it.
#
# A machine whose journal cannot be read (a container, or an unprivileged run)
# answers unknown for the whole object, which is the same signal the unit
# states use.
# ---------------------------------------------------------------------------
KERNEL_EVENT_OOM='Out of memory: Kill|oom-kill:|oom_reaper:'
KERNEL_EVENT_ENOSPC='No space left on device'
KERNEL_EVENT_IO='I/O error|Buffer I/O error|EXT4-fs error|Remounting filesystem read-only'

# One pattern's count over the last day, or nothing at all when the journal
# could not be read (which the caller turns into unknown for all three).
journal_event_count() {
    local out
    out="$(run journalctl --system --since "24 hours ago" --no-pager -o cat -g "$1")" || return 1
    printf '%s' "$(printf '%s\n' "$out" | grep -c -E "$1")"
}

emit_kernel_events() {
    local oom enospc io
    run systemctl show -p Version --value >/dev/null || { printf '"unknown"'; return; }
    oom="$(journal_event_count "$KERNEL_EVENT_OOM")"       || { printf '"unknown"'; return; }
    enospc="$(journal_event_count "$KERNEL_EVENT_ENOSPC")" || { printf '"unknown"'; return; }
    io="$(journal_event_count "$KERNEL_EVENT_IO")"         || { printf '"unknown"'; return; }
    printf '{"oom":%s,"enospc":%s,"io_error":%s}' \
        "$(json_num_or_unknown "$oom")" "$(json_num_or_unknown "$enospc")" "$(json_num_or_unknown "$io")"
}

# ---------------------------------------------------------------------------
# reboot-required, unattended-upgrades
# ---------------------------------------------------------------------------
emit_reboot_required() {
    if [[ -e /var/run/reboot-required ]]; then printf 'true'
    elif [[ -d /var/run ]]; then printf 'false'
    else printf '"unknown"'; fi
}
emit_unattended_upgrades_last_run() {
    local stamp=/var/lib/apt/periodic/unattended-upgrades-stamp t
    if [[ -e "$stamp" ]] && t="$(run stat -c %Y "$stamp")"; then
        json_num_or_unknown "$t"
    else
        printf '"unknown"'
    fi
}

# ---------------------------------------------------------------------------
# The operating system, and the release upgrade Ubuntu offers it.
#
# id, version and codename come from os-release. version is the point release
# (24.04.4) where VERSION carries one, else VERSION_ID.
#
# The upgrade answer is Ubuntu's own: update-motd's release-upgrade step runs
# check-new-release once a day and writes what it printed to a cache file.
# Empty means no release is offered to this machine (under its own Prompt=
# setting); "New release '26.04.1 LTS' available." names one. The cache is
# refreshed only when someone logs in, so its time travels with the answer and
# the reader judges its age. Reading it is the whole of this: the script never
# runs the check, because the check fetches from the network and writes.
# ---------------------------------------------------------------------------
RELEASE_UPGRADE_CACHE=/var/lib/ubuntu-release-upgrader/release-upgrade-available

os_release_field() {
    local f
    for f in /etc/os-release /usr/lib/os-release; do
        [[ -r "$f" ]] || continue
        run head -c 4096 "$f" | awk -F= -v k="$1" '$1==k { v=$2; gsub(/"/, "", v); print v; exit }'
        return
    done
}

# The version a check-new-release line offers (26.04.1), none for an empty
# cache, or unknown for text it does not recognise. Reads the cache on stdin.
release_offered_from() {
    local text version
    text="$(head -c 4096)"
    if [[ -z "${text//[[:space:]]/}" ]]; then printf 'none'; return; fi
    version="$(printf '%s\n' "$text" | grep -o -E "New release '[0-9]+(\.[0-9]+)*" | head -n 1)"
    version="${version#New release \'}"
    if [[ "$version" =~ ^[0-9]+(\.[0-9]+)*$ ]]; then printf '%s' "${version:0:16}"; else printf 'unknown'; fi
}

emit_os() {
    local id version codename offered=unknown checked=""
    id="$(os_release_field ID)"
    version="$(os_release_field VERSION)"
    version="$(printf '%s' "$version" | grep -o -E '^[0-9]+(\.[0-9]+)*' | head -n 1)"
    [[ -n "$version" ]] || version="$(os_release_field VERSION_ID)"
    codename="$(os_release_field VERSION_CODENAME)"
    [[ -n "$id" ]] || id=unknown
    [[ -n "$version" ]] || version=unknown
    [[ -n "$codename" ]] || codename=unknown
    if [[ -r "$RELEASE_UPGRADE_CACHE" ]]; then
        offered="$(run head -c 4096 "$RELEASE_UPGRADE_CACHE" | release_offered_from)"
        checked="$(run stat -c %Y "$RELEASE_UPGRADE_CACHE")"
    fi
    printf '{"id":%s,"version":%s,"codename":%s,"release_upgrade":{"offered":%s,"checked_at":%s}}' \
        "$(json_str "$id")" "$(json_str "$version")" "$(json_str "$codename")" \
        "$(json_str "$offered")" "$(json_num_or_unknown "$checked")"
}

# ---------------------------------------------------------------------------
# Does each service ANSWER, not merely run (the service_health recipe of
# specs/agent_recipes_and_vocabulary.md, Settled 2026-09-23). systemd already
# restarts a crashed unit; what it cannot see is one that runs and does not
# answer.
#
#   apache2    - any HTTP response on the loopback, for this site's own name
#   php-fpm    - that response came through PHP: serve.php sets
#                X-Joinery-Version on every request it handles, and Apache
#                answers a request it could not hand to FPM without it
#   postgresql - pg_isready: the server accepts connections. No credential is
#                read (the site's config is a secret), so this is the
#                credential-free form of SELECT 1.
#
# Each is yes, no, or unknown (no site on this machine, no tool). Only the
# verdicts are printed; the site's name is read to address the request and
# never reaches the object.
# ---------------------------------------------------------------------------
site_vhost() {
    local vhost="/etc/apache2/sites-available/$(basename "$SITE_ROOT").conf"
    [[ -r "$vhost" ]] && run head -c 65536 "$vhost"
}
site_server_name() {
    site_vhost | awk 'tolower($1)=="servername" {print $2; exit}'
}
# The address the site's vhost is bound to: install.sh binds it to the
# server's own IP (default_virtualhost.conf), a proxy vhost to *. A request to
# the loopback would reach only the default site on the first shape.
site_vhost_address() {
    local a
    a="$(site_vhost | grep -m1 -oE '<VirtualHost[[:space:]]+[^:>]+:' | sed -E 's/<VirtualHost[[:space:]]+//; s/:$//')"
    if [[ "$a" =~ ^[0-9.]{7,15}$ ]]; then printf '%s' "$a"; else printf '127.0.0.1'; fi
}

# HTTP headers from this machine for NAME at ADDR: https first (the plain
# vhost of a site with a certificate redirects without reaching PHP), plain
# http when https does not answer. Prints nothing when neither answered.
loopback_headers() {
    local name="$1" addr="$2" h
    h="$(run curl -sk --max-time 8 -o /dev/null -D - --resolve "${name}:443:${addr}" "https://${name}/")"
    if [[ ! "$h" =~ ^HTTP/ ]]; then
        h="$(run curl -s --max-time 8 -o /dev/null -D - --resolve "${name}:80:${addr}" "http://${name}/")"
    fi
    printf '%s' "$h"
}

emit_answers() {
    local name headers code apache=unknown fpm=unknown pg=unknown
    name="$(site_server_name)"
    if [[ "$name" =~ ^[A-Za-z0-9.-]{1,253}$ ]] && command -v curl >/dev/null 2>&1; then
        headers="$(loopback_headers "$name" "$(site_vhost_address)")"
        if [[ "$headers" =~ ^HTTP/ ]]; then
            apache=yes
            # PHP answered when serve.php's header is there. Without it, only
            # Apache's own gateway failures (502, 503, 504: it could not hand
            # the request to FPM) say FPM does not answer; a redirect, a 403
            # or anything else without the header says nothing about FPM.
            code="$(printf '%s\n' "$headers" | awk 'NR==1 {print $2}')"
            if printf '%s\n' "$headers" | grep -q -i '^x-joinery-version:'; then fpm=yes
            elif [[ "$code" == 502 || "$code" == 503 || "$code" == 504 ]]; then fpm=no
            else fpm=unknown
            fi
        else
            apache=no
        fi
    fi
    if command -v pg_isready >/dev/null 2>&1 && [[ -n "$name" ]]; then
        if run pg_isready -q -t 5; then pg=yes
        else
            case $? in 1|2) pg=no ;; *) pg=unknown ;; esac
        fi
    fi
    printf '{"apache2":"%s","php-fpm":"%s","postgresql":"%s"}' "$apache" "$fpm" "$pg"
}

# ---------------------------------------------------------------------------
# The certificate each of this site's names SERVES, read over this machine's
# own connection to its vhost address, with SNI: what a visitor is handed, not
# what certbot holds (check_status reads the held ones). The certificate_expiry
# recipe reads it: fewer than 14 days left means certbot's own timer failed.
#
# The names are the site vhost's own ServerName and ServerAlias lines (its
# configuration; the recipe's repair takes the domain from this list, never
# from the wire). A name whose handshake does not complete is left out, not
# guessed at. Only the name and whole days left are printed.
# ---------------------------------------------------------------------------
MAX_CERT_NAMES=10
site_names() {
    local site f
    site="$(basename "$SITE_ROOT")"
    for f in "/etc/apache2/sites-available/${site}.conf" "/etc/apache2/sites-available/${site}-le-ssl.conf"; do
        [[ -r "$f" ]] || continue
        run head -c 65536 "$f" | awk 'tolower($1)=="servername" || tolower($1)=="serveralias" { for (i = 2; i <= NF; i++) print $i }'
    done | grep -E '^[A-Za-z0-9.-]{1,253}$' | grep -v '^\*' | awk '!seen[$0]++' | head -n "$MAX_CERT_NAMES"
}

emit_served_certificates() {
    local addr name end end_s now days n=0 first=1 primary
    command -v openssl >/dev/null 2>&1 || { printf '"unknown"'; return; }
    addr="$(site_vhost_address)"
    now="$(date -u +%s)"
    primary="$(site_server_name)"
    printf '['
    while read -r name; do
        [[ -n "$name" ]] || continue
        end="$(run openssl s_client -connect "${addr}:443" -servername "$name" < /dev/null | run openssl x509 -noout -enddate)"
        end="${end#notAfter=}"
        [[ -n "$end" ]] || continue
        end_s="$(date -u -d "$end" +%s 2>/dev/null)" || continue
        [[ "$end_s" =~ ^[0-9]+$ ]] || continue
        days=$(( (end_s - now) / 86400 ))
        (( first )) || printf ','
        first=0
        printf '{"domain":%s,"days_left":%s,"primary":%s}' "$(json_str "$name")" "$days" "$([[ "$name" == "$primary" ]] && echo true || echo false)"
        n=$((n+1))
    done < <(site_names)
    printf ']'
}

# ---------------------------------------------------------------------------
# This host's Joinery containers: every container whose name is its SITENAME,
# the shape install.sh creates. Each with docker's state and health, and
# whether the site inside answers through PHP on its published web port.
# "none" where the machine has no docker; "unknown" where docker would not
# answer (not root). Names are site slugs, the same list restart_container
# accepts.
# ---------------------------------------------------------------------------
emit_containers() {
    local names c site state health port headers answers n=0 first=1
    command -v docker >/dev/null 2>&1 || { printf '"none"'; return; }
    names="$(run docker ps -a --format '{{.Names}}')" || { printf '"unknown"'; return; }
    printf '['
    for c in $names; do
        (( n < MAX_LIST )) || break
        [[ "$c" =~ ^[a-z0-9_-]{1,50}$ ]] || continue
        site="$(run docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$c" | awk -F= '$1=="SITENAME" {print $2; exit}')"
        [[ "$site" == "$c" ]] || continue
        state="$(run docker inspect -f '{{.State.Status}}' "$c")"
        health="$(run docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$c")"
        answers=unknown
        port="$(run docker port "$c" 80/tcp | awk -F: 'NR==1 {print $NF}')"
        if [[ "$state" != "running" ]]; then
            answers=no
        elif [[ "$port" =~ ^[0-9]+$ ]]; then
            headers="$(run curl -s --max-time 8 -o /dev/null -D - "http://127.0.0.1:${port}/")"
            if printf '%s\n' "$headers" | grep -q -i '^x-joinery-version:'; then answers=yes; else answers=no; fi
        fi
        (( first )) || printf ','
        first=0
        printf '{"name":%s,"state":%s,"health":%s,"answers":"%s"}' \
            "$(json_str "$c")" "$(json_str "${state:-unknown}")" "$(json_str "${health:-unknown}")" "$answers"
        n=$((n+1))
    done
    printf ']'
}

# ---------------------------------------------------------------------------
# The object. One line, every key, in this order.
# ---------------------------------------------------------------------------
printf '{'
printf '"failed_units":%s,' "$(emit_failed_units)"
printf '"expected_units":%s,' "$(emit_expected_units)"
printf '"fail2ban_jails":%s,' "$(emit_fail2ban_jails)"
printf '"ssh_auth_failures_24h":%s,' "$(emit_ssh_auth_failures)"
printf '"kernel_events_24h":%s,' "$(emit_kernel_events)"
printf '"sshd":%s,' "$(emit_sshd)"
printf '"disk":%s,' "$(emit_disk)"
printf '"memory":%s,' "$(emit_memory)"
printf '"swap":%s,' "$(emit_swap)"
printf '"reboot_required":%s,' "$(emit_reboot_required)"
printf '"unattended_upgrades_last_run":%s,' "$(emit_unattended_upgrades_last_run)"
printf '"os":%s,' "$(emit_os)"
printf '"answers":%s,' "$(emit_answers)"
printf '"served_certificates":%s,' "$(emit_served_certificates)"
printf '"containers":%s,' "$(emit_containers)"
printf '"generated_at":%s' "$(date -u +%s)"
printf '}\n'
exit 0
