#!/bin/bash
# @joinery-test
# name: install_new_site_only
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/install_tools/install.sh]
#
# install.sh site --new-site-only: what a host agent passes for a new site on
# a shared host (site_create). On that host a name already in use is another
# customer's site, and -y would stop and rebuild it over its volumes.
#   - a name this host already has (its container, its run spec, or any of
#     its volumes) is refused with nothing changed and nothing removed;
#   - a container engine that does not answer is a refusal, never "free";
#   - a domain another site's proxy file serves is refused; the site's own
#     file and a look-alike name are not a clash;
#   - a free name is marked unfinished (/etc/joinery/new-site/NAME);
#   - a finished install loses the mark; a failed one is removed whole and
#     loses it; a failed one whose removal did not finish keeps it;
#   - remains WITH a mark are an earlier run that never finished (killed at
#     the agent's time limit): they are removed and the install goes on;
#     remains with no mark are never touched;
#   - the exit trap do_site_create sets ends the script with the install's
#     own exit code after the cleanup.
# The engine and the removal tool are stand-ins under a scratch root.
set -u
SITE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$SITE_ROOT/maintenance_scripts/install_tools"
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

# A stand-in engine: containers are lines of $T/engine/containers, volumes are
# files under $T/engine/volumes, and $T/engine/down makes it not answer.
cat > "$T/stubs.sh" << 'EOF'
site_engine() {
    [ ! -e "$NEW_SITE_ROOT/engine/down" ] || return 1
    case "$1 ${2:-}" in
        "ps -a") cat "$NEW_SITE_ROOT/engine/containers" 2>/dev/null; return 0 ;;
        "volume inspect") [ -e "$NEW_SITE_ROOT/engine/volumes/$3" ] ;;
        *) return 1 ;;
    esac
}
EOF
# A stand-in removal tool: takes the site's container, run spec and volumes
# away and says it ran; $T/remove_fails makes it fail having removed nothing.
cat > "$T/remove.sh" << 'EOF'
#!/bin/bash
R="$NEW_SITE_ROOT"
echo "$1 $2" >> "$R/removed"
[ ! -e "$R/remove_fails" ] || exit 1
grep -vx -- "$1" "$R/engine/containers" > "$R/engine/containers.new" 2>/dev/null || true
mv "$R/engine/containers.new" "$R/engine/containers"
rm -f "$R/engine/volumes/$1"_* "$R/etc/joinery/sites/$1/run_spec"
exit 0
EOF

fresh() {
    rm -rf "${T:?}/engine" "${T:?}/etc" "$T/removed" "$T/remove_fails"
    mkdir -p "$T/engine/volumes" "$T/etc/joinery/sites" "$T/etc/apache2/sites-available"
    : > "$T/engine/containers"
}
# run SNIPPET: the snippet in a shell that has sourced install.sh and the stubs.
run() {
    NEW_SITE_ROOT="$T" NEW_SITE_REMOVE_TOOL="$T/remove.sh" \
        bash -c ". '$TOOLS/install.sh'; . '$T/stubs.sh'; $1" > "$T/out" 2>&1
    echo $?
}
said() { grep -c -- "$1" "$T/out"; }
MARK="$T/etc/joinery/new-site/st5"

echo "=== a free name ==="
fresh
chk "a free name and domain: accepted" "$(run 'new_site_begin st5 new.example.org')" "0"
chk "and marked unfinished" "$([ -f "$MARK" ] && echo yes || echo no)" "yes"
chk "nothing was removed" "$([ -e "$T/removed" ] && echo yes || echo no)" "no"

echo "=== a name this host already has ==="
fresh; echo st5 > "$T/engine/containers"
chk "its container: refused" "$(run 'new_site_begin st5 new.example.org')" "1"
chk "and the refusal names it" "$(said 'already has a site named st5 (its container)')" "1"
chk "no mark was made" "$([ -e "$MARK" ] && echo yes || echo no)" "no"
chk "nothing was removed" "$([ -e "$T/removed" ] && echo yes || echo no)" "no"
fresh; echo st55 > "$T/engine/containers"
chk "a longer name that starts the same is another site" "$(run 'new_site_begin st5 new.example.org')" "0"
fresh; mkdir -p "$T/etc/joinery/sites/st5"; : > "$T/etc/joinery/sites/st5/run_spec"
chk "its run spec alone: refused" "$(run 'new_site_begin st5 new.example.org')" "1"
chk "and the refusal names it" "$(said '(its run spec)')" "1"
fresh; : > "$T/engine/volumes/st5_postgres"
chk "one of its volumes alone: refused" "$(run 'new_site_begin st5 new.example.org')" "1"
chk "and the refusal names it" "$(said '(its volumes)')" "1"
chk "nothing was removed" "$([ -e "$T/removed" ] && echo yes || echo no)" "no"

echo "=== an engine that does not answer ==="
fresh; : > "$T/engine/down"
chk "refused, never read as free" "$(run 'new_site_begin st5 new.example.org')" "1"
chk "and it says why" "$(said 'container engine did not answer')" "1"
chk "no mark was made" "$([ -e "$MARK" ] && echo yes || echo no)" "no"

echo "=== a domain another site serves ==="
fresh; printf '<VirtualHost *:80>\n    ServerName Shop.Example.org\n</VirtualHost>\n' > "$T/etc/apache2/sites-available/other.conf"
chk "another site's ServerName, whatever its case: refused" "$(run 'new_site_begin st5 shop.example.org')" "1"
chk "and the refusal names that site" "$(said 'Another site on this host (other) already serves shop.example.org')" "1"
chk "no mark was made" "$([ -e "$MARK" ] && echo yes || echo no)" "no"
fresh; printf '    ServerName a.example.org\n    ServerAlias www.a.example.org shop.example.org\n' > "$T/etc/apache2/sites-available/other.conf"
chk "another site's ServerAlias: refused" "$(run 'new_site_begin st5 shop.example.org')" "1"
fresh; printf '    ServerName myshop.example.org\n    ServerAlias shop.example.org.evil.test\n' > "$T/etc/apache2/sites-available/other.conf"
chk "a look-alike name is not a clash" "$(run 'new_site_begin st5 shop.example.org')" "0"
fresh; printf '    ServerName shop.example.org\n' > "$T/etc/apache2/sites-available/st5.conf"
chk "the site's own proxy file is not a clash" "$(run 'new_site_begin st5 shop.example.org')" "0"

echo "=== how an install ends ==="
fresh; run 'new_site_begin st5 new.example.org' > /dev/null
run 'new_site_end st5 0 > "$NEW_SITE_ROOT/rc"' > /dev/null
chk "finished: exit code 0 kept" "$(cat "$T/rc")" "0"
chk "the mark is gone" "$([ -e "$MARK" ] && echo yes || echo no)" "no"
chk "nothing was removed" "$([ -e "$T/removed" ] && echo yes || echo no)" "no"
fresh; run 'new_site_begin st5 new.example.org' > /dev/null
echo st5 > "$T/engine/containers"; : > "$T/engine/volumes/st5_code"
run 'new_site_end st5 3 > "$NEW_SITE_ROOT/rc"' > /dev/null
chk "failed: its exit code kept" "$(cat "$T/rc")" "3"
chk "what it made was removed, without a prompt" "$(cat "$T/removed" 2>/dev/null)" "st5 -y"
chk "its container is gone" "$(grep -cx st5 "$T/engine/containers")" "0"
chk "the mark is gone, so a retry starts clean" "$([ -e "$MARK" ] && echo yes || echo no)" "no"
chk "and the retry is accepted" "$(run 'new_site_begin st5 new.example.org')" "0"
fresh; run 'new_site_begin st5 new.example.org' > /dev/null
echo st5 > "$T/engine/containers"; : > "$T/remove_fails"
run 'new_site_end st5 3 > "$NEW_SITE_ROOT/rc"' > /dev/null
chk "failed, and the removal did not finish: exit code kept" "$(cat "$T/rc")" "3"
chk "the mark stays, so the next run tries again" "$([ -f "$MARK" ] && echo yes || echo no)" "yes"

echo "=== an earlier run that was killed ==="
# Killed outright: no trap ran, so its mark and what it had made are both here.
fresh; run 'new_site_begin st5 new.example.org' > /dev/null
echo st5 > "$T/engine/containers"; : > "$T/engine/volumes/st5_postgres"
mkdir -p "$T/etc/joinery/sites/st5"; : > "$T/etc/joinery/sites/st5/run_spec"
chk "its remains are cleared and the install goes on" "$(run 'new_site_begin st5 new.example.org')" "0"
chk "through the removal tool" "$(cat "$T/removed" 2>/dev/null)" "st5 -y"
chk "the container is gone" "$(grep -cx st5 "$T/engine/containers")" "0"
chk "and the new run is marked unfinished" "$([ -f "$MARK" ] && echo yes || echo no)" "yes"
fresh; run 'new_site_begin st5 new.example.org' > /dev/null
echo st5 > "$T/engine/containers"; : > "$T/remove_fails"
chk "remains that cannot be removed: refused" "$(run 'new_site_begin st5 new.example.org')" "1"
chk "and it says so" "$(said 'could not be removed')" "1"
# The same remains with no mark are a site: never touched.
fresh; echo st5 > "$T/engine/containers"; : > "$T/engine/volumes/st5_postgres"
chk "the same remains with no mark: refused" "$(run 'new_site_begin st5 new.example.org')" "1"
chk "and nothing was removed" "$([ -e "$T/removed" ] && echo yes || echo no)" "no"

echo "=== the exit trap do_site_create sets ==="
TRAP_LINE="$(grep -m1 "^        trap 'new_site_rc=" "$TOOLS/install.sh")"
chk "do_site_create sets one" "$([ -n "$TRAP_LINE" ] && echo yes || echo no)" "yes"
printf '%s\n' "$TRAP_LINE" > "$T/trap.sh"
fresh
chk "a failing install ends with its own exit code" \
    "$(run "new_site_begin st5 new.example.org; echo st5 > '$T/engine/containers'; NEW_SITE_CLEANUP_NAME=st5; . '$T/trap.sh'; exit 7")" "7"
chk "after removing what it made" "$(cat "$T/removed" 2>/dev/null)" "st5 -y"
chk "and its mark" "$([ -e "$MARK" ] && echo yes || echo no)" "no"
fresh
chk "a command that fails under set -e does the same" \
    "$(run "new_site_begin st5 new.example.org; NEW_SITE_CLEANUP_NAME=st5; . '$T/trap.sh'; false; echo not-reached")" "1"
chk "and removes what was made" "$(cat "$T/removed" 2>/dev/null)" "st5 -y"
fresh
chk "a finished install ends 0" \
    "$(run "new_site_begin st5 new.example.org; NEW_SITE_CLEANUP_NAME=st5; . '$T/trap.sh'; exit 0")" "0"
chk "with nothing removed" "$([ -e "$T/removed" ] && echo yes || echo no)" "no"
chk "and no mark left" "$([ -e "$MARK" ] && echo yes || echo no)" "no"

echo "=== where do_site_create uses them ==="
chk "the checks run before the trap is set" \
    "$(awk '/new_site_begin "\$SITENAME" "\$DOMAIN_NAME"/ { b = NR } /^        trap .new_site_rc=/ { t = NR } END { print (b > 0 && t > b) ? "yes" : "no" }' "$TOOLS/install.sh")" "yes"
chk "any other install of the name drops the mark" \
    "$(grep -c '^        rm -f "\$(new_site_mark "\$SITENAME")"' "$TOOLS/install.sh")" "1"
chk "a dormant copy's name check is the same one" \
    "$(grep -c 'copy_clash="\$(site_name_remains "\$SITENAME")"' "$TOOLS/install.sh")" "1"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
