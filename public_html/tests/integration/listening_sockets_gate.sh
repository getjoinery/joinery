#!/bin/bash
# @joinery-test
# name: listening_sockets
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/listening_sockets.sh]
#
# listening_sockets.sh is what the agent's listening_sockets observe word runs
# as root (specs/agent_recipes_and_vocabulary.md, the running list). This gate
# pins its contract: the real run on this box prints one JSON object; against
# a stubbed ss it reads each listener's protocol, family, address and port,
# the first holder's program and the unit its cgroup names; it never carries a
# peer, a pid or a command line; it reduces hostile names, caps the list, and
# says unknown where ss does not answer.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/listening_sockets.sh"
T=$(mktemp -d)
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}
jv() {
    php -r '
        $o = json_decode(file_get_contents($argv[1]), true);
        if (!is_array($o)) { echo "NOTJSON"; exit; }
        $v = $o; if ($argv[2] !== "") { foreach (explode(".", $argv[2]) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "ABSENT"; exit; } $v = $v[$k]; } }
        if ($argv[3] === "count") { echo is_array($v) ? count($v) : "NOTLIST"; exit; }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

echo "=== The real run on this box ==="
bash "$SCRIPT" > "$T/real.json" 2>/dev/null; rc=$?
chk "exit 0" "$rc" "0"
chk "one object with every key" "$(jv "$T/real.json" "" keys)" "sockets,truncated,generated_at"
chk "sockets is a list here" "$(php -r '$o=json_decode(file_get_contents($argv[1]),true); echo is_array($o["sockets"]) ? "list" : "no";' "$T/real.json")" "list"

echo "=== A stubbed ss ==="
mkdir -p "$T/bin"
# The gate's own pid stands in for a holder, so its cgroup is a real file.
cat > "$T/bin/ss" <<STUB
#!/bin/bash
cat <<'OUT'
tcp   LISTEN 0      511              0.0.0.0:80        0.0.0.0:*    users:(("apache2",pid=$$,fd=4),("apache2",pid=2,fd=4))
tcp   LISTEN 0      4096                [::]:993          [::]:*    users:(("joinery-mail-ga",pid=$$,fd=7))
tcp   LISTEN 0      128                    *:22              *:*    users:(("sshd",pid=999999999,fd=3))
udp   UNCONN 0      0          127.0.0.53%lo:53        0.0.0.0:*
tcp   LISTEN 0      50             127.0.0.1:5432      0.0.0.0:*    users:(("evil\$(reboot)<b>",pid=$$,fd=5))
tcp   ESTAB  0      0             10.0.0.5:443     203.0.113.9:51234 users:(("apache2",pid=$$,fd=9))
tcp   LISTEN 0      511              0.0.0.0:80        0.0.0.0:*    users:(("apache2",pid=$$,fd=4))
raw   UNCONN 0      0                      *:icmp            *:*
OUT
STUB
chmod 755 "$T/bin/ss"
PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/s.json" 2>/dev/null; rc=$?
chk "exit 0" "$rc" "0"
chk "five listeners: the duplicate, the connection and the raw socket left out" "$(jv "$T/s.json" sockets count)" "5"
chk "an IPv4 listener" "$(jv "$T/s.json" sockets.0.proto)/$(jv "$T/s.json" sockets.0.family)/$(jv "$T/s.json" sockets.0.address)/$(jv "$T/s.json" sockets.0.port)/$(jv "$T/s.json" sockets.0.program)" "tcp/ipv4/0.0.0.0/80/apache2"
chk "an IPv6 listener, unbracketed (the gateway that listened on [::] only)" "$(jv "$T/s.json" sockets.1.family)/$(jv "$T/s.json" sockets.1.address)/$(jv "$T/s.json" sockets.1.port)/$(jv "$T/s.json" sockets.1.program)" "ipv6/::/993/joinery-mail-ga"
chk "* is any family" "$(jv "$T/s.json" sockets.2.family)/$(jv "$T/s.json" sockets.2.address)" "any/*"
chk "a holder whose cgroup cannot be read has no unit" "$(jv "$T/s.json" sockets.2.unit)" ""
chk "a bound UDP socket, its interface suffix dropped, no holder named" "$(jv "$T/s.json" sockets.3.proto)/$(jv "$T/s.json" sockets.3.address)/$(jv "$T/s.json" sockets.3.program)" "udp/127.0.0.53/"
chk "a hostile program name is reduced" "$(jv "$T/s.json" sockets.4.program)" "evilrebootb"
own_unit="$(tail -n 1 /proc/$$/cgroup)"; own_unit="${own_unit##*/}"
case "$own_unit" in *.service|*.scope) ;; *) own_unit="" ;; esac
chk "the unit is the holder's cgroup's" "$(jv "$T/s.json" sockets.0.unit)" "$(printf '%s' "$own_unit" | tr -cd 'A-Za-z0-9._@:-' | cut -c1-64)"
chk "no peer, no pid, no remote address ever" "$(grep -c -E '203\.0\.113|pid|51234|fd=' "$T/s.json")" "0"
chk "each row has exactly its six keys" "$(jv "$T/s.json" sockets.0 keys)" "proto,family,address,port,program,unit"
chk "not truncated" "$(jv "$T/s.json" truncated)" "false"

echo "=== Caps, and an ss that does not answer ==="
cat > "$T/bin/ss" <<'STUB'
#!/bin/bash
for i in $(seq 1 150); do echo "tcp LISTEN 0 10 127.0.0.1:$((20000+i)) 0.0.0.0:*"; done
STUB
PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/many.json" 2>/dev/null
chk "150 listeners: 100 listed, truncated" "$(jv "$T/many.json" sockets count)/$(jv "$T/many.json" truncated)" "100/true"
cat > "$T/bin/ss" <<'STUB'
#!/bin/bash
exit 1
STUB
PATH="$T/bin:$PATH" bash "$SCRIPT" > "$T/none.json" 2>/dev/null; rc=$?
chk "ss failing: exit 0, sockets unknown" "$rc/$(jv "$T/none.json" sockets)" "0/unknown"

echo "=== Static pins ==="
chk "ss is asked for listeners only (-l), numeric, TCP and UDP, under the timeout" "$(grep -c 'run ss -H -l -n -t -u -p' "$SCRIPT")" "1"
chk "nothing writes" "$(grep -v '^[[:space:]]*#' "$SCRIPT" | grep -c -E '\btee\b|>\s*/(etc|var|srv|usr|run)|\brm |\bmv |\bkill\b')" "0"

echo
echo "listening_sockets gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
