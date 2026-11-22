#!/usr/bin/env bash
#
# build_root.sh - the Ubuntu 26.04 build root the publishing machine builds
# package fixes in (specs/package_fixes_ahead_of_ubuntu.md WP1).
#
# Version: 1.4 - build copies upstream_keys/ into the root beside build.sh (the upstream_release check)
# Version: 1.3 - source: Ubuntu's source for one exact version, fetched and unpacked in the root,
#                with its .dsc's SHA-256; newest: the newest Ubuntu version of a source; both for
#                the automatic recipe writer (specs/package_fixes_automatic.md WP3)
# Version: 1.2 - a build never holds the build lock past its end: the build runs without the lock's
#                descriptor, and whatever it left running inside its root is stopped when it ends
#                (php's curl tests left an openssl s_server that kept the lock and refused the next
#                publish)
# Version: 1.1 - each build runs in a throwaway overlay of the root, so one build's dependencies are
#                never in the next (reviewer2 G12): the root itself is only brought up to date
# Version: 1.0
#
# A fix is Ubuntu's own source plus upstream's patches, built against the
# libraries every node has, so it is built inside 26.04 with Ubuntu's source
# lines, and not on the publishing machine itself. The root is made once, by
# hand, by the owner:
#
#     sudo bash maintenance_scripts/install_tools/fixes/build_root.sh create
#
# and the publish pipeline, which runs as root, then builds in it whenever a
# recipe changed.
#
#   build_root.sh create                    Root. Installs debootstrap from this
#                                           machine's archive when missing;
#                                           bootstraps a minimal 26.04 (resolute)
#                                           at ROOT from Ubuntu's archive, checked
#                                           against Ubuntu's archive key, with deb
#                                           and deb-src lines; installs the
#                                           packaging tools build.sh uses. Run
#                                           again on a root already made, it
#                                           changes nothing.
#   build_root.sh check                     Exit 0 when the root is there and is
#                                           26.04; 1, saying the create command,
#                                           when it is not.
#   build_root.sh build RECIPE_DIR OUT_DIR  Root. Brings the root's packages up to
#                                           date, runs build.sh inside it on the
#                                           recipe, and copies the archive and its
#                                           .sha256 to OUT_DIR. One build at a
#                                           time; the kernel mounts it makes for
#                                           the build are gone when it ends,
#                                           however it ends.
#   build_root.sh source SRC VERSION OUT_DIR
#                                           Root. Fetches Ubuntu's source SRC at
#                                           exactly VERSION inside the root (apt
#                                           checks it against the signed archive
#                                           index), unpacks it there, and leaves
#                                           OUT_DIR/DSC_SHA256 (the .dsc's SHA-256,
#                                           as a recipe's dsc_sha256) and
#                                           OUT_DIR/tree (the unpacked source,
#                                           Ubuntu's patches applied). Takes the
#                                           build lock: never during a build.
#   build_root.sh newest SRC                Root. Prints the newest version of
#                                           source SRC in 26.04's -security pocket
#                                           (the release pocket when -security has
#                                           none), from the root's deb-src indexes;
#                                           never a +joinery build. One line, and
#                                           nothing else on stdout; exit 1 with the
#                                           reason when it cannot tell.
#
# ROOT is /var/lib/joinery-package-fixes-build/resolute: about 1 GB, and it
# keeps each source's build dependencies once installed (PHP's and
# PostgreSQL's are each about 1 GB), plus several GB under its /var/tmp while
# a build runs.

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BASE="${JOINERY_PACKAGE_FIXES_BUILD_BASE:-/var/lib/joinery-package-fixes-build}"
ROOT="$BASE/resolute"
LOCK="$BASE/build.lock"
MIRROR="http://archive.ubuntu.com/ubuntu"
KEYRING="/usr/share/keyrings/ubuntu-archive-keyring.gpg"
# Every Ubuntu release's debootstrap script is a link to this one; naming it
# lets an older debootstrap make a release newer than itself.
DEBOOTSTRAP_SCRIPT="/usr/share/debootstrap/scripts/gutsy"
TOOLCHAIN="ca-certificates build-essential dpkg-dev fakeroot debhelper xz-utils util-linux"
CREATE_CMD="sudo bash ${HERE}/build_root.sh create"

die() { echo "package fix build root: $*" >&2; exit 1; }
say() { echo "package fix build root: $*"; }

need_root() { [[ "$(id -u)" -eq 0 ]] || die "run as root"; }

is_root_ready() {
    [[ -f "$ROOT/etc/os-release" ]] && grep -qx 'VERSION_CODENAME=resolute' "$ROOT/etc/os-release" \
        && [[ -f "$ROOT/.joinery-package-fixes-build-root" ]]
}

# The root a run's mounts and chroot act on: ROOT itself, or a build's overlay of it.
TARGET="$ROOT"
MOUNTED=()
# Every process whose root is the build's: a test suite's server left running
# would keep the overlay busy, and the next build waiting.
stop_leftovers() {
    local p r n
    [[ -n "${TARGET:-}" && "$TARGET" != "/" ]] || return 0
    for n in 1 2 3; do
        r=0
        for p in /proc/[0-9]*; do
            [[ "$(readlink "$p/root" 2>/dev/null)" == "$TARGET" ]] || continue
            kill -KILL "${p#/proc/}" 2>/dev/null && r=1
        done
        [[ "$r" -eq 1 ]] || return 0
        sleep 1
    done
}
unmount_all() {
    local i
    stop_leftovers
    for (( i=${#MOUNTED[@]}-1; i>=0; i-- )); do
        umount -l "${MOUNTED[$i]}" 2>/dev/null || true
    done
    MOUNTED=()
}
mount_for_run() {
    local m
    for m in proc sys dev dev/pts; do mkdir -p "$TARGET/$m"; done
    mount -t proc proc "$TARGET/proc" && MOUNTED+=("$TARGET/proc")
    mount --rbind /sys "$TARGET/sys" && mount --make-rslave "$TARGET/sys" && MOUNTED+=("$TARGET/sys")
    mount --rbind /dev "$TARGET/dev" && mount --make-rslave "$TARGET/dev" && MOUNTED+=("$TARGET/dev")
    cp -L /etc/resolv.conf "$TARGET/etc/resolv.conf"
}
in_root() { chroot "$TARGET" /usr/bin/env -i PATH=/usr/sbin:/usr/bin:/sbin:/bin HOME=/root LANG=C.UTF-8 DEBIAN_FRONTEND=noninteractive "$@"; }

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
    # The same pockets a node takes from, and their sources: a fix is built on
    # the Ubuntu build a node runs, which is in -security or -updates.
    local kind pocket
    for kind in deb deb-src; do
        for pocket in resolute resolute-updates resolute-security; do
            echo "${kind} ${MIRROR} ${pocket} main universe"
        done
    done > "$ROOT.partial/etc/apt/sources.list"
    mv -T "$ROOT.partial" "$ROOT"
    trap unmount_all EXIT
    mount_for_run
    say "installing the packaging tools"
    in_root apt-get update -qq
    in_root apt-get install -y -qq --no-install-recommends $TOOLCHAIN > /dev/null
    unmount_all
    trap - EXIT
    date -u +%Y-%m-%dT%H:%M:%SZ > "$ROOT/.joinery-package-fixes-build-root"
    say "ready: $(grep '^PRETTY_NAME=' "$ROOT/etc/os-release" | cut -d'"' -f2)"
}

check() {
    if is_root_ready; then
        say "${ROOT} is ready"
        return 0
    fi
    echo "package fix build root: none at ${ROOT}; the owner makes it once with: ${CREATE_CMD}" >&2
    return 1
}

build() {  # RECIPE_DIR OUT_DIR
    need_root
    local recipe out source built
    recipe="$(cd "$1" && pwd)"
    out="$2"
    source="$(basename "$recipe")"
    [[ -f "$recipe/RECIPE" ]] || die "no RECIPE in ${recipe}"
    is_root_ready || die "no build root at ${ROOT}; the owner makes it once with: ${CREATE_CMD}"
    mkdir -p "$out"
    exec 9> "$LOCK"
    flock -n 9 || die "another package fix build is running"
    # The root itself is only brought up to date.
    TARGET="$ROOT"
    trap unmount_all EXIT
    mount_for_run
    in_root apt-get update -qq
    in_root apt-get upgrade -y -qq > /dev/null
    in_root apt-get clean
    unmount_all
    # The build runs in a throwaway overlay of it: the build dependencies it
    # installs, and everything else it writes, are gone when it ends.
    local run="$BASE/run.$$"
    rm -rf "$run"
    mkdir -p "$run/upper" "$run/work" "$run/merged"
    mount -t overlay overlay -o "lowerdir=$ROOT,upperdir=$run/upper,workdir=$run/work" "$run/merged" \
        || die "could not make the build's overlay of ${ROOT}"
    MOUNTED+=("$run/merged")
    trap 'unmount_all; rm -rf "'"$run"'"' EXIT
    TARGET="$run/merged"
    mount_for_run
    mkdir -p "$TARGET/root/fixes"
    cp "$HERE/build.sh" "$TARGET/root/fixes/"
    # The projects' release keys a person committed: build.sh checks an
    # upstream_release tarball's signature against upstream_keys/<source>.asc.
    [[ -d "$HERE/upstream_keys" ]] && cp -r "$HERE/upstream_keys" "$TARGET/root/fixes/"
    cp -r "$recipe" "$TARGET/root/fixes/$source"
    # Without the lock's descriptor: nothing the build starts can hold the lock.
    in_root bash /root/fixes/build.sh "/root/fixes/$source" /root/fix-out 9>&-
    stop_leftovers
    built="$(ls "$TARGET/root/fix-out/"*.tar.xz 2>/dev/null | head -n 1)"
    [[ -n "$built" && -f "$built.sha256" ]] || die "build.sh left no archive"
    cp -f "$built" "$built.sha256" "$out/"
    unmount_all
    rm -rf "$run"
    trap - EXIT
    say "built $(basename "$built")"
}

fetch_source() {  # SRC VERSION OUT_DIR
    need_root
    local src="$1" version="$2" out="$3" fetch dsc sha
    [[ "$src" =~ ^[a-z0-9][a-z0-9.+-]+$ ]] || die "'${src}' is not a source package name"
    [[ "$version" =~ ^([0-9]+:)?[0-9][A-Za-z0-9.+~-]*$ ]] || die "'${version}' is not a package version"
    is_root_ready || die "no build root at ${ROOT}; the owner makes it once with: ${CREATE_CMD}"
    mkdir -p "$out"
    out="$(cd "$out" && pwd)"
    exec 9> "$LOCK"
    flock -n 9 || die "a package fix build is running"
    TARGET="$ROOT"
    fetch="/var/tmp/source-fetch.$$"
    trap 'unmount_all; rm -rf "'"$ROOT$fetch"'"' EXIT
    mount_for_run
    rm -rf "${ROOT:?}$fetch"
    mkdir -p "$ROOT$fetch"
    # apt fetches as _apt: a folder only root can enter makes it fall back to root, with a warning.
    chmod 755 "$ROOT$fetch"
    in_root apt-get update -qq
    in_root sh -c 'cd "$1" && apt-get source -qq --download-only "$2=$3"' sh "$fetch" "$src" "$version" \
        || die "apt could not fetch ${src} ${version} (is it still in Ubuntu's archive?)"
    dsc="${ROOT}${fetch}/${src}_${version#*:}.dsc"
    [[ -f "$dsc" ]] || die "no ${src}_${version#*:}.dsc was fetched"
    sha="$(sha256sum "$dsc" | awk '{print $1}')"
    # dpkg-source checks each file against the checksums the .dsc lists.
    in_root sh -c 'cd "$1" && dpkg-source -q -x "$2" tree' sh "$fetch" "$(basename "$dsc")" > /dev/null 2>&1 \
        || die "${src} ${version} would not unpack"
    rm -rf "${out:?}/tree" "$out/DSC_SHA256"
    mv -T "${ROOT}${fetch}/tree" "$out/tree" 2>/dev/null || cp -a "${ROOT}${fetch}/tree" "$out/tree"
    echo "$sha" > "$out/DSC_SHA256"
    unmount_all
    rm -rf "${ROOT:?}$fetch"
    trap - EXIT
    say "${src} ${version}: .dsc ${sha}, unpacked in ${out}/tree"
}

newest() {  # SRC
    need_root
    local src="$1" pocket v best
    [[ "$src" =~ ^[a-z0-9][a-z0-9.+-]+$ ]] || die "'${src}' is not a source package name"
    is_root_ready || die "no build root at ${ROOT}; the owner makes it once with: ${CREATE_CMD}"
    exec 9> "$LOCK"
    flock -n 9 || die "a package fix build is running"
    TARGET="$ROOT"
    trap unmount_all EXIT
    mount_for_run
    in_root apt-get update -qq >&2 || die "apt could not read Ubuntu's archive indexes"
    # madison's source lines: " php8.5 | 8.5.4-0ubuntu1.6 | http://... resolute-security/main Sources"
    local listing
    listing="$(in_root apt-cache madison "$src" 2>/dev/null | awk -F' [|] ' -v s="$src" '$1 ~ ("^ *" s "$") && $3 ~ / Sources$/ {print $2 "\t" $3}')"
    unmount_all
    trap - EXIT
    for pocket in resolute-security resolute; do
        best=""
        while IFS=$'\t' read -r v where; do
            [[ -n "$v" && "$where" == *" ${pocket}/"* && "$v" != *+joinery* ]] || continue
            if [[ -z "$best" ]] || dpkg --compare-versions "$v" gt "$best"; then best="$v"; fi
        done <<< "$listing"
        if [[ -n "$best" ]]; then
            echo "$best"
            return 0
        fi
    done
    die "Ubuntu's archive lists no ${src} source in resolute-security or resolute"
}

case "${1:-}" in
    create) create ;;
    check)  check ;;
    build)  [[ $# -eq 3 ]] || die "usage: build_root.sh build RECIPE_DIR OUT_DIR"; build "$2" "$3" ;;
    source) [[ $# -eq 4 ]] || die "usage: build_root.sh source SRC VERSION OUT_DIR"; fetch_source "$2" "$3" "$4" ;;
    newest) [[ $# -eq 2 ]] || die "usage: build_root.sh newest SRC"; newest "$2" ;;
    *)      die "usage: build_root.sh create | check | build RECIPE_DIR OUT_DIR | source SRC VERSION OUT_DIR | newest SRC" ;;
esac
