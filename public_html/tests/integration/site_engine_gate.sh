#!/bin/bash
# @joinery-test
# name: site_engine
# tier: safe
# env: any
# needs: []
# timeout: 120
# covers: [maintenance_scripts/install_tools/install_site_engine.sh, maintenance_scripts/install_tools/site_engine/build.sh, maintenance_scripts/install_tools/site_engine/versions.env]
#
# The site container engine's host installer (specs/site_container_engine.md
# D3), run unprivileged against a fixture root (JOINERY_SITE_ENGINE_ROOT) with
# stand-in engine archives whose podman is a script that prints a version:
#
#   - an archive is refused, nothing written, when its hash is not the one given,
#     its name is not an engine's, it unpacks to another name, a part is missing,
#     or it was built on another Ubuntu release than the machine's (B30)
#   - installed: the version's directory, current naming it, podman COPIED to
#     /usr/local/bin (podman records its resolved path in every container), the
#     containers.conf drop-in (runc, the helpers, netavark), the apt pin keeping
#     Ubuntu's engine out, the boot unit pointing at /usr/local/bin/podman
#   - run again, it changes nothing; a newer engine keeps the previous one and
#     removes the one before; the previous archive again rolls back
#   - an engine that fails its check is refused and current goes back
#   - converge: a tree naming no engine does nothing; one naming an engine on the
#     release server (a file:// fixture) fetches and installs it, then reports it
#     current; a fetched archive that is not the named hash is refused; the same
#     Podman version rebuilt (another hash) is a new engine and becomes current
#   - build.sh: pins and checks every commit, checks the built tags from the
#     binary, and versions.env names commits as 40 hex digits

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
INST="$ROOT/maintenance_scripts/install_tools/install_site_engine.sh"
BUILD="$ROOT/maintenance_scripts/install_tools/site_engine/build.sh"
VERS="$ROOT/maintenance_scripts/install_tools/site_engine/versions.env"
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

FR="$T/root"
LIB="$FR/usr/local/lib/joinery-site-engine"
# The machine's release: a file outside the fixture root, so 'nothing written'
# still means nothing.
export JOINERY_SITE_ENGINE_OS_RELEASE="$T/os-release"
host_is() {  # VERSION_ID PRETTY_NAME
    printf 'NAME="Ubuntu"\nID=ubuntu\nVERSION_ID="%s"\nPRETTY_NAME="%s"\n' "$1" "$2" > "$T/os-release"
}
host_is 26.04 "Ubuntu 26.04.1 LTS"
run() { JOINERY_SITE_ENGINE_ROOT="$FR" bash "$INST" "$@" > "$T/out" 2>&1; echo $?; }

# make_archive VERSION [REPORTED_VERSION] [TOP_NAME] [SKIP_FILE] [BUILD_NOTE] [BUILT_ON, - for none] -> path of the archive
make_archive() {
    local v="$1" reported="${2:-$1}" top="${3:-joinery-site-engine-$1-amd64}" skip="${4:-}" note="${5:-}" built="${6:-Ubuntu 26.04.1 LTS}" d
    d="$T/build-$v-$RANDOM"
    mkdir -p "$d/$top/bin" "$d/$top/helpers" "$d/$top/systemd"
    printf '#!/bin/sh\necho "podman version %s"\n' "$reported" > "$d/$top/bin/podman"
    printf '#!/bin/sh\necho netavark\n' > "$d/$top/helpers/netavark"
    printf '#!/bin/sh\necho aardvark\n' > "$d/$top/helpers/aardvark-dns"
    chmod 755 "$d/$top/bin/podman" "$d/$top/helpers/"*
    printf '[Service]\nExecStart=/usr/local/bin/podman start --all --filter should-start-on-boot=true\n' > "$d/$top/systemd/podman-restart.service"
    printf 'engine=%s\npodman=v%s\n' "joinery-site-engine-$v-amd64" "$v" > "$d/$top/BUILDINFO"
    [ -n "$note" ] && echo "note=$note" >> "$d/$top/BUILDINFO"
    [ "$built" != "-" ] && echo "built_on=$built" >> "$d/$top/BUILDINFO"
    [ -n "$skip" ] && rm -f "$d/$top/$skip"
    tar -C "$d" -cJf "$T/joinery-site-engine-$v-amd64.tar.xz" "$top"
    echo "$T/joinery-site-engine-$v-amd64.tar.xz"
}
sha() { sha256sum "$1" | awk '{print $1}'; }
slot() { echo "$1-$(sha "$2" | cut -c1-12)"; }   # VERSION ARCHIVE

echo "Refused archives change nothing"
A=$(make_archive 6.1.4)
chk "a hash that is not the archive's is refused" "$(run install "$A" "$(printf '0%.0s' $(seq 64))")" "1"
chk "  and says whose hash it is" "$(grep -c "not the release's" "$T/out")" "1"
chk "  and nothing is written" "$([ -e "$FR" ] && echo written || echo nothing)" "nothing"
chk "a hash that is not 64 hex digits is refused" "$(run install "$A" abc)" "1"
cp "$A" "$T/engine.tar.xz"
chk "an archive not named like an engine is refused" "$(run install "$T/engine.tar.xz" "$(sha "$A")")" "1"
B=$(make_archive 6.1.4 6.1.4 joinery-site-engine-6.1.9-amd64)
chk "an archive that unpacks to another engine's name is refused" "$(run install "$B" "$(sha "$B")")" "1"
chk "  and leaves no engine directory" "$(ls "$LIB" 2>/dev/null | grep -c -E '^6\.1\.4-')" "0"
C=$(make_archive 6.1.4 6.1.4 "" helpers/netavark)
chk "an archive missing a part is refused" "$(run install "$C" "$(sha "$C")")" "1"
chk "  naming the part" "$(grep -c 'has no helpers/netavark' "$T/out")" "1"
# The engine links against the libraries of the release it was built on: on
# 24.04 the 26.04 build's podman could not load libgpgme.so.45 and failed only
# after the switch (B30).
host_is 24.04 "Ubuntu 24.04.4 LTS"
A=$(make_archive 6.1.4)
chk "an engine built on 26.04, on a 24.04 machine, is refused" "$(run install "$A" "$(sha "$A")")" "1"
chk "  saying which release it needs and which this is" "$(grep -c 'built on Ubuntu 26.04 and links against that release.s libraries, and this machine is Ubuntu 24.04.4 LTS' "$T/out")" "1"
nothing_installed() { echo "$(ls "$LIB" 2>/dev/null | grep -c -E '^6\.1\.4-'):$([ -e "$LIB/current" ] && echo current || echo none):$([ -e "$FR/usr/local/bin/podman" ] && echo podman || echo none)"; }
chk "  and no engine is unpacked, made current or installed" "$(nothing_installed)" "0:none:none"
host_is 26.04 "Ubuntu 26.04.1 LTS"
N=$(make_archive 6.1.4 6.1.4 "" "" "" -)
chk "an engine whose BUILDINFO names no release it was built on is refused" "$(run install "$N" "$(sha "$N")"):$(grep -c 'names no Ubuntu release' "$T/out")" "1:1"
chk "  and no engine is unpacked, made current or installed" "$(nothing_installed)" "0:none:none"

echo "Installed"
A=$(make_archive 6.1.4)
chk "the engine installs" "$(run install "$A" "$(sha "$A")")" "0"
S4=$(slot 6.1.4 "$A")
chk "its directory is named by version and hash" "$([ -x "$LIB/$S4/bin/podman" ] && echo yes)" "yes"
chk "  and records the archive's hash" "$(cat "$LIB/$S4/ARCHIVE_SHA256")" "$(sha "$A")"
chk "current names it" "$(readlink "$LIB/current")" "$S4"
chk "podman is a copy, not a link" "$([ -L "$FR/usr/local/bin/podman" ] && echo link || ([ -f "$FR/usr/local/bin/podman" ] && echo file))" "file"
chk "  the current one, mode 755" "$(cmp -s "$FR/usr/local/bin/podman" "$LIB/$S4/bin/podman" && stat -c %a "$FR/usr/local/bin/podman")" "755"
CONF="$FR/etc/containers/containers.conf.d/50-joinery-site-engine.conf"
chk "the drop-in makes runc the runtime" "$(grep -c '^runtime = "runc"$' "$CONF")" "1"
chk "  and points at the current helpers" "$(grep -c '^helper_binaries_dir = \["/usr/local/lib/joinery-site-engine/current/helpers"\]$' "$CONF")" "1"
chk "  and netavark" "$(grep -c '^network_backend = "netavark"$' "$CONF")" "1"
PREF="$FR/etc/apt/preferences.d/joinery-site-engine"
chk "the apt pin names Ubuntu's engine and its configuration packages" "$(grep -c '^Package: podman podman-docker netavark aardvark-dns crun golang-github-containers-common golang-github-containers-image containers-storage containernetworking-plugins$' "$PREF")" "1"
chk "  and never installs them" "$(grep -c '^Pin-Priority: -1$' "$PREF")" "1"
chk "the boot unit starts podman from its one path" "$(grep -c 'ExecStart=/usr/local/bin/podman ' "$FR/etc/systemd/system/podman-restart.service")" "1"
chk "the image policy is written" "$(grep -c 'insecureAcceptAnything' "$FR/etc/containers/policy.json")" "2"
chk "short image names are looked for on docker.io alone" "$(grep -c '^unqualified-search-registries = \["docker.io"\]$' "$FR/etc/containers/registries.conf")" "1"
chk "the site filter is the tree's generated one, byte for byte" "$(cmp -s "$FR/etc/containers/joinery-site-seccomp.json" "$ROOT/maintenance_scripts/install_tools/site_engine/joinery_site_seccomp.json" && echo same)" "same"
# specs/multi_tenant_host_hardening.md D2: the filter is Docker's default with
# our narrowing, generated, never edited; it differs from Docker's nowhere else.
SE="$ROOT/maintenance_scripts/install_tools/site_engine"
chk "  and it is what make_site_profile.php makes from Docker's default" "$(php "$SE/make_site_profile.php" --check 2>&1)" "joinery_site_seccomp.json is current"
chk "  differing from Docker's only by D2's changes" "$(python3 -I - "$SE/site_seccomp.json" "$SE/joinery_site_seccomp.json" <<'PY'
import json, sys
d = json.load(open(sys.argv[1])); g = json.load(open(sys.argv[2]))
refused = {'ptrace','process_vm_readv','process_vm_writev','kcmp','userfaultfd','io_uring_setup','io_uring_enter',
    'io_uring_register','bpf','perf_event_open','keyctl','add_key','request_key','unshare','setns','mount','umount2',
    'pivot_root','open_by_handle_at','init_module','finit_module','kexec_load','kexec_file_load','socketcall'}
bad = []
# The arch map: Docker's, with 32-bit x86 out of x86_64's subarchitectures and nothing else.
want_map = [dict(e, subArchitectures=[a for a in e.get('subArchitectures', []) if a != 'SCMP_ARCH_X86'])
            if e.get('architecture') == 'SCMP_ARCH_X86_64' else e for e in d.get('archMap', [])]
if g.get('archMap') != want_map:
    bad.append('archMap')
for k in d:
    if k not in ('syscalls', 'archMap') and d[k] != g.get(k):
        bad.append('top-level ' + k)
want = []
for r in d['syscalls']:
    if r['names'] == ['socket']:
        continue
    if r['names'] == ['personality'] and r.get('args') not in ([{'index': 0, 'value': 0, 'op': 'SCMP_CMP_EQ'}], [{'index': 0, 'value': 4294967295, 'op': 'SCMP_CMP_EQ'}]):
        continue
    kept = [n for n in r['names'] if n not in refused]
    if kept:
        want.append(dict(r, names=kept))
got = [r for r in g['syscalls'] if r['names'] != ['socket']]
if got != want:
    bad.append('a rule other than socket differs from Docker\'s with the refusals taken out')
sock = [r for r in g['syscalls'] if r['names'] == ['socket']]
key = lambda r: tuple((a['index'], a['value'], a['op']) for a in r.get('args', []))
allow = sorted(key(r) for r in sock if r['action'] == 'SCMP_ACT_ALLOW')
if allow != sorted([((0, 1, 'SCMP_CMP_EQ'),), ((0, 2, 'SCMP_CMP_EQ'),), ((0, 10, 'SCMP_CMP_EQ'),), ((0, 16, 'SCMP_CMP_EQ'), (2, 0, 'SCMP_CMP_EQ'))]):
    bad.append('socket allows')
errs = [r for r in sock if r['action'] != 'SCMP_ACT_ALLOW']
if any(r['action'] != 'SCMP_ACT_ERRNO' for r in errs):
    bad.append('a socket rule neither allows nor answers an errno')
# Every refused netlink protocol answers EPROTONOSUPPORT as a (16, N) pair, every
# other refused domain EAFNOSUPPORT on the domain alone; no refusal names a bare 16.
for r in errs:
    a = r.get('args', [])
    if a and a[0]['value'] == 16 and a[0]['op'] == 'SCMP_CMP_EQ':
        if len(a) != 2 or r.get('errnoRet') != 93:
            bad.append('a netlink refusal')
    elif len(a) != 1 or r.get('errnoRet') != 97:
        bad.append('a domain refusal')
domains = {r['args'][0]['value'] for r in errs if len(r['args']) == 1 and r['args'][0]['op'] == 'SCMP_CMP_EQ'}
if domains != set(range(64)) - {1, 2, 10, 16}:
    bad.append('the refused domains are not every one below 64 but the four')
for r in g['syscalls']:
    if r['action'] == 'SCMP_ACT_ALLOW' and refused & set(r['names']):
        bad.append('allows ' + ','.join(sorted(refused & set(r['names']))))
print('; '.join(sorted(set(bad))) or 'only D2')
PY
)" "only D2"
chk "  Docker's default it is made from is the file its source record names" "$(sha256sum "$ROOT/maintenance_scripts/install_tools/site_engine/site_seccomp.json" | cut -c1-64)" "$(sed -n 's/^SHA256="\(.*\)"$/\1/p' "$ROOT/maintenance_scripts/install_tools/site_engine/site_seccomp.source")"
chk "  which refuses user namespaces to every process, with or without CAP_SYS_ADMIN" "$(python3 -I -c "import json,sys; d=json.load(open(sys.argv[1])); print(d['defaultAction'], any('unshare' in r['names'] for r in d['syscalls'] if r['action']=='SCMP_ACT_ALLOW'))" "$ROOT/maintenance_scripts/install_tools/site_engine/joinery_site_seccomp.json")" "SCMP_ACT_ERRNO False"
chk "no data root in the fixture: storage is left to the engine's default" "$([ -f "$FR/etc/containers/storage.conf" ] && echo written || echo left)" "left"
chk "status prints the engine" "$(JOINERY_SITE_ENGINE_ROOT="$FR" bash "$INST" status | head -n1)" "engine=joinery-site-engine-6.1.4-amd64"

echo "A profile edited by hand is refused (reviewer2 K2)"
# A host has no PHP to make the profile again, so it checks the record
# make_site_profile.php writes: the profile's hash and the Docker file's.
HT="$T/handtree"; mkdir -p "$HT"; cp -r "$ROOT/maintenance_scripts/install_tools" "$HT/"
python3 -I - "$HT/install_tools/site_engine/joinery_site_seccomp.json" <<'PY'
import json, sys
p = sys.argv[1]; g = json.load(open(p)); g['syscalls'][0]['names'].append('ptrace'); json.dump(g, open(p, 'w'), indent=4)
PY
HR="$T/handroot"
chk "the install stops, naming the record, and writes no filter" \
    "$(JOINERY_SITE_ENGINE_ROOT="$HR" bash "$HT/install_tools/install_site_engine.sh" install "$A" "$(sha "$A")" > "$T/out" 2>&1; echo $?):$(grep -c 'is not the profile joinery_site_seccomp.source records' "$T/out"):$([ -f "$HR/etc/containers/joinery-site-seccomp.json" ] && echo written || echo none)" "1:1:none"
rm -rf "$HT" "$HR"

echo "Converge keeps the host's configuration"
mkdir -p "$FR/srv/joinery"
rm -f "$FR/etc/containers/joinery-site-seccomp.json"
echo "# an edit" > "$CONF"
STAMPF0="$T/ENGINE0"; printf 'archive=%s\nsha256=%s\n' "$(basename "$A")" "$(sha "$A")" > "$STAMPF0"
JOINERY_SITE_ENGINE_ROOT="$FR" JOINERY_SITE_ENGINE_STAMP="$STAMPF0" bash "$INST" converge "file:///nowhere" > "$T/out" 2>&1
chk "an engine already current: converge writes back a missing site filter" "$(grep -c 'is current' "$T/out") $([ -f "$FR/etc/containers/joinery-site-seccomp.json" ] && echo back)" "1 back"
chk "  and writes its containers.conf drop-in back, the host's hook folder in it" "$(grep -c '^hooks_dir = \["/usr/share/containers/oci/hooks.d", "/etc/containers/oci/hooks.d"\]$' "$CONF")|$(grep -c 'an edit' "$CONF")" "1|0"
chk "  and puts container storage on the data root once there is one" "$(grep -c '^graphroot = "/srv/joinery/containers/storage"$' "$FR/etc/containers/storage.conf")" "1"

echo "Run again, newer, rollback"
chk "the same archive again" "$(run install "$A" "$(sha "$A")")" "0"
chk "  changes nothing" "$(grep -c -E 'unpacked|wrote|current engine' "$T/out")" "0"
A5=$(make_archive 6.1.5)
chk "a newer engine installs" "$(run install "$A5" "$(sha "$A5")")" "0"
S5=$(slot 6.1.5 "$A5")
chk "  current names it" "$(readlink "$LIB/current")" "$S5"
chk "  podman is the newer one" "$("$FR/usr/local/bin/podman" --version)" "podman version 6.1.5"
chk "  the previous is kept" "$([ -d "$LIB/$S4" ] && echo kept)" "kept"
A6=$(make_archive 6.1.6)
run install "$A6" "$(sha "$A6")" > /dev/null
chk "one newer again removes the one before the previous" "$([ -d "$LIB/$S4" ] && echo kept || echo removed) $([ -d "$LIB/$S5" ] && echo kept)" "removed kept"
chk "the previous archive again rolls back" "$(run install "$A5" "$(sha "$A5")") $(readlink "$LIB/current") $("$FR/usr/local/bin/podman" --version | awk '{print $3}')" "0 $S5 6.1.5"

echo "An engine that fails its check"
A7=$(make_archive 6.1.7 9.9.9)
chk "is refused" "$(run install "$A7" "$(sha "$A7")")" "1"
chk "  saying why" "$(grep -c "reports version '9.9.9', not 6.1.7" "$T/out")" "1"
chk "  and current goes back" "$(readlink "$LIB/current") $("$FR/usr/local/bin/podman" --version | awk '{print $3}')" "$S5 6.1.5"

echo "Converge from the tree's record"
SRV="$T/server/static_files/site_engine"; mkdir -p "$SRV"
STAMPF="$T/ENGINE"
conv() { JOINERY_SITE_ENGINE_ROOT="$FR" JOINERY_SITE_ENGINE_STAMP="$STAMPF" bash "$INST" converge "file://$T/server" > "$T/out" 2>&1; echo $?; }
stamp_for() { printf 'source_sha256=%s\narchive=%s\nsha256=%s\n' "$(printf '0%.0s' $(seq 64))" "$(basename "$1")" "$(sha "$1")" > "$STAMPF"; }
chk "a tree naming no engine: nothing to do" "$(conv) $(grep -c 'names no engine' "$T/out")" "0 1"
A8=$(make_archive 6.1.8); cp "$A8" "$SRV/"; stamp_for "$A8"
chk "a tree naming an engine on the server: fetched and installed" "$(conv) $(readlink "$LIB/current")" "0 $(slot 6.1.8 "$A8")"
chk "  converge again: current, nothing fetched" "$(conv) $(grep -c 'is current' "$T/out") $(grep -c fetching "$T/out")" "0 1 0"
chk "  no download is left behind" "$(ls "$FR/var/tmp" | grep -c site-engine-fetch)" "0"
A9=$(make_archive 6.1.9); cp "$A9" "$SRV/"; stamp_for "$A9"
cp "$A8" "$SRV/$(basename "$A9")"
chk "a fetched archive that is not the named hash is refused" "$(conv) $(readlink "$LIB/current")" "1 $(slot 6.1.8 "$A8")"
chk "  and its download is removed" "$(ls "$FR/var/tmp" | grep -c site-engine-fetch)" "0"
FIRST8="$(slot 6.1.8 "$SRV/$(basename "$A9")")"
A8b=$(make_archive 6.1.8 6.1.8 "" "" "rebuilt with a newer Go"); cp "$A8b" "$SRV/"; stamp_for "$A8b"
chk "the same version rebuilt is another engine" "$([ "$FIRST8" != "$(slot 6.1.8 "$A8b")" ] && echo differs)" "differs"
chk "  and converge makes it current" "$(conv) $(readlink "$LIB/current")" "0 $(slot 6.1.8 "$A8b")"

echo "Where converge fetches from when no server is named (B32, reviewer2 F1)"
# Host housekeeping names no server: the management node this host's agent
# joined is asked first, then UPGRADE_SERVER (getjoinery.com by default).
conv_auto() { JOINERY_SITE_ENGINE_ROOT="$FR" JOINERY_SITE_ENGINE_STAMP="$STAMPF" UPGRADE_SERVER="file://$T/server" bash "$INST" converge > "$T/out" 2>&1; echo $?; }
mkdir -p "$FR/etc/joinery-agent"
printf '{\n  "node_id": 7,\n  "plane_url": "https://127.0.0.1:9/",\n  "agent": "x"\n}\n' > "$FR/etc/joinery-agent/node_identity.json"
A10=$(make_archive 6.1.10); cp "$A10" "$SRV/"; stamp_for "$A10"
chk "a plane that does not serve the engine: the next server does, and it is installed" "$(conv_auto) $(readlink "$LIB/current")" "0 $(slot 6.1.10 "$A10")"
chk "  the plane was asked first, then the fallback" "$(grep -o 'fetching [^ ]* from [^ ]*' "$T/out" | awk '{print $4}' | paste -sd ' ')" "https://127.0.0.1:9 file://$T/server"
A11=$(make_archive 6.1.11); stamp_for "$A11"
chk "neither serving it: refused, naming both, and nothing changes" "$(conv_auto) $(grep -c "from https://127.0.0.1:9 or file://$T/server" "$T/out") $(readlink "$LIB/current")" "1 1 $(slot 6.1.10 "$A10")"
printf '{\n  "plane_url": "http://127.0.0.1:9",\n}\n' > "$FR/etc/joinery-agent/node_identity.json"
cp "$A11" "$SRV/"
chk "a plane address that is not https is never asked" "$(conv_auto) $(grep -c '127.0.0.1:9' "$T/out")" "0 0"
printf '{\n  "plane_url": "https://127.0.0.1:9"\n}\n' > "$FR/etc/joinery-agent/node_identity.json"
A12=$(make_archive 6.1.12); cp "$A12" "$SRV/"; stamp_for "$A12"
chk "a server named on the command line is the only one asked" "$(conv) $(grep -o 'fetching [^ ]* from [^ ]*' "$T/out" | awk '{print $4}' | paste -sd ' ') $(readlink "$LIB/current")" "0 file://$T/server $(slot 6.1.12 "$A12")"
rm -f "$FR/etc/joinery-agent/node_identity.json"

echo "Failures stop the run"
chk "converge runs the install as a plain statement, never as a condition (errexit would be off inside it)" "$(grep -c -E '^\s*( if !|.*\|\|).*\( install_engine' "$INST") $(grep -c -E '^    \( install_engine "\$dl/\$archive" "\$sha" \)$' "$INST")" "0 1"
chk "the package lists are fetched before runc and conmon are installed" "$(grep -c 'apt-get update -qq || die' "$INST")" "1"
chk "a failed apt step stops the run, saying which" "$(grep -c 'apt-get install -y -qq --no-install-recommends \$missing > /dev/null \\' "$INST") $(grep -c "could not install\${missing} from Ubuntu's archive" "$INST")" "1 1"

echo "The build"
chk "versions.env pins three commits, each 40 hex digits" "$(grep -c -E '^(PODMAN|NETAVARK|AARDVARK)_COMMIT="[0-9a-f]{40}"$' "$VERS")" "3"
chk "versions.env asks for seccomp and apparmor" "$(. "$VERS"; [[ " $PODMAN_BUILDTAGS " == *" seccomp "* && " $PODMAN_BUILDTAGS " == *" apparmor "* ]] && echo yes)" "yes"
chk "build.sh checks every checkout against its commit" "$(grep -c '^fetch [a-z-]* "\$[A-Z]*_REPO" "\$[A-Z]*_TAG" "\$[A-Z]*_COMMIT"$' "$BUILD")" "3"
chk "build.sh reads the tags back from the binary" "$(grep -c 'go version -m "\$PODMAN_BIN"' "$BUILD")" "1"
chk "build.sh refuses a binary from another commit" "$(grep -c 'not ${PODMAN_COMMIT}' "$BUILD")" "1"
chk "build.sh builds only on the hosts' release" "$(grep -c 'VERSION_CODENAME:-}" == "resolute"' "$BUILD")" "1"
# install.sh asks before it makes the data root (B30's early half); the release
# it names is the one build.sh builds on (resolute is 26.04).
chk "preflight names the release build.sh builds on" "$(grep -c '^ENGINE_UBUNTU="26.04"$' "$INST")" "1"
chk "preflight on the engine's release: passes" "$(run preflight)" "0"
host_is 24.04 "Ubuntu 24.04.4 LTS"
chk "preflight on 24.04: refused" "$(JOINERY_SITE_ENGINE_ROOT="$T/pre" bash "$INST" preflight > "$T/out" 2>&1; echo $?)" "1"
chk "  saying which release it needs and which this is" "$(grep -c 'engine cannot run here: it is built on Ubuntu 26.04 and links against that release.s libraries, and this machine is Ubuntu 24.04.4 LTS' "$T/out")" "1"
chk "  and nothing written" "$([ -e "$T/pre" ] && echo written || echo nothing)" "nothing"
chk "preflight on 24.04, in a tree that names no engine: nothing to refuse" "$(JOINERY_SITE_ENGINE_STAMP="$T/no-engine" run preflight)" "0"
host_is 26.04 "Ubuntu 26.04.1 LTS"

echo
echo "site_engine gate: $passed passed, $failed failed"
[ "$failed" -eq 0 ]
