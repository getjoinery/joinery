#!/bin/bash
# @joinery-test
# name: backup_files_sudo
# tier: safe
# env: any
# needs: []
# timeout: 120
#
# backup_files.sh against the sudo shapes that produced a week of empty
# backups reported as success (2026-09-05, dev box):
#
#   - an account with ONE narrow NOPASSWD rule validates with `sudo -v`, so
#     the old probe said "sudo works", and the `sudo tar` that followed was
#     refused with exit 1 and no output — which the script accepted as tar's
#     own "a file changed while being read" status and wrote a 32-byte
#     envelope around nothing.
#
# A fake `sudo` on PATH plays each shape. The property under test is that no
# shape can produce an archive of nothing that is reported as a backup, and
# that a box with only a narrow rule archives as the plain user instead.
#
# Shell-gate contract: exit 0 = pass, non-zero = fail.

set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
BACKUP="$ROOT/maintenance_scripts/sysadmin_tools/backup_files.sh"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

W=$(mktemp -d /tmp/jy_sudo_gate_XXXXXX)
trap 'rm -rf "$W"' EXIT
mkdir -p "$W/proj/sub" "$W/proj/config" "$W/out" "$W/narrow" "$W/refusing"
echo hello > "$W/proj/a.txt"; echo more > "$W/proj/sub/b.txt"; echo key > "$W/key"

# A narrow rule: -v validates, -l lists one helper, everything else refused.
cat > "$W/narrow/sudo" <<'FAKE'
#!/bin/bash
case "$1" in -n) shift;; esac
case "$1" in
  -v) exit 0;;
  -l) echo "User www-data may run the following commands on host:"; echo "    (root) NOPASSWD: /usr/local/bin/relay-helper"; exit 0;;
  *) echo "Sorry, user www-data is not allowed to execute '$*' as root." >&2; exit 1;;
esac
FAKE
# The rule lists ALL, but the command is refused anyway: the exact failure
# shape — exit 1, no output.
cat > "$W/refusing/sudo" <<'FAKE'
#!/bin/bash
case "$1" in -n) shift;; esac
case "$1" in
  -v) exit 0;;
  -l) echo "    (ALL : ALL) NOPASSWD: ALL"; exit 0;;
  *) exit 1;;
esac
FAKE
chmod +x "$W/narrow/sudo" "$W/refusing/sudo"

run() { # name, PATH prefix ('' = real), extra... -> sets RC and OUT
    local name="$1" prefix="$2"
    if [ -n "$prefix" ]; then
        OUT=$(PATH="$prefix:$PATH" bash "$BACKUP" proj --project-dir "$W/proj" --output-dir "$W/out" --name "$name" --key-file "$W/key" 2>&1); RC=$?
    else
        OUT=$(bash "$BACKUP" proj --project-dir "$W/proj" --output-dir "$W/out" --name "$name" --key-file "$W/key" 2>&1); RC=$?
    fi
}

if [ "$(id -u)" -eq 0 ]; then
    echo "  SKIP: running as root, the sudo probe never runs"; exit 0
fi

echo "== A narrow NOPASSWD rule is not sudo =="
run narrow "$W/narrow"
chk "the run succeeds as the plain user" "$RC" "0"
chk "sudo was not used" "$(echo "$OUT" | grep -c 'not allowed to execute')" "0"
chk "the archive holds the tree" "$([ "$(stat -c %s "$W/out/narrow.tar.gz.enc" 2>/dev/null || echo 0)" -gt 100 ] && echo yes || echo no)" "yes"

echo "== A refused sudo command can never be reported as a backup =="
run refusing "$W/refusing"
chk "the run fails" "$([ "$RC" -ne 0 ] && echo fail || echo pass)" "fail"
chk "it says nothing was archived" "$(echo "$OUT" | grep -c 'nothing was archived')" "1"
chk "no archive is left behind" "$([ -e "$W/out/refusing.tar.gz.enc" ] && echo left || echo gone)" "gone"

echo "== The root-only release signing key is left out on purpose, and said =="
echo secret > "$W/proj/config/agent_signing_key"; chmod 000 "$W/proj/config/agent_signing_key"
run signing ""
chk "the run succeeds without it" "$RC" "0"
chk "the omission is announced" "$(echo "$OUT" | grep -c 'agent_signing_key is root-only')" "1"
chmod 600 "$W/proj/config/agent_signing_key"

echo "== Any other unreadable file still fails the run =="
echo x > "$W/proj/config/other_secret"; chmod 000 "$W/proj/config/other_secret"
run other ""
chk "the run fails on an unreadable file" "$([ "$RC" -ne 0 ] && echo fail || echo pass)" "fail"
chk "no archive is left behind" "$([ -e "$W/out/other.tar.gz.enc" ] && echo left || echo gone)" "gone"
chmod 600 "$W/proj/config/other_secret"

echo "backup_files_sudo: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
