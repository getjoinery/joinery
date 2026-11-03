#!/bin/bash
# @joinery-test
# name: never_do_check
# tier: safe
# env: any
# needs: []
# timeout: 400
# covers: [maintenance_scripts/sysadmin_tools/never_do_check.sh]
#
# never_do_check.sh is what the agent's never_do_check observe word runs as
# root every five minutes (agent 1.106.0, specs/node_unexplained_root.md
# § 5.1). This gate pins its contract: it takes nothing, an argument changes
# nothing, the object has its keys, every item keeps only safe characters, a
# port in the ephemeral range reads eph, a run that is not root says partial,
# a program run from /tmp is seen while it runs, and nothing writes.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/never_do_check.sh"
T=$(mktemp -d)
SCRATCH=$(mktemp -d /tmp/never-do-gate.XXXXXX)
SLEEPER_PID=""
trap '[ -n "$SLEEPER_PID" ] && kill "$SLEEPER_PID" 2>/dev/null; rm -rf "${T:?}" "${SCRATCH:?}"' EXIT
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
        $path = $argv[2]; $v = $o;
        if ($path !== "") { foreach (explode(".", $path) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { echo "ABSENT"; exit; } $v = $v[$k]; } }
        if ($argv[3] === "keys") { echo is_array($v) ? implode(",", array_keys($v)) : "NOTOBJ"; exit; }
        if ($argv[3] === "lines") { foreach ((array)$v as $x) { echo is_string($x) ? $x : json_encode($x), "\n"; } exit; }
        if ($argv[3] === "unsafe") {
            $bad = 0;
            foreach (array("findings", "inventory") as $half) { foreach ($o[$half] as $list) {
                foreach ((array)$list as $item) { if (!is_string($item) || !preg_match("#^[A-Za-z0-9._/@:+=,*%? >-]*$#", $item)) { $bad++; } }
            } }
            echo $bad; exit;
        }
        echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));
    ' "$1" "$2" "${3:-value}"
}

echo "=== The real run on this box ==="
# A program started from a scratch directory, running while the check runs.
cp "$(command -v sleep)" "$SCRATCH/sleep"
"$SCRATCH/sleep" 300 &
SLEEPER_PID=$!
sleep 0.2
before="$(ls -A "$ROOT" | md5sum)"
TMPDIR="$T" bash "$SCRIPT" > "$T/real.json" 2> "$T/real.err"; rc=$?
chk "exit 0" "$rc" "0"
chk "one line" "$(wc -l < "$T/real.json")" "1"
chk "nothing on stderr" "$(wc -c < "$T/real.err")" "0"
chk "the top-level keys" "$(jv "$T/real.json" "" keys)" "findings,inventory,truncated,partial,generated_at"
chk "the findings" "$(jv "$T/real.json" findings keys)" \
    "setuid_unpackaged,setuid_modified,uid0_accounts,kernel_taint,scratch_programs,deleted_root_programs,ld_preload"
chk "the inventory" "$(jv "$T/real.json" inventory keys)" "ssh_keys,accounts,sudoers,startup,ports,logs"
chk "every item keeps only safe characters" "$(jv "$T/real.json" "" unsafe)" "0"
if [ "$(id -u)" = 0 ]; then
    chk "run as root it is complete" "$(jv "$T/real.json" partial)" "false"
else
    chk "run as another user it says partial" "$(jv "$T/real.json" partial)" "true"
fi
chk "the program running from /tmp is seen, by uid and path" \
    "$(jv "$T/real.json" findings.scratch_programs lines | grep -c "^$(id -u) $SCRATCH/sleep$")" "1"
chk "and never with its arguments" "$(jv "$T/real.json" findings.scratch_programs lines | grep -c ' 300$')" "0"
chk "root is listed as an account that can log in" "$(jv "$T/real.json" inventory.accounts lines | grep -c '^root 0 ')" "1"
chk "no port is a number in the ephemeral range" \
    "$(jv "$T/real.json" inventory.ports lines | awk '{ n = split($2, a, ":"); p = a[n] } p ~ /^[0-9]+$/ && p >= 32768 { c++ } END { print c + 0 }')" "0"
chk "a log is a path, an inode and a size" \
    "$(jv "$T/real.json" inventory.logs lines | grep -cvE '^/var/log/[a-z.]+ [0-9]+ [0-9]+$')" "0"
chk "no key comment travels: a key is a user and a fingerprint" \
    "$(jv "$T/real.json" inventory.ssh_keys lines | grep -cvE '^[A-Za-z0-9._-]+ SHA256:[A-Za-z0-9+/=]+$')" "0"
chk "its temp files are gone" "$(ls -A "$T" | grep -cv '^real\.')" "0"
chk "nothing in the tree was written" "$(ls -A "$ROOT" | md5sum)" "$before"

echo "=== An argument changes nothing ==="
TMPDIR="$T" bash "$SCRIPT" --skip=/tmp /etc > "$T/args.json" 2>/dev/null
# The lists, not the log sizes: auth.log grows between two runs on a live box.
for list in ssh_keys accounts sudoers startup; do
    chk "the same $list" "$(jv "$T/args.json" inventory.$list)" "$(jv "$T/real.json" inventory.$list)"
done
chk "the same program from /tmp" \
    "$(jv "$T/args.json" findings.scratch_programs lines | grep -c "$SCRATCH/sleep")" "1"

kill "$SLEEPER_PID" 2>/dev/null; wait "$SLEEPER_PID" 2>/dev/null; SLEEPER_PID=""
TMPDIR="$T" bash "$SCRIPT" > "$T/after.json" 2>/dev/null
chk "once it stops it is not reported" "$(jv "$T/after.json" findings.scratch_programs lines | grep -c "$SCRATCH/sleep")" "0"

echo "=== A merged-/usr path matches its package under either name ==="
# fuse3 on Ubuntu 24.04 lists /bin/fusermount3; the walk finds /usr/bin/fusermount3.
eval "$(sed -n '/^usr_twins()/,/^}/p' "$SCRIPT")"
merged=(bin sbin lib)
chk "a .list path gains its /usr twin" \
    "$(printf '/bin/fusermount3\n' | usr_twins / | grep -cx '/usr/bin/fusermount3')" "1"
chk "a /usr path gains its old twin" \
    "$(printf '/usr/sbin/x\n' | usr_twins / | grep -cx '/sbin/x')" "1"
chk "an .md5sums line keeps its sum on the twin" \
    "$(printf 'abc bin/fusermount3\n' | usr_twins "" | grep -cx 'abc usr/bin/fusermount3')" "1"
chk "a path outside the merged dirs gains nothing" "$(printf '/etc/y\n' | usr_twins / | wc -l)" "1"
merged=()
chk "on a machine that is not merged nothing gains a twin" "$(printf '/bin/x\n' | usr_twins / | wc -l)" "1"

echo "=== Container image layers are not walked ==="
for d in /var/lib/docker /var/lib/containerd /var/lib/containers; do
    chk "the walk prunes $d" "$(grep -c -- "-path $d " "$SCRIPT")" "1"
done
for d in containers containerd; do
    chk "the walk prunes a rootless engine's $d" "$(grep -c -- "-path '\*/.local/share/$d'" "$SCRIPT")" "1"
done

echo
echo "never_do_check gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
