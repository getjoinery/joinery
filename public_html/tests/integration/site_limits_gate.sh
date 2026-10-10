#!/bin/bash
# @joinery-test
# name: site_limits
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/sysadmin_tools/site_limits.sh, maintenance_scripts/install_tools/_site_run_spec.sh, maintenance_scripts/install_tools/install.sh]
#
# A site's memory, CPU ceiling and disk allowance changed without a rebuild
# (specs/multi_tenant_docker_hosts.md WP6), with docker and the disk pool
# stubbed and the run spec in a scratch root:
#
#   - each value is a figure, none, or keep; keep leaves the spec's;
#   - memory and CPU go to docker update on the running container (swap held
#     to the memory figure) and into the run spec; the container restarts only
#     when its memory changed;
#   - the disk allowance goes to docker_disk_pool.sh allow, none to release;
#   - refused (exit 2, nothing done): a container that is not install.sh's, a
#     site with no run spec, a value install.sh would refuse, a CPU ceiling
#     above the host's, lifting a running container's memory or CPU, an
#     allowance on a host with no pool;
#   - install.sh site-limits runs it, keep for every flag left out.
#
# Run on a real host (mt-wp4-scratch, 2026-10-07): see the spec.

set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$HERE/../../.." && pwd)"
SCRIPT="$ROOT_DIR/maintenance_scripts/sysadmin_tools/site_limits.sh"
INSTALL="$ROOT_DIR/maintenance_scripts/install_tools/install.sh"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed + 1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed + 1)); fi
}

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/tools" "$T/root/etc/joinery/sites/sitea"
cp "$ROOT_DIR/maintenance_scripts/install_tools/_site_run_spec.sh" "$T/tools/"
export GATE_LOG="$T/log" GATE_POOL="$T/pool"
cat > "$T/tools/docker_disk_pool.sh" <<'STUB'
#!/bin/bash
echo "pool $*" >> "$GATE_LOG"
[ "$1" = can-cap ] && { [ -f "$GATE_POOL" ] && exit 0; echo 'docker_disk_pool: this host has no disk pool' >&2; exit 1; }
exit 0
STUB
cat > "$T/bin/docker" <<'STUB'
#!/bin/bash
case "$1" in
    inspect) case "${@: -1}" in sitea|nospec) echo "SITENAME=${@: -1}" ;; other) echo "SITENAME=sitea" ;; *) exit 1 ;; esac ;;
    info) echo 2 ;;
    update|restart) echo "docker $*" >> "$GATE_LOG" ;;
esac
STUB
chmod 755 "$T/bin/docker" "$T/tools/docker_disk_pool.sh"
SPEC="$T/root/etc/joinery/sites/sitea/run_spec"
reset() {
    printf 'spec_version=2\nhostname=sitea\nrestart=unless-stopped\nmemory=512m\ncpus=0.5\npids_limit=512\ndisk=1G\n' > "$SPEC"
    : > "$T/log"; touch "$T/pool"
}
run() { PATH="$T/bin:$PATH" JOINERY_SITE_STATE_ROOT="$T/root" SITE_LIMITS_TOOLS="$T/tools" bash "$SCRIPT" "$@"; }
key() { sed -n "s/^$1=//p" "$SPEC"; }
# The host's CPU count (run_spec_host_cpus, docker info) is the stub's: 2.

echo "=== changes ==="
reset
out="$(run sitea 768m keep 2G)"; rc=$?
chk "memory and disk changed: exit 0, done" "$rc|$(grep -c '"done":true' <<< "$out")" "0|1"
chk "memory goes to docker update, swap held to it" "$(grep -c '^docker update --memory=768m --memory-swap=768m sitea$' "$T/log")" "1"
chk "the allowance goes to the disk pool" "$(grep -c '^pool allow sitea 2G$' "$T/log")" "1"
chk "the run spec records both, and keeps the CPU ceiling" "$(key memory)|$(key cpus)|$(key disk)" "768m|0.5|2G"
chk "the memory change restarts the site" "$(grep -c '^docker restart sitea$' "$T/log")|$(grep -c '"restarted":true' <<< "$out")" "1|1"
chk "the object says what is in force" "$(grep -o '"memory":"[^"]*","cpus":"[^"]*","disk":"[^"]*"' <<< "$out")" '"memory":"768m","cpus":"0.5","disk":"2G"'
reset
out="$(run sitea keep 1.0 keep)"
chk "a CPU change alone: docker update --cpus, no restart" "$(grep -c '^docker update --cpus=1.0 sitea$' "$T/log")|$(grep -c restart "$T/log")|$(key cpus)" "1|0|1.0"
reset
out="$(run sitea keep keep keep)"
chk "keep for all: nothing changed" "$(grep -c '"done":true' <<< "$out")|$(grep -c -e '^docker' -e '^pool allow' -e '^pool release' "$T/log")" "1|0"
reset
out="$(run sitea keep keep none)"
chk "disk none: released, and the spec's line emptied" "$(grep -c '^pool release sitea$' "$T/log")|$(key disk)" "1|"

echo "=== refusals ==="
refused() {  # LABEL ARGS...
    local label="$1"; shift
    reset
    local o; o="$(run "$@" 2>&1)"; local r=$?
    chk "refused: $label" "$r|$(grep -c '^docker \(update\|restart\)' "$T/log")|$(key memory)$(key cpus)$(key disk)" "2|0|512m0.51G"
}
refused "a container that is not a site" nosuch keep keep keep
refused "a container whose SITENAME is another's" other keep keep keep
refused "a site with no run spec" nospec 1g keep keep
refused "a name that is not one" 'a;b' keep keep keep
refused "a memory size Docker would not take" sitea 1x keep keep
refused "a CPU ceiling that is not one" sitea keep 1.x keep
refused "a CPU ceiling above the host's" sitea keep 64 keep
refused "a disk size that is not one" sitea keep keep 4
refused "lifting a running container's memory" sitea none keep keep
refused "lifting a running container's CPU ceiling" sitea keep none keep
reset; rm -f "$T/pool"
o="$(run sitea keep keep 2G 2>&1)"; r=$?
chk "refused: an allowance on a host with no disk pool" "$r|$(grep -c 'no disk pool' <<< "$o")|$(key disk)" "2|1|1G"

echo "=== install.sh site-limits ==="
chk "the command runs site_limits.sh with keep for each flag left out" \
    "$(grep -c 'local site="" memory=keep cpus=keep disk=keep' "$INSTALL")|$(grep -c 'sysadmin_tools/site_limits.sh" "$site" "$memory" "$cpus" "$disk"' "$INSTALL")" "1|1"

echo
echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
