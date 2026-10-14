#!/usr/bin/env bash
#
# build_root.sh - the Ubuntu 26.04 build root the publishing machine builds the
# site container engine in (specs/site_container_engine.md D1).
#
# Version: 1.0
#
# The engine links against the hosts' libraries, so it is built inside the
# hosts' release, not on the publishing machine's own (dev runs 24.04). The
# root is made once, by hand, by the owner:
#
#     sudo bash maintenance_scripts/install_tools/site_engine/build_root.sh create
#
# and the publish pipeline, which runs as root, then builds in it whenever
# versions.env changed.
#
#   build_root.sh create          Root. Installs debootstrap from this machine's
#                                 archive when missing; bootstraps a minimal
#                                 26.04 (resolute) at ROOT from Ubuntu's archive,
#                                 checked against Ubuntu's archive key; installs
#                                 the toolchain build.sh uses. Run again on a
#                                 root already made, it changes nothing.
#   build_root.sh check           Exit 0 when the root is there and is 26.04;
#                                 1, saying the create command, when it is not.
#   build_root.sh build OUT_DIR   Root. Brings the root's packages up to date
#                                 (the Go and Rust toolchains get security
#                                 fixes too), runs build.sh inside it with the
#                                 versions.env beside this script, and copies
#                                 the archive and its .sha256 to OUT_DIR. One
#                                 build at a time; the kernel mounts it makes for
#                                 the build are gone when it ends, however it ends.
#
# ROOT is /var/lib/joinery-site-engine-build/resolute: about 2 GB, plus about
# 4 GB under its /var/tmp while a build runs.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="${JOINERY_SITE_ENGINE_BUILD_BASE:-/var/lib/joinery-site-engine-build}"
ROOT="$BASE/resolute"
LOCK="$BASE/build.lock"
MIRROR="http://archive.ubuntu.com/ubuntu"
KEYRING="/usr/share/keyrings/ubuntu-archive-keyring.gpg"
# Every Ubuntu release's debootstrap script is a link to this one; naming it
# lets a 24.04 debootstrap make a release newer than itself.
DEBOOTSTRAP_SCRIPT="/usr/share/debootstrap/scripts/gutsy"
TOOLCHAIN="ca-certificates git make gcc pkg-config golang-go cargo rustc protobuf-compiler libgpgme-dev libseccomp-dev libsystemd-dev libapparmor-dev xz-utils"
CREATE_CMD="sudo bash ${HERE}/build_root.sh create"

die() { echo "site engine build root: $*" >&2; exit 1; }
say() { echo "site engine build root: $*"; }

need_root() { [[ "$(id -u)" -eq 0 ]] || die "run as root"; }

is_root_ready() {
    [[ -f "$ROOT/etc/os-release" ]] && grep -qx 'VERSION_CODENAME=resolute' "$ROOT/etc/os-release" \
        && [[ -f "$ROOT/.joinery-site-engine-build-root" ]]
}

MOUNTED=()
unmount_all() {
    local i
    for (( i=${#MOUNTED[@]}-1; i>=0; i-- )); do
        umount -l "${MOUNTED[$i]}" 2>/dev/null || true
    done
    MOUNTED=()
}
mount_for_run() {
    local m
    for m in proc sys dev dev/pts; do mkdir -p "$ROOT/$m"; done
    mount -t proc proc "$ROOT/proc" && MOUNTED+=("$ROOT/proc")
    mount --rbind /sys "$ROOT/sys" && mount --make-rslave "$ROOT/sys" && MOUNTED+=("$ROOT/sys")
    mount --rbind /dev "$ROOT/dev" && mount --make-rslave "$ROOT/dev" && MOUNTED+=("$ROOT/dev")
    cp -L /etc/resolv.conf "$ROOT/etc/resolv.conf"
}
in_root() { chroot "$ROOT" /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root LANG=C.UTF-8 DEBIAN_FRONTEND=noninteractive "$@"; }

create() {
    need_root
    if is_root_ready; then
        say "${ROOT} is already a 26.04 build root; nothing to do"
        return 0
    fi
    [[ -f "$KEYRING" ]] || die "no Ubuntu archive keyring at ${KEYRING}"
    if ! command -v debootstrap > /dev/null; then
        say "installing debootstrap"
        apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq debootstrap > /dev/null
    fi
    [[ -f "$DEBOOTSTRAP_SCRIPT" ]] || die "debootstrap has no Ubuntu script at ${DEBOOTSTRAP_SCRIPT}"
    mkdir -p "$BASE"
    chmod 700 "$BASE"
    [[ -e "$ROOT" ]] && die "${ROOT} exists but is not a finished build root; move it aside and run create again"
    say "bootstrapping Ubuntu 26.04 into ${ROOT} (a few minutes)"
    rm -rf "${ROOT:?}.partial"
    debootstrap --variant=minbase --components=main,universe --keyring="$KEYRING" \
        resolute "$ROOT.partial" "$MIRROR" "$DEBOOTSTRAP_SCRIPT" > "$BASE/create.log" 2>&1 \
        || die "debootstrap failed; see ${BASE}/create.log"
    printf 'deb %s resolute main universe\ndeb %s resolute-updates main universe\ndeb %s resolute-security main universe\n' \
        "$MIRROR" "$MIRROR" "$MIRROR" > "$ROOT.partial/etc/apt/sources.list"
    mv -T "$ROOT.partial" "$ROOT"
    trap unmount_all EXIT
    mount_for_run
    say "installing the toolchain"
    in_root apt-get update -qq
    in_root apt-get install -y -qq --no-install-recommends $TOOLCHAIN > /dev/null
    local tools
    tools="go $(in_root go env GOVERSION) (fetches the official one versions.env pins), rustc $(in_root rustc --version | awk '{print $2}')"
    unmount_all
    trap - EXIT
    date -u +%Y-%m-%dT%H:%M:%SZ > "$ROOT/.joinery-site-engine-build-root"
    say "ready: $(grep '^PRETTY_NAME=' "$ROOT/etc/os-release" | cut -d'"' -f2), ${tools}"
}

check() {
    if is_root_ready; then
        say "${ROOT} is ready"
        return 0
    fi
    echo "site engine build root: none at ${ROOT}; the owner makes it once with: ${CREATE_CMD}" >&2
    return 1
}

build() {  # OUT_DIR
    need_root
    local out="$1" built
    is_root_ready || die "no build root at ${ROOT}; the owner makes it once with: ${CREATE_CMD}"
    mkdir -p "$out"
    exec 9> "$LOCK"
    flock -n 9 || die "another engine build is running"
    trap unmount_all EXIT
    mount_for_run
    in_root apt-get update -qq
    in_root apt-get upgrade -y -qq > /dev/null
    rm -rf "$ROOT/root/site_engine" "$ROOT/root/engine-out"
    mkdir -p "$ROOT/root/site_engine"
    cp "$HERE/build.sh" "$HERE/versions.env" "$ROOT/root/site_engine/"
    in_root bash /root/site_engine/build.sh /root/engine-out
    unmount_all
    trap - EXIT
    built="$(ls "$ROOT/root/engine-out/"*.tar.xz 2>/dev/null | head -n 1)"
    [[ -n "$built" && -f "$built.sha256" ]] || die "build.sh left no archive"
    cp -f "$built" "$built.sha256" "$out/"
    rm -rf "$ROOT/root/engine-out"
    say "built $(basename "$built")"
}

case "${1:-}" in
    create) create ;;
    check)  check ;;
    build)  [[ $# -eq 2 ]] || die "usage: build_root.sh build OUT_DIR"; build "$2" ;;
    *)      die "usage: build_root.sh create | check | build OUT_DIR" ;;
esac
