#!/bin/bash
# @joinery-test
# name: docker_multi_tenant
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/install.sh, maintenance_scripts/install_tools/_site_run_spec.sh, maintenance_scripts/sysadmin_tools/migrate_site_to_code_volumes.sh, maintenance_scripts/sysadmin_tools/rebase_site_container.sh]
#
# install.sh docker --multi-tenant turns on Docker's user-namespace remapping,
# so root in a site container is an unprivileged user on the host
# (specs/multi_tenant_docker_hosts.md WP5 item 3). This gate pins: daemon.json
# gains userns-remap and keeps what it had, and a broken one is left alone; the
# setting is written before Docker is installed; a host that already remaps is
# left alone; one with no containers and no volumes is switched over and
# checked; one with sites, or whose Docker does not answer, is refused with
# nothing changed. Host-side writes into volumes are pinned too: the code-volume
# move refuses on a remapping host, and the rebase rollback no longer chowns
# volume files from the host with the container's ids. Docker and systemctl are
# stubs; the same moves on a real host are live checks.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
TOOLS="$ROOT/maintenance_scripts/install_tools"
INSTALL="$TOOLS/install.sh"
MIGRATE="$ROOT/maintenance_scripts/sysadmin_tools/migrate_site_to_code_volumes.sh"
REBASE="$ROOT/maintenance_scripts/sysadmin_tools/rebase_site_container.sh"
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

mkdir -p "$T/bin"
# docker info answers from $T/secopts (missing: Docker does not answer); ps and
# volume ls print STUB_CONTAINERS / STUB_VOLUMES lines.
cat > "$T/bin/docker" <<'STUB'
#!/bin/bash
echo "docker $*" >> "$STUB_T/calls"
case "$1" in
    info)   [ -f "$STUB_T/secopts" ] || exit 1; cat "$STUB_T/secopts" ;;
    ps)     for i in $(seq 1 "${STUB_CONTAINERS:-0}"); do echo "c$i"; done ;;
    volume) for i in $(seq 1 "${STUB_VOLUMES:-0}"); do echo "v$i"; done ;;
    images) for i in $(seq 1 "${STUB_IMAGES:-0}"); do echo "i$i"; done ;;
esac
STUB
# A restart of Docker takes daemon.json's userns-remap, as the daemon does.
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
echo "systemctl $*" >> "$STUB_T/calls"
if [ "$1 $2" = "restart docker" ] && grep -q '"userns-remap"' "$STUB_T/daemon.json" 2>/dev/null; then
    echo "name=apparmor name=seccomp,profile=builtin name=userns name=cgroupns " > "$STUB_T/secopts"
fi
STUB
chmod +x "$T/bin/docker" "$T/bin/systemctl"
export PATH="$T/bin:$PATH" STUB_T="$T"

# The multi-tenant functions as install.sh has them, writing to a fixture
# daemon.json instead of /etc/docker's.
FUNCS="$(awk '/^# --- Docker daemon settings, and multi-tenant hosts/,/^do_docker_install\(\) \{$/' "$INSTALL" | sed '$d' \
    | sed -e "s#/etc/docker/daemon.json#${T}/daemon.json#" -e "s#mkdir -m 0755 -p /etc/docker#:#")"
chk "the daemon.json and multi-tenant functions are findable" "$(printf '%s\n' "$FUNCS" | grep -c '^docker_[a-z_]*() {')" "4"
run() {  # FUNCTION
    bash -c 'SCRIPT_DIR="$1"; print_success() { echo "OK $*"; }; print_error() { echo "ERR $*"; }
        '"$FUNCS"'
        '"$2" _ "$TOOLS"
}

echo "=== daemon.json gains userns-remap and keeps what it had ==="
rm -f "$T/daemon.json"
run x docker_daemon_json_set_userns_remap > /dev/null
chk "a missing file is created with it" "$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["userns-remap"])' "$T/daemon.json")" "default"
printf '{"builder":{"gc":{"enabled":true}},"log-driver":"local"}\n' > "$T/daemon.json"
run x docker_daemon_json_set_userns_remap > /dev/null
chk "existing settings are kept beside it" \
    "$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(d["userns-remap"], d["log-driver"], d["builder"]["gc"]["enabled"])' "$T/daemon.json")" "default local True"
printf '{"builder": oops' > "$T/daemon.json"
out="$(run x docker_daemon_json_set_userns_remap)"; rc=$?
chk "a broken daemon.json is refused and left as it was" "$rc|$(cat "$T/daemon.json")|$(echo "$out" | grep -c 'is it valid JSON')" '1|{"builder": oops|1'
chk "and no temporary file is left" "$(ls "$T" | grep -c 'daemon.json.tmp')" "0"
# A key inside a nested object is not a top-level key: the merge is JSON, not text.
printf '{\n  "userns-remap": "default",\n  "log-opts": {\n    "max-size": "10m"\n  }\n}\n' > "$T/daemon.json"
run x "docker_daemon_json_set builder '{\"gc\":{\"enabled\":true,\"defaultKeepStorage\":\"2GB\"}}'"
chk "the BuildKit policy lands at the top level only, the nested object untouched" \
    "$(python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); print(sorted(d), d["log-opts"], d["builder"]["gc"]["defaultKeepStorage"])' "$T/daemon.json")" \
    "['builder', 'log-opts', 'userns-remap'] {'max-size': '10m'} 2GB"
chk "install.sh edits daemon.json as text nowhere" "$(grep -v '^[[:space:]]*#' "$INSTALL" | grep -c 'sed -i[^|]*daemon\|sed -i .s/}\$/')" "0"

echo "=== Which hosts remap: Docker's own answer, and no answer is not a no ==="
echo "name=apparmor name=seccomp,profile=builtin name=userns name=cgroupns " > "$T/secopts"
chk "userns among the security options remaps" "$(bash -c '. "$1"; run_spec_docker_remaps_ids; echo $?' _ "$TOOLS/_site_run_spec.sh")" "0"
echo "name=apparmor name=seccomp,profile=builtin name=cgroupns " > "$T/secopts"
chk "without it does not" "$(bash -c '. "$1"; run_spec_docker_remaps_ids; echo $?' _ "$TOOLS/_site_run_spec.sh")" "1"
rm -f "$T/secopts"
chk "no answer from Docker is unknown, not no" "$(bash -c '. "$1"; run_spec_docker_remaps_ids; echo $?' _ "$TOOLS/_site_run_spec.sh")" "2"

echo "=== --multi-tenant on a host that already has Docker ==="
echo "name=seccomp,profile=builtin name=userns " > "$T/secopts"; : > "$T/calls"; rm -f "$T/daemon.json"
out="$(STUB_CONTAINERS=3 run x docker_multi_tenant_existing)"; rc=$?
chk "a host that already remaps is left alone, sites or not" "$rc|$(grep -c systemctl "$T/calls")|$(test -f "$T/daemon.json" && echo written)" "0|0|"

echo "name=seccomp,profile=builtin " > "$T/secopts"; : > "$T/calls"
out="$(STUB_CONTAINERS=2 STUB_VOLUMES=30 run x docker_multi_tenant_existing)"; rc=$?
chk "a host with sites is refused" "$rc" "1"
chk "and says what it would leave behind" "$(echo "$out" | grep -c '2 container(s) and 30 volume(s)')" "1"
chk "with nothing changed" "$(grep -c systemctl "$T/calls")|$(test -f "$T/daemon.json" && echo written)" "0|"
out="$(STUB_VOLUMES=1 run x docker_multi_tenant_existing)"; rc=$?
chk "a single leftover volume is refused too" "$rc|$(test -f "$T/daemon.json" && echo written)" "1|"
out="$(STUB_IMAGES=4 run x docker_multi_tenant_existing)"; rc=$?
chk "images alone are refused: remapping would strand them under the old root" \
    "$rc|$(echo "$out" | grep -c '4 image(s)')|$(test -f "$T/daemon.json" && echo written)|$(grep -c systemctl "$T/calls")" "1|1||0"

rm -f "$T/secopts"; : > "$T/calls"
out="$(run x docker_multi_tenant_existing)"; rc=$?
chk "a Docker that does not answer is refused with nothing changed" \
    "$rc|$(echo "$out" | grep -c 'did not say')|$(test -f "$T/daemon.json" && echo written)|$(grep -c systemctl "$T/calls")" "1|1||0"

echo "name=seccomp,profile=builtin " > "$T/secopts"; : > "$T/calls"
out="$(run x docker_multi_tenant_existing)"; rc=$?
chk "an empty Docker host is switched over" "$rc|$(grep -c '"userns-remap": "default"' "$T/daemon.json")|$(grep -c 'systemctl restart docker' "$T/calls")" "0|1|1"
chk "and checked after the restart" "$(echo "$out" | grep -c 'Docker remaps user ids')" "1"

# A daemon that ignores the setting (no kernel support, a typo) must not pass.
cat > "$T/bin/systemctl" <<'STUB'
#!/bin/bash
echo "systemctl $*" >> "$STUB_T/calls"
STUB
echo "name=seccomp,profile=builtin " > "$T/secopts"; rm -f "$T/daemon.json"
out="$(run x docker_multi_tenant_existing)"; rc=$?
chk "a restart that did not take remapping fails" "$rc|$(echo "$out" | grep -c 'running without user-namespace remapping')" "1|1"

echo "=== The setting precedes Docker's first start ==="
DOCKER_FN="$(awk '/^do_docker_install\(\) \{$/,/^}$/' "$INSTALL")"
line_of() { printf '%s\n' "$DOCKER_FN" | grep -n -- "$1" | head -1 | cut -d: -f1; }
set_at="$(line_of 'docker_daemon_json_set_userns_remap || exit 1')"
pkg_at="$(line_of 'apt-get install -y docker-ce')"
chk "daemon.json is written before the package that starts the daemon" \
    "$([ -n "$set_at" ] && [ -n "$pkg_at" ] && [ "$set_at" -lt "$pkg_at" ] && echo yes)" "yes"
chk "and the fresh install checks the daemon remaps" "$(printf '%s\n' "$DOCKER_FN" | grep -c 'docker_assert_remaps_ids || exit 1')" "1"
existing_at="$(line_of 'docker_multi_tenant_existing || exit 1')"
hk_at="$(line_of '^        host_housekeeping$')"
chk "an existing host is checked before its housekeeping and agent" \
    "$([ -n "$existing_at" ] && [ -n "$hk_at" ] && [ "$existing_at" -lt "$hk_at" ] && echo yes)" "yes"
walls_calls="$(printf '%s\n' "$DOCKER_FN" | grep -n 'multi_tenant_host_install || exit 1' | cut -d: -f1 | tr '\n' ' ')"
chk "both paths install the site walls, each right after its remap check" \
    "$walls_calls" "$((existing_at + 1)) $(( $(line_of 'docker_assert_remaps_ids || exit 1') + 1 )) "
chk "an existing host is walled before its housekeeping and agent" \
    "$([ "${walls_calls%% *}" -lt "$hk_at" ] && echo yes)" "yes"
agent_at="$(printf '%s\n' "$DOCKER_FN" | grep -n 'install_docker_host_agent' | tail -1 | cut -d: -f1)"
chk "a fresh host is walled before its agent joins" \
    "$([ "$(echo $walls_calls | cut -d' ' -f2)" -lt "$agent_at" ] && echo yes)" "yes"
WALLS_FN="$(awk '/^multi_tenant_host_install\(\) \{$/,/^}$/' "$INSTALL")"
chk "the helper runs multi_tenant_host.sh install beside install.sh" \
    "$(printf '%s\n' "$WALLS_FN" | grep -c 'bash "$SCRIPT_DIR/multi_tenant_host.sh" install')" "1"
chk "and refuses the host when it fails" "$(printf '%s\n' "$WALLS_FN" | grep -c 'return 1')" "1"
chk "--multi-tenant is an option of install.sh docker" "$(printf '%s\n' "$DOCKER_FN" | grep -c -- '--multi-tenant) MULTI_TENANT=1 ;;')" "1"

echo "=== Nothing writes container ids into a volume from the host ==="
code() { grep -v '^[[:space:]]*#' "$1"; }
chk "rebase: no host-side chown of a volume" "$(code "$REBASE" | grep -c 'chown "[^"]*" "$(vol_mp')" "0"
chk "rebase: rollback hands the log directory back inside the old image, by name" \
    "$(code "$REBASE" | grep -c 'docker run --rm -v "${LOG_VOL}:/pglog" --entrypoint bash "$KEEP_IMAGE"')" "1"
# Run the real script against the stub: on a host that does not remap (the
# only hosts it is for) it must get past the check under its own set -e; the
# stub then reports no container status, so prepare stops at that.
migrate() { bash "$MIGRATE" oldsite prepare 2>&1; }
echo "name=seccomp,profile=builtin " > "$T/secopts"
out="$(migrate)"
chk "migrate runs on a host that does not remap (past the check, into prepare)" \
    "$(echo "$out" | grep -c 'Preparing oldsite')|$(echo "$out" | grep -c 'oldsite is not running')" "1|1"
echo "name=seccomp,profile=builtin name=userns " > "$T/secopts"
out="$(migrate)"; rc=$?
chk "migrate refuses on a remapping host, saying why, before prepare" \
    "$rc|$(echo "$out" | grep -c 'remaps user ids (userns-remap)')|$(echo "$out" | grep -c 'Preparing')" "1|1|0"
rm -f "$T/secopts"
out="$(migrate)"; rc=$?
chk "migrate refuses when Docker does not say" "$rc|$(echo "$out" | grep -c 'did not say whether it remaps')" "1|1"
remap_at="$(code "$MIGRATE" | grep -n 'run_spec_docker_remaps_ids' | head -1 | cut -d: -f1)"
cp_at="$(code "$MIGRATE" | grep -n 'docker cp -a' | head -1 | cut -d: -f1)"
prep_at="$(code "$MIGRATE" | grep -n 'if \[ "$STAGE" = "prepare" \]' | head -1 | cut -d: -f1)"
chk "migrate refuses on a remapping host before prepare or any copy" \
    "$([ -n "$remap_at" ] && [ "$remap_at" -lt "$prep_at" ] && [ "$remap_at" -lt "$cp_at" ] && echo yes)" "yes"
chk "and when Docker does not say" "$(code "$MIGRATE" | grep -c 'Docker did not say whether it remaps user ids')" "1"

echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
