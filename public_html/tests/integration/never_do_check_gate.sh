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
# a program run from /tmp is seen while it runs, a port is named after the
# holder that started first (pids wrap), nothing writes, and the mail gateway's credentials
# drop-in is one fixed entry only while it holds nothing but LoadCredential lines,
# and the shipped gateway's own ports and enable link are left out.
# 1.7 (specs/never_do_false_alarms.md): the object says format 2, a setuid
# program not owned by root is setid_other with its hash, one inside a copied
# root filesystem is listed apart (setuid_copied_root), a name ending in a space
# is its own file, and a preload entry is sorted by whether a package installed
# what it names intact, with $LIB expanded whatever the working directory.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$ROOT/maintenance_scripts/sysadmin_tools/never_do_check.sh"
T=$(mktemp -d)
SCRATCH=$(mktemp -d /tmp/never-do-gate.XXXXXX)
# Setuid fixtures need a filesystem that allows setuid, which /tmp often is not.
SUID=$(mktemp -d "${HOME:-/root}/.never-do-gate.XXXXXX")
SLEEPER_PID=""
trap '[ -n "$SLEEPER_PID" ] && kill "$SLEEPER_PID" 2>/dev/null; rm -rf "${T:?}" "${SCRATCH:?}" "${SUID:?}"' EXIT
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
# A setuid program owned by this user (not root), and one inside a copied root
# filesystem. Root's copy of either would be setuid-root; as another user it
# is setid_other, which is what the gate can make.
cp "$(type -P true)" "$SUID/mine"; chmod 4755 "$SUID/mine"
# The same name with a trailing space, other bytes: its own file, never "mine".
{ cat "$(type -P true)"; printf 'x'; } > "$SUID/mine "; chmod 4755 "$SUID/mine "
mkdir -p "$SUID/rootfs/etc" "$SUID/rootfs/usr/bin"; echo 'root:x:0:0:root:/root:/bin/sh' > "$SUID/rootfs/etc/passwd"
cp "$(type -P true)" "$SUID/rootfs/usr/bin/sh"
cp "$(type -P true)" "$SUID/rootfs/usr/bin/su"; chmod 4755 "$SUID/rootfs/usr/bin/su"
"$SCRATCH/sleep" 300 &
SLEEPER_PID=$!
sleep 0.2
before="$(ls -A "$ROOT" | md5sum)"
TMPDIR="$T" bash "$SCRIPT" > "$T/real.json" 2> "$T/real.err"; rc=$?
chk "exit 0" "$rc" "0"
chk "one line" "$(wc -l < "$T/real.json")" "1"
chk "nothing on stderr" "$(wc -c < "$T/real.err")" "0"
chk "the top-level keys" "$(jv "$T/real.json" "" keys)" "format,findings,inventory,truncated,partial,generated_at"
chk "format 2" "$(jv "$T/real.json" format)" "2"
chk "the findings" "$(jv "$T/real.json" findings keys)" \
    "setuid_unpackaged,setuid_modified,setid_other,setuid_copied_root,uid0_accounts,kernel_taint,scratch_programs,deleted_root_programs,ld_preload,ld_preload_unpackaged,ld_preload_packaged"
if ! findmnt -no OPTIONS -T "$SUID" | grep -qw nosuid; then
    want_hash="$(sha256sum "$SUID/mine" | cut -c1-16)"
    if [ "$(stat -c %u "$SUID/mine")" = 0 ]; then is_list=setuid_unpackaged; not_list=setid_other; who="root"
    else is_list=setid_other; not_list=setuid_unpackaged; who="another user"; fi
    chk "a setuid program owned by $who is $is_list, path and hash" \
        "$(jv "$T/real.json" findings.$is_list lines | grep -cxF "$SUID/mine $want_hash")" "1"
    space_hash="$(sha256sum "$SUID/mine " | cut -c1-16)"
    chk "a name ending in a space is its own file, with its own hash, not folded into the one beside it" \
        "$(jv "$T/real.json" findings.$is_list lines | grep -cxF "$SUID/mine? $space_hash")" "1"
    chk "and not $not_list" "$(jv "$T/real.json" findings.$not_list lines | grep -c "$SUID/")" "0"
    chk "a setuid program inside a copied root filesystem is listed as one, never dropped" \
        "$(jv "$T/real.json" findings.setuid_copied_root lines | grep -c "^$SUID/rootfs/usr/bin/su ")" "1"
    chk "and is in no other list" \
        "$(jv "$T/real.json" findings.$is_list lines | grep -c "$SUID/rootfs")" "0"
else
    echo "  SKIP: setuid fixtures: $SUID is on a nosuid filesystem"
fi
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

echo "=== A port is named after the holder that opened it ==="
eval "$(sed -n '/^holders_by_age()/,/^}/p; /^port_holder()/,/^}/p' "$SCRIPT")"
# A fake /proc: each stat line carries its start time in field 22, and a
# comm with a space and a parenthesis, which the parse must survive.
PROC="$T/proc"
fake_stat() {  # pid starttime
    mkdir -p "$PROC/$1"
    printf '%s (odd name) x) S 1 %s %s\n' "$1" "1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17" "$2" > "$PROC/$1/stat"
}
fake_stat 980 100; fake_stat 2043 900
chk "a postfix master and its smtpd child read as master, whichever ss lists first" \
    "$(port_holder 'users:(("smtpd",pid=2043,fd=6),("master",pid=980,fd=13))')" "master"
chk "and the other way round" \
    "$(port_holder 'users:(("master",pid=980,fd=13),("smtpd",pid=2043,fd=6))')" "master"
fake_stat 4000000 100; fake_stat 1500 900
chk "a child forked after the pids wrapped (lower pid, started later) still reads as master" \
    "$(port_holder 'users:(("smtpd",pid=1500,fd=6),("master",pid=4000000,fd=13))')" "master"
chk "a holder gone before its start time is read sorts last" \
    "$(port_holder 'users:(("smtpd",pid=7777,fd=6),("master",pid=4000000,fd=13))')" "master"
fake_stat 711 50
chk "one holder is that holder" "$(port_holder 'users:(("sshd",pid=711,fd=3))')" "sshd"
chk "no holder (not root) is empty" "$(port_holder '')" ""
PROC=/proc

echo "=== Container image layers are not walked ==="
eval "$(sed -n '/^walk_skips()/,/^}/p' "$SCRIPT")"
SKIPS="$(walk_skips)"
for d in /var/lib/docker /var/lib/containerd /var/lib/containers /var/lib/joinery-site-engine-build \
         '*/.local/share/containers' '*/.local/share/containerd'; do
    chk "the walk skips $d" "$(printf '%s\n' "$SKIPS" | grep -cxF -- "$d")" "1"
done
# An engine's storage moved by its own configuration is read from it.
FAKE="$T/etc"; mkdir -p "$FAKE/docker" "$FAKE/containers"
printf '{\n  "data-root": "/srv/joinery/docker",\n  "userns-remap": "default"\n}\n' > "$FAKE/docker/daemon.json"
printf '[storage]\ndriver = "overlay"\ngraphroot = "/srv/joinery/containers/storage"\n' > "$FAKE/containers/storage.conf"
MOVED="$(eval "$(sed -n '/^system_tree()/,/^}/p; /^configured_storage()/,/^}/p; /^walk_skips()/,/^}/p' "$SCRIPT" | sed "s#/etc/docker/#$FAKE/docker/#; s#/etc/containers/#$FAKE/containers/#")"; walk_skips)"
printf '{ "data-root": "/usr" }\n' > "$FAKE/docker/daemon.json"
MOVED2="$(eval "$(sed -n '/^system_tree()/,/^}/p; /^configured_storage()/,/^}/p; /^walk_skips()/,/^}/p' "$SCRIPT" | sed "s#/etc/docker/#$FAKE/docker/#; s#/etc/containers/#$FAKE/containers/#")"; walk_skips)"
chk "a data-root of /usr does not take /usr out of the walk" "$(printf '%s\n' "$MOVED2" | grep -cxF /usr)" "0"
chk "Docker's data-root from daemon.json" "$(printf '%s\n' "$MOVED" | grep -cxF /srv/joinery/docker)" "1"
chk "Podman's graphroot from storage.conf" "$(printf '%s\n' "$MOVED" | grep -cxF /srv/joinery/containers/storage)" "1"


echo "=== A copied root filesystem, wherever it is (1.7) ==="
eval "$(sed -n '/^copied_root_of()/,/^}/p' "$SCRIPT")"
R="$T/img"; mkdir -p "$R/etc" "$R/usr/bin" "$R/usr/lib/x"; echo 'root:x:0:0:root:/root:/bin/sh' > "$R/etc/passwd"; : > "$R/usr/bin/sh"
icr() { copied_root_of "$1" || echo no; }
chk "a program in its usr/bin is inside one, named" "$(icr "$R/usr/bin/su")" "$R"
chk "so is one deeper" "$(icr "$R/usr/lib/x/helper")" "$R"
chk "the machine's own /usr/bin is not" "$(icr /usr/bin/passwd)" "no"
chk "a program beside the copy is not" "$(icr "$T/su")" "no"
R2="$T/half"; mkdir -p "$R2/usr/bin"
chk "usr/bin without etc/passwd is not a root filesystem" "$(icr "$R2/usr/bin/su")" "no"
R3="$T/linked"; mkdir -p "$R3/etc" "$R3/usr/bin"; ln -s /etc/passwd "$R3/etc/passwd"; : > "$R3/usr/bin/sh"
chk "nor is one whose etc/passwd is a link" "$(icr "$R3/usr/bin/su")" "no"
R4="$T/empty"; mkdir -p "$R4/etc" "$R4/usr/bin"; : > "$R4/etc/passwd"; : > "$R4/usr/bin/sh"
chk "nor one whose passwd has no root line" "$(icr "$R4/usr/bin/su")" "no"
R5="$T/noshell"; mkdir -p "$R5/etc" "$R5/usr/bin"; echo 'root:x:0:0::/:/bin/sh' > "$R5/etc/passwd"
chk "nor one with no shell" "$(icr "$R5/usr/bin/su")" "no"
chk "a top-level directory and /usr/local never count" \
    "$(grep -c '"${d#/}" == \*/\* && "$d" != /usr/local' "$SCRIPT")" "1"

echo "=== An engine's configured storage (1.7) ==="
eval "$(sed -n '/^system_tree()/,/^}/p' "$SCRIPT")"
st() { system_tree "$1" && echo system || echo skip; }
chk "the data root's docker storage is skipped" "$(st /srv/joinery/docker)" "skip"
chk "a home's is skipped" "$(st /home/joinery/containers)" "skip"
for d in / /usr /usr/local /usr/local/bin /etc/x /opt /var /bin /lib64 /boot/x /home /srv; do
    chk "$d is never skipped" "$(st "$d")" "system"
done
chk "a relative or dotted path is never skipped" "$(st srv/x)$(st /srv/../usr)" "systemsystem"
chk "the engines' configuration is in the startup list, packaged or not" \
    "$(grep -c 'for f in "${ENGINE_CONFIGS\[@\]}"' "$SCRIPT")" "1"

echo "=== A preload entry (1.7) ==="
eval "$(sed -n '/^preload_candidates()/,/^}/p' "$SCRIPT")"
libc="$(ls /lib/*-linux-gnu/libc.so.6 2>/dev/null | head -n1)"
if [ -n "$libc" ]; then
    chk "a bare name is looked up in the library directories" "$(preload_candidates libc.so.6 | grep -cxF "$libc")" "1"
fi
chk "a path stays the path" "$(preload_candidates /opt/x/lib.so | sort -u)" "/opt/x/lib.so"
# ld.so gives $LIB the machine's own multiarch directory (lib/x86_64-linux-gnu
# on Ubuntu); the candidates must not depend on the working directory.
ma="lib/$(uname -m)-linux-gnu"
chk "\$LIB is expanded to $ma, from a working directory that is not /" \
    "$(cd "$T" && preload_candidates '/opt/x/$LIB/e.so' | grep -cxF "/opt/x/$ma/e.so")" "1"
chk "and from / alike" "$(cd / && preload_candidates '/opt/x/$LIB/e.so' | grep -cxF "/opt/x/$ma/e.so")" "1"
chk "no candidate keeps a glob" "$(cd "$T" && preload_candidates '/opt/x/$LIB/e.so' | grep -c '[*]')" "0"
chk "\${PLATFORM} is expanded" "$(preload_candidates '/opt/${PLATFORM}/e.so' | grep -c '\$')" "0"

echo "=== The preload scan, on a fixture file (1.7) ==="
failed_before=$failed
(
    eval "$(sed -n '/^safe()/,/^}/p; /^hash16()/p; /^item_line()/,/^}/p; /^is_packaged()/p; /^packaged_intact()/,/^}/p; /^preload_candidates()/,/^}/p; /^preload_scan()/,/^}/p' "$SCRIPT")"
    MAX_STR=200
    T="$T/pl"; mkdir -p "$T" "$T/opt/$ma"
    cp "$(type -P true)" "$T/opt/$ma/evil.so"
    : > "$T/pkgfiles"; : > "$T/pkgmd5"
    : > "$T/preload_unpackaged"; : > "$T/preload_packaged"
    printf '%s\n' "$T/opt/\$LIB/evil.so" '$ORIGIN/x.so' /nonexistent/gone.so > "$T/ld.so.preload"
    cd "$T"
    preload_scan "$T/ld.so.preload"
    evil_hash="$(sha256sum "$T/opt/$ma/evil.so" | cut -c1-16)"
    chk "an unpackaged library named through \$LIB alarms, hashed" \
        "$(grep -cxF "$(safe "$T/opt/\$LIB/evil.so") $evil_hash" "$T/preload_unpackaged")" "1"
    chk "an entry with a token this cannot check (\$ORIGIN) alarms" "$(grep -cxF '?ORIGIN/x.so' "$T/preload_unpackaged")" "1"
    chk "a plain name that is not there is listed as missing" "$(grep -cxF '/nonexistent/gone.so missing' "$T/preload_packaged")" "1"
    chk "and nothing else lands in either list" "$(cat "$T/preload_unpackaged" "$T/preload_packaged" | wc -l)" "3"
    exit $(( failed > failed_before ))
) || failed=$((failed + 1))
passed=$((passed + 4))
eval "$(sed -n '/^hash16()/p; /^item_line()/,/^}/p; /^safe()/,/^}/p' "$SCRIPT")"
MAX_STR=200
chk "an item whose file cannot be read is its path alone" "$(item_line /nonexistent/x)" "/nonexistent/x"

echo "=== The mail gateway's credentials drop-in (1.4) ==="
eval "$(sed -n '/^credentials_only()/,/^}/p' "$SCRIPT")"
C="$T/credentials.conf"
printf '[Service]\n' > "$C"
chk "[Service] alone is credentials only" "$(credentials_only "$C" && echo yes || echo no)" "yes"
printf '[Service]\nLoadCredential=joinerytest.crt:/etc/letsencrypt/live/dev.getjoinery.com/fullchain.pem\nLoadCredential=joinerytest.key:/etc/letsencrypt/live/dev.getjoinery.com/privkey.pem\n' > "$C"
chk "certificate hand-offs are credentials only" "$(credentials_only "$C" && echo yes || echo no)" "yes"
printf '[Service]\nLoadCredential=a.crt:/x\nExecStartPre=/tmp/x\n' > "$C"
chk "any other line is not" "$(credentials_only "$C" && echo yes || echo no)" "no"
printf '[Service]\nLoadCredential=a.crt:relative\n' > "$C"
chk "a credential that is not an absolute path is not" "$(credentials_only "$C" && echo yes || echo no)" "no"
ln -s "$C" "$T/link.conf"
chk "a link is not" "$(credentials_only "$T/link.conf" && echo yes || echo no)" "no"
chk "only the gateway's own path gets the fixed entry" "$(grep -c 'GATEWAY_CREDENTIALS" ]] && credentials_only' "$SCRIPT")" "1"


echo "=== The mail gateway's ports and enable link (1.5) ==="
G="$T/gw"; mkdir -p "$G/lib" "$G/root/public_html/plugins/mail_apps/provisioning/bin"
cp "$(command -v bash)" "$G/lib/mail-gateway"
cp "$(command -v bash)" "$G/root/public_html/plugins/mail_apps/provisioning/bin/mail-gateway-$(uname -m)"
eval "$(sed -n '/^gateway_is_shipped()/,/^}/p; /^holders_by_age()/,/^}/p; /^port_holder_pid()/,/^}/p; /^gateway_port()/,/^}/p' "$SCRIPT")"
GATEWAY_BIN="$G/lib/mail-gateway"; SITE_ROOT="$G/root"
"$G/lib/mail-gateway" -c 'sleep 300; :' &
GW_PID=$!
sleep 0.2
gp() { gateway_port "$1" "$2" && echo yes || echo no; }
chk "993 held by the shipped gateway is left out" "$(gp 993 "users:((\"mail-gateway\",pid=$GW_PID,fd=3))")" "yes"
chk "so are 465 and 10465" "$(gp 465 "users:((\"mail-gateway\",pid=$GW_PID,fd=4))")$(gp 10465 "users:((\"mail-gateway\",pid=$GW_PID,fd=5))")" "yesyes"
chk "another port it holds is not" "$(gp 8443 "users:((\"mail-gateway\",pid=$GW_PID,fd=6))")" "no"
chk "993 held by another program is not" "$(gp 993 "users:((\"mail-gateway\",pid=$$,fd=3))")" "no"
printf 'x' >> "$G/root/public_html/plugins/mail_apps/provisioning/bin/mail-gateway-$(uname -m)"
chk "a gateway that is not the shipped one, byte for byte, is not" "$(gp 993 "users:((\"mail-gateway\",pid=$GW_PID,fd=3))")" "no"
pkill -P "$GW_PID" 2>/dev/null; kill "$GW_PID" 2>/dev/null
chk "only the gateway's own enable link to its own unit is left out" \
    "$(grep -c '"$f" == "$GATEWAY_LINK" && "$(readlink "$f")" == "$GATEWAY_UNIT"' "$SCRIPT")" "1"

echo
echo "never_do_check gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
