#!/usr/bin/env bash
#
# listening_sockets.sh - the addresses and ports this machine listens on, and
# which program and systemd unit holds each, as ONE JSON object on stdout.
#
# Version: 1.0 - the listening_sockets observe word of
#                specs/agent_recipes_and_vocabulary.md (the running list,
#                2026-10-12: a gateway that said it listened on [::]:993 and
#                was never reached over IPv4). The agent runs this file,
#                verified against the release manifest, with no argv and no
#                stdin.
#
# THE CONTRACT, which tests/integration/listening_sockets_gate.sh pins:
#
#   - It takes NOTHING: no argument, no stdin. A caller cannot choose a port,
#     an address or a process.
#   - LISTENERS ONLY. TCP sockets that listen and UDP sockets that are bound;
#     never a connection, never a remote address, never a process id or a
#     command line. Each row is a protocol, a family, a local address, a
#     port, the name of the program that holds it and the systemd unit that
#     program runs in.
#   - Capped (MAX_SOCKETS rows, names and addresses reduced to a safe
#     character set and capped), so the agent's output cap is never the thing
#     that bounds the answer and nothing a program is named can break the JSON.
#   - Every key is ALWAYS present. Where ss is missing or does not answer,
#     sockets is the string unknown; the exit code is 0 whenever the object
#     was printed. Run as an ordinary user it still prints every socket, with
#     the program and unit empty where only root can see who holds it.
#   - READ ONLY: ss and /proc/<pid>/cgroup.
#
# Inside a site container it answers for the container's own network: what
# that container listens on, which is the question its site's mail gateway or
# web server raises there.

set -u
export LC_ALL=C

CMD_TIMEOUT=10          # seconds per external command
MAX_SOCKETS=100         # rows
MAX_NAME=64             # characters kept of a program or unit name

run() { timeout "$CMD_TIMEOUT" "$@" 2>/dev/null; }
safe() { local s="${1//[^A-Za-z0-9._@:-]/}"; printf '%s' "${s:0:$MAX_NAME}"; }

# The systemd unit a process runs in, from the last part of its cgroup path:
# a .service, or a .scope (a container, a login session). Empty where the
# process is gone or its cgroup cannot be read.
unit_of() {
    local pid="$1" line part
    [[ "$pid" =~ ^[0-9]{1,10}$ ]] || return 0
    line="$(head -c 600 "/proc/${pid}/cgroup" 2>/dev/null | tail -n 1)"
    line="${line##*/}"
    case "$line" in
        *.service|*.scope) part="$line" ;;
        *) part="" ;;
    esac
    safe "$part"
}

emit_sockets() {
    local out proto state local_addr users addr port family program pid unit n=0 key sep=""
    local -A seen=()
    local truncated=false
    command -v ss >/dev/null 2>&1 || { printf '"unknown"\nfalse\n'; return; }
    out="$(run ss -H -l -n -t -u -p)" || { printf '"unknown"\nfalse\n'; return; }
    printf '['
    while read -r proto state _rq _sq local_addr _peer users; do
        # A listening TCP socket or a bound UDP one; never a connection, even
        # if ss were to print one.
        [[ ( "$proto" == "tcp" && "$state" == "LISTEN" ) || ( "$proto" == "udp" && "$state" == "UNCONN" ) ]] || continue
        # The port is after the last colon; the address before it, unbracketed,
        # with any %interface suffix dropped.
        port="${local_addr##*:}"
        addr="${local_addr%:*}"
        [[ "$port" =~ ^[0-9]{1,5}$ ]] || continue
        addr="${addr#[}"; addr="${addr%]}"; addr="${addr%%%*}"
        if [[ "$addr" == "*" ]]; then family=any
        elif [[ "$addr" == *:* ]]; then family=ipv6
        else family=ipv4
        fi
        addr="${addr//[^0-9A-Fa-f.:*]/}"; addr="${addr:0:45}"
        # users:(("name",pid=N,fd=M),...): the first holder names it.
        program=""; pid=""
        if [[ "$users" =~ \(\(\"([^\"]*)\",pid=([0-9]+) ]]; then
            program="$(safe "${BASH_REMATCH[1]}")"; pid="${BASH_REMATCH[2]}"
        fi
        unit=""
        [[ -n "$pid" ]] && unit="$(unit_of "$pid")"
        key="${proto}|${addr}|${port}|${program}"
        [[ -z "${seen[$key]:-}" ]] || continue
        seen[$key]=1
        if (( n >= MAX_SOCKETS )); then truncated=true; break; fi
        printf '%s{"proto":"%s","family":"%s","address":"%s","port":%d,"program":"%s","unit":"%s"}' \
            "$sep" "$proto" "$family" "$addr" "$((10#$port))" "$program" "$unit"
        sep=","; n=$((n+1))
    done <<< "$out"
    printf ']\n%s\n' "$truncated"
}

# Two lines back: the list, then whether it was cut at MAX_SOCKETS.
REPLY_LINES="$(emit_sockets)"
SOCKETS="$(head -n 1 <<< "$REPLY_LINES")"
TRUNCATED="$(tail -n 1 <<< "$REPLY_LINES")"
[[ "$TRUNCATED" == "true" ]] || TRUNCATED=false

printf '{"sockets":%s,"truncated":%s,"generated_at":%d}\n' "$SOCKETS" "$TRUNCATED" "$(date +%s)"
exit 0
