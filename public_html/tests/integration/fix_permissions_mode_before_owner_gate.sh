#!/bin/bash
# @joinery-test
# name: fix_permissions_mode_before_owner
# tier: safe
# env: any
# needs: []
# timeout: 30
# covers: [maintenance_scripts/install_tools/fix_permissions.sh, maintenance_scripts/install_tools/_plugin_installers_start.sh]
#
# A permission sweep sets the mode BEFORE the owner. Owner first, every entry
# sits between the two walks owned by its new owner at its old mode: a 0700
# directory or 0600 file handed to www-data is usable by nobody in the group
# until the chmod walk reaches it, and on a loaded box that is seconds. It
# took a test's own scratch directory from it mid-run (every later backup in
# the test failed), and it leaves a config/*.php or a tree file unreadable to
# the pool for the same window.
#
# Static, because the sweeps need root: in each block of each script (split at
# `fi`), no walk that changes owners (`-exec chown`) comes before a walk that
# changes modes (`-exec chmod`). A single file's `chown X; chmod X` is not a
# walk — the gap is one syscall — and the pins that hand a secret to its owner
# and then tighten it are that shape on purpose. Set SCRIPTS to check other
# copies.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPTS="${SCRIPTS:-$ROOT/maintenance_scripts/install_tools/fix_permissions.sh $ROOT/maintenance_scripts/install_tools/_plugin_installers_start.sh}"
passed=0; failed=0

for script in $SCRIPTS; do
    name=$(basename "$script")
    # One line per offending block: "line N: chown ... then line M: chmod ...".
    offences=$(awk '
        /^[[:space:]]*#/ { next }
        /-exec chown / { if (!chown_at) chown_at = NR; if (index($0, "-exec chmod ") > index($0, "-exec chown ")) { printf "line %d: chown, then chmod in the same walk\n", NR; chown_at = 0 } }
        /-exec chmod / && !/-exec chown / { if (chown_at) { printf "line %d owner walk, then line %d mode walk\n", chown_at, NR; chown_at = 0 } }
        /^[[:space:]]*fi[[:space:]]*$/ { chown_at = 0 }
    ' "$script")
    sweeps=$(grep -cE -- '-exec (chown|chmod) ' "$script")
    if [ "$sweeps" -gt 0 ]; then
        echo "  PASS: $name has permission walks to check ($sweeps)"; passed=$((passed+1))
    else
        echo "  FAIL: $name has no chown or chmod walk at all — the check reads nothing"; failed=$((failed+1))
    fi
    if [ -z "$offences" ]; then
        echo "  PASS: $name sets every mode before the owner"; passed=$((passed+1))
    else
        echo "  FAIL: $name changes an owner before its mode:"; echo "$offences" | sed 's/^/        /'
        failed=$((failed+1))
    fi
done

echo
echo "RESULT: $([ $failed -eq 0 ] && echo PASS || echo FAIL) $passed $failed"
[ $failed -eq 0 ]
