#!/bin/bash
# @joinery-test
# name: site_engine_run_spec
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/_site_engine.sh, maintenance_scripts/install_tools/_site_run_spec.sh, maintenance_scripts/install_tools/install.sh]
#
# A site's run spec on a host whose sites run on the site container engine
# (specs/site_container_engine.md D4, D5), against a stand-in podman on PATH:
#
#   - the engine is podman where install_site_engine.sh put ours, docker
#     anywhere else, and a test can name it
#   - an id block is a fixed range: block N's first host id, its size, the last
#     block, a bad one refused, and idblock= checked as a spec line
#   - each new site takes the lowest block no spec and no claim holds, a claim
#     keeps a block for an install that stopped, a spec keeps its own, and two
#     sites never share one
#   - the run arguments carry the site's block as --uidmap/--gidmap, port 80
#     for an unprivileged process and the site filter (Docker's own); a spec
#     with no block, or a host with no filter, is refused there;
#     a Docker host's arguments carry neither
#   - a site network is made with no DNS service and its bridge named, read back
#     from Podman's account, and one with a DNS service is not a site network
#   - adopt is Docker's alone, and the CPU count and the remap answer are Podman's
#   - install.sh calls docker by name only in Docker's own host install, and
#     names the engine itself in what it tells the operator to type

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/install_tools"
T=$(mktemp -d) || { echo "mktemp failed"; exit 1; }
[ -n "$T" ] && [ -d "$T" ] && [ "$T" != "/" ] || { echo "no scratch dir"; exit 1; }
trap 'rm -rf "${T:?}"' EXIT
passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then
        echo "  PASS: $1"; passed=$((passed+1))
    else
        echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed+1))
    fi
}

if [ "$(id -u)" = "0" ]; then
    echo "  SKIP: run unprivileged; as root the fixture root is ignored and /etc would be written"
    echo "RESULT: 0 passed, 0 failed"
    exit 0
fi

export JOINERY_SITE_STATE_ROOT="$T/root"
mkdir -p "$T/bin" "$T/root/etc/joinery/host_restrictions" "$T/root/etc/containers"
echo '{"defaultAction":"SCMP_ACT_ERRNO"}' > "$T/root/etc/containers/joinery-site-seccomp.json"
cat > "$T/bin/podman" <<'STUB'
#!/bin/bash
echo "$*" >> "${STUB_CALLS:-/dev/null}"
case "$1 $2" in
    "info -f") echo "${STUB_CPUS:-4}"; exit 0 ;;
    "network inspect")
        if [ "$3" = "-f" ]; then
            [ -n "${STUB_NET_LINE:-}" ] || exit 1
            echo "$STUB_NET_LINE"; exit 0
        fi
        [ -n "${STUB_NET_LINE:-}" ]; exit $? ;;
    "network create") exit 0 ;;
    "network ls") exit 0 ;;
esac
exit 0
STUB
chmod 755 "$T/bin/podman"
cp "$T/bin/podman" "$T/bin/docker"
export PATH="$T/bin:$PATH"
export STUB_CALLS="$T/calls"
in_lib() { bash -c ". '$TOOLS/_site_run_spec.sh'; $1" 2>"$T/err"; }

echo "Which engine"
FX="$T/engine-fixture"
chk "a host without our engine runs its sites on docker" "$(SITE_ENGINE_ROOT="$FX" bash -c ". '$TOOLS/_site_engine.sh'; site_engine_kind")" "docker"
mkdir -p "$FX/usr/local/lib/joinery-site-engine/6.1.4-aaaaaaaaaaaa" "$FX/usr/local/bin"
ln -s 6.1.4-aaaaaaaaaaaa "$FX/usr/local/lib/joinery-site-engine/current"
printf '#!/bin/sh\n' > "$FX/usr/local/bin/podman"; chmod 755 "$FX/usr/local/bin/podman"
chk "a host where install_site_engine.sh put ours runs them on podman" "$(SITE_ENGINE_ROOT="$FX" bash -c ". '$TOOLS/_site_engine.sh'; site_engine_kind")" "podman"
chk "a test can name the engine" "$(JOINERY_SITE_ENGINE_KIND=podman bash -c ". '$TOOLS/_site_engine.sh'; site_engine_kind")" "podman"
chk "  and naming another is refused" "$(JOINERY_SITE_ENGINE_KIND=lxc bash -c ". '$TOOLS/_site_engine.sh'; site_engine_kind" 2>/dev/null; echo $?)" "1"

echo "An id block is a fixed range"
idb() { bash -c ". '$TOOLS/_site_engine.sh'; site_engine_idblock_base $1" 2>/dev/null; }
chk "block 0 starts at 2^31" "$(idb 0)" "2147483648"
chk "block 7 starts 7 x 65536 further on" "$(idb 7)" "$((2147483648 + 7 * 65536))"
chk "the last block ends below 2^32 - 1" "$(( $(idb 32766) + 65536 <= 4294967295 ))" "1"
chk "block 32767 is past the end" "$(idb 32767; echo $?)" "1"
chk "a block that is not a number is refused" "$(idb x7; echo $?)" "1"
chk "idblock=0 and idblock=32766 are spec lines" "$(in_lib "run_spec_check_line idblock=0 && run_spec_check_line idblock=32766 && echo ok")" "ok"
chk "idblock=32767 and idblock=x are not" "$(in_lib "run_spec_check_line idblock=32767 || run_spec_check_line idblock=x || echo refused")" "refused"

echo "Each site takes the lowest free block"
export JOINERY_SITE_ENGINE_KIND=podman
spec() {  # SITE [IDBLOCK]
    mkdir -p "$T/root/etc/joinery/sites/$1"
    { printf 'spec_version=2\nhostname=%s\nrestart=unless-stopped\nmemory=384m\ncpus=\npids_limit=512\n' "$1"
      printf 'publish=127.0.0.1:8081:80\nvolume=%s_code:/var/www/html/%s/public_html\n' "$1" "$1"
      [ -n "${2:-}" ] && printf 'idblock=%s\n' "$2"; } > "$T/root/etc/joinery/sites/$1/run_spec"
}
chk "the first site takes block 0" "$(in_lib "run_spec_idblock_line a")" "idblock=0"
chk "  and holds it with a claim until its spec is written" "$(cat "$T/root/etc/joinery/sites/a/idblock")" "0"
chk "the same site asking again gets its claim back" "$(in_lib "run_spec_idblock_line a")" "idblock=0"
chk "a second site, while the first is only claimed, takes block 1" "$(in_lib "run_spec_idblock_line b")" "idblock=1"
spec a 0; in_lib "run_spec_idblock_claim_clear a"
chk "the first site's spec written, its claim is cleared" "$([ -f "$T/root/etc/joinery/sites/a/idblock" ] && echo kept || echo cleared)" "cleared"
chk "  and its spec keeps the block" "$(in_lib "run_spec_idblock_line a")" "idblock=0"
spec c 5
chk "a site whose spec names a block keeps it" "$(in_lib "run_spec_idblock_line c")" "idblock=5"
chk "the next new site skips every block a spec or a claim holds" "$(in_lib "run_spec_idblock_line d")" "idblock=2"
chk "no two sites share a block" "$({ sed -n 's/^idblock=//p' "$T"/root/etc/joinery/sites/*/run_spec; cat "$T"/root/etc/joinery/sites/*/idblock; } 2>/dev/null | sort | uniq -d | wc -l)" "0"

echo "The run arguments"
spec e 7
args() { in_lib "mapfile -d '' A < <(run_spec_args $1) && printf '%s\n' \"\${A[@]}\""; }
A="$(args e)"
B7=$((2147483648 + 7 * 65536))
chk "the site's block as its uid map" "$(grep -A1 -x -- '--uidmap' <<< "$A" | tail -n1)" "0:${B7}:65536"
chk "and its gid map" "$(grep -A1 -x -- '--gidmap' <<< "$A" | tail -n1)" "0:${B7}:65536"
chk "port 80 open to an unprivileged process in the container" "$(grep -A1 -x -- '--sysctl' <<< "$A" | tail -n1)" "net.ipv4.ip_unprivileged_port_start=0"
chk "the site runs under the site filter, Docker's own" "$(grep -A1 -x -- '--security-opt' <<< "$A" | tail -n1)" "seccomp=/etc/containers/joinery-site-seccomp.json"
chk "never --userns=auto" "$(grep -c -- '--userns' <<< "$A")" "0"
chk "the six capabilities and nothing else, as on Docker" "$(grep -c -- '--cap-add=' <<< "$A") $(grep -c -x -- '--cap-drop=ALL' <<< "$A")" "6 1"
spec f
chk "a spec with no block gives no arguments on the site engine" "$(args f | grep -c .)" "0"
chk "  and says why" "$(grep -c 'has no id block' "$T/err")" "1"
mv "$T/root/etc/containers/joinery-site-seccomp.json" "$T/seccomp.aside"
chk "a host with no site filter runs no site" "$(args e | grep -c .)" "0"
chk "  and says what to run" "$(grep -c 'install_site_engine.sh converge' "$T/err")" "1"
mv "$T/seccomp.aside" "$T/root/etc/containers/joinery-site-seccomp.json"
A="$(JOINERY_SITE_ENGINE_KIND=docker args e)"
chk "a Docker host's arguments carry no id map, no sysctl and no filter of ours" "$(grep -c -E -- '^--(uidmap|gidmap|sysctl|security-opt)$' <<< "$A")" "0"

echo "A site network on the site engine"
: > "$STUB_CALLS"
in_lib "printf 'network=g_net\nbridge=jsnet3\nsubnet=10.250.3.0/24\nsubnet6=fd00:250:3::/64\n' | run_spec_network_create"
chk "made with no DNS service and its bridge named" "$(grep '^network create' "$STUB_CALLS")" "network create --driver bridge --disable-dns --interface-name jsnet3 --subnet 10.250.3.0/24 --ipv6 --subnet fd00:250:3::/64 g_net"
chk "read back from Podman's account" "$(STUB_NET_LINE='bridge|jsnet3|10.250.3.0/24 fd00:250:3::/64 |false' in_lib "run_spec_network_read g_net" | tr '\n' ' ')" "network=g_net bridge=jsnet3 subnet=10.250.3.0/24 subnet6=fd00:250:3::/64 "
chk "one with a DNS service is not a site network" "$(STUB_NET_LINE='bridge|jsnet3|10.250.3.0/24 |true' in_lib "run_spec_network_read g_net"; echo $?)" "1"
chk "  and says why" "$(grep -c 'DNS service on its bridge' "$T/err")" "1"

echo "What only Docker had"
chk "adopt is refused on the site engine" "$(in_lib "run_spec_adopt nospec"; echo $?)" "1"
chk "  saying the site is rebuilt instead" "$(grep -c 'rebuild the site with install.sh site' "$T/err")" "1"
chk "the CPU count is Podman's" "$(STUB_CPUS=8 in_lib "run_spec_host_cpus")" "8"
chk "and every site's ids are its own" "$(in_lib "run_spec_docker_remaps_ids; echo \$?")" "0"

echo "install.sh speaks to the site's engine"
# Every docker command outside Docker's own host install, by function (B10: the
# reach probe's docker exec failed on every site engine host).
bare_docker() {  # FILE
    awk '/^[a-zA-Z_][a-zA-Z0-9_]*\(\) *\{/ { fn = $1; sub(/\(\).*/, "", fn) } /^}/ { fn = "" } /^[[:space:]]*#/ { next }
         /(^|[^_a-z-])docker (exec|ps|run|inspect|cp|volume|network|image|images|build|stop|rm|start|logs|port|info|version|pull|tag|rmi|kill|restart|wait|update|system)( |$)/ \
         && fn != "do_docker_install" && fn != "docker_multi_tenant_existing" { print NR ":" (fn == "" ? "(top)" : fn) }' "$1"
}
INSTALL="$TOOLS/install.sh"
chk "no docker command outside Docker's own host install" "$(bare_docker "$INSTALL" | tr '\n' ' ')" ""
sed 's/site_engine exec "$sitename" sh -c "mkdir -p/docker exec "$sitename" sh -c "mkdir -p/' "$INSTALL" > "$T/install_mut.sh"
chk "  which a docker exec put back in the reach probe fails" "$(bare_docker "$T/install_mut.sh" | grep -c ':name_reaches_here$')" "1"
chk "what the operator is told to type names the engine, not this script's function" "$(grep -v '^[[:space:]]*#' "$INSTALL" | grep -E 'echo|print_' | grep -c 'site_engine \(exec\|logs\|stop\|start\)')" "0"

echo "A copy without the helper beside it"
# outbound_limits.sh installs _site_run_spec.sh alone; a tree missing the helper
# must never send a site engine host's calls to Docker (B11).
mkdir -p "$T/lone"; cp "$TOOLS/_site_run_spec.sh" "$TOOLS/install.sh" "$T/lone/"
lone() { (unset JOINERY_SITE_ENGINE_KIND; bash -c ". '$T/lone/$1'; $2" 2>"$T/err"); }
chk "the run spec helper alone, where the engine is not installed: docker" "$(SITE_ENGINE_ROOT="$T/none" lone _site_run_spec.sh site_engine_kind)" "docker"
chk "  and install.sh alone: docker" "$(SITE_ENGINE_ROOT="$T/none" lone install.sh site_engine_kind)" "docker"
: > "$STUB_CALLS"
chk "the run spec helper alone, where the engine is installed: every call refused" "$(SITE_ENGINE_ROOT="$FX" lone _site_run_spec.sh 'site_engine ps; echo $?')" "1"
chk "  saying the helper is missing" "$(grep -c 'no _site_engine.sh beside' "$T/err")" "1"
chk "  and nothing reached docker" "$(grep -c '^ps' "$STUB_CALLS")" "0"
chk "install.sh alone, where the engine is installed: refused too, and errexit stops its caller there" "$(SITE_ENGINE_ROOT="$FX" lone install.sh 'site_engine ps; echo carried on') $(grep -c 'no _site_engine.sh beside' "$T/err")" " 1"

echo
echo "site_engine_run_spec gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
