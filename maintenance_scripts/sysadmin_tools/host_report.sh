#!/usr/bin/env bash
#
# host_report.sh - the machine this site runs on, as ONE JSON object on stdout:
# failed units, the expected units and their state, fail2ban's jails and how
# many addresses each has banned, how many SSH logins failed in the last day,
# sshd's password and root-login posture, disk, memory, swap, whether a reboot
# is pending, and when unattended-upgrades last ran.
#
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
#   - COUNTS ONLY for SSH authentication failures. No usernames, no source
#     addresses, ever: the journal lines are counted and discarded. The spec
#     records this as the line (agent_tier1_recipes.md, "Accepted risk").
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
# unattended-upgrades stamp path, which is unknown where it is absent.

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

# The php-fpm unit carries its version in its name (php8.3-fpm.service); the
# first unit file matching is the one this host runs.
php_fpm_unit() {
    run systemctl list-unit-files 'php*-fpm.service' --plain --no-legend --no-pager \
        | awk 'NR==1 {print $1}'
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
    lines="$(run journalctl --system -u ssh -u sshd --since "24 hours ago" --no-pager -o cat)" \
        || { printf '"unknown"'; return; }
    count="$(printf '%s\n' "$lines" | grep -c -E 'Failed password|Invalid user|authentication failure|Failed publickey')"
    json_num_or_unknown "$count"
}

# ---------------------------------------------------------------------------
# sshd posture, as sshd -T prints it (root only: it has to open the host keys).
# Reported, never written.
# ---------------------------------------------------------------------------
emit_sshd() {
    local conf pw root
    if conf="$(run sshd -T)" && [[ -n "$conf" ]]; then
        pw="$(printf '%s\n' "$conf" | awk '$1=="passwordauthentication" {print $2; exit}')"
        root="$(printf '%s\n' "$conf" | awk '$1=="permitrootlogin" {print $2; exit}')"
        [[ -n "$pw" ]] || pw=unknown
        [[ -n "$root" ]] || root=unknown
    else
        pw=unknown; root=unknown
    fi
    printf '{"password_authentication":%s,"permit_root_login":%s}' "$(json_str "$pw")" "$(json_str "$root")"
}

# ---------------------------------------------------------------------------
# disk, memory, swap
# ---------------------------------------------------------------------------
emit_disk() {
    local line used total
    line="$(run df -B1 --output=used,size "$WEB_ROOT" | tail -n 1)"
    read -r used total <<< "$line"
    printf '{"path":%s,"used_bytes":%s,"total_bytes":%s}' \
        "\"$(printf '%s' "$WEB_ROOT" | tr -cd 'A-Za-z0-9._/-' | head -c 200)\"" \
        "$(json_num_or_unknown "${used:-}")" "$(json_num_or_unknown "${total:-}")"
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
# The object. One line, every key, in this order.
# ---------------------------------------------------------------------------
printf '{'
printf '"failed_units":%s,' "$(emit_failed_units)"
printf '"expected_units":%s,' "$(emit_expected_units)"
printf '"fail2ban_jails":%s,' "$(emit_fail2ban_jails)"
printf '"ssh_auth_failures_24h":%s,' "$(emit_ssh_auth_failures)"
printf '"sshd":%s,' "$(emit_sshd)"
printf '"disk":%s,' "$(emit_disk)"
printf '"memory":%s,' "$(emit_memory)"
printf '"swap":%s,' "$(emit_swap)"
printf '"reboot_required":%s,' "$(emit_reboot_required)"
printf '"unattended_upgrades_last_run":%s,' "$(emit_unattended_upgrades_last_run)"
printf '"generated_at":%s' "$(date -u +%s)"
printf '}\n'
exit 0
