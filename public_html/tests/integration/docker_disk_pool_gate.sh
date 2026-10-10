#!/bin/bash
# @joinery-test
# name: docker_disk_pool
# tier: safe
# env: any
# needs: []
# timeout: 60
# covers: [maintenance_scripts/install_tools/docker_disk_pool.sh, maintenance_scripts/install_tools/install.sh, maintenance_scripts/install_tools/_site_run_spec.sh]
#
# A Docker host's disk pool and each site's allowance in it
# (specs/multi_tenant_docker_hosts.md WP4), against a scratch root with the
# system's commands stubbed: what docker_disk_pool.sh writes and asks for, and
# what it refuses. The pool is the host's data root (specs/one_data_root.md
# WP1); joinery_data_root.sh's own gate pins how that is made and grown.
#
#   - create: refused with Docker on the host and for a size that is not one.
#     On a host with no data root it makes one of the size given (refused when
#     the root disk cannot give it above its reserve); on a host whose data
#     root is mounted it uses that one; on a host whose data root is declared
#     and not mounted it refuses. Either way Docker's data-root is set to
#     /srv/joinery/docker in daemon.json, every other key kept, and Docker and
#     containerd wait for the data root.
#   - allow: refused with no pool. Otherwise every volume but backups and
#     deploy gets one project, numbered from 1,000,000,000, whose hard limit is
#     the allowance plus 10%; backups and deploy each get one of their own, with
#     no limit; the allowance is written where the site reads it. Run again, the
#     same numbers; another site, new ones.
#   - release: the site's projects leave /etc/projid and /etc/projects, and
#     another site's stay.
#   - install.sh: --disk-pool needs --multi-tenant, makes the pool before
#     Docker's package, and is refused on a host Docker is on without one;
#     site --disk is refused without a pool, recorded as disk=, and caps the
#     writable layer.
#
# The real XFS behaviour (a project's limit as df's size, writes stopping at
# it, direct I/O on the loop device) was proven on a scratch Linode,
# 2026-10-07; see the spec.

set -u
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$HERE/../../.." && pwd)"
TOOLS="$ROOT_DIR/maintenance_scripts/install_tools"
SCRIPT="$TOOLS/docker_disk_pool.sh"
INSTALL="$TOOLS/install.sh"

passed=0; failed=0
chk() {
    if [ "$2" = "$3" ]; then echo "  PASS: $1"; passed=$((passed + 1))
    else echo "  FAIL: $1 (got '$2', want '$3')"; failed=$((failed + 1)); fi
}

# Unprivileged only: as root the scripts ignore the fixture and the stubs would
# report a real /etc/fstab and real units rewritten as success.
if [ "$(id -u)" = "0" ]; then echo "  SKIP: this gate runs unprivileged"; echo "RESULT: PASS 0 0"; exit 0; fi

T="$(mktemp -d)"
trap 'rm -rf "$T"' EXIT
mkdir -p "$T/bin" "$T/dockerbin" "$T/root/etc" "$T/root/srv"
LOG="$T/log"; : > "$LOG"
export GATE_LOG="$LOG" GATE_MOUNTED="$T/mounted" GATE_T="$T"

stub() { printf '#!/bin/bash\n%s\n' "$2" > "$T/bin/$1"; chmod 755 "$T/bin/$1"; }
stub fallocate 'echo "fallocate $*" >> "$GATE_LOG"; touch "${@: -1}"'
stub mkfs.xfs 'echo "mkfs.xfs $*" >> "$GATE_LOG"'
stub mount 'echo "mount $*" >> "$GATE_LOG"; touch "$GATE_MOUNTED"'
stub systemctl 'echo "systemctl $*" >> "$GATE_LOG"'
stub apt-get 'echo "apt-get $*" >> "$GATE_LOG"'
stub findmnt '[ -f "$GATE_MOUNTED" ] || exit 1; case "$*" in *FSTYPE,OPTIONS*) echo "xfs rw,relatime,inode64,prjquota" ;; *) echo /srv/joinery ;; esac'
stub xfs_quota 'echo "xfs_quota $*" >> "$GATE_LOG"; case "$*" in *report*) cat "$GATE_T/report" 2>/dev/null ;; esac'
# Docker exists only for allow: create refuses a host it is on.
printf '#!/bin/bash\n[ "$1 $2" = "volume inspect" ] && echo "/srv/joinery/docker/100000.100000/volumes/${@: -1}/_data"\n' > "$T/dockerbin/docker"
chmod 755 "$T/dockerbin/docker"

run() { PATH="$T/bin:$PATH" JOINERY_POOL_ROOT="$T/root" JOINERY_SITE_STATE_ROOT="$T/root" bash "$SCRIPT" "$@"; }
run_docker() { PATH="$T/dockerbin:$T/bin:$PATH" JOINERY_POOL_ROOT="$T/root" JOINERY_SITE_STATE_ROOT="$T/root" bash "$SCRIPT" "$@"; }
DJ="$T/root/etc/docker/daemon.json"
data_root_of() { python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("data-root",""))' "$DJ" 2>/dev/null; }

echo "=== create ==="
out="$(PATH="$T/dockerbin:$T/bin:$PATH" JOINERY_POOL_ROOT="$T/root" bash "$SCRIPT" create 1G 2>&1)"; rc=$?
chk "refused with Docker on the host" "$rc|$(grep -c 'already installed' <<< "$out")" "1|1"
out="$(run create 68GB 2>&1)"; rc=$?
chk "refused: a size that is not one" "$rc|$(grep -c 'not .68GB.' <<< "$out")" "1|1"
out="$(run create 999T 2>&1)"; rc=$?
chk "refused: a size the root disk cannot give above its reserve" "$rc|$(grep -c 'under its reserve' <<< "$out")" "1|1"
chk "and nothing was allocated or written" \
    "$(wc -l < "$LOG" | tr -d ' ')|$( [ -e "$T/root/etc/fstab" ] && echo fstab || echo none)|$( [ -e "$DJ" ] && echo dj || echo none)" "0|none|none"
mkdir -p "$(dirname "$DJ")"; printf '{"userns-remap": "default"}\n' > "$DJ"
out="$(run create 1G 2>&1)"; rc=$?
chk "create on a host with no data root: exit 0" "$rc" "0"
chk "the data root is made at the size given: a 1G file, formatted with ftype=1" \
    "$(grep -c "^fallocate -l 1073741824 $T/root/srv/joinery.img$" "$LOG")|$(grep -c "^mkfs.xfs -q -n ftype=1 $T/root/srv/joinery.img$" "$LOG")" "1|1"
chk "fstab: one line, at /srv/joinery, loop, prjquota, nofail" \
    "$(grep -c '^/srv/joinery.img /srv/joinery xfs loop,prjquota,nofail 0 0$' "$T/root/etc/fstab")" "1"
chk "Docker and containerd each require the data root" \
    "$(cat "$T/root/etc/systemd/system/docker.service.d/joinery-data-root.conf" "$T/root/etc/systemd/system/containerd.service.d/joinery-data-root.conf" | grep -c '^Requires=joinery-data.target$')" "2"
chk "Docker's data-root is /srv/joinery/docker, and userns-remap is kept" \
    "$(data_root_of)|$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["userns-remap"])' "$DJ")" "/srv/joinery/docker|default"
chk "the directory is made, 710" "$(stat -c %a "$T/root/srv/joinery/docker")" "710"
: > "$LOG"
out="$(run create 1G 2>&1)"; rc=$?
chk "run again with the pool in place: nothing more is done" "$rc|$(grep -c '^fallocate' "$LOG")|$(grep -c 'joinery.img' "$T/root/etc/fstab")|$(grep -c 'is in place' <<< "$out")" "0|0|1|1"
chk "check: 0 with the pool in place" "$(run check; echo $?)" "0"
python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); d.pop("data-root"); json.dump(d, open(sys.argv[1], "w"))' "$DJ"
chk "check: 1 when Docker's data-root is elsewhere" "$(run check; echo $?)" "1"
printf '{"userns-remap": "default", "data-root": "/srv/joinery/docker/"}\n' > "$DJ"
chk "check: 0 for the same place written with a trailing slash (reviewer2 F7)" "$(run check; echo $?)" "0"
python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); d.pop("data-root"); json.dump(d, open(sys.argv[1], "w"))' "$DJ"
out="$(run create 8G 2>&1)"; rc=$?
chk "create on a host whose data root is mounted: uses it, allocates nothing, sets data-root, says 8G was not used" \
    "$rc|$(grep -c '^fallocate' "$LOG")|$(data_root_of)|$(grep -c 'The size 8G was not used' <<< "$out")" "0|0|/srv/joinery/docker|1"
rm -f "$GATE_MOUNTED"
python3 -c 'import json,sys; d=json.load(open(sys.argv[1])); d.pop("data-root"); json.dump(d, open(sys.argv[1], "w"))' "$DJ"
out="$(run create 1G 2>&1)"; rc=$?
chk "create on a host whose data root is declared and not mounted: refused, nothing changed" \
    "$rc|$(grep -c 'is not mounted' <<< "$out")|$(data_root_of)" "1|1|"
run create >/dev/null 2>&1 || true
touch "$GATE_MOUNTED"; run create >/dev/null 2>&1

echo "=== allow ==="
mkdir -p "$T/root/etc/joinery/sites/sitea" "$T/root/etc/joinery/sites/siteb"
for s in sitea siteb; do
    for v in code uploads postgres config backups deploy; do printf 'volume=%s_%s:/var/www/html/%s/%s\n' "$s" "$v" "$s" "$v"; done > "$T/root/etc/joinery/sites/$s/run_spec"
done
rm -f "$GATE_MOUNTED"
out="$(run_docker allow sitea 4G 2>&1)"; rc=$?
chk "refused with no pool" "$rc|$(grep -c 'no disk pool' <<< "$out")" "1|1"
touch "$GATE_MOUNTED"; : > "$LOG"
# The config volume, where the site reads its allowance, is a real directory here.
V="$T/root/srv/joinery/docker/100000.100000/volumes"
mkdir -p "$V/sitea_config/_data"
cat > "$T/dockerbin/docker" <<STUB
#!/bin/bash
[ "\$1 \$2" = "volume inspect" ] || exit 0
case "\${@: -1}" in *_config) echo "$V/\${@: -1}/_data" ;; *) echo "/srv/joinery/docker/100000.100000/volumes/\${@: -1}/_data" ;; esac
STUB
out="$(run_docker allow sitea 4G 2>&1)"; rc=$?
chk "allow: exit 0" "$rc" "0"
A_ID="$(awk -F: '$1 == "joinery_sitea" { print $2 }' "$T/root/etc/projid")"
chk "the site's project is numbered from 1,000,000,000" "$( [ "${A_ID:-0}" -gt 1000000000 ] && echo yes)" "yes"
chk "every volume but backups and deploy is in it" \
    "$(grep "^${A_ID}:" "$T/root/etc/projects" | sed 's#.*/volumes/##; s#/_data##' | sort | paste -sd,)" "sitea_code,sitea_config,sitea_postgres,sitea_uploads"
chk "each is marked with project -s" "$(grep -c "xfs_quota -x -c project -s -p .* ${A_ID} " "$LOG")" "4"
chk "backups and deploy each have a project of their own, with no limit" \
    "$(awk -F: '$1 ~ /^joinery_sitea_(backups|deploy)$/' "$T/root/etc/projid" | wc -l | tr -d ' ')|$(grep -c 'limit -p' "$LOG")" "2|1"
chk "the hard limit is the allowance plus 10%" "$(grep -c "limit -p bhard=$(( 4 * 1073741824 * 11 / 10 )) ${A_ID} " "$LOG")" "1"
chk "the allowance is written where the site reads it, and on the host" \
    "$(cat "$V/sitea_config/_data/disk_allowance")|$(cat "$T/root/etc/joinery/sites/sitea/disk_allowance")|$(run show sitea)" "4294967296|4294967296|4294967296"
run_docker allow sitea 6G > /dev/null 2>&1
chk "run again: the same project, a new allowance" \
    "$(awk -F: '$1 == "joinery_sitea"' "$T/root/etc/projid" | wc -l | tr -d ' ')|$(cat "$V/sitea_config/_data/disk_allowance")" "1|6442450944"
run_docker allow siteb 4G > /dev/null 2>&1
B_ID="$(awk -F: '$1 == "joinery_siteb" { print $2 }' "$T/root/etc/projid")"
chk "another site: a project of its own" "$( [ -n "$B_ID" ] && [ "$B_ID" != "$A_ID" ] && echo yes)" "yes"
chk "ids never repeat" "$(cut -d: -f2 "$T/root/etc/projid" | sort | uniq -d | wc -l | tr -d ' ')" "0"
# Every site's hard limit together must fit the pool (reviewer2 B2).
pool_kib=$(( $(df -B1 --output=size "$T/root/srv/joinery" | tail -n 1 | tr -d ' ') / 1024 ))
printf '#%s 0 0 %s 00 [--------]\n' "$B_ID" "$(( pool_kib - 1024 ))" > "$T/report"
out="$(run_docker allow sitea 1G 2>&1)"; rc=$?
chk "refused: an allowance that, with the others' limits, would promise more than the pool holds" \
    "$rc|$(grep -c 'more than the pool holds' <<< "$out")|$(cat "$V/sitea_config/_data/disk_allowance")" "1|1|6442450944"
printf '#%s 0 0 1048576 00 [--------]\n#%s 0 0 99999999999 00 [--------]\n' "$B_ID" "$A_ID" > "$T/report"
out="$(run_docker allow sitea 1G 2>&1)"; rc=$?
chk "its own former limit is not counted against it" "$rc" "0"
rm -f "$T/report"
out="$(run_docker allow 'a;b' 4G 2>&1)"; rc=$?
chk "a name that is not a site's is refused" "$rc" "1"
out="$(run_docker allow sitea 4.5G 2>&1)"; rc=$?
chk "a size that is not one is refused" "$rc" "1"

echo "=== release ==="
run_docker release sitea
chk "the site's projects leave both files; the other site's stay" \
    "$(grep -c 'joinery_sitea' "$T/root/etc/projid")|$(grep -c "^${A_ID}:" "$T/root/etc/projects")|$(grep -c "^joinery_siteb:" "$T/root/etc/projid")|$(grep -c "^${B_ID}:" "$T/root/etc/projects")" "0|0|1|4"
chk "and its allowance goes, from the host and from the site's config volume (reviewer2 B1)" \
    "$( [ -e "$T/root/etc/joinery/sites/sitea/disk_allowance" ] && echo kept || echo gone)|$( [ -e "$V/sitea_config/_data/disk_allowance" ] && echo kept || echo gone)" "gone|gone"

echo "=== install.sh ==="
chk "--disk-pool needs --multi-tenant" "$(grep -c 'disk-pool needs --multi-tenant' "$INSTALL")" "1"
# The pool is made after the remap is set and before Docker's package installs.
order="$(grep -n -e 'docker_daemon_json_set_userns_remap || exit 1' -e 'docker_disk_pool.sh" create' -e 'apt-get install -y docker-ce' "$INSTALL" | cut -d: -f1 | paste -sd' ')"
read -r o1 o2 o3 <<< "$order"
chk "the pool is made before Docker's package starts the daemon" "$( [ "$o2" -gt "$o1" ] && [ "$o3" -gt "$o2" ] && echo yes)" "yes"
chk "a host Docker is already on, with no pool, is refused" "$(grep -c 'disk-pool must be made before Docker is installed' "$INSTALL")" "1"
chk "site --disk is refused without a pool" "$(grep -c 'it has no disk pool' "$INSTALL")" "1"
chk "the allowance is set once the volumes exist, after the container runs" \
    "$(awk '/docker run -d "\$\{RUN_ARGS\[@\]\}"/ { r = NR } /docker_disk_pool.sh" allow/ { a = NR } END { print (r && a > r) ? "yes" : "no" }' "$INSTALL")" "yes"
. "$TOOLS/_site_run_spec.sh"
chk "the run spec takes disk= and checks it" "$(run_spec_check_line disk=4G && echo ok)|$(run_spec_check_line disk=4.5G || echo refused)" "ok|refused"
mkdir -p "$T/rs/etc/joinery/sites/capped"
printf 'spec_version=2\nhostname=capped\nrestart=unless-stopped\ndisk=4G\nvolume=capped_code:/var/www/html/capped/public_html\n' > "$T/rs/etc/joinery/sites/capped/run_spec"
args="$(JOINERY_SITE_STATE_ROOT="$T/rs" bash -c '. "$1"; run_spec_fits_host() { return 0; }; run_spec_ensure_network() { return 0; }; run_spec_args capped | tr "\0" " "' _ "$TOOLS/_site_run_spec.sh")"
chk "a site with an allowance runs with its writable layer capped at 1G" "$(grep -c -- '--storage-opt size=1G' <<< "$args")" "1"

echo
echo "RESULT: ${passed} passed, ${failed} failed"
[ "$failed" -eq 0 ]
