#!/bin/bash
# @joinery-test
# name: follow_main
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/dev_tools/follow_main.sh]
#
# follow_main.sh (dev's every-minute pull of main) run against a scratch clone
# through FOLLOW_MAIN_REPO: a file whose mode alone differs from git is the
# host converger's doing and does not stop the pull; an edited file or an
# untracked one does, and says so.

set -u
SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="$SITE_ROOT/maintenance_scripts/dev_tools/follow_main.sh"
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

g() { git -c user.name=t -c user.email=t@example.com -c commit.gpgsign=false "$@"; }

g init -q -b main "$T/work"
mkdir -p "$T/work/maintenance_scripts"
printf 'echo one\n' > "$T/work/maintenance_scripts/a.sh"
chmod 644 "$T/work/maintenance_scripts/a.sh"
g -C "$T/work" add -A && g -C "$T/work" commit -q -m one
g clone -q --bare "$T/work" "$T/origin.git"
g -C "$T/work" remote add origin "$T/origin.git"
g clone -q "$T/origin.git" "$T/dev"

ahead() {
    printf '%s\n' "$1" > "$T/work/maintenance_scripts/b.txt"
    g -C "$T/work" add -A && g -C "$T/work" commit -q -m "$1" && g -C "$T/work" push -q origin main
}
run() { HOME="$T/home" FOLLOW_MAIN_REPO="$T/dev" bash "$SCRIPT" 2>&1; }
mkdir -p "$T/home"

echo "== A mode the converger set is not a local edit =="
ahead two
chmod 755 "$T/dev/maintenance_scripts/a.sh"
out="$(run)"
chk "a script at 755 where git says 644 is pulled over" "$(g -C "$T/dev" log -1 --format=%s)" "two"
chk "and the run says where it is" "$(grep -c 'follow_main: at ' <<<"$out")" "1"

echo "== An edit or an untracked file still stops it =="
ahead three
echo 'echo edited' >> "$T/dev/maintenance_scripts/a.sh"
out="$(run)"
chk "an edited file stops the pull, saying so" "$(g -C "$T/dev" log -1 --format=%s)/$(grep -c 'NOT pulled' <<<"$out")" "two/1"
g -C "$T/dev" checkout -q -- maintenance_scripts/a.sh
touch "$T/dev/stray.txt"
out="$(run)"
chk "an untracked file stops the pull, saying so" "$(g -C "$T/dev" log -1 --format=%s)/$(grep -c 'NOT pulled' <<<"$out")" "two/1"
rm "$T/dev/stray.txt"
out="$(run)"
chk "with both gone it pulls" "$(g -C "$T/dev" log -1 --format=%s)" "three"

echo
echo "follow_main gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
