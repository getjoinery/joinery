#!/bin/bash
# @joinery-test
# name: parser_jail
# tier: safe
# env: any
# needs: [parser-jail]
# timeout: 120
#
# The parser jail from the outside (specs/parser_jail.md). Runs only where the
# launcher is installed (needs: parser-jail); tests/unit/document_text_test.php
# proves the same fence from inside the extraction subprocess. Each check here
# is one thing the launcher promises the command cannot do: be anyone but the
# jail user, write the tree, keep running past the deadline, or grow past the
# address space it was given.

set -u
LAUNCHER=/usr/local/sbin/joinery-jail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
passed=0; failed=0

chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

echo "== the launcher is installed the way the installer leaves it =="
chk "launcher exists" "$(test -f "$LAUNCHER" && echo yes)" "yes"
chk "owned by root" "$(stat -c %U "$LAUNCHER")" "root"
chk "setuid" "$(stat -c %a "$LAUNCHER")" "4755"
chk "the jail user exists" "$(id -u joinery-jail >/dev/null 2>&1 && echo yes)" "yes"
JAIL_UID="$(id -u joinery-jail)"

echo "== the command runs as the jail user with nothing extra =="
chk "uid is the jail user" "$("$LAUNCHER" -- /usr/bin/id -u)" "$JAIL_UID"
chk "no supplementary groups" "$("$LAUNCHER" -- /usr/bin/id -G | wc -w)" "1"
chk "environment is scrubbed" "$(SECRET=x "$LAUNCHER" -- /usr/bin/env | grep -c '^SECRET=')" "0"
chk "stdin reaches the command" "$(printf 'hello' | "$LAUNCHER" -- /bin/cat)" "hello"
chk "exit code passes through" "$("$LAUNCHER" -- /bin/sh -c 'exit 7'; echo $?)" "7"

echo "== what the command cannot do =="
probe="$ROOT/jail_gate_probe_$$"
"$LAUNCHER" -- /usr/bin/touch "$probe" 2>/dev/null
chk "cannot write the code tree" "$(test -e "$probe" && echo wrote || echo refused)" "refused"
rm -f "$probe"
chk "cannot fork (a shell running two commands)" \
    "$("$LAUNCHER" -- /bin/sh -c '/bin/true; /bin/true' >/dev/null 2>&1 && echo forked || echo refused)" "refused"
chk "cannot open a socket" \
    "$("$LAUNCHER" -- /usr/bin/php -r 'exit(@stream_socket_client("udp://127.0.0.1:9", $e, $s, 1) ? 1 : 0);' && echo refused || echo opened)" "refused"
chk "cannot change user" \
    "$("$LAUNCHER" -- /usr/bin/php -r 'exit(@posix_setuid(0) ? 1 : 0);' && echo refused || echo changed)" "refused"

echo "== limits the kernel enforces =="
start=$(date +%s)
"$LAUNCHER" --timeout=1 -- /bin/sleep 10 >/dev/null 2>&1; code=$?
chk "deadline exits 124" "$code" "124"
chk "and does so promptly" "$(( $(date +%s) - start < 5 ))" "1"
"$LAUNCHER" --rlimit-as=268435456 -- /usr/bin/php -d memory_limit=-1 -r '$a = str_repeat("x", 400 * 1024 * 1024); echo strlen($a);' >/dev/null 2>&1; code=$?
chk "address space is capped (a 400 MB allocation under a 256 MB ceiling fails)" "$( [ "$code" -ne 0 ] && echo capped || echo grew )" "capped"

echo "== the extractor works inside the fence =="
cd "$ROOT" || exit 1
out="$(php -r 'require "includes/PathHelper.php"; $r = DocumentText::extractBytes("plain words", "text/plain"); echo $r["status"], ":", $r["text"];')"
chk "a document extracts through the jail" "$out" "ok:plain words"
out="$(php -r 'require "includes/PathHelper.php"; require "tests/fixtures/documents/JailProbeParser.php"; $r = DocumentText::parseWith("JailProbeParser", "x"); $j = json_decode($r["text"], true); echo (int)$j["uid"], ":", $j["socket"] ? "socket" : "nosocket", ":", $j["fork"] ? "fork" : "nofork", ":", $j["wrote_tree"] ? "wrote" : "nowrite", ":", $j["staged_mode"], ":", $j["config_readable"] ? "config" : "noconfig";')"
chk "a parser class runs as the jail user, without socket, fork or a tree write, staging 0600" \
    "${out%:*}" "$JAIL_UID:nosocket:nofork:nowrite:0600"
# The config file's mode is the tree's business (security_inventory S10), not
# the launcher's: a dev box keeps it world-readable. Named, not failed.
case "$out" in
    *:config) echo "  WARN: the config file is readable by the jail user on this box (tree permissions, not the jail)";;
esac

echo
echo "parser_jail gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
