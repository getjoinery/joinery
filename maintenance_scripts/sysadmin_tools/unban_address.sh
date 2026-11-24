#!/usr/bin/env bash
#
# unban_address.sh - lift one fail2ban ban: one address, in one jail, and say
# how many bans were lifted, as ONE JSON object on stdout.
#
# Version: 1.0 - the unban_address operate word. The agent runs this file,
#                verified against the release manifest, with two argv elements
#                it has already validated.
#
# THE CONTRACT, which tests/integration/unban_address_gate.sh pins:
#
#   - TWO arguments. The jail must be one fail2ban itself lists on this
#     machine; the address must be an IPv4 or an IPv6 address. Anything else
#     is refused with exit 2 and nothing runs.
#   - It lifts a ban, and only that: the one changing call is
#     `fail2ban-client set JAIL unbanip ADDRESS`. It never bans, never stops,
#     reloads or reconfigures fail2ban, and never touches the firewall itself.
#   - An address that was not banned there is not an error: unbanned is 0.
#   - Every key is ALWAYS present. `fail2ban` is answered, absent (not
#     installed) or no_answer (installed and not answering); `unbanned` is the
#     count fail2ban gave, or the string unknown. Exit 0 whenever the object
#     was printed.
#
# Runs on: any host with fail2ban.

set -u
export LC_ALL=C

CMD_TIMEOUT=20

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }

refuse() {
    printf 'unban_address: %s\n' "$1" >&2
    exit 2
}

[[ $# -eq 2 ]] || refuse "takes a jail and an address, nothing else"
JAIL="$1"
ADDRESS="$2"

[[ "$JAIL" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{0,39}$ ]] || refuse "that is not a jail name"

# An IPv4 address: four numbers of 0 to 255. An IPv6 address: hex groups and
# colons, at least two colons, with an IPv4 tail allowed (::ffff:192.0.2.1).
# fail2ban parses it again; this keeps anything else from reaching it.
is_ipv4() {
    local a b c d
    [[ "$1" =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1
    for a in "${BASH_REMATCH[@]:1}"; do
        (( 10#$a <= 255 )) || return 1
    done
    return 0
}
is_ipv6() {
    local colons
    [[ "$1" =~ ^[0-9A-Fa-f:.]{2,45}$ ]] || return 1
    colons="${1//[^:]/}"
    (( ${#colons} >= 2 && ${#colons} <= 7 )) || return 1
    [[ "$1" != *.* ]] || is_ipv4 "${1##*:}" || return 1
    return 0
}
is_ipv4 "$ADDRESS" || is_ipv6 "$ADDRESS" || refuse "that is not an IPv4 or IPv6 address"

answer() {  # FAIL2BAN UNBANNED
    printf '{"jail":"%s","address":"%s","fail2ban":"%s","unbanned":%s}\n' "$JAIL" "$ADDRESS" "$1" "$2"
    exit 0
}

command -v fail2ban-client >/dev/null 2>&1 || answer absent '"unknown"'
status="$(run fail2ban-client status)" || answer no_answer '"unknown"'

# The jail must be one of this machine's own.
known=0
for j in $(printf '%s\n' "$status" | awk -F'Jail list:' '/Jail list:/ {print $2}' | tr ',' ' '); do
    [[ "$j" == "$JAIL" ]] && { known=1; break; }
done
(( known )) || refuse "${JAIL} is not a jail on this node"

out="$(run fail2ban-client set "$JAIL" unbanip "$ADDRESS")" || answer answered '"unknown"'
count="$(printf '%s\n' "$out" | awk 'NR == 1 && /^[0-9]+$/ { print; exit }')"
[[ -n "$count" ]] || answer answered '"unknown"'
answer answered "$((10#$count))"
