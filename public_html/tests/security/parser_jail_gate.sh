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
# A descriptor the caller left open (a web worker's file, session or socket)
# stays outside the fence, and keeping it out leaves the launcher running.
chk "an inherited descriptor does not reach the command" \
    "$("$LAUNCHER" -- /usr/bin/php -r 'echo @fopen("php://fd/9", "r") ? "leaked" : "kept out";' 9</etc/hostname)" "kept out"

echo "== what the command cannot do =="
# No box has a world-writable tree any more, the developer's included
# (specs/read_only_tree.md), so this is a hard check on every machine. It used
# to warn and pass on a 777 tree.
probe="$ROOT/jail_gate_probe_$$"
TREE_WRITE_EXPECT="nowrite"
"$LAUNCHER" -- /usr/bin/touch "$probe" 2>/dev/null
chk "cannot write the code tree" "$(test -e "$probe" && echo wrote || echo refused)" "refused"
rm -f "$probe"
chk "cannot fork (a shell running two commands)" \
    "$("$LAUNCHER" -- /bin/sh -c '/bin/true; /bin/true' >/dev/null 2>&1 && echo forked || echo refused)" "refused"
chk "cannot open a socket" \
    "$("$LAUNCHER" -- /usr/bin/php -r 'exit(@stream_socket_client("udp://127.0.0.1:9", $e, $s, 1) ? 1 : 0);' && echo refused || echo opened)" "refused"
chk "cannot change user" \
    "$("$LAUNCHER" -- /usr/bin/php -n -d extension=posix -r 'exit(function_exists("posix_setuid") && !@posix_setuid(0) ? 0 : 1);' && echo refused || echo changed)" "refused"

if grep -aq 'JOINERY_JAIL_INTERFACE=2' "$LAUNCHER"; then
    # The allowlist fence (specs/sandboxed_php_plugins.md design 2). Every row
    # of the design's tables also runs from inside, as real PHP making raw
    # system calls, in the launcher's own Go tests (joinery_jail/jail_fence_test.go);
    # these are the ones that need the installed, setuid binary.
    echo "== the allowlist fence =="
    CONFIG="$(cd "$ROOT/.." && pwd)/config/Globalvars_site.php"
    chk "cannot read the site config, even by an open" \
        "$("$LAUNCHER" -- /bin/cat "$CONFIG" >/dev/null 2>&1 && echo read || echo refused)" "refused"
    chk "cannot read /etc/passwd" \
        "$("$LAUNCHER" -- /bin/cat /etc/passwd >/dev/null 2>&1 && echo read || echo refused)" "refused"
    chk "cannot write /dev/shm without a run folder" \
        "$("$LAUNCHER" -- /usr/bin/php -n -r 'exit(@file_put_contents("/dev/shm/jj_gate_" . getmypid(), "x") === false ? 0 : 1);' && echo refused || echo wrote)" "refused"
    run_folder="$("$LAUNCHER" --scratch=1048576 -- /usr/bin/php -n -r '$t = getenv("TMPDIR"); echo file_put_contents("$t/a", "x") === 1 ? $t : "no";')"
    chk "a run folder of its own is writable" "${run_folder:0:26}" "/dev/shm/joinery-jail-run."
    chk "and is gone when the run ends" "$(test -e "$run_folder" && echo left || echo gone)" "gone"
    chk "a run folder over its cap is killed, and says why" \
        "$("$LAUNCHER" --scratch=1048576 -- /usr/bin/php -n -r '$t = getenv("TMPDIR"); for ($i = 0; $i < 8; $i++) { file_put_contents("$t/f$i", str_repeat("x", 1000000)); } usleep(400000); echo "lived";' 2>&1 | grep -c 'passed its cap')" "1"
    # reviewer2 D1: bytes the folder's sum cannot see. An open file unlinked
    # and written on, or an O_TMPFILE file, would hold memory past the cap.
    chk "a run cannot unlink a file in its folder" \
        "$("$LAUNCHER" --scratch=1048576 -- /usr/bin/php -n -r '$p = getenv("TMPDIR") . "/a"; file_put_contents($p, "x"); echo @unlink($p) ? "unlinked" : "refused";')" "refused"
    chk "a run cannot make an unnamed O_TMPFILE file" \
        "$("$LAUNCHER" --scratch=1048576 -- /usr/bin/python3 -I -c 'import os; os.open(os.environ["TMPDIR"], os.O_TMPFILE | os.O_RDWR)' >/dev/null 2>&1 && echo made || echo refused)" "refused"
    # reviewer2 D2: setting the stage marker yourself skips the supervisor,
    # its deadline and its cap, and named any live run's folder.
    chk "stage 2 cannot be entered from outside" \
        "$(JOINERY_JAIL_STAGE=2 JOINERY_JAIL_SCRATCH=/dev/shm/joinery-jail-run.x "$LAUNCHER" --scratch=1024 -- /usr/bin/id -u 2>&1 | grep -c 'started by the launcher itself')" "1"
    # reviewer2 D3: root opens the command; nothing about a root-only file
    # leaks, and a FIFO cannot hang the root child.
    chk "a root-only file named as the command says only 'not runnable'" \
        "$("$LAUNCHER" -- /etc/shadow 2>&1)" "joinery-jail: not a runnable program: /etc/shadow"
    chk "a missing root-only path says the same" \
        "$("$LAUNCHER" -- /root/nonexistent 2>&1)" "joinery-jail: not a runnable program: /root/nonexistent"
    fifo="/tmp/jj_gate_fifo_$$"; mkfifo "$fifo"
    # A launcher with the bug blocks a ROOT child in open(), which no timeout
    # here may kill; opening the far end releases it, so the gate never hangs.
    ( sleep 6; : > "$fifo" ) 2>/dev/null &
    chk "a FIFO as the command is refused at once" \
        "$(timeout 5 "$LAUNCHER" --timeout=2 -- "$fifo" >/dev/null 2>&1; echo $?)" "125"
    rm -f "$fifo"
    chk "--user refuses a name that is not a plugin user" \
        "$("$LAUNCHER" --user=root -- /usr/bin/id -u >/dev/null 2>&1; echo $?)" "125"
    chk "--user refuses a plugin with no user yet" \
        "$("$LAUNCHER" --user=jpx-nosuchplugin -- /usr/bin/id -u 2>&1 | grep -c 'being set up')" "1"
    chk "a plugin run gets no scratch folder" \
        "$("$LAUNCHER" --user=jpx-nosuchplugin --scratch=1024 -- /usr/bin/id -u >/dev/null 2>&1; echo $?)" "125"
    chk "a grant through a link is refused" \
        "$(ln -sfn "$(dirname "$CONFIG")" "/tmp/jj_gate_link_$$"; "$LAUNCHER" --read="/tmp/jj_gate_link_$$" -- /usr/bin/id -u >/dev/null 2>&1; echo $?; rm -f "/tmp/jj_gate_link_$$")" "125"
else
    echo "  NOTE: the installed launcher is the deny-list one; the allowlist rows run once install_parser_jail.sh installs the new binary"
fi

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
chk "a parser class runs as the jail user, without socket or fork, staging 0600, and cannot write the tree" \
    "${out%:*}" "$JAIL_UID:nosocket:nofork:$TREE_WRITE_EXPECT:0600"
# config/Globalvars_site.php is root:www-data 0640 on every box now, and the
# jail user is in neither. A parser that can read the site config can read the
# database password (specs/read_only_tree.md B15). This used to warn.
chk "the jail user cannot read the site config" "${out##*:}" "noconfig"

echo
echo "parser_jail gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
